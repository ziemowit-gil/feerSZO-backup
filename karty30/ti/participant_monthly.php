<?php
/**
 * karty30/ti/participant_monthly.php — Raport MIESIĘCZNY per uczestnik (TI) → PDF.
 * Dla każdego aktywnego kursanta: frekwencja (per kurs) + rozliczenia w danym miesiącu.
 * GET: ?m=YYYY-MM (domyślnie bieżący), ?course_id=N (opcjonalnie — jeden kurs),
 *      ?client_id=N (opcjonalnie — jeden kursant).
 * Dostęp: pracownik K30 / administrator. Wynik: tylko PDF.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_participant_report.php';

k30_require_access();
karty30_migrate();

if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

$ym = (string)($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
[$year, $month] = array_map('intval', explode('-', $ym));
$from = sprintf('%04d-%02d-01', $year, $month);
$to   = date('Y-m-t', strtotime($from));

$course_id = (int)($_GET['course_id'] ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);
$course    = $course_id ? k30_ti_course_get($course_id) : null;

$clients = ti_pr_clients($course_id);
if ($client_id) $clients = array_values(array_filter($clients, fn($c) => (int)$c['id'] === $client_id));

// Zbierz dane; pomiń kursantów bez aktywności w miesiącu (brak lekcji i rozliczeń).
$data = [];
foreach ($clients as $cl) {
    $cid = (int)$cl['id'];
    $att = ti_pr_attendance($cid, $from, $to, $course_id);
    $bil = ti_pr_billing_month($cid, $year, $month);
    $bal = ti_client_balance($cid);
    $hasActivity = $att['held'] > 0 || $att['cancelled_lesson'] > 0
        || $bil['charges'] != 0.0 || $bil['payments'] != 0.0;
    if (!$hasActivity) continue;
    $data[] = ['name' => $cl['name'], 'att' => $att, 'bil' => $bil, 'bal' => $bal];
}

// ── PDF ────────────────────────────────────────────────────────────────────────
require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
$FD  = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
$pl  = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
$org = defined('ORG_NAME') ? ORG_NAME : '';
$month_label = (TI_PR_MONTHS_PL[$month] ?? '') . ' ' . $year;

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $FD);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $FD);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    // Nagłówek dokumentu
    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 9, $pl('Raport miesięczny — per uczestnik · ' . $month_label), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('DejaVu', '', 8);
    $sub = ($org ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i')
         . ($course ? '   ·   Kurs: ' . $course['name'] : '')
         . '   ·   Kursantów: ' . count($data);
    $pdf->Cell($W, 5, $pl($sub), 0, 1);
    $pdf->Ln(2);

    if (!$data) {
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell($W, 8, $pl('Brak aktywności kursantów w tym miesiącu.'), 0, 1);
    }

    foreach ($data as $d) {
        // Nowa strona, gdy zostało za mało miejsca na sensowny blok
        if ($pdf->GetY() > $pdf->GetPageHeight() - 55) $pdf->AddPage();

        // Nagłówek kursanta
        $pdf->SetFillColor(233, 238, 245); $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('DejaVu', 'B', 11);
        $pdf->Cell($W, 7, $pl($d['name']), 0, 1, 'L', true);
        $pdf->Ln(1);

        // ── Frekwencja ──
        $att = $d['att'];
        $pdf->SetFont('DejaVu', 'B', 8.5); $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell($W, 5, $pl('Frekwencja'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        $wCourse = $W - 4 - 22 - 20 - 22 - 24; // Kurs | Lekcje | Obecny | Nieob. | Frekw.
        $pdf->SetFont('DejaVu', 'B', 8);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->Cell(4);
        $pdf->Cell($wCourse, 6, $pl('Kurs'),        1, 0, 'L', true);
        $pdf->Cell(22, 6, $pl('Lekcje'),            1, 0, 'C', true);
        $pdf->Cell(20, 6, $pl('Obecny'),            1, 0, 'C', true);
        $pdf->Cell(22, 6, $pl('Nieobecny'),         1, 0, 'C', true);
        $pdf->Cell(24, 6, $pl('Frekwencja'),        1, 1, 'C', true);

        $pdf->SetFont('DejaVu', '', 8);
        if (!$att['courses']) {
            $pdf->Cell(4);
            $pdf->Cell($wCourse + 22 + 20 + 22 + 24, 6, $pl('Brak lekcji z listą obecności w tym miesiącu.'), 1, 1, 'L');
        } else {
            $fill = false;
            foreach ($att['courses'] as $c) {
                if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); $pdf->SetFont('DejaVu', '', 8); }
                $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
                $extra = $c['cancelled_lesson'] ? '  (odw. lekcje: ' . $c['cancelled_lesson'] . ')' : '';
                $pdf->Cell(4);
                $pdf->Cell($wCourse, 6, $pl(mb_strimwidth($c['name'] . $extra, 0, 62, '…')), 1, 0, 'L', true);
                $pdf->Cell(22, 6, (string)$c['held'],    1, 0, 'C', true);
                $pdf->Cell(20, 6, (string)$c['present'], 1, 0, 'C', true);
                $pdf->Cell(22, 6, (string)$c['absent'],  1, 0, 'C', true);
                if ($c['pct'] === null) { $pdf->SetTextColor(140, 140, 140); $txt = '—'; }
                else {
                    $col = $c['pct'] >= 80 ? [0, 120, 0] : ($c['pct'] >= 50 ? [180, 100, 0] : [170, 0, 0]);
                    $pdf->SetTextColor($col[0], $col[1], $col[2]); $txt = $c['pct'] . '%';
                }
                $pdf->SetFont('DejaVu', 'B', 8);
                $pdf->Cell(24, 6, $txt, 1, 1, 'C', true);
                $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(0, 0, 0);
                $fill = !$fill;
            }
            // Razem
            $pdf->SetFont('DejaVu', 'B', 8); $pdf->SetFillColor(224, 232, 244);
            $pdf->Cell(4);
            $pdf->Cell($wCourse, 6, $pl('Razem'),           1, 0, 'L', true);
            $pdf->Cell(22, 6, (string)$att['held'],         1, 0, 'C', true);
            $pdf->Cell(20, 6, (string)$att['present'],      1, 0, 'C', true);
            $pdf->Cell(22, 6, (string)$att['absent'],       1, 0, 'C', true);
            $ov = $att['pct'];
            if ($ov === null) { $pdf->SetTextColor(140, 140, 140); $ovt = '—'; }
            else { $oc = $ov >= 80 ? [0, 120, 0] : ($ov >= 50 ? [180, 100, 0] : [170, 0, 0]); $pdf->SetTextColor($oc[0], $oc[1], $oc[2]); $ovt = $ov . '%'; }
            $pdf->Cell(24, 6, $ovt, 1, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln(2);

        // ── Rozliczenia ──
        $bil = $d['bil']; $bal = $d['bal'];
        $pdf->SetFont('DejaVu', 'B', 8.5); $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell($W, 5, $pl('Rozliczenia'), 0, 1);
        $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('DejaVu', '', 8.5);
        $pdf->Cell(4);
        $pdf->MultiCell($W - 4, 5, $pl(
            'Należności w miesiącu: ' . ti_pr_zl($bil['charges']) . ' zł'
            . '   ·   Zapłacone z nich: ' . ti_pr_zl($bil['paid']) . ' zł'
            . '   ·   Wpłaty w miesiącu: ' . ti_pr_zl($bil['payments']) . ' zł'), 0, 'L');
        // Saldo bieżące konta
        $pdf->Cell(4);
        $pdf->SetFont('DejaVu', 'B', 8.5);
        if ($bal['debt'] > 0.005)        { $pdf->SetTextColor(170, 0, 0);  $st = 'Saldo konta: niedopłata ' . ti_pr_zl($bal['debt']) . ' zł'; }
        elseif ($bal['credit'] > 0.005)  { $pdf->SetTextColor(0, 120, 0);  $st = 'Saldo konta: nadpłata ' . ti_pr_zl($bal['credit']) . ' zł'; }
        else                             { $pdf->SetTextColor(80, 80, 80); $st = 'Saldo konta: rozliczone (0,00 zł)'; }
        $pdf->MultiCell($W - 4, 5, $pl($st), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
    }

    // Legenda
    $pdf->SetFont('DejaVu', '', 6.5); $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($W, 4, $pl(
        'Frekwencja = obecności ÷ lekcje z listą obecności (statusy „odbyła się"/„zmiana indywidualna"; '
        . '„praca własna prowadzącego" i odwołane lekcje nie wchodzą do mianownika). '
        . 'Saldo konta jest bieżące (całościowe), niezależne od wybranego miesiąca.'), 0, 'L');

    while (ob_get_level() > 0) ob_end_clean();
    $pdf->Output('D', 'raport_uczestnik_' . $ym . '.pdf');
    exit;
} catch (\Throwable $e) {
    error_log('[participant_monthly] ' . $ym . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować raportu.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
