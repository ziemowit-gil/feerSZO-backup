<?php
/**
 * karty30/ti/kursant/lessons_pdf.php — PDF listy zajęć kursanta.
 * GET: ?all=1 => wszystkie lekcje; domyślnie tylko nadchodzące + ostatnie 30 dni.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student = student_require();
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];
$name    = trim((string)($client['name'] ?? $student['login'] ?? 'Kursant'));

$show_all = !empty($_GET['all']);
$limit    = $show_all ? 2000 : 500;

// Pobierz lekcje — wszystkie kursy kursanta, malejąco po dacie
$lessons = db_all(
    "SELECT s.lesson_date, s.time_from, s.time_to, s.duration_min, s.topic, s.status,
            c.name AS course_name,
            a.attended, a.cancelled AS att_cancelled
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
     WHERE s.course_id IN (
         SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active'
     )
     " . (!$show_all ? "AND s.lesson_date >= date('now','-30 days')" : "") . "
     ORDER BY s.lesson_date ASC, s.time_from ASC
     LIMIT " . $limit,
    [$student['client_id'], $student['client_id']]
);

if (!$lessons) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Brak lekcji do wydruku.');
}

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';

$FONT_DIR = dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/font/';
$ORG      = defined('ORG_NAME') ? ORG_NAME : '';

function _lp(string $s): string {
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}

$DAYS_PL   = ['Nd','Pn','Wt','Sr','Czw','Pt','Sb'];
$MONTHS_PL = ['','sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paz','lis','gru'];

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetMargins(12, 12, 12);
$pdf->AddFont('DejaVu', '',  'dejavusans.json',  $FONT_DIR);
$pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $FONT_DIR);
$pdf->AddPage();

$PW = $pdf->GetPageWidth() - 24;

// Nagłówek
$pdf->SetFillColor(37, 99, 235);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('DejaVu', 'B', 13);
$pdf->Cell($PW, 10, _lp('Lista zajec — ' . $name), 0, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('DejaVu', '', 8);
$scope = $show_all ? 'wszystkie lekcje' : 'ostatnie 30 dni + nadchodzace';
$pdf->Cell($PW, 5, _lp(($ORG ? $ORG . '   |   ' : '') . 'Wydruk: ' . date('d.m.Y H:i') . '   |   Zakres: ' . $scope), 0, 1, 'C');
$pdf->Ln(3);

// Nagłówki tabeli
$COL = [30, 52, 22, 0]; // data, kurs, godz, temat (auto)
$COL[3] = $PW - array_sum(array_slice($COL, 0, 3));
$ROW_H = 6.5;

$pdf->SetFillColor(220, 230, 245);
$pdf->SetFont('DejaVu', 'B', 8);
$pdf->Cell($COL[0], $ROW_H, _lp('Data'),    1, 0, 'C', true);
$pdf->Cell($COL[1], $ROW_H, _lp('Kurs'),    1, 0, 'C', true);
$pdf->Cell($COL[2], $ROW_H, _lp('Godziny'), 1, 0, 'C', true);
$pdf->Cell($COL[3], $ROW_H, _lp('Temat'),   1, 1, 'C', true);

// Wiersze
$pdf->SetFont('DejaVu', '', 7.5);
$pdf->SetDrawColor(200, 210, 225);
$fill = false;
$today = date('Y-m-d');

foreach ($lessons as $l) {
    if ($pdf->GetY() > $pdf->GetPageHeight() - 18) {
        $pdf->AddPage();
        $pdf->SetFont('DejaVu', '', 7.5);
    }

    $ld   = (string)($l['lesson_date'] ?? '');
    $dt   = $ld ? new DateTime($ld) : null;
    $dow  = $dt ? $DAYS_PL[(int)$dt->format('w')] : '';
    $date = $dt ? ($dow . ' ' . $dt->format('d') . '.' . $MONTHS_PL[(int)$dt->format('n')] . '.' . $dt->format('y')) : '';

    $is_past      = $ld && $ld < $today;
    $is_cancelled = ($l['status'] ?? '') === 'cancelled' || !empty($l['att_cancelled']);
    $attended     = (int)($l['attended'] ?? 0);

    // Kolor tła wiersza
    if ($is_cancelled) {
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetTextColor(160, 160, 160);
    } elseif ($is_past && $attended) {
        $pdf->SetFillColor(235, 250, 238);
        $pdf->SetTextColor(0, 0, 0);
    } elseif ($is_past && !$attended) {
        $pdf->SetFillColor(255, 245, 245);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 255 : 255);
        $pdf->SetTextColor(0, 0, 0);
    }

    $time_str = '';
    if ($l['time_from']) {
        $time_str = substr((string)$l['time_from'], 0, 5) . '-' . substr((string)$l['time_to'], 0, 5);
    } elseif ((int)$l['duration_min']) {
        $time_str = (int)$l['duration_min'] . ' min';
    }

    $topic = mb_strimwidth(trim((string)($l['topic'] ?? '')), 0, 80, '...');
    if ($is_cancelled) $topic = '[odwolana] ' . $topic;

    $pdf->Cell($COL[0], $ROW_H, _lp($date),                           1, 0, 'L', true);
    $pdf->Cell($COL[1], $ROW_H, _lp(mb_strimwidth($l['course_name'], 0, 30, '...')), 1, 0, 'L', true);
    $pdf->Cell($COL[2], $ROW_H, _lp($time_str),                       1, 0, 'C', true);
    $pdf->Cell($COL[3], $ROW_H, _lp($topic),                          1, 1, 'L', true);

    $pdf->SetTextColor(0, 0, 0);
    $fill = !$fill;
}

// Podsumowanie
$pdf->Ln(3);
$pdf->SetFont('DejaVu', '', 7);
$pdf->SetTextColor(100, 100, 100);
$total  = count($lessons);
$att    = count(array_filter($lessons, fn($l) => (int)($l['attended'] ?? 0)));
$canc   = count(array_filter($lessons, fn($l) => ($l['status'] ?? '') === 'cancelled' || !empty($l['att_cancelled'])));
$future = count(array_filter($lessons, fn($l) => ($l['lesson_date'] ?? '') >= $today));
$pdf->Cell($PW, 5, _lp("Razem: $total lekcji  |  Obecnosci: $att  |  Odwolane: $canc  |  Nadchodzace: $future"), 0, 1, 'C');

// Legenda
$pdf->SetFont('DejaVu', '', 6.5);
$pdf->Cell($PW, 4, _lp('Kolor wiersza: zielony = obecny, rozowy = nieobecny, szary = odwolana, biale = nadchodzace'), 0, 1, 'C');

$fname = 'zajecia_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '_' . date('Ymd') . '.pdf';
$pdf->Output('D', $fname);
exit;
