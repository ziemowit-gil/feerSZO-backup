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
$cons = db_one("SELECT * FROM k30_consultations WHERE id=?", [$id]);
if (!$cons) {
    flash_set('danger', 'Konsultacja nie istnieje.');
    header('Location: index.php');
    exit;
}
if ($cons['status'] === 'completed') {
    flash_set('warning', 'Zatwierdzone konsultacje nie mogą być edytowane.');
    header('Location: view.php?id=' . $id);
    exit;
}

$PAGE_TITLE = 'Edycja konsultacji — Karty 30';
$errors = [];

$clients = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$users   = k30_get_consultants();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $client_id     = (int)($_POST['client_id'] ?? 0);
    $consultant_id = (int)($_POST['consultant_id'] ?? 0) ?: null;
    $cons_dt       = trim($_POST['consultation_datetime'] ?? '');
    $duration      = (int)($_POST['duration_minutes'] ?? 0) ?: null;
    $description   = trim($_POST['description'] ?? '');
    $next_action   = trim($_POST['next_action'] ?? '');
    $status        = $_POST['status'] ?? 'draft';

    if (!$client_id) $errors[] = 'Wybierz beneficjenta.';
    if (!$cons_dt)   $errors[] = 'Data i czas są wymagane.';
    if (!array_key_exists($status, K30_CONSULTATION_STATUSES)) $status = 'draft';

    $cons_dt_db = str_replace('T', ' ', $cons_dt) . (strlen($cons_dt) <= 16 ? ':00' : '');

    if (!$errors) {
        db_update('k30_consultations', $id, [
            'client_id'            => $client_id,
            'consultant_id'        => $consultant_id,
            'consultation_datetime'=> $cons_dt_db,
            'duration_minutes'     => $duration,
            'description'          => $description ?: null,
            'next_action'          => $next_action ?: null,
            'status'               => $status,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Konsultacja zaktualizowana.');
        header('Location: view.php?id=' . $id);
        exit;
    }
    $cons = array_merge($cons, $_POST);
}

$dt_local = str_replace(' ', 'T', substr($cons['consultation_datetime'], 0, 16));

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <li class="breadcrumb-item"><a href="index.php">Konsultacje</a></li>
    <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>">Konsultacja #<?= $id ?></a></li>
    <li class="breadcrumb-item active">Edycja</li>
  </ol>
</nav>

<h4 class="fw-bold mb-4"><i class="bi bi-pencil text-secondary me-2"></i>Edycja konsultacji #<?= $id ?></h4>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:650px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
        <select name="client_id" class="form-select" required>
          <option value="">— Wybierz —</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$cons['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Konsultant</label>
        <select name="consultant_id" class="form-select">
          <option value="">— Nie przypisano —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($cons['consultant_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-8">
          <label class="form-label fw-semibold">Data i czas <span class="text-danger">*</span></label>
          <input name="consultation_datetime" type="datetime-local" class="form-control" required value="<?= h($dt_local) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Czas trwania (min)</label>
          <input name="duration_minutes" type="number" min="15" step="15" class="form-control" value="<?= h($cons['duration_minutes'] ?? '') ?>">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Opis konsultacji</label>
        <textarea name="description" class="form-control" rows="4"><?= h($cons['description'] ?? '') ?></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label">Następne działania</label>
        <textarea name="next_action" class="form-control" rows="2"><?= h($cons['next_action'] ?? '') ?></textarea>
      </div>

      <div class="mb-4">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach (K30_CONSULTATION_STATUSES as $sk => $sv): ?>
          <option value="<?= $sk ?>" <?= ($cons['status'] ?? 'draft') === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
