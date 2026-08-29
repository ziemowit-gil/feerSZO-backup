<?php
/**
 * karty30/pfron/siatka.php — Planowana siatka godzin PFRON (zdalnie/stacjonarnie).
 *
 * Zbiorczy widok wszystkich beneficjentów, u których przy rejestracji umowy
 * PFRON włączono podział planowanych godzin na zdalne i stacjonarne
 * (k30_pfron_contracts.hours_plan_enabled — przełącznik na formularzu umowy
 * w karty30/clients/view.php#pfron). Pozwala zobaczyć i skorygować rozdział
 * godzin między osoby w jednym miejscu, obok realnego wykorzystania per tryb
 * (k30_pfron_hours_used_by_mode() — wnioskowane z k30_schedules.resource_id).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
$PAGE_TITLE = 'Planowana siatka godzin PFRON — Dydaktyka 3';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { http_response_code(403); die('Brak uprawnień do zapisu.'); }

    $rows = $_POST['plan'] ?? [];
    $saved = 0; $errors = 0;
    foreach ($rows as $cid => $vals) {
        $cid = (int)$cid;
        $row = db_one("SELECT hours_limit FROM k30_pfron_contracts WHERE id=? AND hours_plan_enabled=1", [$cid]);
        if (!$row) continue;
        $remote = max(0, (float)str_replace(',', '.', $vals['remote'] ?? '0'));
        $onsite = max(0, (float)str_replace(',', '.', $vals['onsite'] ?? '0'));
        if ((float)$row['hours_limit'] > 0 && ($remote + $onsite) > (float)$row['hours_limit']) {
            $errors++;
            continue;
        }
        k30_pfron_contract_save(['planned_hours_remote' => $remote, 'planned_hours_onsite' => $onsite], $cid);
        $saved++;
    }
    if ($saved)  flash_set('success', "Zapisano plan dla $saved umów.");
    if ($errors) flash_set('warning', "$errors umów pominięto — suma godzin przekraczała limit umowy.");
    header('Location: siatka.php'); exit;
}

$contracts = db_all(
    "SELECT pc.*, c.name AS client_name
     FROM k30_pfron_contracts pc
     JOIN k30_clients c ON c.id = pc.client_id
     WHERE pc.hours_plan_enabled = 1
     ORDER BY c.name, pc.contract_number"
);

$totalRemote = 0; $totalOnsite = 0; $totalLimit = 0;
foreach ($contracts as &$pc) {
    $used = k30_pfron_hours_used_by_mode((int)$pc['id']);
    $pc['used_remote'] = $used['remote'];
    $pc['used_onsite'] = $used['onsite'];
    $totalRemote += (float)$pc['planned_hours_remote'];
    $totalOnsite += (float)$pc['planned_hours_onsite'];
    $totalLimit  += (float)$pc['hours_limit'];
}
unset($pc);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/pfron/training.php">Szkolenia PFRON</a></li>
    <li class="breadcrumb-item active">Planowana siatka godzin</li>
  </ol>
</nav>

<?= flash_html() ?>

<div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
  <i class="bi bi-grid-3x3-gap-fill fs-3" style="color:#7c3aed" aria-hidden="true"></i>
  <div>
    <h1 class="h5 fw-bold mb-0">Planowana siatka godzin PFRON</h1>
    <p class="text-body-secondary small mb-0">
      Rozdział planowanych godzin (zdalnie / stacjonarnie) między beneficjentów z włączonym planem na umowie.
    </p>
  </div>
</div>

<?php if (!$contracts): ?>
<div class="alert alert-info d-flex gap-3 align-items-start">
  <i class="bi bi-info-circle mt-1 flex-shrink-0" aria-hidden="true"></i>
  <div>
    Żaden beneficjent nie ma jeszcze włączonej planowanej siatki godzin.
    Włącz ją na formularzu umowy PFRON (karta beneficjenta → Umowy PFRON → pole
    „Planowana siatka godzin”).
  </div>
</div>
<?php else: ?>

<div class="d-flex gap-3 flex-wrap mb-3">
  <div class="text-center px-3 py-2 border rounded bg-white">
    <div class="fs-5 fw-bold lh-1"><?= number_format($totalLimit, 1, ',', '') ?></div>
    <div class="text-body-secondary" style="font-size:.75rem">Limit razem h</div>
  </div>
  <div class="text-center px-3 py-2 border rounded bg-white">
    <div class="fs-5 fw-bold lh-1"><i class="bi bi-laptop"></i> <?= number_format($totalRemote, 1, ',', '') ?></div>
    <div class="text-body-secondary" style="font-size:.75rem">Zaplanowane zdalnie h</div>
  </div>
  <div class="text-center px-3 py-2 border rounded bg-white">
    <div class="fs-5 fw-bold lh-1"><i class="bi bi-building"></i> <?= number_format($totalOnsite, 1, ',', '') ?></div>
    <div class="text-body-secondary" style="font-size:.75rem">Zaplanowane stacjonarnie h</div>
  </div>
</div>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <div class="table-responsive">
    <table class="table table-sm align-middle" style="font-size:.87rem">
      <caption class="visually-hidden">Planowana siatka godzin PFRON per beneficjent</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Beneficjent</th>
          <th scope="col">Umowa</th>
          <th scope="col" class="text-end">Limit</th>
          <th scope="col" class="text-end">Zdalnie — plan</th>
          <th scope="col" class="text-end">Zdalnie — wykorzystano</th>
          <th scope="col" class="text-end">Stacjonarnie — plan</th>
          <th scope="col" class="text-end">Stacjonarnie — wykorzystano</th>
          <th scope="col" class="text-end">Pozostało</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($contracts as $pc):
          $sum       = (float)$pc['planned_hours_remote'] + (float)$pc['planned_hours_onsite'];
          $remaining = max(0, (float)$pc['hours_limit'] - (float)$pc['hours_used']);
        ?>
        <tr>
          <td>
            <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$pc['client_id'] ?>#pfron">
              <?= h($pc['client_name']) ?>
            </a>
          </td>
          <td class="font-monospace text-body-secondary"><?= h($pc['contract_number']) ?></td>
          <td class="text-end"><?= number_format((float)$pc['hours_limit'], 1, ',', '') ?> h</td>
          <td class="text-end" style="max-width:110px">
            <?php if ($can_write): ?>
            <input type="number" class="form-control form-control-sm text-end" min="0" step="0.5"
                   name="plan[<?= (int)$pc['id'] ?>][remote]" value="<?= h($pc['planned_hours_remote']) ?>">
            <?php else: ?>
            <?= number_format((float)$pc['planned_hours_remote'], 1, ',', '') ?> h
            <?php endif; ?>
          </td>
          <td class="text-end text-body-secondary"><?= number_format($pc['used_remote'], 1, ',', '') ?> h</td>
          <td class="text-end" style="max-width:110px">
            <?php if ($can_write): ?>
            <input type="number" class="form-control form-control-sm text-end" min="0" step="0.5"
                   name="plan[<?= (int)$pc['id'] ?>][onsite]" value="<?= h($pc['planned_hours_onsite']) ?>">
            <?php else: ?>
            <?= number_format((float)$pc['planned_hours_onsite'], 1, ',', '') ?> h
            <?php endif; ?>
          </td>
          <td class="text-end text-body-secondary"><?= number_format($pc['used_onsite'], 1, ',', '') ?> h</td>
          <td class="text-end <?= $remaining <= 0 ? 'text-danger fw-semibold' : '' ?>">
            <?= number_format($remaining, 1, ',', '') ?> h
            <?php if ($sum > (float)$pc['hours_limit'] && (float)$pc['hours_limit'] > 0): ?>
            <br><span class="badge text-bg-warning" style="font-size:.65rem">plan &gt; limit</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($can_write): ?>
  <button type="submit" class="btn btn-primary btn-sm">
    <i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz siatkę godzin
  </button>
  <?php endif; ?>
</form>

<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
