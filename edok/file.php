<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'source';

if ($type === 'dowod') {
    $doc = db_one("SELECT dowod_zaplaty_path FROM edok_documents WHERE id = ?", [$id]);
    if (!$doc || !$doc['dowod_zaplaty_path']) { http_response_code(404); die('Brak dowodu zapłaty.'); }
    $file_path = $doc['dowod_zaplaty_path'];
} elseif ($type === 'print') {
    $g = db_one("SELECT file_path FROM edok_generated_pdf WHERE id = ? AND doc_id = ? AND kind = 'print'", [(int)($_GET['gid'] ?? 0), $id]);
    if (!$g) { http_response_code(404); die('Brak zapisanego wydruku.'); }
    $file_path = $g['file_path'];
} elseif ($type === 'final') {
    $gen = edok_latest_generated_pdf($id);
    if (!$gen || !$gen['file_path']) { http_response_code(404); die('Dokument końcowy nie został jeszcze wygenerowany.'); }
    $file_path = $gen['file_path'];
} else {
    $doc = db_one("SELECT file_path FROM edok_documents WHERE id = ?", [$id]);
    if (!$doc || !$doc['file_path']) { http_response_code(404); die('Plik nie istnieje.'); }
    $file_path = $doc['file_path'];
}

$abs = UPLOAD_DIR . $file_path;
if (!is_file($abs)) { http_response_code(404); die('Plik nie istnieje na dysku.'); }

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match($ext) {
    'pdf'          => 'application/pdf',
    'jpg', 'jpeg'  => 'image/jpeg',
    'png'          => 'image/png',
    'xml'          => 'application/xml',
    'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    default        => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($abs) . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
