<?php
/**
 * events/public/feed.php — Publiczny feed JSON wydarzeń (bez logowania)
 * Zwraca tylko wydarzenia status=published i is_public=1, przez ev_public_event()
 * (bez meeting_url ani danych uczestników).
 *
 * Parametry GET:
 *   type   — filtr po typie (webinar|stationary)
 *   when   — upcoming | past | all (domyślnie all)
 */
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/events.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (org_setting('events_enabled') === '0') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Moduł wydarzeń jest wyłączony.']);
    exit;
}

$type = trim($_GET['type'] ?? '');
$when = trim($_GET['when'] ?? 'all');

$where  = ["status='published'", "is_public=1"];
$params = [];

if (in_array($type, ['webinar', 'stationary'], true)) {
    $where[] = 'type=?';
    $params[] = $type;
}
if ($when === 'upcoming') {
    $where[] = "start_at >= datetime('now','localtime')";
} elseif ($when === 'past') {
    $where[] = "start_at < datetime('now','localtime')";
}

$events = db_all(
    "SELECT * FROM ev_events WHERE " . implode(' AND ', $where) . " ORDER BY start_at ASC",
    $params
);

echo json_encode([
    'ok'         => true,
    'fetched_at' => date('c'),
    'events'     => array_map('ev_public_event', $events),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
