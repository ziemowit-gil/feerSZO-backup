<?php
/**
 * POST JSON — begin WebAuthn authentication (step-up) dla zalogowanego użytkownika.
 * Używane przez step_up.php przy egzekwowaniu poziomu 3.
 */
header('Content-Type: application/json');

$_ROOT = dirname(dirname(dirname(__FILE__)));
require_once $_ROOT . '/config.php';
require_once $_ROOT . '/includes/db.php';
require_once $_ROOT . '/includes/auth.php';
require_once $_ROOT . '/includes/functions.php';
require_once $_ROOT . '/includes/webauthn.php';

auth_start();

$user = current_user();
if (!$user) {
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
    $options = webauthn_begin_auth((int)$user['id']);
    // Zapamiętaj uid w sesji — complete_auth musi zweryfikować właściciela
    $_SESSION['tz_wk_pending_uid'] = (int)$user['id'];
    echo json_encode(['ok' => true, 'options' => $options]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
