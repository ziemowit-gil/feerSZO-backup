<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$id   = (int)($_GET['id'] ?? 0);
$unit = org_unit_get($id);
if (!$unit) { flash_set('error','Jednostka nie istnieje.'); header('Location:'.APP_URL.'/org/index.php'); exit; }

$PAGE_TITLE = 'Edytuj: '.$unit['code'];
$all_units  = array_filter(org_units_all(), fn($u) => $u['id'] != $id); // wyklucz samą siebie
$all_users  = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$row        = $unit;
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'update';

    if ($action === 'deactivate') {
        org_unit_delete($id, (int)current_user()['id']);
        flash_set('success','Jednostka zdezaktywowana.');
        header('Location:'.APP_URL.'/org/index.php'); exit;
    }

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
            org_unit_update($id, $row, (int)current_user()['id']);
            flash_set('success','Jednostka zaktualizowana.');
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
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/units/view.php?id=<?= $id ?>"><?= h($unit['code']) ?></a></li>
  <li class="breadcrumb-item active">Edytuj</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-pencil text-primary me-2"></i>Edytuj jednostkę <span class="font-monospace"><?= h($unit['code']) ?></span></h4>

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
    <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Zmiana hierarchii nie zmienia historycznych przypisań.</div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-4">
      <label class="form-label fw-semibold">Kod / skrót <span class="text-danger">*</span></label>
      <input type="text" name="code" class="form-control text-uppercase font-monospace"
             value="<?= h($row['code']) ?>" maxlength="20" required>
    </div>
    <div class="col-8">
      <label class="form-label fw-semibold">Pełna nazwa <span class="text-danger">*</span></label>
      <input type="text" name="name" class="form-control" value="<?= h($row['name']) ?>" required>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Nazwa skrócona</label>
    <input type="text" name="short_name" class="form-control" value="<?= h($row['short_name']) ?>">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Opis</label>
    <textarea name="description" class="form-control" rows="2"><?= h($row['description']) ?></textarea>
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
          <option value="<?= $u['id'] ?>" <?= (int)($row['supervisor_unit_id'] ?? 0)===$u['id']?'selected':'' ?>>
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
          <option value="<?= $u['id'] ?>" <?= (int)($row['supervisor_user_id'] ?? 0)===$u['id']?'selected':'' ?>>
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
      <input type="email" name="email" class="form-control" value="<?= h($row['email']) ?>">
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Telefon / nr wewnętrzny</label>
      <input type="text" name="phone" class="form-control" value="<?= h($row['phone']) ?>">
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Lokalizacja / pokój</label>
      <input type="text" name="location" class="form-control" value="<?= h($row['location']) ?>">
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
<div class="card-footer d-flex gap-2 justify-content-between">
  <div class="d-flex gap-2">
    <button type="submit" name="_action" value="update" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
    <a href="<?= APP_URL ?>/org/units/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
  </div>
  <button type="submit" name="_action" value="deactivate" class="btn btn-outline-danger btn-sm"
          onclick="return confirm('Dezaktywować tę jednostkę? Istniejące przypisania zostaną zachowane historycznie.')"
          title="Dezaktywuj jednostkę"><i class="bi bi-trash me-1"></i>Dezaktywuj</button>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
