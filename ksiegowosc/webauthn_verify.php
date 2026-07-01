<?php
/**
 * ksiegowosc/webauthn_verify.php — potwierdzenie weryfikacji kluczem WebAuthn
 * przed opisaniem dokumentu (EOD Dokumentów Księgowych, KDOK).
 */
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';
require_once dirname(__DIR__) . '/includes/ksiegowosc.php';

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
    $user     = current_user();
    $response = $body['response'] ?? [];
    webauthn_migrate();
    $cred_user_id = webauthn_complete_auth($response);
    if ($cred_user_id !== (int)$user['id']) {
        throw new \RuntimeException('Klucz należy do innego użytkownika.');
    }
    kdok_webauthn_mark($cred_user_id);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
