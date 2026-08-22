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
    header('Location: ' . APP_URL . '/tozsamosc/index.php'); exit;
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
        header('Location: ' . APP_URL . '/tozsamosc/index.php'); exit;
    }
}

$_b       = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Organizacja');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ustaw hasło — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<?php auth_screen_bg_css(); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { min-height: 100%; margin: 0; }
.page-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 2rem 1rem;
}
.login-card {
  width: 100%; max-width: 420px;
  background: #fff; border-radius: 16px;
  box-shadow: 0 4px 32px rgba(0,0,0,.1);
  overflow: hidden;
}
.card-top {
  background: linear-gradient(155deg, var(--c-darker) 0%, var(--c) 55%, var(--c-light) 100%);
  padding: 1.75rem 2rem 1.5rem; text-align: center;
}
.card-top-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto .75rem; font-size: 1.75rem; color: #fff;
}
.card-top h1 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; }
.card-top p  { color: rgba(255,255,255,.8); font-size: .875rem; margin: .4rem 0 0; }
.card-body-inner { padding: 2rem; }
.btn-primary { background: var(--c); border-color: var(--c); }
.btn-primary:hover { background: var(--c-darker); border-color: var(--c-darker); }
.pwd-hint { font-size: .8rem; color: #6c757d; margin-top: .4rem; }
</style>
</head>
<body>
<div class="page-wrap">
  <div class="login-card">

    <!-- Nagłówek -->
    <div class="card-top">
      <div class="card-top-icon"><i class="bi bi-key-fill"></i></div>
      <h1>Ustaw swoje hasło</h1>
      <p><?= h($org_name) ?></p>
    </div>

    <div class="card-body-inner">

      <?php if ($invalid_token): ?>
      <!-- Link nieprawidłowy / wygasły -->
      <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div>
          <strong>Link jest nieprawidłowy lub już został użyty.</strong><br>
          Skontaktuj się z administratorem, który wyśle nowy link.
        </div>
      </div>
      <div class="text-center mt-3">
        <a href="<?= h(APP_URL) ?>/auth/login.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>Wróć do logowania
        </a>
      </div>

      <?php else: ?>
      <!-- Formularz ustawiania hasła -->
      <p class="text-muted mb-4" style="font-size:.9rem">
        Witaj, <strong><?= h($user['name'] ?? $user['email']) ?></strong>!<br>
        Ustaw swoje hasło, aby aktywować konto.
      </p>

      <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off" novalidate>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="mb-3">
          <label class="form-label fw-semibold">Nowe hasło</label>
          <input type="password" name="password" id="password"
                 class="form-control" autofocus autocomplete="new-password" required>
          <div class="pwd-hint">Min. 8 znaków, 1 wielka litera, 1 cyfra.</div>
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold">Powtórz hasło</label>
          <input type="password" name="password2" id="password2"
                 class="form-control" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-semibold">
          <i class="bi bi-check-lg me-1"></i>Ustaw hasło i zaloguj się
        </button>
      </form>

      <div class="text-center mt-3">
        <a href="<?= h(APP_URL) ?>/auth/login.php" class="text-muted" style="font-size:.85rem">
          Masz już hasło? Zaloguj się
        </a>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>
