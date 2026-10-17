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
$tab   = in_array($_GET['tab'] ?? '', ['kursant','dydaktyk','rodzic','up','kandydat'], true) ? $_GET['tab'] : 'kursant';
$krok1 = false;   // układ portalowy: role w zakładkach nad formularzem, domyślnie kursant (dawny krok 1 z kaflami usunięty)

// ── Prefill pól po błędzie ────────────────────────────────────────────────────
$prefill_login = h($_GET['l'] ?? '');
// Token CSRF dla formularza kandydata (/nabor/logowanie) — sesja musi wystartować przed wysłaniem HTML
$_kd_csrf = $tab === 'kandydat' ? csrf_token() : '';
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
    'dydaktyk' => ['Wybierz „Prowadzący”.', 'Masz pocztę @feer.org.pl? Wybierz „Microsoft 365” obok.', 'Nie masz poczty w domenie fundacji? Wpisz e-mail i hasło.'],
    'rodzic'   => ['Wybierz „Rodzic”.', 'Wpisz login opiekuna nadany w placówce.', 'Wpisz hasło. Nie masz hasła? Zaloguj się kodem SMS.'],
    'up'       => ['Wybierz „Upoważniony”.', 'Wpisz login i hasło nadane przez kursanta.'],
    'kandydat' => ['Wybierz „Kandydat”.', 'Wpisz e-mail podany w zgłoszeniu.', 'Wpisz hasło ustawione z linku w e-mailu. Nie masz hasła? Wybierz „Nie pamiętam hasła”.'],
];
$_side_steps = $krok1 ? $_side['kursant'] : $_side[$tab];

$_titles = [
    'kursant'  => 'Panel kursanta',
    'dydaktyk' => 'Panel prowadzącego',
    'rodzic'   => 'Panel rodzica',
    'up'       => 'Dostęp upoważnionego',
    'kandydat' => 'Konto kandydata',
];
$_tabs = [
    'kursant'  => ['label' => 'Kursant',     'icon' => 'bi-pc-display',   'who' => 'Uczestnik zajęć',                  'kolor' => '#0f6fa8'],
    'dydaktyk' => ['label' => 'Prowadzący',  'icon' => 'bi-easel2',       'who' => 'Prowadzę zajęcia',                 'kolor' => '#1d1d1d'],
    'rodzic'   => ['label' => 'Rodzic',      'icon' => 'bi-people',       'who' => 'Opiekun kursanta',                 'kolor' => '#00a300'],
    'up'       => ['label' => 'Upoważniony', 'icon' => 'bi-person-check', 'who' => 'Osoba upoważniona przez kursanta', 'kolor' => '#7e3878'],
    'kandydat' => ['label' => 'Kandydat',    'icon' => 'bi-person-badge', 'who' => 'Rodzic / opiekun ze zgłoszeniem z naboru', 'kolor' => '#164eb6'],
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
<?php include dirname(__DIR__, 2) . '/modules/ti/view/portal/_style.php'; ?>
<style>
  /* Formularze ról — w stylu portalu (pp-): pola bez zaokrągleń, żółty przycisk „Zaloguj się” */
  .pp-form .form-control { border-radius: 0; box-shadow: none; padding: .6rem .8rem; font-size: 1rem; border-color: var(--pp-line); }
  .pp-form .form-control:focus { border-color: var(--pp-blue); outline: 3px solid var(--pp-yellow); }
  .pp-form label { font-weight: 600; font-size: 1.02rem; margin-bottom: .55rem; }
  .tz-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 200px; padding: .5rem 1.5rem; border: 0; border-radius: 0;
            background: var(--pp-yellow); color: var(--pp-blue); font-weight: 600; text-decoration: none; }
  .tz-btn:hover { filter: brightness(.95); color: var(--pp-blue); }
  .tz-btn-ghost { background: #fff; color: var(--pp-blue); border: 2px solid var(--pp-blue); }
  .tz-tile { display: block; height: 100%; padding: 1rem; text-decoration: none; color: #fff; }
  .tz-tile:hover { color: #fff; filter: brightness(.92); }
  .tz-choice { position: relative; padding: 1rem 1.1rem; min-height: 88px; }
  .tz-choice-badge { position: absolute; top: .6rem; right: .6rem; background: #fff; color: #1d1d1d; font-size: .75rem; font-weight: 700; padding: .1rem .45rem; }
  .tz-hint { font-size: .85rem; color: var(--pp-muted); margin-top: .75rem; }
  .pass-wrap { position: relative; } .pass-wrap .form-control { padding-right: 3rem; }
  .pass-toggle { position: absolute; right: 0; top: 0; height: 100%; width: 3rem; border: 0; background: transparent; }
</style>
</head>
<body class="pp">
<?php
$ppTitle = 'Panel zajęć'; $ppOrg = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER')); $ppCrumb = 'Logowanie';
$ppLogo = ''; $ppLoginUrl = '/login.php'; $ppAccount = null;
include dirname(__DIR__, 2) . '/modules/ti/view/portal/_header.php';
$_ppRoles = ['kursant' => 'Kursant', 'rodzic' => 'Rodzic / opiekun', 'up' => 'Osoba upoważniona', 'kandydat' => 'Kandydat (zapisy)', 'dydaktyk' => 'Prowadzący / kadra'];
?>
<main id="pp-main" class="pp-wrap">
  <h1 class="pp-h1">Wybierz sposób logowania</h1>
  <ul class="pp-tabs" aria-label="Kim jesteś">
    <?php foreach ($_ppRoles as $_rk => $_rl): ?><li><a href="login.php?tab=<?= $_rk ?>"<?= $tab === $_rk ? ' aria-current="page"' : '' ?>><?= h($_rl) ?></a></li><?php endforeach; ?>
  </ul>
  <?php if ($error): ?><div class="pp-error" role="alert" aria-live="assertive"><?= h($error) ?></div><?php endif; ?>
  <div class="pp-cols">
    <div class="pp-form">
      <p class="tz-hint mt-0 mb-3"><?= h(implode(' ', array_slice($_side[$tab], 1))) ?></p>
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

      <?php elseif ($tab === 'kandydat'): ?>
      <?php /* Konto kandydata (nabór do grup, modules/ti) — formularz wysyła do /nabor/logowanie (ta sama sesja SZO, CSRF _csrf) */ ?>
      <form method="post" action="/nabor/logowanie" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= h($_kd_csrf) ?>"><input type="hidden" name="next" value="/konto">
        <div class="mb-3">
          <label for="kd-email">E-mail</label>
          <input type="email" class="form-control form-control-lg" id="kd-email" name="email" required autofocus autocomplete="username">
        </div>
        <div class="mb-4">
          <label for="kd-password">Hasło</label>
          <div class="pass-wrap">
            <input type="password" class="form-control form-control-lg" id="kd-password" name="password" required autocomplete="current-password">
            <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('kd-password', this)">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="tz-btn">Zaloguj się</button>
      </form>
      <p class="tz-hint"><a href="/nabor/przypomnij">Nie pamiętam hasła / nie mam hasła</a></p>
      <p class="tz-hint">Konto kandydata powstaje po potwierdzeniu pierwszego zgłoszenia.<?= org_setting('ti_nabor_enabled') === '1' ? ' <a href="/nabor">Złóż zgłoszenie na zajęcia</a>.' : '' ?></p>

      <?php elseif ($tab === 'dydaktyk'): ?>
      <?php /* Microsoft 365 — w prawej kolumnie (bez duplikatu); tu e-mail i hasło */ ?>
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
        <p class="tz-hint">Prywatny adres e-mail: użyj danych logowania do Systemu Wspomagania Zarządzania Organizacją.</p>

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
    </div>

    <aside aria-labelledby="pp-alt-h" class="pp-note">
      <?php if ($tab === 'dydaktyk'): ?>
        <p id="pp-alt-h" style="margin:0 0 .3rem">Masz pocztę w domenie fundacji?</p>
        <a class="pp-alt" href="<?= h($office_login_url) ?>"><i class="bi bi-microsoft" aria-hidden="true"></i> Zaloguj przez Microsoft 365</a>
        <p>Dla prowadzących i pracowników z adresem @feer.org.pl — nastąpi przekierowanie na stronę logowania Microsoft.</p>
      <?php elseif ($tab === 'rodzic'): ?>
        <p id="pp-alt-h" style="margin:0 0 .3rem">Nie masz hasła albo go nie pamiętasz?</p>
        <a class="pp-alt" href="kursant/parent.php"><i class="bi bi-chat-dots" aria-hidden="true"></i> Zaloguj kodem SMS</a>
        <p>Jednorazowy kod na numer telefonu opiekuna zapisany w placówce.</p>
      <?php elseif ($tab === 'kandydat'): ?>
        <p id="pp-alt-h" style="margin:0 0 .3rem">Nie masz jeszcze konta kandydata?</p>
        <a class="pp-alt" href="/nabor/rejestracja"><i class="bi bi-person-plus" aria-hidden="true"></i> Załóż konto</a>
        <p>Albo <?= org_setting('ti_nabor_enabled') === '1' ? '<a href="/nabor">złóż zgłoszenie na zajęcia</a> — konto założymy po jego potwierdzeniu.' : 'zaczekaj na otwarcie naboru.' ?></p>
      <?php elseif ($tab === 'up'): ?>
        <p id="pp-alt-h" style="margin-top:0"><strong style="color:var(--pp-ink)">Osoba upoważniona</strong> widzi rozliczenia, frekwencję i plan zajęć kursanta, który nadał jej dostęp w zakładce „Upoważnieni”.</p>
      <?php else: ?>
        <p id="pp-alt-h" style="margin-top:0"><strong style="color:var(--pp-ink)">Obrazek bezpieczeństwa.</strong> Po wpisaniu loginu zobaczysz swój obrazek (z opisem dla czytnika ekranu). Jeśli go nie widzisz albo widzisz inny — nie wpisuj hasła.</p>
        <p>Pierwsze logowanie: wpisz tylko login, kod przyjdzie SMS-em.</p>
      <?php endif; ?>
      <?php if ($tab !== 'kandydat'): ?>
      <div style="border-top:1px solid var(--pp-line);margin-top:1.4rem;padding-top:1.1rem">
        <strong style="color:var(--pp-ink)">Nie masz jeszcze konta?</strong><br>
        <?php if (org_setting('ti_nabor_enabled') === '1'): ?><a href="/nabor">Zapisz dziecko na zajęcia</a> · <?php endif; ?><a href="/nabor/rejestracja">Załóż konto kandydata</a>
      </div>
      <?php endif; ?>
    </aside>
  </div>
</main>
<?php
$ppFootLinks = ['Co nowego w panelu' => $TI_BASE . 'nowosci.php', 'Czym jest SSO' => $TI_BASE . 'sso.php', 'Strona Fundacji FEER' => 'https://feer.org.pl',
                'Panel wolontariusza' => rtrim(APP_URL, '/') . '/panel/index.php', 'Logowanie do systemu (SZO)' => rtrim(APP_URL, '/') . '/auth/login.php'];
include dirname(__DIR__, 2) . '/modules/ti/view/portal/_footer.php';
?>

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
