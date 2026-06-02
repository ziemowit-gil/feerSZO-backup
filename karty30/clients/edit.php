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
$client = db_one("SELECT * FROM k30_clients WHERE id=?", [$id]);
if (!$client) {
    flash_set('danger', 'Beneficjent nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Edycja beneficjenta — Karty 30';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $dob      = trim($_POST['date_of_birth'] ?? '');
    $gender   = $_POST['gender'] ?? '';
    $status   = $_POST['status'] ?? 'enrolled';
    $problem  = trim($_POST['problem'] ?? '');
    $equipment= trim($_POST['equipment'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');
    $consent  = isset($_POST['consent']) ? 1 : 0;
    $avail_h  = (float)($_POST['available_hours'] ?? 0);
    $pcm      = $_POST['preferred_contact_method'] ?? 'email';

    if ($name === '') $errors[] = 'Imię i nazwisko jest wymagane.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres email.';
    if (!array_key_exists($status, K30_CLIENT_STATUSES)) $status = 'enrolled';

    if (!$errors) {
        db_update('k30_clients', $id, [
            'name'                    => $name,
            'email'                   => $email ?: null,
            'phone'                   => $phone ?: null,
            'date_of_birth'           => $dob ?: null,
            'gender'                  => $gender ?: null,
            'status'                  => $status,
            'problem'                 => $problem ?: null,
            'equipment'               => $equipment ?: null,
            'address'                 => $address ?: null,
            'notes'                   => $notes ?: null,
            'consent'                 => $consent,
            'available_hours'         => $avail_h,
            'preferred_contact_method'=> $pcm,
            'updated_at'              => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Dane beneficjenta zostały zaktualizowane.');
        header('Location: view.php?id=' . $id);
        exit;
    }

    // Repopulate from POST on error
    $client = array_merge($client, $_POST);
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <li class="breadcrumb-item"><a href="index.php">Beneficjenci</a></li>
    <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>"><?= h($client['name']) ?></a></li>
    <li class="breadcrumb-item active">Edycja</li>
  </ol>
</nav>

<h4 class="fw-bold mb-4"><i class="bi bi-pencil text-primary me-2"></i>Edycja: <?= h($client['name']) ?></h4>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:700px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
        <input name="name" class="form-control" required value="<?= h($client['name']) ?>">
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input name="email" type="email" class="form-control" value="<?= h($client['email'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Telefon</label>
          <input name="phone" class="form-control" value="<?= h($client['phone'] ?? '') ?>">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label">Data urodzenia</label>
          <input name="date_of_birth" type="date" class="form-control" value="<?= h($client['date_of_birth'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Płeć</label>
          <select name="gender" class="form-select">
            <option value="">— Nie podano —</option>
            <option value="male"   <?= ($client['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Mężczyzna</option>
            <option value="female" <?= ($client['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Kobieta</option>
            <option value="other"  <?= ($client['gender'] ?? '') === 'other'  ? 'selected' : '' ?>>Inne</option>
          </select>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (K30_CLIENT_STATUSES as $sk => $sv): ?>
            <option value="<?= $sk ?>" <?= ($client['status'] ?? 'enrolled') === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Dostępne godziny</label>
          <input name="available_hours" type="number" step="0.5" min="0" class="form-control" value="<?= h($client['available_hours'] ?? '0') ?>">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Problem / opis potrzeby</label>
        <textarea name="problem" class="form-control" rows="3"><?= h($client['problem'] ?? '') ?></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label">Sprzęt</label>
        <input name="equipment" class="form-control" value="<?= h($client['equipment'] ?? '') ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">Adres</label>
        <input name="address" class="form-control" value="<?= h($client['address'] ?? '') ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">Preferowany kontakt</label>
        <select name="preferred_contact_method" class="form-select">
          <option value="email" <?= ($client['preferred_contact_method'] ?? 'email') === 'email' ? 'selected' : '' ?>>Email</option>
          <option value="phone" <?= ($client['preferred_contact_method'] ?? '') === 'phone' ? 'selected' : '' ?>>Telefon</option>
          <option value="sms"   <?= ($client['preferred_contact_method'] ?? '') === 'sms'   ? 'selected' : '' ?>>SMS</option>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Notatki</label>
        <textarea name="notes" class="form-control" rows="2"><?= h($client['notes'] ?? '') ?></textarea>
      </div>

      <div class="mb-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="consent" id="consent" value="1" <?= ($client['consent'] ?? 0) ? 'checked' : '' ?>>
          <label class="form-check-label" for="consent">Zgoda na przetwarzanie danych osobowych</label>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
