<?php
/**
 * karty30/ti/dydaktyk/plan_pdf.php — Plan zajęć prowadzącego do pobrania (PDF).
 * Wariant plan_print.php (widok HTML) — te same dane (ti_instructor_plan_grouped()).
 * Lista: najpierw dzień tygodnia + godzina + kurs, pod spodem konkretne daty.
 * GET: instructor_id (staff — dowolny; zwykły prowadzący tylko swój), weeks.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me       = dyd_require();
$uid      = (int)$me['user_id'];
$is_staff = dyd_is_staff();

$target_uid = $uid;
if ($is_staff && isset($_GET['instructor_id'])) {
    $target_uid = max(1, (int)$_GET['instructor_id']);
}

$PD = ti_instructor_plan_grouped($target_uid, (int)($_GET['weeks'] ?? 8));
$instructor = $PD['instructor'];
if (!$instructor) { http_response_code(404); exit('Nie znaleziono prowadzącego.'); }

$org = defined('APP_ORG') ? APP_ORG : (defined('ORG_NAME') ? ORG_NAME : '');

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pdf/logic/TiPdf.php';   // DejaVu z polskimi znakami + stopka
$pl = fn(string $s): string => TiPdf::pl($s);

try {
    $pdf = new TiPdf('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($W, 10, $pl('Plan zajęć — ' . $instructor['name']), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8.5);
    $range = $PD['unbounded'] ? 'Ogólny — od dziś, bez ograniczenia końcowego' : $PD['from'] . ' – ' . $PD['to'] . ' (' . $PD['weeks'] . ' tyg.)';
    $sub = ($org !== '' ? $org . '   ·   ' : '') . $range;
    $pdf->Cell($W, 5, $pl($sub), 0, 1);
    $pdf->Ln(4);

    if (!$PD['groups']) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($W, 8, $pl('Brak zajęć w wybranym okresie.'), 0, 1);
    } else {
        $wDate = 30; $wStud = $W - $wDate - 34; $wStat = 34;
        foreach ($PD['groups'] as $g) {
            $time_label = ($g['time_from'] && $g['time_to']) ? substr((string)$g['time_from'],0,5) . '–' . substr((string)$g['time_to'],0,5) : '—';
            if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
            $pdf->SetFillColor(241, 245, 249); $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell($W, 7, $pl($g['day_label'] . ', ' . $time_label . ' · ' . $g['course_name']), 0, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);

            $pdf->SetFillColor(248, 250, 252); $pdf->SetDrawColor(226, 232, 240);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Cell($wDate, 6, $pl('Data'), 1, 0, 'L', true);
            $pdf->Cell($wStud, 6, $pl('Uczestnicy'), 1, 0, 'L', true);
            $pdf->Cell($wStat, 6, $pl('Status'), 1, 1, 'L', true);

            $pdf->SetFont('Helvetica', '', 8);
            foreach ($g['dates'] as $d) {
                if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); }
                $st_label = K30_TI_SESSION_STATUSES[$d['status']]['label'] ?? $d['status'];
                $pdf->Cell($wDate, 6, $pl(date('d.m.Y', strtotime($d['date']))), 1, 0, 'L');
                $pdf->Cell($wStud, 6, $pl(mb_strimwidth($d['student_names'] !== '' ? $d['student_names'] : '–', 0, 45, '…')), 1, 0, 'L');
                $pdf->Cell($wStat, 6, $pl($st_label), 1, 1, 'L');
            }
            $pdf->Ln(2);
        }
    }

    $pdf->SetAutoPageBreak(false);
    $pdf->SetY(-15);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 4, $pl('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? '')), 0, 0, 'L');

    ti_print_log_add('plan_pdf', 'Plan zajęć PDF — ' . $instructor['name'], 0, 0, ['weeks' => $PD['weeks']], $me);
    $fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', (string)$instructor['name']) . '.pdf';
    $pdfData = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[plan_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
