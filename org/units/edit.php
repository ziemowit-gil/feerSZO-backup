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
$kind       = (($_POST['kind'] ?? $unit['kind'] ?? 'internal') === 'external') ? 'external' : 'internal';
$is_ext     = $kind === 'external';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'update';

    if ($action === 'deactivate') {
        org_unit_delete($id, (int)current_user()['id']);
        flash_set('success','Jednostka zdezaktywowana.');
        header('Location:'.APP_URL.'/org/index.php'); exit;
    }

    $row = array_merge($unit, [
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
        'kind'               => $kind,
        'legal_form'         => trim($_POST['legal_form']    ?? ''),
        'nip'                => trim($_POST['nip']           ?? ''),
        'regon'              => trim($_POST['regon']         ?? ''),
        'krs'                => trim($_POST['krs']           ?? ''),
        'www'                => trim($_POST['www']           ?? ''),
        'contact_person'     => trim($_POST['contact_person']?? ''),
        'contact_role'       => trim($_POST['contact_role']  ?? ''),
        'contact_phone'      => trim($_POST['contact_phone'] ?? ''),
        'contact_email'      => trim($_POST['contact_email'] ?? ''),
        'cooperation_type'   => trim($_POST['cooperation_type'] ?? ''),
        'cooperation_from'   => trim($_POST['cooperation_from'] ?? ''),
        'cooperation_to'     => trim($_POST['cooperation_to']   ?? ''),
    ]);
    if (!$row['code']) $errors[] = 'Kod jednostki jest wymagany.';
    if (!$row['name']) $errors[] = 'Nazwa jest wymagana.';
    if ($row['email'] && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres e-mail.';
    if ($row['contact_email'] && !filter_var($row['contact_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy e-mail osoby kontaktowej.';

    if (!$errors) {
        try {
            org_unit_update($id, $row, (int)current_user()['id']);
            org_unit_save_field_values($id, $_POST['cf'] ?? [], $kind);
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
    <label class="form-label fw-semibold">Rodzaj jednostki</label>
    <select name="kind" id="unit_kind" class="form-select">
      <?php foreach (ORG_UNIT_KINDS as $k=>$v): ?>
      <option value="<?= h($k) ?>" <?= $kind===$k?'selected':'' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Zmiana rodzaju przeładowuje dostępne pola dodatkowe po zapisaniu.</div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold"><?= $is_ext ? 'Jednostka nadrzędna w drzewie' : 'Jednostka nadrzędna' ?></label>
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

  <!-- Dane jednostki zewnętrznej (partnera) — widoczne dla rodzaju „zewnętrzna" -->
  <div id="ext_fields" <?= $is_ext ? '' : 'style="display:none"' ?>>
    <div class="border rounded p-3 mb-3 bg-light">
      <div class="fw-semibold mb-2" style="font-size:.82rem"><i class="bi bi-buildings text-info me-1"></i>Dane organizacji</div>
      <div class="row g-3">
        <div class="col-6">
          <label class="form-label" style="font-size:.82rem">Typ jednostki</label>
          <select name="cooperation_type" class="form-select form-select-sm">
            <option value="">— wybierz —</option>
            <?php foreach (ORG_EXT_TYPES as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= ($row['cooperation_type']??'')===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label" style="font-size:.82rem">Forma prawna</label>
          <input type="text" name="legal_form" class="form-control form-control-sm" value="<?= h($row['legal_form']??'') ?>" placeholder="np. Fundacja, Sp. z o.o., JST">
        </div>
        <div class="col-4">
          <label class="form-label" style="font-size:.82rem">NIP</label>
          <input type="text" name="nip" class="form-control form-control-sm" value="<?= h($row['nip']??'') ?>">
        </div>
        <div class="col-4">
          <label class="form-label" style="font-size:.82rem">REGON</label>
          <input type="text" name="regon" class="form-control form-control-sm" value="<?= h($row['regon']??'') ?>">
        </div>
        <div class="col-4">
          <label class="form-label" style="font-size:.82rem">KRS</label>
          <input type="text" name="krs" class="form-control form-control-sm" value="<?= h($row['krs']??'') ?>">
        </div>
        <div class="col-12">
          <label class="form-label" style="font-size:.82rem">Strona WWW</label>
          <input type="url" name="www" class="form-control form-control-sm" value="<?= h($row['www']??'') ?>" placeholder="https://…">
        </div>
      </div>
    </div>

    <div class="border rounded p-3 mb-3 bg-light">
      <div class="fw-semibold mb-2" style="font-size:.82rem"><i class="bi bi-person-vcard text-info me-1"></i>Osoba kontaktowa</div>
      <div class="row g-3">
        <div class="col-6">
          <label class="form-label" style="font-size:.82rem">Imię i nazwisko</label>
          <input type="text" name="contact_person" class="form-control form-control-sm" value="<?= h($row['contact_person']??'') ?>">
        </div>
        <div class="col-6">
          <label class="form-label" style="font-size:.82rem">Stanowisko / rola</label>
          <input type="text" name="contact_role" class="form-control form-control-sm" value="<?= h($row['contact_role']??'') ?>">
        </div>
        <div class="col-6">
          <label class="form-label" style="font-size:.82rem">Telefon</label>
          <input type="text" name="contact_phone" class="form-control form-control-sm" value="<?= h($row['contact_phone']??'') ?>">
        </div>
        <div class="col-6">
          <label class="form-label" style="font-size:.82rem">E-mail</label>
          <input type="email" name="contact_email" class="form-control form-control-sm" value="<?= h($row['contact_email']??'') ?>">
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6">
        <label class="form-label fw-semibold">Współpraca od</label>
        <input type="date" name="cooperation_from" class="form-control" value="<?= h($row['cooperation_from']??'') ?>">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Współpraca do</label>
        <input type="date" name="cooperation_to" class="form-control" value="<?= h($row['cooperation_to']??'') ?>">
        <div class="form-text">Puste = bezterminowo.</div>
      </div>
    </div>
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
        <?php foreach (ORG_UNIT_STATUSES as $st=>$meta): ?>
        <option value="<?= h($st) ?>" <?= $row['status']===$st?'selected':'' ?>><?= h($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <?= org_render_field_inputs($id, $kind) ?>

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
<script>
document.getElementById('unit_kind')?.addEventListener('change', function(){
  const ext = document.getElementById('ext_fields');
  if (ext) ext.style.display = this.value === 'external' ? '' : 'none';
});
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
