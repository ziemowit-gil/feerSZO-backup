<?php
/**
 * api/ajax.php — Ogólny endpoint AJAX dla szybkich akcji in-page
 *
 * POST: action, id, type, value, _csrf
 * Response: {ok: bool, msg: string, data: object}
 *
 * Obsługiwane akcje:
 *   set_status         — zmiana statusu umowy
 *   set_favorite       — toggle ulubione/przypięte
 *   read_message       — oznacz wątek jako przeczytany
 *   dismiss_notification — oznacz powiadomienie jako przeczytane
 */
define('SKIP_CONSENT_CHECK', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/messages.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

csrf_check();

$action = trim($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
$type   = trim($_POST['type'] ?? '');
$value  = trim($_POST['value'] ?? '');

// ── Helpers ───────────────────────────────────────────────────────────────────
function ajax_ok(array $data = [], string $msg = ''): void {
    echo json_encode(['ok' => true, 'msg' => $msg] + $data);
    exit;
}

function ajax_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

// ── Routing ───────────────────────────────────────────────────────────────────
switch ($action) {

    // ── set_status ────────────────────────────────────────────────────────────
    case 'set_status': {
        if (!can_edit()) ajax_err('Brak uprawnień', 403);
        if (!$id)        ajax_err('Brak id');
        if (!$type)      ajax_err('Brak type');
        if (!$value)     ajax_err('Brak value');

        if (!isset(STATUS_LABELS[$value])) {
            ajax_err('Nieznany status: ' . $value);
        }

        $table = 'umowy_' . $type;
        if (!isset(CONTRACT_TYPES[$type])) {
            ajax_err('Nieznany typ umowy: ' . $type);
        }

        try {
            $row = db_one("SELECT status FROM {$table} WHERE id=?", [$id]);
        } catch (\Throwable $e) {
            ajax_err('Nie znaleziono umowy');
        }

        if (!$row) ajax_err('Nie znaleziono umowy');

        $old_status = $row['status'];
        if ($old_status === $value) {
            // Nic do zmiany — zwróć aktualny stan
            $st = STATUS_LABELS[$value];
            ajax_ok([
                'label'       => $st['label'],
                'badge_class' => $st['class'],
            ], 'Status niezmieniony');
        }

        // Walidacja dozwolonych przejść — edytorzy tylko do przodu, admini bez ograniczeń
        $is_admin = (current_user()['role'] ?? '') === 'admin';
        if (!$is_admin) {
            $allowed_next = STATUS_TRANSITIONS[$old_status] ?? [];
            if (!in_array($value, $allowed_next, true)) {
                $from_lbl = STATUS_LABELS[$old_status]['label'] ?? $old_status;
                $to_lbl   = STATUS_LABELS[$value]['label']      ?? $value;
                ajax_err('Niedozwolona zmiana: ' . $from_lbl . ' → ' . $to_lbl, 403);
            }
        }

        try {
            db_update($table, ['status' => $value], $id);
            log_contract_action(
                $type, $id,
                (int)current_user()['id'],
                'status_change',
                'Zmiana statusu: ' . $old_status . ' → ' . $value
            );
        } catch (\Throwable $e) {
            ajax_err('Błąd zapisu: ' . $e->getMessage());
        }

        $st = STATUS_LABELS[$value];
        ajax_ok([
            'label'       => $st['label'],
            'badge_class' => $st['class'],
        ], 'Status zaktualizowany');
    }

    // ── set_favorite ──────────────────────────────────────────────────────────
    case 'set_favorite': {
        if (!can_edit()) ajax_err('Brak uprawnień', 403);
        if (!$id)        ajax_err('Brak id');
        if (!$type)      ajax_err('Brak type');

        if (!isset(CONTRACT_TYPES[$type])) {
            ajax_err('Nieznany typ umowy: ' . $type);
        }

        $table = 'umowy_' . $type;

        // Dodaj kolumnę jeśli nie istnieje (bezpieczny try/catch)
        try {
            db()->exec("ALTER TABLE {$table} ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {
            // Kolumna już istnieje — ignoruj
        }

        try {
            $row = db_one("SELECT is_favorite FROM {$table} WHERE id=?", [$id]);
        } catch (\Throwable $e) {
            ajax_err('Nie znaleziono umowy');
        }

        if (!$row) ajax_err('Nie znaleziono umowy');

        $new_fav = $row['is_favorite'] ? 0 : 1;

        try {
            db_update($table, ['is_favorite' => $new_fav], $id);
        } catch (\Throwable $e) {
            ajax_err('Błąd zapisu: ' . $e->getMessage());
        }

        ajax_ok(['is_favorite' => (bool)$new_fav], $new_fav ? 'Dodano do ulubionych' : 'Usunięto z ulubionych');
    }

    // ── read_message ──────────────────────────────────────────────────────────
    case 'read_message': {
        $ctx_type = trim($_POST['context_type'] ?? '');
        $ctx_id   = (int)($_POST['context_id'] ?? 0);

        if (!$ctx_type || !$ctx_id) ajax_err('Brak context_type lub context_id');

        $u = current_user();
        // Admini czytają wiadomości od userów (sender_type='user')
        msg_mark_read($ctx_type, $ctx_id, 'admin');

        $unread = msg_unread_admin();
        ajax_ok(['unread_count' => $unread], 'Oznaczono jako przeczytane');
    }

    // ── dismiss_notification ──────────────────────────────────────────────────
    case 'dismiss_notification': {
        $notif_id = (int)($_POST['notification_id'] ?? 0);
        if (!$notif_id) ajax_err('Brak notification_id');

        $u = current_user();
        notif_migrate();
        notif_mark_read((int)$u['id'], $notif_id);

        ajax_ok([], 'Powiadomienie odrzucone');
    }

    default:
        ajax_err('Nieznana akcja: ' . $action);
}
