<?php
/**
 * panel/includes/pv_zwroty_history.php — „Historia wniosków o zwrot kosztów".
 *
 * Markup Bootstrap renderowany serwerowo + filtr po statusie (chipy `.pv-hub-chip`)
 * wzbogacony waniliowym JS ze wspólnego pv_enhance.php. Gdy JS zawiedzie, lista
 * jest w pełni widoczna.
 *
 * Wymaga w zasięgu: $_pv_zwroty (znormalizowana tablica, patrz panel/zwroty.php), h().
 */
$_pv_zwroty = $_pv_zwroty ?? [];
if (!$_pv_zwroty) return;

// status => [label, kolor-badge, kolor-ikony, ikona]
$_zwr_st = [
    'oczekuje'     => ['Oczekuje',                 'secondary', 'warning', 'bi-hourglass-split'],
    'weryfikacja'  => ['Weryfikacja merytoryczna', 'info',      'info',    'bi-search'],
    'zatwierdzony' => ['Zatwierdzony',             'success',   'success', 'bi-check-circle'],
    'zatwierdzone' => ['Zatwierdzone',             'success',   'success', 'bi-check-circle'],
    'do_wyplaty'   => ['Do wypłaty',               'primary',   'primary', 'bi-cash'],
    'wyplacono'    => ['Wypłacono',                'dark',      'success', 'bi-cash-stack'],
    'odrzucony'    => ['Odrzucony',                'danger',    'danger',  'bi-x-circle'],
];
$_present = [];
foreach (['oczekuje','weryfikacja','zatwierdzony','zatwierdzone','do_wyplaty','wyplacono','odrzucony'] as $s) {
    foreach ($_pv_zwroty as $w) { if (($w['status'] ?? '') === $s) { $_present[] = $s; break; } }
}
?>
<div class="vol-activity mb-4" id="pvZwrotyHistory"<?= count($_present) > 1 ? ' data-pv-filter' : '' ?>>
  <div class="vol-activity-header">
    <span class="vol-activity-title"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historia wniosków</span>
    <span class="badge bg-secondary"><?= count($_pv_zwroty) ?></span>
  </div>
  <?php if (count($_present) > 1): ?>
  <div class="pv-filter-chips" role="group" aria-label="Filtruj po statusie">
    <button type="button" class="pv-hub-chip" data-filter-val="all" aria-pressed="true">Wszystkie</button>
    <?php foreach ($_present as $s): ?>
    <button type="button" class="pv-hub-chip" data-filter-val="<?= h($s) ?>" aria-pressed="false"><?= h($_zwr_st[$s][0]) ?></button>
    <?php endforeach; ?>
  </div>
  <div data-pv-filter-live data-plural="wniosek|wnioski|wniosków" class="visually-hidden" aria-live="polite"></div>
  <?php endif; ?>
  <?php foreach ($_pv_zwroty as $w):
    $m = $_zwr_st[$w['status']] ?? [$w['status'], 'secondary', 'secondary', 'bi-circle'];
  ?>
  <div class="vol-activity-row" data-filter-item data-status="<?= h($w['status']) ?>">
    <div class="vol-activity-icon bg-<?= $m[2] ?> bg-opacity-15 text-<?= $m[2] ?>">
      <i class="bi <?= $m[3] ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold <?= $w['status'] === 'odrzucony' ? 'text-decoration-line-through' : '' ?>" style="font-size:.85rem"><?= h($w['tytul']) ?></span>
        <span class="badge bg-<?= $m[1] ?>"><?= h($m[0]) ?></span>
      </div>
      <div class="text-muted" style="font-size:.78rem">
        <?= h($w['nr']) ?> · <strong><?= h($w['kwota']) ?> PLN</strong><?= $w['data_wydatku'] ? ' · ' . h($w['data_wydatku']) : '' ?>
      </div>
      <?php if ($w['odrzucenie_powod']): ?>
      <div class="text-danger" style="font-size:.78rem">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= h($w['odrzucenie_powod']) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.77rem"><?= h($w['created']) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (count($_present) > 1) require_once __DIR__ . '/pv_enhance.php'; ?>
