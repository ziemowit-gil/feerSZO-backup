<?php
/**
 * auth/set_password.php — Ustawienie hasła przez link aktywacyjny.
 *
 * GET:  ?token=XXX            — pokazuje formularz
 * POST: token, password, password2 — zapisuje hasło i loguje
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/auth_screen.php';

auth_start();

if (current_user()) {
    header('Location: ' . auth_default_landing()); exit;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$user  = null;
$error = '';

if ($token) {
    try {
        $user = db_one(
            "SELECT * FROM users WHERE activation_token = ? AND is_active = 1",
            [$token]
        );
    } catch (\Throwable $e) {}
}

$invalid_token = !$token || !$user;

if (!$invalid_token && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass1 = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass1) < 8) {
        $error = 'Hasło musi mieć co najmniej 8 znaków.';
    } elseif (!preg_match('/[A-Z]/', $pass1)) {
        $error = 'Hasło musi zawierać co najmniej jedną wielką literę.';
    } elseif (!preg_match('/[0-9]/', $pass1)) {
        $error = 'Hasło musi zawierać co najmniej jedną cyfrę.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Hasła nie są identyczne.';
    } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT);
        db()->prepare(
            "UPDATE users SET password = ?, activation_token = NULL, must_change_password = 0 WHERE id = ?"
        )->execute([$hash, (int)$user['id']]);

        try {
            require_once dirname(__DIR__) . '/includes/auth_security.php';
            authlog_write((int)$user['id'], 'pwd_set_via_link', $user['email'] ?? '', 'Hasło ustawione przez link aktywacyjny');
        } catch (\Throwable $e) {}

        login_user($user);
        header('Location: ' . auth_default_landing()); exit;
    }
}

// Widok: jasny wygląd ekranów wejścia (includes/auth_screen.php, tryb 'light'; przebudowa 2026-11-14). Logika powyżej bez zmian.
// Błąd stoi pod polem, którego dotyczy: niezgodne hasła — pod „Powtórz hasło”, pozostałe — pod „Nowe hasło”.
$err_field = $error === '' ? '' : ($error === 'Hasła nie są identyczne.' ? 'password2' : 'password');
$_ferr = function (string $f) use ($error, $err_field): string {
    if ($f !== $err_field) return '';
    return '<p class="au-ferr" id="' . $f . '-err" role="alert"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>'
         . '<span id="login-error-text">' . h($error) . '</span></p>';
};
$_fattr = fn(string $f, string $hint = ''): string => ($f === $err_field)
    ? ' aria-invalid="true" aria-describedby="' . trim($hint . ' ' . $f . '-err') . '"'
    : ($hint !== '' ? ' aria-describedby="' . $hint . '"' : '');

auth_screen_head([
    'title'           => 'Ustaw hasło',
    'light'           => true,
    'width'           => 460,
    'main_id'         => 'setpw-main',
    'account_buttons' => [],
]);
?>
<div class="ks-hero-ico" aria-hidden="true"><i class="bi bi-key-fill"></i></div>
<h1 class="ks-h1">Ustaw swoje hasło</h1>

<?php if ($invalid_token): ?>
<div class="l-alert l-alert-danger" role="alert">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <div><strong>Link jest nieprawidłowy lub już został użyty.</strong><br>Skontaktuj się z administratorem, który wyśle nowy link.</div>
</div>
<a href="<?= h(APP_URL) ?>/auth/login.php" class="ks-btn ks-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć do logowania</a>

<?php else: ?>
<p class="ks-lead">Witaj, <strong><?= h($user['name'] ?? $user['email']) ?></strong>! Ustaw swoje hasło, aby aktywować konto.</p>

<form method="post" autocomplete="off" novalidate>
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <div class="ks-field">
    <label for="password">Nowe hasło</label>
    <div class="pass-wrap">
      <input type="password" name="password" id="password" class="form-control" autofocus autocomplete="new-password" required<?= $_fattr('password', 'password-hint') ?>>
      <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" aria-controls="password" onclick="togglePass('password', this)"><i class="bi bi-eye" aria-hidden="true"></i></button>
    </div>
    <p class="ks-fieldhint" id="password-hint">Min. 8 znaków, 1 wielka litera, 1 cyfra.</p>
    <?= $_ferr('password') ?>
  </div>
  <div class="ks-field">
    <label for="password2">Powtórz hasło</label>
    <div class="pass-wrap">
      <input type="password" name="password2" id="password2" class="form-control" autocomplete="new-password" required<?= $_fattr('password2') ?>>
      <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" aria-controls="password2" onclick="togglePass('password2', this)"><i class="bi bi-eye" aria-hidden="true"></i></button>
    </div>
    <?= $_ferr('password2') ?>
  </div>
  <button type="submit" class="ks-btn ks-btn--primary"><i class="bi bi-check-lg" aria-hidden="true"></i>Ustaw hasło i zaloguj się</button>
</form>
<p class="ks-note"><a href="<?= h(APP_URL) ?>/auth/login.php">Masz już hasło? Zaloguj się</a></p>
<?php endif; ?>
<?php
auth_screen_foot(['no_new_tiles' => true]);
