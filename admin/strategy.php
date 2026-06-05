<?php
/**
 * admin/strategy.php — Dashboard Strategii Rozwoju NGO
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/strategy.php';

require_role('admin', 'editor');
strategy_migrate();

$PAGE_TITLE = 'Strategia Rozwoju NGO';

// ── POST: szybki reorder / toggle statusu sfery ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'toggle_sphere') {
        $sid = (int)($_POST['sphere_id'] ?? 0);
        if ($sid > 0) {
            try {
                $s = db_one("SELECT is_active FROM public_benefit_spheres WHERE id = ?", [$sid]);
                if ($s) {
                    db_update('public_benefit_spheres', ['is_active' => $s['is_active'] ? 0 : 1], $sid);
                    flash_set('success', 'Status sfery został zmieniony.');
                }
            } catch (\Throwable $e) {
                error_log('[strategy dashboard] toggle_sphere: ' . $e->getMessage());
                flash_set('error', 'Błąd przy zmianie statusu sfery.');
            }
        }
    }

    if ($action === 'toggle_objective') {
        $oid = (int)($_POST['objective_id'] ?? 0);
        if ($oid > 0) {
            try {
                $o = db_one("SELECT status FROM strategy_objectives WHERE id = ?", [$oid]);
                if ($o) {
                    $new_status = $o['status'] === 'aktywny' ? 'wstrzymany' : 'aktywny';
                    db_update('strategy_objectives', ['status' => $new_status], $oid);
                    flash_set('success', 'Status celu został zmieniony.');
                }
            } catch (\Throwable $e) {
                error_log('[strategy dashboard] toggle_objective: ' . $e->getMessage());
                flash_set('error', 'Błąd przy zmianie statusu celu.');
            }
        }
    }

    if ($action === 'reorder_sphere') {
        $ids   = array_map('intval', (array)($_POST['ids'] ?? []));
        $order = 10;
        foreach ($ids as $sid) {
            if ($sid > 0) {
                try {
                    db_update('public_benefit_spheres', ['sort_order' => $order], $sid);
                    $order += 10;
                } catch (\Throwable $e) {}
            }
        }
        flash_set('success', 'Kolejność sfer została zapisana.');
    }

    header('Location: ' . APP_URL . '/admin/strategy.php');
    exit;
}

// ── Dane dashboardu ───────────────────────────────────────────────────────────

$spheres = [];
try {
    $spheres = db_all(
        "SELECT * FROM public_benefit_spheres ORDER BY sort_order, id"
    );
} catch (\Throwable $e) {
    error_log('[strategy dashboard] spheres: ' . $e->getMessage());
}

// Pobierz wszystkie cele z widoku
$all_objectives = [];
try {
    $all_objectives = db_all("SELECT * FROM v_strategy_dashboard ORDER BY sphere_id, waga DESC, id");
} catch (\Throwable $e) {
    error_log('[strategy dashboard] objectives: ' . $e->getMessage());
}

// Statystyki
$stat_spheres_total   = count($spheres);
$stat_spheres_active  = count(array_filter($spheres, fn($s) => $s['is_active']));
$stat_objectives_total  = count($all_objectives);
$stat_objectives_active = 0;
$stat_objectives_at_risk = 0;

foreach ($all_objectives as $obj) {
    if ($obj['status'] === 'aktywny') {
        $stat_objectives_active++;
        if (strategy_health_score($obj) === 'red') {
            $stat_objectives_at_risk++;
        }
    }
}

// Indeks celów według sfery
$objectives_by_sphere = [];
foreach ($all_objectives as $obj) {
    $objectives_by_sphere[(int)($obj['sphere_id'] ?? 0)][] = $obj;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.strategy-page { max-width: 1100px; padding: 2rem 1.5rem; }
.strategy-title { font-size: 1.55rem; font-weight: 800; color: #0f172a; margin-bottom: .2rem; display:flex;align-items:center;gap:.55rem; }
.strategy-sub   { font-size: .875rem; color: #64748b; margin-bottom: 1.75rem; }

/* Stats */
.str-stats {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: .75rem;
    margin-bottom: 1.75rem;
}
.str-stat {
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:.9rem 1.1rem;
    display:flex;
    align-items:center;
    gap:.75rem;
}
.str-stat-icon {
    width:38px;height:38px;border-radius:9px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.15rem;flex-shrink:0;
}
.str-stat-val  { font-size:1.45rem;font-weight:800;color:#0f172a;line-height:1; }
.str-stat-lbl  { font-size:.73rem;color:#64748b;margin-top:.1rem; }

/* Sphere card */
.sphere-card {
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:14px;
    overflow:hidden;
    margin-bottom:1.25rem;
}
.sphere-header {
    display:flex;align-items:center;gap:.75rem;
    padding:.85rem 1.2rem;
    border-bottom:1px solid #f1f5f9;
    background:#f8fafc;
}
.sphere-color-dot {
    width:14px;height:14px;border-radius:50%;flex-shrink:0;
}
.sphere-icon {
    width:32px;height:32px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    font-size:1rem;flex-shrink:0;color:#fff;
}
.sphere-code { font-size:.7rem;color:#94a3b8;font-weight:700;letter-spacing:.06em;text-transform:uppercase; }
.sphere-name { font-weight:700;font-size:.95rem;color:#0f172a; }
.sphere-actions { margin-left:auto;display:flex;gap:.4rem; }

/* Objective row */
.obj-row {
    padding:.75rem 1.2rem;
    border-bottom:1px solid #f1f5f9;
    display:flex;align-items:flex-start;gap:.85rem;
}
.obj-row:last-child { border-bottom:none; }
.obj-main { flex:1;min-width:0; }
.obj-name { font-weight:600;font-size:.88rem;color:#1e293b;text-decoration:none; }
.obj-name:hover { color:#2563eb; }
.obj-meta { font-size:.76rem;color:#94a3b8;margin-top:.15rem; }
.obj-progress { width:160px;flex-shrink:0; }
.obj-dates  { width:130px;flex-shrink:0;font-size:.75rem;color:#64748b;text-align:right; }

/* Empty state */
.sphere-empty { padding:1.2rem 1.4rem;color:#94a3b8;font-size:.85rem;font-style:italic; }

/* Inactive sphere */
.sphere-card.inactive { opacity:.55; }
</style>

<div id="content" class="strategy-page">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= h(APP_URL) ?>/admin/index.php">Admin</a></li>
      <li class="breadcrumb-item active">Strategia</li>
    </ol>
  </nav>

  <!-- Header -->
  <div class="d-flex align-items-start justify-content-between mb-1 gap-3">
    <div>
      <div class="strategy-title">
        <i class="bi bi-bullseye" style="color:#2563eb"></i>
        Strategia Rozwoju NGO
      </div>
      <p class="strategy-sub">Zarządzanie celami strategicznymi, sferami pożytku publicznego i mapowaniem encji.</p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0 mt-1">
      <a href="<?= h(APP_URL) ?>/admin/strategy_sphere.php?new=1"
         class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Nowa sfera
      </a>
      <a href="<?= h(APP_URL) ?>/admin/strategy_objective.php?new=1"
         class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Nowy cel
      </a>
    </div>
  </div>

  <?= flash_html() ?>

  <!-- Statystyki -->
  <div class="str-stats">
    <div class="str-stat">
      <div class="str-stat-icon" style="background:#eff6ff;color:#2563eb">
        <i class="bi bi-globe2"></i>
      </div>
      <div>
        <div class="str-stat-val"><?= $stat_spheres_active ?></div>
        <div class="str-stat-lbl">Aktywne sfery</div>
      </div>
    </div>
    <div class="str-stat">
      <div class="str-stat-icon" style="background:#f0fdf4;color:#16a34a">
        <i class="bi bi-bullseye"></i>
      </div>
      <div>
        <div class="str-stat-val"><?= $stat_objectives_total ?></div>
        <div class="str-stat-lbl">Wszystkie cele</div>
      </div>
    </div>
    <div class="str-stat">
      <div class="str-stat-icon" style="background:#f0fdf4;color:#16a34a">
        <i class="bi bi-check2-circle"></i>
      </div>
      <div>
        <div class="str-stat-val"><?= $stat_objectives_active ?></div>
        <div class="str-stat-lbl">Aktywne cele</div>
      </div>
    </div>
    <div class="str-stat">
      <div class="str-stat-icon" style="background:#fef2f2;color:#dc2626">
        <i class="bi bi-exclamation-triangle"></i>
      </div>
      <div>
        <div class="str-stat-val"><?= $stat_objectives_at_risk ?></div>
        <div class="str-stat-lbl">Zagrożone cele</div>
      </div>
    </div>
  </div>

  <!-- Sfery -->
  <?php if (empty($spheres)): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      Brak sfer pożytku publicznego. <a href="<?= h(APP_URL) ?>/admin/strategy_sphere.php?new=1">Dodaj pierwszą sferę</a>.
    </div>
  <?php else: ?>
    <?php foreach ($spheres as $sphere): ?>
      <?php
        $sphere_id  = (int)$sphere['id'];
        $objs       = $objectives_by_sphere[$sphere_id] ?? [];
        $is_active  = (bool)$sphere['is_active'];
        $kolor      = h($sphere['kolor'] ?: '#2563eb');
        $ikona      = h($sphere['ikona'] ?: 'bi-globe2');
      ?>
      <div class="sphere-card<?= $is_active ? '' : ' inactive' ?>">

        <!-- Nagłówek sfery -->
        <div class="sphere-header">
          <div class="sphere-icon" style="background:<?= $kolor ?>">
            <i class="bi <?= $ikona ?>"></i>
          </div>
          <div>
            <div class="sphere-code"><?= h($sphere['kod']) ?></div>
            <div class="sphere-name"><?= h($sphere['nazwa']) ?></div>
          </div>
          <?php if (!$is_active): ?>
            <span class="badge bg-secondary ms-1" style="font-size:.65rem">nieaktywna</span>
          <?php endif ?>
          <div class="sphere-actions">
            <a href="<?= h(APP_URL) ?>/admin/strategy_objective.php?new=1&sphere_id=<?= $sphere_id ?>"
               class="btn btn-sm btn-outline-primary" title="Dodaj cel do sfery">
              <i class="bi bi-plus-lg"></i> Dodaj cel
            </a>
            <a href="<?= h(APP_URL) ?>/admin/strategy_sphere.php?edit=<?= $sphere_id ?>"
               class="btn btn-sm btn-outline-secondary" title="Edytuj sferę">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="_action"   value="toggle_sphere">
              <input type="hidden" name="sphere_id" value="<?= $sphere_id ?>">
              <button type="submit" class="btn btn-sm <?= $is_active ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                      title="<?= $is_active ? 'Dezaktywuj' : 'Aktywuj' ?> sferę">
                <i class="bi <?= $is_active ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
              </button>
            </form>
          </div>
        </div>

        <!-- Cele -->
        <?php if (empty($objs)): ?>
          <div class="sphere-empty">Brak celów w tej sferze.
            <a href="<?= h(APP_URL) ?>/admin/strategy_objective.php?new=1&sphere_id=<?= $sphere_id ?>">Dodaj pierwszy cel</a>.
          </div>
        <?php else: ?>
          <?php foreach ($objs as $obj): ?>
            <?php
              $health = strategy_health_score($obj);
              $postep = (float)($obj['postep_procent'] ?? 0);
              $bar_class = match ($health) {
                  'green'  => 'bg-success',
                  'yellow' => 'bg-warning',
                  'red'    => 'bg-danger',
                  default  => 'bg-secondary',
              };
              $bar_width = min(100, max(0, $postep));
            ?>
            <div class="obj-row">
              <div class="obj-main">
                <a href="<?= h(APP_URL) ?>/admin/strategy_objective.php?id=<?= (int)$obj['id'] ?>"
                   class="obj-name">
                  <?= h($obj['nazwa']) ?>
                </a>
                <div class="obj-meta">
                  <?php if ($obj['cel_miernika']): ?>
                    <span><i class="bi bi-rulers me-1"></i><?= h($obj['cel_miernika']) ?></span>
                    <?php if ($obj['wartosc_docelowa']): ?>
                      <span class="ms-2">cel: <?= number_format((float)$obj['wartosc_docelowa'], 0, ',', ' ') ?></span>
                    <?php endif ?>
                    <span class="ms-2">|</span>
                  <?php endif ?>
                  <?= strategy_health_badge($health) ?>
                  <?php if ($obj['status'] !== 'aktywny'): ?>
                    <span class="badge bg-secondary ms-1" style="font-size:.65rem"><?= h($obj['status']) ?></span>
                  <?php endif ?>
                  <?php if ($obj['entity_count'] > 0): ?>
                    <span class="badge bg-light text-muted ms-1" style="font-size:.65rem">
                      <i class="bi bi-link-45deg"></i><?= (int)$obj['entity_count'] ?>
                    </span>
                  <?php endif ?>
                </div>
              </div>
              <div class="obj-progress">
                <div class="d-flex justify-content-between" style="font-size:.7rem;color:#64748b;margin-bottom:2px">
                  <span>Postęp</span>
                  <span><?= number_format($postep, 1, ',', ' ') ?>%</span>
                </div>
                <div class="progress" style="height:6px;border-radius:4px">
                  <div class="progress-bar <?= $bar_class ?>" style="width:<?= $bar_width ?>%"></div>
                </div>
              </div>
              <div class="obj-dates">
                <?php if ($obj['data_od']): ?>
                  <div><i class="bi bi-calendar3 me-1" style="opacity:.5"></i><?= date_pl($obj['data_od']) ?></div>
                <?php endif ?>
                <?php if ($obj['data_do']): ?>
                  <div><i class="bi bi-calendar-x me-1" style="opacity:.5"></i><?= date_pl($obj['data_do']) ?></div>
                <?php endif ?>
                <?php if (!$obj['data_od'] && !$obj['data_do']): ?>
                  <span class="text-muted">—</span>
                <?php endif ?>
              </div>
            </div>
          <?php endforeach ?>
        <?php endif ?>

      </div><!-- /sphere-card -->
    <?php endforeach ?>
  <?php endif ?>

  <!-- Nieprzypisane cele (sphere_id = 0 lub NULL) -->
  <?php
    $unassigned = $objectives_by_sphere[0] ?? [];
    if (!empty($unassigned)):
  ?>
    <div class="sphere-card mt-3">
      <div class="sphere-header">
        <div class="sphere-icon" style="background:#94a3b8"><i class="bi bi-question-circle"></i></div>
        <div>
          <div class="sphere-code">—</div>
          <div class="sphere-name">Nieprzypisane do sfery</div>
        </div>
        <div class="sphere-actions">
          <a href="<?= h(APP_URL) ?>/admin/strategy_objective.php?new=1"
             class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-lg"></i> Dodaj cel
          </a>
        </div>
      </div>
      <?php foreach ($unassigned as $obj): ?>
        <?php
          $health   = strategy_health_score($obj);
          $postep   = (float)($obj['postep_procent'] ?? 0);
          $bar_class = match ($health) {
              'green'  => 'bg-success',
              'yellow' => 'bg-warning',
              'red'    => 'bg-danger',
              default  => 'bg-secondary',
          };
          $bar_width = min(100, max(0, $postep));
        ?>
        <div class="obj-row">
          <div class="obj-main">
            <a href="<?= h(APP_URL) ?>/admin/strategy_objective.php?id=<?= (int)$obj['id'] ?>"
               class="obj-name">
              <?= h($obj['nazwa']) ?>
            </a>
            <div class="obj-meta">
              <?= strategy_health_badge($health) ?>
              <?php if ($obj['status'] !== 'aktywny'): ?>
                <span class="badge bg-secondary ms-1" style="font-size:.65rem"><?= h($obj['status']) ?></span>
              <?php endif ?>
            </div>
          </div>
          <div class="obj-progress">
            <div class="d-flex justify-content-between" style="font-size:.7rem;color:#64748b;margin-bottom:2px">
              <span>Postęp</span>
              <span><?= number_format($postep, 1, ',', ' ') ?>%</span>
            </div>
            <div class="progress" style="height:6px;border-radius:4px">
              <div class="progress-bar <?= $bar_class ?>" style="width:<?= $bar_width ?>%"></div>
            </div>
          </div>
          <div class="obj-dates">
            <?php if ($obj['data_od']): ?><div><?= date_pl($obj['data_od']) ?></div><?php endif ?>
            <?php if ($obj['data_do']): ?><div><?= date_pl($obj['data_do']) ?></div><?php endif ?>
          </div>
        </div>
      <?php endforeach ?>
    </div>
  <?php endif ?>

</div><!-- /strategy-page -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
