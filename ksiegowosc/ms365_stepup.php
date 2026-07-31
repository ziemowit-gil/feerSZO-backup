<?php
/**
 * ksiegowosc/ms365_stepup.php — Inicjuje weryfikację tożsamości przez Microsoft 365
 * przed opisaniem dokumentu (KDOK), bez pełnego logowania.
 *
 *   ?return_to=URL   — strona powrotu po weryfikacji (musi być w obrębie APP_URL)
 *   ?bypass=1        — tryb bypass: użytkownik MA klucz WebAuthn, ale go nie ma przy sobie;
 *                      po MS365 wymagany będzie dodatkowo kod IKA w modalu
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

if (!ms_login_available()) {
    flash_set('error', 'Logowanie przez Microsoft 365 jest niedostępne — skontaktuj się z administratorem.');
    header('Location: ' . APP_URL . '/ksiegowosc/');
    exit;
}

$user = current_user();
if (empty($user['microsoft_id'])) {
    flash_set('error', 'Twoje konto nie ma powiązanego konta Microsoft 365.');
    header('Location: ' . APP_URL . '/ksiegowosc/');
    exit;
}

// Sanitacja return_to — tylko w obrębie tej samej domeny
$return_to = trim($_GET['return_to'] ?? '');
$app_host  = parse_url(APP_URL, PHP_URL_HOST) ?? '';
$ret_host  = parse_url($return_to, PHP_URL_HOST) ?? '';
if ($ret_host && $ret_host !== $app_host) $return_to = '';
if (!$return_to) $return_to = APP_URL . '/ksiegowosc/';

$is_bypass = !empty($_GET['bypass']); // bypass WebAuthn kluczem (klucz niedostępny)

// Zapisz kontekst step-up — callback (auth/microsoft.php) je odczyta
$_SESSION['kdok_ms365_step_up']   = (int)$user['id'];
$_SESSION['kdok_ms365_return_to'] = $return_to;
$_SESSION['kdok_ms365_bypass']    = $is_bypass;

// ms_auth_url() ustawia PKCE + state w sesji i bazie; redirect_uri = /auth/microsoft.php
$url = ms_auth_url('__kdok_ms365__');
header('Location: ' . $url);
exit;
