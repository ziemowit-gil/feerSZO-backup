<?php
/**
 * tozsamosc/ldap.php — Zarządzanie katalogiem LDAP.
 *
 * Zakładki:
 *   sync        — Synchronizacja (eksport SZO→LDAP)
 *   katalog     — Przeglądarka katalogu (live odczyt, read-only)
 *   konfigurator — Graficzny autokonfigurator (live bloki + test połączenia)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ldap.php';
if (is_file(dirname(__DIR__) . '/includes/admin_audit.php')) {
    require_once dirname(__DIR__) . '/includes/admin_audit.php';
}

auth_start();
require_role('admin');

// ── AJAX: test połączenia ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'test_conn') {
    // JSON zawsze — nawet gdy coś pójdzie nie tak przed bind
    header('Content-Type: application/json; charset=utf-8');
    try {
        csrf_check();
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'CSRF: ' . $e->getMessage()]);
        exit;
    }

    $host = trim($_POST['host'] ?? '');
    $port = (int)($_POST['port'] ?? 389);
    $dn   = trim($_POST['bind_dn'] ?? '');
    $pw   = trim($_POST['bind_pw'] ?? '');

    if (!$host || !$dn || !$pw) {
        echo json_encode(['ok' => false, 'msg' => 'Wypelnij Host, Bind DN i Haslo.']);
        exit;
    }
    if (!function_exists('ldap_connect')) {
        echo json_encode(['ok' => false, 'msg' => 'Rozszerzenie PHP ldap nie jest zaladowane (apt install php-ldap).']);
        exit;
    }

    $uri  = sprintf('ldap://%s:%d', $host, $port);
    $conn = @ldap_connect($uri);
    if (!$conn) {
        echo json_encode(['ok' => false, 'msg' => "Nie mozna zainicjalizowac polaczenia z: {$uri}"]);
        exit;
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 4);

    // Sprawdz czy w ogole jest cos pod adresem (anonymous bind jako ping)
    $anon = @ldap_bind($conn);
    if (!$anon) {
        $net_err = ldap_error($conn);
        @ldap_unbind($conn);
        echo json_encode(['ok' => false, 'msg' => "Serwer nieosiagalny ({$uri}): {$net_err}. Jesli PHP dziala w Dockerze, uzyj nazwy serwisu (np. 'ldap') zamiast '127.0.0.1'."]);
        exit;
    }
    @ldap_unbind($conn);

    // Bind z credentials
    $conn2 = @ldap_connect($uri);
    ldap_set_option($conn2, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn2, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn2, LDAP_OPT_NETWORK_TIMEOUT, 4);
    if (@ldap_bind($conn2, $dn, $pw)) {
        @ldap_unbind($conn2);
        echo json_encode(['ok' => true, 'msg' => "OK — zbindowano jako {$dn} na {$uri}"]);
    } else {
        $err = ldap_error($conn2);
        @ldap_unbind($conn2);
        echo json_encode(['ok' => false, 'msg' => "Serwer osiagalny, ale bind nieudany [{$err}] — sprawdz Bind DN i haslo."]);
    }
    exit;
}

// ── Obsługa POST (sync) ───────────────────────────────────────────────────────
$PAGE_TITLE     = 'Zarządzanie LDAP';
$TZ_ACTIVE      = 'administracja';
$SELF           = APP_URL . '/tozsamosc/ldap.php';
$_tab           = in_array($_GET['tab'] ?? '', ['sync', 'katalog', 'konfigurator'])
                    ? ($_GET['tab'] ?? 'sync') : 'sync';
$action_results = [];
$action_ran     = false;

$LDAP_ACTION_LABELS = [
    'created'     => 'Nowy wpis',
    'updated'     => 'Zaktualizowano',
    'deactivated' => 'Przeniesiono do dezaktywowanych',
    'reactivated' => 'Przywrocono z dezaktywowanych',
    'skipped'     => 'Pominieto',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'sync') {
    csrf_check();
    $_tab       = 'sync';
    $action_ran = true;

    try {
        $sync = ldap_run_sync();

        foreach ($sync['items'] as $item) {
            $msg = $LDAP_ACTION_LABELS[$item['ldap_action']] ?? ($item['ldap_error'] ?? $item['ldap_action']);
            if ($item['graph_action'] === 'updated') {
                $msg .= ' + M365';
            } elseif ($item['graph_action'] === 'error') {
                $msg .= ' (M365: ' . $item['graph_error'] . ')';
            }
            $action_results[] = [
                'ok'    => $item['ldap_action'] !== 'error' && $item['graph_action'] !== 'error',
                'name'  => $item['name'],
                'login' => $item['email'],
                'msg'   => $msg,
            ];
        }

        $l = $sync['summary']['ldap'];
        $g = $sync['summary']['graph'];
        if (function_exists('admin_audit')) {
            admin_audit('ldap_sync', 'ldap', "Utw: {$l['created']}, upd: {$l['updated']}, dezakt: {$l['deactivated']}, err: {$l['error']}.", 0);
        }
        $hasErrors = $l['error'] > 0 || $g['error'] > 0;
        flash_set($hasErrors ? 'warning' : 'success',
            "Synchronizacja zakonczona - nowe: {$l['created']}, zaktualizowane: {$l['updated']}, "
            . "dezaktywowane: {$l['deactivated']}, reaktywowane: {$l['reactivated']}, bledy: {$l['error']}.");
    } catch (\Throwable $e) {
        flash_set('danger', 'Synchronizacja przerwana: ' . $e->getMessage());
    }
}

// ── Obsługa POST (konfigurator: zapisz + wystaw OU + zsynchronizuj) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_and_run') {
    csrf_check();
    $_tab       = 'sync';
    $action_ran = true;

    try {
        $result = ldap_configure_and_run($_POST);

        foreach ($result['sync']['items'] as $item) {
            $msg = $LDAP_ACTION_LABELS[$item['ldap_action']] ?? ($item['ldap_error'] ?? $item['ldap_action']);
            if ($item['graph_action'] === 'updated') {
                $msg .= ' + M365';
            } elseif ($item['graph_action'] === 'error') {
                $msg .= ' (M365: ' . $item['graph_error'] . ')';
            }
            $action_results[] = [
                'ok'    => $item['ldap_action'] !== 'error' && $item['graph_action'] !== 'error',
                'name'  => $item['name'],
                'login' => $item['email'],
                'msg'   => $msg,
            ];
        }

        $l = $result['sync']['summary']['ldap'];
        $g = $result['sync']['summary']['graph'];
        if (function_exists('admin_audit')) {
            admin_audit('ldap_sync', 'ldap', "Konfiguracja zapisana + synchronizacja: utw {$l['created']}, upd {$l['updated']}, err {$l['error']}.", 0);
        }
        $hasErrors = $l['error'] > 0 || $g['error'] > 0;
        flash_set($hasErrors ? 'warning' : 'success',
            "Konfiguracja zapisana. Gałęzie: users_ou {$result['install']['users_ou']}, disabled_ou {$result['install']['disabled_ou']}. "
            . "Synchronizacja — nowe: {$l['created']}, zaktualizowane: {$l['updated']}, "
            . "dezaktywowane: {$l['deactivated']}, reaktywowane: {$l['reactivated']}, bledy: {$l['error']}.");
    } catch (\Throwable $e) {
        // Ustawienia sa juz zapisane w tym momencie (ldap_configure_and_run zapisuje
        // przed probą połączenia) — admin poprawia tylko blędne pole, nie wpisuje wszystkiego od nowa.
        $_tab = 'konfigurator';
        flash_set('danger', 'Zapisano ustawienia, ale uruchomienie nie powiodlo sie: ' . $e->getMessage());
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$ldap_configured = (new LdapDirectory())->is_configured();
$last_sync       = ldap_setting('ldap_last_sync');
$szo_users_total = (int)(db_one('SELECT COUNT(*) AS n FROM users WHERE is_active=1')['n'] ?? 0);

$conn_ok  = false;
$conn_err = '';
if ($ldap_configured) {
    try {
        $t = new LdapDirectory(); $t->connect(); $t->close(); $conn_ok = true;
    } catch (\Throwable $e) { $conn_err = $e->getMessage(); }
}

// Wartości do autokonfiguratora (po stronie PHP — wypełnienie formularza).
// Ustawienie zapisane przez sam Konfigurator ma pierwszeństwo przed stałą
// z config.local.php — to ten sam porządek co w LdapDirectory::__construct().
$cfg_host        = ldap_setting('ldap_host')        ?: (defined('LDAP_HOST')     ? LDAP_HOST     : '');
$cfg_port        = ldap_setting('ldap_port')         ?: (defined('LDAP_PORT')     ? (int)LDAP_PORT : 389);
$cfg_base_dn     = ldap_setting('ldap_base_dn')     ?: (defined('LDAP_BASE_DN')  ? LDAP_BASE_DN  : '');
$cfg_users_ou    = ldap_setting('ldap_users_ou')    ?: (defined('LDAP_USERS_OU') ? LDAP_USERS_OU : '');
$cfg_disabled_ou = ldap_setting('ldap_disabled_ou') ?: (defined('LDAP_DISABLED_OU') ? LDAP_DISABLED_OU : '');
$cfg_bind_dn     = ldap_setting('ldap_bind_dn')     ?: (defined('LDAP_BIND_DN')  ? LDAP_BIND_DN  : '');
$cfg_use_tls     = ldap_setting('ldap_use_tls') !== '' ? ldap_setting('ldap_use_tls') === '1' : (defined('LDAP_USE_TLS') && LDAP_USE_TLS);
$cfg_has_bind_pw = ldap_setting('ldap_bind_pw') !== '' || (defined('LDAP_BIND_PW') && LDAP_BIND_PW !== '');

// Odczyt katalogu (tylko na zakładce katalog) — przez LdapDirectory, żeby
// konfiguracja (ustawienia z bazy albo stałe LDAP_*) była brana z JEDNEGO
// miejsca, tego samego co reszta strony (patrz LdapDirectory::__construct).
$ldap_entries = [];
$ldap_err_msg = '';
if ($_tab === 'katalog' && $ldap_configured) {
    try {
        $dir = new LdapDirectory();
        $dir->connect();
        $ldap_entries = $dir->search_users();
        $dir->close();
    } catch (\Throwable $e) {
        $ldap_err_msg = $e->getMessage();
    }
}

include dirname(__DIR__) . '/tozsamosc/_head.php';
?>
<style>
/* Autokonfigurator */
.cfg-form label{font-size:.8rem;font-weight:600;color:var(--tz-muted);margin-bottom:.2rem;display:block;text-transform:uppercase;letter-spacing:.04em}
.cfg-form input,.cfg-form select{font-size:.85rem;border-radius:7px;border:1.5px solid var(--tz-line);padding:.45rem .75rem;width:100%;background:#fff;color:#111827;transition:border-color .13s,box-shadow .13s;font-family:ui-monospace,SFMono-Regular,monospace}
.cfg-form input:focus,.cfg-form select:focus{border-color:var(--tz);box-shadow:0 0 0 3px rgba(37,99,235,.12);outline:none}
.cfg-form .row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
@media(max-width:580px){.cfg-form .row{grid-template-columns:1fr}}
.cfg-form .row3{grid-template-columns:1fr 1fr 1fr}
@media(max-width:700px){.cfg-form .row3{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.cfg-form .row3{grid-template-columns:1fr}}
.cfg-test-bar{display:flex;align-items:center;gap:.75rem;padding:.75rem 1.1rem;border-top:1px solid var(--tz-line);background:#f9fafb}
.cfg-test-bar button{background:var(--tz);color:#fff;border:none;border-radius:7px;padding:.42rem 1rem;font-size:.84rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem}
.cfg-test-bar button:disabled{opacity:.55;cursor:default}
.cfg-test-result{font-size:.83rem;padding:.3rem .65rem;border-radius:6px;font-weight:500}
.cfg-test-result.ok{background:#ecfdf5;color:#047857}
.cfg-test-result.err{background:#fef2f2;color:#b91c1c}
.cfg-block{background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:.75rem}
.cfg-block__hd{display:flex;align-items:center;justify-content:space-between;padding:.5rem .85rem;background:#fff;border-bottom:1px solid #e5e7eb;font-size:.8rem;font-weight:600;gap:.5rem}
.cfg-block__hd i{color:var(--tz)}
.cfg-block pre{margin:0;padding:.75rem 1rem;font-size:.77rem;line-height:1.55;overflow-x:auto;color:#1e293b;background:transparent}
.cfg-block .dl-row{display:grid;grid-template-columns:160px 1fr;gap:.2rem .6rem;font-size:.82rem;padding:.6rem 1rem}
.cfg-block .dl-row dt{color:var(--tz-muted);font-weight:500}
.cfg-block .dl-row dd{font-family:ui-monospace,monospace;margin:0;word-break:break-all}
.copy-btn{background:none;border:1px solid #d1d5db;border-radius:5px;padding:.15rem .55rem;font-size:.72rem;cursor:pointer;color:#6b7280;flex-shrink:0}
.copy-btn:hover{background:#f3f4f6;color:#111}
</style>

<div class="tz-h">
  <h1><i class="bi bi-diagram-3" aria-hidden="true"></i> Katalog LDAP</h1>
  <p>Synchronizacja i przeglądanie katalogu OpenLDAP. Konfiguracja blokow dla innych uslug.</p>
</div>

<nav class="tz-subnav" aria-label="Sekcje LDAP">
  <div class="seg" role="tablist">
    <?php foreach ([
        'sync'         => ['bi-arrow-repeat', 'Synchronizacja'],
        'katalog'      => ['bi-people',        'Katalog'],
        'konfigurator' => ['bi-sliders',       'Konfigurator'],
    ] as $k => [$ico, $lbl]): ?>
    <a href="?tab=<?= $k ?>" role="tab" aria-selected="<?= $_tab === $k ? 'true' : 'false' ?>"
       class="<?= $_tab === $k ? 'on' : '' ?>">
      <i class="bi <?= $ico ?>" aria-hidden="true"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>
  </div>
</nav>

<?php flash_show(); ?>

<?php if (!$ldap_configured && $_tab !== 'konfigurator'): ?>
<div class="tz-card mb-3" style="border-color:#fde68a;background:#fffbeb">
  <div class="tz-card__bd">
    <div class="d-flex align-items-start gap-2">
      <i class="bi bi-exclamation-triangle-fill text-warning fs-5 mt-1"></i>
      <div>
        <strong>LDAP nie jest skonfigurowany</strong><br>
        <span class="small text-muted">
          Uzupelnij parametry w <a href="?tab=konfigurator" class="text-primary">Konfiguratorze</a>,
          a nastepnie przepisz wygenerowany blok do <code>config.local.php</code>.
        </span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SYNCHRONIZACJA
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($_tab === 'sync'): ?>

<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-activity"></i> Status</div>
  <div class="tz-dl">
    <div>
      <dt>Polaczenie</dt>
      <dd><?php if (!$ldap_configured): ?>
        <span class="tz-badge tz-badge--off"><i class="bi bi-dash-circle"></i> Niekonfigurowany</span>
      <?php elseif ($conn_ok): ?>
        <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill"></i> OK</span>
      <?php else: ?>
        <span class="tz-badge tz-badge--warn"><i class="bi bi-x-circle-fill"></i> Blad</span>
      <?php endif; ?></dd>
    </div>
    <div><dt>Serwer</dt><dd class="font-monospace small"><?= h($cfg_host ?: '—') ?>:<?= $cfg_port ?></dd></div>
    <div><dt>Ostatnia synchronizacja</dt><dd><?= $last_sync ? h($last_sync) : '<span class="text-muted">brak</span>' ?></dd></div>
    <div><dt>Aktywnych kont SZO</dt><dd><?= $szo_users_total ?></dd></div>
    <div><dt>Kierunek</dt><dd>SZO &rarr; LDAP <span class="text-muted small">(hasla nie sa eksportowane)</span></dd></div>
    <?php if (!$conn_ok && $conn_err): ?>
    <div style="grid-column:1/-1"><dt>Blad polaczenia</dt><dd class="text-danger small font-monospace"><?= h($conn_err) ?></dd></div>
    <?php endif; ?>
  </div>
  <div class="tz-note"><i class="bi bi-info-circle"></i> Synchronizacja nigdy nie kasuje wpisow z katalogu — dezaktywacja przenosi konto do <code><?= h(defined('LDAP_DISABLED_OU') ? LDAP_DISABLED_OU : 'ou=disabled') ?></code>.</div>
</div>

<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-arrow-repeat"></i> Synchronizuj teraz</div>
  <div class="tz-card__bd">
    <?php if (!$ldap_configured): ?>
      <p class="text-muted small mb-0">Najpierw skonfiguruj polaczenie LDAP w zakładce <a href="?tab=konfigurator">Konfigurator</a>.</p>
    <?php else: ?>
    <p class="small text-muted mb-3">
      Eksportuje wszystkie konta do katalogu LDAP: aktywne sa tworzone/aktualizowane,
      nieaktywne przenoszone do galezi dezaktywowanych. Konta juz powiazane z M365
      (<code>microsoft_id</code>) sa dodatkowo aktualizowane w Entra ID (status + nazwa).
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="sync">
      <button type="submit" class="tz-btn"
              onclick="this.innerHTML='<i class=\'bi bi-hourglass-split\'></i> Synchronizuje&hellip;';this.disabled=true;this.form.submit()">
        <i class="bi bi-arrow-repeat"></i> Synchronizuj wszystkie konta
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($action_ran && $action_results): ?>
<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-list-check"></i> Wyniki
    <span class="ms-auto small fw-normal text-muted">
      <?= count(array_filter($action_results, fn($r) => $r['ok'])) ?> OK,
      <?= count(array_filter($action_results, fn($r) => !$r['ok'])) ?> bledow
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr><th class="ps-3">Uzytkownik</th><th>Dzialanie</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($action_results as $r): ?>
      <tr>
        <td class="ps-3"><span class="fw-semibold"><?= h($r['name']) ?></span><span class="d-block text-muted small"><?= h($r['login']) ?></span></td>
        <td class="text-muted small"><?= $r['ok'] ? h($r['msg']) : '' ?></td>
        <td>
          <?php if ($r['ok']): ?>
            <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill"></i> OK</span>
          <?php else: ?>
            <span class="tz-badge tz-badge--warn"><i class="bi bi-x-circle-fill"></i> Blad</span>
            <div class="small text-danger font-monospace" style="font-size:.75rem"><?= h($r['msg']) ?></div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     KATALOG — read-only
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($_tab === 'katalog'): ?>

<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-people"></i>
    Konta w katalogu LDAP
    <?php if ($ldap_entries): ?>
    <span class="ms-2 tz-badge tz-badge--ok"><?= count($ldap_entries) ?></span>
    <?php endif; ?>
    <span class="ms-auto small fw-normal text-muted font-monospace"><?= h($cfg_users_ou ?: '—') ?></span>
  </div>

  <?php if (!$ldap_configured): ?>
  <div class="tz-card__bd text-muted small">LDAP niekonfigurowany. Uzupelnij parametry w <a href="?tab=konfigurator">Konfiguratorze</a>.</div>
  <?php elseif ($ldap_err_msg): ?>
  <div class="tz-card__bd">
    <div class="d-flex align-items-start gap-2">
      <i class="bi bi-x-octagon-fill text-danger mt-1"></i>
      <span class="small text-danger font-monospace"><?= h($ldap_err_msg) ?></span>
    </div>
  </div>
  <?php elseif (!$ldap_entries): ?>
  <div class="tz-card__bd text-muted small">
    Katalog jest pusty lub nie udalo sie odczytac. Upewnij sie, ze LDAP jest dostepny
    i ze konta zostaly zsynchronizowane (<a href="?tab=sync">Synchronizacja</a>).
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:28%">Imie i nazwisko</th>
          <th style="width:8%">uid</th>
          <th style="width:26%">E-mail</th>
          <th>Jednostka / stanowisko</th>
          <th style="width:10%">Telefon</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ldap_entries as $e): ?>
      <tr>
        <td class="ps-3 fw-semibold"><?= h($e['cn']) ?></td>
        <td class="font-monospace small text-muted"><?= h($e['uid']) ?></td>
        <td class="small"><?= h($e['mail']) ?></td>
        <td class="small text-muted">
          <?php echo $e['title'] || $e['ou']
              ? h($e['title']) . ($e['title'] && $e['ou'] ? ' &middot; ' : '') . h($e['ou'])
              : '&mdash;'; ?>
        </td>
        <td class="small text-muted"><?= $e['phone'] ? h($e['phone']) : '&mdash;' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="tz-note">
    <i class="bi bi-info-circle"></i>
    Widok tylko do odczytu. Aby dodac konta uzyj <a href="?tab=sync" class="text-primary">Synchronizacji</a>.
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     KONFIGURATOR — formularz + live bloki
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($_tab === 'konfigurator'): ?>

<form method="post" id="cfg-form-el">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_action" value="save_and_run">
<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-sliders"></i> Parametry serwera LDAP</div>
  <div class="tz-card__bd cfg-form">
    <div class="row mb-3">
      <div>
        <label for="f-host">Host</label>
        <div style="display:flex;gap:.4rem;align-items:center">
          <input id="f-host" name="host" class="cfg-in" data-k="host" type="text" value="<?= h($cfg_host) ?>" placeholder="127.0.0.1" style="flex:1;min-width:0">
          <button type="button" id="btn-host-ext" title="Zewnętrzny (poza Dockerem)" class="copy-btn" style="white-space:nowrap">127.0.0.1</button>
          <button type="button" id="btn-host-docker" title="Nazwa serwisu Docker Compose" class="copy-btn" style="white-space:nowrap;background:var(--tz-50);border-color:var(--tz)">ldap</button>
        </div>
        <div class="mt-1" style="font-size:.73rem;color:var(--tz-muted)">
          Jesli PHP dziala w Dockerze, uzyj <strong>ldap</strong> (nazwa serwisu), nie 127.0.0.1
        </div>
      </div>
      <div>
        <label for="f-port">Port</label>
        <input id="f-port" name="port" class="cfg-in" data-k="port" type="number" value="<?= $cfg_port ?>" placeholder="389">
      </div>
    </div>
    <div class="row row3 mb-3">
      <div>
        <label for="f-base-dn">Base DN</label>
        <input id="f-base-dn" name="base_dn" class="cfg-in" data-k="base_dn" type="text" value="<?= h($cfg_base_dn) ?>" placeholder="dc=feer,dc=org,dc=pl">
      </div>
      <div>
        <label for="f-users-ou">Users OU</label>
        <input id="f-users-ou" name="users_ou" class="cfg-in" data-k="users_ou" type="text" value="<?= h($cfg_users_ou) ?>" placeholder="ou=users,dc=feer,dc=org,dc=pl">
      </div>
      <div>
        <label for="f-disabled-ou">Disabled OU (dezaktywowane)</label>
        <input id="f-disabled-ou" name="disabled_ou" class="cfg-in" data-k="disabled_ou" type="text" value="<?= h($cfg_disabled_ou) ?>" placeholder="ou=disabled,dc=feer,dc=org,dc=pl">
      </div>
    </div>
    <div class="row mb-2">
      <div>
        <label for="f-bind-dn">Bind DN</label>
        <input id="f-bind-dn" name="bind_dn" class="cfg-in" data-k="bind_dn" type="text" value="<?= h($cfg_bind_dn) ?>" placeholder="cn=admin,dc=feer,dc=org,dc=pl">
      </div>
      <div>
        <label for="f-bind-pw">Hasło</label>
        <input id="f-bind-pw" name="bind_pw" class="cfg-in" data-k="bind_pw" type="password" value=""
               autocomplete="new-password"
               placeholder="<?= $cfg_has_bind_pw ? '(zapisane — zostaw puste by nie zmieniać)' : 'haslo konta serwisowego' ?>">
      </div>
    </div>
    <div class="mb-1">
      <label style="display:inline-flex;align-items:center;gap:.4rem;text-transform:none;font-weight:500;font-size:.85rem;color:inherit">
        <input type="checkbox" name="use_tls" value="1" class="cfg-in" data-k="use_tls" <?= $cfg_use_tls ? 'checked' : '' ?>>
        STARTTLS (LDAP_USE_TLS)
      </label>
    </div>
  </div>
  <div class="cfg-test-bar">
    <button type="button" id="test-btn">
      <i class="bi bi-plug"></i> Testuj polaczenie
    </button>
    <span id="test-result"></span>
    <button type="submit" class="tz-btn ms-auto"
            onclick="return confirm('Zapisac ustawienia, utworzyc galezie (users/disabled) i od razu uruchomic pelna synchronizacje z LDAP i M365?');">
      <i class="bi bi-rocket-takeoff"></i> Zapisz i uruchom synchronizacje
    </button>
  </div>
  <div class="tz-note">
    <i class="bi bi-info-circle"></i>
    Zapisuje parametry do bazy (bez SSH / edycji config.local.php), tworzy galezie
    <code>users</code>/<code>disabled</code> jesli brak, i od razu wykonuje pelna
    synchronizacje — wynik zobaczysz w zakladce <a href="?tab=sync">Synchronizacja</a>.
  </div>
</div>
</form>

<!-- Bloki konfiguracyjne -->
<div id="cfg-blocks">

  <!-- SZO config.local.php -->
  <div class="cfg-block mb-3">
    <div class="cfg-block__hd">
      <span><i class="bi bi-code-slash"></i> SZO &mdash; config.local.php</span>
      <button class="copy-btn" data-target="blk-szo">Kopiuj</button>
    </div>
    <pre id="blk-szo"></pre>
  </div>

  <!-- Gitea -->
  <div class="cfg-block mb-3">
    <div class="cfg-block__hd">
      <span><i class="bi bi-git"></i> Gitea &mdash; Admin &rarr; Authentication &rarr; LDAP (Bind DN)</span>
      <button class="copy-btn" data-target="blk-gitea-pre">Kopiuj</button>
    </div>
    <div class="dl-row" id="blk-gitea"></div>
  </div>

  <!-- Nextcloud -->
  <div class="cfg-block mb-3">
    <div class="cfg-block__hd">
      <span><i class="bi bi-cloud"></i> Nextcloud &mdash; Settings &rarr; LDAP/AD Integration</span>
      <button class="copy-btn" data-target="blk-nc-pre">Kopiuj</button>
    </div>
    <div class="dl-row" id="blk-nc"></div>
  </div>

  <!-- .env -->
  <div class="cfg-block mb-3">
    <div class="cfg-block__hd">
      <span><i class="bi bi-file-earmark-code"></i> Generic .env / docker-compose</span>
      <button class="copy-btn" data-target="blk-env">Kopiuj</button>
    </div>
    <pre id="blk-env"></pre>
  </div>

  <!-- nginx -->
  <div class="cfg-block mb-3">
    <div class="cfg-block__hd">
      <span><i class="bi bi-server"></i> nginx &mdash; ngx_http_auth_ldap_module</span>
      <button class="copy-btn" data-target="blk-nginx">Kopiuj</button>
    </div>
    <pre id="blk-nginx"></pre>
  </div>

</div><!-- /#cfg-blocks -->

<script>
(function() {
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var SELF = <?= json_encode($SELF) ?>;

  function v(k) {
    var el = document.querySelector('.cfg-in[data-k="' + k + '"]');
    return el ? el.value.trim() : '';
  }

  function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function dl(pairs) {
    return '<dl class="dl-row">' + pairs.map(function(p) {
      return '<dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd>';
    }).join('') + '</dl>';
  }

  // Ukryte pola do kopii tekstu z dl-row
  var hiddenPre = {};

  function updateBlocks() {
    var host    = v('host')     || '127.0.0.1';
    var port    = v('port')     || '389';
    var baseDn     = v('base_dn') || 'dc=example,dc=org';
    var usersOu    = v('users_ou')|| 'ou=users,' + baseDn;
    var disabledOu = v('disabled_ou') || 'ou=disabled,' + baseDn;
    var bindDn     = v('bind_dn') || 'cn=admin,' + baseDn;
    var docker     = 'ldap';

    // SZO — opcjonalne: przycisk "Zapisz i uruchom" wyzej zapisuje te same
    // parametry do bazy, wiec edycja config.local.php nie jest wymagana.
    // Ten blok zostaje dla instalacji, ktore wola trzymac konfiguracje w pliku.
    var szoTxt =
      "define('LDAP_ENABLED',  true);\n" +
      "define('LDAP_HOST',     '127.0.0.1');  // Docker internal: '" + docker + "'\n" +
      "define('LDAP_PORT',     " + port + ");\n" +
      "define('LDAP_BIND_DN',  '" + bindDn + "');\n" +
      "define('LDAP_BIND_PW',  '...');  // LDAP_ADMIN_PASSWORD z docker/.env\n" +
      "define('LDAP_BASE_DN',  '" + baseDn + "');\n" +
      "define('LDAP_USERS_OU', '" + usersOu + "');\n" +
      "define('LDAP_DISABLED_OU', '" + disabledOu + "');";
    document.getElementById('blk-szo').textContent = szoTxt;

    // Gitea
    var giteaPairs = [
      ['Authentication Type', 'LDAP (Bind DN)'],
      ['Host',                host],
      ['Port',                port],
      ['Bind DN',             bindDn],
      ['Bind Password',       '(LDAP_ADMIN_PASSWORD)'],
      ['User Search Base',    usersOu],
      ['User Filter',         '(&(objectClass=inetOrgPerson)(uid=%s))'],
      ['Username Attr',       'uid'],
      ['Firstname Attr',      'givenName'],
      ['Surname Attr',        'sn'],
      ['Email Attr',          'mail'],
    ];
    document.getElementById('blk-gitea').innerHTML = dl(giteaPairs);
    hiddenPre['blk-gitea-pre'] = giteaPairs.map(function(p){ return p[0]+': '+p[1]; }).join('\n');

    // Nextcloud
    var ncPairs = [
      ['Server',          'ldap://' + host + ':' + port],
      ['Port',            port],
      ['User DN',         bindDn],
      ['Password',        '(LDAP_ADMIN_PASSWORD)'],
      ['Base DN',         baseDn],
      ['Users filter',    '(|(objectclass=inetOrgPerson))'],
      ['Login attribute', 'uid'],
      ['Email attribute', 'mail'],
      ['Display name',    'cn'],
    ];
    document.getElementById('blk-nc').innerHTML = dl(ncPairs);
    hiddenPre['blk-nc-pre'] = ncPairs.map(function(p){ return p[0]+': '+p[1]; }).join('\n');

    // .env
    var envTxt =
      'LDAP_URL=ldap://' + docker + ':389\n' +
      'LDAP_BASE_DN=' + baseDn + '\n' +
      'LDAP_BIND_DN=' + bindDn + '\n' +
      'LDAP_BIND_PASSWORD=(LDAP_ADMIN_PASSWORD)\n' +
      'LDAP_USERS_BASE=' + usersOu + '\n' +
      'LDAP_USER_FILTER=(objectClass=inetOrgPerson)\n' +
      'LDAP_USER_LOGIN_ATTR=uid\n' +
      'LDAP_USER_EMAIL_ATTR=mail\n' +
      'LDAP_USER_DISPLAY_ATTR=cn';
    document.getElementById('blk-env').textContent = envTxt;

    // nginx
    var nginxTxt =
      'ldap_server feer_ldap {\n' +
      '    url ldap://' + host + ':' + port + '/' + usersOu + '?uid?sub?(objectClass=inetOrgPerson);\n' +
      '    binddn "' + bindDn + '";\n' +
      '    binddn_passwd "(LDAP_ADMIN_PASSWORD)";\n' +
      '    group_attribute uniqueMember;\n' +
      '    group_attribute_is_dn on;\n' +
      '    require valid_user;\n' +
      '}';
    document.getElementById('blk-nginx').textContent = nginxTxt;
  }

  // Podpinamy live update
  document.querySelectorAll('.cfg-in').forEach(function(el) {
    el.addEventListener('input', updateBlocks);
  });
  updateBlocks();

  // Przyciski Kopiuj
  document.querySelectorAll('.copy-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var target = btn.dataset.target;
      var txt;
      if (hiddenPre[target] !== undefined) {
        txt = hiddenPre[target];
      } else {
        var el = document.getElementById(target);
        txt = el ? el.textContent : '';
      }
      navigator.clipboard.writeText(txt).then(function() {
        var orig = btn.textContent;
        btn.textContent = 'Skopiowano';
        setTimeout(function(){ btn.textContent = orig; }, 1400);
      });
    });
  });

  // Przełącznik hosta: zewnętrzny / Docker
  var extBtn    = document.getElementById('btn-host-ext');
  var dockerBtn = document.getElementById('btn-host-docker');
  var hostInput = document.getElementById('f-host');
  if (extBtn) {
    extBtn.addEventListener('click', function() {
      hostInput.value = '127.0.0.1';
      updateBlocks();
    });
  }
  if (dockerBtn) {
    dockerBtn.addEventListener('click', function() {
      hostInput.value = 'ldap';
      updateBlocks();
    });
  }

  // Test polaczenia
  document.getElementById('test-btn').addEventListener('click', function() {
    var btn    = this;
    var result = document.getElementById('test-result');
    var host2  = v('host');
    var port2  = v('port') || '389';
    var dn     = v('bind_dn');
    var pw     = v('bind_pw');

    if (!pw) {
      result.className = 'cfg-test-result err';
      result.textContent = 'Wpisz haslo w polu "Haslo (do testu polaczenia)".';
      return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Lacze&hellip;';
    result.className = '';
    result.textContent = '';

    var fd = new FormData();
    fd.append('_csrf',    CSRF);
    fd.append('_action',  'test_conn');
    fd.append('host',     host2);
    fd.append('port',     port2);
    fd.append('bind_dn',  dn);
    fd.append('bind_pw',  pw);

    fetch(SELF, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data) {
        result.className = 'cfg-test-result ' + (data.ok ? 'ok' : 'err');
        result.textContent = (data.ok ? '✔ ' : '✖ ') + data.msg;
      })
      .catch(function() {
        result.className = 'cfg-test-result err';
        result.textContent = 'Blad zadania HTTP.';
      })
      .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug"></i> Testuj polaczenie';
      });
  });
})();
</script>

<?php endif; // tab ?>

<?php include dirname(__DIR__) . '/tozsamosc/_foot.php'; ?>
