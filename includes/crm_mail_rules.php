<?php
/**
 * includes/crm_mail_rules.php — reguły automatyczne Skrzynki CRM (wzór: moduł Workflows z FreeScout).
 *
 * Reguła = WARUNKI (skrzynka, nadawca — e-mail/domena/nazwa, temat, załącznik; wszystkie albo dowolny) → AKCJE (kolejka, tag, opiekun, obserwujący).
 * Reguły obejmują NOWE wiadomości przychodzące: przebieg crm_mr_run (cron co 5 min) bierze wiadomości nowsze niż znacznik crm_rules_since_id
 * (ustawiany przy pierwszym przebiegu na ostatnią istniejącą — włączenie reguł nie rusza starej poczty) i nieprzetworzone (crm_mail_rule_done).
 * Kolejność wg sort_order; „zatrzymaj po dopasowaniu” przerywa dalsze reguły. Historię można objąć ręcznie (crm_mr_apply_existing — jedna reguła).
 *
 * Rozsądne domyślne zachowania (żeby reguła nie psuła pracy zespołu):
 *   • „opiekun” przypisuje tylko rozmowę BEZ opiekuna (nie nadpisuje ręcznego przypisania) i nie osobie poza biurem,
 *   • „kolejka” nie przenosi rozmowy, która ma już jawną kolejkę (ktoś ją przeniósł albo wcześniejsza reguła),
 *   • odpowiedź w rozmowie przeniesionej do kolejki dziedziczy jej kolejkę (dzieje się w tym samym przebiegu, także bez żadnych reguł),
 *   • tag i obserwujący są idempotentne (dubel = nic się nie dzieje).
 */

const CRM_MR_FIELDS = ['mailbox' => 'Skrzynka', 'from' => 'Nadawca (e-mail)', 'from_name' => 'Nadawca (nazwa)', 'subject' => 'Temat', 'attachment' => 'Załącznik'];
const CRM_MR_OPS = [
    'mailbox'    => ['is' => 'jest'],
    'from'       => ['contains' => 'zawiera', 'equals' => 'jest równy', 'domain' => 'jest z domeny', 'not_contains' => 'nie zawiera'],
    'from_name'  => ['contains' => 'zawiera', 'not_contains' => 'nie zawiera'],
    'subject'    => ['contains' => 'zawiera', 'not_contains' => 'nie zawiera', 'starts' => 'zaczyna się od', 'equals' => 'jest równy'],
    'attachment' => ['is' => 'jest'],
];
const CRM_MR_ACTIONS = ['queue' => 'Przenieś do kolejki', 'tag' => 'Dodaj tag', 'assign' => 'Przypisz opiekuna', 'follow' => 'Dodaj obserwującego'];

function crm_mr_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_rules (
            id           {$pk},
            name         VARCHAR(120) NOT NULL,
            is_active    INTEGER NOT NULL DEFAULT 1,
            sort_order   INTEGER NOT NULL DEFAULT 50,
            stop_on_match INTEGER NOT NULL DEFAULT 0,
            match_mode   VARCHAR(3) NOT NULL DEFAULT 'all',
            conditions   TEXT NOT NULL DEFAULT '[]',
            actions      TEXT NOT NULL DEFAULT '[]',
            created_by   INTEGER,
            created_at   VARCHAR(19)
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_rule_done (
            comm_id      INTEGER PRIMARY KEY,
            processed_at VARCHAR(19) NOT NULL
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_rule_log (
            id         {$pk},
            rule_id    INTEGER NOT NULL,
            comm_id    INTEGER NOT NULL,
            summary    VARCHAR(300) NOT NULL DEFAULT '',
            created_at VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_mail_rule_log ON crm_mail_rule_log(rule_id, comm_id)");
    } catch (\Throwable $e) {
        error_log('[crm_mr_migrate] ' . $e->getMessage());
    }
}

// ── Normalizacja i zapis ────────────────────────────────────────────────────

/** Czyści warunki z formularza: tylko znane pola i operatory, niepuste wartości. */
function crm_mr_clean_conditions(array $in): array
{
    $out = [];
    foreach ($in as $c) {
        $f = (string)($c['field'] ?? ''); $op = (string)($c['op'] ?? ''); $v = trim((string)($c['value'] ?? ''));
        if (!isset(CRM_MR_FIELDS[$f]) || !isset(CRM_MR_OPS[$f][$op])) continue;
        if ($f === 'attachment') $v = $v === 'no' ? 'no' : 'yes';
        elseif ($f === 'mailbox') { $v = (string)max(0, (int)$v); if ($v === '0') continue; }
        else { $v = mb_substr($v, 0, 200); if ($v === '') continue; }
        $out[] = ['field' => $f, 'op' => $op, 'value' => $v];
        if (count($out) >= 8) break;
    }
    return $out;
}

/** Czyści akcje: queue/assign/follow wymagają id, tag — tekstu (po normalizacji). */
function crm_mr_clean_actions(array $in): array
{
    $out = [];
    foreach ($in as $a) {
        $t = (string)($a['type'] ?? '');
        if (!isset(CRM_MR_ACTIONS[$t])) continue;
        if ($t === 'tag') { $n = crm_mt_normalize((string)($a['tag'] ?? ($a['value'] ?? ''))); if (!$n) continue; $out[] = ['type' => 'tag', 'value' => $n[0]]; }
        else { $id = (int)($a[$t === 'queue' ? 'queue_id' : 'user_id'] ?? ($a['value'] ?? 0)); if ($id <= 0) continue; $out[] = ['type' => $t, 'value' => $id]; }
        if (count($out) >= 8) break;
    }
    return $out;
}

/** Zapis reguły. Wymaga ≥ 1 warunku i ≥ 1 akcji. Zwraca id. */
function crm_mr_save(array $d, ?int $id = null): int
{
    crm_mr_migrate();
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('Podaj nazwę reguły.');
    $cond = crm_mr_clean_conditions((array)($d['conditions'] ?? []));
    $act = crm_mr_clean_actions((array)($d['actions'] ?? []));
    if (!$cond) throw new \InvalidArgumentException('Dodaj co najmniej jeden warunek (z wartością).');
    if (!$act) throw new \InvalidArgumentException('Dodaj co najmniej jedną akcję.');
    $row = ['name' => mb_substr($name, 0, 120), 'is_active' => isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1, 'sort_order' => (int)($d['sort_order'] ?? 50),
            'stop_on_match' => !empty($d['stop_on_match']) ? 1 : 0, 'match_mode' => ($d['match_mode'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions' => json_encode($cond, JSON_UNESCAPED_UNICODE), 'actions' => json_encode($act, JSON_UNESCAPED_UNICODE)];
    if ($id === null) return db_insert('crm_mail_rules', $row + ['created_by' => (int)(current_user()['id'] ?? 0) ?: null, 'created_at' => date('Y-m-d H:i:s')]);
    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($row)));
    db()->prepare("UPDATE crm_mail_rules SET $set WHERE id = :id")->execute($row + ['id' => $id]);
    return $id;
}

function crm_mr_list(bool $onlyActive = false): array
{
    crm_mr_migrate();
    try {
        $rows = db_all("SELECT * FROM crm_mail_rules " . ($onlyActive ? "WHERE is_active = 1 " : "") . "ORDER BY sort_order, id");
    } catch (\Throwable $e) { return []; }
    foreach ($rows as &$r) { $r['cond'] = (array)(json_decode((string)$r['conditions'], true) ?: []); $r['acts'] = (array)(json_decode((string)$r['actions'], true) ?: []); }
    unset($r);
    return $rows;
}

function crm_mr_delete(int $id): void
{
    crm_mr_migrate();
    db()->prepare("DELETE FROM crm_mail_rules WHERE id = ?")->execute([$id]);
    db()->prepare("DELETE FROM crm_mail_rule_log WHERE rule_id = ?")->execute([$id]);
}

// ── Dopasowanie ─────────────────────────────────────────────────────────────

function _crm_mr_cond(array $c, array $m): bool
{
    $has = fn(string $hay, string $needle) => $needle !== '' && mb_stripos($hay, $needle) !== false;
    switch ($c['field']) {
        case 'mailbox':    return (int)($m['mailbox_id'] ?? 0) === (int)$c['value'];
        case 'attachment': return ((int)($m['has_attachments'] ?? 0) === 1) === ($c['value'] === 'yes');
        case 'from':
            $from = mb_strtolower(trim((string)($m['from_email'] ?? ''))); $v = mb_strtolower(trim($c['value']));
            return match ($c['op']) {
                'equals'       => $from === $v,
                'domain'       => (function () use ($from, $v) { $d = ltrim($v, '@'); $fd = substr(strrchr($from, '@') ?: '', 1); return $d !== '' && ($fd === $d || str_ends_with($fd, '.' . $d)); })(),
                'not_contains' => !$has($from, $v),
                default        => $has($from, $v),
            };
        case 'from_name':
            $n = (string)($m['from_name'] ?? '');
            return $c['op'] === 'not_contains' ? !$has($n, $c['value']) : $has($n, $c['value']);
        case 'subject':
            $s = trim((string)($m['subject'] ?? '')); $v = trim($c['value']);
            return match ($c['op']) {
                'equals'       => mb_strtolower($s) === mb_strtolower($v),
                'starts'       => $v !== '' && mb_stripos($s, $v) === 0,
                'not_contains' => !$has($s, $v),
                default        => $has($s, $v),
            };
    }
    return false;
}

/** Czy wiadomość spełnia warunki reguły (all/any; reguła bez warunków nigdy). */
function crm_mr_match(array $m, array $rule): bool
{
    $conds = $rule['cond'] ?? (array)(json_decode((string)($rule['conditions'] ?? '[]'), true) ?: []);
    if (!$conds) return false;
    $any = ($rule['match_mode'] ?? 'all') === 'any';
    foreach ($conds as $c) {
        $ok = _crm_mr_cond($c, $m);
        if ($any && $ok) return true;
        if (!$any && !$ok) return false;
    }
    return !$any;
}

// ── Akcje ───────────────────────────────────────────────────────────────────

function _crm_mr_thread_has(array $m, string $col): bool
{
    $tk = trim((string)($m['thread_key'] ?? ''));
    if ($tk === '') return db_one("SELECT 1 AS x FROM crm_communications WHERE id = ? AND $col IS NOT NULL AND $col <> 0", [(int)$m['id']]) ? true : false;
    return (bool)db_one("SELECT 1 AS x FROM crm_communications WHERE thread_key = ? AND $col IS NOT NULL AND $col <> 0 LIMIT 1", [$tk]);
}

/**
 * Wykonuje akcje reguły na wiadomości; $dry = tylko opis tego, co by się stało. @return string[] opisy wykonanych (lub pominiętych) akcji
 */
function crm_mr_apply(array $m, array $rule, bool $dry = false): array
{
    $out = [];
    $acts = $rule['acts'] ?? (array)(json_decode((string)($rule['actions'] ?? '[]'), true) ?: []);
    $id = (int)$m['id'];
    foreach ($acts as $a) {
        $v = $a['value'];
        switch ($a['type']) {
            case 'tag':
                $out[] = 'tag „' . $v . '”' . ($dry ? '' : (crm_mt_add($id, (string)$v) ? '' : ' (już był)'));
                break;
            case 'queue':
                $q = db_one("SELECT name FROM crm_mail_queues WHERE id = ? AND is_active = 1", [(int)$v]);
                if (!$q) { $out[] = 'kolejka #' . $v . ' — pominięto (nie istnieje lub wyłączona)'; break; }
                if (_crm_mr_thread_has($m, 'queue_id')) { $out[] = 'kolejka „' . $q['name'] . '” — pominięto (rozmowa ma już kolejkę)'; break; }
                if (!$dry) crm_mq_move($id, (int)$v);
                $out[] = 'kolejka „' . $q['name'] . '”';
                break;
            case 'assign':
                $u = db_one("SELECT name FROM users WHERE id = ? AND is_active = 1", [(int)$v]);
                if (!$u) { $out[] = 'opiekun #' . $v . ' — pominięto (nieaktywny)'; break; }
                if (_crm_mr_thread_has($m, 'assigned_to')) { $out[] = 'opiekun ' . $u['name'] . ' — pominięto (rozmowa ma już opiekuna)'; break; }
                if (uav_is_away((int)$v)) { $out[] = 'opiekun ' . $u['name'] . ' — pominięto (poza biurem)'; break; }
                if (!$dry && !crm_mailbox_assign($id, (int)$v)) { $out[] = 'opiekun ' . $u['name'] . ' — pominięto'; break; }
                $out[] = 'opiekun ' . $u['name'];
                break;
            case 'follow':
                $u = db_one("SELECT name FROM users WHERE id = ? AND is_active = 1", [(int)$v]);
                if (!$u) { $out[] = 'obserwujący #' . $v . ' — pominięto (nieaktywny)'; break; }
                if ($dry) { $out[] = 'obserwujący ' . $u['name']; break; }
                $out[] = 'obserwujący ' . $u['name'] . (crm_fol_add($id, (int)$v, null) ? '' : ' (już obserwuje lub brak dostępu do skrzynki)');
                break;
        }
    }
    return $out;
}

/** Reguły pasujące do wiadomości (do testu na stronie): [[rule, opisy akcji]], z uwzględnieniem „zatrzymaj po dopasowaniu”. */
function crm_mr_dry_run(array $m): array
{
    $res = [];
    foreach (crm_mr_list(true) as $r) {
        if (!crm_mr_match($m, $r)) continue;
        $res[] = ['rule' => $r, 'actions' => crm_mr_apply($m, $r, true)];
        if ((int)$r['stop_on_match']) break;
    }
    return $res;
}

function _crm_mr_log(array $rule, int $comm_id, array $summary): void
{
    db()->prepare("INSERT OR IGNORE INTO crm_mail_rule_log (rule_id, comm_id, summary, created_at) VALUES (?,?,?,?)")
        ->execute([(int)$rule['id'], $comm_id, mb_substr(implode('; ', $summary), 0, 300), date('Y-m-d H:i:s')]);
}

/** Odpowiedź w rozmowie, która ma jawną kolejkę, dziedziczy tę kolejkę (kolejka jest liczona per wiadomość). */
function crm_mr_inherit_queue(array $m): void
{
    $tk = trim((string)($m['thread_key'] ?? ''));
    if ($tk === '' || !empty($m['queue_id'])) return;
    $q = db_one("SELECT queue_id FROM crm_communications WHERE thread_key = ? AND id <> ? AND queue_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$tk, (int)$m['id']]);
    if ($q) db()->prepare("UPDATE crm_communications SET queue_id = ? WHERE id = ?")->execute([(int)$q['queue_id'], (int)$m['id']]);
}

/**
 * Przebieg reguł dla nowych wiadomości przychodzących. @return array{checked:int,matched:int,actions:int,errors:string[]}
 */
function crm_mr_run(int $limit = 100): array
{
    crm_mr_migrate();
    $out = ['checked' => 0, 'matched' => 0, 'actions' => 0, 'errors' => []];
    try {
        $since = org_setting('crm_rules_since_id');
        if ($since === '') {   // pierwszy przebieg: bez historii — znacznik na ostatniej istniejącej wiadomości
            $since = (string)(int)(db_one("SELECT MAX(id) AS n FROM crm_communications")['n'] ?? 0);
            org_setting_set('crm_rules_since_id', $since);
            return $out;
        }
        $rules = crm_mr_list(true);
        $rows = db_all("SELECT c.* FROM crm_communications c WHERE c.direction = 'in' AND c.id > ? AND NOT EXISTS (SELECT 1 FROM crm_mail_rule_done d WHERE d.comm_id = c.id) ORDER BY c.id LIMIT " . (int)$limit, [(int)$since]);
    } catch (\Throwable $e) { $out['errors'][] = $e->getMessage(); return $out; }
    foreach ($rows as $m) {
        $out['checked']++;
        try {
            crm_mr_inherit_queue($m);
            $m['queue_id'] = db_one("SELECT queue_id FROM crm_communications WHERE id = ?", [(int)$m['id']])['queue_id'] ?? null;
            $hit = false;
            foreach ($rules as $r) {
                if (!crm_mr_match($m, $r)) continue;
                $hit = true;
                $sum = crm_mr_apply($m, $r);
                _crm_mr_log($r, (int)$m['id'], $sum);
                $out['actions'] += count($sum);
                $m['queue_id'] = db_one("SELECT queue_id FROM crm_communications WHERE id = ?", [(int)$m['id']])['queue_id'] ?? null;
                if ((int)$r['stop_on_match']) break;
            }
            if ($hit) $out['matched']++;
        } catch (\Throwable $e) { $out['errors'][] = '#' . $m['id'] . ': ' . $e->getMessage(); }
        db()->prepare("INSERT OR IGNORE INTO crm_mail_rule_done (comm_id, processed_at) VALUES (?, ?)")->execute([(int)$m['id'], date('Y-m-d H:i:s')]);
    }
    return $out;
}

/** Ręczne zastosowanie jednej reguły do ostatnich wiadomości przychodzących (historia). @return array{checked:int,matched:int} */
function crm_mr_apply_existing(int $rule_id, int $limit = 500): array
{
    crm_mr_migrate();
    $out = ['checked' => 0, 'matched' => 0];
    $r = null;
    foreach (crm_mr_list(false) as $x) if ((int)$x['id'] === $rule_id) $r = $x;
    if (!$r) return $out;
    foreach (db_all("SELECT * FROM crm_communications WHERE direction = 'in' AND inbox_status IN ('active','archived') ORDER BY id DESC LIMIT " . (int)$limit) as $m) {
        $out['checked']++;
        if (!crm_mr_match($m, $r)) continue;
        $out['matched']++;
        _crm_mr_log($r, (int)$m['id'], crm_mr_apply($m, $r));
    }
    return $out;
}
