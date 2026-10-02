<?php
/**
 * karty30/ti/dydaktyk/raportyPlanisty.php — Raporty operacyjne i menedżerskie (kierownik):
 * planowana frekwencja grup, wykorzystanie sal, prognoza wynagrodzeń trenerów w miesiącu.
 * GET: ym=YYYY-MM, csv=att|rooms|pay (eksport CSV).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerExport.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
ti_sched_migrate();
$ym = sc_ym((string)($_GET['ym'] ?? date('Y-m')));
$att = sc_report_attendance($ym); $rooms = sc_report_rooms($ym); $pay = sc_report_payouts($ym);
$tot = sc_report_totals($pay); $missing = sc_rooms_missing_count($ym);
$weeksN = max(2, min(26, (int)($_GET['weeks'] ?? 8))); $cyc = sc_report_cycles(); $wl = sc_report_week_load($weeksN);
$exp = function (string $k) use ($ym, $weeksN): string {
    $q = 'raportyPlanistyExport.php?r=' . $k . '&ym=' . urlencode($ym) . '&weeks=' . $weeksN;
    return '<span class="ms-auto btn-group btn-group-sm"><a class="btn btn-outline-secondary py-0" href="' . $q . '&fmt=pdf" target="_blank" rel="noopener">PDF</a><a class="btn btn-outline-secondary py-0" href="' . $q . '&fmt=docx">Word</a><a class="btn btn-outline-secondary py-0" href="' . $q . '&fmt=csv">CSV</a></span>';
};

$money = fn($x) => number_format((float)$x, 2, ',', ' ') . ' zł';
$hrs = fn(int $m) => rtrim(rtrim(number_format($m / 60, 1, ',', ''), '0'), ',') . ' h';
$prev = date('Y-m', strtotime($ym . '-01 -1 month')); $next = date('Y-m', strtotime($ym . '-01 +1 month'));
$KP_TITLE  = 'Raporty planisty — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $PL_CUR = 'raportyPlanisty.php'; $PL_LABEL = 'Raporty planisty'; include __DIR__ . '/_planista_bar.php'; ?>
<style>@media print{.dyd-sb,.dyd-topnav,.skin-sections,.skin-subnav,nav,.btn,.btn-group,form{display:none!important}main.dyd-wrap{margin:0!important}}</style>
<main id="main" class="dyd-wrap">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Raporty planisty</h1>
  <div class="btn-group btn-group-sm ms-auto"><a class="btn btn-outline-secondary" href="?ym=<?= h($prev) ?>" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left"></i></a>
    <span class="btn btn-outline-secondary disabled text-body fw-semibold"><?= h($ym) ?></span>
    <a class="btn btn-outline-secondary" href="?ym=<?= h($next) ?>" aria-label="Następny miesiąc"><i class="bi bi-chevron-right"></i></a></div>
  <a class="btn btn-outline-secondary btn-sm" href="planista.php">Planista</a>
  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Drukuj stronę</button>
</div>
<div class="d-flex flex-wrap gap-2 mb-3">
  <div class="border bg-white px-3 py-2"><b><?= (int)$tot['lessons'] ?></b> lekcji z wynagrodzeniem</div>
  <div class="border bg-white px-3 py-2"><b><?= h(number_format($tot['hours'], 1, ',', '')) ?></b> godzin</div>
  <div class="border bg-white px-3 py-2">BB razem <b><?= h($money($tot['total_bb'])) ?></b> (odbyte <?= h($money($tot['held_bb'])) ?>, zaplanowane <?= h($money($tot['planned_bb'])) ?>)</div>
  <div class="border bg-white px-3 py-2">Netto razem <b><?= h($money($tot['total_netto'])) ?></b></div>
  <?php if ($missing): ?><div class="border border-warning bg-white px-3 py-2"><b><?= $missing ?></b> lekcji stacjonarnych bez sali</div><?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-header fw-semibold bg-body-tertiary d-flex"><span><i class="bi bi-cash-stack me-1"></i>Prognoza wynagrodzeń trenerów</span><?= $exp('pay') ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th scope="col">Trener</th><th scope="col">Forma</th><th scope="col" class="text-end">Odbyte</th><th scope="col" class="text-end">Zaplanowane</th><th scope="col" class="text-end">Godziny</th><th scope="col" class="text-end">BB razem</th><th scope="col" class="text-end">Netto razem</th></tr></thead><tbody>
  <?php foreach ($pay as $r): ?><tr>
    <td><?= h($r['name']) ?></td><td><?= h($r['form']) ?></td><td class="text-end"><?= (int)$r['held_n'] ?></td><td class="text-end"><?= (int)$r['planned_n'] ?></td>
    <td class="text-end"><?= h($hrs($r['total_min'])) ?><?= $r['over_limit'] ? ' <span class="badge text-bg-danger" title="Limit miesięczny ' . h($hrs($r['limit_min'])) . '">ponad limit</span>' : '' ?></td>
    <td class="text-end"><?= h($money($r['total_bb'])) ?></td><td class="text-end"><?= h($money($r['total_netto'])) ?></td></tr>
  <?php endforeach; if (!$pay): ?><tr><td colspan="7" class="p-3 text-body-secondary">Brak lekcji ze stawką w tym miesiącu.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card-footer small text-body-secondary">Stawka za lekcję z ustawień grupy (brutto-brutto); netto wg zasad rozliczeń TI (student/B2B bez ZUS i PIT). Odbyte liczone faktycznie, zaplanowane prognozowane.</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-header fw-semibold bg-body-tertiary d-flex"><span><i class="bi bi-door-open me-1"></i>Wykorzystanie sal</span><?= $exp('rooms') ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th scope="col">Sala</th><th scope="col" class="text-end">Lekcje</th><th scope="col" class="text-end">Zajęte</th><th scope="col" style="width:30%">Wykorzystanie</th><th scope="col" class="text-end">Śr. osób / miejsc</th></tr></thead><tbody>
  <?php foreach ($rooms as $r): ?><tr>
    <td><?= h(($r['building'] !== '' ? $r['building'] . ' · ' : '') . $r['room']) ?></td><td class="text-end"><?= (int)$r['lessons'] ?></td><td class="text-end"><?= h($hrs($r['used_min'])) ?> / <?= h($hrs($r['avail_min'])) ?></td>
    <td><div class="progress" style="height:1rem" role="progressbar" aria-valuenow="<?= (int)$r['util'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $r['util'] >= 85 ? 'bg-danger' : ($r['util'] >= 50 ? 'bg-success' : 'bg-secondary') ?>" style="width:<?= min(100, (float)$r['util']) ?>%"><?= h(number_format($r['util'], 1, ',', '')) ?>%</div></div></td>
    <td class="text-end"><?= h(number_format($r['avg_people'], 1, ',', '')) ?> / <?= (int)$r['capacity'] ?><?= $r['seat_fill'] !== null ? ' (' . (int)$r['seat_fill'] . '%)' : '' ?></td></tr>
  <?php endforeach; if (!$rooms): ?><tr><td colspan="5" class="p-3 text-body-secondary">Brak aktywnych sal.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card-footer small text-body-secondary">Dostępność sali: dni robocze (bez świąt i dni wolnych), <?= sprintf('%02d:00–%02d:00', SC_OPEN_FROM / 60, SC_OPEN_TO / 60) ?>.</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-header fw-semibold bg-body-tertiary d-flex"><span><i class="bi bi-arrow-repeat me-1"></i>Cykle zajęć grup</span><?= $exp('cyc') ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th scope="col">Grupa</th><th scope="col">Prowadzący</th><th scope="col">Wzorzec</th><th scope="col" class="text-end">Lekcji</th><th scope="col" class="text-end">Godz.</th><th scope="col">Od – do</th><th scope="col" class="text-end">Godz./tydz.</th><th scope="col" class="text-end">Co ile dni</th></tr></thead><tbody>
  <?php foreach ($cyc as $r): ?><tr><td><?= h(($r['code'] !== '' ? $r['code'] . ' · ' : '') . $r['course']) ?></td><td><?= h($r['instructor']) ?></td><td class="small"><?= h($r['pattern']) ?></td>
    <td class="text-end"><?= (int)$r['lessons'] ?></td><td class="text-end"><?= h($hrs($r['min'])) ?></td><td><?= h(date('d.m.Y', strtotime($r['first'])) . ' – ' . date('d.m.Y', strtotime($r['last']))) ?></td>
    <td class="text-end"><?= h($hrs($r['per_week_min'])) ?></td><td class="text-end"><?= $r['gap_days'] ? (int)$r['gap_days'] : '—' ?></td></tr>
  <?php endforeach; if (!$cyc): ?><tr><td colspan="8" class="p-3 text-body-secondary">Brak zaplanowanych lekcji od dziś.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card-footer small text-body-secondary">Wzorzec wyliczany z lekcji zaplanowanych od dziś; „co ile dni” to mediana odstępów między terminami.</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-header fw-semibold bg-body-tertiary d-flex align-items-center"><span><i class="bi bi-grid-3x3-gap me-1"></i>Obciążenie trenerów wg tygodni</span>
  <form method="get" class="ms-3 d-flex align-items-center gap-1"><input type="hidden" name="ym" value="<?= h($ym) ?>"><label class="small mb-0" for="wkN">tygodni:</label><select name="weeks" id="wkN" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><?php foreach ([4, 8, 12, 26] as $w): ?><option value="<?= $w ?>" <?= $w === $weeksN ? 'selected' : '' ?>><?= $w ?></option><?php endforeach; ?></select></form><?= $exp('week') ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0 text-center">
  <thead class="table-light"><tr><th scope="col" class="text-start">Trener</th><?php foreach ($wl['weeks'] as $w): ?><th scope="col"><?= h(date('d.m', strtotime($w))) ?></th><?php endforeach; ?><th scope="col">Razem</th></tr></thead><tbody>
  <?php foreach ($wl['rows'] as $r): ?><tr><td class="text-start"><?= h($r['name']) ?></td>
    <?php foreach ($wl['weeks'] as $w): $m = $r['cells'][$w]; $over = $r['week_limit'] && $m > $r['week_limit']; ?><td class="<?= $over ? 'table-danger fw-semibold' : ($m ? '' : 'text-body-secondary') ?>" <?= $over ? 'title="Ponad limit tygodniowy"' : '' ?>><?= $m ? h(rtrim(rtrim(number_format($m / 60, 1, ',', ''), '0'), ',')) : '·' ?></td><?php endforeach; ?>
    <td class="fw-semibold"><?= h($hrs($r['total'])) ?></td></tr>
  <?php endforeach; if (!$wl['rows']): ?><tr><td colspan="<?= count($wl['weeks']) + 2 ?>" class="p-3 text-body-secondary">Brak lekcji w wybranym zakresie.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card-footer small text-body-secondary">Godziny odbyte i zaplanowane w tygodniach od poniedziałku; czerwone pole = ponad limit tygodniowy trenera.</div></div>

<div class="card border-0 shadow-sm"><div class="card-header fw-semibold bg-body-tertiary d-flex"><span><i class="bi bi-people me-1"></i>Planowana frekwencja grup</span><?= $exp('att') ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th scope="col">Grupa</th><th scope="col">Prowadzący</th><th scope="col" class="text-end">Zapl.</th><th scope="col" class="text-end">Odb.</th><th scope="col" class="text-end">Odw.</th><th scope="col" class="text-end">Zapisani / limit</th><th scope="col" class="text-end">Frekw. hist.</th><th scope="col" class="text-end">Prognoza obecności</th></tr></thead><tbody>
  <?php foreach ($att as $r): ?><tr>
    <td><?= h(($r['code'] !== '' ? $r['code'] . ' · ' : '') . $r['course']) ?></td><td><?= h($r['instructor']) ?></td><td class="text-end"><?= $r['planned'] ?></td><td class="text-end"><?= $r['held'] ?></td><td class="text-end"><?= $r['cancelled'] ?></td>
    <td class="text-end"><?= $r['enrolled'] ?><?= $r['max'] ? ' / ' . $r['max'] : '' ?></td><td class="text-end"><?= $r['rate'] === null ? '—' : h(number_format($r['rate'], 1, ',', '')) . '%' ?></td>
    <td class="text-end"><?= $r['expected'] ?> z <?= $r['slots'] ?></td></tr>
  <?php endforeach; if (!$att): ?><tr><td colspan="8" class="p-3 text-body-secondary">Brak lekcji w tym miesiącu.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card-footer small text-body-secondary">Prognoza = zaplanowane lekcje × zapisani × frekwencja z ostatnich 90 dni (przy mniej niż 5 wpisach — bez korekty).</div></div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
