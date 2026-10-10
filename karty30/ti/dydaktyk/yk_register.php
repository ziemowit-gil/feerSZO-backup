<?php
/**
 * karty30/ti/dydaktyk/yk_register.php — rejestracja klucza sprzętowego (YubiKey, WebAuthn) dla prowadzącego.
 * Alternatywa dla certyfikatu .p12: ten sam klucz służy do zatwierdzania protokołów (yk_protocol.php).
 *   GET  ?action=begin     → opcje dla navigator.credentials.create()
 *   POST ?action=complete  (JSON: odpowiedź z przeglądarki) → zapisuje klucz
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/webauthn.php';

$me   = dyd_require();
$uid  = (int)$me['user_id'];
$name = (string)($me['name'] ?? ('Prowadzący #' . $uid));
karty30_migrate();
webauthn_migrate();

header('Content-Type: application/json; charset=utf-8');
function yr_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

if ($action === 'begin' && $method === 'GET') {
    yr_out(['data' => webauthn_begin_register($uid, $name, $name)]);
}

if ($action === 'complete' && $method === 'POST') {
    dyd_token_check();
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) yr_out(['error' => 'Nieprawidłowe dane.'], 422);
    try {
        // Klucz zapisany pod uid zalogowanego prowadzącego (sesja rejestracji ustawia uid).
        webauthn_complete_register($in, 'YubiKey ' . date('d.m.Y'));
        yr_out(['data' => ['ok' => true]]);
    } catch (\Throwable $e) {
        yr_out(['error' => $e->getMessage()], 422);
    }
}

yr_out(['error' => 'Nieznana akcja.'], 404);
