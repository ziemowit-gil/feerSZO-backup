<?php
/**
 * karty30/ti/dydaktyk/attendance_csv.php — Eksport CSV frekwencji.
 * GET: ?month=YYYY-MM (wymagany), ?course_id=N (opcjonalny — jeden kurs)
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

$my_courses = dyd_courses($uid);
$course_ids = array_map(fn($c) => (int)$c['id'], $my_courses);

$cid_filter = (int)($_GET['course_id'] ?? 0);
if ($cid_filter) {
    if (!in_array($cid_filter, $course_ids, true)) {
        http_response_code(403); exit('Brak uprawnień do tego kursu.');
    }
    $course_ids = [$cid_filter];
}
if (!$course_ids) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Brak kursów.');
}

// Zbierz sesje i kursantów per kurs
$ph = implode(',', array_fill(0, count($course_ids), '?'));
$sessions = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, c.id AS course_id, c.name AS course_name
     FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
     WHERE s.course_id IN ($ph)
       AND s.status IN ('held','individual_change')
       AND COALESCE(c.track_attendance,1)=1
       AND strftime('%Y-%m', s.lesson_date)=?
     ORDER BY c.name COLLATE NOCASE, s.lesson_date, s.time_from",
    array_merge($course_ids, [$month])
);

// Grupuj sesje per kurs
$sess_by_course = [];
foreach ($sessions as $s) $sess_by_course[$s['course_id']][] = $s;

// Kursanci i frekwencja
$att_all = db_all(
    "SELECT a.client_id, a.session_id, a.attended, a.cancelled, a.no_show
     FROM k30_ti_attendance a
     JOIN k30_ti_sessions s ON s.id=a.session_id
     WHERE s.course_id IN ($ph)
       AND s.status IN ('held','individual_change')
       AND strftime('%Y-%m', s.lesson_date)=?",
    array_merge($course_ids, [$month])
);
$att_map = [];
foreach ($att_all as $a) $att_map[$a['session_id']][$a['client_id']] = $a;

$students_by_course = [];
foreach ($course_ids as $cid) {
    $students_by_course[$cid] = db_all(
        "SELECT cl.id, cl.name FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active'
         ORDER BY cl.name COLLATE NOCASE",
        [(int)$cid]
    );
}

// Nagłówek CSV
while (ob_get_level() > 0) ob_end_clean();
$fname = 'frekwencja_' . $month . ($cid_filter ? '_kurs' . $cid_filter : '') . '.csv';
ti_print_log_add('attendance_csv', 'Eksport CSV frekwencji — ' . $month, $cid_filter, 0, [], $me);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache, no-store');

// BOM UTF-8 dla Excela
echo "\xEF\xBB\xBF";

$f = fopen('php://output', 'w');

foreach ($course_ids as $cid) {
    $course_sessions = $sess_by_course[$cid] ?? [];
    $students        = $students_by_course[$cid] ?? [];
    if (!$course_sessions || !$students) continue;

    $cname = '';
    foreach ($my_courses as $mc) { if ((int)$mc['id'] === $cid) { $cname = $mc['name']; break; } }

    // Nagłówek kursu
    fputcsv($f, ['Kurs: ' . $cname, 'Miesiąc: ' . $month], ",", "\"", "");

    // Nagłówek kolumn: Kursant | Data1 | Data2 | ... | Razem | %
    $header = ['Kursant'];
    foreach ($course_sessions as $s) {
        $label = date('d.m', strtotime($s['lesson_date']));
        if ($s['time_from']) $label .= ' ' . substr((string)$s['time_from'], 0, 5);
        $header[] = $label;
    }
    $header[] = 'Obecności';
    $header[] = 'Lekcji';
    $header[] = 'Frekwencja %';
    fputcsv($f, $header, ",", "\"", "");

    // Wiersze — kursanci
    foreach ($students as $st) {
        $row = [$st['name']];
        $present = 0; $total = 0;
        foreach ($course_sessions as $s) {
            $a = $att_map[$s['id']][$st['id']] ?? null;
            $total++;
            if ($a === null) {
                $row[] = '?';
            } elseif ((int)$a['cancelled']) {
                $row[] = 'odw.';
            } elseif ((int)$a['attended']) {
                $present++;
                $row[] = '1';
            } else {
                $row[] = '0';
            }
        }
        $row[] = $present;
        $row[] = $total;
        $row[] = $total > 0 ? round($present / $total * 100) . '%' : '—';
        fputcsv($f, $row, ",", "\"", "");
    }

    fputcsv($f, [], ",", "\"", ""); // pusta linia między kursami
}

fclose($f);
exit;
