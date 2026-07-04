<?php
/** podpisy/serve.php — pobranie oryginału lub podpisanej wersji dokumentu. */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/doc_signing.php';

require_login();
require_module_enabled('doc_signing_enabled', 'Podpisz dokument');
doc_signing_migrate();

$id   = (int)($_GET['id'] ?? 0);
$kind = ($_GET['kind'] ?? '') === 'signed' ? 'signed' : 'original';

$row = ds_get($id);
if (!$row) { http_response_code(404); exit('Nie znaleziono dokumentu.'); }

$user = current_user();
if ((int)$row['user_id'] !== (int)$user['id'] && is_viewer()) {
    http_response_code(403); exit('Brak uprawnień do tego dokumentu.');
}

$filename = $kind === 'signed' ? $row['signed_filename'] : $row['original_filename'];
$origName = $kind === 'signed' ? $row['signed_name']     : $row['original_name'];
$mime     = $kind === 'signed' ? $row['signed_mime']     : $row['original_mime'];

if (!$filename) { http_response_code(404); exit('Plik nie istnieje.'); }

$path = ds_dir($id) . $filename;
if (!is_file($path)) { http_response_code(404); exit('Plik nie istnieje.'); }

header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0, no-cache');
header('Content-Disposition: attachment; filename="' . addslashes($origName ?: $filename) . '"');
readfile($path);
exit;
