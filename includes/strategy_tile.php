<?php
/**
 * includes/strategy_tile.php — Kafelek Strategii Rozwoju dla dashboardu.
 *
 * Wymaga: strategy.php załadowanego wcześniej.
 * Wyświetla skrócony health-score dla każdej aktywnej sfery.
 */

try {
    $tile_spheres = db_all(
        "SELECT s.id, s.kod, s.nazwa, s.kolor, s.ikona,
                COUNT(o.id)                         AS obj_total,
                SUM(CASE WHEN o.status='aktywny' THEN 1 ELSE 0 END) AS obj_active
         FROM public_benefit_spheres s
         LEFT JOIN strategy_objectives o ON o.sphere_id = s.id
         WHERE s.is_active = 1
         GROUP BY s.id
         ORDER BY s.sort_order, s.kod"
    );

    $tile_objectives = db_all(
        "SELECT o.id, o.nazwa, o.sphere_id, o.data_do, o.wartosc_docelowa, o.waga,
                p.wartosc_realizowana, p.budzet_wydany, p.budzet_przypisany
         FROM strategy_objectives o
         LEFT JOIN (
             SELECT objective_id,
                    wartosc_realizowana, budzet_wydany, budzet_przypisany
             FROM strategy_progress
             WHERE id IN (
                 SELECT MAX(id) FROM strategy_progress GROUP BY objective_id
             )
         ) p ON p.objective_id = o.id
         WHERE o.status = 'aktywny'
         ORDER BY o.waga DESC, o.id"
    );

    // Group objectives by sphere
    $obj_by_sphere = [];
    foreach ($tile_objectives as $o) {
        $obj_by_sphere[$o['sphere_id']][] = $o;
    }

    // Overall health
    $scores = array_column(array_map(fn($o) => strategy_health_score($o), $tile_objectives), null);
    $total_red    = count(array_filter($scores, fn($s) => $s === 'red'));
    $total_yellow = count(array_filter($scores, fn($s) => $s === 'yellow'));
    $total_green  = count(array_filter($scores, fn($s) => $s === 'green'));
    $overall = $total_red > 0 ? 'red' : ($total_yellow > 0 ? 'yellow' : 'green');

    $overall_colors = ['green' => '#16a34a', 'yellow' => '#d97706', 'red' => '#dc2626'];
    $overall_labels = ['green' => 'Na dobrej drodze', 'yellow' => 'Wymaga uwagi', 'red' => 'Zagrożona'];
    $overall_icons  = ['green' => 'bi-check-circle-fill', 'yellow' => 'bi-exclamation-triangle-fill', 'red' => 'bi-x-circle-fill'];

} catch (\Throwable $e) {
    // Strategy tables not yet created — silent fallback
    $tile_spheres = $tile_objectives = [];
    $obj_by_sphere = [];
    $total_red = $total_yellow = $total_green = 0;
    $overall = 'green';
}
?>

<div class="card shadow-sm mb-3 border-0" style="border-left:4px solid <?= $overall_colors[$overall] ?>!important">
  <div class="card-header d-flex align-items-center gap-2 fw-semibold py-2"
       style="background:<?= $overall_colors[$overall] ?>15">
    <i class="bi bi-bullseye" style="color:<?= $overall_colors[$overall] ?>"></i>
    <span>Strategia Rozwoju NGO</span>
    <span class="ms-auto badge" style="background:<?= $overall_colors[$overall] ?>">
      <i class="bi <?= $overall_icons[$overall] ?> me-1"></i><?= $overall_labels[$overall] ?>
    </span>
    <a href="<?= APP_URL ?>/admin/strategy.php" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1">
      Szczegóły →
    </a>
  </div>

  <div class="card-body p-3">
    <?php if (!$tile_spheres && !$tile_objectives): ?>
    <div class="text-muted small text-center py-2">
      <i class="bi bi-info-circle me-1"></i>
      Brak skonfigurowanych celów strategicznych.
      <a href="<?= APP_URL ?>/admin/strategy.php">Skonfiguruj moduł →</a>
    </div>
    <?php else: ?>

    <!-- Stats row -->
    <div class="row g-2 mb-3">
      <div class="col-4 text-center">
        <div class="fw-bold fs-5"><?= count($tile_objectives) ?></div>
        <div class="text-muted" style="font-size:.72rem">Aktywnych celów</div>
      </div>
      <div class="col-4 text-center">
        <div class="fw-bold fs-5" style="color:#16a34a"><?= $total_green ?></div>
        <div class="text-muted" style="font-size:.72rem">Na bieżąco</div>
      </div>
      <div class="col-4 text-center">
        <div class="fw-bold fs-5" style="color:<?= $total_red ? '#dc2626' : ($total_yellow ? '#d97706' : '#6b7280') ?>">
          <?= $total_red + $total_yellow ?>
        </div>
        <div class="text-muted" style="font-size:.72rem">Wymaga uwagi</div>
      </div>
    </div>

    <!-- Sfery z miniaturami celów -->
    <?php foreach ($tile_spheres as $sphere):
        $objs = $obj_by_sphere[$sphere['id']] ?? [];
        if (!$objs) continue;
    ?>
    <div class="mb-2">
      <div class="d-flex align-items-center gap-1 mb-1">
        <span class="badge" style="background:<?= h($sphere['kolor']) ?>;font-size:.68rem">
          <?= h($sphere['kod']) ?>
        </span>
        <span class="small fw-semibold"><?= h($sphere['nazwa']) ?></span>
      </div>
      <?php foreach ($objs as $obj):
          $score  = strategy_health_score($obj);
          $colors = ['green'=>'#16a34a','yellow'=>'#d97706','red'=>'#dc2626'];
          $pct    = $obj['wartosc_docelowa'] > 0
              ? min(100, round($obj['wartosc_realizowana'] / $obj['wartosc_docelowa'] * 100))
              : 0;
          $days   = $obj['data_do'] ? (int)round((strtotime($obj['data_do']) - time()) / 86400) : null;
      ?>
      <div class="d-flex align-items-center gap-2 mb-1 ps-2">
        <span style="width:8px;height:8px;border-radius:50%;background:<?= $colors[$score] ?>;flex-shrink:0"></span>
        <a href="<?= APP_URL ?>/admin/strategy_objective.php?id=<?= $obj['id'] ?>"
           class="text-decoration-none text-dark small" style="min-width:0;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis"
           title="<?= h($obj['nazwa']) ?>">
          <?= h(mb_substr($obj['nazwa'], 0, 40)) ?>
        </a>
        <div class="progress flex-shrink-0" style="width:60px;height:6px">
          <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $colors[$score] ?>"></div>
        </div>
        <span class="text-muted" style="font-size:.68rem;white-space:nowrap"><?= $pct ?>%</span>
        <?php if ($days !== null && $days <= 30): ?>
        <span class="badge <?= $days < 0 ? 'bg-danger' : ($days <= 14 ? 'bg-warning text-dark' : 'bg-secondary') ?>"
              style="font-size:.65rem"><?= $days < 0 ? 'po terminie' : "{$days}d" ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
