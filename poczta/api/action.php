<?php
/**
 * poczta/api/action.php — Akcje AJAX modułu Poczty.
 *
 * Dostęp: zalogowany użytkownik z uprawnieniem ZARZĄDZANIA daną skrzynką
 * (admin, właściciel skrzynki osobistej albo osoba z can_manage w ACL).
 *
 * POST body (JSON): {action: "scan_now"|"toggle_enabled"|"reset_status", mailbox_id: N, enabled?: 0|1}
 * Odpowiedź: JSON {ok, message, data}
 */

$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/poczta.php';
require_once $_root . '/includes/poczta_acl.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Brak dostępu.']);
    exit;
}
poczta_acl_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Wymagana metoda POST.']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = ($raw && ($json = json_decode($raw, true)) !== null) ? $json : $_POST;

$action     = trim($input['action'] ?? '');
$mailbox_id = (int)($input['mailbox_id'] ?? 0);

if (!$mailbox_id) {
    echo json_encode(['ok' => false, 'message' => 'Brak mailbox_id.']);
    exit;
}

// Każda akcja zmienia stan skrzynki — wymagamy uprawnienia zarządzania właśnie tą skrzynką
if (!poczta_can_access($mailbox_id, 'manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Brak uprawnień do tej skrzynki.']);
    exit;
}

$mb = db_one("SELECT id FROM poczta_mailboxes WHERE id=?", [$mailbox_id]);
if (!$mb) {
    echo json_encode(['ok' => false, 'message' => 'Nie znaleziono skrzynki.']);
    exit;
}

try {
    switch ($action) {
        case 'scan_now':
            $result = (new PocztaScanService())->scan_mailbox($mailbox_id);
            echo json_encode([
                'ok'      => $result['status'] !== 'error',
                'message' => sprintf(
                    'Pobrano %d, dopasowano %d, zapisano %d nowych.%s',
                    $result['fetched'], $result['matched'], $result['created'],
                    $result['error'] ? ' Błąd: ' . $result['error'] : ''
                ),
                'data' => $result,
            ]);
            break;

        case 'toggle_enabled':
            $enabled = !empty($input['enabled']) ? 1 : 0;
            db()->prepare("UPDATE poczta_mailboxes SET enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$enabled, $mailbox_id]);
            echo json_encode(['ok' => true, 'message' => 'Zapisano.']);
            break;

        case 'reset_status':
            db()->prepare(
                "UPDATE poczta_mailboxes SET status='ok', status_detail='', next_retry_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute([$mailbox_id]);
            echo json_encode(['ok' => true, 'message' => 'Status zresetowany.']);
            break;

        default:
            echo json_encode(['ok' => false, 'message' => 'Nieznana akcja.']);
    }
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
