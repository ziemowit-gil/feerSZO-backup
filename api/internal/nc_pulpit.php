<?php
/**
 * api/internal/nc_pulpit.php — dane kafla „SZO” na pulpicie Nextclouda
 * (aplikacja docker/nextcloud/apps/feer_poczta, widżet SzoWidget): komunikaty i moje zadania.
 *
 * GET ?uid=<uid w chmurze>&email=<e-mail konta w chmurze>&limit=7
 * → {"items":[{id,title,date,kategoria,pinned,read,url}], "all_url":"…", "unread":N,
 *    "tasks":[{id,title,due,overdue,ws,url}], "tasks_url":"…", "tasks_total":N, "szo_url":"…",
 *    "teams":[{id,name,unit,members,url}], "teams_url":"…"}
 * Zespoły: aktywne zespoły osoby (task_team_members) ze Struktury (org/teams.php), z jednostką.
 *
 * Osoba: sso_accounts.cloud_uid = uid, a gdy brak — users.email = email (aktywne konto).
 * Widoczność jak w /komunikaty (ann_list_for_user: audytorium, wygaśnięcie), bez komunikatów
 * tylko dla prowadzących TI i publicznych. Nieznana osoba → pusta lista.
 *
 * Zadania: tylko gdy moduł włączony (tasks_enabled).
 *
 * Zabezpieczenie: nagłówek X-Internal-Key = ustawienie roundcube_signature_key (ten sam klucz
 * co rc_signature.php / rc_directory.php; w chmurze: occ config:app:set feer_poczta szo_key).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key      = org_setting('roundcube_signature_key');
$incoming = (string)($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '');
if (strlen($key) < 32 || $incoming === '' || !hash_equals($key, $incoming)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$uid   = trim((string)($_GET['uid'] ?? ''));
$email = strtolower(trim((string)($_GET['email'] ?? '')));
$limit = max(1, min(20, (int)($_GET['limit'] ?? 7)));

$base = rtrim(APP_URL, '/');
$out = [
    'items' => [], 'all_url' => $base . '/komunikaty/index.php', 'unread' => 0,
    'tasks' => [], 'tasks_url' => $base . '/tasks/moje.php', 'tasks_total' => 0,
    'szo_url' => $base . '/',
    'teams' => [], 'teams_url' => $base . '/org/teams.php',
];

$user = null;
if ($uid !== '') {
    try {
        $user = db_one(
            "SELECT u.id, u.role FROM sso_accounts s JOIN users u ON u.id = s.user_id
             WHERE s.cloud_uid = ? AND u.is_active = 1 LIMIT 1", [$uid]) ?: null;
    } catch (\Throwable $e) {
        $user = null;
    }
}
if (!$user && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        $user = db_one(
            "SELECT id, role FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1", [$email]) ?: null;
    } catch (\Throwable $e) {
        $user = null;
    }
}

if ($user) {
    $list = array_values(array_filter(
        ann_list_for_user((int)$user['id'], (string)($user['role'] ?? 'viewer')),
        fn($a) => !in_array($a['audience'] ?? 'all', ['ti:prowadzacy', 'public'], true)
    ));
    foreach ($list as $a) {
        if (!(int)($a['is_read_by_me'] ?? 0)) $out['unread']++;
    }
    foreach (array_slice($list, 0, $limit) as $a) {
        $kat = (string)($a['kategoria'] ?? 'ogolne');
        $out['items'][] = [
            'id'        => (int)$a['id'],
            'title'     => (string)$a['title'],
            'date'      => substr((string)($a['created_at'] ?? ''), 0, 10),
            'kategoria' => ann_kategoria_label($kat),
            'pinned'    => (bool)(int)($a['is_pinned'] ?? 0),
            'read'      => (bool)(int)($a['is_read_by_me'] ?? 0),
            'url'       => $base . '/komunikaty/announcement.php?id=' . (int)$a['id'],
        ];
    }

    // Moje zadania (jak „Moje zadania” w panelu): przypisane, nieukończone; po terminie i pilne najpierw.
    $tasksOn = true;
    try {
        $tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
        $tasksOn = ($tm['value'] ?? '1') !== '0';
    } catch (\Throwable $e) {}
    if ($tasksOn) {
        try {
            $where = "FROM tasks t
                 JOIN task_assignments ta ON ta.task_id = t.id
                 JOIN task_workspaces tw ON tw.id = t.workspace_id
                 WHERE ta.user_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL";
            $out['tasks_total'] = (int)(db_one("SELECT COUNT(*) AS c $where", [(int)$user['id']])['c'] ?? 0);
            $rows = db_all(
                "SELECT t.id, t.title, t.due_date, tw.name AS ws_name $where
                 ORDER BY (t.due_date IS NULL), t.due_date ASC, t.priority DESC
                 LIMIT $limit", [(int)$user['id']]);
            $today = date('Y-m-d');
            foreach ($rows as $t) {
                $due = substr((string)($t['due_date'] ?? ''), 0, 10);
                $out['tasks'][] = [
                    'id'      => (int)$t['id'],
                    'title'   => (string)$t['title'],
                    'due'     => $due,
                    'overdue' => $due !== '' && $due < $today,
                    'ws'      => (string)($t['ws_name'] ?? ''),
                    'url'     => $base . '/tasks/index.php?task=' . (int)$t['id'],
                ];
            }
        } catch (\Throwable $e) {}
    }

    // Moje zespoły (Struktura Organizacyjna → Zespoły).
    try {
        $rows = db_all(
            "SELECT tt.id, tt.name, ou.name AS unit_name,
                    (SELECT COUNT(*) FROM task_team_members x WHERE x.team_id = tt.id) AS members
             FROM task_team_members tm
             JOIN task_teams tt ON tt.id = tm.team_id AND tt.is_active = 1
             LEFT JOIN org_units ou ON ou.id = tt.unit_id
             WHERE tm.user_id = ? ORDER BY tt.name LIMIT $limit", [(int)$user['id']]);
        foreach ($rows as $t) {
            $out['teams'][] = [
                'id'      => (int)$t['id'],
                'name'    => (string)$t['name'],
                'unit'    => (string)($t['unit_name'] ?? ''),
                'members' => (int)$t['members'],
                'url'     => $base . '/org/teams.php#team-' . (int)$t['id'],
            ];
        }
    } catch (\Throwable $e) {}
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
