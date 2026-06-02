<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/correspondence.php';

require_login();
require_module_enabled('correspondence_enabled', 'Moduł Korespondencja');

$id  = (int)($_GET['id'] ?? 0);
$it  = corr_get($id);
if (!$it || !$it['attachment']) { http_response_code(404); die('Nie znaleziono pliku.'); }

$path = dirname(__DIR__) . '/uploads/correspondence/' . $it['attachment'];
if (!file_exists($path)) { http_response_code(404); die('Plik nie istnieje.'); }

$mime = mime_content_type($path) ?: 'application/octet-stream';
$name = basename($it['attachment']);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
