<?php
/**
 * Partial: uproszczone płatności kursanta (tryb „Kursant uproszczony — terminy + płatności uproszczone”, simple_mode 3).
 * Jeden ekran zamiast Rozliczeń i Portfela: czy coś jest do zapłaty, ile, i jak zapłacić (online albo przelewem).
 * Bez historii, faktur i doładowań — te są w portalu płatności (/platnosci, przycisk „Zapłać online”).
 * Wymaga: $student (sesja kursanta), $account (wiersz k30_ti_student_accounts).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';

$op_client  = (int)$student['client_id'];
$op_free    = !empty($account['no_billing']);
$op_hours   = !$op_free && ti_client_only_hours_only($op_client);
$op_bal     = ($op_free || $op_hours) ? null : ti_client_balance($op_client);
$op_pay     = ($op_free || $op_hours) ? null : k30_ti_client_payment($op_client);
$op_debt    = $op_bal ? round((float)$op_bal['debt'], 2) : 0.0;
$op_credit  = $op_bal ? round((float)$op_bal['credit'], 2) : 0.0;
$op_zl      = fn(float $v) => number_format($v, 2, ',', ' ') . ' zł';
?>
<h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-3"><i class="bi bi-cash-coin text-primary" aria-hidden="true"></i>Opłaty</h2>

<?php if ($op_free || $op_hours): ?>
<div class="alert alert-success d-flex gap-2 align-items-start" role="status">
  <i class="bi bi-check-circle-fill fs-4" aria-hidden="true"></i>
  <div><strong>Nie musisz nic płacić.</strong><br>
    <?= $op_free ? 'Twoje zajęcia nie są rozliczane w panelu.' : 'Twoje zajęcia są rozliczane w godzinach, bez opłat.' ?></div>
</div>
<?php else: ?>

<?php if ($op_debt > 0): ?>
<div class="alert alert-warning d-flex gap-3 align-items-center" role="status">
  <i class="bi bi-exclamation-circle-fill fs-2" aria-hidden="true"></i>
  <div><div class="small">Do zapłaty</div><div class="fs-3 fw-bold"><?= h($op_zl($op_debt)) ?></div></div>
</div>
<?php else: ?>
<div class="alert alert-success d-flex gap-3 align-items-center" role="status">
  <i class="bi bi-check-circle-fill fs-2" aria-hidden="true"></i>
  <div><div class="fs-5 fw-bold">Wszystko opłacone</div>
    <?php if ($op_credit > 0): ?><div class="small">Masz nadpłatę <?= h($op_zl($op_credit)) ?> — wykorzystamy ją na kolejne zajęcia.</div><?php endif; ?></div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card h-100">
      <div class="card-body">
        <h3 class="h6 fw-bold"><i class="bi bi-credit-card me-1 text-primary" aria-hidden="true"></i>Zapłać online</h3>
        <p class="small text-body-secondary">Karta, BLIK lub szybki przelew. Otworzy się portal płatności — bez ponownego logowania.</p>
        <a class="btn btn-primary btn-lg w-100" href="platnosci.php" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Zapłać online<span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card h-100">
      <div class="card-body">
        <h3 class="h6 fw-bold"><i class="bi bi-bank2 me-1 text-primary" aria-hidden="true"></i>Albo zwykły przelew</h3>
        <dl class="mb-2">
          <?php foreach ([['Numer konta', (string)$op_pay['account'], 'op-acc', true], ['Tytuł przelewu', (string)$op_pay['title'], 'op-title', false]] as [$op_l, $op_v, $op_id, $op_mono]):
            if ($op_v === '') continue; ?>
          <dt class="small text-body-secondary fw-normal"><?= h($op_l) ?></dt>
          <dd class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span id="<?= $op_id ?>" class="<?= $op_mono ? 'font-monospace ' : '' ?>fw-semibold text-break"><?= h($op_v) ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-op-copy="<?= $op_id ?>">
              <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj<span class="visually-hidden"> — <?= h($op_l) ?></span></button>
          </dd>
          <?php endforeach; ?>
          <?php if ($op_debt > 0): ?>
          <dt class="small text-body-secondary fw-normal">Kwota</dt>
          <dd class="fw-semibold mb-2"><?= h($op_zl($op_debt)) ?></dd>
          <?php endif; ?>
        </dl>
        <?php if (!empty($op_pay['virtual'])): ?>
        <a class="small" href="rachunek_pdf.php" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz PDF z numerem konta</a>
        <?php endif; ?>
        <p class="small text-body-secondary mb-0 mt-2">Przelew zaksięgujemy w ciągu 1–2 dni roboczych.</p>
        <p class="visually-hidden" id="op-copy-status" aria-live="polite"></p>
      </div>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('[data-op-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = document.getElementById(b.getAttribute('data-op-copy')).textContent.trim();
    var done = function () { document.getElementById('op-copy-status').textContent = 'Skopiowano.'; b.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Skopiowano'; };
    if (navigator.clipboard) navigator.clipboard.writeText(t).then(done); else { var r = document.createRange(); r.selectNodeContents(document.getElementById(b.getAttribute('data-op-copy'))); getSelection().removeAllRanges(); getSelection().addRange(r); }
  });
});
</script>
<?php endif; ?>
