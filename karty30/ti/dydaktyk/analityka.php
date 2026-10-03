<?php
/**
 * karty30/ti/dydaktyk/analityka.php — Analityka TI: wskaźniki (KPI) dla zarządu i koordynatorów.
 *
 * Aktywni kursanci, aktywne licencje, sprzęt w terenie, otwarte wnioski o zwrot kosztów, formy rozliczenia licencji
 * (FAK / FAZ / DSC / GST) i wejścia do raportów (analitykaRaport.php, eksport analitykaEksport.php).
 * Logika: modules/analytics (AnalyticsManager) — tylko odczyt. Dostęp: administrator, zarząd albo uprawnienie „analityka”
 * (dyd_can_analytics()); pozostali użytkownicy panelu wracają na pulpit.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$me = dyd_require();
if (!dyd_can_analytics()) { header('Location: index.php'); exit; }
$dyd_name = (string)($me['name'] ?? '') ?: 'Użytkownik';
$an_kpi   = AnalyticsManager::kpis();

$KP_TITLE  = 'Analityka TI — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .an-tile { height: 100%; border: 1px solid #adb5bd; border-radius: 6px; background: #fff; padding: .85rem 1rem; }
  .an-tile dt { font-size: .85rem; font-weight: 600; color: #334155; }
  .an-tile .an-num { font-size: 2rem; font-weight: 700; color: #10335c; margin: 0; line-height: 1.15; }
  .an-tile .an-sub { font-size: .78rem; color: #475569; margin: .25rem 0 0; }
  .an-table th, .an-table td { font-size: .88rem; }
  .an-card, body.ti-skin a.an-card { display: block; height: 100%; border: 1px solid #adb5bd; border-radius: 6px; padding: .75rem; background: #fff; color: #1b2430; text-decoration: none; }
  .an-card:hover, body.ti-skin a.an-card:hover { border-color: #10335c; box-shadow: 0 3px 10px rgba(16,51,92,.12); text-decoration: none; }
  .an-ico { flex-shrink: 0; width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #1d3f8f; font-size: 1.1rem; }
</style>

<?php $AN_CUR = 'analityka.php'; $AN_LABEL = 'Przegląd wskaźników'; include __DIR__ . '/_analityka_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
  <div class="d-flex align-items-baseline gap-2 flex-wrap mb-1">
    <h1 class="h4 mb-0"><i class="bi bi-speedometer2 text-primary me-2" aria-hidden="true"></i>Analityka TI</h1>
    <span class="text-body-secondary small">stan na <?= h($an_kpi['at']) ?></span>
  </div>
  <p class="text-body-secondary small mb-4" style="max-width:62rem">Wskaźniki liczone na bieżąco z danych modułu Dydaktyka (TI), Licencji, rejestru sprzętu i zwrotów kosztów.
    Ekran jest przeznaczony dla zarządu i koordynatorów — nie zmienia żadnych danych.</p>

  <?php include dirname(__DIR__, 3) . '/modules/analytics/views/_kpi.php'; ?>

  <section class="mt-4" aria-labelledby="an-rep-h">
    <h2 id="an-rep-h" class="h5 mb-2">Raporty</h2>
    <ul class="row g-3 list-unstyled">
      <?php foreach (AnalyticsManager::REPORTS as $_k => $_m): ?>
      <li class="col-md-6 col-xl-3">
        <a href="analitykaRaport.php?r=<?= h($_k) ?>" class="an-card">
          <span class="d-flex align-items-start gap-2">
            <span class="an-ico" aria-hidden="true"><i class="bi bi-<?= h($_m[1]) ?>"></i></span>
            <span class="flex-grow-1" style="min-width:0">
              <span class="fw-semibold d-block"><?= h($_m[0]) ?></span>
              <span class="d-block text-body-secondary mt-1" style="font-size:.8rem;line-height:1.35"><?= h($_m[2]) ?></span>
            </span>
          </span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
