<?php
/**
 * admin/users_ajax.php — AJAX endpointy dla strony zarządzania użytkownikami
 *
 * GET ?action=delete_impact&uid=N → JSON {ok, data: {cascade, set_null, contracts}}
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/user_delete.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

if (!current_user() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Brak dostępu.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'delete_impact') {
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $impact = user_delete_impact($uid);
    echo json_encode(['ok' => true, 'data' => $impact]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => 'Nieznana akcja.']);
