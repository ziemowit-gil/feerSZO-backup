<?php
/**
 * karty30/ti/student_billing.php — Zestawienie płatności kursanta (admin).
 * GET ?client_id=N — pełna historia wpłat i należności dla jednego kursanta.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_payments.php';
$__bf = dirname(dirname(__DIR__)) . '/includes/betterfly_invoices.php';
if (is_file($__bf)) require_once $__bf;

k30_require_access();
karty30_migrate();
ti_payments_migrate();

$bf_backend = function_exists('betterfly_is_ti_backend') && betterfly_is_ti_backend();

$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin();

$client_id = (int)($_GET['client_id'] ?? 0);
if (!$client_id) { http_response_code(400); die('Brak parametru client_id.'); }

$client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]);
if (!$client) { http_response_code(404); die('Nie znaleziono kursanta.'); }

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'add_payment') {
        $amount  = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
        $paid_at = trim($_POST['paid_at'] ?? '');
        $method  = in_array($_POST['method'] ?? '', ['transfer','cash','stripe','payu','p24','other'], true)
                   ? $_POST['method'] : 'transfer';
        $note    = trim($_POST['note'] ?? '');
        // Model kombinowany: wpłatę można zaksięgować na konkretną grupę (0 = ogólna na konto)
        $pay_course = (int)($_POST['pay_course_id'] ?? 0);
        if ($pay_course > 0 && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$client_id, $pay_course])) {
            $pay_course = 0;
        }
        if ($amount > 0) {
            $r = ti_payment_add($client_id, $amount, $paid_at, $method, $note, 'manual', 0, $pay_course);
            $gname = $pay_course ? (db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$pay_course])['name'] ?? '') : '';
            $msg = 'Wpłata ' . number_format($amount, 2, ',', ' ') . ' zł zapisana'
                 . ($gname !== '' ? ' na grupę „' . $gname . '”' : ' (ogólna)') . '.';
            if ($r['credit'] > 0)
                $msg .= ' Nadpłata: ' . number_format($r['credit'], 2, ',', ' ') . ' zł'
                      . (!empty($r['emailed']) ? ' (wysłano e-mail).' : '.');
            flash_set('success', $msg);
        } else {
            flash_set('danger', 'Podaj kwotę wpłaty.');
        }
        header('Location: student_billing.php?client_id=' . $client_id); exit;
    }

    if ($op === 'del_payment') {
        if ($can_delete) {
            ti_payment_delete((int)($_POST['payment_id'] ?? 0));
            flash_set('success', 'Wpłata usunięta, saldo przeliczone.');
        }
        header('Location: student_billing.php?client_id=' . $client_id); exit;
    }

    // Faktura zbiorcza Betterfly — jedna faktura, osobna pozycja na każdy kurs.
    if ($op === 'make_invoice_betterfly_client' && $bf_backend) {
        $bm = (int)($_POST['bf_month'] ?? date('n'));
        $by = (int)($_POST['bf_year'] ?? date('Y'));
        try {
            $res = betterfly_issue_ti_client_invoice($client_id, $bm, $by, ['uid' => (int)(current_user()['id'] ?? 0)]);
            if (!empty($res['via_edok'])) {
                flash_set('success', 'Faktura zbiorcza utworzona w Betterfly i skierowana do obiegu EODoK (dokument #' . (int)$res['edok_doc_id'] . ').');
            } else {
                flash_set('success', 'Faktura zbiorcza wystawiona w Betterfly' . (!empty($res['number']) ? ' (nr ' . $res['number'] . ').' : '.'));
            }
        } catch (\Throwable $e) {
            flash_set('danger', 'Betterfly: ' . $e->getMessage());
        }
        header('Location: student_billing.php?client_id=' . $client_id); exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$bal      = ti_billing_recompute($client_id);  // odświeżamy alokację FIFO przed wyświetleniem
$balance  = ti_client_balance($client_id);
$payments = ti_payments_for_client($client_id);
// Model kombinowany — rozbicie salda na grupy (przedmioty) + grupy do wyboru przy wpłacie
$group_bal   = ti_client_group_balances($client_id);
$client_enr  = db_all("SELECT e.course_id, c.name FROM k30_ti_enrollments e
                       JOIN k30_ti_courses c ON c.id=e.course_id
                       WHERE e.client_id=? AND e.status='active' ORDER BY c.name", [$client_id]);
$course_names = [];
foreach ($client_enr as $ce)                 $course_names[(int)$ce['course_id']] = (string)$ce['name'];
foreach ($group_bal['groups'] as $gc => $gg) $course_names[(int)$gc] = $course_names[(int)$gc] ?? (string)$gg['course_name'];

// Wszystkie należności (poza anulowanymi) najstarsze → najnowsze
$billings = db_all(
    "SELECT b.*, c.name AS course_name
     FROM k30_ti_billing b
     LEFT JOIN k30_ti_courses c ON c.id=b.course_id
     WHERE b.client_id=? AND b.status!='cancelled'
     ORDER BY b.year ASC, b.month ASC, b.id ASC",
    [$client_id]
);

$months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
              7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];

$org_name  = defined('ORG_NAME') ? ORG_NAME : '';
$PAGE_TITLE = 'Płatności — ' . $client['name'];
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>
<style>
@media print {
  nav.breadcrumb-wrap, .navbar, footer, .no-print,
  .btn, button, form, .alert { display: none !important; }
  .card { border: 1px solid #ccc !important; box-shadow: none !important; break-inside: avoid; }
  .card-header { background: #e8eef8 !important; print-color-adjust: exact; }
  .table-warning { background: #fff9e0 !important; print-color-adjust: exact; }
  .table-success  { background: #edfbe9 !important; print-color-adjust: exact; }
  h4 { margin-top: 0 !important; }
  .print-header { display: block !important; }
}
.print-header { display: none; margin-bottom: 1.2rem; }
</style>

<div class="print-header">
  <?php if ($org_name): ?><div style="font-size:1.1rem;font-weight:700"><?= h($org_name) ?></div><?php endif; ?>
  <div style="font-size:.85rem;color:#555">Zestawienie płatności · <?= h($client['name']) ?> · Wydrukowano: <?= date('d.m.Y H:i') ?></div>
  <hr style="margin:.4rem 0 .8rem">
</div>

<nav aria-label="breadcrumb" class="mb-3 no-print"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="billing.php">Rozliczenia</a></li>
  <li class="breadcrumb-item active"><?= h($client['name']) ?></li>
</ol></nav>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill text-primary me-2" aria-hidden="true"></i><?= h($client['name']) ?></h4>
  <span class="badge bg-secondary">ID <?= $client_id ?></span>
  <a class="btn btn-outline-primary btn-sm ms-auto no-print" target="_blank" rel="noopener"
     href="hours_pdf.php?client_id=<?= $client_id ?>&amp;month=<?= (int)date('n') ?>&amp;year=<?= (int)date('Y') ?>"
     title="Szczegółowa rozpiska zajęć i godzin za bieżący miesiąc">
    <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Rozpiska godzin (PDF)
  </a>
  <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()">
    <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj
  </button>
</div>

<?= flash_html() ?>

<?php if ($bf_backend && $can_write): ?>
<form method="post" class="d-flex align-items-end gap-2 flex-wrap mb-3 p-2 border rounded bg-light no-print">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="make_invoice_betterfly_client">
  <div>
    <label class="form-label small mb-1">Miesiąc</label>
    <input type="number" name="bf_month" min="1" max="12" value="<?= (int)date('n') ?>" class="form-control form-control-sm" style="width:5rem">
  </div>
  <div>
    <label class="form-label small mb-1">Rok</label>
    <input type="number" name="bf_year" min="2020" max="2100" value="<?= (int)date('Y') ?>" class="form-control form-control-sm" style="width:6rem">
  </div>
  <button type="submit" class="btn btn-primary btn-sm">
    <i class="bi bi-receipt me-1"></i>Wystaw fakturę zbiorczą (Betterfly)
  </button>
  <span class="text-muted small">Jedna faktura, osobna pozycja na każdy kurs kursanta.</span>
</form>
<?php endif; ?>

<!-- ── Saldo ──────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body">
        <div class="fs-4 fw-bold lh-1"><?= number_format($balance['charges'], 2, ',', ' ') ?> zł</div>
        <div class="text-body-secondary small mt-1">Suma należności</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body">
        <div class="fs-4 fw-bold lh-1 text-success"><?= number_format($balance['payments'], 2, ',', ' ') ?> zł</div>
        <div class="text-body-secondary small mt-1">Suma wpłat</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body">
        <?php if ($balance['credit'] > 0.005): ?>
        <div class="fs-4 fw-bold lh-1 text-success"><?= number_format($balance['credit'], 2, ',', ' ') ?> zł</div>
        <div class="text-body-secondary small mt-1"><i class="bi bi-piggy-bank me-1" aria-hidden="true"></i>Nadpłata</div>
        <?php elseif ($balance['debt'] > 0.005): ?>
        <div class="fs-4 fw-bold lh-1 text-danger"><?= number_format($balance['debt'], 2, ',', ' ') ?> zł</div>
        <div class="text-body-secondary small mt-1">Niedopłata (do zapłaty)</div>
        <?php else: ?>
        <div class="fs-4 fw-bold lh-1">0,00 zł</div>
        <div class="text-body-secondary small mt-1">Saldo rozliczone</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body d-flex flex-column justify-content-between">
        <div class="text-body-secondary small mb-2">Liczba wpłat</div>
        <div class="fs-4 fw-bold lh-1"><?= count($payments) ?></div>
      </div>
    </div>
  </div>
</div>

<?php if ($balance['credit'] > 0.005): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="status">
  <i class="bi bi-piggy-bank-fill" aria-hidden="true"></i>
  <span>Nadpłata <strong><?= number_format($balance['credit'], 2, ',', ' ') ?> zł</strong> — zostanie automatycznie zaliczona na poczet kolejnych zajęć.</span>
</div>
<?php elseif ($balance['debt'] > 0.005): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="status">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <span>Niedopłata <strong><?= number_format($balance['debt'], 2, ',', ' ') ?> zł</strong> — kursant ma zaległości.</span>
</div>
<?php endif; ?>

<!-- ── Rozliczenia per grupa (model kombinowany) ──────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-collection text-primary me-2" aria-hidden="true"></i>Rozliczenia per grupa
    <span class="badge bg-secondary ms-2"><?= count($group_bal['groups']) ?></span>
    <?php if ($group_bal['general_credit'] > 0.005): ?>
    <span class="ms-auto small fw-normal text-success">
      Nadpłata ogólna (dowolna grupa): <strong><?= number_format($group_bal['general_credit'], 2, ',', ' ') ?> zł</strong>
    </span>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Należności, wpłaty i saldo kursanta w podziale na grupy</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Grupa / przedmiot</th>
          <th scope="col" class="text-end">Należności</th>
          <th scope="col" class="text-end">Wpłaty na grupę</th>
          <th scope="col" class="text-end">Pokryte</th>
          <th scope="col" class="text-end">Saldo grupy</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$group_bal['groups']): ?>
        <tr><td colspan="5" class="text-center text-body-secondary py-4">Brak rozliczeń.</td></tr>
        <?php endif; ?>
        <?php foreach ($group_bal['groups'] as $g): ?>
        <tr>
          <th scope="row" class="fw-normal"><?= h($g['course_name']) ?></th>
          <td class="text-end"><?= number_format($g['charges'], 2, ',', ' ') ?> zł</td>
          <td class="text-end"><?= number_format($g['payments'], 2, ',', ' ') ?> zł</td>
          <td class="text-end"><?= number_format($g['paid'], 2, ',', ' ') ?> zł</td>
          <td class="text-end fw-semibold">
            <?php if ($g['debt'] > 0.005): ?>
              <span class="text-danger">−<?= number_format($g['debt'], 2, ',', ' ') ?> zł</span>
            <?php elseif ($g['credit'] > 0.005): ?>
              <span class="text-success">+<?= number_format($g['credit'], 2, ',', ' ') ?> zł</span>
            <?php else: ?>
              <span class="text-body-secondary">0,00 zł</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white small text-body-secondary">
    Każda grupa (przedmiot) ma osobne rozliczenia i osobne saldo. Nadpłata przypisana do grupy pokrywa wyłącznie
    kolejne zajęcia w tej grupie; wpłaty ogólne pokrywają należności od najstarszej (FIFO) niezależnie od grupy.
  </div>
</div>

<!-- ── Dopisanie wpłaty ───────────────────────────────────────────────────── -->
<?php if ($can_write): ?>
<div class="card border-0 shadow-sm mb-4 no-print">
  <div class="card-header fw-semibold"><i class="bi bi-plus-circle text-success me-2" aria-hidden="true"></i>Dopisz wpłatę</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="add_payment">
      <div class="col-sm-3">
        <label class="form-label small mb-1" for="payamt">Kwota (zł)</label>
        <input type="text" name="amount" id="payamt" class="form-control" placeholder="0,00" inputmode="decimal" required>
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1" for="paydt">Data wpłaty</label>
        <input type="date" name="paid_at" id="paydt" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1" for="paymeth">Metoda</label>
        <select name="method" id="paymeth" class="form-select">
          <option value="transfer">Przelew</option>
          <option value="cash">Gotówka</option>
          <option value="stripe">Stripe</option>
          <option value="payu">PayU</option>
          <option value="p24">Przelewy24</option>
          <option value="other">Inna</option>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small mb-1" for="paygrp">Zaksięguj na grupę</label>
        <select name="pay_course_id" id="paygrp" class="form-select">
          <option value="0">— wpłata ogólna (FIFO) —</option>
          <?php foreach ($client_enr as $ce): ?>
          <option value="<?= (int)$ce['course_id'] ?>"><?= h($ce['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-8">
        <label class="form-label small mb-1" for="paynote">Notatka (opcjonalnie)</label>
        <input type="text" name="note" id="paynote" class="form-control" placeholder="np. tytuł przelewu">
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Zapisz wpłatę</button>
        <span class="form-text ms-2">Wpłata wyższa niż suma należności tworzy nadpłatę (rodzic/opiekun dostaje e-mail).</span>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Historia wpłat ─────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-cash-stack text-success me-2" aria-hidden="true"></i>Historia wpłat
    <span class="badge bg-secondary ms-2"><?= count($payments) ?></span>
    <span class="ms-auto text-body-secondary small fw-normal">
      Łącznie: <?= number_format($balance['payments'], 2, ',', ' ') ?> zł
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Historia wpłat kursanta</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Data</th>
          <th scope="col">Kwota</th>
          <th scope="col">Grupa</th>
          <th scope="col">Metoda</th>
          <th scope="col">Notatka</th>
          <th scope="col">Źródło</th>
          <?php if ($can_delete): ?><th scope="col" class="text-end">Akcja</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$payments): ?>
        <tr><td colspan="<?= $can_delete ? 7 : 6 ?>" class="text-center text-body-secondary py-4">Brak wpłat.</td></tr>
        <?php endif; ?>
        <?php foreach ($payments as $pm):
          $mlabel = ['transfer'=>'Przelew','cash'=>'Gotówka','stripe'=>'Stripe','payu'=>'PayU','p24'=>'Przelewy24','other'=>'Inna'][$pm['method']] ?? $pm['method'];
          $slabel = match($pm['source_type']) { 'stripe'=>'Stripe (auto)', 'payu'=>'PayU (auto)', 'p24'=>'Przelewy24 (auto)', 'manual'=>'Ręcznie', default=>h($pm['source_type']) };
        ?>
        <tr>
          <td class="text-nowrap"><?= h(substr($pm['paid_at'] ?: $pm['created_at'], 0, 10)) ?></td>
          <td class="fw-semibold text-success">+<?= number_format((float)$pm['amount'], 2, ',', ' ') ?> zł</td>
          <td><?php $pmc = (int)($pm['course_id'] ?? 0); ?>
            <?php if ($pmc > 0): ?>
              <span class="badge bg-light text-secondary border" style="font-size:.75rem"><?= h($course_names[$pmc] ?? ('Grupa #'.$pmc)) ?></span>
            <?php else: ?>
              <span class="text-body-secondary small">ogólna</span>
            <?php endif; ?>
          </td>
          <td><?= h($mlabel) ?></td>
          <td class="text-body-secondary"><?= h(mb_substr($pm['note'] ?? '', 0, 80)) ?></td>
          <td><span class="badge bg-light text-secondary border" style="font-size:.75rem"><?= $slabel ?></span></td>
          <?php if ($can_delete): ?>
          <td class="text-end">
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę wpłatę? Saldo zostanie przeliczone.')">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"          value="del_payment">
              <input type="hidden" name="payment_id"   value="<?= (int)$pm['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń wpłatę"><i class="bi bi-trash"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Należności ─────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-receipt text-primary me-2" aria-hidden="true"></i>Należności (wszystkie okresy)
    <span class="badge bg-secondary ms-2"><?= count($billings) ?></span>
    <span class="ms-auto text-body-secondary small fw-normal">
      Razem: <?= number_format($balance['charges'], 2, ',', ' ') ?> zł
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Należności kursanta</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Okres</th>
          <th scope="col">Kurs</th>
          <th scope="col">Godz.</th>
          <th scope="col">Kwota bazowa</th>
          <th scope="col">Korekta</th>
          <th scope="col">Do zapłaty</th>
          <th scope="col">Wpłacono (FIFO)</th>
          <th scope="col">Pozostało</th>
          <th scope="col">Status</th>
          <th scope="col">Termin</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$billings): ?>
        <tr><td colspan="10" class="text-center text-body-secondary py-4">Brak należności.</td></tr>
        <?php endif; ?>
        <?php
          $st_map = ['draft'=>['Robocze','secondary'],'issued'=>['Wystawione','primary'],'paid'=>['Opłacone','success'],'cancelled'=>['Anulowane','danger']];
          foreach ($billings as $b):
            $adj  = (float)($b['adjustment'] ?? 0);
            $base = (float)$b['amount'];
            $tot  = round($base + $adj, 2);
            $paid_a = round((float)($b['paid_amount'] ?? 0), 2);
            $rem    = round($tot - $paid_a, 2);
            [$stlbl, $stcol] = $st_map[$b['status']] ?? [$b['status'], 'secondary'];
            $overdue = $b['status'] !== 'paid' && !empty($b['due_date']) && $b['due_date'] < date('Y-m-d');
        ?>
        <tr class="<?= $rem > 0.005 && $b['status'] === 'issued' ? 'table-warning' : ($b['status'] === 'paid' ? 'table-success bg-opacity-25' : '') ?>">
          <td class="text-nowrap fw-semibold">
            <?= h($months_pl[(int)$b['month']] ?? $b['month']) ?> <?= (int)$b['year'] ?>
          </td>
          <td class="text-body-secondary" style="font-size:.82rem">
            <?= $b['course_name'] ? h(mb_strimwidth($b['course_name'], 0, 40, '…')) : '<span class="text-muted">łącznie</span>' ?>
          </td>
          <td><?= number_format((float)$b['hours_billed'], 2, ',', '') ?> h</td>
          <td><?= number_format($base, 2, ',', ' ') ?> zł</td>
          <td>
            <?php if ($adj != 0): ?>
              <span class="fw-semibold <?= $adj > 0 ? 'text-danger' : 'text-success' ?>">
                <?= ($adj > 0 ? '+' : '−') . number_format(abs($adj), 2, ',', ' ') ?> zł
              </span>
              <?php if (!empty($b['adjustment_note'])): ?>
              <div class="text-body-secondary" style="font-size:.72rem"><?= h(mb_substr($b['adjustment_note'], 0, 40)) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-body-secondary">—</span>
            <?php endif; ?>
          </td>
          <td class="fw-bold"><?= number_format($tot, 2, ',', ' ') ?> zł</td>
          <td class="text-success fw-semibold"><?= number_format($paid_a, 2, ',', ' ') ?> zł</td>
          <td>
            <?php if ($rem > 0.005): ?>
              <span class="fw-semibold text-danger"><?= number_format($rem, 2, ',', ' ') ?> zł</span>
            <?php else: ?>
              <span class="text-success">0,00 zł</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge text-bg-<?= $stcol ?>"><?= h($stlbl) ?></span>
          </td>
          <td>
            <?php if (!empty($b['due_date'])): ?>
              <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?>" style="font-size:.85rem">
                <?= date('d.m.Y', strtotime($b['due_date'])) ?>
                <?php if ($overdue): ?><i class="bi bi-exclamation-triangle-fill ms-1" title="Po terminie" aria-label="Po terminie"></i><?php endif; ?>
              </span>
            <?php else: ?>
              <span class="text-body-secondary">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($billings): ?>
      <tfoot class="table-light fw-semibold">
        <tr>
          <td colspan="5">Razem</td>
          <td><?= number_format($balance['charges'], 2, ',', ' ') ?> zł</td>
          <td class="text-success"><?= number_format($balance['payments'], 2, ',', ' ') ?> zł</td>
          <td>
            <?php if ($balance['debt'] > 0.005): ?>
              <span class="text-danger"><?= number_format($balance['debt'], 2, ',', ' ') ?> zł</span>
            <?php else: ?>
              <span class="text-success">0,00 zł</span>
            <?php endif; ?>
          </td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="mt-3 no-print">
  <a href="billing.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left me-1"></i>Powrót do rozliczeń</a>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
