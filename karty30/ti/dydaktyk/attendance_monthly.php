<?php
/**
 * karty30/ti/dydaktyk/attendance_monthly.php — Raport miesięczny frekwencji.
 * GET: ?month=YYYY-MM  (domyślnie bieżący miesiąc)
 *      ?course_id=N    (opcjonalnie — tylko jeden kurs; domyślnie wszystkie dostępne)
 * Dostęp: zalogowany dydaktyk (własne kursy; pracownik D3 — wszystkie).
 */
require_once __DIR__ . '/auth.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

// Miesiąc — domyślnie bieżący
$month_raw = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month_raw)) $month_raw = date('Y-m');
[$yr, $mo] = array_map('intval', explode('-', $month_raw));
$month_first = sprintf('%04d-%02d-01', $yr, $mo);
$month_last  = date('Y-m-t', strtotime($month_first));

// Kursy dostępne dla dydaktyka
$all_courses = dyd_courses($uid);

// Filtr kursu
$course_id_filter = (int)($_GET['course_id'] ?? 0);
if ($course_id_filter && !dyd_owns_course($uid, $course_id_filter)) {
    http_response_code(403); exit('Brak uprawnień do tego kursu.');
}
if ($course_id_filter) {
    $all_courses = array_values(array_filter($all_courses, fn($c) => (int)$c['id'] === $course_id_filter));
}

// Kursy z wyłączonym liczeniem frekwencji nie wchodzą do raportu.
// Filtrujemy w PHP (z domyślną wartością 1), aby nie zależeć od istnienia
// kolumny track_attendance w SQL — raport działa też przed migracją.
$all_courses = array_values(array_filter($all_courses, fn($c) => (int)($c['track_attendance'] ?? 1) === 1));

// Lekcje w miesiącu — dla wybranych kursów
$course_ids = array_column($all_courses, 'id');
if (!$course_ids) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Brak kursów do raportu.');
}

$placeholders = implode(',', array_fill(0, count($course_ids), '?'));
$sessions = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.course_id, s.status, s.topic,
            c.name AS course_name
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     WHERE s.course_id IN ($placeholders)
       AND s.lesson_date BETWEEN ? AND ?
       AND (s.status IS NULL OR s.status NOT IN ('removed','remote_material'))
     ORDER BY s.course_id, s.lesson_date, s.time_from",
    array_merge($course_ids, [$month_first, $month_last])
);

if (!$sessions) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Brak lekcji w miesiącu $month_raw.");
}

// Aktywni kursanci per kurs
$enrollees_by_course = [];
foreach ($course_ids as $cid) {
    $rows = db_all(
        "SELECT e.client_id, cl.name FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active' ORDER BY cl.name",
        [$cid]
    );
    if ($rows) $enrollees_by_course[$cid] = $rows;
}

// Mapa obecności
$sess_ids = array_column($sessions, 'id');
if ($sess_ids) {
    $ph2 = implode(',', array_fill(0, count($sess_ids), '?'));
    $att_raw = db_all(
        "SELECT session_id, client_id, attended, COALESCE(cancelled,0) AS cancelled
         FROM k30_ti_attendance WHERE session_id IN ($ph2)",
        $sess_ids
    );
    $att = [];
    foreach ($att_raw as $r) $att[(int)$r['session_id']][(int)$r['client_id']] = $r;
} else {
    $att = [];
}

// Grupuj lekcje wg kursu
$sessions_by_course = [];
foreach ($sessions as $s) $sessions_by_course[(int)$s['course_id']][] = $s;

// PDF
require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
$FONT_DIR = dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/font/';

function _mr(string $s): string {
    return iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;
}

$MONTHS_PL_FULL = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
                   'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
$ORG = defined('ORG_NAME') ? ORG_NAME : '';

// Generowanie PDF w try/catch — czytelny powód zamiast gołego 500
try {
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetMargins(10, 10, 10);
$pdf->
$pdf->

$month_label = $MONTHS_PL_FULL[$mo] . ' ' . $yr;

foreach ($sessions_by_course as $cid => $c_sessions) {
    $course      = $all_courses[array_search($cid, array_column($all_courses, 'id'))];
    $c_enrollees = $enrollees_by_course[$cid] ?? [];
    if (!$c_enrollees) continue;

    $pdf->AddPage();
    $PW = $pdf->GetPageWidth() - 20;

    // Nagłówek
    $pdf->SetFillColor(15, 80, 150);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell($PW, 9, _mr('Raport frekwencji — ' . ($course['name'] ?? '')), 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell($PW, 5, _mr(($ORG ? $ORG . '   |   ' : '') . 'Miesiąc: ' . $month_label . '   |   Wygenerowano: ' . date('d.m.Y H:i')), 0, 1, 'C');
    $pdf->Ln(3);

    // Statystyki miesiaca
    $total_s = count($c_sessions);
    $canc_s  = count(array_filter($c_sessions, fn($s) => ($s['status'] ?? '') === 'cancelled'));
    $held_s  = $total_s - $canc_s;
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell($PW, 5, _mr("Lekcje w miesiącu: $total_s   |   Odbyłe się: $held_s   |   Odwołane: $canc_s   |   Aktywnych kursantów: " . count($c_enrollees)), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    // Szerokosci kolumn
    $name_w  = 50;
    $stat_w  = 16;
    $n_s     = count($c_sessions);
    $avail_w = $PW - $name_w - $stat_w;
    $sess_w  = min(max($avail_w / max($n_s, 1), 6), 14);
    $hfont   = $sess_w < 9 ? 6 : 7;
    $row_h   = 6;

    // Nagłówek tabeli — daty
    $pdf->SetFillColor(220, 232, 248);
    $pdf->SetDrawColor(180, 195, 215);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell($name_w, $row_h * 2, _mr('Kursant'), 1, 0, 'L', true);

    $pdf->SetFont('Helvetica', 'B', $hfont);
    foreach ($c_sessions as $s) {
        $dd   = date('d.m', strtotime($s['lesson_date']));
        $canc = ($s['status'] ?? '') === 'cancelled';
        $pdf->SetFillColor($canc ? 225 : 220, $canc ? 225 : 232, $canc ? 225 : 248);
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->MultiCell($sess_w, $row_h, _mr($dd), 1, 'C', true);
        $pdf->SetXY($x + $sess_w, $y);
    }
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetFillColor(220, 232, 248);
    $pdf->Cell($stat_w, $row_h * 2, _mr('Frekw.'), 1, 1, 'C', true);

    // Wiersze kursantów
    $pdf->SetFont('Helvetica', '', 7.5);
    $fill = false;
    $course_total_att = 0;
    $course_countable = 0;

    foreach ($c_enrollees as $e) {
        $cid2 = (int)$e['client_id'];
        if ($pdf->GetY() > $pdf->GetPageHeight() - 20) { $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 7.5); }
        $bg = $fill ? [248, 250, 254] : [255, 255, 255];
        $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);

        $pdf->Cell($name_w, $row_h, _mr(mb_strimwidth($e['name'], 0, 34, '...')), 1, 0, 'L', true);

        $present = 0; $countable = 0;
        foreach ($c_sessions as $s) {
            $sid  = (int)$s['id'];
            $a    = $att[$sid][$cid2] ?? null;
            $canc = !empty($a['cancelled']) || ($s['status'] ?? '') === 'cancelled';
            $ok   = !$canc && (int)($a['attended'] ?? 0);

            if (($s['status'] ?? '') === 'cancelled') {
                // Odwolana lekcja
                $pdf->SetFillColor(238, 238, 238);
                $pdf->SetTextColor(150, 150, 150);
                $pdf->Cell($sess_w, $row_h, '—', 1, 0, 'C', true);
            } elseif ($canc) {
                $pdf->SetFillColor(240, 240, 240);
                $pdf->SetTextColor(130, 130, 130);
                $pdf->Cell($sess_w, $row_h, 'x', 1, 0, 'C', true);
                $countable++;
            } elseif ($ok) {
                $pdf->SetFillColor(210, 240, 215);
                $pdf->SetTextColor(0, 100, 20);
                $pdf->Cell($sess_w, $row_h, '+', 1, 0, 'C', true);
                $present++; $countable++;
            } else {
                $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
                $pdf->SetTextColor(180, 60, 60);
                $pdf->Cell($sess_w, $row_h, '–', 1, 0, 'C', true);
                $countable++;
            }
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $pdf->SetTextColor(0, 0, 0);
        }

        $pct = $countable > 0 ? round($present / $countable * 100) : 0;
        $pc  = $pct >= 80 ? [0, 120, 0] : ($pct >= 50 ? [180, 100, 0] : [170, 0, 0]);
        $pdf->SetTextColor($pc[0], $pc[1], $pc[2]);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->Cell($stat_w, $row_h, $pct . '%', 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 7.5);

        $course_total_att += $present;
        $course_countable += $countable;
        $fill = !$fill;
    }

    // Wiersz podsumowania kursu
    $pdf->Ln(2);
    $pdf->SetFillColor(220, 232, 248);
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->Cell($name_w, $row_h, _mr('Frekwencja w lekcji:'), 1, 0, 'L', true);
    foreach ($c_sessions as $s) {
        $sid   = (int)$s['id'];
        $total_e = count($c_enrollees);
        $p = 0;
        foreach ($c_enrollees as $e) {
            $cid2 = (int)$e['client_id'];
            $a = $att[$sid][$cid2] ?? null;
            if ((int)($a['attended'] ?? 0) && !$a['cancelled'] && ($s['status'] ?? '') !== 'cancelled') $p++;
        }
        $col_pct = $total_e > 0 ? round($p / $total_e * 100) : 0;
        $cc = $col_pct >= 80 ? [0, 120, 0] : ($col_pct >= 50 ? [140, 90, 0] : [170, 0, 0]);
        $pdf->SetTextColor($cc[0], $cc[1], $cc[2]);
        $pdf->Cell($sess_w, $row_h, $col_pct . '%', 1, 0, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
    }
    $overall = $course_countable > 0 ? round($course_total_att / $course_countable * 100) : 0;
    $oc = $overall >= 80 ? [0, 120, 0] : ($overall >= 50 ? [140, 90, 0] : [170, 0, 0]);
    $pdf->SetTextColor($oc[0], $oc[1], $oc[2]);
    $pdf->Cell($stat_w, $row_h, $overall . '%', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    // Legenda
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', '', 6.5);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell($PW, 4, _mr('+  obecny     –  nieobecny     x  odwołany udział     —  lekcja odwołana     %  frekwencja (bez odwołanych)'), 0, 1, 'L');
}

$fname = 'frekwencja_' . str_replace('-', '_', $month_raw) . '_' . date('His') . '.pdf';
$pdf->Output('D', $fname);
exit;
} catch (\Throwable $e) {
    error_log('[attendance_monthly] ' . $month_raw . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować raportu frekwencji.\n";
    echo "Powód: " . $e->getMessage() . "\n";
    exit;
}
