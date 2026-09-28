<?php
/**
 * API: Obserwowanie zadania (powiadomienia bez przypisania)
 * POST JSON: { _csrf, task_id, watch: true|false }
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

$task_id = (int)($body['task_id'] ?? 0);
if (!$task_id) task_api_error('Brak task_id.');

$task = db_one(
    "SELECT t.id, tl.workspace_id FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);
// Obserwować może każdy, kto widzi zadanie (także viewer)
task_require_workspace_access((int)$task['workspace_id']);

$on = !empty($body['watch']);
task_watch_set($task_id, $uid, $on);

task_api_ok(['watching' => $on, 'watchers' => array_map(
    fn($w) => ['id' => (int)$w['id'], 'name' => $w['name']],
    task_watchers($task_id)
)]);
