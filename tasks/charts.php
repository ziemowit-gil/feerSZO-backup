<?php
/**
 * tasks/charts.php — Wykresy i statystyki modułu Zadań
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_login();

$uid    = (int)(current_user()['id'] ?? 0);
$ws_id  = isset($_GET['ws']) ? (int)$_GET['ws'] : 0;
$period = in_array($_GET['period'] ?? '', ['7','30','90','365'], true) ? (int)$_GET['period'] : 30;

$ws_and = $ws_id ? " AND t.workspace_id = $ws_id" : '';
$p_and  = " AND t.created_at >= date('now','localtime','-$period days')";

// ── KPI ──────────────────────────────────────────────────────────────────
$kpi_total    = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL$ws_and")['n'] ?? 0);
$kpi_open     = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL$ws_and")['n'] ?? 0);
$kpi_overdue  = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL AND t.due_date IS NOT NULL AND t.due_date < date('now','localtime')$ws_and")['n'] ?? 0);
$kpi_done_p   = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NOT NULL$ws_and$p_and")['n'] ?? 0);
$kpi_cr_p     = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL$ws_and$p_and")['n'] ?? 0);
$kpi_done_pct = $kpi_cr_p > 0 ? round($kpi_done_p / $kpi_cr_p * 100) : 0;
$kpi_cycle_r  = db_one("SELECT AVG(julianday(completed_at,'localtime') - julianday(created_at,'localtime')) AS avg FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NOT NULL$ws_and$p_and");
$kpi_cycle    = $kpi_cycle_r && $kpi_cycle_r['avg'] !== null ? round((float)$kpi_cycle_r['avg'], 1) : null;
$kpi_due7     = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL AND t.due_date BETWEEN date('now','localtime') AND date('now','localtime','+7 days')$ws_and")['n'] ?? 0);

// ── Status distribution ───────────────────────────────────────────────────
$st_open     = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) = 0$ws_and")['n'] ?? 0);
$st_taken    = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) > 0 AND (t.due_date IS NULL OR t.due_date >= date('now','localtime'))$ws_and")['n'] ?? 0);
$st_done     = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NOT NULL$ws_and")['n'] ?? 0);
$st_overdue  = $kpi_overdue;
$st_total    = max(1, $st_open + $st_taken + $st_done + $st_overdue);

// ── Priority distribution (open tasks) ───────────────────────────────────
$pri_rows = db_all("SELECT priority, COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL$ws_and GROUP BY priority ORDER BY priority");
$pri = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($pri_rows as $r) $pri[(int)$r['priority']] = (int)$r['n'];

// ── Weekly trend (last 8 weeks) ───────────────────────────────────────────
$trend_labels = $trend_cr = $trend_done = [];
for ($i = 7; $i >= 0; $i--) {
    $mon = date('Y-m-d', strtotime("monday -$i weeks"));
    $sun = ($i === 0) ? date('Y-m-d') : date('Y-m-d', strtotime("sunday -$i weeks"));
    $trend_labels[] = date('d.m', strtotime($mon));
    $trend_cr[]   = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND date(t.created_at,'localtime') BETWEEN '$mon' AND '$sun'$ws_and")['n'] ?? 0);
    $trend_done[] = (int)(db_one("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NOT NULL AND date(t.completed_at,'localtime') BETWEEN '$mon' AND '$sun'$ws_and")['n'] ?? 0);
}

// ── Workspace completion ──────────────────────────────────────────────────
$ws_rows = db_all("
    SELECT tw.name, tw.color,
        COUNT(t.id) AS total,
        SUM(CASE WHEN t.completed_at IS NOT NULL THEN 1 ELSE 0 END) AS done
    FROM task_workspaces tw
    LEFT JOIN tasks t ON t.workspace_id = tw.id AND t.deleted_at IS NULL
    WHERE tw.is_active = 1
    GROUP BY tw.id
    HAVING total > 0
    ORDER BY total DESC
    LIMIT 10");

// ── Area distribution (open tasks) ───────────────────────────────────────
$area_rows = db_all("
    SELECT ta.name, COUNT(t.id) AS n
    FROM task_areas ta
    JOIN tasks t ON t.area_id = ta.id AND t.deleted_at IS NULL AND t.completed_at IS NULL
    WHERE ta.is_active = 1
    GROUP BY ta.id
    ORDER BY n DESC
    LIMIT 8");

$PAGE_TITLE      = 'Wykresy — Zadania';
$TASKS_WS_ID     = $ws_id;
$TASKS_BREADCRUMB = 'Wykresy';
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style>
/* ── viz-root custom properties ─────────────────────────────────────────── */
.viz-root {
  --vz-s1: #2a78d6; --vz-s2: #eb6834; --vz-s3: #1baf7a; --vz-s4: #eda100;
  --vz-seq1: #86b6ef; --vz-seq2: #5598e7; --vz-seq3: #3987e5; --vz-seq4: #256abf;
  --vz-stat-good: #0ca30c; --vz-stat-crit: #d03b3b;
  --vz-ink: #0b0b0b; --vz-ink2: #52514e; --vz-muted: #898781;
  --vz-grid: #e1e0d9; --vz-surface: #fcfcfb;
}
@media (prefers-color-scheme: dark) {
  :root:where(:not([data-theme="light"])) .viz-root {
    --vz-s1: #3987e5; --vz-s2: #d95926; --vz-s3: #199e70; --vz-s4: #c98500;
    --vz-seq1: #3987e5; --vz-seq2: #2a78d6; --vz-seq3: #256abf; --vz-seq4: #184f95;
    --vz-ink: #fff; --vz-ink2: #c3c2b7; --vz-muted: #898781;
    --vz-grid: #2c2c2a; --vz-surface: #1a1a19;
  }
}
:root[data-theme="dark"] .viz-root {
  --vz-s1: #3987e5; --vz-s2: #d95926; --vz-s3: #199e70; --vz-s4: #c98500;
  --vz-seq1: #3987e5; --vz-seq2: #2a78d6; --vz-seq3: #256abf; --vz-seq4: #184f95;
  --vz-ink: #fff; --vz-ink2: #c3c2b7; --vz-muted: #898781;
  --vz-grid: #2c2c2a; --vz-surface: #1a1a19;
}

/* ── layout ────────────────────────────────────────────── */
.ch-wrap    { max-width: 1140px; padding: 1.5rem 1rem 3rem; }
.ch-filter  { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.ch-pill    { font-size: .78rem; padding: .28rem .75rem; border-radius: 20px;
              border: 1px solid #e2e8f0; background: #fff; color: #475569;
              cursor: pointer; text-decoration: none; transition: all .12s; }
.ch-pill:hover, .ch-pill.active { border-color: var(--tsk-green); color: var(--tsk-green); background: #ecfdf5; }
.ch-pill.active { font-weight: 600; }

/* ── KPI tiles ─────────────────────────────────────────── */
.kc-tile    { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;
              padding: 1rem 1.25rem; }
.kc-val     { font-size: 1.75rem; font-weight: 700; line-height: 1.1; color: #0f172a; }
.kc-lbl     { font-size: .75rem; color: #64748b; margin-top: .2rem; }
.kc-delta   { font-size: .75rem; margin-top: .35rem; }
.kc-tile.is-red   .kc-val { color: #dc2626; }
.kc-tile.is-green .kc-val { color: #059669; }
.kc-tile.is-amber .kc-val { color: #d97706; }

/* ── chart cards ───────────────────────────────────────── */
.ch-card    { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;
              overflow: hidden; }
.ch-card-hd { padding: .75rem 1.25rem; border-bottom: 1px solid #f1f5f9;
              font-size: .84rem; font-weight: 700; color: #1e293b;
              display: flex; align-items: center; gap: .5rem; }
.ch-card-hd .ch-sub { font-weight: 400; color: #94a3b8; font-size: .75rem; margin-left: auto; }
.ch-card-bd { padding: 1.25rem; }
.ch-canvas  { width: 100% !important; }

/* ── status bar ────────────────────────────────────────── */
.st-bar     { display: flex; height: 28px; border-radius: 6px; overflow: hidden; gap: 2px; }
.st-seg     { transition: flex .4s ease; display: flex; align-items: center;
              justify-content: center; font-size: .65rem; font-weight: 700;
              color: #fff; min-width: 0; overflow: hidden; white-space: nowrap; }
.st-legend  { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-top: .85rem; font-size: .78rem; color: #374151; }
.st-dot     { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }

/* ── ws bars ───────────────────────────────────────────── */
.ws-row     { display: flex; align-items: center; gap: .6rem; margin-bottom: .6rem; font-size: .8rem; }
.ws-name    { min-width: 110px; max-width: 130px; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ws-track   { flex: 1; height: 10px; background: #f1f5f9; border-radius: 5px; overflow: hidden; }
.ws-fill    { height: 100%; border-radius: 5px; background: var(--vz-s1); transition: width .5s ease; }
.ws-pct     { width: 36px; text-align: right; color: #64748b; }
.ws-cnt     { width: 36px; text-align: right; color: #94a3b8; font-size: .72rem; }
</style>

<div class="ch-wrap viz-root mx-auto">

  <!-- Header + filter ────────────────────────────────────── -->
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
    <div>
      <h1 class="h5 fw-bold mb-0" style="color:#0f172a">
        <i class="bi bi-bar-chart-line me-2" style="color:var(--tsk-green)"></i>Wykresy i statystyki
      </h1>
      <div style="font-size:.8rem;color:#64748b;margin-top:.2rem">
        <?= $ws_id ? ('Obszar: <strong>' . h((db_one("SELECT name FROM task_workspaces WHERE id=$ws_id") ?: [])['name'] ?? '—') . '</strong> · ') : '' ?>
        Ostatnie <?= $period ?> dni
      </div>
    </div>
    <div class="ch-filter">
      <span style="font-size:.75rem;color:#94a3b8">Okres:</span>
      <?php foreach ([7=>'7 dni',30=>'30 dni',90=>'90 dni',365=>'Rok'] as $d => $lbl): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['period' => $d])) ?>"
         class="ch-pill <?= $period === $d ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- KPI row ────────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="kc-tile">
        <div class="kc-val"><?= $kpi_total ?></div>
        <div class="kc-lbl"><i class="bi bi-list-task me-1"></i>Wszystkich zadań</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kc-tile <?= $kpi_done_pct >= 70 ? 'is-green' : ($kpi_done_pct >= 40 ? '' : 'is-amber') ?>">
        <div class="kc-val"><?= $kpi_done_pct ?>%</div>
        <div class="kc-lbl"><i class="bi bi-check-circle me-1"></i>Ukończonych (<?= $period ?>d)</div>
        <div class="kc-delta" style="color:#94a3b8"><?= $kpi_done_p ?> z <?= $kpi_cr_p ?> nowych</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kc-tile <?= $kpi_cycle !== null && $kpi_cycle > 14 ? 'is-amber' : 'is-green' ?>">
        <div class="kc-val"><?= $kpi_cycle !== null ? $kpi_cycle : '—' ?><?= $kpi_cycle !== null ? '<span style="font-size:1rem;font-weight:400"> dni</span>' : '' ?></div>
        <div class="kc-lbl"><i class="bi bi-hourglass-split me-1"></i>Śr. czas realizacji</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kc-tile <?= $kpi_overdue > 0 ? 'is-red' : 'is-green' ?>">
        <div class="kc-val"><?= $kpi_overdue ?></div>
        <div class="kc-lbl"><i class="bi bi-exclamation-triangle me-1"></i>Po terminie</div>
        <?php if ($kpi_due7 > 0): ?>
        <div class="kc-delta" style="color:#d97706"><i class="bi bi-clock me-1"></i><?= $kpi_due7 ?> terminuje w 7 dni</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Row 1: Status + Priority ───────────────────────────── -->
  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <div class="ch-card h-100">
        <div class="ch-card-hd">
          <i class="bi bi-pie-chart-fill" style="color:#2563eb"></i>Status zadań
          <span class="ch-sub">wszystkie zadania</span>
        </div>
        <div class="ch-card-bd">
          <div class="st-bar" role="img" aria-label="Rozkład statusów">
            <?php
            $segs = [
                ['val'=>$st_open,    'color'=>'#2a78d6', 'label'=>'Wolne'],
                ['val'=>$st_taken,   'color'=>'#1baf7a', 'label'=>'Przydzielone'],
                ['val'=>$st_done,    'color'=>'#898781', 'label'=>'Ukończone'],
                ['val'=>$st_overdue, 'color'=>'#d03b3b', 'label'=>'Po terminie'],
            ];
            foreach ($segs as $sg):
                $pct = round($sg['val'] / $st_total * 100);
                if ($pct < 1 && $sg['val'] === 0) continue;
            ?>
            <div class="st-seg"
                 style="flex:<?= max(1, $pct) ?>;background:<?= $sg['color'] ?>"
                 title="<?= $sg['label'] ?>: <?= $sg['val'] ?>">
              <?= $pct >= 8 ? $pct . '%' : '' ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="st-legend" role="list">
            <?php foreach ($segs as $sg): ?>
            <div class="d-flex align-items-center gap-1" role="listitem">
              <div class="st-dot" style="background:<?= $sg['color'] ?>" aria-hidden="true"></div>
              <span><?= $sg['label'] ?></span>
              <strong><?= $sg['val'] ?></strong>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- table view (a11y) -->
          <details class="mt-3" style="font-size:.75rem;color:#94a3b8">
            <summary style="cursor:pointer">Pokaż tabelę</summary>
            <table class="table table-sm table-bordered mt-2" style="font-size:.78rem">
              <thead><tr><th>Status</th><th>Liczba</th><th>%</th></tr></thead>
              <tbody>
              <?php foreach ($segs as $sg): ?>
              <tr><td><?= $sg['label'] ?></td><td><?= $sg['val'] ?></td><td><?= round($sg['val']/$st_total*100) ?>%</td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        </div>
      </div>
    </div>

    <div class="col-md-7">
      <div class="ch-card h-100">
        <div class="ch-card-hd">
          <i class="bi bi-bar-chart-fill" style="color:#059669"></i>Priorytety (otwarte zadania)
          <span class="ch-sub">4 poziomy</span>
        </div>
        <div class="ch-card-bd">
          <canvas id="chPriority" class="ch-canvas" height="160" aria-label="Wykres priorytetów"></canvas>
          <details class="mt-3" style="font-size:.75rem;color:#94a3b8">
            <summary style="cursor:pointer">Pokaż tabelę</summary>
            <table class="table table-sm table-bordered mt-2" style="font-size:.78rem">
              <thead><tr><th>Priorytet</th><th>Zadań</th></tr></thead>
              <tbody>
              <?php foreach ([1=>'Niski',2=>'Normalny',3=>'Wysoki',4=>'Krytyczny'] as $p=>$lbl): ?>
              <tr><td><?= $lbl ?></td><td><?= $pri[$p] ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        </div>
      </div>
    </div>
  </div>

  <!-- Row 2: Trend ───────────────────────────────────────── -->
  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="ch-card">
        <div class="ch-card-hd">
          <i class="bi bi-graph-up" style="color:#7c3aed"></i>Trend tygodniowy
          <span class="ch-sub">ostatnie 8 tygodni — nowe vs ukończone</span>
        </div>
        <div class="ch-card-bd">
          <canvas id="chTrend" class="ch-canvas" height="90" aria-label="Trend tygodniowy zadań"></canvas>
          <details class="mt-3" style="font-size:.75rem;color:#94a3b8">
            <summary style="cursor:pointer">Pokaż tabelę</summary>
            <table class="table table-sm table-bordered mt-2" style="font-size:.78rem">
              <thead><tr><th>Tydzień</th><th>Nowe</th><th>Ukończone</th></tr></thead>
              <tbody>
              <?php for ($i=0;$i<8;$i++): ?>
              <tr><td><?= $trend_labels[$i] ?></td><td><?= $trend_cr[$i] ?></td><td><?= $trend_done[$i] ?></td></tr>
              <?php endfor; ?>
              </tbody>
            </table>
          </details>
        </div>
      </div>
    </div>
  </div>

  <!-- Row 3: Workspace bars + Areas ─────────────────────── -->
  <div class="row g-3">
    <div class="col-md-6">
      <div class="ch-card h-100">
        <div class="ch-card-hd">
          <i class="bi bi-grid-3x3-gap-fill" style="color:#d97706"></i>Ukończenie — obszary robocze
        </div>
        <div class="ch-card-bd">
          <?php if (!$ws_rows): ?>
          <div class="text-center py-3 text-muted" style="font-size:.82rem">Brak danych</div>
          <?php else: ?>
          <?php foreach ($ws_rows as $ws): $pct = $ws['total'] > 0 ? round($ws['done']/$ws['total']*100) : 0; ?>
          <div class="ws-row">
            <div class="ws-name" title="<?= h($ws['name']) ?>"><?= h($ws['name']) ?></div>
            <div class="ws-track" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
              <div class="ws-fill" style="width:<?= $pct ?>%;background:<?= h($ws['color'] ?: '#2a78d6') ?>"></div>
            </div>
            <div class="ws-pct"><?= $pct ?>%</div>
            <div class="ws-cnt"><?= (int)$ws['done'] ?>/<?= (int)$ws['total'] ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="ch-card h-100">
        <div class="ch-card-hd">
          <i class="bi bi-layers-fill" style="color:#0891b2"></i>Zadania otwarte wg obszaru
          <span class="ch-sub">top 8</span>
        </div>
        <div class="ch-card-bd">
          <?php if (!$area_rows): ?>
          <div class="text-center py-3 text-muted" style="font-size:.82rem">Brak danych</div>
          <?php else: ?>
          <?php
          $area_max = max(1, array_reduce($area_rows, fn($m,$r)=>max($m,(int)$r['n']), 0));
          foreach ($area_rows as $ar): $pct = round((int)$ar['n']/$area_max*100); ?>
          <div class="ws-row">
            <div class="ws-name" title="<?= h($ar['name']) ?>"><?= h($ar['name']) ?></div>
            <div class="ws-track">
              <div class="ws-fill" style="width:<?= $pct ?>%;background:#1baf7a"></div>
            </div>
            <div class="ws-cnt" style="width:28px"><?= (int)$ar['n'] ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div><!-- .ch-wrap -->

<script>
(function() {
var s = document.createElement('script');
s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js';
s.onload = initCharts;
document.head.appendChild(s);

var LABELS = <?= json_encode($trend_labels, JSON_UNESCAPED_UNICODE) ?>;
var CR     = <?= json_encode($trend_cr) ?>;
var DONE   = <?= json_encode($trend_done) ?>;
var PRI    = [<?= implode(',', array_values($pri)) ?>];

function css(v) { return getComputedStyle(document.documentElement).getPropertyValue(v).trim() || v; }

function initCharts() {
  var dark = document.documentElement.dataset.theme === 'dark'
    || (!document.documentElement.dataset.theme && window.matchMedia('(prefers-color-scheme: dark)').matches);

  var grid   = dark ? '#2c2c2a' : '#e1e0d9';
  var ink2   = dark ? '#c3c2b7' : '#52514e';
  var s1     = dark ? '#3987e5' : '#2a78d6';
  var s3     = dark ? '#199e70' : '#1baf7a';

  Chart.defaults.font.family = 'system-ui,-apple-system,"Segoe UI",sans-serif';
  Chart.defaults.font.size   = 11;

  // Priority column chart
  new Chart(document.getElementById('chPriority'), {
    type: 'bar',
    data: {
      labels: ['Niski','Normalny','Wysoki','Krytyczny'],
      datasets: [{
        label: 'Zadań',
        data: PRI,
        backgroundColor: ['#86b6ef','#5598e7','#256abf','#104281'],
        borderRadius: 4,
        borderSkipped: 'bottom',
        borderWidth: 0,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: { label: function(c){ return c.parsed.y + ' zadań'; } }
        }
      },
      scales: {
        x: { grid: { color: grid, lineWidth: 1 }, ticks: { color: ink2 } },
        y: {
          grid: { color: grid, lineWidth: 1 }, ticks: { color: ink2, stepSize: 1 },
          beginAtZero: true
        }
      }
    }
  });

  // Trend line chart
  new Chart(document.getElementById('chTrend'), {
    type: 'line',
    data: {
      labels: LABELS,
      datasets: [
        {
          label: 'Nowe zadania',
          data: CR,
          borderColor: s1, backgroundColor: s1 + '22',
          borderWidth: 2, pointRadius: 4, pointHitRadius: 16,
          tension: 0.35, fill: false,
        },
        {
          label: 'Ukończone',
          data: DONE,
          borderColor: s3, backgroundColor: s3 + '22',
          borderWidth: 2, pointRadius: 4, pointHitRadius: 16,
          tension: 0.35, fill: false,
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'top', labels: { color: ink2, boxWidth: 12, padding: 16 } },
        tooltip: { mode: 'index', intersect: false }
      },
      scales: {
        x: { grid: { color: grid }, ticks: { color: ink2 } },
        y: {
          grid: { color: grid }, ticks: { color: ink2, stepSize: 1 },
          beginAtZero: true
        }
      }
    }
  });
}
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
