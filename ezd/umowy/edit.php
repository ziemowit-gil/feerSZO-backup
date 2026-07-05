<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko');
if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }

$id    = (int)($_GET['id'] ?? 0);
$umowa = ezd_umowa_get($id);
if (!$umowa) { flash_set('error','Umowa nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
if ($umowa['sprawa_status'] === 'closed' && !is_admin()) {
    flash_set('error','Koszulka jest zamknięta.'); header('Location:'.APP_URL.'/ezd/umowy/view.php?id='.$id); exit;
}

$PAGE_TITLE = 'Edytuj: '.$umowa['sygnatura'];
$users = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$row   = $umowa;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'typ'               => $_POST['typ']               ?? $umowa['typ'],
        'title'             => trim($_POST['title']        ?? ''),
        'strona'            => trim($_POST['strona']       ?? ''),
        'wartosc'           => $_POST['wartosc']           ?? '',
        'waluta'            => $_POST['waluta']            ?? 'PLN',
        'data_zawarcia'     => $_POST['data_zawarcia']     ?? '',
        'data_od'           => $_POST['data_od']           ?? '',
        'data_do'           => $_POST['data_do']           ?? '',
        'warunki_platnosci' => $_POST['warunki_platnosci'] ?? '',
        'status'            => $_POST['status']            ?? $umowa['status'],
        'owner_id'          => (int)($_POST['owner_id']   ?? 0) ?: null,
    ];
    if (!$row['title']) $errors[] = 'Tytuł umowy jest wymagany.';

    if (!$errors) {
        try {
            ezd_umowa_update($id, $row, (int)current_user()['id']);
            flash_set('success','Umowa zaktualizowana.');
            header('Location:'.APP_URL.'/ezd/umowy/view.php?id='.$id); exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $umowa['sprawa_id'] ?>"><?= h($umowa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/umowy/view.php?id=<?= $id ?>"><?= h($umowa['sygnatura']) ?></a></li>
  <li class="breadcrumb-item active">Edytuj</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-pencil text-primary me-2"></i>Edytuj umowę <span class="font-monospace"><?= h($umowa['sygnatura']) ?></span></h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">

  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Typ dokumentu</label>
      <select name="typ" class="form-select">
        <?php foreach(EZD_UMOWA_TYPY as $tv=>$tl): ?>
        <option value="<?= $tv ?>" <?= $row['typ']===$tv?'selected':'' ?>><?= h($tl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(['projekt'=>'Projekt','aktywna'=>'Aktywna','wygasla'=>'Wygasła','rozwiazana'=>'Rozwiązana','anulowana'=>'Anulowana'] as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($sl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł / nazwa <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>" required>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Strona umowy</label>
    <input type="text" name="strona" class="form-control" value="<?= h($row['strona']) ?>">
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Wartość brutto</label>
      <input type="number" name="wartosc" class="form-control" value="<?= h($row['wartosc']) ?>" step="0.01" min="0">
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Waluta</label>
      <select name="waluta" class="form-select">
        <?php foreach(['PLN','EUR','USD','GBP','CHF'] as $w): ?>
        <option value="<?= $w ?>" <?= $row['waluta']===$w?'selected':'' ?>><?= $w ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-4">
      <label class="form-label fw-semibold">Data zawarcia</label>
      <input type="date" name="data_zawarcia" class="form-control" value="<?= h($row['data_zawarcia']) ?>">
    </div>
    <div class="col-4">
      <label class="form-label fw-semibold">Obowiązuje od</label>
      <input type="date" name="data_od" class="form-control" value="<?= h($row['data_od']) ?>">
    </div>
    <div class="col-4">
      <label class="form-label fw-semibold">Obowiązuje do</label>
      <input type="date" name="data_do" class="form-control" value="<?= h($row['data_do']) ?>">
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Warunki płatności / uwagi</label>
    <textarea name="warunki_platnosci" class="form-control" rows="3"><?= h($row['warunki_platnosci']) ?></textarea>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Referent</label>
    <select name="owner_id" class="form-select">
      <option value="">— brak —</option>
      <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
    </select>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
  <a href="<?= APP_URL ?>/ezd/umowy/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
