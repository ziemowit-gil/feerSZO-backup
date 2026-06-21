<?php
/**
 * Partial: widok rozliczeń (+ opcjonalnie frekwencji). Bootstrap 5.3 + WCAG.
 * Wymaga: $rv_client_id (int), $rv_show_lessons (bool).
 */
$rv_billing = k30_ti_client_billing((int)$rv_client_id);
$rv_months  = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
               7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$rv_st = ['draft'=>['Robocze','secondary'], 'issued'=>['Wystawione','primary'], 'paid'=>['Opłacone','success']];
$rv_total = 0.0; $rv_paid = 0.0;
foreach ($rv_billing as $b) {
    $t = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
    $rv_total += $t;
    if ($b['status']==='paid') $rv_paid += $t;
}
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
      <div class="fs-4 fw-bold lh-1"><?= number_format(max(0, $rv_total - $rv_paid), 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1">Do zapłaty</div>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <caption class="visually-hidden">Rozliczenia miesięczne</caption>
      <thead>
        <tr>
          <th scope="col">Okres</th>
          <th scope="col">Godziny</th>
          <th scope="col">Korekta</th>
          <th scope="col">Do zapłaty</th>
          <th scope="col">Termin</th>
          <th scope="col">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rv_billing): ?>
        <tr><td colspan="6" class="text-center text-body-secondary py-4">Brak rozliczeń.</td></tr>
        <?php endif; ?>
        <?php foreach ($rv_billing as $b):
          [$lbl, $col] = $rv_st[$b['status']] ?? [$b['status'], 'secondary'];
          $adj = (float)($b['adjustment'] ?? 0);
          $tot = (float)$b['amount'] + $adj;
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
            <?php if (!empty($b['due_date'])):
              $rv_overdue = $b['status'] !== 'paid' && $b['due_date'] < date('Y-m-d'); ?>
              <span class="<?= $rv_overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">
                <?= date('d.m.Y', strtotime($b['due_date'])) ?>
              </span>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $col ?>"><?= h($lbl) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($rv_show_lessons)): ?>
<h2 class="h5 fw-bold d-flex align-items-center gap-2 mt-4 mb-3"><i class="bi bi-calendar-check text-primary" aria-hidden="true"></i>Frekwencja</h2>
<?php include __DIR__ . '/_frekwencja_view.php'; ?>
<?php endif; ?>
