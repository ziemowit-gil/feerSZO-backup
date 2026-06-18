<?php
/**
 * Rejestr pełnomocnictw — wersja do wydruku.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$rows = ezd_pelnomocnictwa_all(['status' => $_GET['status'] ?? '', 'q' => trim($_GET['q'] ?? '')]);
$jrwa = ezd_peln_jrwa();
$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$PAGE_TITLE = 'Rejestr pełnomocnictw — wydruk';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.rej-meta{font-size:.85rem;color:#475569}
.rej-table{font-size:.78rem}
.rej-table th{background:#f1f5f9;font-size:.68rem;text-transform:uppercase;letter-spacing:.03em}
@media print{ .no-print{display:none!important} .breadcrumb{display:none} body{background:#fff} .rej-print-area{box-shadow:none!important;border:none!important} }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <h4 class="fw-bold mb-0"><i class="bi bi-person-vcard text-primary me-2"></i>Rejestr pełnomocnictw</h4>
  <button onclick="window.print()" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Drukuj / PDF</button>
</div>

<div class="card shadow-sm rej-print-area"><div class="card-body p-4">
  <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
      <?php if($org_name): ?><div class="fw-bold" style="font-size:1.05rem"><?= h($org_name) ?></div><?php endif; ?>
      <div class="rej-meta">Rejestr pełnomocnictw i upoważnień (JRWA <?= h($jrwa) ?>)</div>
    </div>
    <div class="text-end rej-meta">Wydrukowano: <?= date('d.m.Y H:i') ?> · Pozycji: <?= count($rows) ?></div>
  </div>

  <table class="table table-bordered rej-table">
    <thead>
      <tr>
        <th style="width:36px">Lp.</th>
        <th>Znak sprawy / nr</th>
        <th>Mocodawca</th>
        <th>Pełnomocnik</th>
        <th>Zakres</th>
        <th style="width:80px">Udzielono</th>
        <th style="width:80px">Ważne do</th>
        <th style="width:70px">Status</th>
      </tr>
    </thead>
    <tbody>
      <?php $lp=0; foreach($rows as $r): $lp++; $st=$r['_status']; ?>
      <tr>
        <td class="text-center"><?= $lp ?></td>
        <td class="font-monospace"><?= h($r['znak_sprawy']) ?><?= $r['numer'] ? '<br><small>nr '.h($r['numer']).'</small>' : '' ?></td>
        <td><?= h($r['mocodawca'] ?: '—') ?></td>
        <td><?= h($r['pelnomocnik'] ?: '—') ?></td>
        <td><?= h($r['zakres'] ?: '—') ?></td>
        <td class="text-center"><?= $r['data_udzielenia'] ? date('d.m.Y', strtotime($r['data_udzielenia'])) : '—' ?></td>
        <td class="text-center"><?= $r['data_waznosci'] ? date('d.m.Y', strtotime($r['data_waznosci'])) : 'bezterm.' ?></td>
        <td class="text-center"><?= h($st['label']) ?><?= $r['data_odwolania'] ? '<br><small>'.date('d.m.Y', strtotime($r['data_odwolania'])).'</small>' : '' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">Brak pełnomocnictw w rejestrze.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
