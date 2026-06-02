<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';
require_once dirname(__DIR__) . '/includes/address.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Nowa osoba';
$errors = [];
$row = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    if (empty(trim($row['imie_nazwisko'] ?? ''))) {
        $errors[] = 'Imię i nazwisko jest wymagane.';
    }

    if (!$errors) {
        $allowed = array_merge(
            ['imie_nazwisko', 'pesel', 'email', 'telefon', 'data_urodzenia',
             'seria_nr_dowodu', 'urzad_skarbowy', 'rachunek_bankowy', 'uwagi'],
            address_fields()
        );
        $data = array_intersect_key($row, array_flip($allowed));
        $data['created_by'] = current_user()['id'];
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        // Empty strings to null for optional fields
        foreach (['pesel','email','telefon','data_urodzenia','seria_nr_dowodu','urzad_skarbowy','rachunek_bankowy','uwagi'] as $f) {
            if (isset($data[$f]) && trim($data[$f]) === '') $data[$f] = null;
        }
        $new_id = db_insert('persons', $data);
        flash_set('success', 'Osoba została dodana.');
        header('Location: ' . APP_URL . '/persons/view.php?id=' . $new_id);
        exit;
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-person-plus text-primary"></i> Nowa osoba</h4>
  <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Lista</a>
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
    <input name="imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko'] ?? '') ?>" required autofocus>
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
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Zapisz osobę</button>
  <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
</div>
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
