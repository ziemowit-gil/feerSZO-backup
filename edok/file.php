<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = db_one("SELECT file_path FROM edok_documents WHERE id = ?", [$id]);
if (!$doc || !$doc['file_path']) { http_response_code(404); die('Plik nie istnieje.'); }

$abs = UPLOAD_DIR . $doc['file_path'];
if (!is_file($abs)) { http_response_code(404); die('Plik nie istnieje na dysku.'); }

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match($ext) {
    'pdf'          => 'application/pdf',
    'jpg', 'jpeg'  => 'image/jpeg',
    'png'          => 'image/png',
    'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    default        => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($abs) . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
