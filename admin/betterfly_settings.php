<?php
/**
 * admin/betterfly_settings.php — Konfiguracja integracji Comarch Betterfly (faktury).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/betterfly.php';
require_once dirname(__DIR__) . '/includes/betterfly_invoices.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia Comarch Betterfly';

betterfly_invoices_migrate();

$KEYS = [
    'betterfly_enabled', 'betterfly_ti_enabled', 'betterfly_base_url',
    'betterfly_client_id', 'betterfly_client_secret',
    'betterfly_default_payment_type_id', 'betterfly_default_vat_rate_id',
    'betterfly_ti_product_id', 'betterfly_ti_price_is_gross', 'betterfly_default_vat_percent',
    'betterfly_api_ver_customers', 'betterfly_api_ver_invoices',
];
$settings = [];
foreach ($KEYS as $k) { $settings[$k] = org_setting($k); }
if ($settings['betterfly_base_url'] === '')            $settings['betterfly_base_url'] = 'https://app.comarchbetterfly.pl';
if ($settings['betterfly_api_ver_customers'] === '')   $settings['betterfly_api_ver_customers'] = 'v1.2';
if ($settings['betterfly_api_ver_invoices'] === '')    $settings['betterfly_api_ver_invoices'] = 'v1.7';
if ($settings['betterfly_default_vat_percent'] === '') $settings['betterfly_default_vat_percent'] = '23';

// ── Zapis ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    csrf_check();

    $save = [
        'betterfly_enabled'                 => isset($_POST['betterfly_enabled'])       ? '1' : '0',
        'betterfly_ti_enabled'              => isset($_POST['betterfly_ti_enabled'])    ? '1' : '0',
        'betterfly_ti_price_is_gross'       => isset($_POST['betterfly_ti_price_is_gross']) ? '1' : '0',
        'betterfly_base_url'                => rtrim(trim($_POST['betterfly_base_url'] ?? ''), '/') ?: 'https://app.comarchbetterfly.pl',
        'betterfly_client_id'              => trim($_POST['betterfly_client_id'] ?? ''),
        'betterfly_default_payment_type_id' => trim($_POST['betterfly_default_payment_type_id'] ?? ''),
        'betterfly_default_vat_rate_id'     => trim($_POST['betterfly_default_vat_rate_id'] ?? ''),
        'betterfly_ti_product_id'          => trim($_POST['betterfly_ti_product_id'] ?? ''),
        'betterfly_default_vat_percent'     => trim($_POST['betterfly_default_vat_percent'] ?? '23'),
        'betterfly_api_ver_customers'       => trim($_POST['betterfly_api_ver_customers'] ?? 'v1.2') ?: 'v1.2',
        'betterfly_api_ver_invoices'        => trim($_POST['betterfly_api_ver_invoices'] ?? 'v1.7') ?: 'v1.7',
    ];

    // Secret — zachowaj stary jeśli pole puste
    $secret = trim($_POST['betterfly_client_secret'] ?? '');
    if ($secret !== '') $save['betterfly_client_secret'] = $secret;

    foreach ($save as $k => $v) { org_setting_set($k, $v); }

    // Zmiana poświadczeń unieważnia zbuforowany token.
    org_setting_set('betterfly_token', '');
    org_setting_set('betterfly_token_expires', '0');

    $settings = array_merge($settings, $save);
    flash_set('success', 'Ustawienia Comarch Betterfly zapisane.');
    header('Location: betterfly_settings.php'); exit;
}

// ── Test połączenia (autoryzacja OAuth) ─────────────────────────────────────────
$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_test'])) {
    csrf_check();
    try {
        $client = BetterFlyClient::fromSettings();
        $token  = $client->getToken(); // wymusza autoryzację client_credentials
        $test_result = ['ok' => true, 'msg' => 'Połączenie z Comarch Betterfly działa — token OAuth2 pobrany poprawnie.'];
    } catch (BetterFlyException $e) {
        $msg = $e->getMessage();
        if ($e->httpStatus === 401 || str_contains($msg, 'client_id')) {
            $test_result = ['ok' => false, 'msg' => 'Błąd autoryzacji — sprawdź Client ID i Client Secret.'];
        } else {
            $test_result = ['ok' => false, 'msg' => 'Błąd: ' . h($msg)];
        }
    } catch (\Throwable $e) {
        $test_result = ['ok' => false, 'msg' => 'Błąd: ' . h($e->getMessage())];
    }
}

// ── Statystyki lokalnego rejestru ───────────────────────────────────────────────
$stats = db_one("SELECT
        COUNT(*)                                        AS total,
        SUM(CASE WHEN payment_status=1 THEN 1 ELSE 0 END) AS paid,
        SUM(CASE WHEN betterfly_invoice_id>0 AND payment_status<>1 THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN last_error<>'' THEN 1 ELSE 0 END) AS errors
    FROM betterfly_invoices") ?? ['total'=>0,'paid'=>0,'pending'=>0,'errors'=>0];

$configured = $settings['betterfly_client_id'] !== '' && $settings['betterfly_client_secret'] !== '';

// ── Słowniki do list wyboru ─────────────────────────────────────────────────────
// Produkty pobieramy z API (gdy skonfigurowane); stawki VAT to stały słownik.
$products       = [];
$products_error = '';
if ($configured) {
    try {
        $products = BetterFlyClient::fromSettings()->listProducts();
    } catch (\Throwable $e) {
        $products_error = $e->getMessage();
    }
}
$vat_options = betterfly_vat_rate_options();

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Comarch Betterfly</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Ustawienia Comarch Betterfly</h4>
  <?php if ($settings['betterfly_enabled'] === '1' && $configured): ?>
    <span class="badge bg-success">Aktywne</span>
  <?php elseif ($configured): ?>
    <span class="badge bg-warning text-dark">Skonfigurowane — nieaktywne</span>
  <?php else: ?>
    <span class="badge bg-secondary">Nieskonfigurowane</span>
  <?php endif; ?>
  <?php if ($settings['betterfly_ti_enabled'] === '1'): ?>
    <span class="badge bg-info text-dark">TI: fakturowanie włączone</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($test_result !== null): ?>
<div class="alert alert-<?= $test_result['ok'] ? 'success' : 'danger' ?> mb-3">
  <i class="bi bi-<?= $test_result['ok'] ? 'check-circle' : 'exclamation-triangle' ?>"></i>
  <?= $test_result['msg'] ?>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- Konfiguracja -->
<div class="col-lg-7">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Konfiguracja API</div>
<div class="card-body">
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" role="switch" name="betterfly_enabled"
           id="betterfly_enabled" value="1" <?= $settings['betterfly_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="betterfly_enabled">Integracja aktywna</label>
  </div>

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="betterfly_ti_enabled"
           id="betterfly_ti_enabled" value="1" <?= $settings['betterfly_ti_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label" for="betterfly_ti_enabled">
      Fakturowanie modułu <strong>TI</strong> przez Betterfly (wymagane do wystawiania faktur za kursy)
    </label>
  </div>

  <hr>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Client ID <span class="text-danger">*</span></label>
    <input type="text" name="betterfly_client_id" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['betterfly_client_id']) ?>"
           placeholder="Client ID z Betterfly">
    <div class="form-text">Betterfly → Moje konto → Zarządzanie kontem → Klucz API.</div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Client Secret <span class="text-danger">*</span></label>
    <input type="password" name="betterfly_client_secret" class="form-control form-control-sm font-monospace"
           autocomplete="new-password"
           placeholder="<?= $settings['betterfly_client_secret'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'Client Secret z Betterfly' ?>">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Adres API</label>
    <input type="text" name="betterfly_base_url" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['betterfly_base_url']) ?>">
  </div>

  <div class="row g-2">
    <div class="col-sm-6 mb-3">
      <label class="form-label fw-semibold small">Domyślna forma płatności (PaymentTypeId) <span class="text-danger">*</span></label>
      <input type="number" name="betterfly_default_payment_type_id" class="form-control form-control-sm"
             value="<?= h($settings['betterfly_default_payment_type_id']) ?>" placeholder="np. 10260089">
      <div class="form-text">Id formy płatności z Betterfly. API nie udostępnia listy form
        płatności — odczytaj Id w panelu Betterfly (Ustawienia → Formy płatności).</div>
    </div>
    <div class="col-sm-6 mb-3">
      <label class="form-label fw-semibold small">Domyślna stawka VAT <span class="text-danger">*</span></label>
      <select name="betterfly_default_vat_rate_id" class="form-select form-select-sm">
        <option value="">— wybierz —</option>
        <?php foreach ($vat_options as $vid => $vlabel): ?>
        <option value="<?= (int)$vid ?>" <?= (string)$vid === (string)$settings['betterfly_default_vat_rate_id'] ? 'selected' : '' ?>>
          <?= h($vlabel) ?> (VatRateId <?= (int)$vid ?>)
        </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Słownik stawek VAT Betterfly.</div>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Produkt dla pozycji kursu TI <span class="text-danger">*</span></label>
    <?php if ($products): ?>
      <select name="betterfly_ti_product_id" class="form-select form-select-sm">
        <option value="">— wybierz produkt —</option>
        <?php
          $cur = (string)$settings['betterfly_ti_product_id'];
          $found = false;
          foreach ($products as $p):
            $pid = (string)(int)($p['Id'] ?? 0);
            if ($pid === $cur) $found = true;
        ?>
        <option value="<?= h($pid) ?>" <?= $pid === $cur ? 'selected' : '' ?>>
          <?= h(trim((string)($p['Name'] ?? '')) . ' — ' . (string)($p['ProductCode'] ?? '')
                . ' (' . number_format((float)($p['SaleNetPrice'] ?? 0), 2, ',', ' ') . ' zł netto)') ?>
        </option>
        <?php endforeach; ?>
        <?php if (!$found && $cur !== ''): ?>
        <option value="<?= h($cur) ?>" selected>Bieżący ProductId <?= h($cur) ?> (spoza listy)</option>
        <?php endif; ?>
      </select>
      <div class="form-text">Produkt/usługa Betterfly użyty jako pozycja faktury za kurs (lista z API).</div>
    <?php else: ?>
      <input type="number" name="betterfly_ti_product_id" class="form-control form-control-sm"
             value="<?= h($settings['betterfly_ti_product_id']) ?>" placeholder="np. 11521520">
      <div class="form-text">
        <?php if ($products_error !== ''): ?>
          <span class="text-warning">Nie udało się pobrać listy produktów z API (<?= h($products_error) ?>) — wpisz ProductId ręcznie.</span>
        <?php else: ?>
          Zapisz Client ID/Secret i przetestuj połączenie, aby wybierać produkt z listy. Na razie wpisz ProductId ręcznie.
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="form-text">Nazwa kursu i okres trafiają do opisu pozycji. Nadpisanie per kurs: klucz
      <code>betterfly_ti_product_course_&lt;id_kursu&gt;</code>.</div>
  </div>

  <div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" role="switch" name="betterfly_ti_price_is_gross"
           id="betterfly_ti_price_is_gross" value="1" <?= $settings['betterfly_ti_price_is_gross'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label" for="betterfly_ti_price_is_gross">
      Stawki godzinowe TI są <strong>brutto</strong> (przelicz na netto przy wystawianiu)
    </label>
  </div>
  <div class="mb-3">
    <label class="form-label small">Procent VAT do przeliczenia brutto→netto</label>
    <input type="number" step="0.01" name="betterfly_default_vat_percent" class="form-control form-control-sm" style="max-width:140px"
           value="<?= h($settings['betterfly_default_vat_percent']) ?>">
  </div>

  <details class="mb-3">
    <summary class="small text-muted">Zaawansowane — wersje API</summary>
    <div class="row g-2 mt-1">
      <div class="col-sm-6">
        <label class="form-label small">Wersja API kontrahentów</label>
        <input type="text" name="betterfly_api_ver_customers" class="form-control form-control-sm font-monospace"
               value="<?= h($settings['betterfly_api_ver_customers']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label small">Wersja API faktur</label>
        <input type="text" name="betterfly_api_ver_invoices" class="form-control form-control-sm font-monospace"
               value="<?= h($settings['betterfly_api_ver_invoices']) ?>">
      </div>
    </div>
  </details>

  <div class="d-flex gap-2">
    <button type="submit" name="_save" class="btn btn-primary btn-sm"><i class="bi bi-floppy"></i> Zapisz</button>
    <button type="submit" name="_test" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug"></i> Testuj połączenie</button>
  </div>
</form>
</div>
</div>
</div>

<!-- Prawa kolumna -->
<div class="col-lg-5">

<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-bar-chart me-1"></i>Rejestr faktur Betterfly</div>
<div class="card-body">
  <div class="row text-center g-2">
    <div class="col"><div class="fs-4 fw-bold"><?= (int)$stats['total'] ?></div><div class="small text-muted">Łącznie</div></div>
    <div class="col"><div class="fs-4 fw-bold text-success"><?= (int)$stats['paid'] ?></div><div class="small text-muted">Zapłacone</div></div>
    <div class="col"><div class="fs-4 fw-bold text-warning"><?= (int)$stats['pending'] ?></div><div class="small text-muted">Oczekujące</div></div>
    <div class="col"><div class="fs-4 fw-bold text-danger"><?= (int)$stats['errors'] ?></div><div class="small text-muted">Błędy</div></div>
  </div>
</div>
</div>

<div class="card shadow-sm mt-3">
<div class="card-header fw-semibold"><i class="bi bi-book me-1"></i>Jak skonfigurować</div>
<div class="card-body small">
  <ol class="mb-2 ps-3">
    <li class="mb-2">W Betterfly: <strong>Moje konto → Zarządzanie kontem</strong> — wygeneruj klucz API
      (Client ID + Client Secret) i wklej powyżej.</li>
    <li class="mb-2">Ustal w Betterfly Id <strong>formy płatności</strong>, <strong>stawki VAT</strong>
      oraz <strong>produktu</strong> dla pozycji kursu i wpisz je w polach obok.</li>
    <li class="mb-2">Włącz integrację oraz fakturowanie TI, zapisz i kliknij <em>Testuj połączenie</em>.</li>
    <li class="mb-2">Synchronizacja statusów płatności działa przez cron
      <code>cron/betterfly_sync.php</code> (dyspozytor uruchamia ją automatycznie).</li>
  </ol>
  <div class="text-muted">Integracja niezależna od Fakturowni/KSeF. Faktury wiążą się z kursami TI
    (rozliczenie per kurs) i kontaktami CRM.</div>
</div>
</div>

</div><!-- /col -->
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
