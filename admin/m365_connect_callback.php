<?php
/**
 * Krok 2: Odbiór kodu OAuth z Microsoft → wymiana na token (PKCE, bez client_secret)
 * → autodetekcja tenant ID, domeny, licencji, użytkowników → redirect do m365_settings.php
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

// KLUCZOWA POPRAWKA: Ustawienie ciasteczek sesji przed jakimkolwiek sprawdzeniem roli lub startem sesji.
// Zapobiega to gubieniu sesji przy powrocie z domeny login.microsoftonline.com
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '1'); // Microsoft wymaga HTTPS, ciasteczko też powinno być secure
    ini_set('session.cookie_httponly', '1');
}

auth_start();
require_role('admin');

$error = '';

// Błąd zwrócony przez Microsoft
if (!empty($_GET['error'])) {
    $desc  = $_GET['error_description'] ?? $_GET['error'];
    $error = 'Microsoft zwrócił błąd: ' . h($desc);
}

// Walidacja state (CSRF dla OAuth)
if (!$error) {
    $expected = $_SESSION['m365_pkce_state'] ?? '';
    $current_state = $_GET['state'] ?? '';

    if (empty($current_state) || $current_state !== $expected) {
        $error = 'Nieprawidłowy parametr state — możliwe nadużycie CSRF. Zacznij połączenie od nowa.';
        
        // LOGOWANIE BŁĘDU DO PLIKU (ułatwia debugowanie w przypadku problemów z sesją)
        $log_message = sprintf(
            "[%s] M365 Connect State Error. Otrzymany z MS: '%s' | Oczekiwany w sesji: '%s' | ID Sesji: %s\n",
            date('Y-m-d H:i:s'),
            $current_state,
            $expected,
            session_id()
        );
        @file_put_contents(dirname(__DIR__) . '/m365_conn_error.log', $log_message, FILE_APPEND);
    }
}

$code      = trim($_GET['code'] ?? '');
$verifier  = $_SESSION['m365_pkce_verifier']  ?? '';
$client_id = $_SESSION['m365_pkce_client_id'] ?? m365_setting('m365_graph_client_id');

if (!$error && !$code)     $error = 'Brak kodu autoryzacyjnego w odpowiedzi Microsoft.';
if (!$error && !$verifier) $error = 'Brak code_verifier w sesji — sesja mogła wygasnąć.';

// ── Wymień kod na token (PKCE — bez client_secret) ───────────────────────────
$token_resp = [];
if (!$error) {
    $redirect_uri = APP_URL . '/admin/m365_connect_callback.php';
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
    ]]);
    $body = @file_get_contents('https://login.microsoftonline.com/common/oauth2/v2.0/token', false, $ctx);
    $token_resp = json_decode($body ?: '{}', true) ?? [];

    if (empty($token_resp['access_token'])) {
        $err_desc = $token_resp['error_description'] ?? $token_resp['error'] ?? 'nieznany błąd';
        $error = 'Błąd wymiany kodu na token: ' . h($err_desc);
    }
}

// ── Wyodrębnij Tenant ID z id_token (JWT) ─────────────────────────────────────
$tenant_id = '';
if (!$error) {
    // id_token zawiera tid (tenant ID) w payloadzie JWT
    $claims    = M365Graph::decode_jwt($token_resp['id_token'] ?? '');
    $tenant_id = $claims['tid'] ?? '';

    // Fallback: spróbuj z access_token
    if (!$tenant_id) {
        $claims    = M365Graph::decode_jwt($token_resp['access_token']);
        $tenant_id = $claims['tid'] ?? '';
    }

    if (!$tenant_id) {
        $error = 'Nie udało się odczytać Tenant ID z tokenu. Spróbuj ponownie.';
    }
}

// ── Autodetekcja przez Graph API ──────────────────────────────────────────────
if (!$error) {
    // Zapisz Tenant ID i Client ID do bazy
    m365_save_setting('m365_tenant_id',      $tenant_id);
    m365_save_setting('m365_graph_client_id', $client_id);

    // Utwórz instancję z delegowanym tokenem
    $graph = new M365Graph([
        'tenant_id'    => $tenant_id,
        'client_id'    => $client_id,
        'access_token' => $token_resp['access_token'],
        'expires_in'   => $token_resp['expires_in'] ?? 3600,
    ]);

    $conn  = $graph->test_connection();
    $skus  = $conn['ok'] ? array_values($graph->get_subscribed_skus()) : [];
    $users = $conn['ok'] ? $graph->get_users(200) : [];

    // Wybierz pierwszą domenę spoza *.onmicrosoft.com
    $domains = $conn['domains'] ?? [];
    $domain  = '';
    foreach ($domains as $d) {
        if (!str_ends_with(strtolower($d), '.onmicrosoft.com')) { $domain = $d; break; }
    }
    if (!$domain && $domains) $domain = $domains[0];
    if ($domain) m365_save_setting('m365_domain', $domain);

    // Zachowaj dane autodetekcji w sesji dla m365_settings.php
    $_SESSION['m365_autodetect'] = [
        'ok'        => $conn['ok'],
        'tenant_id' => $tenant_id,
        'org_name'  => $conn['org_name'] ?? '',
        'domains'   => $domains,
        'domain'    => $domain,
        'skus'      => $skus,
        'users'     => $users,
        'error'     => $conn['error'] ?? '',
    ];

    // Wyczyść dane PKCE z sesji
    unset($_SESSION['m365_pkce_verifier'], $_SESSION['m365_pkce_state'], $_SESSION['m365_pkce_client_id']);

    $msg = $conn['ok']
        ? 'Połączono z Microsoft 365! Tenant ID i dane organizacji wykryte automatycznie.'
        : 'Zalogowano, ale nie udało się pobrać danych Graph API: ' . ($conn['error'] ?? '');
    flash_set($conn['ok'] ? 'success' : 'warning', $msg);

    header('Location: ' . APP_URL . '/admin/m365_settings.php');
    exit;
}

// ── Strona błędu ──────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Błąd połączenia z Microsoft 365';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="m365_connect.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0 text-danger"><i class="bi bi-microsoft"></i> Błąd połączenia z Microsoft 365</h4>
</div>

<div class="row g-3">
<div class="col-lg-6">
<div class="card shadow-sm border-danger">
<div class="card-header fw-semibold text-danger"><i class="bi bi-x-circle"></i> Nie udało się połączyć</div>
<div class="card-body">
  <div class="alert alert-danger"><?= $error ?></div>
  <p class="small text-muted mb-2">Możliwe przyczyny:</p>
  <ul class="small text-muted mb-3">
    <li>Nieprawidłowy Client ID aplikacji Azure</li>
    <li>Redirect URI w Azure nie pasuje do: <code><?= h(APP_URL . '/admin/m365_connect_callback.php') ?></code></li>
    <li>Brak uprawnień lub nie kliknięto <em>Grant admin consent</em></li>
    <li>Sesja wygasła — spróbuj ponownie od początku</li>
  </ul>
  <a href="m365_connect.php" class="btn btn-primary">
    <i class="bi bi-arrow-repeat"></i> Zacznij od nowa
  </a>
</div>
</div>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>