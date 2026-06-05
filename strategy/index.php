<?php
/**
 * strategy/index.php — Dashboard modułu Strategii Rozwoju NGO.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/strategy.php';

require_login();
$PAGE_TITLE = 'Dashboard — Strategia NGO';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'toggle_sphere' && $_can_edit ?? can_edit()) {
        $sid = (int)($_POST['sphere_id'] ?? 0);
        if ($sid > 0) {
            try {
                $s = db_one("SELECT is_active FROM public_benefit_spheres WHERE id=?", [$sid]);
                if ($s) db_update('public_benefit_spheres', ['is_active' => $s['is_active'] ? 0 : 1], $sid);
                flash_set('success', 'Status sfery zmieniony.');
            } catch (\Throwable $e) { error_log('[strategy] ' . $e->getMessage()); }
        }
    }

    if ($act === 'refresh_all') {
        $objs = db_all("SELECT id FROM strategy_objectives WHERE status='aktywny'");
        foreach ($objs as $o) strategy_add_progress_snapshot((int)$o['id']);
        flash_set('success', 'Snapshoty odświeżone dla ' . count($objs) . ' celów.');
    }

    header('Location: ' . APP_URL . '/strategy/index.php'); exit;
}

// ── Dane ──────────────────────────────────────────────────────────────────────
try {
    $spheres = db_all("SELECT * FROM public_benefit_spheres ORDER BY sort_order, id");
} catch (\Throwable $e) { $spheres = []; }

try {
    $all_objs = db_all("SELECT * FROM v_strategy_dashboard ORDER BY sphere_id, waga DESC, id");
} catch (\Throwable $e) { $all_objs = []; }

$stat_total    = count($all_objs);
$stat_active   = 0;
$stat_green    = $stat_yellow = $stat_red = 0;
$obj_by_sphere = [];

foreach ($all_objs as $o) {
    $obj_by_sphere[(int)($o['sphere_id'] ?? 0)][] = $o;
    if ($o['status'] === 'aktywny') {
        $stat_active++;
        $hs = strategy_health_score($o);
        if ($hs === 'green')  $stat_green++;
        elseif ($hs === 'yellow') $stat_yellow++;
        elseif ($hs === 'red')    $stat_red++;
    }
}

$can_edit = can_edit() || is_admin();

include __DIR__ . '/includes/header_strategy.php';
?>

<!-- Nagłówek strony -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-3">
  <div>
    <h1 style="font-size:1.5rem;font-weight:800;color:#0f172a;margin:0 0 .2rem">
      <i class="bi bi-bullseye me-2" style="color:var(--strat-accent)"></i>Strategia Rozwoju NGO
    </h1>
    <p class="text-muted mb-0" style="font-size:.875rem">
      Cele strategiczne · Sfery pożytku publicznego · Monitorowanie realizacji
    </p>
  </div>
  <?php if ($can_edit): ?>
  <div class="d-flex gap-2 flex-shrink-0">
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="refresh_all">
      <button class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-clockwise me-1"></i>Odśwież postęp
      </button>
    </form>
    <a href="<?= APP_URL ?>/strategy/spheres/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-globe2 me-1"></i>Sfery
    </a>
    <a href="<?= APP_URL ?>/strategy/objectives/view.php?new=1" class="btn btn-primary btn-sm"
       style="background:var(--strat-accent);border-color:var(--strat-accent)">
      <i class="bi bi-plus-lg me-1"></i>Nowy cel
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
  <?php
  $kpis = [
    ['val'=>count(array_filter($spheres,fn($s)=>$s['is_active'])), 'lbl'=>'Aktywne sfery', 'icon'=>'bi-globe2', 'bg'=>'#f5f3ff','color'=>'#7c3aed'],
    ['val'=>$stat_active,  'lbl'=>'Aktywne cele',      'icon'=>'bi-bullseye',            'bg'=>'#eff6ff','color'=>'#2563eb'],
    ['val'=>$stat_green,   'lbl'=>'Na dobrej drodze',  'icon'=>'bi-check-circle-fill',   'bg'=>'#f0fdf4','color'=>'#16a34a'],
    ['val'=>$stat_yellow,  'lbl'=>'Wymaga uwagi',       'icon'=>'bi-exclamation-triangle','bg'=>'#fffbeb','color'=>'#d97706'],
    ['val'=>$stat_red,     'lbl'=>'Zagrożone',          'icon'=>'bi-x-circle-fill',       'bg'=>'#fef2f2','color'=>'#dc2626'],
  ];
  foreach ($kpis as $k): ?>
  <div class="col-6 col-md-4 col-lg">
    <div class="card border-0 shadow-sm text-center py-3 h-100">
      <i class="bi <?= $k['icon'] ?> fs-3 mb-1" style="color:<?= $k['color'] ?>"></i>
      <div style="font-size:1.75rem;font-weight:800;color:<?= $k['color'] ?>"><?= $k['val'] ?></div>
      <div class="text-muted" style="font-size:.75rem"><?= $k['lbl'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Sfery z celami -->
<?php if (!$spheres): ?>
<div class="card shadow-sm border-0 text-center py-5">
  <i class="bi bi-globe2 d-block mb-2" style="font-size:3rem;color:#cbd5e1"></i>
  <div class="fw-bold">Brak sfer pożytku publicznego</div>
  <?php if ($can_edit): ?>
  <div class="mt-2">
    <a href="<?= APP_URL ?>/strategy/spheres/index.php" class="btn btn-primary btn-sm"
       style="background:var(--strat-accent);border-color:var(--strat-accent)">
      <i class="bi bi-plus-lg me-1"></i>Skonfiguruj sfery
    </a>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-4">
  <?php foreach ($spheres as $sp):
      $objs       = $obj_by_sphere[$sp['id']] ?? [];
      $active_objs = array_filter($objs, fn($o) => $o['status'] === 'aktywny');
  ?>
  <div class="col-lg-6">
    <div class="card shadow-sm h-100" style="border-left:4px solid <?= h($sp['kolor']) ?>;opacity:<?= $sp['is_active'] ? 1 : .55 ?>">
      <div class="card-header d-flex align-items-center gap-2 py-2"
           style="background:<?= h($sp['kolor']) ?>12">
        <i class="bi <?= h($sp['ikona']) ?>" style="color:<?= h($sp['kolor']) ?>"></i>
        <span class="badge" style="background:<?= h($sp['kolor']) ?>;font-size:.65rem"><?= h($sp['kod']) ?></span>
        <span class="fw-bold small"><?= h($sp['nazwa']) ?></span>
        <?php if (!$sp['is_active']): ?>
        <span class="badge bg-secondary ms-1" style="font-size:.65rem">nieaktywna</span>
        <?php endif; ?>
        <span class="ms-auto badge bg-secondary" style="font-size:.68rem"><?= count($active_objs) ?> cel<?= count($active_objs) === 1 ? '' : (count($active_objs) < 5 ? 'e' : 'ów') ?></span>
        <?php if ($can_edit): ?>
        <a href="<?= APP_URL ?>/strategy/objectives/view.php?new=1&sphere_id=<?= $sp['id'] ?>"
           class="btn btn-sm btn-outline-primary py-0 px-1 ms-1"><i class="bi bi-plus-lg"></i></a>
        <?php endif; ?>
      </div>

      <?php if (!$objs): ?>
      <div class="card-body text-muted small py-3 text-center">
        Brak celów.
        <?php if ($can_edit): ?>
        <a href="<?= APP_URL ?>/strategy/objectives/view.php?new=1&sphere_id=<?= $sp['id'] ?>">Dodaj →</a>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($objs as $o):
            $score  = strategy_health_score($o);
            $colors = ['green'=>'#16a34a','yellow'=>'#d97706','red'=>'#dc2626'];
            $pct    = min(100, (float)($o['postep_procent'] ?? 0));
            $days   = $o['data_do'] ? (int)round((strtotime($o['data_do']) - time()) / 86400) : null;
        ?>
        <div class="list-group-item px-3 py-2">
          <div class="d-flex align-items-start gap-2">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $colors[$score] ?>;flex-shrink:0;margin-top:5px"></span>
            <div class="flex-grow-1" style="min-width:0">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="<?= APP_URL ?>/strategy/objectives/view.php?id=<?= $o['id'] ?>"
                   class="fw-semibold text-decoration-none text-dark" style="font-size:.86rem">
                  <?= h($o['nazwa']) ?>
                </a>
                <?= strategy_health_badge($score) ?>
                <?php if ($o['status'] !== 'aktywny'): ?>
                <span class="badge bg-secondary" style="font-size:.65rem"><?= h($o['status']) ?></span>
                <?php endif; ?>
              </div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <div class="progress flex-grow-1" style="height:5px">
                  <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $colors[$score] ?>"></div>
                </div>
                <span class="text-muted" style="font-size:.7rem;white-space:nowrap"><?= number_format($pct,1) ?>%</span>
                <?php if ($days !== null && $days <= 30): ?>
                <span class="badge <?= $days < 0 ? 'bg-danger' : ($days <= 14 ? 'bg-warning text-dark' : 'bg-secondary') ?>"
                      style="font-size:.65rem"><?= $days < 0 ? 'po term.' : "{$days}d" ?></span>
                <?php endif; ?>
                <?php if ((int)$o['entity_count'] > 0): ?>
                <span class="text-muted" style="font-size:.68rem">
                  <i class="bi bi-link-45deg"></i><?= (int)$o['entity_count'] ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php // Nieprzypisane
  $unassigned = $obj_by_sphere[0] ?? [];
  if ($unassigned): ?>
  <div class="col-lg-6">
    <div class="card shadow-sm h-100" style="border-left:4px solid #94a3b8">
      <div class="card-header d-flex align-items-center gap-2 py-2 bg-light">
        <i class="bi bi-question-circle text-secondary"></i>
        <span class="fw-bold small text-secondary">Nieprzypisane do sfery</span>
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ($unassigned as $o):
            $score = strategy_health_score($o);
            $colors = ['green'=>'#16a34a','yellow'=>'#d97706','red'=>'#dc2626'];
            $pct = min(100,(float)($o['postep_procent']??0));
        ?>
        <div class="list-group-item px-3 py-2">
          <div class="d-flex align-items-center gap-2">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $colors[$score] ?>;flex-shrink:0"></span>
            <a href="<?= APP_URL ?>/strategy/objectives/view.php?id=<?= $o['id'] ?>"
               class="fw-semibold text-decoration-none text-dark flex-grow-1" style="font-size:.86rem">
              <?= h($o['nazwa']) ?>
            </a>
            <?= strategy_health_badge($score) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_strategy.php'; ?>
