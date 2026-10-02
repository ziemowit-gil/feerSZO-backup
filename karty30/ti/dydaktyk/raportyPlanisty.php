<?php
/**
 * karty30/ti/dydaktyk/raportyPlanisty.php — Raporty operacyjne i menedżerskie (kierownik):
 * planowana frekwencja grup, wykorzystanie sal, prognoza wynagrodzeń trenerów w miesiącu.
 * GET: ym=YYYY-MM, csv=att|rooms|pay (eksport CSV).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerReports.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
ti_sched_migrate();
$ym = sc_ym((string)($_GET['ym'] ?? date('Y-m')));
$att = sc_report_attendance($ym); $rooms = sc_report_rooms($ym); $pay = sc_report_payouts($ym);
$tot = sc_report_totals($pay); $missing = sc_rooms_missing_count($ym);

$csv = (string)($_GET['csv'] ?? '');
if (in_array($csv, ['att', 'rooms', 'pay'], true)) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="raport_' . $csv . '_' . $ym . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    $f = fn(array $r) => fputcsv($o, $r, ';');
    $n = fn($x) => number_format((float)$x, 2, ',', '');
    if ($csv === 'att') { $f(['Grupa', 'Prowadzący', 'Zaplanowane', 'Odbyte', 'Odwołane', 'Zapisani', 'Limit', 'Miejsca zajęte %', 'Frekwencja hist. %', 'Planowane obecności', 'Prognoza obecności']);
        foreach ($att as $r) $f([$r['course'], $r['instructor'], $r['planned'], $r['held'], $r['cancelled'], $r['enrolled'], $r['max'], $r['fill'] ?? '', $r['rate'] ?? '', $r['slots'], $r['expected']]); }
    if ($csv === 'rooms') { $f(['Budynek', 'Sala', 'Pojemność', 'Lekcje', 'Godziny zajęte', 'Godziny dostępne', 'Wykorzystanie %', 'Śr. osób', 'Zapełnienie miejsc %']);
        foreach ($rooms as $r) $f([$r['building'], $r['room'], $r['capacity'], $r['lessons'], $n($r['used_min'] / 60), $n($r['avail_min'] / 60), $n($r['util']), $n($r['avg_people']), $r['seat_fill'] ?? '']); }
    if ($csv === 'pay') { $f(['Trener', 'Forma', 'Lekcje odbyte', 'Lekcje zaplanowane', 'Godziny razem', 'Limit mies. (h)', 'BB odbyte', 'BB zaplanowane', 'BB razem', 'Netto razem']);
        foreach ($pay as $r) $f([$r['name'], $r['form'], $r['held_n'], $r['planned_n'], $n($r['total_min'] / 60), $r['limit_min'] ? $n($r['limit_min'] / 60) : '', $n($r['held_bb']), $n($r['planned_bb']), $n($r['total_bb']), $n($r['total_netto'])]); }
    fclose($o); exit;
}

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
<?php $KIER_CUR = 'raportyPlanisty.php'; $KIER_LABEL = 'Raporty planisty'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Raporty planisty</h1>
  <div class="btn-group btn-group-sm ms-auto"><a class="btn btn-outline-secondary" href="?ym=<?= h($prev) ?>" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left"></i></a>
    <span class="btn btn-outline-secondary disabled text-body fw-semibold"><?= h($ym) ?></span>
    <a class="btn btn-outline-secondary" href="?ym=<?= h($next) ?>" aria-label="Następny miesiąc"><i class="bi bi-chevron-right"></i></a></div>
  <a class="btn btn-outline-secondary btn-sm" href="planista.php">Planista</a>
</div>
<div class="d-flex flex-wrap gap-2 mb-3">
  <div class="border bg-white px-3 py-2"><b><?= (int)$tot['lessons'] ?></b> lekcji z wynagrodzeniem</div>
  <div class="border bg-white px-3 py-2"><b><?= h(number_format($tot['hours'], 1, ',', '')) ?></b> godzin</div>
  <div class="border bg-white px-3 py-2">BB razem <b><?= h($money($tot['total_bb'])) ?></b> (odbyte <?= h($money($tot['held_bb'])) ?>, zaplanowane <?= h($money($tot['planned_bb'])) ?>)</div>
  <div class="border bg-white px-3 py-2">Netto razem <b><?= h($money($tot['total_netto'])) ?></b></div>
  <?php if ($missing): ?><div class="border border-warning bg-white px-3 py-2"><b><?= $missing ?></b> lekcji stacjonarnych bez sali</div><?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-header fw-semibold bg-body-tertiary d-flex"><span><i class="bi bi-cash-stack me-1"></i>Prognoza wynagrodzeń trenerów</span><a class="btn btn-outline-secondary btn-sm py-0 ms-auto" href="?ym=<?= h($ym) ?>&csv=pay">CSV</a></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th scope="col">Trener</th><th scope="col">Forma</th><th scope="col" class="text-end">Odbyte</th><th scope="col" class="text-end">Zaplanowane</th><th scope="col" class="text-end">Godziny</th><th scope="col" class="text-end">BB razem</th><th scope="col" class="text-end">Netto razem</th></tr></thead><tbody>
  <?php foreach ($pay as $r): ?><tr>
    <td><?= h($r['name']) ?></td><td><?= h($r['form']) ?></td><td class="text-end"><?= (int)$r['held_n'] ?></td><td class="text-end"><?= (int)$r['planned_n'] ?></td>
    <td class="text-end"><?= h($hrs($r['total_min'])) ?><?= $r['over_limit'] ? ' <span class="badge text-bg-danger" title="Limit miesięczny ' . h($hrs($r['limit_min'])) . '">ponad limit</span>' : '' ?></td>
    <td class="text-end"><?= h($money($r['total_bb'])) ?></td><td class="text-end"><?= h($money($r['total_netto'])) ?></td></tr>
  <?php endforeach; if (!$pay): ?><tr><td colspan="7" class="p-3 text-body-secondary">Brak lekcji ze stawką w tym miesiącu.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card-footer small text-body-secondary">Stawka za lekcję z ustawień grupy (brutto-brutto); netto wg zasad rozliczeń TI (student/B2B bez ZUS i PIT). Odbyte liczone faktycznie, zaplanowane prognozowane.</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-header fw-semibold bg-body-tertiary d-flex"><span><i class="bi bi-door-open me-1"></i>Wykorzystanie sal</span><a class="btn btn-outline-secondary btn-sm py-0 ms-auto" href="?ym=<?= h($ym) ?>&csv=rooms">CSV</a></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th scope="col">Sala</th><th scope="col" class="text-end">Lekcje</th><th scope="col" class="text-end">Zajęte</th><th scope="col" style="width:30%">Wykorzystanie</th><th scope="col" class="text-end">Śr. osób / miejsc</th></tr></thead><tbody>
  <?php foreach ($rooms as $r): ?><tr>
    <td><?= h(($r['building'] !== '' ? $r['building'] . ' · ' : '') . $r['room']) ?></td><td class="text-end"><?= (int)$r['lessons'] ?></td><td class="text-end"><?= h($hrs($r['used_min'])) ?> / <?= h($hrs($r['avail_min'])) ?></td>
    <td><div class="progress" style="height:1rem" role="progressbar" aria-valuenow="<?= (int)$r['util'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $r['util'] >= 85 ? 'bg-danger' : ($r['util'] >= 50 ? 'bg-success' : 'bg-secondary') ?>" style="width:<?= min(100, (float)$r['util']) ?>%"><?= h(number_format($r['util'], 1, ',', '')) ?>%</div></div></td>
    <td class="text-end"><?= h(number_format($r['avg_people'], 1, ',', '')) ?> / <?= (int)$r['capacity'] ?><?= $r['seat_fill'] !== null ? ' (' . (int)$r['seat_fill'] . '%)' : '' ?></td></tr>
  <?php endforeach; if (!$rooms): ?><tr><td colspan="5" class="p-3 text-body-secondary">Brak aktywnych sal.</td></tr><?php endif; ?></tbody></table></div>
  <div class="card-footer small text-body-secondary">Dostępność sali: dni robocze (bez świąt i dni wolnych), <?= sprintf('%02d:00–%02d:00', SC_OPEN_FROM / 60, SC_OPEN_TO / 60) ?>.</div></div>

<div class="card border-0 shadow-sm"><div class="card-header fw-semibold bg-body-tertiary d-flex"><span><i class="bi bi-people me-1"></i>Planowana frekwencja grup</span><a class="btn btn-outline-secondary btn-sm py-0 ms-auto" href="?ym=<?= h($ym) ?>&csv=att">CSV</a></div>
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
