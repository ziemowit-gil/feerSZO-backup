<?php
/**
 * Webhook Azure AD Change Notification.
 *
 * Azure AD wysyła powiadomienia o zmianach kont do tego endpointu.
 *
 * Obsługiwane żądania:
 *   GET  ?validationToken=X   → odesłanie tokenu (walidacja subskrypcji Azure)
 *   POST (JSON body)          → parsowanie powiadomień, kolejkowanie re-sync
 *   GET  ?setup=1             → (admin) informacje o konfiguracji webhooka w Azure
 *
 * Tabela kolejki: m365_sync_queue (tworzona automatycznie przy pierwszym wywołaniu)
 */

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';

// ── Tworzenie tabeli kolejki (migracja jednorazowa) ──────────────────────────
_ensure_sync_queue_table();

// ── Walidacja subskrypcji Azure AD (GET ?validationToken=...) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['validationToken'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $_GET['validationToken'];
    exit;
}

// ── Panel konfiguracyjny (GET ?setup=1) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['setup'])) {
    // Prosta autoryzacja — nagłówek Authorization: Bearer <ADMIN_TOKEN>
    $admin_token = defined('APP_KEY') ? APP_KEY : '';
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $provided    = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
        $provided = trim($m[1]);
    }

    if (empty($admin_token) || $provided !== $admin_token) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized. Provide Authorization: Bearer <APP_KEY>']);
        exit;
    }

    $webhook_url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/m365_webhook.php';
    $queue_count = (int)(db_one("SELECT COUNT(*) AS c FROM m365_sync_queue WHERE processed_at IS NULL")['c'] ?? 0);

    header('Content-Type: application/json');
    echo json_encode([
        'webhook_url'         => $webhook_url,
        'instructions'        => [
            '1. W Azure AD → Applications → wybierz swoją aplikację → "Certificates & secrets"',
            '2. Utwórz subskrypcję w Graph Explorer lub przez API: POST /subscriptions',
            '3. changeType: "updated,deleted"',
            '4. resource: "users"',
            '5. notificationUrl: ' . $webhook_url,
            '6. clientState: dowolny sekret — zapisz go do settings (m365_webhook_secret)',
        ],
        'queue_unprocessed'   => $queue_count,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Obsługa powiadomień (POST) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($body['value'])) {
    // Azure może wysłać puste lub nieprawidłowe body przy retransmisji
    http_response_code(202);
    exit;
}

$queued = 0;
foreach ($body['value'] as $notification) {
    // Wyciągnij Azure AD object ID użytkownika
    $az_user_id = null;

    // Scenariusz 1: resourceData.id (standardowe powiadomienie delta)
    if (!empty($notification['resourceData']['id'])) {
        $az_user_id = $notification['resourceData']['id'];
    }

    // Scenariusz 2: resource = "users/{id}" lub "users('{id}')"
    if (empty($az_user_id) && !empty($notification['resource'])) {
        if (preg_match("/users[\/\(']([0-9a-f\-]{36})/i", $notification['resource'], $rm)) {
            $az_user_id = $rm[1];
        }
    }

    if (empty($az_user_id)) {
        continue;
    }

    // Opcjonalna weryfikacja clientState (jeśli skonfigurowany)
    $expected_secret = db_one("SELECT value FROM settings WHERE key_ = 'm365_webhook_secret'")['value'] ?? '';
    if (!empty($expected_secret) && ($notification['clientState'] ?? '') !== $expected_secret) {
        // Niezgodny clientState — pomijamy to powiadomienie
        continue;
    }

    // Dodaj do kolejki (ignoruj duplikaty nieprzetworzone)
    try {
        $existing = db_one(
            "SELECT id FROM m365_sync_queue WHERE user_id = ? AND processed_at IS NULL",
            [$az_user_id]
        );
        if (!$existing) {
            db()->prepare(
                "INSERT INTO m365_sync_queue (user_id, queued_at, processed_at) VALUES (?, ?, NULL)"
            )->execute([$az_user_id, date('Y-m-d H:i:s')]);
            $queued++;
        }
    } catch (\Throwable $e) {
        // Nie przerywamy — Azure wymaga 202 lub ponowi żądanie
    }
}

// Azure wymaga 202 Accepted (nie 200) dla powiadomień
http_response_code(202);
header('Content-Type: application/json');
echo json_encode(['queued' => $queued]);

// ── Migracja tabeli ──────────────────────────────────────────────────────────

function _ensure_sync_queue_table(): void {
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS m365_sync_queue (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      TEXT    NOT NULL,
                queued_at    TEXT    NOT NULL,
                processed_at TEXT    NULL
            )
        ");
        // Indeks dla szybkiego wyszukiwania nieprzetworzone + user_id
        db()->exec("CREATE INDEX IF NOT EXISTS idx_m365q_uid_proc ON m365_sync_queue (user_id, processed_at)");
    } catch (\Throwable $e) {
        // Tabela już istnieje — ignorujemy
    }
}
