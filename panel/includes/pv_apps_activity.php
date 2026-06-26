<?php
/**
 * panel/includes/pv_apps_activity.php — „Ostatnie wnioski i pisma".
 *
 * Markup Bootstrap renderowany serwerowo + filtr po statusie (chipy) wzbogacony
 * waniliowym JS ze wspólnego pv_enhance.php. Gdy JS zawiedzie, pełna lista jest
 * widoczna.
 *
 * Wymaga w zasięgu: $_pv_apps (array: tytul, type_label, type_icon, status,
 *   created_at, odpowiedz), APP_URL, h().
 */
$_pv_apps = $_pv_apps ?? [];
if (!$_pv_apps) return;

$_app_st = [
    'nowy'        => ['Nowy',        'var(--vol-color)', '#EFF6FF'],
    'w_trakcie'   => ['W trakcie',   '#D97706',          '#FEF3E2'],
    'rozpatrzony' => ['Rozpatrzony', '#16A34A',          '#F0FDF4'],
    'odrzucony'   => ['Odrzucony',   '#DC2626',          '#FEF2F2'],
];
$_present = [];
foreach (['nowy','w_trakcie','rozpatrzony','odrzucony'] as $s) {
    foreach ($_pv_apps as $a) { if (($a['status'] ?? '') === $s) { $_present[] = $s; break; } }
}
?>
<section class="vol-activity mb-3" aria-labelledby="pvp-activity-heading" id="pvAppsActivity"<?= count($_present) > 1 ? ' data-pv-filter' : '' ?>>
  <div class="vol-activity-header">
    <h2 id="pvp-activity-heading" class="vol-activity-title">
      <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Ostatnie wnioski i pisma
    </h2>
    <a href="<?= APP_URL ?>/panel/apply.php" class="btn btn-sm py-0 px-2"
       style="background:var(--vol-color);color:#fff;font-size:.75rem;border-radius:5px" aria-label="Złóż nowy wniosek">
      <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowy
    </a>
  </div>
  <?php if (count($_present) > 1): ?>
  <div class="pv-filter-chips" role="group" aria-label="Filtruj po statusie">
    <button type="button" class="pv-hub-chip" data-filter-val="all" aria-pressed="true">Wszystkie</button>
    <?php foreach ($_present as $s): ?>
    <button type="button" class="pv-hub-chip" data-filter-val="<?= h($s) ?>" aria-pressed="false"><?= h($_app_st[$s][0]) ?></button>
    <?php endforeach; ?>
  </div>
  <div data-pv-filter-live data-plural="wniosek|wnioski|wniosków" class="visually-hidden" aria-live="polite"></div>
  <?php endif; ?>
  <?php foreach ($_pv_apps as $app):
    $_c = $_app_st[$app['status']][1] ?? '#9CA3AF';
    $_b = $_app_st[$app['status']][2] ?? '#F3F4F6';
    $_l = $_app_st[$app['status']][0] ?? $app['status'];
  ?>
  <div class="vol-activity-row" data-filter-item data-status="<?= h($app['status']) ?>">
    <div class="vol-activity-icon" style="background:<?= $_b ?>;color:<?= $_c ?>">
      <i class="bi <?= h($app['type_icon'] ?? 'bi-file-text') ?>" aria-hidden="true"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($app['tytul']) ?></div>
      <div style="font-size:.73rem;color:#9CA3AF"><?= h($app['type_label'] ?? '') ?> · <?= h(substr((string)$app['created_at'],0,10)) ?></div>
      <?php if (!empty($app['odpowiedz']) && $app['status'] !== 'nowy'): ?>
      <div style="font-size:.78rem;color:#374151;margin-top:.15rem;font-style:italic"><?= h(mb_substr($app['odpowiedz'],0,80)) ?><?= mb_strlen($app['odpowiedz'])>80?'…':'' ?></div>
      <?php endif; ?>
    </div>
    <span style="display:inline-flex;align-items:center;padding:.15rem .55rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:<?= $_b ?>;color:<?= $_c ?>;white-space:nowrap;flex-shrink:0"><?= h($_l) ?></span>
  </div>
  <?php endforeach; ?>
</section>

<?php if (count($_present) > 1) require_once __DIR__ . '/pv_enhance.php'; ?>
