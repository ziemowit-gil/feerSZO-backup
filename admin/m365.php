<?php
/**
 * Zintegrowana strona zarządzania Microsoft 365.
 * Zakładki: Konfiguracja | Konta bez umowy | Synchronizacja
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'Microsoft 365';

// ── Aktywna zakładka (URL lub localStorage-fallback) ───────────────────────
$tab = in_array($_GET['tab'] ?? '', ['config','standalone','sync','przed2026']) ? $_GET['tab'] : 'config';

// ────────────────────────────────────────────────────────────────────────────
// Tab: Konfiguracja — obsługa POST (przeniesiona z m365_settings.php)
// ────────────────────────────────────────────────────────────────────────────
$cfg = [];
foreach (['m365_enabled','m365_graph_client_id','m365_graph_client_secret',
          'm365_domain','m365_license_sku_id','m365_sender_user_id'] as $k) {
    $cfg[$k] = m365_setting($k);
}

$action      = $_POST['_action'] ?? '';
$test_result = null;
$skus        = [];
$users_m365  = [];
$post        = $_POST;

// Auto-wykryte dane z OAuth callback
$autodetect = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['m365_autodetect'])) {
    auth_start();
    $autodetect  = $_SESSION['m365_autodetect'];
    unset($_SESSION['m365_autodetect']);
    $test_result = [
        'ok'       => $autodetect['ok'] ?? false,
        'org_name' => $autodetect['org_name'] ?? '',
        'domains'  => $autodetect['domains'] ?? [],
        'error'    => $autodetect['error'] ?? '',
    ];
    if ($autodetect['ok'] ?? false) {
        $skus       = $autodetect['skus']  ?? [];
        $users_m365 = $autodetect['users'] ?? [];
    }
    if (!empty($autodetect['domain'])) $cfg['m365_domain'] = $autodetect['domain'];
    $tab = 'config';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['test','save','send_test'])) {
    csrf_check();
    $tab = 'config';

    $tenant_id     = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $client_id     = trim($post['m365_graph_client_id']     ?? $cfg['m365_graph_client_id']);
    $client_secret = trim($post['m365_graph_client_secret'] ?? '');
    if ($client_secret === '') $client_secret = $cfg['m365_graph_client_secret'];
    $domain        = trim($post['m365_domain'] ?? $cfg['m365_domain']) ?: 'example.pl';

    if ($action === 'test' || $action === 'save') {
        if ($client_id && $client_secret) {
            $graph       = new M365Graph(compact('tenant_id','client_id','client_secret','domain'));
            $test_result = $graph->test_connection();
            if ($test_result['ok']) {
                $skus       = array_values($graph->get_subscribed_skus());
                $users_m365 = $graph->get_users();
            }
        } else {
            $test_result = ['ok' => false, 'error' => 'Uzupełnij Client ID i Client Secret.'];
        }
    }

    if ($action === 'save') {
        m365_save_setting('m365_enabled',         trim($post['m365_enabled']        ?? '0'));
        m365_save_setting('m365_graph_client_id', $client_id);
        if ($client_secret) m365_save_setting('m365_graph_client_secret', $client_secret);
        m365_save_setting('m365_domain',          $domain);
        m365_save_setting('m365_license_sku_id',  trim($post['m365_license_sku_id'] ?? ''));
        m365_save_setting('m365_sender_user_id',  trim($post['m365_sender_user_id'] ?? ''));
        flash_set('success', 'Konfiguracja M365 zapisana.');
        header('Location: m365.php'); exit;
    }

    $cfg['m365_graph_client_id'] = $client_id;
    $cfg['m365_domain']          = $domain;
}

// Auto-test przy ładowaniu (jeśli skonfigurowane)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $test_result === null
    && $cfg['m365_graph_client_id'] && $cfg['m365_graph_client_secret']) {
    $graph       = new M365Graph();
    $test_result = $graph->test_connection();
    if ($test_result['ok']) {
        $skus       = array_values($graph->get_subscribed_skus());
        $users_m365 = $graph->get_users();
    }
}

$tenant_id = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');

// ────────────────────────────────────────────────────────────────────────────
// Tab: Konta bez umowy — dane
// ────────────────────────────────────────────────────────────────────────────
$m365_enabled = m365_setting('m365_enabled') === '1';
$accounts = db_all(
    "SELECT a.*, u.name AS linked_user_name, u.email AS linked_user_email,
            cb.name AS created_by_name
     FROM m365_standalone_accounts a
     LEFT JOIN users u  ON u.id = a.linked_user_id
     LEFT JOIN users cb ON cb.id = a.created_by
     ORDER BY a.created_at DESC"
);
$local_users = db_all("SELECT id, name, email, microsoft_id FROM users WHERE is_active=1 ORDER BY name");

// Hasło jednorazowe z sesji (po create)
auth_start();
$_creds = null;
if (!empty($_SESSION['m365sa_new_login'])) {
    $_creds = [
        'login' => $_SESSION['m365sa_new_login'],
        'pass'  => $_SESSION['m365sa_new_pass'],
        'sent'  => $_SESSION['m365sa_sent'] ?? false,
        'email' => $_SESSION['m365sa_email'] ?? '',
    ];
    unset($_SESSION['m365sa_new_login'], $_SESSION['m365sa_new_pass'],
          $_SESSION['m365sa_sent'],      $_SESSION['m365sa_email']);
    if ($tab !== 'standalone') $tab = 'standalone';
}

// Counters for tab badges
$_standalone_count = count($accounts);

// ── Obsługa akcji „przed 01.06" ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'link_przed2026') {
    csrf_check();
    $tab = 'przed2026';

    // Zbierz kontrakty do przetworzenia
    $ids = array_map('intval', (array)($_POST['contract_ids'] ?? []));
    if (empty($ids)) {
        // Tryb "przetwórz wszystkie"
        $rows_to_process = db_all(
            "SELECT id, imie_nazwisko, email, m365_login, m365_user_id
             FROM umowy_wolontariat
             WHERE is_technical=1 AND m365_konto=1
             ORDER BY imie_nazwisko"
        );
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows_to_process = db_all(
            "SELECT id, imie_nazwisko, email, m365_login, m365_user_id
             FROM umowy_wolontariat
             WHERE id IN ({$placeholders}) AND is_technical=1 AND m365_konto=1",
            $ids
        );
    }

    $_p2026_results = [];
    foreach ($rows_to_process as $r) {
        $m365_uid   = trim($r['m365_user_id'] ?? '');
        $email      = trim($r['email'] ?? $r['m365_login'] ?? '');
        $name       = trim($r['imie_nazwisko'] ?? '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_p2026_results[] = ['row' => $r, 'status' => 'skip', 'msg' => 'Brak e-mail'];
            continue;
        }

        // Szukaj po microsoft_id, potem po e-mail
        $existing = $m365_uid
            ? db_one("SELECT id, name, microsoft_id FROM users WHERE microsoft_id=?", [$m365_uid])
            : null;
        if (!$existing) {
            $existing = db_one("SELECT id, name, microsoft_id FROM users WHERE LOWER(email)=LOWER(?)", [$email]);
        }

        if ($existing) {
            if ($existing['microsoft_id'] === $m365_uid && $m365_uid) {
                $_p2026_results[] = ['row' => $r, 'status' => 'already', 'msg' => 'Już powiązane'];
                continue;
            }
            db()->prepare("UPDATE users SET microsoft_id=?, is_active=1 WHERE id=?")
                ->execute([$m365_uid ?: null, (int)$existing['id']]);
            log_contract_action('wolontariat', (int)$r['id'], (int)current_user()['id'], 'note',
                'Admin: powiązano konto lokalne id=' . $existing['id'] . ' z M365: ' . $r['m365_login']);
            $_p2026_results[] = ['row' => $r, 'status' => 'linked', 'msg' => 'Powiązano z istniejącym kontem'];
        } else {
            $uid = db_insert('users', [
                'name'         => $name ?: $email,
                'email'        => $email,
                'password'     => null,
                'microsoft_id' => $m365_uid ?: null,
                'role'         => 'viewer',
                'is_active'    => 1,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            log_contract_action('wolontariat', (int)$r['id'], (int)current_user()['id'], 'note',
                'Admin: utworzono konto lokalne id=' . $uid . ' z M365: ' . $r['m365_login']);
            $_p2026_results[] = ['row' => $r, 'status' => 'created', 'msg' => 'Utworzono nowe konto'];
        }
    }

    $n_done = count(array_filter($_p2026_results, fn($x) => in_array($x['status'], ['linked','created'])));
    flash_set('success', "Przetworzono {$n_done} kont.");
}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.step-badge { width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0 }
.step-ok   { background:#d1fae5;color:#065f46 }
.step-todo { background:#e2e8f0;color:#475569 }
.step-warn { background:#fef3c7;color:#92400e }
.cfg-row   { border-bottom:1px solid #f1f5f9;padding:.55rem 0 }
.cfg-row:last-child { border-bottom:0 }
</style>

<div class="d-flex align-items-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-microsoft text-primary"></i> Microsoft 365</h4>
  <?php if ($cfg['m365_enabled'] === '1'): ?>
    <span class="badge bg-success">Integracja aktywna</span>
  <?php else: ?>
    <span class="badge bg-secondary">Integracja wyłączona</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($autodetect && ($autodetect['ok'] ?? false)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-check-circle-fill fs-5"></i>
  <div>
    <strong>Połączono z Microsoft 365!</strong>
    Organizacja: <strong><?= h($autodetect['org_name']) ?></strong>
    &middot; Tenant ID i domena wykryte automatycznie.
    <?php if (!empty($autodetect['domain'])): ?> Domena: <code><?= h($autodetect['domain']) ?></code><?php endif; ?>
  </div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Zakładki ─────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="m365Tabs">
  <li class="nav-item">
    <a class="nav-link d-flex align-items-center gap-1" id="tab-config" data-bs-toggle="tab"
       href="#pane-config" role="tab">
      <i class="bi bi-gear-fill"></i> Konfiguracja
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link d-flex align-items-center gap-1" id="tab-standalone" data-bs-toggle="tab"
       href="#pane-standalone" role="tab">
      <i class="bi bi-people"></i> Konta bez umowy
      <?php if ($_standalone_count): ?>
      <span class="badge bg-secondary ms-1"><?= $_standalone_count ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link d-flex align-items-center gap-1" id="tab-sync" data-bs-toggle="tab"
       href="#pane-sync" role="tab">
      <i class="bi bi-arrow-repeat"></i> Synchronizacja
    </a>
  </li>
  <li class="nav-item">
    <?php
    $_p2026_total = (int)(db_one(
        "SELECT COUNT(*) AS n FROM umowy_wolontariat WHERE is_technical=1 AND m365_konto=1"
    )['n'] ?? 0);
    $_p2026_unlinked = 0;
    if ($_p2026_total) {
        // Ile nie ma jeszcze powiązanego konta lokalnego
        $_p2026_rows_all = db_all(
            "SELECT email, m365_user_id FROM umowy_wolontariat WHERE is_technical=1 AND m365_konto=1"
        );
        foreach ($_p2026_rows_all as $_pr) {
            $m = $_pr['m365_user_id']
                ? db_one("SELECT id FROM users WHERE microsoft_id=?", [$_pr['m365_user_id']])
                : null;
            if (!$m && $_pr['email']) {
                $m = db_one("SELECT id FROM users WHERE LOWER(email)=LOWER(?)", [$_pr['email']]);
            }
            if (!$m || !$m['id']) $_p2026_unlinked++;
        }
    }
    ?>
    <a class="nav-link d-flex align-items-center gap-1" id="tab-przed2026" data-bs-toggle="tab"
       href="#pane-przed2026" role="tab">
      <i class="bi bi-clock-history"></i> Przed 01.06
      <?php if ($_p2026_unlinked): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $_p2026_unlinked ?></span>
      <?php elseif ($_p2026_total): ?>
      <span class="badge bg-success ms-1"><i class="bi bi-check"></i></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<div class="tab-content" id="m365TabContent">

<!-- ══════════════════════════════════════════════════════════════════════════
     Tab: Konfiguracja
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="pane-config" role="tabpanel">
<div class="row g-3">

<!-- Formularz konfiguracji -->
<div class="col-xl-7">
<form method="post" id="m365form">
<input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
<input type="hidden" name="_action"  id="form_action" value="test">

<!-- Krok 1: Rejestracja aplikacji -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <span class="step-badge <?= ($test_result['ok'] ?? false) ? 'step-ok' : 'step-todo' ?>">1</span>
  Rejestracja aplikacji Azure AD
  <a href="m365_connect.php" class="btn btn-sm btn-outline-success ms-auto">
    <i class="bi bi-plug"></i> Połącz przez OAuth
  </a>
</div>
<div class="card-body">
  <div class="alert alert-light border small p-2 mb-3">
    <strong>Redirect URI (dodaj obie w Azure → Authentication → Web):</strong><br>
    <code class="user-select-all"><?= h(APP_URL) ?>/admin/m365_connect_callback.php</code>
    <span class="text-muted">— wizard</span><br>
    <code class="user-select-all"><?= h(APP_URL) ?>/auth/microsoft.php</code>
    <span class="text-muted">— logowanie</span><br><br>
    <strong>Uprawnienia Application:</strong>
    <code>User.ReadWrite.All</code> &nbsp;•&nbsp; <code>Directory.ReadWrite.All</code>
    &nbsp;•&nbsp; <code>Mail.Send</code> &nbsp;•&nbsp; <code>Organization.Read.All</code>
  </div>
  <div class="row g-3">
    <div class="col-12">
      <label class="form-label fw-semibold small">Tenant ID
        <span class="text-muted fw-normal"><?= m365_setting('m365_tenant_id') ? '(wykryty przez OAuth)' : '(z config.php)' ?></span>
      </label>
      <input type="text" class="form-control form-control-sm font-monospace bg-light"
             value="<?= h($tenant_id ?: '— nie ustawiono — kliknij „Połącz przez OAuth"') ?>" readonly>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold small">Client ID <span class="text-danger">*</span></label>
      <input type="text" name="m365_graph_client_id" class="form-control form-control-sm font-monospace"
             value="<?= h($cfg['m365_graph_client_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold small">Client Secret <span class="text-danger">*</span></label>
      <input type="password" name="m365_graph_client_secret" class="form-control form-control-sm"
             placeholder="<?= $cfg['m365_graph_client_secret'] ? '(skonfigurowany — zostaw puste)' : 'Wklej secret z Azure' ?>"
             autocomplete="new-password">
      <?php if ($cfg['m365_graph_client_secret']): ?>
      <div class="form-text text-success"><i class="bi bi-check-circle"></i> Client Secret jest zapisany.</div>
      <?php endif; ?>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold small">Domena email <span class="text-danger">*</span></label>
      <div class="input-group input-group-sm">
        <span class="input-group-text">@</span>
        <input type="text" name="m365_domain" class="form-control font-monospace"
               value="<?= h($cfg['m365_domain']) ?>" placeholder="organizacja.pl">
      </div>
    </div>
  </div>
  <div class="mt-3">
    <button type="button" class="btn btn-outline-primary btn-sm" onclick="submitCfgAction('test')">
      <i class="bi bi-plug"></i> Testuj połączenie z Azure AD
    </button>
  </div>
</div>
</div>

<!-- Krok 2: Licencja -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <span class="step-badge <?= ($cfg['m365_license_sku_id'] && ($test_result['ok'] ?? false)) ? 'step-ok' : (($test_result['ok'] ?? false) ? 'step-warn' : 'step-todo') ?>">2</span>
  Licencja Microsoft 365
</div>
<div class="card-body">
<?php if ($skus): ?>
  <div class="row g-2 mb-3">
  <?php foreach ($skus as $sku):
      $avail   = ($sku['prepaidUnits']['enabled'] ?? 0) - ($sku['consumedUnits'] ?? 0);
      $checked = ($cfg['m365_license_sku_id'] === $sku['skuId']) ? 'checked' : '';
  ?>
  <div class="col-12">
    <div class="form-check border rounded px-3 py-2 <?= $checked ? 'border-primary bg-primary bg-opacity-10' : '' ?>">
      <input class="form-check-input" type="radio" name="m365_license_sku_id"
             id="sku_<?= h($sku['skuId']) ?>" value="<?= h($sku['skuId']) ?>" <?= $checked ?>>
      <label class="form-check-label w-100" for="sku_<?= h($sku['skuId']) ?>">
        <span class="fw-semibold"><?= h($sku['skuPartNumber']) ?></span>
        <span class="badge <?= $avail > 0 ? 'bg-success' : 'bg-danger' ?> ms-2"><?= $avail ?> wolnych</span><br>
        <small class="text-muted font-monospace"><?= h($sku['skuId']) ?></small>
      </label>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
<?php elseif ($test_result !== null && !($test_result['ok'] ?? false)): ?>
  <div class="alert alert-warning small py-2 mb-2">Najpierw popraw połączenie z Azure AD.</div>
<?php else: ?>
  <div class="alert alert-light small py-2 mb-2">Kliknij <em>Testuj połączenie</em> — licencje załadują się automatycznie.</div>
<?php endif; ?>
  <label class="form-label fw-semibold small mt-1">SKU ID licencji</label>
  <input type="text" name="m365_license_sku_id" id="sku_manual"
         class="form-control form-control-sm font-monospace"
         value="<?= h($cfg['m365_license_sku_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
  <div class="form-text">Zostaw puste jeśli nie chcesz przypisywać licencji automatycznie.</div>
</div>
</div>

<!-- Krok 3: Nadawca -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <span class="step-badge <?= ($cfg['m365_sender_user_id'] && ($test_result['ok'] ?? false)) ? 'step-ok' : (($test_result['ok'] ?? false) ? 'step-warn' : 'step-todo') ?>">3</span>
  Konto nadawcy maili systemowych
</div>
<div class="card-body">
  <p class="small text-muted mb-2">Nadawca maili akceptacji, powiadomień, danych logowania.</p>
<?php if ($users_m365): ?>
  <select name="m365_sender_user_id" class="form-select form-select-sm mb-2"
          onchange="document.getElementById('sender_manual').value=this.value">
    <option value="">— wybierz użytkownika —</option>
    <?php foreach ($users_m365 as $u): ?>
    <option value="<?= h($u['id']) ?>" <?= $cfg['m365_sender_user_id']===$u['id'] ? 'selected' : '' ?>>
      <?= h($u['displayName']) ?> &lt;<?= h($u['userPrincipalName']) ?>&gt;
    </option>
    <?php endforeach; ?>
  </select>
<?php endif; ?>
  <input type="text" name="m365_sender_user_id" id="sender_manual"
         class="form-control form-control-sm font-monospace"
         value="<?= h($cfg['m365_sender_user_id']) ?>" placeholder="GUID lub upn@domena.pl">
</div>
</div>

<!-- Krok 4: Aktywacja -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <span class="step-badge <?= $cfg['m365_enabled']==='1' ? 'step-ok' : 'step-todo' ?>">4</span>
  Aktywacja integracji
</div>
<div class="card-body">
  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch"
           name="m365_enabled" id="m365_enabled" value="1"
           <?= $cfg['m365_enabled']==='1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="m365_enabled">
      Integracja Microsoft 365 aktywna
    </label>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-primary" onclick="submitCfgAction('save')">
      <i class="bi bi-check-lg"></i> Zapisz konfigurację
    </button>
    <button type="button" class="btn btn-outline-secondary" onclick="submitCfgAction('test')">
      <i class="bi bi-arrow-repeat"></i> Odśwież z Azure
    </button>
  </div>
</div>
</div>
</form>
</div><!-- /col-xl-7 -->

<!-- Status + pomoc -->
<div class="col-xl-5">
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-activity"></i> Status połączenia</div>
<div class="card-body p-0">
<?php if ($test_result === null): ?>
  <div class="p-3 text-muted small">
    <i class="bi bi-info-circle"></i>
    <?= ($cfg['m365_graph_client_id'] && $cfg['m365_graph_client_secret'])
        ? 'Ładowanie statusu...' : 'Uzupełnij dane i kliknij "Testuj połączenie".' ?>
  </div>
<?php elseif (!$test_result['ok']): ?>
  <div class="p-3">
    <div class="alert alert-danger py-2 small mb-2">
      <i class="bi bi-x-circle-fill"></i> <strong>Brak połączenia</strong><br>
      <?= h($test_result['error'] ?? 'Nieznany błąd') ?>
    </div>
  </div>
<?php else: ?>
  <div class="p-3">
    <div class="alert alert-success py-2 small mb-3">
      <i class="bi bi-check-circle-fill"></i> <strong>Połączono z Azure AD</strong>
    </div>
  </div>
  <div class="px-3 pb-3">
    <div class="cfg-row d-flex justify-content-between"><span class="small text-muted">Organizacja</span><span class="fw-semibold small"><?= h($test_result['org_name'] ?? '?') ?></span></div>
    <div class="cfg-row d-flex justify-content-between"><span class="small text-muted">Tenant ID</span><span class="font-monospace small"><?= h(substr($tenant_id,0,8)) ?>...</span></div>
    <?php if (!empty($test_result['domains'])): ?>
    <div class="cfg-row d-flex justify-content-between align-items-start">
      <span class="small text-muted">Domeny</span>
      <span class="small text-end"><?= h(implode(', ', array_slice($test_result['domains'],0,3))) ?></span>
    </div>
    <?php endif; ?>
    <div class="cfg-row d-flex justify-content-between"><span class="small text-muted">Licencje</span><span class="badge <?= count($skus)>0?'bg-success':'bg-secondary' ?>"><?= count($skus) ?></span></div>
    <div class="cfg-row d-flex justify-content-between"><span class="small text-muted">Użytkownicy (pobrano)</span><span class="badge bg-secondary"><?= count($users_m365) ?></span></div>
    <div class="cfg-row d-flex justify-content-between">
      <span class="small text-muted">Licencja SKU</span>
      <span class="badge <?= $cfg['m365_license_sku_id'] ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $cfg['m365_license_sku_id'] ? 'Wybrana' : 'Nie wybrano' ?></span>
    </div>
    <div class="cfg-row d-flex justify-content-between">
      <span class="small text-muted">Nadawca maili</span>
      <?php
      $senderUser = [];
      foreach ($users_m365 as $u) {
          if ($u['id']===$cfg['m365_sender_user_id']||$u['userPrincipalName']===$cfg['m365_sender_user_id']) { $senderUser=$u; break; }
      }
      ?>
      <span class="small fw-semibold"><?= h($senderUser['displayName'] ?? ($cfg['m365_sender_user_id'] ? $cfg['m365_sender_user_id'] : '—')) ?></span>
    </div>
    <div class="cfg-row d-flex justify-content-between">
      <span class="small text-muted">Integracja aktywna</span>
      <span class="badge <?= $cfg['m365_enabled']==='1' ? 'bg-success' : 'bg-secondary' ?>"><?= $cfg['m365_enabled']==='1' ? 'Tak' : 'Nie' ?></span>
    </div>
  </div>
<?php endif; ?>
</div>
</div>

<?php if ($test_result['ok'] ?? false): ?>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-send"></i> Test wysyłki maila</div>
<div class="card-body">
  <?php if ($cfg['m365_sender_user_id']): ?>
  <form method="post">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="send_test">
    <div class="input-group input-group-sm mb-1">
      <input type="email" name="test_email" class="form-control" placeholder="Adres do testu" required>
      <button type="submit" class="btn btn-outline-primary"><i class="bi bi-envelope-arrow-up"></i> Wyślij</button>
    </div>
  </form>
  <?php if ($action==='send_test'):
      $to = trim($_POST['test_email']??'');
      if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
          try {
              $ok = approval_send_email($to,'Test maila — '.ORG_NAME,
                '<p>Test z Rejestru Umów <strong>'.h(ORG_NAME).'</strong>.</p>');
              echo '<div class="alert alert-'.($ok?'success':'danger').' py-2 small mt-2">'.($ok?'✓ Mail wysłany.':'✗ Błąd wysyłki.').'</div>';
          } catch(\Exception $e){ echo '<div class="alert alert-danger py-2 small mt-2">'.h($e->getMessage()).'</div>'; }
      }
  endif; ?>
  <?php else: ?>
  <p class="text-muted small mb-0">Ustaw nadawcę maili, aby włączyć test.</p>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-question-circle"></i> Jak skonfigurować</div>
<div class="card-body small">
  <ol class="ps-3 mb-0">
    <li class="mb-1"><a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank">Azure Portal → App registrations → New</a></li>
    <li class="mb-1">API permissions → Graph → <strong>Application</strong>: <code>User.ReadWrite.All</code>, <code>Mail.Send</code>, <code>Organization.Read.All</code></li>
    <li class="mb-1"><em>Grant admin consent</em></li>
    <li class="mb-1">Certificates &amp; secrets → New client secret</li>
    <li class="mb-1">Kliknij <a href="m365_connect.php"><strong>Połącz przez OAuth</strong></a></li>
    <li>Wróć tu, uzupełnij Secret, wybierz licencję i nadawcę</li>
  </ol>
</div>
</div>
</div><!-- /col status -->
</div><!-- /row -->
</div><!-- /pane-config -->


<!-- ══════════════════════════════════════════════════════════════════════════
     Tab: Konta bez umowy
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="pane-standalone" role="tabpanel">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <p class="mb-0 text-muted small">Konta Microsoft 365 tworzone bez powiązania z umową — np. dla personelu administracyjnego.</p>
  <?php if ($m365_enabled): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-person-plus"></i> Nowe konto M365
  </button>
  <?php endif; ?>
</div>

<?php if (!$m365_enabled): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle"></i>
  Integracja Microsoft 365 jest wyłączona.
  <a href="#" onclick="switchM365Tab('config')" class="alert-link">Włącz w zakładce Konfiguracja →</a>
</div>
<?php endif; ?>

<?php if ($_creds): ?>
<div class="alert alert-warning border-warning">
  <strong><i class="bi bi-key-fill"></i> Hasło jednorazowe — zapisz teraz!</strong><br>
  Login: <code><?= h($_creds['login']) ?></code> &nbsp;/&nbsp;
  Hasło: <code><?= h($_creds['pass']) ?></code>
  <?php if ($_creds['sent']): ?>
  <br><small class="text-success"><i class="bi bi-check-circle"></i> Mail wysłany na: <?= h($_creds['email']) ?></small>
  <?php elseif ($_creds['email']): ?>
  <br><small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Mail nie wysłany.</small>
  <?php else: ?>
  <br><small class="text-muted"><i class="bi bi-info-circle"></i> Nie podano adresu e-mail.</small>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Lista kont -->
<div class="card shadow-sm">
<div class="card-header fw-semibold">
  <i class="bi bi-people"></i> Konta (<?= $_standalone_count ?>)
</div>
<?php if ($accounts): ?>
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Imię i nazwisko</th>
      <th>E-mail</th>
      <th>Login M365</th>
      <th>Status</th>
      <th class="text-center">Licencja</th>
      <th>Powiązanie</th>
      <th>Utworzono</th>
      <th>Przez</th>
      <th class="text-end">Akcje</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($accounts as $acc): ?>
  <tr>
    <td class="fw-semibold">
      <?= h($acc['imie_nazwisko']) ?>
      <?php if ($acc['opis']): ?>
      <br><small class="text-muted"><?= h(mb_strimwidth($acc['opis'], 0, 50, '…')) ?></small>
      <?php endif; ?>
    </td>
    <td class="small">
      <?= $acc['email'] ? '<a href="mailto:'.h($acc['email']).'">'.h($acc['email']).'</a>' : '<span class="text-muted">—</span>' ?>
    </td>
    <td class="font-monospace small">
      <?= $acc['m365_login'] ? h($acc['m365_login']) : '<span class="text-muted">—</span>' ?>
    </td>
    <td>
      <?php if (!$acc['m365_login']): ?>
        <span class="badge bg-light text-dark border">Brak konta</span>
      <?php elseif ($acc['m365_konto_aktywne']): ?>
        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Aktywne</span>
      <?php else: ?>
        <span class="badge bg-secondary"><i class="bi bi-pause-circle"></i> Wyłączone</span>
      <?php endif; ?>
    </td>
    <td class="text-center">
      <?= $acc['m365_licencja_przypisana']
          ? '<i class="bi bi-check-circle text-success" title="Licencja przypisana"></i>'
          : '<span class="text-muted">—</span>' ?>
    </td>
    <td class="small">
      <?php if ($acc['linked_user_name']): ?>
      <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
        <i class="bi bi-link-45deg"></i> <?= h($acc['linked_user_name']) ?>
      </span>
      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
    </td>
    <td class="small text-nowrap"><?= date_pl($acc['m365_data_utworzenia'] ?: $acc['created_at']) ?></td>
    <td class="small"><?= h($acc['created_by_name'] ?? '—') ?></td>
    <td class="text-end text-nowrap">
      <div class="btn-group btn-group-sm">
        <?php if (!$acc['m365_login'] && $m365_enabled): ?>
        <form method="post" action="m365_standalone_action.php" class="d-inline">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="create">
          <button class="btn btn-sm btn-primary" title="Utwórz konto M365">
            <i class="bi bi-microsoft"></i> Utwórz
          </button>
        </form>
        <?php elseif ($acc['m365_login']): ?>
        <form method="post" action="m365_standalone_action.php" class="d-inline">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="<?= $acc['m365_konto_aktywne'] ? 'disable' : 'enable' ?>">
          <button class="btn btn-sm <?= $acc['m365_konto_aktywne'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                  title="<?= $acc['m365_konto_aktywne'] ? 'Wyłącz konto' : 'Włącz konto' ?>">
            <i class="bi bi-<?= $acc['m365_konto_aktywne'] ? 'pause-circle' : 'play-circle' ?>"></i>
          </button>
        </form>
        <?php if ($acc['email']): ?>
        <form method="post" action="m365_standalone_action.php" class="d-inline">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="send_email">
          <button class="btn btn-sm btn-outline-primary" title="Wyślij mail z nowym hasłem">
            <i class="bi bi-envelope-at"></i>
          </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                data-bs-toggle="dropdown"></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><button class="dropdown-item" data-bs-toggle="modal"
                      data-bs-target="#editModal<?= $acc['id'] ?>">
            <i class="bi bi-pencil me-2"></i>Edytuj dane</button></li>
          <?php if ($acc['m365_user_id']): ?>
          <li><button class="dropdown-item" data-bs-toggle="modal"
                      data-bs-target="#linkModal<?= $acc['id'] ?>">
            <i class="bi bi-link-45deg me-2"></i>Powiąż z kontem lokalnym</button></li>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item text-warning" data-bs-toggle="modal"
                      data-bs-target="#unlinkModal<?= $acc['id'] ?>">
            <i class="bi bi-unlink me-2"></i>Odepnij konto M365</button></li>
          <li><button class="dropdown-item text-danger" data-bs-toggle="modal"
                      data-bs-target="#deleteM365Modal<?= $acc['id'] ?>">
            <i class="bi bi-microsoft me-2"></i>Usuń konto z Azure AD</button></li>
          <li><hr class="dropdown-divider"></li>
          <?php endif; ?>
          <li><button class="dropdown-item text-danger" data-bs-toggle="modal"
                      data-bs-target="#deleteRowModal<?= $acc['id'] ?>">
            <i class="bi bi-trash3 me-2"></i>Usuń wpis z rejestru</button></li>
        </ul>
      </div>
    </td>
  </tr>

  <?php
  // ── Modale dla każdego wiersza ─────────────────────────────────────────────
  ?>

  <!-- EDIT MODAL -->
  <div class="modal fade" id="editModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj dane</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post" action="m365_standalone_action.php">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
        <input type="hidden" name="action" value="edit">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
            <input type="text" name="imie_nazwisko" class="form-control" value="<?= h($acc['imie_nazwisko']) ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">Adres e-mail</label>
            <input type="email" name="email" class="form-control" value="<?= h($acc['email']??'') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Opis / notatka</label>
            <textarea name="opis" class="form-control" rows="2"><?= h($acc['opis']??'') ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-floppy"></i> Zapisz</button>
        </div>
      </form>
    </div></div>
  </div>

  <?php if ($acc['m365_user_id']): ?>
  <!-- LINK MODAL -->
  <div class="modal fade" id="linkModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-link-45deg"></i> Powiąż konto lokalne</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post" action="m365_standalone_action.php">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
        <input type="hidden" name="action" value="link_local_user">
        <div class="modal-body">
          <p class="text-muted small">Powiązanie umożliwia logowanie przez MS365 do konta lokalnego.</p>
          <select name="local_user_id" class="form-select" required>
            <option value="">— wybierz użytkownika —</option>
            <?php foreach ($local_users as $lu): ?>
            <option value="<?= intval($lu['id']) ?>" <?= $acc['linked_user_id']==$lu['id']?'selected':'' ?>>
              <?= h($lu['name']) ?> &lt;<?= h($lu['email']) ?>&gt;<?= $lu['microsoft_id']?' ✓':'' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg"></i> Powiąż</button>
        </div>
      </form>
    </div></div>
  </div>

  <!-- UNLINK MODAL -->
  <div class="modal fade" id="unlinkModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <div class="modal-header bg-warning"><h5 class="modal-title"><i class="bi bi-unlink"></i> Odepnij konto M365</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post" action="m365_standalone_action.php">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
        <input type="hidden" name="action" value="unlink">
        <div class="modal-body">
          <p>Konto <strong><?= h($acc['m365_login']) ?></strong> zostanie odpięte.<br>
          <strong>Konto w Azure AD NIE zostanie usunięte.</strong></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning"><i class="bi bi-unlink"></i> Odepnij</button>
        </div>
      </form>
    </div></div>
  </div>

  <!-- DELETE M365 MODAL -->
  <div class="modal fade" id="deleteM365Modal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-microsoft"></i> Usuń konto z Azure AD</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post" action="m365_standalone_action.php">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
        <input type="hidden" name="action" value="delete_m365">
        <div class="modal-body">
          <p class="text-danger fw-bold">Tej operacji nie można cofnąć!</p>
          <p>Konto <strong><?= h($acc['m365_login']) ?></strong> zostanie trwale usunięte z Azure AD.</p>
          <?php if ($acc['linked_user_name']): ?>
          <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle"></i>
            Konto lokalne <strong><?= h($acc['linked_user_name']) ?></strong> zostanie dezaktywowane.
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Usuń z Azure AD</button>
        </div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>

  <!-- DELETE ROW MODAL -->
  <div class="modal fade" id="deleteRowModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-trash3"></i> Usuń wpis z rejestru</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post" action="m365_standalone_action.php">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
        <input type="hidden" name="action" value="delete_row">
        <div class="modal-body">
          <?php if ($acc['m365_login']): ?>
          <div class="alert alert-warning py-2 small mb-2">
            Konto <strong><?= h($acc['m365_login']) ?></strong> w Azure AD <strong>NIE zostanie usunięte</strong>.
          </div>
          <?php endif; ?>
          <p>Usuwa wpis dla <strong><?= h($acc['imie_nazwisko']) ?></strong> z lokalnego rejestru.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Usuń wpis</button>
        </div>
      </form>
    </div></div>
  </div>

  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<div class="card-body text-muted">
  Brak kont. Użyj przycisku <strong>Nowe konto M365</strong>, aby dodać.
</div>
<?php endif; ?>
</div><!-- /card -->

<!-- Modal: Nowe konto -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white">
      <h5 class="modal-title"><i class="bi bi-microsoft"></i> Nowe konto M365 bez umowy</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="post" action="m365_standalone_action.php">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-body">
        <div class="alert alert-info py-2 small mb-3">
          <i class="bi bi-info-circle"></i>
          Konto zostanie utworzone w Azure AD (tenant: <strong><?= h(m365_setting('m365_domain') ?: 'nie skonfigurowano') ?></strong>)
          i zapisane bez powiązania z umową.
        </div>
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
            <input type="text" name="imie_nazwisko" class="form-control" placeholder="Jan Kowalski" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Konto aktywne od razu?</label>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="konto_aktywne" id="kaNew" value="1" checked>
              <label class="form-check-label" for="kaNew">Tak, aktywuj</label>
            </div>
          </div>
          <div class="col-md-8">
            <label class="form-label">E-mail (do wysyłki danych logowania)</label>
            <input type="email" name="email" class="form-control" placeholder="jan.kowalski@email.pl">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Przypisz licencję?</label>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="przypisz_licencje" id="plNew" value="1" checked>
              <label class="form-check-label" for="plNew"><?= m365_setting('m365_license_sku_id') ? 'Tak (domyślna)' : '<span class="text-muted">Brak SKU</span>' ?></label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Opis / notatka <span class="text-muted small">(widoczny tylko w rejestrze)</span></label>
            <textarea name="opis" class="form-control" rows="2" placeholder="np. Pracownik administracyjny, konto techniczne…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-microsoft"></i> Utwórz konto M365</button>
      </div>
    </form>
  </div></div>
</div>

</div><!-- /pane-standalone -->


<!-- ══════════════════════════════════════════════════════════════════════════
     Tab: Synchronizacja
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="pane-sync" role="tabpanel">
<div class="row g-3">
<div class="col-xl-7">
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-arrow-repeat"></i> Synchronizacja kont M365</div>
<div class="card-body">
  <p class="text-muted">
    Synchronizacja sprawdza, czy konta Microsoft 365 powiązane z umowami powinny być aktywne lub wyłączone
    na podstawie dat i statusów umów w systemie.
  </p>
  <ul class="small text-muted mb-3">
    <li>Konta wolontariuszy po zakończeniu umowy są dezaktywowane.</li>
    <li>Konta umów zlecenie i o dzieło są dezaktywowane po upływie terminu.</li>
    <li>Synchronizację uruchamia się ręcznie lub można ją zaplanować cronie.</li>
  </ul>
  <?php if ($m365_enabled): ?>
  <a href="m365_sync.php" class="btn btn-primary">
    <i class="bi bi-arrow-repeat"></i> Przejdź do synchronizacji kont
  </a>
  <?php else: ?>
  <div class="alert alert-warning py-2 small mb-0">
    <i class="bi bi-exclamation-triangle"></i>
    Integracja M365 jest wyłączona. Włącz ją w zakładce <a href="#" onclick="switchM365Tab('config')" class="alert-link">Konfiguracja</a>.
  </div>
  <?php endif; ?>
</div>
</div>
</div>
</div>
</div><!-- /pane-sync -->

<!-- ══ ZAKŁADKA: PRZED 01.06.2026 ═══════════════════════════════════════════ -->
<div class="tab-pane fade" id="pane-przed2026" role="tabpanel">
<?php
// Załaduj dane na potrzeby wyświetlenia
$_p2026_contracts = db_all(
    "SELECT id, imie_nazwisko, email, m365_login, m365_user_id, numer_umowy
     FROM umowy_wolontariat
     WHERE is_technical=1 AND m365_konto=1
     ORDER BY imie_nazwisko"
);
// Uzupełnij status lokalnego konta dla każdego
foreach ($_p2026_contracts as &$_pc) {
    $m = ($_pc['m365_user_id'] ?? '')
        ? db_one("SELECT id, name, microsoft_id FROM users WHERE microsoft_id=?", [$_pc['m365_user_id']])
        : null;
    if (!$m && ($_pc['email'] ?? '')) {
        $m = db_one("SELECT id, name, microsoft_id FROM users WHERE LOWER(email)=LOWER(?)", [$_pc['email']]);
    }
    $_pc['_local'] = $m;
    $_pc['_status'] = !$m ? 'brak'
        : ($m['microsoft_id'] ? 'ok' : 'nopowiazania');
}
unset($_pc);
$_cnt_ok   = count(array_filter($_p2026_contracts, fn($r) => $r['_status'] === 'ok'));
$_cnt_warn = count(array_filter($_p2026_contracts, fn($r) => $r['_status'] === 'nopowiazania'));
$_cnt_miss = count(array_filter($_p2026_contracts, fn($r) => $r['_status'] === 'brak'));
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h6 class="mb-0">Konta M365 z umów przed 01.06.2026</h6>
    <div class="text-muted small">Umowy techniczne z kontem M365 — podpinanie kont lokalnych (logowanie przez Microsoft).</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge bg-success py-2 px-3"><i class="bi bi-check me-1"></i>Powiązane: <?= $_cnt_ok ?></span>
    <?php if ($_cnt_warn): ?>
    <span class="badge bg-warning text-dark py-2 px-3"><i class="bi bi-exclamation-triangle me-1"></i>Konto bez powiązania: <?= $_cnt_warn ?></span>
    <?php endif; ?>
    <?php if ($_cnt_miss): ?>
    <span class="badge bg-danger py-2 px-3"><i class="bi bi-x me-1"></i>Brak konta lokalnego: <?= $_cnt_miss ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if (isset($_p2026_results)): ?>
<div class="alert alert-success alert-dismissible fade show mb-3">
  <strong>Wyniki przetwarzania:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($_p2026_results as $_pr): ?>
    <li>
      <strong><?= h($_pr['row']['imie_nazwisko'] ?: $_pr['row']['email']) ?></strong>
      — <?= h($_pr['msg']) ?>
      <?php if ($_pr['status'] === 'skip'): ?><span class="text-warning">(pominięto)</span><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (empty($_p2026_contracts)): ?>
<div class="alert alert-secondary">
  <i class="bi bi-info-circle me-1"></i>
  Brak umów technicznych z kontem M365 w systemie.
</div>
<?php else: ?>

<?php if ($_cnt_miss + $_cnt_warn > 0): ?>
<form method="post" class="mb-3">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="link_przed2026">
  <button type="submit" class="btn btn-primary"
          onclick="return confirm('Przetworzyć wszystkie niepowiązane konta (<?= $_cnt_miss + $_cnt_warn ?>)?')">
    <i class="bi bi-lightning-fill me-1"></i>Powiąż wszystkie niepowiązane (<?= $_cnt_miss + $_cnt_warn ?>)
  </button>
</form>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Wolontariusz</th>
      <th>E-mail / M365 login</th>
      <th>Konto lokalne</th>
      <th>Status</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($_p2026_contracts as $_pc): ?>
  <tr>
    <td>
      <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= $_pc['id'] ?>&tab=m365" class="fw-semibold text-decoration-none">
        <?= h($_pc['imie_nazwisko'] ?: '—') ?>
      </a>
      <?php if ($_pc['numer_umowy']): ?>
      <div class="text-muted small font-monospace"><?= h($_pc['numer_umowy']) ?></div>
      <?php endif; ?>
    </td>
    <td>
      <div><?= h($_pc['email'] ?: '—') ?></div>
      <div class="text-muted small font-monospace"><?= h($_pc['m365_login'] ?: '—') ?></div>
    </td>
    <td>
      <?php if ($_pc['_local']): ?>
        <span class="small"><?= h($_pc['_local']['name'] ?: $_pc['_local']['email'] ?? '—') ?></span>
      <?php else: ?>
        <span class="text-muted small">—</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($_pc['_status'] === 'ok'): ?>
        <span class="badge bg-success"><i class="bi bi-check"></i> Powiązane</span>
      <?php elseif ($_pc['_status'] === 'nopowiazania'): ?>
        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Konto bez M365</span>
      <?php else: ?>
        <span class="badge bg-danger"><i class="bi bi-x"></i> Brak konta</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($_pc['_status'] !== 'ok'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="link_przed2026">
        <input type="hidden" name="contract_ids[]" value="<?= intval($_pc['id']) ?>">
        <button type="submit" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-link-45deg"></i>
          <?= $_pc['_status'] === 'nopowiazania' ? 'Powiąż' : 'Utwórz' ?>
        </button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

</div><!-- /pane-przed2026 -->

</div><!-- /tab-content -->


<script>
// ── Tab persistence ─────────────────────────────────────────────────────────
const LS_KEY = 'm365_active_tab';
const tabParam = '<?= h($tab) ?>';

function switchM365Tab(name) {
    const el = document.getElementById('tab-' + name);
    if (el) { el.click(); }
}

document.addEventListener('DOMContentLoaded', function() {
    // Prefer URL param > localStorage
    let target = tabParam !== 'config' ? tabParam : (localStorage.getItem(LS_KEY) || 'config');
    switchM365Tab(target);

    document.querySelectorAll('#m365Tabs .nav-link').forEach(function(el) {
        el.addEventListener('shown.bs.tab', function(e) {
            const id = e.target.id.replace('tab-','');
            localStorage.setItem(LS_KEY, id);
        });
    });
});

// ── Config form helper ───────────────────────────────────────────────────────
function submitCfgAction(action) {
    document.getElementById('form_action').value = action;
    document.getElementById('m365form').submit();
}
document.querySelectorAll('[name="m365_license_sku_id"][type="radio"]').forEach(function(r) {
    r.addEventListener('change', function() {
        document.getElementById('sku_manual').value = r.value;
    });
});
document.getElementById('m365_enabled')?.addEventListener('change', function() {
    if (!this.checked) {
        const h = document.createElement('input');
        h.type='hidden'; h.name='m365_enabled'; h.value='0';
        this.form.appendChild(h);
    }
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
