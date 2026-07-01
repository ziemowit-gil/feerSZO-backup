<?php
/**
 * events/public/embed_feed.php — Publiczny feed JSON wydarzeń (do osadzania na zewnętrznych stronach).
 * Bez autoryzacji, CORS: *. Zwraca WYŁĄCZNIE wydarzenia status='published' i is_public=1
 * — nigdy dane uczestników, nigdy meeting_url (dostępny dopiero po rejestracji).
 *
 * Parametry: limit (1-20, domyślnie 6), ids ("slug1,slug2" — zastępuje filtr czasu),
 *            type (webinar|stationary), upcoming (0|1, domyślnie 1).
 */
declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/events.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

if (org_setting('events_enabled') === '0') {
    http_response_code(404);
    echo json_encode(['error' => 'Moduł wydarzeń jest wyłączony.']);
    exit;
}

$limit    = min(20, max(1, (int)($_GET['limit'] ?? 6)));
$ids_raw  = trim((string)($_GET['ids'] ?? ''));
$type     = trim((string)($_GET['type'] ?? ''));
$upcoming = ($_GET['upcoming'] ?? '1') !== '0';

$where  = ["status = 'published'", 'is_public = 1'];
$params = [];

if ($ids_raw !== '') {
    $slugs = array_values(array_filter(array_map('trim', explode(',', $ids_raw))));
    if ($slugs) {
        $where[]  = 'slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')';
        array_push($params, ...$slugs);
    }
    $upcoming = false; // wybór konkretnych wydarzeń ignoruje filtr czasu
}
if ($type === 'webinar' || $type === 'stationary') {
    $where[] = 'type = ?';
    $params[] = $type;
}
if ($upcoming) {
    $where[] = "start_at >= datetime('now','localtime')";
}

$sql  = "SELECT * FROM ev_events WHERE " . implode(' AND ', $where) . " ORDER BY start_at ASC LIMIT ?";
$rows = db_all($sql, array_merge($params, [$limit]));

header('Cache-Control: public, max-age=120');
echo json_encode(['data' => array_map('ev_public_event', $rows)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
