<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/events.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ev_api_error('Metoda niedozwolona.', 405);
ev_csrf_check();

$body        = ev_parse_json();
$event_id    = (int)($body['event_id'] ?? 0);
$ticket_code = strtoupper(trim($body['ticket_code'] ?? ''));

if (!$event_id)    ev_api_error('Brak event_id.');
if (!$ticket_code) ev_api_error('Brak kodu biletu.');

// Auth: login required OR valid checkin token in session
auth_start();
$token_raw = $_SESSION['ev_checkin_token'] ?? null;
$user_id   = $_SESSION['user_id'] ?? null;

if ($user_id) {
    // Normal logged-in user
    $role = ev_role($event_id, (int)$user_id);
    if (!$role || !in_array($role, ['admin','volunteer','checkin'], true)) {
        ev_api_error('Brak dostępu do check-in.', 403);
    }
    $checker_id = (int)$user_id;
} elseif ($token_raw) {
    // Token auth
    $tok = db_one(
        "SELECT id FROM ev_checkin_tokens WHERE token=? AND event_id=? AND (expires_at IS NULL OR expires_at > datetime('now','localtime'))",
        [$token_raw, $event_id]
    );
    if (!$tok) ev_api_error('Token jest nieważny.', 403);
    $checker_id = null;
} else {
    ev_api_error('Brak autoryzacji.', 401);
}

$event = db_one("SELECT id FROM ev_events WHERE id=?", [$event_id]);
if (!$event) ev_api_error('Wydarzenie nie istnieje.', 404);

$reg = db_one("SELECT * FROM ev_registrations WHERE event_id=? AND ticket_code=?", [$event_id, $ticket_code]);
if (!$reg) ev_api_error('Nie znaleziono biletu o podanym kodzie.');
if ($reg['status'] === 'cancelled') ev_api_error('Ta rejestracja została anulowana.');
if ($reg['status'] === 'waitlist')  ev_api_error('Uczestnik jest na liście oczekujących.');
if ($reg['checked_in_at'])          ev_api_error('Ten bilet został już zeskanowany o ' . date('H:i', strtotime($reg['checked_in_at'])) . '.');

$now = date('Y-m-d H:i:s');
db()->prepare("UPDATE ev_registrations SET checked_in_at=?, checked_in_by=?, updated_at=? WHERE id=?")
    ->execute([$now, $checker_id, $now, $reg['id']]);

ev_api_ok([
    'ticket_code' => $ticket_code,
    'first_name'  => $reg['first_name'],
    'last_name'   => $reg['last_name'],
    'email'       => $reg['email'],
    'checked_in_at' => $now,
]);
