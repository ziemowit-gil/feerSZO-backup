<?php
/**
 * admin/payu_settings.php — Konfiguracja płatności PayU + historia płatności.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/payu.php';

require_role('admin');
payu_migrate();
$PAGE_TITLE = 'Płatności / PayU';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        payu_save_setting('enabled',     !empty($_POST['enabled']) ? '1' : '0');
        payu_save_setting('environment', ($_POST['environment'] ?? 'sandbox') === 'prod' ? 'prod' : 'sandbox');
        payu_save_setting('currency',    strtoupper(trim($_POST['currency'] ?? 'PLN')) ?: 'PLN');
        payu_save_setting('pos_id',      trim($_POST['pos_id'] ?? ''));
        payu_save_setting('client_id',   trim($_POST['client_id'] ?? ''));
        // Sekrety — nie nadpisuj pustym (zostaw dotychczasowy)
        if (trim($_POST['client_secret'] ?? '') !== '') payu_save_setting('client_secret', trim($_POST['client_secret']));
        if (trim($_POST['md5_key'] ?? '') !== '')        payu_save_setting('md5_key', trim($_POST['md5_key']));
        flash_set('success', 'Ustawienia PayU zapisane.');
        header('Location: payu_settings.php'); exit;
    }

    if ($action === 'test') {
        $r = payu_test_connection();
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: payu_settings.php'); exit;
    }
}

$cfg = [
    'enabled'        => payu_setting('enabled'),
    'environment'    => payu_environment(),
    'currency'       => payu_currency(),
    'pos_id'         => payu_setting('pos_id'),
    'client_id'      => payu_setting('client_id'),
    'has_secret'     => payu_setting('client_secret') !== '',
    'has_md5'        => payu_setting('md5_key') !== '',
];
$webhook_url = rtrim(APP_URL, '/') . '/api/payu_webhook.php';
$payments = db_all("SELECT * FROM payu_payments ORDER BY id DESC LIMIT 50");

$status_label = [
    'pending'  => ['Oczekuje', 'warning text-dark'],
    'paid'     => ['Opłacone', 'success'],
    'failed'   => ['Nieudane', 'danger'],
    'expired'  => ['Wygasłe', 'secondary'],
    'canceled' => ['Anulowane', 'secondary'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-wallet2 text-primary me-2"></i>Płatności / PayU</h4>
  <span class="badge <?= payu_enabled() ? 'bg-success' : 'bg-warning text-dark' ?>">
    <?= payu_enabled() ? 'Aktywne' : (payu_configured() ? 'Skonfigurowane (wyłączone)' : 'Wymaga konfiguracji') ?>
  </span>
  <span class="badge bg-info text-dark"><?= $cfg['environment'] === 'prod' ? 'Produkcja' : 'Sandbox' ?></span>
</div>

<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-gear me-2"></i>Konfiguracja PayU</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="save">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" id="pu_en" <?= $cfg['enabled']==='1'?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="pu_en">Włącz płatności PayU</label>
          </div>

          <div class="mb-3" style="max-width:240px">
            <label class="form-label fw-semibold" for="pu_env">Środowisko</label>
            <select class="form-select" name="environment" id="pu_env">
              <option value="sandbox" <?= $cfg['environment']==='sandbox'?'selected':'' ?>>Sandbox (testowe)</option>
              <option value="prod"    <?= $cfg['environment']==='prod'?'selected':'' ?>>Produkcja</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="pu_pos">POS ID (merchantPosId) <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="pos_id" id="pu_pos"
                   value="<?= h($cfg['pos_id']) ?>" placeholder="np. 300746">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="pu_cid">OAuth client_id <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="client_id" id="pu_cid"
                   value="<?= h($cfg['client_id']) ?>" placeholder="zwykle równy POS ID">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="pu_cs">OAuth client_secret <span class="text-danger">*</span></label>
            <input type="password" class="form-control font-monospace" name="client_secret" id="pu_cs"
                   placeholder="<?= $cfg['has_secret'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'klucz protokołu OAuth' ?>">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="pu_md5">Drugi klucz (MD5) <span class="text-danger">*</span></label>
            <input type="password" class="form-control font-monospace" name="md5_key" id="pu_md5"
                   placeholder="<?= $cfg['has_md5'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'klucz do weryfikacji podpisów' ?>">
            <div class="form-text">Z panelu PayU → Ustawienia → Punkty płatności → „Drugi klucz (MD5)”. Bez niego podpisy powiadomień nie są weryfikowane.</div>
          </div>

          <div class="mb-3" style="max-width:180px">
            <label class="form-label fw-semibold" for="pu_cur">Waluta</label>
            <input type="text" class="form-control text-uppercase" name="currency" id="pu_cur" value="<?= h($cfg['currency']) ?>" maxlength="3">
            <div class="form-text">Kod ISO, np. PLN.</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz</button>
            <?php if (payu_configured()): ?>
            <button type="submit" form="pu_test" class="btn btn-outline-secondary"><i class="bi bi-wifi me-1"></i>Testuj połączenie</button>
            <?php endif; ?>
          </div>
        </form>
        <form id="pu_test" method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="test">
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2"></i>Adres powiadomień (notifyUrl)</div>
      <div class="card-body" style="font-size:.88rem">
        <p class="text-muted mb-2">W panelu PayU (punkt płatności → adres powiadomień / notifyUrl) podaj poniższy adres. PayU wyśle na niego status zamówienia.</p>
        <div class="d-flex align-items-center gap-2">
          <code class="flex-grow-1 px-2 py-1 rounded" style="background:#0f172a;color:#93c5fd;overflow-x:auto;display:block"><?= h($webhook_url) ?></code>
          <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                  onclick="navigator.clipboard.writeText('<?= h(addslashes($webhook_url)) ?>').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='⎘',1500)})">⎘</button>
        </div>
        <hr>
        <div class="small text-muted">
          <i class="bi bi-info-circle me-1"></i>Linki do zapłaty generujesz w rozliczeniach TI
          (<a href="<?= APP_URL ?>/karty30/ti/billing.php">Zajęcia TI → Rozliczenia</a>) przy wystawionych pozycjach.
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
