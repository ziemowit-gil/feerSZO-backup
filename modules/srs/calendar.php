<?php
/**
 * modules/srs/calendar.php — Kanał zdarzeń SRS dla FullCalendar (JSON).
 *
 * GET: start, end (ISO, dodawane automatycznie przez FullCalendar dla
 * widocznego zakresu) + opcjonalne filtry: cat (kategoria), resource
 * (konkretny zasób), status[] (lista statusów rezerwacji — domyślnie
 * wszystkie oprócz odmowa/anulowana).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/srs/logic/srs.php';

require_login();
resources_migrate();
header('Content-Type: application/json; charset=utf-8');

$start = substr((string)($_GET['start'] ?? ''), 0, 10) ?: date('Y-m-d');
$end   = substr((string)($_GET['end']   ?? ''), 0, 10) ?: date('Y-m-d', strtotime('+7 days'));
$cat   = (int)($_GET['cat'] ?? 0);
$res_id = (int)($_GET['resource'] ?? 0);

$statuses = array_filter((array)($_GET['status'] ?? []), fn($s) => isset(RES_STATUSES[$s]));
if (!$statuses) $statuses = array_diff(array_keys(RES_STATUSES), ['odmowa', 'anulowana']);

$where  = ['rr.date_from <= ?', 'rr.date_to >= ?'];
$params = [$end, $start];

$in_status = implode(',', array_fill(0, count($statuses), '?'));
$where[] = "rr.status IN ($in_status)";
array_push($params, ...array_values($statuses));

if ($cat)    { $where[] = 'r.category_id=?'; $params[] = $cat; }
if ($res_id) { $where[] = 'rr.resource_id=?'; $params[] = $res_id; }

$rows = db_all(
    "SELECT rr.id, rr.resource_id, rr.date_from, rr.date_to, rr.time_from, rr.time_to,
            rr.status, rr.purpose, r.name AS res_name, u.name AS user_name
     FROM resource_reservations rr
     JOIN resources r ON r.id = rr.resource_id
     JOIN users u ON u.id = rr.user_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY rr.date_from, rr.time_from",
    $params
);

$events = [];
foreach ($rows as $r) {
    $st = RES_STATUSES[$r['status']] ?? ['label' => $r['status'], 'color' => '#6b7280'];
    $has_time = $r['time_from'] !== '' && $r['time_to'] !== '';

    $events[] = [
        'id'    => (int)$r['id'],
        'title' => $r['res_name'] . ($r['purpose'] !== '' ? ' — ' . $r['purpose'] : ''),
        'start' => $has_time ? ($r['date_from'] . 'T' . $r['time_from']) : $r['date_from'],
        'end'   => $has_time ? ($r['date_to'] . 'T' . $r['time_to'])
                              : date('Y-m-d', strtotime($r['date_to'] . ' +1 day')),
        'allDay' => !$has_time,
        'color'  => $st['color'],
        'url'    => APP_URL . '/modules/srs/view.php?id=' . (int)$r['id'],
        'extendedProps' => [
            'resource' => $r['res_name'],
            'user'     => $r['user_name'],
            'status'   => $st['label'],
        ],
    ];
}

echo json_encode($events);
