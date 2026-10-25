<?php
/**
 * karty30/ti/dydaktyk/raportGodzin.php — prosty raport TI: liczba godzin w miesiącach (cała instytucja).
 *
 * Rok (?y=): 12 miesięcy + razem — lekcje odbyte, godziny odbyte / zaplanowane / odwołane. Kliknięcie miesiąca (?m=YYYY-MM)
 * pokazuje podział na prowadzących albo grupy (?by=prowadzacy|grupa). Eksport CSV / Excel (?export=csv|xlsx, zakres jak na ekranie).
 * Tylko kierownik (dyd_is_staff); własne godziny prowadzącego — „Moje godziny” (godziny.php). Logika: TiHoursInsights::orgYear/orgMonthBy.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_hours_insights/logic/hoursInsights.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';
require_once dirname(__DIR__, 3) . '/modules/pivot_table/lib/pivotTable.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: godziny.php'); exit; }
karty30_migrate();

$years = TiHoursInsights::orgYears();
$y = (int)($_GET['y'] ?? date('Y')); if (!in_array($y, $years, true)) $y = (int)date('Y');
$m = (string)($_GET['m'] ?? ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = '';
$by = ($_GET['by'] ?? '') === 'grupa' ? 'grupa' : 'prowadzacy';
if ($m !== '' && (int)substr($m, 0, 4) !== $y) $y = (int)substr($m, 0, 4);

$dsYear  = TiHoursInsights::orgYear($y);
$dsMonth = $m !== '' ? TiHoursInsights::orgMonthBy($m, $by) : null;

$ex = (string)($_GET['export'] ?? '');
if (in_array($ex, ['csv', 'xlsx'], true)) {
    $ds   = $dsMonth ?? $dsYear;
    $file = 'godziny_ti_' . ($dsMonth ? $m . '_' . $by : $y) . '.' . $ex;
    if ($ex === 'csv') { AnalyticsExport::csv($ds, $file); exit; }
    AnalyticsExport::xlsx($ds, (string)($me['name'] ?? ''), $file);
    exit;
}

$q = fn(array $extra = []) => 'raportGodzin.php?' . http_build_query(array_filter(array_merge(['y' => $y, 'm' => $m, 'by' => $m !== '' ? $by : ''], $extra), fn($v) => $v !== null && $v !== ''));

$KP_TITLE  = 'Godziny TI w miesiącach — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
$tbl = function (array $ds, ?callable $link = null): void { ?>
<div class="table-responsive">
<table class="table table-sm table-striped align-middle rg-table">
  <caption class="visually-hidden"><?= h($ds['title']) ?></caption>
  <thead><tr><?php foreach ($ds['headers'] as $c => $hd): ?><th scope="col" class="<?= in_array($c, $ds['num'], true) ? 'num' : '' ?>"><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($ds['rows'] as $i => $row): $tot = ($row[0] ?? '') === 'Razem'; ?>
  <tr class="<?= $tot ? 'rg-total' : '' ?>">
    <?php foreach ($row as $c => $v): $num = in_array($c, $ds['num'], true); ?>
    <<?= $c === 0 ? 'th scope="row" class="fw-normal"' : 'td class="' . ($num ? 'num' : '') . '"' ?>>
      <?php if ($c === 0 && $link && !$tot): ?><a href="<?= h($link($i)) ?>"><?= h((string)$v) ?></a>
      <?php else: ?><?= h(AnalyticsExport::display($v, AnalyticsExport::decimals($ds, (int)$c))) ?><?php endif; ?>
    </<?= $c === 0 ? 'th' : 'td' ?>>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php };
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .rg-table th, .rg-table td { white-space: nowrap; } .rg-table .num { text-align: right; font-variant-numeric: tabular-nums; }
  .rg-table tr.rg-total > * { font-weight: 700; border-top: 2px solid #495057; }
  @media print { .rg-actions, .rg-filters, nav, .kp-topbar { display: none !important; } }
</style>
<?php $KIER_CUR = 'raportGodzin.php'; $KIER_LABEL = 'Godziny w miesiącach'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
  <div class="d-flex align-items-center mb-2 gap-2 flex-wrap">
    <h1 class="h4 mb-0 fw-bold"><i class="bi bi-calendar3 me-2" style="color:#0f6fa8" aria-hidden="true"></i>Godziny TI w miesiącach</h1>
    <span class="text-body-secondary small">cała instytucja · rok <?= $y ?></span>
  </div>

  <form method="get" class="rg-filters d-flex flex-wrap align-items-end gap-2 mb-3" role="search" aria-label="Rok">
    <div>
      <label class="form-label small mb-0" for="rg-y">Rok</label>
      <select class="form-select form-select-sm" id="rg-y" name="y" onchange="this.form.submit()">
        <?php foreach ($years as $yy): ?><option value="<?= $yy ?>" <?= $yy === $y ? 'selected' : '' ?>><?= $yy ?></option><?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-sm btn-outline-primary">Pokaż</button></noscript>
    <div class="rg-actions ms-auto d-flex gap-1 flex-wrap">
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($q(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($q(['export' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel</a>
    </div>
  </form>
  <p class="small text-body-secondary">Eksport obejmuje widok, który masz na ekranie<?= $m !== '' ? ' (' . h(TiHoursInsights::monthLabel($m)) . ')' : ' (cały rok)' ?>.</p>

  <h2 class="h5 fw-bold"><?= h($dsYear['title']) ?></h2>
  <?php $tbl($dsYear, fn($i) => $q(['m' => sprintf('%04d-%02d', $y, $i + 1), 'by' => $by]) . '#szczegoly'); ?>
  <?php foreach ($dsYear['notes'] as $n): ?><p class="small text-body-secondary mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= h($n) ?></p><?php endforeach; ?>

  <?php $pv = TiHoursInsights::orgFacts($y);
        pivotTableWidget('pv-godz', $pv['dims'], $pv['measures'], $pv['facts'], ['row' => 'miesiac', 'col' => 'status', 'measure' => 'godz', 'agg' => 'sum', 'title' => 'Tabela przestawna — lekcje roku ' . $y, 'file' => 'godziny-ti-' . $y . '-przestawna']); ?>

  <?php if ($dsMonth): ?>
  <section id="szczegoly" class="mt-4" aria-labelledby="rg-det">
    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
      <h2 class="h5 fw-bold mb-0" id="rg-det"><?= h($dsMonth['title']) ?></h2>
      <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Podział">
        <a class="btn btn-outline-primary <?= $by === 'prowadzacy' ? 'active' : '' ?>" href="<?= h($q(['by' => 'prowadzacy']) . '#szczegoly') ?>"<?= $by === 'prowadzacy' ? ' aria-current="true"' : '' ?>>Prowadzący</a>
        <a class="btn btn-outline-primary <?= $by === 'grupa' ? 'active' : '' ?>" href="<?= h($q(['by' => 'grupa']) . '#szczegoly') ?>"<?= $by === 'grupa' ? ' aria-current="true"' : '' ?>>Grupy</a>
      </div>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($q(['m' => null, 'by' => null])) ?>">Zamknij szczegóły</a>
    </div>
    <?php if (!$dsMonth['rows']): ?><div class="alert alert-secondary">Brak zajęć w tym miesiącu.</div><?php else: $tbl($dsMonth); endif; ?>
  </section>
  <?php else: ?>
  <p class="small text-body-secondary mt-3"><i class="bi bi-hand-index me-1" aria-hidden="true"></i>Kliknij miesiąc w tabeli, aby zobaczyć podział na prowadzących i grupy.</p>
  <?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
