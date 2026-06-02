<?php
/**
 * POST JSON: begin WebAuthn registration for current user
 * Returns publicKeyCredentialCreationOptions JSON
 */
header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/webauthn.php';

auth_start();
require_role('admin', 'editor');

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
    $user    = current_user();
    $user_id = (int)$user['id'];
    webauthn_migrate();
    $options = webauthn_begin_register($user_id, $user['email'], $user['name'] ?? $user['email']);
    echo json_encode(['ok' => true, 'options' => $options]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
