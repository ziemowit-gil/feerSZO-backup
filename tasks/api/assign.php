<?php
/**
 * API: Przypisania użytkowników do zadań
 * POST JSON: { _csrf, action:'add'|'remove', task_id, user_id }
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';
require_once dirname(__DIR__, 2) . '/includes/task_notify.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body = task_parse_json_body();
task_csrf_check($body);

$action  = $body['action']  ?? '';
$task_id = (int)($body['task_id'] ?? 0);
$user_id = (int)($body['user_id'] ?? 0);

if (!$task_id || !$user_id) task_api_error('Brak task_id lub user_id.');

$task = db_one(
    "SELECT t.*, tl.workspace_id FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);
task_require_workspace_access((int)$task['workspace_id'], ['admin', 'editor']);

$target_user = db_one("SELECT id, name FROM users WHERE id=? AND is_active=1", [$user_id]);
if (!$target_user) task_api_error('Użytkownik nie istnieje lub jest nieaktywny.', 404);

if ($action === 'add') {
    try {
        db()->prepare(
            "INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by)
             VALUES (?, ?, ?)"
        )->execute([$task_id, $user_id, $uid]);

        // Użytkownik przypisany do zadania musi widzieć obszar roboczy.
        // INSERT OR IGNORE — nie nadpisuje istniejącej roli (admin/editor/viewer).
        db()->prepare(
            "INSERT OR IGNORE INTO task_workspace_members
             (workspace_id, user_id, role, added_by, added_at)
             VALUES (?, ?, 'member', ?, datetime('now','localtime'))"
        )->execute([(int)$task['workspace_id'], $user_id, $uid]);

    } catch (\Throwable $e) {
        task_api_error('Błąd zapisu przypisania: ' . $e->getMessage(), 500);
    }
    task_log($task_id, $uid, 'assigned', null, $target_user['name']);
    // Powiadomienie email (nie blokuje odpowiedzi)
    try { task_notify_assigned($task_id, $user_id, $uid); } catch (\Throwable $e) {}
    task_api_ok(['task_id' => $task_id, 'user_id' => $user_id]);
}

if ($action === 'remove') {
    db()->prepare("DELETE FROM task_assignments WHERE task_id=? AND user_id=?")
        ->execute([$task_id, $user_id]);
    task_log($task_id, $uid, 'unassigned', $target_user['name'], null);
    task_api_ok(['task_id' => $task_id, 'user_id' => $user_id]);
}

task_api_error('Nieznana akcja. Oczekiwano: add, remove.', 400);
