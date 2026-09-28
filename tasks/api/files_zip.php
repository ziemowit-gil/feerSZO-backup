<?php
/**
 * API: Pobranie wszystkich załączników zadania jako ZIP.
 * GET ?task=N — tylko lokalne załączniki (task_files); pliki z Koszulek (SharePoint) nie są
 * pobierane z Graph na potrzeby archiwum, ich lista trafia do pliku _Koszulki.txt w ZIP-ie.
 * Uprawnienia jak przy podglądzie załącznika (tasks/api/file.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();

function _zip_fail(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!class_exists('ZipArchive')) _zip_fail(500, 'Serwer nie obsługuje archiwów ZIP.');

$task_id = (int)($_GET['task'] ?? 0);
$task = $task_id ? db_one(
    "SELECT t.id, t.title, tl.workspace_id FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$task_id]
) : null;
if (!$task) _zip_fail(404, 'Zadanie nie istnieje.');

$ws_role = task_workspace_role((int)$task['workspace_id']);
if (!$ws_role || !task_field_visible('files', $ws_role)) _zip_fail(403, 'Brak dostępu do plików zadania.');

$files = db_all("SELECT original_name, stored_name FROM task_files WHERE task_id=? ORDER BY created_at, id", [$task_id]);
$ws_files = [];
if (db_one("SELECT 1 AS x FROM sqlite_master WHERE type='table' AND name='ws_task_files'")) {
    $ws_files = db_all(
        "SELECT COALESCE(NULLIF(wf.original_name, ''), wf.name) AS name, wf.web_url
         FROM ws_task_files wtf JOIN ws_files wf ON wf.id = wtf.file_id AND wf.deleted_at IS NULL
         WHERE wtf.task_id = ?",
        [$task_id]
    );
}
if (!$files && !$ws_files) _zip_fail(404, 'Zadanie nie ma plików.');

$tmp = tempnam(sys_get_temp_dir(), 'taskzip_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) _zip_fail(500, 'Nie udało się utworzyć archiwum.');

$dir   = dirname(__DIR__, 2) . '/uploads/tasks/';
$used  = [];
$added = 0;
foreach ($files as $f) {
    $path = $dir . basename((string)$f['stored_name']);
    if (!is_file($path)) continue;
    // Nazwa w archiwum: bez ścieżek i znaków sterujących; duplikaty → „nazwa (2).ext”
    $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', basename((string)$f['original_name'])) ?: 'plik';
    $base = pathinfo($name, PATHINFO_FILENAME); $ext = pathinfo($name, PATHINFO_EXTENSION);
    for ($i = 2; isset($used[mb_strtolower($name)]); $i++) $name = $base . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
    $used[mb_strtolower($name)] = true;
    $zip->addFile($path, $name);
    $added++;
}
if ($ws_files) {
    $txt = "Pliki z Koszulek powiązane z zadaniem (przechowywane w SharePoint, nie dołączone do archiwum):\r\n\r\n";
    foreach ($ws_files as $w) $txt .= '- ' . $w['name'] . ($w['web_url'] ? "\r\n  " . $w['web_url'] : '') . "\r\n";
    $zip->addFromString('_Koszulki.txt', $txt);
}
$zip->close();

if (!$added && !$ws_files) { @unlink($tmp); _zip_fail(404, 'Pliki zadania nie istnieją na serwerze.'); }

$zip_name = 'Zadanie ' . $task_id . ' - ' . mb_strimwidth(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $task['title']), 0, 60, '') . '.zip';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($tmp));
header('Content-Disposition: attachment; filename="zadanie_' . $task_id . '.zip"; filename*=UTF-8\'\'' . rawurlencode($zip_name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($tmp);
@unlink($tmp);
exit;
