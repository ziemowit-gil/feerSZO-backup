<?php
/**
 * API: Podzadania
 * POST JSON: { _csrf, action, task_id, ... }
 * Akcje: add | toggle | rename | delete | reorder
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
if (!$task_id) task_api_error('Brak task_id.');

// Sprawdź zadanie i uprawnienia
$task = db_one(
    "SELECT t.*, tl.workspace_id FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);

// toggle: member/viewer może zaznaczać podzadania na swoich zadaniach
// add/rename/delete/reorder: tylko admin/editor
$_sub_ws_role = task_workspace_role((int)$task['workspace_id'], $uid);
if ($action === 'toggle') {
    if (!in_array($_sub_ws_role, ['admin', 'editor'], true)) {
        if (!in_array($_sub_ws_role, ['member', 'viewer'], true) || !task_is_assigned($task_id, $uid)) {
            task_api_error('Brak uprawnień.', 403);
        }
    }
} else {
    if (!in_array($_sub_ws_role, ['admin', 'editor'], true)) {
        task_api_error('Brak uprawnień.', 403);
    }
}

// ── Dodaj podzadanie ────────────────────────────────────────────────────────
if ($action === 'add') {
    $title = trim($body['title'] ?? '');
    if (!$title) task_api_error('Brak tytułu podzadania.');

    $max = db_one("SELECT MAX(position) AS m FROM task_subtasks WHERE task_id=?", [$task_id]);
    $pos = (float)($max['m'] ?? 0) + 1;

    $id = db_insert('task_subtasks', [
        'task_id'    => $task_id,
        'title'      => $title,
        'is_done'    => 0,
        'position'   => $pos,
        'created_by' => $uid,
    ]);

    task_log($task_id, $uid, 'subtask_added', null, $title);
    task_api_ok(db_one("SELECT * FROM task_subtasks WHERE id=?", [$id]));
}

// ── Przełącz ukończenie ─────────────────────────────────────────────────────
if ($action === 'toggle') {
    $st_id  = (int)($body['subtask_id'] ?? 0);
    $is_done = (int)($body['is_done']  ?? 0) ? 1 : 0;
    if (!$st_id) task_api_error('Brak subtask_id.');

    $st = db_one("SELECT * FROM task_subtasks WHERE id=? AND task_id=?", [$st_id, $task_id]);
    if (!$st) task_api_error('Podzadanie nie istnieje.', 404);

    $now = date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE task_subtasks SET is_done=?, completed_at=? WHERE id=?"
    )->execute([$is_done, $is_done ? $now : null, $st_id]);

    task_api_ok(['id' => $st_id, 'is_done' => $is_done]);
}

// ── Zmień nazwę podzadania ──────────────────────────────────────────────────
if ($action === 'rename') {
    $st_id = (int)($body['subtask_id'] ?? 0);
    $title = trim($body['title'] ?? '');
    if (!$st_id) task_api_error('Brak subtask_id.');
    if (!$title) task_api_error('Tytuł nie może być pusty.');

    $st = db_one("SELECT * FROM task_subtasks WHERE id=? AND task_id=?", [$st_id, $task_id]);
    if (!$st) task_api_error('Podzadanie nie istnieje.', 404);

    db()->prepare("UPDATE task_subtasks SET title=? WHERE id=?")->execute([$title, $st_id]);
    task_api_ok(['id' => $st_id, 'title' => $title]);
}

// ── Usuń podzadanie ─────────────────────────────────────────────────────────
if ($action === 'delete') {
    $st_id = (int)($body['subtask_id'] ?? 0);
    if (!$st_id) task_api_error('Brak subtask_id.');

    $st = db_one("SELECT * FROM task_subtasks WHERE id=? AND task_id=?", [$st_id, $task_id]);
    if (!$st) task_api_error('Podzadanie nie istnieje.', 404);

    db()->prepare("DELETE FROM task_subtasks WHERE id=?")->execute([$st_id]);
    task_log($task_id, $uid, 'subtask_deleted', $st['title'], null);
    task_api_ok(['id' => $st_id]);
}

// ── Zmień kolejność ─────────────────────────────────────────────────────────
if ($action === 'reorder') {
    $ids = array_map('intval', (array)($body['ordered_ids'] ?? []));
    $pos = 1;
    $stmt = db()->prepare("UPDATE task_subtasks SET position=? WHERE id=? AND task_id=?");
    foreach ($ids as $sid) {
        $stmt->execute([$pos++, $sid, $task_id]);
    }
    task_api_ok(['reordered' => count($ids)]);
}

task_api_error('Nieznana akcja.', 400);
