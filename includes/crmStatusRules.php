<?php
/**
 * includes/crmStatusRules.php — automatyczne nadawanie STATUSU RELACJI kontaktom (drugi z dwóch statusów;
 * pierwszy, kategoria główna, wynika z crmCategories). Reguły działają cyklicznie (cron + „Uruchom teraz”):
 *   active_contract — kontakt ma aktywną umowę (po e-mailu, każdy rodzaj)  → status X,
 *   category        — kontakt ma kategorię Y (np. darczyńca)                → status X,
 *   inactive_days   — brak aktywności (wiadomości, notatki, sprawy) od N dni i brak aktywnej umowy → status X.
 * Reguła zmienia status tylko, gdy obecny status należy do „z jakich statusów” (puste = każdy), a docelowy jest w katalogu.
 * Każda zmiana trafia do dziennika crm_status_auto_log (z regułą) i wywołuje zdarzenie contact_status_changed.
 * Domyślne reguły są nasiewane WYŁĄCZONE poza „aktywna umowa → aktywny” (awans bez ryzyka); „nieaktywny po N dniach” włącza człowiek po podglądzie.
 */
require_once __DIR__ . '/crm_contract_segments.php';
require_once __DIR__ . '/crmCategories.php';

const CRM_STATUS_RULE_CONDITIONS = [
    'active_contract' => 'ma aktywną umowę',
    'category'        => 'ma kategorię',
    'inactive_days'   => 'brak aktywności od (dni)',
    'type'            => 'typ kontaktu to',
    'email_kind'      => 'rodzaj adresu e-mail to',
    'org_no_nip'      => 'firma bez NIP',
];

function crm_status_rules_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_status_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        cond TEXT NOT NULL,
        param TEXT NOT NULL DEFAULT '',
        from_statuses TEXT NOT NULL DEFAULT '',
        to_status TEXT NOT NULL,
        hits INTEGER NOT NULL DEFAULT 0,
        last_run_at TEXT
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_status_auto_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER NOT NULL,
        rule_id INTEGER,
        rule_name TEXT,
        from_status TEXT,
        to_status TEXT,
        created_at TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_sal_contact ON crm_status_auto_log(contact_id)");
    if ((int)(db_one("SELECT COUNT(*) AS n FROM crm_status_rules")['n'] ?? 0) === 0) {
        $ins = db()->prepare("INSERT INTO crm_status_rules (name,is_active,sort_order,cond,param,from_statuses,to_status) VALUES (?,?,?,?,?,?,?)");
        $ins->execute(['Aktywna umowa → aktywny', 1, 1, 'active_contract', '', 'prospect,nowy,nieaktywny', 'aktywny']);
        $ins->execute(['Darczyńca (kategoria) → darczyńca', 0, 2, 'category', 'darczynca', 'prospect,nowy', 'darczyńca']);
        $ins->execute(['Brak aktywności 365 dni → nieaktywny', 0, 3, 'inactive_days', '365', 'aktywny,prospect,nowy', 'nieaktywny']);
    }
}

function crm_status_rules(): array {
    crm_status_rules_schema();
    return db_all("SELECT * FROM crm_status_rules ORDER BY sort_order, id");
}

/** @return list<int> kontakty spełniające warunek reguły (przed sprawdzeniem „z jakich statusów”). */
function crm_status_rule_candidates(array $rule): array {
    $ids = [];
    switch ($rule['cond']) {
        case 'active_contract':
            foreach (array_keys(crm_contract_segment_types()) as $t) foreach (crm_contract_segment_ids($t, 'active') as $id) $ids[$id] = true;
            return array_map('intval', array_keys($ids));
        case 'category':
            return crm_category_contact_ids([(string)$rule['param']]);
        case 'type':
            if (!isset(CRM_CONTACT_TYPES[(string)$rule['param']])) return [];
            return array_map('intval', array_column(db_all("SELECT id FROM crm_contacts WHERE crm_active=1 AND type=?", [(string)$rule['param']]), 'id'));
        case 'email_kind':
            if (!in_array((string)$rule['param'], ['personal', 'generic', 'unknown'], true)) return [];
            return array_map('intval', array_column(db_all("SELECT id FROM crm_contacts WHERE crm_active=1 AND email_kind=?", [(string)$rule['param']]), 'id'));
        case 'org_no_nip':
            $orgs = array_keys(array_filter(CRM_CONTACT_TYPES, fn($t) => !empty($t['org_like'])));
            $ph = implode(',', array_fill(0, count($orgs), '?'));
            return array_map('intval', array_column(db_all("SELECT id FROM crm_contacts WHERE crm_active=1 AND type IN ($ph) AND TRIM(COALESCE(nip,''))=''", $orgs), 'id'));
        case 'inactive_days':
            $n = max(30, (int)$rule['param']);
            $cut = date('Y-m-d H:i:s', strtotime("-{$n} days"));
            $sql = "SELECT c.id FROM crm_contacts c WHERE c.crm_active=1
                      AND COALESCE(c.created_at,'0000') < ?
                      AND COALESCE((SELECT MAX(sent_at) FROM crm_communications WHERE contact_id=c.id),'0000') < ?
                      AND COALESCE((SELECT MAX(created_at) FROM crm_notes WHERE contact_id=c.id),'0000') < ?
                      AND COALESCE((SELECT MAX(updated_at) FROM crm_cases WHERE contact_id=c.id),'0000') < ?";
            $idsq = array_map('intval', array_column(db_all($sql, [$cut, $cut, $cut, $cut]), 'id'));
            $active = [];
            foreach (array_keys(crm_contract_segment_types()) as $t) foreach (crm_contract_segment_ids($t, 'active') as $id) $active[$id] = true;
            return array_values(array_filter($idsq, fn($id) => !isset($active[$id])));
    }
    return [];
}

/**
 * Wylicza (i opcjonalnie wykonuje) zmiany statusów.
 * @return array{changes:list<array{contact_id:int,rule:string,from:string,to:string}>,applied:int}
 */
function crm_status_rules_run(bool $apply = true, ?int $only_rule = null): array {
    crm_status_rules_schema();
    $catalog = array_keys(crm_statuses());
    $changes = [];
    $moved = [];   // kontakt zmieniony przez wcześniejszą regułę w tym przebiegu — nie ruszamy go drugi raz
    foreach (crm_status_rules() as $r) {
        if ($only_rule ? (int)$r['id'] !== $only_rule : !(int)$r['is_active']) continue;
        if (!in_array($r['to_status'], $catalog, true)) continue;
        $cand = crm_status_rule_candidates($r);
        if (!$cand) { if ($apply) db()->prepare("UPDATE crm_status_rules SET last_run_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]); continue; }
        $from = array_values(array_filter(array_map('trim', explode(',', (string)$r['from_statuses']))));
        $ph = implode(',', array_fill(0, count($cand), '?'));
        $n = 0;
        foreach (db_all("SELECT id, status FROM crm_contacts WHERE crm_active=1 AND id IN ($ph)", $cand) as $c) {
            $cid = (int)$c['id'];
            if (isset($moved[$cid]) || $c['status'] === $r['to_status']) continue;
            if ($from && !in_array($c['status'], $from, true)) continue;
            $changes[] = ['contact_id' => $cid, 'rule' => (string)$r['name'], 'from' => (string)$c['status'], 'to' => (string)$r['to_status']];
            $moved[$cid] = true; $n++;
            if ($apply) {
                db()->prepare("UPDATE crm_contacts SET status=?, updated_at=? WHERE id=?")->execute([$r['to_status'], date('Y-m-d H:i:s'), $cid]);
                db()->prepare("INSERT INTO crm_status_auto_log (contact_id, rule_id, rule_name, from_status, to_status, created_at) VALUES (?,?,?,?,?,?)")
                    ->execute([$cid, (int)$r['id'], $r['name'], $c['status'], $r['to_status'], date('Y-m-d H:i:s')]);
                try {
                    require_once __DIR__ . '/crm_automation.php';
                    crm_automation_fire('contact_status_changed', $cid, ['from_status' => $c['status'], 'to_status' => $r['to_status']]);
                } catch (\Throwable $e) {}
            }
        }
        if ($apply) db()->prepare("UPDATE crm_status_rules SET hits=hits+?, last_run_at=? WHERE id=?")->execute([$n, date('Y-m-d H:i:s'), (int)$r['id']]);
    }
    return ['changes' => $changes, 'applied' => $apply ? count($changes) : 0];
}
