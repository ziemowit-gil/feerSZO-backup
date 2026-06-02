<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/events.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ev_api_error('Metoda niedozwolona.', 405);
ev_csrf_check();

$body = ev_parse_json();

$event_id  = (int)($body['event_id'] ?? 0);
$first_name = trim($body['first_name'] ?? '');
$last_name  = trim($body['last_name']  ?? '');
$email      = trim($body['email']      ?? '');
$phone      = trim($body['phone']      ?? '');
$reg_data   = $body['reg_data'] ?? [];

if (!$event_id)   ev_api_error('Brak event_id.');
if (!$first_name) ev_api_error('Imię jest wymagane.');
if (!$last_name)  ev_api_error('Nazwisko jest wymagane.');
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) ev_api_error('Nieprawidłowy email.');

$event = db_one("SELECT * FROM ev_events WHERE id=?", [$event_id]);
if (!$event || $event['status'] !== 'published') ev_api_error('Wydarzenie nie istnieje lub jest niedostępne.', 404);

// Public check
if (!$event['is_public']) {
    require_login();
}

// Registration window
$now = date('Y-m-d H:i:s');
if ($event['reg_open_at']  && $now < $event['reg_open_at'])  ev_api_error('Rejestracja jeszcze nie jest otwarta.');
if ($event['reg_close_at'] && $now > $event['reg_close_at']) ev_api_error('Rejestracja jest zamknięta.');

// Duplicate
$dup = db_one("SELECT id FROM ev_registrations WHERE event_id=? AND email=? AND status!='cancelled'", [$event_id, $email]);
if ($dup) ev_api_error('Ten email jest już zarejestrowany.');

// Capacity
$confirmed = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND status='confirmed'", [$event_id])['n'] ?? 0);
$status = 'confirmed';
if ($event['capacity'] && $confirmed >= (int)$event['capacity']) {
    $waitlist = org_setting('ev_waitlist_enabled');
    if ($waitlist === '0') ev_api_error('Brak wolnych miejsc.');
    $status = 'waitlist';
}

$ticket_code = ev_ticket_code();
$reg_id = db_insert('ev_registrations', [
    'event_id'    => $event_id,
    'first_name'  => $first_name,
    'last_name'   => $last_name,
    'email'       => $email,
    'phone'       => $phone,
    'ticket_code' => $ticket_code,
    'status'      => $status,
    'reg_data'    => json_encode($reg_data, JSON_UNESCAPED_UNICODE),
    'source'      => 'api',
    'created_at'  => date('Y-m-d H:i:s'),
    'updated_at'  => date('Y-m-d H:i:s'),
]);

ev_crm_sync($event_id, $reg_id, compact('first_name','last_name','email','phone'));
ev_pa_notify($event_id, compact('first_name','last_name','email','phone','ticket_code'));

ev_api_ok([
    'registration_id' => $reg_id,
    'ticket_code'     => $ticket_code,
    'status'          => $status,
]);
