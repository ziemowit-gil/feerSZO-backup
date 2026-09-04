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

$rows = db_all(
    "SELECT id FROM edok_documents
     WHERE CAST(SUBSTR(created_at, 6, 2) AS INTEGER) = ? AND CAST(SUBSTR(created_at, 1, 4) AS INTEGER) = ?
     ORDER BY id ASC",
    [$miesiac, $rok]
);

if (!$rows) {
    flash_set('warning', 'Brak dokumentów EODoK z wybranego miesiąca.');
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$tmp_dir = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);

$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8', 'format' => 'A4',
    'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 8, 'margin_bottom' => 8,
    'default_font' => 'dejavusans', 'tempDir' => $tmp_dir,
]);
$months_pl = ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];
$mpdf->SetTitle('EODoK — dokumenty ' . $months_pl[$miesiac] . ' ' . $rok);

$html = '<style>' . edok_print_css() . '</style>';
foreach ($rows as $i => $r) {
    $doc = edok_get((int)$r['id']);
    if (!$doc) continue;
    if ($i > 0) $html .= '<pagebreak />';
    $html .= edok_print_html($doc);
}
$mpdf->WriteHTML($html);

$filename = 'EODoK_' . sprintf('%04d_%02d', $rok, $miesiac) . '.pdf';
$mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
