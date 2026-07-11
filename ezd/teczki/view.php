<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko');
$id     = (int)($_GET['id']??0);
$teczka = ezd_teczka_get($id);
if (!$teczka) { flash_set('error','Segregator nie istnieje.'); header('Location:'.APP_URL.'/ezd/teczki/index.php'); exit; }
$sprawy  = ezd_sprawy_by_teczka($id);
$PAGE_TITLE = $teczka['symbol'].' — '.$teczka['title'];
$status_f = $_GET['status'] ?? '';
if ($status_f) $sprawy = array_filter($sprawy, fn($s)=>$s['status']===$status_f);
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.sprawa-row{display:flex;align-items:center;gap:.75rem;padding:.65rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .12s;}
.sprawa-row:last-child{border-bottom:none;}
.sprawa-row:hover{background:#f8fafc;}
.sprawa-znak{font-family:monospace;font-size:.8rem;font-weight:700;color:#2563eb;white-space:nowrap;}
</style>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/index.php">Segregatory</a></li>
  <li class="breadcrumb-item active"><?= h($teczka['symbol']) ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-primary bg-opacity-15 text-primary fw-bold font-monospace fs-6"><?= h($teczka['symbol']) ?></span>
          <span class="badge bg-<?= $teczka['status']==='open'?'success':'secondary' ?>"><?= $teczka['status']==='open'?'Otwarty':'Zamknięty' ?></span>
          <?php if($teczka['kat_arch']): ?><span class="badge bg-light text-dark border" style="font-size:.68rem">Kat. <?= h($teczka['kat_arch']) ?></span><?php endif; ?>
          <?php if(!empty($teczka['arch_status'])): ?><?= ezd_arch_teczka_badge($teczka['arch_status']) ?><?php if(!empty($teczka['rok_brakowania'])): ?><span class="badge bg-light text-dark border" style="font-size:.68rem">Brakowanie: <?= (int)$teczka['rok_brakowania'] ?></span><?php endif; ?><?php endif; ?>
        </div>
        <h4 class="fw-bold mb-1"><?= h($teczka['title']) ?></h4>
        <div class="text-muted" style="font-size:.8rem">
          <?php if($teczka['jrwa_title']): ?><i class="bi bi-tag me-1"></i><?= h($teczka['jrwa_symbol'].' — '.$teczka['jrwa_title']) ?> &nbsp;·&nbsp;<?php endif; ?>
          Rok: <?= $teczka['rok'] ?>
          <?php if($teczka['owner_name']): ?>&nbsp;·&nbsp;<i class="bi bi-person me-1"></i><?= h($teczka['owner_name']) ?><?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/ezd/teczki/spis.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-list-ol me-1"></i>Spis koszulek</a>
        <?php if(can_edit()): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/add.php?teczka_id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-folder-plus me-1"></i>Nowa koszulka</a>
        <?php endif; ?>
        <?php if(is_admin()): ?>
        <a href="<?= APP_URL ?>/ezd/teczki/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Filtry statusu spraw -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach ([''=>'Wszystkie']+array_map(fn($s)=>$s['label'], EZD_STATUSES_SPRAWA) as $sv=>$sl): ?>
  <a href="?id=<?= $id ?>&status=<?= $sv ?>" class="btn btn-sm <?= $status_f===$sv?'btn-primary':'btn-outline-secondary' ?>"><?= h($sl) ?></a>
  <?php endforeach; ?>
</div>

<!-- Lista spraw -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-folder2-open me-1 text-primary"></i>Koszulki (<?= count($sprawy) ?>)</span>
  </div>
  <div>
    <?php foreach ($sprawy as $s): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="sprawa-row">
      <div class="sprawa-znak"><?= h($s['znak_sprawy']) ?></div>
      <div class="flex-grow-1 overflow-hidden">
        <div class="fw-semibold text-truncate" style="font-size:.88rem"><?= h($s['title']) ?></div>
        <?php if($s['description']): ?>
        <div class="text-muted text-truncate" style="font-size:.74rem"><?= h($s['description']) ?></div>
        <?php endif; ?>
      </div>
      <?= ezd_priority_badge($s['priority']) ?>
      <?= ezd_status_badge_sprawa($s['status']) ?>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap">
        <?php if($s['deadline']): ?><span class="<?= $s['deadline']<date('Y-m-d')?'text-danger fw-bold':'' ?>"><i class="bi bi-calendar-event me-1"></i><?= date_pl($s['deadline']) ?></span><?php endif; ?>
      </div>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap"><?= h($s['owner_name']??'—') ?></div>
      <i class="bi bi-chevron-right text-muted" style="font-size:.75rem"></i>
    </a>
    <?php endforeach; ?>
    <?php if (!$sprawy): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-folder2" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
      Brak koszulek w tym segregatorze.
      <?php if(can_edit()&&$teczka['status']==='open'): ?><br><a href="<?= APP_URL ?>/ezd/sprawy/add.php?teczka_id=<?= $id ?>">Załóż pierwszą koszulkę.</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
