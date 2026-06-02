<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/procedures.php';

require_login();
require_module_enabled('procedures_enabled', 'Moduł procedur');

$id  = (int)($_GET['id'] ?? 0);
$att = proc_get_attachment($id);

if (!$att) { http_response_code(404); exit('Nie znaleziono załącznika.'); }

// Sprawdź czy procedura istnieje i nie jest usunięta
$proc = proc_get((int)$att['procedure_id']);
if (!$proc || $proc['status'] === 'deleted') { http_response_code(404); exit('Nie znaleziono.'); }

$path = UPLOAD_DIR . PROC_UPLOAD_SUBDIR . $att['procedure_id'] . '/' . $att['filename'];
if (!is_file($path)) { http_response_code(404); exit('Plik nie istnieje.'); }

$download = isset($_GET['download']);
$mime     = $att['mime_type'] ?: 'application/octet-stream';
$name     = $att['original_name'];

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
if ($download) {
    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
} else {
    header('Content-Disposition: inline; filename="' . addslashes($name) . '"');
}
readfile($path);
exit;
