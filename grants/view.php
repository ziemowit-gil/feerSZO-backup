<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/grants.php';

require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? 0);
$grant = grant_by_id($id);
if (!$grant) {
    flash_set('danger', 'Grant nie istnieje.');
    header('Location: ' . APP_URL . '/grants/index.php');
    exit;
}

$PAGE_TITLE = h($grant['nazwa']);

// Budżet grantu na działania (modules/strategy_actions — kwota przyznana kontra kwoty przypisane działaniom)
require_once dirname(__DIR__) . '/modules/strategy_actions/logic/ActionsGrants.php';
$budget = ActionsGrants::budget($id);
$obszary = json_decode($grant['obszar_tematyczny'] ?? '[]', true) ?: [];

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
$status_info = $grant_statuses[$grant['status']] ?? ['label' => $grant['status'], 'class' => 'secondary'];

$action_statuses = [
    'planowane'       => ['label' => 'Planowane',       'class' => 'secondary'],
    'w_przygotowaniu' => ['label' => 'W przygotowaniu', 'class' => 'info'],
    'w_trakcie'       => ['label' => 'W trakcie',       'class' => 'success'],
    'zawieszone'      => ['label' => 'Zawieszone',      'class' => 'warning'],
    'zakończone'      => ['label' => 'Zakończone',      'class' => 'dark'],
    'anulowane'       => ['label' => 'Anulowane',       'class' => 'danger'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-cash-coin text-success"></i>
    <?= h($grant['nazwa']) ?>
    <span class="badge bg-<?= $status_info['class'] ?> ms-2"><?= h($status_info['label']) ?></span>
  </h4>
  <div class="d-flex gap-2">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Edytuj</a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <!-- Left column: details -->
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Szczegóły grantu</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Donator</dt>
          <dd class="col-sm-8"><?= h($grant['donator']) ?></dd>

          <?php if ($grant['program']): ?>
          <dt class="col-sm-4">Program</dt>
          <dd class="col-sm-8"><?= h($grant['program']) ?></dd>
          <?php endif; ?>

          <?php if ($grant['nr_umowy']): ?>
          <dt class="col-sm-4">Nr umowy</dt>
          <dd class="col-sm-8 font-monospace"><?= h($grant['nr_umowy']) ?></dd>
          <?php endif; ?>

          <?php if ($grant['nr_wewnetrzny']): ?>
          <dt class="col-sm-4">Nr wewnętrzny</dt>
          <dd class="col-sm-8 font-monospace"><?= h($grant['nr_wewnetrzny']) ?></dd>
          <?php endif; ?>

          <dt class="col-sm-4">Koordynator</dt>
          <dd class="col-sm-8"><?= $grant['koordynator_name'] ? h($grant['koordynator_name']) : '<span class="text-muted">—</span>' ?></dd>

          <?php if ($obszary): ?>
          <dt class="col-sm-4">Obszary tematyczne</dt>
          <dd class="col-sm-8">
            <?php foreach ($obszary as $o): ?>
            <span class="badge bg-light text-dark border me-1"><?= h($o) ?></span>
            <?php endforeach; ?>
          </dd>
          <?php endif; ?>

          <?php if ($grant['cel_strategiczny']): ?>
          <dt class="col-sm-4">Cel strategiczny</dt>
          <dd class="col-sm-8"><?= nl2br(h($grant['cel_strategiczny'])) ?></dd>
          <?php endif; ?>

          <?php if ($grant['opis']): ?>
          <dt class="col-sm-4">Opis</dt>
          <dd class="col-sm-8"><?= nl2br(h($grant['opis'])) ?></dd>
          <?php endif; ?>

          <?php if ($grant['uwagi']): ?>
          <dt class="col-sm-4">Uwagi</dt>
          <dd class="col-sm-8"><?= nl2br(h($grant['uwagi'])) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <!-- Right column: finances & dates -->
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-currency-euro"></i> Finansowanie</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-6">Wnioskowana</dt>
          <dd class="col-6 text-end"><?= $grant['kwota_wnioskowana'] !== null ? money((float)$grant['kwota_wnioskowana'], $grant['waluta']) : '—' ?></dd>
          <dt class="col-6">Przyznana</dt>
          <dd class="col-6 text-end fw-bold text-success"><?= $grant['kwota_przyznana'] !== null ? money((float)$grant['kwota_przyznana'], $grant['waluta']) : '—' ?></dd>
          <dt class="col-6">Waluta</dt>
          <dd class="col-6 text-end"><?= h($grant['waluta']) ?></dd>
        </dl>
      </div>
    </div>
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-calendar3"></i> Harmonogram</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-6">Złożenie</dt>
          <dd class="col-6 text-end"><?= date_pl($grant['data_zlozenia']) ?></dd>
          <dt class="col-6">Realizacja od</dt>
          <dd class="col-6 text-end"><?= date_pl($grant['data_od']) ?></dd>
          <dt class="col-6">Realizacja do</dt>
          <dd class="col-6 text-end"><?= date_pl($grant['data_do']) ?></dd>
          <dt class="col-6">Rozliczenie</dt>
          <dd class="col-6 text-end"><?= date_pl($grant['data_rozliczenia']) ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<?php if ($budget): $bpct = min(100, (int)($budget['pct'] ?? 0)); ?>
<!-- Budżet grantu na działania -->
<div class="card shadow-sm mb-3" aria-labelledby="grant-budget-h">
  <div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2 align-items-baseline">
      <h5 class="mb-0" id="grant-budget-h"><i class="bi bi-pie-chart text-success"></i> Budżet na działania</h5>
      <?php if ($budget['budget'] !== null): ?>
      <span class="<?= $budget['over'] ? 'text-danger fw-bold' : 'text-muted' ?>">
        rozdysponowano <?= money($budget['allocated'], $budget['waluta']) ?> z <?= money($budget['budget'], $budget['waluta']) ?><?= $budget['estimated'] ? ' (kwota wnioskowana)' : '' ?>
        · <?= $budget['over'] ? 'przekroczono o ' . money(-$budget['remaining'], $budget['waluta']) : 'zostaje ' . money($budget['remaining'], $budget['waluta']) ?>
      </span>
      <?php else: ?>
      <span class="text-muted">Uzupełnij kwotę przyznaną, żeby liczyć budżet. Przypisano działaniom: <?= money($budget['allocated'], $budget['waluta']) ?>.</span>
      <?php endif; ?>
    </div>
    <?php if ($budget['budget'] !== null): ?>
    <div class="progress mt-2" style="height:10px" role="progressbar" aria-label="Rozdysponowanie budżetu" aria-valuenow="<?= (int)$budget['pct'] ?>" aria-valuemin="0" aria-valuemax="100">
      <div class="progress-bar <?= $budget['over'] ? 'bg-danger' : 'bg-success' ?>" style="width:<?= $bpct ?>%"></div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Powiązane działania (modules/strategy_actions: podpinanie, postęp, eksport) -->
<?php $grantActions = ActionsGrants::actionsOf($id); $candidates = ActionsGrants::candidates($id); ?>
<div id="dzialania" class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-calendar-event text-primary"></i> Powiązane działania (<?= count($grantActions) ?>)</h5>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($grantActions): ?>
    <a href="<?= APP_URL ?>/dzialania/granty/<?= $id ?>/eksport?format=xlsx" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Zestawienie do sprawozdania</a>
    <a href="<?= APP_URL ?>/dzialania/granty/<?= $id ?>/eksport?format=csv" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-csv"></i> CSV</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/dzialania/nowe?grant_id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> Nowe działanie z tym grantem</a>
  </div>
</div>

<?php if ($grantActions): ?>
<div class="card shadow-sm mb-3">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Nazwa działania</th><th>Status</th><th>Okres</th>
      <th class="text-end">Udział %</th><th class="text-end">Kwota</th>
      <th style="min-width:120px">Wskaźniki</th><th>Etapy</th><th><span class="visually-hidden">Akcje</span></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($grantActions as $a):
    $ast = $action_statuses[$a['status']] ?? ['label' => $a['status'], 'class' => 'secondary'];
  ?>
  <tr>
    <td>
      <a href="<?= APP_URL ?>/dzialania/<?= (int)$a['id'] ?>" class="fw-semibold text-decoration-none"><?= h($a['nazwa']) ?></a>
      <?php if (!empty($a['flags']['outsideGrant']) && in_array($grant['nazwa'], $a['outsideOf'], true)): ?>
      <span class="badge bg-warning text-dark ms-1" title="Termin działania wychodzi poza okres realizacji grantu">poza okresem grantu</span>
      <?php endif; ?>
      <?php if (!empty($a['flags']['overdue'])): ?><span class="badge bg-danger ms-1">po terminie</span><?php endif; ?>
    </td>
    <td><span class="badge bg-<?= $ast['class'] ?>"><?= h($ast['label']) ?></span></td>
    <td class="small text-nowrap"><?= date_pl($a['od']) ?><?= $a['do'] && $a['do'] !== $a['od'] ? ' – ' . date_pl($a['do']) : '' ?></td>
    <td class="text-end small"><?= $a['grantUdzial'] !== null ? h(rtrim(rtrim(number_format($a['grantUdzial'], 2, ',', ''), '0'), ',')) . '%' : '—' ?></td>
    <td class="text-end small"><?= $a['grantKwota'] !== null ? money((float)$a['grantKwota'], $grant['waluta']) : '—' ?></td>
    <td>
      <?php if ($a['pct'] !== null): ?>
      <div class="d-flex align-items-center gap-1" title="<?= h($a['indReal'] . ' / ' . $a['indPlan']) ?>">
        <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar bg-<?= $a['pct'] >= 100 ? 'success' : ($a['pct'] >= 50 ? 'info' : 'warning') ?>" style="width:<?= (int)$a['pct'] ?>%"></div></div>
        <small class="text-muted"><?= (int)$a['pct'] ?>%</small>
      </div>
      <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
    </td>
    <td class="small"><?= $a['ms']['total'] ? (int)$a['ms']['done'] . '/' . (int)$a['ms']['total'] : '—' ?></td>
    <td class="text-end text-nowrap">
      <a href="<?= APP_URL ?>/dzialania/<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-secondary" aria-label="Otwórz działanie <?= h($a['nazwa']) ?>"><i class="bi bi-eye"></i></a>
      <form method="post" action="<?= APP_URL ?>/dzialania/granty/<?= $id ?>/odepnij" class="d-inline" onsubmit="return confirm('Odpiąć to działanie od grantu? Kwota przypisana z grantu zostanie usunięta.')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Odepnij od grantu: <?= h($a['nazwa']) ?>"><i class="bi bi-link-45deg"></i><i class="bi bi-x"></i></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="alert alert-secondary">Brak powiązanych działań — utwórz nowe albo podepnij istniejące poniżej.</div>
<?php endif; ?>

<?php if ($candidates): ?>
<form method="post" action="<?= APP_URL ?>/dzialania/granty/<?= $id ?>/podepnij" class="card shadow-sm mb-3" aria-labelledby="grant-link-h">
  <div class="card-body">
    <h6 class="mb-2" id="grant-link-h"><i class="bi bi-link-45deg"></i> Podepnij istniejące działanie</h6>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="row g-2 align-items-end">
      <div class="col-md-6">
        <label class="form-label small mb-1" for="gl-action">Działanie</label>
        <select id="gl-action" name="action_id" class="form-select form-select-sm" required>
          <option value="">— wybierz —</option>
          <?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['nazwa']) ?><?= $c['data_od'] ? ' (' . date_pl($c['data_od']) . ')' : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1" for="gl-udzial">Udział %</label>
        <input id="gl-udzial" name="udzial" class="form-control form-control-sm" inputmode="decimal">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1" for="gl-kwota">Kwota (<?= h($grant['waluta']) ?>)</label>
        <input id="gl-kwota" name="kwota" class="form-control form-control-sm" inputmode="decimal"<?= $budget && $budget['remaining'] !== null ? ' placeholder="zostaje ' . h(number_format(max(0, $budget['remaining']), 2, ',', ' ')) . '"' : '' ?>>
      </div>
      <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-link-45deg"></i> Podepnij</button></div>
    </div>
  </div>
</form>
<?php endif; ?>

<div class="text-muted small mt-3">
  Dodano: <?= date_pl($grant['created_at']) ?> &nbsp;|&nbsp; Zaktualizowano: <?= date_pl($grant['updated_at']) ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
