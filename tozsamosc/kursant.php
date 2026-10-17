<?php
/**
 * tozsamosc/kursant.php — logowanie kursanta w module Tożsamość (wygląd portalu FEER: modules/ti/view/portal, dwie kolumny).
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
require_once dirname(__DIR__) . '/includes/ti_student_reauth.php';

karty30_migrate();
ti_sec_image_migrate();
ti_reauth_migrate();
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
    } elseif ($acc && ti_student_needs_reauth($acc)) {
        // Pierwsze logowanie albo brak ustawionego obrazka: ponowna autoryzacja jak przy pierwszym logowaniu (kod SMS, bez starego hasła).
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
        header('Location: ' . APP_URL . (empty($wybrany['sec_chosen']) && ti_sec_image_required((int)$acc['id'])
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
<title>Logowanie kursanta — Panel zajęć — <?= htmlspecialchars((string)(org_setting('org_name') ?: 'FEER'), ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__) . '/modules/ti/view/portal/_style.php'; ?>
<style>
  .kur-sec { gap: .6rem; align-items: center; background: var(--pp-bg); border-radius: .7rem; padding: .5rem .7rem; margin-top: .6rem; }
  .kur-note { background: var(--pp-bg); border-radius: .7rem; padding: .7rem .9rem; font-size: .88rem; color: var(--pp-ink); margin-top: .7rem; }
  .pp details { border: 1px solid var(--pp-line); border-radius: var(--pp-radius); padding: .9rem 1.1rem; margin-top: 1.2rem; }
  .pp details summary { font-weight: 700; cursor: pointer; color: var(--pp-ink); }
  .pp details h2 { font-size: 1rem; font-weight: 700; margin: 1rem 0 .4rem; }
  .pp-btn-key { display: inline-flex; align-items: center; gap: .5rem; min-height: 44px; padding: .45rem 1.2rem; border: 2px solid var(--pp-blue); border-radius: .7rem;
                background: #fff; color: var(--pp-blue); font: inherit; font-weight: 700; cursor: pointer; }
  .pp-btn-key:disabled { opacity: .5; cursor: not-allowed; }
</style>
</head>
<body class="pp">
<?php
$ppTitle = 'Panel Kursanta SSO'; $ppOrg = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER')); $ppCrumb = 'Logowanie kursanta';
$ppLogo = ''; $ppLoginUrl = '/login.php'; $ppAccount = null;
include dirname(__DIR__) . '/modules/ti/view/portal/_header.php';
?>
<main id="pp-main" class="pp-wrap">
  <h1 class="pp-h1">Zaloguj się do panelu kursanta</h1>
  <?php if ($blad !== ''): ?><div class="pp-error" role="alert"><?= htmlspecialchars($blad, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <div class="pp-cols">
    <div>
      <form method="post" action="" autocomplete="on">
        <div class="pp-field">
          <label for="kur-login">Login</label>
          <input type="text" class="pp-input" id="kur-login" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username" autofocus aria-describedby="kur-login-h">
          <p id="kur-login-h" class="pp-note" style="margin:.35rem 0 0">np. <span class="font-monospace">j.kowalski</span> albo <span class="font-monospace">k1234</span> (konsultacje), lub własny alias.</p>
          <div class="kur-sec d-none" id="kur-sec" aria-live="polite">
            <div id="kur-sec-img" style="width:56px;height:56px;flex-shrink:0" aria-hidden="true"></div>
            <div><div class="pp-note" style="margin:0">Twój obrazek bezpieczeństwa:</div><strong id="kur-sec-label"></strong></div>
          </div>
        </div>
        <div class="pp-field">
          <label for="kur-password">Hasło</label>
          <input type="password" class="pp-input" id="kur-password" name="password" autocomplete="current-password" aria-describedby="kur-pass-h">
          <p id="kur-pass-h" class="pp-note" style="margin:.35rem 0 0">Przy pierwszym logowaniu hasła nie wpisujesz — kod przyjdzie SMS-em.</p>
          <div class="kur-note" role="note">Konta założone przed 10.10.2026 traktujemy jak nowe: przy tym logowaniu potwierdzisz się kodem SMS i ustawisz nowe hasło. SMS-y wysyła Sendly.</div>
        </div>
        <button type="submit" class="pp-submit">Dalej</button>
      </form>
      <div style="margin-top:1.4rem">
        <button type="button" class="pp-btn-key" id="kur-klucz"><i class="bi bi-usb-drive" aria-hidden="true"></i> Zaloguj kluczem sprzętowym</button>
        <p class="pp-note" style="margin:.4rem 0 0">Działa po pierwszym logowaniu, gdy masz zarejestrowany klucz.</p>
        <div class="pp-error d-none" id="kur-klucz-blad" role="alert" style="margin-top:.6rem"></div>
      </div>
    </div>

    <aside class="pp-note" aria-labelledby="kur-side-h">
      <p id="kur-side-h" style="margin-top:0"><strong style="color:var(--pp-ink)">Obrazek bezpieczeństwa.</strong> Po wpisaniu loginu pojawi się Twój obrazek — każdy ma opis tekstowy dla czytnika ekranu. <strong>Jeśli go nie widzisz albo widzisz inny, nie wpisuj hasła.</strong></p>
      <details id="jak-logowac">
        <summary>Jak się zalogować? Jaki mam login?</summary>
        <h2>Twój login</h2>
        <ul>
          <li><strong>Kursant szkoleń</strong>: pierwsza litera imienia, kropka i nazwisko bez polskich znaków, np. <span class="font-monospace">j.kowalski</span> (przy powtórzeniu z cyfrą: <span class="font-monospace">j.kowalski2</span>), albo własny alias.</li>
          <li><strong>Osoba korzystająca z konsultacji</strong>: „k” i cztery cyfry, np. <span class="font-monospace">k1234</span> — przychodzi SMS-em po umówieniu pierwszej konsultacji.</li>
          <li>Login i pierwsze hasło przychodzą SMS-em albo dostajesz je od nas na wydruku.</li>
        </ul>
        <h2>Pierwsze logowanie</h2>
        <ol>
          <li>Wpisz login i wybierz „Dalej”.</li>
          <li>Sprawdź obrazek bezpieczeństwa.</li>
          <li>Potwierdź się kodem z SMS-a (albo hasłem jednorazowym).</li>
          <li>Ustaw własne hasło.</li>
        </ol>
        <h2>Nie pamiętasz hasła albo loginu?</h2>
        <p>Wpisz login — dostaniesz kod SMS na numer zapisany w koncie. Jeśli nie pamiętasz loginu albo zmieniłeś numer, skontaktuj się z placówką.</p>
      </details>
      <div style="border-top:1px solid var(--pp-line);margin-top:1.4rem;padding-top:1.1rem">
        <strong style="color:var(--pp-ink)">Inne osoby logują się gdzie indziej:</strong>
        <ul style="padding-left:1.1rem;margin:.4rem 0 0">
          <li><a href="/login.php?tab=rodzic">Rodzic lub opiekun</a></li>
          <li><a href="/login.php?tab=up">Osoba upoważniona</a></li>
          <li><a href="/login.php?tab=kandydat">Kandydat (zapisy)</a></li>
          <li><a href="/login.php?tab=dydaktyk">Prowadzący i kadra</a></li>
        </ul>
      </div>
    </aside>
  </div>
</main>
<?php
$ppFootLinks = ['Panel zajęć' => '/login.php', 'Czym jest SSO' => '/karty30/ti/sso.php', 'Konto kandydata' => '/nabor/logowanie', 'Strona Fundacji FEER' => 'https://feer.org.pl'];
include dirname(__DIR__) . '/modules/ti/view/portal/_footer.php';
?>
<script>
(function () {
  // Logowanie kluczem (WebAuthn): tylko dla kont z zarejestrowanym kluczem.
  var kbtn = document.getElementById('kur-klucz'), kblad = document.getElementById('kur-klucz-blad');
  function b64uToBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var bin = atob(s), buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  if (kbtn) {
    if (!window.PublicKeyCredential || !navigator.credentials) { kbtn.disabled = true; }
    kbtn.addEventListener('click', function () {
      var login = (document.getElementById('kur-login').value || '').trim();
      kblad.classList.add('d-none');
      if (!login) { kblad.textContent = 'Najpierw wpisz login.'; kblad.classList.remove('d-none'); return; }
      var api = '<?= APP_URL ?>/tozsamosc/kursant_klucz_login.php?api=';
      fetch(api + 'begin&login=' + encodeURIComponent(login), { credentials: 'omit', cache: 'no-store' })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Błąd'); return j; }); })
        .then(function (opts) {
          opts.challenge = b64uToBuf(opts.challenge);
          opts.allowCredentials = (opts.allowCredentials || []).map(function (c) { return { id: b64uToBuf(c.id), type: c.type, transports: c.transports }; });
          return navigator.credentials.get({ publicKey: opts });
        })
        .then(function (cred) {
          var data = {
            id: cred.id,
            rawId: bufToB64u(cred.rawId),
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            authenticatorData: bufToB64u(cred.response.authenticatorData),
            signature: bufToB64u(cred.response.signature)
          };
          var fd = new FormData();
          fd.append('data', JSON.stringify(data));
          return fetch(api + 'complete', { method: 'POST', body: fd, credentials: 'omit' })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Błąd'); return j; }); });
        })
        .then(function (j) { window.location = j.redirect; })
        .catch(function (err) {
          kblad.textContent = (err && err.message) ? err.message : 'Nie udało się zalogować kluczem.';
          kblad.classList.remove('d-none');
        });
    });
  }
  var inp = document.getElementById('kur-login'), box = document.getElementById('kur-sec');
  var img = document.getElementById('kur-sec-img'), lbl = document.getElementById('kur-sec-label');
  if (!inp || !box) return;
  function pokaz() {
    var v = inp.value.trim();
    if (!v) { box.classList.add('d-none'); return; }
    fetch('<?= APP_URL ?>/karty30/ti/kursant/sec_image.php?login=' + encodeURIComponent(v), { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.none) { box.classList.add('d-none'); box.classList.remove('d-flex'); return; }   // konto bez obrazka
        img.innerHTML = j.svg; lbl.textContent = j.label; box.classList.remove('d-none'); box.classList.add('d-flex'); })
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
