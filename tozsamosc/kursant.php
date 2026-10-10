<?php
/**
 * tozsamosc/kursant.php — logowanie kursanta w module Tożsamość (wygląd METRO UI).
 * Pierwsze logowanie (konto nigdy nie logowało się): login → kod SMS (tozsamosc/kursant_kod.php).
 * Kolejne logowania: login + hasło. Sesja kursanta jest tworzona jak dotychczas (student_login_user).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_student_otp.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';

karty30_migrate();
if (student_current()) { header('Location: ' . APP_URL . '/karty30/ti/kursant/index.php'); exit; }

$blad  = '';
$login = trim((string)($_POST['login'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $haslo = (string)($_POST['password'] ?? '');
    $acc = $login !== '' ? db_one(
        "SELECT * FROM k30_ti_student_accounts
          WHERE (login = ? OR (login_alias != '' AND login_alias = ?)) AND is_active = 1",
        [$login, $login]) : null;

    if ($acc && !empty($acc['child_access_blocked'])) {
        $blad = 'Dostęp do panelu jest wstrzymany przez opiekuna. Skontaktuj się z rodzicem lub opiekunem.';
    } elseif ($acc && empty($acc['last_login'])) {
        // Pierwsze logowanie: kod SMS na telefon z karty.
        if (ti_student_otp_send((int)$acc['id'], (int)$acc['client_id'])) {
            student_start();
            $_SESSION['k30_ti_student_otp'] = ['id' => (int)$acc['id'], 'ts' => time()];
            header('Location: ' . APP_URL . '/tozsamosc/kursant_kod.php'); exit;
        }
        $blad = 'Nie udało się wysłać kodu. Skontaktuj się z prowadzącym, żeby uzupełnił numer telefonu.';
    } elseif ($acc && $haslo !== '' && password_verify($haslo, (string)$acc['password_hash'])) {
        student_login_user($acc, 'password', "login: {$login}");
        header('Location: ' . APP_URL . '/karty30/ti/kursant/index.php'); exit;
    } else {
        if ($acc && function_exists('ti_account_log')) {
            ti_account_log((int)$acc['id'], 'login_failed', "Nieudana próba dla: {$login}");
        }
        $blad = 'Nieprawidłowy login lub hasło.';
    }
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie kursanta — System Tożsamości</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; }
  .metro-bar { background: #1d1d1d; color: #fff; padding: .75rem 1rem; font-weight: 600; }
  .metro-card { background: #fff; border: 0; border-radius: 0; box-shadow: 0 2px 10px rgba(0,0,0,.08); padding: 2.5rem 2rem; max-width: 460px; margin: 2.5rem auto; }
  .metro-card .form-control-lg { font-size: 1.1rem; padding: .75rem 1rem; }
  .metro-footer { text-align: center; font-size: .85rem; color: #4b5563; margin-top: 1.5rem; }
  .metro-footer a { color: #1d4ed8; text-decoration: none; margin: 0 .5rem; }
  .metro-footer a:hover { text-decoration: underline; }
  .metro-card .form-control { border-radius: 0; box-shadow: none; }
  .metro-btn { border-radius: 0; background: #1ba1e2; border-color: #1ba1e2; color: #fff; font-weight: 600; }
  .metro-btn:hover { background: #1790c9; border-color: #1790c9; color: #fff; }
  .metro-btn:focus-visible, .form-control:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<header class="metro-bar">FEER · System Tożsamości</header>
<main class="metro-card">
  <h1 class="h5 fw-bold mb-1">Zaloguj się do panelu kursanta</h1>
  <p class="small text-body-secondary mb-3"><strong>Logowanie przez SSO.</strong> Logowanie do panelu TI idzie przez centralny serwer logowania Systemu Tożsamości. Adresy i zapisane linki się nie zmieniają.</p>

  <?php if ($blad !== ''): ?>
  <div class="alert alert-danger small py-2 rounded-0" role="alert"><?= htmlspecialchars($blad, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" action="" autocomplete="on">
    <div class="mb-3">
      <label for="kur-login" class="form-label fw-semibold">Login</label>
      <input type="text" class="form-control form-control-lg" id="kur-login" name="login"
             value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username" autofocus>
    </div>
    <div class="align-items-center gap-3 mb-3 d-none" id="kur-sec" aria-live="polite">
      <div id="kur-sec-img" style="width:56px;height:56px;flex-shrink:0" aria-hidden="true"></div>
      <div class="small" id="kur-sec-label"></div>
    </div>
    <div class="mb-4">
      <label for="kur-password" class="form-label fw-semibold">Hasło</label>
      <input type="password" class="form-control form-control-lg" id="kur-password" name="password" autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-lg metro-btn w-100">Dalej</button>
  </form>
</main>
<footer class="metro-footer" aria-label="Pomoc">
  <a href="https://feer.org.pl">Strona Fundacji</a>
</footer>
<script>
(function () {
  var inp = document.getElementById('kur-login'), box = document.getElementById('kur-sec');
  var img = document.getElementById('kur-sec-img'), lbl = document.getElementById('kur-sec-label');
  if (!inp || !box) return;
  function pokaz() {
    var v = inp.value.trim();
    if (!v) { box.classList.add('d-none'); return; }
    fetch('<?= APP_URL ?>/karty30/ti/kursant/sec_image.php?login=' + encodeURIComponent(v), { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { img.innerHTML = j.svg; lbl.textContent = j.label; box.classList.remove('d-none'); box.classList.add('d-flex'); })
      .catch(function () { box.classList.add('d-none'); });
  }
  inp.addEventListener('change', pokaz);
  inp.addEventListener('blur', pokaz);
  pokaz();
})();
</script>
</body>
</html>
