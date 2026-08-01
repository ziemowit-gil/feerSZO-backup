<?php
/**
 * POST JSON — begin WebAuthn registration for current user (any role).
 */
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/webauthn.php';

auth_start();

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Wymagane logowanie']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$_POST['_csrf'] = $body['_csrf'] ?? '';
try { csrf_check(); } catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF']);
    exit;
}

try {
    webauthn_migrate();
    $user    = current_user();
    $options = webauthn_begin_register((int)$user['id'], $user['email'], $user['name'] ?? $user['email']);
    echo json_encode(['ok' => true, 'options' => $options]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
