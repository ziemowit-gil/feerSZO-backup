<?php
/**
 * crm/api/bulk.php — Operacje masowe na kontaktach CRM.
 * POST JSON: { action, ids[], ...params }
 * actions: set_status, add_tag, remove_tag, add_to_group, delete
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user())               { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Wymagane logowanie.']); exit; }
if (!can_write('crm') && !is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Brak uprawnień.']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$ids    = array_values(array_filter(array_map('intval', (array)($body['ids'] ?? []))));
$uid    = (int)(current_user()['id'] ?? 0);

if (!$ids)    { echo json_encode(['ok'=>false,'error'=>'Brak wybranych kontaktów.']); exit; }

// CSRF — sprawdź token przesłany w polu _csrf
if (($body['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Błąd CSRF.']); exit;
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$affected = 0;

try {
    if ($action === 'set_status') {
        $status = trim($body['value'] ?? '');
        if (!array_key_exists($status, crm_statuses())) { echo json_encode(['ok'=>false,'error'=>'Nieprawidłowy status.']); exit; }
        $st = db()->prepare("UPDATE crm_contacts SET status=?,updated_at=datetime('now') WHERE id IN ($ph) AND crm_active=1");
        $st->execute(array_merge([$status], $ids));
        $affected = $st->rowCount();

    } elseif ($action === 'add_tag') {
        $tag = trim($body['value'] ?? '');
        if (!$tag) { echo json_encode(['ok'=>false,'error'=>'Podaj tag.']); exit; }
        foreach ($ids as $cid) {
            try {
                db_insert('crm_tags', ['contact_id'=>$cid,'tag'=>$tag,'created_at'=>date('Y-m-d H:i:s')]);
                $affected++;
            } catch (\Throwable $e) {} // UNIQUE — ignoruj duplikat
        }

    } elseif ($action === 'remove_tag') {
        $tag = trim($body['value'] ?? '');
        if (!$tag) { echo json_encode(['ok'=>false,'error'=>'Podaj tag.']); exit; }
        $st = db()->prepare("DELETE FROM crm_tags WHERE contact_id IN ($ph) AND tag=?");
        $st->execute(array_merge($ids, [$tag]));
        $affected = $st->rowCount();

    } elseif ($action === 'add_to_group') {
        $gid = (int)($body['value'] ?? 0);
        if (!$gid) { echo json_encode(['ok'=>false,'error'=>'Wybierz grupę.']); exit; }
        foreach ($ids as $cid) {
            try {
                db_insert('crm_group_members', ['group_id'=>$gid,'contact_id'=>$cid,'added_by'=>$uid,'added_at'=>date('Y-m-d H:i:s')]);
                $affected++;
            } catch (\Throwable $e) {}
        }

    } elseif ($action === 'delete') {
        if (!can_delete('crm') && !is_admin()) { echo json_encode(['ok'=>false,'error'=>'Brak uprawnień do usuwania.']); exit; }
        $st = db()->prepare("UPDATE crm_contacts SET crm_active=0,updated_at=datetime('now') WHERE id IN ($ph)");
        $st->execute($ids);
        $affected = $st->rowCount();

    } else {
        echo json_encode(['ok'=>false,'error'=>"Nieznana akcja: {$action}"]); exit;
    }

    echo json_encode(['ok'=>true,'affected'=>$affected,'action'=>$action]);

} catch (\Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
