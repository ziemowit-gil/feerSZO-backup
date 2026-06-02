<?php
/**
 * crm/api/heartbeat.php — SyncService: delta-sync endpoint.
 *
 * POST:
 *   token — klucz synchronizacji (z settings.crm_sync_token)
 *   since — Unix timestamp ostatniej synchronizacji (0 = wszystkie)
 *
 * Response: JSON
 *   ok         bool
 *   count      int     — liczba zmienionych rekordów
 *   total      int     — łączna liczba aktywnych kontaktów
 *   contacts   array   — zmienione kontakty (delta)
 *   server_ts  int     — aktualny czas serwera (Unix)
 *
 * Zabezpieczenie: secret token (nie wymaga sesji PHP — może być wywoływany
 * przez zewnętrzne systemy lub JS heartbeat zalogowanego użytkownika).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

// Tylko POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Weryfikacja tokenu
$token = trim($_POST['token'] ?? '');
if (!SyncService::verifyToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

// Alternatywnie: zalogowany użytkownik może wywołać bez tokenu (heartbeat JS)
// jeśli sesja jest aktywna — sprawdź jedną z dwóch metod autoryzacji
$authed_by_session = false;
try {
    $u = current_user();
    if ($u && in_array($u['role'], ['admin', 'editor', 'viewer'], true)) {
        $authed_by_session = true;
    }
} catch (\Throwable $e) {}

$since = max(0, (int)($_POST['since'] ?? 0));
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $delta = SyncService::getDelta($since);

    // Łączna liczba aktywnych kontaktów
    $total = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1")['c'] ?? 0);

    // Loguj tylko co 5. wywołanie (żeby nie zaśmiecać logu przy 50s heartbeat)
    if ($delta['count'] > 0 || (int)(microtime(true) * 10) % 5 === 0) {
        SyncService::logSync($delta['count'], 'heartbeat', $ip);
    }

    echo json_encode([
        'ok'        => true,
        'count'     => $delta['count'],
        'total'     => $total,
        'contacts'  => $delta['contacts'],
        'server_ts' => $delta['server_ts'],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
