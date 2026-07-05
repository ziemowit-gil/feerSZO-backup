<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_role('admin'); require_module_enabled('ezd_enabled','Moduł kancelarii');

$id     = (int)($_GET['id'] ?? 0);
$teczka = ezd_teczka_get($id);
if (!$teczka) { flash_set('error','Teczka nie istnieje.'); header('Location:'.APP_URL.'/ezd/teczki/index.php'); exit; }

$PAGE_TITLE = 'Edytuj: '.$teczka['symbol'];
$jrwa  = ezd_jrwa_all();
$users = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$row   = $teczka;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'jrwa_id'  => (int)($_POST['jrwa_id']  ?? 0) ?: null,
        'symbol'   => trim($_POST['symbol']    ?? ''),
        'title'    => trim($_POST['title']     ?? ''),
        'rok'      => (int)($_POST['rok']      ?? date('Y')),
        'owner_id' => (int)($_POST['owner_id'] ?? 0) ?: null,
        'status'   => $_POST['status']         ?? 'open',
    ];
    if (!$row['symbol']) $errors[] = 'Symbol jest wymagany.';
    if (!$row['title'])  $errors[] = 'Tytuł jest wymagany.';
    if (!in_array($row['status'], ['open','closed'])) $errors[] = 'Nieprawidłowy status.';
    if (!$errors) {
        ezd_teczka_update($id, $row, (int)current_user()['id']);
        flash_set('success','Teczka zaktualizowana.');
        header('Location:'.APP_URL.'/ezd/teczki/view.php?id='.$id); exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/index.php">Teczki</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $id ?>"><?= h($teczka['symbol']) ?></a></li>
  <li class="breadcrumb-item active">Edytuj</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-pencil text-primary me-2"></i>Edytuj teczkę <span class="font-monospace"><?= h($teczka['symbol']) ?></span></h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-6">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm"><div class="card-body">
  <div class="mb-3">
    <label class="form-label fw-semibold">Klasyfikacja JRWA</label>
    <select name="jrwa_id" class="form-select">
      <option value="">— brak —</option>
      <?php foreach($jrwa as $j): ?>
      <option value="<?= $j['id'] ?>" <?= (int)$row['jrwa_id']===$j['id']?'selected':'' ?>>
        <?= h($j['symbol'].' — '.$j['title'].' ('.$j['kat_arch'].') ') ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Symbol teczki <span class="text-danger">*</span></label>
    <input type="text" name="symbol" class="form-control text-uppercase font-monospace"
           value="<?= h($row['symbol']) ?>" maxlength="20" required>
    <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Zmiana symbolu nie zmienia już nadanych znaków koszulek!</div>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł teczki <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>" required>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-4">
      <label class="form-label fw-semibold">Rok</label>
      <input type="number" name="rok" class="form-control" value="<?= $row['rok'] ?>" min="2000" max="2099">
    </div>
    <div class="col-8">
      <label class="form-label fw-semibold">Właściciel</label>
      <select name="owner_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Status teczki</label>
    <select name="status" class="form-select">
      <option value="open"   <?= $row['status']==='open'  ?'selected':'' ?>>Otwarta</option>
      <option value="closed" <?= $row['status']==='closed'?'selected':'' ?>>Zamknięta</option>
    </select>
    <div class="form-text">Zamknięcie teczki zablokuje zakładanie nowych koszulek.</div>
  </div>
</div><div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
  <a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div></div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
