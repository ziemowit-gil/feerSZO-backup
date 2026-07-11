<?php
/**
 * karty30/ti/wup_report.php — Sprawozdanie do Wojewódzkiego Urzędu Pracy (TI) → PDF.
 *
 * Zestawienie zajęć na potrzeby sprawozdawczości wg ustawy z dnia 20 kwietnia 2004 r.
 * o promocji zatrudnienia i instytucjach rynku pracy. Zawiera:
 *   1. prowadzącego per grupa (grupy pogrupowane wg prowadzącego),
 *   2. liczbę osób (unikalnych uczestników łącznie),
 *   3. liczbę uczestników per prowadzący,
 *   4. czas pracy per prowadzący (suma czasu lekcji odbytych, w godzinach),
 *   oraz miejsce na podpis kierownika.
 *
 * GET: ?from=YYYY-MM-DD&to=YYYY-MM-DD (zakres; domyślnie bieżący miesiąc),
 *      ?m=YYYY-MM (skrót — cały miesiąc), ?instructor_id=N (opcjonalnie — jeden prowadzący).
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

// ── Okres ──────────────────────────────────────────────────────────────────────
$from = (string)($_GET['from'] ?? '');
$to   = (string)($_GET['to'] ?? '');
$ok   = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
if (!$ok($from) || !$ok($to)) {
    $ym = (string)($_GET['m'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $from = $ym . '-01';
    $to   = date('Y-m-t', strtotime($from));
}
if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }

$instr_filter = (int)($_GET['instructor_id'] ?? 0);

// ── Dane organizacji (nadawca / stopka) ─────────────────────────────────────────
$S = function (string $k): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
    return trim((string)($r['value'] ?? ''));
};
$org_name   = $S('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres  = $S('org_adres');
$org_miejsc = $S('org_miejscowosc');
$org_nip    = $S('org_nip');
$org_regon  = $S('org_regon');

// ── Zbierz dane: grupy z zajęciami w okresie, pogrupowane wg prowadzącego ────────
$byInstr = [];        // iid => ['name'=>, 'groups'=>[...], 'ids'=>[...distinct...]]
$all_ids = [];        // unikalne osoby łącznie
$grand   = ['groups' => 0, 'participants' => 0, 'mins' => 0, 'lessons' => 0];

foreach (k30_ti_courses(false) as $co) {
    $iid = (int)($co['instructor_id'] ?? 0);
    if ($instr_filter && $iid !== $instr_filter) continue;
    $cid = (int)$co['id'];

    $w = db_one(
        "SELECT COALESCE(SUM(duration_min),0) AS mins, COUNT(*) AS lessons
         FROM k30_ti_sessions
         WHERE course_id=? AND lesson_date BETWEEN ? AND ?
           AND status IN ('held','individual_change','remote_material')",
        [$cid, $from, $to]);
    $lessons = (int)$w['lessons'];
    if ($lessons === 0) continue; // grupa bez zajęć w okresie — pomijamy
    $mins = (int)$w['mins'];

    $ids = array_map('intval', array_column(
        db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]),
        'client_id'));
    $part = count($ids);

    if (!isset($byInstr[$iid])) {
        $byInstr[$iid] = [
            'name'   => $iid ? (trim((string)($co['instructor_name'] ?? '')) ?: ('Prowadzący #' . $iid)) : '(brak przypisanego prowadzącego)',
            'groups' => [], 'ids' => [], 'mins' => 0, 'participants' => 0, 'lessons' => 0,
        ];
    }
    $byInstr[$iid]['groups'][] = ['name' => $co['name'], 'participants' => $part, 'mins' => $mins, 'lessons' => $lessons];
    foreach ($ids as $id) { $byInstr[$iid]['ids'][$id] = true; $all_ids[$id] = true; }
    $byInstr[$iid]['mins']         += $mins;
    $byInstr[$iid]['participants'] += $part;
    $byInstr[$iid]['lessons']      += $lessons;

    $grand['groups']++; $grand['participants'] += $part; $grand['mins'] += $mins; $grand['lessons'] += $lessons;
}
uasort($byInstr, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$fmtH = fn(int $m): string => number_format($m / 60, 2, ',', ' ');
$MONTHS = TI_PR_MONTHS_PL;
$period_txt = date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to));

// ── PDF ────────────────────────────────────────────────────────────────────────
require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
$FD = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
$pl = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetMargins(14, 14, 14);
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $FD);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $FD);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 28;

    // Nadawca (lewa) + miejscowość/data (prawa)
    $pdf->SetFont('DejaVu', 'B', 9);
    $topY = $pdf->GetY();
    $pdf->MultiCell($W * 0.62, 4.5, $pl($org_name), 0, 'L');
    $pdf->SetFont('DejaVu', '', 8);
    $addr = trim($org_adres . ($org_miejsc ? ($org_adres ? ', ' : '') . $org_miejsc : ''));
    if ($addr)       $pdf->MultiCell($W * 0.62, 4, $pl($addr), 0, 'L');
    $reg = trim(($org_nip ? 'NIP ' . $org_nip : '') . ($org_regon ? ($org_nip ? '   ·   ' : '') . 'REGON ' . $org_regon : ''));
    if ($reg)        $pdf->MultiCell($W * 0.62, 4, $pl($reg), 0, 'L');
    $endLeftY = $pdf->GetY();
    // prawa kolumna
    $pdf->SetXY(14 + $W * 0.62, $topY);
    $pdf->SetFont('DejaVu', '', 8.5);
    $placeDate = ($org_miejsc ? $org_miejsc . ', ' : '') . 'dnia ' . date('d.m.Y') . ' r.';
    $pdf->MultiCell($W * 0.38, 4.5, $pl($placeDate), 0, 'R');
    $pdf->SetY(max($endLeftY, $topY) + 4);

    // Tytuł
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->MultiCell($W, 6.5, $pl('ZESTAWIENIE ZAJĘĆ / SPRAWOZDANIE'), 0, 'C');
    $pdf->SetFont('DejaVu', '', 8.5); $pdf->SetTextColor(70, 70, 70);
    $pdf->MultiCell($W, 4.5, $pl('dla Wojewódzkiego Urzędu Pracy — sporządzono na podstawie ustawy z dnia 20 kwietnia 2004 r. '
        . 'o promocji zatrudnienia i instytucjach rynku pracy'), 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('DejaVu', 'B', 9.5);
    $pdf->MultiCell($W, 5.5, $pl('za okres: ' . $period_txt), 0, 'C');
    $pdf->Ln(3);

    // Kolumny tabeli: Lp | Grupa | Liczba zajęć | Liczba uczestników | Czas pracy (godz.)
    $cLp = 9; $cLes = 22; $cPart = 30; $cH = 30;
    $cGrp = $W - ($cLp + $cLes + $cPart + $cH);

    $tableHead = function () use ($pdf, $pl, $W, $cLp, $cGrp, $cLes, $cPart, $cH) {
        $pdf->SetFont('DejaVu', 'B', 8);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(150, 165, 185);
        $pdf->Cell($cLp,   7, $pl('Lp.'),        1, 0, 'C', true);
        $pdf->Cell($cGrp,  7, $pl('Grupa (kurs)'), 1, 0, 'L', true);
        $pdf->Cell($cLes,  7, $pl('Liczba zajęć'), 1, 0, 'C', true);
        $pdf->Cell($cPart, 7, $pl('Liczba uczestników'), 1, 0, 'C', true);
        $pdf->Cell($cH,    7, $pl('Czas pracy (godz.)'), 1, 1, 'C', true);
    };
    $tableHead();

    $pdf->SetFont('DejaVu', '', 8.5);
    $lp = 0;
    if (!$byInstr) {
        $pdf->Cell($W, 7, $pl('Brak zajęć w wybranym okresie.'), 1, 1, 'C');
    }
    foreach ($byInstr as $ins) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 40) { $pdf->AddPage(); $tableHead(); $pdf->SetFont('DejaVu', '', 8.5); }

        // Pasek prowadzącego (pkt 1: prowadzący per grupa)
        $pdf->SetFont('DejaVu', 'B', 9); $pdf->SetFillColor(236, 240, 246);
        $pdf->Cell($W, 6.5, $pl('Prowadzący: ' . $ins['name']), 1, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 8.5);

        $fill = false;
        foreach ($ins['groups'] as $g) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 24) { $pdf->AddPage(); $tableHead(); $pdf->SetFont('DejaVu', '', 8.5); }
            $lp++;
            $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
            $pdf->Cell($cLp,   6, (string)$lp, 1, 0, 'C', true);
            $pdf->Cell($cGrp,  6, $pl(mb_strimwidth($g['name'], 0, 70, '…')), 1, 0, 'L', true);
            $pdf->Cell($cLes,  6, (string)$g['lessons'], 1, 0, 'C', true);
            $pdf->Cell($cPart, 6, (string)$g['participants'], 1, 0, 'C', true);
            $pdf->Cell($cH,    6, $fmtH($g['mins']), 1, 1, 'R', true);
            $fill = !$fill;
        }
        // Podsumowanie prowadzącego (pkt 3 + 4)
        $pdf->SetFont('DejaVu', 'B', 8.5); $pdf->SetFillColor(224, 232, 244);
        $pdf->Cell($cLp + $cGrp, 6.5, $pl('Razem — ' . $ins['name']
            . '   (osób: ' . count($ins['ids']) . ')'), 1, 0, 'L', true);
        $pdf->Cell($cLes,  6.5, (string)$ins['lessons'], 1, 0, 'C', true);
        $pdf->Cell($cPart, 6.5, (string)$ins['participants'], 1, 0, 'C', true);
        $pdf->Cell($cH,    6.5, $fmtH($ins['mins']), 1, 1, 'R', true);
        $pdf->SetFont('DejaVu', '', 8.5);
    }

    // Wiersz ogółem (pkt 2: liczba osób)
    if ($byInstr) {
        $pdf->SetFont('DejaVu', 'B', 9); $pdf->SetFillColor(210, 221, 236);
        $pdf->Cell($cLp + $cGrp, 7, $pl('OGÓŁEM   (liczba osób / unikalnych uczestników: ' . count($all_ids) . ')'), 1, 0, 'L', true);
        $pdf->Cell($cLes,  7, (string)$grand['lessons'], 1, 0, 'C', true);
        $pdf->Cell($cPart, 7, (string)$grand['participants'], 1, 0, 'C', true);
        $pdf->Cell($cH,    7, $fmtH($grand['mins']), 1, 1, 'R', true);
    }
    $pdf->Ln(2);

    // Podsumowanie skrótowe
    $pdf->SetFont('DejaVu', '', 8.5);
    $pdf->MultiCell($W, 4.5, $pl(
        'Prowadzących: ' . count($byInstr)
        . '   ·   Grup: ' . $grand['groups']
        . '   ·   Liczba osób (unikalnych): ' . count($all_ids)
        . '   ·   Liczba uczestników w grupach (łącznie): ' . $grand['participants']
        . '   ·   Łączny czas pracy: ' . $fmtH($grand['mins']) . ' godz.'), 0, 'L');
    $pdf->SetFont('DejaVu', '', 6.8); $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($W, 3.8, $pl(
        'Czas pracy = suma czasu trwania zajęć odbytych w okresie (statusy: odbyła się / zmiana indywidualna / praca własna prowadzącego). '
        . 'Liczba uczestników = osoby aktywnie zapisane do grupy; „liczba osób" nie liczy podwójnie osób zapisanych do kilku grup. '
        . 'Uwzględniono wyłącznie grupy, w których w podanym okresie odbyły się zajęcia.'), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);

    // Miejsce na podpisy
    $pdf->Ln(12);
    if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
    $colW = $W / 2;
    $ySig = $pdf->GetY() + 10;
    $pdf->SetDrawColor(120, 120, 120);
    // linia sporządzającego
    $pdf->Line(14 + 8, $ySig, 14 + $colW - 8, $ySig);
    // linia kierownika
    $pdf->Line(14 + $colW + 8, $ySig, 14 + $W - 8, $ySig);
    $pdf->SetXY(14, $ySig + 1);
    $pdf->SetFont('DejaVu', '', 8);
    $pdf->Cell($colW, 4, $pl('(sporządził/a — imię, nazwisko i podpis)'), 0, 0, 'C');
    $pdf->Cell($colW, 4, $pl('(podpis i pieczęć kierownika)'), 0, 1, 'C');

    while (ob_get_level() > 0) ob_end_clean();
    $pdf->Output('D', 'sprawozdanie_WUP_' . $from . '_' . $to . '.pdf');
    exit;
} catch (\Throwable $e) {
    error_log('[wup_report] ' . $from . '..' . $to . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować sprawozdania.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
