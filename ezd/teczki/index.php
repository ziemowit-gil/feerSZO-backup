<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();
$PAGE_TITLE = 'Segregatory aktowe';
$status = $_GET['status'] ?? 'open';
$teczki = ezd_teczki_all($status === 'all' ? '' : $status);
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-archive text-primary me-2"></i>Segregatory aktowe</h4>
  <?php if (is_admin()): ?>
  <a href="<?= APP_URL ?>/ezd/teczki/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowy segregator</a>
  <?php endif; ?>
</div>
<?= flash_html() ?>

<div class="mb-3 d-flex gap-2">
  <?php foreach (['open'=>'Otwarte','closed'=>'Zamknięte','all'=>'Wszystkie'] as $s=>$l): ?>
  <a href="?status=<?= $s ?>" class="btn btn-sm <?= $status===$s ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-archive me-1 text-primary"></i>Segregatory (<?= count($teczki) ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th style="width:90px">Symbol</th>
          <th>Tytuł</th>
          <th>JRWA</th>
          <th class="text-center" style="width:70px">Rok</th>
          <th class="text-center text-nowrap" style="width:130px">Koszulki</th>
          <th style="width:100px">Status</th>
          <th style="width:90px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($teczki as $t): ?>
        <tr onclick="location='<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $t['id'] ?>'" style="cursor:pointer">
          <td class="font-monospace fw-bold text-primary"><?= h($t['symbol']) ?></td>
          <td class="fw-semibold"><?= h($t['title']) ?></td>
          <td class="text-muted" style="font-size:.76rem"><?= $t['jrwa_symbol'] ? h($t['jrwa_symbol'].' — '.$t['jrwa_title']) : '—' ?></td>
          <td class="text-center"><?= (int)$t['rok'] ?></td>
          <td class="text-center text-nowrap">
            <span title="Aktywne / wszystkie"><i class="bi bi-folder2 me-1 text-muted"></i><span class="fw-semibold"><?= (int)$t['open_cases'] ?></span><span class="text-muted"> / <?= (int)$t['total_cases'] ?></span></span>
          </td>
          <td><span class="badge bg-<?= $t['status']==='open'?'success':'secondary' ?>"><?= $t['status']==='open'?'Otwarty':'Zamknięty' ?></span></td>
          <td class="text-end" onclick="event.stopPropagation()">
            <a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $t['id'] ?>" class="btn btn-xs btn-outline-primary btn-sm" title="Otwórz"><i class="bi bi-folder2-open"></i></a>
            <?php if (is_admin()): ?>
            <a href="<?= APP_URL ?>/ezd/teczki/edit.php?id=<?= $t['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm" title="Edytuj"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$teczki): ?>
        <tr><td colspan="7" class="text-center py-5 text-muted">
          <i class="bi bi-archive" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Brak segregatorów. <?php if(is_admin()): ?><a href="<?= APP_URL ?>/ezd/teczki/add.php">Utwórz pierwszy segregator.</a><?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
