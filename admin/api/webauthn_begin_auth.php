<?php
/**
 * POST JSON: begin WebAuthn authentication (during login, user NOT yet logged in)
 */
header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/webauthn.php';

auth_start();

// User must have a pending WebAuthn UID (set after password check)
if (empty($_SESSION['webauthn_pending_uid'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Brak sesji logowania']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF from JSON body
$_POST['_csrf'] = $body['_csrf'] ?? '';
try {
    csrf_check();
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF']);
    exit;
}

try {
    $pending_uid = (int)$_SESSION['webauthn_pending_uid'];
    webauthn_migrate();
    $options = webauthn_begin_auth($pending_uid);
    echo json_encode(['ok' => true, 'options' => $options]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
