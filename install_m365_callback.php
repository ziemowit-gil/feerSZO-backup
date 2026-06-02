<?php
/**
 * install_m365_callback.php — Callback OAuth M365 dla kreatora instalacji.
 * Nie wymaga zalogowanego admina — działa tylko w trakcie instalacji.
 */
define('INSTALL_MODE', true);
define('BOOTSTRAP_CHECKED', true);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

// Blokada: tylko jeśli mamy aktywną sesję instalacji
if (empty($_SESSION['m365_install_pkce_state'])) {
    http_response_code(403);
    exit('Brak aktywnej sesji instalacji. Wróć do kreatora i zacznij od nowa.');
}

// Załaduj config jeśli istnieje (może jeszcze nie być)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Fallback — APP_URL z bieżącego hosta
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('APP_URL', rtrim($scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']), '/'));
}
require_once __DIR__ . '/includes/db.php';

$error = '';

// Błąd od Microsoft
if (!empty($_GET['error'])) {
    $error = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
}

// Walidacja state
if (!$error) {
    $expected = $_SESSION['m365_install_pkce_state'] ?? '';
    $received = $_GET['state'] ?? '';
    if (empty($received) || $received !== $expected) {
        $error = 'Nieprawidłowy parametr state — zacznij od nowa.';
    }
}

$code      = trim($_GET['code'] ?? '');
$verifier  = $_SESSION['m365_install_pkce_verifier']  ?? '';
$client_id = $_SESSION['m365_install_pkce_client_id'] ?? '';

if (!$error && !$code)     $error = 'Brak kodu autoryzacyjnego.';
if (!$error && !$verifier) $error = 'Brak code_verifier — sesja wygasła.';

// ── Wymień kod na token (PKCE bez client_secret) ─────────────────────────────
$redirect_uri = APP_URL . '/install_m365_callback.php';

if (!$error) {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query([
            'client_id'     => $client_id,
            'code'          => $code,
            'redirect_uri'  => $redirect_uri,
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,
        ]),
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

    $body       = @file_get_contents('https://login.microsoftonline.com/common/oauth2/v2.0/token', false, $ctx);
    $token_resp = json_decode($body ?: '{}', true) ?? [];

    if (empty($token_resp['access_token'])) {
        $error = 'Błąd tokenu: ' . htmlspecialchars($token_resp['error_description'] ?? $token_resp['error'] ?? 'nieznany');
    }
}

// ── Autodetekcja przez Graph API ─────────────────────────────────────────────
if (!$error) {
    $access_token = $token_resp['access_token'];

    // Dekoduj JWT żeby wyciągnąć tenant_id
    $parts     = explode('.', $access_token);
    $payload   = json_decode(base64_decode(str_pad(strtr($parts[1] ?? '', '-_', '+/'), strlen($parts[1] ?? '') % 4, '=', STR_PAD_RIGHT)), true) ?? [];
    $tenant_id = $payload['tid'] ?? '';

    if (!$tenant_id) { $error = 'Nie udało się wykryć Tenant ID z tokenu.'; }
}

if (!$error) {
    // Pobierz dane organizacji z Graph API
    $graph_ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer {$access_token}\r\nContent-Type: application/json\r\n",
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

    $org_raw  = @file_get_contents('https://graph.microsoft.com/v1.0/organization', false, $graph_ctx);
    $org_data = json_decode($org_raw ?: '{}', true)['value'][0] ?? [];
    $org_name = $org_data['displayName'] ?? '';

    $domains_raw = @file_get_contents('https://graph.microsoft.com/v1.0/domains', false, $graph_ctx);
    $domains_all = array_column(json_decode($domains_raw ?: '{}', true)['value'] ?? [], 'id');
    $domain = '';
    foreach ($domains_all as $d) {
        if (!str_ends_with(strtolower($d), '.onmicrosoft.com')) { $domain = $d; break; }
    }
    if (!$domain && $domains_all) $domain = $domains_all[0];

    // Zapisz do sesji instalatora
    $_SESSION['install_ms'] = [
        'enabled'       => true,
        'tenant_id'     => $tenant_id,
        'client_id'     => $client_id,
        'client_secret' => '',        // PKCE — bez client_secret
        'org_name'      => $org_name,
        'domain'        => $domain,
        'autodetected'  => true,
    ];

    // Wyczyść PKCE
    unset($_SESSION['m365_install_pkce_state'], $_SESSION['m365_install_pkce_verifier'], $_SESSION['m365_install_pkce_client_id']);

    header('Location: install.php?step=5&ms_ok=1'); exit;
}

// ── Błąd ─────────────────────────────────────────────────────────────────────
unset($_SESSION['m365_install_pkce_state'], $_SESSION['m365_install_pkce_verifier'], $_SESSION['m365_install_pkce_client_id']);
?><!DOCTYPE html><html lang="pl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Błąd połączenia M365</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f1f5f9}.wrap{max-width:520px;margin:4rem auto;padding:0 1rem}</style>
</head><body><div class="wrap">
  <div class="card shadow-sm p-4">
    <h5 class="text-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i>Błąd połączenia z Microsoft 365</h5>
    <div class="alert alert-danger py-2 small"><?= $error ?></div>
    <a href="install.php?step=4" class="btn btn-primary">← Wróć do kroku 4</a>
  </div>
</div></body></html>
