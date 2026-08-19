<?php
/**
 * tozsamosc/ldap.php — Zarządzanie katalogiem LDAP.
 *
 * Zakładki:
 *   sync    — Synchronizacja (eksport SZO→LDAP, historia)
 *   katalog — Przeglądarka katalogu (live odczyt z OpenLDAP)
 *   config  — Parametry serwera + gotowe bloki config dla innych usług
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

$PAGE_TITLE = 'Zarządzanie LDAP';
$TZ_ACTIVE  = 'administracja';
$SELF       = APP_URL . '/tozsamosc/ldap.php';

$_tab = in_array($_GET['tab'] ?? '', ['sync', 'katalog', 'config']) ? ($_GET['tab'] ?? 'sync') : 'sync';

// ── Helpery ───────────────────────────────────────────────────────────────────

/** Połącz i zbinduj — null gdy LDAP niekonfigurowny lub błąd. */
function _ldap_open(): ?\LdapDirectory
{
    $ldap = new LdapDirectory();
    if (!$ldap->is_configured()) return null;
    try { $ldap->connect(); return $ldap; } catch (\Throwable $e) { return null; }
}

/** Odczytaj wszystkie wpisy z OU użytkowników; zwraca tablicę asoc lub [] przy błędzie. */
function _ldap_list_users(): array
{
    $ldap = new LdapDirectory();
    if (!$ldap->is_configured()) return [];
    try {
        $ldap->connect();
    } catch (\Throwable $e) {
        return [];
    }

    $ou = defined('LDAP_USERS_OU') ? LDAP_USERS_OU : '';
    if ($ou === '') { $ldap->close(); return []; }

    // Budujemy połączenie przez refleksję (conn jest private).
    // Obejście: duplikujemy minimalny bind lokalnie.
    $host = defined('LDAP_HOST') ? LDAP_HOST : '';
    $port = defined('LDAP_PORT') ? (int)LDAP_PORT : 389;
    $dn   = defined('LDAP_BIND_DN') ? LDAP_BIND_DN : '';
    $pw   = defined('LDAP_BIND_PW') ? LDAP_BIND_PW : '';

    $conn = @ldap_connect(sprintf('ldap://%s:%d', $host, $port));
    if (!$conn) { $ldap->close(); return []; }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
    if (!@ldap_bind($conn, $dn, $pw)) { @ldap_unbind($conn); $ldap->close(); return []; }

    $res = @ldap_search($conn, $ou, '(objectClass=inetOrgPerson)',
        ['uid', 'cn', 'mail', 'telephoneNumber', 'title', 'ou', 'displayName', 'givenName', 'sn', 'employeeNumber']);
    $entries = [];
    if ($res && ldap_count_entries($conn, $res) > 0) {
        $all = ldap_get_entries($conn, $res);
        for ($i = 0; $i < ($all['count'] ?? 0); $i++) {
            $e = $all[$i];
            $entries[] = [
                'dn'          => $e['dn'] ?? '',
                'uid'         => $e['uid'][0] ?? '',
                'cn'          => $e['cn'][0] ?? ($e['displayname'][0] ?? ''),
                'mail'        => $e['mail'][0] ?? '',
                'phone'       => $e['telephonenumber'][0] ?? '',
                'title'       => $e['title'][0] ?? '',
                'ou'          => $e['ou'][0] ?? '',
            ];
        }
        usort($entries, fn($a, $b) => strcmp($a['cn'], $b['cn']));
    }

    @ldap_unbind($conn);
    $ldap->close();
    return $entries;
}

/** Usuń wpis po DN; zwraca true/false. */
function _ldap_delete_dn(string $dn): bool
{
    $host = defined('LDAP_HOST') ? LDAP_HOST : '';
    $port = defined('LDAP_PORT') ? (int)LDAP_PORT : 389;
    $bdn  = defined('LDAP_BIND_DN') ? LDAP_BIND_DN : '';
    $bpw  = defined('LDAP_BIND_PW') ? LDAP_BIND_PW : '';
    $conn = @ldap_connect(sprintf('ldap://%s:%d', $host, $port));
    if (!$conn) return false;
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if (!@ldap_bind($conn, $bdn, $bpw)) { @ldap_unbind($conn); return false; }
    $ok = @ldap_delete($conn, $dn);
    @ldap_unbind($conn);
    return (bool) $ok;
}

// ── Obsługa POST ───────────────────────────────────────────────────────────────

$action_results = [];
$action_ran     = false;
$action_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // ── Pełna synchronizacja ─────────────────────────────────────────────────
    if ($action === 'sync') {
        $_tab        = 'sync';
        $action_ran  = true;
        $created = $updated = $failed = 0;

        try {
            $ldap = new LdapDirectory();
            if (!$ldap->is_configured()) {
                throw new RuntimeException('LDAP nie jest skonfigurowany (uzupełnij LDAP_* w config.local.php).');
            }
            $ldap->connect();

            foreach (ldap_collect_users() as $user) {
                try {
                    $act = $ldap->upsert_user($user);
                    $act === 'created' ? $created++ : $updated++;
                    $action_results[] = ['ok' => true,  'name' => $user['name'] ?? '', 'login' => $user['email'] ?? '', 'msg' => $act === 'created' ? 'Nowy wpis' : 'Zaktualizowano'];
                } catch (\Throwable $e) {
                    $failed++;
                    error_log('[LDAP sync] uid=' . ($user['id'] ?? '?') . ': ' . $e->getMessage());
                    $action_results[] = ['ok' => false, 'name' => $user['name'] ?? '', 'login' => $user['email'] ?? '', 'msg' => $e->getMessage()];
                }
            }

            $ldap->close();
            ldap_save_setting('ldap_last_sync', date('Y-m-d H:i:s'));
            if (function_exists('admin_audit')) {
                admin_audit('ldap_sync', 'ldap', "Utwórzono: {$created}, zaktualizowano: {$updated}, błędy: {$failed}.", 0);
            }
            flash_set($failed > 0 ? 'warning' : 'success', "Synchronizacja zakończona — nowe: {$created}, zaktualizowane: {$updated}, błędy: {$failed}.");
        } catch (\Throwable $e) {
            flash_set('danger', 'Synchronizacja przerwana: ' . $e->getMessage());
            $action_error = $e->getMessage();
        }
    }

    // ── Usuń wpis z LDAP ──────────────────────────────────────────────────────
    if ($action === 'delete_dn') {
        $_tab = 'katalog';
        $target_dn = trim($_POST['dn'] ?? '');
        if ($target_dn === '') {
            flash_set('warning', 'Brak DN do usunięcia.');
        } else {
            $ok = _ldap_delete_dn($target_dn);
            if ($ok) {
                if (function_exists('admin_audit')) {
                    admin_audit('ldap_delete', 'ldap', 'Usunięto DN: ' . $target_dn, 0);
                }
                flash_set('success', 'Wpis usunięty z katalogu LDAP.');
            } else {
                flash_set('danger', 'Nie udało się usunąć wpisu (sprawdź uprawnienia bind DN lub poprawność DN).');
            }
        }
        header("Location: {$SELF}?tab=katalog");
        exit;
    }

    // ── Wypchnij pojedynczego użytkownika ────────────────────────────────────
    if ($action === 'push_user') {
        $_tab      = 'katalog';
        $uid       = (int) ($_POST['user_id'] ?? 0);
        $user_row  = $uid > 0 ? ldap_user_row($uid) : null;
        if (!$user_row) {
            flash_set('warning', 'Nie znaleziono użytkownika.');
        } else {
            try {
                $ldap = new LdapDirectory();
                $ldap->connect();
                $act  = $ldap->upsert_user($user_row);
                $ldap->close();
                flash_set('success', 'Konto "' . $user_row['name'] . '" — ' . $act . ' w LDAP.');
                if (function_exists('admin_audit')) {
                    admin_audit('ldap_push', 'ldap', "uid={$uid} ({$user_row['name']}): {$act}", $uid);
                }
            } catch (\Throwable $e) {
                flash_set('danger', 'Błąd: ' . $e->getMessage());
            }
        }
        header("Location: {$SELF}?tab=katalog");
        exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────

$ldap_configured = (new LdapDirectory())->is_configured();
$last_sync       = ldap_setting('ldap_last_sync');
$szo_users_total = (int) (db_one('SELECT COUNT(*) AS n FROM users WHERE is_active=1')['n'] ?? 0);

// Parametry do wyświetlenia
$cfg = [
    'Host'         => (defined('LDAP_HOST') ? LDAP_HOST : '') . ':' . (defined('LDAP_PORT') ? LDAP_PORT : 389),
    'Base DN'      => defined('LDAP_BASE_DN')  ? LDAP_BASE_DN  : '—',
    'Users OU'     => defined('LDAP_USERS_OU') ? LDAP_USERS_OU : '—',
    'Bind DN'      => defined('LDAP_BIND_DN')  ? LDAP_BIND_DN  : '—',
    'STARTTLS'     => (defined('LDAP_USE_TLS') && LDAP_USE_TLS) ? 'tak' : 'nie',
    'phpLDAPadmin' => 'http://127.0.0.1:8389/',
];

// Test połączenia (lekki)
$conn_ok  = false;
$conn_err = '';
if ($ldap_configured) {
    try {
        $ldap_tmp = new LdapDirectory();
        $ldap_tmp->connect();
        $ldap_tmp->close();
        $conn_ok = true;
    } catch (\Throwable $e) {
        $conn_err = $e->getMessage();
    }
}

include dirname(__DIR__) . '/tozsamosc/_head.php';
?>

<div class="tz-h">
  <h1><i class="bi bi-diagram-3" aria-hidden="true"></i> Katalog LDAP</h1>
  <p>Synchronizacja i zarządzanie katalogiem OpenLDAP — jednokierunkowy eksport kont SZO.</p>
</div>

<!-- Subnav -->
<nav class="tz-subnav" aria-label="Sekcje LDAP">
  <div class="seg" role="tablist">
    <?php foreach (['sync' => ['bi-arrow-repeat','Synchronizacja'], 'katalog' => ['bi-people','Katalog'], 'config' => ['bi-gear','Konfiguracja']] as $k => [$ico, $lbl]): ?>
    <a href="?tab=<?= $k ?>" role="tab" aria-selected="<?= $_tab === $k ? 'true' : 'false' ?>"
       class="<?= $_tab === $k ? 'on' : '' ?>" id="tz-tab-<?= $k ?>">
      <i class="bi <?= $ico ?>" aria-hidden="true"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>
  </div>
</nav>

<?php flash_show(); ?>

<?php if (!$ldap_configured): ?>
<div class="tz-card" style="border-color:#fde68a;background:#fffbeb">
  <div class="tz-card__bd">
    <div class="d-flex align-items-start gap-2">
      <i class="bi bi-exclamation-triangle-fill text-warning fs-5 mt-1" aria-hidden="true"></i>
      <div>
        <strong>LDAP nie jest skonfigurowany</strong><br>
        <span class="small text-muted">Uzupełnij stałe <code>LDAP_HOST</code>, <code>LDAP_BIND_DN</code>, <code>LDAP_BIND_PW</code>, <code>LDAP_BASE_DN</code>, <code>LDAP_USERS_OU</code>
        w pliku <code>config.local.php</code>, a następnie sprawdź zakładkę <strong>Konfiguracja</strong>.</span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ZAKŁADKA: SYNCHRONIZACJA
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($_tab === 'sync'): ?>

<!-- Status -->
<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-activity"></i> Status</div>
  <div class="tz-dl">
    <div>
      <dt>Połączenie</dt>
      <dd>
        <?php if (!$ldap_configured): ?>
          <span class="tz-badge tz-badge--off"><i class="bi bi-dash-circle"></i> Niekonfigurowany</span>
        <?php elseif ($conn_ok): ?>
          <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill"></i> OK</span>
        <?php else: ?>
          <span class="tz-badge tz-badge--warn"><i class="bi bi-x-circle-fill"></i> Błąd</span>
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt>Serwer</dt>
      <dd class="font-monospace small"><?= h($cfg['Host']) ?></dd>
    </div>
    <div>
      <dt>Ostatnia synchronizacja</dt>
      <dd><?= $last_sync ? h($last_sync) : '<span class="text-muted">brak</span>' ?></dd>
    </div>
    <div>
      <dt>Aktywnych kont SZO</dt>
      <dd><?= $szo_users_total ?></dd>
    </div>
    <div>
      <dt>Kierunek</dt>
      <dd>SZO → LDAP <span class="text-muted small">(hasła nie są eksportowane)</span></dd>
    </div>
    <?php if (!$conn_ok && $conn_err): ?>
    <div style="grid-column:1/-1">
      <dt>Błąd połączenia</dt>
      <dd class="text-danger small font-monospace"><?= h($conn_err) ?></dd>
    </div>
    <?php endif; ?>
  </div>
  <div class="tz-note">
    <i class="bi bi-info-circle"></i>
    Synchronizacja jest addytywna — nie usuwa istniejących wpisów z katalogu.
    Aby wyeksportować konto indywidualnie, użyj zakładki <strong>Katalog</strong>.
  </div>
</div>

<!-- Przycisk sync -->
<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-arrow-repeat"></i> Synchronizuj teraz</div>
  <div class="tz-card__bd">
    <?php if (!$ldap_configured): ?>
      <p class="text-muted small mb-0">Najpierw skonfiguruj połączenie LDAP.</p>
    <?php else: ?>
    <p class="small text-muted mb-3">
      Eksportuje wszystkie aktywne konta (<code>users.is_active=1</code>) do katalogu LDAP.
      Istniejące wpisy są aktualizowane; nowe tworzone.
    </p>
    <form method="post" id="sync-form">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="sync">
      <button type="submit" class="tz-btn" id="sync-btn"
              onclick="this.textContent='Synchronizuję…';this.disabled=true;this.form.submit()">
        <i class="bi bi-arrow-repeat"></i> Synchronizuj wszystkie konta
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Wyniki ostatniej operacji (po POST) -->
<?php if ($action_ran && $action_results): ?>
<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-list-check"></i>
    Wyniki
    <span class="ms-auto small fw-normal text-muted">
      <?= count(array_filter($action_results, fn($r) => $r['ok'])) ?> OK,
      <?= count(array_filter($action_results, fn($r) => !$r['ok'])) ?> błędów
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr><th class="ps-3">Użytkownik</th><th>Działanie</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($action_results as $r): ?>
      <tr>
        <td class="ps-3">
          <span class="fw-semibold"><?= h($r['name']) ?></span>
          <span class="d-block text-muted small"><?= h($r['login']) ?></span>
        </td>
        <td class="text-muted small"><?= $r['ok'] ? h($r['msg']) : '' ?></td>
        <td>
          <?php if ($r['ok']): ?>
            <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill"></i> OK</span>
          <?php else: ?>
            <span class="tz-badge tz-badge--warn"><i class="bi bi-x-circle-fill"></i> Błąd</span>
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
     ZAKŁADKA: KATALOG
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($_tab === 'katalog'): ?>

<?php
$ldap_entries  = $ldap_configured ? _ldap_list_users() : [];
$szo_users_all = db_all('SELECT id, name, email, is_active FROM users ORDER BY name');
// Indeks email→uid dla porównania co jest / czego nie ma w LDAP
$in_ldap = array_flip(array_column($ldap_entries, 'uid'));
?>

<!-- Konta w katalogu -->
<div class="tz-card mb-3">
  <div class="tz-card__hd">
    <i class="bi bi-people"></i>
    Konta w katalogu LDAP
    <span class="ms-2 tz-badge tz-badge--<?= count($ldap_entries) > 0 ? 'ok' : 'off' ?>">
      <?= count($ldap_entries) ?>
    </span>
    <span class="ms-auto small fw-normal text-muted">
      <?= defined('LDAP_USERS_OU') ? h(LDAP_USERS_OU) : '—' ?>
    </span>
  </div>

  <?php if (!$ldap_configured): ?>
  <div class="tz-card__bd text-muted small">LDAP niekonfigurowany.</div>
  <?php elseif (!$ldap_entries): ?>
  <div class="tz-card__bd text-muted small">
    Katalog jest pusty lub nie udało się odczytać. Upewnij się, że LDAP jest dostępny i że konta zostały zsynchronizowane.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Imię i nazwisko</th>
          <th>uid</th>
          <th>E-mail</th>
          <th>Jednostka / stanowisko</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ldap_entries as $e): ?>
      <tr>
        <td class="ps-3 fw-semibold"><?= h($e['cn']) ?></td>
        <td class="font-monospace small text-muted"><?= h($e['uid']) ?></td>
        <td class="small"><?= h($e['mail']) ?></td>
        <td class="small text-muted">
          <?php if ($e['title'] || $e['ou']): ?>
            <?= h($e['title']) ?><?= ($e['title'] && $e['ou']) ? ' · ' : '' ?><?= h($e['ou']) ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="text-end pe-3">
          <form method="post" class="d-inline"
                onsubmit="return confirm('Usunąć wpis <?= h(addslashes($e['cn'])) ?> z katalogu LDAP? Nie usuwa konta w SZO.')">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="_action"  value="delete_dn">
            <input type="hidden" name="dn"       value="<?= h($e['dn']) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń wpis z LDAP">
              <i class="bi bi-trash3"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Konta SZO poza katalogiem -->
<?php
$not_in_ldap = array_filter($szo_users_all, fn($u) => !isset($in_ldap[(string)$u['id']]) && $u['is_active']);
?>
<?php if ($not_in_ldap): ?>
<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-person-dash text-warning"></i>
    Aktywne konta SZO bez wpisu w LDAP
    <span class="ms-2 tz-badge tz-badge--warn"><?= count($not_in_ldap) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr><th class="ps-3">Użytkownik</th><th>E-mail</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($not_in_ldap as $u): ?>
      <tr>
        <td class="ps-3 fw-semibold"><?= h($u['name']) ?></td>
        <td class="small text-muted"><?= h($u['email']) ?></td>
        <td class="text-end pe-3">
          <?php if ($ldap_configured): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="_action"  value="push_user">
            <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-2" title="Wypchnij do LDAP">
              <i class="bi bi-cloud-upload"></i>
            </button>
          </form>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="tz-note">
    <i class="bi bi-arrow-repeat"></i>
    Użyj <a href="?tab=sync" class="text-primary">Synchronizuj teraz</a> aby wyeksportować wszystkie naraz.
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ZAKŁADKA: KONFIGURACJA
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($_tab === 'config'): ?>

<?php
$host     = defined('LDAP_HOST')     ? LDAP_HOST     : '';
$port     = defined('LDAP_PORT')     ? (int)LDAP_PORT : 389;
$base_dn  = defined('LDAP_BASE_DN')  ? LDAP_BASE_DN  : '';
$users_ou = defined('LDAP_USERS_OU') ? LDAP_USERS_OU : '';
$bind_dn  = defined('LDAP_BIND_DN')  ? LDAP_BIND_DN  : '';
$bind_pw  = defined('LDAP_BIND_PW')  ? LDAP_BIND_PW  : '';
$use_tls  = defined('LDAP_USE_TLS')  && LDAP_USE_TLS;
$pw_mask  = $bind_pw ? str_repeat('●', min(12, strlen($bind_pw))) : '—';
$docker   = 'ldap';
?>

<!-- Parametry serwera -->
<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-hdd-network"></i> Parametry serwera</div>
  <div class="tz-dl">
    <div><dt>Host (zewnętrzny)</dt><dd class="font-monospace"><?= h($host ?: '—') ?>:<?= $port ?></dd></div>
    <div><dt>Host (Docker internal)</dt><dd class="font-monospace"><?= h($docker) ?>:389</dd></div>
    <div><dt>Base DN</dt><dd class="font-monospace small"><?= h($base_dn ?: '—') ?></dd></div>
    <div><dt>Users OU</dt><dd class="font-monospace small"><?= h($users_ou ?: '—') ?></dd></div>
    <div><dt>Bind DN</dt><dd class="font-monospace small"><?= h($bind_dn ?: '—') ?></dd></div>
    <div><dt>Bind hasło</dt><dd class="font-monospace small"><?= h($pw_mask) ?></dd></div>
    <div><dt>STARTTLS</dt><dd><?= $use_tls ? '<span class="tz-badge tz-badge--ok">tak</span>' : '<span class="tz-badge tz-badge--off">nie</span>' ?></dd></div>
    <div><dt>phpLDAPadmin</dt><dd><a href="http://127.0.0.1:8389/" target="_blank" rel="noopener" class="small">http://127.0.0.1:8389/</a></dd></div>
    <div>
      <dt>Status połączenia</dt>
      <dd>
        <?php if ($conn_ok): ?>
          <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill"></i> OK</span>
        <?php elseif (!$ldap_configured): ?>
          <span class="tz-badge tz-badge--off">Niekonfigurowany</span>
        <?php else: ?>
          <span class="tz-badge tz-badge--warn"><i class="bi bi-x-circle-fill"></i> Błąd połączenia</span>
          <div class="small text-danger mt-1"><?= h($conn_err) ?></div>
        <?php endif; ?>
      </dd>
    </div>
  </div>
</div>

<?php
// Bloki konfiguracyjne — generujemy tylko gdy LDAP skonfigurowany
$blocks = [];

if ($ldap_configured) {
    $blocks['SZO — config.local.php'] = [
        'icon' => 'bi-code-slash',
        'code' => "define('LDAP_ENABLED',  true);\n"
                . "define('LDAP_HOST',     '127.0.0.1'); // Docker: '{$docker}'\n"
                . "define('LDAP_PORT',     {$port});\n"
                . "define('LDAP_BIND_DN',  '{$bind_dn}');\n"
                . "define('LDAP_BIND_PW',  'hasło'); // LDAP_ADMIN_PASSWORD z docker/.env\n"
                . "define('LDAP_BASE_DN',  '{$base_dn}');\n"
                . "define('LDAP_USERS_OU', '{$users_ou}');",
    ];

    $blocks['Gitea — Admin → Authentication → LDAP (Bind DN)'] = [
        'icon' => 'bi-git',
        'rows' => [
            'Host'              => $host,
            'Port'              => (string)$port,
            'Bind DN'           => $bind_dn,
            'Bind Password'     => '(z LDAP_ADMIN_PASSWORD)',
            'User Search Base'  => $users_ou,
            'User Filter'       => '(&(objectClass=inetOrgPerson)(uid=%s))',
            'Username Attr'     => 'uid',
            'Firstname Attr'    => 'givenName',
            'Surname Attr'      => 'sn',
            'Email Attr'        => 'mail',
        ],
    ];

    $blocks['Nextcloud — Settings → LDAP/AD Integration'] = [
        'icon' => 'bi-cloud',
        'rows' => [
            'Server'            => "ldap://{$host}:{$port}",
            'Port'              => (string)$port,
            'User DN'           => $bind_dn,
            'Base DN'           => $base_dn,
            'Users filter'      => '(|(objectclass=inetOrgPerson))',
            'Login attribute'   => 'uid',
            'Email attribute'   => 'mail',
            'Display name'      => 'cn',
        ],
    ];

    $blocks['Generic .env / docker-compose'] = [
        'icon' => 'bi-file-earmark-code',
        'code' => "LDAP_URL=ldap://{$docker}:389\n"
                . "LDAP_BASE_DN={$base_dn}\n"
                . "LDAP_BIND_DN={$bind_dn}\n"
                . "LDAP_BIND_PASSWORD=(hasło)\n"
                . "LDAP_USERS_BASE={$users_ou}\n"
                . "LDAP_USER_FILTER=(objectClass=inetOrgPerson)\n"
                . "LDAP_USER_LOGIN_ATTR=uid\n"
                . "LDAP_USER_EMAIL_ATTR=mail\n"
                . "LDAP_USER_DISPLAY_ATTR=cn",
    ];

    $blocks['nginx — ngx_http_auth_ldap_module'] = [
        'icon' => 'bi-server',
        'code' => "ldap_server feer_ldap {\n"
                . "    url ldap://{$host}:{$port}/{$users_ou}?uid?sub?(objectClass=inetOrgPerson);\n"
                . "    binddn \"{$bind_dn}\";\n"
                . "    binddn_passwd \"(hasło)\";\n"
                . "    group_attribute uniqueMember;\n"
                . "    group_attribute_is_dn on;\n"
                . "    require valid_user;\n"
                . "}",
    ];
}
?>

<?php if ($blocks): ?>
<?php foreach ($blocks as $title => $block): ?>
<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi <?= $block['icon'] ?>"></i> <?= h($title) ?></div>
  <div class="tz-card__bd" style="padding:.75rem 1rem">
    <?php if (isset($block['code'])): ?>
    <div class="position-relative">
      <pre class="mb-0 p-3 rounded" style="background:#f8f9fa;font-size:.78rem;overflow-x:auto;border:1px solid #e5e7eb"><?= h($block['code']) ?></pre>
      <button class="btn btn-sm btn-outline-secondary position-absolute" style="top:.5rem;right:.5rem;font-size:.72rem"
              onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent).then(()=>{this.textContent='✔';setTimeout(()=>this.textContent='Kopiuj',1200)})">Kopiuj</button>
    </div>
    <?php else: ?>
    <dl class="mb-0" style="display:grid;grid-template-columns:200px 1fr;gap:.3rem .75rem;font-size:.84rem">
      <?php foreach ($block['rows'] as $k => $v): ?>
      <dt class="text-muted fw-normal"><?= h($k) ?></dt>
      <dd class="font-monospace mb-0"><?= h($v) ?></dd>
      <?php endforeach; ?>
    </dl>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<!-- Komendy diagnostyczne -->
<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-terminal"></i> Komendy diagnostyczne</div>
  <div class="tz-card__bd" style="padding:.75rem 1rem">
    <p class="small text-muted mb-2">Uruchom z hosta lub z kontenera PHP:</p>
    <?php
    $cmds = [
        'Test bind (z hosta)'          => "ldapwhoami -x -H ldap://{$host}:{$port} -D \"{$bind_dn}\" -W",
        'Lista kont (z hosta)'         => "ldapsearch -x -H ldap://{$host}:{$port} -D \"{$bind_dn}\" -W \\\n  -b \"{$users_ou}\" \"(objectClass=inetOrgPerson)\" uid cn mail",
        'Test bind (Docker, php ctn)'  => "docker exec feer-php ldapwhoami -x -H ldap://ldap:389 -D \"{$bind_dn}\" -W",
        'Lista kont (Docker, php ctn)' => "docker exec feer-php ldapsearch -x -H ldap://ldap:389 -D \"{$bind_dn}\" -W \\\n  -b \"{$users_ou}\" uid cn",
        'Pełny raport CLI'             => 'php cli/ldap_info.php',
    ];
    foreach ($cmds as $lbl => $cmd): ?>
    <div class="mb-2">
      <div class="small text-muted mb-1"><?= h($lbl) ?></div>
      <div class="position-relative">
        <pre class="mb-0 p-2 rounded" style="background:#1e293b;color:#e2e8f0;font-size:.77rem;overflow-x:auto"><?= h($cmd) ?></pre>
        <button class="btn btn-sm position-absolute" style="top:.3rem;right:.3rem;font-size:.68rem;background:rgba(255,255,255,.1);color:#e2e8f0;border:none"
                onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent.trim()).then(()=>{this.textContent='✔';setTimeout(()=>this.textContent='Kopiuj',1200)})">Kopiuj</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php else: ?>
<div class="tz-card">
  <div class="tz-card__bd text-muted small">
    Bloki konfiguracyjne są dostępne po skonfigurowaniu połączenia LDAP (stałe <code>LDAP_*</code> w <code>config.local.php</code>).
  </div>
</div>
<?php endif; ?>

<?php endif; // tab ?>

<?php include dirname(__DIR__) . '/tozsamosc/_foot.php'; ?>
