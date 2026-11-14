<?php
/**
 * modules/ti_login/view/loginPage.php — wspólna strona logowania TI (szata METRO portalu TI, modules/ti/view/portal).
 * Jeden formularz „login + hasło” dla uczestników, rodziców/opiekunów, osób upoważnionych i kadry; rodzaj konta
 * rozpoznaje TiLogin. Drugi widok: wybór panelu, gdy ten sam login i hasło pasują do kilku kont. Trzeci: wpisanie kodu
 * z SMS-a (tymczasowe logowanie samym loginem, TiLoginSms, gdy $smsOn).
 *
 * Wejście: $view ('form'|'choice'|'code'), $error, $notice, $login, $hint (tab ze starych adresów), $choiceItems, $choiceNonce,
 *          $formAction, $officeUrl, $pfronOn, $naborOn, $TI_BASE, $smsOn.
 */
$smsOn = !empty($smsOn);
$_lpOrg = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
$_lpTiles = [TiLogin::STUDENT => '#0f6fa8', TiLogin::PARENT => '#00a300', TiLogin::AUTHP => '#7e3878', TiLogin::STAFF => '#1d1d1d'];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($view === 'choice' ? 'Wybierz panel' : ($view === 'code' ? 'Kod z SMS-a' : 'Logowanie')) . ': panel kursanta i dydaktyka, ' . $_lpOrg) ?></title>
<meta name="robots" content="noindex,nofollow">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__, 2) . '/ti/view/portal/_style.php'; ?>
<style>
  .lp-lead { font-size: 1.08rem; margin: -.3rem 0 1.4rem; color: var(--pp-muted); max-width: 46rem; }
  .lp-row { display: flex; align-items: flex-start; gap: 1rem; }
  .lp-row > :first-child { flex: 1 1 auto; min-width: 0; }
  .lp-sec { display: flex; align-items: center; gap: .6rem; max-width: 12rem; background: var(--pp-bg); padding: .45rem .6rem; }
  .lp-sec[hidden] { display: none; }
  .lp-sec-img { width: 56px; height: 56px; flex-shrink: 0; }
  .lp-help { font-size: .95rem; color: var(--pp-muted); margin: .4rem 0 0; }
  .lp-office { border-left: 5px solid var(--pp-blue); background: #e8f2f9; padding: .75rem 1rem; margin: 0 0 1.35rem; }
  .lp-office[hidden] { display: none; }
  .lp-pass { position: relative; }
  .lp-pass .pp-input { padding-right: 3.2rem; }
  .lp-pass-btn { position: absolute; right: 2px; top: 2px; bottom: 2px; width: 3rem; border: 0; background: #fff; font-size: 1.15rem; cursor: pointer; }
  .lp-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .8rem 1.2rem; }
  .lp-ms { display: inline-flex; align-items: center; gap: .5rem; min-height: 48px; padding: .55rem 1.3rem; background: #fff; color: var(--pp-ink) !important;
           border: 2px solid var(--pp-ink); font-weight: 600; text-decoration: none; }
  .lp-ms:hover { background: var(--pp-bg); }
  .lp-or { display: flex; align-items: center; gap: .8rem; margin: 1.6rem 0 1rem; color: var(--pp-muted); font-size: .95rem; }
  .lp-or::before, .lp-or::after { content: ""; flex: 1; border-top: 1px solid var(--pp-line); }
  .lp-more { margin-top: 1.6rem; border-top: 1px solid var(--pp-line); padding-top: 1rem; }
  .lp-more > summary { cursor: pointer; font-weight: 600; color: #0b5f91; min-height: 44px; display: flex; align-items: center; }
  .lp-more ul { list-style: none; padding: 0; margin: .5rem 0 0; display: grid; gap: .5rem; }
  .lp-more a, .lp-more button.lp-link { display: flex; align-items: center; gap: .6rem; min-height: 44px; padding: .5rem .8rem; border: 1px solid var(--pp-line);
                                         background: #fff; color: var(--pp-ink) !important; text-decoration: none; font: inherit; text-align: left; width: 100%; cursor: pointer; }
  .lp-more a:hover, .lp-more button.lp-link:hover { border-color: var(--pp-blue); }
  .lp-more .bi { font-size: 1.2rem; color: var(--pp-blue); }
  .lp-kinds { list-style: none; padding: 0; margin: .4rem 0 0; }
  .lp-kinds li { padding: .45rem 0; border-top: 1px solid #374151; }
  .lp-kinds li:first-child { border-top: 0; }
  .lp-kinds code { color: #fde68a; font-size: .95rem; }
  /* Wybór panelu — kafle METRO */
  .lp-tiles { list-style: none; padding: 0; margin: 0 0 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem; }
  .lp-tile { display: flex; flex-direction: column; justify-content: space-between; gap: .9rem; width: 100%; min-height: 128px; padding: 1rem 1.1rem; border: 0;
             color: #fff; text-align: left; font: inherit; cursor: pointer; }
  .lp-tile:hover { filter: brightness(.9); }
  .lp-tile .bi { font-size: 2rem; }
  .lp-tile-t { display: block; font-size: 1.15rem; font-weight: 600; }
  .lp-tile-d { display: block; font-size: .92rem; opacity: .92; }
  /* Kod z SMS-a (tymczasowe logowanie samym loginem) */
  .lp-sms { border-left: 5px solid var(--pp-blue); background: #e8f2f9; padding: .75rem 1rem; margin: 0 0 1.35rem; }
  .lp-code { max-width: 12rem; font-size: 1.5rem; letter-spacing: .3em; font-variant-numeric: tabular-nums; }
  .lp-linkbtn { background: none; border: 0; padding: 0; min-height: 44px; font: inherit; color: #0b5f91; text-decoration: underline; cursor: pointer; }
  .lp-linkbtn:hover { color: #084466; }
  /* 2026-11-14 („prostszy, podobny do Logto”, „tak samo ti.feer.org.pl”): jedna wąska kolumna, kolor #1e6dff jak logowanie SZO.
     Biały na #1e6dff = 4,48:1, więc napis przycisku duży i pogrubiony (WCAG: duży tekst wymaga 3:1). */
  .lp-one { max-width: 28rem; margin: 0 auto; }
  .lp-one > .pp-h1, .lp-one > .lp-lead { text-align: center; }
  .lp-one .lp-lead { margin: -.3rem auto 1.4rem; }
  .lp-one .pp-input { width: 100%; }
  .lp-one .pp-submit { width: 100%; min-height: 50px; background: #1e6dff; border-color: #1e6dff; color: #fff; font-size: 1.2rem; font-weight: 700; }
  .lp-one .pp-submit:hover { background: #1559e0; border-color: #1559e0; }
  .lp-one .lp-actions { display: block; }
  .lp-one .lp-ms { width: 100%; justify-content: center; }
  .lp-one .lp-row { flex-wrap: wrap; }
  .lp-kinds2 { margin: .5rem 0 0; padding-left: 1.2rem; }
  .lp-kinds2 li { margin: 0 0 .35rem; }
  .lp-imp { margin: 1.5rem 0 0; padding: .85rem 1rem; background: #fffbeb; border: 2px solid #b45309; font-size: .95rem; line-height: 1.5; }
  .lp-imp p { margin: 0 0 .35rem; } .lp-imp p:last-child { margin: 0; }
  .lp-imp-h { font-size: .8rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #78350f; }
  .lp-new { margin: 1.25rem 0 0; text-align: center; }
</style>
</head>
<body class="pp">
<?php
$ppTitle = 'Panel kursanta / dydaktyka';   // nazwa wg polecenia 2026-11-14 „Panel TI = Panel kursanta / dydaktyka”
 $ppOrg = $_lpOrg; $ppCrumb = 'Logowanie'; $ppLogo = ''; $ppLoginUrl = $formAction; $ppAccount = null;
include dirname(__DIR__, 2) . '/ti/view/portal/_header.php';
?>
<main id="pp-main" class="pp-wrap" tabindex="-1">
<?php if ($view === 'choice'): ?>
  <h1 class="pp-h1">Wybierz panel</h1>
  <p class="lp-lead">Ten login i hasło pasują do kilku kont. Wybierz, do którego panelu chcesz wejść. Zmienisz go, wylogowując się i logując ponownie.</p>
  <form method="post" action="<?= h($formAction) ?>">
    <input type="hidden" name="nonce" value="<?= h($choiceNonce) ?>">
    <ul class="lp-tiles" aria-label="Twoje konta">
      <?php foreach ($choiceItems as $_i => $_it): [$_t, $_d, $_ic] = TiLogin::LABELS[$_it['type']]; ?>
      <li><button type="submit" name="wybor" value="<?= (int)$_i ?>" class="lp-tile" style="background:<?= h($_lpTiles[$_it['type']]) ?>">
        <i class="bi <?= h($_ic) ?>" aria-hidden="true"></i>
        <span><span class="lp-tile-t"><?= h($_t) ?></span><span class="lp-tile-d"><?= h($_d) ?></span></span>
      </button></li>
      <?php endforeach; ?>
    </ul>
  </form>
  <p><a href="<?= h($formAction) ?>">Anuluj i wróć do logowania</a></p>
<?php elseif ($view === 'code'): ?>
  <div class="lp-one">
  <h1 class="pp-h1">Wpisz kod z SMS-a</h1>
  <p class="lp-lead">Jeśli login należy do uczestnika albo prowadzącego z umową i mamy jego numer, wysłaliśmy SMS z kodem. Kod działa 10 minut.</p>
  <?php if ($error): ?><div class="pp-error" role="alert" id="lp-err"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$error && $notice): ?><div class="pp-ok" role="status"><?= h($notice) ?></div><?php endif; ?>
  <form method="post" action="<?= h($formAction) ?>" autocomplete="off">
    <div class="pp-field">
      <label for="lp-code">Kod z SMS-a</label>
      <input type="text" class="pp-input lp-code" id="lp-code" name="kod" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
             autocomplete="one-time-code" required autofocus aria-describedby="lp-code-h<?= $error ? ' lp-err' : '' ?>"<?= $error ? ' aria-invalid="true"' : '' ?>>
      <p id="lp-code-h" class="lp-help">Sześć cyfr, bez spacji.</p>
    </div>
    <div class="lp-actions">
      <button type="submit" class="pp-submit">Zaloguj się</button>
    </div>
  </form>
  <form method="post" action="<?= h($formAction) ?>" style="margin-top:1rem">
    <button type="submit" name="ponow" value="1" class="lp-linkbtn">Kod nie przyszedł? Wyślij nowy</button>
  </form>
  <p style="margin-top:.5rem"><a href="<?= h($formAction) ?>">Wróć do logowania</a></p>
  <details class="lp-more">
    <summary>Skąd bierzemy numer?</summary>
    <p>Uczestnik dostaje SMS na numer z karty w placówce. Prowadzący dostaje go na numer komórkowy z konta SZO, a gdy go tam nie ma, z umowy.
      Numer się zmienił albo kod nie przychodzi? Skontaktuj się z placówką. Rodzic, opiekun i osoba upoważniona logują się hasłem.</p>
  </details>
  </div>
<?php else: ?>
  <div class="lp-one">
  <h1 class="pp-h1">Zaloguj się do panelu kursanta albo dydaktyka</h1>
  <p class="lp-lead">Wpisz login, a rozpoznamy, do którego panelu należy.</p>
  <?php if ($error): ?><div class="pp-error" role="alert" id="lp-err"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$error && $notice): ?><div class="pp-ok" role="status"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($smsOn): ?>
  <div class="lp-sms">Uczestnik i prowadzący z umową wpisują sam login (prowadzący: adres e-mail) i dostają kod SMS.
    Rodzic, opiekun i osoba upoważniona wpisują hasło.</div>
  <?php endif; ?>
  <form method="post" action="<?= h($formAction) ?>" autocomplete="on" id="lp-form">
    <div class="pp-field">
      <label for="lp-login">Login lub e-mail</label>
      <div class="lp-row">
        <div>
          <input type="text" class="pp-input" id="lp-login" name="login" value="<?= h($login) ?>" required autocomplete="username"
                 autocapitalize="none" spellcheck="false" autofocus aria-describedby="lp-login-h<?= $error ? ' lp-err' : '' ?>"<?= $error ? ' aria-invalid="true"' : '' ?>>
          <p id="lp-login-h" class="lp-help">Uczestnik: np. <span class="font-monospace">j.kowalski</span> albo <span class="font-monospace">k1234</span>.
            Rodzic: login z końcówką <span class="font-monospace">-r</span>. Kadra: adres e-mail.</p>
        </div>
        <div class="lp-sec" id="lp-sec" hidden aria-live="polite">
          <div class="lp-sec-img" id="lp-sec-img" aria-hidden="true"></div>
          <div class="small"><span class="visually-hidden">Twój obrazek bezpieczeństwa: </span><span id="lp-sec-label"></span></div>
        </div>
      </div>
    </div>
    <div class="lp-office" id="lp-office" role="status" hidden>
      <?php if ($smsOn): ?>
      Adres <?= h(TiLogin::OFFICE_DOMAIN) ?>: hasła tu nie wpisujesz. Prowadzący z umową dostanie kod SMS, a administratora przeniesiemy do logowania Microsoft 365.
      <?php else: ?>
      Adresy <?= h(TiLogin::OFFICE_DOMAIN) ?> logują się przez Microsoft 365, więc hasła tu nie wpisujesz. Wybierz „Dalej”.
      <?php endif; ?>
    </div>
    <div class="pp-field" id="lp-pass-field">
      <label for="lp-password">Hasło</label>
      <div class="lp-pass">
        <input type="password" class="pp-input" id="lp-password" name="password" autocomplete="current-password" aria-describedby="lp-pass-h">
        <button type="button" class="lp-pass-btn" id="lp-pass-btn" aria-label="Pokaż hasło" aria-pressed="false" aria-controls="lp-password">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
      </div>
      <?php if ($smsOn): ?>
      <p id="lp-pass-h" class="lp-help">Uczestnik i prowadzący z umową zostawiają to pole puste, kod przyjdzie SMS-em.</p>
      <?php else: ?>
      <p id="lp-pass-h" class="lp-help">Pierwsze logowanie uczestnika: zostaw to pole puste, kod przyjdzie SMS-em. Kadra po haśle poda kod z aplikacji.</p>
      <?php endif; ?>
    </div>
    <div class="lp-actions">
      <button type="submit" class="pp-submit" id="lp-submit"><?= $smsOn ? 'Wyślij kod SMS' : 'Zaloguj się' ?></button>
    </div>
  </form>

  <div class="lp-or">albo</div>
  <a class="lp-ms" href="<?= h($officeUrl) ?>"><i class="bi bi-microsoft" aria-hidden="true"></i> <?= $smsOn ? 'Administratorzy: Microsoft 365' : 'Kadra ' . h(TiLogin::OFFICE_DOMAIN) . ': Microsoft 365' ?></a>

  <details class="lp-more">
    <summary>Jak wygląda login</summary>
    <ul class="lp-kinds2">
      <li>Uczestnik: login z SMS-a albo z wydruku, np. <code>j.kowalski</code> albo <code>k1234</code> (konsultacje), albo własny alias.</li>
      <li>Rodzic lub opiekun: login rodzica, np. <code>j.kowalski-r</code>.</li>
      <li>Osoba upoważniona: login od uczestnika, np. <code>a.nowak-up</code>.</li>
      <li>Kadra (prowadzący, konsultant, kierownik): adres e-mail.<?= $smsOn ? ' Prowadzący z umową dostaje kod SMS, administratorzy logują się przez Microsoft 365.' : ' Konta ' . h(TiLogin::OFFICE_DOMAIN) . ' logują się przez Microsoft 365.' ?></li>
    </ul>
  </details>

  <details class="lp-more"<?= in_array($hint, ['rodzic', 'up'], true) ? ' open' : '' ?>>
    <summary>Inne sposoby logowania</summary>
    <ul>
      <li><a href="<?= h(tiPanelUrl('kursant', 'parent.php')) ?>"><i class="bi bi-chat-dots" aria-hidden="true"></i> Rodzic lub opiekun bez hasła: kod SMS na telefon opiekuna</a></li>
      <li><button type="button" class="lp-link" id="lp-key"><i class="bi bi-usb-drive" aria-hidden="true"></i> Uczestnik: klucz sprzętowy (najpierw wpisz login)</button>
        <div class="pp-error" id="lp-key-err" role="alert" hidden style="margin:.5rem 0 0"></div></li>
      <?php if ($pfronOn): ?>
      <li><a href="<?= h(tiPanelUrl('kursant', 'pfron.php')) ?>"><i class="bi bi-shield-lock" aria-hidden="true"></i> Rozliczenia PFRON (Aktywny Samorząd): numer umowy i telefon</a></li>
      <?php endif; ?>
      <li><a href="/nabor/logowanie"><i class="bi bi-person-badge" aria-hidden="true"></i> Kandydat na zajęcia: konto kandydata</a></li>
    </ul>
  </details>

  <div class="lp-imp" role="note" aria-labelledby="lp-imp-h">
    <p class="lp-imp-h" id="lp-imp-h">Ważne</p>
    <p>Po wpisaniu loginu uczestnik widzi swój obrazek. Gdy widzisz inny, nie wpisuj hasła.</p>
    <p>Nikt z Fundacji nie poprosi Cię o hasło ani o kod z SMS-a. Nie pamiętasz loginu albo hasła? Skontaktuj się z placówką.</p>
  </div>

  <p class="lp-new">Nie masz konta?
    <?php if ($naborOn): ?><a href="/nabor">Zapisz się na zajęcia</a><?php else: ?><a href="<?= h($TI_BASE) ?>zapisy.php">Zostaw kontakt</a><?php endif; ?> albo <a href="/nabor/rejestracja">załóż konto kandydata</a>.</p>
  </div>
<?php endif; ?>
</main>
<?php
$ppFootLinks = ['Co nowego w panelu' => $TI_BASE . 'nowosci.php', 'Czym jest SSO' => $TI_BASE . 'sso.php', 'Strona Fundacji FEER' => 'https://feer.org.pl',
                'Panel współpracownika' => rtrim(APP_URL, '/') . '/panel/index.php', 'Logowanie do systemu (SZO)' => rtrim(APP_URL, '/') . '/auth/login.php'];
include dirname(__DIR__, 2) . '/ti/view/portal/_footer.php';
?>
<?php if ($view === 'form'): ?>
<script>
(function () {
  var officeDomain = <?= json_encode(TiLogin::OFFICE_DOMAIN) ?>;
  var inp = document.getElementById('lp-login'), pass = document.getElementById('lp-password');
  var passField = document.getElementById('lp-pass-field'), office = document.getElementById('lp-office');
  var submit = document.getElementById('lp-submit'), sec = document.getElementById('lp-sec');
  var secImg = document.getElementById('lp-sec-img'), secLbl = document.getElementById('lp-sec-label');
  var err = document.getElementById('lp-err');
  if (err) { (pass.value === '' && inp.value !== '' ? pass : inp).focus(); }

  // Pokaż / ukryj hasło (klawiatura, aria-pressed).
  var pbtn = document.getElementById('lp-pass-btn');
  pbtn.addEventListener('click', function () {
    var show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    pbtn.setAttribute('aria-pressed', show ? 'true' : 'false');
    pbtn.setAttribute('aria-label', show ? 'Ukryj hasło' : 'Pokaż hasło');
  });

  // Rodzaj loginu po samym zapisie (bez pytania serwera): domena Microsoft 365 → bez hasła; e-mail kadry; konto panelu.
  var lastKind = '';
  function kind(v) {
    v = v.trim().toLowerCase();
    if (v.indexOf('@') >= 0) return v.slice(-officeDomain.length) === officeDomain ? 'office' : 'staff';
    return v === '' ? '' : 'panel';
  }
  // Tymczasowe logowanie kodem SMS: pusty login lub hasło (albo adres w domenie Microsoft 365) → „Wyślij kod SMS”.
  var smsOn = <?= json_encode($smsOn) ?>;
  function update() {
    var k = kind(inp.value);
    var isOffice = k === 'office';
    office.hidden = !isOffice;
    passField.hidden = isOffice;
    pass.disabled = isOffice;
    submit.textContent = (smsOn && (isOffice || pass.value === '')) ? 'Wyślij kod SMS'
                       : (isOffice ? 'Dalej: Microsoft 365' : 'Zaloguj się');
    if (k !== 'panel') { sec.hidden = true; }
    lastKind = k;
  }
  pass.addEventListener('input', update);
  // Obrazek bezpieczeństwa uczestnika (nie dla e-maili ani loginów rodzica „-r” / osoby upoważnionej „-up”).
  function showSec() {
    var v = inp.value.trim();
    if (kind(v) !== 'panel' || /-(r|up)\d*$/i.test(v)) { sec.hidden = true; return; }
    fetch(<?= json_encode(rtrim(APP_URL, '/') . '/karty30/ti/kursant/sec_image.php?login=') ?> + encodeURIComponent(v), { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.none || inp.value.trim() !== v) { sec.hidden = true; return; } secImg.innerHTML = j.svg; secLbl.textContent = j.label; sec.hidden = false; })
      .catch(function () { sec.hidden = true; });
  }
  var t = null;
  inp.addEventListener('input', function () { update(); clearTimeout(t); t = setTimeout(showSec, 300); });
  inp.addEventListener('change', function () { update(); showSec(); });
  update(); if (inp.value.trim() !== '') showSec();

  // Logowanie kluczem sprzętowym uczestnika (WebAuthn) — jak w module Tożsamość.
  var kbtn = document.getElementById('lp-key'), kerr = document.getElementById('lp-key-err');
  function b64uToBuf(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var bin = atob(s), buf = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i); return buf.buffer; }
  function bufToB64u(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function keyError(m) { kerr.textContent = m; kerr.hidden = false; }
  if (!window.PublicKeyCredential || !navigator.credentials) { kbtn.disabled = true; kbtn.setAttribute('aria-describedby', 'lp-key-na'); kbtn.insertAdjacentHTML('beforeend', '<span id="lp-key-na" class="visually-hidden">, przeglądarka nie obsługuje kluczy</span>'); }
  kbtn.addEventListener('click', function () {
    var login = inp.value.trim(); kerr.hidden = true;
    if (!login || kind(login) !== 'panel') { keyError('Najpierw wpisz login uczestnika.'); inp.focus(); return; }
    var api = <?= json_encode(rtrim(APP_URL, '/') . '/tozsamosc/kursant_klucz_login.php?api=') ?>;
    fetch(api + 'begin&login=' + encodeURIComponent(login), { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Błąd'); return j; }); })
      .then(function (o) {
        o.challenge = b64uToBuf(o.challenge);
        o.allowCredentials = (o.allowCredentials || []).map(function (c) { return { id: b64uToBuf(c.id), type: c.type, transports: c.transports }; });
        return navigator.credentials.get({ publicKey: o });
      })
      .then(function (c) {
        var fd = new FormData();
        fd.append('data', JSON.stringify({ id: c.id, rawId: bufToB64u(c.rawId), clientDataJSON: bufToB64u(c.response.clientDataJSON),
          authenticatorData: bufToB64u(c.response.authenticatorData), signature: bufToB64u(c.response.signature) }));
        return fetch(api + 'complete', { method: 'POST', body: fd, credentials: 'omit' })
          .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Błąd'); return j; }); });
      })
      .then(function (j) { window.location = j.redirect; })
      .catch(function (e) { keyError(e && e.message ? e.message : 'Nie udało się zalogować kluczem.'); });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
