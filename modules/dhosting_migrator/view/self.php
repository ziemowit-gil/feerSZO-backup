<?php
/**
 * modules/dhosting_migrator/view/self.php — /migracjapoczty: SAMODZIELNA strona (bez nagłówka, menu i stopki SZO —
 * polecenie 2026-11-13), wygląd METRO (jak Panel współpracownika). Wejście z panelu współpracownika albo bez logowania
 * (DhostingGuest: prywatny e-mail/PESEL + adres służbowy + kod SMS; wtedy w pasku „Zakończ” zamiast linków SZO).
 * Użytkownik zakłada nową skrzynkę z loginem Microsoft albo dostaje nowe hasło (tylko SMS-em) i kopiuje pocztę
 * z Microsoft 365 (w tle, z postępem). Bez numeru komórkowego — formularz numeru (kod SMS → zapis w SZO).
 * Konto zmigrowane (kopia zakończona) — akcje zablokowane, chyba że to administrator ($st['locked']).
 * Zmienne: $st (DhostingMigrator::selfState), $flash, $web, $me, $guest (bool), $phoneFlow (?array).
 */
$org     = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
$locked  = !empty($st['locked']);
$canAct  = $st['problem'] === '' && $st['wait'] === 0 && !$locked;
$exists  = $st['exists'] === true;
$job     = $st['copy'];
$jobActive = $job && in_array($job['status'], DhostingCopy::ACTIVE, true);
// Serwer nowej poczty odrzucił zapisane hasło (DhostingCopy::runJob → need_password): prosimy o aktualne, bez auto-odświeżania
$needPw  = $jobActive && !empty($job['need_password']);
$pct     = $job ? DhostingCopy::percent($job) : 0;
$copyOk  = $st['copyAvailable'] && $st['mailbox'] && DhostingConfig::selfEnabled();
$swTs    = DhostingConfig::switchDate();
$swTxt   = $swTs ? date('d.m.Y', $swTs) : '';
$swPast  = $swTs && time() >= $swTs;
[$imapHost, $imapPort] = DhostingConfig::imapForUsers();
$domain  = $st['mailbox'] ? substr((string)$st['mailbox'], strrpos((string)$st['mailbox'], '@') + 1) : 'feer.org.pl';
$hdUrl   = !empty($st['ticket']) ? DhostingHelpdesk::trackUrl($st['ticket']) : '';
$phonePost = route('dhm.phone');
// Stan kroków: done | doing | todo | info (stan zawsze tekstem obok koloru)
$steps = [];
$steps[] = $st['mobile']
    ? ['done', 'Numer komórkowy', 'Gotowe. Hasło przyjdzie na ' . $st['phoneMasked'] . '.']
    : ['todo', 'Numer komórkowy', 'Podaj numer poniżej. Na niego przyjdzie hasło.'];
$steps[] = $exists
    ? ['done', 'Nowa skrzynka', 'Gotowe: ' . $st['mailbox'] . '.']
    : ($st['exists'] === false
        ? ['todo', 'Nowa skrzynka', 'Kliknij niżej „Załóż skrzynkę i wyślij hasło SMS-em”.']
        : ['info', 'Nowa skrzynka', 'Nie udało się teraz sprawdzić. Zobacz komunikat niżej.']);
if ($job && $job['status'] === 'done') $steps[] = ['done', 'Kopia poczty', 'Gotowe: ' . (int)$job['copied_items'] . ' wiadomości.'];
elseif ($needPw) $steps[] = ['todo', 'Kopia poczty', 'Wstrzymana. ' . ($locked ? 'Pomoże administrator.' : 'Podaj niżej aktualne hasło do skrzynki.')];
elseif ($job && $job['status'] === 'waiting') $steps[] = ['doing', 'Kopia poczty', 'Czeka. Spróbujemy ponownie ' . substr((string)$job['next_run_at'], 0, 16) . '.'];
elseif ($jobActive) $steps[] = ['doing', 'Kopia poczty', 'W toku: ' . $pct . '%. Nic nie musisz robić.'];
else $steps[] = ['todo', 'Kopia poczty', $exists ? 'Podaj niżej hasło do skrzynki.' : 'Zaznacz „Skopiuj też moją pocztę” przy zakładaniu skrzynki.'];
$steps[] = ['info', 'Telefon i program', ($swTxt !== '' ? ($swPast ? 'Od ' . $swTxt : 'Od ' . $swTxt . ', nie wcześniej,') : 'Po przełączeniu') . ' wpisz adres e-mail i nowe hasło.'];
$stLabel = ['done' => 'gotowe', 'doing' => 'w toku', 'todo' => 'do zrobienia', 'info' => 'informacja'];
$stIcon  = ['done' => 'bi-check-circle-fill', 'doing' => 'bi-hourglass-split', 'todo' => 'bi-circle', 'info' => 'bi-gear'];
?><!doctype html>
<html lang="pl" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<?php if ($jobActive && !$needPw): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
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
    <span class="dm-bar__who"><span class="visually-hidden"><?= $guest ? 'Potwierdzono jako' : 'Zalogowano jako' ?> </span><?= h((string)($me['name'] ?? $me['email'] ?? '')) ?></span>
    <?php if ($guest): ?>
      <form method="post" action="<?= h(route('dhm.guestEnd')) ?>"><?= Router::csrfField() ?>
        <button type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Zakończ</button></form>
    <?php else: ?>
      <a href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel współpracownika</a>
      <a href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Wyloguj</a>
    <?php endif; ?>
  </div>
</header>

<main id="tresc" class="pvm dm">
  <?php
  // 2026-11-14 (polecenia „po migracji duży ptaszek i nie za dużo informacji”, „w formie zakładek”, „uprość tę stronę tekstowo zależnie od etapu”):
  // u góry tylko to, co trzeba zrobić na danym etapie; reszta (program pocztowy, hasła, logowanie jednokrotne, pomoc) w zakładkach na dole.
  // Zakładki: bez JS zwykłe sekcje z nagłówkami; z JS wzorzec ARIA „tabs” (strzałki, Home, End), patrz skrypt na dole.
  $migrated = !empty($st['migrated']);
  $mj = $st['copy'] ?? null;
  $reg = $st['registry'] ?? [];
  $inf = $st['info'] ?? [];
  $byAdmin = ($mj['via'] ?? 'admin') !== 'self';
  $loginUrl = $web !== '' ? $web : DhostingConfig::DEFAULT_WEBMAIL;
  $loginHost = (string)(parse_url($loginUrl, PHP_URL_HOST) ?: $loginUrl);
  $smsTo = ($inf['phoneMasked'] ?? '') !== '' ? (string)$inf['phoneMasked'] : (string)($reg['sms_to'] ?? '');
  $helpLink = $hdUrl !== '' ? 'napisz w <a href="' . h($hdUrl) . '">swoim zgłoszeniu ' . h((string)($st['ticket']['number'] ?? '')) . '</a>'
            : ($guest ? 'napisz do Helpdesku IT' : 'napisz w <a href="' . APP_URL . '/panel/helpdesk.php">Helpdesku IT</a>');
  ?>
  <?php if ($migrated): ?>
  <section class="dm-done" aria-labelledby="hDone">
    <div class="dm-ok" aria-hidden="true"><i class="bi bi-check-lg"></i></div>
    <div class="dm-done__bd">
      <div class="mt-kicker">Migracja poczty</div>
      <h1 id="hDone" class="dm-done__t">Gotowe. Twoja poczta jest w nowej skrzynce.</h1>
      <p class="dm-done__when"><?= $byAdmin ? 'Przeniósł ją administrator. ' : '' ?><?= !empty($mj['finished_at']) ? 'Zakończono ' . h(date('d.m.Y, H:i', strtotime((string)$mj['finished_at']))) . '.' : '' ?></p>
    </div>
  </section>
  <?php else: ?>
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Poczta Fundacji</div>
      <h1 class="mt-title">Migracja poczty</h1>
      <p class="mt-lead">Zakładasz nową skrzynkę z tym samym adresem e-mail. Hasło przyjdzie SMS-em, a poczta z Microsoft 365 przeniesie się sama.</p>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

  <?php ob_start(); if ($copyOk): ?>
  <section class="dm-sec" aria-labelledby="hCopy">
    <h2 id="hCopy" class="mt-h2">Kopiowanie <b>poczty z Microsoft 365</b></h2>
    <div class="mt-group">
      <?php if ($job): ?>
        <?php $lab = DhostingCopy::LABELS[$job['status']] ?? $job['status'];
              $bcls = ['done' => 'mt-badge--ok', 'error' => '', 'cancelled' => '', 'waiting' => 'mt-badge--warn'][$job['status']] ?? 'mt-badge--info'; ?>
        <p>Stan: <span class="mt-badge <?= $bcls ?>"><?= h($lab) ?></span></p>
        <div class="mt-progress<?= $job['status'] === 'done' ? ' mt-progress--done' : '' ?>" role="progressbar" aria-label="Postęp kopiowania poczty" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pct ?>%"></span></div>
        <div class="mt-progress-lbl"><span>Skopiowano <?= (int)$job['copied_items'] ?> z <?= (int)$job['total_items'] ?> wiadomości<?= (int)$job['failed_items'] ? ' · nie udało się: ' . (int)$job['failed_items'] : '' ?></span><span><?= $pct ?>%</span></div>
        <?php if ($jobActive && !$needPw): ?><p class="dm-help">Strona odświeża się sama co minutę.</p><?php endif; ?>
        <?php $failed = DhostingCopy::failedFor((string)$job['mailbox']); if ($failed): ?>
          <details class="dm-help" style="margin-top:.6rem"><summary>Wiadomości przeniesione niecałkowicie albo wcale (<?= count($failed) ?>)</summary>
            <p style="margin:.4rem 0">Oryginały zostały w starej skrzynce Microsoft 365. Jeśli któraś jest Ci potrzebna w całości, otwórz ją tam i prześlij do siebie albo napisz do administratora.</p>
            <ul style="margin:0;padding-left:1.2rem">
              <?php foreach ($failed as $fr): ?>
              <li><?= h($fr['received_at'] ? date('d.m.Y', strtotime((string)$fr['received_at'])) . ' · ' : '') ?><?= h((string)($fr['folder'] ?: 'folder nieznany')) ?> · <?= h((string)($fr['subject'] ?? '') !== '' ? (string)$fr['subject'] : '(bez tematu)') ?>
                <span>, <?= (int)$fr['result'] !== 3 ? 'nie przeniesiona' : (str_contains((string)$fr['reason'], 'bez załączników') ? 'przeniesiona bez załączników' : 'przeniesiona bez pełnej treści') ?></span></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
        <?php if ($needPw): ?>
          <div class="dm-msg dm-msg--warning" role="alert" style="background:var(--pvm-bg)"><i class="bi bi-key" aria-hidden="true"></i><div><strong>Nowa skrzynka nie przyjęła hasła.</strong>
            Hasło, z którym zaczęliśmy kopiowanie, już nie pasuje. Może zostało zmienione po założeniu skrzynki.
            <?php if ($locked): ?>Twoja poczta jest już przeniesiona, więc dalej pomoże administrator: <?php if ($hdUrl !== ''): ?>napisz w <a href="<?= h($hdUrl) ?>">swoim zgłoszeniu</a><?php else: ?>napisz do Helpdesku IT<?php endif; ?>, a dosyłanie wiadomości ruszy z nowym hasłem.
            <?php else: ?>Podaj poniżej aktualne hasło do nowej skrzynki, a kopiowanie ruszy od miejsca, w którym stanęło. Nie pamiętasz go? Poproś wyżej o nowe hasło SMS-em, a kopiowanie samo je przejmie.<?php endif; ?></div></div>
        <?php elseif ($job['status'] === 'waiting'): ?>
          <div class="dm-msg dm-msg--warning" role="status" style="background:var(--pvm-bg)"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div>Serwer był niedostępny. Spróbujemy ponownie <?= h(substr((string)$job['next_run_at'], 0, 16)) ?> (co <?= DhostingCopy::RETRY_HOURS ?> h). <?= $job['last_error'] ? h(mb_substr((string)$job['last_error'], 0, 200)) : '' ?></div></div>
        <?php elseif ($job['status'] === 'error' && $job['last_error']): ?>
          <div class="dm-msg dm-msg--danger" role="alert" style="background:var(--pvm-bg)"><i class="bi bi-x-octagon" aria-hidden="true"></i><div><?= h(mb_substr((string)$job['last_error'], 0, 300)) ?></div></div>
        <?php elseif ($job['status'] === 'done'): ?>
          <?php $doneWeb = $web !== '' ? $web : DhostingConfig::DEFAULT_WEBMAIL; // po zakończonym kopiowaniu — odnośnik do poczty w przeglądarce (polecenie 2026-11-13) ?>
          <p class="dm-help">Zakończono <?= h(substr((string)$job['finished_at'], 0, 16)) ?>. Foldery z Outlooka są w nowej skrzynce pod tymi samymi nazwami.</p>
          <p><a class="mt-btn" href="<?= h($doneWeb) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Otwórz pocztę: <?= h((string)(parse_url($doneWeb, PHP_URL_HOST) ?: $doneWeb)) ?><span class="visually-hidden"> (nowa karta)</span></a></p>
        <?php endif; ?>
        <?php if ($jobActive): ?>
        <form method="post" action="<?= h(route('dhm.selfCopy')) ?>" onsubmit="return confirm('Anulować kopiowanie poczty?')">
          <?= Router::csrfField() ?><input type="hidden" name="op" value="cancel">
          <button class="mt-btn mt-btn--danger" type="submit"><i class="bi bi-x-circle" aria-hidden="true"></i> Anuluj kopiowanie</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>

      <?php $sync = $st['sync'] ?? null; if ($sync && ($sync['active'] || $sync['last'])): ?>
        <p class="dm-help" style="margin-top:.6rem"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>
          <?php if ($sync['active']): ?>Nowe wiadomości, które jeszcze wpadną do Microsoft 365, dosyłamy automatycznie co <?= DhostingCopy::SYNC_HOURS ?> h, do <?= h(substr($sync['until'], 0, 16)) ?>.
            Dopisujemy tylko brakujące, a tego, co już jest w nowej skrzynce, nie zmieniamy.<?php endif; ?>
          <?php if ($sync['last']): ?>Ostatnio <?= h(substr($sync['last']['at'], 0, 16)) ?>: dosłano <?= (int)$sync['last']['added'] ?>.<?php endif; ?></p>
      <?php endif; ?>

      <?php // po migracji ponowne kopiowanie tylko przez administratora (/migracjaadmin) — formularz zostaje jedynie, gdy trwające dosyłanie czeka na aktualne hasło (polecenie 2026-11-13)
            if (($needPw || ($exists && !$jobActive && empty($st['migrated']))) && !$locked): ?>
        <?php if ($needPw): ?>
        <?php elseif (!$job): ?><p>Masz już skrzynkę i hasło z SMS-a? Podaj je, a skopiujemy pocztę z Microsoft 365. Wiadomości już przeniesione nie zdublują się.</p>
        <?php else: ?><p>Chcesz skopiować pocztę jeszcze raz (np. nowe wiadomości)? Podaj hasło do nowej skrzynki.</p><?php endif; ?>
        <form method="post" action="<?= h(route('dhm.selfCopy')) ?>" class="js-once">
          <?= Router::csrfField() ?><input type="hidden" name="op" value="start">
          <label class="dm-fld" for="mpw">Hasło do nowej skrzynki</label>
          <input class="dm-inp" type="password" id="mpw" name="mail_password" autocomplete="current-password" required aria-describedby="mpwHelp"<?= $needPw ? ' aria-invalid="true"' : '' ?>>
          <p class="dm-help" id="mpwHelp">To hasło z SMS-a albo Twoje własne, jeśli było zmieniane. Przechowujemy je zaszyfrowane tylko do końca kopiowania.</p>
          <button class="mt-btn mt-btn--ghost" type="submit"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> <?= $needPw ? 'Wznów kopiowanie' : 'Skopiuj pocztę' ?></button>
        </form>
      <?php elseif (!$exists && !$job && !$locked): ?>
        <p>Najpierw załóż skrzynkę. Kopiowanie zaznaczysz przy zakładaniu.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php $copyHtml = trim((string)ob_get_clean()); ?>

  <?php if ($migrated): ?>
    <?php if (($inf['active'] ?? null) === false): ?>
      <div class="dm-msg dm-msg--danger" role="alert"><i class="bi bi-lock" aria-hidden="true"></i><div><strong>Skrzynka jest teraz zablokowana.</strong> <?= ucfirst($helpLink) ?>.</div></div>
    <?php endif; ?>
    <div class="mt-grid dm-logins" role="list" aria-label="Dane do logowania">
      <div class="mt-tile dm-static" role="listitem" style="--c:var(--m-blue)">
        <i class="bi bi-person ic" aria-hidden="true"></i>
        <div><span class="val"><?= h((string)($st['mailbox'] ?? '')) ?></span><span class="lbl">Login, czyli Twój adres e-mail</span></div>
      </div>
      <div class="mt-tile dm-static" role="listitem" style="--c:var(--m-indigo)">
        <i class="bi bi-phone ic" aria-hidden="true"></i>
        <div><span class="val">Hasło z SMS-a</span><span class="lbl"><?= $smsTo !== '' ? 'wysłane na numer ' . h($smsTo) : 'albo od administratora' ?><?= !empty($reg['sms_at']) ? ', ' . h(date('d.m.Y', strtotime((string)$reg['sms_at']))) : '' ?></span></div>
      </div>
      <a class="mt-tile" role="listitem" style="--c:var(--m-green)" href="<?= h($loginUrl) ?>" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right ic" aria-hidden="true"></i>
        <div><span class="val"><?= h($loginHost) ?></span><span class="lbl">Otwórz pocztę w przeglądarce<span class="visually-hidden"> (nowa karta)</span></span></div>
      </a>
    </div>
    <p class="dm-done__tip">W telefonie i programie pocztowym wpisz tylko adres e-mail i hasło, tak jak w Microsoft 365. Resztę ustawień program znajdzie sam.</p>
    <?php if (!$locked): ?>
      <div class="dm-msg dm-msg--info" role="status"><i class="bi bi-shield-check" aria-hidden="true"></i>
        <div>Konto jest już zmigrowane. Jako administrator możesz ustawić nowe hasło niżej, a ponowne kopiowanie uruchomisz na <a href="<?= h(rtrim(APP_URL, '/')) ?>/migracjaadmin#kopiowanie">/migracjaadmin</a>.</div></div>
    <?php endif; ?>
    <?php if ($needPw) echo $copyHtml; // dosyłanie czeka na aktualne hasło: to wymaga działania, więc na wierzchu ?>
  <?php else: ?>
  <div class="mt-imp">
    <div class="mt-imp__item" style="--c:var(--m-blue)">
      <h2 class="mt-imp__ttl">Co się zmienia</h2>
      <p class="mt-imp__body"><?php if ($swTxt !== ''): ?><strong>Od <?= h($swTxt) ?></strong> poczta @<?= h($domain) ?> <?= $swPast ? 'działa' : 'będzie działać' ?><?php else: ?>Wkrótce poczta @<?= h($domain) ?> będzie działać<?php endif; ?> u nowego dostawcy.
        Adres e-mail się nie zmienia. Zrób kroki poniżej<?= $swTxt !== '' && !$swPast ? ' przed ' . h($swTxt) : '' ?>, zajmie to około 5 minut.</p>
    </div>
  </div>

  <section class="dm-sec" aria-labelledby="hSteps">
    <h2 id="hSteps" class="mt-h2">Twoje <b>kroki</b></h2>
    <ol class="dm-steps">
      <?php foreach ($steps as $i => [$state, $title, $desc]): ?>
      <li class="mt-svc dm-st--<?= $state ?>">
        <i class="bi <?= $stIcon[$state] ?> ic" aria-hidden="true"></i>
        <div class="mt-svc__bd"><h3><?= $i + 1 ?>. <?= h($title) ?> <span class="dm-state"><?= $stLabel[$state] ?></span></h3><p><?= h($desc) ?></p></div>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <?php if ($st['problem'] !== '' && !$locked && !(!empty($st['needPhone']) && !$guest)): // przy braku numeru wystarczy sekcja z formularzem ?>
    <div class="dm-msg dm-msg--warning" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><?= h($st['problem']) ?></div></div>
  <?php elseif ($st['wait'] > 0 && !$locked): ?>
    <div class="dm-msg dm-msg--info" role="status"><i class="bi bi-clock" aria-hidden="true"></i><div>Kolejną prośbę o hasło możesz wysłać za <?= (int)ceil($st['wait'] / 60) ?> min.</div></div>
  <?php endif; ?>

  <?php if (!empty($st['needPhone']) && !$guest && !$locked): ?>
  <section class="dm-sec" aria-labelledby="hPhone">
    <h2 id="hPhone" class="mt-h2">Dodaj <b>numer komórkowy</b></h2>
    <div class="mt-group">
      <?php if ($phoneFlow): ?>
        <p>Wysłaliśmy kod SMS na numer <strong><?= h($phoneFlow['phoneMask']) ?></strong>. Wpisz go, a zapiszemy numer w SZO.</p>
        <form method="post" action="<?= h($phonePost) ?>" class="js-once">
          <?= Router::csrfField() ?><input type="hidden" name="op" value="verify">
          <label class="dm-fld" for="dmPCode">Kod z SMS-a</label>
          <input class="dm-inp dm-inp--code" id="dmPCode" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code">
          <p class="dm-help">6 cyfr, ważny 10 minut.</p>
          <button type="submit" class="mt-btn"><i class="bi bi-check2" aria-hidden="true"></i> Potwierdź numer</button>
        </form>
        <form method="post" action="<?= h($phonePost) ?>" style="margin-top:.6rem"><?= Router::csrfField() ?><input type="hidden" name="op" value="cancel">
          <button type="submit" class="mt-btn mt-btn--ghost">Wpisz inny numer</button></form>
      <?php else: ?>
        <p>Hasło do nowej skrzynki przyjdzie SMS-em. Podaj numer komórkowy, a wyślemy na niego kod do potwierdzenia.</p>
        <form method="post" action="<?= h($phonePost) ?>" class="js-once">
          <?= Router::csrfField() ?><input type="hidden" name="op" value="send">
          <label class="dm-fld" for="dmPNum">Numer komórkowy</label>
          <input class="dm-inp" id="dmPNum" name="phone" type="tel" inputmode="tel" required autocomplete="tel" aria-describedby="dmPNumHelp">
          <p class="dm-help" id="dmPNumHelp">9 cyfr, np. 600 100 200 (albo z +48).</p>
          <button type="submit" class="mt-btn"><i class="bi bi-send" aria-hidden="true"></i> Wyślij kod</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($canAct): ?>
  <section class="dm-sec" aria-labelledby="hPass">
    <h2 id="hPass" class="mt-h2"><?= $exists ? 'Nowe <b>hasło do skrzynki</b>' : 'Załóż <b>skrzynkę</b>' ?></h2>
    <div class="mt-group">
      <div class="mt-note" role="note" style="background:var(--pvm-bg);border-left-color:var(--m-orange)"><i class="bi bi-exclamation-diamond" aria-hidden="true" style="color:var(--m-orange)"></i>
        <div>Hasło przyjdzie <strong>tylko SMS-em</strong> na numer <?= h($st['phoneMasked']) ?>.
        <?php if ($exists): ?>Zastąpi obecne, więc telefon i program poproszą o nie ponownie.<?php endif; ?>
        Hasło do Microsoft 365 się nie zmienia.</div>
      </div>
      <form method="post" action="<?= h(route('dhm.selfPost')) ?>" class="js-once">
        <?= Router::csrfField() ?>
        <div class="dm-chk">
          <input type="checkbox" name="confirm" value="1" id="cfm" required>
          <label for="cfm">Numer <?= h($st['phoneMasked']) ?> jest mój i mam przy sobie ten telefon.</label>
        </div>
        <?php if ($copyOk && !$jobActive): ?>
        <div class="dm-chk">
          <input type="checkbox" name="copy_mail" value="1" id="cpy" checked>
          <label for="cpy">Skopiuj też moją pocztę z Microsoft 365 (w tle; potrwa od kilku minut do kilku godzin)</label>
        </div>
        <?php endif; ?>
        <button type="submit" class="mt-btn"><i class="bi <?= $exists ? 'bi-key' : 'bi-envelope-plus' ?>" aria-hidden="true"></i>
          <?= $exists ? 'Wyślij mi nowe hasło SMS-em' : 'Załóż skrzynkę i wyślij hasło SMS-em' ?></button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($exists || $job) echo $copyHtml; // przed założeniem skrzynki kopiowanie zaznacza się w formularzu wyżej ?>
  <?php endif; ?>

  <?php
    // Logowanie jednokrotne i chmura plików (modules/sso_logto): osoba sama zakłada konta albo zmienia hasło — osobne od hasła do poczty
    // (opcję „to samo hasło także do poczty” usunięto 2026-11-13: osobne hasła ze względów bezpieczeństwa). Formularz idzie na /sso/konto albo /sso/haslo i wraca tutaj.
    require_once dirname(__DIR__, 2) . '/sso_logto/logic/SsoAccounts.php';
    $ssoEmail = strtolower(trim((string)($me['email'] ?? '')));
    $ssoOn = !$guest && SsoConfig::selfEnabled() && SsoConfig::logtoConfigured() && filter_var($ssoEmail, FILTER_VALIDATE_EMAIL);
    $ssoHtml = '';
    if ($ssoOn):
      $ssoAcc       = SsoAccounts::get($ssoEmail);
      $ssoHas       = $ssoAcc && !empty($ssoAcc['logto_id']);
      $ssoHasCld    = $ssoAcc && !empty($ssoAcc['cloud_uid']);
      $ssoCloudSelf = SsoConfig::cloudSelf() && SsoConfig::cloudConfigured();
      $ssoCloud     = SsoConfig::cloudLabel();
      $ssoNeedCloud = $ssoHas && !$ssoHasCld && $ssoCloudSelf;   // ma konto bez chmury: /sso/konto dołoży chmurę z nowym hasłem dla obu
      $ssoAction    = rtrim(APP_URL, '/') . ($ssoHas && !$ssoNeedCloud ? '/sso/haslo' : '/sso/konto');
      $ssoBtn       = !$ssoHas ? ($ssoCloudSelf ? 'Załóż konta' : 'Załóż konto') : ($ssoNeedCloud ? 'Załóż konto w chmurze' : 'Zmień hasło');
  ?>
  <?php ob_start(); ?>
    <div class="mt-group">
      <?php if (!$ssoHas): ?>
        <p>Jednym kontem zalogujesz się do aplikacji organizacji<?= $ssoCloudSelf ? ', a w chmurze ' . h($ssoCloud) . ' dostaniesz miejsce na pliki' : '' ?>. Login to Twój adres <?= h($ssoEmail) ?>, a hasło wymyślasz sam(a).</p>
      <?php else: ?>
        <p>Masz już konto. Login: <code><?= h((string)$ssoAcc['logto_username']) ?></code> albo <?= h($ssoEmail) ?>.
          <?php if ($ssoHasCld): ?>W chmurze <?= h($ssoCloud) ?> logujesz się loginem <code><?= h((string)$ssoAcc['cloud_uid']) ?></code> i tym samym hasłem.<?php if (SsoConfig::cloudUrl() !== ''): ?> <a href="<?= h(SsoConfig::cloudUrl()) ?>" target="_blank" rel="noopener">Otwórz chmurę<span class="visually-hidden"> (nowa karta)</span></a><?php endif; ?>
          <?php elseif ($ssoNeedCloud): ?>Konta w chmurze plików jeszcze nie masz. Założysz je tutaj, a nowe hasło będzie działać w obu miejscach.
          <?php endif; ?></p>
      <?php endif; ?>
      <form method="post" action="<?= h($ssoAction) ?>" class="js-once">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="from" value="migracja">
        <label class="dm-fld" for="ssoP1"><?= $ssoHas && !$ssoNeedCloud ? 'Nowe hasło' : 'Hasło' ?></label>
        <input class="dm-inp" type="password" id="ssoP1" name="password" required minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="ssoP1h">
        <p class="dm-help" id="ssoP1h">Co najmniej 10 znaków. Najłatwiej zapamiętać kilka słów połączonych w jedno.</p>
        <label class="dm-fld" for="ssoP2">Powtórz hasło</label>
        <input class="dm-inp" type="password" id="ssoP2" name="password2" required minlength="10" maxlength="128" autocomplete="new-password">
        <p class="dm-help" aria-hidden="true"></p>
        <p class="dm-help">To hasło jest osobne od hasła do poczty, które się nie zmienia.</p>
        <button type="submit" class="mt-btn"><i class="bi <?= $ssoHas && !$ssoNeedCloud ? 'bi-key' : 'bi-person-plus' ?>" aria-hidden="true"></i> <?= h($ssoBtn) ?></button>
      </form>
    </div>
  <?php $ssoHtml = (string)ob_get_clean(); endif; ?>

  <?php
  // ── Zakładki: [id, etykieta, HTML] ────────────────────────────────────────────────────────────
  $tabs = [];
  ob_start(); ?>
    <p><?= $migrated ? 'Thunderbird, Outlook i większość aplikacji w telefonie same znajdą serwery poczty.' : (($swTxt !== '' ? ($swPast ? 'Od ' . h($swTxt) : 'Od ' . h($swTxt) . ', nie wcześniej,') : 'Po przełączeniu') . ' dodasz pocztę w telefonie i programie. Thunderbird, Outlook i większość aplikacji same znajdą serwery.') ?>
      Wpisujesz tylko adres e-mail i hasło do poczty. Jeśli program proponuje logowanie przez konto Microsoft albo Google, pomiń ten krok i wybierz konto IMAP.</p>
    <details class="dm-more">
      <summary>Dane do wpisania ręcznie, gdyby program o nie zapytał</summary>
      <?php $cfgBox = (string)($st['mailbox'] ?? ''); include __DIR__ . '/_mailConfig.php'; ?>
    </details>
    <?php if ($migrated): ?>
      <p style="margin-top:.8rem"><a class="mt-btn mt-btn--ghost" href="<?= h(route('dhm.selfPdf')) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Pobierz PDF z danymi logowania</a>
        <span class="dm-help" style="display:block">Bez haseł. Hasło masz z SMS-a.</span></p>
    <?php endif; ?>
  <?php $tabs[] = ['program', $migrated ? 'Telefon i program' : 'Program pocztowy', (string)ob_get_clean()];

  ob_start(); ?>
    <?php if ($migrated): ?>
      <p>Hasło z SMS-a możesz zmienić na własne. Zaloguj się na <a href="<?= h($loginUrl) ?>" target="_blank" rel="noopener"><?= h($loginHost) ?><span class="visually-hidden"> (nowa karta)</span></a>
        i zmień je w ustawieniach poczty. Potem wpisz nowe hasło w telefonie i programie.
        <?php if (!empty($st['sync']['active'])): ?>Jeśli zrobisz to przed <?= h(date('d.m.Y', strtotime((string)$st['sync']['until']))) ?>, podaj nowe hasło na tej stronie, gdy o nie poprosimy. Wtedy dalej dosyłamy wiadomości z Microsoft 365.<?php endif; ?></p>
    <?php endif; ?>
    <p>Hasło do poczty jest inne niż hasło do Panelu współpracownika (SZO) i do logowania jednokrotnego. Gdy ktoś pozna jedno z nich, nie wejdzie na pozostałe konta.</p>
    <p>Hasło do skrzynki wysyłamy tylko SMS-em, nigdy e-mailem. Nikt z Fundacji nie poprosi Cię o hasło. Taka prośba to próba oszustwa: zgłoś ją, <?= $helpLink ?>.</p>
  <?php $tabs[] = ['hasla', $migrated ? 'Hasło' : 'Hasła', (string)ob_get_clean()];

  if ($migrated && $copyHtml !== '' && !$needPw) $tabs[] = ['kopia', 'Kopia poczty', $copyHtml];
  if ($ssoHtml !== '') $tabs[] = ['sso', 'Logowanie jednokrotne', $ssoHtml];
  if (!$migrated) { ob_start(); $whyCollapsed = false; include __DIR__ . '/_why.php'; $tabs[] = ['why', 'Dlaczego zmiana', (string)ob_get_clean()]; }
  $tabs[] = ['pomoc', 'Pomoc', '<p>Nie masz hasła albo coś nie działa? ' . ucfirst($helpLink) . '.</p>'];
  ?>
  <section class="dm-tabs" aria-labelledby="hMore">
    <h2 id="hMore" class="mt-h2">Więcej <b>informacji</b></h2>
    <div class="dm-tabs__list" role="tablist" aria-label="Więcej informacji" hidden>
      <?php foreach ($tabs as $i => [$tid, $tlabel]): ?>
      <button type="button" role="tab" id="tab-<?= $tid ?>" aria-controls="pan-<?= $tid ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>"><?= h($tlabel) ?></button>
      <?php endforeach; ?>
    </div>
    <?php foreach ($tabs as $i => [$tid, $tlabel, $thtml]): ?>
    <div class="dm-tabs__panel mt-group" id="pan-<?= $tid ?>">
      <h3 class="dm-tabs__h"><?= h($tlabel) ?></h3>
      <?= $thtml ?>
    </div>
    <?php endforeach; ?>
  </section>
</main>

<script>
// Zakładki (WCAG, wzorzec ARIA „tabs”, automatyczna aktywacja): bez JS wszystkie sekcje są widoczne po kolei.
(function () {
  var box = document.querySelector('.dm-tabs'); if (!box) return;
  var list = box.querySelector('[role=tablist]'), tabs = Array.prototype.slice.call(box.querySelectorAll('[role=tab]'));
  if (!list || tabs.length < 2) return;
  box.classList.add('is-js'); list.hidden = false;
  tabs.forEach(function (t) {
    var p = document.getElementById(t.getAttribute('aria-controls'));
    p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', t.id); p.tabIndex = 0;
  });
  function show(t, focus) {
    tabs.forEach(function (x) {
      var on = x === t, p = document.getElementById(x.getAttribute('aria-controls'));
      x.setAttribute('aria-selected', on ? 'true' : 'false'); x.tabIndex = on ? 0 : -1; p.hidden = !on;
    });
    if (focus) t.focus();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t, false); });
    t.addEventListener('keydown', function (e) {
      var n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
      if (n === undefined) return;
      e.preventDefault(); show(tabs[(n + tabs.length) % tabs.length], true);
    });
  });
  show(tabs[0], false);
})();
document.querySelectorAll('form.js-once').forEach(function (f) {
  f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.lastChild.textContent = ' Chwileczkę…'; } });
});
</script>
</body>
</html>
