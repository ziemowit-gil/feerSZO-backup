<?php
/**
 * POST JSON: complete WebAuthn registration
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
    $response = $body['response'] ?? [];
    $key_name = trim($body['key_name'] ?? 'Klucz sprzętowy') ?: 'Klucz sprzętowy';
    webauthn_migrate();
    webauthn_complete_register($response, $key_name);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
