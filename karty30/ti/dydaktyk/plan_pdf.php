<?php
/**
 * karty30/ti/dydaktyk/plan_pdf.php — Plan zajęć prowadzącego do pobrania (PDF).
 * Wariant plan_print.php (widok HTML) — te same dane (ti_instructor_plan_data()).
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

$PD = ti_instructor_plan_data($target_uid, (int)($_GET['weeks'] ?? 8));
$instructor = $PD['instructor'];
if (!$instructor) { http_response_code(404); exit('Nie znaleziono prowadzącego.'); }

$days_pl   = [1=>'Poniedziałek',2=>'Wtorek',3=>'Środa',4=>'Czwartek',5=>'Piątek',6=>'Sobota',7=>'Niedziela'];
$months_pl = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
$org = defined('APP_ORG') ? APP_ORG : (defined('ORG_NAME') ? ORG_NAME : '');

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
$pl = fn(string $s): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($W, 10, $pl('Plan zajęć — ' . $instructor['name']), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8.5);
    $sub = ($org !== '' ? $org . '   ·   ' : '') . h($PD['from']) . ' – ' . h($PD['to'])
         . ' (' . $PD['weeks'] . ' tyg.)   ·   Wygenerowano: ' . date('d.m.Y H:i');
    $pdf->Cell($W, 5, $pl($sub), 0, 1);
    $pdf->Ln(4);

    if (!$PD['by_week']) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($W, 8, $pl('Brak zajęć w wybranym okresie.'), 0, 1);
    } else {
        $wDay = 42; $wTime = 24; $wCourse = $W - $wDay - $wTime - 40 - 30; $wStud = 40; $wStat = 30;
        foreach ($PD['by_week'] as $week_start => $wsessions) {
            $ws_ts = strtotime($week_start);
            $we_ts = strtotime($week_start . ' +6 days');
            $wlabel = date('j', $ws_ts) . ' ' . $months_pl[(int)date('n', $ws_ts)]
                    . ' – ' . date('j', $we_ts) . ' ' . $months_pl[(int)date('n', $we_ts)] . ' ' . date('Y', $ws_ts);

            if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
            $pdf->SetFillColor(241, 245, 249); $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell($W, 7, $pl('Tydzień ' . $wlabel), 0, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);

            $pdf->SetFillColor(248, 250, 252); $pdf->SetDrawColor(226, 232, 240);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Cell($wDay, 6, $pl('Dzień'), 1, 0, 'L', true);
            $pdf->Cell($wTime, 6, $pl('Godziny'), 1, 0, 'L', true);
            $pdf->Cell($wCourse, 6, $pl('Kurs'), 1, 0, 'L', true);
            $pdf->Cell($wStud, 6, $pl('Uczestnicy'), 1, 0, 'L', true);
            $pdf->Cell($wStat, 6, $pl('Status'), 1, 1, 'L', true);

            $pdf->SetFont('Helvetica', '', 8);
            foreach ($wsessions as $s) {
                if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); }
                $wd = (int)date('N', strtotime((string)$s['lesson_date']));
                $day_label = $days_pl[$wd] . ' ' . date('j.m', strtotime((string)$s['lesson_date']));
                $time_label = ($s['time_from'] && $s['time_to']) ? substr((string)$s['time_from'],0,5) . '–' . substr((string)$s['time_to'],0,5) : '—';
                $st_label = K30_TI_SESSION_STATUSES[(string)$s['status']]['label'] ?? (string)$s['status'];
                $y0 = $pdf->GetY();
                $pdf->Cell($wDay, 6, $pl($day_label), 1, 0, 'L');
                $pdf->Cell($wTime, 6, $pl($time_label), 1, 0, 'L');
                $pdf->Cell($wCourse, 6, $pl(mb_strimwidth((string)$s['course_name'], 0, 40, '…')), 1, 0, 'L');
                $pdf->Cell($wStud, 6, $pl(mb_strimwidth((string)($s['student_names'] ?? '–'), 0, 22, '…')), 1, 0, 'L');
                $pdf->Cell($wStat, 6, $pl($st_label), 1, 1, 'L');
            }
            $pdf->Ln(2);
        }
    }

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
