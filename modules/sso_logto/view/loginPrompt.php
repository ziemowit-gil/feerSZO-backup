<?php
/**
 * modules/sso_logto/view/loginPrompt.php — prośba po zalogowaniu (GET /sso/start, SsoController::prompt):
 * „Załóż konto w chmurze plików i logowaniu jednokrotnym”. Strona samodzielna (bez header.php — bez pętli bramki),
 * szata jak kreator „Pierwsze kroki” (modules/first_run): płasko, ciemny pasek z linią cyan.
 * Formularz idzie na POST /sso/konto z from=start (SsoController::selfCreate) i wraca tutaj — wtedy widok „Gotowe”.
 * Zmienne: $me, $email, $need ('both'|'logto'|'cloud'|null), $acc, $cloud (bool), $next, $cont, $flash.
 */
$org       = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
$name      = trim((string)($me['first_name'] ?? '')) ?: (explode(' ', trim((string)($me['name'] ?? '')))[0] ?? '');
$cloudName = SsoConfig::cloudLabel();
$cloudUrl  = SsoConfig::cloudUrl();
$cloudHost = (string)parse_url($cloudUrl, PHP_URL_HOST);
$ssoHost   = (string)parse_url(SsoConfig::logtoUrl(), PHP_URL_HOST);
$hasCloud  = !empty($acc['cloud_uid']);
$username  = !empty($acc['logto_username']) ? (string)$acc['logto_username'] : SsoAccounts::usernameBase((string)($me['m365_login'] ?? ''), $email);
$post      = rtrim(APP_URL, '/') . '/sso/konto';
$fType     = $flash['type'] ?? '';
$fCls      = ['success' => 'ok', 'danger' => 'error', 'error' => 'error', 'warning' => 'warn'][$fType] ?? 'info';
$err       = $fCls === 'error';
$title     = match ($need) {
    'both'  => 'Załóż konto w chmurze plików i logowaniu jednokrotnym',
    'cloud' => 'Załóż konto w chmurze plików',
    'logto' => 'Załóż konto logowania jednokrotnego',
    default => 'Gotowe',
};
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> — <?= h($org) ?></title>
<style>
:root { --cyan:#1ba1e2; --cyan-d:#1583b8; --dark:#1d1d1d; --ok:#008a00; --warn:#c25100; --err:#a20025; --line:#d9d9d9; --mute:#595959; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif; color: #1d1d1d; background: #f4f4f4; font-size: 15px; line-height: 1.5; }
a { color: #0f6fa8; }
.bar { background: var(--dark); color: #fff; padding: 0 20px; min-height: 52px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 3px solid var(--cyan); }
.bar b { font-weight: 600; letter-spacing: .02em; }
.bar a { color: #fff; text-decoration: underline; font-size: 14px; }
.wrap { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
.main { background: #fff; border: 1px solid var(--line); padding: 28px; }
@media (max-width: 520px) { .main { padding: 20px 16px; } }
.kicker { font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--mute); margin: 0 0 4px; }
h1 { font-size: 26px; font-weight: 300; margin: 0 0 6px; line-height: 1.25; }
h2 { font-size: 17px; font-weight: 600; margin: 24px 0 8px; }
.lead { color: var(--mute); margin: 0 0 18px; }
.msg { padding: 10px 14px; margin: 0 0 18px; border-left: 4px solid var(--cyan); background: #f7f7f7; }
.msg.ok { border-color: var(--ok); } .msg.warn { border-color: var(--warn); } .msg.error { border-color: var(--err); }
.imp { padding: 12px 14px; margin: 16px 0; border-left: 4px solid var(--warn); background: #fff8f2; }
.gets { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.gets li { display: flex; gap: 12px; padding: 12px 14px; border: 1px solid #e6e6e6; }
.gets .ic { width: 36px; height: 36px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; background: var(--c, var(--cyan)); }
label { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 4px; }
.help { font-size: 13px; color: var(--mute); margin-top: 4px; }
input[type=text], input[type=email], input[type=password] { width: 100%; max-width: 380px; padding: 9px 10px; border: 2px solid #8a8a8a; font: inherit; border-radius: 0; background: #fff; }
input[readonly] { background: #f2f2f2; border-color: #c8c8c8; }
input[aria-invalid=true] { border-color: var(--err); }
input:focus, button:focus-visible, a:focus-visible { outline: 3px solid var(--dark); outline-offset: 2px; }
.btn { display: inline-block; padding: 9px 18px; border: 2px solid #0f6fa8; background: #0f6fa8; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; border-radius: 0; }
.btn:hover { background: #0b5683; border-color: #0b5683; }
.btn.ghost { background: #fff; color: var(--dark); border-color: #8a8a8a; }
.btn.ghost:hover { background: #f0f0f0; }
.row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 20px; }
.rows { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; }
.rows dt { color: var(--mute); } .rows dd { margin: 0; font-weight: 600; word-break: break-word; }
.mis { color: var(--err); font-weight: 600; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
</head>
<body>
<a class="sr" href="#tresc">Przejdź do treści</a>
<header class="bar">
  <b><?= h($org) ?> · Logowanie jednokrotne</b>
  <?php if ($need !== null): ?><a href="<?= h($cont) ?>">Nie teraz</a><?php endif; ?>
</header>

<div class="wrap">
  <main class="main" id="tresc">
    <p class="kicker"><?= $name !== '' ? 'Cześć, ' . h($name) : 'Nowe konto' ?></p>
    <h1><?= h($title) ?></h1>

    <?php if ($flash): ?>
      <div class="msg <?= $fCls ?>" role="<?= $err ? 'alert' : 'status' ?>"><?= h((string)$flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($need === null): ?>
      <p class="lead">Masz konto logowania jednokrotnego<?= $hasCloud ? ' i konto w chmurze plików ' . h($cloudName) : '' ?>.</p>
      <dl class="rows">
        <dt>Login</dt><dd><?= h($username) ?> albo <?= h($email) ?></dd>
        <dt>Strona logowania</dt><dd><?= h($ssoHost) ?></dd>
        <?php if ($hasCloud): ?>
          <dt>Chmura plików</dt><dd><?= h($cloudName) ?><?= $cloudHost !== '' ? ' — ' . h($cloudHost) : '' ?>, to samo hasło</dd>
        <?php endif; ?>
      </dl>
      <p class="help" style="margin-top:14px">Hasło zmienisz w Systemie Tożsamości, w sekcji <a href="<?= h(APP_URL . '/tozsamosc/index.php#sso') ?>">Logowanie jednokrotne</a>.</p>
      <div class="row">
        <a class="btn" href="<?= h($cont) ?>">Przejdź dalej</a>
        <?php if ($hasCloud && $cloudUrl !== ''): ?>
          <a class="btn ghost" href="<?= h($cloudUrl) ?>" target="_blank" rel="noopener">Otwórz chmurę plików<span class="sr"> (otwiera się w nowej karcie)</span></a>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <p class="lead">Zajmie to minutę. Wybierzesz jedno hasło<?= $need !== 'logto' ? ', które zadziała w chmurze plików i przy logowaniu jednokrotnym' : '' ?>.</p>

      <h2>Co dostajesz</h2>
      <ul class="gets">
        <?php if ($need !== 'logto'): ?>
        <li><span class="ic" style="--c:#0050ef" aria-hidden="true">☁</span><span><strong>Chmura plików <?= h($cloudName) ?></strong><?= $cloudHost !== '' ? ' (' . h($cloudHost) . ')' : '' ?> — miejsce na Twoje pliki i wspólne foldery zespołu. Działa w przeglądarce, na komputerze i w telefonie.</span></li>
        <?php endif; ?>
        <?php if ($need !== 'cloud'): ?>
        <li><span class="ic" aria-hidden="true">🔑</span><span><strong>Logowanie jednokrotne</strong><?= $ssoHost !== '' ? ' (' . h($ssoHost) . ')' : '' ?> — jedno konto i jedno hasło do aplikacji Fundacji. Gdy zalogujesz się raz, kolejne aplikacje nie pytają o hasło.</span></li>
        <?php endif; ?>
      </ul>

      <div class="imp" role="note">
        <?php if ($need === 'cloud'): ?>
          <strong>Masz już konto logowania jednokrotnego</strong> (login <?= h($username) ?>). Nowe hasło zastąpi obecne — od teraz to samo hasło będzie działać w chmurze plików i przy logowaniu jednokrotnym.
        <?php else: ?>
          <strong>To osobne, nowe hasło.</strong>
        <?php endif; ?>
        Hasło do poczty i hasło do SZO zostają bez zmian. Nie używaj tego samego hasła co do poczty.
      </div>

      <h2>Wybierz hasło</h2>
      <form method="post" action="<?= h($post) ?>" novalidate>
        <?= Router::csrfField() ?>
        <input type="hidden" name="from" value="start">
        <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= h($next) ?>"><?php endif; ?>

        <label for="spLogin">Login</label>
        <input type="email" id="spLogin" value="<?= h($email) ?>" readonly autocomplete="username" aria-describedby="spLoginH">
        <div id="spLoginH" class="help">Zalogujesz się tym adresem albo loginem <strong><?= h($username) ?></strong>.</div>

        <label for="spP1">Hasło</label>
        <input type="password" id="spP1" name="password" required minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="spP1H"<?= $err ? ' aria-invalid="true" autofocus' : '' ?>>
        <div id="spP1H" class="help">Co najmniej 10 znaków. Najłatwiej zapamiętać kilka słów, np. trzy wyrazy ze spacjami.</div>

        <label for="spP2">Powtórz hasło</label>
        <input type="password" id="spP2" name="password2" required minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="spP2H"<?= $err ? ' aria-invalid="true"' : '' ?>>
        <div id="spP2H" class="help" aria-live="polite"></div>

        <div class="row">
          <button type="submit" class="btn">Załóż konto</button>
          <a class="btn ghost" href="<?= h($cont) ?>">Nie teraz</a>
        </div>
        <p class="help">„Nie teraz” — zapytamy ponownie przy następnym logowaniu. Konto założysz też sam w Systemie Tożsamości, w sekcji <a href="<?= h(APP_URL . '/tozsamosc/index.php#sso') ?>">Logowanie jednokrotne</a>.</p>
      </form>
      <script>
      (function () {
        var p1 = document.getElementById('spP1'), p2 = document.getElementById('spP2'), out = document.getElementById('spP2H');
        function check() {
          var bad = p2.value.length > 0 && p1.value !== p2.value;
          out.innerHTML = bad ? '<span class="mis">Hasła się różnią.</span>' : '';
          p2.setAttribute('aria-invalid', bad ? 'true' : 'false');
        }
        p1.addEventListener('input', check); p2.addEventListener('input', check);
      })();
      </script>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
