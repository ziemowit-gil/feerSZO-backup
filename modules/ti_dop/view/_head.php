<?php
/**
 * modules/ti_dop/view/_head.php — nagłówek panelu dydaktyka dla ekranów „Dokumenty rozliczeniowe uczestnika”
 * w szacie Metro UI: płaskie kafle w kolorach stanu, zero zaokrągleń, pivot (zakładki tekstowe), akcent cyan.
 * Wejście: $title, $actor. Względne adresy liczą się od karty30/ti/dydaktyk/wnioskiDop.php.
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
  .ldm { font-family: "Segoe UI", "Open Sans", system-ui, sans-serif; }
  .ldm *, .ldm .card, .ldm .btn, .ldm .form-control, .ldm .form-select, .ldm .badge, .ldm .alert, .ldm .list-group-item, .ldm .nav-link { border-radius: 0 !important; }
  .ldm h1 { font-weight: 300; font-size: 2rem; }
  .ldm h2 { font-weight: 300; font-size: 1.35rem; margin: 1.25rem 0 .6rem; }
  .ldm .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px; margin-bottom: 1rem; }
  .ldm .tile { display: block; color: #fff; padding: .7rem .9rem; min-height: 96px; text-decoration: none; position: relative; }
  .ldm .tile:hover, .ldm .tile:focus { outline: 3px solid #1d1d1d; outline-offset: -3px; color: #fff; }
  .ldm .tile .n { font-size: 2.1rem; font-weight: 300; line-height: 1; }
  .ldm .tile .l { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; position: absolute; bottom: .55rem; left: .9rem; right: .6rem; }
  .ldm .tile.wide { grid-column: span 2; }
  .ldm a.tile, .ldm a.tile:hover, .ldm a.tile:visited, .ldm .tile span, .ldm .tile .n, .ldm .tile .l { color: #fff !important; text-decoration: none !important; }
  .ldm .chip { display: inline-block; color: #fff; padding: .1rem .5rem; font-size: .78rem; font-weight: 600; }
  .ldm .panel { background: #fff; border: 1px solid #d6d6d6; padding: 1rem 1.1rem; margin-bottom: 1rem; }
  .ldm .panel.accent { border-left: 6px solid #1ba1e2; }
  .ldm .panel.warn { border-left: 6px solid #f0a30a; }
  .ldm .btn-metro { background: #1ba1e2; color: #fff; border: 0; font-weight: 600; padding: .45rem 1rem; }
  .ldm .btn-metro:hover { background: #0050ef; color: #fff; }
  .ldm .btn-metro.dark { background: #1d1d1d; } .ldm .btn-metro.red { background: #a20025; } .ldm .btn-metro.green { background: #008a00; } .ldm .btn-metro.orange { background: #fa6800; }
  .ldm table.table td, .ldm table.table th { vertical-align: middle; }
  .ldm .sym { background: #1ba1e2; color: #fff; font-weight: 700; padding: .05rem .4rem; letter-spacing: .03em; }
  .ldm .pivot { display: flex; gap: 1.4rem; border-bottom: 1px solid #d6d6d6; margin-bottom: 1rem; flex-wrap: wrap; }
  .ldm .pivot a { font-size: 1.15rem; font-weight: 300; color: #647687; text-decoration: none; padding: .3rem 0; }
  .ldm .pivot a[aria-current="page"] { color: #1d1d1d; border-bottom: 3px solid #1ba1e2; }
  .ldm .form-control:focus, .ldm .form-select:focus { border-color: #1ba1e2; box-shadow: 0 0 0 .2rem rgba(27,161,226,.25); }
</style>
<?php $KIER_CUR = 'wnioskiDop.php'; $KIER_LABEL = $kierLabel ?? $title;
include $_dyd . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap ldm">
<div class="container-fluid py-3" style="max-width:1240px">
<?= flash_html() ?>
