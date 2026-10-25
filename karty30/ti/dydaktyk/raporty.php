<?php
/**
 * karty30/ti/dydaktyk/raporty.php — centrum „Raporty”: wszystkie zestawienia i raporty panelu w jednym miejscu (kafle wg tematów).
 * Prowadzący widzi swoje zestawienia godzin; kierownik dodatkowo raporty instytucji (godziny, przychód i prognozy, odwołania, rozliczenia, analityka).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_bigtiles.php';

$me = dyd_require();
$staff = dyd_is_staff();
karty30_migrate();

$KP_TITLE  = 'Raporty — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php dydBigTilesCss(); ?>
<?php if ($staff): $KIER_CUR = 'raporty.php'; $KIER_LABEL = 'Raporty'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1280px">
  <h1 class="h4 fw-bold mb-1"><i class="bi bi-bar-chart-line me-2" style="color:#0f6fa8" aria-hidden="true"></i>Raporty</h1>
  <p class="text-body-secondary small mb-3">Wszystkie zestawienia w jednym miejscu. Raporty godzin i przychodu mają tabele przestawne i eksport CSV / Excel.</p>
  <?php foreach (dydReportGroups($staff) as $title => $tiles): ?>
  <h2 class="dyd-bt-h"><?= h($title) ?></h2>
  <?php dydBigTiles($tiles, $title); ?>
  <?php endforeach; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
