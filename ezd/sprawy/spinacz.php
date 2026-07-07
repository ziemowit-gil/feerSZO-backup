<?php
/**
 * Spinacz — łączy 2+ wgranych plików (Word/Excel są automatycznie konwertowane
 * na PDF) w jeden plik PDF i dodaje go do repozytorium koszulki.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$sprawa_id = (int)($_GET['sprawa_id'] ?? 0);
$sprawa    = ezd_sprawa_get($sprawa_id);
if (!$sprawa) { flash_set('error', 'Koszulka nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

$user_id = (int)current_user()['id'];
$access  = ezd_sprawa_access($sprawa, $user_id);
if (!$access) { flash_set('error', 'Brak dostępu do tej koszulki.'); header('Location: ' . APP_URL . '/ezd/index.php'); exit; }

$can_act = $access === 'write' && $sprawa['status'] !== 'closed';
if (!$can_act) { flash_set('error', 'Brak uprawnień do dodawania plików w tej koszulce.'); header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id); exit; }

$PAGE_TITLE = 'Spinacz — ' . $sprawa['znak_sprawy'];
$grupy = ezd_grupy_by_sprawa($sprawa_id);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $custom_name = trim($_POST['custom_name'] ?? '');
    $grupa_id    = (int)($_POST['grupa_id'] ?? 0) ?: null;

    $r = ezd_spinacz_merge($sprawa_id, $user_id, 'files', $custom_name ?: null, $grupa_id);
    if ($r['ok']) {
        flash_set('success', 'Pliki połączone i dodane do repozytorium koszulki.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id . '#files'); exit;
    }
    $errors[] = $r['error'];
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Spinacz</li>
</ol></nav>
<h4 class="fw-bold mb-1"><i class="bi bi-paperclip text-primary me-2"></i>Spinacz</h4>
<div class="text-muted mb-3" style="font-size:.82rem"><i class="bi bi-folder2 me-1"></i>Sprawa: <strong><?= h($sprawa['znak_sprawy']) ?></strong> — <?= h($sprawa['title']) ?></div>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post" enctype="multipart/form-data" id="spinacz-form">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm"><div class="card-body">

  <p class="mb-3" style="font-size:.85rem">
    Wgraj co najmniej 2 pliki (PDF, Word lub Excel). Pliki Word/Excel zostaną automatycznie
    przekonwertowane na PDF, a następnie wszystkie zostaną połączone w <strong>jeden plik PDF</strong>
    i dodane do repozytorium koszulki.
  </p>

  <div class="mb-3">
    <label class="form-label fw-semibold">Pliki do połączenia <span class="text-danger">*</span></label>
    <input type="file" id="spinacz-files" name="files[]" class="form-control" multiple required
           accept=".pdf,.doc,.docx,.xls,.xlsx">
    <small class="text-muted">Maks. 25 MB na plik. Kolejność wybranych plików = kolejność stron w wynikowym PDF.</small>
    <div id="spinacz-count-warn" class="text-danger mt-1 d-none" style="font-size:.8rem">
      <i class="bi bi-exclamation-circle me-1"></i>Wybierz co najmniej 2 pliki.
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label fw-semibold">Nazwa wynikowego pliku <span class="text-muted fw-normal">(opcjonalnie)</span></label>
      <input type="text" name="custom_name" class="form-control" maxlength="200" placeholder="np. Komplet dokumentacji">
      <small class="text-muted">Pozostaw puste, aby użyć nazwy domyślnej.</small>
    </div>
    <?php if($grupy): ?>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Grupa plików <span class="text-muted fw-normal">(opcjonalnie)</span></label>
      <select name="grupa_id" class="form-select">
        <option value="0">— bez grupy —</option>
        <?php foreach($grupy as $g): ?><option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary" id="spinacz-submit"><i class="bi bi-paperclip me-1"></i>Połącz i dodaj do koszulki</button>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>

<script>
(function(){
  var form  = document.getElementById('spinacz-form');
  var input = document.getElementById('spinacz-files');
  var warn  = document.getElementById('spinacz-count-warn');
  if (!form || !input || !warn) return;
  form.addEventListener('submit', function(e){
    if (input.files.length < 2) {
      e.preventDefault();
      warn.classList.remove('d-none');
    } else {
      warn.classList.add('d-none');
    }
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
