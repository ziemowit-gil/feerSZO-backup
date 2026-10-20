<?php
/**
 * Widok kreatora pierwszego logowania (szata Metro jak System Tożsamości: płasko, cyan, bez zaokrągleń).
 * Zmienne: $user, $steps, $keys, $step, $isAdminView, $msg, $ctx, $url, $back, $panel, $SELF, $next.
 */
$name   = trim((string)($user['first_name'] ?? '')) ?: (explode(' ', trim((string)($user['name'] ?? '')))[0] ?? '');
$titles = ['welcome' => 'Witaj'] + array_map(fn($s) => $s['title'], $steps) + ['done' => 'Gotowe'];
$pos    = array_search($step, $keys, true);
$prev   = $pos > 0 ? $keys[$pos - 1] : null;
$nextK  = $pos < count($keys) - 1 ? $keys[$pos + 1] : null;
$u      = $ctx['u'];
$csrf   = csrf_field() . ($next !== '' ? '<input type="hidden" name="next" value="' . h($next) . '">' : '');
$state  = fn($d) => $d === true ? ['ok', '✔', 'Zrobione'] : ($d === false ? ['todo', '•', 'Do zrobienia'] : ['info', 'ℹ', 'Opcjonalnie']);
$ext    = 'target="_blank" rel="noopener"';
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pierwsze kroki — <?= h(defined('ORG_NAME') ? ORG_NAME : 'SZO') ?></title>
<style>
:root { --cyan:#1ba1e2; --cyan-d:#1583b8; --dark:#1d1d1d; --ok:#008a00; --todo:#fa6800; --err:#a20025; --line:#d9d9d9; --mute:#5c5c5c; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif; color: #1d1d1d; background: #f4f4f4; font-size: 15px; line-height: 1.5; }
a { color: var(--cyan-d); }
.bar { background: var(--dark); color: #fff; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid var(--cyan); }
.bar b { font-weight: 600; letter-spacing: .02em; }
.bar a { color: #fff; text-decoration: none; font-size: 14px; opacity: .85; }
.wrap { max-width: 1040px; margin: 0 auto; padding: 24px 16px 48px; display: grid; grid-template-columns: 260px 1fr; gap: 24px; }
@media (max-width: 760px) { .wrap { grid-template-columns: 1fr; } .side { order: 2; } }
.side ol { list-style: none; margin: 0; padding: 0; background: #fff; border: 1px solid var(--line); }
.side li a { display: flex; gap: 12px; align-items: center; padding: 12px 14px; color: inherit; text-decoration: none; border-left: 4px solid transparent; }
.side li a[aria-current] { border-left-color: var(--cyan); background: #eaf6fc; font-weight: 600; }
.side li + li a { border-top: 1px solid #eee; }
.dot { width: 28px; height: 28px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; background: #9a9a9a; font-size: 13px; }
.dot.ok { background: var(--ok); } .dot.todo { background: var(--todo); } .dot.info { background: var(--cyan); }
.main { background: #fff; border: 1px solid var(--line); padding: 28px; min-width: 0; }
h1 { font-size: 26px; font-weight: 300; margin: 0 0 6px; }
h2 { font-size: 17px; font-weight: 600; margin: 24px 0 8px; }
.lead { color: var(--mute); margin: 0 0 20px; }
.msg { padding: 10px 14px; margin: 0 0 18px; border-left: 4px solid; background: #f7f7f7; }
.msg.ok { border-color: var(--ok); } .msg.warn { border-color: var(--todo); } .msg.error { border-color: var(--err); }
.status { display: inline-flex; gap: 8px; align-items: center; font-weight: 600; margin-bottom: 10px; }
.status.ok { color: var(--ok); } .status.todo { color: #b54a00; } .status.info { color: var(--cyan-d); }
label { display: block; font-weight: 600; font-size: 14px; margin: 12px 0 4px; }
input[type=text], input[type=tel] { width: 100%; max-width: 360px; padding: 9px 10px; border: 2px solid #c8c8c8; font: inherit; border-radius: 0; }
input:focus, button:focus-visible, a:focus-visible { outline: 3px solid var(--cyan); outline-offset: 2px; }
.btn { display: inline-block; padding: 9px 18px; border: 2px solid var(--cyan); background: var(--cyan); color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; border-radius: 0; }
.btn:hover { background: var(--cyan-d); border-color: var(--cyan-d); }
.btn.ghost { background: #fff; color: var(--dark); border-color: #9a9a9a; }
.btn.ghost:hover { background: #f0f0f0; }
.btn.link { background: none; border-color: transparent; color: var(--mute); font-weight: 400; text-decoration: underline; padding-left: 0; padding-right: 0; }
.row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 14px; }
.nav { display: flex; justify-content: space-between; align-items: center; gap: 10px; border-top: 1px solid #eee; margin-top: 28px; padding-top: 18px; flex-wrap: wrap; }
.tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; margin: 10px 0; }
.tile { display: block; padding: 14px; color: #fff; text-decoration: none; min-height: 88px; background: var(--c, var(--cyan)); }
.tile b { display: block; font-size: 15px; } .tile span { font-size: 13px; opacity: .9; }
.tile:hover { filter: brightness(1.08); }
ul.plain { padding-left: 18px; margin: 6px 0; } ul.plain li { margin: 3px 0; }
code { background: #f0f0f0; padding: 1px 5px; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
</head>
<body>
<header class="bar">
  <b>Pierwsze kroki</b>
  <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="_action" value="later"><button class="btn link" style="color:#fff">Dokończę później ✕</button></form>
</header>

<div class="wrap">
  <nav class="side" aria-label="Kroki kreatora">
    <ol>
      <?php foreach ($keys as $i => $k):
        $d = $k === 'welcome' ? ($pos > 0 ? true : null) : ($k === 'done' ? null : $steps[$k]['done']);
        [$cls, $ico, $lbl] = $k === 'welcome' || $k === 'done' ? ['', (string)($i + 1), ''] : $state($d); ?>
      <li><a href="<?= h($url(['step' => $k])) ?>" <?= $k === $step ? 'aria-current="step"' : '' ?>>
        <span class="dot <?= $cls ?>" aria-hidden="true"><?= $cls ? $ico : $i + 1 ?></span>
        <span><?= h($titles[$k]) ?><?php if ($lbl): ?><span class="sr"> — <?= h($lbl) ?></span><?php endif; ?></span>
      </a></li>
      <?php endforeach; ?>
    </ol>
    <?php if (is_admin()): ?>
      <p style="font-size:13px;margin:12px 4px"><a href="<?= h($url(['admin' => 1])) ?>">Ustawienia kreatora (administrator)</a></p>
    <?php endif; ?>
  </nav>

  <main class="main" id="tresc">
    <?php if ($msg): ?><div class="msg <?= h($msg[0]) ?>" role="<?= $msg[0] === 'error' ? 'alert' : 'status' ?>"><?= h($msg[1]) ?></div><?php endif; ?>

    <?php if ($isAdminView): $mode = firstRunEnabled() ? 'on' : 'off'; ?>
      <h1>Ustawienia kreatora</h1>
      <p class="lead">Kreator pokazuje się raz — przy pierwszym wejściu nowej osoby do SZO albo Panelu współpracownika. Konta istniejące w chwili wdrożenia zostały oznaczone jako „ukończone”.</p>
      <form method="post"><?= $csrf ?><input type="hidden" name="_action" value="admin_save">
        <label><input type="radio" name="mode" value="on" <?= $mode === 'on' ? 'checked' : '' ?>> Włączony — nowe osoby przechodzą kreator</label>
        <label><input type="radio" name="mode" value="off" <?= $mode === 'off' ? 'checked' : '' ?>> Wyłączony</label>
        <div class="row"><button class="btn">Zapisz</button></div>
      </form>
      <h2>Wymuś ponownie</h2>
      <div class="row">
        <form method="post" onsubmit="return confirm('Pokazać kreator ponownie WSZYSTKIM osobom?')"><?= $csrf ?><input type="hidden" name="_action" value="admin_reset_all"><button class="btn ghost">Pokaż wszystkim ponownie</button></form>
        <form method="post"><?= $csrf ?><input type="hidden" name="_action" value="admin_reset_me"><button class="btn ghost">Przejdź kreator jeszcze raz (ja)</button></form>
      </div>
      <h2>Co kreator sprawdza</h2>
      <ul class="plain">
        <li><b>Telefon</b> — tylko gdy SMS są włączone (Ustawienia SMS); potwierdzenie kodem.</li>
        <li><b>Microsoft 365</b> — gdy działa logowanie Microsoft albo osoba ma konto M365 (umowa z kontem M365).</li>
        <li><b>Profil w katalogu</b> — zawsze; „Zrobione” liczy się wg wymaganych pól z ustawień profilu (Pola profilu).</li>
        <li><b>Stopka e-mail</b> — tylko osobom ze skrzynką M365.</li>
      </ul>

    <?php elseif ($step === 'welcome'): ?>
      <h1>Witaj<?= $name !== '' ? ', ' . h($name) : '' ?>!</h1>
      <p class="lead">To Twoje pierwsze logowanie. W kilku krokach przygotujemy konto — zajmie to 2–3 minuty. Każdy krok możesz pominąć i wrócić do niego później.</p>
      <ul class="plain">
        <?php foreach ($steps as $s): ?><li><b><?= h($s['title']) ?></b> — <?= h($s['short']) ?></li><?php endforeach; ?>
      </ul>

    <?php elseif ($step === 'phone'): [$c, $i, $l] = $state($steps['phone']['done']); ?>
      <h1>Telefon</h1>
      <p class="lead">Numer komórkowy służy do kodów logowania, resetu hasła i ważnych powiadomień SMS. Widzą go tylko osoby uprawnione.</p>
      <p class="status <?= $c ?>"><span aria-hidden="true"><?= $i ?></span> <?= $steps['phone']['done'] ? 'Numer potwierdzony: ' . h($u['phone_number']) : 'Numer niepotwierdzony' ?></p>
      <?php if ($ctx['phone_pending'] !== ''): ?>
        <form method="post"><?= $csrf ?><input type="hidden" name="_action" value="phone_verify">
          <label for="code">Kod z SMS wysłany na <?= h($ctx['phone_pending']) ?></label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
          <div class="row"><button class="btn">Potwierdź</button></div>
        </form>
        <form method="post" class="row"><?= $csrf ?><input type="hidden" name="_action" value="phone_cancel"><button class="btn link">Zmień numer</button></form>
      <?php else: ?>
        <form method="post"><?= $csrf ?><input type="hidden" name="_action" value="phone_send">
          <label for="phone"><?= $steps['phone']['done'] ? 'Zmień numer' : 'Numer telefonu komórkowego' ?></label>
          <input id="phone" name="phone_number" type="tel" autocomplete="tel" value="<?= h($steps['phone']['done'] ? '' : (string)($u['phone_number'] ?? '')) ?>" placeholder="np. 600 100 200" required>
          <div class="row"><button class="btn">Wyślij kod SMS</button></div>
        </form>
      <?php endif; ?>

    <?php elseif ($step === 'm365'): $mb = firstRunMailbox($u); $ms = function_exists('ms_login_available') && ms_login_available(); ?>
      <h1>Microsoft 365 i Outlook</h1>
      <p class="lead">Konto Microsoft 365 daje pocztę, Teams i pliki — i pozwala logować się do SZO jednym kliknięciem.</p>
      <?php if ($mb === '' && empty($u['microsoft_id'])): ?>
        <div class="status todo" role="note" style="display:block">
          <b>Nie masz jeszcze konta służbowego Microsoft 365 — to normalne przy pierwszym logowaniu.</b>
          <ul style="margin:8px 0 0 18px;padding:0">
            <li>Nic tu nie musisz robić — kliknij <b>Dalej</b>. Panel działa już na obecnym logowaniu.</li>
            <li>Konto służbowe zakłada dział IT. Gdy będzie gotowe, dostaniesz wiadomość z adresem służbowym.</li>
            <li>Wtedy wróć do tego kroku (lub zaloguj się przez Microsoft) — konta zostaną połączone. Brakuje konta dłużej niż kilka dni? <a href="<?= h(APP_URL) ?>/helpdesk/new.php" <?= $ext ?>>Zgłoś w Helpdesku ↗</a></li>
          </ul>
          <p style="margin:8px 0 0"><a href="<?= h(APP_URL) ?>/uslugi-it/uslugi/moj-login/pierwsze-logowanie" <?= $ext ?>>Instrukcja: pierwsze logowanie i zgody ↗</a></p>
        </div>
      <?php endif; ?>
      <?php if ($ms): ?>
        <h2>Logowanie przez Microsoft</h2>
        <?php if (!empty($u['microsoft_id'])): ?>
          <p class="status ok"><span aria-hidden="true">✔</span> Konto połączone — na stronie logowania używaj „Zaloguj przez Microsoft”.</p>
        <?php else: ?>
          <p class="status todo"><span aria-hidden="true">•</span> Konto niepołączone</p>
          <p>Kliknij i zaloguj się kontem służbowym<?= $mb !== '' ? ' <b>' . h($mb) . '</b>' : '' ?>. Wrócisz tutaj.</p>
          <div class="row"><a class="btn" href="<?= h(ms_auth_url($url(['step' => 'm365']))) ?>">Połącz z kontem Microsoft</a></div>
        <?php endif; ?>
      <?php endif; ?>
      <h2>Poczta w Outlooku</h2>
      <?php if ($mb !== ''): ?><p>Twój adres: <b><?= h($mb) ?></b></p><?php else: ?><p>Nie widzimy jeszcze Twojej skrzynki Microsoft 365 — jeśli ją masz, dostęp działa tak samo.</p><?php endif; ?>
      <div class="tiles">
        <a class="tile" style="--c:#0050ef" href="https://outlook.office.com/mail/" <?= $ext ?>><b>Outlook w przeglądarce</b><span>Działa od razu, bez instalacji</span></a>
        <a class="tile" style="--c:#00aba9" href="https://apps.apple.com/app/microsoft-outlook/id951937596" <?= $ext ?>><b>iPhone / iPad</b><span>Outlook z App Store</span></a>
        <a class="tile" style="--c:#008a00" href="https://play.google.com/store/apps/details?id=com.microsoft.office.outlook" <?= $ext ?>><b>Android</b><span>Outlook z Google Play</span></a>
        <a class="tile" style="--c:#6a00ff" href="https://www.office.com/" <?= $ext ?>><b>Komputer</b><span>Pobierz Office / Outlook z office.com</span></a>
      </div>
      <p style="font-size:14px;color:var(--mute)">Na komputerze: Outlook → <i>Plik → Dodaj konto</i> → wpisz swój adres służbowy → zaloguj się. Na telefonie: zainstaluj Outlook i dodaj to samo konto.</p>
      <?php if ($panel): ?><p><a href="<?= h(APP_URL) ?>/panel/m365.php" <?= $ext ?>>Moje konto Microsoft 365 (hasło, alias) ↗</a></p><?php endif; ?>

    <?php elseif ($step === 'profile'): [$c, $i, $l] = $state($steps['profile']['done']); ?>
      <h1>Profil w katalogu</h1>
      <p class="lead">Katalog współpracowników pomaga innym Cię znaleźć: zdjęcie, kilka słów o sobie, kontakt i umiejętności.</p>
      <p class="status <?= $c ?>"><span aria-hidden="true"><?= $i ?></span> <?= $steps['profile']['done'] === true ? 'Profil uzupełniony' : ($steps['profile']['done'] === false ? 'Brakuje wymaganych informacji' : 'Uzupełnij według uznania') ?></p>
      <div class="row">
        <a class="btn" href="<?= h($steps['profile']['url']) ?>" <?= $ext ?>>Edytuj profil ↗</a>
        <a class="btn ghost" href="<?= h($url(['step' => 'profile'])) ?>">Sprawdź ponownie</a>
      </div>
      <p style="font-size:14px;color:var(--mute)">Profil otworzy się w nowej karcie — po zapisaniu wróć tutaj.</p>

    <?php elseif ($step === 'stopka'): $sp = $ctx['stopka']; [$c, $i, $l] = $state($steps['stopka']['done']); ?>
      <h1>Stopka e-mail</h1>
      <p class="lead">Stopka dopisuje się automatycznie pod wiadomościami z Twojej skrzynki. Imię, nazwisko i adres już znamy — potrzebujemy stanowiska i telefonu.</p>
      <p class="status <?= $c ?>"><span aria-hidden="true"><?= $i ?></span> <?= $steps['stopka']['done'] ? 'Dane do stopki zapisane' : 'Uzupełnij stanowisko' ?></p>
      <form method="post"><?= $csrf ?><input type="hidden" name="_action" value="stopka_save">
        <label for="jt">Stanowisko</label>
        <input id="jt" name="job_title" type="text" value="<?= h($sp['job_title']) ?>" placeholder="np. Koordynator projektu" required>
        <label for="ph">Telefon w stopce</label>
        <input id="ph" name="phone" type="tel" value="<?= h($sp['phone']) ?>">
        <div class="row"><button class="btn">Zapisz</button><a href="<?= h(APP_URL) ?>/modules/stopka/index.php" <?= $ext ?>>Podgląd i kopiowanie stopki ↗</a></div>
      </form>

    <?php else: /* done */ ?>
      <h1>Gotowe!</h1>
      <p class="lead">Podsumowanie — to, czego brakuje, możesz uzupełnić później w Systemie Tożsamości i w profilu.</p>
      <ul class="plain" style="list-style:none;padding:0">
        <?php foreach ($steps as $k => $s): [$c, $i, $l] = $state($s['done']); ?>
          <li><span class="status <?= $c ?>" style="margin:0"><span aria-hidden="true"><?= $i ?></span> <?= h($s['title']) ?></span> — <?= h($l) ?> · <a href="<?= h($url(['step' => $k])) ?>">wróć do kroku</a></li>
        <?php endforeach; ?>
      </ul>
      <form method="post"><?= $csrf ?><input type="hidden" name="_action" value="finish">
        <div class="row"><button class="btn">Zakończ i przejdź dalej</button></div>
      </form>
    <?php endif; ?>

    <?php if (!$isAdminView && $step !== 'done'): ?>
    <div class="nav">
      <span><?php if ($prev): ?><a class="btn ghost" href="<?= h($url(['step' => $prev])) ?>">← Wstecz</a><?php endif; ?></span>
      <a class="btn" href="<?= h($url(['step' => $nextK])) ?>"><?= $step === 'welcome' ? 'Zaczynamy →' : 'Dalej →' ?></a>
    </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
