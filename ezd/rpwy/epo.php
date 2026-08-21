<?php
/**
 * Bezpieczne serwowanie dowodu doręczenia (ZPO / e-Doręczenia) z książki nadawczej.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$id = (int)($_GET['id'] ?? 0);
$r  = ezd_rpwy_get($id);
if (!$r || !$r['epo_file']) { http_response_code(404); echo 'Dowód doręczenia nie istnieje.'; exit; }

$path = UPLOAD_DIR . EZD_RPWY_SUBDIR . $id . '/' . $r['epo_file'];
if (!is_file($path)) { http_response_code(404); echo 'Plik nie znaleziony na serwerze.'; exit; }

$inline_mimes = ['application/pdf','image/png','image/jpeg','image/gif','image/webp','text/plain','text/xml','application/xml'];
$disposition  = (empty($_GET['dl']) && in_array($r['epo_mime'], $inline_mimes, true)) ? 'inline' : 'attachment';

header('Content-Type: '        . ($r['epo_mime'] ?: 'application/octet-stream'));
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($r['epo_name']) . '"');
header('Content-Length: '      . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
