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
$_is_volunteer_only = panel_volunteer_shell($user); // powłoka panelu: wolontariusz albo ktokolwiek z umową

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
    require_once __DIR__ . '/includes/pv_ui.php'; pv_ui_styles(); // style panelu (.tz-*, .pv-*) także pod powłoką SZO
}
?>

<?php
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0050ef');
$m3_active = $has_m365 && !empty($m365_row['m365_konto_aktywne']);
// Poczta prowadzi na WŁASNY adres organizacji (strona wyboru klienta), nie na outlook.office.com —
// jeden adres do zapamiętania, zob. includes/webmail_clients.php::webmail_chooser_url().
$m365_apps = [
    ['Poczta',     webmail_chooser_url(),                      'bi-envelope-fill',     'blue',    'w'],
    ['Teams',      'https://teams.microsoft.com',              'bi-microsoft-teams',   'indigo',  ''],
    ['OneDrive',   'https://onedrive.live.com',                'bi-cloud-fill',        'teal',    ''],
    ['SharePoint', 'https://sharepoint.com',                   'bi-diagram-2-fill',    'green',   ''],
    ['Word',       'https://office.live.com/start/word.aspx',  'bi-file-word-fill',    'blue',    ''],
    ['Excel',      'https://office.live.com/start/excel.aspx', 'bi-file-excel-fill',   'green',   ''],
];
?>
<style>
.pvm .m3-cols{display:grid;gap:8px;grid-template-columns:1fr;margin-bottom:1.5rem}
@media(min-width:900px){.pvm .m3-cols{grid-template-columns:1fr 1fr}}
.pvm .m3-cols .mt-group{margin:0;display:flex;flex-direction:column}
.pvm .m3-cols .mt-kv{grid-template-columns:1fr 1fr!important;margin-bottom:1rem}
.pvm .m3-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:auto}
.pvm .m3-state{display:flex;align-items:center;gap:.9rem;margin-bottom:1rem}
.pvm .m3-state .sq{width:52px;height:52px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:#fff;background:var(--c)}
.pvm .m3-state b{display:block;font-size:1.1rem;font-weight:600}
.pvm .m3-state small{color:var(--pvm-muted)}
.pvm .m3-danger{border-left:6px solid var(--m-red);background:var(--pvm-bg);padding:1rem;margin-top:1rem}
.pvm .m3-danger h3{font-size:1rem;font-weight:600;color:var(--m-red);margin:0 0 .4rem}
.pvm .m3-in{border:2px solid #8a8a8a;border-radius:0;padding:.55rem .75rem;font:inherit;font-size:1rem;background:var(--pvm-bg);color:var(--pvm-ink);min-width:0;width:100%}
.pvm .m3-in:focus{border-color:var(--pvm-acc);outline:0}
.pvm .m3-alias{display:flex;flex-wrap:wrap;gap:8px;align-items:stretch;max-width:640px}
.pvm .m3-alias .fld{display:flex;flex:1;min-width:240px}
.pvm .m3-alias .fld .m3-in{border-right:0}
.pvm .m3-alias .dom{display:flex;align-items:center;padding:0 .75rem;background:var(--pvm-light-h);border:2px solid #8a8a8a;border-left:0;font-weight:600;white-space:nowrap}
.pvm .m3-hint{font-size:.8rem;color:var(--pvm-muted);margin:.35rem 0 0}
.pvm .m3-pass{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;background:var(--pvm-bg);padding:.75rem 1rem;margin:.5rem 0}
.pvm .m3-pass span{font-family:ui-monospace,Menlo,monospace;font-size:1.3rem;font-weight:700;flex:1}
</style>

<div class="pvm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker"><a href="<?= APP_URL ?>/panel/index.php" style="color:inherit">Panel współpracownika</a> · konta i dostępy</div>
      <h1 class="mt-title">Microsoft 365</h1>
      <p class="mt-lead">Twoje konto <?= $has_m365 ? '<b style="font-weight:600">' . h($m365_row['m365_login']) . '</b>' : 'w organizacji' ?>: aplikacje, hasło, logowanie i alias e-mail.</p>
    </div>
  </div>

  <?php if ($pass_error): ?><div class="mt-note" role="alert" style="border-left-color:var(--m-red)"><i class="bi bi-x-circle" style="color:var(--m-red)" aria-hidden="true"></i><span><?= h($pass_error) ?></span></div><?php endif; ?>
  <?php if ($del_error): ?><div class="mt-note" role="alert" style="border-left-color:var(--m-red)"><i class="bi bi-x-circle" style="color:var(--m-red)" aria-hidden="true"></i><span><?= h($del_error) ?></span></div><?php endif; ?>
  <?php if ($del_success !== null): ?>
  <div class="mt-note" role="status" style="border-left-color:var(--m-green)"><i class="bi bi-check-circle" style="color:var(--m-green)" aria-hidden="true"></i>
    <span>Konto Microsoft 365<?= $del_success ? ' <strong>' . h($del_success) . '</strong>' : '' ?> zostało usunięte. Powiązanie logowania przez Microsoft zostało odłączone.</span></div>
  <?php endif; ?>

  <?php if ($pass_success): ?>
  <section class="mt-group" style="border-left:6px solid var(--m-green)" aria-labelledby="m3-pass-h">
    <h2 class="mt-h2" id="m3-pass-h"><b>Hasło zostało zresetowane</b></h2>
    <p style="margin:0">Zapisz nowe hasło — nie będzie już pokazane.</p>
    <div class="m3-pass">
      <span id="new-pass-val"><?= h($pass_success) ?></span>
      <button type="button" class="mt-btn mt-btn--ghost"
              onclick="navigator.clipboard.writeText(document.getElementById('new-pass-val').textContent);this.innerHTML='<i class=\'bi bi-check2\' aria-hidden=\'true\'></i> Skopiowano'">
        <i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj
      </button>
    </div>
    <p class="m3-hint" style="margin:0"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Przy pierwszym logowaniu Microsoft może wymagać zmiany hasła na własne.</p>
  </section>
  <?php endif; ?>

  <?php if ($m3_active): ?>
  <section class="mt-grp" aria-labelledby="m3-apps-h" style="margin-bottom:1.5rem">
    <h2 class="mt-grp-h" id="m3-apps-h" style="font-size:1.05rem;font-weight:300;color:var(--pvm-muted);margin:0 0 .5rem">Aplikacje — zaloguj się jako <?= h($m365_row['m365_login']) ?></h2>
    <div class="mt-grid mt-grid--sm">
      <?php foreach ($m365_apps as [$name, $url, $icon, $color, $size])
          panelMetroTile(['href' => $url, 'icon' => $icon, 'label' => $name, 'color' => $color, 'size' => $size, 'external' => true,
                          'sub' => $name === 'Poczta' ? 'jeden adres do skrzynki' : '']); ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="m3-cols">
    <!-- Konto organizacyjne -->
    <section class="mt-group" aria-labelledby="m3-acc-h">
      <h2 class="mt-h2" id="m3-acc-h">Konto <b>organizacyjne</b></h2>
      <?php if ($has_m365): ?>
      <div class="m3-state" style="--c:<?= $m365_row['m365_konto_aktywne'] ? 'var(--m-blue)' : 'var(--m-orange)' ?>">
        <span class="sq" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <span><b><?= h($m365_row['m365_login']) ?></b><small>login Microsoft 365</small></span>
      </div>
      <dl class="mt-kv">
        <div><dt>Status</dt><dd><?= $m365_row['m365_konto_aktywne'] ? '<span class="mt-yes">aktywne</span>' : '<span class="mt-no">nieaktywne</span>' ?></dd></div>
        <div><dt>Umowa</dt><dd><?= h(ucfirst((string)$m365_row['_type'])) ?><?= !empty($m365_row['numer_umowy']) ? ' · ' . h($m365_row['numer_umowy']) : '' ?></dd></div>
      </dl>
      <?php if ($m365_row['m365_konto_aktywne']): ?>
      <div class="m3-acts">
        <form method="post" onsubmit="return confirm('Zresetować hasło do konta Microsoft 365?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="reset_m365">
          <button type="submit" class="mt-btn"><i class="bi bi-key" aria-hidden="true"></i>Resetuj hasło</button>
        </form>
        <button type="button" class="mt-btn mt-btn--ghost" aria-controls="m365-del-zone"
                onclick="var d=document.getElementById('m365-del-zone');d.hidden=!d.hidden;this.setAttribute('aria-expanded',!d.hidden);if(!d.hidden)d.querySelector('input[name=confirm_login]').focus();" aria-expanded="false">
          <i class="bi bi-trash" aria-hidden="true"></i>Usuń konto
        </button>
      </div>
      <div id="m365-del-zone" hidden class="m3-danger">
        <h3><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Usunięcie konta Microsoft 365</h3>
        <p class="m3-hint" style="margin:0 0 .6rem">Operacja <strong>nieodwracalna</strong> — usuwa konto z Microsoft 365 / Entra ID wraz z pocztą, plikami OneDrive i dostępem do aplikacji.</p>
        <form method="post" onsubmit="return confirm('Na pewno TRWALE usunąć konto Microsoft 365? Tej operacji nie można cofnąć.')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="delete_m365">
          <label for="confirm_login" style="display:block;font-weight:600;font-size:.88rem;margin-bottom:.3rem">Wpisz <code><?= h($m365_row['m365_login']) ?></code>, aby potwierdzić</label>
          <input type="text" class="m3-in" id="confirm_login" name="confirm_login" autocomplete="off" placeholder="<?= h($m365_row['m365_login']) ?>" required style="margin-bottom:.6rem">
          <button type="submit" class="mt-btn" style="background:var(--m-red);border-color:var(--m-red);color:#fff"><i class="bi bi-trash" aria-hidden="true"></i>Usuń konto na stałe</button>
        </form>
      </div>
      <?php else: ?>
      <div class="mt-note" style="border-left-color:var(--m-orange);margin:0"><i class="bi bi-info-circle" style="color:var(--m-orange)" aria-hidden="true"></i><span>Konto nieaktywne. Skontaktuj się z administratorem.</span></div>
      <?php endif; ?>
      <?php else: ?>
      <div class="m3-state" style="--c:var(--m-grey)"><span class="sq" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <span><b>Brak konta</b><small>Nie masz przypisanego konta Microsoft 365 w organizacji.</small></span></div>
      <?php endif; ?>
    </section>

    <!-- Logowanie SSO -->
    <section class="mt-group" aria-labelledby="m3-sso-h">
      <h2 class="mt-h2" id="m3-sso-h">Logowanie <b>przez Microsoft</b></h2>
      <?php if ($ms_linked): ?>
      <div class="m3-state" style="--c:var(--m-green)"><span class="sq" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
        <span><b>Konto połączone</b><small>Możesz logować się przyciskiem „Zaloguj przez Microsoft”.</small></span></div>
      <?php elseif ($ms_login_available): ?>
      <div class="m3-state" style="--c:var(--m-orange)"><span class="sq" aria-hidden="true"><i class="bi bi-link-45deg"></i></span>
        <span><b>Konto niepołączone</b><small>Połącz, aby logować się przez Microsoft.</small></span></div>
      <div class="m3-acts"><a href="<?= h(ms_auth_url(APP_URL . '/panel/m365.php')) ?>" class="mt-btn"><i class="bi bi-microsoft" aria-hidden="true"></i>Połącz z kontem Microsoft</a></div>
      <?php else: ?>
      <div class="m3-state" style="--c:var(--m-grey)"><span class="sq" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <span><b>Niedostępne</b><small>Logowanie przez Microsoft nie jest skonfigurowane w tej organizacji.</small></span></div>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($m3_active): ?>
  <!-- Alias e-mail -->
  <section class="mt-group" aria-labelledby="m3-alias-h">
    <h2 class="mt-h2" id="m3-alias-h">Alias <b>e-mail</b></h2>
    <p style="margin:0 0 1rem;font-weight:300;max-width:75ch">Krótszy, łatwiejszy adres (np. <b style="font-weight:600">kasia@<?= h($alias_domain) ?></b>) obok głównego
      <b style="font-weight:600"><?= h($m365_row['m365_login']) ?></b>. Poczta z obu adresów trafia do jednej skrzynki; wniosek zatwierdza administrator.</p>

    <?php if ($alias_submitted): ?>
    <div class="mt-note" role="status" style="border-left-color:var(--m-green)"><i class="bi bi-check-circle" style="color:var(--m-green)" aria-hidden="true"></i>
      <span>Wniosek o alias <strong><?= h($alias_submitted['alias']) ?></strong> został złożony<?= !empty($alias_submitted['ticket']) ? ' (zgłoszenie <strong>' . h($alias_submitted['ticket']) . '</strong>)' : '' ?>. Oczekuje na zatwierdzenie.</span></div>
    <?php endif; ?>
    <?php if ($alias_error): ?>
    <div class="mt-note" role="alert" style="border-left-color:var(--m-red)"><i class="bi bi-x-circle" style="color:var(--m-red)" aria-hidden="true"></i><span><?= h($alias_error) ?></span></div>
    <?php endif; ?>

    <?php if ($alias_pending): ?>
    <div class="m3-state" style="--c:var(--m-orange)">
      <span class="sq" aria-hidden="true"><i class="bi bi-at"></i></span>
      <span><b><?= h($alias_pending['requested_alias']) ?></b><small>Twój wniosek · <?= ealias_status_badge($alias_pending['status']) ?></small></span>
      <?php if (!empty($alias_pending['ticket_id'])): ?>
      <a class="mt-btn mt-btn--ghost" style="margin-left:auto" href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$alias_pending['ticket_id'] ?>"><i class="bi bi-ticket-perforated" aria-hidden="true"></i>Zobacz zgłoszenie</a>
      <?php endif; ?>
    </div>
    <?php if ($alias_pending['status'] === 'błąd' && !empty($alias_pending['graph_error'])): ?>
    <div class="mt-note" style="border-left-color:var(--m-orange);margin:0"><i class="bi bi-exclamation-triangle" style="color:var(--m-orange)" aria-hidden="true"></i><span>Ostatnia próba ustawienia nie powiodła się — administrator został powiadomiony.</span></div>
    <?php endif; ?>
    <?php else: ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="request_alias">
      <label for="alias_local" style="display:block;font-weight:600;font-size:.88rem;margin-bottom:.3rem">Wybierz alias</label>
      <div class="m3-alias">
        <div class="fld">
          <input type="text" class="m3-in" id="alias_local" name="alias_local" placeholder="np. kasia" pattern="[a-z0-9._-]{2,32}" maxlength="32" required aria-describedby="alias_help">
          <span class="dom" aria-hidden="true">@<?= h($alias_domain) ?></span>
        </div>
        <button type="submit" class="mt-btn" onclick="return confirm('Złożyć wniosek o ten alias e-mail?')"><i class="bi bi-send" aria-hidden="true"></i>Złóż wniosek</button>
      </div>
      <p id="alias_help" class="m3-hint">Małe litery, cyfry, kropka, myślnik lub podkreślenie (2–32 znaki). Domena: @<?= h($alias_domain) ?>.</p>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

</div>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
