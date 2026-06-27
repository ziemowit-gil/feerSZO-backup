<?php
/**
 * karty30/includes/header_k30.php — Layout modułu Dydaktyka (d. TyfloKonsultacje).
 *
 * Czysty Bootstrap 5.3 (komponenty: navbar, dropdown, alert, container).
 * Kolor marki (pomarańcz) przez nadpisanie --bs-primary — bez własnej warstwy klas k30-*.
 *
 * Dostępność WCAG 2.1 AA:
 *  - Skip link jako pierwszy element focusowalny
 *  - Semantyczne landmarki HTML5 (header, nav, main, footer)
 *  - Widoczny focus ring (żółty, wysoki kontrast)
 *  - Nawigacja klawiaturą (Bootstrap navbar / dropdown)
 *  - Live region dla komunikatów (aria-live)
 *  - Ikony aria-hidden + tekst widoczny / aria-label
 *
 * Wymaga: $PAGE_TITLE przed include.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();

// ── IKA — wymagane przy każdym dostępie do Karty30 ───────────────────────────
// Moduł przetwarza dane osobowe beneficjentów (imię, adres, opis problemu).
// Sesja IKA ważna 30 min — wspólna z CRM i systemem głównym.
if (function_exists('ika_require')) {
    (function () {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
        if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        ika_require(APP_URL . $uri);
    })();
}

$_ku        = current_user();
$_k30_title = $PAGE_TITLE ?? 'Dydaktyka';
$_uri       = $_SERVER['REQUEST_URI'] ?? '';
$_org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_can_write = can_write('karty30') || is_admin();

// Inicjały użytkownika
$_ku_initials = '?';
$_ku_name     = '';
if ($_ku) {
    $_name = trim(($_ku['first_name'] ?? '') . ' ' . ($_ku['last_name'] ?? ''));
    if (!$_name) $_name = $_ku['name'] ?? '';
    $_ku_name = $_name ?: ($_ku['email'] ?? 'Użytkownik');
    $_parts   = preg_split('/\s+/', trim($_name));
    $_ku_initials = '';
    foreach ($_parts as $w) $_ku_initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    $_ku_initials = mb_substr($_ku_initials, 0, 2, 'UTF-8') ?: '?';
}

// Nawigacja — ścieżka aktywna
function _k30_active(string $path): bool {
    global $_uri;
    return str_contains($_uri, $path);
}
?><!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($_k30_title) ?> — Dydaktyka<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
/* ── Kolor marki: pomarańcz nałożony na standardowe tokeny Bootstrap ──────── */
:root {
  --bs-primary:#c2410c;            /* orange-700 — biały tekst kontrast ~5:1 (AA) */
  --bs-primary-rgb:194,65,12;
  --bs-link-color-rgb:194,65,12;
  --bs-link-hover-color-rgb:154,52,18;
}
.btn-primary {
  --bs-btn-bg:#c2410c; --bs-btn-border-color:#c2410c;
  --bs-btn-hover-bg:#9a3412; --bs-btn-hover-border-color:#9a3412;
  --bs-btn-active-bg:#7c2d12; --bs-btn-active-border-color:#7c2d12;
  --bs-btn-disabled-bg:#c2410c; --bs-btn-disabled-border-color:#c2410c;
}
.btn-outline-primary {
  --bs-btn-color:#c2410c; --bs-btn-border-color:#c2410c;
  --bs-btn-hover-bg:#c2410c; --bs-btn-hover-border-color:#c2410c;
  --bs-btn-active-bg:#9a3412; --bs-btn-active-border-color:#9a3412;
}
.bg-primary { background-color:#c2410c !important; }
.text-primary { color:#c2410c !important; }
.link-primary { color:#c2410c !important; }

/* ── WCAG: widoczny, spójny focus dla klawiatury ─────────────────────────── */
*:focus-visible {
  outline:3px solid #facc15 !important;   /* żółty — widoczny na każdym tle */
  outline-offset:2px !important;
  box-shadow:none !important;
}
/* ── WCAG 2.4.1: skip linki ──────────────────────────────────────────────── */
.skip-link {
  position:absolute; left:.75rem; top:-200%; z-index:1090;
  transition:top .15s ease;
}
.skip-link:focus { top:.5rem; }

/* Aktywna pozycja menu — wyróżnienie niezależne od koloru (pogrubienie + tło) */
.navbar .nav-link.active,
.navbar .dropdown-item.active { font-weight:700; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition:none !important; animation:none !important; }
}
</style>
</head>
<body>

<!-- ══ SKIP LINKI — pierwsze elementy focusowalne ═══════════════════════════ -->
<a href="#k30-main" class="skip-link btn btn-primary btn-sm">Przejdź do treści głównej</a>
<a href="#k30-nav"  class="skip-link btn btn-primary btn-sm" style="left:14rem">Przejdź do nawigacji</a>

<!-- ══ Live regiony — czytniki ekranu ogłaszają dynamiczne zmiany ═══════════ -->
<div aria-live="polite"    aria-atomic="true" class="visually-hidden" id="k30-live"        role="status"></div>
<div aria-live="assertive" aria-atomic="true" class="visually-hidden" id="k30-live-urgent" role="alert"></div>

<header class="sticky-top shadow-sm">

  <!-- ══ Pasek marki + użytkownik ═══════════════════════════════════════════ -->
  <nav class="navbar navbar-dark bg-primary py-1" aria-label="Pasek górny">
    <div class="container-fluid">
      <a href="<?= APP_URL ?>/karty30/index.php" class="navbar-brand d-flex align-items-center gap-2 fw-bold" aria-label="Dydaktyka Karty 30 — strona główna">
        <span class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-25 rounded" style="width:34px;height:34px" aria-hidden="true">
          <i class="bi bi-card-checklist fs-5"></i>
        </span>
        <span class="lh-1">
          Dydaktyka
          <small class="d-block fw-normal opacity-75" style="font-size:.68rem">Karty 30<?= $_org_name ? ' · ' . h(mb_substr($_org_name, 0, 20, 'UTF-8')) : '' ?></small>
        </span>
      </a>

      <div class="d-flex align-items-center gap-2">
        <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
        <button type="button" class="btn btn-sm btn-outline-light d-inline-flex align-items-center gap-1"
                data-bs-toggle="modal" data-bs-target="#bugReportModal"
                title="Zgłoś błąd na tej stronie" aria-label="Zgłoś błąd">
          <i class="bi bi-bug-fill" aria-hidden="true"></i>
          <span class="d-none d-sm-inline">Zgłoś błąd</span>
        </button>
        <?php endif; ?>

        <?php $msw_active='k30'; $msw_dark=true; $_msw=dirname(dirname(__DIR__)).'/includes/module_switcher.php'; if (is_file($_msw)) require_once $_msw; ?>

        <?php if ($_ku): ?>
        <div class="dropdown">
          <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle d-inline-flex align-items-center gap-2"
                  data-bs-toggle="dropdown" aria-expanded="false"
                  aria-label="Menu użytkownika: <?= h($_ku_name) ?>">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white text-primary fw-bold"
                  style="width:26px;height:26px;font-size:.72rem" aria-hidden="true"><?= h($_ku_initials) ?></span>
            <span class="d-none d-md-inline"><?= h(explode(' ', $_ku_name)[0]) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:210px">
            <li>
              <div class="px-3 py-2 border-bottom">
                <div class="fw-bold"><?= h($_ku_name) ?></div>
                <div class="text-body-secondary small"><?= h($_ku['email'] ?? '') ?></div>
              </div>
            </li>
            <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/index.php">
              <i class="bi bi-person-circle me-2" aria-hidden="true"></i>Moje konto
            </a></li>
            <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/index.php">
              <i class="bi bi-house me-2" aria-hidden="true"></i>System główny
            </a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php">
              <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Wyloguj się
            </a></li>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <!-- ══ Menu modułu ════════════════════════════════════════════════════════ -->
  <?php
  $_k30_wait_count = 0;
  try {
      $r = db_one("SELECT COUNT(*) AS c FROM k30_waiting_list WHERE status IN ('waiting','contacted')");
      $_k30_wait_count = (int)($r['c'] ?? 0);
  } catch (\Throwable $e) {}
  $g_ti      = _k30_active('/karty30/ti/');
  $g_clients = _k30_active('/karty30/clients');
  $g_kons    = $g_clients || _k30_active('/karty30/waiting')
            || _k30_active('/karty30/schedules') || _k30_active('/karty30/consultations')
            || _k30_active('/karty30/reports') || _k30_active('/karty30/blacklist')
            || _k30_active('/karty30/admin/');
  ?>
  <nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom py-1" id="k30-nav" aria-label="Nawigacja modułu">
    <div class="container-fluid">
      <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse"
              data-bs-target="#k30-menu" aria-controls="k30-menu" aria-expanded="false"
              aria-label="Przełącz nawigację">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="k30-menu">
        <ul class="navbar-nav me-auto">
          <li class="nav-item">
            <a class="nav-link d-inline-flex align-items-center gap-2 <?= _k30_active('/karty30/index') ? 'active' : '' ?>"
               href="<?= APP_URL ?>/karty30/index.php" <?= _k30_active('/karty30/index') ? 'aria-current="page"' : '' ?>>
              <i class="bi bi-grid-1x2-fill" aria-hidden="true"></i>Dashboard</a>
          </li>

          <li class="nav-item">
            <a class="nav-link d-inline-flex align-items-center gap-2 <?= $g_clients ? 'active' : '' ?>"
               href="<?= APP_URL ?>/karty30/clients/index.php" <?= $g_clients ? 'aria-current="page"' : '' ?>>
              <i class="bi bi-people-fill" aria-hidden="true"></i>Beneficjenci</a>
          </li>

          <!-- ══ Dydaktyka (TI) ══ -->
          <li class="nav-item dropdown">
            <button type="button" class="nav-link dropdown-toggle d-inline-flex align-items-center gap-2 <?= $g_ti ? 'active' : '' ?>"
                    data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-pc-display" aria-hidden="true"></i>Dydaktyka (TI)</button>
            <ul class="dropdown-menu">
              <li><h6 class="dropdown-header">Nauka i zajęcia</h6></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/index.php" <?= _k30_active('/karty30/ti/index') || _k30_active('/karty30/ti/course') || _k30_active('/karty30/ti/lesson') ? 'aria-current="page"' : '' ?>><i class="bi bi-pc-display me-2" aria-hidden="true"></i>Kursy i zajęcia</a></li>
              <?php if ($_can_write): ?>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/curriculum.php" <?= _k30_active('/karty30/ti/curriculum') ? 'aria-current="page"' : '' ?>><i class="bi bi-list-check me-2" aria-hidden="true"></i>Plan nauczania</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/tests.php" <?= _k30_active('/karty30/ti/tests') || _k30_active('/karty30/ti/test_build') ? 'aria-current="page"' : '' ?>><i class="bi bi-card-checklist me-2" aria-hidden="true"></i>Testy</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/materials.php" <?= _k30_active('/karty30/ti/materials') ? 'aria-current="page"' : '' ?>><i class="bi bi-collection-play me-2" aria-hidden="true"></i>Materiały / eLearning</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/homework.php" <?= _k30_active('/karty30/ti/homework') ? 'aria-current="page"' : '' ?>><i class="bi bi-journal-check me-2" aria-hidden="true"></i>Zadania domowe</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/grades.php" <?= _k30_active('/karty30/ti/grades') ? 'aria-current="page"' : '' ?>><i class="bi bi-table me-2" aria-hidden="true"></i>Dziennik ocen</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/messages.php" <?= _k30_active('/karty30/ti/messages') ? 'aria-current="page"' : '' ?>><i class="bi bi-envelope me-2" aria-hidden="true"></i>Wiadomości</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/komunikacja.php" <?= _k30_active('/karty30/ti/komunikacja') ? 'aria-current="page"' : '' ?>><i class="bi bi-megaphone me-2" aria-hidden="true"></i>Komunikacja (e-mail/SMS)</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/urlopy.php" <?= _k30_active('/karty30/ti/urlopy') ? 'aria-current="page"' : '' ?>><i class="bi bi-airplane me-2" aria-hidden="true"></i>Urlopy prowadzących</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/billing.php" <?= _k30_active('/karty30/ti/billing') ? 'aria-current="page"' : '' ?>><i class="bi bi-receipt me-2" aria-hidden="true"></i>Rozliczenia</a></li>
              <?php endif; ?>
              <?php if (is_admin()): ?>
              <li><hr class="dropdown-divider"></li>
              <li><h6 class="dropdown-header">Administracja TI</h6></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/kursant/accounts.php" <?= _k30_active('/karty30/ti/kursant/accounts') ? 'aria-current="page"' : '' ?>><i class="bi bi-person-badge me-2" aria-hidden="true"></i>Konta kursantów</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/availability.php" <?= _k30_active('/karty30/ti/availability') ? 'aria-current="page"' : '' ?>><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Dostępność prowadzących</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/online_admin.php" <?= _k30_active('/karty30/ti/online_admin') ? 'aria-current="page"' : '' ?>><i class="bi bi-camera-video me-2" aria-hidden="true"></i>Nauka online</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/moodle_admin.php" <?= _k30_active('/karty30/ti/moodle_admin') ? 'aria-current="page"' : '' ?>><i class="bi bi-mortarboard me-2" aria-hidden="true"></i>Moodle</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/licencje_admin.php" <?= _k30_active('/karty30/ti/licencje_admin') ? 'aria-current="page"' : '' ?>><i class="bi bi-key me-2" aria-hidden="true"></i>Licencje</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/vlab_admin.php" <?= _k30_active('/karty30/ti/vlab_admin') ? 'aria-current="page"' : '' ?>><i class="bi bi-hdd-stack me-2" aria-hidden="true"></i>VLAB / Docker</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/vlab_contracts.php" <?= _k30_active('/karty30/ti/vlab_contracts') ? 'aria-current="page"' : '' ?>><i class="bi bi-file-earmark-lock me-2" aria-hidden="true"></i>Umowy VLab</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/terms_admin.php" <?= _k30_active('/karty30/ti/terms_admin') ? 'aria-current="page"' : '' ?>><i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Regulaminy</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/m365.php" <?= _k30_active('/karty30/admin/m365') ? 'aria-current="page"' : '' ?>><i class="bi bi-microsoft me-2" aria-hidden="true"></i>M365 K30</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/email_templates.php" <?= _k30_active('/karty30/ti/email_templates') ? 'aria-current="page"' : '' ?>><i class="bi bi-envelope-paper me-2" aria-hidden="true"></i>Szablony e-mail</a></li>
              <?php endif; ?>
            </ul>
          </li>

          <!-- ══ Konsultacje tyflo ══ -->
          <li class="nav-item dropdown">
            <button type="button" class="nav-link dropdown-toggle d-inline-flex align-items-center gap-2 <?= $g_kons ? 'active' : '' ?>"
                    data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-clipboard2-check" aria-hidden="true"></i>Konsultacje tyflo<?php if ($_k30_wait_count > 0): ?> <span class="badge text-bg-warning"><?= $_k30_wait_count ?></span><?php endif; ?></button>
            <ul class="dropdown-menu">
              <li><h6 class="dropdown-header">Beneficjenci</h6></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/clients/index.php" <?= _k30_active('/karty30/clients') ? 'aria-current="page"' : '' ?>><i class="bi bi-people-fill me-2" aria-hidden="true"></i>Beneficjenci</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/waiting/index.php" <?= _k30_active('/karty30/waiting') ? 'aria-current="page"' : '' ?>><i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Lista oczekujących<?php if ($_k30_wait_count > 0): ?> <span class="badge text-bg-warning"><?= $_k30_wait_count ?></span><?php endif; ?></a></li>
              <li><hr class="dropdown-divider"></li>
              <li><h6 class="dropdown-header">Wizyty</h6></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/index.php" <?= _k30_active('/karty30/schedules/index') || _k30_active('/karty30/schedules/add') || _k30_active('/karty30/schedules/edit') || _k30_active('/karty30/schedules/view') ? 'aria-current="page"' : '' ?>><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Harmonogram wizyt</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/calendar.php" <?= _k30_active('/karty30/schedules/calendar') ? 'aria-current="page"' : '' ?>><i class="bi bi-calendar-week me-2" aria-hidden="true"></i>Kalendarz</a></li>
              <?php if ($_can_write): ?>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/quick.php" <?= _k30_active('/karty30/schedules/quick') ? 'aria-current="page"' : '' ?>><i class="bi bi-lightning-charge-fill me-2" aria-hidden="true"></i>Szybka rezerwacja</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/add.php"><i class="bi bi-calendar-plus me-2" aria-hidden="true"></i>Nowy termin</a></li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li><h6 class="dropdown-header">Konsultacje i raporty</h6></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/consultations/index.php" <?= _k30_active('/karty30/consultations') ? 'aria-current="page"' : '' ?>><i class="bi bi-clipboard2-check me-2" aria-hidden="true"></i>Konsultacje</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/reports/index.php" <?= _k30_active('/karty30/reports') ? 'aria-current="page"' : '' ?>><i class="bi bi-bar-chart-line me-2" aria-hidden="true"></i>Raporty</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/blacklist/index.php" <?= _k30_active('/karty30/blacklist') ? 'aria-current="page"' : '' ?>><i class="bi bi-slash-circle me-2" aria-hidden="true"></i>Czarna lista</a></li>
              <?php if (is_admin()): ?>
              <li><hr class="dropdown-divider"></li>
              <li><h6 class="dropdown-header">Administracja</h6></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/consultants.php" <?= _k30_active('/karty30/admin/consultants') ? 'aria-current="page"' : '' ?>><i class="bi bi-people-fill me-2" aria-hidden="true"></i>Doradcy</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/cert_upload.php" <?= _k30_active('/karty30/admin/cert_upload') ? 'aria-current="page"' : '' ?>><i class="bi bi-patch-check-fill me-2" aria-hidden="true"></i>Certyfikaty x509</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/pricing.php" <?= _k30_active('/karty30/admin/pricing') ? 'aria-current="page"' : '' ?>><i class="bi bi-currency-exchange me-2" aria-hidden="true"></i>Cennik</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/karty30/admin/clean_k30.php" <?= _k30_active('/karty30/admin/clean_k30') ? 'aria-current="page"' : '' ?>><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Wyczyść dane K30</a></li>
              <?php endif; ?>
            </ul>
          </li>
        </ul>

        <div class="d-flex align-items-center gap-2 flex-wrap py-1 py-lg-0">
          <?php if ($_can_write): ?>
          <a class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1" href="<?= APP_URL ?>/karty30/clients/add.php">
            <i class="bi bi-person-plus" aria-hidden="true"></i>Nowy beneficjent</a>
          <?php endif; ?>
          <a class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1" href="<?= APP_URL ?>/portal.php" aria-label="Wróć do wyboru systemu — portal główny">
            <i class="bi bi-box-arrow-left" aria-hidden="true"></i>Portal</a>
        </div>
      </div>
    </div>
  </nav>
</header>

<?php $_brw = dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; if (is_file($_brw)) require_once $_brw; ?>

<!-- Notka o zmianie nazwy modułu -->
<div class="alert alert-info border-0 border-bottom rounded-0 mb-0 py-2 small d-flex align-items-center gap-2" role="note">
  <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
  <span>Moduł zmienił nazwę z „TyfloKonsultacje" na <strong>„Dydaktyka"</strong>. Dawne konsultacje znajdziesz w sekcji „Konsultacje i raporty".</span>
</div>

<!-- ══ GŁÓWNA TREŚĆ ════════════════════════════════════════════════════════ -->
<main class="container-fluid py-4" id="k30-main" role="main" tabindex="-1" style="max-width:1320px">

<?php
// Flash messages — ogłoszone przez aria-live
$_flash = flash_get();
if ($_flash):
  $_ftype  = $_flash['type'] ?? 'info';
  $_fmsg   = $_flash['msg']  ?? '';
  $_fmap   = ['success'=>'success','danger'=>'danger','warning'=>'warning','info'=>'info'];
  $_fclass = $_fmap[$_ftype] ?? 'info';
  $_ficons = ['success'=>'bi-check-circle-fill','danger'=>'bi-exclamation-triangle-fill','warning'=>'bi-exclamation-circle-fill','info'=>'bi-info-circle-fill'];
  $_flabels= ['success'=>'Sukces','danger'=>'Błąd','warning'=>'Ostrzeżenie','info'=>'Informacja'];
?>
<div class="alert alert-<?= h($_fclass) ?> alert-dismissible d-flex align-items-start gap-2" role="alert"
     aria-label="<?= h($_flabels[$_ftype] ?? 'Informacja') ?>: <?= h($_fmsg) ?>">
  <i class="bi <?= h($_ficons[$_ftype] ?? 'bi-info-circle-fill') ?> fs-5 flex-shrink-0" aria-hidden="true"></i>
  <span class="flex-grow-1"><?= h($_fmsg) ?></span>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij powiadomienie"></button>
</div>
<script>
// Ogłoś flash message przez live region (dla czytników nieodczytujących role=alert automatycznie)
(function() {
  var live = document.getElementById('k30-live-urgent');
  if (live) live.textContent = '<?= addslashes(h($_flabels[$_ftype] ?? 'Informacja')) ?>: <?= addslashes(h($_fmsg)) ?>';
})();
</script>
<?php endif; ?>
