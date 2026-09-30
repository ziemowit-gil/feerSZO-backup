<?php
/**
 * edok/monthly_pdf.php — jeden PDF z kartami akceptacji wszystkich dokumentów
 * EODoK dodanych w wybranym miesiącu (wg created_at). Każdy dokument = jedna
 * strona (edok_print_html()), strony sklejone <pagebreak /> (mPDF).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$miesiac = (int)($_GET['miesiac'] ?? date('n'));
$rok     = (int)($_GET['rok']     ?? date('Y'));
if ($miesiac < 1 || $miesiac > 12) { http_response_code(400); die('Nieprawidłowy miesiąc.'); }
if ($rok < 2000 || $rok > 2100)    { http_response_code(400); die('Nieprawidłowy rok.'); }

// Faktury (źródła) + karty akceptacji, dokument po dokumencie.
$tmp = tempnam(sys_get_temp_dir(), 'edokm');
$count = edok_build_monthly_combined($rok, $miesiac, $tmp);
if ($count === 0) {
    @unlink($tmp);
    flash_set('warning', 'Brak dokumentów EODoK z wybranego miesiąca.');
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}
$filename = 'EODoK_' . sprintf('%04d_%02d', $rok, $miesiac) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
