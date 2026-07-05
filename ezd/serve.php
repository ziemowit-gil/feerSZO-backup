<?php
/**
 * Secure EZD attachment serving.
 * Checks authentication before streaming the file.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko');

$id = (int)($_GET['id'] ?? 0);
$z  = ezd_zal_get($id);

if (!$z) {
    http_response_code(404); echo 'Plik nie istnieje.'; exit;
}

$path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $z['sprawa_id'] . '/' . $z['filename'];

if (!is_file($path)) {
    http_response_code(404); echo 'Plik nie znaleziony na serwerze.'; exit;
}

// Inline vs download
$inline_mimes = ['application/pdf','image/png','image/jpeg','image/gif','image/webp','text/plain'];
$disposition  = in_array($z['mime_type'], $inline_mimes) ? 'inline' : 'attachment';

header('Content-Type: '        . ($z['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($z['original_name']) . '"');
header('Content-Length: '      . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($path);
exit;
