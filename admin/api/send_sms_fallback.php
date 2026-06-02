<?php
/**
 * API: Wysyłka awaryjnego kodu SMS (fallback do CPC).
 *
 * POST JSON {_csrf} lub $_POST
 * Response: application/json
 *
 * Dostęp: zalogowany użytkownik z rolą admin lub editor.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
require_once dirname(dirname(__DIR__)) . '/includes/cpc.php';

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

try {
    $result = cpc_send_sms_fallback((int) $user['id']);

    if ($result['ok']) {
        echo json_encode(['ok' => true, 'message' => 'Kod SMS wysłany na Twój numer telefonu.']);
    } else {
        echo json_encode(['ok' => false, 'message' => $result['error'] ?? 'Nie udało się wysłać kodu SMS.']);
    }

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Błąd serwera.']);
}
