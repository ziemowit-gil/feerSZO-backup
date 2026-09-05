<?php
/**
 * karty30/ti/dydaktyk/lekcje_feed.php — JSON feed lekcji kursu dla kalendarza
 * (FullCalendar) w zakładce Lekcje. Tylko odczyt, GET, bez CSRF.
 */
require_once __DIR__ . '/auth.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];

header('Content-Type: application/json; charset=utf-8');

$course_id = (int)($_GET['course'] ?? 0);

// Kierownik/staff widzi kalendarz WSZYSTKICH grup naraz (jak resztę pulpitu —
// dyd_courses() już rozróżnia rolę: staff => k30_ti_courses(false), zwykły
// prowadzący => tylko jego kursy). Zwykły prowadzący widzi tylko swój kurs.
if (dyd_is_staff()) {
    $course_ids = array_map(fn($c) => (int)$c['id'], dyd_courses($uid));
} else {
    if (!$course_id || !dyd_owns_course($uid, $course_id)) {
        http_response_code(403);
        echo '[]';
        exit;
    }
    $course_ids = [$course_id];
}
if (!$course_ids) { echo '[]'; exit; }

$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end']   ?? '');

$course_names = [];
if (count($course_ids) > 1) {
    $ph = implode(',', array_fill(0, count($course_ids), '?'));
    foreach (db_all("SELECT id, name FROM k30_ti_courses WHERE id IN ($ph)", $course_ids) as $c) {
        $course_names[(int)$c['id']] = (string)$c['name'];
    }
}

$out = [];
foreach ($course_ids as $cid) {
    $rows = k30_ti_sessions($cid, $start, $end);
    foreach ($rows as $s) {
        $st    = K30_TI_SESSION_STATUSES[$s['status']] ?? ['label' => $s['status'], 'color' => '#666', 'bg' => '#eee'];
        $title = ($s['time_from'] ? substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5) . ' · ' : '') . $st['label'];
        if (count($course_ids) > 1) {
            $title .= ' · ' . ($course_names[$cid] ?? '');
        }
        $out[] = [
            'id'              => (int)$s['id'],
            'title'           => $title,
            'start'           => $s['lesson_date'] . ($s['time_from'] ? 'T' . $s['time_from'] : ''),
            'end'             => $s['time_to'] ? $s['lesson_date'] . 'T' . $s['time_to'] : null,
            'allDay'          => $s['time_from'] === '' || $s['time_from'] === null,
            'backgroundColor' => $st['bg'],
            'borderColor'     => $st['color'],
            'textColor'       => $st['color'],
            'extendedProps'   => ['courseId' => $cid],
        ];
    }
}
echo json_encode($out);
