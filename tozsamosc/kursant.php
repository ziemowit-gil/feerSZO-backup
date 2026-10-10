<?php
/**
 * tozsamosc/kursant.php — logowanie kursanta w module Tożsamość (wygląd METRO UI, układ dwukolumnowy jak w iPKO).
 * Pierwsze logowanie (konto nigdy nie logowało się): login → kod SMS (tozsamosc/kursant_kod.php).
 * Kolejne logowania: login + hasło. Sesja kursanta jest tworzona jak dotychczas (student_login_user).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_student_otp.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';
require_once dirname(__DIR__) . '/includes/ti_sec_image.php';

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
        // Brak wybranego obrazka bezpieczeństwa: najpierw wybór, potem panel.
        ti_sec_image_migrate();
        $wybrany = db_one("SELECT sec_chosen FROM k30_ti_student_accounts WHERE id = ?", [(int)$acc['id']]);
        header('Location: ' . APP_URL . (empty($wybrany['sec_chosen'])
            ? '/karty30/ti/kursant/sec_choose.php'
            : '/karty30/ti/kursant/index.php')); exit;
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
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; }
  .metro-split { display: flex; flex-direction: column; min-height: 100vh; }
  @media (min-width: 768px) { .metro-split { flex-direction: row; } }
  .metro-side { background: #1d1d1d; color: #fff; padding: 2.5rem 2rem; flex: 1 1 50%; display: flex; flex-direction: column; justify-content: center; }
  .metro-side h2 { font-size: 1.6rem; font-weight: 700; margin: 0 0 .5rem; }
  .metro-side .lead-sub { color: #cbd5e1; font-size: .95rem; }
  .metro-side ul { padding-left: 1.1rem; margin: 1.25rem 0 0; font-size: .92rem; }
  .metro-side li { margin-bottom: .5rem; }
  .metro-main { flex: 1 1 50%; display: flex; align-items: center; justify-content: center; padding: 2rem 1.25rem; }
  .metro-card { background: #fff; border: 0; border-radius: 0; box-shadow: 0 2px 10px rgba(0,0,0,.08); padding: 2.5rem 2rem; width: 100%; max-width: 420px; }
  .metro-card .form-control-lg { font-size: 1.1rem; padding: .75rem 1rem; border-radius: 0; box-shadow: none; }
  .metro-btn { border-radius: 0; background: #1ba1e2; border-color: #1ba1e2; color: #fff; font-weight: 600; }
  .metro-btn:hover { background: #1790c9; border-color: #1790c9; color: #fff; }
  .metro-btn:focus-visible, .form-control:focus-visible, .metro-side a:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
  .metro-footer { text-align: center; font-size: .85rem; color: #4b5563; padding: 1rem; }
  .metro-footer a { color: #1d4ed8; text-decoration: none; margin: 0 .5rem; }
  .metro-side a { color: #7dd3fc; }
</style>
</head>
<body>
<div role="region" aria-label="Informacja o logowaniu" style="background:#1ba1e2;color:#fff;padding:.6rem 1rem;text-align:center;font-weight:600;font-size:.95rem">
  Logowanie centralne przez System Tożsamości — adresy i zapisane linki bez zmian.
  <a href="../karty30/ti/sso.php" style="color:#fff;text-decoration:underline;margin-left:.5rem">Czym jest SSO?</a>
</div>
<div class="metro-split">
  <aside class="metro-side" aria-labelledby="side-h">
    <div class="small fw-bold mb-2" style="letter-spacing:.06em">FEER · System Tożsamości</div>
    <h2 id="side-h">Panel kursanta</h2>
    <p class="lead-sub mb-0">Logowanie jest obsługiwane centralnie przez System Tożsamości. Adresy i zapisane linki się nie zmieniają.</p>
    <ul>
      <li><strong>Pierwsze logowanie:</strong> wpisujesz tylko login. Kod przychodzi SMS-em na Twój telefon.</li>
      <li><strong>Obrazek bezpieczeństwa:</strong> po wpisaniu loginu pojawia się Twój obrazek. Każdy ma opis tekstowy, który czyta czytnik ekranu.</li>
      <li><strong>Jeśli nie widzisz obrazka albo widzisz inny,</strong> nie wpisuj hasła.</li>
    </ul>
  </aside>

  <main class="metro-main" id="main">
    <div class="metro-card">
      <h1 class="h5 fw-bold mb-3">Zaloguj się do panelu kursanta</h1>

      <?php if ($blad !== ''): ?>
      <div class="alert alert-danger small py-2 rounded-0" role="alert"><?= htmlspecialchars($blad, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" action="" autocomplete="on">
        <div class="mb-3">
          <label for="kur-login" class="form-label fw-semibold">Login</label>
          <div class="d-flex align-items-start gap-3">
            <div class="flex-grow-1 min-w-0">
              <input type="text" class="form-control form-control-lg" id="kur-login" name="login"
                     value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username" autofocus>
            </div>
            <div class="align-items-center gap-2 d-none" id="kur-sec" style="max-width:11rem" aria-live="polite">
              <div id="kur-sec-img" style="width:56px;height:56px;flex-shrink:0" aria-hidden="true"></div>
              <div class="small" id="kur-sec-label"></div>
            </div>
          </div>
        </div>
        <div class="mb-4">
          <label for="kur-password" class="form-label fw-semibold">Hasło</label>
          <input type="password" class="form-control form-control-lg" id="kur-password" name="password" autocomplete="current-password">
          <div class="form-text">Przy pierwszym logowaniu hasła nie wpisujesz.</div>
        </div>
        <button type="submit" class="btn btn-lg metro-btn w-100">Dalej</button>
      </form>
    </div>
  </main>
</div>
<footer class="metro-footer" aria-label="Linki">
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
  // Obrazek pojawia się od razu podczas pisania (z krótką przerwą), nie dopiero po wyjściu z pola.
  var t = null;
  inp.addEventListener('input', function () { clearTimeout(t); t = setTimeout(pokaz, 300); });
  inp.addEventListener('change', pokaz);
  inp.addEventListener('blur', pokaz);
  pokaz();
})();
</script>
</body>
</html>
