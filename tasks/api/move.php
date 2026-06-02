<?php
/**
 * API: Przesuń zadanie między kolumnami (drag & drop)
 * POST JSON: { _csrf, task_id, list_id, position, ordered_ids[] }
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

$task_id     = (int)($body['task_id']  ?? 0);
$new_list_id = (int)($body['list_id']  ?? 0);
$ordered_ids = array_map('intval', $body['ordered_ids'] ?? []);

if (!$task_id || !$new_list_id) task_api_error('Brak task_id lub list_id.');

// Sprawdź dostęp
$task = db_one(
    "SELECT t.*, tl.workspace_id FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);

task_require_workspace_access((int)$task['workspace_id'], ['admin', 'editor']);

// Sprawdź, że nowa lista należy do tego samego obszaru
$new_list = db_one(
    "SELECT * FROM task_lists WHERE id=? AND workspace_id=?",
    [$new_list_id, $task['workspace_id']]
);
if (!$new_list) task_api_error('Lista nie istnieje w tym obszarze.', 404);

// Przenumeruj zadania w nowej kolumnie
$new_pos = (float)($body['position'] ?? 1);

try {
    $updated = task_move($task_id, $new_list_id, $new_pos, $uid);
} catch (\Throwable $e) {
    task_api_error('Błąd zapisu: ' . $e->getMessage(), 500);
}

// Przenumeruj wszystkie karty w nowej kolumnie (utrzymaj porządek)
if ($ordered_ids) {
    task_reorder_list($new_list_id, $ordered_ids);
}

task_api_ok($updated);
