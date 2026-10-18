<?php
/**
 * modules/ti_msues/view/_head.php — nagłówek panelu dydaktyka (skórka ti-skin, stałe menu) dla ekranów Certyfikacji MSUES.
 * Wejście: $title, $actor (['id','name','staff']). Względne adresy (logout.php, ../assets) liczą się od karty30/ti/dydaktyk/msues.php.
 */
$_dyd = dirname(__DIR__, 3) . '/karty30/ti/dydaktyk';
$KP_TITLE  = $title . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $actor['name'], 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname($_dyd) . '/kursant/_layout_head.php';
$_skin_css = dirname($_dyd) . '/assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .msu .card, .msu .btn, .msu .form-control, .msu .form-select, .msu .badge, .msu .alert, .msu .progress, .msu .list-group-item { border-radius: 0 !important; }
  .msu .tile { border: 1px solid #c9d3df; background: #fff; padding: .75rem 1rem; height: 100%; }
  .msu .tile h3 { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #45516b; margin: 0 0 .25rem; }
  .msu .tile .n { font-size: 1.8rem; font-weight: 600; color: #10335c; line-height: 1.1; }
  .msu .ok { color: #1f7a4d; } .msu .no { color: #b02a37; }
  .msu table.table td, .msu table.table th { vertical-align: middle; }
  .msu .launch { border-left: 4px solid #c8a11a; background: #fffbea; }
</style>
<?php $KIER_CUR = 'msues.php'; $KIER_LABEL = $kierLabel ?? $title;
if (empty($actor['staff'])) $KIER_CRUMB = ['Mój panel', 'index.php?tab=pulpit'];
include $_dyd . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap msu">
<div class="container-fluid py-3" style="max-width:1200px">
<?= flash_html() ?>
