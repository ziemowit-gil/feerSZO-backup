<?php
/**
 * karty30/ti/login.php — Centralny punkt logowania TI (wygląd Tożsamości: METRO UI, układ dwukolumnowy jak w iPKO).
 *
 * Krok 1: wybór roli (Kursant / Prowadzący / Rodzic / Upoważniony). Krok 2: formularz wybranej roli.
 * POST trafia do sub-handlerów: kursant → tozsamosc/kursant.php, dydaktyk/login.php,
 * kursant/parent_login.php, kursant/authp_login.php. Błędy wracają kodem e=N w query string.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/org_case.php';   // odmiana nazwy organizacji

karty30_migrate();

// Linki do stron TI jako ścieżki od korzenia: na ti.* i kursant.* ta strona działa pod /login.php,
// więc względne „sso.php” prowadziło do /sso.php (404).
$TI_BASE = rtrim((string)(parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/') . '/karty30/ti/';

// ── Komunikaty błędów ─────────────────────────────────────────────────────────
$ERR = [
    1 => 'Nieprawidłowy login lub hasło.',
    2 => 'Dostęp do panelu wstrzymany przez opiekuna. Skontaktuj się z rodzicem lub opiekunem.',
    3 => 'Konto jest nieaktywne. Skontaktuj się z prowadzącym.',
    4 => 'To konto nie ma uprawnień dydaktyka TI. Skontaktuj się z administratorem.',
    5 => 'Zbyt wiele nieudanych prób logowania. Odczekaj chwilę i spróbuj ponownie.',
    6 => 'Nieprawidłowy login lub hasło konta opiekuna.',
    7 => 'Nieprawidłowy login lub hasło osoby upoważnionej.',
];
$ec    = (int)($_GET['e'] ?? 0);
$error = $ERR[$ec] ?? null;

// ── Aktywna zakładka i krok ───────────────────────────────────────────────────
$tab   = in_array($_GET['tab'] ?? '', ['kursant','dydaktyk','rodzic','up'], true) ? $_GET['tab'] : 'kursant';
$krok1 = !in_array($_GET['tab'] ?? '', ['kursant','dydaktyk','rodzic','up'], true);

// ── Prefill pól po błędzie ────────────────────────────────────────────────────
$prefill_login = h($_GET['l'] ?? '');
$prefill_email = h($_GET['m'] ?? '');

// ── Microsoft 365 dla prowadzącego ────────────────────────────────────────────
$office_login_url = rtrim(APP_URL, '/') . '/auth/ms365.php?redirect='
    . urlencode(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/office_enter.php');
$office_available = function_exists('ms_login_available') && ms_login_available();
// Prowadzący zwykle mają konto Microsoft 365 — najpierw wybór (jak na logowaniu SZO):
// poczta w domenie fundacji → od razu Microsoft 365; prywatny e-mail → formularz hasła.
// Bez Microsoft 365 i po błędzie logowania od razu formularz hasła.
$dyd_haslo = !$office_available || $ec > 0 || ($_GET['konto'] ?? '') === 'prywatny';

// Lewa kolumna: instrukcja zależna od roli.
$_side = [
    'kursant'  => in_array($_GET['fn'] ?? '', ['szk', 'konsultacje'], true)
        ? ['Wybierz, kim jesteś.', 'Wpisz login, który dostałeś SMS-em albo na wydruku.', 'Wpisz hasło. Przy pierwszym logowaniu dostaniesz kod SMS i ustawisz własne hasło.']
        : ['Wybierz, kim jesteś.', 'Wpisz login.', 'Zobaczysz swój obrazek. Jeśli go nie widzisz albo widzisz inny, nie wpisuj hasła.', 'Wpisz hasło.'],
    'dydaktyk' => ['Wybierz „Prowadzący”.', 'Masz pocztę @feer.org.pl? Wybierz „Mam pocztę w domenie fundacji” — zalogujesz się kontem Microsoft 365.', 'Nie masz poczty w domenie fundacji? Wybierz „Mam prywatny e-mail” i wpisz e-mail oraz hasło.'],
    'rodzic'   => ['Wybierz „Rodzic”.', 'Wpisz login opiekuna nadany w placówce.', 'Wpisz hasło. Nie masz hasła? Zaloguj się kodem SMS.'],
    'up'       => ['Wybierz „Upoważniony”.', 'Wpisz login i hasło nadane przez kursanta.'],
];
$_side_steps = $krok1 ? $_side['kursant'] : $_side[$tab];

$_titles = [
    'kursant'  => 'Panel kursanta',
    'dydaktyk' => 'Panel prowadzącego',
    'rodzic'   => 'Panel rodzica',
    'up'       => 'Dostęp upoważnionego',
];
$_tabs = [
    'kursant'  => ['label' => 'Kursant',     'icon' => 'bi-pc-display',   'who' => 'Uczestnik zajęć',                  'kolor' => '#0f6fa8'],
    'dydaktyk' => ['label' => 'Prowadzący',  'icon' => 'bi-easel2',       'who' => 'Prowadzę zajęcia',                 'kolor' => '#1d1d1d'],
    'rodzic'   => ['label' => 'Rodzic',      'icon' => 'bi-people',       'who' => 'Opiekun kursanta',                 'kolor' => '#00a300'],
    'up'       => ['label' => 'Upoważniony', 'icon' => 'bi-person-check', 'who' => 'Osoba upoważniona przez kursanta', 'kolor' => '#7e3878'],
];
// Krok 1 z podziałem wg roli (kto) i funkcji (co robi): każdy kafel to rola ($tab) + funkcja (fn),
// która podpowiada login (kursant) albo tryb pracy po zalogowaniu (prowadzący/konsultant → choose_mode.php).
$_fn = in_array($_GET['fn'] ?? '', ['szkolenia', 'szk', 'konsultacje', 'kierownik'], true) ? (string)$_GET['fn'] : '';
$_groups = [
    ['title' => 'Uczestnicy', 'items' => [
        ['tab' => 'kursant', 'fn' => 'szkolenia',   'kolor' => '#0f6fa8', 'icon' => 'bi-pc-display',       'label' => 'Uczestnik szkoleń',          'who' => 'Chodzę na zajęcia w grupie'],
        ['tab' => 'kursant', 'fn' => 'szk',         'kolor' => '#0c5b8a', 'icon' => 'bi-lightning-charge', 'label' => 'Uczestnik szkolenia krótkiego', 'who' => 'Mam kilka spotkań w małej grupie'],
        ['tab' => 'kursant', 'fn' => 'konsultacje', 'kolor' => '#2b6cb0', 'icon' => 'bi-clipboard2-check', 'label' => 'Beneficjent konsultacji',    'who' => 'Mam umówioną konsultację'],
    ]],
    ['title' => 'Rodzina i osoby bliskie', 'items' => [
        ['tab' => 'rodzic',  'fn' => '',            'kolor' => '#00a300', 'icon' => 'bi-people',           'label' => 'Rodzic / opiekun',         'who' => 'Opiekuję się dzieckiem, które tu się uczy'],
        ['tab' => 'up',      'fn' => '',            'kolor' => '#7e3878', 'icon' => 'bi-person-check',     'label' => 'Osoba upoważniona',        'who' => 'Kursant dał mi dostęp do swoich spraw'],
    ]],
    ['title' => 'Kadra', 'items' => [
        ['tab' => 'dydaktyk', 'fn' => 'szkolenia',   'kolor' => '#1d1d1d', 'icon' => 'bi-easel2',           'label' => 'Prowadzący zajęcia',       'who' => 'Prowadzę grupy i uzupełniam dziennik'],
        ['tab' => 'dydaktyk', 'fn' => 'konsultacje', 'kolor' => '#3b3b3b', 'icon' => 'bi-chat-square-text', 'label' => 'Konsultant',               'who' => 'Umawiam i prowadzę konsultacje'],
        ['tab' => 'dydaktyk', 'fn' => 'kierownik',   'kolor' => '#5b5b5b', 'icon' => 'bi-building',         'label' => 'Kierownik',                'who' => 'Zarządzam całą placówką'],
    ]],
];
// Zapamiętany wybór funkcji przez prowadzącego — panel ustawia tryb pracy po zalogowaniu (choose_mode.php czyta cookie)
if ($tab === 'dydaktyk' && in_array($_fn, ['szkolenia', 'konsultacje', 'kierownik'], true)) {
    setcookie('ti_login_fn', $_fn, ['expires' => time() + 600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}
$_kursant_hint = ['szkolenia' => 'Login (albo własny alias)', 'szk' => 'Login z SMS-a lub wydruku',
                  'konsultacje' => 'Login z SMS-a po umówieniu konsultacji'][$_fn] ?? 'Login lub własny alias';
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($krok1 ? 'Logowanie — Dydaktyka 3' : $_titles[$tab] . ' — Logowanie') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; color: #1d1d1d; }
  .tz-split { display: flex; flex-direction: column; min-height: 100vh; }
  /* Telefon: najpierw formularz, potem panel z instrukcją. */
  @media (max-width: 767.98px) { .tz-main { order: 1; } .tz-side { order: 2; } }
  @media (min-width: 768px) { .tz-split { flex-direction: row; } }
  .tz-side { background: #1d1d1d; color: #fff; padding: 2.5rem 2rem; flex: 1 1 50%; display: flex; flex-direction: column; justify-content: center; }
  .tz-side h2 { font-size: 1.6rem; font-weight: 700; margin: 0 0 .5rem; }
  .tz-side .sub { color: #cbd5e1; font-size: .95rem; }
  .tz-side ul { padding-left: 1.1rem; margin: 1.25rem 0 0; font-size: .92rem; }
  .tz-side li { margin-bottom: .5rem; }
  .tz-side a { color: #7dd3fc; }
  .tz-main { flex: 1 1 50%; display: flex; align-items: center; justify-content: center; padding: 2rem 1.25rem; }
  .tz-card { background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.08); padding: 2.5rem 2rem; width: 100%; max-width: 420px; }
  .tz-card.tz-wide { max-width: 640px; }
  .tz-card .form-control { border-radius: 0; box-shadow: none; padding: .7rem .9rem; font-size: 1.05rem; }
  .tz-card label { font-weight: 600; font-size: .92rem; margin-bottom: .3rem; }
  .tz-btn { border-radius: 0; background: #0f6fa8; border: 0; color: #fff; font-weight: 600; padding: .75rem 1rem; width: 100%; text-align: center; text-decoration: none; display: block; }
  .tz-btn:hover { background: #0c5b8a; color: #fff; }
  .tz-btn-ghost { background: #fff; color: #1d1d1d; border: 2px solid #0f6fa8; }
  .tz-btn-ghost:hover { background: #e6f6fc; color: #1d1d1d; }
  .tz-tile { display: block; height: 100%; padding: 1rem; text-decoration: none; color: #fff; border-radius: 0; font-family: "Segoe UI", system-ui, sans-serif; }
  .tz-tile:hover { color: #fff; filter: brightness(.92); }
  .tz-choice { position: relative; padding: 1rem 1.1rem; min-height: 88px; }
  .tz-choice-badge { position: absolute; top: .6rem; right: .6rem; background: #fff; color: #1d1d1d; font-size: .75rem; font-weight: 700; padding: .1rem .45rem; }
  .tz-tile:focus-visible, .tz-card :focus-visible, .tz-side a:focus-visible, .tz-btn:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
  .tz-footer { text-align: center; font-size: .85rem; color: #4b5563; padding: 1rem; }
  .tz-footer a { color: #1d4ed8; text-decoration: none; margin: 0 .5rem; }
  .tz-hint { font-size: .85rem; color: #4b5563; margin-top: .75rem; }
  .pass-wrap { position: relative; }
  .pass-wrap .form-control { padding-right: 3rem; }
  .pass-toggle { position: absolute; right: 0; top: 0; height: 100%; width: 3rem; border: 0; background: transparent; }
</style>
</head>
<body>
<div role="region" aria-label="Informacja o logowaniu" style="background:#0f6fa8;color:#fff;padding:.6rem 1rem;text-align:center;font-weight:600;font-size:.95rem">
  Logowanie centralne przez System Tożsamości — adresy i zapisane linki bez zmian.
  <a href="<?= h($TI_BASE) ?>sso.php" style="color:#fff;text-decoration:underline;margin-left:.5rem">Czym jest SSO?</a>
</div>
<div class="tz-split">
  <aside class="tz-side" aria-labelledby="tz-side-h">
    <div class="small fw-bold mb-2" style="letter-spacing:.06em">FEER · System Tożsamości</div>
    <h2 id="tz-side-h"><?= h($krok1 ? 'Dydaktyka 3' : $_titles[$tab]) ?></h2>
    <p class="sub mb-0">Jak się zalogować:</p>
    <ol>
      <?php foreach ($_side_steps as $_st): ?><li><?= h($_st) ?></li><?php endforeach; ?>
    </ol>
  </aside>

  <main class="tz-main" id="ti-login-main">
    <div class="tz-card<?= $krok1 ? ' tz-wide' : '' ?>">
      <h1 class="h4 fw-bold mb-2"><?= h($krok1 ? 'Logowanie — Dydaktyka 3' : org_login_title($_titles[$tab])) ?></h1>

      <?php if ($error): ?>
      <div class="alert alert-danger small py-2 rounded-0" role="alert" aria-live="assertive"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($krok1): ?>
      <h2 class="h6 fw-semibold mb-3">Krok 1 z 2: wybierz, kim jesteś i z czego korzystasz</h2>
      <?php foreach ($_groups as $_g): ?>
      <h3 class="small text-uppercase fw-bold text-body-secondary mt-3 mb-2" style="letter-spacing:.05em"><?= h($_g['title']) ?></h3>
      <div class="row g-2" role="list" aria-label="<?= h($_g['title']) ?>">
        <?php foreach ($_g['items'] as $_i): ?>
        <div class="col-6 col-sm-4" role="listitem">
          <a class="tz-tile" href="login.php?tab=<?= h($_i['tab']) ?><?= $_i['fn'] !== '' ? '&amp;fn=' . h($_i['fn']) : '' ?>" style="background:<?= h($_i['kolor']) ?>">
            <i class="bi <?= h($_i['icon']) ?> fs-4" aria-hidden="true"></i>
            <span class="d-block fw-semibold mt-1"><?= h($_i['label']) ?></span>
            <span class="d-block small"><?= h($_i['who']) ?></span>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <?php /* Nabór do grup TI (modules/ti): formularz dla rodziców i konto kandydata — adresy względne, zostają na bieżącym hoście */ ?>
      <h3 class="small text-uppercase fw-bold text-body-secondary mt-3 mb-2" style="letter-spacing:.05em">Zapisy na zajęcia</h3>
      <div class="row g-2" role="list" aria-label="Zapisy na zajęcia">
        <?php if (org_setting('ti_nabor_enabled') === '1'): ?>
        <div class="col-6 col-sm-4" role="listitem">
          <a class="tz-tile" href="/nabor" style="background:#1d68f2">
            <i class="bi bi-person-plus fs-4" aria-hidden="true"></i>
            <span class="d-block fw-semibold mt-1">Zapisz się na zajęcia</span>
            <span class="d-block small">Formularz zgłoszenia dla rodziców i opiekunów</span>
          </a>
        </div>
        <?php endif; ?>
        <div class="col-6 col-sm-4" role="listitem">
          <a class="tz-tile" href="/nabor/logowanie" style="background:#164eb6">
            <i class="bi bi-person-badge fs-4" aria-hidden="true"></i>
            <span class="d-block fw-semibold mt-1">Konto kandydata</span>
            <span class="d-block small">Status zgłoszeń, wiadomości, dokumenty</span>
          </a>
        </div>
      </div>

      <?php else: ?>
      <p class="small mb-3">Krok 2 z 2: wpisz dane logowania dla roli: <strong><?= h($_tabs[$tab]['label']) ?></strong>.
        <a href="login.php">Zmień rolę</a></p>

      <?php if ($tab === 'kursant'): ?>
      <form method="post" action="<?= h(rtrim(APP_URL, '/')) ?>/tozsamosc/kursant.php" autocomplete="on">
        <div class="mb-3">
          <label for="stu-login">Login</label>
          <div class="d-flex align-items-start gap-3">
            <div class="flex-grow-1 min-w-0">
              <input type="text" class="form-control form-control-lg" id="stu-login" name="login"
                     value="<?= $prefill_login ?>" required autofocus autocomplete="username"
                     placeholder="<?= h($_kursant_hint) ?>"
                     <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
            </div>
            <div class="d-flex align-items-center gap-2" id="stu-sec" style="display:none;max-width:11rem" aria-live="polite">
              <div id="stu-sec-img" style="width:56px;height:56px;flex-shrink:0" aria-hidden="true"></div>
              <div class="small" id="stu-sec-label"></div>
            </div>
          </div>
        </div>
        <div class="mb-4">
          <label for="stu-password">Hasło</label>
          <div class="pass-wrap">
            <input type="password" class="form-control form-control-lg" id="stu-password" name="password" autocomplete="current-password">
            <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('stu-password', this)">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
          <div class="form-text">Przy pierwszym logowaniu hasła nie wpisujesz.</div>
        </div>
        <button type="submit" class="tz-btn">Dalej</button>
      </form>
      <?php if (org_setting('ti_nabor_enabled') === '1'): ?>
      <p class="tz-hint">Nie masz konta? <a href="/nabor">Zapisz się na zajęcia</a> — po przyjęciu dostaniesz login i hasło. Złożone zgłoszenie sprawdzisz w <a href="/nabor/logowanie">koncie kandydata</a>.</p>
      <?php else: ?>
      <p class="tz-hint">Nie masz konta? <a href="<?= h($TI_BASE) ?>zapisy.php">Zostaw kontakt</a> — odezwiemy się w sprawie zapisów.</p>
      <?php endif; ?>
      <?php if (k30_pfron_enabled()): ?>
      <a href="kursant/pfron.php" class="tz-btn tz-btn-ghost mt-3">Rozliczenia PFRON (Aktywny Samorząd)</a>
      <?php endif; ?>

      <?php elseif ($tab === 'dydaktyk'): ?>
      <?php if (!$dyd_haslo): ?>
      <p class="mb-3">Prowadzący zwykle logują się kontem <strong>Microsoft 365</strong>. Jaki adres e-mail masz?</p>
      <div class="d-grid gap-2" role="list" aria-label="Rodzaj adresu e-mail">
        <a role="listitem" class="tz-tile tz-choice" href="<?= h($office_login_url) ?>" style="background:#0f6fa8"
           aria-label="Mam pocztę w domenie fundacji — zalecane. Zaloguj przez Microsoft 365, zostaniesz przekierowany do Microsoft">
          <span class="tz-choice-badge">Zalecane</span>
          <i class="bi bi-building fs-4" aria-hidden="true"></i>
          <span class="d-block fw-semibold mt-1">Mam pocztę w domenie fundacji</span>
          <span class="d-block small">Adres @feer.org.pl — logowanie przez Microsoft 365</span>
        </a>
        <a role="listitem" class="tz-tile tz-choice" href="login.php?tab=dydaktyk&amp;konto=prywatny" style="background:#1d1d1d">
          <i class="bi bi-envelope fs-4" aria-hidden="true"></i>
          <span class="d-block fw-semibold mt-1">Mam prywatny adres e-mail</span>
          <span class="d-block small">Logowanie e-mailem i hasłem</span>
        </a>
      </div>
      <?php else: ?>
        <form method="post" action="dydaktyk/login.php" autocomplete="on">
          <div class="mb-3">
            <label for="dyd-email">Adres e-mail</label>
            <input type="email" class="form-control form-control-lg" id="dyd-email" name="email"
                   value="<?= $prefill_email ?>" required autocomplete="username"
                   placeholder="imie.nazwisko@feer.org.pl"
                   autofocus
                   <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
          </div>
          <div class="mb-4">
            <label for="dyd-password">Hasło</label>
            <div class="pass-wrap">
              <input type="password" class="form-control form-control-lg" id="dyd-password" name="password"
                     required autocomplete="current-password">
              <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('dyd-password', this)">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="tz-btn">Zaloguj się</button>
        </form>
        <p class="tz-hint">Użyj danych logowania do Systemu Wspomagania Zarządzania Organizacją.</p>
        <?php if ($office_available): ?>
        <p class="tz-hint"><a href="<?= h($office_login_url) ?>">Mam pocztę @feer.org.pl — zaloguj przez Microsoft 365</a></p>
        <?php endif; ?>
      <?php endif; ?>

      <?php elseif ($tab === 'rodzic'): ?>
      <form method="post" action="kursant/parent_login.php" autocomplete="on">
        <div class="mb-3">
          <label for="par-login">Login rodzica/opiekuna</label>
          <input type="text" class="form-control form-control-lg" id="par-login" name="login"
                 value="<?= $prefill_login ?>" required autofocus autocomplete="username"
                 placeholder="Login opiekuna nadany w placówce"
                 <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
          <p class="tz-hint">Login konta opiekuna nadany w placówce.</p>
        </div>
        <div class="mb-4">
          <label for="par-password">Hasło</label>
          <div class="pass-wrap">
            <input type="password" class="form-control form-control-lg" id="par-password" name="password"
                   required autocomplete="current-password">
            <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('par-password', this)">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="tz-btn">Zaloguj się</button>
      </form>
      <p class="tz-hint">Nie masz hasła? Zaloguj się kodem SMS wysłanym na Twój numer telefonu.</p>
      <a href="kursant/parent.php" class="tz-btn tz-btn-ghost mt-2">Logowanie kodem SMS</a>

      <?php else: /* up */ ?>
      <form method="post" action="kursant/authp_login.php" autocomplete="on">
        <div class="mb-3">
          <label for="up-login">Login osoby upoważnionej</label>
          <input type="text" class="form-control form-control-lg" id="up-login" name="login"
                 value="<?= $prefill_login ?>" required autofocus autocomplete="username"
                 placeholder="Login nadany przez kursanta"
                 <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
          <p class="tz-hint">Login i hasło nadane przez kursanta w zakładce „Upoważnieni”.</p>
        </div>
        <div class="mb-4">
          <label for="up-password">Hasło</label>
          <div class="pass-wrap">
            <input type="password" class="form-control form-control-lg" id="up-password" name="password"
                   required autocomplete="current-password">
            <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('up-password', this)">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="tz-btn">Zaloguj się</button>
      </form>
      <p class="tz-hint">Dostęp do rozliczeń, frekwencji i harmonogramu kursanta, który Cię upoważnił.</p>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>

<footer class="tz-footer" aria-label="Linki">
  <a href="<?= h($TI_BASE) ?>nowosci.php"><strong>Co nowego w panelu kursanta</strong></a>
  <a href="/nabor/logowanie">Konto kandydata (zapisy)</a>
  <a href="https://feer.org.pl">Strona Fundacji FEER</a>
  <a href="<?= h(rtrim(APP_URL, '/')) ?>/panel/index.php">Panel wolontariusza</a>
  <a href="<?= h(rtrim(APP_URL, '/')) ?>/auth/login.php">Logowanie do systemu (SZO)</a>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Pokazywanie/ukrywanie hasła (dostępne z klawiatury, z aria-pressed).
function tzPokaz(id, btn) {
  var p = document.getElementById(id);
  if (!p) return;
  var pokaz = p.type === 'password';
  p.type = pokaz ? 'text' : 'password';
  btn.setAttribute('aria-pressed', pokaz ? 'true' : 'false');
  btn.setAttribute('aria-label', pokaz ? 'Ukryj hasło' : 'Pokaż hasło');
}
(function () {
  // Obrazek bezpieczeństwa kursanta po wpisaniu loginu.
  var inp = document.getElementById('stu-login'), box = document.getElementById('stu-sec');
  var img = document.getElementById('stu-sec-img'), lbl = document.getElementById('stu-sec-label');
  if (!inp || !box) return;
  function pokaz() {
    var v = inp.value.trim();
    if (!v) { box.style.display = 'none'; return; }
    fetch('<?= APP_URL ?>/karty30/ti/kursant/sec_image.php?login=' + encodeURIComponent(v), { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { img.innerHTML = j.svg; lbl.textContent = j.label; box.style.display = 'flex'; })
      .catch(function () { box.style.display = 'none'; });
  }
  // Obrazek pojawia się od razu podczas pisania (z krótką przerwą), nie dopiero po wyjściu z pola.
  var t = null;
  inp.addEventListener('input', function () { clearTimeout(t); t = setTimeout(pokaz, 300); });
  inp.addEventListener('change', pokaz);
  inp.addEventListener('blur', pokaz);
})();
</script>
</body>
</html>
