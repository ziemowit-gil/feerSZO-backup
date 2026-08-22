<?php
/**
 * crm/invoices/form.php — szkic faktury: nabywca, daty i pozycje.
 *
 * Edycja dotyczy WYŁĄCZNIE szkiców. Po wystawieniu dokument należy do Fakturowni
 * i SZO go nie zmienia — invoice_update() odrzuci taką próbę niezależnie od UI.
 *
 * Nabywcę można wskazać z CRM (wtedy dane wypełniają się z kartoteki) albo wpisać
 * ręcznie — kursanci TI nie muszą mieć kartoteki kontaktu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/invoices.php';

require_login();
require_module_enabled('invoices_enabled', 'Moduł Faktury');
invoices_migrate();

if (!is_admin() && !can_write('crm')) {
    flash_set('error', 'Brak uprawnień do wystawiania faktur.');
    header('Location: ' . APP_URL . '/crm/invoices/index.php'); exit;
}

$id      = (int)($_GET['id'] ?? 0);
$inv     = $id ? invoice_get($id) : null;
$is_edit = (bool)$inv;
$cfg     = invoices_config();
$uid     = (int)(current_user()['id'] ?? 0);
$errors  = [];

if ($is_edit && $inv['status'] !== 'szkic' && $inv['status'] !== 'blad') {
    flash_set('error', 'Wystawionej faktury nie można edytować.');
    header('Location: ' . APP_URL . '/crm/invoices/view.php?id=' . $id); exit;
}

// Prefill z kontaktu CRM (np. wejście z kartoteki: form.php?contact_id=…)
$prefill = [];
if (!$is_edit && ($cid = (int)($_GET['contact_id'] ?? 0))) {
    $c = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]);
    if ($c) $prefill = invoice_buyer_from_contact($c);
}

/** Pozycje z POST → lista tablic dla invoice_save_items(). */
function form_items(): array
{
    $out = [];
    foreach ((array)($_POST['items'] ?? []) as $row) {
        if (!is_array($row)) continue;
        if (trim((string)($row['name'] ?? '')) === '') continue;
        $out[] = [
            'name'     => (string)$row['name'],
            'unit'     => (string)($row['unit'] ?? 'szt.'),
            'qty'      => (float)str_replace(',', '.', (string)($row['qty'] ?? 1)),
            'unit_net' => (float)str_replace(',', '.', (string)($row['unit_net'] ?? 0)),
            'vat_rate' => (string)($row['vat_rate'] ?? '23'),
        ];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $d = [
        'kind'            => (string)($_POST['kind'] ?? $cfg['kind']),
        'contact_id'      => (int)($_POST['contact_id'] ?? 0),
        'buyer_name'      => trim($_POST['buyer_name']      ?? ''),
        'buyer_tax_no'    => trim($_POST['buyer_tax_no']    ?? ''),
        'buyer_street'    => trim($_POST['buyer_street']    ?? ''),
        'buyer_post_code' => trim($_POST['buyer_post_code'] ?? ''),
        'buyer_city'      => trim($_POST['buyer_city']      ?? ''),
        'buyer_email'     => trim($_POST['buyer_email']     ?? ''),
        'currency'        => strtoupper(trim($_POST['currency'] ?? 'PLN')) ?: 'PLN',
        'issue_date'      => trim($_POST['issue_date'] ?? ''),
        'sell_date'       => trim($_POST['sell_date']  ?? ''),
        'payment_to'      => trim($_POST['payment_to'] ?? ''),
        'notes'           => trim($_POST['notes'] ?? ''),
        // Fakturę testową może oznaczyć tylko administrator — to obejście
        // wszystkich ścieżek wysyłki, nie zwykłe pole formularza.
        'is_test'         => (is_admin() && !empty($_POST['is_test'])) ? 1 : 0,
    ];
    $items = form_items();

    if ($d['buyer_name'] === '') $errors[] = 'Nazwa nabywcy jest wymagana.';
    if (!$items)                 $errors[] = 'Dodaj co najmniej jedną pozycję z nazwą.';
    if ($d['buyer_email'] !== '' && !filter_var($d['buyer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Nieprawidłowy adres e-mail nabywcy.';
    }

    if (!$errors) {
        if ($is_edit) {
            $err = invoice_update($id, $d, $items, $uid);
            if ($err) { $errors[] = $err; }
            else {
                flash_set('success', 'Szkic faktury zapisany.');
                header('Location: ' . APP_URL . '/crm/invoices/view.php?id=' . $id); exit;
            }
        } else {
            $new_id = invoice_create($d, $items, $uid);
            flash_set('success', 'Szkic faktury utworzony — sprawdź dane i wystaw dokument.');
            header('Location: ' . APP_URL . '/crm/invoices/view.php?id=' . $new_id); exit;
        }
    }
}

// Wartości do formularza: POST (po błędzie) → rekord → prefill → domyślne.
$v = array_merge(
    [
        'kind'            => $cfg['kind'],
        'contact_id'      => 0,
        'buyer_name'      => '', 'buyer_tax_no' => '', 'buyer_street' => '',
        'buyer_post_code' => '', 'buyer_city'   => '', 'buyer_email'  => '',
        'currency'        => 'PLN',
        'issue_date'      => date('Y-m-d'),
        'sell_date'       => date('Y-m-d'),
        'payment_to'      => date('Y-m-d', strtotime('+' . $cfg['days'] . ' days')),
        'notes'           => '',
        'is_test'         => 0,
    ],
    $prefill,
    $inv ?: [],
    array_intersect_key($_POST, array_flip([
        'kind','contact_id','buyer_name','buyer_tax_no','buyer_street','buyer_post_code',
        'buyer_city','buyer_email','currency','issue_date','sell_date','payment_to','notes','is_test',
    ]))
);

$items_v = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? form_items()
    : ($inv['items'] ?? [['name' => '', 'unit' => 'szt.', 'qty' => 1, 'unit_net' => 0, 'vat_rate' => $cfg['vat']]]);
if (!$items_v) $items_v = [['name' => '', 'unit' => 'szt.', 'qty' => 1, 'unit_net' => 0, 'vat_rate' => $cfg['vat']]];

$PAGE_TITLE = $is_edit ? 'Edycja szkicu faktury' : 'Nowa faktura';
include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:900px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= APP_URL ?>/crm/invoices/index.php" class="btn btn-sm btn-crm-ghost"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0"><i class="bi bi-receipt me-2"></i><?= h($PAGE_TITLE) ?></h1>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger" role="alert">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <!-- Nabywca -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold py-2">Nabywca</div>
      <div class="card-body">
        <div class="position-relative mb-3">
          <label class="form-label small fw-semibold" for="buyerPick">Wypełnij z kartoteki CRM</label>
          <input type="text" class="form-control form-control-sm" id="buyerPick" autocomplete="off"
                 placeholder="Szukaj kontaktu — nazwa, firma lub NIP…" aria-describedby="buyerPickHelp">
          <div id="buyerPickDd" class="list-group position-absolute w-100 shadow" style="z-index:20;display:none;max-height:240px;overflow-y:auto"></div>
          <div id="buyerPickHelp" class="form-text">Opcjonalne — dane nabywcy możesz też wpisać ręcznie.</div>
        </div>

        <input type="hidden" name="contact_id" id="contact_id" value="<?= (int)$v['contact_id'] ?>">

        <div class="row g-2">
          <div class="col-md-8">
            <label class="form-label small fw-semibold" for="buyer_name">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="buyer_name" name="buyer_name" required
                   value="<?= h($v['buyer_name']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="buyer_tax_no">NIP</label>
            <input type="text" class="form-control" id="buyer_tax_no" name="buyer_tax_no" value="<?= h($v['buyer_tax_no']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="buyer_street">Ulica i numer</label>
            <input type="text" class="form-control" id="buyer_street" name="buyer_street" value="<?= h($v['buyer_street']) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-semibold" for="buyer_post_code">Kod</label>
            <input type="text" class="form-control" id="buyer_post_code" name="buyer_post_code" value="<?= h($v['buyer_post_code']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="buyer_city">Miasto</label>
            <input type="text" class="form-control" id="buyer_city" name="buyer_city" value="<?= h($v['buyer_city']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="buyer_email">E-mail</label>
            <input type="email" class="form-control" id="buyer_email" name="buyer_email" value="<?= h($v['buyer_email']) ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Dokument -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold py-2">Dokument</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="kind">Rodzaj</label>
            <select class="form-select" id="kind" name="kind">
              <?php foreach (['vat' => 'Faktura VAT', 'proforma' => 'Proforma', 'bill' => 'Rachunek'] as $k => $lbl): ?>
              <option value="<?= h($k) ?>"<?= $v['kind'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="issue_date">Data wystawienia</label>
            <input type="date" class="form-control" id="issue_date" name="issue_date" value="<?= h($v['issue_date']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="sell_date">Data sprzedaży</label>
            <input type="date" class="form-control" id="sell_date" name="sell_date" value="<?= h($v['sell_date']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="payment_to">Termin płatności</label>
            <input type="date" class="form-control" id="payment_to" name="payment_to" value="<?= h($v['payment_to']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="currency">Waluta</label>
            <input type="text" class="form-control" id="currency" name="currency" maxlength="3" value="<?= h($v['currency']) ?>">
          </div>
          <div class="col-md-9">
            <label class="form-label small fw-semibold" for="notes">Uwagi (widoczne w SZO)</label>
            <input type="text" class="form-control" id="notes" name="notes" value="<?= h($v['notes']) ?>">
          </div>
          <?php if (is_admin()): ?>
          <div class="col-12">
            <div class="form-check">
              <input type="checkbox" class="form-check-input" id="is_test" name="is_test" value="1"
                     <?= !empty($v['is_test']) ? 'checked' : '' ?> aria-describedby="isTestHelp">
              <label class="form-check-label fw-semibold" for="is_test">Faktura testowa</label>
            </div>
            <div id="isTestHelp" class="form-text">
              Numer dostanie przedrostek <code>TEST/</code> i osobną sekwencję, więc nie zużyje numeru
              produkcyjnego. Taka faktura <strong>nie jest wystawiana w Fakturowni ani KSeF</strong>,
              <strong>nie jest wysyłana do nabywcy</strong> i nie zakłada koszulki w SZO.
              Pole widoczne tylko dla administratora.
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Pozycje -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold py-2">Pozycje</div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-2" id="itemsTable">
            <caption class="visually-hidden">Pozycje faktury</caption>
            <thead class="table-light">
              <tr>
                <th scope="col" style="min-width:200px">Nazwa <span class="text-danger">*</span></th>
                <th scope="col" style="width:90px">Ilość</th>
                <th scope="col" style="width:90px">J.m.</th>
                <th scope="col" style="width:120px">Cena netto</th>
                <th scope="col" style="width:90px">VAT</th>
                <th scope="col" style="width:110px" class="text-end">Brutto</th>
                <th scope="col" style="width:40px"></th>
              </tr>
            </thead>
            <tbody id="itemsBody">
            <?php foreach ($items_v as $i => $it): ?>
              <tr data-item-row>
                <td><input type="text" class="form-control form-control-sm" name="items[<?= $i ?>][name]" value="<?= h($it['name']) ?>"></td>
                <td><input type="text" class="form-control form-control-sm text-end" data-calc name="items[<?= $i ?>][qty]" value="<?= h((string)$it['qty']) ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="items[<?= $i ?>][unit]" value="<?= h($it['unit']) ?>"></td>
                <td><input type="text" class="form-control form-control-sm text-end" data-calc name="items[<?= $i ?>][unit_net]" value="<?= h((string)$it['unit_net']) ?>"></td>
                <td><input type="text" class="form-control form-control-sm text-end" data-calc name="items[<?= $i ?>][vat_rate]" value="<?= h((string)$it['vat_rate']) ?>"></td>
                <td class="text-end text-nowrap" data-line-gross>—</td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-item aria-label="Usuń pozycję">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <th colspan="5" class="text-end">Razem brutto</th>
                <td class="text-end fw-bold" id="totalGross">—</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <button type="button" class="btn btn-sm btn-crm-outline" id="addItemBtn">
          <i class="bi bi-plus-lg me-1"></i>Dodaj pozycję
        </button>
        <p class="form-text mb-0">Stawka VAT: liczba (np. <code>23</code>, <code>8</code>, <code>0</code>) albo <code>zw</code> / <code>np</code>.</p>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-crm-primary">
        <i class="bi bi-save me-1"></i><?= $is_edit ? 'Zapisz szkic' : 'Utwórz szkic' ?>
      </button>
      <a href="<?= $is_edit ? APP_URL . '/crm/invoices/view.php?id=' . $id : APP_URL . '/crm/invoices/index.php' ?>"
         class="btn btn-crm-ghost">Anuluj</a>
    </div>
  </form>
</div>

<script>
(function () {
  var body = document.getElementById('itemsBody');
  var next = <?= count($items_v) ?>;

  function num(v) { return parseFloat(String(v || '0').replace(',', '.')) || 0; }

  // Podsumowanie liczone w przeglądarce jest wyłącznie podpowiedzią —
  // wiążące wartości wylicza invoice_item_calc() na serwerze.
  function recalc() {
    var total = 0;
    body.querySelectorAll('[data-item-row]').forEach(function (tr) {
      var qty  = num(tr.querySelector('[name$="[qty]"]').value);
      var net  = num(tr.querySelector('[name$="[unit_net]"]').value);
      var rate = tr.querySelector('[name$="[vat_rate]"]').value.trim();
      var line = qty * net;
      var vat  = /^[0-9.,]+$/.test(rate) ? line * (num(rate) / 100) : 0;
      var gross = line + vat;
      tr.querySelector('[data-line-gross]').textContent = gross.toFixed(2).replace('.', ',');
      total += gross;
    });
    document.getElementById('totalGross').textContent = total.toFixed(2).replace('.', ',');
  }

  document.getElementById('addItemBtn').addEventListener('click', function () {
    var tr = body.querySelector('[data-item-row]').cloneNode(true);
    tr.querySelectorAll('[name]').forEach(function (inp) {
      inp.name = inp.name.replace(/items\[\d+\]/, 'items[' + next + ']');
      if (inp.name.endsWith('[name]')) inp.value = '';
    });
    tr.querySelector('[data-line-gross]').textContent = '—';
    body.appendChild(tr);
    next++;
    recalc();
    tr.querySelector('input').focus();
  });

  body.addEventListener('click', function (ev) {
    if (!ev.target.closest('[data-remove-item]')) return;
    if (body.querySelectorAll('[data-item-row]').length <= 1) return;  // zostaw jeden wiersz
    ev.target.closest('[data-item-row]').remove();
    recalc();
  });

  body.addEventListener('input', function (ev) {
    if (ev.target.matches('[data-calc]')) recalc();
  });
  recalc();

  // ── Wypełnianie nabywcy z CRM ──
  var pick = document.getElementById('buyerPick');
  var dd   = document.getElementById('buyerPickDd');
  var API  = <?= json_encode(APP_URL . '/crm/api/contacts_search.php', JSON_UNESCAPED_SLASHES) ?>;
  var timer = null;

  function esc(v) {
    return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  pick.addEventListener('input', function () {
    clearTimeout(timer);
    var q = pick.value.trim();
    if (q.length < 2) { dd.style.display = 'none'; return; }
    timer = setTimeout(function () {
      fetch(API + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (rows) {
          if (!rows.length) { dd.style.display = 'none'; return; }
          dd.innerHTML = rows.map(function (c) {
            return '<button type="button" class="list-group-item list-group-item-action" data-cid="' + c.id + '">' +
              '<span class="fw-semibold">' + esc(c.name) + '</span>' +
              (c.organizacja ? ' <span class="text-muted">· ' + esc(c.organizacja) + '</span>' : '') +
              '</button>';
          }).join('');
          dd.style.display = '';
        }).catch(function () { dd.style.display = 'none'; });
    }, 220);
  });

  dd.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-cid]');
    if (!btn) return;
    // Pełne dane nabywcy (adres, NIP) bierzemy z serwera — wyszukiwarka ich nie zwraca.
    location.href = '?<?= $is_edit ? 'id=' . $id . '&' : '' ?>contact_id=' + btn.dataset.cid;
  });

  document.addEventListener('click', function (ev) {
    if (!dd.contains(ev.target) && ev.target !== pick) dd.style.display = 'none';
  });
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
