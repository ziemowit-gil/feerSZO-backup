<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_portal_auth.php';

auth_start();
edok_portal_migrate();

if (edok_portal_current()) {
    header('Location: ' . APP_URL . '/portal_kontrahenta/index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = edok_portal_login(trim($_POST['identifier'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($res['ok']) {
        header('Location: ' . APP_URL . '/portal_kontrahenta/index.php');
        exit;
    }
    $error = $res['error'];
}

$PAGE_TITLE = 'Logowanie';
$BREADCRUMB = '<a href="' . APP_URL . '/portal_kontrahenta/login.php">Strona główna</a> / Logowanie';
require __DIR__ . '/_layout_head.php';
?>
<div class="pk-card">
  <h1>Logowanie do Portalu Kontrahenta</h1>
  <?php if ($error): ?>
  <div class="pk-alert pk-alert-danger"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($msg = $_GET['msg'] ?? ''): ?>
    <?php if ($msg === 'verified'): ?>
    <div class="pk-alert pk-alert-success">Konto potwierdzone — możesz się teraz zalogować.</div>
    <?php elseif ($msg === 'reset_ok'): ?>
    <div class="pk-alert pk-alert-success">Hasło zostało zmienione — możesz się teraz zalogować.</div>
    <?php elseif ($msg === 'registered'): ?>
    <div class="pk-alert pk-alert-info">Konto założone. Sprawdź skrzynkę pocztową i kliknij link aktywacyjny.</div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="pk-field">
      <label for="identifier">NIP lub adres e-mail</label>
      <input type="text" id="identifier" name="identifier" required autofocus>
    </div>
    <div class="pk-field">
      <label for="password">Hasło</label>
      <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" class="pk-btn">Zaloguj</button>
  </form>
  <p style="margin-top:18px;font-size:.9rem">
    <a href="<?= APP_URL ?>/portal_kontrahenta/forgot.php">Nie pamiętam hasła</a>
  </p>
  <p style="font-size:.9rem">
    Nie masz konta? <a href="<?= APP_URL ?>/portal_kontrahenta/register.php">Załóż konto</a>
  </p>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
