<?php
/**
 * karty30/ti/dydaktyk/analitykaRaport.php — jeden raport Analityki TI (zarząd / koordynatorzy).
 * GET: r = bez_sprzetu | licencje | obciazenie | rozliczenia, filtry (days / ym / from + to), view = tabela | lista.
 * Eksport CSV / Excel: formularz POST do analitykaEksport.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$me = dyd_require();
if (!dyd_can_analytics()) { header('Location: index.php'); exit; }
$an_key = (string)($_GET['r'] ?? '');
if (!isset(AnalyticsManager::REPORTS[$an_key])) { header('Location: analityka.php'); exit; }
$dyd_name   = (string)($me['name'] ?? '') ?: 'Użytkownik';
$an_filters = AnalyticsManager::filters($an_key, $_GET);
$an_view    = (($_GET['view'] ?? '') === 'lista') ? 'lista' : 'tabela';
$an_token   = dyd_token();
try {
    $an_ds = AnalyticsManager::dataset($an_key, $an_filters);
} catch (\Throwable $e) {
    error_log('analytics: raport ' . $an_key . ' — ' . $e->getMessage());
    $an_ds = ['title' => AnalyticsManager::reportLabel($an_key), 'subtitle' => 'Raport jest chwilowo niedostępny — spróbuj ponownie później.',
              'headers' => [], 'rows' => [], 'num' => [], 'dec' => [], 'sheet' => 'Raport'];
}

$KP_TITLE  = AnalyticsManager::reportLabel($an_key) . ' — Analityka TI — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .an-table th, .an-table td { font-size: .88rem; }
  .an-list li { margin: .55rem 0; line-height: 1.5; }
</style>

<?php $AN_CUR = 'analitykaRaport.php?r=' . $an_key; $AN_LABEL = AnalyticsManager::reportLabel($an_key); include __DIR__ . '/_analityka_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
  <?php include dirname(__DIR__, 3) . '/modules/analytics/views/_report.php'; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
