<?php
/**
 * POST JSON — complete WebAuthn registration for current user (any role).
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
    $response = $body['response'] ?? [];
    $key_name = trim($body['key_name'] ?? 'Klucz bezpieczeństwa') ?: 'Klucz bezpieczeństwa';
    $cred_id  = webauthn_complete_register($response, $key_name);
    log_user_action((int)current_user()['id'], (int)current_user()['id'], 'webauthn_registered', 'Zarejestrowano klucz WebAuthn: ' . $key_name . ' (Tożsamość)');
    echo json_encode(['ok' => true, 'credential_id' => $cred_id]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
