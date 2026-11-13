<?php
/**
 * modules/dhosting_migrator/view/guest.php — /migracjapoczty bez logowania do SZO (polecenie 2026-11-13), wygląd METRO.
 * Kroki: 1) prywatny e-mail albo PESEL + adres w domenie feer.org.pl, 2) numer komórkowy (tylko gdy SZO go nie ma),
 * 3) kod SMS (i kod z prywatnego e-maila przy nowym numerze). Logika: DhostingGuest. Zmienne: $flow, $flash.
 */
$org  = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
$step = $flow['step'];
$post = route('dhm.guest');
$loginUrl = APP_URL . '/auth/login.php?redirect=' . urlencode(APP_URL . '/migracjapoczty');
$path = ['ident' => 'Twoje dane', 'code' => 'Kod SMS', 'go' => 'Migracja'];
$cur  = $step === 'phone' ? 'code' : $step;
$order = array_keys($path);
?><!doctype html>
<html lang="pl" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Migracja poczty — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include __DIR__ . '/_metro.php'; ?>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="dm-bar">
  <div class="dm-bar__in">
    <span class="dm-bar__org"><span class="sq" aria-hidden="true"><i class="bi bi-envelope-at"></i></span><?= h($org) ?></span>
    <span class="dm-bar__sp"></span>
    <a href="<?= h($loginUrl) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Masz konto w SZO? Zaloguj się</a>
  </div>
</header>

<main id="tresc" class="pvm dm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Poczta Fundacji</div>
      <h1 class="mt-title">Migracja poczty</h1>
      <p class="mt-lead">Przeniesiesz tu swoją pocztę z Microsoft 365 do nowej skrzynki — bez logowania do SZO.
        Potwierdzimy, że to Ty, danymi z umowy z Fundacją i kodem SMS.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($step === 'ident') include __DIR__ . '/_why.php'; // pierwszy krok: na początku strony (polecenie 2026-11-13) ?>

  <ol class="dm-path" aria-label="Etapy">
    <?php foreach ($path as $k => $label):
      $done = array_search($k, $order, true) < array_search($cur, $order, true); ?>
      <li<?= $k === $cur ? ' aria-current="step"' : '' ?><?= $done ? ' class="done"' : '' ?>><?= array_search($k, $order, true) + 1 ?>. <?= h($label) ?><?= $done ? '<span class="visually-hidden"> (zrobione)</span>' : '' ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($step === 'ident'): ?>
  <section class="dm-sec" aria-labelledby="hId">
    <h2 id="hId" class="mt-h2">Twoje <b>dane</b></h2>
    <div class="mt-group">
      <form method="post" action="<?= h($post) ?>" class="js-once" novalidate>
        <?= Router::csrfField() ?><input type="hidden" name="op" value="ident">
        <label class="dm-fld" for="dmIdent">Prywatny e-mail albo PESEL</label>
        <input class="dm-inp" id="dmIdent" name="ident" type="text" required autocomplete="off" spellcheck="false" aria-describedby="dmIdentHelp">
        <p class="dm-help" id="dmIdentHelp">Ten sam, który podano w umowie z Fundacją. Prywatny e-mail — nie służbowy.</p>
        <label class="dm-fld" for="dmAddr">Twój adres w domenie feer.org.pl</label>
        <input class="dm-inp" id="dmAddr" name="address" type="email" required autocomplete="email" spellcheck="false" aria-describedby="dmAddrHelp">
        <p class="dm-help" id="dmAddrHelp">Adres, którego pocztę przenosimy, np. imie.nazwisko@feer.org.pl.</p>
        <button type="submit" class="mt-btn"><i class="bi bi-arrow-right" aria-hidden="true"></i> Dalej</button>
      </form>
    </div>
    <div class="mt-note"><i class="bi bi-shield-lock" aria-hidden="true"></i>
      <div>Dane tylko porównujemy z tymi, które Fundacja już ma — nigdzie ich nie zapisujemy. Następny krok to kod SMS na numer
        zapisany w SZO. Jeśli SZO nie ma Twojego numeru komórkowego, poprosimy Cię o niego.</div></div>
  </section>

  <?php elseif ($step === 'phone'): ?>
  <section class="dm-sec" aria-labelledby="hPh">
    <h2 id="hPh" class="mt-h2">Twój <b>numer komórkowy</b></h2>
    <div class="mt-group">
      <p>Nie mamy Twojego numeru komórkowego. Podaj go — wyślemy na niego kod, a po potwierdzeniu zapiszemy numer w SZO.
        Na ten numer przyjdzie też hasło do nowej skrzynki.</p>
      <?php if ($flow['privMask'] !== ''): ?>
        <p>Dla bezpieczeństwa drugi kod wyślemy e-mailem na Twój prywatny adres <strong><?= h($flow['privMask']) ?></strong>.</p>
      <?php endif; ?>
      <form method="post" action="<?= h($post) ?>" class="js-once">
        <?= Router::csrfField() ?><input type="hidden" name="op" value="phone">
        <label class="dm-fld" for="dmPhone">Numer komórkowy</label>
        <input class="dm-inp" id="dmPhone" name="phone" type="tel" inputmode="tel" required autocomplete="tel" aria-describedby="dmPhoneHelp">
        <p class="dm-help" id="dmPhoneHelp">9 cyfr, np. 600 100 200 (albo z +48).</p>
        <button type="submit" class="mt-btn"><i class="bi bi-send" aria-hidden="true"></i> Wyślij kod</button>
      </form>
    </div>
  </section>

  <?php elseif ($step === 'code'): ?>
  <section class="dm-sec" aria-labelledby="hCode">
    <h2 id="hCode" class="mt-h2">Kod <b>SMS</b></h2>
    <div class="mt-group">
      <p>Wysłaliśmy kod SMS na numer <strong><?= h($flow['phoneMask']) ?></strong>. Kod jest ważny 10 minut.
        <?php if ($flow['mailMask'] !== ''): ?>Drugi kod wysłaliśmy e-mailem na <strong><?= h($flow['mailMask']) ?></strong>.<?php endif; ?></p>
      <form method="post" action="<?= h($post) ?>" class="js-once">
        <?= Router::csrfField() ?><input type="hidden" name="op" value="code">
        <label class="dm-fld" for="dmCode">Kod z SMS-a</label>
        <input class="dm-inp dm-inp--code" id="dmCode" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code">
        <p class="dm-help">6 cyfr.</p>
        <?php if ($flow['mailMask'] !== ''): ?>
          <label class="dm-fld" for="dmMailCode">Kod z e-maila</label>
          <input class="dm-inp dm-inp--code" id="dmMailCode" name="mail_code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="off" aria-describedby="dmMailHelp">
          <p class="dm-help" id="dmMailHelp">6 cyfr. Nie ma e-maila? Sprawdź folder ze spamem.</p>
        <?php endif; ?>
        <button type="submit" class="mt-btn"><i class="bi bi-check2" aria-hidden="true"></i> Potwierdź</button>
      </form>
    </div>
    <div class="dm-row">
      <form method="post" action="<?= h($post) ?>"><?= Router::csrfField() ?><input type="hidden" name="op" value="resend">
        <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Wyślij kod ponownie</button></form>
      <form method="post" action="<?= h($post) ?>"><?= Router::csrfField() ?><input type="hidden" name="op" value="restart">
        <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Zacznij od nowa</button></form>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($step !== 'ident') include __DIR__ . '/_why.php'; // dalsze kroki: na dole, żeby pole kodu SMS było od razu widać ?>
</main>

<script>
document.querySelectorAll('form.js-once').forEach(function (f) {
  f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.lastChild.textContent = ' Chwileczkę…'; } });
});
</script>
</body>
</html>
