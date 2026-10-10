<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_portal_auth.php';
require_once __DIR__ . '/../inc/loginShell/loginShell.php';

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

$msg = $_GET['msg'] ?? '';

loginShellHead([
    'title'     => 'Logowanie — Portal Kontrahenta',
    'orgLabel'  => 'FEER · Portal Kontrahenta',
    'sideTitle' => 'Portal Kontrahenta',
    'sideSub'   => 'Jak się zalogować:',
    'sideSteps' => [
        'Wpisz NIP firmy albo adres e-mail.',
        'Wpisz hasło.',
        'Nie masz konta? Załóż je i potwierdź linkiem z e-maila.',
    ],
    'banner'    => false,   // kontrahenci nie korzystają z logowania centralnego
    'mainId'    => 'pk-login-main',
]);
?>
      <h1 class="h4 fw-bold mb-3">Logowanie do Portalu Kontrahenta</h1>

      <?php if ($error): ?>
      <div class="alert alert-danger small py-2" role="alert" aria-live="assertive"><?= h($error) ?></div>
      <?php endif; ?>
      <?php if ($msg === 'verified'): ?>
      <div class="alert alert-success small py-2" role="status">Konto potwierdzone — możesz się teraz zalogować.</div>
      <?php elseif ($msg === 'reset_ok'): ?>
      <div class="alert alert-success small py-2" role="status">Hasło zostało zmienione — możesz się teraz zalogować.</div>
      <?php elseif ($msg === 'registered'): ?>
      <div class="alert alert-info small py-2" role="status">Konto założone. Sprawdź skrzynkę pocztową i kliknij link aktywacyjny.</div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="mb-3">
          <label for="identifier">NIP lub adres e-mail</label>
          <input type="text" class="form-control form-control-lg" id="identifier" name="identifier"
                 value="<?= h($_POST['identifier'] ?? '') ?>" required autofocus autocomplete="username"
                 <?php if ($error): ?>aria-invalid="true"<?php endif; ?>>
        </div>
        <div class="mb-4">
          <label for="password">Hasło</label>
          <div class="pass-wrap">
            <input type="password" class="form-control form-control-lg" id="password" name="password"
                   required autocomplete="current-password">
            <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('password', this)">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="tz-btn">Zaloguj się</button>
      </form>
      <p class="tz-hint"><a href="<?= APP_URL ?>/portal_kontrahenta/forgot.php">Nie pamiętam hasła</a></p>
      <p class="tz-hint mb-2">Nie masz konta?</p>
      <a href="<?= APP_URL ?>/portal_kontrahenta/register.php" class="tz-btn tz-btn-ghost">Załóż konto</a>
<?php
loginShellFoot();
