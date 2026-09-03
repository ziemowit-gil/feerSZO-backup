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
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';

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

// Nawigacja — ścieżka aktywna (prefix URL, nie substring)
function _k30_active(string $path): bool {
    global $_uri;
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    $full = rtrim($base, '/') . $path;
    return str_starts_with($_uri, $full) || str_starts_with($_uri, $path);
}
?><!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($_k30_title) ?> — Dydaktyka<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/karty30/includes/k30.css?v=<?= filemtime(__DIR__ . '/k30.css') ?>">
</head>
<body>

<!-- ══ SKIP LINKI — pierwsze elementy focusowalne ═══════════════════════════ -->
<a href="#k30-main" class="skip-link btn btn-primary btn-sm">Przejdź do treści głównej</a>
<a href="#k30-nav"  class="skip-link btn btn-primary btn-sm">Przejdź do nawigacji</a>

<!-- ══ Live regiony — czytniki ekranu ogłaszają dynamiczne zmiany ═══════════ -->
<div aria-live="polite"    aria-atomic="true" class="visually-hidden" id="k30-live"        role="status"></div>
<div aria-live="assertive" aria-atomic="true" class="visually-hidden" id="k30-live-urgent" role="alert"></div>

<header class="sticky-top shadow-sm">

  <!-- ══ Pasek marki + użytkownik ═══════════════════════════════════════════ -->
  <nav class="navbar navbar-dark py-1 k30-topbar" aria-label="Pasek górny">
    <div class="container-fluid">
      <a href="<?= APP_URL ?>/karty30/index.php" class="navbar-brand d-flex align-items-center gap-2 fw-bold" aria-label="Dydaktyka 3 — strona główna">
        <span class="k30-logo-icon rounded-2" aria-hidden="true">
          <i class="bi bi-card-checklist text-white" style="font-size:1.05rem"></i>
        </span>
        <span class="lh-1">
          <span style="font-size:.97rem">Dydaktyka</span>
          <small class="d-block fw-normal opacity-60" style="font-size:.65rem;letter-spacing:.02em">KARTY 30<?= $_org_name ? ' · ' . h(mb_strtoupper(mb_substr($_org_name, 0, 18, 'UTF-8'), 'UTF-8')) : '' ?></small>
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
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white text-primary fw-bold flex-shrink-0"
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

</header>

  <!-- ══ Menu modułu (panel boczny — układ jak w panelu dydaktyka) ═════════ -->
  <?php
  $_k30_wait_count = 0;
  try {
      $r = db_one("SELECT COUNT(*) AS c FROM k30_waiting_list WHERE status IN ('waiting','contacted')");
      $_k30_wait_count = (int)($r['c'] ?? 0);
  } catch (\Throwable $e) {}

  /**
   * Spec menu bocznego: 'section' (nagłówek), 'sep' (linia), 'link' (pozycja).
   * Pozycja: [label, ikona, href, dopasowania… (prefiksy URL), 'badge'=>int]
   */
  $K30_NAV = [];
  $K30_NAV[] = ['link', 'Dashboard', 'grid-1x2-fill', '/karty30/index.php', ['/karty30/index']];
  $K30_NAV[] = ['link', 'Beneficjenci', 'people-fill', '/karty30/clients/index.php', ['/karty30/clients']];

  $K30_NAV[] = ['section', 'Dydaktyka (TI)'];
  $K30_NAV[] = ['link', 'Kursy i zajęcia', 'pc-display', '/karty30/ti/index.php', ['/karty30/ti/index','/karty30/ti/course','/karty30/ti/lesson']];
  if ($_can_write) {
      $K30_NAV[] = ['link', 'Plan nauczania',    'list-check',      '/karty30/ti/curriculum.php',  ['/karty30/ti/curriculum']];
      $K30_NAV[] = ['link', 'Testy',             'card-checklist',  '/karty30/ti/tests.php',       ['/karty30/ti/tests','/karty30/ti/test_build']];
      $K30_NAV[] = ['link', 'Materiały / eLearning', 'collection-play', '/karty30/ti/materials.php', ['/karty30/ti/materials']];
      $K30_NAV[] = ['link', 'Zadania domowe',    'journal-check',   '/karty30/ti/homework.php',    ['/karty30/ti/homework']];
      $K30_NAV[] = ['link', 'Dziennik ocen',     'table',           '/karty30/ti/grades.php',      ['/karty30/ti/grades']];
      $K30_NAV[] = ['link', 'Wiadomości',        'envelope',        '/karty30/ti/messages.php',    ['/karty30/ti/messages']];
      $K30_NAV[] = ['link', 'Komunikacja',       'megaphone',       '/karty30/ti/komunikacja.php', ['/karty30/ti/komunikacja']];
      $K30_NAV[] = ['link', 'Urlopy prowadzących','airplane',       '/karty30/ti/urlopy.php',      ['/karty30/ti/urlopy']];
      $K30_NAV[] = ['link', 'Rozliczenia (klasyczne)', 'receipt',   '/karty30/ti/billing.php',     ['/karty30/ti/billing']];
      $K30_NAV[] = ['link', 'Moduł Rozliczenia', 'cash-coin',       '/rozliczenia/index.php',      ['/rozliczenia/']];
  }
  if (is_admin()) {
      $K30_NAV[] = ['section', 'Administracja TI'];
      $K30_NAV[] = ['link', 'Konta kursantów',   'person-badge',    '/karty30/ti/kursant/accounts.php', ['/karty30/ti/kursant/accounts']];
      $K30_NAV[] = ['link', 'Dostępność prowadzących', 'clock-history', '/karty30/ti/availability.php', ['/karty30/ti/availability']];
      $K30_NAV[] = ['link', 'Nauka online',      'camera-video',    '/karty30/ti/online_admin.php',     ['/karty30/ti/online_admin']];
      $K30_NAV[] = ['link', 'Licencje',          'key',             '/karty30/ti/licencje_admin.php',   ['/karty30/ti/licencje_admin']];
      $K30_NAV[] = ['link', 'VLAB / Docker',     'hdd-stack',       '/karty30/ti/vlab_admin.php',       ['/karty30/ti/vlab_admin']];
      $K30_NAV[] = ['link', 'Umowy VLab',        'file-earmark-lock','/karty30/ti/vlab_contracts.php',  ['/karty30/ti/vlab_contracts']];
      $K30_NAV[] = ['link', 'Regulaminy',        'file-earmark-text','/karty30/ti/terms_admin.php',     ['/karty30/ti/terms_admin']];
      $K30_NAV[] = ['link', 'M365 K30',          'microsoft',       '/karty30/admin/m365.php',          ['/karty30/admin/m365']];
      $K30_NAV[] = ['link', 'Szablony e-mail',   'envelope-paper',  '/karty30/ti/email_templates.php',  ['/karty30/ti/email_templates']];
  }

  if (k30_pfron_enabled()) {
      $K30_NAV[] = ['section', 'PFRON'];
      $K30_NAV[] = ['link', 'Szkolenia PFRON',   'mortarboard',     '/karty30/pfron/training.php', ['/karty30/pfron/training']];
      $K30_NAV[] = ['link', 'Dokumenty PFRON',   'file-earmark-pdf','/karty30/pfron/docs.php',     ['/karty30/pfron/docs','/karty30/pfron/doc_print']];
  }

  $K30_NAV[] = ['section', 'Konsultacje tyflo'];
  $K30_NAV[] = ['link', 'Lista oczekujących', 'hourglass-split', '/karty30/waiting/index.php', ['/karty30/waiting'], $_k30_wait_count];
  $K30_NAV[] = ['link', 'Harmonogram wizyt',  'calendar3',       '/karty30/schedules/index.php',
                ['/karty30/schedules/index','/karty30/schedules/add','/karty30/schedules/edit','/karty30/schedules/view']];
  $K30_NAV[] = ['link', 'Kalendarz',          'calendar-week',   '/karty30/schedules/calendar.php', ['/karty30/schedules/calendar']];
  if ($_can_write)
      $K30_NAV[] = ['link', 'Szybka rezerwacja', 'lightning-charge-fill', '/karty30/schedules/quick.php', ['/karty30/schedules/quick']];
  $K30_NAV[] = ['link', 'Konsultacje',        'clipboard2-check','/karty30/consultations/index.php', ['/karty30/consultations']];
  $K30_NAV[] = ['link', 'Raporty',            'bar-chart-line',  '/karty30/reports/index.php',       ['/karty30/reports']];
  $K30_NAV[] = ['link', 'Czarna lista',       'slash-circle',    '/karty30/blacklist/index.php',     ['/karty30/blacklist']];

  if (is_admin()) {
      $K30_NAV[] = ['section', 'Administracja'];
      $K30_NAV[] = ['link', 'Doradcy',            'people-fill',      '/karty30/admin/consultants.php', ['/karty30/admin/consultants']];
      $K30_NAV[] = ['link', 'Certyfikaty x509',   'patch-check-fill', '/karty30/admin/cert_upload.php', ['/karty30/admin/cert_upload']];
      $K30_NAV[] = ['link', 'Cennik',             'currency-exchange','/karty30/admin/pricing.php',     ['/karty30/admin/pricing']];
      $K30_NAV[] = ['link', 'Rejestr dostępu',    'shield-lock',      '/karty30/admin/access_log.php',  ['/karty30/admin/access_log']];
      $K30_NAV[] = ['link', 'Testowe logowanie',  'person-fill-gear', '/karty30/admin/test_login.php',  ['/karty30/admin/test_login']];
      $K30_NAV[] = ['link', 'Wyczyść dane D3',    'trash3',           '/karty30/admin/clean_k30.php',   ['/karty30/admin/clean_k30'], 0, true];
  }

  $K30_NAV[] = ['sep'];
  if ($_can_write)
      $K30_NAV[] = ['link', 'Nowy beneficjent', 'person-plus', '/karty30/clients/add.php', ['/karty30/clients/add']];
  $K30_NAV[] = ['link', 'Portal SZO', 'box-arrow-left', '/portal.php', ['/portal.php']];
  ?>

  <button id="k30SbToggle" type="button" aria-label="Pokaż menu modułu" aria-controls="k30-nav" aria-expanded="false">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <div class="k30-sb-overlay" id="k30SbOverlay" hidden></div>

  <nav class="k30-sidebar" id="k30-nav" aria-label="Nawigacja modułu">
    <?php foreach ($K30_NAV as $_it):
      if ($_it[0] === 'sep')     { echo '<div class="k30-sb-sep"></div>'; continue; }
      if ($_it[0] === 'section') { echo '<div class="k30-sb-section">' . h($_it[1]) . '</div>'; continue; }
      [, $_lbl, $_ico, $_href, $_matches] = $_it;
      $_badge  = $_it[5] ?? 0;
      $_danger = !empty($_it[6]);
      $_on = false;
      foreach ($_matches as $_m) { if (_k30_active($_m)) { $_on = true; break; } }
    ?>
    <a class="k30-sb-link<?= $_on ? ' active' : '' ?><?= $_danger ? ' k30-sb-danger' : '' ?>"
       href="<?= APP_URL . h($_href) ?>" <?= $_on ? 'aria-current="page"' : '' ?>>
      <i class="bi bi-<?= h($_ico) ?>" aria-hidden="true"></i><span class="k30-sb-txt"><?= h($_lbl) ?></span>
      <?php if ($_badge > 0): ?><span class="badge text-bg-warning ms-auto"><?= (int)$_badge ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>


<?php $_brw = dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; if (is_file($_brw)) require_once $_brw; ?>
<?php $ASAI_WIDGET_SCOPE = 'karty30';
      $_asw = dirname(dirname(__DIR__)) . '/includes/asystent_widget.php'; if (is_file($_asw)) require_once $_asw; ?>

<!-- ══ GŁÓWNA TREŚĆ ════════════════════════════════════════════════════════ -->
<main class="container-fluid py-4 k30-main-content k30-content k30-wrap" id="k30-main" role="main" tabindex="-1">

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
