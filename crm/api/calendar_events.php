<?php
/**
 * crm/api/calendar_events.php — REST-like JSON API dla kalendarza CRM.
 *
 * GET  ?action=list&from=YYYY-MM-DD&to=YYYY-MM-DD   → lista zdarzeń
 * POST action=create   → utwórz zdarzenie
 * POST action=update   → aktualizuj zdarzenie
 * POST action=delete   → usuń zdarzenie
 * POST action=done     → oznacz jako wykonane/niewykonane
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

function api_ok(mixed $data = null): never {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function api_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Uczestnicy pojedynczego zdarzenia: [{id, name}]
function event_participants(int $event_id): array {
    return db_all(
        "SELECT u.id, u.name
         FROM crm_event_participants p JOIN users u ON u.id=p.user_id
         WHERE p.event_id=? ORDER BY u.name",
        [$event_id]
    );
}

// Zapisz zestaw uczestników zdarzenia (replace). $ids = lista int user_id.
function event_set_participants(int $event_id, array $ids, int $by): void {
    db()->prepare("DELETE FROM crm_event_participants WHERE event_id=?")->execute([$event_id]);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return;
    $st = db()->prepare(
        "INSERT OR IGNORE INTO crm_event_participants (event_id, user_id, added_by, added_at)
         SELECT ?, id, ?, ? FROM users WHERE id=? AND is_active=1"
    );
    $now = date('Y-m-d H:i:s');
    foreach ($ids as $u) { $st->execute([$event_id, $by, $now, $u]); }
}

if (!current_user()) api_err('Wymagane logowanie.', 401);

crm_require_json('contacts', 'read');
require_module_or_die: ;
try { require_module_enabled('crm_enabled', 'CRM'); }
catch (\Throwable $e) { api_err('Moduł CRM wyłączony.', 403); }
crm_migrate();

$can_write = can_write('crm') || is_admin();
$uid       = (int)(current_user()['id'] ?? 0);
$method    = $_SERVER['REQUEST_METHOD'];
$action    = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── GET: lista zdarzeń ────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-t');

    // Zdarzenia własne
    $events = db_all(
        "SELECT e.*, ct.imie_nazwisko AS contact_name
         FROM crm_events e
         LEFT JOIN crm_contacts ct ON ct.id=e.contact_id
         WHERE e.event_date BETWEEN ? AND ?
         ORDER BY e.event_date, e.event_time",
        [$from, $to]
    );

    // Sprawy (created_at jako zdarzenie)
    $cases = db_all(
        "SELECT c.id, c.title, DATE(c.created_at) AS event_date,
                ct.imie_nazwisko AS contact_name, c.status, c.priority
         FROM crm_cases c
         LEFT JOIN crm_contacts ct ON ct.id=c.contact_id
         WHERE DATE(c.created_at) BETWEEN ? AND ? AND c.status NOT IN ('closed','cancelled')",
        [$from, $to]
    );

    // Komunikacje (sent_at)
    $comms = db_all(
        "SELECT cc.id, cc.subject, cc.channel, DATE(cc.sent_at) AS event_date,
                ct.imie_nazwisko AS contact_name
         FROM crm_communications cc
         LEFT JOIN crm_contacts ct ON ct.id=cc.contact_id
         WHERE DATE(cc.sent_at) BETWEEN ? AND ?",
        [$from, $to]
    );

    // Uczestnicy dla pobranych zdarzeń — jedno zapytanie, mapowane po event_id
    $part_map = [];
    $ev_ids = array_map(fn($e) => (int)$e['id'], $events);
    if ($ev_ids) {
        $ph = implode(',', array_fill(0, count($ev_ids), '?'));
        foreach (db_all(
            "SELECT p.event_id, u.id, u.name
             FROM crm_event_participants p JOIN users u ON u.id=p.user_id
             WHERE p.event_id IN ($ph) ORDER BY u.name", $ev_ids
        ) as $row) {
            $part_map[(int)$row['event_id']][] = ['id' => (int)$row['id'], 'name' => $row['name']];
        }
    }

    $out = [];

    foreach ($events as $e) {
        $out[] = [
            'id'           => 'ev_' . $e['id'],
            'raw_id'       => (int)$e['id'],
            'type'         => 'event',
            'event_type'   => $e['event_type'],
            'title'        => $e['title'],
            'description'  => $e['description'],
            'date'         => $e['event_date'],
            'time'         => $e['event_time'],
            'end_date'     => $e['event_end_date'],
            'end_time'     => $e['event_end_time'],
            'all_day'      => (bool)$e['all_day'],
            'color'        => $e['color'],
            'status'       => $e['status'],
            'contact_id'   => $e['contact_id'],
            'contact_name' => $e['contact_name'],
            'created_by'   => (int)$e['created_by'],
            'participants' => $part_map[(int)$e['id']] ?? [],
        ];
    }
    foreach ($cases as $c) {
        $colors = ['low'=>'#6B7280','medium'=>'#D97706','high'=>'#DC2626'];
        $out[] = [
            'id'           => 'case_' . $c['id'],
            'raw_id'       => (int)$c['id'],
            'type'         => 'case',
            'event_type'   => 'case',
            'title'        => $c['title'],
            'description'  => null,
            'date'         => $c['event_date'],
            'time'         => null,
            'all_day'      => true,
            'color'        => $colors[$c['priority']] ?? '#6B7280',
            'status'       => $c['status'],
            'contact_name' => $c['contact_name'],
            'link'         => APP_URL . '/crm/cases/view.php?id=' . $c['id'],
        ];
    }
    foreach ($comms as $c) {
        $ch_colors = ['email'=>'#0176D3','sms'=>'#D97706','telefon'=>'#2E844A','osobisty'=>'#7C3AED'];
        $out[] = [
            'id'           => 'comm_' . $c['id'],
            'raw_id'       => (int)$c['id'],
            'type'         => 'comm',
            'event_type'   => $c['channel'],
            'title'        => $c['subject'] ?: ('Komunikacja: ' . $c['channel']),
            'description'  => null,
            'date'         => $c['event_date'],
            'time'         => null,
            'all_day'      => true,
            'color'        => $ch_colors[$c['channel']] ?? '#9CA3AF',
            'status'       => 'done',
            'contact_name' => $c['contact_name'],
        ];
    }

    api_ok($out);
}

// ── POST: operacje na zdarzeniach ─────────────────────────────────────────────
if ($method !== 'POST') api_err('Metoda nieobsługiwana.', 405);
if (!$can_write)        api_err('Brak uprawnień.', 403);

$body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $body['action'] ?? $action;

if ($action === 'create') {
    $title = trim($body['title'] ?? '');
    $date  = $body['date']  ?? '';
    if (!$title)                        api_err('Tytuł jest wymagany.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) api_err('Nieprawidłowa data.');

    $etype   = in_array($body['event_type']??'', ['task','meeting','deadline','reminder','call']) ? $body['event_type'] : 'task';
    $all_day = !empty($body['all_day']);
    $colors  = ['task'=>'#2E844A','meeting'=>'#0176D3','deadline'=>'#DC2626','reminder'=>'#D97706','call'=>'#7C3AED'];

    $id = db_insert('crm_events', [
        'contact_id'     => ($body['contact_id'] ?? 0) ? (int)$body['contact_id'] : null,
        'title'          => $title,
        'description'    => trim($body['description'] ?? '') ?: null,
        'event_date'     => $date,
        'event_time'     => (!$all_day && !empty($body['event_time'])) ? $body['event_time'] : null,
        'event_end_date' => !empty($body['event_end_date']) ? $body['event_end_date'] : null,
        'event_end_time' => (!$all_day && !empty($body['event_end_time'])) ? $body['event_end_time'] : null,
        'all_day'        => $all_day ? 1 : 0,
        'event_type'     => $etype,
        'color'          => $body['color'] ?? ($colors[$etype] ?? '#2E844A'),
        'status'         => 'pending',
        'created_by'     => $uid,
        'created_at'     => date('Y-m-d H:i:s'),
        'updated_at'     => date('Y-m-d H:i:s'),
    ]);
    if (isset($body['participants']) && is_array($body['participants'])) {
        event_set_participants($id, $body['participants'], $uid);
    }
    api_ok(['id' => $id, 'participants' => event_participants($id)]);
}

if ($action === 'update') {
    $eid   = (int)($body['id'] ?? 0);
    $title = trim($body['title'] ?? '');
    $date  = $body['date'] ?? '';
    if (!$eid || !$title || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) api_err('Nieprawidłowe dane.');
    $event = db_one("SELECT * FROM crm_events WHERE id=?", [$eid]);
    if (!$event) api_err('Zdarzenie nie istnieje.', 404);
    if ($event['created_by'] != $uid && !is_admin()) api_err('Brak uprawnień.', 403);
    $all_day = !empty($body['all_day']);
    db()->prepare(
        "UPDATE crm_events SET title=?, description=?, event_date=?, event_time=?,
         event_end_date=?, event_end_time=?, all_day=?, event_type=?, color=?,
         contact_id=?, updated_at=? WHERE id=?"
    )->execute([
        $title,
        trim($body['description'] ?? '') ?: null,
        $date,
        (!$all_day && !empty($body['event_time'])) ? $body['event_time'] : null,
        !empty($body['event_end_date']) ? $body['event_end_date'] : null,
        (!$all_day && !empty($body['event_end_time'])) ? $body['event_end_time'] : null,
        $all_day ? 1 : 0,
        in_array($body['event_type']??'', ['task','meeting','deadline','reminder','call']) ? $body['event_type'] : $event['event_type'],
        $body['color'] ?? $event['color'],
        ($body['contact_id'] ?? 0) ? (int)$body['contact_id'] : null,
        date('Y-m-d H:i:s'),
        $eid,
    ]);
    if (isset($body['participants']) && is_array($body['participants'])) {
        event_set_participants($eid, $body['participants'], $uid);
    }
    api_ok(['participants' => event_participants($eid)]);
}

if ($action === 'done') {
    $eid = (int)($body['id'] ?? 0);
    $event = db_one("SELECT * FROM crm_events WHERE id=?", [$eid]);
    if (!$event) api_err('Nie znaleziono.', 404);
    if ($event['created_by'] != $uid && !is_admin()) api_err('Brak uprawnień.', 403);
    $new_status = $event['status'] === 'done' ? 'pending' : 'done';
    db()->prepare("UPDATE crm_events SET status=?, updated_at=? WHERE id=?")
        ->execute([$new_status, date('Y-m-d H:i:s'), $eid]);
    api_ok(['status' => $new_status]);
}

if ($action === 'delete') {
    $eid = (int)($body['id'] ?? 0);
    $event = db_one("SELECT * FROM crm_events WHERE id=?", [$eid]);
    if (!$event) api_err('Nie znaleziono.', 404);
    if ($event['created_by'] != $uid && !is_admin()) api_err('Brak uprawnień.', 403);
    db()->prepare("DELETE FROM crm_events WHERE id=?")->execute([$eid]);
    api_ok();
}

api_err('Nieznana akcja.');
