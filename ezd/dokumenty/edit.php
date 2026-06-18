<?php
/**
 * Edycja dokumentu wewnętrznego sprawy.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł kancelarii');
if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }

$id  = (int)($_GET['id'] ?? 0);
$doc = ezd_dokument_get($id);
if (!$doc) { flash_set('error','Dokument nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
if ($doc['sprawa_status'] === 'closed' && !is_admin()) {
    flash_set('error','Sprawa jest zamknięta.'); header('Location:'.APP_URL.'/ezd/dokumenty/view.php?id='.$id); exit;
}

$PAGE_TITLE = 'Edycja — '.$doc['sygnatura'];
$users  = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$errors = [];
$row = [
    'rodzaj'   => $doc['rodzaj'],
    'title'    => $doc['title'],
    'tresc'    => $doc['tresc'],
    'status'   => $doc['status'],
    'owner_id' => $doc['owner_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'rodzaj'   => $_POST['rodzaj']   ?? $doc['rodzaj'],
        'title'    => trim($_POST['title'] ?? ''),
        'tresc'    => $_POST['tresc']     ?? '',
        'status'   => $_POST['status']    ?? $doc['status'],
        'owner_id' => (int)($_POST['owner_id'] ?? 0) ?: null,
    ];
    if (!$row['title']) $errors[] = 'Tytuł dokumentu jest wymagany.';
    if (!$errors) {
        try {
            ezd_dokument_update($id, $row, (int)current_user()['id']);
            flash_set('success','Dokument zaktualizowany.');
            header('Location:'.APP_URL.'/ezd/dokumenty/view.php?id='.$id); exit;
        } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $doc['sprawa_id'] ?>"><?= h($doc['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/dokumenty/view.php?id=<?= $id ?>"><?= h($doc['sygnatura']) ?></a></li>
  <li class="breadcrumb-item active">Edycja</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-pencil-square text-primary me-2"></i>Edycja dokumentu</h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm"><div class="card-body">
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label class="form-label fw-semibold">Rodzaj dokumentu</label>
      <select name="rodzaj" class="form-select">
        <?php foreach(EZD_DOK_RODZAJE as $rv=>$rl): ?>
        <option value="<?= $rv ?>" <?= $row['rodzaj']===$rv?'selected':'' ?>><?= h($rl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(EZD_DOK_STATUSY as $sv=>$si): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($si['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł dokumentu <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>" required>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Treść</label>
    <textarea name="tresc" class="form-control" rows="8"><?= h($row['tresc']) ?></textarea>
  </div>
  <div class="mb-0">
    <label class="form-label fw-semibold">Referent / autor</label>
    <select name="owner_id" class="form-select">
      <option value="">— brak —</option>
      <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
  <a href="<?= APP_URL ?>/ezd/dokumenty/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
