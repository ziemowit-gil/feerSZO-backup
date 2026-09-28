<?php
/**
 * karty30/ti/dydaktyk/dni_wolne_pdf.php — Wykaz dni wolnych/przerw za dany rok (PDF).
 * GET: year (domyślnie bieżący). Dostęp: kierownik (dyd_is_staff()).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak uprawnień.'); }
karty30_migrate();

$year = (int)($_GET['year'] ?? date('Y'));
$year = max(2020, min(2035, $year));

// Tabelę zakłada dopiero ekran dni_wolne.php — bez jego odwiedzin eksport kończył się
// błędem krytycznym „no such table”; brak tabeli = pusty wykaz.
try {
    $items = db_all(
    "SELECT * FROM k30_ti_holidays WHERE strftime('%Y', date_from)=? OR strftime('%Y', date_to)=? ORDER BY date_from",
    [(string)$year, (string)$year]
    );
} catch (\Throwable $e) { $items = []; }

$type_labels = ['holiday' => 'Dzień wolny / święto', 'break' => 'Przerwa w działalności', 'other' => 'Inne'];
$org = defined('ORG_NAME') ? ORG_NAME : '';

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pdf/logic/TiPdf.php';   // DejaVu z polskimi znakami + stopka
$pl = fn(string $s): string => TiPdf::pl($s);

try {
    $pdf = new TiPdf('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(14, 14, 14);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 28;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($W, 9, $pl('Wykaz dni wolnych — ' . $year), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8.5);
    if ($org !== '') { $pdf->Cell($W, 5, $pl($org), 0, 1); }
    $pdf->Ln(4);

    if (!$items) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($W, 8, $pl('Brak wpisów w kalendarzu dla roku ' . $year . '.'), 0, 1);
    } else {
        $wDate = 28; $wDays = 16; $wName = $W - $wDate*2 - $wDays - 38; $wType = 38;
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell($wDate, 7, $pl('Od'), 1, 0, 'L', true);
        $pdf->Cell($wDate, 7, $pl('Do'), 1, 0, 'L', true);
        $pdf->Cell($wDays, 7, $pl('Dni'), 1, 0, 'C', true);
        $pdf->Cell($wName, 7, $pl('Nazwa'), 1, 0, 'L', true);
        $pdf->Cell($wType, 7, $pl('Typ'), 1, 1, 'L', true);

        $pdf->SetFont('Helvetica', '', 8.5);
        $fill = false;
        foreach ($items as $h) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 8.5); }
            $hdf = new DateTime($h['date_from']); $hdt = new DateTime($h['date_to']);
            $days = (int)$hdf->diff($hdt)->days + 1;
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 248 : 255, $fill ? 255 : 255);
            $pdf->Cell($wDate, 6, $pl($hdf->format('d.m.Y')), 1, 0, 'L', true);
            $pdf->Cell($wDate, 6, $pl($hdt->format('d.m.Y')), 1, 0, 'L', true);
            $pdf->Cell($wDays, 6, (string)$days, 1, 0, 'C', true);
            $pdf->Cell($wName, 6, $pl(mb_strimwidth((string)$h['name'], 0, 45, '…')), 1, 0, 'L', true);
            $pdf->Cell($wType, 6, $pl($type_labels[(string)$h['type']] ?? (string)$h['type']), 1, 1, 'L', true);
            $fill = !$fill;
        }
    }

    $pdf->SetAutoPageBreak(false);
    $pdf->SetY(-15);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 4, $pl('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? '')), 0, 0, 'L');

    ti_print_log_add('dni_wolne_pdf', 'Wykaz dni wolnych — ' . $year, 0, 0, ['year' => $year], $me);
    $fname = 'dni_wolne_' . $year . '.pdf';
    $pdfData = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[dni_wolne_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
