<?php
/**
 * modules/nowe_haslo/view/index.php — /nowehaslo: samodzielna strona (bez menu SZO), wygląd METRO jak /migracjapoczty
 * (styl: modules/dhosting_migrator/view/_metro.php). Stany: wejście gościa (dane) → kod SMS + formularz zmiany;
 * zalogowany: konta, kod SMS, formularz. Konta i login pokazujemy dopiero potwierdzonej osobie (gość — po pierwszym kodzie).
 * Kod potwierdzenia: hasło do poczty — SMS na numer z konta/umowy albo e-mail na prywatny adres; logowanie jednokrotne — e-mail.
 * Zmienne: $st (NoweHasloAuth::state), $acc (NoweHaslo::overview|null), $code (NoweHasloAuth::codeInfo|null), $flash, $form.
 */
$org   = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
$mode  = $st['mode'];
$p     = $st['p'];
$conf  = $st['confirmed'];
$chSso  = $conf ? NoweHasloAuth::channelFor($p, 'sso') : null;
$chMail = $conf ? NoweHasloAuth::channelFor($p, 'mail') : null;
$loginUrl = APP_URL . '/auth/login.php?redirect=' . urlencode(APP_URL . '/nowehaslo');
$hasSso  = $acc ? $acc['sso'] : true;
$hasMail = $acc ? $acc['mailbox'] !== '' : true;
// co można zmienić: konto istnieje i (u potwierdzonej osoby) jest gdzie wysłać kod
$okSso  = $hasSso && (!$conf || $chSso !== null);
$okMail = $hasMail && (!$conf || $chMail !== null);
$tSel = (string)($form['target'] ?? '');
if ($tSel === '' || ($tSel === 'sso' && !$okSso) || ($tSel === 'mail' && !$okMail)) $tSel = $okSso ? 'sso' : ($okMail ? 'mail' : '');
$mSel = in_array($form['mode'] ?? '', ['own', 'random'], true) ? $form['mode'] : 'own';
$noRandom = $conf && $p && $p['phone'] === '';   // hasło losowe idzie tylko SMS-em
if ($noRandom) $mSel = 'own';
?><!doctype html>
<html lang="pl" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Nowe hasło — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__, 2) . '/dhosting_migrator/view/_metro.php'; ?>
<style>
  .pvm fieldset.nh-set{border:0;margin:0 0 1.1rem;padding:0}
  .pvm fieldset.nh-set>legend{font-weight:600;font-size:1rem;margin:0 0 .4rem;padding:0}
  .pvm .nh-opt{display:flex;gap:.6rem;align-items:flex-start;margin:.25rem 0;padding:.55rem .7rem;border:2px solid var(--pvm-line);background:#fff;max-width:36rem}
  .pvm .nh-opt:has(input:checked){border-color:var(--m-blue);background:var(--pvm-light)}
  .pvm .nh-opt input{width:1.25rem;height:1.25rem;margin-top:.15rem;flex-shrink:0;accent-color:var(--m-blue)}
  .pvm .nh-opt .nh-sub{display:block;font-size:.875rem;color:var(--pvm-muted)}
  .pvm .nh-opt input:disabled+span{color:var(--pvm-muted)}
  .pvm .nh-opt input:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
  .pvm .nh-login{font-size:1.15rem;font-weight:600;word-break:break-all}
  .pvm ol.nh-two{margin:.3rem 0 0;padding-left:1.3rem}
  .pvm ol.nh-two li{margin:.35rem 0}
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="dm-bar">
  <div class="dm-bar__in">
    <span class="dm-bar__org"><span class="sq" aria-hidden="true"><i class="bi bi-key"></i></span><?= h($org) ?></span>
    <span class="dm-bar__sp"></span>
    <?php if ($mode === 'user'): ?>
      <span class="dm-bar__who"><?= h((string)($p['name'] ?? '')) ?></span>
      <a href="<?= h(APP_URL . '/') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do SZO</a>
    <?php elseif ($mode === 'guest'): ?>
      <form method="post" action="<?= h(route('nh.end')) ?>"><?= Router::csrfField() ?>
        <button type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Zakończ</button></form>
    <?php else: ?>
      <a href="<?= h($loginUrl) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Masz konto w SZO? Zaloguj się</a>
    <?php endif; ?>
  </div>
</header>

<main id="tresc" class="pvm dm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Konta Fundacji</div>
      <h1 class="mt-title">Nowe hasło</h1>
      <p class="mt-lead">Tu zmienisz hasło do logowania jednokrotnego i chmury plików albo hasło do poczty.
        <?= $mode === 'user' ? 'Każdą zmianę potwierdzisz kodem.' : 'Najpierw potwierdzimy, że to Ty: danymi z umowy z Fundacją i kodem.' ?>
        Przy haśle do poczty kod przychodzi SMS-em (a gdy nie mamy numeru, na prywatny e-mail), przy haśle logowania jednokrotnego e-mailem.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= in_array($flash['type'], ['success', 'info'], true) ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : ($flash['type'] === 'info' ? 'bi-info-circle' : 'bi-exclamation-triangle') ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

  <div class="mt-note" role="note" style="border-left-color:var(--m-orange);margin-bottom:1.25rem"><i class="bi bi-shield-lock" aria-hidden="true" style="color:var(--m-orange)"></i>
    <div><strong>Masz dwa hasła.</strong>
      <ol class="nh-two">
        <li><strong>Logowanie jednokrotne i chmura plików</strong> — jedno wspólne hasło.
          Od <?= h(NoweHaslo::ONE_PASSWORD_FROM) ?> tym samym loginem i tym hasłem zalogujesz się do wszystkich aplikacji Fundacji.</li>
        <li><strong>Poczta</strong> — osobne hasło. Musi być inne niż hasło logowania jednokrotnego. Sprawdzimy to przy zmianie.</li>
      </ol>
      <p style="margin:.4rem 0 0">Gdy ktoś pozna jedno hasło, nie dostanie się do drugiego konta. Hasła nigdy nie wysyłamy e-mailem.</p></div></div>

  <?php if ($mode === 'none'): ?>
  <ol class="dm-path" aria-label="Etapy">
    <li aria-current="step">1. Twoje dane</li>
    <li>2. Kod i nowe hasło</li>
  </ol>
  <section class="dm-sec" aria-labelledby="hId">
    <h2 id="hId" class="mt-h2">Twoje <b>dane</b></h2>
    <div class="mt-group">
      <form method="post" action="<?= h(route('nh.guest')) ?>" class="js-once" novalidate>
        <?= Router::csrfField() ?><input type="hidden" name="op" value="ident">
        <label class="dm-fld" for="nhIdent">Prywatny e-mail albo PESEL</label>
        <input class="dm-inp" id="nhIdent" name="ident" type="text" required autocomplete="off" spellcheck="false" aria-describedby="nhIdentHelp">
        <p class="dm-help" id="nhIdentHelp">Ten sam, który podano w umowie z Fundacją. Prywatny e-mail — nie służbowy.</p>
        <label class="dm-fld" for="nhAddr">Twój adres w domenie feer.org.pl</label>
        <input class="dm-inp" id="nhAddr" name="address" type="email" required autocomplete="email" spellcheck="false" aria-describedby="nhAddrHelp">
        <p class="dm-help" id="nhAddrHelp">Na przykład imie.nazwisko@feer.org.pl.</p>
        <button type="submit" class="mt-btn"><i class="bi bi-arrow-right" aria-hidden="true"></i> Dalej</button>
      </form>
    </div>
    <div class="mt-note"><i class="bi bi-info-circle" aria-hidden="true"></i>
      <div>Dane tylko porównujemy z tymi, które Fundacja już ma. Potem wybierzesz hasło i zamówisz kod: SMS-em na numer z SZO albo z umowy
        albo e-mailem na adres, który znamy. Jeśli kod nie przyjdzie, napisz do Helpdesku IT.</div></div>
  </section>

  <?php else: ?>
    <?php if ($mode === 'guest'): ?>
    <ol class="dm-path" aria-label="Etapy">
      <li class="done">1. Twoje dane<span class="visually-hidden"> (zrobione)</span></li>
      <li aria-current="step">2. Kod i nowe hasło</li>
    </ol>
    <?php endif; ?>

    <?php if ($acc): ?>
    <section class="dm-sec" aria-labelledby="hAcc">
      <h2 id="hAcc" class="mt-h2">Twój <b>login</b></h2>
      <div class="mt-group">
        <p class="nh-login"><?= h($acc['login']) ?></p>
        <p>Tego loginu używasz w SZO, w logowaniu jednokrotnym i w chmurze plików<?= $acc['cloudUid'] !== '' ? ' (w chmurze działa też login <strong>' . h($acc['cloudUid']) . '</strong>)' : '' ?>.</p>
        <?php if ($acc['mailbox'] !== ''): ?>
          <p>Login do poczty: <strong><?= h($acc['mailbox']) ?></strong><?= $acc['mailbox'] === $acc['login'] ? ' — ten sam adres, ale inne hasło' : '' ?>.</p>
        <?php endif; ?>
        <?php if (!$acc['sso']): ?>
          <p>Nie masz jeszcze konta logowania jednokrotnego ani konta w chmurze plików. Napisz do Helpdesku IT — założymy je.</p>
        <?php endif; ?>
        <?php if ($acc['mailbox'] === ''): ?>
          <p>Nie masz skrzynki pocztowej Fundacji, więc zmienisz tu tylko hasło logowania jednokrotnego.</p>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($hasSso || $hasMail): ?>
    <section class="dm-sec" aria-labelledby="hChg" id="zmiana">
      <h2 id="hChg" class="mt-h2">Zmień <b>hasło</b></h2>

      <?php if ($code === null): ?>
        <div class="mt-group">
          <form method="post" action="<?= h(route('nh.code')) ?>" class="js-once" novalidate><?= Router::csrfField() ?>
            <fieldset class="nh-set">
              <legend>Które hasło zmieniasz?</legend>
              <label class="nh-opt"><input type="radio" name="target" value="sso"<?= $tSel === 'sso' ? ' checked' : '' ?><?= $okSso ? '' : ' disabled' ?>>
                <span>Logowanie jednokrotne i chmura plików<span class="nh-sub">Jedno hasło do obu.
                  <?php if (!$hasSso): ?>Nie masz jeszcze tego konta.
                  <?php elseif ($conf && !$chSso): ?>Nie mamy Twojego adresu e-mail, więc nie wyślemy kodu. Napisz do Helpdesku IT.
                  <?php else: ?>Kod przyjdzie <?= $chSso ? h($chSso['mask']) : 'e-mailem' ?>.<?php endif; ?></span></span></label>
              <label class="nh-opt"><input type="radio" name="target" value="mail"<?= $tSel === 'mail' ? ' checked' : '' ?><?= $okMail ? '' : ' disabled' ?>>
                <span>Poczta<span class="nh-sub"><?= $acc && $acc['mailbox'] !== '' ? h($acc['mailbox']) . '. ' : '' ?>Osobne hasło, inne niż do logowania jednokrotnego.
                  <?php if (!$hasMail): ?>Nie masz skrzynki.
                  <?php elseif ($conf && !$chMail): ?>Nie mamy Twojego numeru komórkowego ani prywatnego e-maila. Napisz do Helpdesku IT.
                  <?php else: ?>Kod przyjdzie <?= $chMail ? h($chMail['mask']) : 'SMS-em na numer z SZO albo z umowy, a gdy go nie mamy, na Twój prywatny e-mail' ?>.<?php endif; ?></span></span></label>
            </fieldset>
            <?php if ($okSso || $okMail): ?>
              <p class="dm-help">Kod jest ważny 10 minut i działa przy jednej zmianie.</p>
              <button type="submit" class="mt-btn"><i class="bi bi-send" aria-hidden="true"></i> Wyślij kod</button>
            <?php endif; ?>
          </form>
        </div>
        <?php if ($mode === 'guest' && !$conf): ?>
          <div class="dm-row">
            <form method="post" action="<?= h(route('nh.guest')) ?>"><?= Router::csrfField() ?><input type="hidden" name="op" value="restart">
              <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Zacznij od nowa</button></form>
          </div>
        <?php endif; ?>
      <?php else:
        $tLabel = $code['target'] === 'mail' ? 'hasło do poczty' . ($acc && $acc['mailbox'] !== '' ? ' ' . $acc['mailbox'] : '') : 'hasło logowania jednokrotnego i chmury plików';
        $isSms  = $code['channel'] === 'sms'; ?>
        <div class="mt-group">
          <p>Zmieniasz <strong><?= h($tLabel) ?></strong>.
            <?= $code['mask'] !== '' ? 'Kod wysłaliśmy ' . h($code['mask']) . '.' : 'Jeśli dane się zgadzają, kod już do Ciebie idzie.' ?></p>
          <form method="post" action="<?= h(route('nh.change')) ?>" class="js-once" id="nhForm" novalidate>
            <?= Router::csrfField() ?>
            <fieldset class="nh-set">
              <legend>Jakie ma być nowe hasło?</legend>
              <label class="nh-opt"><input type="radio" name="mode" value="own"<?= $mSel === 'own' ? ' checked' : '' ?> aria-controls="nhOwn">
                <span>Ustawię własne<span class="nh-sub">Wpiszesz je poniżej.</span></span></label>
              <label class="nh-opt"><input type="radio" name="mode" value="random"<?= $mSel === 'random' ? ' checked' : '' ?><?= $noRandom ? ' disabled' : '' ?> aria-controls="nhOwn">
                <span>Wyślij mi losowe SMS-em<span class="nh-sub"><?= $noRandom ? 'Nie mamy Twojego numeru komórkowego, a hasła nie wysyłamy e-mailem.' : 'Dostaniesz je na telefon. Na ekranie go nie pokażemy.' ?></span></span></label>
            </fieldset>
            <div id="nhOwn">
              <label class="dm-fld" for="nhPw">Nowe hasło</label>
              <input class="dm-inp" id="nhPw" name="password" type="password" autocomplete="new-password" maxlength="<?= NoweHaslo::MAX_LEN ?>" aria-describedby="nhPwHelp">
              <p class="dm-help" id="nhPwHelp">Co najmniej <?= NoweHaslo::MIN_LEN ?> znaków: małe i wielkie litery oraz cyfry. Bez imienia, nazwiska i loginu.
                Hasło do poczty musi być inne niż hasło logowania jednokrotnego.</p>
              <label class="dm-fld" for="nhPw2">Powtórz nowe hasło</label>
              <input class="dm-inp" id="nhPw2" name="password2" type="password" autocomplete="new-password" maxlength="<?= NoweHaslo::MAX_LEN ?>">
              <label class="dm-chk" style="margin-top:.6rem"><input type="checkbox" id="nhShow"> <span>Pokaż hasło</span></label>
            </div>
            <label class="dm-fld" for="nhCode" style="margin-top:.6rem"><?= $code['channel'] === '' ? 'Kod potwierdzenia' : ($isSms ? 'Kod z SMS-a' : 'Kod z e-maila') ?></label>
            <input class="dm-inp dm-inp--code" id="nhCode" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code" aria-describedby="nhCodeHelp">
            <p class="dm-help" id="nhCodeHelp">6 cyfr, ważny do <?= h(date('H:i', $code['exp'])) ?>.<?= !$isSms ? ' Nie ma e-maila? Sprawdź folder ze spamem.' : '' ?></p>
            <button type="submit" class="mt-btn"><i class="bi bi-check2" aria-hidden="true"></i> Zmień hasło</button>
          </form>
        </div>
        <div class="dm-row">
          <?php if ($code['canResendIn'] === 0): ?>
            <form method="post" action="<?= h(route('nh.code')) ?>"><?= Router::csrfField() ?><input type="hidden" name="target" value="<?= h($code['target']) ?>">
              <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Wyślij nowy kod</button></form>
          <?php else: ?>
            <p class="dm-help" style="margin:0">Kod nie przyszedł? Nowy możesz zamówić za minutę (odśwież stronę).</p>
          <?php endif; ?>
          <form method="post" action="<?= h(route('nh.code')) ?>"><?= Router::csrfField() ?><input type="hidden" name="op" value="cancel">
            <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i> Zmień wybór</button></form>
          <?php if ($mode === 'guest' && !$conf): ?>
            <form method="post" action="<?= h(route('nh.guest')) ?>"><?= Router::csrfField() ?><input type="hidden" name="op" value="restart">
              <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Zacznij od nowa</button></form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  <?php endif; ?>
</main>

<script>
(function () {
  document.querySelectorAll('form.js-once').forEach(function (f) {
    f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.lastChild.textContent = ' Chwileczkę…'; } });
  });
  var form = document.getElementById('nhForm');
  if (!form) return;
  var own = document.getElementById('nhOwn');
  function sync() {
    var r = form.querySelector('input[name=mode]:checked');
    var isOwn = !r || r.value === 'own';
    own.hidden = !isOwn;
    own.querySelectorAll('input[type=password]').forEach(function (i) { i.required = isOwn; });
  }
  form.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
  var show = document.getElementById('nhShow');
  if (show) show.addEventListener('change', function () {
    own.querySelectorAll('#nhPw, #nhPw2').forEach(function (i) { i.type = show.checked ? 'text' : 'password'; });
  });
})();
</script>
</body>
</html>
