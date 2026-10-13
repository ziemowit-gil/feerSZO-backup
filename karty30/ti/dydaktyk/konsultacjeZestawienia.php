<?php
/**
 * karty30/ti/dydaktyk/konsultacjeZestawienia.php — wydruki i zestawienia dla konsultacji i szkoleń krótkich (SzK).
 * ?r=<klucz TiConsultReports::REPORTS>&m=RRRR-MM | from&to, ?v=lista — widok bez tabeli (czytniki ekranu).
 * Eksport CSV / Excel: POST do konsultacjeEksport.php. Wydruki: zbiorczy PDF kart z okresu (konsultacjaPdf.php?from&to),
 * lista obecności szkolenia krótkiego do podpisu (konsultacjeEksport.php?lista=<id kursu>).
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consult_reports/logic/consultReports.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$key = isset(TiConsultReports::REPORTS[$_GET['r'] ?? '']) ? (string)$_GET['r'] : 'karty';
$range = TiConsultReports::range($_GET);
$view = ($_GET['v'] ?? '') === 'lista' ? 'lista' : 'tabela';
try { $ds = TiConsultReports::dataset($key, $range, $uid, $staff); }
catch (\Throwable $e) { error_log('konsultacjeZestawienia: ' . $e->getMessage()); $ds = ['title' => TiConsultReports::REPORTS[$key][0], 'subtitle' => 'Zestawienie chwilowo niedostępne.', 'headers' => [], 'rows' => [], 'num' => [], 'dec' => []]; }
$q = fn(array $x = []) => 'konsultacjeZestawienia.php?' . http_build_query(array_filter(array_merge(['r' => $key, 'm' => $range['m'], 'from' => $range['m'] === '' ? $range['from'] : '', 'to' => $range['m'] === '' ? $range['to'] : '', 'v' => $view === 'lista' ? 'lista' : ''], $x), fn($v) => $v !== '' && $v !== null));
$szk = in_array($key, ['szk', 'szk_uczestnicy'], true) ? db_all("SELECT id, name FROM k30_ti_courses WHERE simple_mode > 0 ORDER BY name DESC LIMIT 200") : [];

$RZ_TITLE = 'Wydruki i zestawienia';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1300px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-printer text-primary me-2" aria-hidden="true"></i>Wydruki i zestawienia — konsultacje i szkolenia krótkie</h1>
<?php rz_nav('zestawienia'); ?>
<?= flash_html() ?>
<form method="get" class="row g-2 align-items-end mb-3" aria-label="Okres">
  <input type="hidden" name="r" value="<?= h($key) ?>"><?php if ($view === 'lista'): ?><input type="hidden" name="v" value="lista"><?php endif; ?>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="zs-m">Miesiąc</label><input class="form-control form-control-sm" type="month" id="zs-m" name="m" value="<?= h($range['m']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="zs-f">albo od</label><input class="form-control form-control-sm" type="date" id="zs-f" name="from" value="<?= h($range['m'] === '' ? $range['from'] : '') ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="zs-t">do</label><input class="form-control form-control-sm" type="date" id="zs-t" name="to" value="<?= h($range['m'] === '' ? $range['to'] : '') ?>"></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button></div>
</form>
<ul class="nav nav-pills flex-wrap gap-1 mb-3" aria-label="Zestawienia">
  <?php foreach (TiConsultReports::REPORTS as $k => [$l, $ic]): ?><li class="nav-item"><a class="nav-link py-1 px-2<?= $k === $key ? ' active' : '' ?>" href="<?= h($q(['r' => $k])) ?>"<?= $k === $key ? ' aria-current="page"' : '' ?>><i class="bi bi-<?= h($ic) ?> me-1" aria-hidden="true"></i><?= h($l) ?></a></li><?php endforeach; ?>
</ul>

<section class="card border-0 shadow-sm mb-3" aria-labelledby="zs-print-h"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
  <h2 class="h6 fw-bold mb-0 me-2" id="zs-print-h">Wydruki</h2>
  <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="konsultacjaPdf.php?from=<?= h($range['from']) ?>&amp;to=<?= h($range['to']) ?>"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Wszystkie karty z okresu (PDF)</a>
  <?php if ($szk): ?>
  <form method="get" action="konsultacjeEksport.php" target="_blank" class="d-flex gap-1 align-items-center">
    <label class="small" for="zs-lo">Lista obecności SzK:</label>
    <select class="form-select form-select-sm w-auto" id="zs-lo" name="lista"><?php foreach ($szk as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1" aria-hidden="true"></i>PDF</button>
  </form>
  <?php endif; ?>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj zestawienie</button>
</div></section>

<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h2 class="h5 fw-bold mb-0"><?= h($ds['title']) ?></h2>
  <span class="small text-body-secondary"><?= h(TiConsultReports::REPORTS[$key][2]) ?></span>
  <div class="ms-auto d-flex gap-1 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($q(['v' => $view === 'lista' ? '' : 'lista'])) ?>"><?= $view === 'lista' ? 'Widok tabeli' : 'Widok listy' ?></a>
    <form method="post" action="konsultacjeEksport.php" class="d-inline">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="r" value="<?= h($key) ?>">
      <input type="hidden" name="m" value="<?= h($range['m']) ?>"><input type="hidden" name="from" value="<?= h($range['m'] === '' ? $range['from'] : '') ?>"><input type="hidden" name="to" value="<?= h($range['m'] === '' ? $range['to'] : '') ?>">
      <button class="btn btn-sm btn-outline-secondary" name="fmt" value="csv">CSV</button>
      <button class="btn btn-sm btn-outline-secondary" name="fmt" value="xlsx">Excel</button>
    </form>
  </div>
</div>
<p class="small text-body-secondary"><?= h($ds['subtitle']) ?></p>
<?php if (!$ds['rows']): ?><div class="alert alert-secondary">Brak danych w wybranym okresie.</div>
<?php elseif ($view === 'lista'): ?><ol class="small"><?php foreach (AnalyticsExport::sentences($ds) as $s): ?><li><?= h(preg_replace('/^\d+\.\s*/', '', $s)) ?></li><?php endforeach; ?></ol>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm table-striped align-middle">
  <caption class="visually-hidden"><?= h($ds['title'] . ' — ' . $ds['subtitle']) ?></caption>
  <thead class="table-light"><tr><?php foreach ($ds['headers'] as $c => $hd): ?><th scope="col"<?= in_array($c, $ds['num'], true) ? ' class="text-end"' : '' ?>><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
  <tbody><?php foreach ($ds['rows'] as $row): ?><tr<?= ($row[0] ?? '') === 'Razem' ? ' class="fw-bold"' : '' ?>><?php foreach ($row as $c => $v): ?><td<?= in_array($c, $ds['num'], true) ? ' class="text-end"' : '' ?>><?= h(AnalyticsExport::display($v, AnalyticsExport::decimals($ds, (int)$c))) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
