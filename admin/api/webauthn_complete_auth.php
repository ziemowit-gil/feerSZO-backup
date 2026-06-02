<?php
/**
 * POST JSON: complete WebAuthn login
 */
header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/webauthn.php';

auth_start();

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
    $response = $body['response'] ?? [];
    webauthn_migrate();
    $user_id = webauthn_complete_auth($response);

    // Load user
    $user = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$user_id]);
    if (!$user) {
        throw new \RuntimeException('Użytkownik nie istnieje lub jest nieaktywny');
    }

    $pending = (int)$_SESSION['webauthn_pending_uid'];
    if ($pending !== $user_id) {
        throw new \RuntimeException('WebAuthn: niezgodność użytkownika');
    }

    unset($_SESSION['webauthn_pending_uid']);
    login_user($user);

    // Check must_change_password
    if (function_exists('auth_must_change_password') && auth_must_change_password($user)) {
        flash_set('warning', 'Administrator zresetował Twoje hasło. Ustaw nowe przed kontynuowaniem.');
        echo json_encode(['ok' => true, 'redirect' => APP_URL . '/panel/password.php?force=1']);
        exit;
    }

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
