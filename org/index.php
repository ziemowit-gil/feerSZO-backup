<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org.php';
require_login(); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$PAGE_TITLE = 'Struktura Organizacyjna';
$all_units  = org_units_all();
$tree       = org_build_tree($all_units);

// Statystyki podsumowujące
$total_units   = count($all_units);
$active_units  = count(array_filter($all_units, fn($u) => $u['status'] === 'active'));
$total_members = db_one("SELECT COUNT(*) AS c FROM org_members WHERE (valid_to IS NULL OR valid_to >= date('now'))")['c'] ?? 0;
$on_leave      = db_one("SELECT COUNT(*) AS c FROM org_members WHERE status IN ('leave','sick') AND (valid_to IS NULL OR valid_to >= date('now'))")['c'] ?? 0;

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.org-node { border-left:2px solid #e2e8f0; margin-left:0; }
.org-node:first-child { border-left:none; }
.org-node-card {
  background:#fff; border:1px solid #e2e8f0; border-radius:8px;
  margin:.2rem 0 .2rem .5rem; transition:box-shadow .12s;
}
.org-node-card:hover { box-shadow:0 2px 10px rgba(0,0,0,.07); }
.org-node > .org-node-card { margin-left:0; }
.org-toggle { color:#94a3b8; line-height:1; }
.org-toggle:hover { color:#2563eb; }

/* Stats cards */
.stat-chip { background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.9rem 1.1rem;display:flex;align-items:center;gap:.8rem; }
.stat-icon { width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 text-primary me-2"></i>Struktura Organizacyjna</h4>
    <div class="text-muted" style="font-size:.78rem;margin-top:.1rem">Hierarchia jednostek, stanowiska i przypisania osobowe</div>
  </div>
  <?php if(is_admin()): ?>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/org/units/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowa jednostka</a>
    <a href="<?= APP_URL ?>/org/positions/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-briefcase me-1"></i>Stanowiska</a>
    <a href="<?= APP_URL ?>/org/history.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i>Historia</a>
  </div>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['Jednostek',       $active_units.'/'.$total_units, 'bi-diagram-3',      'primary'],
    ['Osób w strukturze', $total_members,               'bi-people-fill',    'success'],
    ['Na urlopie/zw.',  $on_leave,                      'bi-person-slash',   'warning'],
  ] as [$lbl,$val,$icon,$color]): ?>
  <div class="col-6 col-md-4">
    <div class="stat-chip">
      <div class="stat-icon bg-<?= $color ?> bg-opacity-10"><i class="bi <?= $icon ?> text-<?= $color ?>"></i></div>
      <div><div style="font-size:1.4rem;font-weight:700;line-height:1"><?= $val ?></div><div style="font-size:.72rem;color:#64748b"><?= $lbl ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Drzewo Struktury -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-diagram-3 me-1 text-primary"></i>Drzewo organizacyjne</span>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="expandAll()"><i class="bi bi-arrows-expand me-1"></i>Rozwiń</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="collapseAll()"><i class="bi bi-arrows-collapse me-1"></i>Zwiń</button>
    </div>
  </div>
  <div class="card-body p-3">
    <?php if ($tree): ?>
    <?php org_render_tree($tree); ?>
    <?php else: ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-diagram-3" style="font-size:3rem;display:block;margin-bottom:.75rem;opacity:.25"></i>
      Brak jednostek organizacyjnych.
      <?php if(is_admin()): ?><br><a href="<?= APP_URL ?>/org/units/add.php">Utwórz pierwszą jednostkę</a>.<?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Moje przypisania (dla zalogowanego użytkownika) -->
<?php
$my_units = org_user_units((int)current_user()['id']);
if ($my_units): ?>
<div class="card shadow-sm mt-4">
  <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-person-badge me-1 text-primary"></i>Moje przypisania (<?= count($my_units) ?>)</div>
  <div class="row g-0">
    <?php foreach($my_units as $m): ?>
    <div class="col-md-6 col-xl-4">
      <div class="p-3 border-bottom border-end">
        <div class="d-flex align-items-start gap-2">
          <i class="bi bi-diagram-3 text-primary mt-1" style="font-size:.9rem"></i>
          <div class="flex-grow-1">
            <a href="<?= APP_URL ?>/org/units/view.php?id=<?= $m['unit_id'] ?>" class="fw-semibold text-decoration-none" style="font-size:.88rem"><?= h($m['unit_name']) ?></a>
            <span class="badge bg-light text-dark border font-monospace ms-1" style="font-size:.63rem"><?= h($m['unit_code']) ?></span>
            <?php if($m['is_head']): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.63rem"><i class="bi bi-star-fill me-1"></i>Kierownik</span><?php endif; ?>
            <div class="text-muted" style="font-size:.74rem"><?= h($m['position_name'] ?: ($m['position_label'] ?? '—')) ?></div>
            <div class="mt-1"><?= org_status_badge($m['status']) ?></div>
          </div>
          <a href="<?= APP_URL ?>/org/members/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ustawienia"><i class="bi bi-gear" style="font-size:.75rem"></i></a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
function expandAll() {
  document.querySelectorAll('#orgn-*,.collapse').forEach(el => {
    if (el.classList.contains('collapse') && !el.classList.contains('show')) {
      bootstrap.Collapse.getOrCreateInstance(el).show();
    }
  });
}
function collapseAll() {
  document.querySelectorAll('.collapse.show').forEach(el => {
    bootstrap.Collapse.getOrCreateInstance(el).hide();
  });
}
// Rotate chevron on toggle
document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(btn => {
  const target = document.querySelector(btn.dataset.bsTarget);
  if (!target) return;
  target.addEventListener('show.bs.collapse', () => btn.querySelector('.org-toggle i, .sb-chevron, .bi-chevron-down')?.classList.replace('bi-chevron-right','bi-chevron-down'));
  target.addEventListener('hide.bs.collapse', () => btn.querySelector('.bi-chevron-down')?.classList.replace('bi-chevron-down','bi-chevron-right'));
});
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
