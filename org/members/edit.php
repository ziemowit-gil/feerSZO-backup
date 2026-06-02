<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/org.php';
require_login(); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$id     = (int)($_GET['id'] ?? 0);
$member = org_member_get($id);
if (!$member) { flash_set('error','Przypisanie nie istnieje.'); header('Location:'.APP_URL.'/org/index.php'); exit; }

$user_id = (int)current_user()['id'];
// Pozwól edytować: admin = pełna edycja, właściciel = tylko status/zastępstwo/dostępność
$is_owner   = $member['user_id'] == $user_id;
$full_edit  = is_admin();
if (!$full_edit && !$is_owner) {
    flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/org/units/view.php?id='.$member['unit_id']); exit;
}

$PAGE_TITLE = 'Edytuj przypisanie — '.$member['user_name'];
$positions  = org_positions_all();
// Dostępni zastępcy = inni aktywni members
$all_members = db_all(
    "SELECT m.id, u.name AS user_name, o.code AS unit_code
     FROM org_members m JOIN users u ON u.id=m.user_id JOIN org_units o ON o.id=m.unit_id
     WHERE m.id!=? AND (m.valid_to IS NULL OR m.valid_to >= date('now'))
     ORDER BY o.code, u.name",
    [$id]
);

$row    = $member;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'position_id'   => $full_edit ? ((int)($_POST['position_id']  ?? 0) ?: null) : $member['position_id'],
        'position_name' => $full_edit ? trim($_POST['position_name']  ?? '') : $member['position_name'],
        'is_head'       => $full_edit ? (isset($_POST['is_head'])   ? 1 : 0) : $member['is_head'],
        'is_primary'    => isset($_POST['is_primary']) ? 1 : 0,
        'email_service' => trim($_POST['email_service']  ?? ''),
        'phone_direct'  => trim($_POST['phone_direct']   ?? ''),
        'phone_mobile'  => trim($_POST['phone_mobile']   ?? ''),
        'availability'  => trim($_POST['availability']   ?? ''),
        'status'        => $_POST['status']              ?? $member['status'],
        'substitute_id' => (int)($_POST['substitute_id'] ?? 0) ?: null,
        'valid_from'    => $full_edit ? ($_POST['valid_from'] ?? $member['valid_from']) : $member['valid_from'],
        'valid_to'      => $full_edit ? ($_POST['valid_to']   ?: null) : $member['valid_to'],
        'notes'         => $full_edit ? trim($_POST['notes'] ?? '') : $member['notes'],
    ];
    if ($row['email_service'] && !filter_var($row['email_service'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy e-mail.';

    if (!$errors) {
        org_member_update($id, $row, $user_id);
        flash_set('success','Przypisanie zaktualizowane.');
        header('Location:'.APP_URL.'/org/units/view.php?id='.$member['unit_id']); exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/units/view.php?id=<?= $member['unit_id'] ?>"><?= h($member['unit_code']) ?></a></li>
  <li class="breadcrumb-item active">Edytuj przypisanie</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-person-gear text-primary me-2"></i>Edytuj przypisanie — <?= h($member['user_name']) ?></h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<?php if(!$full_edit && $is_owner): ?>
<div class="alert alert-info py-2 px-3" style="font-size:.82rem"><i class="bi bi-info-circle me-1"></i>Edytujesz własne ustawienia kontaktu i dostępności. Zmiany stanowiska i dat wymaga uprawnień administratora.</div>
<?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">

  <!-- Info readonly -->
  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Jednostka</label>
      <input type="text" class="form-control" value="<?= h($member['unit_code'].' — '.$member['unit_name']) ?>" readonly>
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Osoba</label>
      <input type="text" class="form-control" value="<?= h($member['user_name']) ?>" readonly>
    </div>
  </div>

  <?php if($full_edit): ?>
  <!-- Stanowisko (tylko admin) -->
  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Stanowisko (szablon)</label>
      <select name="position_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($positions as $p): ?><option value="<?= $p['id'] ?>" <?= (int)$row['position_id']===$p['id']?'selected':'' ?>><?= h($p['name']) ?><?= $p['is_head_role']?' ★':'' ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Nazwa stanowiska (własna)</label>
      <input type="text" name="position_name" class="form-control" value="<?= h($row['position_name']) ?>">
    </div>
  </div>
  <div class="mb-3">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" name="is_head" id="is_head" <?= $row['is_head']?'checked':'' ?>>
      <label class="form-check-label fw-semibold" for="is_head"><i class="bi bi-star-fill text-warning me-1"></i>Kierownik jednostki</label>
    </div>
  </div>
  <?php endif; ?>

  <div class="mb-3">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" name="is_primary" id="is_primary" <?= $row['is_primary']?'checked':'' ?>>
      <label class="form-check-label fw-semibold" for="is_primary">Jednostka główna (do powiadomień)</label>
    </div>
  </div>

  <!-- Kontakt -->
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
      <label class="form-label fw-semibold">Zastępstwo</label>
      <select name="substitute_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($all_members as $am): ?>
        <option value="<?= $am['id'] ?>" <?= (int)$row['substitute_id']===$am['id']?'selected':'' ?>>
          <?= h($am['unit_code'].' · '.$am['user_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Osoba, do której trafią powiadomienia podczas Twojej nieobecności.</div>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Godziny dostępności</label>
    <input type="text" name="availability" class="form-control" value="<?= h($row['availability']) ?>" placeholder="Pon–Pt 8:00–16:00">
  </div>

  <?php if($full_edit): ?>
  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Ważne od</label>
      <input type="date" name="valid_from" class="form-control" value="<?= h($row['valid_from']) ?>">
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Ważne do</label>
      <input type="date" name="valid_to" class="form-control" value="<?= h($row['valid_to']) ?>">
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Uwagi</label>
    <textarea name="notes" class="form-control" rows="2"><?= h($row['notes']) ?></textarea>
  </div>
  <?php endif; ?>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
  <a href="<?= APP_URL ?>/org/units/view.php?id=<?= $member['unit_id'] ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
