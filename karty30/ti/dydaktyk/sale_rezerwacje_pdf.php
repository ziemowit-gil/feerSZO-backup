<?php
/**
 * karty30/ti/dydaktyk/sale_rezerwacje_pdf.php — Wykaz sal do rezerwacji do pobrania (PDF).
 * Wariant sale_rezerwacje.php (widok HTML) — te same dane i ten sam zakres dat
 * (ti_room_reservation_range() + ti_room_reservation_report()).
 * GET: range ('week'|'month'|'quarter', domyślnie 'week'), w (data kotwicząca).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak dostępu.'); }
karty30_migrate();
ti_planner_ext_migrate();

$range = in_array($_GET['range'] ?? '', ['week', 'month', 'quarter'], true) ? $_GET['range'] : 'week';
$w     = (string)($_GET['w'] ?? '');
$RR    = ti_room_reservation_range($w, $range);
$from  = $RR['from']; $to = $RR['to']; $range_label = $RR['label'];

$by_day = ti_room_reservation_report($from, $to);
$org = defined('APP_ORG') ? APP_ORG : (defined('ORG_NAME') ? ORG_NAME : '');

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
$pl = fn(string $s): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;

try {
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($W, 10, $pl('Wykaz sal do rezerwacji'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8.5);
    $sub = ($org !== '' ? $org . '   ·   ' : '') . $range_label;
    $pdf->Cell($W, 5, $pl($sub), 0, 1);
    $pdf->Ln(4);

    if (!$by_day) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($W, 8, $pl('Brak terminów z przypisaną salą w wybranym okresie.'), 0, 1);
    } else {
        // Godziny | Sala/lokalizacja | Grupa | Prowadzący | Status
        $wTime = 24; $wStat = 34; $rest = $W - $wTime - $wStat;
        $wRoom = (int)round($rest * 0.34); $wCourse = (int)round($rest * 0.34); $wInstr = $rest - $wRoom - $wCourse;

        foreach ($by_day as $date => $day_rows) {
            $dow = (int)date('N', strtotime($date));
            if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();

            $pdf->SetFillColor(241, 245, 249); $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell($W, 7, $pl((TI_DAYS_PL_FULL[$dow] ?? '') . ', ' . date('d.m.Y', strtotime($date)) . ' (' . count($day_rows) . ')'), 0, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);

            $pdf->SetFillColor(248, 250, 252); $pdf->SetDrawColor(226, 232, 240);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Cell($wTime,   6, $pl('Godziny'),           1, 0, 'L', true);
            $pdf->Cell($wRoom,   6, $pl('Sala / lokalizacja'),1, 0, 'L', true);
            $pdf->Cell($wCourse, 6, $pl('Grupa'),             1, 0, 'L', true);
            $pdf->Cell($wInstr,  6, $pl('Prowadzący'),        1, 0, 'L', true);
            $pdf->Cell($wStat,   6, $pl('Status'),            1, 1, 'L', true);

            $pdf->SetFont('Helvetica', '', 8);
            foreach ($day_rows as $r) {
                if ($pdf->GetY() > $pdf->GetPageHeight() - 20) $pdf->AddPage();
                $confirmed = $r['room_reservation_status'] === 'potwierdzone';
                $time_lbl  = substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5);
                $pdf->Cell($wTime,   6, $pl($time_lbl), 1, 0, 'L');
                $pdf->Cell($wRoom,   6, $pl(mb_strimwidth($r['room_label'], 0, 30, '…')), 1, 0, 'L');
                $pdf->Cell($wCourse, 6, $pl(mb_strimwidth($r['course_name'], 0, 30, '…')), 1, 0, 'L');
                $pdf->Cell($wInstr,  6, $pl(mb_strimwidth($r['instructor_label'], 0, 28, '…')), 1, 0, 'L');
                $pdf->Cell($wStat,   6, $pl($confirmed ? 'Potwierdzone' : 'Do rezerwacji'), 1, 1, 'L');
            }
            $pdf->Ln(2);
        }
    }

    $pdf->SetAutoPageBreak(false);
    $pdf->SetY(-15);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 4, $pl('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? '')), 0, 0, 'L');

    ti_print_log_add('sale_rezerwacje_pdf', 'Wykaz sal do rezerwacji PDF — ' . $range_label, 0, 0, ['range' => $range], $me);
    $fname = 'wykaz_sal_' . preg_replace('/[^a-z0-9]+/i', '_', $from . '_' . $to) . '.pdf';
    $pdfData = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[sale_rezerwacje_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
