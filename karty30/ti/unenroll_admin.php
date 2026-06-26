<?php
/**
 * karty30/ti/unenroll_admin.php — Panel administratora: zatwierdzanie wniosków wypisania.
 * Dostęp: osoby z uprawnieniami karty30 (admin/write).
 * Admin może też ręcznie wypisać kursanta z kursu (revoke enrollment).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write = can_write('karty30') || is_admin();
$PAGE_TITLE = 'TI: Wnioski wypisania z kursów';

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    if (!isset($_POST['_token']) || !hash_equals(csrf_token(), (string)$_POST['_token'])) {
        http_response_code(403); exit('Nieprawidłowy token CSRF.');
    }
    $op     = $_POST['_op'] ?? '';
    $req_id = (int)($_POST['req_id'] ?? 0);
    $note   = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);

    if ($op === 'approve' && $req_id) {
        if (k30_ti_unenroll_admin_decide($req_id, (int)(current_user()['id'] ?? 0), true, $note)) {
            $flash = ['success', 'Wniosek zatwierdzony. Kursant wypisany z kursu.'];
        } else {
            $flash = ['danger', 'Nie udało się zatwierdzić — wniosek mógł już być rozpatrzony.'];
        }
    } elseif ($op === 'reject' && $req_id) {
        if (k30_ti_unenroll_admin_decide($req_id, (int)(current_user()['id'] ?? 0), false, $note)) {
            $flash = ['warning', 'Wniosek odrzucony. Kursant pozostaje na kursie.'];
        } else {
            $flash = ['danger', 'Nie udało się odrzucić — wniosek mógł już być rozpatrzony.'];
        }
    } elseif ($op === 'force_unenroll') {
        // Bezpośrednie wypisanie przez admina (revoke) — nie wymaga wniosku
        $enroll_id = (int)($_POST['enrollment_id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        $reason    = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 1000);
        if ($enroll_id && $client_id) {
            k30_ti_unenroll_execute($enroll_id, $client_id, $reason, (int)(current_user()['id'] ?? 0), $note);
            $flash = ['success', 'Kursant wypisany z kursu przez administratora.'];
        }
    }
    header('Location: unenroll_admin.php'); exit;
}

$pending = k30_ti_unenroll_pending_admin();
$history = k30_ti_unenroll_requests_all();
$status_labels = [
    'pending_parent' => ['label' => 'Czeka na opiekuna', 'cls' => 'warning'],
    'pending_admin'  => ['label' => 'Czeka na admina',   'cls' => 'primary'],
    'approved'       => ['label' => 'Zatwierdzone',       'cls' => 'success'],
    'rejected'       => ['label' => 'Odrzucone',          'cls' => 'secondary'],
];

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <h1 class="h3 fw-bold mb-4">Wnioski wypisania z kursów (małoletni)</h1>

  <?php if ($flash): ?>
  <div class="alert alert-<?= h($flash[0]) ?> alert-dismissible fade show">
    <?= h($flash[1]) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <!-- ── Oczekujące na decyzję ──────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-hourglass-split text-warning" aria-hidden="true"></i>
      Oczekujące na rozpatrzenie
      <?php if ($pending): ?>
      <span class="badge bg-danger ms-1"><?= count($pending) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!$pending): ?>
    <div class="card-body text-body-secondary small">Brak oczekujących wniosków.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Kursant</th><th>Kurs</th><th>Powód</th><th>Status</th>
            <th>Zgłoszono</th><th>Opiekun ok</th><th>Decyzja</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $r):
            $sl = $status_labels[$r['status']] ?? ['label' => $r['status'], 'cls' => 'secondary'];
          ?>
          <tr>
            <td class="fw-semibold small"><?= h($r['client_name']) ?></td>
            <td class="small"><?= h($r['course_name']) ?></td>
            <td class="small text-body-secondary" style="max-width:200px">
              <?= $r['reason'] !== '' ? nl2br(h(mb_substr($r['reason'], 0, 120))) : '<em>—</em>' ?>
            </td>
            <td><span class="badge text-bg-<?= $sl['cls'] ?>"><?= h($sl['label']) ?></span></td>
            <td class="small text-nowrap"><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
            <td class="small text-nowrap"><?= $r['parent_ok_at'] ? h(substr($r['parent_ok_at'], 0, 16)) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="text-nowrap">
              <?php if ($can_write): ?>
              <button type="button" class="btn btn-success btn-sm"
                      data-bs-toggle="modal" data-bs-target="#modalDecide"
                      data-req-id="<?= (int)$r['id'] ?>"
                      data-action="approve"
                      data-client="<?= h($r['client_name']) ?>"
                      data-course="<?= h($r['course_name']) ?>">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Zatwierdź
              </button>
              <button type="button" class="btn btn-outline-danger btn-sm ms-1"
                      data-bs-toggle="modal" data-bs-target="#modalDecide"
                      data-req-id="<?= (int)$r['id'] ?>"
                      data-action="reject"
                      data-client="<?= h($r['client_name']) ?>"
                      data-course="<?= h($r['course_name']) ?>">
                <i class="bi bi-x-lg" aria-hidden="true"></i> Odrzuć
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Historia ───────────────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-clock-history" aria-hidden="true"></i> Historia wniosków
    </div>
    <?php if (!$history): ?>
    <div class="card-body text-body-secondary small">Brak historii.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light">
          <tr>
            <th>Kursant</th><th>Kurs</th><th>Status</th>
            <th>Zgłoszono</th><th>Decyzja admina</th><th>Admin</th><th>Uwaga</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $r):
            $sl = $status_labels[$r['status']] ?? ['label' => $r['status'], 'cls' => 'secondary'];
          ?>
          <tr>
            <td class="fw-semibold"><?= h($r['client_name']) ?></td>
            <td><?= h($r['course_name']) ?></td>
            <td><span class="badge text-bg-<?= $sl['cls'] ?>"><?= h($sl['label']) ?></span></td>
            <td class="text-nowrap"><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
            <td class="text-nowrap"><?= $r['admin_ok_at'] ? h(substr($r['admin_ok_at'], 0, 16)) : '<span class="text-body-secondary">—</span>' ?></td>
            <td><?= $r['admin_name'] ? h($r['admin_name']) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="text-body-secondary"><?= $r['admin_note'] !== '' ? h(mb_substr($r['admin_note'], 0, 80)) : '' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal decyzji -->
<div class="modal fade" id="modalDecide" tabindex="-1" aria-labelledby="modalDecideLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 fw-bold" id="modalDecideLabel">Decyzja ws. wniosku</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"    id="decideOp" value="">
        <input type="hidden" name="req_id" id="decideReqId" value="">
        <div class="modal-body">
          <p id="decideDesc" class="mb-3"></p>
          <div class="mb-3">
            <label class="form-label" for="decideNote">Uwaga dla kursanta <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
            <textarea class="form-control" id="decideNote" name="note" rows="2" maxlength="500"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" id="decideSubmitBtn" class="btn btn-primary fw-semibold">Potwierdź</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('modalDecide');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (e) {
    var btn    = e.relatedTarget;
    var action = btn.getAttribute('data-action');
    var client = btn.getAttribute('data-client') || '';
    var course = btn.getAttribute('data-course') || '';
    document.getElementById('decideOp').value     = action;
    document.getElementById('decideReqId').value  = btn.getAttribute('data-req-id') || '';
    document.getElementById('decideNote').value   = '';
    var submitBtn = document.getElementById('decideSubmitBtn');
    if (action === 'approve') {
      document.getElementById('decideDesc').textContent =
        'Zatwierdź wypisanie kursanta ' + client + ' z kursu ' + course + '. Operacja jest nieodwracalna.';
      submitBtn.textContent = 'Zatwierdź wypisanie';
      submitBtn.className = 'btn btn-success fw-semibold';
    } else {
      document.getElementById('decideDesc').textContent =
        'Odrzuć wniosek wypisania kursanta ' + client + ' z kursu ' + course + '. Kursant pozostanie na kursie.';
      submitBtn.textContent = 'Odrzuć wniosek';
      submitBtn.className = 'btn btn-danger fw-semibold';
    }
  });
})();
</script>

<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
