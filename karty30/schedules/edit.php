<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

if (!can_write('karty30') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do zapisu.');
    header('Location: index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$schedule = db_one("SELECT s.*, c.name AS client_name FROM k30_schedules s LEFT JOIN k30_clients c ON c.id=s.client_id WHERE s.id=?", [$id]);
if (!$schedule) {
    flash_set('danger', 'Termin nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Edycja terminu — Karty 30';
$errors = [];

$clients = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$users   = k30_get_consultants();

// Quick status change actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    $quick_actions = ['attended','cancelled_by_feer','cancelled_by_client','no_show','confirmed','cancelled'];
    if (in_array($action, $quick_actions)) {
        $cancel_reason = trim($_POST['cancel_reason'] ?? '');
        db()->prepare("UPDATE k30_schedules SET status=?, cancel_reason=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$action, $cancel_reason ?: null, $id]);

        // Synchronizuj z CRM jako aktywność
        $sch_client = db_one("SELECT k.* FROM k30_clients k JOIN k30_schedules s ON s.client_id=k.id WHERE s.id=?", [$id]);
        if ($sch_client) {
            $uid = (int)(current_user()['id'] ?? 0);
            $crm_cid = k30_get_crm_contact($sch_client);
            if (!$crm_cid) $crm_cid = k30_sync_to_crm($sch_client, $uid);
            if ($crm_cid) {
                $type_map = [
                    'attended'           => ['meeting','Odbyła się wizyta TyfloK.','done'],
                    'cancelled_by_feer'  => ['task',  'Odwołano wizytę (FEER)','done'],
                    'cancelled_by_client'=> ['task',  'Odwołano wizytę (beneficjent)','done'],
                    'no_show'            => ['call',  'Beneficjent nie pojawił się','done'],
                    'confirmed'          => ['meeting','Potwierdzono wizytę TyfloK.','planned'],
                    'cancelled'          => ['task',  'Anulowano wizytę TyfloK.','done'],
                ];
                [$act_type, $act_title, $act_status] = $type_map[$action] ?? ['task','Zmiana statusu wizyty','done'];
                $sched_row = db_one("SELECT start_time FROM k30_schedules WHERE id=?", [$id]);
                k30_log_crm_activity(
                    $crm_cid, $act_type, $act_title,
                    $cancel_reason,
                    $sched_row['start_time'] ?? '',
                    $act_status,
                    $cancel_reason ?: '',
                    $uid
                );
            }
        }

        flash_set('success', 'Status terminu zaktualizowany.');
        header('Location: view.php?id=' . $id);
        exit;
    }

    // Regular form save
    $client_id   = (int)($_POST['client_id'] ?? 0);
    $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $date        = trim($_POST['date'] ?? '');
    $time        = trim($_POST['time'] ?? '');
    $duration    = (int)($_POST['duration_minutes'] ?? 60);
    $status      = $_POST['status'] ?? 'preliminary';
    $description = trim($_POST['description'] ?? '');

    if (!$client_id) $errors[] = 'Wybierz beneficjenta.';
    if (!$date || !$time) $errors[] = 'Data i godzina są wymagane.';
    if ($duration <= 0) $duration = 60;
    if (!array_key_exists($status, K30_SCHEDULE_STATUSES)) $status = 'preliminary';

    if (!$errors) {
        $start_time = $date . ' ' . $time . ':00';
        db_update('k30_schedules', [
            'client_id'        => $client_id,
            'assigned_to'      => $assigned_to,
            'start_time'       => $start_time,
            'duration_minutes' => $duration,
            'status'           => $status,
            'description'      => $description ?: null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], $id);
        flash_set('success', 'Termin został zaktualizowany.');
        header('Location: view.php?id=' . $id);
        exit;
    }
    $schedule = array_merge($schedule, $_POST);
}

$dt = new DateTime($schedule['start_time']);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <li class="breadcrumb-item"><a href="index.php">Harmonogram</a></li>
    <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>">Termin</a></li>
    <li class="breadcrumb-item active">Edycja</li>
  </ol>
</nav>

<h1 class="k30-page-title mb-1">Edycja terminu</h1>
<p class="k30-page-subtitle mb-4">
  Beneficjent: <strong><?= h($schedule['client_name']) ?></strong> ·
  <?= $dt->format('d.m.Y') ?>, godzina <?= $dt->format('H:i') ?>
</p>

<!-- Szybka zmiana statusu — każdy przycisk ma pełny opis -->
<section aria-labelledby="status-heading" class="mb-4">
  <h2 id="status-heading" class="visually-hidden">Szybka zmiana statusu terminu</h2>
  <div class="card shadow-sm">
    <div class="card-header fw-bold" id="quick-status-desc">
      Zmień status wizyty
      <span class="text-muted fw-normal ms-2" style="font-size:.85rem">
        Obecny: <?= k30_status_badge($schedule['status']) ?>
      </span>
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3" id="quick-status-hint">
        Wybierz nowy status wizyty. Zmiany są natychmiastowe.
      </p>
      <div class="d-flex flex-wrap gap-2" role="group" aria-labelledby="status-heading" aria-describedby="quick-status-hint">
        <?php
        $quick = [
            'attended'           => ['Wizyta odbyła się',            'btn-primary',          'Oznacz wizytę jako odbytą — beneficjent uczestniczył'],
            'confirmed'          => ['Potwierdź wizytę',             'btn-success',          'Zmień status na: Potwierdzona — termin ustalony z beneficjentem'],
            'cancelled_by_feer'  => ['Odwołaj (ze strony FEER)',     'btn-danger',           'Odwołaj wizytę ze strony organizacji FEER'],
            'cancelled_by_client'=> ['Odwołaj (beneficjent)',        'btn-warning text-dark','Odwołaj wizytę — beneficjent poinformował o nieobecności'],
            'no_show'            => ['Beneficjent nie przyszedł',    'btn-secondary',        'Wizyta nie odbyła się — brak kontaktu z beneficjentem'],
            'cancelled'          => ['Anuluj termin',                'btn-outline-secondary','Anuluj termin całkowicie'],
        ];
        foreach ($quick as $st => [$lbl, $cls, $desc]):
            if ($schedule['status'] === $st) continue;
            $needs_reason = in_array($st, ['cancelled_by_feer','cancelled_by_client','cancelled']);
        ?>
        <form method="post" class="d-inline"
              <?= $needs_reason ? "onsubmit=\"var r=prompt('Powód odwołania (wymagany):');if(!r){return false;}this.querySelector('[name=cancel_reason]').value=r;\"" : '' ?>>
          <input type="hidden" name="_csrf"         value="<?= csrf_token() ?>">
          <input type="hidden" name="_action"        value="<?= h($st) ?>">
          <?php if ($needs_reason): ?>
          <input type="hidden" name="cancel_reason" value="">
          <?php endif; ?>
          <button type="submit"
                  class="btn btn-sm <?= $cls ?>"
                  aria-label="<?= h($desc) ?>"
                  title="<?= h($desc) ?>">
            <?= h($lbl) ?>
          </button>
        </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<?php if ($errors): ?>
<div class="k30-alert k30-alert-danger" role="alert" aria-label="Błędy formularza">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0"></i>
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:600px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
        <select name="client_id" class="form-select" required>
          <option value="">— Wybierz beneficjenta —</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$schedule['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Konsultant</label>
        <select name="assigned_to" class="form-select">
          <option value="">— Nie przypisano —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($schedule['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Data <span class="text-danger">*</span></label>
          <input name="date" type="date" class="form-control" required value="<?= $dt->format('Y-m-d') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Godzina <span class="text-danger">*</span></label>
          <input name="time" type="time" class="form-control" required value="<?= $dt->format('H:i') ?>">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label">Czas trwania (minuty)</label>
          <input name="duration_minutes" type="number" min="15" step="15" class="form-control" value="<?= h($schedule['duration_minutes'] ?? '60') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (K30_SCHEDULE_STATUSES as $sk => $sv): ?>
            <option value="<?= $sk ?>" <?= ($schedule['status'] ?? 'preliminary') === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label">Opis / uwagi</label>
        <textarea name="description" class="form-control" rows="3"><?= h($schedule['description'] ?? '') ?></textarea>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
