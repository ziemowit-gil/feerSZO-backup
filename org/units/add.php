<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$PAGE_TITLE  = 'Nowa jednostka organizacyjna';
$all_units   = org_units_all('active');
$all_users   = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$preselect_parent = (int)($_GET['parent_id'] ?? 0);

$row = [
    'parent_id'          => $preselect_parent ?: '',
    'supervisor_unit_id' => '',
    'supervisor_user_id' => '',
    'code'               => '',
    'name'               => '',
    'short_name'         => '',
    'description'        => '',
    'phone'              => '',
    'email'              => '',
    'location'           => '',
    'status'             => 'active',
    'sort_order'         => 0,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'parent_id'          => (int)($_POST['parent_id']          ?? 0) ?: null,
        'supervisor_unit_id' => (int)($_POST['supervisor_unit_id'] ?? 0) ?: null,
        'supervisor_user_id' => (int)($_POST['supervisor_user_id'] ?? 0) ?: null,
        'code'               => trim($_POST['code']        ?? ''),
        'name'               => trim($_POST['name']        ?? ''),
        'short_name'         => trim($_POST['short_name']  ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'phone'              => trim($_POST['phone']       ?? ''),
        'email'              => trim($_POST['email']       ?? ''),
        'location'           => trim($_POST['location']    ?? ''),
        'status'             => $_POST['status']           ?? 'active',
        'sort_order'         => (int)($_POST['sort_order'] ?? 0),
    ];
    if (!$row['code']) $errors[] = 'Kod jednostki jest wymagany.';
    if (!$row['name']) $errors[] = 'Nazwa jest wymagana.';
    if ($row['email'] && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres e-mail.';

    if (!$errors) {
        try {
            $id = org_unit_create($row, (int)current_user()['id']);
            flash_set('success', 'Jednostka utworzona.');
            header('Location:'.APP_URL.'/org/units/view.php?id='.$id); exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <li class="breadcrumb-item active">Nowa jednostka</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-diagram-3 text-primary me-2"></i>Nowa jednostka organizacyjna</h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-7">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">

  <div class="mb-3">
    <label class="form-label fw-semibold">Jednostka nadrzędna</label>
    <select name="parent_id" class="form-select">
      <option value="">— brak (jednostka główna) —</option>
      <?php foreach($all_units as $u): ?>
      <option value="<?= $u['id'] ?>" <?= (int)$row['parent_id']===$u['id']?'selected':'' ?>>
        <?= h($u['code'].' — '.$u['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-4">
      <label class="form-label fw-semibold">Kod / skrót <span class="text-danger">*</span></label>
      <input type="text" name="code" class="form-control text-uppercase font-monospace"
             value="<?= h($row['code']) ?>" maxlength="20" placeholder="np. DZ.IT" required>
      <div class="form-text">Unikalny kod, np. DZ.IT, KANC, ZAR</div>
    </div>
    <div class="col-8">
      <label class="form-label fw-semibold">Pełna nazwa <span class="text-danger">*</span></label>
      <input type="text" name="name" class="form-control" value="<?= h($row['name']) ?>"
             placeholder="np. Dział Informatyki" required>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Nazwa skrócona</label>
    <input type="text" name="short_name" class="form-control" value="<?= h($row['short_name']) ?>"
           placeholder="np. IT">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Opis / zakres działań</label>
    <textarea name="description" class="form-control" rows="2"
              placeholder="Krótki opis zadań i odpowiedzialności jednostki…"><?= h($row['description']) ?></textarea>
  </div>

  <!-- Nadzorowanie -->
  <div class="border rounded p-3 mb-3 bg-light">
    <div class="fw-semibold mb-2" style="font-size:.82rem"><i class="bi bi-eye text-primary me-1"></i>Nadzorowanie <span class="text-muted fw-normal">(opcjonalnie)</span></div>
    <div class="row g-3">
      <div class="col-6">
        <label class="form-label" style="font-size:.82rem">Jednostka nadzorująca</label>
        <select name="supervisor_unit_id" class="form-select form-select-sm">
          <option value="">— brak —</option>
          <?php foreach($all_units as $u): ?>
          <option value="<?= $u['id'] ?>" <?= (int)$row['supervisor_unit_id']===$u['id']?'selected':'' ?>>
            <?= h($u['code'].' — '.$u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" style="font-size:.71rem">Jednostka sprawująca nadzór merytoryczny lub administracyjny.</div>
      </div>
      <div class="col-6">
        <label class="form-label" style="font-size:.82rem">Osoba nadzorująca</label>
        <select name="supervisor_user_id" class="form-select form-select-sm">
          <option value="">— brak —</option>
          <?php foreach($all_users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= (int)$row['supervisor_user_id']===$u['id']?'selected':'' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" style="font-size:.71rem">Konkretna osoba odpowiedzialna za nadzór tej jednostki.</div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">E-mail ogólny</label>
      <input type="email" name="email" class="form-control" value="<?= h($row['email']) ?>"
             placeholder="np. it@organizacja.pl">
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Telefon / nr wewnętrzny</label>
      <input type="text" name="phone" class="form-control" value="<?= h($row['phone']) ?>"
             placeholder="np. +48 22 123 45 67 w. 100">
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Lokalizacja / pokój</label>
      <input type="text" name="location" class="form-control" value="<?= h($row['location']) ?>"
             placeholder="np. II piętro, pok. 214">
    </div>
    <div class="col-3">
      <label class="form-label fw-semibold">Kolejność</label>
      <input type="number" name="sort_order" class="form-control" value="<?= (int)$row['sort_order'] ?>" min="0">
    </div>
    <div class="col-3">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <option value="active"   <?= $row['status']==='active'  ?'selected':'' ?>>Aktywna</option>
        <option value="inactive" <?= $row['status']==='inactive'?'selected':'' ?>>Nieaktywna</option>
      </select>
    </div>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Utwórz jednostkę</button>
  <a href="<?= APP_URL ?>/org/index.php" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
