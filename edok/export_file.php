<?php
/** edok/export_file.php — pobranie zapisanego eksportu zbiorczego (edok_exports). */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();
$e = db_one("SELECT * FROM edok_exports WHERE id = ?", [(int)($_GET['id'] ?? 0)]);
$abs = $e ? UPLOAD_DIR . $e['file_path'] : '';
if (!$e || !is_file($abs)) { http_response_code(404); die('Eksport nie istnieje.'); }
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
header('Content-Disposition: ' . ($ext === 'pdf' ? 'inline' : 'attachment') . '; filename="' . basename($abs) . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
