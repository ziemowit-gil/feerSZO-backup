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

$KP_TITLE = 'Logowanie — Panel kursanta';
$KP_BODY_CLASS = 'd-flex align-items-center justify-content-center py-4';
include __DIR__ . '/_layout_head.php';
?>
<main id="main" class="w-100" style="max-width:400px">
  <div class="card shadow-lg border-0">
    <div class="card-body p-4 p-sm-5">
      <div class="text-center mb-4">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 mb-3"
              style="width:60px;height:60px;background:linear-gradient(135deg,#2563eb,#7c3aed)">
          <i class="bi bi-pc-display fs-3 text-white" aria-hidden="true"></i>
        </span>
        <h1 class="h4 fw-bold mb-1">Panel kursanta</h1>
        <p class="text-body-secondary small mb-0"><?= h($org) ?> · Zajęcia informatyki / TI</p>
      </div>

      <?php if ($error): ?>
      <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <span><?= h($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <div class="mb-3">
          <label class="form-label" for="login">Login</label>
          <input type="text" class="form-control" id="login" name="login"
                 value="<?= h($_POST['login'] ?? '') ?>" required
                 autofocus autocomplete="username" placeholder="Twój login">
        </div>
        <div class="mb-4">
          <label class="form-label" for="password">Hasło</label>
          <input type="password" class="form-control" id="password" name="password"
                 required autocomplete="current-password" placeholder="••••••••">
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
          <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
        </button>
      </form>

      <p class="text-center text-body-secondary mt-3 mb-0" style="font-size:.78rem">
        Nie masz konta? Skontaktuj się z prowadzącym.
      </p>
    </div>
  </div>
</main>
<?php include __DIR__ . '/_layout_foot.php'; ?>
