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
        if (!$event_id) ev_api_error('Brak event_id.');
        ev_require_role($event_id, ['admin']);

        $label     = trim($body['label'] ?? '');
        $field_key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($body['field_key'] ?? $label)));
        $type      = $body['type'] ?? 'text';
        if (!$label)     ev_api_error('Etykieta jest wymagana.');
        if (!$field_key) ev_api_error('Klucz pola jest wymagany.');
        if (!in_array($type, ['text','email','tel','select','checkbox','textarea','number'], true)) ev_api_error('Nieprawidłowy typ.');

        // Duplicate key check for this event
        $dup = db_one("SELECT id FROM ev_form_fields WHERE event_id=? AND field_key=?", [$event_id, $field_key]);
        if ($dup) ev_api_error('Pole o tym kluczu już istnieje.');

        $max_pos = db_one("SELECT COALESCE(MAX(position),0) AS p FROM ev_form_fields WHERE event_id=?", [$event_id]);
        $opts    = $body['options'] ?? [];
        $id = db_insert('ev_form_fields', [
            'event_id'    => $event_id,
            'field_key'   => $field_key,
            'label'       => $label,
            'type'        => $type,
            'options'     => json_encode(is_array($opts) ? $opts : []),
            'placeholder' => trim($body['placeholder'] ?? ''),
            'is_required' => (int)($body['is_required'] ?? 0),
            'position'    => (int)($max_pos['p'] ?? 0) + 1,
        ]);
        ev_api_ok(['id' => $id]);
    }

    case 'update': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) ev_api_error('Brak ID.');
        $ff = db_one("SELECT * FROM ev_form_fields WHERE id=?", [$id]);
        if (!$ff) ev_api_error('Pole nie istnieje.', 404);
        ev_require_role((int)$ff['event_id'], ['admin']);

        $label = trim($body['label'] ?? $ff['label']);
        $type  = $body['type'] ?? $ff['type'];
        if (!in_array($type, ['text','email','tel','select','checkbox','textarea','number'], true)) ev_api_error('Nieprawidłowy typ.');

        $opts = $body['options'] ?? [];
        db()->prepare(
            "UPDATE ev_form_fields SET label=?,type=?,options=?,placeholder=?,is_required=? WHERE id=?"
        )->execute([
            $label, $type,
            json_encode(is_array($opts) ? $opts : []),
            trim($body['placeholder'] ?? $ff['placeholder']),
            (int)($body['is_required'] ?? $ff['is_required']),
            $id,
        ]);
        ev_api_ok();
    }

    case 'remove': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) ev_api_error('Brak ID.');
        $ff = db_one("SELECT event_id FROM ev_form_fields WHERE id=?", [$id]);
        if (!$ff) ev_api_error('Pole nie istnieje.', 404);
        ev_require_role((int)$ff['event_id'], ['admin']);
        db()->prepare("DELETE FROM ev_form_fields WHERE id=?")->execute([$id]);
        ev_api_ok();
    }

    case 'reorder': {
        $event_id = (int)($body['event_id'] ?? 0);
        if (!$event_id) ev_api_error('Brak event_id.');
        ev_require_role($event_id, ['admin']);
        $ids = $body['ids'] ?? [];
        if (!is_array($ids)) ev_api_error('ids musi być tablicą.');
        foreach ($ids as $pos => $fid) {
            db()->prepare("UPDATE ev_form_fields SET position=? WHERE id=? AND event_id=?")
                ->execute([(int)$pos + 1, (int)$fid, $event_id]);
        }
        ev_api_ok();
    }

    default:
        ev_api_error('Nieznana akcja.');
}
