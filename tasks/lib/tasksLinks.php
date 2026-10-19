<?php
/**
 * tasks/lib/tasksLinks.php — powiązania zadania ze sprawą w innych modułach, widoczne w obie strony.
 *
 * task_links(task_id, entity_type, entity_ref):
 *   ezd_sprawa  — koszulka EZD (ezd_sprawy.id; sprawa = Koszulka, JRWA = Segregator),
 *   crm_contact — kontakt / firma CRM (crm_contacts.id),
 *   ti_client   — uczestnik TI (k30_clients.id; karta: panel dydaktyka › Konta),
 *   contract    — umowa ('typ:id', tabela umowy_{typ}); dodatkowo tasks.contract_type/contract_id, z których
 *                 korzysta zakładka „Zadania” w kartach umów zlecenia/wolontariatu.
 * Etykiety i linki pokazujemy tylko z uprawnieniem do danego rekordu; listy zadań na kartach — tylko zadania
 * z obszarów, w których użytkownik ma rolę (task_workspace_role).
 */
require_once __DIR__ . '/tasksCreate.php';

const TASKS_LINK_TYPES = [
    'ezd_sprawa'  => 'Koszulka EZD',
    'crm_contact' => 'Kontakt CRM',
    'ti_client'   => 'Uczestnik TI',
    'contract'    => 'Umowa',
];
const TASKS_LINK_CONTRACT_TYPES = ['wolontariat', 'zlecenie', 'dzielo', 'praca', 'inne', 'powierzenie', 'uslugi'];

function tasksLinksMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS task_links (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id     INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
        entity_type TEXT NOT NULL,
        entity_ref  TEXT NOT NULL,
        created_by  INTEGER,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(task_id, entity_type, entity_ref)
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_task_links_entity ON task_links(entity_type, entity_ref)");
}

/** Normalizacja i walidacja odwołania; null = niepoprawne. */
function tasksLinksRef(string $type, string $ref): ?string
{
    if (!isset(TASKS_LINK_TYPES[$type])) return null;
    if ($type === 'contract') {
        return preg_match('/^(' . implode('|', TASKS_LINK_CONTRACT_TYPES) . '):(\d+)$/', $ref, $m) && (int)$m[2] > 0 ? $m[1] . ':' . (int)$m[2] : null;
    }
    return ctype_digit($ref) && (int)$ref > 0 ? (string)(int)$ref : null;
}

/**
 * Opis rekordu dla bieżącego użytkownika: ['label' => …, 'title' => …, 'url' => …, 'can' => bool, 'exists' => bool].
 * $uid — użytkownik SZO (w panelu dydaktyka: $me['user_id']); uprawnienia modułów SZO liczone dla zalogowanej sesji SZO.
 */
function tasksLinksDescribe(string $type, string $ref): array
{
    $out = ['label' => TASKS_LINK_TYPES[$type] ?? $type, 'title' => '', 'url' => '', 'can' => false, 'exists' => false];
    $root = dirname(__DIR__, 2);
    try {
        switch ($type) {
            case 'ezd_sprawa':
                require_once $root . '/includes/ezd.php';
                $s = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [(int)$ref]);
                if (!$s) break;
                $out['exists'] = true;
                $uid = (int)(current_user()['id'] ?? 0);
                $out['can'] = $uid && (can_read('ezd') || can_write('ezd')) && ezd_sprawa_access($s, $uid) !== null;
                $out['title'] = trim(($s['znak_sprawy'] ?? '') . ' — ' . ($s['title'] ?? ''), ' —');
                $out['url'] = APP_URL . '/ezd/sprawy/view.php?id=' . (int)$ref;
                break;
            case 'crm_contact':
                require_once $root . '/includes/crm_perms.php';
                $c = db_one("SELECT * FROM crm_contacts WHERE id=?", [(int)$ref]);
                if (!$c) break;
                $out['exists'] = true;
                if (!function_exists('crm_can_access_contact')) require_once $root . '/includes/crm.php';
                $out['can'] = crm_can('contacts', 'read') && (!function_exists('crm_can_access_contact') || crm_can_access_contact((int)$c['id']));
                $orgLike = defined('CRM_CONTACT_TYPES') ? !empty(CRM_CONTACT_TYPES[$c['type'] ?? '']['org_like']) : ($c['type'] ?? '') === 'organizacja';
                $out['label'] = $orgLike ? 'Firma / organizacja CRM' : 'Kontakt CRM';
                $out['title'] = (string)($c['imie_nazwisko'] ?: trim(($c['imie'] ?? '') . ' ' . ($c['nazwisko'] ?? '')) ?: ($c['organizacja'] ?? ''));
                $out['url'] = APP_URL . '/crm/contact/view.php?id=' . (int)$ref;
                break;
            case 'ti_client':
                $c = db_one("SELECT id, name FROM k30_clients WHERE id=?", [(int)$ref]);
                if (!$c) break;
                $out['exists'] = true;
                $out['can'] = is_admin() || can_read('karty30') || can_write('karty30');
                $out['title'] = (string)$c['name'];
                $acc = db_one("SELECT MIN(id) AS id FROM k30_ti_student_accounts WHERE client_id=?", [(int)$ref]);
                $out['url'] = APP_URL . '/karty30/ti/dydaktyk/konta.php' . (!empty($acc['id']) ? '?selected=' . (int)$acc['id'] : '');
                break;
            case 'contract':
                [$ctype, $cid] = explode(':', $ref, 2) + [1 => 0];
                if (!in_array($ctype, TASKS_LINK_CONTRACT_TYPES, true)) break;
                $row = db_one("SELECT * FROM umowy_{$ctype} WHERE id=?", [(int)$cid]);
                if (!$row) break;
                $out['exists'] = true;
                $out['can'] = viewer_owns_contract($ctype, $row);
                $out['label'] = 'Umowa (' . $ctype . ')';
                $out['title'] = trim(($row['nr_rejestru'] ?: ($row['numer_umowy'] ?? '')) . ' — '
                    . ($row['imie_nazwisko'] ?? $row['nazwa_zadania'] ?? ''), ' —');
                $out['url'] = APP_URL . '/contracts/' . $ctype . '/view.php?id=' . (int)$cid;
                break;
        }
    } catch (\Throwable $e) {
        error_log('[tasksLinks] ' . $type . ':' . $ref . ' — ' . $e->getMessage());
    }
    return $out;
}

/** Powiązania zadania (z task_links i z tasks.contract_type/contract_id). */
function tasksLinksOf(int $taskId): array
{
    tasksLinksMigrate();
    $rows = db_all("SELECT entity_type, entity_ref FROM task_links WHERE task_id=? ORDER BY id", [$taskId]);
    $t = db_one("SELECT contract_type, contract_id FROM tasks WHERE id=?", [$taskId]);
    if ($t && $t['contract_type'] && (int)$t['contract_id']) {
        $ref = $t['contract_type'] . ':' . (int)$t['contract_id'];
        if (!in_array(['entity_type' => 'contract', 'entity_ref' => $ref], $rows, true)) $rows[] = ['entity_type' => 'contract', 'entity_ref' => $ref];
    }
    return $rows;
}

function tasksLinksAdd(int $taskId, string $type, string $ref, int $byUid): bool
{
    tasksLinksMigrate();
    $ref = tasksLinksRef($type, $ref);
    if ($ref === null) return false;
    $st = db()->prepare("INSERT OR IGNORE INTO task_links (task_id, entity_type, entity_ref, created_by, created_at) VALUES (?,?,?,?,?)");
    $st->execute([$taskId, $type, $ref, $byUid, date('Y-m-d H:i:s')]);
    if ($type === 'contract') {
        [$ctype, $cid] = explode(':', $ref, 2);
        db()->prepare("UPDATE tasks SET contract_type=?, contract_id=? WHERE id=? AND (contract_type IS NULL OR contract_type='')")
            ->execute([$ctype, (int)$cid, $taskId]);
    }
    if ($st->rowCount()) {
        $d = tasksLinksDescribe($type, $ref);
        task_log($taskId, $byUid, 'linked', null, $d['label'] . ($d['title'] !== '' ? ': ' . $d['title'] : ''), ['type' => $type, 'ref' => $ref]);
    }
    return true;
}

function tasksLinksRemove(int $taskId, string $type, string $ref, int $byUid): void
{
    tasksLinksMigrate();
    db()->prepare("DELETE FROM task_links WHERE task_id=? AND entity_type=? AND entity_ref=?")->execute([$taskId, $type, $ref]);
    if ($type === 'contract') {
        [$ctype, $cid] = explode(':', $ref, 2) + [1 => 0];
        db()->prepare("UPDATE tasks SET contract_type=NULL, contract_id=NULL WHERE id=? AND contract_type=? AND contract_id=?")
            ->execute([$taskId, $ctype, (int)$cid]);
    }
    $d = tasksLinksDescribe($type, $ref);
    task_log($taskId, $byUid, 'unlinked', $d['label'] . ($d['title'] !== '' ? ': ' . $d['title'] : ''), null, ['type' => $type, 'ref' => $ref]);
}

/** Rozpoznaje wklejony adres karty (koszulka, kontakt, umowa, konto uczestnika) → [type, ref] albo null. */
function tasksLinksParseUrl(string $url): ?array
{
    $p = parse_url(trim($url));
    $path = (string)($p['path'] ?? '');
    parse_str((string)($p['query'] ?? ''), $q);
    if (preg_match('#/ezd/sprawy/[a-z_]+\.php$#', $path) && !empty($q['id'])) return ['ezd_sprawa', (string)(int)$q['id']];
    if (preg_match('#/crm/contact/view\.php$#', $path) && !empty($q['id'])) return ['crm_contact', (string)(int)$q['id']];
    if (preg_match('#/contracts/([a-z]+)/view\.php$#', $path, $m) && in_array($m[1], TASKS_LINK_CONTRACT_TYPES, true) && !empty($q['id'])) {
        return ['contract', $m[1] . ':' . (int)$q['id']];
    }
    if (preg_match('#/modules/contract_card/router\.php/([a-z]+)/(\d+)#', $path, $m) && in_array($m[1], TASKS_LINK_CONTRACT_TYPES, true)) {
        return ['contract', $m[1] . ':' . (int)$m[2]];
    }
    if (preg_match('#/karty30/ti/dydaktyk/konta\.php$#', $path)) {
        $acc = (int)($q['selected'] ?? $q['guardian'] ?? $q['authp'] ?? 0);
        $cl = $acc ? db_one("SELECT client_id FROM k30_ti_student_accounts WHERE id=?", [$acc]) : null;
        if ($cl && (int)$cl['client_id']) return ['ti_client', (string)(int)$cl['client_id']];
    }
    return null;
}

/**
 * Zadania powiązane z rekordem, widoczne dla użytkownika (rola w obszarze zadania).
 * @return array{tasks: array}
 */
function tasksLinksTasksFor(string $type, string $ref, int $uid): array
{
    tasksLinksMigrate();
    task_extras_schema_heal();
    $ref = tasksLinksRef($type, $ref);
    if ($ref === null) return ['tasks' => []];
    $sql = "SELECT t.id, t.title, t.priority, t.due_date, t.due_time, t.completed_at, t.workspace_id, t.archived_at,
                   tl.name AS list_name, tl.is_done_state, w.name AS ws_name, w.color AS ws_color
            FROM tasks t JOIN task_lists tl ON tl.id=t.list_id JOIN task_workspaces w ON w.id=t.workspace_id AND w.is_active=1
            WHERE t.deleted_at IS NULL AND (t.id IN (SELECT task_id FROM task_links WHERE entity_type=? AND entity_ref=?)";
    $params = [$type, $ref];
    if ($type === 'contract') {
        [$ctype, $cid] = explode(':', $ref, 2);
        $sql .= " OR (t.contract_type=? AND t.contract_id=?)";
        array_push($params, $ctype, (int)$cid);
    }
    $sql .= ") ORDER BY (t.completed_at IS NOT NULL), t.due_date IS NULL, t.due_date, t.id DESC";
    $roles = [];
    $out = [];
    foreach (db_all($sql, $params) as $t) {
        $ws = (int)$t['workspace_id'];
        $roles[$ws] ??= task_workspace_role($ws, $uid);
        if (!$roles[$ws]) continue;
        $t['overdue'] = task_is_overdue($t);
        $out[] = $t;
    }
    return ['tasks' => $out];
}

/** Adres formularza „Nowe zadanie” z prewypełnionym powiązaniem i powrotem na kartę. */
function tasksLinksNewUrl(string $type, string $ref, string $back = ''): string
{
    return APP_URL . '/tasks/router.php/nowe?' . http_build_query(array_filter(['link' => $type . ':' . $ref, 'back' => $back ?: null]));
}
