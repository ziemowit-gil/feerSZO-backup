<?php
/**
 * API: Pliki zadań — upload / delete
 * Upload: multipart POST z polami: _csrf, task_id, file
 * Delete: JSON POST { _csrf, action:'delete', file_id, task_id }
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

// ── JSON body → delete ─────────────────────────────────────────────────────
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($content_type, 'application/json')) {
    $body = task_parse_json_body();
    task_csrf_check($body);

    if (($body['action'] ?? '') === 'delete') {
        $file_id = (int)($body['file_id'] ?? 0);
        $task_id = (int)($body['task_id'] ?? 0);
        if (!$file_id) task_api_error('Brak file_id.');

        $file = db_one("SELECT * FROM task_files WHERE id=?", [$file_id]);
        if (!$file) task_api_error('Plik nie istnieje.', 404);

        // Sprawdź dostęp do obszaru zadania
        $task = db_one("SELECT tl.workspace_id FROM tasks t JOIN task_lists tl ON tl.id=t.list_id WHERE t.id=?", [$file['task_id']]);
        if ($task) {
            $ws_role = task_workspace_role((int)$task['workspace_id']);
            if (!in_array($ws_role, ['admin', 'editor'], true)) {
                if (!task_field_editable('files', $ws_role) || !task_is_assigned((int)$file['task_id'])) {
                    task_api_error('Brak uprawnień do usunięcia pliku.', 403);
                }
            }
        }

        // Usuń fizyczny plik
        $path = __DIR__ . '/../../uploads/tasks/' . $file['stored_name'];
        if (file_exists($path)) @unlink($path);

        db()->prepare("DELETE FROM task_files WHERE id=?")->execute([$file_id]);
        task_log((int)$file['task_id'], $uid, 'deleted_file', $file['original_name']);
        task_api_ok(['file_id' => $file_id]);
    }

    task_api_error('Nieznana akcja.', 400);
}

// ── Multipart upload ───────────────────────────────────────────────────────
$csrf_post = $_POST['_csrf'] ?? '';
auth_start();
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf_post)) {
    task_api_error('Nieprawidłowy token CSRF.', 403);
}

$task_id = (int)($_POST['task_id'] ?? 0);
if (!$task_id) task_api_error('Brak task_id.');

$task = db_one(
    "SELECT t.*, tl.workspace_id FROM tasks t JOIN task_lists tl ON tl.id=t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);
$ws_role = task_workspace_role((int)$task['workspace_id']);
if (!in_array($ws_role, ['admin', 'editor'], true)) {
    if (!task_field_editable('files', $ws_role) || !task_is_assigned($task_id)) {
        task_api_error('Brak uprawnień do dodania pliku.', 403);
    }
}

// Walidacja pliku
if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $codes = [1=>'za duży (limit php.ini)',2=>'za duży (limit formularza)',
              3=>'przesłany częściowo',4=>'nie przesłano pliku'];
    $err = $codes[$_FILES['file']['error'] ?? 0] ?? 'błąd przesyłania';
    task_api_error('Błąd pliku: ' . $err);
}

$f    = $_FILES['file'];
$size = (int)$f['size'];
$chk  = task_upload_validate($f['tmp_name'], (string)$f['name'], $size);
if (is_string($chk)) task_api_error($chk);
['ext' => $ext, 'mime' => $mime] = $chk;

// Zapis na dysku
$dir = __DIR__ . '/../../uploads/tasks/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest   = $dir . $stored;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    task_api_error('Nie udało się zapisać pliku na serwerze.', 500);
}

$fid = db_insert('task_files', [
    'task_id'       => $task_id,
    'original_name' => mb_substr($f['name'], 0, 255),
    'stored_name'   => $stored,
    'file_size'     => $size,
    'mime_type'     => $mime,
    'uploaded_by'   => $uid,
    'created_at'    => date('Y-m-d H:i:s'),
]);

task_log($task_id, $uid, 'uploaded_file', null, $f['name']);
task_api_ok([
    'file_id'       => $fid,
    'original_name' => $f['name'],
    'stored_name'   => $stored,
    'file_size'     => $size,
]);
