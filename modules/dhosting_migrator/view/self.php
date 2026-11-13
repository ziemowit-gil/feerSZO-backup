<?php
/**
 * modules/dhosting_migrator/view/self.php — /migracjapoczty: SAMODZIELNA strona (bez nagłówka, menu i stopki SZO —
 * polecenie 2026-11-13), wejście z panelu współpracownika. Użytkownik zakłada skrzynkę dhosting z loginem Microsoft
 * albo dostaje nowe hasło (tylko SMS-em) i kopiuje pocztę z Microsoft 365 (w tle, z postępem).
 * Na górze „Co się dzieje” (data przełączenia z ustawień) i lista kroków ze stanem; niżej: dlaczego, ustawienia programu
 * pocztowego, co się nie zmienia, bezpieczeństwo (treść jak w komunikacie do użytkowników, 2026-11-13).
 * Zmienne: $st (DhostingMigrator::selfState), $flash, $web, $me.
 */
$org     = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
$canAct  = $st['problem'] === '' && $st['wait'] === 0;
$exists  = $st['exists'] === true;
$job     = $st['copy'];
$jobActive = $job && in_array($job['status'], DhostingCopy::ACTIVE, true);
$pct     = $job ? DhostingCopy::percent($job) : 0;
$copyOk  = $st['copyAvailable'] && $st['mailbox'] && DhostingConfig::selfEnabled();
$swTs    = DhostingConfig::switchDate();
$swTxt   = $swTs ? date('d.m.Y', $swTs) : '';
$swPast  = $swTs && time() >= $swTs;
[$imapHost, $imapPort] = DhostingConfig::imapForUsers();
$domain  = $st['mailbox'] ? substr((string)$st['mailbox'], strrpos((string)$st['mailbox'], '@') + 1) : 'feer.org.pl';
// Stan kroków: done | doing | todo | info (tekst stanu zawsze obok ikony — nie tylko kolor)
$steps = [];
$steps[] = $st['mobile']
    ? ['done', 'Numer komórkowy w SZO', 'Gotowe — hasło przyjdzie na ' . $st['phoneMasked'] . '.']
    : ['todo', 'Numer komórkowy w SZO', 'Do zrobienia — dodaj numer w Ustawieniach konta (link niżej). Na niego przyjdzie hasło.'];
$steps[] = $exists
    ? ['done', 'Nowa skrzynka i hasło SMS-em', 'Gotowe — skrzynka ' . $st['mailbox'] . ' istnieje.']
    : ($st['exists'] === false
        ? ['todo', 'Nowa skrzynka i hasło SMS-em', 'Do zrobienia — kliknij „Załóż skrzynkę i wyślij hasło SMS-em” poniżej.']
        : ['info', 'Nowa skrzynka i hasło SMS-em', 'Nie udało się teraz sprawdzić — zobacz komunikat niżej.']);
if ($job && $job['status'] === 'done') $steps[] = ['done', 'Kopia poczty z Microsoft 365', 'Gotowe — skopiowano ' . (int)$job['copied_items'] . ' wiadomości.'];
elseif ($job && $job['status'] === 'waiting') $steps[] = ['doing', 'Kopia poczty z Microsoft 365', 'Czeka — serwer był niedostępny, ponowimy ' . substr((string)$job['next_run_at'], 0, 16) . '.'];
elseif ($jobActive) $steps[] = ['doing', 'Kopia poczty z Microsoft 365', 'W toku — ' . $pct . '% (nic nie musisz robić, kopiuje się w tle).'];
else $steps[] = ['todo', 'Kopia poczty z Microsoft 365', $exists ? 'Do zrobienia — podaj hasło do skrzynki w sekcji „Kopiowanie poczty” poniżej.' : 'Do zrobienia — zaznacz „Skopiuj też moją pocztę” przy zakładaniu skrzynki.'];
$steps[] = ['info', 'Program pocztowy w telefonie i komputerze', ($swTxt !== '' ? ($swPast ? 'Od ' . $swTxt : 'Od ' . $swTxt . ' (wtedy, nie wcześniej)') : 'Po przełączeniu') . ' ustaw konto według danych w sekcji „Ustawienia programu pocztowego”.'];
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<?php if ($jobActive): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
<title>Migracja poczty — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root { --bg:#f5f6f8; --card:#fff; --ink:#1d2939; --muted:#475467; --line:#d0d5dd; --acc:#0f5bd8; --acc-ink:#fff;
          --ok-bg:#dcfae6; --ok:#085d3a; --info-bg:#eff4ff; --info:#1849a9; --warn-bg:#fffaeb; --warn:#7a2e0e; --err-bg:#fee4e2; --err:#912018; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  a { color:var(--acc); }
  :focus-visible { outline:3px solid var(--ink); outline-offset:2px; }
  .visually-hidden { position:absolute !important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
  .skip { position:absolute; left:-999px; top:0; background:#fff; padding:.5rem 1rem; }
  .skip:focus { left:1rem; z-index:10; }
  .bar { background:#fff; border-bottom:1px solid var(--line); }
  .bar__in { max-width:720px; margin:0 auto; padding:.75rem 1rem; display:flex; align-items:center; gap:1rem; }
  .bar__org { font-weight:700; }
  .bar__sp { flex:1; }
  .bar a { font-size:.875rem; text-decoration:none; padding:.4rem .2rem; }
  .bar a:hover { text-decoration:underline; }
  main { max-width:720px; margin:0 auto; padding:1.5rem 1rem 3rem; }
  h1 { font-size:1.5rem; margin:0 0 .25rem; }
  h2 { font-size:1.125rem; margin:0 0 .75rem; }
  .lead { color:var(--muted); margin:0 0 1.25rem; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:1.25rem; margin-bottom:1rem; }
  dl { display:grid; grid-template-columns:max-content 1fr; gap:.45rem 1rem; margin:0; }
  dt { font-weight:600; color:var(--muted); font-size:.9rem; }
  dd { margin:0; word-break:break-all; }
  .b { display:inline-block; padding:.1rem .55rem; border-radius:999px; font-size:.8rem; font-weight:600; }
  .b--ok { background:var(--ok-bg); color:var(--ok); } .b--info { background:var(--info-bg); color:var(--info); }
  .b--err { background:var(--err-bg); color:var(--err); } .b--off { background:#f2f4f7; color:#344054; }
  .msg { border-radius:8px; padding:.8rem 1rem; margin-bottom:1rem; border-left:4px solid; }
  .msg--success { background:var(--ok-bg); color:var(--ok); border-color:#12b76a; }
  .msg--danger { background:var(--err-bg); color:var(--err); border-color:#d92d20; }
  .msg--warning { background:var(--warn-bg); color:var(--warn); border-color:#dc6803; }
  .msg--info { background:var(--info-bg); color:var(--info); border-color:#2e90fa; }
  .imp { background:var(--warn-bg); color:var(--warn); border-left:4px solid #b54708; border-radius:6px; padding:.75rem 1rem; font-size:.95rem; margin-bottom:1rem; }
  .chk { display:flex; gap:.6rem; align-items:flex-start; margin:.25rem 0 .9rem; }
  .chk input { width:1.25rem; height:1.25rem; margin-top:.15rem; flex-shrink:0; }
  .btn { display:inline-flex; align-items:center; gap:.5rem; min-height:44px; padding:.55rem 1.1rem; border-radius:8px; border:1px solid var(--acc);
         background:var(--acc); color:var(--acc-ink); font:inherit; font-weight:600; cursor:pointer; }
  .btn--ghost { background:#fff; color:var(--acc); }
  .btn--danger { background:#fff; color:var(--err); border-color:#d92d20; }
  .btn[disabled] { opacity:.6; cursor:wait; }
  label.fld { display:block; font-weight:600; margin-bottom:.3rem; }
  input.inp { width:100%; max-width:22rem; min-height:44px; padding:.5rem .7rem; border:1px solid #98a2b3; border-radius:8px; font:inherit; }
  .help { color:var(--muted); font-size:.875rem; margin:.3rem 0 .8rem; }
  .prog { height:.75rem; background:#eaecf0; border-radius:999px; overflow:hidden; margin:.4rem 0; }
  .prog > span { display:block; height:100%; background:var(--acc); }
  .prog--done > span { background:#12b76a; }
  .row { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }
  .small { font-size:.875rem; color:var(--muted); }
  .news { background:#eff4ff; border:1px solid #b2ccff; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; }
  .news h2 { margin-bottom:.35rem; }
  .news p { margin:.25rem 0; }
  .steps { list-style:none; margin:0; padding:0; }
  .steps li { display:grid; grid-template-columns:1.75rem 1fr; gap:.15rem .6rem; padding:.55rem 0; border-top:1px solid #eaecf0; }
  .steps li:first-child { border-top:0; }
  .steps .ic { font-size:1.2rem; line-height:1.4; }
  .steps .done .ic { color:#067647; } .steps .doing .ic { color:#b54708; } .steps .todo .ic { color:#1849a9; } .steps .info .ic { color:#475467; }
  .steps b { display:block; }
  .steps span.st { font-size:.9rem; color:var(--muted); }
  table.cfg { border-collapse:collapse; width:100%; font-size:.95rem; }
  table.cfg th, table.cfg td { text-align:left; padding:.4rem .5rem; border-top:1px solid #eaecf0; vertical-align:top; }
  table.cfg th { width:42%; color:var(--muted); font-weight:600; }
  code { background:#f2f4f7; padding:.05rem .3rem; border-radius:4px; word-break:break-all; }
  ul.plain { margin:.25rem 0 0; padding-left:1.2rem; }
  @media (max-width:480px) { dl { grid-template-columns:1fr; } dt { margin-top:.35rem; } table.cfg th { width:auto; } }
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="bar">
  <div class="bar__in">
    <span class="bar__org"><?= h($org) ?></span>
    <span class="bar__sp"></span>
    <span class="small" aria-label="Zalogowano jako"><?= h((string)($me['name'] ?? $me['email'] ?? '')) ?></span>
    <a href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel współpracownika</a>
    <a href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Wyloguj</a>
  </div>
</header>

<main id="tresc">
  <h1><i class="bi bi-envelope-at" aria-hidden="true"></i> Migracja poczty</h1>
  <p class="lead">Nowa skrzynka pocztowa z <strong>tym samym loginem</strong> co Twoje konto Microsoft 365 i <strong>nowym hasłem</strong>. Możesz też przenieść do niej pocztę z Microsoft 365.</p>

  <?php if ($flash): ?>
    <div class="msg msg--<?= h($flash['type']) ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <section class="news" aria-labelledby="hNews">
    <h2 id="hNews"><i class="bi bi-info-circle" aria-hidden="true"></i> Co się dzieje z pocztą?</h2>
    <p><?php if ($swTxt !== ''): ?><strong>Od <?= h($swTxt) ?> poczta</strong> w domenie @<?= h($domain) ?> <?= $swPast ? 'działa' : 'będzie działać' ?>
       <?php else: ?>Wkrótce poczta w domenie @<?= h($domain) ?> będzie działać<?php endif; ?>
       u polskiego dostawcy zamiast w Microsoft 365. <strong>Twój adres e-mail się nie zmienia.</strong></p>
    <p>Zrób kroki z listy poniżej<?= $swTxt !== '' && !$swPast ? ' przed ' . h($swTxt) : '' ?> — zajmie to około 5 minut. Dotychczasowa poczta skopiuje się sama, razem z folderami.</p>
  </section>

  <section class="card" aria-labelledby="hSteps">
    <h2 id="hSteps">Twoje kroki</h2>
    <ol class="steps">
      <?php foreach ($steps as $i => [$state, $title, $desc]):
        $icon = ['done' => 'bi-check-circle-fill', 'doing' => 'bi-hourglass-split', 'todo' => 'bi-circle', 'info' => 'bi-gear'][$state];
        $lab  = ['done' => 'gotowe', 'doing' => 'w toku', 'todo' => 'do zrobienia', 'info' => 'informacja'][$state]; ?>
      <li class="<?= $state ?>">
        <i class="bi <?= $icon ?> ic" aria-hidden="true"></i>
        <div><b><?= $i + 1 ?>. <?= h($title) ?> <span class="visually-hidden">(<?= $lab ?>)</span></b><span class="st"><?= h($desc) ?></span></div>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <section class="card" aria-labelledby="hBox">
    <h2 id="hBox">Twoja skrzynka</h2>
    <dl>
      <dt>Login Microsoft</dt><dd><?= $st['login'] !== '' ? h($st['login']) : '—' ?></dd>
      <dt>Nowa skrzynka</dt><dd><?= $st['mailbox'] ? h($st['mailbox']) : '—' ?></dd>
      <dt>Stan</dt>
      <dd><?php if ($st['exists'] === true): ?><span class="b b--ok">Skrzynka istnieje</span>
          <?php elseif ($st['exists'] === false): ?><span class="b b--info">Jeszcze jej nie ma</span>
          <?php else: ?>—<?php endif; ?></dd>
      <dt>Numer do SMS</dt>
      <dd><?= $st['phoneMasked'] !== '' ? h($st['phoneMasked']) : 'brak' ?> · <a href="<?= APP_URL ?>/panel/password.php">zmień w Ustawieniach konta</a></dd>
      <?php if (!empty($st['registry']['password_set_at'])): ?>
      <dt>Ostatnie hasło</dt><dd><?= h(substr((string)$st['registry']['password_set_at'], 0, 16)) ?></dd>
      <?php endif; ?>
    </dl>
  </section>

  <?php if ($st['problem'] !== ''): ?>
    <div class="msg msg--warning" role="alert"><?= h($st['problem']) ?></div>
  <?php elseif ($st['wait'] > 0): ?>
    <div class="msg msg--info" role="status">Kolejną prośbę o hasło możesz wysłać za <?= (int)ceil($st['wait'] / 60) ?> min.</div>
  <?php endif; ?>

  <?php if ($canAct): ?>
  <section class="card" aria-labelledby="hPass">
    <h2 id="hPass"><?= $exists ? 'Nowe hasło do skrzynki' : 'Załóż skrzynkę' ?></h2>
    <div class="imp" role="note">
      <strong>Ważne:</strong> hasło wyślemy <strong>tylko SMS-em</strong> na numer <?= h($st['phoneMasked']) ?> — nie pokażemy go na ekranie ani nie wyślemy e-mailem.
      <?php if ($exists): ?>Nowe hasło zastąpi obecne: programy pocztowe (telefon, Outlook) poproszą o nie ponownie.<?php endif; ?>
      Hasło do konta Microsoft 365 się nie zmienia.
    </div>
    <form method="post" action="<?= h(route('dhm.selfPost')) ?>" class="js-once">
      <?= Router::csrfField() ?>
      <div class="chk">
        <input type="checkbox" name="confirm" value="1" id="cfm" required>
        <label for="cfm">Numer <?= h($st['phoneMasked']) ?> jest mój i mam przy sobie ten telefon.</label>
      </div>
      <?php if ($copyOk && !$jobActive): ?>
      <div class="chk">
        <input type="checkbox" name="copy_mail" value="1" id="cpy" checked>
        <label for="cpy">Skopiuj też moją pocztę z Microsoft 365 (w tle; potrwa od kilku minut do kilku godzin)</label>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn"><i class="bi <?= $exists ? 'bi-key' : 'bi-envelope-plus' ?>" aria-hidden="true"></i>
        <?= $exists ? 'Wyślij mi nowe hasło SMS-em' : 'Załóż skrzynkę i wyślij hasło SMS-em' ?></button>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($copyOk): ?>
  <section class="card" aria-labelledby="hCopy">
    <h2 id="hCopy">Kopiowanie poczty z Microsoft 365</h2>
    <?php if ($job): ?>
      <?php $lab = DhostingCopy::LABELS[$job['status']] ?? $job['status'];
            $bcls = ['done' => 'b--ok', 'error' => 'b--err', 'cancelled' => 'b--off', 'waiting' => 'b--err'][$job['status']] ?? 'b--info'; ?>
      <p style="margin:0 0 .3rem">Stan: <span class="b <?= $bcls ?>"><?= h($lab) ?></span></p>
      <div class="prog<?= $job['status'] === 'done' ? ' prog--done' : '' ?>" role="progressbar" aria-label="Postęp kopiowania poczty" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pct ?>%"></span></div>
      <p class="small" style="margin:0 0 .6rem">Skopiowano <?= (int)$job['copied_items'] ?> z <?= (int)$job['total_items'] ?> wiadomości (<?= $pct ?>%)<?= (int)$job['failed_items'] ? ' · nie udało się: ' . (int)$job['failed_items'] : '' ?>.
        <?php if ($jobActive): ?>Strona odświeża się sama co minutę.<?php endif; ?></p>
      <?php if ($job['status'] === 'waiting'): ?>
        <div class="msg msg--warning" role="status">Serwer był niedostępny — spróbujemy ponownie <?= h(substr((string)$job['next_run_at'], 0, 16)) ?> (co <?= DhostingCopy::RETRY_HOURS ?> h). <?= $job['last_error'] ? h(mb_substr((string)$job['last_error'], 0, 200)) : '' ?></div>
      <?php elseif ($job['status'] === 'error' && $job['last_error']): ?>
        <div class="msg msg--danger" role="alert"><?= h(mb_substr((string)$job['last_error'], 0, 300)) ?></div>
      <?php elseif ($job['status'] === 'done'): ?>
        <p class="small">Zakończono <?= h(substr((string)$job['finished_at'], 0, 16)) ?>. Foldery z Outlooka są w nowej skrzynce pod tymi samymi nazwami.</p>
      <?php endif; ?>
      <?php if ($jobActive): ?>
      <form method="post" action="<?= h(route('dhm.selfCopy')) ?>" onsubmit="return confirm('Anulować kopiowanie poczty?')">
        <?= Router::csrfField() ?><input type="hidden" name="op" value="cancel">
        <button class="btn btn--danger" type="submit"><i class="bi bi-x-circle" aria-hidden="true"></i> Anuluj kopiowanie</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($exists && !$jobActive): ?>
      <?php if (!$job): ?><p class="small" style="margin-top:0">Masz już skrzynkę i hasło z SMS-a? Podaj je, a skopiujemy pocztę z Microsoft 365. Wiadomości już przeniesione nie zdublują się.</p>
      <?php else: ?><p class="small">Chcesz skopiować pocztę jeszcze raz (np. nowe wiadomości)? Podaj hasło do nowej skrzynki.</p><?php endif; ?>
      <form method="post" action="<?= h(route('dhm.selfCopy')) ?>" class="js-once">
        <?= Router::csrfField() ?><input type="hidden" name="op" value="start">
        <label class="fld" for="mpw">Hasło do nowej skrzynki</label>
        <input class="inp" type="password" id="mpw" name="mail_password" autocomplete="current-password" required aria-describedby="mpwHelp">
        <p class="help" id="mpwHelp">To hasło z SMS-a albo Twoje własne, jeśli było zmieniane. Przechowujemy je zaszyfrowane tylko do końca kopiowania.</p>
        <button class="btn btn--ghost" type="submit"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Skopiuj pocztę</button>
      </form>
    <?php elseif (!$exists && !$job): ?>
      <p class="small" style="margin:0">Najpierw załóż skrzynkę — kopiowanie możesz zaznaczyć od razu przy zakładaniu.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="hWhy">
    <h2 id="hWhy">Dlaczego przenosimy pocztę?</h2>
    <p style="margin-top:0">Microsoft zmienił zasady programu dla organizacji pozarządowych: od 1 lipca 2025 r. wycofał bezpłatne licencje
      Microsoft 365 Business Premium i Office 365 E1. Przy odnowieniu subskrypcji organizacje mogą przejść tylko na bezpłatny,
      uboższy pakiet Business Basic albo kupić licencje (z rabatem).</p>
    <p>Ostatnie wydarzenia pokazały, jak bardzo zależymy od jednego dostawcy: gdy zmienia zasady, nie mamy wyboru i musimy się dostosować.
      Dlatego pozbywamy się takiego uzależnienia (ang. <span lang="en">vendor lock-in</span>).</p>
    <p style="margin-bottom:0">Poczta to usługa, z której korzysta każdy z nas — dlatego przenosimy ją do polskiego dostawcy,
      u którego koszt nie zależy od zmian w programach Microsoft. Nowa poczta działa na otwartych standardach (IMAP i SMTP),
      więc w razie potrzeby przeniesiemy ją dalej bez zmiany Twojego adresu.</p>
  </section>

  <section class="card" aria-labelledby="hCfg">
    <h2 id="hCfg">Ustawienia programu pocztowego</h2>
    <p class="small" style="margin-top:0">Dla telefonu (Gmail, Poczta iOS), Outlooka, Thunderbirda — <?= $swTxt !== '' ? ($swPast ? 'od ' . h($swTxt) : 'od ' . h($swTxt) . ', nie wcześniej') : 'po przełączeniu' ?>. Typ konta: <strong>IMAP</strong>.</p>
    <table class="cfg">
      <caption class="visually-hidden">Dane do konfiguracji konta pocztowego</caption>
      <tr><th scope="row">Adres e-mail i login</th><td><?= $st['mailbox'] ? '<code>' . h($st['mailbox']) . '</code>' : 'pełny adres e-mail' ?></td></tr>
      <tr><th scope="row">Hasło</th><td>to z SMS-a (nie hasło do Microsoft 365)</td></tr>
      <tr><th scope="row">Poczta przychodząca (IMAP)</th><td><code><?= h($imapHost) ?></code>, port <?= (int)$imapPort ?>, SSL/TLS</td></tr>
      <tr><th scope="row">Poczta wychodząca (SMTP)</th><td><code><?= h(DhostingConfig::SMTP_HOST) ?></code>, port <?= (int)DhostingConfig::SMTP_PORT ?>, SSL/TLS, z uwierzytelnianiem (ten sam login i hasło)</td></tr>
    </table>
  </section>

  <section class="card" aria-labelledby="hSame">
    <h2 id="hSame">Co się nie zmienia</h2>
    <ul class="plain">
      <li>adres e-mail,</li>
      <li>hasło do konta Microsoft 365 i logowanie do SZO,</li>
      <li>wiadomości, które w dniu przełączenia jeszcze wpadną do starej skrzynki — dokopiujemy je, nic nie zginie.</li>
    </ul>
  </section>

  <div class="imp" role="note">
    <strong>Bezpieczeństwo:</strong> hasło do nowej skrzynki wysyłamy <strong>tylko SMS-em</strong>, nigdy e-mailem.
    Nikt z Fundacji nie poprosi Cię o podanie hasła — jeśli dostaniesz taką prośbę, to próba oszustwa.
    Zgłoś ją w <a href="<?= APP_URL ?>/panel/helpdesk.php">Helpdesku IT</a>.
  </div>

  <?php if ($web !== '' && $exists): ?>
    <p class="small">Poczta przez przeglądarkę: <a href="<?= h($web) ?>" target="_blank" rel="noopener"><?= h($web) ?></a> (otwiera się w nowej karcie)</p>
  <?php endif; ?>
</main>

<script>
document.querySelectorAll('form.js-once').forEach(function (f) {
  f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.textContent = 'Chwileczkę…'; } });
});
</script>
</body>
</html>
