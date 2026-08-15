<?php
/**
 * karty30/ti/dydaktyk/self_work.php — Raport „Praca własna prowadzącego" (PDF)
 * dostępny z panelu prowadzącego. Zakres: kursy zalogowanego prowadzącego
 * (pracownik D3 — wszystkie). Lekcje o statusie remote_material w miesiącu.
 * GET: ?month=YYYY-MM (domyślnie bieżący), ?course_id=N (opcjonalnie — jeden kurs).
 */
require_once __DIR__ . '/auth.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

// Zakres kursów prowadzącego
$my_courses = dyd_courses($uid);
$course_ids = array_map(fn($c) => (int)$c['id'], $my_courses);

$course_id_filter = (int)($_GET['course_id'] ?? 0);
if ($course_id_filter) {
    if (!in_array($course_id_filter, $course_ids, true)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }
    $course_ids = [$course_id_filter];
}
if (!$course_ids) { http_response_code(200); header('Content-Type: text/plain; charset=UTF-8'); exit('Brak kursów.'); }

$ph = implode(',', array_fill(0, count($course_ids), '?'));
$rows = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.duration_min, s.topic,
            c.id AS course_id, c.name AS course_name, c.lesson_payout_bb
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     WHERE s.course_id IN ($ph) AND s.status='remote_material'
       AND strftime('%Y-%m', s.lesson_date)=?
     ORDER BY c.name COLLATE NOCASE, s.lesson_date, s.time_from",
    array_merge($course_ids, [$month])
);

$_pf = db_one("SELECT COALESCE(ti_payout_form, CASE WHEN COALESCE(ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS pf FROM users WHERE id=?", [$uid]);
$_is_student = in_array($_pf['pf'] ?? 'zlecenie', ['student','b2b'], true);

// Grupowanie po kursie + sumy
$groups = []; $tot_count = 0; $tot_min = 0; $tot_net = 0.0;
foreach ($rows as $r) {
    $net = ((float)$r['lesson_payout_bb'] > 0) ? (float)k30_ti_payout_breakdown((float)$r['lesson_payout_bb'], $_is_student)['netto'] : 0.0;
    $r['_net'] = $net;
    $k = $r['course_name'];
    $groups[$k]['rows'][]  = $r;
    $groups[$k]['count']   = ($groups[$k]['count'] ?? 0) + 1;
    $groups[$k]['min']     = ($groups[$k]['min'] ?? 0) + (int)$r['duration_min'];
    $groups[$k]['net']     = ($groups[$k]['net'] ?? 0) + $net;
    $tot_count++; $tot_min += (int)$r['duration_min']; $tot_net += $net;
}

$_msc = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$ym_label = ($_msc[(int)substr($month,5,2)] ?? '') . ' ' . substr($month,0,4);
$who = trim((string)(db_one("SELECT COALESCE(NULLIF(TRIM(COALESCE(first_name,'')||' '||COALESCE(last_name,'')),''), name, '') AS n FROM users WHERE id=?", [$uid])['n'] ?? ''));
$f  = fn($x) => number_format((float)$x, 2, ',', ' ');
$hh = fn($m) => number_format($m / 60, 2, ',', ' ');

// ── PDF ───────────────────────────────────────────────────────────────────────
require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
$FD = dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/font/';
$pl = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(12, 12, 12);
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $FD);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $FD);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    $pdf->SetFillColor(8, 145, 178); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 9, $pl('Praca własna prowadzącego — ' . ucfirst($ym_label)), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('DejaVu', '', 8);
    $pdf->Cell($W, 5, $pl(($who ? $who . '   ·   ' : '') . ($org ? $org . '   ·   ' : '')
        . 'Lekcji: ' . $tot_count . ' · ' . $hh($tot_min) . ' h · netto ' . $f($tot_net) . ' zł'
        . '   ·   ' . date('d.m.Y H:i')), 0, 1);
    $pdf->Ln(2);

    if (!$rows) {
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell($W, 8, $pl('Brak lekcji „praca własna" w tym miesiącu.'), 0, 1);
    }
    foreach ($groups as $cname => $g) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
        $pdf->SetFillColor(233, 245, 248); $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell($W, 7, $pl($cname . '   (' . (int)$g['count'] . ' lekcji · ' . $hh($g['min']) . ' h'
            . ($g['net'] > 0 ? ' · netto ' . $f($g['net']) . ' zł' : '') . ')'), 0, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 8.5);
        foreach ($g['rows'] as $r) {
            $d = new DateTime($r['lesson_date']);
            $line = $d->format('d.m.Y') . '  ' . substr((string)$r['time_from'], 0, 5)
                  . '  ·  ' . (int)$r['duration_min'] . ' min'
                  . ($r['topic'] ? '  — ' . $r['topic'] : '')
                  . ($r['_net'] > 0 ? '   (' . $f($r['_net']) . ' zł)' : '');
            $pdf->Cell(4); $pdf->MultiCell($W - 4, 5, $pl($line), 0, 'L');
        }
        $pdf->Ln(2);
    }

    while (ob_get_level() > 0) ob_end_clean();
    $pdf->Output('D', 'praca_wlasna_' . $month . '.pdf');
    exit;
} catch (\Throwable $e) {
    error_log('[dyd self_work] ' . $month . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować PDF.\nPowód: " . $e->getMessage() . "\n"; exit;
}
