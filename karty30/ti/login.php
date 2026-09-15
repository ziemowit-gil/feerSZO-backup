<?php
/**
 * karty30/ti/login.php — Centralny punkt logowania TI.
 *
 * Jeden ekran z zakładkami Kursant / Prowadzący / Rodzic / Upoważniony.
 * POST trafia do sub-handlerów (PRG): kursant/login.php, dydaktyk/login.php,
 * kursant/parent_login.php, kursant/authp_login.php. Błędy wracają kodem
 * e=N w query string, unikając sesji po stronie tego pliku.
 *
 * Wygląd współdzielony z resztą logowania SZO (includes/auth_screen.php) —
 * ten sam szablon co auth/login.php, ale w wariancie „zakładki roli po
 * lewej, formularz po prawej" (side_tabs_html), bo tu są 4 zakładki zamiast
 * zwykłych dwóch (Zaloguj/Rejestracja).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/org_case.php';   // odmiana nazwy organizacji
require_once dirname(dirname(__DIR__)) . '/includes/auth_screen.php';

karty30_migrate();

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

// ── Aktywna zakładka ──────────────────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['kursant','dydaktyk','rodzic','up'], true) ? $_GET['tab'] : 'kursant';

// ── Prefill pól po błędzie (z urlencode() w handlerach) ──────────────────────
$prefill_login = h($_GET['l'] ?? '');
$prefill_email = h($_GET['m'] ?? '');

// ── SSO dla dydaktyka ─────────────────────────────────────────────────────────
$office_login_url = rtrim(APP_URL, '/') . '/auth/ms365.php?redirect='
    . urlencode(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/office_enter.php');
$office_available = function_exists('ms_login_available') && ms_login_available();
$dyd_pwd_open     = !$office_available || $ec > 0;

$_titles = [
    'kursant'  => 'Panel kursanta',
    'dydaktyk' => 'Panel prowadzącego',
    'rodzic'   => 'Panel rodzica',
    'up'       => 'Dostęp upoważnionego',
];
$_tabs = [
    'kursant'  => ['label' => 'Kursant',     'icon' => 'bi-pc-display'],
    'dydaktyk' => ['label' => 'Prowadzący',  'icon' => 'bi-easel2'],
    'rodzic'   => ['label' => 'Rodzic',      'icon' => 'bi-people'],
    'up'       => ['label' => 'Upoważniony', 'icon' => 'bi-person-check'],
];

// ── Zakładki roli po lewej (zamiast poziomego paska nad kartą) ───────────────
ob_start();
foreach ($_tabs as $_key => $_t): ?>
  <a class="ks-tab" href="login.php?tab=<?= $_key ?>" <?= $tab === $_key ? 'aria-current="page"' : '' ?>>
    <i class="bi <?= $_t['icon'] ?> me-1" aria-hidden="true"></i><?= h($_t['label']) ?>
  </a>
<?php endforeach;
$_tabs_html = ob_get_clean();

auth_screen_head([
    'title'          => $_titles[$tab] . ' — Logowanie',
    'side_tabs_html' => $_tabs_html,
    'bootstrap'       => true,
    'width'           => 760,
    'main_id'         => 'ti-login-main',
]);
?>

<h1 class="ks-h1"><?= h(org_login_title($_titles[$tab])) ?></h1>
<p class="ks-lead">Zaloguj się loginem/e-mailem i hasłem.</p>

<?php if ($error): ?>
<div class="l-alert l-alert-danger" role="alert" aria-live="assertive" aria-atomic="true" style="margin-bottom:1.25rem">
  <i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span><?= h($error) ?></span>
</div>
<?php endif; ?>

<?php if ($tab === 'kursant'): ?>
<!-- ═══ KURSANT ═══════════════════════════════════════════════════════ -->
<form method="post" action="kursant/login.php" autocomplete="on">
  <div class="ks-field">
    <label for="stu-login">Login</label>
    <input type="text" class="form-control" id="stu-login" name="login"
           value="<?= $prefill_login ?>" required autofocus autocomplete="username"
           placeholder="Login lub własny alias"
           <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
    <p class="ks-fieldhint">Login otrzymujesz od prowadzącego.</p>
  </div>
  <div class="ks-field">
    <label for="stu-password">Hasło</label>
    <div class="pass-wrap">
      <input type="password" class="form-control" id="stu-password" name="password"
             required autocomplete="current-password">
      <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
              onclick="togglePass('stu-password', this)">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <button type="submit" class="ks-btn ks-btn--primary">
    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się
  </button>
</form>

<p class="ks-hint">Nie masz konta? <a href="zapisy.php">Zostaw kontakt</a> — odezwiemy się w sprawie zapisów.</p>

<?php if (k30_pfron_enabled()): ?>
<hr class="ks-sep">
<a href="kursant/pfron.php" class="ks-btn ks-btn--ghost">
  <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Rozliczenia PFRON (Aktywny Samorząd)
</a>
<?php endif; ?>

<?php elseif ($tab === 'dydaktyk'): ?>
<!-- ═══ PROWADZĄCY ════════════════════════════════════════════════════ -->
<?php if ($office_available): ?>
<a href="<?= h($office_login_url) ?>" class="ks-btn ks-btn--ghost"
   aria-label="Zaloguj się przez Microsoft 365 — zostaniesz przekierowany do Microsoft">
  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
    <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
    <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
  </svg>
  Zaloguj przez Microsoft 365
</a>
<p class="ks-hint">Konto <strong>@feer.org.pl</strong> — zalecana metoda logowania</p>
<button type="button" class="ks-btn ks-btn--ghost<?= $dyd_pwd_open ? ' d-none' : '' ?>" id="dydPwToggle"
        data-bs-toggle="collapse" data-bs-target="#dydPwCollapse"
        aria-expanded="<?= $dyd_pwd_open ? 'true' : 'false' ?>" aria-controls="dydPwCollapse">
  <i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Zaloguj hasłem (konta bez Microsoft 365)
</button>
<div class="collapse<?= $dyd_pwd_open ? ' show' : '' ?>" id="dydPwCollapse">
  <div class="ks-or"><span>logowanie hasłem</span></div>
<?php endif; ?>

  <form method="post" action="dydaktyk/login.php" autocomplete="on">
    <div class="ks-field">
      <label for="dyd-email">Adres e-mail</label>
      <input type="email" class="form-control" id="dyd-email" name="email"
             value="<?= $prefill_email ?>" required autocomplete="username"
             placeholder="imie.nazwisko@feer.org.pl"
             <?= (!$office_available) ? 'autofocus' : '' ?>
             <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
    </div>
    <div class="ks-field">
      <label for="dyd-password">Hasło</label>
      <div class="pass-wrap">
        <input type="password" class="form-control" id="dyd-password" name="password"
               required autocomplete="current-password">
        <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
                onclick="togglePass('dyd-password', this)">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
      </div>
    </div>
    <button type="submit" class="ks-btn ks-btn--primary">
      <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się
    </button>
  </form>
  <p class="ks-hint">Użyj danych logowania do Systemu Wspomagania Zarządzania Organizacją.</p>
<?php if ($office_available): ?>
</div><!-- /#dydPwCollapse -->
<?php endif; ?>

<?php elseif ($tab === 'rodzic'): ?>
<!-- ═══ RODZIC / OPIEKUN ══════════════════════════════════════════════ -->
<form method="post" action="kursant/parent_login.php" autocomplete="on">
  <div class="ks-field">
    <label for="par-login">Login rodzica/opiekuna</label>
    <input type="text" class="form-control" id="par-login" name="login"
           value="<?= $prefill_login ?>" required autofocus autocomplete="username"
           placeholder="np. j.kowalski-r"
           <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
    <p class="ks-fieldhint">Login konta opiekuna nadany w placówce.</p>
  </div>
  <div class="ks-field">
    <label for="par-password">Hasło</label>
    <div class="pass-wrap">
      <input type="password" class="form-control" id="par-password" name="password"
             required autocomplete="current-password">
      <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
              onclick="togglePass('par-password', this)">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <button type="submit" class="ks-btn ks-btn--primary">
    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się
  </button>
</form>

<p class="ks-hint">Nie masz hasła? Zaloguj się kodem SMS wysłanym na Twój numer telefonu.</p>
<a href="kursant/parent.php" class="ks-btn ks-btn--ghost">
  <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Logowanie kodem SMS
</a>

<?php else: /* up */ ?>
<!-- ═══ OSOBA UPOWAŻNIONA ═════════════════════════════════════════════ -->
<form method="post" action="kursant/authp_login.php" autocomplete="on">
  <div class="ks-field">
    <label for="up-login">Login osoby upoważnionej</label>
    <input type="text" class="form-control" id="up-login" name="login"
           value="<?= $prefill_login ?>" required autofocus autocomplete="username"
           placeholder="Login nadany przez kursanta"
           <?php if ($ec): ?>aria-invalid="true"<?php endif; ?>>
    <p class="ks-fieldhint">Login i hasło nadane przez kursanta w zakładce „Upoważnieni".</p>
  </div>
  <div class="ks-field">
    <label for="up-password">Hasło</label>
    <div class="pass-wrap">
      <input type="password" class="form-control" id="up-password" name="password"
             required autocomplete="current-password">
      <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
              onclick="togglePass('up-password', this)">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <button type="submit" class="ks-btn ks-btn--primary">
    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się
  </button>
</form>

<p class="ks-hint">Dostęp do rozliczeń, frekwencji i harmonogramu kursanta, który Cię upoważnił.</p>
<?php endif; ?>

<?php
auth_screen_foot([
    'links' => [
        ['url' => 'https://feer.org.pl', 'label' => 'Strona Fundacji FEER', 'icon' => 'bi-globe2'],
        ['url' => rtrim(APP_URL, '/') . '/panel/index.php', 'label' => 'Panel wolontariusza', 'icon' => 'bi-person-heart'],
        ['url' => rtrim(APP_URL, '/') . '/auth/login.php',  'label' => 'Logowanie do systemu (SZO)', 'icon' => 'bi-box-arrow-in-right'],
    ],
]);
