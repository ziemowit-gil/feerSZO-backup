<?php
/**
 * API: Weryfikacja kodu CPC.
 *
 * POST JSON {code, _csrf} lub $_POST
 * Response: application/json
 *
 * Dostęp: zalogowany użytkownik z rolą admin lub editor.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/cpc.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';

header('Content-Type: application/json; charset=utf-8');

// Autoryzacja
$user = current_user();
if (!$user || !in_array($user['role'], ['admin', 'editor'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Brak dostępu.']);
    exit;
}

// Odczyt danych — JSON ma pierwszeństwo przed $_POST, $_POST nadpisuje
$json = json_decode(file_get_contents('php://input'), true) ?? [];
$data = array_merge($json, $_POST);

// Weryfikacja CSRF
$csrf_given   = $data['_csrf'] ?? '';
$csrf_session = $_SESSION['csrf'] ?? '';
if (!hash_equals($csrf_session, $csrf_given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF.']);
    exit;
}

$code = trim($data['code'] ?? '');

try {
    $result = cpc_verify((int) $user['id'], $code);

    if ($result['blocked']) {
        echo json_encode([
            'ok'            => false,
            'blocked'       => true,
            'blocked_until' => $result['blocked_until'],
            'message'       => 'Kod IKA zablokowany na 15 minut.',
        ]);
        exit;
    }

    if ($result['ok']) {
        log_system_action((int) $user['id'], 'cpc_verify_ok', 'Weryfikacja CPC zakończona sukcesem');
        echo json_encode(['ok' => true]);
        exit;
    }

    $fails     = (int) ($result['fails'] ?? 0);
    $remaining = max(0, 3 - $fails);
    echo json_encode([
        'ok'      => false,
        'fails'   => $fails,
        'message' => 'Nieprawidłowy kod IKA. Pozostało ' . $remaining . ' prób.',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Błąd serwera.']);
}
