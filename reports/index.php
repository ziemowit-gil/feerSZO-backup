<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
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
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
