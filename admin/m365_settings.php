<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

require_role('admin');
ika_require(APP_URL . '/admin/m365_settings.php', 3600);
$PAGE_TITLE = 'Konfiguracja Microsoft 365';

// ── Bieżące ustawienia z DB ───────────────────────────────────────────────────
$cfg = [];
foreach (['m365_enabled','m365_graph_client_id','m365_graph_client_secret',
          'm365_domain','m365_license_sku_id','m365_sender_user_id',
          'm365_send_from_email'] as $k) {
    $cfg[$k] = m365_setting($k);
}

$action      = $_POST['_action'] ?? '';
$test_result = null;
$skus        = [];
$users       = [];
$post        = $_POST;

// ── Auto-wykryte dane z OAuth (po m365_connect_callback) ─────────────────────
$autodetect = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['m365_autodetect'])) {
    $autodetect  = $_SESSION['m365_autodetect'];
    unset($_SESSION['m365_autodetect']);
    $test_result = [
        'ok'       => $autodetect['ok'] ?? false,
        'org_name' => $autodetect['org_name'] ?? '',
        'domains'  => $autodetect['domains'] ?? [],
        'error'    => $autodetect['error'] ?? '',
    ];
    if ($autodetect['ok'] ?? false) {
        $skus  = $autodetect['skus']  ?? [];
        $users = $autodetect['users'] ?? [];
    }
    if (!empty($autodetect['domain'])) $cfg['m365_domain'] = $autodetect['domain'];
}

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $tenant_id     = trim($post['m365_tenant_id'] ?? '') ?: m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $client_id     = trim($post['m365_graph_client_id']     ?? $cfg['m365_graph_client_id']);
    // Puste pole = zostaw stary secret
    $client_secret = trim($post['m365_graph_client_secret'] ?? '');
    if ($client_secret === '') $client_secret = $cfg['m365_graph_client_secret'];
    $domain        = trim($post['m365_domain'] ?? $cfg['m365_domain']) ?: 'feer.org.pl';

    if ($action === 'test' || $action === 'save') {
        if ($client_id && $client_secret) {
            $graph       = new M365Graph(compact('tenant_id','client_id','client_secret','domain'));
            $test_result = $graph->test_connection();
            if ($test_result['ok']) {
                $skus  = array_values($graph->get_subscribed_skus());
                $users = $graph->get_users();
            }
        } else {
            $test_result = ['ok' => false, 'error' => 'Uzupełnij Client ID i Client Secret.'];
        }
    }

    if ($action === 'save') {
        m365_save_setting('m365_enabled',             trim($post['m365_enabled']          ?? '0'));
        m365_save_setting('m365_graph_client_id',     $client_id);
        if ($client_secret) m365_save_setting('m365_graph_client_secret', $client_secret);
        if ($tenant_id)     m365_save_setting('m365_tenant_id', $tenant_id);
        m365_save_setting('m365_domain',              $domain);
        m365_save_setting('m365_license_sku_id',      trim($post['m365_license_sku_id']   ?? ''));
        m365_save_setting('m365_sender_user_id',      trim($post['m365_sender_user_id']   ?? ''));
        m365_save_setting('m365_send_from_email',    trim($post['m365_send_from_email']  ?? ''));
        flash_set('success', 'Konfiguracja M365 zapisana.');
        header('Location: m365_settings.php'); exit;
    }

    // Zaktualizuj $cfg o nowe wartości (żeby form pokazywał co wpisano)
    $cfg['m365_graph_client_id'] = $client_id;
    $cfg['m365_domain']          = $domain;
}

// ── Auto-test przy ładowaniu strony (jeśli skonfigurowane i brak danych z OAuth) ─
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $test_result === null && $cfg['m365_graph_client_id'] && $cfg['m365_graph_client_secret']) {
    $graph       = new M365Graph();
    $test_result = $graph->test_connection();
    if ($test_result['ok']) {
        $skus  = array_values($graph->get_subscribed_skus());
        $users = $graph->get_users();
    }
}

$tenant_id = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.step-badge { width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0 }
.step-ok    { background:#d1fae5;color:#065f46 }
.step-todo  { background:#e2e8f0;color:#475569 }
.step-warn  { background:#fef3c7;color:#92400e }
.cfg-row    { border-bottom:1px solid #f1f5f9;padding:.55rem 0 }
.cfg-row:last-child { border-bottom:0 }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-microsoft text-primary"></i> Konfiguracja Microsoft 365</h4>
  <div class="d-flex gap-2">
    <a href="m365_connect.php" class="btn btn-sm btn-outline-success"><i class="bi bi-plug"></i> Połącz przez OAuth</a>
    <a href="m365_sync.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Synchronizacja kont</a>
    <a href="sharepoint_settings.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-cloud-upload"></i> SharePoint</a>
  </div>
</div>

<?= flash_html() ?>

<?php if ($autodetect && ($autodetect['ok'] ?? false)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-check-circle-fill fs-5"></i>
  <div>
    <strong>Połączono z Microsoft 365!</strong>
    Organizacja: <strong><?= h($autodetect['org_name']) ?></strong>
    &middot; Tenant ID i domena wykryte i zapisane automatycznie.
    <?php if (!empty($autodetect['domain'])): ?>
    Domena: <code><?= h($autodetect['domain']) ?></code>
    <?php endif; ?>
  </div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">

<!-- ── FORMULARZ ─────────────────────────────────────────────────────────── -->
<div class="col-xl-7">

<form method="post" id="m365form">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_action" id="form_action" value="test">

<!-- Krok 1 -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <span class="step-badge <?= ($test_result['ok'] ?? false) ? 'step-ok' : 'step-todo' ?>">1</span>
  Rejestracja aplikacji Azure AD
</div>
<div class="card-body">
  <div class="alert alert-light border small p-2 mb-3">
    <strong>Redirect URI (dodaj obie w Azure → Authentication → Web):</strong><br>
    <code class="user-select-all"><?= h(APP_URL) ?>/admin/m365_connect_callback.php</code>
    <span class="text-muted">— konfiguracja (OAuth wizard)</span><br>
    <code class="user-select-all"><?= h(APP_URL) ?>/auth/microsoft.php</code>
    <span class="text-muted">— logowanie użytkowników</span><br><br>
    <strong>Uprawnienia Application (nie delegowane):</strong>
    <code>User.ReadWrite.All</code> &nbsp;•&nbsp; <code>Directory.ReadWrite.All</code> &nbsp;•&nbsp;
    <code>Mail.Send</code> &nbsp;•&nbsp; <code>Organization.Read.All</code><br>
    <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" class="small">
      <i class="bi bi-box-arrow-up-right"></i> Azure Portal → App registrations →
    </a>
  </div>

  <div class="row g-3">
    <div class="col-12">
      <label class="form-label fw-semibold small" for="m365_tenant_id">Tenant ID</label>
      <input type="text" name="m365_tenant_id" id="m365_tenant_id"
             class="form-control form-control-sm font-monospace"
             value="<?= h($tenant_id) ?>"
             placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx lub feer.org.pl">
      <div class="form-text">
        Znajdziesz w <a href="https://portal.azure.com/#view/Microsoft_AAD_IAM/TenantPropertiesBlade" target="_blank" rel="noopener">Azure Portal → Azure AD → Properties → Tenant ID</a>.
        Możesz też wpisać domenę (np. <code>feer.org.pl</code>).
        Jeśli pole puste używany jest endpoint <code>common</code>.
        <a href="m365_connect.php" class="ms-2"><i class="bi bi-plug"></i> Wykryj przez OAuth</a>
      </div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold small">Client ID (Application ID) <span class="text-danger">*</span></label>
      <input type="text" name="m365_graph_client_id" class="form-control form-control-sm font-monospace"
        value="<?= h($cfg['m365_graph_client_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold small">Client Secret <span class="text-danger">*</span></label>
      <input type="password" name="m365_graph_client_secret" class="form-control form-control-sm"
        placeholder="<?= $cfg['m365_graph_client_secret'] ? '(skonfigurowany — zostaw puste by nie zmieniać)' : 'Wklej secret z Azure' ?>"
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
          value="<?= h($cfg['m365_domain'] ?: 'feer.org.pl') ?>" placeholder="feer.org.pl" required>
      </div>
    </div>
  </div>

  <div class="mt-3">
    <button type="button" class="btn btn-outline-primary btn-sm" onclick="submitAction('test')">
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
  <p class="small text-muted mb-2">Wybierz licencję, która zostanie przypisana do nowych kont:</p>
  <div class="row g-2 mb-3">
  <?php foreach ($skus as $sku):
      $avail = ($sku['prepaidUnits']['enabled'] ?? 0) - ($sku['consumedUnits'] ?? 0);
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
<?php elseif ($test_result && !$test_result['ok']): ?>
  <div class="alert alert-warning small py-2 mb-2">Najpierw popraw połączenie z Azure AD.</div>
<?php elseif ($test_result === null): ?>
  <div class="alert alert-light small py-2 mb-2">Kliknij <em>Testuj połączenie</em> — licencje załadują się automatycznie.</div>
<?php else: ?>
  <div class="alert alert-warning small py-2 mb-2">Brak dostępnych licencji w tenantcie lub brak uprawnień <code>Organization.Read.All</code>.</div>
<?php endif; ?>

  <label class="form-label fw-semibold small mt-1">SKU ID licencji <span class="text-muted fw-normal">(GUID — uzupełniane automatycznie po wyborze powyżej)</span></label>
  <input type="text" name="m365_license_sku_id" id="sku_manual"
    class="form-control form-control-sm font-monospace"
    value="<?= h($cfg['m365_license_sku_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
  <div class="form-text">Zostaw puste jeśli nie chcesz przypisywać licencji automatycznie.</div>
</div>
</div>

<!-- Krok 3: Nadawca maili -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <span class="step-badge <?= ($cfg['m365_sender_user_id'] && ($test_result['ok'] ?? false)) ? 'step-ok' : (($test_result['ok'] ?? false) ? 'step-warn' : 'step-todo') ?>">3</span>
  Konto nadawcy maili systemowych
</div>
<div class="card-body">
  <p class="small text-muted mb-2">
    Ten użytkownik będzie nadawcą wszystkich maili wysyłanych przez system (akceptacje, powiadomienia).
    Musi mieć licencję z włączoną skrzynką Exchange.
  </p>

<?php if ($users): ?>
  <label class="form-label fw-semibold small">Wybierz nadawcę:</label>
  <select name="m365_sender_user_id" class="form-select form-select-sm mb-2"
    onchange="document.getElementById('sender_manual').value = this.value">
    <option value="">— wybierz użytkownika —</option>
    <?php foreach ($users as $u): ?>
    <option value="<?= h($u['id']) ?>"
      <?= $cfg['m365_sender_user_id'] === $u['id'] ? 'selected' : '' ?>>
      <?= h($u['displayName']) ?> &lt;<?= h($u['userPrincipalName']) ?>&gt;
    </option>
    <?php endforeach; ?>
  </select>
<?php elseif ($test_result === null): ?>
  <div class="alert alert-light small py-2 mb-2">Kliknij <em>Testuj połączenie</em> — lista użytkowników załaduje się automatycznie.</div>
<?php endif; ?>

  <label class="form-label fw-semibold small">ID nadawcy <span class="text-muted fw-normal">(GUID lub UPN — uzupełniany automatycznie)</span></label>
  <input type="text" name="m365_sender_user_id" id="sender_manual"
    class="form-control form-control-sm font-monospace"
    value="<?= h($cfg['m365_sender_user_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx lub upn@domena.pl">

  <div class="mt-3 pt-3 border-top">
    <label class="form-label fw-semibold small">
      Adres e-mail nadawcy (From) — <strong>wymagany do wysyłki maili systemowych i CRM</strong>
      <span class="text-danger">*</span>
    </label>
    <input type="email" name="m365_send_from_email" id="send_from_email"
      class="form-control form-control-sm"
      value="<?= h($cfg['m365_send_from_email']) ?>"
      placeholder="system@twojadomena.pl">
    <div class="form-text">
      <i class="bi bi-info-circle me-1"></i>
      Musi być aktywna skrzynka w Microsoft 365 z uprawnieniem <code>Mail.Send</code>.
      Ten adres będzie nadawcą wszystkich e-maili: powiadomień, CRM, akceptacji.
      <?php if ($cfg['m365_send_from_email']): ?>
      <br><span class="text-success"><i class="bi bi-check-circle"></i> Skonfigurowano: <?= h($cfg['m365_send_from_email']) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<!-- Krok 4: Aktywacja -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <span class="step-badge <?= $cfg['m365_enabled']==='1' ? 'step-ok' : 'step-todo' ?>">4</span>
  Aktywacja integracji
</div>
<div class="card-body">
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" role="switch" name="m365_enabled"
      id="m365_enabled" value="1" <?= $cfg['m365_enabled']==='1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="m365_enabled">
      Integracja Microsoft 365 aktywna
    </label>
  </div>
  <div class="form-text">Gdy wyłączona, konta M365 nie są tworzone ani synchronizowane automatycznie.</div>

  <div class="d-flex gap-2 mt-3">
    <button type="button" class="btn btn-primary" onclick="submitAction('save')">
      <i class="bi bi-check-lg"></i> Zapisz konfigurację
    </button>
    <button type="button" class="btn btn-outline-secondary" onclick="submitAction('test')">
      <i class="bi bi-arrow-repeat"></i> Odśwież dane z Azure
    </button>
  </div>
</div>
</div>

</form>
</div>

<!-- ── STATUS ────────────────────────────────────────────────────────────── -->
<div class="col-xl-5">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-activity"></i> Status połączenia</div>
<div class="card-body p-0">

<?php if ($test_result === null): ?>
  <div class="p-3 text-muted small">
    <i class="bi bi-info-circle"></i>
    <?= ($cfg['m365_graph_client_id'] && $cfg['m365_graph_client_secret']) ? 'Ładowanie statusu...' : 'Uzupełnij dane i kliknij "Testuj połączenie".' ?>
  </div>

<?php elseif (!$test_result['ok']): ?>
  <div class="p-3">
    <div class="alert alert-danger py-2 small mb-2">
      <i class="bi bi-x-circle-fill"></i> <strong>Brak połączenia</strong><br>
      <?= h($test_result['error'] ?? 'Nieznany błąd') ?>
    </div>
    <div class="small text-muted">Sprawdź: Client ID, Client Secret, uprawnienia aplikacji i consent admina.</div>
  </div>

<?php else: ?>
  <div class="p-3">
    <div class="alert alert-success py-2 small mb-3">
      <i class="bi bi-check-circle-fill"></i> <strong>Połączono z Azure AD</strong>
    </div>
  </div>

  <div class="px-3 pb-3">
    <div class="cfg-row d-flex justify-content-between align-items-center">
      <span class="small text-muted">Organizacja</span>
      <span class="fw-semibold small"><?= h($test_result['org_name'] ?? '?') ?></span>
    </div>
    <div class="cfg-row d-flex justify-content-between align-items-center">
      <span class="small text-muted">Tenant ID</span>
      <span class="font-monospace small"><?= h(substr($tenant_id, 0, 8)) ?>...</span>
    </div>
    <?php if (!empty($test_result['domains'])): ?>
    <div class="cfg-row d-flex justify-content-between align-items-start">
      <span class="small text-muted">Domeny</span>
      <span class="small text-end"><?= h(implode('<br>', array_slice($test_result['domains'], 0, 4))) ?></span>
    </div>
    <?php endif; ?>
    <div class="cfg-row d-flex justify-content-between align-items-center">
      <span class="small text-muted">Dostępne licencje</span>
      <span class="badge <?= count($skus) > 0 ? 'bg-success' : 'bg-secondary' ?>"><?= count($skus) ?></span>
    </div>
    <div class="cfg-row d-flex justify-content-between align-items-center">
      <span class="small text-muted">Użytkownicy (pobrano)</span>
      <span class="badge bg-secondary"><?= count($users) ?></span>
    </div>
    <div class="cfg-row d-flex justify-content-between align-items-center">
      <span class="small text-muted">Licencja skonfigurowana</span>
      <?php if ($cfg['m365_license_sku_id']): ?>
        <span class="badge bg-success"><i class="bi bi-check"></i> Tak</span>
      <?php else: ?>
        <span class="badge bg-warning text-dark">Nie wybrano</span>
      <?php endif; ?>
    </div>
    <div class="cfg-row d-flex justify-content-between align-items-center">
      <span class="small text-muted">Nadawca maili</span>
      <?php if ($cfg['m365_sender_user_id']): ?>
        <?php
        $senderUser = [];
        foreach ($users as $u) {
            if ($u['id'] === $cfg['m365_sender_user_id'] || $u['userPrincipalName'] === $cfg['m365_sender_user_id']) {
                $senderUser = $u; break;
            }
        }
        ?>
        <span class="small fw-semibold"><?= h($senderUser['displayName'] ?? $cfg['m365_sender_user_id']) ?></span>
      <?php else: ?>
        <span class="badge bg-warning text-dark">Nie ustawiono</span>
      <?php endif; ?>
    </div>
    <div class="cfg-row d-flex justify-content-between align-items-center">
      <span class="small text-muted">Integracja aktywna</span>
      <?php if ($cfg['m365_enabled'] === '1'): ?>
        <span class="badge bg-success">Tak</span>
      <?php else: ?>
        <span class="badge bg-secondary">Nie</span>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
</div>
</div>

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-question-circle"></i> Jak skonfigurować</div>
<div class="card-body small">
  <p class="text-muted mb-2">Zalecane: użyj kreatora OAuth — Tenant ID i dane organizacji zostaną wykryte automatycznie.</p>
  <ol class="ps-3 mb-2">
    <li class="mb-2">
      <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank">Azure Portal → App registrations</a>
      → <em>New registration</em>
    </li>
    <li class="mb-2">
      API permissions → Microsoft Graph → <strong>Application permissions</strong>:<br>
      <code>User.ReadWrite.All</code>, <code>Directory.ReadWrite.All</code>, <code>Mail.Send</code>, <code>Organization.Read.All</code>
    </li>
    <li class="mb-2"><em>Grant admin consent</em> dla tych uprawnień</li>
    <li class="mb-2">Certificates &amp; secrets → <em>New client secret</em> → skopiuj wartość</li>
    <li class="mb-2">Kliknij <a href="m365_connect.php"><strong>Połącz przez OAuth</strong></a> → podaj Client ID → zaloguj się kontem admina tenanta</li>
    <li>Wróć tu i wklej <strong>Client Secret</strong>, wybierz licencję i nadawcę</li>
  </ol>
</div>
</div>

<?php if ($test_result['ok'] ?? false): ?>
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-send"></i> Test wysyłki maila</div>
<div class="card-body">
  <?php if ($cfg['m365_sender_user_id']): ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="send_test">
    <div class="mb-2">
      <input type="email" name="test_email" class="form-control form-control-sm"
        placeholder="Adres e-mail do testu" required>
    </div>
    <button type="submit" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-envelope-arrow-up"></i> Wyślij testowy mail
    </button>
  </form>
  <?php if ($action === 'send_test'):
      $to = trim($_POST['test_email'] ?? '');
      if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
          try {
              require_once dirname(__DIR__) . '/includes/approval.php';
              $ok = approval_send_email($to, 'Test maila — ' . ORG_NAME,
                  '<p>To jest testowa wiadomość z systemu Rejestru Umów <strong>' . h(ORG_NAME) . '</strong>.</p>');
              echo '<div class="alert alert-' . ($ok ? 'success' : 'danger') . ' py-2 small mt-2">'
                 . ($ok ? '✓ Mail wysłany pomyślnie.' : '✗ Wysyłka nie powiodła się.') . '</div>';
          } catch (\Exception $e) {
              echo '<div class="alert alert-danger py-2 small mt-2">' . h($e->getMessage()) . '</div>';
          }
      }
  endif; ?>
  <?php else: ?>
  <p class="text-muted small mb-0">Ustaw i zapisz nadawcę maili, aby włączyć test.</p>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>

</div><!-- /col status -->
</div><!-- /row -->

<!-- SKU radio → manual input sync -->
<script>
function submitAction(action) {
    document.getElementById('form_action').value = action;
    document.getElementById('m365form').submit();
}
// Sync radio wyboru licencji z polem tekstowym
document.querySelectorAll('[name="m365_license_sku_id"][type="radio"]').forEach(r => {
    r.addEventListener('change', () => {
        document.getElementById('sku_manual').value = r.value;
    });
});
// Ustawienie enabled jako wartość "0" gdy odznaczony
document.getElementById('m365_enabled')?.addEventListener('change', function() {
    if (!this.checked) {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = 'm365_enabled'; h.value = '0';
        this.form.appendChild(h);
    }
});
</script>

<!-- ══ SEKCJA: ROUNDCUBE / IMAP OAuth2 ═══════════════════════════════════════ -->
<?php
$_rc_tenant  = m365_setting('m365_tenant_id') ?: '&lt;tenant-id&gt;';
$_rc_cid     = m365_setting('m365_graph_client_id') ?: '&lt;client-id&gt;';
$_rc_secret_ok = (bool)m365_setting('m365_graph_client_secret');
$_rc_url     = 'http://localhost:8880/';
?>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-envelope-at text-primary"></i> Roundcube Webmail — konfiguracja OAuth2 (IMAP/SMTP)
</div>
<div class="card-body small">
  <p class="mb-2 text-muted">
    Roundcube używa tych samych credentials (Client ID / Secret / Tenant ID) co Graph API,
    ale wymaga osobnych <strong>uprawnień delegowanych</strong> i <strong>redirect URI</strong> w Azure.
  </p>

  <h6 class="fw-semibold mb-2">1. Dodaj Redirect URI w Azure Portal</h6>
  <p class="mb-1">
    <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Authentication/appId/<?= h($_rc_cid) ?>/isMSAApp~/false"
       target="_blank" rel="noopener">
      Azure Portal → App registrations → Twoja aplikacja → Authentication → Web → Add URI
    </a>
  </p>
  <div class="d-flex align-items-center gap-2 mb-3 p-2 bg-light border rounded font-monospace">
    <code class="flex-grow-1 user-select-all"><?= h($_rc_url) ?></code>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0"
            onclick="navigator.clipboard.writeText('<?= h($_rc_url) ?>').then(()=>this.textContent='✓').catch(()=>{})"
            title="Skopiuj">
      <i class="bi bi-clipboard"></i>
    </button>
  </div>

  <h6 class="fw-semibold mb-2">2. Dodaj uprawnienia delegowane (nie Application)</h6>
  <p class="mb-1 text-muted">Azure → App registrations → API permissions → Add a permission → APIs my organization uses →
    <strong>Office 365 Exchange Online</strong>:</p>
  <table class="table table-sm table-bordered mb-3" style="font-size:.8rem">
    <thead class="table-light"><tr><th>Uprawnienie</th><th>Typ</th><th>Do czego</th></tr></thead>
    <tbody>
      <tr><td class="font-monospace">IMAP.AccessAsUser.All</td><td>Delegated</td><td>Odbieranie poczty IMAP</td></tr>
      <tr><td class="font-monospace">SMTP.Send</td><td>Delegated</td><td>Wysyłanie przez SMTP</td></tr>
      <tr><td class="font-monospace">offline_access</td><td>Delegated</td><td>Refresh token (długa sesja)</td></tr>
    </tbody>
  </table>
  <div class="alert alert-warning py-2 mb-3 small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Po dodaniu uprawnień kliknij <strong>Grant admin consent</strong> (wymaga roli Global Administrator).
  </div>

  <h6 class="fw-semibold mb-2">3. Aktualny status konfiguracji</h6>
  <ul class="list-unstyled mb-3" style="font-size:.82rem">
    <li class="<?= m365_setting('m365_tenant_id') ? 'text-success' : 'text-danger' ?>">
      <?= m365_setting('m365_tenant_id') ? '✓' : '✗' ?>
      Tenant ID: <code><?= h(m365_setting('m365_tenant_id') ?: 'brak — wpisz powyżej') ?></code>
    </li>
    <li class="<?= m365_setting('m365_graph_client_id') ? 'text-success' : 'text-danger' ?>">
      <?= m365_setting('m365_graph_client_id') ? '✓' : '✗' ?>
      Client ID: <code><?= h(m365_setting('m365_graph_client_id') ?: 'brak') ?></code>
    </li>
    <li class="<?= $_rc_secret_ok ? 'text-success' : 'text-danger' ?>">
      <?= $_rc_secret_ok ? '✓' : '✗' ?>
      Client Secret: <?= $_rc_secret_ok ? 'skonfigurowany' : 'brak — wpisz w formularzu powyżej' ?>
    </li>
  </ul>

  <h6 class="fw-semibold mb-1">4. Uruchomienie Roundcube</h6>
  <div class="font-monospace bg-dark text-light rounded p-2 small mb-2">
    <span class="text-muted"># W nowym terminalu:</span><br>
    /Users/zgil/webev-projects/roundcube-src/start.sh
  </div>
  <?php if (m365_setting('m365_tenant_id') && m365_setting('m365_graph_client_id') && $_rc_secret_ok): ?>
  <a href="http://localhost:8880/" target="_blank" rel="noopener" class="btn btn-sm btn-success">
    <i class="bi bi-envelope-at me-1"></i>Otwórz FEER Webmail
  </a>
  <?php else: ?>
  <div class="text-warning small"><i class="bi bi-exclamation-circle me-1"></i>Uzupełnij Tenant ID i Client Secret, żeby włączyć logowanie przez Microsoft.</div>
  <?php endif; ?>
</div>
</div>

<!-- Szybki link do kont bez umowy -->
<div class="card shadow-sm mb-3 border-primary">
<div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h6 class="mb-1"><i class="bi bi-person-badge text-primary"></i> Konta M365 bez umowy</h6>
    <small class="text-muted">Twórz i zarządzaj kontami Microsoft 365 nieprzypisanymi do żadnej umowy — tylko dla administratorów.</small>
  </div>
  <a href="<?= APP_URL ?>/admin/m365_standalone.php" class="btn btn-outline-primary">
    <i class="bi bi-arrow-right-circle"></i> Przejdź do rejestru
  </a>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
