<?php
/**
 * edok/print_pdf.php — PDF do wydruku „dokument źródłowy + karta akceptacji” generowany na żądanie
 * (także przed końcem obiegu; karta pokazuje stan akceptacji z chwili eksportu). Każdy eksport jest zapisywany w EODoK
 * (edok_generated_pdf, kind='print') — nie jest dokumentem końcowym.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/edok_queue.php';

edok_require_access();
edok_migrate();

$doc = edok_get((int)($_GET['id'] ?? 0));
if (!$doc) { http_response_code(404); die('Dokument nie istnieje.'); }

try {
    $saved = edok_save_print_pdf($doc); // zapis w EODoK (historia na karcie dokumentu + wpis w audycie)
} catch (\Throwable $e) {
    http_response_code(500);
    die('Nie udało się wygenerować PDF: ' . h($e->getMessage()));
}
$abs = UPLOAD_DIR . $saved['file_path'];
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($abs) . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
