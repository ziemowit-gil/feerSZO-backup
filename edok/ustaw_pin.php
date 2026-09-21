<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$user = current_user();
$uid  = (int)$user['id'];
$has_pin = edok_pin_is_set($uid);

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $current_password = $_POST['current_password'] ?? '';
    $new_pin          = trim($_POST['new_pin'] ?? '');
    $confirm_pin      = trim($_POST['confirm_pin'] ?? '');

    $db_user = db_one("SELECT password FROM users WHERE id = ?", [$uid]);
    if (empty($db_user['password']) || !password_verify($current_password, $db_user['password'])) {
        $errors[] = 'Aktualne hasło logowania jest nieprawidłowe.';
    }
    if (!preg_match('/^\d{6}$/', $new_pin)) {
        $errors[] = 'PIN musi składać się z dokładnie 6 cyfr.';
    }
    if ($new_pin !== $confirm_pin) {
        $errors[] = 'PIN-y nie są identyczne.';
    }

    if (!$errors) {
        edok_pin_set($uid, $new_pin);
        edok_log(0, 'pin_set', '', '', '', ($has_pin ? 'Zmieniono' : 'Ustawiono') . ' PIN EODoK dla użytkownika ' . ($user['name'] ?? ('uid:' . $uid)) . '.');
        flash_set('success', 'PIN EODoK został ' . ($has_pin ? 'zmieniony' : 'ustawiony') . '.');
        header('Location: ' . APP_URL . '/edok/ustaw_pin.php');
        exit;
    }
}

$PAGE_TITLE = 'Twój PIN EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Twój PIN EODoK</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong><?= $has_pin ? 'Zmiana PIN-u' : 'Ustawienie PIN-u' ?></strong></div>
      <div class="card-body">
        <p class="small text-muted">
          6-cyfrowy PIN EODoK to osobny sekret od hasła logowania. Jest wymagany przy każdej
          akceptacji ("Tak/OK") etapu obiegu dokumentu — zgodnie z Uchwałą Zarządu nr 5/2026
          w sprawie elektronicznej akceptacji dokumentów. Nie udostępniaj go nikomu.
        </p>
        <form method="post" novalidate>
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="current_password">Aktualne hasło logowania</label>
            <input type="password" id="current_password" name="current_password" class="form-control form-control-sm" required autocomplete="current-password">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="new_pin"><?= $has_pin ? 'Nowy PIN' : 'PIN' ?> (6 cyfr)</label>
            <input type="password" id="new_pin" name="new_pin" class="form-control form-control-sm" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="off">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="confirm_pin">Powtórz PIN</label>
            <input type="password" id="confirm_pin" name="confirm_pin" class="form-control form-control-sm" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="off">
          </div>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> <?= $has_pin ? 'Zmień PIN' : 'Ustaw PIN' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
