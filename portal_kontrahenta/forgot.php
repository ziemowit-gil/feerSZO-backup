<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_portal_auth.php';

auth_start();
edok_portal_migrate();

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    edok_portal_request_reset(trim($_POST['email'] ?? ''));
    $sent = true; // zawsze ten sam komunikat — nie ujawniamy czy konto istnieje
}

$PAGE_TITLE = 'Przypomnij hasło';
$BREADCRUMB = '<a href="' . APP_URL . '/portal_kontrahenta/login.php">Strona główna</a> / Przypomnij hasło';
require __DIR__ . '/_layout_head.php';
?>
<div class="pk-card">
  <h1>Przypomnij hasło</h1>
  <?php if ($sent): ?>
  <div class="pk-alert pk-alert-success">Jeśli podany adres e-mail jest powiązany z aktywnym kontem, wysłaliśmy na niego link do resetu hasła.</div>
  <p style="font-size:.9rem"><a href="<?= APP_URL ?>/portal_kontrahenta/login.php">Wróć do logowania</a></p>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="pk-field">
      <label for="email">Adres e-mail</label>
      <input type="email" id="email" name="email" required autofocus>
    </div>
    <button type="submit" class="pk-btn">Wyślij link resetujący</button>
  </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
