<?php
/**
 * API: Weź / Oddaj zadanie (self-assign)
 * POST { _csrf, task_id, action: 'add'|'remove' }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();

$body = task_parse_json_body();
task_csrf_check($body);

$uid     = (int)(current_user()['id'] ?? 0);
$task_id = (int)($body['task_id'] ?? 0);
$action  = $body['action'] ?? 'add'; // 'add' | 'remove'

if (!$task_id) task_api_error('Brak task_id.');
if (!in_array($action, ['add', 'remove'], true)) task_api_error('Nieprawidłowa akcja.');

$task = db_one(
    "SELECT t.*, tl.workspace_id, tl.name AS list_name
     FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id = ? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);

task_require_workspace_access((int)$task['workspace_id']);

if ($task['completed_at']) task_api_error('Zadanie jest już ukończone.');

$now = date('Y-m-d H:i:s');

if ($action === 'add') {
    // task_assignments ma composite PK (task_id, user_id) — bez kolumny id
    $existing = db_one(
        "SELECT task_id FROM task_assignments WHERE task_id = ? AND user_id = ?",
        [$task_id, $uid]
    );
    if ($existing) task_api_error('Jesteś już przypisany do tego zadania.');

    $role = task_workspace_role((int)$task['workspace_id'], $uid);
    if (!in_array($role, ['admin', 'editor'], true)) {
        // Viewer/brak roli: może wziąć zadanie tylko gdy jest wolne LUB oznaczone jako claimable
        $is_claimable = (bool)($task['claimable'] ?? false);
        if (!$is_claimable) {
            $cnt = (int)(db_one(
                "SELECT COUNT(*) AS n FROM task_assignments WHERE task_id = ?",
                [$task_id]
            )['n'] ?? 0);
            if ($cnt > 0) {
                task_api_error('To zadanie jest już zajęte. Skontaktuj się z koordynatorem.');
            }
        }
    }

    // INSERT bezpośredni — composite PK, db_insert zwróci 0 ale wykona INSERT
    db()->prepare(
        "INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by, assigned_at)
         VALUES (?, ?, ?, ?)"
    )->execute([$task_id, $uid, $uid, $now]);

    task_log($task_id, $uid, 'assigned', null, current_user()['name']);
    _claim_notify($task, current_user(), 'taken');

} else {
    db()->prepare(
        "DELETE FROM task_assignments WHERE task_id = ? AND user_id = ?"
    )->execute([$task_id, $uid]);

    task_log($task_id, $uid, 'unassigned', current_user()['name'], null);
    _claim_notify($task, current_user(), 'released');
}

task_api_ok();

// ── E-mail ────────────────────────────────────────────────────────────────
function _claim_notify(array $task, array $actor, string $event): void {
    try {
        $org   = defined('ORG_NAME') ? ORG_NAME : 'System';
        $url   = rtrim(APP_URL, '/') . '/tasks/index.php';
        $title = $task['title'];
        $name  = $actor['name'];

        $admins = db_all(
            "SELECT u.email, u.name
             FROM task_workspace_members twm
             JOIN users u ON u.id = twm.user_id
             WHERE twm.workspace_id = ? AND twm.role IN ('admin','editor') AND u.is_active = 1",
            [(int)$task['workspace_id']]
        );
        $sys_admins = db_all("SELECT email, name FROM users WHERE role='admin' AND is_active=1");

        $seen = [];
        $recipients = [];
        foreach (array_merge($admins, $sys_admins) as $r) {
            $e = $r['email'] ?? '';
            if ($e && !isset($seen[$e])) { $seen[$e] = true; $recipients[] = $r; }
        }

        if ($event === 'taken') {
            $subject = "[{$org}] Zadanie podjete: {$title}";
            $body    = "{$name} wziął/wzięła zadanie: \"{$title}\".\n\nGielda zadan: {$url}";
        } else {
            $subject = "[{$org}] Zadanie oddane: {$title}";
            $body    = "{$name} oddał/oddała zadanie: \"{$title}\".\n\nZadanie jest ponownie wolne: {$url}";
        }

        $headers = "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n"
                 . "Content-Type: text/plain; charset=utf-8\r\n";

        foreach ($recipients as $r) {
            if (!empty($r['email'])) @mail($r['email'], $subject, $body, $headers);
        }

        if ($event === 'taken' && !empty($actor['email'])) {
            @mail(
                $actor['email'],
                "[{$org}] Potwierdzenie przypisania zadania",
                "Czesc {$name},\n\nPotwierdzamy przypisanie zadania \"{$title}\".\n\nSzczegoly: {$url}",
                $headers
            );
        }
    } catch (\Throwable $e) { /* nie blokuj */ }
}
