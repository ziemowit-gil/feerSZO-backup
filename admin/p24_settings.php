<?php
/**
 * admin/p24_settings.php — Konfiguracja płatności Przelewy24 + historia płatności.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/p24.php';

require_role('admin');
p24_migrate();
$PAGE_TITLE = 'Płatności / Przelewy24';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        p24_save_setting('enabled',     !empty($_POST['enabled']) ? '1' : '0');
        p24_save_setting('environment', ($_POST['environment'] ?? 'sandbox') === 'prod' ? 'prod' : 'sandbox');
        p24_save_setting('currency',    strtoupper(trim($_POST['currency'] ?? 'PLN')) ?: 'PLN');
        p24_save_setting('merchant_id', trim($_POST['merchant_id'] ?? ''));
        p24_save_setting('pos_id',      trim($_POST['pos_id'] ?? ''));
        // Sekrety — nie nadpisuj pustym (zostaw dotychczasowy)
        if (trim($_POST['api_key'] ?? '') !== '') p24_save_setting('api_key', trim($_POST['api_key']));
        if (trim($_POST['crc']     ?? '') !== '') p24_save_setting('crc', trim($_POST['crc']));
        flash_set('success', 'Ustawienia Przelewy24 zapisane.');
        header('Location: p24_settings.php'); exit;
    }

    if ($action === 'test') {
        $r = p24_test_connection();
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: p24_settings.php'); exit;
    }
}

$cfg = [
    'enabled'     => p24_setting('enabled'),
    'environment' => p24_environment(),
    'currency'    => p24_currency(),
    'merchant_id' => p24_setting('merchant_id'),
    'pos_id'      => p24_setting('pos_id'),
    'has_api_key' => p24_setting('api_key') !== '',
    'has_crc'     => p24_setting('crc') !== '',
];
$webhook_url = rtrim(APP_URL, '/') . '/api/p24_webhook.php';
$payments = db_all("SELECT * FROM p24_payments ORDER BY id DESC LIMIT 50");

$status_label = [
    'pending' => ['Oczekuje', 'warning text-dark'],
    'paid'    => ['Opłacone', 'success'],
    'failed'  => ['Nieudane', 'danger'],
    'expired' => ['Wygasłe', 'secondary'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-wallet2 text-primary me-2"></i>Płatności / Przelewy24</h4>
  <span class="badge <?= p24_enabled() ? 'bg-success' : 'bg-warning text-dark' ?>">
    <?= p24_enabled() ? 'Aktywne' : (p24_configured() ? 'Skonfigurowane (wyłączone)' : 'Wymaga konfiguracji') ?>
  </span>
  <span class="badge bg-info text-dark"><?= $cfg['environment'] === 'prod' ? 'Produkcja' : 'Sandbox' ?></span>
</div>

<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-gear me-2"></i>Konfiguracja Przelewy24</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="save">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" id="p24_en" <?= $cfg['enabled']==='1'?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="p24_en">Włącz płatności Przelewy24</label>
          </div>

          <div class="mb-3" style="max-width:240px">
            <label class="form-label fw-semibold" for="p24_env">Środowisko</label>
            <select class="form-select" name="environment" id="p24_env">
              <option value="sandbox" <?= $cfg['environment']==='sandbox'?'selected':'' ?>>Sandbox (testowe)</option>
              <option value="prod"    <?= $cfg['environment']==='prod'?'selected':'' ?>>Produkcja</option>
            </select>
            <div class="form-text">Domyślnie sandbox — przełącz na produkcję dopiero po przetestowaniu.</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="p24_mid">Merchant ID (ID sprzedawcy) <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="merchant_id" id="p24_mid"
                   value="<?= h($cfg['merchant_id']) ?>" placeholder="np. 123456">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="p24_pos">POS ID (zwykle równy Merchant ID) <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="pos_id" id="p24_pos"
                   value="<?= h($cfg['pos_id']) ?>" placeholder="np. 123456">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="p24_key">Klucz raportowy (API key) <span class="text-danger">*</span></label>
            <input type="password" class="form-control font-monospace" name="api_key" id="p24_key"
                   placeholder="<?= $cfg['has_api_key'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'z panelu P24 → Konfiguracja sprzedawcy' ?>">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="p24_crc">Klucz CRC <span class="text-danger">*</span></label>
            <input type="password" class="form-control font-monospace" name="crc" id="p24_crc"
                   placeholder="<?= $cfg['has_crc'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'do liczenia podpisów sha384' ?>">
            <div class="form-text">Z panelu Przelewy24 → Konfiguracja sprzedawcy → Klucz CRC. Bez niego podpisy powiadomień nie są weryfikowane.</div>
          </div>

          <div class="mb-3" style="max-width:180px">
            <label class="form-label fw-semibold" for="p24_cur">Waluta</label>
            <input type="text" class="form-control text-uppercase" name="currency" id="p24_cur" value="<?= h($cfg['currency']) ?>" maxlength="3">
            <div class="form-text">Kod ISO, np. PLN.</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz</button>
            <?php if (p24_configured()): ?>
            <button type="submit" form="p24_test" class="btn btn-outline-secondary"><i class="bi bi-wifi me-1"></i>Testuj połączenie</button>
            <?php endif; ?>
          </div>
        </form>
        <form id="p24_test" method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="test">
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2"></i>Adres powiadomień (urlStatus)</div>
      <div class="card-body" style="font-size:.88rem">
        <p class="text-muted mb-2">Przelewy24 sam otrzymuje ten adres przy każdej transakcji (przekazywany automatycznie) — nie trzeba go konfigurować ręcznie w panelu P24. Zapisany tu do wglądu/diagnostyki.</p>
        <div class="d-flex align-items-center gap-2">
          <code class="flex-grow-1 px-2 py-1 rounded" style="background:#0f172a;color:#93c5fd;overflow-x:auto;display:block"><?= h($webhook_url) ?></code>
          <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                  onclick="navigator.clipboard.writeText('<?= h(addslashes($webhook_url)) ?>').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='⎘',1500)})">⎘</button>
        </div>
        <hr>
        <div class="small text-muted">
          <i class="bi bi-info-circle me-1"></i>Przelewy24 wymaga dodatkowego kroku: po powiadomieniu system sam
          wywołuje <code>transaction/verify</code> i dopiero po potwierdzeniu księguje wpłatę — nie ma tu ryzyka
          zaksięgowania na podstawie samego (potencjalnie sfałszowanego) powiadomienia.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Historia płatności -->
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header fw-semibold"><i class="bi bi-receipt me-2"></i>Ostatnie płatności <span class="badge bg-secondary ms-1"><?= count($payments) ?></span></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr><th>#</th><th>Źródło</th><th>Opis</th><th>Kwota</th><th>Status</th><th>Utworzono</th><th>Opłacono</th></tr>
      </thead>
      <tbody>
        <?php if (!$payments): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Brak płatności.</td></tr>
        <?php endif; ?>
        <?php foreach ($payments as $p):
          $sl = $status_label[$p['status']] ?? [$p['status'], 'secondary']; ?>
        <tr>
          <td class="text-muted"><?= (int)$p['id'] ?></td>
          <td class="small font-monospace"><?= h($p['source_type']) ?>#<?= (int)$p['source_id'] ?></td>
          <td class="small"><?= h(mb_substr($p['description'], 0, 50)) ?></td>
          <td class="fw-semibold text-nowrap"><?= number_format($p['amount_grosze']/100, 2, ',', ' ') ?> <?= strtoupper(h($p['currency'])) ?></td>
          <td><span class="badge bg-<?= $sl[1] ?>"><?= h($sl[0]) ?></span></td>
          <td class="small text-muted text-nowrap"><?= h(substr($p['created_at'] ?? '', 0, 16)) ?></td>
          <td class="small text-muted text-nowrap"><?= $p['paid_at'] ? h(substr($p['paid_at'],0,16)) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
