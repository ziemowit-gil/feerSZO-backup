<?php
/**
 * API: Okładka karty zadania na Kanbanie
 * POST JSON: { _csrf, task_id, file_id }  — file_id: >0 obrazek z załączników, 0 = automatycznie
 * (pierwszy obrazek), -1 = bez okładki. Uprawnienia jak przy zarządzaniu plikami zadania.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body = task_parse_json_body();
task_csrf_check($body);

$task_id = (int)($body['task_id'] ?? 0);
$file_id = (int)($body['file_id'] ?? 0);
if (!$task_id) task_api_error('Brak task_id.');

$task = db_one(
    "SELECT t.id, tl.workspace_id FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);
$ws_role = task_workspace_role((int)$task['workspace_id']);
if (!in_array($ws_role, ['admin', 'editor'], true)
    && !(task_field_editable('files', $ws_role) && task_is_assigned($task_id))) {
    task_api_error('Brak uprawnień do zmiany okładki.', 403);
}

task_extras_schema_heal();
if ($file_id > 0) {
    $f = db_one("SELECT id, mime_type FROM task_files WHERE id=? AND task_id=?", [$file_id, $task_id]);
    if (!$f || !in_array($f['mime_type'], TASK_COVER_MIMES, true)) task_api_error('Okładką może być tylko obrazek z załączników tego zadania.');
}
db()->prepare("UPDATE tasks SET cover_file_id=? WHERE id=?")
    ->execute([$file_id === 0 ? null : max(-1, $file_id), $task_id]);

task_api_ok(['cover_file_id' => $file_id]);
