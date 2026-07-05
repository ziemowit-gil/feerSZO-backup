<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł kancelarii');

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error','Teczka nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
if (ezd_sprawa_access($sprawa, (int)current_user()['id']) !== 'write') { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }
if ($sprawa['status'] === 'closed' && !is_admin()) {
    flash_set('error','Teczka jest zamknięta — tylko administrator może ją edytować.');
    header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$id); exit;
}

$PAGE_TITLE = 'Edytuj: '.$sprawa['znak_sprawy'];
$users  = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$teczki = ezd_teczki_all(); // all statuses for admin

$row = $sprawa;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'teczka_id'   => (int)($_POST['teczka_id']   ?? $sprawa['teczka_id']),
        'title'       => trim($_POST['title']         ?? ''),
        'description' => trim($_POST['description']   ?? ''),
        'status'      => $_POST['status']             ?? $sprawa['status'],
        'priority'    => $_POST['priority']           ?? $sprawa['priority'],
        'owner_id'    => (int)($_POST['owner_id']     ?? 0) ?: null,
        'deadline'    => $_POST['deadline']           ?? '',
        'ciagla'      => isset($_POST['ciagla']) ? 1 : 0,
    ];
    if (!$row['title']) $errors[] = 'Tytuł teczki jest wymagany.';

    if (!$errors) {
        try {
            ezd_sprawa_update($id, $row, (int)current_user()['id']);
            flash_set('success','Teczka zaktualizowana.');
            header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$id); exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/index.php">Teczki</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Edytuj</li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-pencil text-primary me-2"></i>Edytuj teczkę <span class="font-monospace"><?= h($sprawa['znak_sprawy']) ?></span></h4>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>
<?php if($sprawa['status']==='closed' && is_admin()): ?>
<div class="alert alert-warning py-2 px-3" style="font-size:.82rem"><i class="bi bi-lock me-1"></i><strong>Teczka zamknięta</strong> — edytujesz jako administrator.</div>
<?php endif; ?>

<div class="row"><div class="col-lg-7">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">
  <!-- Znak teczki (readonly) -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Znak teczki</label>
    <input type="text" class="form-control font-monospace" value="<?= h($sprawa['znak_sprawy']) ?>" readonly>
  </div>
  <!-- Teczka -->
  <?php if(is_admin()): ?>
  <div class="mb-3">
    <label class="form-label fw-semibold">Teczka aktowa</label>
    <select name="teczka_id" class="form-select">
      <?php foreach($teczki as $t): ?>
      <option value="<?= $t['id'] ?>" <?= (int)$row['teczka_id']===$t['id']?'selected':'' ?>>
        <?= h($t['symbol'].' — '.$t['title'].' ('.$t['rok'].')') ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
  <input type="hidden" name="teczka_id" value="<?= $sprawa['teczka_id'] ?>">
  <?php endif; ?>
  <!-- Tytuł -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł teczki <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>" required>
  </div>
  <!-- Opis -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Opis / uwagi</label>
    <textarea name="description" class="form-control" rows="3"><?= h($row['description']) ?></textarea>
  </div>
  <!-- Status + Priorytet -->
  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(EZD_STATUSES_SPRAWA as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($sl['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if(!is_admin()): ?><div class="form-text">Zamknięcie teczki zablokuje dalszą edycję dla non-adminów.</div><?php endif; ?>
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Priorytet</label>
      <select name="priority" class="form-select">
        <?php foreach(EZD_PRIORITIES as $pv=>$pl): ?>
        <option value="<?= $pv ?>" <?= $row['priority']===$pv?'selected':'' ?>><?= h($pl['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <!-- Właściciel + Deadline -->
  <div class="row g-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Właściciel / referent</label>
      <select name="owner_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Termin</label>
      <input type="date" name="deadline" id="f-deadline" class="form-control" value="<?= h($row['deadline']) ?>" <?= !empty($row['ciagla'])?'disabled':'' ?>>
    </div>
  </div>
  <div class="form-check form-switch mt-3">
    <input class="form-check-input" type="checkbox" role="switch" name="ciagla" id="f-ciagla" value="1" <?= !empty($row['ciagla'])?'checked':'' ?>
           onchange="document.getElementById('f-deadline').disabled=this.checked; if(this.checked)document.getElementById('f-deadline').value='';">
    <label class="form-check-label fw-semibold" for="f-ciagla">Teczka ciągła (stale otwarta)</label>
    <div class="form-text">Bez terminu zakończenia; nie podlega przypomnieniom i nie zostanie zamknięta zwykłym zapisem.</div>
  </div>
</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
