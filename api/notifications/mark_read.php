<?php
/**
 * AJAX: Oznacz powiadomienie(a) jako przeczytane
 * POST {id: N}       — oznacz jedno
 * POST {all: true}   — oznacz wszystkie
 * Response: {ok: true, unread: N}
 */
define('SKIP_CONSENT_CHECK', true);
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

// Sprawdź czy zalogowany
$_user = current_user();
if (!$_user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Sprawdź metodę i nagłówek AJAX
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$uid = (int)$_user['id'];

notif_migrate();

if (!empty($data['all'])) {
    notif_mark_read($uid);
} elseif (isset($data['id'])) {
    notif_mark_read($uid, (int)$data['id']);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id or all']);
    exit;
}

$unread = notif_unread_count($uid);
echo json_encode(['ok' => true, 'unread' => $unread]);
