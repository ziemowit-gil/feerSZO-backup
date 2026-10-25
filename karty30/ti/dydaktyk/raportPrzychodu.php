<?php
/**
 * karty30/ti/dydaktyk/raportPrzychodu.php — raport przychodu TI od uczestników: naliczone, sugerowane i prognozy wg frekwencji (kierownik).
 *
 * Miesiąc (?m=YYYY-MM, domyślnie bieżący), podział na grupy. Kafle: naliczone dotąd, sugerowane (pełna frekwencja), utracone, prognoza przy bieżącej
 * frekwencji; pasek scenariuszy 100/90/80/70/60/50%; tabela grup. Eksport CSV / Excel (?export=csv|xlsx), druk. Dane finansowe — tylko dyd_is_staff.
 * Logika i definicje: modules/ti_revenue_report/logic/tiRevenueReport.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_revenue_report/logic/tiRevenueReport.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
karty30_migrate();

$m = (string)($_GET['m'] ?? ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = date('Y-m');
$err = ''; $ds = null;
try { $ds = TiRevenueReport::month($m); }
catch (\Throwable $e) { error_log('raportPrzychodu: ' . $e->getMessage()); $err = 'Nie udało się przygotować raportu.'; }

$ex = (string)($_GET['export'] ?? '');
if ($ds && in_array($ex, ['csv', 'xlsx'], true)) {
    $file = 'przychod_ti_' . $m . '.' . $ex;
    if ($ex === 'csv') { AnalyticsExport::csv($ds, $file); exit; }
    AnalyticsExport::xlsx($ds, (string)($me['name'] ?? ''), $file);
    exit;
}

$prevM = date('Y-m', strtotime($m . '-01 -1 month')); $nextM = date('Y-m', strtotime($m . '-01 +1 month'));
$zl = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
$S = $ds['summary'] ?? [];

$KP_TITLE  = 'Przychód TI — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .rg-table th, .rg-table td { white-space: nowrap; } .rg-table .num { text-align: right; font-variant-numeric: tabular-nums; }
  .rg-table tr.rg-total > * { font-weight: 700; border-top: 2px solid #495057; }
  .gh-kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: .6rem; margin: 0 0 1rem; padding: 0; list-style: none; }
  .gh-kpi li { border: 1px solid #adb5bd; border-left: 6px solid #10335c; background: #fff; padding: .55rem .75rem; }
  .gh-kpi .n { display: block; font-size: 1.5rem; font-weight: 800; line-height: 1.1; } .gh-kpi .l { font-size: .8rem; } .gh-kpi .c { font-size: .75rem; color: #495057; }
  html body.ti-metro .rp-kpi li { min-height: 104px; } html body.ti-metro .rp-kpi .n { font-size: 1.7rem; font-weight: 300; }
  .rg-sec { font-size: .74rem; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #3a3a3a; margin: 1rem 0 .4rem; }
  @media print { .rg-actions, .rg-filters, nav, .kp-topbar { display: none !important; } }
</style>
<?php $KIER_CUR = 'raportPrzychodu.php'; $KIER_LABEL = 'Przychód i prognozy'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1280px">
  <div class="d-flex align-items-center mb-2 gap-2 flex-wrap">
    <h1 class="h4 mb-0 fw-bold"><i class="bi bi-cash-coin me-2" style="color:#2e7d32" aria-hidden="true"></i>Przychód TI: naliczone, sugerowane, prognozy</h1>
    <span class="text-body-secondary small"><?= h(TiRevenueReport::monthLabel($m)) ?></span>
  </div>

  <form method="get" class="rg-filters d-flex flex-wrap align-items-end gap-2 mb-3" role="search" aria-label="Miesiąc">
    <div>
      <label class="form-label small mb-0" for="rp-m">Miesiąc</label>
      <div class="input-group input-group-sm">
        <a class="btn btn-outline-secondary" href="?m=<?= h($prevM) ?>" aria-label="Poprzedni miesiąc">‹</a>
        <input type="month" class="form-control" id="rp-m" name="m" value="<?= h($m) ?>" onchange="this.form.submit()">
        <a class="btn btn-outline-secondary" href="?m=<?= h($nextM) ?>" aria-label="Następny miesiąc">›</a>
      </div>
    </div>
    <noscript><button class="btn btn-sm btn-outline-primary">Pokaż</button></noscript>
    <div class="rg-actions ms-auto d-flex gap-1 flex-wrap">
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj</button>
      <a class="btn btn-sm btn-outline-secondary" href="?m=<?= h($m) ?>&amp;export=csv"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>
      <a class="btn btn-sm btn-outline-secondary" href="?m=<?= h($m) ?>&amp;export=xlsx"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel</a>
    </div>
  </form>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div>
<?php elseif (!$ds['rows']): ?><div class="alert alert-secondary">Brak grup z rozliczanymi uczestnikami w tym miesiącu.</div>
<?php else: ?>
  <ul class="gh-kpi rp-kpi" aria-label="Podsumowanie miesiąca">
    <li><span class="n"><?= h($zl($S['accrued'])) ?></span><span class="l">Naliczone dotąd</span><br><span class="c">z rozliczeń uczestników</span></li>
    <li><span class="n"><?= h($zl($S['potential'])) ?></span><span class="l">Sugerowane</span><br><span class="c">przy pełnej frekwencji</span></li>
    <li><span class="n"><?= h($zl($S['lost'])) ?></span><span class="l">Utracone</span><br><span class="c">sugerowane − naliczone</span></li>
    <li><span class="n"><?= h($zl($S['forecast_cur'])) ?></span><span class="l">Prognoza na koniec miesiąca</span><br><span class="c">przy bieżącej frekwencji<?= $S['attendance'] !== null ? ' (' . h(number_format((float)$S['attendance'], 1, ',', '')) . '%)' : '' ?></span></li>
  </ul>

  <h2 class="rg-sec">Prognoza na koniec miesiąca przy różnej frekwencji na lekcjach zaplanowanych</h2>
  <ul class="gh-kpi rp-kpi" aria-label="Scenariusze frekwencji">
    <?php foreach (TiRevenueReport::SCENARIOS as $p): ?>
    <li><span class="n"><?= h($zl($S['forecast'][$p])) ?></span><span class="l">Frekwencja <?= (int)$p ?>%</span><br><span class="c"><?= $S['forecast'][$p] - $S['accrued'] >= 0 ? '+' : '−' ?><?= h($zl(abs($S['forecast'][$p] - $S['accrued']))) ?> do końca miesiąca</span></li>
    <?php endforeach; ?>
  </ul>

  <h2 class="rg-sec">Grupy</h2>
  <div class="table-responsive">
  <table class="table table-sm align-middle rg-table">
    <caption class="visually-hidden"><?= h($ds['title']) ?></caption>
    <thead><tr><?php foreach ($ds['headers'] as $c => $hd): ?><th scope="col" class="<?= in_array($c, $ds['num'], true) ? 'num' : '' ?>"><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($ds['rows'] as $row): $tot = ($row[0] ?? '') === 'Razem'; ?>
    <tr class="<?= $tot ? 'rg-total' : '' ?>">
      <?php foreach ($row as $c => $v): ?>
      <<?= $c === 0 ? 'th scope="row" class="fw-normal"' : 'td class="num"' ?>><?= h(AnalyticsExport::display($v, AnalyticsExport::decimals($ds, (int)$c))) ?></<?= $c === 0 ? 'th' : 'td' ?>>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php foreach ($ds['notes'] as $n): ?><p class="small text-body-secondary mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= h($n) ?></p><?php endforeach; ?>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
