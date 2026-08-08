<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';

k30_require_access();
karty30_migrate();

if (!can_write('karty30') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do zapisu.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Nowa konsultacja — Dydaktyka 3';
$errors = [];

$prefill_client_id   = (int)($_GET['client_id']   ?? 0);
$prefill_schedule_id = (int)($_GET['schedule_id'] ?? 0);

$clients   = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$users     = k30_get_consultants();
$schedules = db_all(
    "SELECT s.id, s.start_time, s.duration_minutes, s.client_id, c.name AS client_name
     FROM k30_schedules s LEFT JOIN k30_clients c ON c.id=s.client_id
     WHERE s.status IN ('confirmed','attended')
     ORDER BY s.start_time DESC LIMIT 100"
);

// Mapa klientów z aktywnymi umowami PFRON (do sugestii w formularzu)
$pfron_client_map = [];
if (k30_pfron_enabled()) {
    foreach (db_all(
        "SELECT pc.id AS pfron_id, pc.client_id, pc.contract_number
         FROM k30_pfron_contracts pc WHERE pc.status='active'"
    ) as $pc) {
        $pfron_client_map[(int)$pc['client_id']] = [
            'pfron_id' => (int)$pc['pfron_id'],
            'contract' => $pc['contract_number'],
        ];
    }
}

// Build schedule map for AJAX autofill
$sched_map = [];
foreach ($schedules as $s) {
    $sched_map[$s['id']] = [
        'client_id'       => $s['client_id'],
        'datetime'        => str_replace(' ', 'T', substr($s['start_time'], 0, 16)),
        'duration_minutes'=> $s['duration_minutes'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $schedule_id   = (int)($_POST['schedule_id'] ?? 0) ?: null;
    $client_id     = (int)($_POST['client_id'] ?? 0);
    $consultant_id = (int)($_POST['consultant_id'] ?? 0) ?: null;
    $cons_dt       = trim($_POST['consultation_datetime'] ?? '');
    $duration      = (int)($_POST['duration_minutes'] ?? 0) ?: null;
    $description   = trim($_POST['description'] ?? '');
    $next_action   = trim($_POST['next_action'] ?? '');
    $status        = $_POST['status'] ?? 'draft';

    if (!$client_id) $errors[] = 'Wybierz beneficjenta.';
    if (!$cons_dt)   $errors[] = 'Data i czas konsultacji są wymagane.';
    if (!array_key_exists($status, K30_CONSULTATION_STATUSES)) $status = 'draft';

    // Normalize datetime-local to datetime
    $cons_dt_db = str_replace('T', ' ', $cons_dt) . (strlen($cons_dt) <= 16 ? ':00' : '');

    if (!$errors) {
        $id = db_insert('k30_consultations', [
            'schedule_id'          => $schedule_id,
            'client_id'            => $client_id,
            'consultant_id'        => $consultant_id,
            'consultation_datetime'=> $cons_dt_db,
            'duration_minutes'     => $duration,
            'description'          => $description ?: null,
            'next_action'          => $next_action ?: null,
            'status'               => $status,
            'created_by'           => current_user()['id'] ?? null,
        ]);
        flash_set('success', 'Konsultacja została dodana.');
        header('Location: view.php?id=' . $id);
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item"><a href="index.php">Konsultacje</a></li>
    <li class="breadcrumb-item active">Nowa</li>
  </ol>
</nav>

<h4 class="fw-bold mb-4"><i class="bi bi-clipboard2-plus text-secondary me-2"></i>Nowa konsultacja</h4>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:650px">
  <div class="card-body">
    <form method="post" id="cons-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label">Powiąż z terminem harmonogramu (opcjonalnie)</label>
        <select name="schedule_id" id="schedule_id" class="form-select">
          <option value="">— Ręcznie (bez terminu) —</option>
          <?php foreach ($schedules as $s): ?>
          <option value="<?= (int)$s['id'] ?>"
                  data-client="<?= (int)$s['client_id'] ?>"
                  data-dt="<?= h(str_replace(' ', 'T', substr($s['start_time'], 0, 16))) ?>"
                  data-dur="<?= (int)$s['duration_minutes'] ?>"
                  <?= (int)($_POST['schedule_id'] ?? $prefill_schedule_id) === (int)$s['id'] ? 'selected' : '' ?>>
            <?= h($s['client_name']) ?> — <?= date('d.m.Y H:i', strtotime($s['start_time'])) ?> (<?= (int)$s['duration_minutes'] ?> min)
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Wybranie terminu auto-wypełni pola poniżej.</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
        <select name="client_id" id="client_id" class="form-select" required>
          <option value="">— Wybierz beneficjenta —</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)($_POST['client_id'] ?? $prefill_client_id) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if (k30_pfron_enabled()): ?>
      <!-- Sugestia PFRON — widoczna gdy wybrany beneficjent ma aktywną umowę PFRON -->
      <div id="pfron-suggestion" class="alert alert-warning d-none d-flex gap-2 align-items-start py-2 mb-3" role="status" aria-live="polite">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div class="small">
          <strong>Ten beneficjent ma aktywną umowę PFRON</strong>
          (<span id="pfron-suggestion-no" class="font-monospace"></span>).
          Szkolenie PFRON to odrębny rodzaj świadczenia — czy na pewno chodzi o konsultację, a nie o zajęcia PFRON?
          <a id="pfron-suggestion-link" href="#" class="alert-link fw-semibold d-block mt-1">
            <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Przejdź do szkolenia PFRON →
          </a>
        </div>
        <button type="button" class="btn-close btn-sm ms-auto flex-shrink-0" aria-label="Zamknij"
                onclick="document.getElementById('pfron-suggestion').classList.add('d-none')"></button>
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label">Konsultant</label>
        <select name="consultant_id" class="form-select">
          <option value="">— Nie przypisano —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($_POST['consultant_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-8">
          <label class="form-label fw-semibold">Data i czas konsultacji <span class="text-danger">*</span></label>
          <input name="consultation_datetime" id="cons_dt" type="datetime-local" class="form-control" required
                 value="<?= h($_POST['consultation_datetime'] ?? date('Y-m-d\TH:i')) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Czas trwania (min)</label>
          <input name="duration_minutes" id="cons_dur" type="number" min="15" step="15" class="form-control"
                 value="<?= h($_POST['duration_minutes'] ?? '60') ?>">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Opis konsultacji</label>
        <textarea name="description" class="form-control" rows="4"><?= h($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label">Następne działania</label>
        <textarea name="next_action" class="form-control" rows="2"><?= h($_POST['next_action'] ?? '') ?></textarea>
      </div>

      <div class="mb-4">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach (K30_CONSULTATION_STATUSES as $sk => $sv): ?>
          <option value="<?= $sk ?>" <?= ($_POST['status'] ?? 'draft') === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('schedule_id').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt.value) {
        document.getElementById('client_id').value  = opt.dataset.client || '';
        document.getElementById('cons_dt').value    = opt.dataset.dt    || '';
        document.getElementById('cons_dur').value   = opt.dataset.dur   || '';
    }
});
// Auto-fill if prefilled
(function() {
    const sel = document.getElementById('schedule_id');
    if (sel.value) sel.dispatchEvent(new Event('change'));
})();

// Sugestia PFRON przy wyborze beneficjenta
(function() {
    const pfronMap = <?= json_encode($pfron_client_map, JSON_HEX_TAG) ?>;
    const appUrl   = <?= json_encode(APP_URL) ?>;

    function checkPfron(clientId) {
        const data = pfronMap[parseInt(clientId)];
        const box  = document.getElementById('pfron-suggestion');
        if (!box) return;
        if (data) {
            document.getElementById('pfron-suggestion-no').textContent = data.contract;
            document.getElementById('pfron-suggestion-link').href =
                appUrl + '/karty30/pfron/training.php?pfron_id=' + data.pfron_id + '&client_id=' + clientId;
            box.classList.remove('d-none');
        } else {
            box.classList.add('d-none');
        }
    }

    document.getElementById('client_id').addEventListener('change', function() {
        checkPfron(this.value);
    });
    // Sprawdź przy załadowaniu jeśli klient był wstępnie wybrany
    checkPfron(document.getElementById('client_id').value);
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
