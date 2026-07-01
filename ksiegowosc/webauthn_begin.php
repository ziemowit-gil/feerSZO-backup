<?php
/**
 * ksiegowosc/webauthn_begin.php — inicjacja weryfikacji kluczem WebAuthn
 * przed opisaniem dokumentu (EOD Dokumentów Księgowych, KDOK).
 */
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';

require_login();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$_POST['_csrf'] = $body['_csrf'] ?? '';
try {
    csrf_check();
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF']);
    exit;
}

try {
    $user = current_user();
    webauthn_migrate();
    if (!webauthn_user_has_keys((int)$user['id'])) {
        throw new \RuntimeException('Brak zarejestrowanego klucza WebAuthn.');
    }
    $options = webauthn_begin_auth((int)$user['id']);
    echo json_encode(['ok' => true, 'options' => $options]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
