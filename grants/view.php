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

$linked_actions = grant_actions_for($id);
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

<!-- Linked actions -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h5 class="mb-0"><i class="bi bi-calendar-event text-primary"></i> Powiązane działania (<?= count($linked_actions) ?>)</h5>
  <a href="<?= APP_URL ?>/strategy/actions/add.php?grant_id=<?= $id ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-plus-lg"></i> Dodaj działanie z tym grantem
  </a>
</div>

<?php if ($linked_actions): ?>
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Nazwa działania</th>
      <th>Status</th>
      <th>Okres</th>
      <th class="text-end">Udział %</th>
      <th class="text-end">Kwota</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($linked_actions as $a):
    $ast = $action_statuses[$a['status']] ?? ['label' => $a['status'], 'class' => 'secondary'];
  ?>
  <tr>
    <td>
      <a href="<?= APP_URL ?>/strategy/actions/view.php?id=<?= $a['id'] ?>" class="fw-semibold text-decoration-none"><?= h($a['nazwa']) ?></a>
    </td>
    <td><span class="badge bg-<?= $ast['class'] ?>"><?= h($ast['label']) ?></span></td>
    <td class="small text-nowrap"><?= date_pl($a['data_od']) ?><?= $a['data_do'] ? ' – ' . date_pl($a['data_do']) : '' ?></td>
    <td class="text-end small"><?= $a['udzial_procent'] !== null ? h($a['udzial_procent']) . '%' : '—' ?></td>
    <td class="text-end small"><?= $a['ag_kwota'] !== null ? money((float)$a['ag_kwota'], $grant['waluta']) : '—' ?></td>
    <td class="text-end">
      <a href="<?= APP_URL ?>/strategy/actions/view.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="alert alert-secondary">Brak powiązanych działań.
  <a href="<?= APP_URL ?>/strategy/actions/add.php?grant_id=<?= $id ?>">Dodaj pierwsze działanie</a>.
</div>
<?php endif; ?>

<div class="text-muted small mt-3">
  Dodano: <?= date_pl($grant['created_at']) ?> &nbsp;|&nbsp; Zaktualizowano: <?= date_pl($grant['updated_at']) ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
