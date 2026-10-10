<?php
/**
 * tozsamosc/klucz_login.php — logowanie do Systemu Tożsamości kluczem bezpieczeństwa
 * (WebAuthn: YubiKey, passkey, Windows Hello, Touch ID) — bez hasła.
 *
 *   GET  ?api=begin[&email=…]  → opcje dla navigator.credentials.get().
 *        Z e-mailem: tylko klucze tego konta (działa też YubiKey bez zapisanego loginu).
 *        Bez e-maila: passkey zapisany w urządzeniu (konto wybiera przeglądarka).
 *        Odpowiedź nie zdradza, czy konto istnieje.
 *   POST ?api=complete (JSON: {_csrf, response, redirect}) → loguje, zwraca {ok, redirect}.
 *
 * Te same zasady co przy logowaniu hasłem (auth/login.php): konta nieaktywne, konta
 * wyłącznie panelu TI i konta „tylko Microsoft 365” nie wchodzą tą drogą; wymuszona
 * zmiana hasła działa. Klucz to silny, odporny na phishing czynnik, więc sesja dostaje
 * od razu poziom „klucz sprzętowy” (TZ_LEVEL_HARDWARE) i zwolnienie z IKA jak po
 * bramce WebAuthn (_webauthn_auth) — nie pytamy już o drugi składnik.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';
require_once dirname(__DIR__) . '/includes/approval.php';   // log_auth_action()
require_once dirname(__DIR__) . '/includes/webauthn.php';
require_once dirname(__DIR__) . '/includes/tz_auth.php';

auth_start();
webauthn_migrate();
header('Content-Type: application/json; charset=utf-8');

function klucz_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$api = (string)($_GET['api'] ?? '');

if ($api === 'begin' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = trim((string)($_GET['email'] ?? ''));
    $uid   = null;
    if ($email !== '') {
        if (brute_check($email) !== null) {
            klucz_out(['error' => 'Zbyt wiele nieudanych prób. Spróbuj ponownie za kilka minut.'], 429);
        }
        $u = db_one("SELECT id FROM users WHERE email = ? AND is_active = 1", [$email]);
        if ($u && webauthn_user_has_keys((int)$u['id'])) $uid = (int)$u['id'];
        // Nieznany e-mail albo konto bez klucza: zwykłe opcje bez listy kluczy (passkey z urządzenia).
    }
    $opts = webauthn_begin_auth($uid);
    $opts['userVerification'] = 'preferred';
    klucz_out(['data' => $opts]);
}

if ($api === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) klucz_out(['error' => 'Brak danych klucza.'], 422);
    // Własne sprawdzenie tokenu: csrf_check() odpowiada zwykłym tekstem, a tu musi być JSON.
    if (!hash_equals(csrf_token(), (string)($body['_csrf'] ?? ''))) {
        klucz_out(['error' => 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.'], 403);
    }

    try {
        $uid = webauthn_complete_auth((array)($body['response'] ?? []));
    } catch (\Throwable $e) {
        authlog_write(null, 'login_fail', '', 'Nieudane logowanie kluczem (Tożsamość): ' . $e->getMessage());
        klucz_out(['error' => 'Nie rozpoznaliśmy tego klucza. Użyj klucza dodanego do Twojego konta albo zaloguj się hasłem.'], 400);
    }
    unset($_SESSION['webauthn_auth_challenge']);   // jednorazowe wyzwanie

    $user = db_one("SELECT * FROM users WHERE id = ? AND is_active = 1", [$uid]);
    if (!$user) klucz_out(['error' => 'Konto jest nieaktywne.'], 403);
    if (account_is_ti_panel_only($user)) {
        authlog_write($uid, 'login_blocked_ti_panel', $user['email'] ?? '', 'Konto panelu dydaktyka TI — logowanie kluczem w Tożsamości zablokowane');
        klucz_out(['error' => 'To konto działa wyłącznie w panelu dydaktyka TI.'], 403);
    }
    if (account_is_office_only($user) && empty($user['allow_local_fallback'])) {
        authlog_write($uid, 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365 (klucz)');
        klucz_out(['error' => 'Konto służbowe @feer.org.pl loguje się przez Microsoft 365. Użyj przycisku „Zaloguj przez Microsoft 365”.'], 403);
    }

    brute_clear((string)($user['email'] ?? ''));
    log_auth_action($uid, 'login_webauthn', 'Logowanie kluczem bezpieczeństwa (Tożsamość)');
    authlog_write($uid, 'login_webauthn', $user['email'] ?? '', 'Logowanie kluczem bezpieczeństwa (Tożsamość)');
    login_user($user);
    $_SESSION['_webauthn_auth'] = true;          // jak po bramce WebAuthn — zwalnia z IKA
    tz_grant_level(TZ_LEVEL_HARDWARE);           // poziom „klucz sprzętowy” w Tożsamości

    if (auth_must_change_password($user)) {
        flash_set('warning', 'Administrator zresetował Twoje hasło. Ustaw nowe przed kontynuowaniem.');
        klucz_out(['ok' => true, 'redirect' => APP_URL . '/panel/password.php?force=1']);
    }
    $redirect = (string)($body['redirect'] ?? '');
    if (!str_starts_with($redirect, APP_URL . '/')) $redirect = APP_URL . '/tozsamosc/index.php';
    klucz_out(['ok' => true, 'redirect' => auth_login_landing($user, $redirect)]);
}

klucz_out(['error' => 'Nieznana operacja.'], 404);
