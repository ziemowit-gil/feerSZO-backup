<?php
/**
 * Spis koszulek segregatora — formalny rejestr koszulek wg kolejnego numeru (do wydruku).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$id     = (int)($_GET['id'] ?? 0);
$teczka = ezd_teczka_get($id);
if (!$teczka) { flash_set('error','Segregator nie istnieje.'); header('Location:'.APP_URL.'/ezd/teczki/index.php'); exit; }

// Spis koszulek — kolejność wg numeru rosnąco (kolejność wszczynania)
$sprawy = db_all(
    "SELECT s.*, u.name AS owner_name FROM ezd_sprawy s
     LEFT JOIN users u ON u.id=s.owner_id
     WHERE s.teczka_id=? ORDER BY s.numer ASC", [$id]
);
$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

$PAGE_TITLE = 'Spis koszulek — ' . $teczka['symbol'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.spis-meta{font-size:.85rem;color:#475569}
.spis-table{font-size:.82rem}
.spis-table th{background:#f1f5f9;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}
@media print{
  .no-print{display:none!important}
  .breadcrumb{display:none}
  body{background:#fff}
  .spis-print-area{box-shadow:none!important;border:none!important}
  a[href]:after{content:none!important}
}
</style>

<nav aria-label="breadcrumb" class="mb-3 no-print"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $id ?>"><?= h($teczka['symbol']) ?></a></li>
  <li class="breadcrumb-item active">Spis koszulek</li>
</ol></nav>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <h4 class="fw-bold mb-0"><i class="bi bi-list-ol text-primary me-2"></i>Spis koszulek segregatora</h4>
  <button onclick="window.print()" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Drukuj / PDF</button>
</div>

<div class="card shadow-sm spis-print-area"><div class="card-body p-4">
  <!-- Nagłówek dokumentu -->
  <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
      <?php if($org_name): ?><div class="fw-bold" style="font-size:1.05rem"><?= h($org_name) ?></div><?php endif; ?>
      <div class="spis-meta">Spis koszulek</div>
    </div>
    <div class="text-end spis-meta">
      <div><strong>Symbol segregatora:</strong> <span class="font-monospace"><?= h($teczka['symbol']) ?></span></div>
      <div><strong>Rok:</strong> <?= (int)$teczka['rok'] ?></div>
      <?php if($teczka['kat_arch']): ?><div><strong>Kat. arch.:</strong> <?= h($teczka['kat_arch']) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="mb-3">
    <div style="font-size:1.1rem;font-weight:700"><?= h($teczka['title']) ?></div>
    <?php if($teczka['jrwa_symbol']): ?><div class="spis-meta"><?= h($teczka['jrwa_symbol'].' — '.$teczka['jrwa_title']) ?></div><?php endif; ?>
  </div>

  <table class="table table-bordered spis-table">
    <thead>
      <tr>
        <th style="width:48px">Lp.</th>
        <th>Znak koszulki</th>
        <th>Tytuł / przedmiot koszulki</th>
        <th style="width:130px">Prowadzący</th>
        <th style="width:95px">Data wszczęcia</th>
        <th style="width:95px">Data zakończenia</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($sprawy as $s): ?>
      <tr>
        <td class="text-center"><?= (int)$s['numer'] ?></td>
        <td class="font-monospace"><?= h($s['znak_sprawy']) ?></td>
        <td><?= h($s['title']) ?>
          <?php if($s['status']!=='closed'): ?><span class="badge bg-light text-dark border ms-1" style="font-size:.6rem">w toku</span><?php endif; ?>
        </td>
        <td><?= h($s['owner_name'] ?: '—') ?></td>
        <td class="text-center"><?= $s['created_at'] ? date('d.m.Y', strtotime($s['created_at'])) : '—' ?></td>
        <td class="text-center"><?= $s['closed_at'] ? date('d.m.Y', strtotime($s['closed_at'])) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$sprawy): ?>
      <tr><td colspan="6" class="text-center text-muted py-3">Brak koszulek w segregatorze.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="spis-meta mt-3">Liczba koszulek: <strong><?= count($sprawy) ?></strong> · Wydrukowano: <?= date('d.m.Y H:i') ?></div>
</div></div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
