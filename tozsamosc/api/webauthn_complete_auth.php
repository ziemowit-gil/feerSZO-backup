<?php
/**
 * POST JSON — complete WebAuthn authentication (step-up).
 * Weryfikuje klucz, nadaje tz_auth_level=3, zwraca URL powrotu.
 */
header('Content-Type: application/json');

$_ROOT = dirname(dirname(dirname(__FILE__)));
require_once $_ROOT . '/config.php';
require_once $_ROOT . '/includes/db.php';
require_once $_ROOT . '/includes/auth.php';
require_once $_ROOT . '/includes/functions.php';
require_once $_ROOT . '/includes/webauthn.php';
require_once $_ROOT . '/includes/tz_auth.php';

auth_start();

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Wymagane logowanie']);
    exit;
}

if (empty($_SESSION['tz_wk_pending_uid'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Brak sesji WebAuthn (zacznij od begin_auth)']);
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
    $verified_uid = webauthn_complete_auth($response);

    // Weryfikacja właściciela klucza
    if ($verified_uid !== (int)$_SESSION['tz_wk_pending_uid'] ||
        $verified_uid !== (int)$user['id']) {
        throw new \RuntimeException('Klucz nie należy do bieżącego konta');
    }

    unset($_SESSION['tz_wk_pending_uid']);

    // Nadaj poziom 3 i zregeneruj sesję
    tz_grant_level(TZ_LEVEL_HARDWARE);

    log_user_action((int)$user['id'], (int)$user['id'],
        'tz_step_up_webauthn', 'Step-up WebAuthn (level 3) zakończony sukcesem');

    // return_url z tz_pending_challenge (jeśli jest)
    $challenge = $_SESSION['tz_pending_challenge'] ?? null;
    $redirect  = $challenge ? tz_validate_return_url($challenge['ret'] ?? '')
                            : APP_URL . '/tozsamosc/index.php';
    unset($_SESSION['tz_pending_challenge']);

    echo json_encode(['ok' => true, 'redirect' => $redirect]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
