<?php
/**
 * strategy/reports/index.php — Raporty strategiczne.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/strategy.php';

require_login();
$PAGE_TITLE = 'Raporty strategiczne';

try {
    $objectives = db_all("SELECT * FROM v_strategy_dashboard ORDER BY sphere_id, waga DESC, id");
    $by_sphere  = [];
    $spheres    = db_all("SELECT * FROM public_benefit_spheres WHERE is_active=1 ORDER BY sort_order");
    foreach ($objectives as $o) { $by_sphere[(int)$o['sphere_id']][] = $o; }

    // Ostatnie snapshoty
    $recent_progress = db_all(
        "SELECT p.*, o.nazwa AS obj_nazwa
         FROM strategy_progress p
         JOIN strategy_objectives o ON o.id=p.objective_id
         ORDER BY p.created_at DESC LIMIT 30"
    );
} catch (\Throwable $e) {
    $objectives = $spheres = $by_sphere = $recent_progress = [];
}

$cnt_green  = count(array_filter($objectives, fn($o) => strategy_health_score($o) === 'green'));
$cnt_yellow = count(array_filter($objectives, fn($o) => strategy_health_score($o) === 'yellow'));
$cnt_red    = count(array_filter($objectives, fn($o) => strategy_health_score($o) === 'red'));

include dirname(__DIR__) . '/includes/header_strategy.php';
?>

<h1 style="font-size:1.35rem;font-weight:800;margin:0 0 1.5rem">
  <i class="bi bi-bar-chart-line me-2" style="color:var(--strat-accent)"></i>Raporty strategiczne
</h1>

<!-- Health Score Summary -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['Na dobrej drodze', $cnt_green,  '#16a34a','#f0fdf4','bi-check-circle-fill'],
    ['Wymaga uwagi',     $cnt_yellow, '#d97706','#fffbeb','bi-exclamation-triangle-fill'],
    ['Zagrożone',        $cnt_red,    '#dc2626','#fef2f2','bi-x-circle-fill'],
    ['Wszystkich celów', count($objectives), '#7c3aed','#f5f3ff','bi-bullseye'],
  ] as [$lbl, $val, $color, $bg, $icon]): ?>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm text-center py-3" style="border-top:3px solid <?= $color ?>">
      <i class="bi <?= $icon ?> fs-3 mb-1" style="color:<?= $color ?>"></i>
      <div style="font-size:1.75rem;font-weight:800"><?= $val ?></div>
      <div class="text-muted" style="font-size:.75rem"><?= $lbl ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Per sfera -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-globe2 me-1"></i>Stan celów per sfera</div>
  <table class="table table-hover align-middle mb-0" style="font-size:.86rem">
    <thead class="table-light">
      <tr>
        <th>Sfera</th>
        <th class="text-center">Celów</th>
        <th class="text-center">🟢 Zdrowe</th>
        <th class="text-center">🟡 Uwaga</th>
        <th class="text-center">🔴 Zagrożone</th>
        <th class="text-center">Śr. postęp</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($spheres as $sp):
          $objs = $by_sphere[$sp['id']] ?? [];
          if (!$objs) continue;
          $g = count(array_filter($objs, fn($o)=>strategy_health_score($o)==='green'));
          $y = count(array_filter($objs, fn($o)=>strategy_health_score($o)==='yellow'));
          $r = count(array_filter($objs, fn($o)=>strategy_health_score($o)==='red'));
          $avg = count($objs) > 0 ? array_sum(array_column($objs,'postep_procent'))/count($objs) : 0;
      ?>
      <tr>
        <td>
          <span class="badge" style="background:<?= h($sp['kolor']) ?>"><?= h($sp['kod']) ?></span>
          <span class="ms-1"><?= h($sp['nazwa']) ?></span>
        </td>
        <td class="text-center"><strong><?= count($objs) ?></strong></td>
        <td class="text-center"><span class="badge" style="background:#dcfce7;color:#15803d"><?= $g ?></span></td>
        <td class="text-center"><span class="badge" style="background:#fef3c7;color:#92400e"><?= $y ?></span></td>
        <td class="text-center"><span class="badge" style="background:#fee2e2;color:#991b1b"><?= $r ?></span></td>
        <td class="text-center">
          <div class="d-flex align-items-center gap-1">
            <div class="progress flex-grow-1" style="height:6px">
              <div class="progress-bar"
                   style="width:<?= min(100,$avg) ?>%;background:<?= $r>0?'#dc2626':($y>0?'#d97706':'#16a34a') ?>"></div>
            </div>
            <span style="font-size:.7rem;white-space:nowrap"><?= number_format($avg,1) ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Ostatnie snapshoty -->
<?php if ($recent_progress): ?>
<div class="card shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Ostatnie wpisy postępu</div>
  <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
    <thead class="table-light">
      <tr><th>Data</th><th>Cel</th><th>Realizacja</th><th>Budżet</th><th>Źródło</th></tr>
    </thead>
    <tbody>
      <?php foreach($recent_progress as $p): ?>
      <tr>
        <td class="text-muted"><?= date('d.m.Y H:i', strtotime($p['created_at'])) ?></td>
        <td><?= h(mb_substr($p['obj_nazwa']??'',0,40)) ?></td>
        <td><?= number_format((float)$p['wartosc_realizowana'],2,',',' ') ?></td>
        <td><?= number_format((float)$p['budzet_wydany'],2,',',' ') ?> / <?= number_format((float)$p['budzet_przypisany'],2,',',' ') ?></td>
        <td><span class="badge bg-<?= $p['source']==='n8n'?'info text-dark':($p['source']==='auto'?'secondary':'light text-dark') ?>"><?= h($p['source']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>
