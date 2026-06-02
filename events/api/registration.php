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
    case 'cancel':
    case 'restore': {
        $id  = (int)($body['id'] ?? 0);
        if (!$id) ev_api_error('Brak ID rejestracji.');
        $reg = db_one("SELECT * FROM ev_registrations WHERE id=?", [$id]);
        if (!$reg) ev_api_error('Rejestracja nie istnieje.', 404);
        ev_require_role((int)$reg['event_id'], ['admin', 'volunteer']);

        $new_status = $action === 'cancel' ? 'cancelled' : 'confirmed';
        db()->prepare("UPDATE ev_registrations SET status=?,updated_at=? WHERE id=?")
            ->execute([$new_status, date('Y-m-d H:i:s'), $id]);
        ev_api_ok(['status' => $new_status]);
    }

    case 'add': {
        $event_id   = (int)($body['event_id'] ?? 0);
        if (!$event_id) ev_api_error('Brak event_id.');
        ev_require_role($event_id, ['admin', 'volunteer']);

        $first_name = trim($body['first_name'] ?? '');
        $last_name  = trim($body['last_name']  ?? '');
        $email      = trim($body['email']      ?? '');
        $phone      = trim($body['phone']      ?? '');
        $notes      = trim($body['notes']      ?? '');

        if (!$first_name) ev_api_error('Imię jest wymagane.');
        if (!$last_name)  ev_api_error('Nazwisko jest wymagane.');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) ev_api_error('Nieprawidłowy email.');

        // Duplicate check
        $dup = db_one("SELECT id FROM ev_registrations WHERE event_id=? AND email=? AND status!='cancelled'", [$event_id, $email]);
        if ($dup) ev_api_error('Ten email jest już zarejestrowany.');

        $ticket_code = ev_ticket_code();
        $reg_id = db_insert('ev_registrations', [
            'event_id'    => $event_id,
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'email'       => $email,
            'phone'       => $phone,
            'ticket_code' => $ticket_code,
            'status'      => 'confirmed',
            'reg_data'    => '{}',
            'source'      => 'admin',
            'notes'       => $notes,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        ev_crm_sync($event_id, $reg_id, compact('first_name','last_name','email','phone'));
        ev_api_ok(['registration_id' => $reg_id, 'ticket_code' => $ticket_code]);
    }

    case 'delete': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) ev_api_error('Brak ID.');
        $reg = db_one("SELECT event_id FROM ev_registrations WHERE id=?", [$id]);
        if (!$reg) ev_api_error('Rejestracja nie istnieje.', 404);
        ev_require_role((int)$reg['event_id'], ['admin']);
        db()->prepare("DELETE FROM ev_registrations WHERE id=?")->execute([$id]);
        ev_api_ok();
    }

    default:
        ev_api_error('Nieznana akcja.');
}
