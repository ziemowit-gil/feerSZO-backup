<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_portal_auth.php';

auth_start();
edok_portal_migrate();

$token = trim($_GET['token'] ?? '');
$ok = $token !== '' && edok_portal_verify($token);

if ($ok) {
    header('Location: ' . APP_URL . '/portal_kontrahenta/login.php?msg=verified');
    exit;
}

$PAGE_TITLE = 'Aktywacja konta';
require __DIR__ . '/_layout_head.php';
?>
<div class="pk-card">
  <h1>Aktywacja konta</h1>
  <div class="pk-alert pk-alert-danger">Link aktywacyjny jest nieprawidłowy lub wygasł.</div>
  <p style="font-size:.9rem"><a href="<?= APP_URL ?>/portal_kontrahenta/login.php">Wróć do logowania</a></p>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
