<?php
/**
 * edok/print_pdf.php — PDF do wydruku „dokument źródłowy + karta akceptacji” generowany na żądanie
 * (także przed końcem obiegu; karta pokazuje aktualny stan akceptacji). Nie zapisuje się jako dokument końcowy.
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

$tmp = tempnam(sys_get_temp_dir(), 'edokp');
try {
    edok_build_source_card_pdf($doc, $tmp);
} catch (\Throwable $e) {
    @unlink($tmp);
    http_response_code(500);
    die('Nie udało się wygenerować PDF: ' . h($e->getMessage()));
}
$name = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', 'EODoK_' . $doc['number'] . '.pdf');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
