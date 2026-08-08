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
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

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
.peln-uwierz-block{margin-top:2rem;padding-top:1.5rem;border-top:2px solid #334155;page-break-inside:avoid}
.peln-uwierz-klauzula{font-size:.88rem;font-style:italic;margin-bottom:1.25rem}
.peln-uwierz-podpis-line{font-size:.88rem;margin-bottom:.2rem}
.peln-uwierz-caption{font-size:.78rem;color:#475569}
.peln-miejsc-inp{border:0;border-bottom:1.5px solid #334155;outline:none;background:transparent;font-size:inherit;min-width:130px;max-width:200px;color:inherit}
@media print{
  .no-print{display:none!important} .breadcrumb{display:none} body{background:#fff} .rej-print-area{box-shadow:none!important;border:none!important}
  .peln-miejsc-inp{border:0!important;border-bottom:1px solid #334155!important;box-shadow:none}
}
</style>

<div class="d-flex justify-content-between align-items-center mb-2 no-print">
  <h4 class="fw-bold mb-0"><i class="bi bi-person-vcard text-primary me-2"></i>Rejestr pełnomocnictw</h4>
  <button onclick="window.print()" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Drukuj / PDF</button>
</div>

<div class="no-print d-flex align-items-center gap-3 flex-wrap mb-3 px-2 py-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:.82rem">
  <label class="d-flex align-items-center gap-2 mb-0 fw-semibold" style="cursor:pointer;color:#15803d">
    <input type="checkbox" id="uwierzytelnioneToggle" class="form-check-input m-0" style="accent-color:#15803d">
    <i class="bi bi-shield-check"></i>Uwierzytelnione
  </label>
  <span class="text-muted" style="font-size:.78rem">Klauzula:</span>
  <div id="uwierzytelnioneKlauzule" style="display:none" class="d-flex gap-3 flex-wrap">
    <label class="d-flex align-items-center gap-1 mb-0" style="cursor:pointer">
      <input type="radio" name="klauzula" id="kl1" value="1" class="form-check-input m-0" checked>
      <span>z kwalifikowanym podpisem elektronicznym</span>
    </label>
    <label class="d-flex align-items-center gap-1 mb-0" style="cursor:pointer">
      <input type="radio" name="klauzula" id="kl2" value="2" class="form-check-input m-0">
      <span>kopia dokumentu cyfrowego</span>
    </label>
  </div>
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

<div id="peln-uwierzytelnienie-block" class="peln-uwierz-block">
  <p id="peln-klauzula-1" class="peln-uwierz-klauzula">
    „Niniejszym poświadczam zgodność wydruku z dokumentem elektronicznym opatrzonym kwalifikowanym podpisem elektronicznym, zachowanym w formacie cyfrowym."
  </p>
  <p id="peln-klauzula-2" class="peln-uwierz-klauzula" style="display:none">
    „Niniejszym poświadczam zgodność niniejszej kopii z dokumentem elektronicznym zachowanym w formacie cyfrowym."
  </p>
  <p class="peln-uwierz-podpis-line mt-3">
    <input id="peln-miejsc" type="text" class="peln-miejsc-inp" placeholder="Miejscowość">,
    dnia <span id="peln-data"></span>
  </p>
  <br><br>
  <p class="peln-uwierz-podpis-line">......................................................</p>
  <p class="peln-uwierz-caption">(własnoręczny podpis osoby poświadczającej)</p>
</div>

<script>
(function () {
  var block  = document.getElementById('peln-uwierzytelnienie-block');
  var toggle = document.getElementById('uwierzytelnioneToggle');
  var opts   = document.getElementById('uwierzytelnioneKlauzule');
  var kl1    = document.getElementById('peln-klauzula-1');
  var kl2    = document.getElementById('peln-klauzula-2');
  var dataEl = document.getElementById('peln-data');

  if (dataEl) {
    var now = new Date();
    var ms  = ['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
    dataEl.textContent = now.getDate() + ' ' + ms[now.getMonth()] + ' ' + now.getFullYear() + ' r.';
  }

  function applyKlauzula() {
    var val = document.querySelector('input[name="klauzula"]:checked');
    if (!val) return;
    if (kl1) kl1.style.display = val.value === '1' ? '' : 'none';
    if (kl2) kl2.style.display = val.value === '2' ? '' : 'none';
  }

  if (block) block.style.display = 'none';

  if (toggle) {
    toggle.addEventListener('change', function () {
      block.style.display = this.checked ? '' : 'none';
      if (opts) opts.style.display = this.checked ? '' : 'none';
      applyKlauzula();
    });
  }

  document.querySelectorAll('input[name="klauzula"]').forEach(function (r) {
    r.addEventListener('change', applyKlauzula);
  });

  applyKlauzula();
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
