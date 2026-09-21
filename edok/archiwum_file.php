<?php
/** edok/archiwum_file.php — pobranie pliku archiwum miesięcznego (PDF kart akceptacji / CSV zestawienia). */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'pdf';

$archive = db_one("SELECT * FROM edok_monthly_archive WHERE id = ?", [$id]);
if (!$archive) { http_response_code(404); die('Archiwum nie istnieje.'); }

$rel = $type === 'csv' ? $archive['csv_path'] : $archive['pdf_path'];
if (!$rel) { http_response_code(404); die('Ten plik nie został wygenerowany.'); }

$abs = UPLOAD_DIR . $rel;
if (!is_file($abs)) { http_response_code(404); die('Plik nie istnieje na dysku.'); }

if ($type === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
} else {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($abs) . '"');
}
header('Content-Length: ' . filesize($abs));
readfile($abs);
