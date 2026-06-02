<?php
/**
 * crm/api/group_link.php — Łączenie i rozłączanie grup (parent/child).
 * POST {action: 'link', parent_id, child_id}
 * POST {action: 'unlink', parent_id, child_id}
 * POST {action: 'set_parent', group_id, parent_id}
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Brak sesji']); exit; }
if (!can_write('crm') && !is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Brak uprawnień']); exit; }
crm_migrate();

$body   = json_decode(file_get_contents('php://input'),true) ?? $_POST;
$action = $body['action'] ?? '';

function ok(mixed $d=null): never { echo json_encode(['ok'=>true,'data'=>$d],JSON_UNESCAPED_UNICODE); exit; }
function err(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m],JSON_UNESCAPED_UNICODE); exit; }

if ($action === 'link') {
    $pid = (int)($body['parent_id']??0);
    $cid = (int)($body['child_id'] ??0);
    if (!$pid||!$cid||$pid===$cid) err('Nieprawidłowe ID grup.');
    try {
        db()->prepare("INSERT OR IGNORE INTO crm_group_links (parent_group_id,child_group_id) VALUES (?,?)")
            ->execute([$pid,$cid]);
    } catch (\Throwable $e) { err('Błąd: '.$e->getMessage()); }
    ok();
}
if ($action === 'unlink') {
    $pid = (int)($body['parent_id']??0);
    $cid = (int)($body['child_id'] ??0);
    db()->prepare("DELETE FROM crm_group_links WHERE parent_group_id=? AND child_group_id=?")->execute([$pid,$cid]);
    ok();
}
if ($action === 'set_parent') {
    $gid = (int)($body['group_id'] ??0);
    $pid = (int)($body['parent_id']??0) ?: null;
    if (!$gid) err('Brak ID grupy.');
    if ($pid && $pid===$gid) err('Grupa nie może być swoją własną podgrupą.');
    db()->prepare("UPDATE crm_groups SET parent_id=?, updated_at=? WHERE id=?")
        ->execute([$pid, date('Y-m-d H:i:s'), $gid]);
    ok();
}
if ($action === 'add_tag') {
    $gid = (int)($body['group_id']??0);
    $tag = trim($body['tag']??'');
    if (!$gid||!$tag) err('Brak danych.');
    try {
        db()->prepare("INSERT OR IGNORE INTO crm_group_tags (group_id,tag) VALUES (?,?)")->execute([$gid,$tag]);
    } catch (\Throwable $e) { err($e->getMessage()); }
    ok();
}
if ($action === 'remove_tag') {
    $gid = (int)($body['group_id']??0);
    $tag = trim($body['tag']??'');
    db()->prepare("DELETE FROM crm_group_tags WHERE group_id=? AND tag=?")->execute([$gid,$tag]);
    ok();
}
err('Nieznana akcja.');
