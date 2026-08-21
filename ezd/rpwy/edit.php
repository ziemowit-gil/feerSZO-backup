<?php
/**
 * Edycja wpisu książki nadawczej (RPW-W). Numer i status pozostają nietknięte —
 * status zmienia się akcjami na karcie wpisu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();
if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/rpwy/index.php'); exit; }

$id  = (int)($_GET['id'] ?? 0);
$rec = ezd_rpwy_get($id);
if (!$rec) { flash_set('error','Wpis nie istnieje.'); header('Location:'.APP_URL.'/ezd/rpwy/index.php'); exit; }

$row = [
    'data_wysylki' => $rec['data_wysylki'],
    'sposob'       => $rec['sposob'],
    'odbiorca'     => $rec['odbiorca'],
    'adres'        => $rec['adres'],
    'ade'          => $rec['ade'],
    'nr_nadania'   => $rec['nr_nadania'],
    'liczba_szt'   => (int)$rec['liczba_szt'],
    'koszt'        => $rec['koszt'] > 0 ? number_format((float)$rec['koszt'], 2, ',', '') : '',
    'termin_dni'   => (int)$rec['termin_dni'],
    'uwagi'        => $rec['uwagi'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (array_keys($row) as $k) {
        if (isset($_POST[$k])) $row[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
    }
    $errors = ezd_rpwy_validate($row);
    if (!$errors) {
        ezd_rpwy_update($id, $row, (int)current_user()['id']);
        flash_set('success', 'Zapisano zmiany w ' . ezd_rpwy_label($rec) . '.');
        header('Location:'.APP_URL.'/ezd/rpwy/view.php?id='.$id); exit;
    }
}

$PAGE_TITLE = 'Edycja ' . ezd_rpwy_label($rec);
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/rpwy/index.php">Książka nadawcza</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/rpwy/view.php?id=<?= $id ?>"><?= h(ezd_rpwy_label($rec)) ?></a></li>
  <li class="breadcrumb-item active">Edycja</li>
</ol></nav>

<h4 class="fw-bold mb-3"><i class="bi bi-pencil-square text-primary me-2"></i>Edycja wpisu <span class="font-monospace"><?= h(ezd_rpwy_label($rec)) ?></span></h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm"><div class="card-body">

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label fw-semibold" for="data_wysylki">Data nadania <span class="text-danger">*</span></label>
      <input type="date" name="data_wysylki" id="data_wysylki" class="form-control" value="<?= h($row['data_wysylki']) ?>" required>
      <small class="text-muted">Zmiana daty nie zmienia numeru wpisu.</small>
    </div>
    <div class="col-md-8">
      <label class="form-label fw-semibold" for="sposob">Sposób wysyłki</label>
      <select name="sposob" id="sposob" class="form-select">
        <?php foreach(EZD_RPWY_SPOSOBY as $sv=>$si): ?>
        <option value="<?= $sv ?>" <?= $row['sposob']===$sv?'selected':'' ?>><?= h($si['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold" for="odbiorca">Odbiorca <span class="text-danger">*</span></label>
      <input type="text" name="odbiorca" id="odbiorca" class="form-control" value="<?= h($row['odbiorca']) ?>" required>
    </div>
    <div class="col-md-7">
      <label class="form-label fw-semibold" for="adres">Adres</label>
      <input type="text" name="adres" id="adres" class="form-control" value="<?= h($row['adres']) ?>">
    </div>
  </div>

  <div class="mb-3" id="ade-wrap" style="<?= $row['sposob']==='edoreczenia'?'':'display:none' ?>">
    <label class="form-label fw-semibold" for="ade">Adres do doręczeń elektronicznych (ADE) odbiorcy <span class="text-danger">*</span></label>
    <input type="text" name="ade" id="ade" class="form-control font-monospace" value="<?= h($row['ade']) ?>" placeholder="AE:PL-00000-00000-XXXXX-00">
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold" for="nr_nadania">Numer nadania / ID dowodu</label>
      <input type="text" name="nr_nadania" id="nr_nadania" class="form-control font-monospace" value="<?= h($row['nr_nadania']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold" for="liczba_szt">Liczba przesyłek</label>
      <input type="number" name="liczba_szt" id="liczba_szt" class="form-control" min="1" value="<?= (int)$row['liczba_szt'] ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold" for="koszt">Opłata (zł)</label>
      <input type="text" name="koszt" id="koszt" class="form-control" value="<?= h($row['koszt']) ?>" inputmode="decimal">
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold" for="termin_dni">Termin od doręczenia</label>
      <div class="input-group">
        <input type="number" name="termin_dni" id="termin_dni" class="form-control" min="0" max="365" value="<?= (int)$row['termin_dni'] ?>">
        <span class="input-group-text">dni</span>
      </div>
    </div>
  </div>

  <div>
    <label class="form-label fw-semibold" for="uwagi">Uwagi</label>
    <textarea name="uwagi" id="uwagi" class="form-control" rows="2"><?= h($row['uwagi']) ?></textarea>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
  <a href="<?= APP_URL ?>/ezd/rpwy/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>

<script>
(function () {
  var sel = document.getElementById('sposob'), ade = document.getElementById('ade-wrap');
  if (sel && ade) sel.addEventListener('change', function () {
    ade.style.display = (sel.value === 'edoreczenia') ? '' : 'none';
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
