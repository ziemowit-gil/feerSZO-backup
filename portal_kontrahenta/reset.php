<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_portal_auth.php';

auth_start();
edok_portal_migrate();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    if ($password !== $password2) {
        $error = 'Hasła nie są identyczne.';
    } else {
        $res = edok_portal_reset_password($token, $password);
        if ($res['ok']) {
            header('Location: ' . APP_URL . '/portal_kontrahenta/login.php?msg=reset_ok');
            exit;
        }
        $error = $res['error'];
    }
}

$PAGE_TITLE = 'Ustaw nowe hasło';
require __DIR__ . '/_layout_head.php';
?>
<div class="pk-card">
  <h1>Ustaw nowe hasło</h1>
  <?php if ($error): ?>
  <div class="pk-alert pk-alert-danger"><?= h($error) ?></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <div class="pk-field">
      <label for="password">Nowe hasło (min. 8 znaków)</label>
      <input type="password" id="password" name="password" required minlength="8" autofocus>
    </div>
    <div class="pk-field">
      <label for="password2">Powtórz nowe hasło</label>
      <input type="password" id="password2" name="password2" required minlength="8">
    </div>
    <button type="submit" class="pk-btn">Zapisz nowe hasło</button>
  </form>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
