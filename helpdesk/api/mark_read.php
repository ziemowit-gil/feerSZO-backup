<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/helpdesk.php';
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
auth_start();
if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }
require_login();

$u   = current_user();
$uid = (int)$u['id'];
if (!hd_is_operator()) { echo json_encode(['ok'=>false]); exit; }

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
if ($ticket_id < 1) { echo json_encode(['ok'=>false]); exit; }

helpdesk_migrate();
hd_mark_read($ticket_id, $uid);

echo json_encode([
    'ok'           => true,
    'unread_count' => count(hd_unread_ids($uid)),
]);
