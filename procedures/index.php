<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/procedures.php';

require_login();
require_module_enabled('procedures_enabled', 'Moduł procedur');

$PAGE_TITLE = 'Procedury';

$filter = [
    'q'        => trim($_GET['q']        ?? ''),
    'category' => trim($_GET['category'] ?? ''),
    'status'   => $_GET['status']        ?? 'active',
];

$procs      = proc_get_all($filter);
$categories = proc_get_categories();
$stats      = proc_stats();

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.proc-card {
    background:#fff;
    border:1.5px solid #e2e8f0;
    border-radius:12px;
    padding:1.15rem 1.25rem;
    text-decoration:none;
    color:inherit;
    display:flex;
    flex-direction:column;
    gap:.45rem;
    transition:border-color .15s, box-shadow .15s, transform .12s;
    height:100%;
}
.proc-card:hover {
    border-color:#93c5fd;
    box-shadow:0 4px 18px rgba(37,99,235,.1);
    transform:translateY(-2px);
    color:inherit;
}
.proc-card-title {
    font-size:.95rem;
    font-weight:700;
    color:#1e293b;
    line-height:1.35;
}
.proc-card-meta {
    font-size:.73rem;
    color:#94a3b8;
    display:flex;
    flex-wrap:wrap;
    gap:.4rem .75rem;
    align-items:center;
}
.proc-version-badge {
    font-size:.65rem;
    padding:.15rem .45rem;
    border-radius:.3rem;
    background:#f1f5f9;
    color:#475569;
    font-weight:600;
    letter-spacing:.02em;
}
.proc-stat-card {
    background:#fff;
    border:1.5px solid #e2e8f0;
    border-radius:12px;
    padding:1rem 1.25rem;
    display:flex;
    align-items:center;
    gap:.9rem;
}
.proc-stat-icon {
    width:42px; height:42px;
    border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.2rem;
    flex-shrink:0;
}
.filter-chip {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.3rem .75rem;
    border:1.5px solid #e2e8f0;
    border-radius:2rem;
    background:#fff;
    font-size:.78rem;
    color:#475569;
    text-decoration:none;
    transition:border-color .13s, background .13s;
    white-space:nowrap;
}
.filter-chip:hover, .filter-chip.active {
    border-color:#2563eb;
    background:#eff6ff;
    color:#1d4ed8;
}
.filter-chip.active { font-weight:600; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold">
      <i class="bi bi-journal-bookmark-fill text-primary me-2"></i>Procedury
    </h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">
      Wewnętrzne procedury i instrukcje organizacji
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/procedures/asystent.php" class="btn btn-outline-primary"
       style="border-color:#7c3aed;color:#6d28d9">
      <i class="bi bi-robot me-1"></i>Asystent AI
    </a>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/procedures/add.php" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowa procedura
    </a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="proc-stat-card">
      <div class="proc-stat-icon bg-primary bg-opacity-10">
        <i class="bi bi-journal-text text-primary"></i>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;line-height:1"><?= (int)$stats['active'] ?></div>
        <div style="font-size:.73rem;color:#64748b">Aktywne</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="proc-stat-card">
      <div class="proc-stat-icon bg-secondary bg-opacity-10">
        <i class="bi bi-archive text-secondary"></i>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;line-height:1"><?= (int)$stats['archived'] ?></div>
        <div style="font-size:.73rem;color:#64748b">Zarchiwizowane</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="proc-stat-card">
      <div class="proc-stat-icon bg-success bg-opacity-10">
        <i class="bi bi-tags text-success"></i>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;line-height:1"><?= count($categories) ?></div>
        <div style="font-size:.73rem;color:#64748b">Kategorie</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="proc-stat-card">
      <div class="proc-stat-icon bg-warning bg-opacity-10">
        <i class="bi bi-person-check text-warning"></i>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;line-height:1">
          <?= db_one("SELECT COUNT(DISTINCT owner_id) AS c FROM procedures WHERE status='active' AND owner_id IS NOT NULL")['c'] ?? 0 ?>
        </div>
        <div style="font-size:.73rem;color:#64748b">Właściciele</div>
      </div>
    </div>
  </div>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-4">
  <div class="card-body py-2 px-3">
    <form method="get" class="d-flex flex-wrap align-items-center gap-2">
      <div class="position-relative flex-grow-1" style="min-width:180px;max-width:320px">
        <i class="bi bi-search position-absolute" style="left:.7rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.9rem;pointer-events:none"></i>
        <input type="search" name="q" value="<?= h($filter['q']) ?>"
               class="form-control form-control-sm" placeholder="Szukaj procedury…"
               style="padding-left:2.1rem">
      </div>
      <?php if ($categories): ?>
      <select name="category" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Wszystkie kategorie</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= h($cat) ?>" <?= $filter['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <input type="hidden" name="status" value="<?= h($filter['status']) ?>">
      <button type="submit" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-funnel"></i> Filtruj
      </button>
      <?php if ($filter['q'] || $filter['category']): ?>
      <a href="?status=<?= h($filter['status']) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x"></i> Wyczyść
      </a>
      <?php endif; ?>

      <div class="ms-auto d-flex gap-1">
        <a href="?status=active<?= $filter['q'] ? '&q='.urlencode($filter['q']) : '' ?>"
           class="filter-chip <?= $filter['status'] === 'active' ? 'active' : '' ?>">
          <i class="bi bi-circle-fill" style="font-size:.45rem;color:#22c55e"></i> Aktywne
        </a>
        <a href="?status=archived<?= $filter['q'] ? '&q='.urlencode($filter['q']) : '' ?>"
           class="filter-chip <?= $filter['status'] === 'archived' ? 'active' : '' ?>">
          <i class="bi bi-archive"></i> Archiwum
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Siatka procedur -->
<?php if (empty($procs)): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-journal-x" style="font-size:3rem;display:block;margin-bottom:.75rem;opacity:.4"></i>
  <div class="fw-semibold mb-1">Brak procedur</div>
  <div style="font-size:.83rem">
    <?php if ($filter['q'] || $filter['category']): ?>
      Nie znaleziono procedur pasujących do podanych filtrów.
    <?php elseif ($filter['status'] === 'archived'): ?>
      Brak zarchiwizowanych procedur.
    <?php else: ?>
      Nie ma jeszcze żadnych procedur.
      <?php if (can_edit()): ?>
      <a href="<?= APP_URL ?>/procedures/add.php">Dodaj pierwszą.</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<?php if ($filter['category']): ?>
<div class="mb-3 d-flex align-items-center gap-2">
  <span class="badge bg-primary"><?= h($filter['category']) ?></span>
  <span class="text-muted" style="font-size:.8rem"><?= count($procs) ?> procedur w tej kategorii</span>
</div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($procs as $p): ?>
  <div class="col-md-6 col-xl-4">
    <a href="<?= APP_URL ?>/procedures/view.php?id=<?= $p['id'] ?>" class="proc-card d-block text-decoration-none">

      <!-- Nagłówek karty -->
      <div class="d-flex align-items-start justify-content-between gap-2">
        <div class="proc-card-title"><?= h($p['title']) ?></div>
        <span class="proc-version-badge flex-shrink-0">v<?= $p['version'] ?></span>
      </div>

      <!-- Kategoria -->
      <?php if ($p['category']): ?>
      <div>
        <span class="badge" style="background:#f1f5f9;color:#475569;font-size:.7rem;font-weight:500">
          <i class="bi bi-tag me-1"></i><?= h($p['category']) ?>
        </span>
      </div>
      <?php endif; ?>

      <!-- Preview treści -->
      <?php if ($p['content']): ?>
      <div style="font-size:.78rem;color:#64748b;line-height:1.4;
                  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
        <?= h(strip_tags(substr($p['content'], 0, 200))) ?>
      </div>
      <?php endif; ?>

      <!-- Meta -->
      <div class="proc-card-meta mt-auto pt-1" style="border-top:1px solid #f1f5f9">
        <?php if ($p['owner_name']): ?>
        <span><i class="bi bi-person me-1"></i><?= h($p['owner_name']) ?></span>
        <?php endif; ?>
        <span class="ms-auto"><i class="bi bi-clock me-1"></i><?= date_pl($p['updated_at']) ?></span>
        <?php if ($p['status'] === 'archived'): ?>
        <span class="badge bg-secondary" style="font-size:.62rem">Archiwum</span>
        <?php endif; ?>
      </div>

    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
