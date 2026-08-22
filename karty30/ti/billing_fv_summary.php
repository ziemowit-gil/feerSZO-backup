<?php
/**
 * karty30/ti/billing_fv_summary.php - Podsumowanie POZYCJI DO FAKTURY VAT (PDF).
 *
 * Faktury wystawiamy poza panelem (domyślnie Comarch ERP Optima) - ten wydruk zawiera
 * gotowe pozycje do przepisania: nabywca/płatnik, okres, nazwa usługi, j.m., ilość,
 * cena jednostkowa, wartość, stawka VAT + informację o zaliczonej nadpłacie.
 *
 * GET (jeden z wariantów):
 *   ?id=N                                - jedno rozliczenie
 *   ?course_id=N&month=M&year=R          - wszystkie rozliczenia grupy w miesiącu
 *   ?client_id=N&month=M&year=R          - wszystkie rozliczenia kursanta w miesiącu
 * Dostęp: pracownik karty30 (zapis) / administrator. Wynik: tylko PDF.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_payments.php';

k30_require_access();
karty30_migrate();
ti_payments_migrate();

if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

$bid       = (int)($_GET['id'] ?? 0);
$course_id = (int)($_GET['course_id'] ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);
$month     = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year      = (int)($_GET['year'] ?? date('Y'));

$MONTHS_PL = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
              7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];

$sql = "SELECT b.*, cl.name AS client_name, cl.address AS client_address, c.name AS course_name
        FROM k30_ti_billing b
        JOIN k30_clients cl ON cl.id=b.client_id
        LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
        WHERE b.status IN ('issued','paid') ";
if ($bid) {
    $rows     = db_all($sql . "AND b.id=?", [$bid]);
    $subtitle = 'rozliczenie #' . $bid;
} elseif ($course_id) {
    $rows     = db_all($sql . "AND COALESCE(b.course_id,0)=CAST(? AS INTEGER) AND b.month=? AND b.year=? ORDER BY cl.name",
                       [$course_id, $month, $year]);
    $cn       = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id]);
    $subtitle = 'grupa: ' . ($cn['name'] ?? ('#'.$course_id)) . ' | ' . ($MONTHS_PL[$month] ?? $month) . ' ' . $year;
} elseif ($client_id) {
    $rows     = db_all($sql . "AND b.client_id=? AND b.month=? AND b.year=? ORDER BY c.name", [$client_id, $month, $year]);
    $cl       = db_one("SELECT name FROM k30_clients WHERE id=?", [$client_id]);
    $subtitle = 'kursant: ' . ($cl['name'] ?? ('#'.$client_id)) . ' | ' . ($MONTHS_PL[$month] ?? $month) . ' ' . $year;
} else {
    http_response_code(400); die('Podaj ?id=, ?course_id= lub ?client_id=.');
}
if (!$rows) { http_response_code(404); die('Brak rozliczeń do wydruku.'); }

$vat     = k30_ti_invoice_vat();
$system  = k30_ti_invoice_system();
$org     = defined('ORG_NAME') ? ORG_NAME : '';

require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
// Font z pełnym zestawem polskich znaków (DejaVu, kodowanie ISO-8859-2)
$pl = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
$zl = fn($x): string => number_format((float)$x, 2, ',', ' ');

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $fdir = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $fdir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $fdir);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 9, $pl('Podsumowanie pozycji do faktury VAT'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('DejaVu', '', 8);
    $pdf->Cell($W, 5, $pl(($org ? $org . '   |   ' : '') . $subtitle . '   |   wygenerowano: ' . date('d.m.Y H:i')), 0, 1);
    $pdf->SetFont('DejaVu', 'B', 7.6); $pdf->SetTextColor(150, 60, 0);
    $pdf->Cell($W, 5, $pl('Fakturę wystaw w systemie: ' . $system . ' - panel faktur nie generuje.'), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    // Kolumny pozycji: Lp | Nazwa | J.m. | Ilość | Cena jedn. | VAT | Wartość
    $cW = ['lp' => 8, 'name' => 0, 'unit' => 14, 'qty' => 16, 'price' => 24, 'vat' => 14, 'val' => 24];
    $cW['name'] = $W - array_sum($cW);

    $grand = 0.0;
    foreach ($rows as $b) {
        $positions = k30_ti_billing_fv_positions($b);
        if (!$positions) continue;
        if ($pdf->GetY() > $pdf->GetPageHeight() - 50) $pdf->AddPage();

        // Nagłówek rozliczenia - nabywca / płatnik / okres
        $period = ($MONTHS_PL[(int)$b['month']] ?? $b['month']) . ' ' . (int)$b['year'];
        $pdf->SetFillColor(233, 238, 245);
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell($W, 7, $pl($b['client_name'] . ($b['course_name'] ? '   |   ' . $b['course_name'] : '   |   rozliczenie łączne')), 0, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 6.8); $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell($W, 4.5, $pl('Płatnik: ' . k30_ti_billing_payer_label($b) . '   |   okres: ' . $period
            . (!empty($b['due_date']) ? '   |   termin płatności: ' . date('d.m.Y', strtotime($b['due_date'])) : '')), 0, 1);
        if (trim((string)($b['client_address'] ?? '')) !== '') {
            $pdf->Cell($W, 4.5, $pl('Adres: ' . preg_replace('/\s+/', ' ', (string)$b['client_address'])), 0, 1);
        }
        if (($b['invoice_kind'] ?? '') === 'oneoff' || !empty($b['invoice_no'])) {
            $pdf->Cell($W, 4.5, $pl(
                (($b['invoice_kind'] ?? '') === 'oneoff' ? 'Faktura jednorazowa' : 'Faktura')
                . (!empty($b['invoice_no']) ? ' nr ' . $b['invoice_no'] : ' - numer jeszcze nienadany')
                . (!empty($b['invoice_issued_on']) ? ' z ' . date('d.m.Y', strtotime((string)$b['invoice_issued_on'])) : '')
                . (empty($b['invoice_path']) ? '   |   UWAGA: brak skanu faktury w panelu' : '')
            ), 0, 1);
        }
        $pdf->SetTextColor(0, 0, 0); $pdf->Ln(1);

        // Tabela pozycji
        $pdf->SetFont('DejaVu', 'B', 6.8);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->Cell($cW['lp'],    6, $pl('Lp'),         1, 0, 'C', true);
        $pdf->Cell($cW['name'],  6, $pl('Nazwa usługi'), 1, 0, 'L', true);
        $pdf->Cell($cW['unit'],  6, $pl('J.m.'),       1, 0, 'C', true);
        $pdf->Cell($cW['qty'],   6, $pl('Ilość'),      1, 0, 'R', true);
        $pdf->Cell($cW['price'], 6, $pl('Cena jedn.'), 1, 0, 'R', true);
        $pdf->Cell($cW['vat'],   6, $pl('VAT'),        1, 0, 'C', true);
        $pdf->Cell($cW['val'],   6, $pl('Wartość'),    1, 1, 'R', true);

        $pdf->SetFont('DejaVu', '', 6.8);
        $sum = 0.0; $lp = 0; $fill = false;
        foreach ($positions as $ps) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); $pdf->SetFont('DejaVu', '', 6.8); }
            $lp++; $sum = round($sum + (float)$ps['value'], 2);
            $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
            $pdf->Cell($cW['lp'],    6, (string)$lp, 1, 0, 'C', true);
            $pdf->Cell($cW['name'],  6, $pl(mb_strimwidth($ps['name'], 0, 70, '…')), 1, 0, 'L', true);
            $pdf->Cell($cW['unit'],  6, $pl($ps['unit']), 1, 0, 'C', true);
            $pdf->Cell($cW['qty'],   6, $zl($ps['qty']),  1, 0, 'R', true);
            $pdf->Cell($cW['price'], 6, $zl($ps['unit_price']), 1, 0, 'R', true);
            $pdf->Cell($cW['vat'],   6, $pl($vat), 1, 0, 'C', true);
            $pdf->Cell($cW['val'],   6, $zl($ps['value']), 1, 1, 'R', true);
            $fill = !$fill;
        }

        // Razem + rozliczenie wpłat/nadpłaty
        $paid = round((float)($b['paid_amount'] ?? 0), 2);
        $rem  = round($sum - $paid, 2);
        $pdf->SetFont('DejaVu', 'B', 7.6);
        $pdf->SetFillColor(240, 244, 250);
        $pdf->Cell($W - $cW['val'], 6.5, $pl('RAZEM do faktury'), 1, 0, 'R', true);
        $pdf->Cell($cW['val'],      6.5, $zl($sum), 1, 1, 'R', true);
        $pdf->SetFont('DejaVu', '', 6.8); $pdf->SetTextColor(80, 80, 80);
        $gb = (int)$b['course_id'] > 0 ? ti_group_balance((int)$b['client_id'], (int)$b['course_id']) : null;
        $info = 'Zaliczone wpłaty: ' . $zl($paid) . ' zł   |   ' . ($rem > 0.005 ? 'pozostaje: ' . $zl($rem) . ' zł' : 'rozliczenie pokryte');
        if ($gb && $gb['credit'] > 0.005) $info .= '   |   nadpłata tej grupy: ' . $zl($gb['credit']) . ' zł';
        if ($gb && $gb['debt']   > 0.005) $info .= '   |   niedopłata tej grupy: ' . $zl($gb['debt']) . ' zł';
        $pdf->Cell($W, 4.5, $pl($info), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
        $grand = round($grand + $sum, 2);
    }

    if (count($rows) > 1) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 25) $pdf->AddPage();
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($W - 30, 8, $pl('RAZEM wszystkie rozliczenia w tym wydruku'), 0, 0, 'R', true);
        $pdf->Cell(30,      8, $zl($grand) . ' ', 0, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    }

    $pdf->Ln(2);
    $pdf->SetFont('DejaVu', '', 6.2); $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($W, 4, $pl(
        'Wydruk pomocniczy - nie jest fakturą ani dokumentem księgowym. Pozycje przepisz do systemu ' . $system
        . '. Stawka VAT (' . $vat . ') pochodzi z ustawień (ti_invoice_vat) - zweryfikuj przed wystawieniem. '
        . 'Model kombinowany: każda grupa (przedmiot) ma osobne rozliczenie i osobne saldo; nadpłata przypisana '
        . 'do grupy pokrywa wyłącznie kolejne zajęcia w tej grupie.'), 0, 'L');

    $data  = $pdf->Output('S');
    $fname = 'fvat_pozycje_' . ($bid ? ('rozl'.$bid) : ($course_id ? ('grupa'.$course_id) : ('kursant'.$client_id))
             ) . '_' . sprintf('%04d-%02d', $year, $month) . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
} catch (\Throwable $e) {
    error_log('[billing_fv_summary] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować podsumowania.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
