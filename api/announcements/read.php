<?php
/**
 * AJAX: Oznacz ogłoszenie jako przeczytane przez bieżącego użytkownika.
 * POST {id: N}
 * Response: {ok: true}
 *
 * Uwierzytelnianie sesją (analogicznie do api/notifications/mark_read.php).
 */
define('SKIP_CONSENT_CHECK', true);
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

$_user = current_user();
if (!$_user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

notif_migrate();
ann_mark_read((int)$data['id'], (int)$_user['id']);

echo json_encode(['ok' => true]);
