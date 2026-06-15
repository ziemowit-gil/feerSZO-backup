<?php
/**
 * saml/slo.php — endpoint Single Logout (IdP).
 *
 * Obsługuje:
 *   - SP-initiated SLO: LogoutRequest (HTTP-Redirect/POST) od Service Providera
 *     → lokalne wylogowanie w SZO + odesłanie LogoutResponse do SP.
 *   - LogoutResponse od SP (po IdP-initiated) → strona potwierdzenia.
 *   - IdP-initiated: GET ?action=init → lokalne wylogowanie z SZO.
 *
 * Uwaga: front-channel kaskadowe wylogowanie ze wszystkich SP jednocześnie nie
 * jest realizowane — terminujemy sesję SZO (krytyczne dla bezpieczeństwa) oraz
 * odpowiadamy SP, który zainicjował wylogowanie.
 *
 * URL: <APP_URL>/saml/slo.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/saml_idp.php';

function slo_page(string $title, string $msg): never {
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($title, ENT_QUOTES);
    $m = htmlspecialchars($msg, ENT_QUOTES);
    $home = htmlspecialchars(rtrim(APP_URL, '/'), ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>{$t}</title>
<style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#1e293b;display:flex;
align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:2rem 2.4rem;max-width:520px;
box-shadow:0 8px 30px rgba(0,0,0,.06)}h1{font-size:1.2rem;margin:0 0 .6rem}a{color:#2563eb}</style></head>
<body><div class="box"><h1>{$t}</h1><p>{$m}</p>
<p style="margin-top:1rem"><a href="{$home}">← {$home}</a></p></div></body></html>
HTML;
    exit;
}

if (!saml_idp_enabled()) slo_page('SAML IdP wyłączony', 'Funkcja jest nieaktywna.');

auth_start();

// Usuń mapowania aktywnych sesji SSO dla bieżącej sesji PHP.
function slo_clear_local_sessions(): void {
    try {
        $sid = session_id() ?: '';
        if ($sid !== '') db()->prepare("DELETE FROM saml_active_sessions WHERE php_session_id=?")->execute([$sid]);
    } catch (\Throwable $e) { error_log('[saml] slo_clear: ' . $e->getMessage()); }
}

// ── IdP-initiated: wyloguj lokalnie z SZO ────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'init') {
    slo_clear_local_sessions();
    if (current_user()) logout_user();
    saml_log(['event' => 'slo', 'result' => 'ok', 'detail' => 'IdP-initiated logout']);
    slo_page('Wylogowano', 'Sesja SZO została zakończona.');
}

// ── LogoutResponse od SP (zakończenie cyklu) ─────────────────────────────────────
if (isset($_GET['SAMLResponse']) || (isset($_POST['SAMLResponse']))) {
    slo_clear_local_sessions();
    if (current_user()) logout_user();
    saml_log(['event' => 'slo', 'result' => 'ok', 'detail' => 'Otrzymano LogoutResponse']);
    slo_page('Wylogowano', 'Wylogowanie zakończone.');
}

// ── SP-initiated: LogoutRequest ──────────────────────────────────────────────────
$binding = '';
$samlRequest = '';
$relayState = '';
$rawQuery = $_SERVER['QUERY_STRING'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['SAMLRequest'])) {
    $samlRequest = (string)$_POST['SAMLRequest']; $relayState = (string)($_POST['RelayState'] ?? ''); $binding = 'post';
} elseif (isset($_GET['SAMLRequest'])) {
    $samlRequest = (string)$_GET['SAMLRequest']; $relayState = (string)($_GET['RelayState'] ?? ''); $binding = 'redirect';
} else {
    // Brak żądania — potraktuj jako prośbę o lokalne wylogowanie.
    slo_clear_local_sessions();
    if (current_user()) logout_user();
    slo_page('Wylogowano', 'Sesja SZO została zakończona.');
}

$xml = saml_decode_message($samlRequest, $binding === 'redirect');
if ($xml === null) slo_page('Błąd', 'Nie udało się zdekodować LogoutRequest.');

$lr = saml_parse_logout_request($xml);
if (!$lr) slo_page('Błąd', 'Nieprawidłowy LogoutRequest.');

$sp = $lr['issuer'] !== '' ? saml_sp_by_entity($lr['issuer']) : null;

// Weryfikacja podpisu, jeśli SP tego wymaga.
if ($sp && (int)$sp['want_signed_req'] === 1) {
    $sigOk = $binding === 'redirect'
        ? saml_verify_redirect_signature($sp, $rawQuery)
        : saml_verify_xml_signature($xml, (string)$sp['sp_cert']);
    if (!$sigOk) {
        saml_log(['sp_id' => (int)$sp['id'], 'sp_entity' => $sp['entity_id'], 'event' => 'slo',
                  'result' => 'error', 'detail' => 'Nieprawidłowy podpis LogoutRequest']);
        slo_page('Błąd podpisu', 'Podpis żądania wylogowania jest nieprawidłowy.');
    }
}

// Lokalne wylogowanie w SZO.
slo_clear_local_sessions();
if (current_user()) logout_user();

saml_log(['sp_id' => $sp ? (int)$sp['id'] : null, 'sp_entity' => $lr['issuer'], 'name_id' => $lr['name_id'],
          'session_index' => $lr['session_index'], 'event' => 'slo', 'result' => 'ok',
          'detail' => 'SP-initiated logout']);

// Odeślij LogoutResponse do SP.
if ($sp && trim((string)$sp['slo_url']) !== '') {
    $respXml = saml_build_logout_response($lr['id'], (string)$sp['slo_url']);
    $msg = saml_encode_redirect($respXml);
    $query = saml_redirect_sign('SAMLResponse', $msg, $relayState);
    header('Location: ' . rtrim($sp['slo_url'], '?') . '?' . $query);
    exit;
}

slo_page('Wylogowano', 'Sesja SZO została zakończona.');
