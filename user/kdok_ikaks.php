<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
kdok_migrate();

$user   = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old  = $_POST['ikaks_old'] ?? '';
    $new1 = $_POST['ikaks_new1'] ?? '';
    $new2 = $_POST['ikaks_new2'] ?? '';

    if (strlen($new1) < 6) {
        $errors[] = 'Nowy IKAKS musi mieć minimum 6 znaków.';
    } elseif ($new1 !== $new2) {
        $errors[] = 'Nowe kody IKAKS nie są identyczne.';
    } elseif (kdok_ikaks_has((int)$user['id']) && !kdok_ikaks_verify((int)$user['id'], $old)) {
        $errors[] = 'Obecny IKAKS jest nieprawidłowy.';
    } else {
        kdok_ikaks_set((int)$user['id'], $new1);
        flash_set('success', 'IKAKS zostało zaktualizowane.');
        header('Location: ' . APP_URL . '/user/kdok_ikaks.php');
        exit;
    }
}

$has_ika  = kdok_ikaks_has((int)$user['id']);
$cert     = kdok_cert_get((int)$user['id']);
$PAGE_TITLE = 'Mój IKAKS — eObieg DK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-key-fill text-warning"></i> Mój IKAKS — autoryzacja dokumentów</h4>
</div>
<?= flash_html() ?>

<!-- Status autoryzacji -->
<div class="row g-3 mb-4" style="max-width:640px">
  <div class="col-sm-6">
    <div class="card p-3 text-center border-<?= $has_ika ? 'success' : 'danger' ?>">
      <i class="bi bi-key-fill fs-2 text-<?= $has_ika ? 'success' : 'danger' ?>"></i>
      <div class="fw-semibold mt-1">IKAKS</div>
      <div class="small text-muted"><?= $has_ika ? 'Ustawiony' : 'Brak — skontaktuj się z adminem' ?></div>
    </div>
  </div>
  <div class="col-sm-6">
    <div class="card p-3 text-center border-<?= ($cert && kdok_cert_is_valid($cert)) ? 'success' : 'danger' ?>">
      <i class="bi bi-patch-check fs-2 text-<?= ($cert && kdok_cert_is_valid($cert)) ? 'success' : 'danger' ?>"></i>
      <div class="fw-semibold mt-1">Certyfikat X.509</div>
      <?php if ($cert && kdok_cert_is_valid($cert)): ?>
      <div class="small text-muted"><?= h($cert['subject_cn']) ?><br>do <?= date('d.m.Y', strtotime($cert['valid_to'])) ?></div>
      <?php elseif ($cert): ?>
      <div class="small text-danger">Wygasł <?= date('d.m.Y', strtotime($cert['valid_to'])) ?></div>
      <?php else: ?>
      <div class="small text-muted">Brak — skontaktuj się z adminem</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger" style="max-width:500px">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (!$has_ika): ?>
<div class="alert alert-warning" style="max-width:500px">
  <i class="bi bi-exclamation-triangle"></i>
  Nie masz ustawionego IKAKS. Poproś administratora o jego nadanie w panelu
  <a href="<?= APP_URL ?>/admin/kdok_certs.php">Certyfikaty X.509 i IKAKS</a>.
</div>
<?php else: ?>
<div class="card shadow-sm" style="max-width:500px">
  <div class="card-header py-2 fw-semibold">Zmień IKAKS</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold">Obecny IKAKS</label>
        <input type="password" name="ikaks_old" class="form-control" autocomplete="current-password" required>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Nowy IKAKS <span class="text-muted small">(min. 6 znaków)</span></label>
        <input type="password" name="ikaks_new1" class="form-control" autocomplete="new-password" required>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Powtórz nowy IKAKS</label>
        <input type="password" name="ikaks_new2" class="form-control" autocomplete="new-password" required>
      </div>
      <button type="submit" class="btn btn-warning">
        <i class="bi bi-key"></i> Zmień IKAKS
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
