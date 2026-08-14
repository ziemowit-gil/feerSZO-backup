<?php
/**
 * _tab_rozliczenia.php — Zestawienie rozliczeń kursantów grupy (dla staff/admin).
 * Wymaga: $cur_course (int), $course (array)
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';
ti_payments_migrate();

$roz_enrolled = db_all(
    "SELECT cl.id, cl.name
     FROM k30_ti_enrollments e
     JOIN k30_clients cl ON cl.id=e.client_id
     WHERE e.course_id=? AND e.status='active'
     ORDER BY cl.name COLLATE NOCASE",
    [$cur_course]
);

// Saldo każdego kursanta (bez ponownego przeliczenia FIFO — wystarczy odczyt)
$roz_balances = [];
foreach ($roz_enrolled as $en) {
    $roz_balances[(int)$en['id']] = ti_client_balance((int)$en['id']);
}

// Łączne statystyki
$roz_total_charges  = array_sum(array_column($roz_balances, 'charges'));
$roz_total_payments = array_sum(array_column($roz_balances, 'payments'));
$roz_debt_count     = count(array_filter($roz_balances, fn($b) => $b['debt'] > 0.005));
$roz_credit_count   = count(array_filter($roz_balances, fn($b) => $b['credit'] > 0.005));
?>

<div class="px-3 py-3" style="max-width:860px">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-receipt text-primary me-2" aria-hidden="true"></i>Rozliczenia grupy</h2>
    <div class="ms-auto d-flex gap-2">
      <a href="billing_pdf.php?course_id=<?= $cur_course ?>" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
      </a>
      <a href="../billing.php?course_id=<?= $cur_course ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Panel rozliczeń (admin)
      </a>
    </div>
  </div>

  <?php if (!$roz_enrolled): ?>
  <div class="alert alert-info">Brak zapisanych kursantów w tej grupie.</div>
  <?php else: ?>

  <!-- Podsumowanie -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-sm-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
        <div class="fw-bold"><?= count($roz_enrolled) ?></div>
        <div class="text-body-secondary" style="font-size:.78rem">Kursantów</div>
      </div></div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
        <div class="fw-bold"><?= number_format($roz_total_charges, 2, ',', ' ') ?> zł</div>
        <div class="text-body-secondary" style="font-size:.78rem">Należności łącznie</div>
      </div></div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
        <div class="fw-bold text-success"><?= number_format($roz_total_payments, 2, ',', ' ') ?> zł</div>
        <div class="text-body-secondary" style="font-size:.78rem">Wpłaty łącznie</div>
      </div></div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
        <?php if ($roz_debt_count): ?>
        <div class="fw-bold text-danger"><?= $roz_debt_count ?></div>
        <div class="text-body-secondary" style="font-size:.78rem">Z niedopłatą</div>
        <?php else: ?>
        <div class="fw-bold text-success">0</div>
        <div class="text-body-secondary" style="font-size:.78rem">Niedopłat</div>
        <?php endif; ?>
      </div></div>
    </div>
  </div>

  <!-- Lista kursantów -->
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Rozliczenia kursantów grupy</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Kursant</th>
            <th scope="col">Należności</th>
            <th scope="col">Wpłaty</th>
            <th scope="col">Saldo</th>
            <th scope="col" class="text-end">Zestawienie</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($roz_enrolled as $en):
            $bal = $roz_balances[(int)$en['id']];
          ?>
          <tr>
            <td class="fw-semibold"><?= h($en['name']) ?></td>
            <td><?= number_format($bal['charges'], 2, ',', ' ') ?> zł</td>
            <td class="text-success"><?= number_format($bal['payments'], 2, ',', ' ') ?> zł</td>
            <td>
              <?php if ($bal['debt'] > 0.005): ?>
                <span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>−<?= number_format($bal['debt'], 2, ',', ' ') ?> zł</span>
              <?php elseif ($bal['credit'] > 0.005): ?>
                <span class="badge text-bg-success"><i class="bi bi-piggy-bank me-1" aria-hidden="true"></i>+<?= number_format($bal['credit'], 2, ',', ' ') ?> zł</span>
              <?php else: ?>
                <span class="text-body-secondary">0,00 zł</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="../student_billing.php?client_id=<?= (int)$en['id'] ?>"
                 class="btn btn-sm btn-outline-primary py-0 px-2" target="_blank" rel="noopener"
                 title="Zestawienie płatności kursanta">
                <i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>Szczegóły
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
