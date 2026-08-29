<?php
/**
 * karty30/pfron/training.php — Szkolenia z PFRON.
 *
 * Dedykowana podstrona dla zajęć rozliczanych z PFRON (k30_schedules billing_type='pfron').
 * Nie jest to konsultacja — to odrębny rodzaj świadczenia realizowanego w ramach umowy PFRON.
 *
 * Przyjmuje ?pfron_id=X lub ?client_id=X jako kontekst.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$pfron_id  = (int)($_GET['pfron_id']  ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);

$pfron  = $pfron_id  ? k30_pfron_contract_get($pfron_id) : null;
if ($pfron && !$client_id) $client_id = (int)($pfron['client_id'] ?? 0);
$client = $client_id ? db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) : null;

// Bez kontekstu — pokaż wszystkie aktywne umowy PFRON
if (!$pfron_id && !$client_id) {
    $all_contracts = db_all(
        "SELECT pc.*, c.name AS client_name
         FROM k30_pfron_contracts pc
         JOIN k30_clients c ON c.id = pc.client_id
         WHERE pc.status = 'active'
         ORDER BY c.name, pc.contract_number"
    );
} else {
    $all_contracts = [];
}

// Zajęcia dla konkretnej umowy PFRON
$sessions = [];
if ($pfron_id) {
    $sessions = db_all(
        "SELECT s.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS trainer_name
         FROM k30_schedules s
         LEFT JOIN users u ON u.id = s.assigned_to
         WHERE s.pfron_contract_id = ? AND s.status NOT IN ('cancelled','rejected')
         ORDER BY s.start_time DESC",
        [$pfron_id]
    );
}

$PAGE_TITLE = 'Szkolenia PFRON — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

$remaining = $pfron ? k30_pfron_hours_remaining($pfron_id) : 0;

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <?php if ($client): ?>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= $client_id ?>#pfron"><?= h($client['name']) ?></a></li>
    <?php endif; ?>
    <li class="breadcrumb-item active">Szkolenia PFRON</li>
  </ol>
</nav>

<?= flash_html() ?>

<?php if (!$pfron_id && !$client_id): ?>
<!-- ── Widok globalny: wszystkie aktywne umowy ─────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
  <i class="bi bi-building-fill-check fs-3 text-purple" style="color:#7c3aed" aria-hidden="true"></i>
  <div>
    <h1 class="h5 fw-bold mb-0">Szkolenia z PFRON</h1>
    <p class="text-body-secondary small mb-0">Zajęcia realizowane w ramach aktywnych umów PFRON</p>
  </div>
  <a href="<?= APP_URL ?>/karty30/pfron/siatka.php" class="btn btn-outline-secondary btn-sm ms-auto">
    <i class="bi bi-grid-3x3-gap-fill me-1" aria-hidden="true"></i>Planowana siatka godzin
  </a>
</div>

<?php if (!$all_contracts): ?>
<div class="alert alert-info">Brak aktywnych umów PFRON w systemie.</div>
<?php else: ?>
<div class="list-group">
  <?php foreach ($all_contracts as $pc):
    $rem = max(0, (float)$pc['hours_limit'] - (float)$pc['hours_used']);
    $pct = $pc['hours_limit'] > 0 ? min(100, round($pc['hours_used'] / $pc['hours_limit'] * 100)) : 0;
  ?>
  <a href="?pfron_id=<?= (int)$pc['id'] ?>"
     class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
    <i class="bi bi-file-earmark-medical text-purple fs-4 flex-shrink-0" style="color:#7c3aed" aria-hidden="true"></i>
    <div class="flex-grow-1 min-w-0">
      <div class="fw-semibold"><?= h($pc['client_name']) ?></div>
      <div class="text-body-secondary small font-monospace"><?= h($pc['contract_number']) ?></div>
      <div class="progress mt-1" style="height:4px;max-width:200px" role="progressbar"
           aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
           aria-label="Wykorzystano <?= $pct ?>% limitu godzin">
        <div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success') ?>"
             style="width:<?= $pct ?>%"></div>
      </div>
    </div>
    <div class="text-end flex-shrink-0 small">
      <div class="fw-semibold <?= $rem <= 0 ? 'text-danger' : '' ?>"><?= number_format($rem, 1, ',', '') ?> h</div>
      <div class="text-body-secondary">pozostało</div>
    </div>
    <i class="bi bi-chevron-right text-body-tertiary" aria-hidden="true"></i>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ── Widok konkretnej umowy ─────────────────────────────────────────── -->

<?php if ($pfron): ?>
<?php
$pct_c = $pfron['hours_limit'] > 0
    ? min(100, round((float)$pfron['hours_used'] / (float)$pfron['hours_limit'] * 100))
    : 0;
$ps = K30_PFRON_CONTRACT_STATUSES[$pfron['status']] ?? ['label' => $pfron['status'], 'color' => '#666', 'bg' => '#eee'];
?>
<div class="card mb-4 border-0 shadow-sm">
  <div class="card-body">
    <div class="d-flex align-items-start gap-3 flex-wrap">
      <i class="bi bi-building-fill-check fs-3 flex-shrink-0" style="color:#7c3aed" aria-hidden="true"></i>
      <div class="flex-grow-1">
        <h1 class="h5 fw-bold mb-1">
          Szkolenie PFRON
          <span class="font-monospace fw-normal text-body-secondary"><?= h($pfron['contract_number']) ?></span>
        </h1>
        <?php if ($client): ?>
        <p class="mb-1">
          <i class="bi bi-person me-1" aria-hidden="true"></i>
          <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= $client_id ?>#pfron"><?= h($client['name']) ?></a>
        </p>
        <?php endif; ?>
        <div class="d-flex gap-3 flex-wrap align-items-center small text-body-secondary">
          <?php if ($pfron['valid_from'] || $pfron['valid_to']): ?>
          <span><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h($pfron['valid_from'] ?? '?') ?> – <?= h($pfron['valid_to'] ?? '?') ?></span>
          <?php endif; ?>
          <span class="badge" style="background:<?= h($ps['bg']) ?>;color:<?= h($ps['color']) ?>;border:1px solid <?= h($ps['color']) ?>33">
            <?= h($ps['label']) ?>
          </span>
        </div>
      </div>
      <!-- Liczniki godzin -->
      <div class="d-flex gap-3 flex-shrink-0 flex-wrap">
        <div class="text-center px-3 py-2 border rounded">
          <div class="fs-5 fw-bold lh-1"><?= number_format((float)$pfron['hours_limit'], 1, ',', '') ?></div>
          <div class="text-body-secondary" style="font-size:.75rem">Limit h</div>
        </div>
        <div class="text-center px-3 py-2 border rounded">
          <div class="fs-5 fw-bold lh-1"><?= number_format((float)$pfron['hours_used'], 1, ',', '') ?></div>
          <div class="text-body-secondary" style="font-size:.75rem">Użyte h</div>
        </div>
        <div class="text-center px-3 py-2 border rounded <?= $remaining <= 0 ? 'border-danger' : 'border-success' ?>">
          <div class="fs-5 fw-bold lh-1 <?= $remaining <= 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($remaining, 1, ',', '') ?></div>
          <div class="text-body-secondary" style="font-size:.75rem">Pozostało h</div>
        </div>
      </div>
    </div>
    <div class="progress mt-3" style="height:6px" role="progressbar"
         aria-valuenow="<?= $pct_c ?>" aria-valuemin="0" aria-valuemax="100"
         aria-label="Wykorzystano <?= $pct_c ?>% limitu">
      <div class="progress-bar <?= $pct_c >= 90 ? 'bg-danger' : ($pct_c >= 70 ? 'bg-warning' : 'bg-success') ?>"
           style="width:<?= $pct_c ?>%"></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Przyciski akcji -->
<div class="d-flex gap-2 mb-4 flex-wrap align-items-center">
  <?php if ($can_write): ?>
  <a href="<?= APP_URL ?>/karty30/schedules/add.php?client_id=<?= $client_id ?>&pfron_contract_id=<?= $pfron_id ?>&billing_type=pfron"
     class="btn btn-primary">
    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj zajęcia
  </a>
  <?php endif; ?>
  <a href="<?= APP_URL ?>/karty30/pfron/docs.php?pfron_id=<?= $pfron_id ?>&client_id=<?= $client_id ?>"
     class="btn btn-danger">
    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj dokumenty
  </a>
  <?php if ($client_id): ?>
  <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= $client_id ?>#pfron"
     class="btn btn-outline-secondary ms-auto">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Karta beneficjenta
  </a>
  <?php endif; ?>
</div>

<!-- Lista zajęć -->
<?php if (!$sessions): ?>
<div class="alert alert-info d-flex gap-3 align-items-start">
  <i class="bi bi-info-circle mt-1 flex-shrink-0" aria-hidden="true"></i>
  <div>
    Brak zajęć przypisanych do tej umowy PFRON.
    <?php if ($can_write): ?>
    <a href="<?= APP_URL ?>/karty30/schedules/add.php?client_id=<?= $client_id ?>&pfron_contract_id=<?= $pfron_id ?>&billing_type=pfron"
       class="alert-link fw-semibold">Dodaj pierwsze zajęcia →</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Zajęcia PFRON</caption>
    <thead class="table-light">
      <tr>
        <th scope="col">Data i czas</th>
        <th scope="col">Prowadzący</th>
        <th scope="col" class="text-end">Czas</th>
        <th scope="col" class="text-end">Godziny PFRON</th>
        <th scope="col">Status</th>
        <th scope="col" class="text-end">Akcje</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sessions as $s):
      $sdt  = new DateTime($s['start_time']);
      $stat = K30_SCHEDULE_STATUSES[$s['status']] ?? ['label' => $s['status'], 'color' => '#666', 'bg' => '#eee'];
      $pst  = $s['pfron_status'] ? (K30_PFRON_STATUSES[$s['pfron_status']] ?? null) : null;
    ?>
    <tr>
      <td class="text-nowrap small">
        <?= h($sdt->format('d.m.Y')) ?><br>
        <span class="text-body-secondary"><?= h($sdt->format('H:i')) ?></span>
      </td>
      <td class="small"><?= $s['trainer_name'] ? h($s['trainer_name']) : '<span class="text-body-secondary">—</span>' ?></td>
      <td class="text-end small"><?= (int)$s['duration_minutes'] ?> min</td>
      <td class="text-end small fw-semibold">
        <?= number_format((float)($s['billed_hours'] ?? 0), 2, ',', '') ?> h
        <?php if ((float)($s['charged_hours'] ?? 0) > 0): ?>
        <br><span class="text-danger small fw-normal">+<?= number_format((float)$s['charged_hours'], 2, ',', '') ?> h płatne</span>
        <?php endif; ?>
      </td>
      <td>
        <span class="badge" style="background:<?= h($stat['bg'] ?? '#eee') ?>;color:<?= h($stat['color'] ?? '#333') ?>">
          <?= h($stat['label'] ?? $s['status']) ?>
        </span>
        <?php if ($pst): ?>
        <br><span class="badge mt-1" style="background:<?= h($pst['bg'] ?? '#eee') ?>;color:<?= h($pst['color'] ?? '#333') ?>;font-size:.7rem">
          PFRON: <?= h($pst['label'] ?? $s['pfron_status']) ?>
        </span>
        <?php endif; ?>
      </td>
      <td class="text-end">
        <a href="<?= APP_URL ?>/karty30/schedules/view.php?id=<?= (int)$s['id'] ?>"
           class="btn btn-sm btn-outline-secondary py-0 px-2">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light">
      <tr>
        <td colspan="3" class="fw-semibold small">Razem</td>
        <td class="text-end fw-semibold small">
          <?= number_format(array_sum(array_column($sessions, 'billed_hours')), 2, ',', '') ?> h
        </td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php endif; // pfron_id / client_id ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
