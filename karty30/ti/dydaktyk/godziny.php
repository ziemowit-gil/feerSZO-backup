<?php
/**
 * karty30/ti/dydaktyk/godziny.php — „Moje godziny”: zestawienia godzinowe prowadzącego.
 *
 * Prowadzący widzi własne godziny; kierownik (dyd_is_staff) może wybrać dowolnego prowadzącego.
 * Okres: miesiąc (?m=YYYY-MM) albo zakres (?from&to). Zestawienia: ?r=<klucz> z TiHoursInsights::REPORTS.
 * Widok tabeli + widok listy zdań (czytniki ekranu), eksport CSV/XLSX przez godzinyEksport.php (POST).
 * Logika: modules/ti_hours_insights/logic/hoursInsights.php (tylko odczyt).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_hours_insights/logic/hoursInsights.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();

$key = (string)($_GET['r'] ?? 'podsumowanie');
if (!isset(TiHoursInsights::REPORTS[$key])) $key = 'podsumowanie';
$range = TiHoursInsights::range($_GET);
$view  = ($_GET['v'] ?? '') === 'lista' ? 'lista' : 'tabela';

// Prowadzący: kierownik wybiera, prowadzący tylko siebie
$who = $uid; $instructors = [];
if ($staff) {
    $instructors = k30_ti_instructors();
    $want = (int)($_GET['i'] ?? 0);
    if ($want && in_array($want, array_map(fn($x) => (int)$x['id'], $instructors), true)) $who = $want;
    elseif ($want === 0 && !in_array($uid, array_map(fn($x) => (int)$x['id'], $instructors), true) && $instructors) $who = (int)$instructors[0]['id'];
}
$whoName = $who === $uid ? (string)($me['name'] ?? '') : (string)(db_one("SELECT name FROM users WHERE id=?", [$who])['name'] ?? '');

$err = '';
try { $ds = TiHoursInsights::dataset($key, $who, $range); }
catch (\Throwable $e) { error_log('godziny: ' . $e->getMessage()); $err = 'Nie udało się przygotować zestawienia.'; $ds = ['title' => TiHoursInsights::REPORTS[$key][0], 'subtitle' => '', 'headers' => [], 'rows' => [], 'num' => [], 'dec' => [], 'notes' => []]; }

$q = function (array $extra = []) use ($key, $range, $who, $staff, $view): string {
    $p = ['r' => $key] + ($range['m'] !== '' ? ['m' => $range['m']] : ['from' => $range['from'], 'to' => $range['to']]);
    if ($staff) $p['i'] = $who;
    if ($view === 'lista') $p['v'] = 'lista';
    return 'godziny.php?' . http_build_query(array_merge($p, $extra));
};
$prevM = date('Y-m', strtotime(($range['m'] ?: substr($range['from'], 0, 7)) . '-01 -1 month'));
$nextM = date('Y-m', strtotime(($range['m'] ?: substr($range['from'], 0, 7)) . '-01 +1 month'));

$KP_TITLE  = 'Moje godziny — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .gh-nav { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 1rem; padding: 0; list-style: none; }
  a.gh-chip, body.ti-skin a.gh-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .7rem; border: 1px solid #6c757d; border-radius: 999px; background: #fff; color: #1b2430; text-decoration: none; font-size: .85rem; min-height: 36px; }
  a.gh-chip:hover, body.ti-skin a.gh-chip:hover { background: #f1f4f8; text-decoration: underline; }
  a.gh-chip.is-on, body.ti-skin a.gh-chip.is-on { background: #10335c; border-color: #10335c; color: #fff; font-weight: 700; }
  .gh-table th { white-space: nowrap; } .gh-table td.num, .gh-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
  .gh-table tr.gh-total td { font-weight: 700; border-top: 2px solid #495057; }
  .gh-heat { display: inline-block; min-width: 2.6rem; padding: .1rem .3rem; border-radius: 3px; }
  .gh-kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: .6rem; margin: 0 0 1rem; padding: 0; list-style: none; }
  .gh-kpi li { border: 1px solid #adb5bd; border-left: 6px solid #10335c; border-radius: 4px; background: #fff; padding: .55rem .75rem; }
  .gh-kpi .n { display: block; font-size: 1.5rem; font-weight: 800; line-height: 1.1; } .gh-kpi .l { font-size: .8rem; } .gh-kpi .c { font-size: .75rem; color: #495057; }
  @media print { .gh-nav, .gh-filters, .gh-actions, .dyd-kier-bar, nav, .kp-topbar { display: none !important; } }
</style>

<?php if ($staff): $KIER_CUR = 'godziny.php'; $KIER_LABEL = 'Godziny prowadzących'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1280px">

<div class="d-flex align-items-center mb-2 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-hourglass-split text-primary me-2" aria-hidden="true"></i><?= $staff && $who !== $uid ? 'Godziny prowadzącego' : 'Moje godziny' ?></h1>
  <span class="text-body-secondary small"><?= h($whoName) ?> · <?= h($range['label']) ?></span>
</div>
<?= flash_html() ?>

<form method="get" class="gh-filters row g-2 align-items-end mb-3" role="search" aria-label="Okres i prowadzący">
  <input type="hidden" name="r" value="<?= h($key) ?>">
  <?php if ($view === 'lista'): ?><input type="hidden" name="v" value="lista"><?php endif; ?>
  <?php if ($staff): ?>
  <div class="col-sm-4 col-lg-3">
    <label class="form-label small mb-0" for="gh-i">Prowadzący</label>
    <select class="form-select form-select-sm" id="gh-i" name="i" onchange="this.form.submit()">
      <?php foreach ($instructors as $ins): ?><option value="<?= (int)$ins['id'] ?>" <?= (int)$ins['id'] === $who ? 'selected' : '' ?>><?= h($ins['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="col-sm-3 col-lg-2">
    <label class="form-label small mb-0" for="gh-m">Miesiąc</label>
    <div class="input-group input-group-sm">
      <a class="btn btn-outline-secondary" href="<?= h($q(['m' => $prevM, 'from' => null, 'to' => null])) ?>" aria-label="Poprzedni miesiąc">‹</a>
      <input type="month" class="form-control" id="gh-m" name="m" value="<?= h($range['m']) ?>" onchange="this.form.from.value='';this.form.to.value='';this.form.submit()">
      <a class="btn btn-outline-secondary" href="<?= h($q(['m' => $nextM, 'from' => null, 'to' => null])) ?>" aria-label="Następny miesiąc">›</a>
    </div>
  </div>
  <div class="col-sm-2"><label class="form-label small mb-0" for="gh-from">Albo od</label><input type="date" class="form-control form-control-sm" id="gh-from" name="from" value="<?= h($range['m'] === '' ? $range['from'] : '') ?>"></div>
  <div class="col-sm-2"><label class="form-label small mb-0" for="gh-to">do</label><input type="date" class="form-control form-control-sm" id="gh-to" name="to" value="<?= h($range['m'] === '' ? $range['to'] : '') ?>"></div>
  <div class="col-sm-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button></div>
</form>

<ul class="gh-nav" aria-label="Zestawienia">
  <?php foreach (TiHoursInsights::REPORTS as $k => [$label, $icon]): ?>
  <li><a class="gh-chip <?= $k === $key ? 'is-on' : '' ?>" href="<?= h($q(['r' => $k])) ?>" <?= $k === $key ? 'aria-current="page"' : '' ?>><i class="bi bi-<?= h($icon) ?>" aria-hidden="true"></i><?= h($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
  <h2 class="h5 mb-0 fw-bold"><?= h($ds['title']) ?></h2>
  <span class="small text-body-secondary"><?= h(TiHoursInsights::REPORTS[$key][2]) ?></span>
  <div class="gh-actions ms-auto d-flex gap-1 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($q(['v' => $view === 'lista' ? null : 'lista'])) ?>"><i class="bi bi-<?= $view === 'lista' ? 'table' : 'list-ul' ?> me-1" aria-hidden="true"></i><?= $view === 'lista' ? 'Widok tabeli' : 'Widok listy' ?></a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj</button>
    <form method="post" action="godzinyEksport.php" class="d-inline">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="r" value="<?= h($key) ?>"><input type="hidden" name="i" value="<?= $who ?>">
      <input type="hidden" name="m" value="<?= h($range['m']) ?>"><input type="hidden" name="from" value="<?= h($range['m'] === '' ? $range['from'] : '') ?>"><input type="hidden" name="to" value="<?= h($range['m'] === '' ? $range['to'] : '') ?>">
      <button class="btn btn-sm btn-outline-secondary" name="fmt" value="csv"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</button>
      <button class="btn btn-sm btn-outline-secondary" name="fmt" value="xlsx"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel</button>
    </form>
  </div>
</div>
<p class="small text-body-secondary mb-2"><?= h($ds['subtitle']) ?></p>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div>
<?php elseif (!$ds['rows']): ?><div class="alert alert-secondary">Brak danych dla wybranego okresu.</div>
<?php elseif ($key === 'podsumowanie'): ?>
<ul class="gh-kpi">
  <?php foreach ($ds['rows'] as $row): ?>
  <li><span class="n"><?= h(AnalyticsExport::display($row[1], is_float($row[1]) ? (str_contains($row[0], '(zł)') ? 2 : 1) : 0)) ?></span><span class="l"><?= h($row[0]) ?></span><?php if ($row[2] !== ''): ?><br><span class="c"><?= h($row[2]) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
</ul>
<?php elseif ($view === 'lista'): ?>
<ol class="small">
  <?php foreach (AnalyticsExport::sentences($ds) as $s): ?><li><?= h($s) ?></li><?php endforeach; ?>
</ol>
<?php else: ?>
<?php $max = 0.0; if ($key === 'siatka') foreach ($ds['rows'] as $i => $row) if ($i < count($ds['rows']) - 1) foreach (array_slice($row, 1, 7) as $v) $max = max($max, (float)$v); ?>
<div class="table-responsive">
<table class="table table-sm table-striped align-middle gh-table">
  <caption class="visually-hidden"><?= h($ds['title']) ?> — <?= h($ds['subtitle']) ?></caption>
  <thead><tr><?php foreach ($ds['headers'] as $c => $hd): ?><th scope="col" class="<?= in_array($c, $ds['num'], true) ? 'num' : '' ?>"><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($ds['rows'] as $i => $row): $isTotal = ($row[0] ?? '') === 'Razem'; ?>
  <tr class="<?= $isTotal ? 'gh-total' : '' ?>">
    <?php foreach ($row as $c => $v): $num = in_array($c, $ds['num'], true); ?>
    <td class="<?= $num ? 'num' : '' ?>">
      <?php if ($key === 'siatka' && !$isTotal && $c >= 1 && $c <= 7 && $v !== null && $max > 0): $a = 0.15 + 0.65 * ((float)$v / $max); ?>
        <span class="gh-heat" style="background:rgba(16,51,92,<?= number_format($a, 2, '.', '') ?>);color:<?= $a > 0.5 ? '#fff' : '#1b2430' ?>"><?= h(AnalyticsExport::display($v, 1)) ?></span>
      <?php else: ?><?= h(AnalyticsExport::display($v, AnalyticsExport::decimals($ds, (int)$c))) ?><?php endif; ?>
    </td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php foreach ($ds['notes'] ?? [] as $n): ?><p class="small text-body-secondary mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= h($n) ?></p><?php endforeach; ?>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
