<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$unit_id   = (int)($_GET['unit_id'] ?? 0);
$unit      = $unit_id ? org_unit_get($unit_id) : null;
$PAGE_TITLE = 'Dodaj osobę' . ($unit ? ' — '.$unit['code'] : '');
$users     = db_all("SELECT id,name,email FROM users WHERE is_active=1 ORDER BY name");
$positions = org_positions_all();
$all_units = org_units_all('active');

// Dla selecta zastępcy — wszyscy aktywni members (opcjonalnie filtr po jednostce)
$all_members = db_all(
    "SELECT m.id, u.name AS user_name, o.code AS unit_code
     FROM org_members m JOIN users u ON u.id=m.user_id JOIN org_units o ON o.id=m.unit_id
     WHERE (m.valid_to IS NULL OR m.valid_to >= date('now'))
     ORDER BY o.code, u.name"
);

$row = [
    'unit_id'       => $unit_id ?: '',
    'user_id'       => '',
    'position_id'   => '',
    'position_name' => '',
    'is_head'       => 0,
    'is_primary'    => 1,
    'email_service' => '',
    'phone_direct'  => '',
    'phone_mobile'  => '',
    'availability'  => 'Pon–Pt 8:00–16:00',
    'status'        => 'active',
    'substitute_id' => '',
    'valid_from'    => date('Y-m-d'),
    'valid_to'      => '',
    'notes'         => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'unit_id'       => (int)($_POST['unit_id']       ?? 0),
        'user_id'       => (int)($_POST['user_id']       ?? 0),
        'position_id'   => (int)($_POST['position_id']   ?? 0) ?: null,
        'position_name' => trim($_POST['position_name']  ?? ''),
        'is_head'       => isset($_POST['is_head'])   ? 1 : 0,
        'is_primary'    => isset($_POST['is_primary']) ? 1 : 0,
        'email_service' => trim($_POST['email_service']  ?? ''),
        'phone_direct'  => trim($_POST['phone_direct']   ?? ''),
        'phone_mobile'  => trim($_POST['phone_mobile']   ?? ''),
        'availability'  => trim($_POST['availability']   ?? ''),
        'status'        => $_POST['status']              ?? 'active',
        'substitute_id' => (int)($_POST['substitute_id'] ?? 0) ?: null,
        'valid_from'    => $_POST['valid_from']          ?? date('Y-m-d'),
        'valid_to'      => $_POST['valid_to']            ?? '',
        'notes'         => trim($_POST['notes']          ?? ''),
    ];
    if (!$row['unit_id']) $errors[] = 'Wybierz jednostkę.';
    if (!$row['user_id']) $errors[] = 'Wybierz osobę.';
    if ($row['email_service'] && !filter_var($row['email_service'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy służbowy e-mail.';

    if (!$errors) {
        try {
            $mid = org_member_add($row, (int)current_user()['id']);
            flash_set('success','Osoba dodana do jednostki.');
            header('Location:'.APP_URL.'/org/units/view.php?id='.$row['unit_id']); exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <?php if($unit): ?>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/units/view.php?id=<?= $unit_id ?>"><?= h($unit['code']) ?></a></li>
  <?php endif; ?>
  <li class="breadcrumb-item active">Dodaj osobę</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-person-plus text-primary me-2"></i>Dodaj osobę do jednostki</h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">

  <!-- Jednostka + Osoba -->
  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Jednostka <span class="text-danger">*</span></label>
      <select name="unit_id" class="form-select" required>
        <option value="">— wybierz —</option>
        <?php foreach($all_units as $u): ?>
        <option value="<?= $u['id'] ?>" <?= (int)$row['unit_id']===$u['id']?'selected':'' ?>><?= h($u['code'].' — '.$u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Osoba (użytkownik) <span class="text-danger">*</span></label>
      <select name="user_id" class="form-select" required>
        <option value="">— wybierz —</option>
        <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['user_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?> <<?= h($u['email']) ?>></option><?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Stanowisko -->
  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Stanowisko (szablon)</label>
      <select name="position_id" class="form-select" onchange="fillPosName(this)">
        <option value="">— brak / free-text —</option>
        <?php foreach($positions as $p): ?>
        <option value="<?= $p['id'] ?>" data-name="<?= h($p['name']) ?>" <?= (int)$row['position_id']===$p['id']?'selected':'' ?>><?= h($p['name']) ?><?= $p['is_head_role']?' ★':'' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Nazwa stanowiska (własna)</label>
      <input type="text" name="position_name" class="form-control" value="<?= h($row['position_name']) ?>" placeholder="np. Kierownik Projektu Beta">
    </div>
  </div>

  <!-- Flagi -->
  <div class="row g-3 mb-3">
    <div class="col-6">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_head" id="is_head" <?= $row['is_head']?'checked':'' ?>>
        <label class="form-check-label fw-semibold" for="is_head"><i class="bi bi-star-fill text-warning me-1"></i>Kierownik jednostki</label>
      </div>
      <div class="form-text">Zaznacz, jeśli ta osoba jest kierownikiem tej jednostki.</div>
    </div>
    <div class="col-6">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_primary" id="is_primary" <?= $row['is_primary']?'checked':'' ?>>
        <label class="form-check-label fw-semibold" for="is_primary">Jednostka główna</label>
      </div>
      <div class="form-text">Używana do adresowania powiadomień e-mail.</div>
    </div>
  </div>

  <!-- Dane kontaktowe w roli -->
  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Służbowy e-mail</label>
      <input type="email" name="email_service" class="form-control" value="<?= h($row['email_service']) ?>" placeholder="jan.kowalski@org.pl">
    </div>
    <div class="col-3">
      <label class="form-label fw-semibold">Tel. bezpośredni</label>
      <input type="text" name="phone_direct" class="form-control" value="<?= h($row['phone_direct']) ?>">
    </div>
    <div class="col-3">
      <label class="form-label fw-semibold">Tel. komórkowy</label>
      <input type="text" name="phone_mobile" class="form-control" value="<?= h($row['phone_mobile']) ?>">
    </div>
  </div>

  <!-- Status + zastępstwo -->
  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(ORG_MEMBER_STATUSES as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($sl['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Zastępstwo (przy urlopie/zwolnieniu)</label>
      <select name="substitute_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($all_members as $am): ?>
        <option value="<?= $am['id'] ?>" <?= (int)$row['substitute_id']===$am['id']?'selected':'' ?>>
          <?= h($am['unit_code'].' · '.$am['user_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Osoba, do której trafią powiadomienia gdy status = urlop/zwolnienie.</div>
    </div>
  </div>

  <!-- Dostępność + daty ważności -->
  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Godziny dostępności</label>
      <input type="text" name="availability" class="form-control" value="<?= h($row['availability']) ?>" placeholder="Pon–Pt 8:00–16:00">
    </div>
    <div class="col-3">
      <label class="form-label fw-semibold">Ważne od</label>
      <input type="date" name="valid_from" class="form-control" value="<?= h($row['valid_from']) ?>">
    </div>
    <div class="col-3">
      <label class="form-label fw-semibold">Ważne do</label>
      <input type="date" name="valid_to" class="form-control" value="<?= h($row['valid_to']) ?>">
      <div class="form-text">Puste = bezterminowo</div>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Uwagi / notatka</label>
    <textarea name="notes" class="form-control" rows="2"><?= h($row['notes']) ?></textarea>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Dodaj do jednostki</button>
  <a href="<?= $unit_id ? APP_URL.'/org/units/view.php?id='.$unit_id : APP_URL.'/org/index.php' ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>

<script>
function fillPosName(sel) {
    var name = sel.options[sel.selectedIndex]?.dataset?.name || '';
    var inp  = document.querySelector('[name=position_name]');
    if (inp && !inp.value && name) inp.value = name;
}
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
