<?php
/**
 * crm/api/outlook_sync.php — REST endpoint synchronizacji Outlook → CRM
 *
 * POST /crm/api/outlook_sync.php
 *   Wymaga: nagłówek X-Requested-With: XMLHttpRequest + zalogowany użytkownik (admin)
 *   lub token API w nagłówku Authorization: Bearer <crm_sync_token>
 *
 * Body (JSON lub form):
 *   action  = "sync_all" | "sync_contacts" | "sync_calendar" | "reset_delta"
 *             | "get_calendars" | "get_status"
 *   calendar_id = "" (opcjonalne, dla sync_calendar)
 *
 * Odpowiedź: JSON {ok, message, data}
 */

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once BASE_PATH . '/includes/crm.php';
require_once BASE_PATH . '/includes/m365.php';
require_once BASE_PATH . '/includes/outlook_sync.php';

header('Content-Type: application/json; charset=utf-8');

// ── Autoryzacja ────────────────────────────────────────────────────────────────

$auth_ok = false;

// 1. Sesja CRM (admin)
if (is_logged_in() && is_admin()) {
    $auth_ok = true;
}

// 2. Token API (dla crona / CLI)
if (!$auth_ok) {
    $bearer = '';
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($hdr && str_starts_with($hdr, 'Bearer ')) {
        $bearer = substr($hdr, 7);
    }
    if (!$bearer) {
        $bearer = $_GET['token'] ?? $_POST['token'] ?? '';
    }
    $sync_token = crm_setting('crm_sync_token');
    if ($bearer && $sync_token && hash_equals($sync_token, $bearer)) {
        $auth_ok = true;
    }
}

if (!$auth_ok) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Brak dostępu.']);
    exit;
}

// ── Tylko POST ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Wymagana metoda POST.']);
    exit;
}

// ── Odczyt parametrów ─────────────────────────────────────────────────────────

$raw = file_get_contents('php://input');
$input = [];
if ($raw && ($json = json_decode($raw, true)) !== null) {
    $input = $json;
} else {
    $input = $_POST;
}

$action      = trim($input['action']      ?? 'sync_all');
$calendar_id = trim($input['calendar_id'] ?? '');

// ── Wykonanie ─────────────────────────────────────────────────────────────────

try {
    $sync = new OutlookSync();

    switch ($action) {
        case 'sync_contacts':
            crm_migrate(); // upewnij się że tabele istnieją
            $data = $sync->sync_contacts();
            echo json_encode([
                'ok'      => empty($data['errors']),
                'message' => sprintf(
                    'Kontakty: +%d nowych, ~%d zaktualizowanych, -%d usuniętych',
                    $data['created'], $data['updated'], $data['removed']
                ),
                'data' => $data,
            ]);
            break;

        case 'sync_calendar':
            crm_migrate();
            $data = $sync->sync_calendar($calendar_id);
            echo json_encode([
                'ok'      => empty($data['errors']),
                'message' => sprintf(
                    'Kalendarz: +%d nowych, ~%d zaktualizowanych, -%d usuniętych',
                    $data['created'], $data['updated'], $data['removed']
                ),
                'data' => $data,
            ]);
            break;

        case 'sync_all':
        default:
            crm_migrate();
            $data = $sync->run_all();
            echo json_encode([
                'ok'      => $data['ok'],
                'message' => $data['message'],
                'data'    => $data,
            ]);
            break;

        case 'reset_delta':
            $key = trim($input['delta_key'] ?? '');
            $sync->reset_delta($key ?: '');
            echo json_encode([
                'ok'      => true,
                'message' => 'Delta-link zresetowany. Następna synchronizacja pobierze wszystkie dane.',
            ]);
            break;

        case 'get_calendars':
            $calendars = $sync->get_available_calendars();
            echo json_encode([
                'ok'   => true,
                'data' => $calendars,
            ]);
            break;

        case 'get_status':
            $logs = $sync->recent_logs(20);
            echo json_encode([
                'ok'   => true,
                'data' => ['logs' => $logs],
            ]);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => 'Błąd synchronizacji: ' . $e->getMessage(),
        'data'    => [],
    ]);
}
