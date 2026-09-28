<?php
/**
 * karty30/ti/dydaktyk/attendance_pdf.php — Eksport listy obecności kursu do PDF.
 * GET: course_id (wymagane)
 * Tabela krzyżowa: wiersze = kursanci, kolumny = lekcje (sortowane rosnąco po dacie).
 * Dostęp: zalogowany dydaktyk posiadający ten kurs.
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

$course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$course_id]);
if (!$course) { http_response_code(404); exit('Nie znaleziono kursu.'); }
if ((int)($course['track_attendance'] ?? 1) === 0) {
    http_response_code(200);
    exit('Frekwencja jest wyłączona dla tego kursu — raport obecności niedostępny.');
}

// Lekcje kursu — rosnąco po dacie. „Praca własna prowadzącego" (remote_material)
// jest wykluczona z listy obecności — nie liczymy dla niej obecności/nieobecności.
$show_cancelled = !empty($_GET['all']); // ?all=1 → pokaż też odwołane
$sessions = $show_cancelled
    ? db_all("SELECT * FROM k30_ti_sessions WHERE course_id=? AND status NOT IN ('remote_material','reserved') ORDER BY lesson_date, time_from", [$course_id])
    : db_all("SELECT * FROM k30_ti_sessions WHERE course_id=? AND (status IS NULL OR status NOT IN ('cancelled','remote_material','reserved')) ORDER BY lesson_date, time_from", [$course_id]);

// Aktywni kursanci
$enrollees = db_all(
    "SELECT e.client_id, cl.name FROM k30_ti_enrollments e
     JOIN k30_clients cl ON cl.id=e.client_id
     WHERE e.course_id=? AND e.status='active' ORDER BY cl.name",
    [$course_id]
);

if (!$sessions) { http_response_code(200); exit('Brak lekcji w tym kursie.'); }
if (!$enrollees){ http_response_code(200); exit('Brak aktywnych kursantów w tym kursie.'); }

// Mapa obecności: [session_id][client_id] => {attended, cancelled}
$att_raw = db_all(
    "SELECT a.session_id, a.client_id, a.attended, COALESCE(a.cancelled,0) AS cancelled
     FROM k30_ti_attendance a
     JOIN k30_ti_sessions s ON s.id=a.session_id
     WHERE s.course_id=?",
    [$course_id]
);
$att = [];
foreach ($att_raw as $r) $att[(int)$r['session_id']][(int)$r['client_id']] = $r;

// ── PDF ──────────────────────────────────────────────────────────────────────
require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pdf/logic/TiPdf.php';   // DejaVu z polskimi znakami + stopka

function _att_txt(string $s): string {
    return TiPdf::pl($s);
}

// Generowanie PDF w try/catch — zamiast gołego 500 pokaż czytelny powód
// (błąd fontu FPDF, brak biblioteki itp.) i zaloguj.
try {

$pdf = new TiPdf('L', 'mm', 'A4'); // landscape — więcej kolumn
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetMargins(10, 10, 10);
$pdf->AddPage();

$PW = $pdf->GetPageWidth() - 20; // szerokość robocza

// ── Nagłówek ─────────────────────────────────────────────────────────────────
$pdf->SetFillColor(15, 80, 150);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell($PW, 10, _att_txt('Lista obecności — ' . ($course['name'] ?? '')), 0, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Helvetica', '', 8);
$org = defined('ORG_NAME') ? ORG_NAME : '';
if ($org !== '') { $pdf->Cell($PW, 5, _att_txt($org), 0, 1, 'C'); }
$pdf->Ln(3);

// ── Oblicz szerokości kolumn ─────────────────────────────────────────────────
$name_w = 48;    // kolumna kursanta
$stat_w = 14;    // kolumna % obecności
$n_sess = count($sessions);
$sess_w_max = ($PW - $name_w - $stat_w) / max($n_sess, 1);
$sess_w = min(max($sess_w_max, 6), 14);  // 6–14 mm na kolumnę lekcji
// Gdy za dużo lekcji → zmniejszamy czcionkę nagłówków
$hdr_font = $sess_w < 9 ? 6 : 7;

// ── Nagłówki kolumn (daty lekcji) ────────────────────────────────────────────
$row_h = 6;
$pdf->SetFillColor(230, 236, 245);
$pdf->SetDrawColor(180, 190, 200);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell($name_w, $row_h * 2, _att_txt('Kursant'), 1, 0, 'L', true);

$pdf->SetFont('Helvetica', 'B', $hdr_font);
foreach ($sessions as $s) {
    $dd = date('d.m', strtotime($s['lesson_date']));
    $lm_abbr = match($s['lesson_method'] ?? '') {
        'stacjonarna' => 'S',
        'zdalna_zoom' => 'ZZ',
        'zdalna_inne' => 'ZI',
        default => '',
    };
    $hdr_text = $lm_abbr !== '' ? $dd . "\n" . $lm_abbr : $dd;
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $pdf->MultiCell($sess_w, $row_h, _att_txt($hdr_text), 1, 'C', true);
    $pdf->SetXY($x + $sess_w, $y);
}
$pdf->SetFont('Helvetica', 'B', 7);
$pdf->Cell($stat_w, $row_h * 2, _att_txt('%'), 1, 1, 'C', true);

// ── Wiersze kursantów ─────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 7.5);
$fill = false;
$total_sessions = count($sessions);

foreach ($enrollees as $e) {
    $cid = (int)$e['client_id'];
    if ($pdf->GetY() > $pdf->GetPageHeight() - 20) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 7.5);
    }
    $bg = $fill ? [248,250,252] : [255,255,255];
    $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);

    $pdf->Cell($name_w, $row_h, _att_txt(mb_strimwidth($e['name'], 0, 32, '…')), 1, 0, 'L', true);

    $present = 0;
    $countable = 0; // lekcje bez odwołanego udziału
    foreach ($sessions as $s) {
        $sid = (int)$s['id'];
        $a   = $att[$sid][$cid] ?? null;
        $canc= (int)($a['cancelled'] ?? 0);
        $ok  = (int)($a['attended'] ?? 0);

        if ($canc) {
            // odwołany udział — przekreślone / inny kolor
            $pdf->SetFillColor(220, 220, 220);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->Cell($sess_w, $row_h, 'x', 1, 0, 'C', true);
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $pdf->SetTextColor(0, 0, 0);
        } elseif ($ok) {
            $pdf->SetFillColor(200, 230, 200);
            $pdf->Cell($sess_w, $row_h, _att_txt('+'), 1, 0, 'C', true);
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $present++;
            $countable++;
        } else {
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $pdf->Cell($sess_w, $row_h, _att_txt('-'), 1, 0, 'C', true);
            $countable++;
        }
    }

    // % obecności (bez odwołanych)
    $pct = $countable > 0 ? round($present / $countable * 100) : 0;
    $pct_color = $pct >= 80 ? [0,120,0] : ($pct >= 50 ? [180,100,0] : [180,0,0]);
    $pdf->SetTextColor($pct_color[0], $pct_color[1], $pct_color[2]);
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->Cell($stat_w, $row_h, $pct . '%', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 7.5);
    $fill = !$fill;
}

// ── Podsumowanie kolumn (frekwencja per lekcja) ───────────────────────────────
$pdf->Ln(2);
$pdf->SetFillColor(230, 236, 245);
$pdf->SetFont('Helvetica', 'B', 7.5);
$pdf->Cell($name_w, $row_h, _att_txt('Frekwencja:'), 1, 0, 'L', true);
foreach ($sessions as $s) {
    $sid = (int)$s['id'];
    $total_e = count($enrollees);
    $p = 0;
    foreach ($enrollees as $e) {
        $cid = (int)$e['client_id'];
        $a   = $att[$sid][$cid] ?? null;
        if ((int)($a['attended'] ?? 0) && !(int)($a['cancelled'] ?? 0)) $p++;
    }
    $col_pct = $total_e > 0 ? round($p / $total_e * 100) : 0;
    $pdf->SetTextColor($col_pct >= 80 ? 0 : ($col_pct >= 50 ? 140 : 180), 0, 0);
    $pdf->Cell($sess_w, $row_h, $col_pct . '%', 1, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
}
$pdf->Cell($stat_w, $row_h, '', 1, 1, 'C', true);

// ── Legenda ───────────────────────────────────────────────────────────────────
$pdf->Ln(3);
$pdf->SetFont('Helvetica', '', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell($PW, 5, _att_txt('+  obecny     -  nieobecny     x  odwołany udział     %  odsetek lekcji z obecnością (bez odwołanych)'), 0, 1, 'L');
$pdf->Cell($PW, 4, _att_txt('Metoda lekcji w naglowku: S = stacjonarna     ZZ = zdalna Zoom     ZI = zdalna inne     (brak symbolu = nie wybrano)'), 0, 1, 'L');
if ($show_cancelled) {
    $pdf->Cell($PW, 4, _att_txt('Widok: wszystkie lekcje (w tym odwołane).'), 0, 1, 'L');
}
$pdf->SetTextColor(0, 0, 0);

$pdf->SetAutoPageBreak(false);
$pdf->SetY(-15);
$pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(130, 130, 130);
$pdf->Cell($PW, 4, _att_txt('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? '')), 0, 0, 'L');

// ── Wysyłka ───────────────────────────────────────────────────────────────────
$fname = 'obecnosc_' . preg_replace('/[^a-z0-9_]/i', '_', $course['name'] ?? 'kurs') . '_' . date('Ymd') . '.pdf';
ti_print_log_add('attendance_pdf', 'Lista obecności — ' . ($course['name'] ?? ''), $course_id, 0, [], $me);
$pdf->Output('D', $fname);
exit;
} catch (\Throwable $e) {
    error_log('[attendance_pdf] course=' . $course_id . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować PDF listy obecności.\n";
    echo "Powód: " . $e->getMessage() . "\n";
    exit;
}
