<?php
/**
 * tozsamosc/kursant_haslo.php — zmiana hasła kursanta w module Tożsamość.
 * Po logowaniu kodem SMS (wymuszona zmiana) nie pytamy o stare hasło.
 * Reguły hasła: includes/ti_student_password.php (min. 12 znaków, wielka i mała litera, cyfra, znak specjalny).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_messages.php';
require_once dirname(__DIR__) . '/includes/ti_student_password.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';

karty30_migrate();
$student = student_current();
if (!$student) { header('Location: ' . APP_URL . '/tozsamosc/kursant.php'); exit; }

$blad = '';
$_mcp = db_one("SELECT must_change_password, login FROM k30_ti_student_accounts WHERE id = ?", [(int)$student['id']]);
$wymuszona = !empty($_mcp['must_change_password']);
$login_konta = (string)($_mcp['login'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $cnf = (string)($_POST['confirm'] ?? '');
    $acc = db_one("SELECT password_hash, ms_user_id, ms_upn FROM k30_ti_student_accounts WHERE id = ?", [(int)$student['id']]);
    if (!$acc || (!$wymuszona && !password_verify($cur, (string)$acc['password_hash']))) {
        $blad = 'Aktualne hasło nie jest poprawne. Spróbuj jeszcze raz.';
    } elseif (($blad_reguly = ti_student_password_error($new, $login_konta)) !== null) {
        $blad = $blad_reguly;
    } elseif ($new !== $cnf) {
        $blad = 'Oba pola z nowym hasłem muszą być takie same.';
    } elseif (!$wymuszona && $new === $cur) {
        $blad = 'Nowe hasło musi być inne niż dotychczasowe.';
    } else {
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash = ?, must_change_password = 0, updated_at = datetime('now') WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), (int)$student['id']]);
        $log_note = 'Zmieniono hasło (Tożsamość).';
        // Jedno hasło do wszystkiego: jeśli kursant ma konto Microsoft (EDU), ustaw też tam.
        if (!empty($acc['ms_user_id'])) {
            try {
                m365_edu()->set_password($acc['ms_user_id'], $new, false);
                $log_note .= ' Zaktualizowano też hasło konta Microsoft (' . $acc['ms_upn'] . ').';
            } catch (\Throwable $e) {
                $log_note .= ' UWAGA: nie udało się zaktualizować hasła konta Microsoft: ' . $e->getMessage();
            }
        }
        ti_account_log((int)$student['id'], 'password_changed', $log_note);
        flash_set('success', 'Hasło zostało zmienione.');
        // Po wymuszonej zmianie (pierwsze logowanie) zachęć do klucza sprzętowego.
        header('Location: ' . APP_URL . ($wymuszona ? '/tozsamosc/kursant_klucz.php?nowy=1' : '/tozsamosc/kursant_haslo.php?ok=1')); exit;
    }
}
$ok = isset($_GET['ok']);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zmiana hasła — System Tożsamości</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; }
  .metro-bar { background: #1d1d1d; color: #fff; padding: .75rem 1rem; font-weight: 600; }
  .metro-card { background: #fff; padding: 2rem; max-width: 460px; margin: 2rem auto; }
  .metro-btn { border-radius: 0; background: #0f6fa8; border-color: #0f6fa8; color: #fff; font-weight: 600; }
  .metro-btn:hover { background: #0c5b8a; border-color: #0c5b8a; color: #fff; }
  .metro-card .form-control { border-radius: 0; box-shadow: none; }
  .rules { list-style: none; padding: 0; margin: .5rem 0 0; font-size: .9rem; }
  .rules li { padding: .15rem 0; color: #6b7280; }
  .rules li::before { content: "○ "; }
  .rules li.ok { color: #15803d; }
  .rules li.ok::before { content: "✓ "; font-weight: 700; }
  .pass-wrap { position: relative; }
  .pass-wrap .form-control { padding-right: 4.5rem; }
  .pass-toggle { position: absolute; right: .4rem; top: 50%; transform: translateY(-50%); background: none; border: 0; color: #1d4ed8; font-size: .85rem; padding: .25rem .5rem; }
  :focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<header class="metro-bar">System Tożsamości · Zmiana hasła</header>
<main class="metro-card">
  <h1 class="h5 fw-bold mb-2">Zmień hasło</h1>
  <p class="small text-body-secondary mb-3">
    <?= $wymuszona ? 'To Twoje pierwsze logowanie. Ustaw własne hasło, którym będziesz logować się od teraz.' : 'Ustaw nowe hasło do panelu kursanta.' ?>
  </p>

  <?php if ($ok): ?>
  <div class="alert alert-success small py-2 rounded-0" role="status">Hasło zostało zmienione. Dziękujemy.</div>
  <?php endif; ?>
  <?php if ($blad !== ''): ?>
  <div class="alert alert-danger small py-2 rounded-0" role="alert"><?= h($blad) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off" id="hs-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <?php if (!$wymuszona): ?>
    <div class="mb-3">
      <label for="hs-cur" class="form-label fw-semibold">Aktualne hasło</label>
      <div class="pass-wrap">
        <input type="password" class="form-control form-control-lg" id="hs-cur" name="current" required autocomplete="current-password">
        <button type="button" class="pass-toggle" data-target="hs-cur" aria-label="Pokaż hasło" aria-pressed="false">Pokaż</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="mb-3">
      <label for="hs-new" class="form-label fw-semibold">Nowe hasło</label>
      <div class="pass-wrap">
        <input type="password" class="form-control form-control-lg" id="hs-new" name="new" required autocomplete="new-password" aria-describedby="hs-rules">
        <button type="button" class="pass-toggle" data-target="hs-new" aria-label="Pokaż hasło" aria-pressed="false">Pokaż</button>
      </div>
      <ul class="rules" id="hs-rules" aria-live="polite">
        <li data-rule="len">Co najmniej 12 znaków</li>
        <li data-rule="lower">Mała litera</li>
        <li data-rule="upper">Wielka litera</li>
        <li data-rule="digit">Cyfra</li>
        <li data-rule="special">Znak specjalny, np. ! ? # $ %</li>
        <li data-rule="login">Bez Twojego loginu</li>
      </ul>
    </div>
    <div class="mb-4">
      <label for="hs-cnf" class="form-label fw-semibold">Powtórz nowe hasło</label>
      <div class="pass-wrap">
        <input type="password" class="form-control form-control-lg" id="hs-cnf" name="confirm" required autocomplete="new-password">
        <button type="button" class="pass-toggle" data-target="hs-cnf" aria-label="Pokaż hasło" aria-pressed="false">Pokaż</button>
      </div>
      <div class="small mt-1 d-none" id="hs-cnf-info" aria-live="polite"></div>
    </div>
    <button type="submit" class="btn btn-lg metro-btn w-100">Zapisz hasło</button>
  </form>
  <p class="small mt-3 mb-1"><a href="<?= h(APP_URL) ?>/tozsamosc/kursant_klucz.php">Klucze sprzętowe i passkey</a></p>
  <p class="small mt-1 mb-1"><a href="<?= h(APP_URL) ?>/tozsamosc/kursant_ustawienia.php">Menu panelu: zakładki czy rozwijane</a></p>
  <p class="small mt-2 mb-0"><a href="<?= h(APP_URL) ?>/karty30/ti/kursant/index.php">Wróć do panelu kursanta</a></p>
</main>

<script>
(function () {
  var login = <?= json_encode($login_konta, JSON_UNESCAPED_UNICODE) ?>.toLowerCase();
  var nowe = document.getElementById('hs-new'), cnf = document.getElementById('hs-cnf');
  var info = document.getElementById('hs-cnf-info');
  var lista = document.getElementById('hs-rules');
  function sprawdz(p) {
    var l = p.toLowerCase();
    return {
      len: [...p].length >= 12,
      lower: /[a-ząćęłńóśźż]/.test(p),
      upper: /[A-ZĄĆĘŁŃÓŚŹŻ]/.test(p),
      digit: /[0-9]/.test(p),
      special: /[^A-Za-z0-9ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/.test(p),
      login: login === '' || l.indexOf(login) === -1
    };
  }
  function odswiez() {
    var wyniki = sprawdz(nowe.value);
    lista.querySelectorAll('li').forEach(function (li) {
      li.classList.toggle('ok', !!wyniki[li.getAttribute('data-rule')]);
    });
    if (cnf.value === '') { info.classList.add('d-none'); return; }
    info.classList.remove('d-none');
    if (cnf.value === nowe.value) { info.textContent = 'Hasła są takie same.'; info.style.color = '#15803d'; }
    else { info.textContent = 'Hasła jeszcze się nie zgadzają.'; info.style.color = '#6b7280'; }
  }
  nowe.addEventListener('input', odswiez);
  cnf.addEventListener('input', odswiez);
  document.querySelectorAll('.pass-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var pole = document.getElementById(b.getAttribute('data-target'));
      var pokaz = pole.type === 'password';
      pole.type = pokaz ? 'text' : 'password';
      b.textContent = pokaz ? 'Ukryj' : 'Pokaż';
      b.setAttribute('aria-pressed', pokaz ? 'true' : 'false');
      b.setAttribute('aria-label', pokaz ? 'Ukryj hasło' : 'Pokaż hasło');
    });
  });
  document.getElementById('hs-form').addEventListener('submit', function (e) {
    var wyniki = sprawdz(nowe.value);
    var brak = Object.keys(wyniki).some(function (k) { return !wyniki[k]; });
    if (brak || nowe.value !== cnf.value) {
      e.preventDefault();
      (brak ? nowe : cnf).focus();
    }
  });
})();
</script>
</body>
</html>
