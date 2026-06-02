<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/events.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ev_api_error('Metoda niedozwolona.', 405);
ev_csrf_check();
require_login();

$body   = ev_parse_json();
$action = $body['action'] ?? '';

switch ($action) {
    case 'generate': {
        $event_id = (int)($body['event_id'] ?? 0);
        if (!$event_id) ev_api_error('Brak event_id.');
        ev_require_role($event_id, ['admin']);

        $expires_at = trim($body['expires_at'] ?? '');
        if ($expires_at && !preg_match('/^\d{4}-\d{2}-\d{2}/', $expires_at)) {
            ev_api_error('Nieprawidłowy format daty ważności.');
        }

        // Generate unique token
        do {
            $token = bin2hex(random_bytes(20));
        } while (db_one("SELECT id FROM ev_checkin_tokens WHERE token=?", [$token]));

        $uid = (int)(current_user()['id'] ?? 0);
        $id  = db_insert('ev_checkin_tokens', [
            'event_id'   => $event_id,
            'token'      => $token,
            'expires_at' => $expires_at ?: null,
            'created_by' => $uid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        ev_api_ok(['id' => $id, 'token' => $token]);
    }

    case 'revoke': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) ev_api_error('Brak ID tokenu.');
        $tok = db_one("SELECT event_id FROM ev_checkin_tokens WHERE id=?", [$id]);
        if (!$tok) ev_api_error('Token nie istnieje.', 404);
        ev_require_role((int)$tok['event_id'], ['admin']);
        db()->prepare("DELETE FROM ev_checkin_tokens WHERE id=?")->execute([$id]);
        ev_api_ok();
    }

    default:
        ev_api_error('Nieznana akcja.');
}
