<?php
/**
 * asysta/view.php — Karta jednego zgłoszenia asysty (widok administracyjny).
 *
 * Góra: dane zgłoszenia (nr, data, status). Następnie dane zgłaszającego,
 * szczegóły wydarzenia i zakres asysty (tylko do odczytu — pochodzą z
 * publicznego formularza). Na dole sekcja administracyjna: zmiana statusu,
 * przypisanie wolontariusza, kanał powiadomień (e-mail/SMS) i notatki wewnętrzne.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/assistance.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('dostepnosc_ngo_enabled', 'Moduł Dostępność NGO');
asr_migrate();

$base     = APP_URL . '/asysta';
$can_edit = can_edit();
$id       = (int)($_GET['id'] ?? 0);
$req      = $id ? asr_get($id) : null;

if (!$req) {
    flash_set('error', 'Nie znaleziono zgłoszenia.');
    header('Location: ' . $base . '/admin.php');
    exit;
}

/* ── Zapis sekcji administracyjnej (PRG) ──────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    csrf_check();
    if (($_POST['_action'] ?? '') === 'delete' && is_admin()) {
        db()->prepare("DELETE FROM szo_assistance_requests WHERE id=?")->execute([$id]);
        flash_set('success', 'Zgłoszenie zostało usunięte.');
        header('Location: ' . $base . '/admin.php');
        exit;
    }
    $changes = asr_admin_update($id, $_POST);
    $msg = 'Zapisano zmiany w zgłoszeniu ' . asr_number($req) . '.';
    if (!empty($req['assigned_volunteer_id']) || ($_POST['assigned_volunteer_id'] ?? '') !== '') {
        if (!empty($_POST['channels'])) $msg .= ' Powiadomienie wysłano wybranymi kanałami.';
    }
    flash_set('success', $msg);
    header('Location: ' . $base . '/view.php?id=' . $id);
    exit;
}

$statuses    = asr_statuses();
$needs_all   = asr_needs();
$channels    = asr_channels();
$all_persons = asr_all_assignees();
$log         = asr_get_log($id);

// Bieżąca wartość selecta: "vol:ID" lub "usr:ID" lub "".
$cur_person_val = '';
if (!empty($req['assigned_person_type']) && !empty($req['assigned_volunteer_id'] ?: $req['assigned_user_id'] ?? 0)) {
    $pid = $req['assigned_person_type'] === 'vol' ? ($req['assigned_volunteer_id'] ?? 0) : ($req['assigned_user_id'] ?? 0);
    if ($pid) $cur_person_val = $req['assigned_person_type'] . ':' . (int)$pid;
}
$picked_needs   = array_filter(array_map('trim', explode(',', (string)$req['needs'])));
$picked_channels= array_filter(array_map('trim', explode(',', (string)$req['assigned_channels'])));

$PAGE_TITLE = 'Zgłoszenie ' . asr_number($req);
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-3" style="max-width:980px">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-universal-access-circle text-primary me-1" aria-hidden="true"></i>
      Zgłoszenie <?= h(asr_number($req)) ?>
    </h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($base) ?>/admin.php">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do listy
    </a>
  </div>

  <!-- ════ Dane zgłoszenia (góra, tylko do odczytu) ════ -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-sm-4">
          <div class="text-secondary small text-uppercase">Numer zgłoszenia</div>
          <div class="fw-semibold"><?= h(asr_number($req)) ?></div>
        </div>
        <div class="col-sm-4">
          <div class="text-secondary small text-uppercase">Data wysłania</div>
          <div class="fw-semibold"><?= h($req['created_at']) ?></div>
        </div>
        <div class="col-sm-4">
          <div class="text-secondary small text-uppercase">Status</div>
          <span class="badge <?= h(asr_status_class($req['status'])) ?>"><?= h(asr_label($statuses, $req['status'])) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ════ Dane zgłaszającego + wydarzenie + zakres (tylko do odczytu) ════ -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <h2 class="h6 fw-bold text-secondary text-uppercase mb-3">Dane zgłaszającego</h2>
      <dl class="row mb-0">
        <dt class="col-sm-3">Imię i nazwisko</dt>
        <dd class="col-sm-9"><?= h($req['participant_name']) ?></dd>
        <dt class="col-sm-3">E-mail</dt>
        <dd class="col-sm-9"><a href="mailto:<?= h($req['participant_email']) ?>"><?= h($req['participant_email']) ?></a></dd>
        <dt class="col-sm-3">Telefon</dt>
        <dd class="col-sm-9"><a href="tel:<?= h($req['participant_phone']) ?>"><?= h($req['participant_phone']) ?></a></dd>
        <dt class="col-sm-3">Zgłasza jako opiekun</dt>
        <dd class="col-sm-9"><?= ((int)$req['is_guardian'] === 1) ? 'Tak — w imieniu innej osoby' : 'Nie' ?></dd>
      </dl>

      <hr>
      <h2 class="h6 fw-bold text-secondary text-uppercase mb-3">Wydarzenie</h2>
      <dl class="row mb-0">
        <dt class="col-sm-3">Nazwa wydarzenia</dt>
        <dd class="col-sm-9"><?= h($req['event_name'] ?: '—') ?></dd>
        <dt class="col-sm-3">Data i miejsce</dt>
        <dd class="col-sm-9"><?= h($req['event_when_where'] ?: '—') ?></dd>
      </dl>

      <hr>
      <h2 class="h6 fw-bold text-secondary text-uppercase mb-3">Zakres asysty i specjalne potrzeby</h2>
      <?php if ($picked_needs): ?>
        <ul class="mb-2">
          <?php foreach ($picked_needs as $k): ?>
            <li><?= h($needs_all[$k] ?? $k) ?>
              <?php if ($k === 'other' && trim((string)$req['needs_other']) !== ''): ?>
                — <em><?= h($req['needs_other']) ?></em>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-secondary mb-2">— brak —</p>
      <?php endif; ?>
      <?php if (trim((string)$req['details']) !== ''): ?>
        <div class="mt-2">
          <div class="fw-semibold">Dodatkowe informacje / uwagi:</div>
          <div class="text-body"><?= nl2br(h($req['details'])) ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ════ Sekcja administracyjna ════ -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-bold">
      <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Obsługa zgłoszenia (administracja)
    </div>
    <div class="card-body">
      <?php if (!$can_edit): ?>
        <p class="text-secondary mb-0">Nie masz uprawnień do edycji tego zgłoszenia.</p>
      <?php else: ?>
      <form method="post" action="<?= h($base) ?>/view.php?id=<?= $id ?>" novalidate>
        <?= csrf_field() ?>

        <div class="row g-3">
          <div class="col-md-6">
            <label for="status" class="form-label fw-semibold">Status zgłoszenia</label>
            <select class="form-select" id="status" name="status">
              <?php foreach ($statuses as $k => $lbl): ?>
                <option value="<?= h($k) ?>" <?= $req['status'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label for="assigned_person" class="form-label fw-semibold">Przypisana osoba realizująca</label>
            <select class="form-select" id="assigned_person" name="assigned_person">
              <option value="">— nie przypisano —</option>
              <?php if ($all_persons['vol']): ?>
              <optgroup label="Wolontariusze">
                <?php foreach ($all_persons['vol'] as $a): ?>
                  <?php $val = 'vol:' . $a['id']; ?>
                  <option value="<?= h($val) ?>" <?= $cur_person_val === $val ? 'selected' : '' ?>>
                    <?= h($a['name']) ?><?= $a['email'] !== '' ? ' (' . h($a['email']) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php if ($all_persons['usr']): ?>
              <optgroup label="Użytkownicy systemu">
                <?php foreach ($all_persons['usr'] as $a): ?>
                  <?php $val = 'usr:' . $a['id']; ?>
                  <option value="<?= h($val) ?>" <?= $cur_person_val === $val ? 'selected' : '' ?>>
                    <?= h($a['name']) ?><?= $a['email'] !== '' ? ' (' . h($a['email']) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
            <?php if (!$all_persons['vol'] && !$all_persons['usr']): ?>
              <div class="form-text text-warning">Brak osób do przypisania w bazie.</div>
            <?php endif; ?>
            <?php if (!empty($req['assigned_token'])): ?>
              <div class="form-text">
                <i class="bi bi-link-45deg" aria-hidden="true"></i>
                Link do odpowiedzi:
                <a href="<?= h(asr_respond_url($req['assigned_token'])) ?>" target="_blank" rel="noopener">
                  <?= h(asr_respond_url($req['assigned_token'])) ?>
                </a>
                <span class="text-secondary">(zmiana przypisania unieważni link)</span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <fieldset class="border-0 p-0 m-0 mt-3">
          <legend class="form-label fw-semibold fs-6 mb-2" style="border:0">
            Kanał powiadomień dla wolontariusza
          </legend>
          <div role="group" aria-label="Kanał powiadomień dla wolontariusza">
            <?php foreach ($channels as $k => $lbl): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="channels[]"
                       id="ch_<?= h($k) ?>" value="<?= h($k) ?>"
                       <?= in_array($k, $picked_channels, true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ch_<?= h($k) ?>"><?= h($lbl) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="form-text">
            Po zapisaniu z zaznaczonym kanałem wolontariusz otrzyma powiadomienie
            o przydzielonym zgłoszeniu (wymaga przypisania wolontariusza).
          </div>
        </fieldset>

        <div class="mt-3" id="rejection-reason-wrap" style="display:none">
          <label for="rejection_reason" class="form-label fw-semibold">
            Powód odrzucenia
            <span class="text-danger" aria-hidden="true">*</span>
          </label>
          <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3"
                    maxlength="500" aria-describedby="rejection-reason-help"
                    placeholder="Opisz powód odrzucenia — trafi do historii i może zostać przekazany uczestnikowi."
                    ><?= h($_POST['rejection_reason'] ?? '') ?></textarea>
          <div id="rejection-reason-help" class="form-text">
            Widoczny w historii zmian. Przy statusach z powiadomieniem uczestnika warto opisać sytuację.
          </div>
        </div>

        <div class="mt-3">
          <label for="internal_notes" class="form-label fw-semibold">Notatki wewnętrzne</label>
          <textarea class="form-control" id="internal_notes" name="internal_notes" rows="4"
                    aria-describedby="notes-help"><?= h($req['internal_notes']) ?></textarea>
          <div id="notes-help" class="form-text">Widoczne tylko dla zespołu — nie są przekazywane zgłaszającemu.</div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz zmiany
          </button>
          <?php if (is_admin()): ?>
            <button type="submit" name="_action" value="delete" class="btn btn-outline-danger ms-auto"
                    onclick="return confirm('Usunąć zgłoszenie <?= h(asr_number($req)) ?>? Tej operacji nie można cofnąć.');">
              <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń zgłoszenie
            </button>
          <?php endif; ?>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($req['updated_at'])): ?>
    <p class="text-secondary small text-end">Ostatnia zmiana: <?= h($req['updated_at']) ?></p>
  <?php endif; ?>

  <!-- ════ Historia zmian ════ -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-bold">
      <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historia zmian
    </div>
    <?php if (!$log): ?>
      <div class="card-body text-secondary small">Brak wpisów w historii.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" aria-label="Historia zmian zgłoszenia">
        <thead class="table-light">
          <tr>
            <th scope="col">Data</th>
            <th scope="col">Autor</th>
            <th scope="col">Zmiana statusu</th>
            <th scope="col">Nota</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($log as $entry): ?>
          <tr>
            <td class="text-nowrap text-secondary small"><?= h(substr((string)$entry['created_at'], 0, 16)) ?></td>
            <td class="small"><?= $entry['actor_name'] !== '' ? h($entry['actor_name']) : '<span class="text-secondary">formularz</span>' ?></td>
            <td class="text-nowrap small">
              <?php if ($entry['status_from'] !== '' && $entry['status_from'] !== $entry['status_to']): ?>
                <span class="badge <?= h(asr_status_class($entry['status_from'])) ?> me-1"><?= h(asr_label($statuses, $entry['status_from'])) ?></span>
                <i class="bi bi-arrow-right text-secondary" aria-label="→"></i>
                <span class="badge <?= h(asr_status_class($entry['status_to'])) ?> ms-1"><?= h(asr_label($statuses, $entry['status_to'])) ?></span>
              <?php elseif ($entry['status_to'] !== ''): ?>
                <span class="badge <?= h(asr_status_class($entry['status_to'])) ?>"><?= h(asr_label($statuses, $entry['status_to'])) ?></span>
              <?php else: ?>
                <span class="text-secondary">—</span>
              <?php endif; ?>
            </td>
            <td class="small text-secondary"><?= h($entry['note']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>
<script>
(function () {
  var sel  = document.getElementById('status');
  var wrap = document.getElementById('rejection-reason-wrap');
  var ta   = document.getElementById('rejection_reason');
  if (!sel || !wrap) return;

  var REJECTION_STATUSES = ['rejected', 'rejected_external', 'volunteer_rejected'];

  function sync() {
    var isRejection = REJECTION_STATUSES.indexOf(sel.value) !== -1;
    wrap.style.display = isRejection ? '' : 'none';
    if (ta) ta.required = isRejection;
    if (isRejection && ta && wrap.style.display !== 'none') ta.focus();
  }

  sel.addEventListener('change', sync);
  sync(); // inicjalizacja przy ładowaniu (np. po błędzie walidacji)
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
