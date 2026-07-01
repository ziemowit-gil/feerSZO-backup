<?php
/**
 * REST API — Wydarzenia (events)
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienia: events:read (odczyt), events:write (zapis).
 *
 * Routing (jeden plik, bez rewrite — sterowanie metodą HTTP + parametrami):
 *   GET    /api/v1/events.php                                      → lista wydarzeń (filtry + paginacja)
 *   GET    /api/v1/events.php?id=N                                 → pojedyncze wydarzenie
 *   POST   /api/v1/events.php                                      → utwórz wydarzenie (JSON body, status=draft)
 *   PATCH  /api/v1/events.php?id=N                                 → aktualizuj wydarzenie
 *   DELETE /api/v1/events.php?id=N                                 → archiwizuj wydarzenie (status=archived)
 *   GET    /api/v1/events.php?id=N&resource=registrations          → lista rejestracji (filtry + paginacja)
 *   POST   /api/v1/events.php?id=N&resource=registrations          → dodaj rejestrację (body: first_name, last_name, email, phone?, reg_data?)
 *   GET    /api/v1/events.php?id=N&resource=registrations&reg_id=M → pojedyncza rejestracja
 *   PATCH  /api/v1/events.php?id=N&resource=registrations&reg_id=M → aktualizuj rejestrację (status, notes, checked_in)
 *   DELETE /api/v1/events.php?id=N&resource=registrations&reg_id=M → anuluj rejestrację (status=cancelled)
 *
 * Metodę można nadpisać nagłówkiem X-HTTP-Method-Override lub ?_method=PATCH
 * (dla klientów bez wsparcia PATCH/DELETE).
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/events.php';

api_auth_migrate();

// ── Routing ────────────────────────────────────────────────────────────────
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? '');
if ($override !== '') $method = strtoupper((string)$override);

$id       = (int)($_GET['id'] ?? 0);
$resource = (string)($_GET['resource'] ?? '');
$reg_id   = (int)($_GET['reg_id'] ?? 0);

// Autoryzacja zależna od metody
if ($method === 'GET') {
    api_require('events:read');
} else {
    api_require('events:write');
}

// ── Helpery ──────────────────────────────────────────────────────────────────

/** Body żądania jako tablica (JSON lub form-encoded). */
function ev_api_input(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $j   = json_decode($raw, true);
        if ($raw !== '' && !is_array($j)) {
            api_error('Invalid JSON body', 400);
        }
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

function ev_api_find(int $id): ?array {
    return db_one("SELECT * FROM ev_events WHERE id=?", [$id]);
}

function ev_api_find_reg(int $event_id, int $reg_id): ?array {
    return db_one("SELECT * FROM ev_registrations WHERE id=? AND event_id=?", [$reg_id, $event_id]);
}

/**
 * Waliduje i buduje payload zapisu wydarzenia z wejścia.
 * @param bool $require_title  Czy title/start_at są wymagane (create).
 * @return array{0:array,1:array}  [$data, $errors]
 */
function ev_api_build(array $in, bool $require_title): array {
    $errors = [];
    $data   = [];

    $fields = [
        'title','description','type','venue','address','meeting_url',
        'start_at','end_at','capacity','is_public','reg_open_at','reg_close_at',
        'cover_image','pa_webhook_url','notify_email','notify_new_reg','crm_auto_sync',
    ];
    foreach ($fields as $f) {
        if (array_key_exists($f, $in)) {
            $v = $in[$f];
            $data[$f] = is_string($v) ? trim($v) : $v;
        }
    }

    if ($require_title && empty($data['title'])) {
        $errors[] = 'Pole title jest wymagane.';
    }
    if ($require_title && empty($in['start_at'])) {
        $errors[] = 'Pole start_at jest wymagane.';
    }
    if (isset($data['type']) && !in_array($data['type'], ['webinar', 'stationary'], true)) {
        $errors[] = "Pole type musi być 'webinar' lub 'stationary'.";
    }
    if (!empty($data['meeting_url']) && !filter_var($data['meeting_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Nieprawidłowy adres meeting_url.';
    }
    if (!empty($data['notify_email']) && !filter_var($data['notify_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Nieprawidłowy adres notify_email.';
    }

    if (array_key_exists('is_public', $data)) $data['is_public'] = (int)!!$data['is_public'];
    if (array_key_exists('notify_new_reg', $data)) $data['notify_new_reg'] = (int)!!$data['notify_new_reg'];
    if (array_key_exists('crm_auto_sync', $data)) $data['crm_auto_sync'] = (int)!!$data['crm_auto_sync'];
    if (array_key_exists('capacity', $data)) {
        $data['capacity'] = ($data['capacity'] === '' || $data['capacity'] === null) ? null : (int)$data['capacity'];
    }
    foreach (['start_at', 'end_at', 'reg_open_at', 'reg_close_at'] as $df) {
        if (array_key_exists($df, $data) && $data[$df] === '') $data[$df] = null;
    }

    return [$data, $errors];
}

function ev_api_slug(string $title): string {
    $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: $title));
    $slug = trim((string)$slug, '-') ?: 'wydarzenie';
    $base = $slug;
    $i    = 1;
    while (db_one("SELECT id FROM ev_events WHERE slug=?", [$slug])) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

// ══════════════════════════════════════════════════════════════════════════════
// SUB-ZASÓB: rejestracje  (?id=N&resource=registrations[&reg_id=M])
// ══════════════════════════════════════════════════════════════════════════════
if ($resource !== '') {
    if ($resource !== 'registrations') api_error('Nieznany zasób.', 404);
    if ($id <= 0) api_error('Brak parametru id.', 400);

    $event = ev_api_find($id);
    if (!$event) api_error('Nie znaleziono wydarzenia.', 404);

    // ── Pojedyncza rejestracja ──────────────────────────────────────────────
    if ($reg_id > 0) {
        $reg = ev_api_find_reg($id, $reg_id);
        if (!$reg) api_error('Nie znaleziono rejestracji.', 404);

        if ($method === 'GET') {
            api_json(['data' => ev_api_registration($reg)]);
        }

        if ($method === 'PATCH' || $method === 'PUT') {
            $in     = ev_api_input();
            $update = [];
            if (isset($in['status']) && in_array($in['status'], ['confirmed', 'cancelled', 'waitlist'], true)) {
                $update['status'] = $in['status'];
            }
            if (array_key_exists('notes', $in)) $update['notes'] = trim((string)$in['notes']);
            if (!empty($in['checked_in'])) $update['checked_in_at'] = date('Y-m-d H:i:s');
            if (!$update) api_error('Brak pól do aktualizacji.', 400);
            $update['updated_at'] = date('Y-m-d H:i:s');
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($update)));
            db()->prepare("UPDATE ev_registrations SET $set WHERE id=?")
                ->execute([...array_values($update), $reg_id]);
            api_audit('update', 'registrations', $reg_id, array_keys($update), 200);
            api_json(['data' => ev_api_registration(ev_api_find_reg($id, $reg_id))]);
        }

        if ($method === 'DELETE') {
            db()->prepare("UPDATE ev_registrations SET status='cancelled', updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), $reg_id]);
            api_audit('delete', 'registrations', $reg_id, [], 200);
            api_json(['data' => ['id' => $reg_id, 'status' => 'cancelled']]);
        }

        api_error('Method Not Allowed', 405);
    }

    // ── Kolekcja rejestracji ────────────────────────────────────────────────
    if ($method === 'GET') {
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
        $offset   = ($page - 1) * $per_page;

        $where  = ['event_id = ?'];
        $params = [$id];
        if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = $_GET['status']; }
        if (!empty($_GET['q'])) {
            $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
            $q = '%' . $_GET['q'] . '%';
            array_push($params, $q, $q, $q);
        }
        $sql_where = 'WHERE ' . implode(' AND ', $where);

        $total = (int)(db_one("SELECT COUNT(*) AS cnt FROM ev_registrations $sql_where", $params)['cnt'] ?? 0);
        $rows  = db_all(
            "SELECT * FROM ev_registrations $sql_where ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$per_page, $offset])
        );

        api_json([
            'data' => array_map('ev_api_registration', $rows),
            'meta' => [
                'total' => $total, 'page' => $page, 'per_page' => $per_page,
                'pages' => max(1, (int)ceil($total / $per_page)),
            ],
        ]);
    }

    if ($method === 'POST') {
        $in    = ev_api_input();
        $first = trim((string)($in['first_name'] ?? ''));
        $last  = trim((string)($in['last_name'] ?? ''));
        $email = trim((string)($in['email'] ?? ''));
        $phone = trim((string)($in['phone'] ?? ''));

        $errs = [];
        if ($first === '') $errs[] = 'Pole first_name jest wymagane.';
        if ($last === '')  $errs[] = 'Pole last_name jest wymagane.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Nieprawidłowy adres e-mail.';
        if ($errs) api_error(implode(' ', $errs), 422);

        $dup = db_one(
            "SELECT id FROM ev_registrations WHERE event_id=? AND email=? AND status!='cancelled'",
            [$id, $email]
        );
        if ($dup) api_error('Ten adres e-mail jest już zarejestrowany na to wydarzenie.', 409);

        $reg_count = ev_reg_count($id);
        $status    = 'confirmed';
        if ($event['capacity'] && $reg_count >= (int)$event['capacity']) {
            if (org_setting('ev_waitlist_enabled') === '0') {
                api_error('Brak wolnych miejsc na to wydarzenie.', 409);
            }
            $status = 'waitlist';
        }

        $ticket    = ev_ticket_code();
        $reg_data  = (isset($in['reg_data']) && is_array($in['reg_data']))
            ? json_encode($in['reg_data'], JSON_UNESCAPED_UNICODE)
            : null;
        $new_reg_id = db_insert('ev_registrations', [
            'event_id'    => $id,
            'first_name'  => $first,
            'last_name'   => $last,
            'email'       => $email,
            'phone'       => $phone ?: null,
            'ticket_code' => $ticket,
            'status'      => $status,
            'reg_data'    => $reg_data,
            'source'      => 'api',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        try {
            ev_crm_sync(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone], $id);
        } catch (\Throwable $e) {}
        try {
            ev_pa_notify($id, [
                'first_name' => $first, 'last_name' => $last, 'email' => $email,
                'phone' => $phone, 'ticket_code' => $ticket,
            ]);
        } catch (\Throwable $e) {}

        api_audit('create', 'registrations', $new_reg_id, ['first_name', 'last_name', 'email', 'phone'], 201);
        api_json(['data' => ev_api_registration(ev_api_find_reg($id, $new_reg_id))], 201);
    }

    api_error('Method Not Allowed', 405);
}

// ══════════════════════════════════════════════════════════════════════════════
// WYDARZENIE POJEDYNCZE  (?id=N)
// ══════════════════════════════════════════════════════════════════════════════
if ($id > 0) {
    $event = ev_api_find($id);
    if (!$event) api_error('Nie znaleziono wydarzenia.', 404);

    if ($method === 'GET') {
        api_json(['data' => ev_api_event($event)]);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        [$data, $errors] = ev_api_build(ev_api_input(), false);
        if ($errors) api_error(implode(' ', $errors), 422);
        if (!$data) api_error('Brak pól do aktualizacji.', 400);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        db()->prepare("UPDATE ev_events SET $set WHERE id=?")->execute([...array_values($data), $id]);
        api_audit('update', 'events', $id, array_keys($data), 200);
        api_json(['data' => ev_api_event(ev_api_find($id))]);
    }

    if ($method === 'DELETE') {
        db()->prepare("UPDATE ev_events SET status='archived', updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $id]);
        api_audit('delete', 'events', $id, [], 200);
        api_json(['data' => ['id' => $id, 'status' => 'archived']]);
    }

    api_error('Method Not Allowed', 405);
}

// ══════════════════════════════════════════════════════════════════════════════
// KOLEKCJA  (bez id)
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    global $api_current_key;
    [$data, $errors] = ev_api_build(ev_api_input(), true);
    if ($errors) api_error(implode(' ', $errors), 422);

    $data['slug']   = ev_api_slug($data['title']);
    $data['status'] = 'draft';
    if (empty($data['type'])) $data['type'] = 'stationary';
    if (!array_key_exists('is_public', $data)) $data['is_public'] = 1;
    $data['created_by'] = (int)($api_current_key['created_by'] ?? 0) ?: null;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    $new_id = db_insert('ev_events', $data);
    api_audit('create', 'events', $new_id, array_keys($data), 201);
    api_json(['data' => ev_api_event(ev_api_find($new_id))], 201);
}

if ($method !== 'GET') {
    api_error('Method Not Allowed', 405);
}

// ── GET: lista z filtrami + paginacją ─────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset   = ($page - 1) * $per_page;

$where  = [];
$params = [];

foreach (['status' => 'status = ?', 'type' => 'type = ?'] as $key => $cond) {
    if (!empty($_GET[$key])) { $where[] = $cond; $params[] = $_GET[$key]; }
}
if (isset($_GET['is_public']) && $_GET['is_public'] !== '') {
    $where[] = 'is_public = ?';
    $params[] = (int)!!$_GET['is_public'];
}
if (!empty($_GET['q'])) {
    $where[] = '(title LIKE ? OR venue LIKE ?)';
    $q = '%' . $_GET['q'] . '%';
    array_push($params, $q, $q);
}
if (!empty($_GET['upcoming'])) {
    $where[] = "start_at >= datetime('now','localtime')";
}
if (!empty($_GET['from'])) { $where[] = 'start_at >= ?'; $params[] = $_GET['from']; }
if (!empty($_GET['to']))   { $where[] = 'start_at <= ?'; $params[] = $_GET['to']; }

$sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)(db_one("SELECT COUNT(*) AS cnt FROM ev_events $sql_where", $params)['cnt'] ?? 0);
$rows  = db_all(
    "SELECT * FROM ev_events $sql_where ORDER BY start_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset])
);

api_json([
    'data' => array_map('ev_api_event', $rows),
    'meta' => [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => max(1, (int)ceil($total / $per_page)),
    ],
]);
