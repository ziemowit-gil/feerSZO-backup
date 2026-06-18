<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł kancelarii');

$PAGE_TITLE = 'Sprawy';

$status_f   = $_GET['status']    ?? '';
$priority_f = $_GET['priority']  ?? '';
$q          = trim($_GET['q']    ?? '');

$filters = ['status' => $status_f, 'priority' => $priority_f, 'q' => $q];
$sprawy  = ezd_sprawy_all($filters);

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.sprawa-row{display:flex;align-items:center;gap:.75rem;padding:.65rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .12s;}
.sprawa-row:last-child{border-bottom:none;}
.sprawa-row:hover{background:#f8fafc;}
.sprawa-znak{font-family:monospace;font-size:.8rem;font-weight:700;color:#2563eb;white-space:nowrap;}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-folder2-open text-primary me-2"></i>Sprawy</h4>
  <?php if(can_edit()): ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="btn btn-primary btn-sm"><i class="bi bi-folder-plus me-1"></i>Nowa sprawa</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Filtry -->
<form method="get" class="mb-3">
<div class="row g-2 align-items-end">
  <div class="col-md-4">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Szukaj znaku lub tytułu…" value="<?= h($q) ?>">
  </div>
  <div class="col-md-3">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <?php foreach(EZD_STATUSES_SPRAWA as $sv=>$sl): ?>
      <option value="<?= $sv ?>" <?= $status_f===$sv?'selected':'' ?>><?= h($sl['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <select name="priority" class="form-select form-select-sm">
      <option value="">Wszystkie priorytety</option>
      <?php foreach(EZD_PRIORITIES as $pv=>$pl): ?>
      <option value="<?= $pv ?>" <?= $priority_f===$pv?'selected':'' ?>><?= h($pl['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i>Szukaj</button>
  </div>
</div>
</form>

<!-- Tabela -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-folder2 me-1 text-primary"></i>Wyniki (<?= count($sprawy) ?>)</span>
  </div>
  <div>
    <?php foreach($sprawy as $s): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="sprawa-row">
      <div class="sprawa-znak"><?= h($s['znak_sprawy']) ?></div>
      <div class="flex-grow-1 overflow-hidden">
        <div class="fw-semibold text-truncate" style="font-size:.88rem"><?= h($s['title']) ?></div>
        <div class="text-muted" style="font-size:.74rem"><i class="bi bi-archive me-1"></i><?= h($s['teczka_symbol'].' — '.$s['teczka_title']) ?></div>
      </div>
      <?= ezd_etap_badge($s['etap'] ?? 'wszczeta') ?>
      <?= ezd_priority_badge($s['priority']) ?>
      <?= ezd_status_badge_sprawa($s['status']) ?>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap">
        <?php if($s['deadline']): ?>
        <span class="<?= $s['deadline']<date('Y-m-d')?'text-danger fw-bold':'' ?>"><i class="bi bi-calendar-event me-1"></i><?= date_pl($s['deadline']) ?></span>
        <?php endif; ?>
      </div>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap"><?= h($s['owner_name']??'—') ?></div>
      <i class="bi bi-chevron-right text-muted" style="font-size:.75rem"></i>
    </a>
    <?php endforeach; ?>
    <?php if(!$sprawy): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-folder2" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
      Brak spraw pasujących do filtrów.
      <?php if(can_edit()): ?><br><a href="<?= APP_URL ?>/ezd/sprawy/add.php">Załóż pierwszą sprawę.</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
