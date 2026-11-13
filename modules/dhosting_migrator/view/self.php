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
      <p class="mt-lead">Nowa skrzynka pocztowa z <strong>tym samym loginem</strong> co Twoje konto Microsoft 365 i <strong>nowym hasłem</strong>.
        Możesz też przenieść do niej pocztę z Microsoft 365.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

  <?php include __DIR__ . '/_why.php'; // na początku strony (polecenie 2026-11-13), pod komunikatem po akcji ?>

  <div class="mt-imp">
    <div class="mt-imp__item" style="--c:var(--m-blue)">
      <h2 class="mt-imp__ttl">Co się dzieje z pocztą?</h2>
      <p class="mt-imp__body"><?php if ($swTxt !== ''): ?><strong>Od <?= h($swTxt) ?> poczta</strong> w domenie @<?= h($domain) ?> <?= $swPast ? 'działa' : 'będzie działać' ?><?php else: ?>Wkrótce poczta w domenie @<?= h($domain) ?> będzie działać<?php endif; ?> u polskiego dostawcy zamiast w Microsoft 365. <strong>Twój adres e-mail się nie zmienia.</strong>
Zrób kroki z listy poniżej<?= $swTxt !== '' && !$swPast ? ' przed ' . h($swTxt) : '' ?> — zajmie to około 5 minut. Dotychczasowa poczta skopiuje się sama, razem z folderami.</p>
    </div>
  </div>

  <div class="mt-grid mt-grid--sm" role="list" aria-label="Stan migracji">
    <div class="mt-tile dm-static" role="listitem" style="--c:var(<?= $exists ? '--m-green' : '--m-blue' ?>)">
      <i class="bi bi-envelope-at ic" aria-hidden="true"></i>
      <div><span class="val"><?= $exists ? 'Jest' : ($st['exists'] === false ? 'Brak' : '—') ?></span><span class="lbl">Nowa skrzynka</span></div>
    </div>
    <div class="mt-tile dm-static" role="listitem" style="--c:var(<?= $job && $job['status'] === 'done' ? '--m-green' : '--m-teal' ?>)">
      <i class="bi bi-arrow-left-right ic" aria-hidden="true"></i>
      <div><span class="val"><?= $job ? $pct . '%' : '—' ?></span><span class="lbl">Kopia poczty<?= $job ? ' — ' . h(DhostingCopy::LABELS[$job['status']] ?? $job['status']) : '' ?></span></div>
    </div>
    <div class="mt-tile dm-static" role="listitem" style="--c:var(<?= $st['mobile'] ? '--m-indigo' : '--m-orange' ?>)">
      <i class="bi bi-phone ic" aria-hidden="true"></i>
      <div><span class="val"><?= $st['mobile'] ? 'Jest' : 'Brak' ?></span><span class="lbl">Numer do SMS</span></div>
    </div>
    <?php if (!empty($st['ticket'])): ?>
      <a class="mt-tile" role="listitem" style="--c:var(--m-violet)" <?= $hdUrl !== '' ? 'href="' . h($hdUrl) . '"' : '' ?>>
        <i class="bi bi-life-preserver ic" aria-hidden="true"></i>
        <div><span class="val" style="font-size:1.1rem;font-weight:600"><?= h((string)$st['ticket']['number']) ?></span><span class="lbl">Zgłoszenie w Helpdesku<?= $hdUrl !== '' ? ' — otwórz' : '' ?></span></div>
      </a>
    <?php endif; ?>
  </div>

  <?php if ($locked):
        // migracja zakończona (polecenie 2026-11-13): kto zakończył, hasło SMS-em, gdzie się logować
        $mj  = $st['copy'] ?? null;
        $reg = $st['registry'] ?? [];
        $byAdmin = ($mj['via'] ?? 'admin') !== 'self';
        $loginUrl = $web !== '' ? $web : DhostingConfig::DEFAULT_WEBMAIL;
        $loginHost = (string)(parse_url($loginUrl, PHP_URL_HOST) ?: $loginUrl); ?>
    <div class="dm-msg dm-msg--success" role="status" style="margin-top:1rem"><i class="bi bi-check2-circle" aria-hidden="true"></i>
      <div><strong><?= $byAdmin ? 'Administrator zakończył migrację Twojej poczty.' : 'Migracja Twojej poczty jest zakończona.' ?></strong>
        <?php if (!empty($mj['finished_at'])): ?><span>(<?= h(date('d.m.Y H:i', strtotime((string)$mj['finished_at']))) ?>)</span><?php endif; ?>
        <ul style="margin:.4rem 0 0;padding-left:1.2rem">
          <?php $inf = $st['info'] ?? []; $smsTo = ($inf['phoneMasked'] ?? '') !== '' ? (string)$inf['phoneMasked'] : (string)($reg['sms_to'] ?? ''); ?>
          <?php if (($inf['active'] ?? null) === true): ?><li>Skrzynka jest aktywna.</li>
          <?php elseif (($inf['active'] ?? null) === false): ?><li><strong>Skrzynka jest teraz zablokowana.</strong> Napisz do administratora.</li><?php endif; ?>
          <li><?php if (!empty($reg['sms_at']) || $smsTo !== ''): ?>Hasło do nowej skrzynki przyszło SMS-em<?= $smsTo !== '' ? ' na numer ' . h($smsTo) : '' ?><?= !empty($reg['sms_at']) ? ' (' . h(date('d.m.Y H:i', strtotime((string)$reg['sms_at']))) . ')' : '' ?>.
              <?php else: ?>Hasło do nowej skrzynki przyszło SMS-em albo przekazał je administrator.<?php endif; ?></li>
          <li>Logowanie: <a href="<?= h($loginUrl) ?>" target="_blank" rel="noopener"><?= h($loginHost) ?><span class="visually-hidden"> (nowa karta)</span></a>
              — login: <b><?= h((string)($st['mailbox'] ?? '')) ?></b>, hasło z SMS-a.</li>
          <li>Nie masz hasła albo chcesz coś zmienić (nowe hasło, ponowne kopiowanie)? To robi administrator —
              <?php if ($hdUrl !== ''): ?>napisz w <a href="<?= h($hdUrl) ?>">swoim zgłoszeniu <?= h((string)$st['ticket']['number']) ?></a>.<?php else: ?>napisz do Helpdesku IT.<?php endif; ?></li>
        </ul></div></div>
  <?php elseif (!empty($st['migrated'])): ?>
    <div class="dm-msg dm-msg--info" role="status" style="margin-top:1rem"><i class="bi bi-shield-check" aria-hidden="true"></i>
      <div>To konto jest już zmigrowane. Jako administrator możesz nadal ustawić nowe hasło albo skopiować pocztę ponownie.</div></div>
  <?php endif; ?>

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

  <section class="dm-sec" aria-labelledby="hBox">
    <h2 id="hBox" class="mt-h2">Twoja <b>skrzynka</b></h2>
    <div class="mt-group">
      <dl class="mt-kv">
        <div><dt>Login Microsoft 365</dt><dd><?= $st['login'] !== '' ? h($st['login']) : '—' ?></dd></div>
        <div><dt>Nowa skrzynka</dt><dd><?= $st['mailbox'] ? h($st['mailbox']) : '—' ?></dd></div>
        <div><dt>Stan</dt><dd><?= $st['exists'] === true ? '<span class="mt-yes">Skrzynka istnieje</span>' : ($st['exists'] === false ? 'Jeszcze jej nie ma' : '—') ?></dd></div>
        <div><dt>Numer do SMS</dt><dd><?= $st['phoneMasked'] !== '' ? h($st['phoneMasked']) : 'brak' ?></dd></div>
        <?php if (!empty($st['registry']['password_set_at'])): ?>
        <div><dt>Ostatnie hasło</dt><dd><?= h(substr((string)$st['registry']['password_set_at'], 0, 16)) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($st['ticket'])): ?>
        <div><dt>Zgłoszenie w Helpdesku</dt><dd><?php if ($hdUrl !== ''): ?><a href="<?= h($hdUrl) ?>"><?= h((string)$st['ticket']['number']) ?></a><?php else: ?><?= h((string)$st['ticket']['number']) ?><?php endif; ?> — tu napisz, jeśli coś nie działa</dd></div>
        <?php endif; ?>
      </dl>
    </div>
  </section>

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
          <p class="dm-help">Zakończono <?= h(substr((string)$job['finished_at'], 0, 16)) ?>. Foldery z Outlooka są w nowej skrzynce pod tymi samymi nazwami.</p>
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

      <?php if ((($exists && !$jobActive) || $needPw) && !$locked): ?>
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

  <section class="dm-sec" aria-labelledby="hCfg">
    <h2 id="hCfg" class="mt-h2">Ustawienia <b>programu pocztowego</b></h2>
    <div class="mt-group">
      <p>Dla telefonu (Gmail, Poczta iOS), Outlooka, Thunderbirda — <?= $swTxt !== '' ? ($swPast ? 'od ' . h($swTxt) : 'od ' . h($swTxt) . ', nie wcześniej') : 'po przełączeniu' ?>. Typ konta: <strong>IMAP</strong>.</p>
      <table class="dm-cfg">
        <caption class="visually-hidden">Dane do konfiguracji konta pocztowego</caption>
        <tr><th scope="row">Adres e-mail i login</th><td><?= $st['mailbox'] ? '<code>' . h($st['mailbox']) . '</code>' : 'pełny adres e-mail' ?></td></tr>
        <tr><th scope="row">Hasło</th><td>to z SMS-a (nie hasło do Microsoft 365)</td></tr>
        <tr><th scope="row">Poczta przychodząca (IMAP)</th><td><code><?= h($imapHost) ?></code>, port <?= (int)$imapPort ?>, SSL/TLS</td></tr>
        <tr><th scope="row">Poczta wychodząca (SMTP)</th><td><code><?= h(DhostingConfig::SMTP_HOST) ?></code>, port <?= (int)DhostingConfig::SMTP_PORT ?>, STARTTLS, z uwierzytelnianiem (ten sam login i hasło)</td></tr>
      </table>
      <?php if ($web !== '' && $exists): ?>
        <p style="margin-top:.8rem">Poczta przez przeglądarkę: <a href="<?= h($web) ?>" target="_blank" rel="noopener"><?= h($web) ?></a> (otwiera się w nowej karcie)</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="dm-sec" aria-labelledby="hSame">
    <h2 id="hSame" class="mt-h2">Co się <b>nie zmienia</b></h2>
    <div class="mt-group">
      <ul class="dm-list">
        <li>adres e-mail,</li>
        <li>hasło do konta Microsoft 365 i logowanie do SZO,</li>
        <li>wiadomości, które w dniu przełączenia jeszcze wpadną do starej skrzynki — dokopiujemy je, nic nie zginie.</li>
      </ul>
    </div>
  </section>

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
