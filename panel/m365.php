<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/email_alias.php';
require_once dirname(__DIR__) . '/includes/webmail_clients.php';
email_alias_migrate();

require_login();
$PAGE_TITLE = 'Moje konto Microsoft 365';
$user = current_user();
$uid  = (int)$user['id'];
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

// ── Usunięcie konta M365 ────────────────────────────────────────────────────
// Akcja NIEODWRACALNA — wymaga potwierdzenia przez wpisanie dokładnego loginu.
$del_error   = null;
$del_success = null;
$_ALLOWED_M365_TABLES = ['wolontariat', 'zlecenie', 'dzielo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_m365') {
    csrf_check();
    if (!$has_m365) {
        $del_error = 'Nie posiadasz konta Microsoft 365.';
    } elseif (strcasecmp(trim($_POST['confirm_login'] ?? ''), (string)$m365_row['m365_login']) !== 0) {
        $del_error = 'Potwierdzenie nieprawidłowe — wpisz dokładnie swój login Microsoft 365, aby usunąć konto.';
    } else {
        $enabled       = m365_setting('m365_enabled') === '1';
        $tenant_id     = m365_setting('m365_tenant_id');
        $client_id     = m365_setting('m365_graph_client_id');
        $client_secret = m365_setting('m365_graph_client_secret');
        if (!$enabled || !$tenant_id || !$client_id || !$client_secret) {
            $del_error = 'Integracja z Microsoft 365 nie jest skonfigurowana. Skontaktuj się z administratorem.';
        } else {
            try {
                $m365 = new M365Graph([
                    'tenant_id'     => $tenant_id,
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ]);
                $m365->delete_user($m365_row['m365_user_id']);

                // Odznacz konto w umowie (tabela z białej listy) i odepnij SSO.
                $tbl = in_array($m365_row['_type'], $_ALLOWED_M365_TABLES, true) ? $m365_row['_type'] : null;
                if ($tbl) {
                    db()->prepare("UPDATE umowy_{$tbl} SET m365_konto_aktywne=0, m365_user_id='' WHERE id=?")
                        ->execute([(int)$m365_row['id']]);
                }
                db()->prepare("UPDATE users SET microsoft_id=NULL WHERE id=?")->execute([$uid]);

                if (function_exists('log_user_action')) {
                    log_user_action($uid, $uid, 'm365_account_deleted', 'Usunięto konto Microsoft 365: ' . ($m365_row['m365_login'] ?? ''));
                }
                if (function_exists('authlog_write')) {
                    authlog_write($uid, 'm365_account_deleted', $user['email'] ?? '', 'Usunięto konto M365: ' . ($m365_row['m365_login'] ?? ''));
                }
                auth_start();
                $_SESSION['m365_deleted'] = $m365_row['m365_login'] ?? '';
                header('Location: ' . APP_URL . '/panel/m365.php?deleted=1'); exit;
            } catch (\Exception $e) {
                $del_error = 'Nie udało się usunąć konta: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['deleted'])) {
    auth_start();
    $del_success = $_SESSION['m365_deleted'] ?? '';
    unset($_SESSION['m365_deleted']);
}

// ── Wniosek o alias e-mail ──────────────────────────────────────────────────────
$alias_error    = null;
$alias_domain   = m365_setting('m365_domain') ?: 'feer.org.pl';
$alias_pending  = ealias_user_pending($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'request_alias') {
    csrf_check();
    $local = strtolower(trim($_POST['alias_local'] ?? ''));

    if (!$has_m365) {
        $alias_error = 'Nie posiadasz aktywnego konta Microsoft 365.';
    } elseif ($alias_pending) {
        $alias_error = 'Masz już wniosek o alias w toku — poczekaj na jego rozpatrzenie.';
    } elseif (!preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,30}[a-z0-9])?$/', $local)) {
        $alias_error = 'Nieprawidłowy format. Użyj 2–32 znaków: małe litery, cyfry, kropka, myślnik lub podkreślenie.';
    } else {
        $alias_full    = $local . '@' . $alias_domain;
        $enabled       = m365_setting('m365_enabled') === '1';
        $tenant_id     = m365_setting('m365_tenant_id');
        $client_id     = m365_setting('m365_graph_client_id');
        $client_secret = m365_setting('m365_graph_client_secret');

        if (!$enabled || !$tenant_id || !$client_id || !$client_secret) {
            $alias_error = 'Integracja z Microsoft 365 nie jest skonfigurowana. Skontaktuj się z administratorem.';
        } else {
            try {
                $m365 = new M365Graph([
                    'tenant_id'     => $tenant_id,
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ]);
                if ($m365->email_in_use($alias_full)) {
                    $alias_error = 'Adres ' . $alias_full . ' jest już zajęty. Wybierz inny.';
                } else {
                    $req = [
                        'user_id'         => $uid,
                        'requester_name'  => $user['name']  ?? '',
                        'requester_email' => $user['email'] ?? '',
                        'm365_user_id'    => $m365_row['m365_user_id'],
                        'm365_login'      => $m365_row['m365_login'] ?? '',
                        'requested_alias' => $alias_full,
                    ];
                    $ticket_id = ealias_create_ticket($req);
                    $req_id = db_insert('email_alias_requests', $req + [
                        'status'    => 'oczekuje',
                        'ticket_id' => $ticket_id,
                    ]);
                    $tnum = db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$ticket_id])['number'] ?? '';
                    auth_start();
                    $_SESSION['alias_submitted'] = ['alias' => $alias_full, 'ticket' => $tnum];
                    header('Location: ' . APP_URL . '/panel/m365.php?alias=ok'); exit;
                }
            } catch (\Exception $e) {
                $alias_error = 'Błąd weryfikacji w Microsoft 365: ' . $e->getMessage();
            }
        }
    }
}

$alias_submitted = null;
if (isset($_GET['alias'])) {
    auth_start();
    $alias_submitted = $_SESSION['alias_submitted'] ?? null;
    unset($_SESSION['alias_submitted']);
    $alias_pending = ealias_user_pending($uid); // odśwież po złożeniu
}

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-microsoft" aria-hidden="true"></i>Konto Microsoft 365</h1>
    <p class="pv-page-sub">Zarządzaj swoim kontem @feer.org.pl</p>
  </div>
</div>

<?php if ($pass_error): ?>
<div class="pv-alert pv-alert-danger" role="alert"><i class="bi bi-x-circle me-2" aria-hidden="true"></i><?= h($pass_error) ?></div>
<?php endif; ?>

<?php if ($del_error): ?>
<div class="pv-alert pv-alert-danger" role="alert"><i class="bi bi-x-circle me-2" aria-hidden="true"></i><?= h($del_error) ?></div>
<?php endif; ?>

<?php if ($del_success !== null): ?>
<div class="pv-alert pv-alert-success" role="status"><i class="bi bi-check-circle me-2" aria-hidden="true"></i>
  Konto Microsoft 365<?= $del_success ? ' <strong>' . h($del_success) . '</strong>' : '' ?> zostało usunięte.
  Powiązanie logowania przez Microsoft zostało odłączone.
</div>
<?php endif; ?>

<!-- ── Hasło zresetowane ──────────────────────────────────────────────────── -->
<?php if ($pass_success): ?>
<div class="tz-card border-success mb-4">
  <div class="tz-card__bd">
    <div class="d-flex align-items-center gap-3 mb-3">
      <i class="bi bi-check-circle-fill text-success fs-3" aria-hidden="true"></i>
      <div>
        <div class="fw-bold">Hasło zostało zresetowane!</div>
        <div class="text-muted small">Zapisz nowe hasło — nie będzie już pokazane.</div>
      </div>
    </div>
    <div class="bg-light rounded p-3 d-flex align-items-center gap-3">
      <span class="fw-bold font-monospace fs-5 flex-grow-1" id="new-pass-val"><?= h($pass_success) ?></span>
      <button type="button" class="tz-btn tz-btn--ghost"
              onclick="navigator.clipboard.writeText('<?= h($pass_success) ?>');this.innerHTML='<i class=\'bi bi-check2\' aria-hidden=\'true\'></i> Skopiowano'">
        <i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj
      </button>
    </div>
    <div class="tz-note tz-note--warning mt-3 small mb-0">
      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
      Przy pierwszym logowaniu Microsoft może wymagać zmiany hasła na własne.
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Status konta M365 ──────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

  <!-- Konto organizacyjne -->
  <div class="col-md-6">
    <div class="tz-card h-100">
      <div class="tz-card__hd fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-building text-primary" aria-hidden="true"></i> Konto organizacyjne M365
      </div>
      <div class="tz-card__bd">
        <?php if ($has_m365): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
               style="width:44px;height:44px;flex-shrink:0">
            <i class="bi bi-person-circle text-primary fs-5" aria-hidden="true"></i>
          </div>
          <div>
            <div class="fw-semibold"><?= h($m365_row['m365_login']) ?></div>
            <div class="text-muted small">Login Microsoft 365</div>
          </div>
        </div>

        <dl class="tz-dl mb-3 small">
          <dt>Status konta</dt>
          <dd>
            <?php if ($m365_row['m365_konto_aktywne']): ?>
            <span class="tz-badge tz-badge--success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Aktywne</span>
            <?php else: ?>
            <span class="tz-badge tz-badge--warning"><i class="bi bi-pause-circle me-1" aria-hidden="true"></i>Nieaktywne</span>
            <?php endif; ?>
          </dd>
          <dt>Typ umowy</dt>
          <dd class="text-capitalize"><?= h($m365_row['_type']) ?></dd>
          <dt>Nr umowy</dt>
          <dd><?= h($m365_row['numer_umowy'] ?? '—') ?></dd>
        </dl>

        <?php if ($m365_row['m365_konto_aktywne']): ?>
        <div class="d-flex flex-column gap-2">
          <form method="post" onsubmit="return confirm('Zresetować hasło do konta Microsoft 365?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="reset_m365">
            <button type="submit" class="tz-btn tz-btn--ghost w-100">
              <i class="bi bi-key me-1" aria-hidden="true"></i>Resetuj hasło
            </button>
          </form>
          <button type="button" class="tz-btn tz-btn--ghost w-100"
                  onclick="var d=document.getElementById('m365-del-zone');d.hidden=!d.hidden;if(!d.hidden)d.querySelector('input[name=confirm_login]').focus();">
            <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto
          </button>
        </div>

        <!-- Strefa niebezpieczna: usunięcie konta (potwierdzenie loginem) -->
        <div id="m365-del-zone" hidden class="mt-3 p-3 rounded border border-danger-subtle bg-danger bg-opacity-10">
          <div class="fw-semibold text-danger mb-1"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Usunięcie konta Microsoft 365</div>
          <p class="small text-muted mb-2">
            Ta operacja jest <strong>nieodwracalna</strong> — usuwa konto z Microsoft 365 / Entra ID wraz z pocztą,
            plikami OneDrive i dostępem do aplikacji. Aby potwierdzić, wpisz swój login:
          </p>
          <form method="post" onsubmit="return confirm('Na pewno TRWALE usunąć konto Microsoft 365? Tej operacji nie można cofnąć.')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="delete_m365">
            <label for="confirm_login" class="form-label small fw-semibold mb-1">
              Wpisz <code><?= h($m365_row['m365_login']) ?></code> aby potwierdzić
            </label>
            <input type="text" class="form-control form-control-sm mb-2" id="confirm_login" name="confirm_login"
                   autocomplete="off" placeholder="<?= h($m365_row['m365_login']) ?>" required>
            <button type="submit" class="tz-btn tz-btn--danger w-100">
              <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto na stałe
            </button>
          </form>
        </div>
        <?php else: ?>
        <div class="tz-note tz-note--warning small mb-0">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Konto nieaktywne. Skontaktuj się z administratorem.
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="tz-empty">
          <i class="bi bi-microsoft fs-2 d-block mb-2" aria-hidden="true"></i>
          <p class="small mb-0">Nie masz przypisanego konta Microsoft 365 w organizacji.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Logowanie SSO -->
  <div class="col-md-6">
    <div class="tz-card h-100">
      <div class="tz-card__hd fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock text-success" aria-hidden="true"></i> Logowanie przez Microsoft (SSO)
      </div>
      <div class="tz-card__bd">
        <?php if ($ms_linked): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center"
               style="width:44px;height:44px;flex-shrink:0">
            <i class="bi bi-check-circle-fill text-success fs-5" aria-hidden="true"></i>
          </div>
          <div>
            <div class="fw-semibold text-success">Konto połączone</div>
            <div class="text-muted small">Możesz logować się przez Microsoft</div>
          </div>
        </div>
        <div class="tz-note tz-note--success">
          <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
          Twoje konto jest powiązane z Microsoft 365. Możesz logować się przyciskiem „Zaloguj przez Microsoft" na stronie logowania.
        </div>

        <?php elseif ($ms_login_available): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center"
               style="width:44px;height:44px;flex-shrink:0">
            <i class="bi bi-link-45deg text-warning fs-5" aria-hidden="true"></i>
          </div>
          <div>
            <div class="fw-semibold">Konto niepołączone</div>
            <div class="text-muted small">Połącz, aby logować się przez Microsoft</div>
          </div>
        </div>
        <a href="<?= h(ms_auth_url(APP_URL . '/panel/m365.php')) ?>"
           class="tz-btn tz-btn--ghost w-100">
          <i class="bi bi-microsoft me-2" aria-hidden="true"></i>Połącz z kontem Microsoft
        </a>

        <?php else: ?>
        <div class="tz-empty">
          <i class="bi bi-microsoft fs-2 d-block mb-2" aria-hidden="true"></i>
          <p class="small mb-0">Logowanie przez Microsoft nie jest skonfigurowane w tej organizacji.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- ── Alias e-mail ───────────────────────────────────────────────────────── -->
<?php if ($has_m365 && $m365_row['m365_konto_aktywne']): ?>
<div class="tz-card mb-4">
  <div class="tz-card__hd fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-at text-primary" aria-hidden="true"></i> Alias e-mail
  </div>
  <div class="tz-card__bd">
    <p class="text-muted small mb-3">
      Możesz poprosić o krótszy, łatwiejszy alias e-mail (np. <code>kasia@<?= h($alias_domain) ?></code>)
      obok Twojego głównego adresu <strong><?= h($m365_row['m365_login']) ?></strong>.
      Maile na oba adresy będą trafiać do Twojej skrzynki. Wniosek wymaga zatwierdzenia przez administratora.
    </p>

    <?php if ($alias_submitted): ?>
    <div class="tz-note tz-note--success" role="status">
      <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
      Wniosek o alias <strong><?= h($alias_submitted['alias']) ?></strong> został złożony.
      <?php if (!empty($alias_submitted['ticket'])): ?>
      Zgłoszenie: <strong><?= h($alias_submitted['ticket']) ?></strong>.
      <?php endif; ?>
      Oczekuje na zatwierdzenie.
    </div>
    <?php endif; ?>

    <?php if ($alias_error): ?>
    <div class="tz-note tz-note--danger" role="alert"><i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= h($alias_error) ?></div>
    <?php endif; ?>

    <?php if ($alias_pending): ?>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span>Twój wniosek:</span>
      <strong><?= h($alias_pending['requested_alias']) ?></strong>
      <?= ealias_status_badge($alias_pending['status']) ?>
      <?php if (!empty($alias_pending['ticket_id'])): ?>
      <a class="tz-btn tz-btn--ghost ms-auto"
         href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$alias_pending['ticket_id'] ?>">
        <i class="bi bi-ticket-perforated me-1" aria-hidden="true"></i>Zobacz zgłoszenie
      </a>
      <?php endif; ?>
    </div>
    <?php if ($alias_pending['status'] === 'błąd' && !empty($alias_pending['graph_error'])): ?>
    <div class="tz-note tz-note--warning small mt-2 mb-0">
      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
      Ostatnia próba ustawienia nie powiodła się — administrator został powiadomiony.
    </div>
    <?php endif; ?>
    <?php else: ?>
    <form method="post" class="row g-2 align-items-start" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="request_alias">
      <div class="col-12 col-sm-auto flex-grow-1">
        <label for="alias_local" class="form-label small mb-1">Wybierz alias</label>
        <div class="input-group">
          <input type="text" class="form-control" id="alias_local" name="alias_local"
                 placeholder="np. kasia" pattern="[a-z0-9._-]{2,32}"
                 maxlength="32" required
                 aria-describedby="alias_help">
          <span class="input-group-text">@<?= h($alias_domain) ?></span>
        </div>
        <div id="alias_help" class="form-text">Małe litery, cyfry, kropka, myślnik lub podkreślenie (2–32 znaki).</div>
      </div>
      <div class="col-12 col-sm-auto">
        <label class="form-label small mb-1 d-none d-sm-block" aria-hidden="true">&nbsp;</label>
        <button type="submit" class="tz-btn w-100"
                onclick="return confirm('Złożyć wniosek o ten alias e-mail?')">
          <i class="bi bi-send me-1" aria-hidden="true"></i>Złóż wniosek
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Aplikacje M365 ─────────────────────────────────────────────────────── -->
<?php if ($has_m365 && $m365_row['m365_konto_aktywne']): ?>
<div class="tz-card mb-4">
  <div class="tz-card__hd fw-semibold">
    <i class="bi bi-grid-3x3-gap me-2" aria-hidden="true"></i>Aplikacje Microsoft 365
  </div>
  <div class="tz-card__bd">
    <p class="text-muted small mb-3">
      Zaloguj się do aplikacji Microsoft 365 używając loginu <strong><?= h($m365_row['m365_login']) ?></strong>
      i swojego hasła.
    </p>
    <?php
    // Poczta prowadzi na WŁASNY adres organizacji (strona wyboru klienta), nie
    // na outlook.office.com — jeden adres do zapamiętania, zob.
    // includes/webmail_clients.php::webmail_chooser_url().
    $m365_apps = [
        ['Poczta',     webmail_chooser_url(),                      'bi-envelope-fill'],
        ['Teams',      'https://teams.microsoft.com',              'bi-camera-video-fill'],
        ['SharePoint', 'https://sharepoint.com',                   'bi-diagram-2-fill'],
        ['OneDrive',   'https://onedrive.live.com',                'bi-cloud-fill'],
        ['Word',       'https://office.live.com/start/word.aspx',  'bi-file-word-fill'],
        ['Excel',      'https://office.live.com/start/excel.aspx', 'bi-file-excel-fill'],
    ];
    ?>
    <div class="row g-2">
      <?php foreach ($m365_apps as [$name, $url, $icon]): ?>
      <div class="col-6 col-sm-4 col-md-2">
        <a href="<?= h($url) ?>" target="_blank" rel="noopener"
           class="tz-card text-decoration-none text-center py-3 px-2 h-100"
           style="transition:.15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
          <i class="bi <?= $icon ?> fs-2 d-block mb-1" aria-hidden="true"></i>
          <span class="small fw-semibold text-dark"><?= $name ?></span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /.pv-wrap -->

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
