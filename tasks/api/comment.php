<?php
/**
 * API: Komentarze do zadań
 * POST JSON: { _csrf, action:'add'|'delete', task_id, body?, comment_id? }
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

$action = $body['action'] ?? '';

// ── Dodaj komentarz ────────────────────────────────────────────────────────
if ($action === 'add') {
    $task_id  = (int)($body['task_id'] ?? 0);
    $text     = trim($body['body'] ?? '');
    if (!$task_id || !$text) task_api_error('Brak task_id lub treści komentarza.');

    $task = db_one(
        "SELECT t.*, tl.workspace_id FROM tasks t
         JOIN task_lists tl ON tl.id = t.list_id
         WHERE t.id=? AND t.deleted_at IS NULL",
        [$task_id]
    );
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);
    task_require_workspace_access((int)$task['workspace_id']);

    $ws_role = task_workspace_role((int)$task['workspace_id']);
    if (!task_field_editable('comments', $ws_role)) {
        task_api_error('Brak uprawnień do dodawania komentarzy w tym obszarze.', 403);
    }

    $now = date('Y-m-d H:i:s');
    $cid = db_insert('task_comments', [
        'task_id'    => $task_id,
        'author_id'  => $uid,
        'body'       => $text,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    task_log($task_id, $uid, 'comment_added');
    // Powiadomienia email (nie blokują odpowiedzi)
    try { task_notify_new_comment($task_id, $cid, $text, $uid); } catch (\Throwable $e) {}

    $comment = db_one(
        "SELECT tc.*, u.name AS author_name FROM task_comments tc
         JOIN users u ON u.id = tc.author_id WHERE tc.id=?",
        [$cid]
    );
    task_api_ok($comment);
}

// ── Usuń komentarz (soft) ──────────────────────────────────────────────────
if ($action === 'delete') {
    $cid = (int)($body['comment_id'] ?? 0);
    if (!$cid) task_api_error('Brak comment_id.');

    $comment = db_one("SELECT * FROM task_comments WHERE id=? AND deleted_at IS NULL", [$cid]);
    if (!$comment) task_api_error('Komentarz nie istnieje.', 404);

    // Tylko autor lub admin może usunąć
    if ((int)$comment['author_id'] !== $uid && !is_admin()) {
        task_api_error('Brak uprawnień do usunięcia tego komentarza.', 403);
    }

    $now = date('Y-m-d H:i:s');
    db()->prepare("UPDATE task_comments SET deleted_at=?, updated_at=? WHERE id=?")
        ->execute([$now, $now, $cid]);

    task_api_ok(['comment_id' => $cid]);
}

task_api_error('Nieznana akcja.', 400);
