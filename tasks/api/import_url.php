<?php
/**
 * API: Import pliku z OneDrive / URL Microsoft
 * POST JSON: { _csrf, task_id, od_item_id }      — plik z OneDrive użytkownika (okno modules/onedrive_picker)
 *        lub { _csrf, task_id, url, filename }   — plik z zaufanego URL Microsoft
 * Zapisuje plik jak normalny upload.
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

$task_id    = (int)($body['task_id'] ?? 0);
$od_item_id = trim((string)($body['od_item_id'] ?? ''));
$url        = trim($body['url']      ?? '');
$filename   = trim($body['filename'] ?? 'plik');

if (!$task_id)                      task_api_error('Brak task_id.');
if (!$url && $od_item_id === '')    task_api_error('Brak URL.');

// ── Weryfikacja że URL pochodzi z Microsoft (OneDrive po ID nie podaje adresu) ─
if ($od_item_id === '') {
    $parsed = parse_url($url);
    $host   = strtolower($parsed['host'] ?? '');
    $trusted_suffixes = [
        '.sharepoint.com', '.microsoft.com', '.office.com',
        '.1drv.ms', 'onedrive.live.com', 'graph.microsoft.com',
    ];
    $ok = false;
    foreach ($trusted_suffixes as $sfx) {
        if (str_ends_with($host, $sfx) || $host === ltrim($sfx, '.')) {
            $ok = true;
            break;
        }
    }
    if (!$ok) task_api_error('URL nie pochodzi z zaufanego serwisu Microsoft.', 403);
}

// ── Sprawdź dostęp do zadania ─────────────────────────────────────────────
$task = db_one(
    "SELECT t.*, tl.workspace_id FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);
task_require_workspace_access((int)$task['workspace_id'], ['admin', 'editor']);

// ── Pobierz plik ──────────────────────────────────────────────────────────
$tmpfile = tempnam(sys_get_temp_dir(), 'od_import_');
$downloaded = false;

if ($od_item_id !== '') {
    // OneDrive zalogowanego użytkownika (Graph, po stronie serwera) — tylko jego własny dysk
    require_once dirname(__DIR__, 2) . '/modules/onedrive_picker/lib/OneDrivePicker.php';
    try {
        $od       = (new OneDrivePicker())->fetch($od_item_id, TASK_UPLOAD_MAX_BYTES);
        $filename = $od['name'];
        $downloaded = file_put_contents($tmpfile, $od['bytes']) !== false;
    } catch (\Throwable $e) {
        @unlink($tmpfile);
        task_api_error($e->getMessage(), 502);
    }
} elseif (function_exists('curl_init')) {
    $ch = curl_init($url);
    $fh = fopen($tmpfile, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'FEER-Tasks/1.0',
    ]);
    curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    $downloaded = ($http_code >= 200 && $http_code < 300);
} else {
    $ctx = stream_context_create(['http' => [
        'timeout'      => 30,
        'user_agent'   => 'FEER-Tasks/1.0',
        'ignore_errors'=> false,
    ], 'ssl' => ['verify_peer' => true]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false) {
        file_put_contents($tmpfile, $data);
        $downloaded = true;
    }
}

if (!$downloaded) {
    @unlink($tmpfile);
    task_api_error('Nie udało się pobrać pliku z OneDrive.', 502);
}

// ── Walidacja rozmiaru i typu (wspólne reguły: task_upload_validate) ─────
$size = (int)filesize($tmpfile);
$chk  = task_upload_validate($tmpfile, $filename, $size);
if (is_string($chk)) {
    @unlink($tmpfile);
    task_api_error($chk, 422);
}
['ext' => $ext, 'mime' => $mime] = $chk;

// ── Zapis do uploads/tasks/ ───────────────────────────────────────────────
$dir = __DIR__ . '/../../uploads/tasks/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest   = $dir . $stored;

if (!rename($tmpfile, $dest)) {
    copy($tmpfile, $dest);
    @unlink($tmpfile);
}

$clean_name = mb_substr(basename($filename), 0, 255);
$fid = db_insert('task_files', [
    'task_id'       => $task_id,
    'original_name' => $clean_name,
    'stored_name'   => $stored,
    'file_size'     => $size,
    'mime_type'     => $mime,
    'uploaded_by'   => $uid,
    'created_at'    => date('Y-m-d H:i:s'),
]);

task_log($task_id, $uid, 'uploaded_file', null, $clean_name . ' (OneDrive)');
try {
    require_once dirname(__DIR__, 2) . '/includes/task_notify.php';
    task_notify_file_added($task_id, $clean_name, $uid);
} catch (\Throwable $e) {}
task_api_ok([
    'file_id'       => $fid,
    'original_name' => $clean_name,
    'stored_name'   => $stored,
    'file_size'     => $size,
]);
