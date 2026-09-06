<?php
/**
 * karty30/ti/login.php — Centralny punkt logowania TI.
 *
 * Jeden formularz z zakładkami Kursant / Prowadzący.
 * POST trafia do sub-handlerów (PRG): kursant/login.php lub dydaktyk/login.php.
 * Błędy wracają kodem e=N w query string, unikając sesji po stronie tego pliku.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/org_case.php';   // odmiana nazwy organizacji

karty30_migrate();

// ── Komunikaty błędów ─────────────────────────────────────────────────────────
static $ERR = [
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

// ── Wygląd ekranu: standardowy albo alternatywny ─────────────────────────────
// Prowadzący pracuje w panelu w gęstym, tabelarycznym układzie, więc dla niego
// alternatywny wygląd jest domyślny — wejście wygląda jak to, co zobaczy po
// zalogowaniu. Kursant zostaje przy dotychczasowym ekranie (u niego ten układ
// jest do wyboru już w panelu). Sesji tu jeszcze nie ma, więc jedyne, co
// pamięta wybór, to adres: ?ui=alt / ?ui=std.
$login_ui = (string)($_GET['ui'] ?? ($tab === 'dydaktyk' ? 'alt' : 'std'));
$login_ui = $login_ui === 'alt' ? 'alt' : 'std';
$login_ui_other = $login_ui === 'alt' ? 'std' : 'alt';
$login_ui_url   = 'login.php?tab=' . rawurlencode($tab) . '&ui=' . $login_ui_other
    . ($ec > 0 ? '&e=' . $ec : '');

$KP_TITLE      = 'Logowanie — Panel Kursanta';
$KP_BODY_CLASS = 'kp-login-split-page' . ($login_ui === 'alt' ? ' ti-skin' : '');
include __DIR__ . '/kursant/_layout_head.php';
?>
<style>
/* ══ Logowanie TI — styl USOS/CAS ═══════════════════════════════════════
   Gradientowy pasek u góry (jak Centralny System Uwierzytelniania), szare
   tło strony i biała, obramowana „karta" logowania z zakładkami wyglądającymi
   jak zakładki przeglądarkowe. Utylitarnie, gęsto, bez dużych cieni. */

*, *::before, *::after { box-sizing: border-box; }

:root {
  --bg:       #eef0f4;
  --card-bg:  #ffffff;
  --strip-bg: #f2f2f6;
  --text:     #1a1a1a;
  --muted:    #5f5f66;
  --rule:     #d0d0d8;
  --accent:   #1e3a5f;
  --accent-h: #15293f;
  --hover-bg: #e7e9ef;
  --topbar-1: #362f52;
  --topbar-2: #5a3a63;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg:       #15151b;
    --card-bg:  #1e1e26;
    --strip-bg: #24242e;
    --text:     #ededed;
    --muted:    #9a9aa4;
    --rule:     #34343f;
    --accent:   #5680b3;
    --accent-h: #6f97c6;
    --hover-bg: #292933;
    --topbar-1: #221d34;
    --topbar-2: #3a2740;
  }
}
:root[data-theme="light"] {
  --bg:#eef0f4; --card-bg:#ffffff; --strip-bg:#f2f2f6; --text:#1a1a1a; --muted:#5f5f66;
  --rule:#d0d0d8; --accent:#1e3a5f; --accent-h:#15293f; --hover-bg:#e7e9ef;
  --topbar-1:#362f52; --topbar-2:#5a3a63;
}
:root[data-theme="dark"] {
  --bg:#15151b; --card-bg:#1e1e26; --strip-bg:#24242e; --text:#ededed; --muted:#9a9aa4;
  --rule:#34343f; --accent:#5680b3; --accent-h:#6f97c6; --hover-bg:#292933;
  --topbar-1:#221d34; --topbar-2:#3a2740;
}

html, body.kp-login-split-page { min-height: 100%; margin: 0; padding: 0 !important; }

body.kp-login-split-page {
  background: var(--bg);
  color: var(--text);
  font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
}

/* ── Pasek u góry (jak Centralny System Uwierzytelniania) ──────────────── */
.kp-usos-topbar {
  width: 100%; background: linear-gradient(120deg, var(--topbar-1), var(--topbar-2) 75%);
  color: #fff; padding: .8rem 1.5rem;
}
.kp-usos-topbar-inner {
  max-width: 460px; margin: 0 auto;
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.kp-usos-topbar-org {
  font-size: .78rem; font-weight: 600; letter-spacing: .03em;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kp-usos-topbar-note {
  font-size: .72rem; color: rgba(255,255,255,.75); white-space: nowrap; flex-shrink: 0;
}

.kp-split-wrap {
  min-height: 100vh; width: 100%;
  display: flex; flex-direction: column; align-items: center;
  padding: 0 1.5rem 3rem;
}

/* ── Branding (logo + tytuł modułu) — pasek pod topbarem, na tle strony ── */
.kp-split-left { width: 100%; max-width: 460px; margin: 1.5rem 0 1rem; }
.kp-split-left-inner { display: flex; align-items: center; gap: .6rem; }
.kp-split-brand-icon {
  width: 40px; height: 40px; border-radius: 2px;
  background: var(--card-bg); border: 1px solid var(--rule); color: var(--accent);
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 1.15rem; flex-shrink: 0;
}
.kp-split-tagline { margin: 0; color: var(--muted); font-size: .8rem; line-height: 1.5; }

/* ── Karta logowania — obramowana, biała, kwadratowe rogi ──────────────── */
.kp-split-right {
  width: 100%; max-width: 460px;
  background: var(--card-bg); border: 1px solid var(--rule); border-radius: 2px;
  box-shadow: 0 1px 4px rgba(0,0,0,.09);
  padding: 0 1.75rem 1.5rem;
}

.kp-split-right .kp-org-name {
  font-size: .68rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
  color: var(--accent); margin: 0; padding-top: 1.25rem;
}
.kp-split-right .kp-login-heading {
  font-size: 1.5rem; font-weight: 700; letter-spacing: -.01em; line-height: 1.2;
  color: var(--text); margin: 0 0 1.25rem;
}
.kp-split-right .kp-auth-footer {
  margin: 2rem 0 0; padding-top: 1.25rem; border-top: 1px solid var(--rule);
  text-align: center; font-size: .75rem; color: var(--muted);
}
.kp-split-right .kp-auth-footer .kp-ui-switch { color: var(--accent); text-decoration: underline; }
.kp-split-right .kp-auth-footer .kp-ui-switch:hover { color: var(--accent-h); }

/* Zakładki — jak zakładki przeglądarkowe, na szarym pasku nad kartą */
.kp-split-right .nav-tabs {
  border-bottom: 1px solid var(--rule); background: var(--strip-bg);
  margin: 0 -1.75rem 1.5rem; padding: 0 1.25rem; gap: 0; flex-wrap: wrap;
}
.kp-split-right .nav-tabs .nav-link {
  color: var(--muted); border: 1px solid transparent; border-bottom: none;
  border-radius: 2px 2px 0 0; padding: .65rem .9rem; margin: 0; background: none;
  font-size: .85rem;
}
.kp-split-right .nav-tabs .nav-link:hover { color: var(--text); }
.kp-split-right .nav-tabs .nav-link.active {
  color: var(--text); background: var(--card-bg); border-color: var(--rule);
  font-weight: 700; margin-bottom: -1px;
}

/* Pola formularza — proste, kwadratowe rogi, wyraźna ramka */
.kp-split-right .form-label { color: var(--text); font-weight: 600; font-size: .88rem; }
.kp-split-right .form-text { color: var(--muted); font-size: .78rem; }
.kp-split-right .input-group-text {
  background: var(--strip-bg); border: 1px solid var(--rule); border-right: none; color: var(--muted);
}
.kp-split-right .form-control {
  background: var(--card-bg); border: 1px solid var(--rule); color: var(--text);
  border-radius: 2px; box-shadow: none;
}
.kp-split-right .input-group .form-control { border-radius: 0 2px 2px 0; }
.kp-split-right .input-group .input-group-text { border-radius: 2px 0 0 2px; }
.kp-split-right .form-control:focus {
  border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent);
}
.kp-split-right .form-control::placeholder { color: var(--muted); opacity: .7; }
.kp-split-right .btn-outline-secondary {
  border: 1px solid var(--rule); color: var(--muted); background: var(--card-bg); border-radius: 2px;
}
.kp-split-right .btn-outline-secondary:hover { background: var(--hover-bg); color: var(--text); }

.kp-split-right .btn-primary {
  background: var(--accent); border-color: var(--accent); border-radius: 2px; font-weight: 600;
}
.kp-split-right .btn-primary:hover { background: var(--accent-h); border-color: var(--accent-h); }
.kp-split-right .btn-lg { border-radius: 2px; }

/* ── Informacja o zmianie wyglądu ──────────────────────────────────────── */
.kp-change {
  display: flex; gap: .6rem; align-items: flex-start;
  background: var(--strip-bg); border: 1px solid var(--rule); color: var(--muted);
  border-radius: 2px; padding: .7rem .85rem; font-size: .82rem; line-height: 1.55;
  margin-bottom: 1.5rem;
}
.kp-change strong { color: var(--text); }
.kp-change i { font-size: 1rem; flex-shrink: 0; margin-top: .1rem; color: var(--accent); }

/* ── Rozjazd „to nie tutaj" ────────────────────────────────────────────── */
.kp-lost { margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--rule); }
.kp-lost-h { font-size: .95rem; font-weight: 700; color: var(--text); margin: 0 0 .3rem; }
.kp-lost-sub { font-size: .8rem; color: var(--muted); line-height: 1.55; margin: 0 0 .9rem; }
.kp-lost ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .25rem; }
.kp-lost a {
  display: flex; align-items: center; gap: .65rem; padding: .6rem .5rem;
  border-radius: 2px; text-decoration: none; color: var(--text);
  transition: background .1s;
}
.kp-lost a:hover, .kp-lost a:focus-visible { background: var(--hover-bg); outline: none; }
.kp-lost a:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
.kp-lost a > i:first-child { color: var(--accent); font-size: 1rem; flex-shrink: 0; }
.kp-lost strong { display: block; font-size: .86rem; font-weight: 600; line-height: 1.3; }
.kp-lost span.d { display: block; font-size: .75rem; color: var(--muted); line-height: 1.4; }
.kp-lost .arr { margin-left: auto; font-size: .78rem; color: var(--muted); }

@media (max-width: 575px) {
  .kp-usos-topbar { padding: .65rem 1rem; }
  .kp-split-right { padding: 0 1.1rem 1.25rem; }
  .kp-split-right .nav-tabs { margin: 0 -1.1rem 1.25rem; padding: 0 .6rem; }
}
</style>

<div class="kp-usos-topbar">
  <div class="kp-usos-topbar-inner">
    <span class="kp-usos-topbar-org"><?= h($KP_ORG) ?> · Centralny System Logowania</span>
    <span class="kp-usos-topbar-note">Nie jesteś zalogowany</span>
  </div>
</div>
<?php if ($login_ui === 'alt'):
  // Kolejność ma znaczenie: reguły powyżej mają tę samą specyficzność, więc
  // arkusz skórki musi iść PO nich, żeby wygrał (tak samo jak w panelach).
  $_skin_css = __DIR__ . '/assets/ti_skin.css'; ?>
<link rel="stylesheet" href="assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php endif; ?>

<main id="main" class="kp-split-wrap">

  <!-- ══════════════════════════════════════════════════════════════════ -->
  <!-- LEWA KOLUMNA — zdjęcie + branding                                 -->
  <!-- ══════════════════════════════════════════════════════════════════ -->
  <?php /* Marka nad kartą. Wcześniej blok był aria-hidden, bo dublował treść
           formularza po prawej; teraz niesie nazwę panelu i jest czytany. */ ?>
  <div class="kp-split-left">
    <div class="kp-split-left-inner">

      <!-- Logo + nazwa panelu -->
      <div class="d-flex align-items-center gap-3">
        <span class="kp-split-brand-icon">
          <i class="bi bi-pc-display" id="hero-icon"></i>
        </span>
        <div>
          <div class="fw-bold" style="font-size:1.05rem" id="hero-title">Panel kursanta</div>
        </div>
      </div>

      <!-- Stopka lewej kolumny -->
      <p class="kp-split-tagline"><?= h($KP_ORG) ?></p>

    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════ -->
  <!-- PRAWA KOLUMNA — formularz                                         -->
  <!-- ══════════════════════════════════════════════════════════════════ -->
  <div class="kp-split-right">

    <?php /* Ekran przedstawia się nazwą modułu i organizacji W DOPEŁNIACZU:
             „Panel kursanta Fundacji…", nie „Panel kursanta Fundacja…".
             Nazwa organizacji jest ustawieniem, więc odmienia ją org_case.php.
             Podtytuł zmienia się razem z zakładką (skrypt na dole strony). */ ?>
    <h1 class="kp-org-name" id="kp-module-title">
      <?= h(org_login_title($tab === 'dydaktyk' ? 'Panel prowadzącego'
          : ($tab === 'rodzic' ? 'Panel rodzica' : ($tab === 'up' ? 'Dostęp upoważnionego' : 'Panel kursanta')))) ?>
    </h1>

    <h2 class="kp-login-heading">Zaloguj</h2>

    <?php /* Wygląd ekranu się zmienił, a sposób logowania NIE. Bez tego zdania
             część osób uzna, że trafiła nie tam, gdzie zwykle, i zacznie szukać
             „starej strony" albo dzwonić do prowadzącego. Komunikat mówi też
             wprost, że login i hasło zostają te same — to jedyne pytanie, które
             taka zmiana naprawdę rodzi. */ ?>
    <div class="kp-change" role="status">
      <i class="bi bi-stars" aria-hidden="true"></i>
      <span>
        <strong>Nowy wygląd logowania.</strong>
        To ta sama strona i to samo konto — <strong>login i hasło bez zmian</strong>.
        Zmienił się tylko wygląd, żeby wejście do panelu wyglądało jak reszta systemu.
      </span>
    </div>

    <!-- Błąd -->
    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3"
         role="alert" aria-live="assertive" aria-atomic="true">
      <i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Zakładki Kursant / Prowadzący / Rodzic / Upoważniony -->
    <ul class="nav nav-tabs" role="tablist" aria-label="Rodzaj użytkownika">
      <li class="nav-item" role="presentation">
        <button class="nav-link<?= $tab === 'kursant' ? ' active' : '' ?>"
                id="tab-kursant-btn"
                data-bs-toggle="tab" data-bs-target="#tab-kursant"
                type="button" role="tab"
                aria-controls="tab-kursant"
                aria-selected="<?= $tab === 'kursant' ? 'true' : 'false' ?>">
          <i class="bi bi-pc-display me-1" aria-hidden="true"></i>Kursant
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link<?= $tab === 'dydaktyk' ? ' active' : '' ?>"
                id="tab-dydaktyk-btn"
                data-bs-toggle="tab" data-bs-target="#tab-dydaktyk"
                type="button" role="tab"
                aria-controls="tab-dydaktyk"
                aria-selected="<?= $tab === 'dydaktyk' ? 'true' : 'false' ?>">
          <i class="bi bi-easel2 me-1" aria-hidden="true"></i>Prowadzący
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link<?= $tab === 'rodzic' ? ' active' : '' ?>"
                id="tab-rodzic-btn"
                data-bs-toggle="tab" data-bs-target="#tab-rodzic"
                type="button" role="tab"
                aria-controls="tab-rodzic"
                aria-selected="<?= $tab === 'rodzic' ? 'true' : 'false' ?>">
          <i class="bi bi-people me-1" aria-hidden="true"></i>Rodzic
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link<?= $tab === 'up' ? ' active' : '' ?>"
                id="tab-up-btn"
                data-bs-toggle="tab" data-bs-target="#tab-up"
                type="button" role="tab"
                aria-controls="tab-up"
                aria-selected="<?= $tab === 'up' ? 'true' : 'false' ?>">
          <i class="bi bi-person-check me-1" aria-hidden="true"></i>Upoważniony
        </button>
      </li>
    </ul>

    <div class="tab-content">

      <!-- ═══ ZAKŁADKA: KURSANT ════════════════════════════════════════ -->
      <div class="tab-pane fade<?= $tab === 'kursant' ? ' show active' : '' ?>"
           id="tab-kursant" role="tabpanel" aria-labelledby="tab-kursant-btn">

        <form method="post" action="kursant/login.php" autocomplete="on">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="stu-login">Login</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-person"></i></span>
              <input type="text"
                     class="form-control form-control-lg<?= ($ec && $tab === 'kursant') ? ' is-invalid' : '' ?>"
                     id="stu-login" name="login"
                     value="<?= $prefill_login ?>"
                     required
                     <?= $tab === 'kursant' ? 'autofocus' : '' ?>
                     autocomplete="username"
                     placeholder="Login lub własny alias"
                     aria-describedby="stu-login-hint"
                     aria-invalid="<?= ($ec && $tab === 'kursant') ? 'true' : 'false' ?>">
            </div>
            <div id="stu-login-hint" class="form-text">Login otrzymujesz od prowadzącego.</div>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold" for="stu-password">Hasło</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
              <input type="password"
                     class="form-control form-control-lg"
                     id="stu-password" name="password"
                     required
                     autocomplete="current-password"
                     placeholder="••••••••"
                     aria-invalid="<?= ($ec && $tab === 'kursant') ? 'true' : 'false' ?>">
              <button class="btn btn-outline-secondary" type="button"
                      data-kp-pw-toggle="stu-password"
                      aria-label="Pokaż lub ukryj hasło" aria-pressed="false">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
          </button>
        </form>

        <p class="text-body-secondary mt-3 mb-0" style="font-size:.82rem">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          Nie masz konta? Skontaktuj się z prowadzącym.
        </p>

        <?php if (k30_pfron_enabled()): ?>
        <hr class="my-3">
        <a href="kursant/pfron.php" class="btn btn-outline-secondary w-100">
          <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Rozliczenia PFRON (Aktywny Samorząd)
        </a>
        <?php endif; ?>

      </div><!-- /#tab-kursant -->

      <!-- ═══ ZAKŁADKA: PROWADZĄCY ══════════════════════════════════════ -->
      <div class="tab-pane fade<?= $tab === 'dydaktyk' ? ' show active' : '' ?>"
           id="tab-dydaktyk" role="tabpanel" aria-labelledby="tab-dydaktyk-btn">

        <?php if ($office_available): ?>
        <a href="<?= h($office_login_url) ?>"
           class="btn btn-lg w-100 fw-semibold mb-2 d-flex align-items-center justify-content-center gap-2"
           style="background:#2f2f2f;color:#fff">
          <svg width="18" height="18" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
            <path fill="#f25022" d="M1 1h10v10H1z"/>
            <path fill="#7fba00" d="M12 1h10v10H12z"/>
            <path fill="#00a4ef" d="M1 12h10v10H1z"/>
            <path fill="#ffb900" d="M12 12h10v10H12z"/>
          </svg>
          Zaloguj przez Microsoft 365
        </a>
        <p class="text-body-secondary text-center mb-3" style="font-size:.78rem">
          <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Zalecana metoda logowania
        </p>
        <button type="button"
                class="btn btn-link btn-sm text-body-secondary px-0 mb-2 text-decoration-none<?= $dyd_pwd_open ? ' d-none' : '' ?>"
                id="dydPwToggle"
                data-bs-toggle="collapse" data-bs-target="#dydPwCollapse"
                aria-expanded="<?= $dyd_pwd_open ? 'true' : 'false' ?>"
                aria-controls="dydPwCollapse">
          <i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Zaloguj hasłem (konta bez Microsoft 365)
        </button>
        <?php endif; ?>

        <div class="collapse<?= $dyd_pwd_open ? ' show' : '' ?>" id="dydPwCollapse">
          <?php if ($office_available): ?>
          <div class="d-flex align-items-center gap-2 my-3 text-body-secondary" aria-hidden="true">
            <hr class="flex-grow-1 m-0">
            <span class="small">logowanie hasłem</span>
            <hr class="flex-grow-1 m-0">
          </div>
          <?php endif; ?>

          <form method="post" action="dydaktyk/login.php" autocomplete="on">
            <div class="mb-3">
              <label class="form-label fw-semibold" for="dyd-email">Adres e-mail</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                <input type="email"
                       class="form-control form-control-lg<?= ($ec && $tab === 'dydaktyk') ? ' is-invalid' : '' ?>"
                       id="dyd-email" name="email"
                       value="<?= $prefill_email ?>"
                       required
                       <?= ($tab === 'dydaktyk' && !$office_available) ? 'autofocus' : '' ?>
                       autocomplete="username"
                       placeholder="imie.nazwisko@feer.org.pl"
                       aria-invalid="<?= ($ec && $tab === 'dydaktyk') ? 'true' : 'false' ?>">
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold" for="dyd-password">Hasło</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                <input type="password"
                       class="form-control form-control-lg"
                       id="dyd-password" name="password"
                       required
                       autocomplete="current-password"
                       placeholder="••••••••"
                       aria-invalid="<?= ($ec && $tab === 'dydaktyk') ? 'true' : 'false' ?>">
                <button class="btn btn-outline-secondary" type="button"
                        data-kp-pw-toggle="dyd-password"
                        aria-label="Pokaż lub ukryj hasło" aria-pressed="false">
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
              </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100">
              <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
            </button>
          </form>

          <p class="text-body-secondary mt-3 mb-0" style="font-size:.82rem">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Użyj danych logowania do Systemu Wspomagania Zarządzania Organizacją.
          </p>
        </div><!-- /#dydPwCollapse -->

      </div><!-- /#tab-dydaktyk -->

      <!-- ═══ ZAKŁADKA: RODZIC / OPIEKUN ══════════════════════════════════ -->
      <div class="tab-pane fade<?= $tab === 'rodzic' ? ' show active' : '' ?>"
           id="tab-rodzic" role="tabpanel" aria-labelledby="tab-rodzic-btn">

        <form method="post" action="kursant/parent_login.php" autocomplete="on">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="par-login">Login rodzica/opiekuna</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-person"></i></span>
              <input type="text"
                     class="form-control form-control-lg<?= ($ec && $tab === 'rodzic') ? ' is-invalid' : '' ?>"
                     id="par-login" name="login"
                     value="<?= $prefill_login ?>"
                     required
                     <?= $tab === 'rodzic' ? 'autofocus' : '' ?>
                     autocomplete="username"
                     placeholder="np. j.kowalski-r"
                     aria-invalid="<?= ($ec && $tab === 'rodzic') ? 'true' : 'false' ?>">
            </div>
            <div class="form-text">Login konta opiekuna nadany w placówce.</div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold" for="par-password">Hasło</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
              <input type="password"
                     class="form-control form-control-lg"
                     id="par-password" name="password"
                     required
                     autocomplete="current-password"
                     placeholder="••••••••"
                     aria-invalid="<?= ($ec && $tab === 'rodzic') ? 'true' : 'false' ?>">
              <button class="btn btn-outline-secondary" type="button"
                      data-kp-pw-toggle="par-password"
                      aria-label="Pokaż lub ukryj hasło" aria-pressed="false">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
          </button>
        </form>

        <p class="text-body-secondary mt-3 mb-0" style="font-size:.82rem">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          Nie masz hasła? Zaloguj się kodem SMS wysłanym na Twój numer telefonu.
        </p>
        <a href="kursant/parent.php" class="btn btn-outline-secondary w-100 mt-2">
          <i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Logowanie kodem SMS
        </a>

      </div><!-- /#tab-rodzic -->

      <!-- ═══ ZAKŁADKA: OSOBA UPOWAŻNIONA ══════════════════════════════════ -->
      <div class="tab-pane fade<?= $tab === 'up' ? ' show active' : '' ?>"
           id="tab-up" role="tabpanel" aria-labelledby="tab-up-btn">

        <form method="post" action="kursant/authp_login.php" autocomplete="on">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="up-login">Login osoby upoważnionej</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-person-check"></i></span>
              <input type="text"
                     class="form-control form-control-lg<?= ($ec && $tab === 'up') ? ' is-invalid' : '' ?>"
                     id="up-login" name="login"
                     value="<?= $prefill_login ?>"
                     required
                     <?= $tab === 'up' ? 'autofocus' : '' ?>
                     autocomplete="username"
                     placeholder="Login nadany przez kursanta"
                     aria-invalid="<?= ($ec && $tab === 'up') ? 'true' : 'false' ?>">
            </div>
            <div class="form-text">Login i hasło nadane przez kursanta w zakładce „Upoważnieni".</div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold" for="up-password">Hasło</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
              <input type="password"
                     class="form-control form-control-lg"
                     id="up-password" name="password"
                     required
                     autocomplete="current-password"
                     placeholder="••••••••"
                     aria-invalid="<?= ($ec && $tab === 'up') ? 'true' : 'false' ?>">
              <button class="btn btn-outline-secondary" type="button"
                      data-kp-pw-toggle="up-password"
                      aria-label="Pokaż lub ukryj hasło" aria-pressed="false">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
          </button>
        </form>

        <p class="text-body-secondary mt-3 mb-0" style="font-size:.82rem">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          Dostęp do rozliczeń, frekwencji i harmonogramu kursanta, który Cię upoważnił.
        </p>

      </div><!-- /#tab-up -->

    </div><!-- /.tab-content -->

    <?php /* Rozjazd dla kogoś, kto tu zabłądził — ten sam pomysł co na ekranie
             logowania do CRM. Panel TI dzieli adres z resztą systemu, więc trafia
             tu też ktoś szukający zupełnie innego miejsca. */ ?>
    <div class="kp-lost">
      <h2 class="kp-lost-h">Szukasz czegoś innego?</h2>
      <p class="kp-lost-sub">Ten ekran prowadzi do zajęć TI. Inne miejsca:</p>
      <ul>
        <li>
          <a href="https://feer.org.pl">
            <i class="bi bi-globe2" aria-hidden="true"></i>
            <span><strong>Strona Fundacji FEER</strong>
              <span class="d">Informacje o działalności, kontakt, zapisy</span></span>
            <i class="bi bi-chevron-right arr" aria-hidden="true"></i>
          </a>
        </li>
        <li>
          <a href="<?= h(rtrim(APP_URL, '/')) ?>/panel/index.php">
            <i class="bi bi-person-heart" aria-hidden="true"></i>
            <span><strong>Panel wolontariusza i współpracownika</strong>
              <span class="d">Umowy, zadania, godziny, zaświadczenia</span></span>
            <i class="bi bi-chevron-right arr" aria-hidden="true"></i>
          </a>
        </li>
        <li>
          <a href="<?= h(rtrim(APP_URL, '/')) ?>/auth/login.php">
            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
            <span><strong>Logowanie do systemu</strong>
              <span class="d">Dla zespołu Fundacji — konto służbowe</span></span>
            <i class="bi bi-chevron-right arr" aria-hidden="true"></i>
          </a>
        </li>
      </ul>
    </div>

    <p class="kp-auth-footer">
      © <?= date('Y') ?> <?= h($KP_ORG) ?>
      <span aria-hidden="true"> · </span>
      <a href="<?= h($login_ui_url) ?>" class="kp-ui-switch">
        <?= $login_ui === 'alt' ? 'Wygląd standardowy' : 'Wygląd alternatywny' ?>
        <span class="visually-hidden"> — przeładuje ekran logowania w drugim układzie</span>
      </a>
    </p>

  </div><!-- /.kp-split-right -->

</main>

<script>
// ── Ikona + tytuł w logo przy zmianie zakładki ────────────────────────────────
(function(){
  var CFG = {
    kursant:  { icon:'bi-pc-display',   title:'Panel kursanta' },
    dydaktyk: { icon:'bi-easel2',       title:'Panel prowadzącego' },
    rodzic:   { icon:'bi-people-fill',  title:'Panel rodzica / opiekuna' },
    up:       { icon:'bi-person-check', title:'Dostęp upoważnionego' },
  };
  // Nazwa organizacji w dopełniaczu przychodzi z serwera (includes/org_case.php) —
  // po stronie przeglądarki tylko doklejamy ją do nazwy modułu.
  var ORG_GEN = <?= json_encode(org_name_genitive(), JSON_UNESCAPED_UNICODE) ?>;

  function applyHero(tab) {
    var c = CFG[tab] || CFG.kursant;
    var ic = document.getElementById('hero-icon');
    var tl = document.getElementById('hero-title');
    var mt = document.getElementById('kp-module-title');
    if (ic) ic.className = 'bi ' + c.icon;
    if (tl) tl.textContent = c.title;
    if (mt) mt.textContent = ORG_GEN ? c.title + ' ' + ORG_GEN : c.title;
  }
  applyHero('<?= $tab ?>');
  document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(btn){
    btn.addEventListener('shown.bs.tab', function(){
      applyHero((btn.getAttribute('data-bs-target') || '').replace('#tab-',''));
    });
  });
})();

// ── Pokaż/ukryj hasło — wspólny handler dla obu formularzy ───────────────────
document.addEventListener('click', function(e){
  var btn = e.target.closest('[data-kp-pw-toggle]');
  if (!btn) return;
  var pw = document.getElementById(btn.getAttribute('data-kp-pw-toggle'));
  if (!pw) return;
  var show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  btn.setAttribute('aria-pressed', show ? 'true' : 'false');
  var ic = btn.querySelector('i');
  if (ic) ic.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  pw.focus();
});

// ── Rozwinięcie formularza hasła dydaktyka + przesuń fokus na e-mail ─────────
(function(){
  var collapse = document.getElementById('dydPwCollapse');
  var toggle   = document.getElementById('dydPwToggle');
  if (!collapse || !toggle) return;
  collapse.addEventListener('shown.bs.collapse', function(){
    toggle.classList.add('d-none');
    var em = document.getElementById('dyd-email');
    if (em) em.focus();
  });
})();
</script>

<?php include __DIR__ . '/kursant/_layout_foot.php'; ?>
