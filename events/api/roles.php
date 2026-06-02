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
    case 'add': {
        $event_id = (int)($body['event_id'] ?? 0);
        $user_id  = (int)($body['user_id']  ?? 0);
        $role     = $body['role'] ?? 'volunteer';
        if (!$event_id) ev_api_error('Brak event_id.');
        if (!$user_id)  ev_api_error('Brak user_id.');
        if (!in_array($role, ['admin','volunteer','checkin'], true)) ev_api_error('Nieprawidłowa rola.');
        ev_require_role($event_id, ['admin']);

        $uid = (int)(current_user()['id'] ?? 0);
        try {
            db()->prepare(
                "INSERT OR REPLACE INTO ev_roles (event_id, user_id, role, added_by, added_at)
                 VALUES (?,?,?,?,datetime('now','localtime'))"
            )->execute([$event_id, $user_id, $role, $uid]);
        } catch (\Throwable $e) {
            // Fallback for DBs without OR REPLACE
            $exists = db_one("SELECT id FROM ev_roles WHERE event_id=? AND user_id=?", [$event_id, $user_id]);
            if ($exists) {
                db()->prepare("UPDATE ev_roles SET role=?, added_by=? WHERE event_id=? AND user_id=?")
                    ->execute([$role, $uid, $event_id, $user_id]);
            } else {
                db_insert('ev_roles', ['event_id'=>$event_id,'user_id'=>$user_id,'role'=>$role,'added_by'=>$uid,'added_at'=>date('Y-m-d H:i:s')]);
            }
        }
        ev_api_ok();
    }

    case 'remove': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) ev_api_error('Brak ID roli.');
        $r = db_one("SELECT event_id FROM ev_roles WHERE id=?", [$id]);
        if (!$r) ev_api_error('Rola nie istnieje.', 404);
        ev_require_role((int)$r['event_id'], ['admin']);
        db()->prepare("DELETE FROM ev_roles WHERE id=?")->execute([$id]);
        ev_api_ok();
    }

    default:
        ev_api_error('Nieznana akcja.');
}
