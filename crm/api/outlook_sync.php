<?php
/**
 * crm/api/outlook_sync.php — REST endpoint synchronizacji Outlook → CRM
 *
 * POST /crm/api/outlook_sync.php
 *   Wymaga: nagłówek X-Requested-With: XMLHttpRequest + zalogowany użytkownik (admin)
 *   lub token API w nagłówku Authorization: Bearer <crm_sync_token>
 *
 * Body (JSON lub form):
 *   action  = "sync_all" | "sync_contacts" | "sync_calendar" | "sync_messages"
 *             | "reset_delta" | "get_calendars" | "get_status"
 *   calendar_id = "" (opcjonalne, dla sync_calendar)
 *
 * Odpowiedź: JSON {ok, message, data}
 */

$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/crm.php';
require_once $_root . '/includes/m365.php';
require_once $_root . '/includes/outlook_sync.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

// ── Autoryzacja ────────────────────────────────────────────────────────────────

$auth_ok = false;

// 1. Sesja CRM (admin)
if (current_user() && is_admin()) {
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

        case 'sync_messages':
            crm_migrate();
            $data = $sync->sync_messages();
            echo json_encode([
                'ok'      => empty($data['errors']),
                'message' => sprintf(
                    'Maile: +%d zapisanych, %d dopasowanych, %d pominiętych',
                    $data['created'], $data['matched'], $data['skipped']
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

        case 'sync_user_calendar':
            // Sync kalendarza zalogowanego użytkownika CRM (wymaga sesji)
            if (!current_user()) {
                http_response_code(403);
                echo json_encode(['ok'=>false,'message'=>'Wymagane logowanie.']);
                break;
            }
            crm_migrate();
            $cu_id = (int)current_user()['id'];
            $data  = $sync->sync_user_calendar($cu_id);
            echo json_encode([
                'ok'      => empty($data['errors']) && !$data['skipped'],
                'message' => $data['skipped']
                    ? 'Pominięto (brak konta M365 lub synchronizacja wyłączona).'
                    : sprintf('+%d nowych, ~%d aktualizacji, -%d usuniętych',
                        $data['created'], $data['updated'], $data['removed']),
                'data' => $data,
            ]);
            break;

        case 'get_calendars':
            // Dla per-user: pobierz kalendarze zalogowanego użytkownika
            $cu = current_user();
            $ms_id = trim($cu['microsoft_id'] ?? '');
            if ($ms_id) {
                // Użyj microsoft_id zalogowanego usera (jeśli nie jest adminem z org-wide sync)
                $per_user_sync = new OutlookSync($ms_id);
                $calendars = $per_user_sync->get_available_calendars();
            } else {
                $calendars = $sync->get_available_calendars();
            }
            echo json_encode(['ok' => true, 'data' => $calendars]);
            break;
        case 'get_calendars_raw': // legacy/admin — org-wide user
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
