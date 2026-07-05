<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł kancelarii');

$id    = (int)($_GET['id'] ?? 0);
$pismo = ezd_pismo_get($id);
if (!$pismo) { flash_set('error','Pismo nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
$_sprawa_pisma = ezd_sprawa_get((int)$pismo['sprawa_id']);
if (!$_sprawa_pisma || ezd_sprawa_access($_sprawa_pisma, (int)current_user()['id']) !== 'write') { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }
if ($pismo['sprawa_status'] === 'closed' && !is_admin()) {
    flash_set('error','Sprawa jest zamknięta.'); header('Location:'.APP_URL.'/ezd/pisma/view.php?id='.$id); exit;
}

$PAGE_TITLE = 'Edytuj: '.$pismo['sygnatura'];
$users = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$row   = $pismo;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'kierunek'     => $_POST['kierunek']    ?? $pismo['kierunek'],
        'title'        => trim($_POST['title']  ?? ''),
        'tresc'        => $_POST['tresc']       ?? '',
        'nadawca'      => trim($_POST['nadawca']?? ''),
        'odbiorca'     => trim($_POST['odbiorca']?? ''),
        'data_pisma'   => $_POST['data_pisma']  ?? '',
        'data_wplywu'  => $_POST['data_wplywu'] ?? '',
        'data_wysylki' => $_POST['data_wysylki']?? '',
        'status'       => $_POST['status']      ?? $pismo['status'],
        'owner_id'     => (int)($_POST['owner_id']??0) ?: null,
        'rodzaj_medium'=> $_POST['rodzaj_medium'] ?? $pismo['rodzaj_medium'],
    ];
    if (!$row['title']) $errors[] = 'Tytuł pisma jest wymagany.';

    if (!$errors) {
        try {
            ezd_pismo_update($id, $row, (int)current_user()['id']);
            flash_set('success','Pismo zaktualizowane.');
            header('Location:'.APP_URL.'/ezd/pisma/view.php?id='.$id); exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $pismo['sprawa_id'] ?>"><?= h($pismo['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $id ?>"><?= h($pismo['sygnatura']) ?></a></li>
  <li class="breadcrumb-item active">Edytuj</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-pencil text-primary me-2"></i>Edytuj pismo <span class="font-monospace"><?= h($pismo['sygnatura']) ?></span></h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">

  <div class="mb-3">
    <label class="form-label fw-semibold">Kierunek</label>
    <div class="d-flex gap-2 flex-wrap">
      <?php foreach(EZD_KIERUNKI as $kv=>$kl): ?>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="kierunek" id="k_<?= $kv ?>" value="<?= $kv ?>" <?= $row['kierunek']===$kv?'checked':'' ?>>
        <label class="form-check-label" for="k_<?= $kv ?>"><i class="bi <?= $kl['icon'] ?> me-1"></i><?= h($kl['label']) ?></label>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł / przedmiot <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>" required>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Rodzaj medium</label>
    <select name="rodzaj_medium" class="form-select" style="max-width:280px">
      <?php foreach(EZD_MEDIA as $mv=>$ml): ?>
      <option value="<?= $mv ?>" <?= $row['rodzaj_medium']===$mv?'selected':'' ?>><?= h($ml['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Nadawca</label>
      <input type="text" name="nadawca" class="form-control" value="<?= h($row['nadawca']) ?>">
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Odbiorca</label>
      <input type="text" name="odbiorca" class="form-control" value="<?= h($row['odbiorca']) ?>">
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-4">
      <label class="form-label fw-semibold">Data pisma</label>
      <input type="date" name="data_pisma" class="form-control" value="<?= h($row['data_pisma']) ?>">
    </div>
    <div class="col-4">
      <label class="form-label fw-semibold">Data wpływu</label>
      <input type="date" name="data_wplywu" class="form-control" value="<?= h($row['data_wplywu']) ?>">
    </div>
    <div class="col-4">
      <label class="form-label fw-semibold">Data wysyłki</label>
      <input type="date" name="data_wysylki" class="form-control" value="<?= h($row['data_wysylki']) ?>">
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(['nowe'=>'Nowe','w_toku'=>'W toku','odpowiedziano'=>'Odpowiedziano','archiwum'=>'Archiwum'] as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($sl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Referent</label>
      <select name="owner_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Treść / notatka</label>
    <textarea name="tresc" class="form-control" rows="5"><?= h($row['tresc']) ?></textarea>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
  <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
