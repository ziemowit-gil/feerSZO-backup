<?php
/**
 * panel/includes/pv_term_history.php — „Moje wnioski o rozwiązanie umowy".
 *
 * Markup Bootstrap renderowany serwerowo + filtr po statusie (chipy `.pv-hub-chip`)
 * wzbogacony waniliowym JS ze wspólnego pv_enhance.php. Gdy JS zawiedzie, lista
 * jest w pełni widoczna.
 *
 * Wymaga w zasięgu: $_pv_terms (znormalizowana tablica, patrz panel/terminations.php), h().
 */
$_pv_terms = $_pv_terms ?? [];
if (!$_pv_terms) return;

$_term_st = [
    'oczekuje'      => ['Oczekuje',      'warning', 'bi-clock-history'],
    'zaakceptowany' => ['Zaakceptowany', 'success', 'bi-check-circle-fill'],
    'odrzucony'     => ['Odrzucony',     'danger',  'bi-x-circle-fill'],
];
$_present = [];
foreach (['oczekuje','zaakceptowany','odrzucony'] as $s) {
    foreach ($_pv_terms as $r) { if (($r['status'] ?? '') === $s) { $_present[] = $s; break; } }
}
?>
<div class="vol-detail-card" id="pvTermHistory"<?= count($_present) > 1 ? ' data-pv-filter' : '' ?>>
  <div class="vol-detail-header">
    <i class="bi bi-list-check me-2" aria-hidden="true"></i>Moje wnioski o rozwiązanie
    <span class="badge bg-secondary ms-auto"><?= count($_pv_terms) ?></span>
  </div>
  <?php if (count($_present) > 1): ?>
  <div class="pv-filter-chips" role="group" aria-label="Filtruj po statusie">
    <button type="button" class="pv-hub-chip" data-filter-val="all" aria-pressed="true">Wszystkie</button>
    <?php foreach ($_present as $s): ?>
    <button type="button" class="pv-hub-chip" data-filter-val="<?= h($s) ?>" aria-pressed="false"><?= h($_term_st[$s][0]) ?></button>
    <?php endforeach; ?>
  </div>
  <div data-pv-filter-live data-plural="wniosek|wnioski|wniosków" class="visually-hidden" aria-live="polite"></div>
  <?php endif; ?>
  <?php foreach ($_pv_terms as $r):
    $m = $_term_st[$r['status']] ?? [$r['status'], 'secondary', 'bi-dot'];
  ?>
  <div class="vol-activity-row" data-filter-item data-status="<?= h($r['status']) ?>">
    <div class="vol-activity-icon bg-<?= $m[1] ?> bg-opacity-15 text-<?= $m[1] ?>">
      <i class="bi <?= $m[2] ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold" style="font-size:.85rem"><?= h($r['type_label']) ?> · <?= h($r['nr']) ?></span>
        <span class="badge bg-<?= $m[1] ?>"><?= h($m[0]) ?></span>
      </div>
      <div class="text-muted" style="font-size:.78rem">Powód: <?= h($r['powod']) ?></div>
      <?php if (!empty($r['variant_label'])): ?>
      <div class="text-muted" style="font-size:.78rem">
        Tryb: <?= h($r['variant_label']) ?><?= !empty($r['effective_pl']) ? ' · koniec współpracy: ' . h($r['effective_pl']) : '' ?>
      </div>
      <?php endif; ?>
      <?php if ($r['decision_note']): ?>
      <div class="<?= $r['status'] === 'odrzucony' ? 'text-danger' : 'text-muted' ?>" style="font-size:.78rem">
        <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= h($r['decision_note']) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.77rem"><?= h($r['created_pl']) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (count($_present) > 1) require_once __DIR__ . '/pv_enhance.php'; ?>
