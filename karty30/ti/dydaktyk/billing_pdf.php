<?php
/**
 * karty30/ti/dydaktyk/billing_pdf.php — PDF zestawienia rozliczeń grupy (staff only).
 * GET ?course_id=N
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';
require_once __DIR__ . '/auth.php';

// Tylko staff/admin
if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }

$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id) { http_response_code(400); die('Brak course_id.'); }
$course = k30_ti_course_get($course_id);
if (!$course) { http_response_code(404); die('Nie znaleziono grupy.'); }

ti_payments_migrate();

$enrolled = db_all(
    "SELECT cl.id, cl.name
     FROM k30_ti_enrollments e
     JOIN k30_clients cl ON cl.id=e.client_id
     WHERE e.course_id=? AND e.status='active'
     ORDER BY cl.name COLLATE NOCASE",
    [$course_id]
);

$balances = [];
$total_charges = $total_payments = 0.0;
foreach ($enrolled as $en) {
    // Model kombinowany — saldo TEJ grupy (nie całego konta kursanta)
    $b = ti_group_balance((int)$en['id'], (int)$course_id);
    $b['payments'] = $b['applied'];   // środki zaliczone na tę grupę
    $balances[(int)$en['id']] = $b;
    $total_charges  += $b['charges'];
    $total_payments += $b['payments'];
}

$org = defined('ORG_NAME') ? ORG_NAME : '';

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
$FD = dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/font/';
$pl = fn(string $s): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    // Nagłówek
    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell($W, 9, $pl('Zestawienie rozliczeń — ' . $course['name']), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8);
    $_me = dyd_current();
    $sub = ($org ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($_me['name'] ?? '')
         . '   ·   Kursantów: ' . count($enrolled);
    $pdf->Cell($W, 5, $pl($sub), 0, 1);
    $pdf->Ln(3);

    if (!$enrolled) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($W, 8, $pl('Brak kursantów w tej grupie.'), 0, 1);
    } else {
        // Nagłówek tabeli
        $wName = $W - 38 - 38 - 38;
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell($wName, 7, $pl('Kursant'),      1, 0, 'L', true);
        $pdf->Cell(38,     7, $pl('Należności'),   1, 0, 'C', true);
        $pdf->Cell(38,     7, $pl('Wpłaty'),       1, 0, 'C', true);
        $pdf->Cell(38,     7, $pl('Saldo'),        1, 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 8.5);
        $fill = false;
        foreach ($enrolled as $en) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 20) {
                $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 8.5);
            }
            $b = $balances[(int)$en['id']];
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 248 : 255, $fill ? 255 : 255);

            $pdf->Cell($wName, 6, $pl(mb_strimwidth($en['name'], 0, 55, '…')), 1, 0, 'L', true);
            $pdf->Cell(38,     6, $pl(number_format($b['charges'],  2, ',', ' ') . ' zl'), 1, 0, 'R', true);
            $pdf->Cell(38,     6, $pl(number_format($b['payments'], 2, ',', ' ') . ' zl'), 1, 0, 'R', true);

            if ($b['debt'] > 0.005) {
                $pdf->SetTextColor(170, 0, 0); $pdf->SetFont('Helvetica', 'B', 8.5);
                $saldo = $pl('-' . number_format($b['debt'],   2, ',', ' ') . ' zl');
            } elseif ($b['credit'] > 0.005) {
                $pdf->SetTextColor(0, 130, 0); $pdf->SetFont('Helvetica', 'B', 8.5);
                $saldo = $pl('+' . number_format($b['credit'], 2, ',', ' ') . ' zl');
            } else {
                $pdf->SetTextColor(100, 100, 100); $pdf->SetFont('Helvetica', '', 8.5);
                $saldo = '0,00 zl';
            }
            $pdf->Cell(38, 6, $saldo, 1, 1, 'R', true);
            $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8.5);
            $fill = !$fill;
        }

        // Wiersz sumaryczny
        $total_bal = round($total_payments - $total_charges, 2);
        $pdf->SetFillColor(224, 232, 244);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell($wName, 7, $pl('Razem'),                                                         1, 0, 'L', true);
        $pdf->Cell(38,     7, $pl(number_format($total_charges,  2, ',', ' ') . ' zl'), 1, 0, 'R', true);
        $pdf->Cell(38,     7, $pl(number_format($total_payments, 2, ',', ' ') . ' zl'), 1, 0, 'R', true);
        if ($total_bal < -0.005) {
            $pdf->SetTextColor(170, 0, 0);
            $s_bal = $pl('-' . number_format(abs($total_bal), 2, ',', ' ') . ' zl');
        } elseif ($total_bal > 0.005) {
            $pdf->SetTextColor(0, 130, 0);
            $s_bal = $pl('+' . number_format($total_bal, 2, ',', ' ') . ' zl');
        } else {
            $pdf->SetTextColor(80, 80, 80); $s_bal = '0,00 zl';
        }
        $pdf->Cell(38, 7, $s_bal, 1, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(110, 110, 110);
        $pdf->MultiCell($W, 4, $pl(
            'Saldo = wpłaty minus należności (wszystkie okresy). Wartość ujemna = niedopłata. '
          . 'Wartość dodatnia = nadpłata (zostanie zaliczona na kolejne zajęcia). '
          . 'Dokument wygenerowany automatycznie — nie jest fakturą ani wezwaniem do zapłaty.'), 0, 'L');
    }

    $fname = 'rozliczenia_' . preg_replace('/[^a-z0-9]+/i', '_', $course['name']) . '_' . date('Ymd') . '.pdf';
    ti_print_log_add('billing_pdf', 'Zestawienie rozliczeń — ' . $course['name'], $course_id, 0, [], $_me);
    $pdfData = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[billing_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
