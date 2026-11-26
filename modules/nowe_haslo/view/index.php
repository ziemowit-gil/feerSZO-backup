<?php
/**
 * modules/nowe_haslo/view/index.php — /nowehaslo: samodzielna strona (bez menu SZO), wygląd METRO jak /migracjapoczty
 * (styl: modules/dhosting_migrator/view/_metro.php). Stany: wejście gościa (dane) → kod SMS + formularz zmiany;
 * zalogowany: konta, kod SMS, formularz. Konta i login pokazujemy dopiero potwierdzonej osobie (gość — po pierwszym kodzie).
 * Kod potwierdzenia: hasło do poczty — SMS na numer z konta/umowy albo e-mail na prywatny adres; logowanie jednokrotne — e-mail.
 * Zalogowany do SZO (mode 'user') zmienia hasło od razu, bez kodu — jeden formularz: które hasło, własne/losowe, hasło dwa razy.
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
$okSso  = $hasSso && (!$conf || $chSso !== null || $mode === 'user');   // zalogowany zmienia bez kodu — kanał niepotrzebny
$okMail = $hasMail && (!$conf || $chMail !== null || $mode === 'user');
$tSel = (string)($form['target'] ?? '');
if ($tSel === 'all' && ($mode !== 'user' || !$okSso || !$okMail)) $tSel = '';
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
  .pvm .nh-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,20rem),1fr));gap:.8rem}
  .pvm .nh-tile{display:flex;gap:.7rem;align-items:flex-start;padding:1rem;border:2px solid var(--pvm-line);background:#fff;cursor:pointer;min-height:44px}
  .pvm .nh-tile:has(input:checked){border-color:var(--m-blue);background:var(--pvm-light);box-shadow:inset 0 4px 0 var(--m-blue)}
  .pvm .nh-tile:has(input:focus-visible){outline:3px solid #fbbf24;outline-offset:2px}
  .pvm .nh-tile--off{cursor:default;opacity:.7}
  .pvm .nh-all{margin:1rem 0;padding:1rem;border-left:4px solid #EA8F00;background:#FFF7ED}
  .pvm .nh-all .dm-chk{display:flex;gap:.5rem;align-items:flex-start;margin:.5rem 0}
  .pvm .nh-all .dm-chk input{width:1.25rem;height:1.25rem;flex-shrink:0;margin-top:.15rem}
  /* kreator: jedna kolumna kafli, numerowane kroki, pytania jako osobne ekrany */
  .pvm form.nh-wiz{background:#fff;padding:0}
  .pvm .nh-wiz .nh-grid{grid-template-columns:1fr}
  .pvm .nh-prog{margin:0 0 .4rem;font-size:.85rem;font-weight:600;color:var(--pvm-muted);text-transform:uppercase;letter-spacing:.06em}
  .pvm .dm-path{border-bottom:0;gap:.5rem}
  .pvm .dm-path li{flex:1;display:flex;align-items:center;gap:.5rem;font-size:.95rem;font-weight:600;padding:.6rem .75rem;border-bottom:0;border-top:4px solid #D1D5DB;color:var(--pvm-muted)}
  .pvm .dm-path li[hidden]{display:none}
  .pvm .dm-path li.done{border-top-color:#1557D0;color:var(--pvm-ink)}
  .pvm .dm-path li[aria-current="step"]{border-top-color:#1E6DFF;color:var(--pvm-ink);background:#EAF1FF}
  .pvm .nh-num{display:inline-flex;width:1.6rem;height:1.6rem;align-items:center;justify-content:center;background:#1D1D1A;color:#fff;font-size:.85rem;flex-shrink:0}
  .pvm .dm-path li:not(.done):not([aria-current]) .nh-num{background:#6B7280}
  @media (max-width:599.98px){
    .pvm .dm-path li:not([aria-current]){flex:0 0 auto;font-size:0;gap:0;padding:.6rem .5rem}
    .pvm .dm-path li:not([aria-current]) .nh-num{font-size:.85rem}
  }
  .pvm .nh-step{padding:.4rem 0 0}
  .pvm .nh-step__h{font-size:1.5rem;font-weight:600;line-height:1.3;margin:0 0 .6rem}
  .pvm .nh-q{padding:1.5rem;border-left:6px solid #EA8F00;background:#FFF7ED}
  .pvm .nh-q__n{margin:0 0 .3rem;font-weight:700;color:#9A3412;text-transform:uppercase;letter-spacing:.06em;font-size:.85rem}
  .pvm .nh-q__d{font-size:1.05rem;margin:0 0 .4rem;max-width:40rem}
  .pvm .nh-all{padding:.7rem .9rem;border-left:4px solid #EA8F00;background:#FFF7ED;color:var(--pvm-ink)}
  .pvm .nh-tile input{width:1.25rem;height:1.25rem;margin-top:.2rem;flex-shrink:0;accent-color:var(--m-blue)}
  .pvm .nh-tile__b{display:flex;flex-direction:column;gap:.2rem;min-width:0}
  .pvm .nh-tile__h{font-weight:600;font-size:1.05rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
  .pvm .nh-tile__h i{color:var(--m-blue)}
  .pvm .nh-tile__l{word-break:break-all}
  .pvm .nh-tile__d{color:var(--pvm-muted);font-size:.9rem}
  .pvm .nh-badge{font-size:.72rem;font-weight:700;color:#9a3412;border:1px solid currentColor;padding:.05rem .4rem;text-transform:uppercase;letter-spacing:.03em}
  .pvm .nh-wiz{background:var(--pvm-light);padding:1.2rem}
  .pvm .nh-wiz .mt-btn{margin-top:.8rem}
  .pvm .nh-step__h{font-size:1.15rem;font-weight:600;margin:0 0 .3rem}
  .pvm .nh-step__l{margin:0 0 .6rem;word-break:break-all}
  .pvm .nh-wiz .dm-inp{max-width:26rem;box-sizing:border-box}
  .pvm .nh-rules{list-style:none;padding:0;margin:.6rem 0;display:flex;flex-wrap:wrap;gap:.35rem .9rem;font-size:.9rem}
  .pvm .nh-rules li::before{content:"○";margin-right:.35rem;color:var(--pvm-muted)}
  .pvm .nh-rules li.ok{color:#14532d}
  .pvm .nh-rules li.ok::before{content:"✓";color:#15803d;font-weight:700}
  .pvm .nh-actions{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center}
  .pvm .nh-alt{margin:1rem 0 0;font-size:.92rem}
  .pvm .nh-link{background:none;border:0;padding:0;font:inherit;color:var(--m-blue);text-decoration:underline;cursor:pointer;min-height:44px}
  .pvm .nh-done{display:flex;gap:.8rem;align-items:flex-start}
  .pvm .nh-done>i{font-size:2rem;color:#15803d;line-height:1}
  .pvm .dm-inp{box-sizing:border-box}
  .nh-dlg{max-width:min(30rem,calc(100vw - 2rem));width:100%;padding:1.6rem 1.5rem 1.3rem;border:0;border-top:8px solid #15803d;
    background:#fff;color:#111;box-shadow:0 24px 70px rgba(0,0,0,.35);text-align:center;font:inherit}
  .nh-dlg::backdrop{background:rgba(0,0,0,.55)}
  .nh-dlg__ic{width:3.5rem;height:3.5rem;margin:0 auto .7rem;border-radius:50%;background:#15803d;color:#fff;display:flex;align-items:center;justify-content:center;font-size:2rem}
  .nh-dlg__h{font-size:1.4rem;font-weight:700;margin:0 0 .6rem}
  .nh-dlg p{margin:.4rem 0;line-height:1.5}
  .nh-dlg__ok{margin-top:1rem;min-width:10rem;min-height:44px;justify-content:center}
  .nh-dlg__ok:focus-visible{outline:3px solid #c50f1f;outline-offset:3px}
  /* Wygląd jak strona logowania jednokrotnego (polecenie 2026-11-27): niebieskie tło marki, białe płaskie karty — formularz i „Ważne informacje” */
  html,body{background:#1E6DFF}
  .dm-bar{background:#1D1D1A}
  .dm-bar__in{max-width:1200px;padding-left:16px;padding-right:16px;box-sizing:border-box}
  .dm-bar__org .sq{background:#1E6DFF}
  body .pvm{--m-blue:#1E6DFF;--pvm-acc:#1E6DFF;--pvm-acc-on:#fff;--pvm-ink:#1D1D1A}
  .nh-shell{max-width:1200px;margin:32px auto 48px;padding:0 16px;display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;align-items:start;box-sizing:border-box}
  .nh-shell>.pvm.dm{max-width:none;margin:0;background:#fff;padding:40px 36px;min-width:0}
  .nh-info{background:#fff;color:#1D1D1A;padding:40px 32px;font-size:.95rem;line-height:1.6}
  .nh-info h2{font-size:1.5rem;font-weight:600;margin:0 0 1.1rem;line-height:1.25}
  .nh-info h3{font-size:.95rem;font-weight:700;margin:0 0 .2rem}
  .nh-info p{margin:0 0 1rem}
  .nh-info a{color:#1557D0;font-weight:600;text-underline-offset:3px}
  .nh-info a:hover{color:#1D1D1A}
  .nh-info a:focus-visible{outline:3px solid #EA8F00;outline-offset:2px}
  .nh-info__lead{padding:12px 14px;background:#EAF1FF;border-left:4px solid #1E6DFF}
  .nh-info__alert{padding:14px 16px;background:#F3F4F6;border-left:4px solid #EA8F00;margin:1.2rem 0 0!important}
  .nh-info__login{word-break:break-all}
  .pvm .mt-btn:focus-visible,.pvm a:focus-visible{outline:3px solid #EA8F00;outline-offset:2px}
  @media (max-width:959.98px){
    .nh-shell{grid-template-columns:1fr;margin-top:16px}
    .nh-shell>.pvm.dm,.nh-info{padding:28px 20px}
  }
  @media (prefers-reduced-motion:no-preference){.nh-dlg[open]{animation:nhPop .18s ease-out}@keyframes nhPop{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:none}}}
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

<div class="nh-shell">
<main id="tresc" class="pvm dm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Konta Fundacji</div>
      <h1 class="mt-title">Nowe hasło</h1>
      <p class="mt-lead">Tu zmienisz hasło do logowania jednokrotnego i chmury plików albo hasło do poczty.
        <?php if ($mode === 'user'): ?>Zmienisz je od razu, bez kodu. Możesz też ustawić jedno hasło do wszystkiego.
        <?php else: ?>Najpierw potwierdzimy, że to Ty: danymi z umowy z Fundacją i kodem.
        Przy haśle do poczty kod przychodzi SMS-em (a gdy nie mamy numeru, na prywatny e-mail), przy haśle logowania jednokrotnego e-mailem.<?php endif; ?></p>
    </div>
  </div>

  <div class="dm-msg dm-msg--info" role="note">
    <i class="bi bi-cone-striped" aria-hidden="true"></i>
    <div><strong>To nowy system — mogą zdarzać się błędy.</strong> Mechanizm zmiany haseł dopiero wdrażamy i nie jest jeszcze doskonały.
      Jeśli coś nie zadziała, spróbuj za kilka minut albo napisz do <a href="<?= h(APP_URL . '/helpdesk/new.php') ?>">Helpdesku IT</a> — pomożemy.</div>
  </div>
  <?php if (!empty($initial['rows'])): /* hasła startowe — NoweHasloInitial (zachęta, po GRACE_DAYS blokada panelu) */ ?>
    <div class="dm-msg dm-msg--<?= $initial['force'] ? 'danger' : 'warning' ?>" role="<?= $initial['force'] ? 'alert' : 'status' ?>">
      <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
      <div><strong>Masz hasło startowe: <?= h(implode(' oraz ', $initial['labels'])) ?>.</strong>
        <?= $initial['force']
            ? 'Zmień je teraz. Do tego czasu panel SZO jest zablokowany.'
            : 'Zmień je na własne do ' . h(NoweHasloInitial::dateLabel((int)$initial['deadline'])) . '. Potem panel SZO będzie zablokowany, dopóki go nie zmienisz.' ?></div>
    </div>
  <?php endif; ?>
  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= in_array($flash['type'], ['success', 'info'], true) ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : ($flash['type'] === 'info' ? 'bi-info-circle' : 'bi-exclamation-triangle') ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($mode !== 'user'): ?>
  <div class="mt-note" role="note" style="border-left-color:var(--m-orange);margin-bottom:1.25rem"><i class="bi bi-shield-lock" aria-hidden="true" style="color:var(--m-orange)"></i>
    <div><strong>Masz dwa hasła.</strong>
      <ol class="nh-two">
        <li><strong>Logowanie jednokrotne i chmura plików</strong> — jedno wspólne hasło.
          Od <?= h(NoweHaslo::ONE_PASSWORD_FROM) ?> tym samym loginem i tym hasłem zalogujesz się do wszystkich aplikacji Fundacji.</li>
        <li><strong>Poczta</strong> — osobne hasło. Musi być inne niż hasło logowania jednokrotnego. Sprawdzimy to przy zmianie.</li>
      </ol>
      <p style="margin:.4rem 0 0">Gdy ktoś pozna jedno hasło, nie dostanie się do drugiego konta. Hasła nigdy nie wysyłamy e-mailem.</p></div></div>
  <?php endif; ?>

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

    <?php if ($acc && $mode !== 'user'): /* zalogowany widzi loginy w kartach */ ?>
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

      <?php if ($mode === 'user'): /* zalogowany: kreator 1) które hasło 2) nowe hasło 3) gotowe — bez kodu (NoweHasloController::changePost) */
        $initSys = array_column($initial['rows'] ?? [], 'system');
        $cards = [
          'sso'  => ['icon' => 'bi-cloud-check', 'title' => 'Logowanie jednokrotne i chmura plików', 'ok' => $okSso,
                     'login' => $acc ? $acc['login'] : '', 'start' => in_array('sso', $initSys, true),
                     'desc' => 'Hasło, które wpisujesz na niebieskiej stronie logowania „Zaloguj się do usług Fundacji FEER”: SZO, chmura plików i inne aplikacje Fundacji.', 'none' => 'Nie masz jeszcze tego konta.'],
          'mail' => ['icon' => 'bi-envelope', 'title' => 'Poczta', 'ok' => $okMail,
                     'login' => $acc ? $acc['mailbox'] : '', 'start' => in_array('poczta', $initSys, true),
                     'desc' => 'Skrzynka pocztowa. Hasło musi być inne niż do logowania jednokrotnego.', 'none' => 'Nie masz skrzynki pocztowej Fundacji.'],
          // jedno hasło do wszystkiego (polecenie 2026-11-27): tylko gdy są oba konta, w kroku 2 trzy potwierdzenia
          'all'  => ['icon' => 'bi-key', 'title' => 'Jedno hasło do wszystkiego', 'ok' => $okSso && $okMail,
                     'login' => $acc ? $acc['login'] : '', 'start' => false,
                     'desc' => 'To samo hasło do poczty, SZO, logowania jednokrotnego i chmury. Wygodniej, ale mniej bezpiecznie.',
                     'none' => 'Potrzebne są konto logowania jednokrotnego i skrzynka pocztowa.'],
        ];
        $done = $flash && $flash['type'] === 'success';
        // Kreator (polecenie 2026-11-27): 1 wybór → [jedno hasło: 3 pytania c1–c3] → 2 nowe hasło → 3 gotowe. Po błędzie przy „jednym haśle”
        // pytania padają od nowa (potwierdzenia nie przechodzą przez przeładowanie strony).
        $wKey  = $done ? '3' : ($tSel === 'all' && ($form['target'] ?? '') === 'all' ? 'c1' : (($form['target'] ?? '') !== '' ? '2' : '1'));
        $wStep = (int)($wKey[0] === 'c' ? 1 : $wKey);   // zgodność: numer kroku dla ścieżki renderowanej po stronie serwera
        $doneT = (string)($form['done'] ?? '');
        $next = '';   // drugie hasło, które nadal jest startowe — propozycja po zmianie
        foreach ($cards as $t => $c) if ($c['ok'] && $c['start'] && $t !== $doneT) { $next = $t; break; }
      ?>
        <ol class="dm-path" aria-label="Etapy">
          <li data-p="1"<?= $wKey === '1' ? ' aria-current="step"' : ' class="done"' ?>><span class="nh-num">1</span> Wybierz hasło</li>
          <li data-p="c"<?= $tSel === 'all' && !$done ? '' : ' hidden' ?><?= $wKey[0] === 'c' ? ' aria-current="step"' : '' ?>><span class="nh-num">2</span> Potwierdź</li>
          <li data-p="2"<?= $wKey === '2' ? ' aria-current="step"' : ($wKey === '3' ? ' class="done"' : '') ?>><span class="nh-num">2</span> Nowe hasło</li>
          <li data-p="3"<?= $wKey === '3' ? ' aria-current="step"' : '' ?>><span class="nh-num">3</span> Gotowe</li>
        </ol>

        <form method="post" action="<?= h(route('nh.change')) ?>" id="nhWiz" class="nh-wiz" novalidate data-step="<?= h($wKey) ?>">
          <p class="nh-prog" id="nhProg" aria-live="polite"></p>
          <?= Router::csrfField() ?><input type="hidden" name="mode" value="own" id="nhMode">

          <div class="nh-step" data-s="1"<?= $wKey === '1' ? '' : ' hidden' ?>>
            <fieldset class="nh-set">
              <legend>Które hasło chcesz zmienić?</legend>
              <div class="nh-grid">
              <?php foreach ($cards as $t => $c): ?>
                <label class="nh-tile<?= $c['ok'] ? '' : ' nh-tile--off' ?>">
                  <input type="radio" name="target" value="<?= $t ?>" data-login="<?= h($c['login']) ?>" data-title="<?= h($c['title']) ?>"<?= $tSel === $t && $c['ok'] ? ' checked' : '' ?><?= $c['ok'] ? '' : ' disabled' ?>>
                  <span class="nh-tile__b">
                    <span class="nh-tile__h"><i class="bi <?= $c['icon'] ?>" aria-hidden="true"></i> <?= h($c['title']) ?>
                      <?php if ($c['start'] && $c['ok']): ?><span class="nh-badge">hasło startowe</span><?php endif; ?></span>
                    <?php if ($c['login'] !== ''): ?><span class="nh-tile__l"><?= h($c['login']) ?></span><?php endif; ?>
                    <span class="nh-tile__d"><?= h($c['ok'] ? $c['desc'] : $c['none']) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
              </div>
            </fieldset>
            <button type="button" class="mt-btn" data-go="2"><?= 'Dalej' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
          </div>

          <input type="hidden" name="all_ok1" value="" id="nhAll1"><input type="hidden" name="all_ok2" value="" id="nhAll2"><input type="hidden" name="all_ok3" value="" id="nhAll3">
          <div class="nh-step nh-q" data-s="c1"<?= $wKey === 'c1' ? '' : ' hidden' ?>>
            <p class="nh-q__n">Pytanie 1 z 3</p>
            <h3 class="nh-step__h" id="nhQ1h" tabindex="-1">Chcesz mieć jedno hasło do wszystkiego?</h3>
            <p class="nh-q__d" id="nhQ1d">To samo hasło zadziała w poczcie, SZO, logowaniu jednokrotnym i chmurze plików.</p>
            <div class="nh-actions">
              <button type="button" class="mt-btn mt-btn--ghost" data-q="no"><i class="bi bi-arrow-left" aria-hidden="true"></i> Nie, wybiorę osobne hasła</button>
              <button type="button" class="mt-btn" data-q="yes" data-n="1" aria-describedby="nhQ1d">Tak, chcę jedno hasło <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </div>
          </div>
          <div class="nh-step nh-q" data-s="c2"<?= $wKey === 'c2' ? '' : ' hidden' ?>>
            <p class="nh-q__n">Pytanie 2 z 3</p>
            <h3 class="nh-step__h" id="nhQ2h" tabindex="-1">Wiesz, czym to grozi?</h3>
            <p class="nh-q__d" id="nhQ2d">Kto pozna to hasło, dostanie się naraz do Twojej poczty, SZO i wszystkich aplikacji Fundacji. Przy osobnych hasłach wyciek jednego nie otwiera reszty.</p>
            <div class="nh-actions">
              <button type="button" class="mt-btn mt-btn--ghost" data-q="no"><i class="bi bi-arrow-left" aria-hidden="true"></i> Nie, wybiorę osobne hasła</button>
              <button type="button" class="mt-btn" data-q="yes" data-n="2" aria-describedby="nhQ2d">Tak, rozumiem <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </div>
          </div>
          <div class="nh-step nh-q" data-s="c3"<?= $wKey === 'c3' ? '' : ' hidden' ?>>
            <p class="nh-q__n">Pytanie 3 z 3</p>
            <h3 class="nh-step__h" id="nhQ3h" tabindex="-1">Na pewno?</h3>
            <p class="nh-q__d" id="nhQ3d">Nie podasz tego hasła nikomu i nie zapiszesz go tam, gdzie widzą je inni. To ostatnie pytanie.</p>
            <div class="nh-actions">
              <button type="button" class="mt-btn mt-btn--ghost" data-q="no"><i class="bi bi-arrow-left" aria-hidden="true"></i> Nie, wybiorę osobne hasła</button>
              <button type="button" class="mt-btn" data-q="yes" data-n="3" aria-describedby="nhQ3d">Tak, na pewno <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </div>
          </div>

          <div class="nh-step" data-s="2"<?= $wKey === '2' ? '' : ' hidden' ?>>
            <h3 class="nh-step__h" id="nhS2h" tabindex="-1">Nowe hasło: <span id="nhTitle"></span></h3>
            <p class="nh-step__l">Login: <strong id="nhLogin"></strong></p>
            <input type="text" name="username" id="nhUser" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true">
            <label class="dm-fld" for="nhPw">Nowe hasło</label>
            <input class="dm-inp" id="nhPw" name="password" type="password" autocomplete="new-password" maxlength="<?= NoweHaslo::MAX_LEN ?>" aria-describedby="nhRules">
            <label class="dm-fld" for="nhPw2">Powtórz nowe hasło</label>
            <input class="dm-inp" id="nhPw2" name="password2" type="password" autocomplete="new-password" maxlength="<?= NoweHaslo::MAX_LEN ?>">
            <label class="dm-chk"><input type="checkbox" id="nhShow"> <span>Pokaż hasło</span></label>
            <ul class="nh-rules" id="nhRules" aria-live="polite">
              <li data-r="len">co najmniej <?= NoweHaslo::MIN_LEN ?> znaków</li>
              <li data-r="low">mała litera</li>
              <li data-r="up">wielka litera</li>
              <li data-r="dig">cyfra</li>
              <li data-r="eq">oba pola takie same</li>
            </ul>
            <p class="dm-help" id="nhHelpSep">Bez imienia, nazwiska i loginu. Hasło do poczty musi być inne niż hasło logowania jednokrotnego — sprawdzimy to.</p>
            <p class="dm-help nh-all" id="nhAllNote" hidden><i class="bi bi-key" aria-hidden="true"></i> Potwierdziłeś(-aś) trzy razy. To hasło zadziała w poczcie, SZO, logowaniu jednokrotnym i chmurze plików. Bez imienia, nazwiska i loginu.</p>
            <div class="nh-actions">
              <button type="button" class="mt-btn mt-btn--ghost" data-go="1"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wstecz</button>
              <button type="submit" class="mt-btn" id="nhSubmit"><i class="bi bi-check2" aria-hidden="true"></i> Zmień hasło</button>
            </div>
            <?php if (!$noRandom): ?>
              <p class="nh-alt">Nie chcesz wymyślać? <button type="submit" class="nh-link" id="nhRandom">Wyślij mi losowe hasło SMS-em</button></p>
            <?php endif; ?>
          </div>

          <div class="nh-step" data-s="3"<?= $wKey === '3' ? '' : ' hidden' ?>>
            <div class="nh-done"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>
              <div><h3 class="nh-step__h">Gotowe</h3>
                <p>Dostaniesz SMS i e-mail z potwierdzeniem. Jeśli telefon albo program pamięta stare hasło, wpisz w nim nowe.</p></div></div>
            <div class="nh-actions">
              <?php if ($next !== ''): ?>
                <button type="button" class="mt-btn" data-go="2" data-pick="<?= $next ?>">Zmień teraz: <?= h(mb_strtolower($cards[$next]['title'])) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
              <?php endif; ?>
              <a class="mt-btn <?= $next !== '' ? 'mt-btn--ghost' : '' ?>" href="<?= h(APP_URL . '/') ?>"><i class="bi bi-house" aria-hidden="true"></i> Wróć do SZO</a>
            </div>
          </div>
        </form>
      <?php elseif ($code === null): ?>
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

<aside class="nh-info" aria-labelledby="nhInfoH">
  <h2 id="nhInfoH">Ważne informacje</h2>
  <p class="nh-info__lead">Hasło logowania jednokrotnego wpisujesz na niebieskiej stronie „Zaloguj się do usług Fundacji FEER”. Tym jednym loginem i hasłem wejdziesz do SZO, chmury plików i innych aplikacji Fundacji.</p>
  <h3>Login</h3>
  <p><?php if ($acc && $mode === 'user'): ?><strong class="nh-info__login"><?= h($acc['login']) ?></strong>.
    <?php endif; ?>Twój adres e-mail w Fundacji. Zadziała też login z kreską dolną zamiast kropki, na przykład <code>jan_kowalski</code>.</p>
  <h3>Poczta</h3>
  <p>Hasło do poczty jest osobne<?= $mode === 'user' ? ', chyba że wybierzesz „Jedno hasło do wszystkiego”' : '' ?>.</p>
  <h3>Dobre hasło</h3>
  <p>Co najmniej <?= NoweHaslo::MIN_LEN ?> znaków, małe i wielkie litery oraz cyfra. Bez imienia, nazwiska i loginu.</p>
  <h3>Pomoc</h3>
  <p>Coś nie działa? Napisz do <a href="<?= h(APP_URL . '/helpdesk/new.php') ?>">Helpdesku IT</a>.</p>
  <p class="nh-info__alert">Nikt z Fundacji nie poprosi Cię o hasło. Nie podawaj go w e-mailu ani przez telefon.</p>
</aside>
</div>

<?php if (!empty($changed)): /* okno po zmianie hasła: natywny <dialog> (showModal = pułapka fokusu, Esc, tło inert), role=alertdialog */ ?>
<dialog id="nhDone" class="nh-dlg" role="alertdialog" aria-modal="true" aria-labelledby="nhDoneH" aria-describedby="nhDoneD">
  <div class="nh-dlg__ic" aria-hidden="true"><i class="bi bi-check-lg"></i></div>
  <h2 id="nhDoneH" class="nh-dlg__h">Hasło zmienione</h2>
  <div id="nhDoneD">
    <p><?= h((string)$changed) ?></p>
    <p>Masz otwartą pocztę na dpoczta.pl? Wyloguj się i zaloguj nowym hasłem — inaczej zobaczysz błąd „AUTHENTICATE PLAIN”.</p>
    <p>Jeśli telefon, Outlook albo aplikacja chmury pamięta stare hasło, wpisz w nich nowe.</p>
  </div>
  <form method="dialog"><button type="submit" class="mt-btn nh-dlg__ok" autofocus>OK, rozumiem</button></form>
</dialog>
<?php endif; ?>

<script>
(function () {
  document.querySelectorAll('form.js-once').forEach(function (f) {
    f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.lastChild.textContent = ' Chwileczkę…'; } });
  });
  var dlg = document.getElementById('nhDone');
  if (dlg && typeof dlg.showModal === 'function') {
    dlg.showModal();
    dlg.addEventListener('close', function () {
      var t = document.querySelector('.nh-step[data-s="3"]:not([hidden]) .nh-actions .mt-btn') || document.getElementById('tresc');
      if (t) { if (!t.hasAttribute('tabindex') && t.tagName === 'MAIN') t.setAttribute('tabindex', '-1'); t.focus(); }
    });
  } else if (dlg) { dlg.setAttribute('open', ''); }
  var wiz = document.getElementById('nhWiz');
  if (wiz) {
    var steps = wiz.querySelectorAll('.nh-step'), path = document.querySelectorAll('.dm-path li[data-p]');
    var pw = document.getElementById('nhPw'), pw2 = document.getElementById('nhPw2'), mode = document.getElementById('nhMode');
    function picked() { return wiz.querySelector('input[name=target]:checked'); }
    // Kreator: kolejność ekranów zależy od wyboru — „jedno hasło” ma trzy pytania (c1–c3) przed nowym hasłem.
    function order() { var r = picked(); return r && r.value === 'all' ? ['1', 'c1', 'c2', 'c3', '2', '3'] : ['1', '2', '3']; }
    function pathKey(k) { return k.charAt(0) === 'c' ? 'c' : k; }
    function go(k) {
      k = String(k);
      var r = picked();
      if (k !== '1' && k !== '3' && !r) { k = '1'; }
      var all = !!r && r.value === 'all';
      if (k === '2' && all && !['nhAll1', 'nhAll2', 'nhAll3'].every(function (id) { return document.getElementById(id).value === '1'; })) k = 'c1';
      steps.forEach(function (s) { s.hidden = s.dataset.s !== k; });
      var pk = pathKey(k), seq = [], seen = {};
      order().forEach(function (x) { var p = pathKey(x); if (!seen[p]) { seen[p] = 1; seq.push(p); } });
      var cur = seq.indexOf(pk), num = 0;
      path.forEach(function (li) {
        var i = seq.indexOf(li.dataset.p);
        li.hidden = i < 0;
        if (i < 0) return;
        li.querySelector('.nh-num').textContent = String(++num);
        li.classList.toggle('done', i < cur);
        if (i === cur) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
      });
      var labels = { '1': 'Wybierz hasło', 'c': 'Potwierdź', '2': 'Nowe hasło', '3': 'Gotowe' };
      document.getElementById('nhProg').textContent = 'Krok ' + (cur + 1) + ' z ' + seq.length + ': ' + labels[pk]
        + (k.charAt(0) === 'c' ? ' (pytanie ' + k.charAt(1) + ' z 3)' : '');
      if (k.charAt(0) === 'c') { var h = document.getElementById('nhQ' + k.charAt(1) + 'h'); if (h) h.focus(); }
      if (k === '2') {
        document.getElementById('nhTitle').textContent = r.dataset.title;
        document.getElementById('nhLogin').textContent = r.dataset.login;
        document.getElementById('nhUser').value = r.dataset.login;
        document.getElementById('nhAllNote').hidden = !all;
        document.getElementById('nhHelpSep').hidden = all;
        pw.focus();
      }
    }
    function resetAll() { ['nhAll1', 'nhAll2', 'nhAll3'].forEach(function (id) { document.getElementById(id).value = ''; }); }
    wiz.querySelectorAll('[data-go]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.dataset.pick) { var r = wiz.querySelector('input[name=target][value=' + b.dataset.pick + ']'); if (r) r.checked = true; pw.value = pw2.value = ''; rules(); }
        if (b.dataset.go === '2' && !picked()) { wiz.querySelector('input[name=target]:not(:disabled)').focus(); return; }
        go(b.dataset.go);
      });
    });
    // Pytania przy „jednym haśle”: „Tak” zapisuje potwierdzenie i idzie dalej, „Nie” kasuje wszystkie i wraca do wyboru
    wiz.querySelectorAll('[data-q]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.dataset.q === 'no') {
          resetAll();
          var sso = wiz.querySelector('input[name=target][value=sso]:not(:disabled)'); if (sso) sso.checked = true;
          go('1'); var c = picked(); if (c) c.focus();
          return;
        }
        var n = +b.dataset.n;
        document.getElementById('nhAll' + n).value = '1';
        go(n < 3 ? 'c' + (n + 1) : '2');
      });
    });
    wiz.querySelectorAll('input[name=target]').forEach(function (r) {
      r.addEventListener('change', function () { resetAll(); go('1'); });
      r.addEventListener('dblclick', function () { go('2'); });
    });
    function rules() {
      var v = pw.value, ok = { len: v.length >= <?= NoweHaslo::MIN_LEN ?>, low: /\p{Ll}/u.test(v), up: /\p{Lu}/u.test(v), dig: /\d/.test(v), eq: v !== '' && v === pw2.value };
      wiz.querySelectorAll('.nh-rules li').forEach(function (li) { li.classList.toggle('ok', !!ok[li.dataset.r]); });
    }
    pw.addEventListener('input', rules); pw2.addEventListener('input', rules);
    document.getElementById('nhShow').addEventListener('change', function (e) { pw.type = pw2.type = e.target.checked ? 'text' : 'password'; });
    var rnd = document.getElementById('nhRandom');
    if (rnd) rnd.addEventListener('click', function () { mode.value = 'random'; });
    document.getElementById('nhSubmit').addEventListener('click', function () { mode.value = 'own'; });
    wiz.addEventListener('submit', function (e) {
      var r = picked();
      // „jedno hasło”: bez trzech „Tak” wracamy do pytań (serwer sprawdza je też)
      if (r && r.value === 'all' && !['nhAll1', 'nhAll2', 'nhAll3'].every(function (id) { return document.getElementById(id).value === '1'; })) {
        e.preventDefault(); go('c1'); return;
      }
      var b = document.getElementById('nhSubmit');
      setTimeout(function () { b.disabled = true; if (rnd) rnd.disabled = true; b.lastChild.textContent = ' Chwileczkę…'; }, 0);
    });
    go(wiz.dataset.step || '1');
  }
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
