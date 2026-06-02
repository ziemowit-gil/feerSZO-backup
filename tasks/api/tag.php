<?php
/**
 * API: Tagi zadań — przypnij / odepnij
 * POST JSON: { _csrf, action:'add'|'remove', task_id, tag_id }
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body = task_parse_json_body();
task_csrf_check($body);

$action  = $body['action']  ?? '';
$task_id = (int)($body['task_id'] ?? 0);
$tag_id  = (int)($body['tag_id']  ?? 0);

if (!$task_id || !$tag_id) task_api_error('Brak task_id lub tag_id.');

$task = db_one(
    "SELECT t.*, tl.workspace_id FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);
task_require_workspace_access((int)$task['workspace_id'], ['admin', 'editor']);

// Sprawdź, że tag istnieje i jest dostępny dla tego obszaru
$tag = db_one(
    "SELECT * FROM task_tags WHERE id=? AND is_active=1
     AND (workspace_id IS NULL OR workspace_id=?)",
    [$tag_id, $task['workspace_id']]
);
if (!$tag) task_api_error('Tag nie istnieje lub nie jest dostępny w tym obszarze.', 404);

if ($action === 'add') {
    try {
        db()->prepare(
            "INSERT OR IGNORE INTO task_task_tags (task_id, tag_id, assigned_by)
             VALUES (?, ?, ?)"
        )->execute([$task_id, $tag_id, $uid]);
    } catch (\Throwable $e) {
        task_api_error('Błąd zapisu tagu: ' . $e->getMessage(), 500);
    }
    task_log($task_id, $uid, 'tag_added', null, $tag['name']);
    task_api_ok(['task_id' => $task_id, 'tag_id' => $tag_id]);
}

if ($action === 'remove') {
    db()->prepare("DELETE FROM task_task_tags WHERE task_id=? AND tag_id=?")
        ->execute([$task_id, $tag_id]);
    task_log($task_id, $uid, 'tag_removed', $tag['name'], null);
    task_api_ok(['task_id' => $task_id, 'tag_id' => $tag_id]);
}

task_api_error('Nieznana akcja. Oczekiwano: add, remove.', 400);
