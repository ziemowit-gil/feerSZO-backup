<?php
/**
 * admin/stripe_settings.php — Konfiguracja płatności Stripe + historia płatności.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/stripe.php';

require_role('admin');
stripe_migrate();
$PAGE_TITLE = 'Płatności / Stripe';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        stripe_save_setting('enabled',         !empty($_POST['enabled']) ? '1' : '0');
        stripe_save_setting('currency',         strtolower(trim($_POST['currency'] ?? 'pln')) ?: 'pln');
        stripe_save_setting('publishable_key',  trim($_POST['publishable_key'] ?? ''));
        // Sekrety — nie nadpisuj pustym (zostaw dotychczasowy)
        if (trim($_POST['secret_key'] ?? '') !== '')     stripe_save_setting('secret_key', trim($_POST['secret_key']));
        if (trim($_POST['webhook_secret'] ?? '') !== '') stripe_save_setting('webhook_secret', trim($_POST['webhook_secret']));
        flash_set('success', 'Ustawienia Stripe zapisane.');
        header('Location: stripe_settings.php'); exit;
    }

    if ($action === 'test') {
        $r = stripe_test_connection();
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: stripe_settings.php'); exit;
    }
}

$cfg = [
    'enabled'         => stripe_setting('enabled'),
    'currency'        => stripe_setting('currency', 'pln'),
    'publishable_key' => stripe_setting('publishable_key'),
    'has_secret'      => stripe_setting('secret_key') !== '',
    'has_webhook'     => stripe_setting('webhook_secret') !== '',
];
$webhook_url = rtrim(APP_URL, '/') . '/api/stripe_webhook.php';
$payments = db_all("SELECT * FROM stripe_payments ORDER BY id DESC LIMIT 50");

$status_label = [
    'pending' => ['Oczekuje', 'warning text-dark'],
    'paid'    => ['Opłacone', 'success'],
    'failed'  => ['Nieudane', 'danger'],
    'expired' => ['Wygasłe', 'secondary'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-credit-card text-primary me-2"></i>Płatności / Stripe</h4>
  <span class="badge <?= stripe_enabled() ? 'bg-success' : 'bg-warning text-dark' ?>">
    <?= stripe_enabled() ? 'Aktywne' : ($cfg['has_secret'] ? 'Skonfigurowane (wyłączone)' : 'Wymaga konfiguracji') ?>
  </span>
</div>

<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-gear me-2"></i>Konfiguracja Stripe</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="save">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" id="st_en" <?= $cfg['enabled']==='1'?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="st_en">Włącz płatności Stripe</label>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Publishable key</label>
            <input type="text" class="form-control font-monospace" name="publishable_key"
                   value="<?= h($cfg['publishable_key']) ?>" placeholder="pk_live_… lub pk_test_…">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Secret key <span class="text-danger">*</span></label>
            <input type="password" class="form-control font-monospace" name="secret_key"
                   placeholder="<?= $cfg['has_secret'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'sk_live_… lub sk_test_…' ?>">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Webhook signing secret</label>
            <input type="password" class="form-control font-monospace" name="webhook_secret"
                   placeholder="<?= $cfg['has_webhook'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'whsec_…' ?>">
            <div class="form-text">Z panelu Stripe → Developers → Webhooks → (endpoint) → Signing secret. Bez niego podpisy nie są weryfikowane.</div>
          </div>

          <div class="mb-3" style="max-width:180px">
            <label class="form-label fw-semibold">Waluta</label>
            <input type="text" class="form-control text-uppercase" name="currency" value="<?= h($cfg['currency']) ?>" maxlength="3">
            <div class="form-text">Kod ISO, np. PLN, EUR.</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz</button>
            <?php if ($cfg['has_secret']): ?>
            <button type="submit" form="st_test" class="btn btn-outline-secondary"><i class="bi bi-wifi me-1"></i>Testuj połączenie</button>
            <?php endif; ?>
          </div>
        </form>
        <form id="st_test" method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="test">
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2"></i>Adres webhooka</div>
      <div class="card-body" style="font-size:.88rem">
        <p class="text-muted mb-2">W panelu Stripe (Developers → Webhooks → Add endpoint) podaj poniższy adres i subskrybuj zdarzenia <code>checkout.session.completed</code> oraz <code>checkout.session.expired</code>.</p>
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
