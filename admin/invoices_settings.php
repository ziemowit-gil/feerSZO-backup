<?php
/**
 * admin/invoices_settings.php — konfiguracja modułu Faktury (integracja fakturownia.pl).
 *
 * Token API jest sekretem: przechowujemy go w settings i NIGDY nie renderujemy
 * z powrotem w formularzu — puste pole oznacza „zostaw dotychczasowy".
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/invoices.php';

require_role('admin');
invoices_migrate();
$PAGE_TITLE = 'Faktury / Fakturownia';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        // Użytkownik może wkleić pełny adres — zostawiamy samą subdomenę.
        $acc = strtolower(trim($_POST['account'] ?? ''));
        $acc = preg_replace('#^https?://#', '', $acc);
        $acc = preg_replace('#\.fakturownia\.pl.*$#', '', $acc);
        $acc = preg_replace('/[^a-z0-9\-]/', '', (string)$acc);

        org_setting_set('fakturownia_account',      (string)$acc);
        org_setting_set('fakturownia_default_vat',  trim($_POST['default_vat'] ?? 'zw') ?: 'zw');
        org_setting_set('fakturownia_vat_exempt_basis', trim($_POST['zw_basis'] ?? ''));
        org_setting_set('fakturownia_default_kind', trim($_POST['default_kind'] ?? 'vat') ?: 'vat');
        org_setting_set('fakturownia_payment_days', (string)max(0, (int)($_POST['payment_days'] ?? 14)));

        // Backend faktur: fakturownia | ksef | betterfly (dla TI decyduje betterfly_is_ti_backend()).
        $backend = in_array($_POST['invoices_backend'] ?? '', ['fakturownia', 'ksef', 'betterfly'], true)
            ? $_POST['invoices_backend'] : 'fakturownia';
        org_setting_set('invoices_backend', $backend);

        if (trim($_POST['token'] ?? '') !== '') {
            org_setting_set('fakturownia_token', trim($_POST['token']));
        }
        flash_set('success', 'Ustawienia faktur zapisane.');
        header('Location: invoices_settings.php'); exit;
    }

    if ($action === 'test') {
        $c = invoices_config();
        $r = fakturownia_test_connection($c['account'], $c['token']);
        flash_set(!empty($r['ok']) ? 'success' : 'danger',
            !empty($r['ok'])
                ? 'Połączenie z Fakturownią działa (konto ' . h($c['account']) . ').'
                : 'Błąd połączenia: ' . (string)($r['error'] ?? 'nieznany'));
        header('Location: invoices_settings.php'); exit;
    }

    if ($action === 'clear_token') {
        org_setting_set('fakturownia_token', '');
        flash_set('success', 'Token API usunięty — wystawianie faktur jest wyłączone do jego ponownego wpisania.');
        header('Location: invoices_settings.php'); exit;
    }
}

$cfg   = invoices_config();
$stats = invoice_stats();

include dirname(__DIR__) . '/includes/header.php';
?>

<h1 class="h4 mb-1"><i class="bi bi-receipt me-2"></i>Faktury — integracja z Fakturownią</h1>
<p class="text-muted small mb-4" style="max-width:70ch">
  Faktury wystawiasz w SZO (z oferty CRM, z rozliczenia TI albo ręcznie), a dokument księgowy —
  numer, PDF i status płatności — powstaje w <strong>fakturownia.pl</strong>. Po wystawieniu
  dokument jest w SZO tylko do odczytu; korekty i anulowanie robisz w Fakturowni,
  a SZO pobiera stan przyciskiem „Odśwież status".
</p>

<?php if (!module_enabled('invoices_enabled')): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill me-1"></i>
  Moduł <strong>Faktury</strong> jest wyłączony — włącz go w
  <a href="<?= APP_URL ?>/admin/modules_settings.php">Modułach</a>, żeby rejestr stał się widoczny w CRM i TI.
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">Połączenie z API</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="save">

          <?php $backend_cur = trim(org_setting('invoices_backend')) ?: 'fakturownia'; ?>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="invoices_backend">Backend faktur</label>
            <select class="form-select" id="invoices_backend" name="invoices_backend">
              <option value="fakturownia" <?= $backend_cur === 'fakturownia' ? 'selected' : '' ?>>Fakturownia.pl</option>
              <option value="ksef"        <?= $backend_cur === 'ksef'        ? 'selected' : '' ?>>KSeF (FA(3) w SZO)</option>
              <option value="betterfly"   <?= $backend_cur === 'betterfly'   ? 'selected' : '' ?>>Comarch Betterfly</option>
            </select>
            <div class="form-text">
              Wybrany system wystawia faktury TI (przycisk „Wystaw fakturę" w rozliczeniach).
              Konfiguracja Betterfly: <a href="<?= APP_URL ?>/admin/betterfly_settings.php">Comarch Betterfly</a>.
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="account">Konto (subdomena)</label>
            <div class="input-group">
              <span class="input-group-text">https://</span>
              <input type="text" class="form-control" id="account" name="account"
                     value="<?= h($cfg['account']) ?>" placeholder="mojafirma"
                     aria-describedby="accountHelp">
              <span class="input-group-text">.fakturownia.pl</span>
            </div>
            <div id="accountHelp" class="form-text">Możesz wkleić cały adres — zostawimy samą subdomenę.</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="token">Token API</label>
            <input type="password" class="form-control" id="token" name="token" autocomplete="new-password"
                   placeholder="<?= $cfg['token'] !== '' ? '•••••••• (zapisany — zostaw puste, aby nie zmieniać)' : 'wklej token z Fakturowni' ?>"
                   aria-describedby="tokenHelp">
            <div id="tokenHelp" class="form-text">
              Fakturownia → Ustawienia → Integracja → Kod autoryzacyjny API.
              Token nie jest nigdzie wyświetlany po zapisaniu.
            </div>
          </div>

          <div class="row g-3">
            <div class="col-sm-4">
              <label class="form-label fw-semibold" for="default_vat">Domyślna stawka VAT</label>
              <input type="text" class="form-control" id="default_vat" name="default_vat"
                     value="<?= h($cfg['vat']) ?>" placeholder="zw">
              <div class="form-text">
                Domyślnie <code>zw</code> — zwolnione. Można wpisać liczbę (<code>23</code>, <code>8</code>,
                <code>0</code>) albo <code>np</code>. Stawkę da się nadpisać przy każdej pozycji faktury.
              </div>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold" for="payment_days">Termin płatności (dni)</label>
              <input type="number" class="form-control" id="payment_days" name="payment_days"
                     value="<?= (int)$cfg['days'] ?>" min="0" max="365">
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold" for="default_kind">Rodzaj dokumentu</label>
              <select class="form-select" id="default_kind" name="default_kind">
                <?php foreach (['vat' => 'Faktura VAT', 'proforma' => 'Proforma', 'bill' => 'Rachunek'] as $k => $lbl): ?>
                <option value="<?= h($k) ?>"<?= $cfg['kind'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mt-3">
            <label class="form-label fw-semibold" for="zw_basis">Podstawa zwolnienia z VAT</label>
            <input type="text" class="form-control" id="zw_basis" name="zw_basis"
                   value="<?= h($cfg['zw_basis']) ?>" aria-describedby="zwHelp">
            <div id="zwHelp" class="form-text">
              Drukowana na fakturze, gdy którakolwiek pozycja ma stawkę zwolnioną —
              wymaga tego art. 106e ust. 1 pkt 19 ustawy o VAT.
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
            <button type="submit" form="fvTest" class="btn btn-outline-secondary"
                    <?= invoices_api_ready() ? '' : 'disabled' ?>>
              <i class="bi bi-plug me-1"></i>Testuj połączenie
            </button>
            <?php if ($cfg['token'] !== ''): ?>
            <button type="submit" form="fvClear" class="btn btn-outline-danger ms-auto"
                    onclick="return confirm('Usunąć token API? Wystawianie faktur przestanie działać.')">
              <i class="bi bi-trash me-1"></i>Usuń token
            </button>
            <?php endif; ?>
          </div>
        </form>

        <form method="post" id="fvTest" class="d-none">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="test">
        </form>
        <form method="post" id="fvClear" class="d-none">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="clear_token">
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">Stan rejestru</div>
      <div class="card-body">
        <?php if (!$stats['count']): ?>
        <p class="text-muted mb-0">Brak faktur w rejestrze.</p>
        <?php else: ?>
        <dl class="row mb-0 small">
          <dt class="col-7">Faktury łącznie</dt><dd class="col-5 text-end"><?= (int)$stats['count'] ?></dd>
          <dt class="col-7">Wartość brutto</dt>
          <dd class="col-5 text-end"><?= number_format($stats['gross'], 2, ',', ' ') ?> zł</dd>
          <dt class="col-7">Wystawione, nieopłacone</dt>
          <dd class="col-5 text-end"><?= number_format($stats['unpaid'], 2, ',', ' ') ?> zł</dd>
        </dl>
        <hr>
        <?php foreach ($stats['by_status'] as $st => $v): ?>
        <div class="d-flex justify-content-between small py-1">
          <span><span class="badge" style="background:<?= h(INVOICE_STATUSES[$st]['color'] ?? '#6B7280') ?>">
            <?= h(INVOICE_STATUSES[$st]['label'] ?? $st) ?></span></span>
          <span><?= (int)$v['count'] ?> · <?= number_format($v['gross'], 2, ',', ' ') ?> zł</span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/crm/invoices/index.php" class="btn btn-sm btn-outline-primary mt-3">
          <i class="bi bi-list-ul me-1"></i>Otwórz rejestr faktur
        </a>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
