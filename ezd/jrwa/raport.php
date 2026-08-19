<?php
/**
 * Raport statystyczny Wykazu Akt (JRWA) — liczba i czas zamykania spraw per klasa.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$rows = db_all("
    SELECT
        j.id,
        j.symbol,
        j.title,
        j.kat_arch,
        COUNT(DISTINCT s.id) AS total_spraw,
        COUNT(DISTINCT CASE WHEN s.status='open' THEN s.id END) AS open_spraw,
        COUNT(DISTINCT CASE WHEN s.status='closed' THEN s.id END) AS closed_spraw,
        ROUND(AVG(CASE WHEN s.status='closed' AND s.closed_at IS NOT NULL
                       THEN julianday(s.closed_at)-julianday(s.created_at) END), 1) AS avg_days_close
    FROM ezd_jrwa j
    LEFT JOIN ezd_sprawy s ON s.jrwa_id = j.id
    WHERE j.kat_arch != ''
    GROUP BY j.id, j.symbol, j.title, j.kat_arch
    ORDER BY j.symbol
");

// Totals for tfoot
$tot_all    = 0;
$tot_open   = 0;
$tot_closed = 0;
foreach ($rows as $r) {
    $tot_all    += (int)$r['total_spraw'];
    $tot_open   += (int)$r['open_spraw'];
    $tot_closed += (int)$r['closed_spraw'];
}

// Badge color helper for kat_arch
function kat_arch_badge(string $ka): string {
    $map = [
        'A'    => 'badge bg-danger bg-opacity-15 text-danger border border-danger',
        'B5'   => 'badge bg-success bg-opacity-15 text-success border border-success',
        'B10'  => 'badge bg-info bg-opacity-15 text-info border border-info',
        'B25'  => 'badge bg-warning bg-opacity-15 text-warning border border-warning',
        'B50'  => 'badge bg-secondary bg-opacity-15 text-secondary border border-secondary',
        'Bc'   => 'badge bg-light text-dark border',
        'BE5'  => 'badge border',
        'BE10' => 'badge border',
    ];
    $cls = $map[$ka] ?? 'badge bg-secondary';
    $style = in_array($ka, ['BE5','BE10'], true)
        ? ' style="background-color:#ede9fe;color:#6d28d9;border-color:#c4b5fd"'
        : '';
    return '<span class="' . $cls . '"' . $style . '>' . h($ka) . '</span>';
}

// Chart data: kat_arch distribution
$chart_kat = [];
foreach ($rows as $r) {
    $k = $r['kat_arch'];
    $chart_kat[$k] = ($chart_kat[$k] ?? 0) + (int)$r['total_spraw'];
}

// Chart data: top 10 by total_spraw
$sorted_rows = $rows;
usort($sorted_rows, fn($a, $b) => (int)$b['total_spraw'] - (int)$a['total_spraw']);
$top10 = array_slice($sorted_rows, 0, 10);

$PAGE_TITLE = 'Raport JRWA';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/jrwa/index.php">Wykaz akt (JRWA)</a></li>
  <li class="breadcrumb-item active">Raport</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-bar-chart text-primary me-2"></i>Raport JRWA</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Statystyki spraw według klas archiwalnych Wykazu Akt</div>
  </div>
  <a href="<?= APP_URL ?>/ezd/jrwa/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Powrót do JRWA
  </a>
</div>

<!-- Wykresy -->
<div class="row g-3 mb-4">
  <div class="col-md-5">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-pie-chart me-1 text-primary"></i>Rozkład spraw wg kategorii archiwalnej</div>
      <div class="card-body d-flex align-items-center justify-content-center" style="min-height:220px">
        <canvas id="chartKat" style="max-height:200px"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-bar-chart me-1 text-primary"></i>Top 10 klas wg liczby spraw</div>
      <div class="card-body d-flex align-items-center" style="min-height:220px">
        <canvas id="chartTop10" style="max-height:200px;width:100%"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Tabela -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.82rem">
      <i class="bi bi-table me-1 text-primary"></i>Zestawienie klas archiwalnych
    </span>
    <span class="text-muted" style="font-size:.75rem"><?= count($rows) ?> klas z przypisanymi kategoriami</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th style="width:90px">Symbol</th>
          <th>Klasa</th>
          <th style="width:90px" class="text-center">Kat. arch.</th>
          <th style="width:110px" class="text-center">Sprawy ogółem</th>
          <th style="width:90px" class="text-center">Otwarte</th>
          <th style="width:90px" class="text-center">Zamknięte</th>
          <th style="width:150px" class="text-center">Śr. czas zamknięcia (dni)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= (int)$r['total_spraw'] ? '' : 'text-muted' ?>"
            onclick="window.location='<?= APP_URL ?>/ezd/sprawy/index.php?jrwa_id=<?= (int)$r['id'] ?>'"
            style="cursor:pointer">
          <td class="font-monospace fw-bold text-primary">
            <a href="<?= APP_URL ?>/ezd/sprawy/index.php?jrwa_id=<?= (int)$r['id'] ?>"
               class="text-decoration-none text-primary"><?= h($r['symbol']) ?></a>
          </td>
          <td><?= h($r['title']) ?></td>
          <td class="text-center"><?= kat_arch_badge($r['kat_arch']) ?></td>
          <td class="text-center fw-semibold"><?= (int)$r['total_spraw'] ?: '<span class="text-muted">0</span>' ?></td>
          <td class="text-center">
            <?php if ((int)$r['open_spraw']): ?>
              <span class="badge bg-primary bg-opacity-15 text-primary border border-primary"><?= (int)$r['open_spraw'] ?></span>
            <?php else: ?>
              <span class="text-muted">0</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ((int)$r['closed_spraw']): ?>
              <span class="badge bg-success bg-opacity-15 text-success border border-success"><?= (int)$r['closed_spraw'] ?></span>
            <?php else: ?>
              <span class="text-muted">0</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($r['avg_days_close'] !== null): ?>
              <span class="font-monospace"><?= h((string)$r['avg_days_close']) ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-5">
          <i class="bi bi-bar-chart" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Brak danych do wyświetlenia.
        </td></tr>
      <?php endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot class="table-secondary fw-bold">
        <tr>
          <td colspan="3" class="text-end pe-3" style="font-size:.8rem">Suma:</td>
          <td class="text-center"><?= $tot_all ?></td>
          <td class="text-center"><?= $tot_open ?></td>
          <td class="text-center"><?= $tot_closed ?></td>
          <td class="text-center text-muted" style="font-weight:400;font-size:.75rem">—</td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
  // Pie chart — kat_arch distribution
  var katLabels = <?= json_encode(array_keys($chart_kat)) ?>;
  var katData   = <?= json_encode(array_values($chart_kat)) ?>;
  var katColors = {
    'A':    '#ef4444',
    'B5':   '#22c55e',
    'B10':  '#06b6d4',
    'B25':  '#f59e0b',
    'B50':  '#94a3b8',
    'Bc':   '#e2e8f0',
    'BE5':  '#8b5cf6',
    'BE10': '#6d28d9',
  };
  var pieColors = katLabels.map(function(k) { return katColors[k] || '#94a3b8'; });

  new Chart(document.getElementById('chartKat'), {
    type: 'pie',
    data: {
      labels: katLabels,
      datasets: [{
        data: katData,
        backgroundColor: pieColors,
        borderWidth: 1,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'right', labels: { font: { size: 11 }, boxWidth: 14 } },
        tooltip: {
          callbacks: {
            label: function(ctx) { return ' ' + ctx.label + ': ' + ctx.parsed + ' spraw'; }
          }
        }
      }
    }
  });

  // Bar chart — top 10
  var barLabels = <?= json_encode(array_map(fn($r) => $r['symbol'], $top10)) ?>;
  var barData   = <?= json_encode(array_map(fn($r) => (int)$r['total_spraw'], $top10)) ?>;
  var barKat    = <?= json_encode(array_map(fn($r) => $r['kat_arch'], $top10)) ?>;
  var barColors = barKat.map(function(k) { return katColors[k] || '#94a3b8'; });

  new Chart(document.getElementById('chartTop10'), {
    type: 'bar',
    data: {
      labels: barLabels,
      datasets: [{
        label: 'Sprawy ogółem',
        data: barData,
        backgroundColor: barColors,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      indexAxis: 'y',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) { return ' ' + ctx.parsed.x + ' spraw'; }
          }
        }
      },
      scales: {
        x: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } } },
        y: { ticks: { font: { size: 11 } } }
      }
    }
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
