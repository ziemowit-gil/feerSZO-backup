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
    ? ['done', 'Numer komórkowy w SZO', 'Gotowe — hasło przyjdzie na ' . $st['phoneMasked'] . '.']
    : ['todo', 'Numer komórkowy w SZO', 'Do zrobienia — podaj numer poniżej. Na niego przyjdzie hasło.'];
$steps[] = $exists
    ? ['done', 'Nowa skrzynka i hasło SMS-em', 'Gotowe — skrzynka ' . $st['mailbox'] . ' istnieje.']
    : ($st['exists'] === false
        ? ['todo', 'Nowa skrzynka i hasło SMS-em', 'Do zrobienia — kliknij „Załóż skrzynkę i wyślij hasło SMS-em” poniżej.']
        : ['info', 'Nowa skrzynka i hasło SMS-em', 'Nie udało się teraz sprawdzić — zobacz komunikat niżej.']);
if ($job && $job['status'] === 'done') $steps[] = ['done', 'Kopia poczty z Microsoft 365', 'Gotowe — skopiowano ' . (int)$job['copied_items'] . ' wiadomości.'];
elseif ($needPw) $steps[] = ['todo', 'Kopia poczty z Microsoft 365', 'Wstrzymana — nowa skrzynka nie przyjęła hasła. ' . ($locked ? 'Pomoże administrator (szczegóły niżej).' : 'Podaj aktualne hasło w sekcji „Kopiowanie poczty” poniżej.')];
elseif ($job && $job['status'] === 'waiting') $steps[] = ['doing', 'Kopia poczty z Microsoft 365', 'Czeka — serwer był niedostępny, ponowimy ' . substr((string)$job['next_run_at'], 0, 16) . '.'];
elseif ($jobActive) $steps[] = ['doing', 'Kopia poczty z Microsoft 365', 'W toku — ' . $pct . '% (nic nie musisz robić, kopiuje się w tle).'];
else $steps[] = ['todo', 'Kopia poczty z Microsoft 365', $exists ? 'Do zrobienia — podaj hasło do skrzynki w sekcji „Kopiowanie poczty” poniżej.' : 'Do zrobienia — zaznacz „Skopiuj też moją pocztę” przy zakładaniu skrzynki.'];
$steps[] = ['info', 'Program pocztowy w telefonie i komputerze', ($swTxt !== '' ? ($swPast ? 'Od ' . $swTxt : 'Od ' . $swTxt . ' (wtedy, nie wcześniej)') : 'Po przełączeniu') . ' ustaw konto według danych w sekcji „Ustawienia programu pocztowego”.'];
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
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Poczta Fundacji</div>
      <h1 class="mt-title">Migracja poczty</h1>
      <?php if (!empty($st['migrated'])): ?>
      <p class="mt-lead">Twoja poczta jest już w nowej skrzynce. Poniżej masz wszystko, czego potrzebujesz do logowania.</p>
      <?php else: ?>
      <p class="mt-lead">Nowa skrzynka pocztowa z <strong>tym samym loginem</strong> co Twoje konto Microsoft 365 i <strong>nowym hasłem</strong>.
        Dotychczasowa poczta przeniesie się do niej sama.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($st['migrated'])):
        // Migracja zakończona (polecenie 2026-11-13): najważniejsze na górze — kto i kiedy, login, hasło (SMS), logowanie, potem dane do programu.
        // Stan skrzynki i numer do SMS-ów z API dostawcy ($st['info'], DhostingMigrator::publicInfo), zapasowo z rejestru.
        $mj  = $st['copy'] ?? null;
        $reg = $st['registry'] ?? [];
        $inf = $st['info'] ?? [];
        $byAdmin   = ($mj['via'] ?? 'admin') !== 'self';
        $loginUrl  = $web !== '' ? $web : DhostingConfig::DEFAULT_WEBMAIL;
        $loginHost = (string)(parse_url($loginUrl, PHP_URL_HOST) ?: $loginUrl);
        $smsTo     = ($inf['phoneMasked'] ?? '') !== '' ? (string)$inf['phoneMasked'] : (string)($reg['sms_to'] ?? ''); ?>
  <section class="dm-done" aria-labelledby="hDone">
    <h2 id="hDone"><i class="bi bi-check2-circle" aria-hidden="true" style="color:var(--m-green)"></i> <?= $byAdmin ? 'Administrator zakończył migrację Twojej poczty' : 'Migracja Twojej poczty jest zakończona' ?></h2>
    <p class="dm-done__when"><?= !empty($mj['finished_at']) ? 'Zakończono ' . h(date('d.m.Y, H:i', strtotime((string)$mj['finished_at']))) . '. ' : '' ?>Możesz już korzystać z nowej skrzynki.</p>
    <?php if (($inf['active'] ?? null) === false): ?>
      <div class="dm-msg dm-msg--danger" role="alert"><i class="bi bi-lock" aria-hidden="true"></i><div><strong>Skrzynka jest teraz zablokowana.</strong> Napisz do administratora<?= $hdUrl !== '' ? ' w <a href="' . h($hdUrl) . '">swoim zgłoszeniu</a>' : '' ?>.</div></div>
    <?php endif; ?>
    <div class="mt-grid mt-grid--sm" role="list" aria-label="Dane do logowania">
      <div class="mt-tile dm-static" role="listitem" style="--c:var(--m-blue)">
        <i class="bi bi-person ic" aria-hidden="true"></i>
        <div><span class="val"><?= h((string)($st['mailbox'] ?? '')) ?></span><span class="lbl">Login — Twój adres e-mail</span></div>
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
    <?php if (!$byAdmin): /* osoba sama zleciła migrację — zachęta do zmiany hasła z SMS-a na własne (polecenie 2026-11-13) */ ?>
      <div class="mt-note" role="note" style="margin:1rem 0 0;border-left-color:var(--m-orange);background:#fff"><i class="bi bi-shield-lock" aria-hidden="true" style="color:var(--m-orange)"></i>
        <div><strong>Zmień hasło z SMS-a na własne.</strong> Zaloguj się na <a href="<?= h($loginUrl) ?>" target="_blank" rel="noopener"><?= h($loginHost) ?><span class="visually-hidden"> (nowa karta)</span></a>
          i zmień hasło w ustawieniach poczty. Wymyśl takie, którego nie używasz nigdzie indziej. Potem wpisz nowe hasło w telefonie i w Outlooku.
          <?php if (!empty($st['sync']['active'])): ?>Jeśli zmienisz je przed <?= h(date('d.m.Y', strtotime((string)$st['sync']['until']))) ?>, podaj nowe hasło na tej stronie, gdy o nie poprosimy — wtedy dalej będziemy dosyłać nowe wiadomości z Microsoft 365.<?php endif; ?></div>
      </div>
    <?php endif; ?>
    <p class="dm-help" style="margin:1rem 0 0">Nie masz hasła albo chcesz coś zmienić (nowe hasło, ponowne kopiowanie)?
      <?= $locked ? 'To robi administrator — ' : '' ?><?php if ($hdUrl !== ''): ?>napisz w <a href="<?= h($hdUrl) ?>">swoim zgłoszeniu <?= h((string)$st['ticket']['number']) ?></a>.<?php else: ?>napisz do Helpdesku IT.<?php endif; ?></p>
  </section>
  <?php if (!$locked): ?>
    <div class="dm-msg dm-msg--info" role="status"><i class="bi bi-shield-check" aria-hidden="true"></i>
      <div>To konto jest już zmigrowane. Jako administrator możesz nadal ustawić nowe hasło; ponowne przenoszenie poczty uruchomisz na <a href="<?= h(rtrim(APP_URL, '/')) ?>/migracjaadmin#kopiowanie">/migracjaadmin</a>.</div></div>
  <?php endif; ?>

  <section class="dm-sec" aria-labelledby="hCfg">
    <h2 id="hCfg" class="mt-h2">Ustawienia <b>programu pocztowego</b></h2>
    <div class="mt-group">
      <p>Telefon (Gmail, Poczta w iPhonie), Outlook, Thunderbird i inne programy: dodaj konto ręcznie, z tymi danymi.</p>
      <?php $cfgBox = (string)($st['mailbox'] ?? ''); include __DIR__ . '/_mailConfig.php'; ?>
      <p style="margin-top:.8rem"><a class="mt-btn mt-btn--ghost" href="<?= h(route('dhm.selfPdf')) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Pobierz PDF z danymi logowania</a>
        <span class="dm-help" style="display:block">Bez haseł — te masz z SMS-a. Przyda się do ustawienia telefonu i komputera.</span></p>
    </div>
  </section>
  <?php else: ?>
  <?php $whyCollapsed = true; include __DIR__ . '/_why.php'; // na początku strony (polecenie 2026-11-13), zwinięte (polecenie „uprość”) ?>

  <div class="mt-imp">
    <div class="mt-imp__item" style="--c:var(--m-blue)">
      <h2 class="mt-imp__ttl">Co się dzieje z pocztą?</h2>
      <p class="mt-imp__body"><?php if ($swTxt !== ''): ?><strong>Od <?= h($swTxt) ?> poczta</strong> w domenie @<?= h($domain) ?> <?= $swPast ? 'działa' : 'będzie działać' ?><?php else: ?>Wkrótce poczta w domenie @<?= h($domain) ?> będzie działać<?php endif; ?> u polskiego dostawcy zamiast w Microsoft 365.
        <strong>Nie zmienia się</strong> Twój adres e-mail, hasło do Microsoft 365 ani logowanie do SZO.
        Zrób kroki poniżej<?= $swTxt !== '' && !$swPast ? ' przed ' . h($swTxt) : '' ?> — to około 5 minut. Dotychczasowa poczta skopiuje się sama, razem z folderami.</p>
    </div>
  </div>
  <?php endif; ?>

  <?php /* osobne hasła (polecenie 2026-11-13): poczta ≠ Panel współpracownika (SZO) ≠ logowanie jednokrotne — ze względów bezpieczeństwa */ ?>
  <div class="mt-note" role="note" style="border-left-color:var(--m-orange);margin-bottom:1rem"><i class="bi bi-key" aria-hidden="true" style="color:var(--m-orange)"></i>
    <div><strong>Hasło do poczty jest osobne.</strong> Jest inne niż hasło do Panelu współpracownika (SZO)<?= $guest ? '' : ', w którym teraz jesteś' ?>,
      i inne niż hasło do logowania jednokrotnego i chmury plików. To celowe, ze względów bezpieczeństwa: gdy ktoś pozna jedno z tych haseł,
      nie dostanie się do pozostałych kont.</div>
  </div>

  <?php if (empty($st['migrated'])): ?>
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
    <?php if (!empty($st['ticket'])): ?>
      <p class="dm-help">Coś nie działa? Napisz w <?php if ($hdUrl !== ''): ?><a href="<?= h($hdUrl) ?>">swoim zgłoszeniu <?= h((string)$st['ticket']['number']) ?></a><?php else: ?>zgłoszeniu <?= h((string)$st['ticket']['number']) ?><?php endif; ?>.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($st['problem'] !== '' && !$locked): ?>
    <div class="dm-msg dm-msg--warning" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><?= h($st['problem']) ?></div></div>
  <?php elseif ($st['wait'] > 0 && !$locked): ?>
    <div class="dm-msg dm-msg--info" role="status"><i class="bi bi-clock" aria-hidden="true"></i><div>Kolejną prośbę o hasło możesz wysłać za <?= (int)ceil($st['wait'] / 60) ?> min.</div></div>
  <?php endif; ?>

  <?php if (!empty($st['needPhone']) && !$guest && !$locked): ?>
  <section class="dm-sec" aria-labelledby="hPhone">
    <h2 id="hPhone" class="mt-h2">Dodaj <b>numer komórkowy</b></h2>
    <div class="mt-group">
      <?php if ($phoneFlow): ?>
        <p>Wysłaliśmy kod SMS na numer <strong><?= h($phoneFlow['phoneMask']) ?></strong>. Wpisz go — wtedy zapiszemy numer w SZO.</p>
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
        <p>Hasło do nowej skrzynki wysyłamy tylko SMS-em. Podaj swój numer komórkowy — wyślemy na niego kod, a po potwierdzeniu zapiszemy numer w SZO.</p>
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
        <div><strong>Ważne:</strong> hasło wyślemy <strong>tylko SMS-em</strong> na numer <?= h($st['phoneMasked']) ?> — nie pokażemy go na ekranie ani nie wyślemy e-mailem.
        <?php if ($exists): ?>Nowe hasło zastąpi obecne: programy pocztowe (telefon, Outlook) poproszą o nie ponownie.<?php endif; ?>
        Hasło do konta Microsoft 365 się nie zmienia.</div>
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

  <?php if ($copyOk): ?>
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
                <span>— <?= (int)$fr['result'] !== 3 ? 'nie przeniesiona' : (str_contains((string)$fr['reason'], 'bez załączników') ? 'przeniesiona bez załączników' : 'przeniesiona bez pełnej treści') ?></span></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
        <?php if ($needPw): ?>
          <div class="dm-msg dm-msg--warning" role="alert" style="background:var(--pvm-bg)"><i class="bi bi-key" aria-hidden="true"></i><div><strong>Nowa skrzynka nie przyjęła hasła.</strong>
            Hasło, z którym zaczęliśmy kopiowanie, już nie pasuje — na przykład zostało zmienione po założeniu skrzynki.
            <?php if ($locked): ?>Twoja poczta jest już przeniesiona, więc dalej pomoże administrator — <?php if ($hdUrl !== ''): ?>napisz w <a href="<?= h($hdUrl) ?>">swoim zgłoszeniu</a><?php else: ?>napisz do Helpdesku IT<?php endif; ?>, a dosyłanie wiadomości ruszy z nowym hasłem.
            <?php else: ?>Podaj poniżej aktualne hasło do nowej skrzynki, a kopiowanie ruszy od miejsca, w którym stanęło. Nie pamiętasz go? Poproś wyżej o nowe hasło SMS-em — kopiowanie samo je przejmie.<?php endif; ?></div></div>
        <?php elseif ($job['status'] === 'waiting'): ?>
          <div class="dm-msg dm-msg--warning" role="status" style="background:var(--pvm-bg)"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div>Serwer był niedostępny — spróbujemy ponownie <?= h(substr((string)$job['next_run_at'], 0, 16)) ?> (co <?= DhostingCopy::RETRY_HOURS ?> h). <?= $job['last_error'] ? h(mb_substr((string)$job['last_error'], 0, 200)) : '' ?></div></div>
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
          <?php if ($sync['active']): ?>Nowe wiadomości, które jeszcze wpadną do Microsoft 365, dosyłamy automatycznie co <?= DhostingCopy::SYNC_HOURS ?> h — do <?= h(substr($sync['until'], 0, 16)) ?>.
            Dopisujemy tylko brakujące; tego, co już jest w nowej skrzynce, nie zmieniamy.<?php endif; ?>
          <?php if ($sync['last']): ?>Ostatnio: <?= h(substr($sync['last']['at'], 0, 16)) ?> — dosłano <?= (int)$sync['last']['added'] ?>.<?php endif; ?></p>
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
        <p>Najpierw załóż skrzynkę — kopiowanie możesz zaznaczyć od razu przy zakładaniu.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php
    // Logowanie jednokrotne i chmura plików (modules/sso_logto): osoba sama zakłada konta albo zmienia hasło — osobne od hasła do poczty
    // (opcję „to samo hasło także do poczty” usunięto 2026-11-13: osobne hasła ze względów bezpieczeństwa). Formularz idzie na /sso/konto albo /sso/haslo i wraca tutaj.
    require_once dirname(__DIR__, 2) . '/sso_logto/logic/SsoAccounts.php';
    $ssoEmail = strtolower(trim((string)($me['email'] ?? '')));
    $ssoOn = !$guest && SsoConfig::selfEnabled() && SsoConfig::logtoConfigured() && filter_var($ssoEmail, FILTER_VALIDATE_EMAIL);
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
  <section class="dm-sec" aria-labelledby="hSso">
    <h2 id="hSso" class="mt-h2">Logowanie jednokrotne i <b>chmura plików</b></h2>
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
        <p class="dm-help">To hasło jest osobne od hasła do poczty — hasło do poczty się nie zmienia.</p>
        <button type="submit" class="mt-btn"><i class="bi <?= $ssoHas && !$ssoNeedCloud ? 'bi-key' : 'bi-person-plus' ?>" aria-hidden="true"></i> <?= h($ssoBtn) ?></button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <?php if (empty($st['migrated'])): ?>
  <section class="dm-sec" aria-labelledby="hCfgLater">
    <h2 id="hCfgLater" class="mt-h2">Ustawienia <b>programu pocztowego</b></h2>
    <details class="dm-more">
      <summary>Dane do telefonu i Outlooka — przydadzą się <?= $swTxt !== '' ? ($swPast ? 'od ' . h($swTxt) : 'od ' . h($swTxt) . ', nie wcześniej') : 'po przełączeniu' ?></summary>
      <?php $cfgBox = (string)($st['mailbox'] ?? ''); include __DIR__ . '/_mailConfig.php'; ?>
    </details>
  </section>
  <?php else: ?>
  <?php $whyCollapsed = true; include __DIR__ . '/_why.php'; ?>
  <?php endif; ?>

  <div class="mt-note" role="note" style="border-left-color:var(--m-red)"><i class="bi bi-shield-exclamation" aria-hidden="true" style="color:var(--m-red)"></i>
    <div><strong>Bezpieczeństwo:</strong> hasło do nowej skrzynki wysyłamy <strong>tylko SMS-em</strong>, nigdy e-mailem.
      Nikt z Fundacji nie poprosi Cię o podanie hasła — jeśli dostaniesz taką prośbę, to próba oszustwa.
      <?php if ($hdUrl !== ''): ?>Zgłoś ją w <a href="<?= h($hdUrl) ?>">swoim zgłoszeniu</a>.<?php elseif (!$guest): ?>Zgłoś ją w <a href="<?= APP_URL ?>/panel/helpdesk.php">Helpdesku IT</a>.<?php else: ?>Zgłoś ją do Helpdesku IT.<?php endif; ?></div>
  </div>
</main>

<script>
document.querySelectorAll('form.js-once').forEach(function (f) {
  f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.lastChild.textContent = ' Chwileczkę…'; } });
});
</script>
</body>
</html>
