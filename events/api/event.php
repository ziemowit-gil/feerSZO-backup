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
    case 'create':
        if (!is_admin()) ev_api_error('Brak uprawnień.', 403);
        $title = trim($body['title'] ?? '');
        $type  = $body['type'] ?? 'webinar';
        if (!$title) ev_api_error('Tytuł jest wymagany.');
        if (!in_array($type, ['webinar','stationary'], true)) ev_api_error('Nieprawidłowy typ.');

        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title)));
        $slug = trim($slug, '-');
        // Ensure unique slug
        $base = $slug; $i = 1;
        while (db_one("SELECT id FROM ev_events WHERE slug=?", [$slug])) {
            $slug = $base . '-' . $i++;
        }

        $uid = (int)(current_user()['id'] ?? 0);
        $id  = db_insert('ev_events', [
            'slug'         => $slug,
            'title'        => $title,
            'description'  => trim($body['description'] ?? ''),
            'type'         => $type,
            'status'       => 'draft',
            'venue'        => trim($body['venue']        ?? ''),
            'address'      => trim($body['address']      ?? ''),
            'meeting_url'  => trim($body['meeting_url']  ?? ''),
            'start_at'     => $body['start_at']  ?: null,
            'end_at'       => $body['end_at']    ?: null,
            'capacity'     => isset($body['capacity']) && $body['capacity'] !== '' ? (int)$body['capacity'] : null,
            'is_public'    => isset($body['is_public']) ? (int)$body['is_public'] : 1,
            'reg_open_at'  => $body['reg_open_at']  ?: null,
            'reg_close_at' => $body['reg_close_at'] ?: null,
            'created_by'   => $uid,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        ev_api_ok(['id' => $id, 'slug' => $slug]);

    case 'update':
        $event_id = (int)($body['id'] ?? 0);
        if (!$event_id) ev_api_error('Brak ID.');
        ev_require_role($event_id, ['admin']);
        $ev = db_one("SELECT * FROM ev_events WHERE id=?", [$event_id]);
        if (!$ev) ev_api_error('Wydarzenie nie istnieje.', 404);

        $fields = ['title','description','type','venue','address','meeting_url',
                   'start_at','end_at','capacity','is_public','reg_open_at','reg_close_at',
                   'pa_webhook_url','cover_image'];
        $update = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $update[$f] = $body[$f] !== '' ? $body[$f] : null;
            }
        }
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($update)));
        db()->prepare("UPDATE ev_events SET $set WHERE id=?")->execute([...array_values($update), $event_id]);
        ev_api_ok();

    case 'update_status':
        $event_id = (int)($body['id'] ?? 0);
        if (!$event_id) ev_api_error('Brak ID.');
        ev_require_role($event_id, ['admin']);
        $status = $body['status'] ?? '';
        if (!in_array($status, ['draft','published','cancelled','archived'], true)) ev_api_error('Nieprawidłowy status.');
        db()->prepare("UPDATE ev_events SET status=?,updated_at=? WHERE id=?")
            ->execute([$status, date('Y-m-d H:i:s'), $event_id]);
        ev_api_ok(['status' => $status]);

    case 'publish':
        $event_id = (int)($body['id'] ?? 0);
        ev_require_role($event_id, ['admin']);
        db()->prepare("UPDATE ev_events SET status='published',updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $event_id]);
        ev_api_ok();

    case 'cancel':
        $event_id = (int)($body['id'] ?? 0);
        ev_require_role($event_id, ['admin']);
        db()->prepare("UPDATE ev_events SET status='cancelled',updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $event_id]);
        ev_api_ok();

    case 'archive':
        $event_id = (int)($body['id'] ?? 0);
        ev_require_role($event_id, ['admin']);
        db()->prepare("UPDATE ev_events SET status='archived',updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $event_id]);
        ev_api_ok();

    default:
        ev_api_error('Nieznana akcja.');
}
