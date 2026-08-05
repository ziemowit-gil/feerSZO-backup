<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
header('Content-Type: application/json');

if (!current_user())          { http_response_code(401); echo json_encode(['error'=>'Niezalogowany']); exit; }
if (!module_enabled('ezd_enabled')) { http_response_code(403); echo json_encode(['error'=>'Moduł wyłączony']); exit; }
if (!can_read('ezd') && !can_write('ezd')) { http_response_code(403); echo json_encode(['error'=>'Brak dostępu do EZD']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'Method']); exit; }
if (($_POST['_csrf'] ?? '') !== csrf_token()) { http_response_code(403); echo json_encode(['error'=>'CSRF']); exit; }
if (!can_edit()) { http_response_code(403); echo json_encode(['error'=>'Brak uprawnień']); exit; }

$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';  // 'hide' | 'unhide'
if (!$id || !in_array($action, ['hide','unhide'])) { echo json_encode(['error'=>'Nieprawidłowe żądanie']); exit; }

$sprawa = ezd_sprawa_get($id);
if (!$sprawa || !ezd_sprawa_access($sprawa, (int)current_user()['id'])) {
    http_response_code(404); echo json_encode(['error'=>'Brak dostępu']); exit;
}

$uid = (int)current_user()['id'];
$teczka_id = (int)($sprawa['teczka_id'] ?? 0);
if ($action === 'hide') {
    db()->prepare("UPDATE ezd_sprawy SET hidden_at=CURRENT_TIMESTAMP, hidden_by=? WHERE id=?")->execute([$uid, $id]);
    ezd_log($teczka_id, $id, null, null, $uid, 'sprawa_hide', 'Ukryto koszulkę na liście');
} else {
    db()->prepare("UPDATE ezd_sprawy SET hidden_at=NULL, hidden_by=NULL WHERE id=?")->execute([$id]);
    ezd_log($teczka_id, $id, null, null, $uid, 'sprawa_unhide', 'Przywrócono koszulkę na liście');
}

echo json_encode(['ok'=>true, 'hidden'=> ($action === 'hide')]);
