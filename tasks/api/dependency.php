<?php
/**
 * API: Zależności zadań („czeka na”)
 * POST JSON: { _csrf, action: 'add'|'remove', task_id, blocked_by_id }
 * task_id nie powinno być kończone przed blocked_by_id. Oba w tym samym obszarze, bez cykli.
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

$action     = $body['action'] ?? '';
$task_id    = (int)($body['task_id'] ?? 0);
$blocker_id = (int)($body['blocked_by_id'] ?? 0);
if (!$task_id || !$blocker_id) task_api_error('Brak task_id lub blocked_by_id.');
if ($task_id === $blocker_id)  task_api_error('Zadanie nie może czekać samo na siebie.');

$rows = db_all(
    "SELECT t.id, t.title, tl.workspace_id FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id IN (?, ?) AND t.deleted_at IS NULL",
    [$task_id, $blocker_id]
);
$by_id = array_column($rows, null, 'id');
if (!isset($by_id[$task_id], $by_id[$blocker_id])) task_api_error('Zadanie nie istnieje.', 404);
$ws_id = (int)$by_id[$task_id]['workspace_id'];
if ($ws_id !== (int)$by_id[$blocker_id]['workspace_id']) {
    task_api_error('Zależność można ustawić tylko między zadaniami tego samego obszaru.');
}
task_require_workspace_access($ws_id, ['admin', 'editor']);
task_extras_schema_heal();

if ($action === 'add') {
    if (task_dependency_creates_cycle($task_id, $blocker_id)) {
        task_api_error('„' . $by_id[$blocker_id]['title'] . '” już (pośrednio) czeka na to zadanie — powstałby cykl.');
    }
    db()->prepare("INSERT OR IGNORE INTO task_dependencies (task_id, blocked_by_id, created_by) VALUES (?, ?, ?)")
        ->execute([$task_id, $blocker_id, $uid]);
    task_log($task_id, $uid, 'dependency_added', null, $by_id[$blocker_id]['title']);
    task_api_ok(['blockers' => task_blockers($task_id)]);
}

if ($action === 'remove') {
    db()->prepare("DELETE FROM task_dependencies WHERE task_id=? AND blocked_by_id=?")->execute([$task_id, $blocker_id]);
    task_log($task_id, $uid, 'dependency_removed', $by_id[$blocker_id]['title'], null);
    task_api_ok(['blockers' => task_blockers($task_id)]);
}

task_api_error('Nieznana akcja.', 400);
