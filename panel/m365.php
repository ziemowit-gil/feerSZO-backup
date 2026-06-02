<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

require_login();
$PAGE_TITLE = 'Moje konto Microsoft 365';
$user = current_user();
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

// Znajdź konto M365 powiązane z użytkownikiem (z umów)
$m365_contracts = [];
foreach (['wolontariat', 'zlecenie', 'dzielo'] as $type) {
    $row = db_one(
        "SELECT id, numer_umowy, m365_user_id, m365_login, m365_konto, m365_konto_aktywne
         FROM umowy_{$type}
         WHERE (email=? OR m365_login=?) AND m365_konto=1
         ORDER BY id DESC LIMIT 1",
        [$user['email'], $user['email']]
    );
    if ($row) {
        $row['_type'] = $type;
        $m365_contracts[] = $row;
        break; // wystarczy pierwsze
    }
}
$m365_row = $m365_contracts[0] ?? null;
$has_m365 = $m365_row && !empty($m365_row['m365_user_id']);

$ms_login_available = ms_login_available();
$ms_linked = !empty($user['microsoft_id']);

// ── Reset hasła M365 ──────────────────────────────────────────────────────────
$pass_error   = null;
$pass_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reset_m365') {
    csrf_check();
    if (!$has_m365) {
        $pass_error = 'Nie posiadasz konta Microsoft 365.';
    } else {
        $enabled       = m365_setting('m365_enabled') === '1';
        $tenant_id     = m365_setting('m365_tenant_id');
        $client_id     = m365_setting('m365_graph_client_id');
        $client_secret = m365_setting('m365_graph_client_secret');

        if (!$enabled || !$tenant_id || !$client_id || !$client_secret) {
            $pass_error = 'Integracja z Microsoft 365 nie jest skonfigurowana. Skontaktuj się z administratorem.';
        } else {
            try {
                $new_pass = M365Graph::generate_password();
                $m365 = new M365Graph([
                    'tenant_id'     => $tenant_id,
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ]);
                $m365->set_password($m365_row['m365_user_id'], $new_pass);
                auth_start();
                $_SESSION['m365_new_pass'] = $new_pass;
                header('Location: ' . APP_URL . '/panel/m365.php?done=1'); exit;
            } catch (\Exception $e) {
                $pass_error = $e->getMessage();
            }
        }
    }
}

if (isset($_GET['done'])) {
    auth_start();
    $pass_success = $_SESSION['m365_new_pass'] ?? null;
    unset($_SESSION['m365_new_pass']);
}

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-microsoft me-2" aria-hidden="true"></i>Microsoft 365</h1>
  <p class="pv-page-sub">Status konta Microsoft 365</p>
</div>
<?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
       style="width:52px;height:52px;background:linear-gradient(135deg,#0078d4,#50e6ff)">
    <i class="bi bi-microsoft text-white fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Konto Microsoft 365</h4>
    <div class="text-muted small">Status i zarządzanie Twoim kontem organizacyjnym M365</div>
  </div>
</div>

<?php if ($pass_error): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= h($pass_error) ?></div>
<?php endif; ?>

<!-- ── Hasło zresetowane ──────────────────────────────────────────────────── -->
<?php if ($pass_success): ?>
<div class="card border-success shadow-sm mb-4">
  <div class="card-body">
    <div class="d-flex align-items-center gap-3 mb-3">
      <i class="bi bi-check-circle-fill text-success fs-3"></i>
      <div>
        <div class="fw-bold">Hasło zostało zresetowane!</div>
        <div class="text-muted small">Zapisz nowe hasło — nie będzie już pokazane.</div>
      </div>
    </div>
    <div class="bg-light rounded p-3 d-flex align-items-center gap-3">
      <span class="fw-bold font-monospace fs-5 flex-grow-1" id="new-pass-val"><?= h($pass_success) ?></span>
      <button type="button" class="btn btn-outline-secondary btn-sm"
              onclick="navigator.clipboard.writeText('<?= h($pass_success) ?>');this.innerHTML='<i class=\'bi bi-check2\'></i> Skopiowano'">
        <i class="bi bi-clipboard"></i> Kopiuj
      </button>
    </div>
    <div class="alert alert-warning py-2 mt-3 small mb-0">
      <i class="bi bi-exclamation-triangle me-1"></i>
      Przy pierwszym logowaniu Microsoft może wymagać zmiany hasła na własne.
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Status konta M365 ──────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

  <!-- Konto organizacyjne -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-building text-primary"></i> Konto organizacyjne M365
      </div>
      <div class="card-body">
        <?php if ($has_m365): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
               style="width:44px;height:44px;flex-shrink:0">
            <i class="bi bi-person-circle text-primary fs-5"></i>
          </div>
          <div>
            <div class="fw-semibold"><?= h($m365_row['m365_login']) ?></div>
            <div class="text-muted small">Login Microsoft 365</div>
          </div>
        </div>

        <dl class="row g-1 mb-3 small">
          <dt class="col-5 text-muted">Status konta</dt>
          <dd class="col-7 mb-0">
            <?php if ($m365_row['m365_konto_aktywne']): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Aktywne</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-pause-circle me-1"></i>Nieaktywne</span>
            <?php endif; ?>
          </dd>
          <dt class="col-5 text-muted">Typ umowy</dt>
          <dd class="col-7 mb-0 text-capitalize"><?= h($m365_row['_type']) ?></dd>
          <dt class="col-5 text-muted">Nr umowy</dt>
          <dd class="col-7 mb-0"><?= h($m365_row['numer_umowy'] ?? '—') ?></dd>
        </dl>

        <?php if ($m365_row['m365_konto_aktywne']): ?>
        <form method="post" onsubmit="return confirm('Zresetować hasło do konta Microsoft 365?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="reset_m365">
          <button type="submit" class="btn btn-outline-primary btn-sm w-100">
            <i class="bi bi-key me-1"></i>Zresetuj hasło M365
          </button>
        </form>
        <?php else: ?>
        <div class="alert alert-warning py-2 small mb-0">
          <i class="bi bi-info-circle me-1"></i>Konto nieaktywne. Skontaktuj się z administratorem.
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-3 text-muted">
          <i class="bi bi-microsoft fs-2 opacity-25 d-block mb-2"></i>
          <p class="small mb-0">Nie masz przypisanego konta Microsoft 365 w organizacji.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Logowanie SSO -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock text-success"></i> Logowanie przez Microsoft (SSO)
      </div>
      <div class="card-body">
        <?php if ($ms_linked): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center"
               style="width:44px;height:44px;flex-shrink:0">
            <i class="bi bi-check-circle-fill text-success fs-5"></i>
          </div>
          <div>
            <div class="fw-semibold text-success">Konto połączone</div>
            <div class="text-muted small">Możesz logować się przez Microsoft</div>
          </div>
        </div>
        <div class="alert alert-success py-2 small mb-0">
          <i class="bi bi-shield-check me-1"></i>
          Twoje konto jest powiązane z Microsoft 365. Możesz logować się przyciskiem „Zaloguj przez Microsoft" na stronie logowania.
        </div>

        <?php elseif ($ms_login_available): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center"
               style="width:44px;height:44px;flex-shrink:0">
            <i class="bi bi-link-45deg text-warning fs-5"></i>
          </div>
          <div>
            <div class="fw-semibold">Konto niepołączone</div>
            <div class="text-muted small">Połącz, aby logować się przez Microsoft</div>
          </div>
        </div>
        <a href="<?= h(ms_auth_url(APP_URL . '/panel/m365.php')) ?>"
           class="btn btn-outline-primary w-100">
          <i class="bi bi-microsoft me-2"></i>Połącz z kontem Microsoft
        </a>

        <?php else: ?>
        <div class="text-center py-3 text-muted">
          <i class="bi bi-microsoft fs-2 opacity-25 d-block mb-2"></i>
          <p class="small mb-0">Logowanie przez Microsoft nie jest skonfigurowane w tej organizacji.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- ── Aplikacje M365 ─────────────────────────────────────────────────────── -->
<?php if ($has_m365 && $m365_row['m365_konto_aktywne']): ?>
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-grid-3x3-gap me-2"></i>Aplikacje Microsoft 365
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Zaloguj się do aplikacji Microsoft 365 używając loginu <strong><?= h($m365_row['m365_login']) ?></strong>
      i swojego hasła.
    </p>
    <?php
    $m365_apps = [
        ['Outlook',   'https://outlook.office.com',               'bi-envelope-fill',   '#0078d4'],
        ['Teams',     'https://teams.microsoft.com',              'bi-camera-video-fill','#5059c9'],
        ['SharePoint','https://sharepoint.com',                   'bi-diagram-2-fill',  '#038387'],
        ['OneDrive',  'https://onedrive.live.com',                'bi-cloud-fill',       '#0078d4'],
        ['Word',      'https://office.live.com/start/word.aspx',  'bi-file-word-fill',   '#2b579a'],
        ['Excel',     'https://office.live.com/start/excel.aspx', 'bi-file-excel-fill',  '#217346'],
    ];
    ?>
    <div class="row g-2">
      <?php foreach ($m365_apps as [$name, $url, $icon, $color]): ?>
      <div class="col-6 col-sm-4 col-md-2">
        <a href="<?= h($url) ?>" target="_blank" rel="noopener"
           class="card text-decoration-none text-center py-3 px-2 h-100 border-0 shadow-sm"
           style="transition:.15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
          <i class="bi <?= $icon ?> fs-2 d-block mb-1" style="color:<?= $color ?>"></i>
          <span class="small fw-semibold text-dark"><?= $name ?></span>
        </a>
      </div>
      <?php endforeach; ?>
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
