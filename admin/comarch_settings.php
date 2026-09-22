<?php
/**
 * admin/comarch_settings.php — Integracja Comarch Betterfly (faktury).
 * Konfiguracja OAuth (Client ID/Secret), test połączenia, podgląd ostatnich
 * faktur i pobieranie ich PDF. Wymaga roli admin.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/comarch_betterfly.php';

require_role('admin');
$PAGE_TITLE = 'Comarch Betterfly — faktury';
$flash = $flash_type = '';
$test = null; $invoices = null; $inv_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'save') {
        org_setting_set('comarch_client_id',     trim($_POST['client_id'] ?? ''));
        // Secret zapisujemy tylko gdy podano nowy (puste pole = zostaw stary).
        if (trim($_POST['client_secret'] ?? '') !== '') {
            org_setting_set('comarch_client_secret', trim($_POST['client_secret']));
        }
        org_setting_set('comarch_base_url', trim($_POST['base_url'] ?? ''));
        org_setting_set('comarch_enabled',  isset($_POST['enabled']) ? '1' : '0');
        // Zmiana danych = unieważnij zapamiętany token.
        org_setting_set('comarch_token', '');
        org_setting_set('comarch_token_exp', '0');
        $flash = 'Zapisano ustawienia integracji.'; $flash_type = 'success';
    }

    if ($act === 'test') {
        $test = comarch_test_connection();
        $flash = $test['ok'] ? 'Połączenie OK — uwierzytelniono i pobrano dane.' : ('Test nieudany: ' . $test['error']);
        $flash_type = $test['ok'] ? 'success' : 'danger';
    }

    if ($flash) { flash_set($flash_type, $flash); }
    header('Location: ' . APP_URL . '/admin/comarch_settings.php'); exit;
}

$cid      = org_setting('comarch_client_id');
$has_sec  = org_setting('comarch_client_secret') !== '';
$base     = org_setting('comarch_base_url') ?: COMARCH_BASE_DEFAULT;
$enabled  = org_setting('comarch_enabled') === '1';

// Podgląd ostatnich faktur (gdy skonfigurowane).
if (comarch_configured()) {
    $lst = comarch_list_invoices(['page' => 1]);
    if ($lst['ok']) $invoices = array_slice($lst['items'], 0, 15);
    else $inv_err = $lst['error'];
}

include dirname(__DIR__) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
  <li class="breadcrumb-item active">Comarch Betterfly</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Comarch Betterfly — faktury</h4>
  <span class="badge bg-<?= $enabled ? 'success' : 'secondary' ?>"><?= $enabled ? 'Włączona' : 'Wyłączona' ?></span>
</div>

<?= flash_html() ?>

<div class="card shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-key me-1"></i>Dane dostępowe (OAuth 2.0)</div>
  <div class="card-body">
    <p class="text-muted small mb-3">Wygeneruj Client ID / Secret w Comarch Betterfly → <em>Moje konto → Zarządzaj kontem → Publiczne API</em>. Uwierzytelnianie: <code>client_credentials</code>, token ważny ~10 min (odświeżany automatycznie).</p>
    <form method="post" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save">
      <div class="col-md-6"><label class="form-label mb-1 small">Client ID</label>
        <input type="text" name="client_id" class="form-control form-control-sm" value="<?= h($cid) ?>" autocomplete="off"></div>
      <div class="col-md-6"><label class="form-label mb-1 small">Client Secret <?= $has_sec ? '<span class="text-success">(zapisany — zostaw puste, by nie zmieniać)</span>' : '' ?></label>
        <input type="password" name="client_secret" class="form-control form-control-sm" value="" autocomplete="new-password" placeholder="<?= $has_sec ? '••••••••' : '' ?>"></div>
      <div class="col-md-8"><label class="form-label mb-1 small">Bazowy URL API</label>
        <input type="text" name="base_url" class="form-control form-control-sm" value="<?= h($base) ?>" placeholder="<?= h(COMARCH_BASE_DEFAULT) ?>"></div>
      <div class="col-md-4 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="enabled" id="c-enabled" <?= $enabled ? 'checked' : '' ?>>
          <label class="form-check-label small" for="c-enabled">Integracja włączona</label>
        </div>
      </div>
      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        <button formaction="<?= APP_URL ?>/admin/comarch_settings.php" name="_action" value="test" class="btn btn-outline-secondary btn-sm" <?= comarch_configured() ? '' : 'disabled' ?>>
          <i class="bi bi-plug me-1"></i>Testuj połączenie
        </button>
      </div>
    </form>
  </div>
</div>

<?php if (comarch_configured()): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-1"></i>Ostatnie faktury sprzedaży</span>
    <span class="text-muted small">API v<?= h(COMARCH_API_VER) ?></span>
  </div>
  <?php if ($inv_err): ?>
  <div class="card-body"><div class="alert alert-warning py-2 small mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?= h($inv_err) ?></div></div>
  <?php elseif (!$invoices): ?>
  <div class="card-body text-muted small">Brak faktur do wyświetlenia.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle small">
      <thead class="table-light"><tr><th>Id</th><th>Numer</th><th>Data</th><th>Kwota brutto</th><th class="text-end">PDF</th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $iv):
        $iid = (int)($iv['Id'] ?? $iv['id'] ?? 0);
        $num = $iv['Number'] ?? $iv['DocumentNumber'] ?? $iv['number'] ?? '—';
        $date= $iv['IssueDate'] ?? $iv['issueDate'] ?? '';
        $gross = $iv['GrossValue'] ?? $iv['grossValue'] ?? $iv['TotalGross'] ?? '';
      ?>
        <tr>
          <td class="text-muted"><?= $iid ?: '—' ?></td>
          <td class="fw-semibold"><?= h((string)$num) ?></td>
          <td class="text-muted"><?= h(is_string($date) ? substr($date,0,10) : '') ?></td>
          <td><?= h(is_numeric($gross) ? number_format((float)$gross, 2, ',', ' ') : (string)$gross) ?></td>
          <td class="text-end">
            <?php if ($iid): ?>
            <a href="<?= APP_URL ?>/admin/comarch_invoice_pdf.php?id=<?= $iid ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="Pobierz PDF" target="_blank"><i class="bi bi-filetype-pdf"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="text-muted small">
  <i class="bi bi-info-circle me-1"></i>Wystawianie faktur przez API Comarch wymaga identyfikatorów (nabywca, forma płatności, produkty, stawki VAT).
  Funkcje klienta: <code>comarch_create_invoice()</code>, <code>comarch_get_invoice()</code>, <code>comarch_invoice_pdf()</code>,
  oraz słowniki <code>comarch_partners()/products()/vat_rates()/payment_types()</code> (includes/comarch_betterfly.php).
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
