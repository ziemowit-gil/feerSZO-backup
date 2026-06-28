<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
$k30_reports = can_read('karty30') || is_admin();
$k30_payouts = can_write('karty30') || is_admin();
require_module_enabled('reports_enabled', 'Moduł zestawień');
$PAGE_TITLE = 'Raporty umów';

// Statystyki per typ i status
$summary = [];
foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $rows  = db_all("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status");
    $summary[$slug] = ['label' => $label, 'statuses' => [], 'total' => 0];
    foreach ($rows as $r) {
        $summary[$slug]['statuses'][$r['status']] = $r['cnt'];
        $summary[$slug]['total'] += $r['cnt'];
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary"></i> Raporty umów</h4>
</div>

<!-- Tabela podsumowania -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold">Podsumowanie rejestru</div>
<div class="table-responsive">
<table class="table table-sm table-bordered mb-0">
  <thead class="table-light">
    <tr>
      <th>Typ umowy</th>
      <?php
      $all_statuses = array_unique(array_merge(...array_values(array_map(fn($s) => array_keys($s['statuses']), $summary))));
      foreach ($all_statuses as $st) echo '<th class="text-center">' . h($st) . '</th>';
      ?>
      <th class="text-center fw-bold">Łącznie</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($summary as $slug => $s): ?>
  <tr>
    <td><a href="<?= APP_URL ?>/contracts/<?= $slug ?>/list.php"><?= h($s['label']) ?></a></td>
    <?php foreach ($all_statuses as $st): ?>
    <td class="text-center"><?= $s['statuses'][$st] ?? '—' ?></td>
    <?php endforeach; ?>
    <td class="text-center fw-bold"><?= $s['total'] ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>

<!-- Eksport -->
<div class="row g-3">
<?php foreach (CONTRACT_TYPES as $slug => $label): ?>
<div class="col-md-6 col-xl-4">
<div class="card shadow-sm h-100">
<div class="card-header fw-semibold"><?= h($label) ?></div>
<div class="card-body d-flex flex-column gap-2">
  <a href="export.php?type=<?= $slug ?>&format=csv" class="btn btn-outline-success">
    <i class="bi bi-file-earmark-spreadsheet"></i> Eksportuj CSV (Excel)
  </a>
  <a href="export.php?type=<?= $slug ?>&format=print" target="_blank" class="btn btn-outline-secondary">
    <i class="bi bi-printer"></i> Zestawienie do druku (PDF)
  </a>
</div>
</div>
</div>
<?php endforeach; ?>

<!-- Eksport zbiorczy -->
<div class="col-12">
<div class="card shadow-sm">
<div class="card-header fw-semibold">Eksport zbiorczy — wszystkie typy</div>
<div class="card-body d-flex gap-2 flex-wrap">
  <a href="export.php?type=all&format=csv" class="btn btn-success">
    <i class="bi bi-file-earmark-spreadsheet"></i> Wszystkie umowy — CSV
  </a>
  <a href="export.php?type=all&format=print" target="_blank" class="btn btn-outline-dark">
    <i class="bi bi-printer"></i> Wszystkie — druk/PDF
  </a>
</div>
</div>
</div>

<!-- Generator XLS — Ubezpieczenie (umowy krótkie) -->
<div class="col-12">
<div class="card border-warning-subtle shadow-sm">
<div class="card-header fw-semibold d-flex align-items-center gap-2"
     style="background:#FFFBEB;border-bottom:1px solid #FDE68A">
  <i class="bi bi-shield-check text-warning fs-5"></i>
  Wykaz do ubezpieczenia — umowy krótsze niż 30 dni
  <span class="badge bg-warning text-dark ms-1" style="font-size:.72rem">PESEL / Imię / Nazwisko</span>
</div>
<div class="card-body">
  <p class="text-muted small mb-3">
    Generuje plik Excel (.xls) z listą osób, których umowy trwają krócej niż określona liczba dni.
    Przydatne do zgłoszeń ubezpieczeniowych (ZUS/NNW) dla krótkoterminowych umów wolontariackich i zleceń.
  </p>
  <form method="get" action="export_insurance.php" class="row g-2 align-items-end">
    <div class="col-sm-3">
      <label class="form-label small fw-semibold">Typ umowy</label>
      <select name="type" class="form-select form-select-sm">
        <option value="all">Wszystkie typy</option>
        <?php foreach (CONTRACT_TYPES as $slug => $label): ?>
        <option value="<?= $slug ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <label class="form-label small fw-semibold">Maks. liczba dni</label>
      <input type="number" name="max_days" class="form-control form-control-sm"
             value="30" min="1" max="365">
    </div>
    <div class="col-sm-2">
      <label class="form-label small fw-semibold">Data od</label>
      <input type="date" name="date_from" class="form-control form-control-sm"
             value="<?= date('Y-m-01') ?>">
    </div>
    <div class="col-sm-2">
      <label class="form-label small fw-semibold">Data do</label>
      <input type="date" name="date_to" class="form-control form-control-sm"
             value="<?= date('Y-m-t') ?>">
    </div>
    <div class="col-sm-3">
      <button type="submit" class="btn btn-warning w-100">
        <i class="bi bi-file-earmark-excel me-1"></i>Generuj XLS
      </button>
    </div>
  </form>
</div>
</div>
</div>

</div>

<?php if ($k30_reports): ?>
<!-- ── Karty 30 — Dydaktyka / TI ──────────────────────────────────────────── -->
<div class="d-flex align-items-center mb-3 mt-4 gap-2 flex-wrap">
  <h4 class="mb-0"><i class="bi bi-card-checklist" style="color:#7c3aed"></i> Raporty — Karty 30 (Dydaktyka)</h4>
  <div class="ms-auto d-flex align-items-center gap-1">
    <label for="k30repM" class="form-label small mb-0 text-muted">Miesiąc:</label>
    <input type="month" id="k30repM" value="<?= date('Y-m') ?>" class="form-control form-control-sm" style="width:auto">
  </div>
</div>
<div class="row g-3">
  <div class="col-md-6 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold">Kursy TI</div>
      <div class="card-body d-flex flex-column gap-2">
        <span class="text-muted small">Lista kursów: prowadzący, uczestnicy, model rozliczania, stawka za lekcję.</span>
        <a href="karty30_export.php?report=courses&format=print" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Do druku (PDF)</a>
        <a href="karty30_export.php?report=courses&format=csv" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> CSV (Excel)</a>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold">Lekcje i frekwencja <span class="text-muted small">(miesiąc)</span></div>
      <div class="card-body d-flex flex-column gap-2">
        <span class="text-muted small">Wykaz lekcji w wybranym miesiącu ze statusem i obecnością.</span>
        <a data-k30base="karty30_export.php?report=lessons&format=print" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Do druku (PDF)</a>
        <a data-k30base="karty30_export.php?report=lessons&format=csv" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> CSV (Excel)</a>
      </div>
    </div>
  </div>
  <?php if ($k30_payouts): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold">Wypłaty prowadzących <span class="text-muted small">(miesiąc)</span></div>
      <div class="card-body d-flex flex-column gap-2">
        <span class="text-muted small">Sumy wynagrodzeń per prowadzący: brutto-brutto, składki, podatek, na rękę.</span>
        <a data-k30base="karty30_export.php?report=payouts&format=print" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Do druku (PDF)</a>
        <a data-k30base="karty30_export.php?report=payouts&format=csv" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> CSV (Excel)</a>
        <a href="<?= APP_URL ?>/karty30/ti/payouts.php" class="btn btn-link btn-sm p-0 text-decoration-none"><i class="bi bi-box-arrow-up-right"></i> Otwórz interaktywny raport</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
(function(){
  var inp = document.getElementById('k30repM');
  function sync(){
    var m = inp.value || '';
    document.querySelectorAll('[data-k30base]').forEach(function(a){
      a.href = a.getAttribute('data-k30base') + (m ? '&m=' + encodeURIComponent(m) : '');
    });
  }
  if (inp){ inp.addEventListener('change', sync); sync(); }
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
