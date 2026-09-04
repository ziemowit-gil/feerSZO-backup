<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_portal_auth.php';

auth_start();
edok_portal_migrate();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nip      = trim($_POST['nip'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $nazwa    = trim($_POST['nazwa'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($password !== $password2) {
        $error = 'Hasła nie są identyczne.';
    } else {
        $res = edok_portal_register($nip, $email, $nazwa, $password);
        if ($res['ok']) {
            header('Location: ' . APP_URL . '/portal_kontrahenta/login.php?msg=registered');
            exit;
        }
        $error = $res['error'];
    }
}

$PAGE_TITLE = 'Załóż konto';
$BREADCRUMB = '<a href="' . APP_URL . '/portal_kontrahenta/login.php">Strona główna</a> / Załóż konto';
require __DIR__ . '/_layout_head.php';
?>
<div class="pk-card">
  <h1>Załóż konto</h1>
  <p style="font-size:.88rem;color:var(--pk-muted)">
    Konto może założyć kontrahent, którego NIP widnieje już na dokumencie w systemie organizacji.
  </p>
  <?php if ($error): ?>
  <div class="pk-alert pk-alert-danger"><?= h($error) ?></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="pk-field">
      <label for="nip">NIP</label>
      <input type="text" id="nip" name="nip" required maxlength="13" value="<?= h($_POST['nip'] ?? '') ?>">
    </div>
    <div class="pk-field">
      <label for="nazwa">Nazwa / imię i nazwisko</label>
      <input type="text" id="nazwa" name="nazwa" required maxlength="200" value="<?= h($_POST['nazwa'] ?? '') ?>">
    </div>
    <div class="pk-field">
      <label for="email">Adres e-mail</label>
      <input type="email" id="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>">
    </div>
    <div class="pk-field">
      <label for="password">Hasło (min. 8 znaków)</label>
      <input type="password" id="password" name="password" required minlength="8">
    </div>
    <div class="pk-field">
      <label for="password2">Powtórz hasło</label>
      <input type="password" id="password2" name="password2" required minlength="8">
    </div>
    <button type="submit" class="pk-btn">Załóż konto</button>
  </form>
  <p style="margin-top:18px;font-size:.9rem"><a href="<?= APP_URL ?>/portal_kontrahenta/login.php">Wróć do logowania</a></p>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
