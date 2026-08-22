<?php
/**
 * Partial: widok rozliczeń (+ opcjonalnie frekwencji). Bootstrap 5.3 + WCAG.
 * Wymaga: $rv_client_id (int), $rv_show_lessons (bool).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';
$rv_billing = k30_ti_client_billing((int)$rv_client_id);
$rv_months  = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
               7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$rv_st = ['draft'=>['Robocze','secondary'], 'issued'=>['Wystawione','primary'], 'paid'=>['Opłacone','success']];

// Saldo z księgi wpłat (nadpłata/niedopłata)
$rv_bal   = ti_client_balance((int)$rv_client_id);
$rv_total = $rv_bal['charges'];
$rv_paid  = $rv_bal['payments'];

// Grupuj wiersze billing per miesiąc
$_rv_grouped = [];
foreach ($rv_billing as $b) {
    $key = sprintf('%04d-%02d', (int)$b['year'], (int)$b['month']);
    if (!isset($_rv_grouped[$key])) {
        $_rv_grouped[$key] = ['year' => (int)$b['year'], 'month' => (int)$b['month'], 'rows' => []];
    }
    $_rv_grouped[$key]['rows'][] = $b;
}

// Przelicz agregaty per miesiąc
foreach ($_rv_grouped as &$_mg) {
    $sum_due = 0.0; $sum_h = 0.0;
    $statuses = []; $due_date = null; $any_invoice = null;
    foreach ($_mg['rows'] as $b) {
        $sum_due += (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
        $sum_h   += (float)$b['hours_billed'];
        $statuses[] = $b['status'];
        if (!$due_date && !empty($b['due_date'])) $due_date = $b['due_date'];
        if (!$any_invoice && !empty($b['invoice_path'])) $any_invoice = $b;
    }
    $all_paid  = !array_filter($statuses, fn($s) => $s !== 'paid');
    $all_draft = !array_filter($statuses, fn($s) => $s !== 'draft');
    $_mg['sum_due']    = round($sum_due, 2);
    $_mg['sum_hours']  = $sum_h;
    $_mg['agg_status'] = $all_paid ? 'paid' : ($all_draft ? 'draft' : 'issued');
    $_mg['due_date']   = $due_date;
    $_mg['any_invoice']= $any_invoice;
    $_mg['multi']      = count($_mg['rows']) > 1
                      || (count($_mg['rows']) === 1 && (int)($_mg['rows'][0]['course_id'] ?? 0) > 0);
}
unset($_mg);
?>
<h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-3"><i class="bi bi-receipt text-primary" aria-hidden="true"></i>Rozliczenia</h2>

<?php $rv_pay = k30_ti_client_payment((int)$rv_client_id); ?>
<?php if ($rv_pay['account'] !== '' || $rv_pay['title'] !== '' || $rv_pay['codes']): ?>
<div class="card mb-4 border-primary-subtle">
  <div class="card-body py-3">
    <div class="fw-semibold mb-1"><i class="bi bi-bank2 text-primary me-1" aria-hidden="true"></i>Dane do wpłaty</div>
    <dl class="row small mb-0">
      <?php if ($rv_pay['account'] !== ''): ?>
      <dt class="col-sm-3 text-body-secondary fw-normal">Nr konta</dt>
      <dd class="col-sm-9 font-monospace mb-1"><?= h($rv_pay['account']) ?></dd>
      <?php endif; ?>
      <?php if ($rv_pay['title'] !== ''): ?>
      <dt class="col-sm-3 text-body-secondary fw-normal">Tytuł wpłaty</dt>
      <dd class="col-sm-9 mb-1"><?= h($rv_pay['title']) ?></dd>
      <?php endif; ?>
      <?php if ($rv_pay['codes']): ?>
      <dt class="col-sm-3 text-body-secondary fw-normal">Kod rozliczeń</dt>
      <dd class="col-sm-9 mb-0">
        <?php foreach ($rv_pay['codes'] as $code): ?>
        <span class="badge <?= $code===9999 ? 'text-bg-warning' : 'text-bg-secondary' ?>"><?= $code===9999 ? '9999 · indywidualny' : (int)$code ?></span>
        <?php endforeach; ?>
      </dd>
      <?php endif; ?>
    </dl>
    <?php if ($rv_pay['account'] === '' && $rv_pay['title'] === ''): ?>
    <div class="text-body-secondary small">Dane do wpłaty nie zostały jeszcze ustawione — skontaktuj się z placówką.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-12 col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="fs-4 fw-bold lh-1"><?= number_format($rv_total, 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1">Suma rozliczeń</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="fs-4 fw-bold lh-1 text-success"><?= number_format($rv_paid, 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1">Opłacone</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card h-100"><div class="card-body">
      <?php if ($rv_bal['credit'] > 0.005): ?>
      <div class="fs-4 fw-bold lh-1 text-success"><?= number_format($rv_bal['credit'], 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1"><i class="bi bi-piggy-bank me-1" aria-hidden="true"></i>Nadpłata (na kolejne zajęcia)</div>
      <?php elseif ($rv_bal['debt'] > 0.005): ?>
      <div class="fs-4 fw-bold lh-1 text-danger"><?= number_format($rv_bal['debt'], 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1">Do zapłaty</div>
      <?php else: ?>
      <div class="fs-4 fw-bold lh-1">0,00 zł</div>
      <div class="text-body-secondary small mt-1">Saldo rozliczone</div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php if ($rv_bal['credit'] > 0.005): ?>
<div class="alert alert-success d-flex align-items-center gap-2" role="status">
  <i class="bi bi-piggy-bank-fill" aria-hidden="true"></i>
  <span>Na koncie jest nadpłata <strong><?= number_format($rv_bal['credit'], 2, ',', ' ') ?> zł</strong> — zostanie automatycznie zaliczona na poczet kolejnych zajęć.</span>
</div>
<?php endif; ?>

<?php
// Rozliczenia per grupa (przedmiot) — każda grupa ma osobne saldo
$rv_groups = ti_client_group_balances((int)$rv_client_id);
if (count($rv_groups['groups']) > 1):
?>
<div class="card mb-4">
  <div class="card-header fw-semibold bg-white">
    <i class="bi bi-collection text-primary me-2" aria-hidden="true"></i>Saldo w podziale na grupy
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Należności, wpłaty i saldo w podziale na grupy zajęciowe</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Grupa / przedmiot</th>
          <th scope="col" class="text-end">Należności</th>
          <th scope="col" class="text-end">Pokryte</th>
          <th scope="col" class="text-end">Saldo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rv_groups['groups'] as $rvg): ?>
        <tr>
          <th scope="row" class="fw-normal"><?= h($rvg['course_name']) ?></th>
          <td class="text-end"><?= number_format($rvg['charges'], 2, ',', ' ') ?> zł</td>
          <td class="text-end"><?= number_format($rvg['paid'], 2, ',', ' ') ?> zł</td>
          <td class="text-end fw-semibold">
            <?php if ($rvg['debt'] > 0.005): ?>
              <span class="text-danger">do zapłaty <?= number_format($rvg['debt'], 2, ',', ' ') ?> zł</span>
            <?php elseif ($rvg['credit'] > 0.005): ?>
              <span class="text-success">nadpłata <?= number_format($rvg['credit'], 2, ',', ' ') ?> zł</span>
            <?php else: ?>
              <span class="text-body-secondary">rozliczone</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white small text-body-secondary">
    Każdy przedmiot rozliczany jest osobno. Nadpłata przy grupie zostanie zaliczona na kolejne zajęcia w tej właśnie grupie.
    <?php if ($rv_groups['general_credit'] > 0.005): ?>
    Dodatkowo nadpłata ogólna <strong><?= number_format($rv_groups['general_credit'], 2, ',', ' ') ?> zł</strong> — do wykorzystania w dowolnej grupie.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <caption class="visually-hidden">Rozliczenia miesięczne</caption>
      <thead>
        <tr>
          <th scope="col">Okres / Grupa</th>
          <th scope="col">Godziny</th>
          <th scope="col">Korekta</th>
          <th scope="col">Do zapłaty</th>
          <th scope="col">Termin</th>
          <th scope="col">Status</th>
          <th scope="col">Faktura</th>
          <th scope="col">Rozpiska godzin</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$_rv_grouped): ?>
        <tr><td colspan="8" class="text-center text-body-secondary py-4">Brak rozliczeń.</td></tr>
        <?php endif; ?>

        <?php foreach ($_rv_grouped as $mg):
          [$mlbl, $mcol] = $rv_st[$mg['agg_status']] ?? [$mg['agg_status'], 'secondary'];
          $rv_overdue = $mg['agg_status'] !== 'paid' && !empty($mg['due_date']) && $mg['due_date'] < date('Y-m-d');
        ?>

        <?php if ($mg['multi']): /* ── Miesiąc z rozbiciem na kursy ── */ ?>

        <?php /* Wiersz nagłówkowy miesiąca */ ?>
        <tr class="table-light">
          <td class="fw-semibold">
            <?= h($rv_months[(int)$mg['month']] ?? $mg['month']) ?> <?= (int)$mg['year'] ?>
          </td>
          <td class="fw-semibold"><?= number_format($mg['sum_hours'], 2, ',', ' ') ?> h</td>
          <td></td>
          <td class="fw-bold"><?= number_format($mg['sum_due'], 2, ',', ' ') ?> zł</td>
          <td>
            <?php if (!empty($mg['due_date'])): ?>
              <span class="<?= $rv_overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">
                <?= date('d.m.Y', strtotime($mg['due_date'])) ?>
              </span>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $mcol ?>"><?= h($mlbl) ?></span></td>
          <td></td>
          <td>
            <a href="hours_pdf.php?month=<?= (int)$mg['month'] ?>&amp;year=<?= (int)$mg['year'] ?>"
               class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               title="Szczegółowa rozpiska zajęć i godzin za ten miesiąc">
              <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Godziny
            </a>
          </td>
        </tr>

        <?php /* Wiersze per kurs (rozbicie) */ ?>
        <?php foreach ($mg['rows'] as $b):
          $adj = (float)($b['adjustment'] ?? 0);
          $tot = (float)$b['amount'] + $adj;
          $cname = $b['course_name'] !== '' ? $b['course_name'] : 'Zajęcia';
        ?>
        <tr style="font-size:.86rem">
          <td class="ps-3 text-body-secondary">
            <i class="bi bi-arrow-return-right me-1" aria-hidden="true"></i><?= h($cname) ?>
          </td>
          <td class="text-body-secondary"><?= number_format((float)$b['hours_billed'], 2, ',', ' ') ?> h</td>
          <td>
            <?php if ($adj != 0): ?>
              <span class="<?= $adj > 0 ? 'text-danger' : 'text-success' ?>"><?= ($adj>0?'+':'−').number_format(abs($adj),2,',',' ') ?> zł</span>
              <?php if (!empty($b['adjustment_note'])): ?><div class="text-body-secondary" style="font-size:.72rem"><?= h($b['adjustment_note']) ?></div><?php endif; ?>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td class="text-body-secondary"><?= number_format($tot, 2, ',', ' ') ?> zł</td>
          <td></td>
          <td></td>
          <td>
            <?php if (!empty($b['invoice_path'])): ?>
            <a href="invoice_file.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank" rel="noopener" style="font-size:.78rem">
              <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>FVAT
            </a>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td>
            <a href="hours_pdf.php?month=<?= (int)$b['month'] ?>&amp;year=<?= (int)$b['year'] ?>&amp;course_id=<?= (int)$b['course_id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank" rel="noopener" style="font-size:.78rem"
               title="Rozpiska godzin tej grupy za ten miesiąc">
              <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Godziny
            </a>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php else: /* ── Miesiąc bez rozbicia (jeden wpis, course_id=0) ── */
          $b   = $mg['rows'][0];
          $adj = (float)($b['adjustment'] ?? 0);
          $tot = (float)$b['amount'] + $adj;
          [$lbl, $col] = $rv_st[$b['status']] ?? [$b['status'], 'secondary'];
          $rv_overdue2 = $b['status'] !== 'paid' && !empty($b['due_date']) && $b['due_date'] < date('Y-m-d');
        ?>
        <tr>
          <td><?= h($rv_months[(int)$b['month']] ?? $b['month']) ?> <?= (int)$b['year'] ?></td>
          <td><?= number_format((float)$b['hours_billed'], 2, ',', ' ') ?> h</td>
          <td>
            <?php if ($adj != 0): ?>
              <span class="<?= $adj > 0 ? 'text-danger' : 'text-success' ?>"><?= ($adj>0?'+':'−').number_format(abs($adj),2,',',' ') ?> zł</span>
              <?php if (!empty($b['adjustment_note'])): ?><div class="text-body-secondary" style="font-size:.72rem"><?= h($b['adjustment_note']) ?></div><?php endif; ?>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td class="fw-bold"><?= number_format($tot, 2, ',', ' ') ?> zł</td>
          <td>
            <?php if (!empty($b['due_date'])): ?>
              <span class="<?= $rv_overdue2 ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">
                <?= date('d.m.Y', strtotime($b['due_date'])) ?>
              </span>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $col ?>"><?= h($lbl) ?></span></td>
          <td>
            <?php if (!empty($b['invoice_path'])): ?>
            <a href="invoice_file.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
              <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz FVAT
            </a>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td>
            <a href="hours_pdf.php?month=<?= (int)$b['month'] ?>&amp;year=<?= (int)$b['year'] ?><?= (int)($b['course_id'] ?? 0) ? '&amp;course_id='.(int)$b['course_id'] : '' ?>"
               class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               title="Szczegółowa rozpiska zajęć i godzin za ten miesiąc">
              <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Godziny
            </a>
          </td>
        </tr>
        <?php endif; /* multi / single */ ?>

        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($rv_show_lessons)): ?>
<h2 class="h5 fw-bold d-flex align-items-center gap-2 mt-4 mb-3"><i class="bi bi-calendar-check text-primary" aria-hidden="true"></i>Frekwencja</h2>
<?php include __DIR__ . '/_frekwencja_view.php'; ?>
<?php endif; ?>
