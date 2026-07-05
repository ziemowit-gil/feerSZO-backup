<?php
/**
 * Nowy dokument wewnętrzny sprawy (notatka służbowa, opinia, protokół, projekt pisma…).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko');
if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }

$sprawa_id = (int)($_GET['sprawa_id'] ?? 0);
$sprawa    = ezd_sprawa_get($sprawa_id);
if (!$sprawa) { flash_set('error','Sprawa nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
if ($sprawa['status'] === 'closed' && !is_admin()) {
    flash_set('error','Sprawa jest zamknięta.'); header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$sprawa_id); exit;
}

$PAGE_TITLE = 'Nowy dokument — '.$sprawa['znak_sprawy'];
$users = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");

$row = [
    'rodzaj'   => $_GET['rodzaj'] ?? 'notatka_sluzbowa',
    'title'    => '',
    'tresc'    => '',
    'status'   => 'projekt',
    'owner_id' => current_user()['id'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'sprawa_id' => $sprawa_id,
        'rodzaj'    => $_POST['rodzaj']   ?? 'notatka_sluzbowa',
        'title'     => trim($_POST['title'] ?? ''),
        'tresc'     => $_POST['tresc']     ?? '',
        'status'    => $_POST['status']    ?? 'projekt',
        'owner_id'  => (int)($_POST['owner_id'] ?? 0) ?: null,
    ];
    if (!$row['title']) $errors[] = 'Tytuł dokumentu jest wymagany.';

    if (!$errors) {
        try {
            $uid = (int)current_user()['id'];
            $did = ezd_dokument_create($row, $uid);
            if (!empty($_FILES['file']['tmp_name'])) {
                $err = ezd_upload('file', $sprawa_id, $uid, null, null, $did);
                if ($err) flash_set('error', 'Dokument zapisano, ale pliku nie wgrano: ' . $err);
            }
            flash_set('success','Dokument wewnętrzny dodany.');
            header('Location:'.APP_URL.'/ezd/dokumenty/view.php?id='.$did); exit;
        } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Nowy dokument</li>
</ol></nav>
<h4 class="fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2"></i>Nowy dokument wewnętrzny</h4>
<div class="text-muted mb-3" style="font-size:.82rem"><i class="bi bi-folder2 me-1"></i>Sprawa: <strong><?= h($sprawa['znak_sprawy']) ?></strong> — <?= h($sprawa['title']) ?></div>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post" enctype="multipart/form-data">
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
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>" placeholder="np. Notatka ze spotkania z kontrahentem" required>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Treść</label>
    <textarea name="tresc" class="form-control" rows="8" placeholder="Treść dokumentu…"><?= h($row['tresc']) ?></textarea>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label fw-semibold">Referent / autor</label>
      <select name="owner_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Plik <span class="text-muted fw-normal">(opcjonalnie)</span></label>
      <input type="file" name="file" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg">
      <small class="text-muted">Maks. 25 MB</small>
    </div>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Dodaj dokument</button>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
