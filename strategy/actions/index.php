<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/grants.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Działania';

$f_status      = trim($_GET['status'] ?? '');
$f_typ         = trim($_GET['typ'] ?? '');
$f_koordynator = trim($_GET['koordynator_id'] ?? '');
$f_grant       = trim($_GET['grant_id'] ?? '');
$f_data_od     = trim($_GET['data_od'] ?? '');
$f_data_do     = trim($_GET['data_do'] ?? '');

$filters = [];
if ($f_status)      $filters['status']        = $f_status;
if ($f_typ)         $filters['typ']           = $f_typ;
if ($f_koordynator) $filters['koordynator_id'] = $f_koordynator;
if ($f_grant)       $filters['grant_id']      = $f_grant;
if ($f_data_od)     $filters['data_od']       = $f_data_od;
if ($f_data_do)     $filters['data_do']       = $f_data_do;

$actions = actions_all($filters);
$users   = db_all("SELECT id, name FROM users WHERE role IN ('admin','editor') ORDER BY name");
$grants  = db_all("SELECT id, nazwa, donator FROM grants ORDER BY nazwa");

$today = date('Y-m-d');
$in14  = date('Y-m-d', strtotime('+14 days'));

$action_statuses = [
    'planowane'       => ['label' => 'Planowane',       'class' => 'secondary'],
    'w_przygotowaniu' => ['label' => 'W przygotowaniu', 'class' => 'info'],
    'w_trakcie'       => ['label' => 'W trakcie',       'class' => 'success'],
    'zawieszone'      => ['label' => 'Zawieszone',      'class' => 'warning'],
    'zakończone'      => ['label' => 'Zakończone',      'class' => 'dark'],
    'anulowane'       => ['label' => 'Anulowane',       'class' => 'danger'],
];

$action_types = [
    'warsztat'             => ['label' => 'Warsztat',             'icon' => 'bi-easel'],
    'konferencja'          => ['label' => 'Konferencja',          'icon' => 'bi-mic'],
    'kampania'             => ['label' => 'Kampania',             'icon' => 'bi-megaphone'],
    'wsparcie_indywidualne'=> ['label' => 'Wsparcie indyw.',      'icon' => 'bi-person-heart'],
    'szkolenie'            => ['label' => 'Szkolenie',            'icon' => 'bi-mortarboard'],
    'spotkanie'            => ['label' => 'Spotkanie',            'icon' => 'bi-people'],
    'inne'                 => ['label' => 'Inne',                 'icon' => 'bi-three-dots'],
];

include dirname(__DIR__) . '/includes/header_strategy.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-calendar-event text-primary"></i> Działania</h4>
  <div class="d-flex gap-2">
    <div class="btn-group btn-group-sm" role="group">
      <button type="button" class="btn btn-outline-secondary view-btn" data-view="lista" id="btn-lista">
        <i class="bi bi-list-ul"></i> Lista
      </button>
      <button type="button" class="btn btn-outline-secondary view-btn" data-view="kalendarz" id="btn-kalendarz">
        <i class="bi bi-calendar3"></i> Kalendarz
      </button>
    </div>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Dodaj działanie</a>
  </div>
</div>

<!-- Filter bar (only in list view) -->
<div id="list-filters" class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label mb-1 small">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php foreach ($action_statuses as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small">Typ</label>
        <select name="typ" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php foreach ($action_types as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $f_typ === $k ? 'selected' : '' ?>><?= h($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small">Koordynator</label>
        <select name="koordynator_id" class="form-select form-select-sm">
          <option value="">— wszyscy —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $f_koordynator == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 small">Grant</label>
        <select name="grant_id" id="grant-filter-select" class="form-select form-select-sm">
          <option value="">— wszystkie granty —</option>
          <?php foreach ($grants as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $f_grant == $g['id'] ? 'selected' : '' ?>><?= h($g['nazwa']) ?> (<?= h($g['donator']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label mb-1 small">Od</label>
        <input type="text" name="data_od" data-flatpickr class="form-control form-control-sm" value="<?= h($f_data_od) ?>" placeholder="RRRR-MM-DD">
      </div>
      <div class="col-md-1">
        <label class="form-label mb-1 small">Do</label>
        <input type="text" name="data_do" data-flatpickr class="form-control form-control-sm" value="<?= h($f_data_do) ?>" placeholder="RRRR-MM-DD">
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($f_status || $f_typ || $f_koordynator || $f_grant || $f_data_od || $f_data_do): ?>
        <a href="index.php" class="btn btn-outline-secondary btn-sm" title="Wyczyść">×</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- LIST VIEW -->
<div id="view-lista">
<?php if ($actions): ?>
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Nazwa</th>
      <th>Typ</th>
      <th>Koordynator</th>
      <th>Status</th>
      <th>Okres</th>
      <th>Granty</th>
      <th>Wskaźniki</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($actions as $a):
    $ast  = $action_statuses[$a['status']] ?? ['label' => $a['status'], 'class' => 'secondary'];
    $atyp = $action_types[$a['typ']] ?? ['label' => $a['typ'], 'icon' => 'bi-three-dots'];
    $ending = $a['data_do'] && $a['data_do'] >= $today && $a['data_do'] <= $in14;
    $ag = action_grants_for((int)$a['id']);
    $no_grant = empty($ag) && !$a['wlasne_dzialanie'];
    $indicators = grant_action_indicators_for((int)$a['id']);
    $ind_behind = false;
    foreach ($indicators as $ind) {
      if ($ind['wartosc_planowana'] > 0 && $ind['wartosc_realizowana'] < $ind['wartosc_planowana'] * 0.5) $ind_behind = true;
    }
  ?>
  <tr>
    <td>
      <a href="view.php?id=<?= $a['id'] ?>" class="fw-semibold text-decoration-none"><?= h($a['nazwa']) ?></a>
      <?php if ($a['wlasne_dzialanie']): ?>
      <span class="badge bg-warning text-dark ms-1" title="Działanie własne"><i class="bi bi-star-fill"></i></span>
      <?php endif; ?>
      <?php if ($no_grant): ?><span class="ms-1" title="Brak grantu!">🔴</span><?php endif; ?>
      <?php if ($ending): ?><span class="ms-1" title="Kończy się wkrótce!">🟡</span><?php endif; ?>
      <?php if ($ind_behind): ?><span class="ms-1" title="Wskaźniki poniżej planu!">🟡</span><?php endif; ?>
    </td>
    <td class="small text-nowrap">
      <i class="bi <?= $atyp['icon'] ?>"></i> <?= h($atyp['label']) ?>
    </td>
    <td class="small"><?= $a['koordynator_name'] ? h($a['koordynator_name']) : '<span class="text-muted">—</span>' ?></td>
    <td><span class="badge bg-<?= $ast['class'] ?>"><?= h($ast['label']) ?></span></td>
    <td class="small text-nowrap"><?= date_pl($a['data_od']) ?><?= $a['data_do'] ? ' – '.date_pl($a['data_do']) : '' ?></td>
    <td>
      <?php foreach ($ag as $g): ?>
      <a href="<?= APP_URL ?>/grants/view.php?id=<?= $g['grant_id'] ?>" class="badge bg-light text-dark border text-decoration-none me-1" style="font-size:.7rem"><?= h($g['nazwa']) ?></a>
      <?php endforeach; ?>
    </td>
    <td>
      <?php if ($indicators):
        $total_plan = array_sum(array_column($indicators, 'wartosc_planowana'));
        $total_real = array_sum(array_column($indicators, 'wartosc_realizowana'));
        $pct = $total_plan > 0 ? min(100, round($total_real / $total_plan * 100)) : 0;
      ?>
      <div class="d-flex align-items-center gap-1" title="<?= $total_real ?> / <?= $total_plan ?>">
        <div class="progress flex-grow-1" style="height:6px;min-width:50px">
          <div class="progress-bar bg-<?= $pct >= 100 ? 'success' : ($pct >= 50 ? 'info' : 'warning') ?>"
               style="width:<?= $pct ?>%"></div>
        </div>
        <small class="text-muted"><?= $pct ?>%</small>
      </div>
      <?php else: ?>
      <span class="text-muted small">—</span>
      <?php endif; ?>
    </td>
    <td class="text-end text-nowrap">
      <a href="view.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
      <a href="edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
      <?php if (is_admin() || (can_edit() && (int)($a['created_by'] ?? 0) === (int)current_user()['id'])): ?>
      <?= delete_btn('actions', (int)$a['id'], $a['nazwa'] ?? '#'.$a['id']) ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="alert alert-secondary">Brak działań spełniających kryteria. <a href="add.php">Dodaj pierwsze działanie</a>.</div>
<?php endif; ?>
</div>

<!-- CALENDAR VIEW -->
<div id="view-kalendarz" style="display:none">
  <div class="card shadow-sm">
    <div class="card-body">
      <div id="fc-calendar"></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/pl.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
flatpickr('[data-flatpickr]', {locale: 'pl', dateFormat: 'Y-m-d', allowInput: true});
new TomSelect('#grant-filter-select', {allowEmptyOption: true});

// View toggle
var currentView = localStorage.getItem('actions_view') || 'lista';
var calendarInitialized = false;
var fcCalendar;

function showView(v) {
    currentView = v;
    localStorage.setItem('actions_view', v);
    document.getElementById('view-lista').style.display = v === 'lista' ? '' : 'none';
    document.getElementById('view-kalendarz').style.display = v === 'kalendarz' ? '' : 'none';
    document.getElementById('list-filters').style.display = v === 'lista' ? '' : 'none';
    document.querySelectorAll('.view-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.view === v);
    });
    if (v === 'kalendarz' && !calendarInitialized) {
        calendarInitialized = true;
        fcCalendar = new FullCalendar.Calendar(document.getElementById('fc-calendar'), {
            initialView: 'dayGridMonth',
            locale: 'pl',
            height: 'auto',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            events: '<?= APP_URL ?>/strategy/actions/calendar.php',
            eventClick: function(info) { window.location = '<?= APP_URL ?>/strategy/actions/view.php?id=' + info.event.id; },
            eventDidMount: function(info) {
                info.el.setAttribute('title', info.event.title);
            }
        });
        fcCalendar.render();
    }
}

document.getElementById('btn-lista').addEventListener('click', function() { showView('lista'); });
document.getElementById('btn-kalendarz').addEventListener('click', function() { showView('kalendarz'); });
showView(currentView);
</script>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>
