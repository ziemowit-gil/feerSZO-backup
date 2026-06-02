<?php
// Partial: blok informacyjny o dostępności zwrotu kosztów
// Wymaga zmiennej $eligibility (array z validateEligibility())
if (!isset($eligibility)) return;

$el = $eligibility;
?>
<?php if (!$el['eligible']): ?>
<div class="alert alert-danger py-2 mb-0" style="font-size:.83rem">
  <i class="bi bi-slash-circle me-1"></i>
  <strong>Zwrot niemożliwy:</strong> <?= h($el['reason']) ?>
</div>
<?php else: ?>
<div class="alert alert-success py-2 mb-0" style="font-size:.83rem">
  <div class="d-flex flex-wrap gap-3 align-items-center">
    <div><i class="bi bi-check-circle-fill text-success me-1"></i><strong>Zwrot kosztów dozwolony</strong></div>
    <?php if ($el['limit'] !== null): ?>
    <div class="ms-auto text-end">
      <div class="text-muted" style="font-size:.75rem">Limit umowy</div>
      <div class="fw-bold"><?= number_format($el['limit'], 2, ',', ' ') ?> PLN</div>
    </div>
    <div class="text-end">
      <div class="text-muted" style="font-size:.75rem">Już zatwierdzone</div>
      <div class="fw-bold text-danger"><?= number_format($el['zuzyty'], 2, ',', ' ') ?> PLN</div>
    </div>
    <div class="text-end">
      <div class="text-muted" style="font-size:.75rem">Dostępne</div>
      <div class="fw-bold text-success"><?= number_format($el['dostepny'], 2, ',', ' ') ?> PLN</div>
    </div>
    <?php else: ?>
    <div class="text-muted small ms-auto">Brak limitu kwotowego na tej umowie.</div>
    <?php endif; ?>
  </div>
  <?php if ($el['limit'] !== null): ?>
  <div class="mt-2">
    <div class="progress" style="height:6px">
      <?php $pct = $el['limit'] > 0 ? min(100, round($el['zuzyty'] / $el['limit'] * 100)) : 0; ?>
      <div class="progress-bar bg-<?= $pct >= 80 ? 'danger' : ($pct >= 50 ? 'warning' : 'success') ?>"
           style="width:<?= $pct ?>%"></div>
    </div>
    <div class="text-muted mt-1" style="font-size:.72rem"><?= $pct ?>% limitu wykorzystano</div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
