<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resolutions.php';

require_login();
require_module_enabled('resolutions_enabled', 'Moduł Uchwały i Zarządzenia');

$id  = (int)($_GET['id'] ?? 0);
$res = uchw_get($id);
if (!$res || !$res['attachment']) { http_response_code(404); die('Nie znaleziono pliku.'); }

$path = dirname(__DIR__) . '/uploads/resolutions/' . $res['attachment'];
if (!file_exists($path)) { http_response_code(404); die('Plik nie istnieje.'); }

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($res['attachment']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
