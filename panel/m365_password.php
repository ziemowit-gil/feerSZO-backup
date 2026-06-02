<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();

$PAGE_TITLE = 'Konto Microsoft 365';
$user = current_user();
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

// Znajdź konto M365 wolontariusza
$m365_row = db_one(
    "SELECT m365_user_id, m365_login, m365_konto, m365_konto_aktywne
     FROM umowy_wolontariat
     WHERE (email = ? OR m365_login = ?) AND m365_user_id != '' AND m365_konto = 1
     ORDER BY id DESC LIMIT 1",
    [$user['email'], $user['email']]
);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reset') {
    csrf_check();

    if (!$m365_row) {
        $error = 'Nie posiadasz konta Microsoft 365.';
    } else {
        $enabled       = m365_setting('m365_enabled') === '1';
        $tenant_id     = m365_setting('m365_tenant_id');
        $client_id     = m365_setting('m365_graph_client_id');
        $client_secret = m365_setting('m365_graph_client_secret');

        if (!$enabled || empty($tenant_id) || empty($client_id) || empty($client_secret)) {
            $error = 'Integracja z Microsoft 365 nie jest skonfigurowana. Skontaktuj się z administratorem.';
        } else {
            try {
                $new_pass = M365Graph::generate_password();
                $m365 = new M365Graph([
                    'tenant_id'     => $tenant_id,
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ]);
                $m365->set_password($m365_row['m365_user_id'], $new_pass);
                log_user_action((int)$user['id'], (int)$user['id'], 'm365_password_reset',
                    'Reset hasła Microsoft 365: ' . ($m365_row['m365_login'] ?? ''));
                auth_start();
                $_SESSION['m365_new_pass'] = $new_pass;
                header('Location: ' . APP_URL . '/panel/m365_password.php?done=1');
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Odczytaj hasło z sesji przy ?done=1
$shown_pass = null;
if (isset($_GET['done']) && $_GET['done'] === '1') {
    auth_start();
    if (!empty($_SESSION['m365_new_pass'])) {
        $shown_pass = $_SESSION['m365_new_pass'];
        unset($_SESSION['m365_new_pass']);
    }
}

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-microsoft me-2" aria-hidden="true"></i>Zmiana hasła Microsoft 365</h1>
  <p class="pv-page-sub">Aktualizuj hasło konta Microsoft</p>
</div>
<?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-microsoft text-primary fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Hasło Microsoft 365</h4>
    <div class="text-muted small">Zarządzanie kontem Microsoft 365 w organizacji.</div>
  </div>
</div>

<?php if (!$m365_row): ?>

<div class="card shadow-sm">
  <div class="card-body p-4 text-center">
    <i class="bi bi-microsoft fs-1 text-muted mb-3 d-block"></i>
    <p class="mb-0 text-muted">Nie posiadasz konta Microsoft 365 przypisanego do Twojego profilu.</p>
    <p class="small text-muted mt-1">Jeśli uważasz, że to błąd, skontaktuj się z administratorem.</p>
  </div>
</div>

<?php else: ?>

<div class="row g-3">
  <div class="col-md-7 col-lg-6">

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
      <div><?= h($error) ?></div>
    </div>
    <?php endif; ?>

    <!-- Karta z loginem M365 -->
    <div class="card shadow-sm mb-3">
      <div class="card-body p-4">
        <div class="text-muted small mb-1">Twój adres logowania do Microsoft 365:</div>
        <div class="fs-5 fw-semibold text-primary">
          <i class="bi bi-envelope-at me-1"></i><?= h($m365_row['m365_login']) ?>
        </div>
        <?php if (!$m365_row['m365_konto_aktywne']): ?>
        <div class="mt-2">
          <span class="badge bg-warning text-dark"><i class="bi bi-pause-circle me-1"></i>Konto nieaktywne</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($shown_pass): ?>
    <!-- Wyświetl nowe hasło jednorazowo -->
    <div class="alert alert-success border-success">
      <div class="fw-semibold mb-2"><i class="bi bi-check-circle-fill text-success"></i> Hasło zostało zresetowane!</div>
      <div class="mb-2 small">Twoje nowe hasło do Microsoft 365:</div>
      <div class="d-flex align-items-center gap-2">
        <code class="fs-4 p-2 bg-white border rounded flex-grow-1 text-center d-block fw-bold" id="m365pass"><?= h($shown_pass) ?></code>
        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText('<?= h($shown_pass) ?>')">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
      <div class="mt-2 small text-danger"><i class="bi bi-exclamation-triangle"></i> Zapamiętaj je teraz — nie będzie ponownie wyświetlone.</div>
    </div>
    <?php endif; ?>

    <!-- Formularz resetu hasła -->
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <p class="text-muted small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Jeśli nie pamiętasz hasła do Outlook/Teams, użyj tej opcji.
        </p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="reset">
          <button type="submit" class="btn btn-warning">
            <i class="bi bi-arrow-repeat me-1"></i> Wygeneruj nowe hasło
          </button>
        </form>
        <div class="mt-3 small text-muted">
          Hasło zostanie wygenerowane automatycznie i wyświetlone jednorazowo.
          Przy następnym logowaniu do Microsoft 365 zostaniesz poproszony/a o jego zmianę.
        </div>
      </div>
    </div>

  </div>
</div>

<?php endif; ?>


<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
