<?php
/**
 * karty30/ti/dydaktyk/harmonogram_pdf.php — Plan zajęć grupy do wydruku (PDF)
 * dla kursanta/rodzica — siatka tygodniowa jak w dzienniku elektronicznym
 * (dzień tygodnia × godzina). GET: course_id (wymagane).
 * Dostęp: zalogowany dydaktyk posiadający ten kurs (własny lub staff).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id || !dyd_owns_course($uid, $course_id)) {
    http_response_code(403); exit('Brak uprawnień do tego kursu.');
}

$WP = ti_course_weekly_slots($course_id);
$course = $WP['course'];
if (!$course) { http_response_code(404); exit('Nie znaleziono grupy.'); }
$rows_time = $WP['rows_time'];
$grid      = $WP['grid'];
$DOW_COLS  = $WP['dow_cols'];
$org = defined('ORG_NAME') ? ORG_NAME : '';

$_contact = array_filter([$course['instructor_email'] ?? '', $course['instructor_phone'] ?? '']);
$_meta = [];
if ($WP['first_lesson'] !== '') $_meta[] = 'Zajęcia od: ' . date('d.m.Y', strtotime($WP['first_lesson']));
if ($_contact) $_meta[] = 'Kontakt: ' . implode(' · ', $_contact);

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
    $pdf->Cell($W, 10, $pl('Plan zajęć — ' . $course['name']), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8.5);
    $sub = ($org ? $org . '   ·   ' : '') . 'Prowadzący: ' . ($course['instructor_name'] ?: '—')
         . '   ·   Wygenerowano: ' . date('d.m.Y H:i');
    $pdf->Cell($W, 5, $pl($sub), 0, 1);
    if ($_meta) {
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell($W, 5, $pl(implode('   ·   ', $_meta)), 0, 1);
    }
    $pdf->Ln(4);

    if (!$rows_time) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($W, 8, $pl('Brak zaplanowanych terminów — harmonogram nie został jeszcze ustalony.'), 0, 1);
    } else {
        $timeW = 26;
        $dayW  = ($W - $timeW) / 7;
        $rowH  = 14;

        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($timeW, 8, $pl('Godzina'), 1, 0, 'C', true);
        foreach ($DOW_COLS as $dlabel) $pdf->Cell($dayW, 8, $pl($dlabel), 1, 0, 'C', true);
        $pdf->Ln();

        foreach ($rows_time as $tk => $t) {
            $label = substr((string)$t['from'], 0, 5) . '–' . substr((string)$t['to'], 0, 5);
            $y0 = $pdf->GetY();
            if ($y0 > $pdf->GetPageHeight() - 30) { $pdf->AddPage(); $y0 = $pdf->GetY(); }
            $pdf->SetFont('Helvetica', 'B', 9); $pdf->SetFillColor(245, 248, 255);
            $pdf->Cell($timeW, $rowH, $pl($label), 1, 0, 'C', true);
            foreach (array_keys($DOW_COLS) as $dow) {
                $slot = $grid[$tk][$dow] ?? null;
                $x = $pdf->GetX(); $y = $pdf->GetY();
                if ($slot) {
                    $lm = (string)($slot['lesson_method'] ?? '');
                    $lm_label = match ($lm) {
                        'stacjonarna' => 'stacjonarnie',
                        'zdalna_zoom' => 'zdalnie (Zoom)',
                        'zdalna_inne' => 'zdalnie',
                        default => '',
                    };
                    $pdf->SetFillColor(220, 238, 220);
                    $pdf->Rect($x, $y, $dayW, $rowH, 'F');
                    $pdf->SetXY($x, $y + 2);
                    $pdf->SetFont('Helvetica', 'B', 8);
                    $pdf->MultiCell($dayW, 4, $pl(mb_strimwidth($course['name'], 0, 26, '…')), 0, 'C');
                    if ($lm_label !== '') {
                        $pdf->SetXY($x, $y + $rowH - 5);
                        $pdf->SetFont('Helvetica', '', 6.5); $pdf->SetTextColor(80, 80, 80);
                        $pdf->Cell($dayW, 4, $pl($lm_label), 0, 0, 'C');
                        $pdf->SetTextColor(0, 0, 0);
                    }
                    $pdf->Rect($x, $y, $dayW, $rowH);
                    $pdf->SetXY($x + $dayW, $y);
                } else {
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Cell($dayW, $rowH, '', 1, 0, 'C', true);
                }
            }
            $pdf->Ln();
        }

        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 7.5); $pdf->SetTextColor(110, 110, 110);
        $pdf->MultiCell($W, 4, $pl(
            'Plan wyznaczony na podstawie ostatnio zaplanowanych/odbytych terminów — może ulec zmianie. '
          . 'Aktualny harmonogram i ewentualne odwołania zawsze widoczne w panelu kursanta.'), 0, 'L');
    }

    $fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', $course['name']) . '.pdf';
    ti_print_log_add('harmonogram_pdf', 'Plan zajęć (dla ucznia/rodzica) — ' . $course['name'], $course_id, 0, [], $me);
    $pdfData = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[harmonogram_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
