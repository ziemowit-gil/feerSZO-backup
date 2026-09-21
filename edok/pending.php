<?php
/**
 * edok/pending.php — Zbiorcze podpisywanie: dokumenty gotowe do akceptacji
 * "Tak/OK" przez bieżącego użytkownika, pogrupowane wg etapu. Jeden wpisany
 * PIN potwierdza wiele zaznaczonych dokumentów na tym samym etapie naraz —
 * zamiast osobno wpisywać PIN dla każdego (edok/view.php nadal działa tak
 * jak dotąd, do pojedynczych decyzji i do "Z uwagami"/"Odrzuć").
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$user = current_user();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_decide') {
    csrf_check();
    $step_key = $_POST['step_key'] ?? '';
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['doc_ids'] ?? '')))));
    $pin = trim($_POST['pin'] ?? '');

    if (!in_array($step_key, edok_step_order(), true) || !$ids) {
        flash_set('danger', 'Nieprawidłowe żądanie.');
    } elseif (!edok_has_role($step_key)) {
        flash_set('danger', 'Brak uprawnień do tego etapu.');
    } else {
        // Weryfikacja PIN JEDNORAZOWO dla całej zaznaczonej partii (doc_id=0 —
        // zdarzenie nie jest przypisane do jednego dokumentu, patrz edok_log()).
        $pin_error = edok_pin_verify_for_decision($uid, $pin, 0, $step_key);
        if ($pin_error !== null) {
            flash_set('danger', $pin_error);
        } else {
            $ok = 0; $skipped = 0;
            foreach ($ids as $id) {
                $doc = edok_get($id);
                $step = $doc['steps'][$step_key] ?? null;
                $eligible = $doc
                    && $doc['status'] === 'w_obiegu'
                    && !($step && in_array($step['status'], ['ok', 'uwagi', 'odrzucono'], true))
                    && edok_step_blocked_reason($doc, $step_key) === null
                    && !edok_step_validation_errors($doc, $step_key);
                if (!$eligible) { $skipped++; continue; }
                edok_decide_step($doc, $step_key, 'ok', $uid, '', true);
                $ok++;
            }
            flash_set('success', "Zatwierdzono zbiorczo: {$ok}." . ($skipped ? " Pominięto (stan zmienił się w międzyczasie): {$skipped}." : ''));
        }
    }
    header('Location: ' . APP_URL . '/edok/pending.php');
    exit;
}

$pending = edok_pending_for_user($uid);
$has_pin = edok_pin_is_set($uid);

$PAGE_TITLE = 'Do akceptacji — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-check2-all"></i> Dokumenty do akceptacji — podpisywanie zbiorcze</h4>
</div>
<p class="text-muted small">Zaznacz dokumenty gotowe do zatwierdzenia na danym etapie i wpisz PIN raz — potwierdzi wszystkie zaznaczone naraz. Dokumenty z brakującymi danymi (nie przejdą walidacji "Tak/OK") nie są tu pokazywane — otwórz je osobno w EODoK.</p>

<?php if (!$has_pin): ?>
<div class="alert alert-warning">
  <i class="bi bi-shield-exclamation"></i> Nie masz jeszcze ustawionego PIN-u EODoK — wymagany do zatwierdzenia.
  <a href="<?= APP_URL ?>/edok/ustaw_pin.php" class="alert-link">Ustaw PIN</a>.
</div>
<?php endif; ?>

<?php if (!$pending): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-check-circle display-4 d-block mb-2"></i>
  Brak dokumentów gotowych do Twojej akceptacji.
</div>
<?php endif; ?>

<?php foreach ($pending as $step_key => $docs): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header py-2 d-flex align-items-center justify-content-between">
    <strong><i class="bi bi-list-check"></i> <?= h(EDOK_STEPS[$step_key]) ?></strong>
    <span class="badge bg-secondary"><?= count($docs) ?></span>
  </div>
  <div class="card-body py-2">
    <form method="post" class="bulk-form" data-step="<?= h($step_key) ?>" onsubmit="return edokBulkSubmit(event, this)">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="bulk_decide">
      <input type="hidden" name="step_key" value="<?= h($step_key) ?>">
      <input type="hidden" name="doc_ids" class="doc_ids_field">
      <input type="hidden" name="pin" class="step-pin-field">
      <div class="table-responsive mb-2">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:2rem"><input type="checkbox" class="select-all"></th>
              <th>Numer</th>
              <th>Kontrahent</th>
              <th class="text-end">Brutto</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($docs as $d): ?>
            <tr>
              <td><input type="checkbox" class="form-check-input doc-check" value="<?= (int)$d['id'] ?>"></td>
              <td><a href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$d['id'] ?>"><code><?= h($d['number']) ?></code></a></td>
              <td><?= h($d['kontrahent_nazwa']) ?></td>
              <td class="text-end font-monospace"><?= h($d['kwota_brutto']) ?> <?= h($d['waluta']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($has_pin): ?>
      <button type="submit" class="btn btn-sm btn-success disabled-until-selected" disabled>
        <i class="bi bi-check2-all"></i> Zatwierdź zaznaczone (PIN)
      </button>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- PIN jako wyskakujące okno — jeden modal współdzielony przez wszystkie karty etapów. -->
<div class="modal fade" id="pinModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-shield-lock"></i> Potwierdź PIN-em</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2" id="pinModalCount"></p>
        <input type="password" id="pinModalInput" class="form-control text-center font-monospace" style="letter-spacing:.4em;font-size:1.2rem"
               inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off" placeholder="••••••">
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="pinModalConfirm"><i class="bi bi-check2"></i> Potwierdź</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modalEl = document.getElementById('pinModal');
  var input   = document.getElementById('pinModalInput');
  var countEl = document.getElementById('pinModalCount');
  var confirmBtn = document.getElementById('pinModalConfirm');
  var modal   = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;
  var pendingForm = null;

  function confirmPin() {
    var pin = input.value.trim();
    if (!/^\d{6}$/.test(pin)) { input.classList.add('is-invalid'); input.focus(); return; }
    input.classList.remove('is-invalid');
    if (pendingForm) {
      pendingForm.querySelector('.step-pin-field').value = pin;
      var f = pendingForm; pendingForm = null;
      if (modal) modal.hide();
      f.submit();
    }
  }
  if (confirmBtn) confirmBtn.addEventListener('click', confirmPin);
  if (input) input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); confirmPin(); } });
  if (modalEl) modalEl.addEventListener('shown.bs.modal', function () { input.value = ''; input.classList.remove('is-invalid'); input.focus(); });

  window.edokBulkSubmit = function (event, form) {
    var checked = Array.from(form.querySelectorAll('.doc-check')).filter(function (b) { return b.checked; });
    if (!checked.length) { event.preventDefault(); return false; }
    event.preventDefault();
    form.querySelector('.doc_ids_field').value = checked.map(function (b) { return b.value; }).join(',');
    if (!modal) { form.submit(); return false; }
    countEl.textContent = 'Zatwierdzasz zbiorczo ' + checked.length + ' dokument(ów).';
    pendingForm = form;
    modal.show();
    return false;
  };
})();

document.querySelectorAll('.bulk-form').forEach(function (form) {
  var boxes     = Array.from(form.querySelectorAll('.doc-check'));
  var selectAll = form.querySelector('.select-all');
  var btn       = form.querySelector('.disabled-until-selected');
  function sync() {
    var checked = boxes.filter(function (b) { return b.checked; });
    if (btn) btn.disabled = checked.length === 0;
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      boxes.forEach(function (b) { b.checked = selectAll.checked; });
      sync();
    });
  }
  sync();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
