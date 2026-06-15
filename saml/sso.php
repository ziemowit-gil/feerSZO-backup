<?php
/**
 * saml/sso.php — endpoint Single Sign-On (IdP).
 *
 * Obsługuje:
 *   - SP-initiated SSO: AuthnRequest na wiązaniu HTTP-Redirect (GET) i HTTP-POST.
 *   - IdP-initiated SSO: GET ?sp=<id|entityID>  (start z poziomu SZO).
 *
 * Wydaje podpisaną asercję SAML i wysyła ją do ACS Service Providera (HTTP-POST).
 *
 * URL: <APP_URL>/saml/sso.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/saml_idp.php';

// ── Strona błędu SAML ────────────────────────────────────────────────────────
function saml_fail(string $title, string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($title, ENT_QUOTES);
    $m = htmlspecialchars($msg, ENT_QUOTES);
    $home = htmlspecialchars(rtrim(APP_URL, '/'), ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>{$t}</title>
<style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#1e293b;display:flex;
align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:2rem 2.4rem;max-width:520px;
box-shadow:0 8px 30px rgba(0,0,0,.06)}h1{font-size:1.2rem;margin:0 0 .6rem;color:#b91c1c}
p{margin:.3rem 0;line-height:1.5}a{color:#2563eb}</style></head>
<body><div class="box"><h1>⚠ {$t}</h1><p>{$m}</p>
<p style="margin-top:1rem"><a href="{$home}">← Powrót do {$home}</a></p></div></body></html>
HTML;
    exit;
}

if (!saml_idp_enabled())   saml_fail('SAML IdP wyłączony', 'Logowanie SSO jest obecnie nieaktywne.', 403);
if (!saml_idp_has_cert())  saml_fail('Brak konfiguracji', 'IdP nie ma certyfikatu podpisującego. Skontaktuj się z administratorem.', 503);

auth_start();

// ── 1. Wyodrębnij parametry żądania ────────────────────────────────────────────
$samlRequest = '';
$relayState  = '';
$binding     = '';   // 'redirect' | 'post' | 'idp'
$rawQuery    = $_SERVER['QUERY_STRING'] ?? '';

// Wznowienie po zalogowaniu (żądanie POST zachowane w sesji).
if (isset($_GET['saml_resume']) && !empty($_SESSION['saml_pending'])) {
    $p = $_SESSION['saml_pending'];
    unset($_SESSION['saml_pending']);
    $samlRequest = (string)($p['SAMLRequest'] ?? '');
    $relayState  = (string)($p['RelayState'] ?? '');
    $binding     = 'post';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['SAMLRequest'])) {
    $samlRequest = (string)$_POST['SAMLRequest'];
    $relayState  = (string)($_POST['RelayState'] ?? '');
    $binding     = 'post';
} elseif (isset($_GET['SAMLRequest'])) {
    $samlRequest = (string)$_GET['SAMLRequest'];
    $relayState  = (string)($_GET['RelayState'] ?? '');
    $binding     = 'redirect';
} elseif (isset($_GET['sp'])) {
    $binding     = 'idp';
    $relayState  = (string)($_GET['RelayState'] ?? '');
} else {
    saml_fail('Nieprawidłowe żądanie', 'Brak parametru SAMLRequest. Ten endpoint przyjmuje żądania logowania SAML.', 400);
}

// ── 2. Bramka logowania ─────────────────────────────────────────────────────────
// Nie używamy require_login() — pomijamy ograniczenia ścieżek crm_only,
// aby SSO działało dla wszystkich aktywnych kont.
if (!current_user()) {
    if ($binding === 'post') {
        $_SESSION['saml_pending'] = ['SAMLRequest' => $samlRequest, 'RelayState' => $relayState];
        $return = rtrim(APP_URL, '/') . '/saml/sso.php?saml_resume=1';
    } else {
        $return = rtrim(APP_URL, '/') . '/saml/sso.php' . ($rawQuery !== '' ? '?' . $rawQuery : '');
    }
    header('Location: ' . rtrim(APP_URL, '/') . '/auth/login.php?redirect=' . urlencode($return));
    exit;
}

$sess = current_user();
$user = db_one("SELECT * FROM users WHERE id=?", [(int)$sess['id']]);
if (!$user) saml_fail('Błąd konta', 'Nie znaleziono konta użytkownika.', 403);
if ((int)($user['is_active'] ?? 1) !== 1) saml_fail('Konto nieaktywne', 'To konto jest nieaktywne.', 403);

// ── 3. Zidentyfikuj Service Providera i parametry odpowiedzi ──────────────────────
$inResponseTo = '';
$requestId    = '';

if ($binding === 'idp') {
    $spKey = (string)$_GET['sp'];
    $sp = ctype_digit($spKey) ? saml_sp_by_id((int)$spKey) : saml_sp_by_entity($spKey);
    if (!$sp) saml_fail('Nieznana aplikacja', 'Nie znaleziono zarejestrowanego Service Providera.', 404);
    if ($relayState === '') $relayState = (string)$sp['relay_default'];
} else {
    $xml = saml_decode_message($samlRequest, $binding === 'redirect');
    if ($xml === null) saml_fail('Błąd żądania', 'Nie udało się zdekodować SAMLRequest.', 400);

    $req = saml_parse_authn_request($xml);
    if (!$req || $req['issuer'] === '') saml_fail('Błąd żądania', 'Nieprawidłowy AuthnRequest (brak Issuer).', 400);

    $sp = saml_sp_by_entity($req['issuer']);
    if (!$sp) {
        saml_log(['sp_entity' => $req['issuer'], 'binding' => $binding, 'result' => 'error',
                  'detail' => 'Nieznany Service Provider (Issuer)']);
        saml_fail('Nieznana aplikacja', 'Aplikacja (' . $req['issuer'] . ') nie jest zarejestrowana w SZO jako Service Provider.', 403);
    }
    $requestId = $req['id'];
    $inResponseTo = $req['id'];

    // Weryfikacja podpisu żądania (jeśli wymagana dla tego SP).
    if ((int)$sp['want_signed_req'] === 1) {
        $sigOk = $binding === 'redirect'
            ? saml_verify_redirect_signature($sp, $rawQuery)
            : saml_verify_xml_signature($xml, (string)$sp['sp_cert']);
        if (!$sigOk) {
            saml_log(['sp_id' => (int)$sp['id'], 'sp_entity' => $sp['entity_id'], 'binding' => $binding,
                      'request_id' => $requestId, 'result' => 'error', 'detail' => 'Nieprawidłowy podpis żądania']);
            saml_fail('Błąd podpisu', 'Podpis żądania logowania jest nieprawidłowy lub go brakuje.', 403);
        }
    }
}

if ((int)$sp['is_active'] !== 1) {
    saml_log(['sp_id' => (int)$sp['id'], 'sp_entity' => $sp['entity_id'], 'binding' => $binding,
              'request_id' => $requestId, 'result' => 'denied', 'detail' => 'SP nieaktywny']);
    saml_fail('Aplikacja wyłączona', 'Logowanie do tej aplikacji jest obecnie wyłączone.', 403);
}

// ── 4. Polityka dostępu (role) ───────────────────────────────────────────────────
$allowed = array_filter(array_map('trim', explode(',', (string)$sp['allowed_roles'])));
if ($allowed && !in_array($user['role'], $allowed, true)) {
    saml_log(['sp_id' => (int)$sp['id'], 'sp_entity' => $sp['entity_id'], 'user_id' => (int)$user['id'],
              'user_email' => $user['email'], 'binding' => $binding, 'request_id' => $requestId,
              'result' => 'denied', 'detail' => 'Rola ' . $user['role'] . ' bez dostępu']);
    saml_fail('Brak dostępu', 'Twoje konto (rola: ' . htmlspecialchars($user['role']) . ') nie ma uprawnień do logowania w tej aplikacji.', 403);
}

// ── 5. Zbuduj i wyślij odpowiedź ─────────────────────────────────────────────────
// ACS zawsze z rejestru SP (ochrona przed open-redirect przez podstawiony ACS).
$acsUrl = (string)$sp['acs_url'];
$sessionIndex = null;

try {
    $responseXml = saml_build_response($sp, $user, $inResponseTo, $acsUrl, $sessionIndex);
} catch (\Throwable $e) {
    error_log('[saml] build_response: ' . $e->getMessage());
    saml_log(['sp_id' => (int)$sp['id'], 'sp_entity' => $sp['entity_id'], 'user_id' => (int)$user['id'],
              'user_email' => $user['email'], 'binding' => $binding, 'request_id' => $requestId,
              'result' => 'error', 'detail' => 'Błąd budowy asercji: ' . $e->getMessage()]);
    saml_fail('Błąd serwera', 'Nie udało się wygenerować odpowiedzi SAML.', 500);
}

$nameIdValue = saml_name_id_value($sp, $user);
saml_log([
    'sp_id'         => (int)$sp['id'],
    'sp_entity'     => $sp['entity_id'],
    'user_id'       => (int)$user['id'],
    'user_email'    => $user['email'],
    'name_id'       => $nameIdValue,
    'request_id'    => $requestId,
    'session_index' => (string)$sessionIndex,
    'relay_state'   => $relayState,
    'binding'       => $binding,
    'event'         => 'sso',
    'result'        => 'ok',
]);
saml_record_active_session($sp, $user, $nameIdValue, (string)$sessionIndex);

saml_post_to_acs($acsUrl, base64_encode($responseXml), $relayState);
