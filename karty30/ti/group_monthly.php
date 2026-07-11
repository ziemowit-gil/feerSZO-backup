<?php
/**
 * karty30/ti/group_monthly.php — Raport MIESIĘCZNY per grupa (kurs) → PDF.
 * Sekcja na kurs: frekwencja uczestników w tej grupie + ich rozliczenia
 * (należności/wpłaty w miesiącu) i bieżące saldo konta. Podsumowanie grupy: śr. frekwencja.
 * GET: ?m=YYYY-MM (domyślnie bieżący), ?course_id=N (opcjonalnie — jedna grupa).
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

$course_id   = (int)($_GET['course_id'] ?? 0);
$all_courses = k30_ti_courses(false);
if ($course_id) $all_courses = array_values(array_filter($all_courses, fn($c) => (int)$c['id'] === $course_id));

$month_label = (TI_PR_MONTHS_PL[$month] ?? '') . ' ' . $year;

// ── PDF ────────────────────────────────────────────────────────────────────────
require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
$FD  = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
$pl  = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
$org = defined('ORG_NAME') ? ORG_NAME : '';

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $FD);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $FD);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 9, $pl('Raport miesięczny — per grupa · ' . $month_label), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('DejaVu', '', 8);
    $pdf->Cell($W, 5, $pl(($org ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i') . '   ·   Grup: ' . count($all_courses)), 0, 1);
    $pdf->Ln(2);

    // Kolumny: Kursant | Lekcje | Ob. | Nieob. | Frekw. | Nal. | Wpł. | Saldo
    $cW = ['name' => 44, 'les' => 13, 'ob' => 12, 'ni' => 14, 'fr' => 17, 'nal' => 22, 'wpl' => 22, 'sal' => 0];
    $cW['sal'] = $W - array_sum($cW);

    $pctColor = function ($p) use ($pdf) {
        if ($p === null) { $pdf->SetTextColor(140, 140, 140); return '—'; }
        $c = $p >= 80 ? [0, 120, 0] : ($p >= 50 ? [180, 100, 0] : [170, 0, 0]);
        $pdf->SetTextColor($c[0], $c[1], $c[2]); return $p . '%';
    };

    $shown = 0;
    foreach ($all_courses as $co) {
        $cid = (int)$co['id'];
        $ga  = ti_pr_course_attendance($cid, $from, $to);
        // Pomiń grupy zupełnie bez aktywności w miesiącu
        if (!$ga['participants'] && !$ga['lessons_held'] && !$ga['lessons_cancelled']) continue;
        $shown++;

        if ($pdf->GetY() > $pdf->GetPageHeight() - 45) $pdf->AddPage();

        // Nagłówek grupy
        $pdf->SetFillColor(233, 238, 245); $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('DejaVu', 'B', 11);
        $htxt = $co['name'] . ($co['instructor_name'] ? '   ·   ' . $co['instructor_name'] : '');
        $pdf->Cell($W, 7, $pl($htxt), 0, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 7.5); $pdf->SetTextColor(80, 80, 80);
        $sum = 'Lekcje odbyte: ' . $ga['lessons_held'] . '   ·   odwołane: ' . $ga['lessons_cancelled']
             . '   ·   uczestników: ' . count($ga['participants'])
             . '   ·   frekwencja grupy: ' . ($ga['avg_pct'] === null ? '—' : $ga['avg_pct'] . '%')
             . ($ga['track'] === 0 ? '   ·   (kurs bez listy obecności)' : '');
        $pdf->Cell($W, 5, $pl($sum), 0, 1);
        $pdf->SetTextColor(0, 0, 0); $pdf->Ln(1);

        // Nagłówek tabeli
        $pdf->SetFont('DejaVu', 'B', 7.5);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->Cell($cW['name'], 6, $pl('Kursant'),   1, 0, 'L', true);
        $pdf->Cell($cW['les'],  6, $pl('Lekcje'),    1, 0, 'C', true);
        $pdf->Cell($cW['ob'],   6, $pl('Ob.'),       1, 0, 'C', true);
        $pdf->Cell($cW['ni'],   6, $pl('Nieob.'),    1, 0, 'C', true);
        $pdf->Cell($cW['fr'],   6, $pl('Frekw.'),    1, 0, 'C', true);
        $pdf->Cell($cW['nal'],  6, $pl('Należn.'),   1, 0, 'R', true);
        $pdf->Cell($cW['wpl'],  6, $pl('Wpłaty'),    1, 0, 'R', true);
        $pdf->Cell($cW['sal'],  6, $pl('Saldo'),     1, 1, 'R', true);

        $pdf->SetFont('DejaVu', '', 7.5);
        $fill = false;
        foreach ($ga['participants'] as $p) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage(); $pdf->SetFont('DejaVu', '', 7.5);
            }
            $bil = ti_pr_billing_month((int)$p['client_id'], $year, $month);
            $bal = ti_client_balance((int)$p['client_id']);
            $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);

            $pdf->Cell($cW['name'], 6, $pl(mb_strimwidth($p['name'], 0, 40, '…')), 1, 0, 'L', true);
            $pdf->Cell($cW['les'],  6, (string)$p['held'],    1, 0, 'C', true);
            $pdf->Cell($cW['ob'],   6, (string)$p['present'], 1, 0, 'C', true);
            $pdf->Cell($cW['ni'],   6, (string)$p['absent'],  1, 0, 'C', true);
            $pdf->SetFont('DejaVu', 'B', 7.5);
            $pdf->Cell($cW['fr'],   6, $pctColor($p['pct']), 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('DejaVu', '', 7.5);
            $pdf->Cell($cW['nal'],  6, ti_pr_zl($bil['charges']),  1, 0, 'R', true);
            $pdf->Cell($cW['wpl'],  6, ti_pr_zl($bil['payments']), 1, 0, 'R', true);
            // Saldo: +nadpłata (zielony) / -niedopłata (czerwony) / 0 rozliczone
            if ($bal['debt'] > 0.005)       { $pdf->SetTextColor(170, 0, 0);  $stxt = '-' . ti_pr_zl($bal['debt']); }
            elseif ($bal['credit'] > 0.005) { $pdf->SetTextColor(0, 120, 0);  $stxt = '+' . ti_pr_zl($bal['credit']); }
            else                            { $pdf->SetTextColor(80, 80, 80); $stxt = ti_pr_zl(0); }
            $pdf->SetFont('DejaVu', 'B', 7.5);
            $pdf->Cell($cW['sal'],  6, $stxt, 1, 1, 'R', true);
            $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('DejaVu', '', 7.5);
            $fill = !$fill;
        }
        if (!$ga['participants']) {
            $pdf->Cell($W, 6, $pl('Brak aktywnych uczestników w tej grupie.'), 1, 1, 'L');
        }
        $pdf->Ln(4);
    }

    if (!$shown) { $pdf->SetFont('DejaVu', '', 10); $pdf->Cell($W, 8, $pl('Brak aktywności grup w tym miesiącu.'), 0, 1); }

    $pdf->SetFont('DejaVu', '', 6.5); $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($W, 4, $pl(
        'Frekwencja grupy = suma obecności ÷ suma lekcji z listą obecności. '
        . 'Należności/Wpłaty dotyczą wybranego miesiąca; Saldo (+ nadpłata / − niedopłata) jest bieżące '
        . 'i dotyczy CAŁEGO konta kursanta (wszystkie jego grupy).'), 0, 'L');

    while (ob_get_level() > 0) ob_end_clean();
    $pdf->Output('D', 'raport_grupa_' . $ym . '.pdf');
    exit;
} catch (\Throwable $e) {
    error_log('[group_monthly] ' . $ym . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować raportu.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
