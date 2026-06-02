<?php
/**
 * crm/api/activities.php — CRUD JSON API dla planowanych działań.
 *
 * POST action=create   → nowe działanie
 * POST action=complete → oznacz jako wykonane (z outcome)
 * POST action=delete   → usuń
 * POST action=reopen   → przywróć do planned
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

function api_ok(mixed $d = null): never { echo json_encode(['ok'=>true,'data'=>$d],JSON_UNESCAPED_UNICODE); exit; }
function api_err(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m],JSON_UNESCAPED_UNICODE); exit; }

if (!current_user()) api_err('Wymagane logowanie.', 401);
crm_migrate();

$can_write = can_write('crm') || is_admin();
$uid       = (int)(current_user()['id'] ?? 0);

$body   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $body['action'] ?? '';

if ($action === 'create') {
    if (!$can_write) api_err('Brak uprawnień.', 403);
    $cid   = (int)($body['contact_id'] ?? 0);
    $title = trim($body['title'] ?? '');
    $type  = in_array($body['type']??'', ['call','email','meeting','task','demo','lunch','other']) ? $body['type'] : 'task';
    if (!$cid || !$title) api_err('Brak wymaganych danych.');

    // Walidacja daty
    $sched = null;
    if (!empty($body['scheduled_at'])) {
        $sched = date('Y-m-d H:i:s', strtotime($body['scheduled_at']));
    }

    $id = db_insert('crm_activities', [
        'contact_id'  => $cid,
        'type'        => $type,
        'title'       => $title,
        'description' => trim($body['description']??'') ?: null,
        'scheduled_at'=> $sched,
        'duration_min'=> ($body['duration_min']??0) ? (int)$body['duration_min'] : null,
        'status'      => 'planned',
        'assigned_to' => ($body['assigned_to']??0) ? (int)$body['assigned_to'] : $uid,
        'created_by'  => $uid,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);
    api_ok(['id' => $id]);
}

if ($action === 'complete') {
    $aid = (int)($body['id'] ?? 0);
    $act = db_one("SELECT * FROM crm_activities WHERE id=?", [$aid]);
    if (!$act) api_err('Nie znaleziono.', 404);
    if (!$can_write) api_err('Brak uprawnień.', 403);
    db()->prepare(
        "UPDATE crm_activities SET status='done', outcome=?, completed_at=?, updated_at=? WHERE id=?"
    )->execute([trim($body['outcome']??'') ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $aid]);
    api_ok();
}

if ($action === 'reopen') {
    $aid = (int)($body['id'] ?? 0);
    if (!$can_write) api_err('Brak uprawnień.', 403);
    db()->prepare("UPDATE crm_activities SET status='planned',completed_at=NULL,updated_at=? WHERE id=?")
        ->execute([date('Y-m-d H:i:s'), $aid]);
    api_ok();
}

if ($action === 'delete') {
    $aid = (int)($body['id'] ?? 0);
    $act = db_one("SELECT * FROM crm_activities WHERE id=?", [$aid]);
    if (!$act) api_err('Nie znaleziono.', 404);
    if ($act['created_by'] != $uid && !is_admin()) api_err('Brak uprawnień.', 403);
    db()->prepare("DELETE FROM crm_activities WHERE id=?")->execute([$aid]);
    api_ok();
}

api_err('Nieznana akcja.');
