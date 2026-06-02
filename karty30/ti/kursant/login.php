<?php
/**
 * Panel kursanta TI — logowanie.
 * Całkowicie niezależne od systemu głównego i K30.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

// Już zalogowany → redirect
if (student_current()) {
    header('Location: ' . APP_URL . '/karty30/ti/kursant/index.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login']    ?? '');
    $password = $_POST['password'] ?? '';

    $account = db_one(
        "SELECT * FROM k30_ti_student_accounts WHERE login=? AND is_active=1",
        [$login]
    );

    if ($account && password_verify($password, $account['password_hash'])) {
        student_login_user($account);
        db()->prepare("UPDATE k30_ti_student_accounts SET last_login=datetime('now') WHERE id=?")
           ->execute([$account['id']]);
        header('Location: ' . APP_URL . '/karty30/ti/kursant/index.php'); exit;
    }
    $error = 'Nieprawidłowy login lub hasło.';
}

$org = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel kursanta — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-card {
  background: #1e293b; border-radius: 16px; padding: 2.5rem 2rem;
  width: 100%; max-width: 380px; box-shadow: 0 8px 40px rgba(0,0,0,.5);
}
.login-icon {
  width: 60px; height: 60px; border-radius: 14px;
  background: linear-gradient(135deg, #2563eb, #7c3aed);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; color: #fff; margin: 0 auto 1.5rem;
}
.login-title { color: #f1f5f9; font-size: 1.3rem; font-weight: 700; text-align: center; margin-bottom: .25rem; }
.login-sub   { color: #94a3b8; font-size: .84rem; text-align: center; margin-bottom: 1.75rem; }
.form-label  { color: #cbd5e1; font-size: .83rem; font-weight: 600; }
.form-control {
  background: #0f172a; border-color: #334155; color: #f1f5f9;
  border-radius: 8px;
}
.form-control:focus { background: #0f172a; border-color: #2563eb; color: #f1f5f9; box-shadow: 0 0 0 3px #2563eb33; }
.btn-login {
  background: linear-gradient(135deg, #2563eb, #7c3aed); border: none;
  border-radius: 8px; color: #fff; font-weight: 700; width: 100%; padding: .7rem;
  font-size: .95rem; transition: opacity .15s;
}
.btn-login:hover { opacity: .9; color: #fff; }
.alert-err { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; border-radius: 8px; padding: .6rem .85rem; font-size: .83rem; margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="login-card">
  <div class="login-icon"><i class="bi bi-pc-display"></i></div>
  <div class="login-title">Panel kursanta</div>
  <div class="login-sub"><?= h($org) ?> · Zajęcia informatyki / TI</div>

  <?php if ($error): ?>
  <div class="alert-err"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <div class="mb-3">
      <label class="form-label">Login</label>
      <input type="text" class="form-control" name="login"
             value="<?= h($_POST['login'] ?? '') ?>"
             autofocus autocomplete="username" placeholder="Twój login">
    </div>
    <div class="mb-4">
      <label class="form-label">Hasło</label>
      <input type="password" class="form-control" name="password"
             autocomplete="current-password" placeholder="••••••••">
    </div>
    <button type="submit" class="btn-login">
      <i class="bi bi-box-arrow-in-right me-2"></i>Zaloguj się
    </button>
  </form>

  <div class="text-center mt-3" style="font-size:.75rem;color:#475569">
    Nie masz konta? Skontaktuj się z prowadzącym.
  </div>
</div>
</body>
</html>
