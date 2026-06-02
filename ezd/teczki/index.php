<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', 'Moduł kancelarii');
$PAGE_TITLE = 'Teczki aktowe';
$status = $_GET['status'] ?? 'open';
$teczki = ezd_teczki_all($status === 'all' ? '' : $status);
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-archive text-primary me-2"></i>Teczki aktowe</h4>
  <?php if (is_admin()): ?>
  <a href="<?= APP_URL ?>/ezd/teczki/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowa teczka</a>
  <?php endif; ?>
</div>
<?= flash_html() ?>

<div class="mb-3 d-flex gap-2">
  <?php foreach (['open'=>'Otwarte','closed'=>'Zamknięte','all'=>'Wszystkie'] as $s=>$l): ?>
  <a href="?status=<?= $s ?>" class="btn btn-sm <?= $status===$s ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<div class="row g-3">
<?php foreach ($teczki as $t): ?>
<div class="col-md-6 col-xl-4">
  <div class="card shadow-sm h-100">
    <div class="card-body">
      <div class="d-flex align-items-start gap-2 mb-2">
        <span class="badge bg-primary bg-opacity-15 text-primary fw-bold font-monospace"><?= h($t['symbol']) ?></span>
        <span class="badge bg-<?= $t['status']==='open'?'success':'secondary' ?> ms-auto"><?= $t['status']==='open'?'Otwarta':'Zamknięta' ?></span>
      </div>
      <div class="fw-bold mb-1" style="font-size:.95rem"><?= h($t['title']) ?></div>
      <?php if ($t['jrwa_title']): ?>
      <div class="text-muted" style="font-size:.76rem"><i class="bi bi-tag me-1"></i><?= h($t['jrwa_symbol'].' — '.$t['jrwa_title']) ?></div>
      <?php endif; ?>
      <div class="d-flex gap-3 mt-2" style="font-size:.75rem;color:#64748b">
        <span><i class="bi bi-folder2 me-1"></i><?= $t['open_cases'] ?> aktywnych / <?= $t['total_cases'] ?> spraw</span>
        <span class="ms-auto"><?= $t['rok'] ?></span>
      </div>
    </div>
    <div class="card-footer d-flex gap-2 py-2">
      <a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1">
        <i class="bi bi-folder2-open me-1"></i>Otwórz
      </a>
      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/ezd/teczki/edit.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$teczki): ?>
<div class="col-12 text-center py-5 text-muted">
  <i class="bi bi-archive" style="font-size:3rem;display:block;margin-bottom:.75rem;opacity:.3"></i>
  Brak teczek. <?php if(is_admin()): ?><a href="<?= APP_URL ?>/ezd/teczki/add.php">Utwórz pierwszą teczkę.</a><?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
