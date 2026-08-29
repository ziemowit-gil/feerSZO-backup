<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';

k30_require_access();
karty30_migrate();

$id = (int)($_GET['id'] ?? 0);
$client = db_one("SELECT * FROM k30_clients WHERE id=?", [$id]);
if (!$client) {
    flash_set('danger', 'Beneficjent nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = h($client['name']) . ' — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

// RODO: rejestr dostępu do danych wrażliwych beneficjenta
if ($_SERVER['REQUEST_METHOD'] === 'GET') k30_log_access('client', $id, 'view', $client['name'] ?? '');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'blacklist_add' && $can_write) {
        $reason = trim($_POST['reason'] ?? '');
        try {
            db()->prepare("INSERT OR IGNORE INTO k30_blacklist (client_id, reason, added_by) VALUES (?,?,?)")
                ->execute([$id, $reason ?: null, current_user()['id'] ?? null]);
            flash_set('success', 'Beneficjent dodany do czarnej listy.');
        } catch (\Exception $e) {
            flash_set('danger', 'Błąd: ' . $e->getMessage());
        }
        header('Location: view.php?id=' . $id);
        exit;
    }

    if ($action === 'blacklist_remove' && $can_write) {
        db()->prepare("DELETE FROM k30_blacklist WHERE client_id=?")->execute([$id]);
        flash_set('success', 'Beneficjent usunięty z czarnej listy.');
        header('Location: view.php?id=' . $id);
        exit;
    }

    // Umowy PFRON
    if ($action === 'pfron_save' && $can_write) {
        $pid     = (int)($_POST['pfron_id'] ?? 0);
        $cn      = trim($_POST['contract_number'] ?? '');
        $limit   = max(0, (float)str_replace(',','.', $_POST['hours_limit'] ?? '0'));
        $vfrom   = trim($_POST['valid_from'] ?? '') ?: null;
        $vto     = trim($_POST['valid_to']   ?? '') ?: null;
        $pstatus = in_array($_POST['pfron_contract_status'] ?? '', ['active','expired','closed'], true)
                   ? $_POST['pfron_contract_status'] : 'active';
        $notes       = trim($_POST['pfron_notes'] ?? '');
        $planEnabled = !empty($_POST['hours_plan_enabled']) ? 1 : 0;
        $planRemote  = max(0, (float)str_replace(',','.', $_POST['planned_hours_remote'] ?? '0'));
        $planOnsite  = max(0, (float)str_replace(',','.', $_POST['planned_hours_onsite']  ?? '0'));
        if (!$cn) { flash_set('danger','Numer umowy PFRON jest wymagany.'); header('Location: view.php?id='.$id.'#pfron'); exit; }
        if ($planEnabled && $limit > 0 && ($planRemote + $planOnsite) > $limit) {
            flash_set('danger', sprintf(
                'Suma godzin planu (%s) przekracza limit umowy (%s h).',
                number_format($planRemote + $planOnsite, 2, ',', ''), number_format($limit, 2, ',', '')
            ));
            header('Location: view.php?id='.$id.'&pfron_edit='.($pid ?: 0).'#pfron'); exit;
        }
        k30_pfron_contract_save([
            'client_id'            => $id,
            'contract_number'      => $cn,
            'hours_limit'          => $limit,
            'valid_from'           => $vfrom,
            'valid_to'             => $vto,
            'status'               => $pstatus,
            'notes'                => $notes,
            'hours_plan_enabled'   => $planEnabled,
            'planned_hours_remote' => $planEnabled ? $planRemote : 0,
            'planned_hours_onsite' => $planEnabled ? $planOnsite : 0,
        ], $pid ?: null);
        flash_set('success', 'Umowa PFRON zapisana.');
        header('Location: view.php?id='.$id.'#pfron'); exit;
    }
    if ($action === 'pfron_delete' && $can_write) {
        $pid = (int)($_POST['pfron_id'] ?? 0);
        if ($pid) db()->prepare("DELETE FROM k30_pfron_contracts WHERE id=? AND client_id=?")->execute([$pid,$id]);
        flash_set('success', 'Umowa PFRON usunięta.');
        header('Location: view.php?id='.$id.'#pfron'); exit;
    }
    if ($action === 'pfron_set_status' && $can_write) {
        $sid = (int)($_POST['schedule_id'] ?? 0);
        $pst = array_key_exists($_POST['pfron_status_val'] ?? '', K30_PFRON_STATUSES)
               ? $_POST['pfron_status_val'] : 'pending';
        if ($sid) db()->prepare("UPDATE k30_schedules SET pfron_status=?,updated_at=datetime('now') WHERE id=?")->execute([$pst,$sid]);
        header('Location: view.php?id='.$id.'#pfron'); exit;
    }
}

$schedules     = db_all("SELECT s.*, u.name AS consultant_name FROM k30_schedules s LEFT JOIN users u ON u.id=s.assigned_to WHERE s.client_id=? ORDER BY s.start_time DESC LIMIT 20", [$id]);
$consultations = db_all("SELECT co.*, u.name AS consultant_name FROM k30_consultations co LEFT JOIN users u ON u.id=co.consultant_id WHERE co.client_id=? ORDER BY co.consultation_datetime DESC LIMIT 20", [$id]);
$blacklist     = db_one("SELECT * FROM k30_blacklist WHERE client_id=?", [$id]);
$remaining     = max(0, (float)$client['available_hours'] - (float)$client['used']);

$gender_labels = ['male' => 'Mężczyzna', 'female' => 'Kobieta', 'other' => 'Inne'];
$pcm_labels    = ['email' => 'Email', 'phone' => 'Telefon', 'sms' => 'SMS'];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item"><a href="index.php">Beneficjenci</a></li>
    <li class="breadcrumb-item active"><?= h($client['name']) ?></li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <div>
    <h4 class="mb-0 fw-bold">
      <?= h($client['name']) ?>
      <?= k30_status_badge($client['status'], 'client') ?>
      <?php if ($blacklist): ?>
      <span class="badge bg-danger ms-1">czarna lista</span>
      <?php endif; ?>
    </h4>
    <div class="text-muted small">Dodany: <?= date('d.m.Y', strtotime($client['created_at'])) ?></div>
  </div>
  <?php if ($can_write): ?>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
    <a href="<?= APP_URL ?>/karty30/schedules/add.php?client_id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Nowy termin</a>
    <a href="<?= APP_URL ?>/karty30/consultations/add.php?client_id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard2-plus me-1"></i>Nowa konsultacja</a>
    <a href="card_print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i>Drukuj kartę</a>
    <?php
    $in_queue = db_one("SELECT id FROM k30_waiting_list WHERE client_id=? AND status IN ('waiting','contacted')", [$id]);
    if ($in_queue): ?>
    <span class="badge bg-warning text-dark ms-1" title="Na liście oczekujących">
      <i class="bi bi-hourglass-split me-1"></i>Oczekuje
    </span>
    <?php else: ?>
    <a href="<?= APP_URL ?>/karty30/waiting/index.php?add_client=<?= $id ?>#add"
       class="btn btn-outline-warning btn-sm" title="Dodaj do kolejki oczekujących">
      <i class="bi bi-hourglass-split me-1"></i>Dodaj do kolejki
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3">
  <!-- Dane klienta -->
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-person me-1"></i>Dane osobowe</div>
      <div class="card-body" style="font-size:.875rem">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><th class="text-muted fw-normal" style="width:130px">Email</th><td><?= $client['email'] ? '<a href="mailto:'.h($client['email']).'">'.h($client['email']).'</a>' : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Telefon</th><td><?= $client['phone'] ? h($client['phone']) : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Data ur.</th><td><?= $client['date_of_birth'] ? date('d.m.Y', strtotime($client['date_of_birth'])) : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Płeć</th><td><?= $gender_labels[$client['gender'] ?? ''] ?? '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Adres</th><td><?= $client['address'] ? h($client['address']) : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Kontakt</th><td><?= $pcm_labels[$client['preferred_contact_method'] ?? 'email'] ?? '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Zgoda RODO</th><td><?= $client['consent'] ? '<span class="text-success">Tak</span>' : '<span class="text-danger">Nie</span>' ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-clock-history me-1"></i>Godziny</div>
      <div class="card-body text-center">
        <div style="font-size:2rem;font-weight:800;color:#2E844A"><?= number_format($remaining, 1) ?></div>
        <div class="text-muted small">pozostałych z <?= number_format((float)$client['available_hours'], 1) ?> h</div>
        <?php if ($client['available_hours'] > 0): ?>
        <div style="height:6px;background:#F3F4F6;border-radius:3px;margin-top:.5rem">
          <div style="height:6px;border-radius:3px;background:#2E844A;width:<?= min(100, round($client['used'] / $client['available_hours'] * 100)) ?>%"></div>
        </div>
        <div class="text-muted small mt-1">Wykorzystano: <?= number_format((float)$client['used'], 1) ?> h</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($client['problem']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-exclamation-circle me-1"></i>Problem / potrzeba</div>
      <div class="card-body" style="font-size:.875rem"><?= nl2br(h($client['problem'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($client['equipment']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-pc-display me-1"></i>Sprzęt</div>
      <div class="card-body" style="font-size:.875rem"><?= nl2br(h($client['equipment'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($client['notes']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-sticky me-1"></i>Notatki</div>
      <div class="card-body" style="font-size:.875rem"><?= nl2br(h($client['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Czarna lista -->
    <?php if ($can_write): ?>
    <div class="card shadow-sm border-<?= $blacklist ? 'danger' : 'secondary' ?> mb-3">
      <div class="card-header fw-semibold text-<?= $blacklist ? 'danger' : 'secondary' ?>" style="font-size:.85rem">
        <i class="bi bi-slash-circle me-1"></i>Czarna lista
      </div>
      <div class="card-body">
        <?php if ($blacklist): ?>
        <p class="small mb-2 text-danger"><?= $blacklist['reason'] ? h($blacklist['reason']) : 'Bez powodu.' ?></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="blacklist_remove">
          <button type="submit" class="btn btn-outline-secondary btn-sm">Usuń z czarnej listy</button>
        </form>
        <?php else: ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="blacklist_add">
          <input name="reason" class="form-control form-control-sm mb-2" placeholder="Powód (opcjonalnie)">
          <button type="submit" class="btn btn-danger btn-sm">Dodaj do czarnej listy</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Terminy i konsultacje -->
  <div class="col-lg-8">
    <!-- Terminy -->
    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex align-items-center justify-content-between fw-semibold" style="font-size:.85rem">
        <span><i class="bi bi-calendar3 me-1"></i>Terminy harmonogramu</span>
        <?php if ($can_write): ?>
        <a href="<?= APP_URL ?>/karty30/schedules/add.php?client_id=<?= $id ?>" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:.72rem"><i class="bi bi-plus"></i> Dodaj</a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
          <thead class="table-light">
            <tr><th>Data / czas</th><th>Czas trwania</th><th>Konsultant</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($schedules as $s): ?>
            <tr>
              <td><?= date('d.m.Y H:i', strtotime($s['start_time'])) ?></td>
              <td><?= (int)$s['duration_minutes'] ?> min</td>
              <td><?= $s['consultant_name'] ? h($s['consultant_name']) : '—' ?></td>
              <td><?= k30_status_badge($s['status']) ?></td>
              <td><a href="<?= APP_URL ?>/karty30/schedules/view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$schedules): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">Brak terminów.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Konsultacje -->
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between fw-semibold" style="font-size:.85rem">
        <span><i class="bi bi-clipboard2 me-1"></i>Konsultacje</span>
        <?php if ($can_write): ?>
        <a href="<?= APP_URL ?>/karty30/consultations/add.php?client_id=<?= $id ?>" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:.72rem"><i class="bi bi-plus"></i> Dodaj</a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
          <thead class="table-light">
            <tr><th>Data</th><th>Czas trwania</th><th>Konsultant</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($consultations as $c): ?>
            <tr>
              <td><?= date('d.m.Y H:i', strtotime($c['consultation_datetime'])) ?></td>
              <td><?= $c['duration_minutes'] ? (int)$c['duration_minutes'] . ' min' : '—' ?></td>
              <td><?= $c['consultant_name'] ? h($c['consultant_name']) : '—' ?></td>
              <td><?= k30_status_badge($c['status'], 'consultation') ?></td>
              <td><a href="<?= APP_URL ?>/karty30/consultations/view.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$consultations): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">Brak konsultacji.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── Umowy PFRON ──────────────────────────────────────────────────────────── -->
<?php if (k30_pfron_enabled()): ?>
<?php
  $pfron_contracts = k30_pfron_contracts_for_client($id);
  $pfron_edit_id   = (int)($_GET['pfron_edit'] ?? 0);
  $pfron_edit_row  = $pfron_edit_id ? k30_pfron_contract_get($pfron_edit_id) : null;
?>
<div class="card border-0 shadow-sm mt-4" id="pfron">
  <div class="card-header d-flex align-items-center gap-2 fw-semibold" style="background:#f5f3ff;border-bottom:2px solid #7c3aed20">
    <i class="bi bi-building-fill-check text-purple" style="color:#7c3aed"></i>
    Umowy PFRON
    <a href="?id=<?= $id ?>&pfron_edit=0#pfron" id="pfron-add-btn" class="btn btn-sm btn-outline-secondary ms-auto py-0 px-2">
      <i class="bi bi-plus-lg me-1"></i>Dodaj umowę
    </a>
  </div>
  <div class="card-body">

    <?php if (isset($_GET['pfron_edit'])): ?>
    <!-- Formularz umowy PFRON -->
    <?php $fe = $pfron_edit_row ?? ['contract_number'=>'','hours_limit'=>0,'valid_from'=>'','valid_to'=>'','status'=>'active','notes'=>'',
                                     'hours_plan_enabled'=>0,'planned_hours_remote'=>0,'planned_hours_onsite'=>0]; ?>
    <form method="post" class="mb-4">
      <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action"  value="pfron_save">
      <input type="hidden" name="pfron_id" value="<?= (int)($fe['id']??0) ?>">
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Numer umowy PFRON <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="contract_number"
                 value="<?= h($fe['contract_number']) ?>" placeholder="np. PFRON/2024/00123" required>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Limit godzin</label>
          <div class="input-group">
            <input type="number" id="pfron_hours_limit" class="form-control" name="hours_limit" min="0" step="0.5"
                   value="<?= h($fe['hours_limit']) ?>">
            <span class="input-group-text">h</span>
          </div>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Status</label>
          <select class="form-select" name="pfron_contract_status">
            <?php foreach (K30_PFRON_CONTRACT_STATUSES as $sk => $sv): ?>
            <option value="<?= h($sk) ?>" <?= ($fe['status']??'active')===$sk?'selected':'' ?>><?= h($sv['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label">Ważna od</label>
          <input type="date" class="form-control" name="valid_from" value="<?= h($fe['valid_from']??'') ?>">
        </div>
        <div class="col-sm-3">
          <label class="form-label">Ważna do</label>
          <input type="date" class="form-control" name="valid_to" value="<?= h($fe['valid_to']??'') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Uwagi</label>
          <input type="text" class="form-control" name="pfron_notes" value="<?= h($fe['notes']??'') ?>">
        </div>
        <div class="col-12">
          <div class="form-check">
            <input type="checkbox" class="form-check-input" id="pfron_plan_enabled" name="hours_plan_enabled" value="1"
                   <?= !empty($fe['hours_plan_enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="pfron_plan_enabled">
              Planowana siatka godzin (podział zdalnie / stacjonarnie)
            </label>
          </div>
          <div class="form-text">
            Rozbija limit godzin na plan zdalny i stacjonarny. Widok zbiorczy dla wszystkich
            beneficjentów z włączoną siatką: <a href="<?= APP_URL ?>/karty30/pfron/siatka.php" target="_blank">Planowana siatka godzin PFRON</a>.
          </div>
        </div>
        <div class="col-sm-4" id="pfron_plan_remote_wrap" style="<?= empty($fe['hours_plan_enabled']) ? 'display:none' : '' ?>">
          <label class="form-label">Planowane godziny — zdalnie</label>
          <div class="input-group">
            <input type="number" class="form-control pfron-plan-input" name="planned_hours_remote" min="0" step="0.5"
                   value="<?= h($fe['planned_hours_remote']) ?>">
            <span class="input-group-text">h</span>
          </div>
        </div>
        <div class="col-sm-4" id="pfron_plan_onsite_wrap" style="<?= empty($fe['hours_plan_enabled']) ? 'display:none' : '' ?>">
          <label class="form-label">Planowane godziny — stacjonarnie</label>
          <div class="input-group">
            <input type="number" class="form-control pfron-plan-input" name="planned_hours_onsite" min="0" step="0.5"
                   value="<?= h($fe['planned_hours_onsite']) ?>">
            <span class="input-group-text">h</span>
          </div>
        </div>
        <div class="col-sm-4 d-flex align-items-end" id="pfron_plan_sum_wrap" style="<?= empty($fe['hours_plan_enabled']) ? 'display:none' : '' ?>">
          <div class="text-body-secondary small" id="pfron_plan_sum">Razem: 0 h</div>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">Zapisz umowę</button>
        <a href="?id=<?= $id ?>#pfron" class="btn btn-outline-secondary btn-sm">Anuluj</a>
      </div>
    </form>
    <script>
    (function () {
      var chk = document.getElementById('pfron_plan_enabled');
      var wraps = ['pfron_plan_remote_wrap', 'pfron_plan_onsite_wrap', 'pfron_plan_sum_wrap'].map(function (id) {
        return document.getElementById(id);
      });
      var sumEl = document.getElementById('pfron_plan_sum');
      var inputs = document.querySelectorAll('.pfron-plan-input');
      function recalc() {
        var sum = 0;
        inputs.forEach(function (i) { sum += parseFloat((i.value || '0').replace(',', '.')) || 0; });
        sumEl.textContent = 'Razem: ' + sum.toLocaleString('pl-PL', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + ' h';
      }
      function toggle() {
        wraps.forEach(function (w) { if (w) w.style.display = chk.checked ? '' : 'none'; });
        if (chk.checked) recalc();
      }
      if (chk) {
        chk.addEventListener('change', toggle);
        inputs.forEach(function (i) { i.addEventListener('input', recalc); });
        recalc();
      }
    })();
    </script>
    <?php endif; ?>

    <?php if (!$pfron_contracts): ?>
    <div role="status" aria-live="polite" aria-atomic="true"
         class="alert alert-warning d-flex gap-3 align-items-start mb-0" id="pfron-no-contracts-alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <div>
        <strong>Brak umowy PFRON</strong> — aby wygenerować dokumenty (umowę uczestnictwa i regulamin),
        najpierw dodaj umowę PFRON korzystając z formularza powyżej.
        <button type="button" class="btn btn-sm btn-warning ms-2 fw-semibold"
                onclick="document.getElementById('pfron-add-btn')?.click(); document.getElementById('pfron-add-btn')?.focus();"
                aria-describedby="pfron-no-contracts-alert">
          <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj umowę PFRON
        </button>
      </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.87rem">
        <thead class="table-light">
          <tr><th>Numer umowy</th><th>Limit</th><th>Wykorzystano</th><th>Pozostało</th><th>Ważność</th><th>Status</th><th class="text-end">Akcje</th></tr>
        </thead>
        <tbody>
          <?php foreach ($pfron_contracts as $pc):
            $remaining = max(0, (float)$pc['hours_limit'] - (float)$pc['hours_used']);
            $pct       = $pc['hours_limit'] > 0 ? min(100, round($pc['hours_used']/$pc['hours_limit']*100)) : 0;
            $ps        = K30_PFRON_CONTRACT_STATUSES[$pc['status']] ?? ['label'=>$pc['status'],'color'=>'#666','bg'=>'#eee'];
          ?>
          <tr>
            <td class="fw-semibold font-monospace">
              <?= h($pc['contract_number']) ?>
              <?php if (!empty($pc['hours_plan_enabled'])): ?>
              <br><span class="badge text-bg-light border" style="font-size:.68rem;font-weight:500">
                <i class="bi bi-laptop"></i> <?= number_format((float)$pc['planned_hours_remote'],1,',','') ?> h
                &nbsp;/&nbsp;<i class="bi bi-building"></i> <?= number_format((float)$pc['planned_hours_onsite'],1,',','') ?> h
              </span>
              <?php endif; ?>
            </td>
            <td><?= number_format((float)$pc['hours_limit'],2,',','') ?> h</td>
            <td>
              <?= number_format((float)$pc['hours_used'],2,',','') ?> h
              <div class="progress mt-1" style="height:4px;width:80px">
                <div class="progress-bar <?= $pct>=90?'bg-danger':($pct>=70?'bg-warning':'bg-success') ?>" style="width:<?= $pct ?>%"></div>
              </div>
            </td>
            <td class="<?= $remaining<=0?'text-danger fw-bold':'' ?>">
              <?= number_format($remaining,2,',','') ?> h
            </td>
            <td class="text-muted">
              <?= $pc['valid_from'] ? h($pc['valid_from']) : '—' ?>
              <?= $pc['valid_to']   ? ' – '.h($pc['valid_to']) : '' ?>
            </td>
            <td>
              <span class="badge" style="background:<?= h($ps['bg']) ?>;color:<?= h($ps['color']) ?>;border:1px solid <?= h($ps['color']) ?>33">
                <?= h($ps['label']) ?>
              </span>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/karty30/pfron/docs.php?pfron_id=<?= (int)$pc['id'] ?>&client_id=<?= $id ?>"
                 class="btn btn-xs btn-sm btn-outline-danger py-0 px-2 me-1" title="Generuj dokumenty PDF"
                 target="_blank">
                <i class="bi bi-file-earmark-pdf"></i>
              </a>
              <a href="?id=<?= $id ?>&pfron_edit=<?= (int)$pc['id'] ?>#pfron"
                 class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2 me-1">
                <i class="bi bi-pencil"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę umowę PFRON?')">
                <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_action"  value="pfron_delete">
                <input type="hidden" name="pfron_id" value="<?= (int)$pc['id'] ?>">
                <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Statusy PFRON terminów — tylko jeśli są terminy PFRON -->
<?php
  $pfron_schedules = db_all(
      "SELECT s.id, s.start_time, s.billed_hours, s.free_hours, s.amount_due,
              s.pfron_status, s.pricing_note,
              pc.contract_number
       FROM k30_schedules s
       LEFT JOIN k30_pfron_contracts pc ON pc.id=s.pfron_contract_id
       WHERE s.client_id=? AND s.billing_type='pfron'
       ORDER BY s.start_time DESC",
      [$id]
  );
?>
<?php if ($pfron_schedules && $can_write): ?>
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header fw-semibold" style="background:#f5f3ff">
    <i class="bi bi-list-check me-1" style="color:#7c3aed"></i>Terminy PFRON — statusy rozliczenia
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr><th>Termin</th><th>Umowa</th><th>Godziny</th><th>Kwota</th><th>Status PFRON</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($pfron_schedules as $ps_row):
          $pst = K30_PFRON_STATUSES[$ps_row['pfron_status'] ?? 'pending'] ?? K30_PFRON_STATUSES['pending'];
        ?>
        <tr>
          <td><a href="<?= APP_URL ?>/karty30/schedules/view.php?id=<?= (int)$ps_row['id'] ?>">
            <?= date('d.m.Y H:i', strtotime($ps_row['start_time'])) ?>
          </a></td>
          <td class="font-monospace text-muted small"><?= h($ps_row['contract_number'] ?? '—') ?></td>
          <td><?= number_format((float)$ps_row['billed_hours'],2,',','') ?> h
            (<?= number_format((float)$ps_row['free_hours'],2,',','') ?> PFRON)</td>
          <td><?= (float)$ps_row['amount_due']>0 ? number_format((float)$ps_row['amount_due'],2,',','').' zł' : '—' ?></td>
          <td>
            <span class="badge" style="background:<?= h($pst['bg']) ?>;color:<?= h($pst['color']) ?>;border:1px solid <?= h($pst['color']) ?>44;font-size:.75rem">
              <?= h($pst['label']) ?>
            </span>
          </td>
          <td class="text-end">
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action"  value="pfron_set_status">
              <input type="hidden" name="schedule_id" value="<?= (int)$ps_row['id'] ?>">
              <select name="pfron_status_val" class="form-select form-select-sm d-inline-block"
                      style="width:auto;font-size:.77rem"
                      onchange="this.form.submit()">
                <?php foreach (K30_PFRON_STATUSES as $sk => $sv): ?>
                <option value="<?= h($sk) ?>" <?= ($ps_row['pfron_status']??'pending')===$sk?'selected':'' ?>><?= h($sv['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; // k30_pfron_enabled ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
