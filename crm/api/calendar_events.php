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

if (!current_user()) api_err('Wymagane logowanie.', 401);
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
    api_ok(['id' => $id]);
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
    api_ok();
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
