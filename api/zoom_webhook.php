<?php
/**
 * api/zoom_webhook.php — Odbiorca zdarzeń Zoom (webhook).
 *
 * Konfiguracja w Zoom Marketplace → Event Subscriptions → Add Endpoint:
 *   URL:    https://twoja-domena/api/zoom_webhook.php
 *   Events: endpoint.url_validation, meeting.ended (i inne wg potrzeb)
 *   Secret Token → Ustawienia → TI → Zoom → "Webhook secret"
 *   (klucz w settings: zoom_webhook_secret)
 *
 * Zoom wymaga jednorazowej weryfikacji URL przez zdarzenie endpoint.url_validation
 * — obsługiwana automatycznie poniżej, bez żadnej akcji ze strony admina.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/zoom.php';

karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw    = file_get_contents('php://input');
$secret = zoom_setting('webhook_secret');

// ── Weryfikacja podpisu HMAC-SHA256 ──────────────────────────────────────────
$timestamp = $_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] ?? '';
$sig       = $_SERVER['HTTP_X_ZM_SIGNATURE']          ?? '';

if ($secret !== '') {
    if ($timestamp === '' || $sig === '') {
        http_response_code(401);
        exit('Missing signature headers');
    }
    $expected = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$raw}", $secret);
    if (!hash_equals($expected, $sig)) {
        http_response_code(401);
        exit('Invalid signature');
    }
    // Ochrona przed replay: okno 5 minut
    if (abs(time() - (int)$timestamp) > 300) {
        http_response_code(400);
        exit('Request expired');
    }
}

$event = json_decode($raw, true) ?: [];
$type  = $event['event'] ?? '';

// ── Walidacja URL (wymagana przez Zoom przy rejestracji endpointu) ─────────────
if ($type === 'endpoint.url_validation') {
    if ($secret === '') {
        http_response_code(500);
        echo json_encode(['error' => 'zoom_webhook_secret not configured in settings']);
        exit;
    }
    $plainToken     = $event['payload']['plainToken'] ?? '';
    $encryptedToken = hash_hmac('sha256', $plainToken, $secret);
    header('Content-Type: application/json');
    echo json_encode(['plainToken' => $plainToken, 'encryptedToken' => $encryptedToken]);
    exit;
}

// ── Zdarzenia spotkań ─────────────────────────────────────────────────────────

if ($type === 'meeting.ended') {
    $obj       = $event['payload']['object'] ?? [];
    $meetingId = (string)($obj['id'] ?? '');
    if ($meetingId !== '') {
        try {
            db()->prepare(
                "INSERT INTO k30_ti_zoom_log (action, meeting_id, detail, status)
                 VALUES ('meeting.ended', ?, ?, 'ok')"
            )->execute([$meetingId, json_encode(['topic' => $obj['topic'] ?? '', 'duration' => $obj['duration'] ?? 0])]);
        } catch (\Throwable $e) {}
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
