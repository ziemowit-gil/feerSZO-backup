<?php
/**
 * _tab_rozliczenia.php — Rozliczenia kursantów grupy (Kierownik → Rozliczenia grupy).
 * Wymaga: $cur_course (int), $course (array). Tylko dla dyd_is_staff().
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

$roz_balances = [];
foreach ($roz_enrolled as $en) {
    // Model kombinowany — saldo TEJ grupy (nie całego konta kursanta)
    $rb = ti_group_balance((int)$en['id'], (int)$cur_course);
    $rb['payments'] = $rb['applied'];   // środki zaliczone na tę grupę
    $roz_balances[(int)$en['id']] = $rb;
}

$roz_total_charges  = array_sum(array_column($roz_balances, 'charges'));
$roz_total_payments = array_sum(array_column($roz_balances, 'payments'));
$roz_debt_count     = count(array_filter($roz_balances, fn($b) => $b['debt']   > 0.005));
$roz_credit_count   = count(array_filter($roz_balances, fn($b) => $b['credit'] > 0.005));
$roz_balance        = $roz_total_payments - $roz_total_charges;
?>

<section aria-label="Rozliczenia grupy" class="dyd-roz-wrap">
<style>
.dyd-roz-wrap { padding: 1.1rem 0 2.5rem; max-width: 860px; }

/* Baner alertu */
.dyd-p-banner {
  display: flex; align-items: flex-start; gap: .75rem;
  border-radius: 12px; padding: .75rem 1rem;
  margin-bottom: 1.1rem; font-size: .875rem; line-height: 1.45;
}
.dyd-p-banner.warn { background: #fff7ed; color: #7c2d12; border: 1px solid #fed7aa; }
.dyd-p-banner.ok   { background: #f0fdf4; color: #14532d; border: 1px solid #bbf7d0; }
[data-bs-theme="dark"] .dyd-p-banner.warn { background: #3c1a00; color: #fdba74; border-color: #92400e; }
[data-bs-theme="dark"] .dyd-p-banner.ok   { background: #052e16; color: #86efac; border-color: #166534; }

/* Kafelki stat */
.dyd-roz-stats { display: grid; grid-template-columns: repeat(2,1fr); gap: .65rem; margin-bottom: 1.25rem; }
@media (min-width: 640px) { .dyd-roz-stats { grid-template-columns: repeat(4,1fr); } }
.dyd-roz-stat {
  background: var(--bs-body-bg); border: 1px solid var(--bs-border-color);
  border-radius: 12px; padding: .9rem 1rem;
}
.dyd-roz-stat-value {
  font-size: 1.25rem; font-weight: 700; line-height: 1.2; margin-bottom: .2rem;
}
.dyd-roz-stat-label {
  font-size: .72rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .06em; color: var(--bs-secondary-color);
}
.dyd-roz-stat-icon {
  font-size: 1.3rem; margin-bottom: .45rem; opacity: .75;
}

/* Wiersze kursantów */
.dyd-roz-row {
  display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
  padding: .65rem 1rem; border-bottom: 1px solid var(--bs-border-color);
}
.dyd-roz-row:last-child { border-bottom: none; }
.dyd-roz-row:hover { background: rgba(0,0,0,.02); }
[data-bs-theme="dark"] .dyd-roz-row:hover { background: rgba(255,255,255,.04); }

.dyd-roz-name { flex: 1; min-width: 120px; font-weight: 600; font-size: .9rem; }

.dyd-roz-amounts {
  display: flex; gap: 1rem; font-size: .82rem; color: var(--bs-secondary-color);
  font-variant-numeric: tabular-nums;
}
.dyd-roz-amounts .lbl { font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; }
.dyd-roz-amounts .val { font-weight: 600; color: var(--bs-body-color); }

/* Badge salda */
.dyd-bal {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .25em .65em; border-radius: 20px; font-size: .72rem; font-weight: 700;
  white-space: nowrap;
}
.dyd-bal.debt   { background: rgba(239,68,68,.12); color: #dc2626; }
.dyd-bal.credit { background: rgba(34,197,94,.12); color: #16a34a; }
.dyd-bal.zero   { background: rgba(100,116,139,.1); color: var(--bs-secondary-color); }
[data-bs-theme="dark"] .dyd-bal.debt   { background: rgba(239,68,68,.18); color: #f87171; }
[data-bs-theme="dark"] .dyd-bal.credit { background: rgba(34,197,94,.18); color: #4ade80; }

/* Puste */
.dyd-roz-empty {
  text-align: center; padding: 2.5rem 1rem;
  color: var(--bs-secondary-color); font-size: .875rem;
}
</style>

<?php /* ── Alert niedopłat ── */ ?>
<?php if ($roz_debt_count > 0): ?>
<div class="dyd-p-banner warn" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    <strong><?= $roz_debt_count === 1 ? '1 kursant ma' : "$roz_debt_count kursantów ma" ?> niedopłatę</strong>
    w tej grupie — łączna różnica:
    <strong><?= number_format($roz_total_charges - $roz_total_payments, 2, ',', ' ') ?> zł</strong>.
  </div>
</div>
<?php elseif ($roz_enrolled): ?>
<div class="dyd-p-banner ok" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>Wszystkie należności w tej grupie są pokryte.</div>
</div>
<?php endif; ?>

<?php /* ── Kafelki statystyk ── */ ?>
<?php if ($roz_enrolled): ?>
<div class="dyd-roz-stats" role="list">
  <div class="dyd-roz-stat" role="listitem">
    <div class="dyd-roz-stat-icon text-primary"><i class="bi bi-people" aria-hidden="true"></i></div>
    <div class="dyd-roz-stat-value"><?= count($roz_enrolled) ?></div>
    <div class="dyd-roz-stat-label">Kursantów</div>
  </div>
  <div class="dyd-roz-stat" role="listitem">
    <div class="dyd-roz-stat-icon text-secondary"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></div>
    <div class="dyd-roz-stat-value"><?= number_format($roz_total_charges, 2, ',', ' ') ?> zł</div>
    <div class="dyd-roz-stat-label">Należności</div>
  </div>
  <div class="dyd-roz-stat" role="listitem">
    <div class="dyd-roz-stat-icon text-success"><i class="bi bi-cash-coin" aria-hidden="true"></i></div>
    <div class="dyd-roz-stat-value"><?= number_format($roz_total_payments, 2, ',', ' ') ?> zł</div>
    <div class="dyd-roz-stat-label">Wpłaty</div>
  </div>
  <div class="dyd-roz-stat" role="listitem">
    <?php if ($roz_debt_count): ?>
    <div class="dyd-roz-stat-icon" style="color:#dc2626"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
    <div class="dyd-roz-stat-value" style="color:#dc2626"><?= $roz_debt_count ?></div>
    <div class="dyd-roz-stat-label">Z niedopłatą</div>
    <?php elseif ($roz_credit_count): ?>
    <div class="dyd-roz-stat-icon" style="color:#16a34a"><i class="bi bi-piggy-bank" aria-hidden="true"></i></div>
    <div class="dyd-roz-stat-value" style="color:#16a34a"><?= $roz_credit_count ?></div>
    <div class="dyd-roz-stat-label">Z nadpłatą</div>
    <?php else: ?>
    <div class="dyd-roz-stat-icon" style="color:#16a34a"><i class="bi bi-check-circle" aria-hidden="true"></i></div>
    <div class="dyd-roz-stat-value" style="color:#16a34a">0</div>
    <div class="dyd-roz-stat-label">Niedopłat</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php /* ── Karta z listą kursantów ── */ ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-people text-primary" aria-hidden="true"></i>
    <span class="fw-semibold">Kursanci grupy</span>
    <div class="ms-auto d-flex gap-2">
      <a href="billing_pdf.php?course_id=<?= $cur_course ?>"
         class="btn btn-sm btn-outline-secondary py-1"
         title="Pobierz zestawienie PDF">
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
      </a>
      <a href="../billing.php?course_id=<?= $cur_course ?>"
         class="btn btn-sm btn-outline-primary py-1" target="_blank" rel="noopener"
         title="Pełny panel rozliczeń (admin)">
        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Panel rozliczeń
      </a>
    </div>
  </div>

  <?php if (!$roz_enrolled): ?>
  <div class="dyd-roz-empty">
    <i class="bi bi-person-x d-block mb-2 fs-3" aria-hidden="true"></i>
    Brak aktywnych kursantów w tej grupie.
  </div>

  <?php else: ?>
  <div role="list" aria-label="Rozliczenia kursantów">
    <?php foreach ($roz_enrolled as $en):
      $bal = $roz_balances[(int)$en['id']];
    ?>
    <div class="dyd-roz-row" role="listitem">
      <span class="dyd-roz-name"><?= h($en['name']) ?></span>

      <div class="dyd-roz-amounts">
        <div>
          <div class="lbl">Należności</div>
          <div class="val"><?= number_format($bal['charges'], 2, ',', ' ') ?> zł</div>
        </div>
        <div>
          <div class="lbl">Wpłaty</div>
          <div class="val text-success"><?= number_format($bal['payments'], 2, ',', ' ') ?> zł</div>
        </div>
      </div>

      <?php if ($bal['debt'] > 0.005): ?>
      <span class="dyd-bal debt" title="Niedopłata">
        <i class="bi bi-dash-circle" aria-hidden="true"></i>
        <?= number_format($bal['debt'], 2, ',', ' ') ?> zł
      </span>
      <?php elseif ($bal['credit'] > 0.005): ?>
      <span class="dyd-bal credit" title="Nadpłata">
        <i class="bi bi-plus-circle" aria-hidden="true"></i>
        <?= number_format($bal['credit'], 2, ',', ' ') ?> zł
      </span>
      <?php else: ?>
      <span class="dyd-bal zero"><i class="bi bi-check2" aria-hidden="true"></i>Rozliczony</span>
      <?php endif; ?>

      <a href="../student_billing.php?client_id=<?= (int)$en['id'] ?>"
         class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto flex-shrink-0"
         target="_blank" rel="noopener"
         title="Szczegółowe zestawienie płatności kursanta">
        <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
        <span class="d-none d-sm-inline ms-1">Szczegóły</span>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

</section>
