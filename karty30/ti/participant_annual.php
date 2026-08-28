<?php
/**
 * karty30/ti/participant_annual.php — Raport ROCZNY per uczestnik (TI) → PDF.
 * Dla każdego aktywnego kursanta: frekwencja (per kurs, cały rok) + rozliczenia
 * z rozbiciem na miesiące (należności / zapłacone / wpłaty) + bieżące saldo konta.
 * GET: ?y=YYYY (domyślnie bieżący), ?course_id=N (opcjonalnie), ?client_id=N (opcjonalnie).
 * Dostęp: pracownik D3 / administrator. Wynik: tylko PDF.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_participant_report.php';

k30_ti_staff_access();   // pracownik modułu LUB kierownik z panelu dydaktyka
karty30_migrate();


$year = (int)($_GET['y'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$from = sprintf('%04d-01-01', $year);
$to   = sprintf('%04d-12-31', $year);

$course_id = (int)($_GET['course_id'] ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);
$course    = $course_id ? k30_ti_course_get($course_id) : null;

$clients = ti_pr_clients($course_id);
if ($client_id) $clients = array_values(array_filter($clients, fn($c) => (int)$c['id'] === $client_id));

$_mon3 = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];

// Zbierz dane; pomiń kursantów bez aktywności w roku.
$data = [];
foreach ($clients as $cl) {
    $cid = (int)$cl['id'];
    $att = ti_pr_attendance($cid, $from, $to, $course_id);
    $bil = ti_pr_billing_year($cid, $year);
    $bal = ti_client_balance($cid);
    $hasActivity = $att['held'] > 0 || $att['cancelled_lesson'] > 0
        || $bil['totals']['charges'] != 0.0 || $bil['totals']['payments'] != 0.0;
    if (!$hasActivity) continue;
    $data[] = ['name' => $cl['name'], 'att' => $att, 'bil' => $bil, 'bal' => $bal];
}

// ── PDF ────────────────────────────────────────────────────────────────────────
require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
$FD  = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
$pl  = fn(string $s): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;
$org = defined('ORG_NAME') ? ORG_NAME : '';

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell($W, 9, $pl('Raport roczny — per uczestnik · ' . $year), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8);
    $sub = ($org ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i')
         . ($course ? '   ·   Kurs: ' . $course['name'] : '')
         . '   ·   Kursantów: ' . count($data);
    $pdf->Cell($W, 5, $pl($sub), 0, 1);
    $pdf->Ln(2);

    if (!$data) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($W, 8, $pl('Brak aktywności kursantów w tym roku.'), 0, 1);
    }

    foreach ($data as $d) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 70) $pdf->AddPage();

        // Nagłówek kursanta
        $pdf->SetFillColor(233, 238, 245); $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell($W, 7, $pl($d['name']), 0, 1, 'L', true);
        $pdf->Ln(1);

        // ── Frekwencja (per kurs, cały rok) ──
        $att = $d['att'];
        $pdf->SetFont('Helvetica', 'B', 8.5); $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell($W, 5, $pl('Frekwencja (rok ' . $year . ')'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        $wCourse = $W - 4 - 22 - 20 - 22 - 24;
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->Cell(4);
        $pdf->Cell($wCourse, 6, $pl('Kurs'),    1, 0, 'L', true);
        $pdf->Cell(22, 6, $pl('Lekcje'),        1, 0, 'C', true);
        $pdf->Cell(20, 6, $pl('Obecny'),        1, 0, 'C', true);
        $pdf->Cell(22, 6, $pl('Nieobecny'),     1, 0, 'C', true);
        $pdf->Cell(24, 6, $pl('Frekwencja'),    1, 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 8);
        if (!$att['courses']) {
            $pdf->Cell(4);
            $pdf->Cell($wCourse + 88, 6, $pl('Brak lekcji z listą obecności w tym roku.'), 1, 1, 'L');
        } else {
            $fill = false;
            foreach ($att['courses'] as $c) {
                if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 8); }
                $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
                $extra = $c['cancelled_lesson'] ? '  (odw. lekcje: ' . $c['cancelled_lesson'] . ')' : '';
                $pdf->Cell(4);
                $pdf->Cell($wCourse, 6, $pl(mb_strimwidth($c['name'] . $extra, 0, 62, '…')), 1, 0, 'L', true);
                $pdf->Cell(22, 6, (string)$c['held'],    1, 0, 'C', true);
                $pdf->Cell(20, 6, (string)$c['present'], 1, 0, 'C', true);
                $pdf->Cell(22, 6, (string)$c['absent'],  1, 0, 'C', true);
                if ($c['pct'] === null) { $pdf->SetTextColor(140, 140, 140); $txt = '—'; }
                else { $col = $c['pct'] >= 80 ? [0, 120, 0] : ($c['pct'] >= 50 ? [180, 100, 0] : [170, 0, 0]); $pdf->SetTextColor($col[0], $col[1], $col[2]); $txt = $c['pct'] . '%'; }
                $pdf->SetFont('Helvetica', 'B', 8);
                $pdf->Cell(24, 6, $txt, 1, 1, 'C', true);
                $pdf->SetFont('Helvetica', '', 8); $pdf->SetTextColor(0, 0, 0);
                $fill = !$fill;
            }
            $pdf->SetFont('Helvetica', 'B', 8); $pdf->SetFillColor(224, 232, 244);
            $pdf->Cell(4);
            $pdf->Cell($wCourse, 6, $pl('Razem'),      1, 0, 'L', true);
            $pdf->Cell(22, 6, (string)$att['held'],    1, 0, 'C', true);
            $pdf->Cell(20, 6, (string)$att['present'], 1, 0, 'C', true);
            $pdf->Cell(22, 6, (string)$att['absent'],  1, 0, 'C', true);
            $ov = $att['pct'];
            if ($ov === null) { $pdf->SetTextColor(140, 140, 140); $ovt = '—'; }
            else { $oc = $ov >= 80 ? [0, 120, 0] : ($ov >= 50 ? [180, 100, 0] : [170, 0, 0]); $pdf->SetTextColor($oc[0], $oc[1], $oc[2]); $ovt = $ov . '%'; }
            $pdf->Cell(24, 6, $ovt, 1, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln(2);

        // ── Rozliczenia (rozbicie na miesiące) ──
        $bil = $d['bil']; $bal = $d['bal'];
        $pdf->SetFont('Helvetica', 'B', 8.5); $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell($W, 5, $pl('Rozliczenia (rok ' . $year . ')'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        $wM = 40; $wCol = ($W - 4 - $wM) / 3;
        $pdf->SetFont('Helvetica', 'B', 8); $pdf->SetFillColor(224, 232, 244);
        $pdf->Cell(4);
        $pdf->Cell($wM, 6, $pl('Miesiąc'),        1, 0, 'L', true);
        $pdf->Cell($wCol, 6, $pl('Należności'),   1, 0, 'R', true);
        $pdf->Cell($wCol, 6, $pl('Zapłacone'),    1, 0, 'R', true);
        $pdf->Cell($wCol, 6, $pl('Wpłaty'),       1, 1, 'R', true);

        $pdf->SetFont('Helvetica', '', 8);
        $anyMonth = false; $fill = false;
        foreach ($bil['months'] as $m => $mm) {
            if ($mm['charges'] == 0.0 && $mm['paid'] == 0.0 && $mm['payments'] == 0.0) continue;
            $anyMonth = true;
            if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 8); }
            $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
            $pdf->Cell(4);
            $pdf->Cell($wM, 6, $pl($_mon3[$m] . ' ' . $year), 1, 0, 'L', true);
            $pdf->Cell($wCol, 6, ti_pr_zl($mm['charges']),  1, 0, 'R', true);
            $pdf->Cell($wCol, 6, ti_pr_zl($mm['paid']),     1, 0, 'R', true);
            $pdf->Cell($wCol, 6, ti_pr_zl($mm['payments']), 1, 1, 'R', true);
            $fill = !$fill;
        }
        if (!$anyMonth) {
            $pdf->Cell(4);
            $pdf->Cell($wM + 3 * $wCol, 6, $pl('Brak rozliczeń w tym roku.'), 1, 1, 'L');
        } else {
            $t = $bil['totals'];
            $pdf->SetFont('Helvetica', 'B', 8); $pdf->SetFillColor(224, 232, 244);
            $pdf->Cell(4);
            $pdf->Cell($wM, 6, $pl('Razem'),            1, 0, 'L', true);
            $pdf->Cell($wCol, 6, ti_pr_zl($t['charges']),  1, 0, 'R', true);
            $pdf->Cell($wCol, 6, ti_pr_zl($t['paid']),     1, 0, 'R', true);
            $pdf->Cell($wCol, 6, ti_pr_zl($t['payments']), 1, 1, 'R', true);
        }

        // Saldo bieżące konta
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell(4);
        if ($bal['debt'] > 0.005)       { $pdf->SetTextColor(170, 0, 0);  $st = 'Saldo konta: niedopłata ' . ti_pr_zl($bal['debt']) . ' zł'; }
        elseif ($bal['credit'] > 0.005) { $pdf->SetTextColor(0, 120, 0);  $st = 'Saldo konta: nadpłata ' . ti_pr_zl($bal['credit']) . ' zł'; }
        else                            { $pdf->SetTextColor(80, 80, 80); $st = 'Saldo konta: rozliczone (0,00 zł)'; }
        $pdf->MultiCell($W - 4, 5, $pl($st), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
    }

    // Legenda
    $pdf->SetFont('Helvetica', '', 6.5); $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($W, 4, $pl(
        'Frekwencja = obecności ÷ lekcje z listą obecności (statusy „odbyła się"/„zmiana indywidualna"; '
        . '„praca prowadzącego" i odwołane lekcje nie wchodzą do mianownika). '
        . 'Należności/Zapłacone dotyczą rozliczeń wystawionych w danym miesiącu; Wpłaty — kwot zaksięgowanych wg daty wpłaty. '
        . 'Saldo konta jest bieżące (całościowe).'), 0, 'L');

    $__pdfData = $pdf->Output('S');
    $__fname   = 'raport_uczestnik_rok_' . $year . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $__fname . '"');
    header('Content-Length: ' . strlen($__pdfData));
    echo $__pdfData;
    try { ti_report_to_ezd($__pdfData, $__fname, 'Raport roczny — per uczestnik · ' . $year, (int)current_user()['id']); } catch (\Throwable $e) { error_log('[ti_rpt_ezd] ' . $e->getMessage()); }
    exit;
} catch (\Throwable $e) {
    error_log('[participant_annual] ' . $year . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować raportu.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
