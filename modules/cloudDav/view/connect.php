<?php
/**
 * modules/cloudDav/view/connect.php — strona „Kalendarz i kontakty z chmury” (GET /kalendarz-kontakty, CloudDavController::index).
 * Samodzielna strona w szacie ekranu „Załóż konto w chmurze” (modules/sso_logto/view/loginPrompt.php): płasko, ciemny pasek z linią cyan.
 * Stany: brak chmury albo konta → odsyłacz do Tożsamości; wybór urządzenia; oczekiwanie na „Udziel dostępu” w chmurze (JS odpytuje
 * /sprawdz); gotowe dane do wklejenia (dPoczta, profil Apple, DAVx5, Thunderbird) i lista kalendarzy i książek adresowych.
 * Zmienne: $userId, $email, $name, $cloudUid, $cloudUrl, $org, $purposes, $pending, $result, $purpose, $flash.
 */
$base      = rtrim(APP_URL, '/') . '/kalendarz-kontakty';
$cloudHost = (string)parse_url($cloudUrl, PHP_URL_HOST);
$security  = $cloudUrl !== '' ? $cloudUrl . '/settings/user/security' : '';
$helpUrl   = rtrim(APP_URL, '/') . '/uslugi-it/uslugi/chmura-plikow/kontakty-i-kalendarz';
$fType     = $flash['type'] ?? '';
$fCls      = ['success' => 'ok', 'danger' => 'error', 'error' => 'error', 'warning' => 'warn'][$fType] ?? 'info';
$p         = $purposes[$purpose] ?? null;
$left      = $result ? max(1, (int)ceil(((int)$result['at'] + CloudDavController::RESULT_TTL - time()) / 60)) : 0;
$copyId    = 0;
/** Pole tylko do odczytu z przyciskiem „Kopiuj” (etykieta połączona z polem, komunikat w regionie aria-live). */
$field = function (string $label, string $value, string $help = '') use (&$copyId): string {
    $id = 'cd' . (++$copyId);
    return '<div class="fld"><label for="' . $id . '">' . h($label) . '</label><div class="cp">'
         . '<input type="text" id="' . $id . '" value="' . h($value) . '" readonly spellcheck="false" autocomplete="off"' . ($help !== '' ? ' aria-describedby="' . $id . 'h"' : '') . '>'
         . '<button type="button" class="btn ghost js-copy" data-copy="' . $id . '">Kopiuj<span class="sr"> ' . h(mb_strtolower($label)) . '</span></button></div>'
         . ($help !== '' ? '<div class="help" id="' . $id . 'h">' . $help . '</div>' : '') . '</div>';
};
$title = $result ? 'Gotowe: dane do połączenia' : ($pending ? 'Potwierdź dostęp w chmurze' : 'Kalendarz i kontakty z chmury');
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> · <?= h($org) ?></title>
<style>
:root { --cyan:#1ba1e2; --dark:#1d1d1d; --ok:#008a00; --warn:#c25100; --err:#a20025; --line:#d9d9d9; --mute:#595959; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif; color: #1d1d1d; background: #f4f4f4; font-size: 15px; line-height: 1.5; }
a { color: #0f6fa8; }
.bar { background: var(--dark); color: #fff; padding: 0 20px; min-height: 52px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 3px solid var(--cyan); flex-wrap: wrap; }
.bar b { font-weight: 600; letter-spacing: .02em; }
.bar a { color: #fff; text-decoration: underline; font-size: 14px; }
.wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
.main { background: #fff; border: 1px solid var(--line); padding: 28px; }
@media (max-width: 520px) { .main { padding: 20px 16px; } }
.kicker { font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--mute); margin: 0 0 4px; }
h1 { font-size: 26px; font-weight: 300; margin: 0 0 6px; line-height: 1.25; }
h2 { font-size: 18px; font-weight: 600; margin: 28px 0 8px; }
h3 { font-size: 16px; font-weight: 600; margin: 20px 0 6px; }
.lead { color: var(--mute); margin: 0 0 18px; }
.msg { padding: 10px 14px; margin: 0 0 18px; border-left: 4px solid var(--cyan); background: #f7f7f7; }
.msg.ok { border-color: var(--ok); } .msg.warn { border-color: var(--warn); } .msg.error { border-color: var(--err); }
.imp { padding: 12px 14px; margin: 16px 0; border-left: 4px solid var(--warn); background: #fff8f2; }
fieldset { border: 0; padding: 0; margin: 0; }
legend { font-weight: 600; font-size: 16px; margin-bottom: 8px; padding: 0; }
.opts { display: grid; gap: 8px; }
.opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border: 2px solid #c8c8c8; cursor: pointer; }
.opt:hover { border-color: #8a8a8a; }
.opt input { margin-top: 4px; width: 18px; height: 18px; flex-shrink: 0; }
.opt:has(input:checked) { border-color: #0f6fa8; background: #f2f8fc; }
.opt b { display: block; }
.opt span span { color: var(--mute); font-size: 14px; }
.fld { margin: 12px 0; }
.fld label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 4px; }
.cp { display: flex; gap: 8px; flex-wrap: wrap; }
.cp input { flex: 1 1 260px; min-width: 0; padding: 9px 10px; border: 2px solid #8a8a8a; font: 14px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #f7f7f7; border-radius: 0; color: #1d1d1d; }
.help { font-size: 13px; color: var(--mute); margin-top: 4px; }
input:focus, button:focus-visible, a:focus-visible, summary:focus-visible, .opt:focus-within { outline: 3px solid var(--dark); outline-offset: 2px; }
.btn { display: inline-block; padding: 9px 18px; border: 2px solid #0f6fa8; background: #0f6fa8; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; border-radius: 0; }
.btn:hover { background: #0b5683; border-color: #0b5683; }
.btn.ghost { background: #fff; color: var(--dark); border-color: #8a8a8a; }
.btn.ghost:hover { background: #f0f0f0; }
.row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 20px; }
ol.steps { padding-left: 22px; } ol.steps li { margin: 6px 0; }
.list { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 6px; }
.list li { border: 1px solid #e6e6e6; padding: 8px 12px; }
.dot { display: inline-block; width: 12px; height: 12px; border: 1px solid #8a8a8a; margin-right: 6px; vertical-align: -1px; }
details { border: 1px solid var(--line); padding: 10px 14px; margin-top: 16px; }
summary { cursor: pointer; font-weight: 600; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
code { font: 13px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #f2f2f2; padding: 1px 4px; word-break: break-all; }
</style>
</head>
<body>
<a class="sr" href="#tresc">Przejdź do treści</a>
<header class="bar">
  <b><?= h($org) ?> · Chmura plików</b>
  <a href="<?= h(rtrim(APP_URL, '/') . '/tozsamosc/index.php#sso') ?>">Wróć do Systemu Tożsamości</a>
</header>
<div class="wrap">
  <main class="main" id="tresc">
    <p class="kicker"><?= $name !== '' ? 'Cześć, ' . h($name) : 'Kalendarz i kontakty' ?></p>
    <h1><?= h($title) ?></h1>
    <div id="live" class="sr" aria-live="polite"></div>
    <?php if ($flash): ?>
      <div class="msg <?= $fCls ?>" role="<?= $fCls === 'error' ? 'alert' : 'status' ?>"><?= h((string)$flash['msg']) ?></div>
    <?php endif; ?>

<?php if ($cloudUrl === ''): ?>
    <p class="lead">Chmura plików nie jest jeszcze skonfigurowana. Gdy administrator ją włączy, połączysz tu kalendarz i kontakty.</p>

<?php elseif ($cloudUid === ''): ?>
    <p class="lead">Kalendarz i kontakty są w chmurze plików <?= h($cloudHost) ?>, a Ty nie masz jeszcze w niej konta.</p>
    <p>Konto założysz w Systemie Tożsamości, w części <a href="<?= h(rtrim(APP_URL, '/') . '/tozsamosc/index.php#sso') ?>">Logowanie jednokrotne</a>. Potem wróć na tę stronę.</p>

<?php elseif ($result): ?>
    <p class="lead">Chmura utworzyła hasło aplikacji dla urządzenia „<?= h($p['label'] ?? 'urządzenie') ?>”. Przepisz poniższe dane tylko tam. Na inne urządzenie połącz się osobno, żeby każde miało własne hasło.</p>
    <div class="imp" role="note">Hasło widać na tej stronie jeszcze przez około <?= (int)$left ?> min. SZO go nie zapisuje. Działa, dopóki go nie usuniesz w chmurze<?php if ($security !== ''): ?>, w <a href="<?= h($security) ?>" target="_blank" rel="noopener">ustawieniach osobistych, w części Bezpieczeństwo<span class="sr"> (otwiera się w nowej karcie)</span></a><?php endif; ?>.</div>

  <?php if ($purpose === 'dpoczta'): ?>
    <h2>Kalendarz z chmury w poczcie dPoczta</h2>
    <ol class="steps">
      <li>Zaloguj się na <a href="https://dpoczta.pl" target="_blank" rel="noopener">dpoczta.pl<span class="sr"> (otwiera się w nowej karcie)</span></a> i otwórz Kalendarz ikoną kalendarza na prawym pasku.</li>
      <li>Kliknij ikonę z trzema kreskami obok nagłówka „Kalendarze” i wybierz „Dodaj kalendarz CalDAV”.</li>
      <li>Skopiuj stąd adres, nazwę użytkownika i hasło do pól o tych samych nazwach.</li>
      <li>Kliknij „Znajdź kalendarze”, zaznacz kalendarze, które chcesz widzieć, i kliknij „Zapisz”.</li>
    </ol>
    <?= $field('Adres URL CalDAV', (string)$result['calHome'], 'Jeśli „Znajdź kalendarze” nic nie znajdzie, wklej zamiast tego adres jednego kalendarza z listy niżej.') ?>
    <?= $field('Nazwa użytkownika', (string)$result['login']) ?>
    <?= $field('Hasło', (string)$result['pass']) ?>
    <p class="help">Kontaktów z chmury poczta dPoczta nie umie pobierać. Kontakty podłączysz w telefonie albo w programie na komputerze: kliknij „Połącz kolejne urządzenie”.</p>

  <?php elseif ($purpose === 'apple'): ?>
    <h2>iPhone, iPad albo Mac: profil z kalendarzem i kontaktami</h2>
    <p>Profil sam doda w urządzeniu konto kalendarza i konto kontaktów z chmury. Na iPhonie i iPadzie pobierz go w przeglądarce Safari na tym urządzeniu. Jeśli jesteś teraz przy komputerze, zaloguj się do SZO na telefonie i połącz go stamtąd.</p>
    <div class="row"><a class="btn" href="<?= h($base . '/profil') ?>">Pobierz profil</a></div>
    <h3>iPhone i iPad</h3>
    <ol class="steps">
      <li>Po pobraniu otwórz Ustawienia. Na górze pojawi się „Pobrano profil”.</li>
      <li>Stuknij go, potem „Zainstaluj”, podaj kod telefonu i jeszcze raz „Zainstaluj”. Telefon ostrzeże, że profil nie jest podpisany. To normalne przy takich profilach.</li>
      <li>Kalendarz z chmury zobaczysz w aplikacji Kalendarz, a kontakty w aplikacji Kontakty.</li>
    </ol>
    <h3>Mac</h3>
    <ol class="steps">
      <li>Otwórz pobrany plik. Potem otwórz Ustawienia systemowe, wyszukaj „Profile” i zainstaluj profil z listy.</li>
      <li>Kalendarz i kontakty pojawią się w aplikacjach Kalendarz i Kontakty.</li>
    </ol>
    <details>
      <summary>Wolę dodać konto ręcznie</summary>
      <p>W Ustawieniach wybierz Kalendarz, potem Konta, „Dodaj konto”, „Inne” i „Dodaj konto CalDAV”. W polu serwera wpisz <code><?= h($cloudHost) ?></code> i podaj dane poniżej. Kontakty dodasz tak samo, w części Kontakty, jako konto CardDAV.</p>
      <?= $field('Nazwa użytkownika', (string)$result['login']) ?>
      <?= $field('Hasło', (string)$result['pass']) ?>
      <?= $field('Adres konta, gdy telefon nie znajdzie serwera', (string)$result['principal']) ?>
    </details>

  <?php elseif ($purpose === 'android'): ?>
    <h2>Android: aplikacja DAVx5</h2>
    <ol class="steps">
      <li>Zainstaluj DAVx5 z <a href="https://f-droid.org/packages/at.bitfire.davdroid/" target="_blank" rel="noopener">F-Droid<span class="sr"> (otwiera się w nowej karcie)</span></a>, gdzie jest bezpłatna, albo ze <a href="https://play.google.com/store/apps/details?id=at.bitfire.davdroid" target="_blank" rel="noopener">Sklepu Play<span class="sr"> (otwiera się w nowej karcie)</span></a>, gdzie jest płatna.</li>
      <li>W DAVx5 stuknij przycisk dodawania konta i wybierz logowanie adresem URL i nazwą użytkownika.</li>
      <li>Wpisz adres, nazwę użytkownika i hasło z tej strony i zaloguj się.</li>
      <li>Utwórz konto, a potem zaznacz książki adresowe i kalendarze, które chcesz mieć w telefonie. Pojawią się w aplikacjach Kontakty i Kalendarz.</li>
    </ol>
    <?= $field('Adres', (string)$result['davRoot']) ?>
    <?= $field('Nazwa użytkownika', (string)$result['login']) ?>
    <?= $field('Hasło', (string)$result['pass']) ?>

  <?php else: ?>
    <h2>Thunderbird albo inny program</h2>
    <ol class="steps">
      <li>Kalendarz: w Thunderbirdzie dodaj nowy kalendarz, wybierz kalendarz w sieci i wpisz nazwę użytkownika i adres z tej strony. Program sam znajdzie Twoje kalendarze.</li>
      <li>Kontakty: w Książce adresowej dodaj książkę adresową CardDAV z tą samą nazwą użytkownika i tym samym adresem.</li>
      <li>Gdy program zapyta o hasło, wklej hasło z tej strony.</li>
    </ol>
    <?= $field('Adres', (string)$result['davRoot']) ?>
    <?= $field('Nazwa użytkownika', (string)$result['login']) ?>
    <?= $field('Hasło', (string)$result['pass']) ?>
    <p class="help">Outlook na Windows sam nie łączy się z takim kalendarzem. Potrzebny jest do niego dodatek. Jeśli go potrzebujesz, napisz do Helpdesku IT.</p>
  <?php endif; ?>

    <details<?= $purpose === 'dpoczta' ? ' open' : '' ?>>
      <summary>Adresy Twoich kalendarzy i książek adresowych</summary>
      <?php if (!empty($result['note'])): ?><p class="help"><?= h((string)$result['note']) ?></p><?php endif; ?>
      <h3>Kalendarze</h3>
      <?php if ($result['cal']): ?>
        <ul class="list">
        <?php foreach ($result['cal'] as $c): ?>
          <li><?php if ($c['color'] !== ''): ?><span class="dot" style="background:<?= h($c['color']) ?>" aria-hidden="true"></span><?php endif; ?><b><?= h($c['name']) ?></b><?= $c['shared'] ? ' (udostępniony Ci)' : '' ?><?= !$c['events'] ? ' (zadania, bez wydarzeń)' : '' ?>
            <?= $field('Adres kalendarza ' . $c['name'], $c['url']) ?></li>
        <?php endforeach; ?>
        </ul>
      <?php else: ?><p>Nie znaleźliśmy kalendarzy. Otwórz Kalendarz w chmurze, a chmura utworzy pierwszy.</p><?php endif; ?>
      <h3>Książki adresowe</h3>
      <?php if ($result['ab']): ?>
        <ul class="list">
        <?php foreach ($result['ab'] as $a): ?>
          <li><b><?= h($a['name']) ?></b><?= $field('Adres książki ' . $a['name'], $a['url']) ?></li>
        <?php endforeach; ?>
        </ul>
      <?php else: ?><p>Nie znaleźliśmy książek adresowych. Otwórz Kontakty w chmurze, a chmura utworzy pierwszą.</p><?php endif; ?>
    </details>

    <div class="row">
      <form method="post" action="<?= h($base . '/zakoncz') ?>"><?= Router::csrfField() ?><input type="hidden" name="next" value="again"><button type="submit" class="btn ghost">Połącz kolejne urządzenie</button></form>
      <form method="post" action="<?= h($base . '/zakoncz') ?>"><?= Router::csrfField() ?><button type="submit" class="btn">Zakończ</button></form>
    </div>
    <p class="help">„Zakończ” usuwa hasło z tej strony. Instrukcja z opisem krok po kroku: <a href="<?= h($helpUrl) ?>">Kalendarz i kontakty z chmury</a>.</p>

<?php elseif ($pending): ?>
    <p class="lead">W nowej karcie otworzyła się strona chmury. Zaloguj się tam, jeśli trzeba, i kliknij „Udziel dostępu”. Potem wróć do tej karty. Sama zauważy, że gotowe.</p>
    <p>Karta się nie otworzyła? <a href="<?= h((string)$pending['login']) ?>" target="_blank" rel="noopener" id="cdLogin">Otwórz stronę chmury<span class="sr"> (otwiera się w nowej karcie)</span></a>.</p>
    <p id="cdWait" role="status">Czekam na potwierdzenie w chmurze.</p>
    <div class="row">
      <form method="post" action="<?= h($base . '/sprawdz') ?>"><?= Router::csrfField() ?><button type="submit" class="btn">Sprawdź teraz</button></form>
      <form method="post" action="<?= h($base . '/zakoncz') ?>"><?= Router::csrfField() ?><input type="hidden" name="next" value="again"><button type="submit" class="btn ghost">Anuluj</button></form>
    </div>
    <p class="help">Na potwierdzenie masz 20 minut. Hasło zobaczysz tylko na tej stronie.</p>
    <script>
    (function () {
      var url = <?= json_encode($base . '/sprawdz') ?>, csrf = <?= json_encode(Router::csrfToken()) ?>, out = document.getElementById('cdWait'), tries = 0;
      function tick() {
        tries++;
        var fd = new FormData(); fd.append('_csrf', csrf);
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j.state === 'done' || j.state === 'none') { location.reload(); return; }
            if (j.state === 'expired' || j.state === 'other') { out.setAttribute('role', 'alert'); out.textContent = j.message; return; }
            if (j.state === 'error') out.textContent = j.message;
            if (tries < 400) setTimeout(tick, 3000);
          })
          .catch(function () { if (tries < 400) setTimeout(tick, 5000); });
      }
      setTimeout(tick, 3000);
    })();
    </script>

<?php else: ?>
    <p class="lead">Kalendarz i kontakty z chmury plików <?= h($cloudHost) ?> możesz mieć w poczcie dPoczta, w telefonie i w programie na komputerze. Zmiana zrobiona w jednym miejscu pojawi się w pozostałych.</p>
    <p>Każde urządzenie dostaje osobne hasło aplikacji. Nie wpisujesz nigdzie hasła do logowania jednokrotnego, a hasło jednego urządzenia możesz w każdej chwili usunąć, nie ruszając pozostałych.</p>
    <form method="post" action="<?= h($base . '/polacz') ?>" id="cdForm">
      <?= Router::csrfField() ?>
      <fieldset>
        <legend>Gdzie chcesz mieć kalendarz i kontakty?</legend>
        <div class="opts">
          <?php $first = true; foreach ($purposes as $key => $o): ?>
          <label class="opt"><input type="radio" name="purpose" value="<?= h($key) ?>"<?= $first ? ' checked' : '' ?>><span><b><?= h($o['label']) ?></b><span><?= h($o['hint']) ?></span></span></label>
          <?php $first = false; endforeach; ?>
        </div>
      </fieldset>
      <div class="row"><button type="submit" class="btn">Połącz z chmurą</button></div>
      <p class="help">Otworzy się strona chmury. Zalogujesz się tam jak zwykle i klikniesz „Udziel dostępu”. Twój login w chmurze: <?= h($cloudUid) ?>.</p>
    </form>
    <div class="imp" role="note">Poczta dPoczta pokaże kalendarz z chmury, ale kontaktów z chmury nie umie pobierać. Kontakty z chmury będą w telefonie i w programie na komputerze.</div>
    <script>
    (function () {
      var f = document.getElementById('cdForm');
      f.addEventListener('submit', function (e) {
        if (!window.fetch) return;   // bez fetch zwykły formularz: strona pokaże link do chmury
        e.preventDefault();
        var w = window.open('', '_blank');
        fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j.ok && j.login) { if (w) { w.opener = null; w.location.href = j.login; } location.reload(); return; }
            if (w) w.close();
            var live = document.getElementById('live'); live.setAttribute('role', 'alert'); live.className = 'msg error'; live.textContent = j.message || 'Coś poszło nie tak.';
          })
          .catch(function () { if (w) w.close(); f.submit(); });
      });
    })();
    </script>
<?php endif; ?>
  </main>
</div>
<?php if ($result): ?>
<script>
(function () {
  var live = document.getElementById('live');
  document.querySelectorAll('.js-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.getAttribute('data-copy'));
      var done = function () { live.textContent = 'Skopiowano.'; var t = b.firstChild.nodeValue; b.firstChild.nodeValue = 'Skopiowano'; setTimeout(function () { b.firstChild.nodeValue = t; }, 2000); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(i.value).then(done, function () { i.select(); document.execCommand('copy'); done(); });
      else { i.select(); document.execCommand('copy'); done(); }
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
