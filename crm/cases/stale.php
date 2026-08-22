<?php
/**
 * crm/cases/stale.php — sprawy proponowane do zamknięcia.
 *
 * Przegląd spraw leżących bez ruchu dłużej niż CRM_CASE_STALE_DAYS. Zamykanie jest
 * ŚWIADOME, nie automatyczne: sprawa to kwestia merytoryczna, a nie porządkowa —
 * system podpowiada, decyzję podejmuje prowadzący.
 *
 * Domyślnie pokazujemy własne sprawy; admin i osoba z prawem zapisu mogą przejrzeć
 * wszystkie, bo porządkowanie rejestru zwykle robi jedna osoba za cały zespół.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$uid       = (int)(current_user()['id'] ?? 0);
$can_write = is_admin() || can_write('crm');
$scope     = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['pick'] ?? []))));
    $now = date('Y-m-d H:i:s');
    $n   = 0;

    foreach ($ids as $cid) {
        if ($cid <= 0) continue;
        $case = db_one("SELECT id, status, contact_id FROM crm_cases WHERE id=?", [$cid]);
        if (!$case || in_array((string)$case['status'], ['closed', 'cancelled'], true)) continue;

        if ($op === 'close') {
            db()->prepare("UPDATE crm_cases SET status='closed', updated_at=?, closed_at=? WHERE id=?")
                ->execute([$now, $now, $cid]);
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/crm_automation.php';
                crm_automation_fire('case_status_changed', (int)$case['contact_id'], [
                    'case_id' => $cid, 'from_status' => (string)$case['status'], 'to_status' => 'closed',
                ]);
            } catch (\Throwable $e) {}
            $n++;
        } elseif ($op === 'ack') {
            // Odłożenie decyzji — sprawa wypada z listy na kolejny okres.
            db()->prepare("UPDATE crm_cases SET stale_ack_at=? WHERE id=?")->execute([$now, $cid]);
            $n++;
        }
    }

    flash_set($n ? 'success' : 'info', $n
        ? ($op === 'close' ? "Zamknięto spraw: {$n}." : "Odłożono decyzję dla spraw: {$n}.")
        : 'Nie zaznaczono żadnej sprawy.');
    header('Location: ' . APP_URL . '/crm/cases/stale.php?scope=' . $scope); exit;
}

$rows = crm_cases_stale($scope === 'all' ? 0 : $uid, 200);
$PAGE_TITLE = 'Sprawy do zamknięcia';

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:1000px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a href="<?= APP_URL ?>/crm/cases/index.php" class="btn btn-sm btn-crm-ghost"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-clock-history me-2"></i>Sprawy do zamknięcia
      <span class="badge bg-warning text-dark"><?= count($rows) ?></span>
    </h1>
    <div class="btn-group btn-group-sm" role="group" aria-label="Zakres">
      <a class="btn btn-crm-outline<?= $scope === 'mine' ? ' active' : '' ?>"
         href="?scope=mine">Moje</a>
      <a class="btn btn-crm-outline<?= $scope === 'all' ? ' active' : '' ?>"
         href="?scope=all">Wszystkie</a>
    </div>
  </div>

  <p class="text-muted small" style="max-width:70ch">
    Sprawy bez aktywności dłużej niż <strong><?= CRM_CASE_STALE_DAYS ?> dni</strong>.
    Liczymy od ostatniego ruchu, nie od utworzenia — sprawa prowadzona długo, ale ruszana
    niedawno, tu nie trafia. Nic nie zamyka się samo: „Odłóż decyzję" zdejmuje sprawę
    z listy na kolejny okres.
  </p>

  <?php if (!$rows): ?>
  <div class="cv-panel"><div class="cv-panel__body text-center text-muted py-5">
    <i class="bi bi-check2-circle display-6 d-block mb-2 opacity-25" aria-hidden="true"></i>
    <div style="font-size:.95rem">Nic nie zalega</div>
    <div style="font-size:.82rem" class="mt-1">
      <?= $scope === 'mine' ? 'Wszystkie Twoje sprawy mają świeżą aktywność.' : 'Żadna sprawa nie leży dłużej niż ' . CRM_CASE_STALE_DAYS . ' dni.' ?>
    </div>
  </div></div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="scope" value="<?= h($scope) ?>">

    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <caption class="visually-hidden">Sprawy bez aktywności</caption>
          <thead class="table-light">
            <tr>
              <th scope="col" style="width:2.5rem">
                <input type="checkbox" class="form-check-input" id="pickAll" aria-label="Zaznacz wszystkie">
              </th>
              <th scope="col">Sprawa</th>
              <th scope="col">Kontakt</th>
              <?php if ($scope === 'all'): ?><th scope="col">Prowadzi</th><?php endif; ?>
              <th scope="col" class="text-end">Bez ruchu</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><input type="checkbox" class="form-check-input pick" name="pick[]" value="<?= (int)$r['id'] ?>"
                         aria-label="Wybierz sprawę <?= h($r['title']) ?>"></td>
              <td>
                <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none">
                  <?= h(mb_strimwidth((string)$r['title'], 0, 60, '…')) ?>
                </a>
                <?php if (!empty($r['case_number'])): ?>
                <div class="font-monospace text-muted" style="font-size:.72rem"><?= h($r['case_number']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if (!empty($r['contact_id'])): ?>
                <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['contact_id'] ?>" class="text-decoration-none">
                  <?= h((string)($r['contact_name'] ?: '—')) ?>
                </a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <?php if ($scope === 'all'): ?>
              <td class="small text-muted"><?= h((string)($r['owner_name'] ?: '—')) ?></td>
              <?php endif; ?>
              <td class="text-end text-nowrap">
                <span class="badge bg-warning text-dark"><?= (int)$r['stale_days'] ?> dni</span>
              </td>
              <td><span class="badge bg-light text-dark border"><?= h((string)$r['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($can_write): ?>
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <button type="submit" name="_op" value="close" class="btn btn-sm btn-success"
                onclick="return this.form.querySelectorAll('.pick:checked').length ? confirm('Zamknąć zaznaczone sprawy?') : (alert('Zaznacz co najmniej jedną sprawę.'), false)">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zamknij zaznaczone
        </button>
        <button type="submit" name="_op" value="ack" class="btn btn-sm btn-crm-outline"
                onclick="return this.form.querySelectorAll('.pick:checked').length ? true : (alert('Zaznacz co najmniej jedną sprawę.'), false)">
          Odłóż decyzję
        </button>
        <span class="text-muted ms-auto" style="font-size:.78rem">
          Zamknięcie bez notatki. Notatkę domykającą dodasz w karcie sprawy.
        </span>
      </div>
    </div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<script>
document.getElementById('pickAll')?.addEventListener('change', function () {
  document.querySelectorAll('.pick').forEach(c => { c.checked = this.checked; });
});
</script>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
