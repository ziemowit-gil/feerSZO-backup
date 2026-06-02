<?php
/**
 * Krok 1: Admin podaje Client ID → przekierowanie do Microsoft OAuth (PKCE).
 * Nie wymaga Tenant ID ani Client Secret z góry — wszystko auto-wykryte z tokenu.
 * Redirect URI: APP_URL/admin/m365_connect_callback.php (wpisz to w Azure App Registration).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

require_role('admin');
auth_start();

$redirect_uri = APP_URL . '/admin/m365_connect_callback.php';
$saved_client_id = m365_setting('m365_graph_client_id');

// POST: zapisz Client ID i przekieruj do MS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $client_id = trim($_POST['client_id'] ?? '');
    if (!$client_id) {
        $error = 'Podaj Client ID aplikacji Azure.';
    } else {
        m365_save_setting('m365_graph_client_id', $client_id);

        // PKCE — nie wymaga client_secret przy wymianie kodu
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state     = bin2hex(random_bytes(16));

        $_SESSION['m365_pkce_verifier']  = $verifier;
        $_SESSION['m365_pkce_state']     = $state;
        $_SESSION['m365_pkce_client_id'] = $client_id;

        $scopes = implode(' ', [
            'openid', 'email', 'profile', 'offline_access',
            'https://graph.microsoft.com/Organization.Read.All',
            'https://graph.microsoft.com/User.ReadWrite.All',
            'https://graph.microsoft.com/Directory.ReadWrite.All',
            'https://graph.microsoft.com/LicenseAssignment.ReadWrite.All',
            'https://graph.microsoft.com/Mail.Send',
        ]);

        $url = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query([
            'client_id'             => $client_id,
            'response_type'         => 'code',
            'redirect_uri'          => $redirect_uri,
            'scope'                 => $scopes,
            'response_mode'         => 'query',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'consent',  // wymusi ekran zgody admina
        ]);

        header('Location: ' . $url);
        exit;
    }
}

$PAGE_TITLE = 'Połącz z Microsoft 365';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="m365_settings.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-microsoft text-primary"></i> Połącz z Microsoft 365</h4>
</div>

<div class="row g-3">
<div class="col-lg-6">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Krok 1 z 2 — Rejestracja aplikacji w Azure</div>
<div class="card-body">
  <p class="text-muted small mb-3">Przed połączeniem musisz zarejestrować aplikację w Azure Active Directory. Zajmuje to ok. 3 minut.</p>

  <ol class="small mb-3">
    <li class="mb-2">
      Otwórz
      <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank">
        Azure Portal → App registrations <i class="bi bi-box-arrow-up-right"></i>
      </a>
      → <strong>New registration</strong>
    </li>
    <li class="mb-2">
      Wpisz dowolną nazwę (np. <em>Rejestr Umów FEER</em>), typ konta: <em>Single tenant</em>
    </li>
    <li class="mb-2">
      Redirect URI → typ <strong>Web</strong>, adres:<br>
      <code class="user-select-all d-block bg-light border rounded p-2 mt-1"><?= h($redirect_uri) ?></code>
    </li>
    <li class="mb-2">
      Po utworzeniu: <strong>API permissions</strong> → <em>Add a permission</em> → <em>Microsoft Graph</em>
      → <em>Application permissions</em> → dodaj:<br>
      <code>User.ReadWrite.All</code> &nbsp;
      <code>Directory.ReadWrite.All</code> &nbsp;
      <code>LicenseAssignment.ReadWrite.All</code> &nbsp;
      <code>Mail.Send</code> &nbsp;
      <code>Organization.Read.All</code>
    </li>
    <li class="mb-2">Kliknij <strong>Grant admin consent</strong></li>
    <li>Skopiuj <strong>Application (client) ID</strong> z ekranu Overview</li>
  </ol>

  <?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <label class="form-label fw-semibold">Application (Client) ID <span class="text-danger">*</span></label>
    <div class="input-group mb-3">
      <span class="input-group-text"><i class="bi bi-app"></i></span>
      <input type="text" name="client_id" class="form-control font-monospace"
        value="<?= h($_POST['client_id'] ?? $saved_client_id) ?>"
        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required>
    </div>
    <p class="small text-muted">
      <i class="bi bi-info-circle"></i>
      Tenant ID i wszystkie pozostałe dane zostaną <strong>wykryte automatycznie</strong> po zalogowaniu.
      Nie musisz nic więcej wpisywać.
    </p>
    <button type="submit" class="btn btn-primary btn-lg w-100">
      <i class="bi bi-microsoft me-2"></i>Zaloguj się przez Microsoft 365 →
    </button>
  </form>
</div>
</div>

</div>
<div class="col-lg-6">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> Co zostanie automatycznie wykryte</div>
<div class="card-body small">
  <ul class="mb-0">
    <li class="mb-2"><strong>Tenant ID</strong> — z tokenu Microsoft (nie musisz go znać)</li>
    <li class="mb-2"><strong>Domena organizacji</strong> — z certyfikowanych domen tenanta</li>
    <li class="mb-2"><strong>Licencje Microsoft 365</strong> — lista dostępnych SKU z liczbą wolnych miejsc</li>
    <li class="mb-2"><strong>Lista użytkowników</strong> — do wyboru nadawcy maili systemowych</li>
    <li><strong>Nazwa organizacji</strong> — do wyświetlania w systemie</li>
  </ul>
</div>
</div>

<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-question-circle"></i> Dlaczego tylko Client ID?</div>
<div class="card-body small text-muted">
  <p>Używamy przepływu <strong>PKCE</strong> — bezpiecznego standardu OAuth 2.0,
  który nie wymaga wpisywania Client Secret do logowania. Secret potrzebujesz
  tylko do operacji działających w tle (tworzenie kont, synchronizacja).</p>
  <p class="mb-0">Po zalogowaniu i auto-wykryciu wszystkich danych, zostaniesz
  poproszony/a o podanie Client Secret do konfiguracji zadań w tle.</p>
</div>
</div>

</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
