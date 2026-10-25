<?php
/**
 * modules/helpdeskReports/view/index.php — pulpit raportowy. Zmienne: $days, $kpi, $cats, $ops, $trend.
 */
$PAGE_TITLE = 'Helpdesk — Raporty';
include dirname(__DIR__, 3) . '/includes/header.php';
$fmt = fn(?int $m) => $m === null ? '—' : hdTimeFmt($m);
$pct = fn(?int $p) => $p === null ? '—' : $p . '%';
$maxTrend = max(1, ...array_map(fn($t) => max($t['created'], $t['closed']), $trend));
$maxCat = max(1, ...array_map(fn($c) => (int)$c['n'], $cats ?: [['n' => 1]]));
?>
<style>
.hdr-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.75rem;margin-bottom:1rem}
.hdr-kpi{background:#fff;border:1px solid #e1dfdd;border-top:3px solid #0f6cbd;padding:.7rem .9rem}
.hdr-kpi .v{font-size:1.6rem;font-weight:600;line-height:1.2}.hdr-kpi .l{font-size:.78rem;color:#605e5c}
.hdr-card{background:#fff;border:1px solid #e1dfdd;margin-bottom:1rem}
.hdr-card h2{font-size:.9rem;font-weight:600;margin:0;padding:.55rem .9rem;border-bottom:1px solid #edebe9}
.hdr-bar{display:flex;align-items:center;gap:.5rem;padding:.25rem .9rem;font-size:.82rem}
.hdr-bar .t{flex:0 0 38%;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hdr-bar .b{flex:1;background:#edebe9;height:10px}.hdr-bar .b i{display:block;height:10px;background:#0f6cbd}
.hdr-trend{display:flex;align-items:flex-end;gap:2px;height:120px;padding:.6rem .9rem 0}
.hdr-trend div{flex:1;display:flex;align-items:flex-end;gap:1px;height:100%}
.hdr-trend i{flex:1;min-height:1px;display:block}.hdr-trend .c{background:#0f6cbd}.hdr-trend .d{background:#107c10}
.hdr-legend{font-size:.75rem;color:#605e5c;padding:.3rem .9rem .6rem}.hdr-legend b{display:inline-block;width:10px;height:10px;margin:0 .25rem 0 .6rem;vertical-align:-1px}
.hdr-table{width:100%;font-size:.82rem}.hdr-table th{color:#605e5c;font-weight:600;padding:.4rem .9rem;border-bottom:1px solid #edebe9;text-align:left}
.hdr-table td{padding:.4rem .9rem;border-top:1px solid #f3f2f1}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2"></i>Helpdesk — raporty</h4>
  <div class="d-flex gap-2 align-items-center">
    <div class="btn-group btn-group-sm" role="group" aria-label="Okres">
      <?php foreach (HD_REP_PERIODS as $d => $l): ?>
      <a class="btn btn-outline-secondary<?= $d === $days ? ' active' : '' ?>" href="<?= h(route('hdrep.index')) ?>&days=<?= $d ?>"><?= h($l) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('hdrep.csv')) ?>&days=<?= $days ?>"><i class="bi bi-download me-1"></i>CSV</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/helpdesk/index.php"><i class="bi bi-arrow-left me-1"></i>Lista zgłoszeń</a>
  </div>
</div>

<div class="hdr-kpis">
  <div class="hdr-kpi"><div class="v"><?= $kpi['created'] ?></div><div class="l">Nowe zgłoszenia (<?= h(HD_REP_PERIODS[$days]) ?>)</div></div>
  <div class="hdr-kpi"><div class="v"><?= $kpi['closed'] ?></div><div class="l">Rozwiązane / zamknięte</div></div>
  <div class="hdr-kpi"><div class="v"><?= $kpi['open_now'] ?></div><div class="l">Otwarte teraz (w tym <?= $kpi['unassigned_now'] ?> nieprzypisanych)</div></div>
  <div class="hdr-kpi"><div class="v"><?= h($fmt($kpi['avg_response_min'])) ?></div><div class="l">Średni czas pierwszej odpowiedzi</div></div>
  <div class="hdr-kpi"><div class="v"><?= h($fmt($kpi['avg_resolution_min'])) ?></div><div class="l">Średni czas rozwiązania</div></div>
  <div class="hdr-kpi"><div class="v"><?= h($pct($kpi['sla_response_pct'])) ?></div><div class="l">SLA reakcji dotrzymane</div></div>
  <div class="hdr-kpi"><div class="v"><?= h($pct($kpi['sla_resolution_pct'])) ?></div><div class="l">SLA rozwiązania dotrzymane</div></div>
  <div class="hdr-kpi"><div class="v"><?= h(hdTimeFmt($kpi['time_logged_min'])) ?></div><div class="l">Zarejestrowany czas pracy</div></div>
  <div class="hdr-kpi"><div class="v"><?= $kpi['csat']['avg'] !== null ? h(number_format($kpi['csat']['avg'], 2, ',', '')) . '/5' : '—' ?></div>
    <div class="l">Ocena zgłaszających (<?= (int)$kpi['csat']['answered'] ?> z <?= (int)$kpi['csat']['sent'] ?> ankiet<?= $kpi['csat']['good_pct'] !== null ? ', ' . (int)$kpi['csat']['good_pct'] . '% dobrych' : '' ?>)</div></div>
</div>

<div class="hdr-card"><h2>Napływ i zamknięcia<?= $days > 90 ? ' (tygodniami)' : ' (dziennie)' ?></h2>
  <div class="hdr-trend" role="img" aria-label="Wykres napływu i zamknięć zgłoszeń">
    <?php foreach ($trend as $t): ?>
    <div title="<?= h($t['label']) ?>: nowe <?= $t['created'] ?>, zamknięte <?= $t['closed'] ?>">
      <i class="c" style="height:<?= round(100 * $t['created'] / $maxTrend) ?>%"></i><i class="d" style="height:<?= round(100 * $t['closed'] / $maxTrend) ?>%"></i>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="hdr-legend"><b style="background:#0f6cbd"></b>nowe<b style="background:#107c10"></b>rozwiązane/zamknięte · <?= h($trend[0]['label'] ?? '') ?> – <?= h(end($trend)['label'] ?? '') ?></div>
</div>

<div class="row g-3">
  <div class="col-lg-6"><div class="hdr-card"><h2>Zgłoszenia wg kategorii</h2>
    <?php foreach ($cats as $c): ?>
    <div class="hdr-bar"><span class="t" title="<?= h($c['label']) ?>"><?= h($c['label']) ?></span>
      <span class="b"><i style="width:<?= round(100 * $c['n'] / $maxCat) ?>%"></i></span><span><?= (int)$c['n'] ?></span></div>
    <?php endforeach; if (!$cats): ?><p class="text-muted small p-3 mb-0">Brak zgłoszeń w okresie.</p><?php endif; ?>
    <div class="pb-2"></div>
  </div></div>
  <div class="col-lg-6"><div class="hdr-card"><h2>Operatorzy</h2>
    <table class="hdr-table"><thead><tr><th>Operator</th><th>Przyp.</th><th>Zakończ.</th><th>Śr. rozw.</th><th>Czas pracy</th><th>Ocena</th></tr></thead><tbody>
    <?php foreach ($ops as $o): ?>
      <tr><td><?= h($o['name']) ?></td><td><?= (int)$o['assigned'] ?></td><td><?= (int)$o['done'] ?></td><td><?= h($fmt($o['avg_res'])) ?></td>
        <td><?= $o['logged'] ? h(hdTimeFmt((int)$o['logged'])) : '—' ?></td><td><?= $o['csat'] !== null ? h(number_format($o['csat'], 1, ',', '')) : '—' ?></td></tr>
    <?php endforeach; if (!$ops): ?><tr><td colspan="6" class="text-muted">Brak przypisanych zgłoszeń w okresie.</td></tr><?php endif; ?>
    </tbody></table>
  </div></div>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
