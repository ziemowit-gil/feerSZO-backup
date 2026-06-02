<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';
require_once dirname(__DIR__) . '/includes/address.php';

require_role('admin', 'editor');

$id = intval($_GET['id'] ?? 0);
$row = person_by_id($id);
if (!$row) { http_response_code(404); die('Nie znaleziono osoby.'); }

$PAGE_TITLE = 'Edycja: ' . $row['imie_nazwisko'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = $_POST;
    unset($data['_csrf']);

    if (empty(trim($data['imie_nazwisko'] ?? ''))) {
        $errors[] = 'Imię i nazwisko jest wymagane.';
    }

    if (!$errors) {
        $allowed = array_merge(
            ['imie_nazwisko', 'pesel', 'email', 'telefon', 'data_urodzenia',
             'seria_nr_dowodu', 'urzad_skarbowy', 'rachunek_bankowy', 'uwagi'],
            address_fields()
        );
        $save = array_intersect_key($data, array_flip($allowed));
        $save['updated_at'] = date('Y-m-d H:i:s');
        foreach (['pesel','email','telefon','data_urodzenia','seria_nr_dowodu','urzad_skarbowy','rachunek_bankowy','uwagi'] as $f) {
            if (isset($save[$f]) && trim($save[$f]) === '') $save[$f] = null;
        }
        db_update('persons', $save, $id);
        flash_set('success', 'Zmiany zapisane.');
        header('Location: view.php?id=' . $id);
        exit;
    }
    $row = array_merge($row, $_POST);
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-pencil text-primary"></i> Edycja: <?= h($row['imie_nazwisko']) ?></h4>
  <div class="d-flex gap-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> Podgląd</a>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-person-vcard"></i> Dane osobowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">Imię i nazwisko <span class="text-danger">*</span></label>
    <input name="imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko'] ?? '') ?>" required>
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">PESEL</label>
    <input name="pesel" class="form-control font-monospace" maxlength="11" value="<?= h($row['pesel'] ?? '') ?>">
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">Data urodzenia</label>
    <input name="data_urodzenia" type="date" class="form-control" value="<?= h($row['data_urodzenia'] ?? '') ?>">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Adres zamieszkania</label>
  <?= address_widget($row, ['copy_button' => true, 'widget_id' => 'personAddrWidget']) ?>
</div>
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Telefon</label>
    <input name="telefon" type="tel" class="form-control" value="<?= h($row['telefon'] ?? '') ?>">
  </div>
</div>
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">E-mail</label>
    <input name="email" type="email" class="form-control" value="<?= h($row['email'] ?? '') ?>">
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">Seria i nr dowodu</label>
    <input name="seria_nr_dowodu" class="form-control" value="<?= h($row['seria_nr_dowodu'] ?? '') ?>">
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">Urząd skarbowy</label>
    <input name="urzad_skarbowy" class="form-control" value="<?= h($row['urzad_skarbowy'] ?? '') ?>">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Rachunek bankowy</label>
  <input name="rachunek_bankowy" class="form-control font-monospace" placeholder="XX XXXX XXXX XXXX..." value="<?= h($row['rachunek_bankowy'] ?? '') ?>">
</div>
<div class="mb-3">
  <label class="form-label">Uwagi</label>
  <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi'] ?? '') ?></textarea>
</div>
</div>
</div>

<div class="d-flex gap-2 mb-4">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Zapisz zmiany</button>
  <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
