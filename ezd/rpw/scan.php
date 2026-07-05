<?php
/**
 * Bezpieczne serwowanie skanu przesyłki z dziennika podawczego (RPW).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$id  = (int)($_GET['id'] ?? 0);
$rpw = ezd_rpw_get($id);
if (!$rpw || !$rpw['scan_file']) { http_response_code(404); echo 'Skan nie istnieje.'; exit; }

$path = UPLOAD_DIR . EZD_RPW_SUBDIR . $id . '/' . $rpw['scan_file'];
if (!is_file($path)) { http_response_code(404); echo 'Plik nie znaleziony na serwerze.'; exit; }

$inline_mimes = ['application/pdf','image/png','image/jpeg','image/gif','image/webp','text/plain'];
$disposition  = (empty($_GET['dl']) && in_array($rpw['scan_mime'], $inline_mimes)) ? 'inline' : 'attachment';

header('Content-Type: '        . ($rpw['scan_mime'] ?: 'application/octet-stream'));
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($rpw['scan_name']) . '"');
header('Content-Length: '      . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
