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

$PAGE_TITLE = 'Szybka rezerwacja — Karty 30';
$errors  = [];
$success = null;

$clients = db_all("SELECT id, name FROM k30_clients WHERE status IN ('enrolled','learning') ORDER BY name");
$users   = k30_get_consultants();

// Zaproponuj najbliższe sloty (następne 7 dni, co godzinę 9-17)
$suggested_slots = [];
for ($day = 0; $day < 7; $day++) {
    $date = date('Y-m-d', strtotime("+$day days"));
    $dow  = (int)date('N', strtotime($date)); // 1=Mon, 7=Sun
    if ($dow >= 6) continue; // skip weekends
    for ($hour = 9; $hour <= 16; $hour++) {
        $dt    = $date . ' ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00:00';
        $taken = (int)(db_one("SELECT COUNT(*) AS c FROM k30_schedules WHERE start_time=? AND status IN ('preliminary','confirmed')", [$dt])['c'] ?? 0);
        if (!$taken) {
            $suggested_slots[] = $dt;
            if (count($suggested_slots) >= 6) break 2;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $client_id   = (int)($_POST['client_id'] ?? 0);
    $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $start_time  = trim($_POST['start_time'] ?? '');
    $duration    = (int)($_POST['duration_minutes'] ?? 60);

    if (!$client_id)  $errors[] = 'Wybierz beneficjenta.';
    if (!$start_time) $errors[] = 'Wybierz termin.';

    if (!$errors) {
        $new_id = db_insert('k30_schedules', [
            'client_id'        => $client_id,
            'assigned_to'      => $assigned_to,
            'start_time'       => $start_time,
            'duration_minutes' => $duration,
            'status'           => 'confirmed',
            'created_by'       => current_user()['id'] ?? null,
        ]);
        flash_set('success', 'Termin zarezerwowany.');
        header('Location: view.php?id=' . $new_id);
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <li class="breadcrumb-item"><a href="index.php">Harmonogram</a></li>
    <li class="breadcrumb-item active">Szybka rezerwacja</li>
  </ol>
</nav>

<h4 class="fw-bold mb-4"><i class="bi bi-lightning-charge text-warning me-2"></i>Szybka rezerwacja</h4>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.85rem">Formularz rezerwacji</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
            <select name="client_id" class="form-select" required>
              <option value="">— Wybierz —</option>
              <?php foreach ($clients as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($_POST['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Konsultant</label>
            <select name="assigned_to" class="form-select">
              <option value="">— Nie przypisano —</option>
              <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)($_POST['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Termin <span class="text-danger">*</span></label>
            <input name="start_time" type="datetime-local" class="form-control" required value="<?= h($_POST['start_time'] ?? '') ?>">
          </div>

          <div class="mb-4">
            <label class="form-label">Czas trwania (minuty)</label>
            <input name="duration_minutes" type="number" min="15" step="15" class="form-control" value="<?= h($_POST['duration_minutes'] ?? '60') ?>">
          </div>

          <button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-lightning-charge me-1"></i>Zarezerwuj</button>
        </form>
      </div>
    </div>
  </div>

  <?php if ($suggested_slots): ?>
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-clock me-1"></i>Sugerowane wolne terminy</div>
      <div class="list-group list-group-flush">
        <?php foreach ($suggested_slots as $slot): ?>
        <button type="button"
                class="list-group-item list-group-item-action slot-btn"
                data-dt="<?= h(str_replace(' ', 'T', substr($slot, 0, 16))) ?>"
                style="font-size:.85rem">
          <i class="bi bi-calendar-check text-success me-2"></i>
          <?= date('l, d.m.Y H:i', strtotime($slot)) ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.slot-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelector('[name="start_time"]').value = btn.dataset.dt;
        btn.classList.add('active');
        document.querySelectorAll('.slot-btn').forEach(b => { if (b !== btn) b.classList.remove('active'); });
    });
});
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
