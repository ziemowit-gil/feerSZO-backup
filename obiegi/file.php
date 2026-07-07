<?php
/**
 * obiegi/file.php — bezpieczne pobieranie pliku wniosku (upload lub plik z koszulki EZD).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/obiegi.php';

require_login();
require_module_enabled('obiegi_enabled', 'Moduł Obiegi');

$id = (int)($_GET['id'] ?? 0);
$f  = obiegi_file_get($id);
if (!$f) { http_response_code(404); echo 'Plik nie istnieje.'; exit; }

// Rozwiąż fizyczną ścieżkę
if ($f['source'] === 'ezd') {
    require_once dirname(__DIR__) . '/includes/ezd.php';
    $z = ezd_zal_get((int)$f['ezd_zalacznik_id']);
    if (!$z) { http_response_code(404); echo 'Plik EZD nie istnieje.'; exit; }
    $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $z['sprawa_id'] . '/' . $z['filename'];
    $mime = $z['mime_type'] ?: 'application/octet-stream';
    $name = $z['original_name'];
} else {
    $path = UPLOAD_DIR . $f['path'];
    $mime = $f['mime_type'] ?: 'application/octet-stream';
    $name = $f['original_name'];
}

if (!is_file($path)) { http_response_code(404); echo 'Plik nie znaleziony na serwerze.'; exit; }

$inline_mimes = ['application/pdf','image/png','image/jpeg','image/gif','image/webp','text/plain'];
$disposition  = (empty($_GET['dl']) && in_array($mime, $inline_mimes, true)) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
