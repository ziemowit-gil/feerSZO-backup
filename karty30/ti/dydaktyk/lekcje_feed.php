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
if (!$course_id || !dyd_owns_course($uid, $course_id)) {
    http_response_code(403);
    echo '[]';
    exit;
}

$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end']   ?? '');
$rows  = k30_ti_sessions($course_id, $start, $end);

$out = [];
foreach ($rows as $s) {
    $st    = K30_TI_SESSION_STATUSES[$s['status']] ?? ['label' => $s['status'], 'color' => '#666', 'bg' => '#eee'];
    $title = ($s['time_from'] ? substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5) . ' · ' : '') . $st['label'];
    $out[] = [
        'id'              => (int)$s['id'],
        'title'           => $title,
        'start'           => $s['lesson_date'] . ($s['time_from'] ? 'T' . $s['time_from'] : ''),
        'end'             => $s['time_to'] ? $s['lesson_date'] . 'T' . $s['time_to'] : null,
        'allDay'          => $s['time_from'] === '' || $s['time_from'] === null,
        'backgroundColor' => $st['bg'],
        'borderColor'     => $st['color'],
        'textColor'       => $st['color'],
    ];
}
echo json_encode($out);
