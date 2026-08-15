<?php
/**
 * karty30/ti/_instructor_report_body.php — wspólne ciało raportu „per prowadzący" (PDF).
 * Dołączane przez instructor_monthly.php / instructor_annual.php po ustaleniu okresu.
 * Wymaga zdefiniowanych zmiennych: $from, $to (YYYY-MM-DD), $report_title, $file_name, $err_tag.
 * Tylko frekwencja (bez kwot). Filtr: ?instructor_id=N.
 */
defined('APP_URL') || exit('Brak dostępu.');
if (!isset($from, $to, $report_title, $file_name)) { http_response_code(400); exit('Nieprawidłowe wywołanie.'); }

$instr_filter = (int)($_GET['instructor_id'] ?? 0);

// Grupowanie kursów wg prowadzącego
$byInstr = [];
foreach (k30_ti_courses(false) as $co) {
    $iid = (int)($co['instructor_id'] ?? 0);
    if ($instr_filter && $iid !== $instr_filter) continue;
    if (!isset($byInstr[$iid])) {
        $byInstr[$iid] = [
            'id'      => $iid,
            'name'    => $iid ? (trim((string)($co['instructor_name'] ?? '')) ?: ('Prowadzący #' . $iid)) : '(brak prowadzącego)',
            'courses' => [],
        ];
    }
    $byInstr[$iid]['courses'][] = $co;
}
uasort($byInstr, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// ── PDF ────────────────────────────────────────────────────────────────────────
require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
$FD  = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
$pl  = fn(string $s): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;
$org = defined('ORG_NAME') ? ORG_NAME : '';

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->
    $pdf->
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell($W, 9, $pl($report_title), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell($W, 5, $pl(($org ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i')), 0, 1);
    $pdf->Ln(2);

    // Kolumny: Kurs | Uczestnicy | Lekcje odbyte | Odwołane | Frekw. grupy
    $cW = ['name' => 0, 'u' => 26, 'h' => 30, 'c' => 26, 'fr' => 30];
    $cW['name'] = $W - ($cW['u'] + $cW['h'] + $cW['c'] + $cW['fr']);

    $pctColor = function ($p) use ($pdf) {
        if ($p === null) { $pdf->SetTextColor(140, 140, 140); return '—'; }
        $c = $p >= 80 ? [0, 120, 0] : ($p >= 50 ? [180, 100, 0] : [170, 0, 0]);
        $pdf->SetTextColor($c[0], $c[1], $c[2]); return $p . '%';
    };

    $shown = 0;
    foreach ($byInstr as $ins) {
        // Policz frekwencję każdej grupy prowadzącego
        $rows = []; $tHeld = $tPres = 0; $tLh = $tLc = 0; $any = false;
        foreach ($ins['courses'] as $co) {
            $ga = ti_pr_course_attendance((int)$co['id'], $from, $to);
            if (!$ga['participants'] && !$ga['lessons_held'] && !$ga['lessons_cancelled']) continue;
            $any = true;
            $rows[] = ['name' => $co['name'], 'track' => $ga['track'],
                       'part' => count($ga['participants']),
                       'lh' => $ga['lessons_held'], 'lc' => $ga['lessons_cancelled'], 'pct' => $ga['avg_pct']];
            $tHeld += $ga['held_total']; $tPres += $ga['present_total'];
            $tLh += $ga['lessons_held']; $tLc += $ga['lessons_cancelled'];
        }
        if (!$any) continue;
        $shown++;
        $ovPct = $tHeld > 0 ? (int)round($tPres / $tHeld * 100) : null;

        if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();

        // Nagłówek prowadzącego
        $pdf->SetFillColor(233, 238, 245); $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell($W, 7, $pl($ins['name']), 0, 1, 'L', true);
        $pdf->Ln(1);

        // Nagłówek tabeli
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->Cell($cW['name'], 6, $pl('Grupa / kurs'),      1, 0, 'L', true);
        $pdf->Cell($cW['u'],    6, $pl('Uczestnicy'),        1, 0, 'C', true);
        $pdf->Cell($cW['h'],    6, $pl('Lekcje odbyte'),     1, 0, 'C', true);
        $pdf->Cell($cW['c'],    6, $pl('Odwołane'),          1, 0, 'C', true);
        $pdf->Cell($cW['fr'],   6, $pl('Frekw. grupy'),      1, 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 8);
        $fill = false;
        foreach ($rows as $r) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 18) { $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 8); }
            $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
            $nm = $r['name'] . ($r['track'] === 0 ? '  (bez listy obecności)' : '');
            $pdf->Cell($cW['name'], 6, $pl(mb_strimwidth($nm, 0, 58, '…')), 1, 0, 'L', true);
            $pdf->Cell($cW['u'],    6, (string)$r['part'], 1, 0, 'C', true);
            $pdf->Cell($cW['h'],    6, (string)$r['lh'],   1, 0, 'C', true);
            $pdf->Cell($cW['c'],    6, (string)$r['lc'],   1, 0, 'C', true);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Cell($cW['fr'],   6, $pctColor($r['pct']), 1, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8);
            $fill = !$fill;
        }
        // Razem prowadzący
        $pdf->SetFont('Helvetica', 'B', 8); $pdf->SetFillColor(224, 232, 244);
        $pdf->Cell($cW['name'], 6, $pl('Razem'),   1, 0, 'L', true);
        $pdf->Cell($cW['u'],    6, (string)count($rows), 1, 0, 'C', true);
        $pdf->Cell($cW['h'],    6, (string)$tLh,   1, 0, 'C', true);
        $pdf->Cell($cW['c'],    6, (string)$tLc,   1, 0, 'C', true);
        $pdf->Cell($cW['fr'],   6, $pctColor($ovPct), 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
    }

    if (!$shown) { $pdf->SetFont('Helvetica', '', 10); $pdf->Cell($W, 8, $pl('Brak aktywności prowadzących w tym okresie.'), 0, 1); }

    $pdf->SetFont('Helvetica', '', 6.5); $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($W, 4, $pl(
        'Frekwencja grupy = suma obecności ÷ suma lekcji z listą obecności (statusy „odbyła się"/„zmiana '
        . 'indywidualna"). „Uczestnicy" = aktywni zapisani do grupy. Kursy bez listy obecności nie mają frekwencji.'), 0, 'L');

    $__pdfData = $pdf->Output('S');
    $__fname   = $file_name . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $__fname . '"');
    header('Content-Length: ' . strlen($__pdfData));
    echo $__pdfData;
    try { ti_report_to_ezd($__pdfData, $__fname, $report_title, (int)current_user()['id']); } catch (\Throwable $e) { error_log('[ti_rpt_ezd] ' . $e->getMessage()); }
    exit;
} catch (\Throwable $e) {
    error_log('[' . ($err_tag ?? 'instructor_report') . '] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować raportu.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
