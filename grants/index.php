<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/grants.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Granty';

// Filters
$f_status      = trim($_GET['status'] ?? '');
$f_donator     = trim($_GET['donator'] ?? '');
$f_year        = trim($_GET['year'] ?? '');
$f_koordynator = trim($_GET['koordynator_id'] ?? '');

$filters = [];
if ($f_status)      $filters['status']        = $f_status;
if ($f_donator)     $filters['donator']        = $f_donator;
if ($f_year)        $filters['year']           = $f_year;
if ($f_koordynator) $filters['koordynator_id'] = $f_koordynator;

$grants = grants_all($filters);

// Stats
$today = date('Y-m-d');
$in30  = date('Y-m-d', strtotime('+30 days'));
$total_grants  = count($grants);
$active_count  = 0;
$total_amount  = 0.0;
$ending_soon   = 0;
foreach ($grants as $g) {
    if ($g['status'] === 'w realizacji') $active_count++;
    if ($g['kwota_przyznana']) $total_amount += (float)$g['kwota_przyznana'];
    if ($g['data_do'] && $g['data_do'] >= $today && $g['data_do'] <= $in30) $ending_soon++;
}

$users = db_all("SELECT id, name FROM users WHERE role IN ('admin','editor') ORDER BY name");

$grant_statuses = [
    'pomysł'       => ['label' => 'Pomysł',       'class' => 'secondary'],
    'złożony'      => ['label' => 'Złożony',       'class' => 'info'],
    'oczekuje'     => ['label' => 'Oczekuje',      'class' => 'warning'],
    'przyznany'    => ['label' => 'Przyznany',     'class' => 'primary'],
    'w realizacji' => ['label' => 'W realizacji',  'class' => 'success'],
    'rozliczany'   => ['label' => 'Rozliczany',    'class' => 'warning'],
    'zamknięty'    => ['label' => 'Zamknięty',     'class' => 'dark'],
    'odrzucony'    => ['label' => 'Odrzucony',     'class' => 'danger'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-cash-coin text-success"></i> Granty</h4>
  <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Dodaj grant</a>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card shadow-sm text-center">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-primary"><?= $total_grants ?></div>
        <div class="text-muted small">Wszystkich grantów</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm text-center">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-success"><?= $active_count ?></div>
        <div class="text-muted small">W realizacji</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm text-center">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-info"><?= number_format($total_amount, 0, ',', ' ') ?></div>
        <div class="text-muted small">Łączna kwota (PLN)</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm text-center">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-warning"><?= $ending_soon ?></div>
        <div class="text-muted small">Kończy się ≤30 dni</div>
      </div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label mb-1 small">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php foreach ($grant_statuses as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 small">Donator</label>
        <input type="text" name="donator" class="form-control form-control-sm" placeholder="Szukaj…" value="<?= h($f_donator) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small">Rok</label>
        <select name="year" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php for ($y = date('Y') + 1; $y >= 2015; $y--): ?>
          <option value="<?= $y ?>" <?= $f_year == $y ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 small">Koordynator</label>
        <select name="koordynator_id" class="form-select form-select-sm">
          <option value="">— wszyscy —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $f_koordynator == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i> Filtruj</button>
        <?php if ($f_status || $f_donator || $f_year || $f_koordynator): ?>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($grants): ?>
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Nazwa</th>
      <th>Donator</th>
      <th>Status</th>
      <th>Okres</th>
      <th class="text-end">Kwota przyznana</th>
      <th class="text-center">Działań</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($grants as $g):
    $status_info = $grant_statuses[$g['status']] ?? ['label' => h($g['status']), 'class' => 'secondary'];
    $ending = $g['data_do'] && $g['data_do'] >= $today && $g['data_do'] <= $in30;
    $no_actions = ($g['status'] === 'w realizacji' && (int)$g['actions_count'] === 0);
  ?>
  <tr>
    <td>
      <a href="view.php?id=<?= $g['id'] ?>" class="fw-semibold text-decoration-none">
        <?= h($g['nazwa']) ?>
      </a>
      <?php if ($g['nr_wewnetrzny']): ?>
      <span class="badge bg-light text-secondary ms-1 font-monospace" style="font-size:.65rem"><?= h($g['nr_wewnetrzny']) ?></span>
      <?php endif; ?>
      <?php if ($no_actions): ?>
      <span class="ms-1" title="Brak działań w realizacji!">🔴</span>
      <?php endif; ?>
      <?php if ($ending): ?>
      <span class="ms-1" title="Kończy się wkrótce!">🟡</span>
      <?php endif; ?>
    </td>
    <td><?= h($g['donator']) ?></td>
    <td><span class="badge bg-<?= $status_info['class'] ?>"><?= h($status_info['label']) ?></span></td>
    <td class="small text-nowrap">
      <?= date_pl($g['data_od']) ?>
      <?php if ($g['data_do']): ?> – <?= date_pl($g['data_do']) ?><?php endif; ?>
    </td>
    <td class="text-end text-nowrap small">
      <?= $g['kwota_przyznana'] !== null ? money((float)$g['kwota_przyznana'], $g['waluta']) : '—' ?>
    </td>
    <td class="text-center">
      <span class="badge bg-secondary"><?= (int)$g['actions_count'] ?></span>
    </td>
    <td class="text-end text-nowrap">
      <a href="view.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
      <a href="edit.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
      <?php if (is_admin()): ?>
      <?= delete_btn('grants', (int)$g['id'], $g['nazwa'] ?? '#'.$g['id']) ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="alert alert-secondary">Brak grantów spełniających kryteria. <a href="add.php">Dodaj pierwszy grant</a>.</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
