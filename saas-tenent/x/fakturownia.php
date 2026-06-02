<?php
/**
 * Fakturowanie tenantów przez fakturownia.pl — panel SaaS.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';
require_once dirname(__DIR__) . '/includes/fakturownia.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION[SAAS_SESSION_KEY])) { header('Location: index.php'); exit; }

$csrf    = saas_csrf();
$flash   = '';
$account = saas_setting('fakturownia_account');
$token   = saas_setting('fakturownia_token');

// ── AJAX: test połączenia ─────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_csrf_check();
    header('Content-Type: application/json');
    echo json_encode(fakturownia_test_connection($account, $token));
    exit;
}

// ── POST akcje ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_csrf_check();
    $action = $_POST['_action'] ?? '';

    // Zapisz globalne ustawienia API
    if ($action === 'save_settings') {
        saas_setting_set('fakturownia_account', trim($_POST['account'] ?? ''));
        $new_token = trim($_POST['token'] ?? '');
        if ($new_token !== '') saas_setting_set('fakturownia_token', $new_token);
        $account = saas_setting('fakturownia_account');
        $token   = saas_setting('fakturownia_token');
        $flash   = ['ok', 'Ustawienia API zapisane.'];
    }

    // Zapisz dane rozliczeniowe tenanta
    if ($action === 'save_billing') {
        $id = (int)($_POST['id'] ?? 0);
        saas_update($id, [
            'billing_name'    => trim($_POST['billing_name']    ?? ''),
            'billing_nip'     => trim($_POST['billing_nip']     ?? ''),
            'billing_address' => trim($_POST['billing_address'] ?? ''),
            'billing_city'    => trim($_POST['billing_city']    ?? ''),
            'billing_zip'     => trim($_POST['billing_zip']     ?? ''),
            'billing_email'   => trim($_POST['billing_email']   ?? ''),
            'billing_price'   => (float)($_POST['billing_price'] ?? 0),
        ]);
        $flash = ['ok', 'Dane rozliczeniowe zaktualizowane.'];
        header('Location: fakturownia.php'); exit;
    }

    // Wystaw fakturę dla tenanta
    if ($action === 'invoice') {
        $id = (int)($_POST['id'] ?? 0);
        $t  = saas_get($id);
        if (!$t) { $flash = ['err', 'Nie znaleziono tenanta.']; }
        else {
            $sell_date = trim($_POST['sell_date'] ?? date('Y-m-d'));
            $due_date  = trim($_POST['due_date']  ?? date('Y-m-d', strtotime('+14 days')));
            $desc      = trim($_POST['description'] ?? 'Abonament Rejestr Umów — ' . date('m/Y'));
            $price     = (float)($_POST['price'] ?? $t['billing_price'] ?? 0);
            $tax       = trim($_POST['tax'] ?? 'zw');

            $result = fakturownia_create_invoice($account, $token, [
                'sell_date'      => $sell_date,
                'issue_date'     => date('Y-m-d'),
                'payment_to'     => $due_date,
                'buyer_name'     => $t['billing_name']    ?: $t['org_name'],
                'buyer_tax_no'   => $t['billing_nip']     ?? '',
                'buyer_post_code'=> $t['billing_zip']     ?? '',
                'buyer_city'     => $t['billing_city']    ?? '',
                'buyer_street'   => $t['billing_address'] ?? '',
                'buyer_email'    => $t['billing_email']   ?: $t['admin_email'],
                'positions'      => [[
                    'name'       => $desc,
                    'quantity'   => 1,
                    'unit_price' => $price,
                    'tax'        => $tax,
                ]],
            ]);

            if ($result['success']) {
                $flash = ['ok', "Faktura <strong>{$result['number']}</strong> wystawiona. "
                    . "<a href='" . htmlspecialchars($result['invoice_url']) . "' target='_blank'>Otwórz w fakturowni</a>"];
            } else {
                $flash = ['err', 'Błąd wystawiania faktury: ' . htmlspecialchars($result['error'])];
            }
        }
    }
}

$tenants    = saas_all();
$configured = ($account !== '' && $token !== '');
$conn_ok    = null;
if ($configured) {
    $test = fakturownia_test_connection($account, $token);
    $conn_ok = $test['ok'];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fakturowanie — SaaS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#f0f4f8; }
.saas-header { background:#1e293b; color:#fff; padding:1rem 1.5rem;
               display:flex; align-items:center; justify-content:space-between; }
.saas-header h1 { font-size:1.1rem; font-weight:700; margin:0; }
.saas-header a  { color:#94a3b8; font-size:.85rem; text-decoration:none; }
</style>
</head>
<body>

<div class="saas-header">
  <h1><i class="bi bi-receipt text-warning me-2"></i>Fakturowanie — fakturownia.pl</h1>
  <a href="index.php"><i class="bi bi-arrow-left me-1"></i>Powrót</a>
</div>

<div class="container-fluid py-4" style="max-width:1100px">

<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0]==='ok'?'success':'danger' ?> alert-dismissible fade show">
  <?= $flash[1] ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- ── Ustawienia API ──────────────────────────────────────────────────────── -->
<div class="col-lg-4">
  <div class="card shadow-sm h-100">
    <div class="card-header fw-semibold small">
      <i class="bi bi-gear me-1"></i>Ustawienia API
    </div>
    <div class="card-body">
      <?php if ($configured): ?>
      <div class="mb-3">
        <span class="badge <?= $conn_ok ? 'bg-success' : 'bg-danger' ?> mb-2">
          <i class="bi bi-<?= $conn_ok ? 'check-circle' : 'x-circle' ?> me-1"></i>
          <?= $conn_ok ? 'Połączono' : 'Błąd połączenia' ?>
        </span><br>
        <span class="small text-muted font-monospace"><?= htmlspecialchars($account) ?>.fakturownia.pl</span>
      </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_csrf"    value="<?= $csrf ?>">
        <input type="hidden" name="_action"  value="save_settings">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Subdomena konta</label>
          <div class="input-group input-group-sm">
            <input type="text" name="account" class="form-control font-monospace"
                   value="<?= htmlspecialchars($account) ?>" placeholder="mojafirma" required>
            <span class="input-group-text small">.fakturownia.pl</span>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Token API</label>
          <input type="password" name="token" class="form-control form-control-sm"
                 placeholder="<?= $token ? '(zapisany — wpisz nowy aby zmienić)' : 'token z ustawień konta' ?>">
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
          <?php if ($configured): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTest">
            <i class="bi bi-wifi" id="testIcon"></i> Testuj
          </button>
          <?php endif; ?>
        </div>
        <div id="testResult" class="small mt-2"></div>
      </form>
    </div>
  </div>
</div>

<!-- ── Lista tenantów + fakturowanie ─────────────────────────────────────── -->
<div class="col-lg-8">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold small">
      <i class="bi bi-building me-1"></i>Tenanci
    </div>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light small">
        <tr>
          <th>Organizacja</th>
          <th>NIP</th>
          <th>Cena/mies.</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tenants as $t): ?>
      <tr>
        <td>
          <div class="fw-semibold small"><?= htmlspecialchars($t['org_name']) ?></div>
          <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($t['billing_email'] ?: $t['admin_email'] ?: '—') ?></div>
        </td>
        <td class="small font-monospace"><?= htmlspecialchars($t['billing_nip'] ?: '—') ?></td>
        <td class="small">
          <?= $t['billing_price'] > 0 ? number_format((float)$t['billing_price'], 2, ',', ' ') . ' zł' : '—' ?>
        </td>
        <td class="text-end">
          <div class="d-flex gap-1 justify-content-end">
            <button class="btn btn-sm btn-outline-secondary" title="Dane rozliczeniowe"
                    onclick="openBilling(<?= htmlspecialchars(json_encode($t)) ?>)">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($configured && $conn_ok): ?>
            <button class="btn btn-sm btn-outline-success" title="Wystaw fakturę"
                    onclick="openInvoice(<?= htmlspecialchars(json_encode($t)) ?>)">
              <i class="bi bi-receipt"></i>
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$tenants): ?>
      <tr><td colspan="4" class="text-center text-muted py-4">Brak tenantów.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

</div><!-- /row -->
</div>

<!-- ── MODAL: dane rozliczeniowe ─────────────────────────────────────────── -->
<div class="modal fade" id="modalBilling" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
  <form method="post">
  <input type="hidden" name="_csrf"    value="<?= $csrf ?>">
  <input type="hidden" name="_action"  value="save_billing">
  <input type="hidden" name="id"       id="billingId">
  <div class="modal-header">
    <h5 class="modal-title small fw-bold"><i class="bi bi-building me-2"></i>Dane rozliczeniowe</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label small fw-semibold">Nazwa na fakturze</label>
        <input type="text" name="billing_name" id="billingName" class="form-control form-control-sm"
               placeholder="(domyślnie: nazwa organizacji)">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">NIP</label>
        <input type="text" name="billing_nip" id="billingNip" class="form-control form-control-sm font-monospace"
               placeholder="1234567890">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">E-mail (do faktury)</label>
        <input type="email" name="billing_email" id="billingEmail" class="form-control form-control-sm">
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold">Adres (ulica i numer)</label>
        <input type="text" name="billing_address" id="billingAddress" class="form-control form-control-sm">
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Kod pocztowy</label>
        <input type="text" name="billing_zip" id="billingZip" class="form-control form-control-sm font-monospace"
               placeholder="00-000">
      </div>
      <div class="col-md-8">
        <label class="form-label small fw-semibold">Miasto</label>
        <input type="text" name="billing_city" id="billingCity" class="form-control form-control-sm">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Cena abonamentu (netto, zł/mies.)</label>
        <input type="number" name="billing_price" id="billingPrice" class="form-control form-control-sm"
               step="0.01" min="0" placeholder="0.00">
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Zapisz</button>
  </div>
  </form>
</div></div></div>

<!-- ── MODAL: wystaw fakturę ─────────────────────────────────────────────── -->
<div class="modal fade" id="modalInvoice" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
  <form method="post">
  <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
  <input type="hidden" name="_action" value="invoice">
  <input type="hidden" name="id"      id="invoiceId">
  <div class="modal-header bg-success text-white">
    <h5 class="modal-title small fw-bold"><i class="bi bi-receipt me-2"></i>Wystaw fakturę</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="mb-2 small text-muted">Nabywca: <strong id="invoiceOrg"></strong></div>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Data sprzedaży</label>
        <input type="date" name="sell_date" class="form-control form-control-sm"
               value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Termin płatności</label>
        <input type="date" name="due_date" class="form-control form-control-sm"
               value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold">Opis pozycji</label>
        <input type="text" name="description" id="invoiceDesc" class="form-control form-control-sm"
               value="Abonament Rejestr Umów — <?= date('m/Y') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Cena netto (zł)</label>
        <input type="number" name="price" id="invoicePrice" class="form-control form-control-sm"
               step="0.01" min="0" required>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Stawka VAT</label>
        <select name="tax" class="form-select form-select-sm">
          <option value="zw">ZW (zwolniony)</option>
          <option value="0">0%</option>
          <option value="8">8%</option>
          <option value="23">23%</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-receipt me-1"></i>Wystaw fakturę</button>
  </div>
  </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openBilling(t) {
    document.getElementById('billingId').value      = t.id;
    document.getElementById('billingName').value    = t.billing_name    || '';
    document.getElementById('billingNip').value     = t.billing_nip     || '';
    document.getElementById('billingEmail').value   = t.billing_email   || '';
    document.getElementById('billingAddress').value = t.billing_address || '';
    document.getElementById('billingZip').value     = t.billing_zip     || '';
    document.getElementById('billingCity').value    = t.billing_city    || '';
    document.getElementById('billingPrice').value   = t.billing_price   || '';
    new bootstrap.Modal(document.getElementById('modalBilling')).show();
}
function openInvoice(t) {
    document.getElementById('invoiceId').value    = t.id;
    document.getElementById('invoiceOrg').textContent = t.billing_name || t.org_name;
    document.getElementById('invoicePrice').value = t.billing_price || '';
    new bootstrap.Modal(document.getElementById('modalInvoice')).show();
}

// Test połączenia
document.getElementById('btnTest')?.addEventListener('click', async function() {
    const icon = document.getElementById('testIcon');
    const res  = document.getElementById('testResult');
    icon.className = 'bi bi-hourglass-split';
    res.textContent = '';
    try {
        const r    = await fetch('fakturownia.php?action=test', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: '_csrf=<?= urlencode($csrf) ?>'
        });
        const data = await r.json();
        if (data.ok) {
            icon.className = 'bi bi-check-circle-fill text-success';
            res.innerHTML  = '<span class="text-success">Połączono pomyślnie</span>';
        } else {
            icon.className = 'bi bi-x-circle-fill text-danger';
            res.innerHTML  = '<span class="text-danger">' + (data.error || 'Błąd') + '</span>';
        }
    } catch(e) {
        icon.className = 'bi bi-wifi-off text-danger';
        res.textContent = 'Błąd połączenia';
    }
});
</script>
</body>
</html>
