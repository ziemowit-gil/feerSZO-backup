<?php
/**
 * includes/crmTechRules.php — decyzje o adresach technicznych w CRM, które pamiętają wybór człowieka.
 *
 *  1. REGUŁY DOMENOWE: „zawsze oznaczaj z tej domeny” (action=technical) — kontakty z domeny są oznaczane od razu.
 *  2. WYJĄTKI: „to nie jest techniczny” (action=not_technical, dla adresu albo domeny) — wzorce, AI i skrypt
 *     tygodniowy przestają zgłaszać taki adres.
 *  3. TRYB AUTOMATYCZNY (ustawienie crm_tech_auto, domyślnie WYŁĄCZONY): adresy rozpoznane PEWNIE (wzorce, domena
 *     platformy) bot oznacza sam; wskazane tylko przez AI zawsze czekają na decyzję.
 *  4. DZIENNIK ZNAKOWAŃ crm_tech_marks: każde oznaczenie pamięta poprzedni typ i status, więc da się je cofnąć.
 */

require_once __DIR__ . '/db.php';

function crm_tech_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    db()->exec("CREATE TABLE IF NOT EXISTS crm_tech_rules (
        id {$pk}, kind VARCHAR(10) NOT NULL, value VARCHAR(190) NOT NULL, action VARCHAR(20) NOT NULL,
        created_by INTEGER, created_at VARCHAR(19)
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_tech_rules ON crm_tech_rules(kind, value, action)");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_tech_marks (
        id {$pk}, contact_id INTEGER NOT NULL, prev_type VARCHAR(40), prev_status VARCHAR(60),
        source VARCHAR(12) NOT NULL, marked_by INTEGER, marked_at VARCHAR(19), undone_at VARCHAR(19)
    )");
}

function crm_tech_domain_of(?string $email): string
{
    $e = strtolower(trim((string)$email));
    $at = strrpos($e, '@');
    return $at === false ? '' : trim(substr($e, $at + 1));
}

/**
 * Domena do grupowania decyzji: znana platforma z CRM_ROBOT_DOMAINS (em1.substack.com → substack.com), a dla innych
 * ostatnie dwa człony (trzy przy com.pl / org.pl…). Reguła domenowa obejmuje potem subdomeny.
 */
function crm_tech_group_domain(?string $email): string
{
    $d = crm_tech_domain_of($email);
    if ($d === '') return '';
    if (defined('CRM_ROBOT_DOMAINS')) foreach (CRM_ROBOT_DOMAINS as $dom) if ($d === $dom || str_ends_with($d, '.' . $dom)) return $dom;
    $p = explode('.', $d);
    if (count($p) <= 2) return $d;
    $n = (strlen(end($p)) === 2 && in_array($p[count($p) - 2], ['com', 'org', 'net', 'edu', 'gov', 'co'], true)) ? 3 : 2;
    return implode('.', array_slice($p, -$n));
}

/** @return array<int,array{id:int,kind:string,value:string,action:string,created_at:?string}> */
function crm_tech_rules(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        crm_tech_migrate();
        try { $cache = db_all("SELECT id, kind, value, action, created_at FROM crm_tech_rules ORDER BY id DESC"); }
        catch (\Throwable $e) { $cache = []; }
    }
    return $cache;
}

/** Czy adres (lub jego domena z subdomenami) pasuje do reguły o danej akcji. */
function _crm_tech_match(?string $email, string $action): bool
{
    $e = strtolower(trim((string)$email));
    if ($e === '' || !str_contains($e, '@')) return false;
    $d = crm_tech_domain_of($e);
    foreach (crm_tech_rules() as $r) {
        if ($r['action'] !== $action) continue;
        if ($r['kind'] === 'email' && $r['value'] === $e) return true;
        if ($r['kind'] === 'domain' && ($d === $r['value'] || str_ends_with($d, '.' . $r['value']))) return true;
    }
    return false;
}

/** Wyjątek „to nie jest techniczny” — adres albo cała domena. */
function crm_tech_is_exception(?string $email): bool { return _crm_tech_match($email, 'not_technical'); }

/** Reguła „zawsze oznaczaj z tej domeny”. */
function crm_tech_domain_rule_hit(?string $email): bool { return _crm_tech_match($email, 'technical'); }

function crm_tech_rule_add(string $kind, string $value, string $action, ?int $uid): bool
{
    $value = strtolower(trim($value));
    if (!in_array($kind, ['domain', 'email'], true) || !in_array($action, ['technical', 'not_technical'], true)) return false;
    if ($kind === 'domain') $value = ltrim(preg_replace('~^.*@~', '', $value), '.');
    if ($value === '' || ($kind === 'email' && !str_contains($value, '@'))) return false;
    crm_tech_migrate();
    // Przeciwna reguła na to samo znika — inaczej dwie reguły sprzeczne ze sobą rozstrzygałaby kolejność
    db()->prepare("DELETE FROM crm_tech_rules WHERE kind = ? AND value = ? AND action <> ?")->execute([$kind, $value, $action]);
    try {
        db()->prepare("INSERT OR IGNORE INTO crm_tech_rules (kind, value, action, created_by, created_at) VALUES (?,?,?,?,?)")
            ->execute([$kind, $value, $action, $uid, date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        try { db()->prepare("INSERT INTO crm_tech_rules (kind, value, action, created_by, created_at) VALUES (?,?,?,?,?)")->execute([$kind, $value, $action, $uid, date('Y-m-d H:i:s')]); }
        catch (\Throwable $e2) { /* duplikat */ }
    }
    crm_tech_rules(true);
    return true;
}

function crm_tech_rule_remove(int $id): void
{
    crm_tech_migrate();
    db()->prepare("DELETE FROM crm_tech_rules WHERE id = ?")->execute([$id]);
    crm_tech_rules(true);
}

function crm_tech_auto_enabled(): bool
{
    // bez org_setting(): ono cache'uje wartość na czas procesu, a przełącznik ma działać od razu
    try { return (string)(db_one("SELECT value FROM settings WHERE key_ = 'crm_tech_auto'")['value'] ?? '') === '1'; }
    catch (\Throwable $e) { return false; }
}

function crm_tech_auto_set(bool $on): void
{
    org_setting_set('crm_tech_auto', $on ? '1' : '0');
}

/** Kartoteki aktywne, jeszcze nie techniczne, z adresem w danej domenie (z subdomenami). */
function crm_tech_domain_candidates(string $domain): array
{
    $d = strtolower(trim($domain));
    if ($d === '') return [];
    return db_all("SELECT id, imie_nazwisko, email FROM crm_contacts
                    WHERE crm_active = 1 AND type <> 'kontakt_techniczny' AND COALESCE(status,'') NOT IN ('techniczny','powiadomienia')
                      AND (LOWER(email) LIKE ? OR LOWER(email) LIKE ?)", ['%@' . $d, '%.' . $d]);
}

/**
 * Oznacza kontakt jako techniczny (typ „Kontakt techniczny” + status „Techniczny”, jeśli jest w słowniku), z notatką
 * i wpisem w dzienniku znakowań (cofnięcie przywraca poprzedni typ i status).
 * @param string $source manual | auto | domain
 */
function crm_tech_mark(int $contactId, string $source, ?int $uid): bool
{
    crm_tech_migrate();
    $c = db_one("SELECT id, type, status FROM crm_contacts WHERE id = ? AND crm_active = 1", [$contactId]);
    if (!$c) return false;
    if ($c['type'] === 'kontakt_techniczny') return true;
    $hasSt = db_one("SELECT 1 AS x FROM crm_statuses WHERE slug = 'techniczny' AND is_active = 1");
    db()->prepare("INSERT INTO crm_tech_marks (contact_id, prev_type, prev_status, source, marked_by, marked_at) VALUES (?,?,?,?,?,?)")
        ->execute([$contactId, (string)$c['type'], (string)$c['status'], $source, $uid, date('Y-m-d H:i:s')]);
    db()->prepare("UPDATE crm_contacts SET type = 'kontakt_techniczny'" . ($hasSt ? ", status = 'techniczny'" : '') . ", updated_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $contactId]);
    $why = ['manual' => 'decyzją użytkownika', 'auto' => 'automatycznie (adres rozpoznany pewnie)', 'domain' => 'regułą domenową'][$source] ?? $source;
    if (class_exists('CrmManager')) CrmManager::addNote($contactId, 'Oznaczono jako kontakt techniczny ' . $why . '. ' . date('Y-m-d H:i') . '.', $uid);
    db()->prepare("UPDATE crm_janitor_findings SET status = 'done', resolved_at = ?, resolved_by = ? WHERE rule = 'robot_contact' AND contact_id = ? AND status = 'open'")
        ->execute([date('Y-m-d H:i:s'), $uid, $contactId]);
    return true;
}

function crm_tech_undo(int $markId, ?int $uid): bool
{
    crm_tech_migrate();
    $m = db_one("SELECT * FROM crm_tech_marks WHERE id = ? AND undone_at IS NULL", [$markId]);
    if (!$m) return false;
    db()->prepare("UPDATE crm_contacts SET type = ?, status = ?, updated_at = ? WHERE id = ?")
        ->execute([$m['prev_type'] ?: 'osoba', (string)$m['prev_status'], date('Y-m-d H:i:s'), (int)$m['contact_id']]);
    db()->prepare("UPDATE crm_tech_marks SET undone_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $markId]);
    if (class_exists('CrmManager')) CrmManager::addNote((int)$m['contact_id'], 'Cofnięto oznaczenie „kontakt techniczny”. ' . date('Y-m-d H:i') . '.', $uid);
    return true;
}

/** Ostatnie oznaczenia do przeglądu i cofnięcia. */
function crm_tech_marks_recent(int $limit = 30): array
{
    crm_tech_migrate();
    return db_all("SELECT m.*, c.imie_nazwisko, c.email FROM crm_tech_marks m LEFT JOIN crm_contacts c ON c.id = m.contact_id
                    WHERE m.source <> 'manual' ORDER BY m.id DESC LIMIT ?", [$limit]);
}

/** Oznacza wszystkie kartoteki z domeny. @return int liczba oznaczonych */
function crm_tech_mark_domain(string $domain, ?int $uid, string $source = 'manual'): int
{
    $n = 0;
    foreach (crm_tech_domain_candidates($domain) as $c) {
        if (crm_tech_is_exception($c['email'])) continue;
        if (crm_tech_mark((int)$c['id'], $source, $uid)) $n++;
    }
    return $n;
}

/**
 * Po dodaniu wyjątku: kontakty, których dotyczy, tracą rozpoznanie „techniczny” (policzy je ponownie
 * crm_weekly_tidy bez gałęzi technicznych), nie są już pytane przez AI, a otwarte zgłoszenia znikają.
 * @return int liczba zamkniętych zgłoszeń
 */
function crm_tech_exception_apply(string $kind, string $value, ?int $uid): int
{
    $value = strtolower(trim($value));
    $rows = $kind === 'domain'
        ? db_all("SELECT id FROM crm_contacts WHERE LOWER(email) LIKE ? OR LOWER(email) LIKE ?", ['%@' . $value, '%.' . $value])
        : db_all("SELECT id FROM crm_contacts WHERE LOWER(email) = ?", [$value]);
    $closed = 0;
    foreach ($rows as $r) {
        db()->prepare("UPDATE crm_contacts SET email_kind = CASE WHEN email_kind = 'technical' THEN NULL ELSE email_kind END, tech_ai_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), (int)$r['id']]);
        $st = db()->prepare("UPDATE crm_janitor_findings SET status = 'dismissed', resolved_at = ?, resolved_by = ? WHERE rule = 'robot_contact' AND contact_id = ? AND status = 'open'");
        $st->execute([date('Y-m-d H:i:s'), $uid, (int)$r['id']]);
        $closed += $st->rowCount();
    }
    return $closed;
}
