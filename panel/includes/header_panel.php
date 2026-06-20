<?php
/**
 * panel/includes/header_panel.php — Standalone layout panelu wolontariusza.
 * Stały kolor — nie zależy od ustawień organizacji.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$_pv_title  = $PAGE_TITLE ?? 'Panel wolontariusza';
$_pv_uri    = $_SERVER['REQUEST_URI'] ?? '';
$_pv_org    = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

// Migracja kolumny per-user panel_color
try { db()->exec("ALTER TABLE users ADD COLUMN panel_color TEXT"); } catch (\Throwable $e) {}

// Kolor: najpierw per-user, fallback na org setting
$_pv_user_row  = db_one("SELECT panel_color FROM users WHERE id=?", [(int)(current_user()['id'] ?? 0)]);
$_vol_color    = (($_pv_user_row['panel_color'] ?? '') !== '')
    ? $_pv_user_row['panel_color']
    : (org_setting('volunteer_color') ?: '#1D4ED8');

// Lekkie tło (10% krycia na białym) — bez zewnętrznych zależności
function _pv_light_bg(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    return sprintf('#%02X%02X%02X',
        (int)round($r*.1 + 255*.9),
        (int)round($g*.1 + 255*.9),
        (int)round($b*.1 + 255*.9)
    );
}
$_vol_bg = _pv_light_bg($_vol_color);

$_pu = current_user();
$_pu_ini = '?'; $_pu_name = '';
if ($_pu) {
    $n = trim(($_pu['first_name']??'').' '.($_pu['last_name']??'')) ?: ($_pu['name']??'');
    $_pu_name = $n ?: ($_pu['email']??'Użytkownik');
    $_pu_ini = '';
    foreach (preg_split('/\s+/', trim($n)) as $w) $_pu_ini .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
    $_pu_ini = mb_substr($_pu_ini, 0, 2, 'UTF-8') ?: '?';
}

function _pv_nav_active(string $path): string {
    global $_pv_uri;
    return str_contains($_pv_uri, $path) ? ' pv-active' : '';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_pv_title) ?> — Panel<?= $_pv_org ? ' · '.h($_pv_org) : '' ?></title>
<link rel="manifest" href="<?= APP_URL ?>/manifest.php">
<meta name="theme-color" content="<?= h($_vol_color) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
:root {
  --vol-color: <?= h($_vol_color) ?>;
  --vol-bg:    <?= h($_vol_bg) ?>;
  --vol-on:    #ffffff;
  --vol-sidebar-w: 220px;
  --vol-topbar-h:  52px;
}
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0}
body{background:#F0F2F5;font-family:system-ui,-apple-system,sans-serif;display:flex;flex-direction:column;min-height:100vh}

/* Skip link */
.pv-skip{position:absolute;top:-100%;left:1rem;z-index:9999;background:var(--vol-color);color:var(--vol-on);padding:.75rem 1.5rem;border-radius:0 0 8px 8px;font-size:1rem;font-weight:700;text-decoration:none;border:3px solid #FBBF24}
.pv-skip:focus{top:0}
*:focus-visible{outline:3px solid #FBBF24 !important;outline-offset:3px !important;border-radius:3px}

/* Topbar */
.pv-topbar{height:var(--vol-topbar-h);background:var(--vol-color);color:var(--vol-on);display:flex;align-items:center;padding:0 1.25rem 0 0;position:fixed;top:0;left:0;right:0;z-index:1040;box-shadow:0 2px 8px rgba(0,0,0,.18)}
.pv-brand{width:var(--vol-sidebar-w);display:flex;align-items:center;gap:.6rem;padding:0 1.1rem;flex-shrink:0;text-decoration:none;color:var(--vol-on);font-weight:800;font-size:.95rem;height:100%;border-right:1px solid rgba(255,255,255,.2)}
.pv-brand:hover{background:rgba(255,255,255,.08);color:var(--vol-on)}
.pv-brand-icon{width:30px;height:30px;background:rgba(255,255,255,.2);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.pv-brand-sub{font-size:.62rem;opacity:.7;font-weight:400;line-height:1}
.pv-topbar-bc{flex:1;padding:0 1.25rem;font-size:.85rem;color:rgba(255,255,255,.85);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pv-topbar-user{display:flex;align-items:center;gap:.75rem;padding-left:1rem;flex-shrink:0}
.pv-avatar{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.25);color:var(--vol-on);display:flex;align-items:center;justify-content:center;font-size:.73rem;font-weight:700;cursor:pointer;border:2px solid rgba(255,255,255,.35);line-height:1}

/* Sidebar */
.pv-sidebar{position:fixed;top:var(--vol-topbar-h);left:0;bottom:0;width:var(--vol-sidebar-w);background:#fff;border-right:1px solid #E5E7EB;display:flex;flex-direction:column;overflow-y:auto;z-index:1030;transition:transform .25s}
.pv-sidebar::-webkit-scrollbar{width:4px}
.pv-sidebar::-webkit-scrollbar-thumb{background:#E5E7EB;border-radius:2px}
.pv-nav-label{font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;padding:.85rem 1rem .3rem;user-select:none}
.pv-nav-link{display:flex;align-items:center;gap:.6rem;padding:.48rem .75rem;border-radius:7px;font-size:.84rem;font-weight:500;color:#374151;text-decoration:none;transition:background .1s,color .1s,border-color .1s;margin:.05rem .5rem;border-left:3px solid transparent}
.pv-nav-link i{font-size:1rem;width:20px;text-align:center;flex-shrink:0;color:#9CA3AF;transition:color .1s}
.pv-nav-link:hover{background:var(--vol-bg);color:var(--vol-color);border-left-color:var(--vol-color)}
.pv-nav-link:hover i{color:var(--vol-color)}
.pv-nav-link.pv-active{background:var(--vol-bg);color:var(--vol-color);font-weight:700;border-left-color:var(--vol-color)}
.pv-nav-link.pv-active i{color:var(--vol-color)}
.pv-nav-link .pv-badge{margin-left:auto;background:var(--vol-color);color:var(--vol-on);font-size:.65rem;font-weight:700;padding:.1rem .4rem;border-radius:10px;min-width:18px;text-align:center}
.pv-nav-divider{height:1px;background:#F3F4F6;margin:.4rem .75rem}
.pv-sidebar-bottom{margin-top:auto;border-top:1px solid #F3F4F6;padding:.5rem}

/* Shell */
.pv-shell{margin-left:var(--vol-sidebar-w);margin-top:var(--vol-topbar-h);min-height:calc(100vh - var(--vol-topbar-h));display:flex;flex-direction:column}
.pv-content{flex:1;padding:1.5rem}
.pv-footer{border-top:1px solid #E5E7EB;padding:.5rem 1.5rem;font-size:.75rem;color:#9CA3AF;background:#fff;display:flex;justify-content:space-between}

/* Live region */
.pv-live{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}

/* Responsive */
@media(max-width:768px){
  :root{--vol-sidebar-w:0px}
  .pv-sidebar{transform:translateX(-220px)}
  .pv-sidebar.open{transform:none;width:220px}
  .pv-shell{margin-left:0}
  .pv-content{padding:1rem .75rem}
  .pv-brand{width:auto;border-right:none}
}
@media(prefers-contrast:high){.pv-nav-link{border-left-width:5px}.pv-nav-link.pv-active{border-left-width:5px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
<?php /* Wspólny system stylów podstron (.pv-page-*, .pv-card, .vol-detail-*, …) */ ?>
<?php require_once __DIR__ . '/pv_styles.php'; ?>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= APP_URL ?>/sw.js')
    .catch(e => console.warn('SW:', e));
}
</script>
</head>
<body>
<a href="#pv-main" class="pv-skip">Przejdź do treści</a>
<div role="status" aria-live="polite" class="pv-live" id="pv-live"></div>

<!-- Topbar -->
<header class="pv-topbar" role="banner">
  <a href="<?= APP_URL ?>/panel/index.php" class="pv-brand" aria-label="Panel wolontariusza — strona główna">
    <div class="pv-brand-icon" aria-hidden="true"><i class="bi bi-person-circle"></i></div>
    <div>
      <div>Panel</div>
      <?php if ($_pv_org): ?><div class="pv-brand-sub" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px" title="<?= h($_pv_org) ?>"><?= h($_pv_org) ?></div><?php endif; ?>
    </div>
  </a>
  <div class="pv-topbar-bc" aria-hidden="true"><strong><?= h($_pv_title) ?></strong></div>
  <nav class="pv-topbar-user" aria-label="Akcje użytkownika">
    <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
    <button type="button"
            data-bs-toggle="modal" data-bs-target="#bugReportModal"
            title="Zgłoś błąd na tej stronie" aria-label="Zgłoś błąd"
            style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.4);
                   border-radius:6px;padding:.18rem .5rem;font-size:.78rem;
                   color:rgba(255,255,255,.9);cursor:pointer;line-height:1.5;
                   transition:all .12s;white-space:nowrap;flex-shrink:0;
                   display:inline-flex;align-items:center;gap:.3rem">
      <i class="bi bi-bug-fill" style="font-size:.85rem"></i>
      <span class="d-none d-sm-inline">Zgłoś błąd</span>
    </button>
    <?php endif; ?>
    <?php if ($_pu): ?>
    <div class="dropdown">
      <button type="button" class="pv-avatar" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Menu użytkownika <?= h($_pu_name) ?>">
        <?= h($_pu_ini) ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.88rem;min-width:200px">
        <li class="px-3 py-2 border-bottom">
          <div class="fw-bold"><?= h($_pu_name) ?></div>
          <div class="text-muted small"><?= h($_pu['email']??'') ?></div>
        </li>
        <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-gear me-2" aria-hidden="true"></i>Ustawienia konta</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Wyloguj się</a></li>
      </ul>
    </div>
    <?php endif; ?>
  </nav>
</header>
<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>

<!-- Sidebar -->
<nav class="pv-sidebar" id="pv-sidebar" aria-label="Nawigacja panelu wolontariusza">
  <div class="pv-nav-label" aria-hidden="true">Moje umowy</div>
  <a href="<?= APP_URL ?>/panel/index.php" class="pv-nav-link<?= _pv_nav_active('/panel/index') ?>" aria-label="Moja umowa — przegląd">
    <i class="bi bi-person-circle" aria-hidden="true"></i>Moja umowa
  </a>
  <?php if (module_enabled('messages_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/messages.php" class="pv-nav-link<?= _pv_nav_active('/panel/messages') ?>" aria-label="Wiadomości">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>Wiadomości
  </a>
  <?php endif; ?>

  <div class="pv-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="pv-nav-label" aria-hidden="true">Sprawy</div>

  <?php if (module_enabled('letters_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/letters.php" class="pv-nav-link<?= _pv_nav_active('/panel/letters') ?>">
    <i class="bi bi-archive" aria-hidden="true"></i>Pisma
  </a>
  <?php endif; ?>
  <a href="<?= APP_URL ?>/panel/apply.php" class="pv-nav-link<?= _pv_nav_active('/panel/apply') ?>">
    <i class="bi bi-send" aria-hidden="true"></i>Wyślij wniosek
  </a>
  <?php if (module_enabled('dyspozycyjnosc_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/dyspozycyjnosc.php" class="pv-nav-link<?= _pv_nav_active('/panel/dyspozycyjnosc') ?>"
     aria-label="Moja dyspozycyjność i urlopy">
    <i class="bi bi-calendar-heart" aria-hidden="true"></i>Dyspozycyjność i urlopy
  </a>
  <?php endif; ?>
  <?php if (module_enabled('certificates_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/certificates.php" class="pv-nav-link<?= _pv_nav_active('/panel/certificates') ?>">
    <i class="bi bi-award" aria-hidden="true"></i>Zaświadczenia
  </a>
  <?php endif; ?>
  <?php if (module_enabled('terminations_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/terminations.php" class="pv-nav-link<?= _pv_nav_active('/panel/terminations') ?>">
    <i class="bi bi-file-earmark-x" aria-hidden="true"></i>Rozwiązanie umowy
  </a>
  <?php endif; ?>
  <?php if (module_enabled('timesheets_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/timesheets.php" class="pv-nav-link<?= _pv_nav_active('/panel/timesheets') ?>">
    <i class="bi bi-clock-history" aria-hidden="true"></i>Ewidencja godzin
  </a>
  <?php endif; ?>
  <?php if (module_enabled('moodle_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/moodle.php" class="pv-nav-link<?= _pv_nav_active('/panel/moodle') ?>">
    <i class="bi bi-mortarboard" aria-hidden="true"></i>Kursy Moodle
  </a>
  <?php endif; ?>
  <?php if (module_enabled('tidycal_enabled') && trim(org_setting('tidycal_api_key')) !== ''): ?>
  <a href="<?= APP_URL ?>/panel/szkolenie.php" class="pv-nav-link<?= _pv_nav_active('/panel/szkolenie') ?>">
    <i class="bi bi-calendar2-check" aria-hidden="true"></i>Umów się na szkolenie
  </a>
  <?php endif; ?>

  <?php
  // Pokaż link do RODO jeśli użytkownik ma aktywne upoważnienie
  $_has_rodo = false;
  try {
      $_pv_email = $_pu['email'] ?? '';
      if ($_pv_email) {
          $_wol_ids = db_all("SELECT id FROM umowy_wolontariat WHERE email=? OR m365_login=? LIMIT 5", [$_pv_email, $_pv_email]);
          if ($_wol_ids) {
              $_wol_ph = implode(',', array_fill(0, count($_wol_ids), '?'));
              $_rodo_check = db_one(
                  "SELECT id FROM rodo_authorizations WHERE contract_type='wolontariat' AND contract_id IN ({$_wol_ph}) LIMIT 1",
                  array_column($_wol_ids, 'id')
              );
              $_has_rodo = (bool)$_rodo_check;
          }
      }
  } catch (\Throwable $e) {}
  ?>
  <?php if ($_has_rodo): ?>
  <a href="<?= APP_URL ?>/panel/rodo.php" class="pv-nav-link<?= _pv_nav_active('/panel/rodo') ?>">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>Upoważnienie RODO
  </a>
  <?php endif; ?>

  <div class="pv-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="pv-nav-label" aria-hidden="true">Wsparcie</div>

  <?php
  // Liczba otwartych zgłoszeń helpdesk (dla odznaki)
  $_hd_open = 0;
  try {
      $_hd_open = (int)(db_one(
          "SELECT COUNT(*) AS c FROM helpdesk_tickets
           WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')",
          [(int)($_pu['id'] ?? 0)]
      )['c'] ?? 0);
  } catch (\Throwable $e) {}
  ?>
  <a href="<?= APP_URL ?>/panel/helpdesk.php"
     class="pv-nav-link<?= _pv_nav_active('/panel/helpdesk') ?>"
     aria-label="Helpdesk IT<?= $_hd_open ? " — {$_hd_open} otwartych" : '' ?>">
    <i class="bi bi-headset" aria-hidden="true"></i>Helpdesk IT
    <?php if ($_hd_open): ?>
    <span class="pv-badge" aria-label="<?= $_hd_open ?> otwartych zgłoszeń"><?= $_hd_open ?></span>
    <?php endif; ?>
  </a>

  <div class="pv-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="pv-nav-label" aria-hidden="true">Konto</div>

  <a href="<?= APP_URL ?>/panel/m365.php" class="pv-nav-link<?= _pv_nav_active('/panel/m365') ?>">
    <i class="bi bi-microsoft" aria-hidden="true"></i>Microsoft 365
  </a>
  <a href="<?= APP_URL ?>/panel/sessions.php" class="pv-nav-link<?= _pv_nav_active('/panel/sessions') ?>">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>Sesje
  </a>
  <a href="<?= APP_URL ?>/panel/password.php" class="pv-nav-link<?= _pv_nav_active('/panel/password') ?>">
    <i class="bi bi-gear" aria-hidden="true"></i>Ustawienia konta
  </a>
  <a href="<?= APP_URL ?>/panel/panel_color.php" class="pv-nav-link<?= _pv_nav_active('/panel/panel_color') ?>">
    <i class="bi bi-palette2" aria-hidden="true"></i>Kolor panelu
  </a>

  <div class="pv-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="pv-nav-label" aria-hidden="true">Organizacja</div>
  <?php if (module_enabled('org_calendar_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/calendar.php" class="pv-nav-link<?= _pv_nav_active('/panel/calendar') ?>">
    <i class="bi bi-calendar3" aria-hidden="true"></i>Kalendarz organizacji
  </a>
  <?php endif; ?>
  <a href="<?= APP_URL ?>/org_intro/index.php" class="pv-nav-link<?= _pv_nav_active('/org_intro/index') ?>"
     aria-label="Zasady i wprowadzenie do organizacji">
    <i class="bi bi-building-heart" aria-hidden="true"></i>Zasady organizacji
  </a>
  <a href="<?= APP_URL ?>/org_intro/panel_guide.php" class="pv-nav-link<?= _pv_nav_active('/org_intro/panel_guide') ?>"
     aria-label="Przewodnik po panelu — jak korzystać z systemu">
    <i class="bi bi-book" aria-hidden="true"></i>Przewodnik po panelu
  </a>

  <div class="pv-sidebar-bottom">
    <a href="<?= APP_URL ?>/auth/logout.php" class="pv-nav-link text-danger">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i>Wyloguj się
    </a>
  </div>
</nav>

<!-- Shell -->
<div class="pv-shell">
<main class="pv-content" id="pv-main" role="main" tabindex="-1">

<?php if (!empty($_SESSION['_admin_original'])): ?>
<?php $_imp_name = $_SESSION['user']['name'] ?? 'użytkownik'; ?>
<a href="<?= APP_URL ?>/admin/impersonate_stop.php"
   style="display:flex;align-items:center;gap:1rem;
          background:linear-gradient(135deg,#b91c1c,#dc2626);
          border-radius:14px;padding:1.1rem 1.4rem;margin-bottom:1.25rem;
          text-decoration:none;color:#fff;
          box-shadow:0 4px 20px rgba(185,28,28,.45);
          animation:_impPulse 2.5s ease-in-out infinite;
          border:2px solid rgba(255,255,255,.25)"
   role="alert" aria-label="Tryb podglądu — kliknij aby wrócić do swojego konta">
  <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem">
    👁
  </div>
  <div style="flex:1;min-width:0">
    <div style="font-size:.72rem;font-weight:700;opacity:.8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.1rem">
      Tryb podglądu — przeglądasz jako:
    </div>
    <div style="font-size:1.05rem;font-weight:800">
      <?= h($_imp_name) ?>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:.5rem;background:rgba(0,0,0,.3);border-radius:10px;padding:.65rem 1.1rem;font-weight:700;font-size:.95rem;flex-shrink:0;white-space:nowrap;border:1.5px solid rgba(255,255,255,.3)">
    <i class="bi bi-arrow-left-circle-fill" style="font-size:1.1rem"></i>
    Powrót do admina
  </div>
</a>
<style>
@keyframes _impPulse {
  0%,100% { box-shadow: 0 4px 20px rgba(185,28,28,.45); }
  50%      { box-shadow: 0 4px 32px rgba(185,28,28,.75); }
}
</style>
<?php endif; ?>

<?php
$_flash = flash_get();
if ($_flash):
  $ft = $_flash['type'] ?? 'info';
  $fi = ['success'=>'bi-check-circle-fill','danger'=>'bi-exclamation-triangle-fill','warning'=>'bi-exclamation-circle','info'=>'bi-info-circle-fill'];
?>
<div class="alert alert-<?= h($ft) ?> d-flex align-items-center gap-2 mb-3 alert-dismissible fade show" role="alert" aria-live="polite">
  <i class="bi <?= h($fi[$ft]??'bi-info-circle-fill') ?>" aria-hidden="true"></i>
  <span><?= h($_flash['msg']) ?></span>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<?php
// ── Komunikaty admina (baner / popup) ────────────────────────────────────────
require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';
notif_migrate();
$_pv_msgs    = ann_panel_messages((int)($_pu['id'] ?? 0), $_pu['role'] ?? 'viewer');
$_pv_banners = array_values(array_filter($_pv_msgs, fn($a) => ($a['display_mode'] ?? '') === 'banner'));
$_pv_popups  = array_values(array_filter($_pv_msgs, fn($a) => ($a['display_mode'] ?? '') === 'popup'));
?>
<?php foreach ($_pv_banners as $_b):
  $_bid = (int)$_b['id'];
  $_pinned = (int)($_b['is_pinned'] ?? 0);
?>
<div class="pv-ann-banner" data-ann-id="<?= $_bid ?>" role="region" aria-label="Komunikat: <?= h($_b['title']) ?>"
     style="border-left:4px solid <?= $_pinned ? '#F59E0B' : 'var(--vol-color)' ?>;background:<?= $_pinned ? '#FFFBEB' : 'var(--vol-bg)' ?>;border-radius:12px;padding:1rem 1.15rem;margin-bottom:1.25rem;display:flex;gap:.9rem;align-items:flex-start">
  <i class="bi bi-megaphone-fill" aria-hidden="true" style="font-size:1.25rem;color:<?= $_pinned ? '#F59E0B' : 'var(--vol-color)' ?>;flex-shrink:0;margin-top:.1rem"></i>
  <div style="flex:1;min-width:0">
    <div class="fw-bold mb-1" style="font-size:.98rem;color:#1f2937"><?= h($_b['title']) ?></div>
    <?php if (($_b['body'] ?? '') !== ''): ?>
    <div style="font-size:.88rem;color:#374151;line-height:1.6;white-space:pre-wrap;word-break:break-word"><?= h($_b['body']) ?></div>
    <?php endif; ?>
    <div class="text-muted mt-2" style="font-size:.73rem"><i class="bi bi-person me-1"></i><?= h($_b['author_name'] ?? '') ?></div>
  </div>
  <button type="button" class="btn btn-sm pv-ann-read" data-ann-id="<?= $_bid ?>"
          style="background:var(--vol-color);color:#fff;font-size:.78rem;white-space:nowrap;flex-shrink:0">
    <i class="bi bi-check2 me-1" aria-hidden="true"></i>Przeczytane
  </button>
</div>
<?php endforeach; ?>

<?php if (!empty($_pv_popups)): ?>
<div class="modal fade" id="pvAnnPopup" tabindex="-1" aria-labelledby="pvAnnPopupLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden">
      <div class="modal-header" style="background:var(--vol-color);color:#fff;border:none">
        <h5 class="modal-title d-flex align-items-center gap-2" id="pvAnnPopupLabel">
          <i class="bi bi-megaphone-fill" aria-hidden="true"></i>
          <?= count($_pv_popups) > 1 ? 'Komunikaty' : 'Komunikat' ?>
        </h5>
      </div>
      <div class="modal-body" style="padding:1.25rem">
        <?php foreach ($_pv_popups as $_i => $_p):
          $_pid = (int)$_p['id'];
        ?>
        <div class="pv-popup-item<?= $_i > 0 ? ' pt-3 mt-3 border-top' : '' ?>" data-ann-id="<?= $_pid ?>">
          <div class="fw-bold mb-2" style="font-size:1.02rem;color:#1f2937"><?= h($_p['title']) ?></div>
          <?php if (($_p['body'] ?? '') !== ''): ?>
          <div style="font-size:.9rem;color:#374151;line-height:1.65;white-space:pre-wrap;word-break:break-word"><?= h($_p['body']) ?></div>
          <?php endif; ?>
          <div class="text-muted mt-2" style="font-size:.73rem"><i class="bi bi-person me-1"></i><?= h($_p['author_name'] ?? '') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer" style="border:none">
        <button type="button" class="btn" id="pvAnnPopupAck"
                style="background:var(--vol-color);color:#fff">
          <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Rozumiem
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($_pv_banners) || !empty($_pv_popups)): ?>
<script>
(function () {
  var APP_URL = '<?= APP_URL ?>';
  function markRead(id) {
    return fetch(APP_URL + '/api/announcements/read.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify({id: id})
    });
  }

  // Banery — przycisk „Przeczytane"
  document.querySelectorAll('.pv-ann-read').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.dataset.annId, 10);
      btn.disabled = true;
      markRead(id).finally(function () {
        var card = document.querySelector('.pv-ann-banner[data-ann-id="' + id + '"]');
        if (card) card.remove();
        var live = document.getElementById('pv-live');
        if (live) live.textContent = 'Komunikat oznaczono jako przeczytany.';
      });
    });
  });

  // Popup — pokaż przy wejściu, potwierdzenie oznacza wszystkie jako przeczytane
  var popupEl = document.getElementById('pvAnnPopup');
  if (popupEl && window.bootstrap) {
    var modal = new bootstrap.Modal(popupEl);
    modal.show();
    var ack = document.getElementById('pvAnnPopupAck');
    if (ack) {
      ack.addEventListener('click', function () {
        ack.disabled = true;
        var ids = Array.prototype.map.call(
          popupEl.querySelectorAll('.pv-popup-item'),
          function (el) { return parseInt(el.dataset.annId, 10); }
        );
        Promise.allSettled(ids.map(markRead)).finally(function () { modal.hide(); });
      });
    }
  }
})();
</script>
<?php endif; ?>
