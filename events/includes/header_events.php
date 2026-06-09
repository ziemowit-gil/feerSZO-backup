<?php
/**
 * events/includes/header_events.php — Standalone layout modułu Wydarzeń
 * Wymaga: $PAGE_TITLE, opcjonalnie $EV_ID (int), $EV_BREADCRUMB (string)
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/events.php';
require_once dirname(dirname(__DIR__)) . '/includes/permissions.php';

require_login();

// Sprawdź uprawnienie do modułu (admin zawsze ma dostęp)
if (!is_admin()) {
    _permissions_init();
    if (!can_read('wydarzenia')) {
        flash_set('error', 'Brak uprawnień do modułu Wydarzeń.');
        header('Location: ' . APP_URL . '/index.php'); exit;
    }
}

$_eu        = current_user();
$_ev_title  = $PAGE_TITLE ?? 'Wydarzenia';
$_ev_bc     = $EV_BREADCRUMB ?? ($PAGE_TITLE ?? '');
$_uri       = $_SERVER['REQUEST_URI'] ?? '';
$_ev_id     = $EV_ID ?? 0;
$_org_name  = defined('ORG_NAME') ? ORG_NAME : '';
$_is_admin  = is_admin();

// Inicjały usera
$_eu_name = trim(($_eu['first_name'] ?? '') . ' ' . ($_eu['last_name'] ?? ''));
if (!$_eu_name) $_eu_name = $_eu['name'] ?? 'Użytkownik';
$_eu_parts = preg_split('/\s+/', trim($_eu_name));
$_eu_inits = '';
foreach ($_eu_parts as $_w) $_eu_inits .= mb_strtoupper(mb_substr($_w,0,1,'UTF-8'),'UTF-8');
$_eu_inits = mb_substr($_eu_inits,0,2,'UTF-8') ?: '?';

// KPI liczniki
$_ev_total    = 0;
$_ev_upcoming = 0;
try {
    $uid_nav = (int)$_eu['id'];
    $u_nav   = db_one("SELECT role FROM users WHERE id=?", [$uid_nav]);
    if (($u_nav['role']??'') === 'admin') {
        $_ev_total    = (int)(db_one("SELECT COUNT(*) AS n FROM ev_events")['n']??0);
        $_ev_upcoming = (int)(db_one("SELECT COUNT(*) AS n FROM ev_events WHERE status='published' AND start_at >= datetime('now','localtime')")['n']??0);
    } else {
        $_ev_total    = (int)(db_one("SELECT COUNT(*) AS n FROM ev_roles WHERE user_id=?",[$uid_nav])['n']??0);
        $_ev_upcoming = (int)(db_one("SELECT COUNT(*) AS n FROM ev_roles er JOIN ev_events e ON e.id=er.event_id WHERE er.user_id=? AND e.status='published' AND e.start_at >= datetime('now','localtime')",[$uid_nav])['n']??0);
    }
} catch (\Throwable $e) {}

function _ev_active(string $path): bool {
    global $_uri;
    return str_contains($_uri, $path);
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($_ev_title) ?> — Wydarzenia<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
  --ev-purple:      #7c3aed;
  --ev-purple-dark: #4c1d95;
  --ev-purple-mid:  #8b5cf6;
  --ev-purple-bg:   #f5f3ff;
  --ev-focus:       #facc15;
  --ev-text:        #0f172a;
  --ev-muted:       #64748b;
  --ev-border:      #e2e8f0;
  --ev-bg:          #f8fafc;
  --ev-sidebar-w:   240px;
  --ev-topbar-h:    52px;
}
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body { margin:0; background:var(--ev-bg); color:var(--ev-text);
  font-family:system-ui,-apple-system,'Segoe UI',sans-serif; font-size:.9375rem; line-height:1.6; }

.skip-link { position:absolute;top:-100%;left:1rem;z-index:9999;
  background:var(--ev-purple);color:#fff;padding:.65rem 1.4rem;
  border-radius:0 0 8px 8px;font-size:.95rem;font-weight:700;
  text-decoration:none;border:3px solid var(--ev-focus); }
.skip-link:focus { top:0; }
*:focus-visible { outline:3px solid var(--ev-focus)!important;outline-offset:2px!important;border-radius:3px; }
*:focus:not(:focus-visible) { outline:none; }

/* Topbar */
.ev-topbar { height:var(--ev-topbar-h);background:var(--ev-purple-dark);color:#fff;
  display:flex;align-items:center;padding:0 1.25rem 0 0;
  position:fixed;top:0;left:0;right:0;z-index:1040;
  box-shadow:0 2px 8px rgba(0,0,0,.28); }
.ev-brand { width:var(--ev-sidebar-w);display:flex;align-items:center;gap:.6rem;
  padding:0 1rem;flex-shrink:0;text-decoration:none;color:#fff;
  font-weight:800;font-size:.97rem;height:100%;
  border-right:1px solid rgba(255,255,255,.2);transition:background .15s; }
.ev-brand:hover { background:rgba(255,255,255,.08);color:#fff; }
.ev-brand-icon { width:30px;height:30px;background:rgba(255,255,255,.18);border-radius:7px;
  display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0; }
.ev-topbar-bc { flex:1;padding:0 1.25rem;font-size:.84rem;
  color:rgba(255,255,255,.8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.ev-topbar-bc strong { color:#fff; }
.ev-topbar-actions { display:flex;align-items:center;gap:.5rem;flex-shrink:0; }
.ev-sys-link { display:inline-flex;align-items:center;gap:.35rem;color:rgba(255,255,255,.8);
  text-decoration:none;font-size:.78rem;padding:.28rem .6rem;
  border:1px solid rgba(255,255,255,.3);border-radius:5px;white-space:nowrap;transition:background .12s; }
.ev-sys-link:hover { background:rgba(255,255,255,.15);color:#fff; }
.ev-user-btn { display:flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.13);
  border:1.5px solid rgba(255,255,255,.35);border-radius:6px;color:#fff;
  padding:.28rem .7rem;font-size:.82rem;font-weight:500; }
.ev-user-av { width:26px;height:26px;border-radius:50%;background:var(--ev-purple-mid);
  display:flex;align-items:center;justify-content:center;font-size:.67rem;font-weight:700;flex-shrink:0; }
.ev-menu-toggle { display:none;background:none;border:none;color:#fff;
  font-size:1.3rem;padding:.3rem .5rem;cursor:pointer;margin-right:.5rem; }

/* Sidebar */
.ev-sidebar { position:fixed;top:var(--ev-topbar-h);left:0;bottom:0;
  width:var(--ev-sidebar-w);background:#fff;border-right:1px solid var(--ev-border);
  display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;z-index:1030;transition:left .2s; }
.ev-nav-label { font-size:.68rem;font-weight:700;letter-spacing:.09em;
  text-transform:uppercase;color:#94a3b8;padding:1rem 1rem .3rem;display:block; }
.ev-nav-link { display:flex;align-items:center;gap:.6rem;padding:.52rem 1rem;
  color:#334155;text-decoration:none;font-size:.88rem;font-weight:500;
  border-left:3px solid transparent;transition:background .1s,color .1s;position:relative; }
.ev-nav-link i { font-size:.95rem;flex-shrink:0;width:18px;text-align:center; }
.ev-nav-link:hover { background:var(--ev-purple-bg);color:var(--ev-purple); }
.ev-nav-link.active { background:var(--ev-purple-bg);color:var(--ev-purple);
  border-left-color:var(--ev-purple);font-weight:700; }
.ev-nav-badge { margin-left:auto;font-size:.64rem;font-weight:700;
  background:#ede9fe;color:var(--ev-purple);border-radius:2rem;padding:.05rem .45rem;flex-shrink:0; }
.ev-nav-sep { height:1px;background:var(--ev-border);margin:.5rem .75rem; }
.ev-sidebar-footer { margin-top:auto;padding:.75rem 1rem;border-top:1px solid var(--ev-border);
  font-size:.77rem;color:#94a3b8; }

/* Main */
.ev-main { margin-top:var(--ev-topbar-h);margin-left:var(--ev-sidebar-w);
  min-height:calc(100vh - var(--ev-topbar-h));padding:1.5rem 1.75rem 2.5rem; }

/* Flash */
.ev-flash { margin-bottom:1rem; }

@media(max-width:768px) {
  .ev-menu-toggle { display:block; }
  .ev-sidebar { left:calc(-1 * var(--ev-sidebar-w)); }
  .ev-sidebar.open { left:0;box-shadow:4px 0 20px rgba(0,0,0,.15); }
  .ev-main { margin-left:0;padding:1rem; }
  .ev-topbar-bc { display:none; }
}
</style>
</head>
<body>

<a class="skip-link" href="#ev-main">Przejdź do treści</a>

<!-- Topbar -->
<header class="ev-topbar" role="banner">
  <button class="ev-menu-toggle" aria-label="Otwórz menu" aria-expanded="false"
          aria-controls="ev-sidebar" onclick="evToggleSidebar(this)">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <a class="ev-brand" href="<?= APP_URL ?>/events/dashboard.php">
    <span class="ev-brand-icon" aria-hidden="true"><i class="bi bi-calendar-event-fill"></i></span>
    <span>Wydarzenia</span>
  </a>
  <div class="ev-topbar-bc" aria-label="Nawigacja">
    Wydarzenia<?= $_ev_bc ? ' / <strong>' . h($_ev_bc) . '</strong>' : '' ?>
  </div>
  <div class="ev-topbar-actions">
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
    <a class="ev-sys-link" href="<?= APP_URL ?>/index.php" aria-label="Wróć do systemu">
      <i class="bi bi-arrow-left" aria-hidden="true"></i><span class="d-none d-sm-inline">System</span>
    </a>
    <span class="ev-user-btn" aria-label="Zalogowany: <?= h($_eu_name) ?>">
      <span class="ev-user-av" aria-hidden="true"><?= h($_eu_inits) ?></span>
      <span class="d-none d-sm-inline"><?= h($_eu_name) ?></span>
    </span>
  </div>
</header>
<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>

<!-- Sidebar -->
<nav id="ev-sidebar" class="ev-sidebar" aria-label="Nawigacja modułu Wydarzeń">

  <span class="ev-nav-label">Przegląd</span>

  <a class="ev-nav-link <?= _ev_active('/events/dashboard') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/events/dashboard.php">
    <i class="bi bi-speedometer2" aria-hidden="true"></i>Dashboard
  </a>

  <a class="ev-nav-link <?= _ev_active('/events/index') || (str_contains($_uri,'/events/') && !str_contains($_uri,'dashboard') && !str_contains($_uri,'settings') && !str_contains($_uri,'checkin') && !str_contains($_uri,'api')) ? 'active' : '' ?>"
     href="<?= APP_URL ?>/events/index.php">
    <i class="bi bi-calendar3" aria-hidden="true"></i>
    Wszystkie wydarzenia
    <?php if ($_ev_upcoming > 0): ?>
    <span class="ev-nav-badge" aria-label="<?= $_ev_upcoming ?> nadchodzących"><?= $_ev_upcoming ?></span>
    <?php endif; ?>
  </a>

  <?php if ($_ev_id && in_array(ev_role($_ev_id), ['admin','volunteer','checkin'], true)):
    $ev_nav = db_one("SELECT title FROM ev_events WHERE id=?", [$_ev_id]);
  ?>
  <div class="ev-nav-sep" role="separator"></div>
  <span class="ev-nav-label">Bieżące wydarzenie</span>
  <div style="padding:.35rem 1rem;font-size:.78rem;font-weight:600;color:var(--ev-purple);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
    <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
    <?= h(mb_substr($ev_nav['title']??'',0,28)) ?>
  </div>
  <a class="ev-nav-link <?= _ev_active('/events/manage') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/events/manage.php?id=<?= $_ev_id ?>">
    <i class="bi bi-people" aria-hidden="true"></i>Uczestnicy
  </a>
  <a class="ev-nav-link <?= _ev_active('/events/checkin') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/events/checkin.php?id=<?= $_ev_id ?>">
    <i class="bi bi-qr-code-scan" aria-hidden="true"></i>Check-in
  </a>
  <?php endif; ?>

  <?php if ($_is_admin): ?>
  <div class="ev-nav-sep" role="separator"></div>
  <span class="ev-nav-label">Ustawienia</span>
  <a class="ev-nav-link <?= _ev_active('/events/settings/') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/events/settings/roles.php<?= $_ev_id ? '?id='.$_ev_id : '' ?>">
    <i class="bi bi-people-fill" aria-hidden="true"></i>Role i dostęp
  </a>
  <?php endif; ?>

  <div class="ev-sidebar-footer" aria-hidden="true">Moduł Wydarzeń</div>
</nav>

<!-- Main -->
<main id="ev-main" class="ev-main" tabindex="-1">
<?php
$_fm = flash_get();
if ($_fm): ?>
<div class="ev-flash" role="status" aria-live="polite">
  <div class="alert alert-<?= $_fm['type']==='error'?'danger':h($_fm['type']) ?> alert-dismissible">
    <?= h($_fm['msg']??$_fm['message']??'') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
</div>
<?php endif; ?>
<script>
function evToggleSidebar(btn){
  const sb=document.getElementById('ev-sidebar'),open=sb.classList.toggle('open');
  btn.setAttribute('aria-expanded',open?'true':'false');
}
document.addEventListener('click',function(e){
  const sb=document.getElementById('ev-sidebar'),btn=document.querySelector('.ev-menu-toggle');
  if(sb&&btn&&!sb.contains(e.target)&&!btn.contains(e.target)){
    sb.classList.remove('open');btn.setAttribute('aria-expanded','false');
  }
});
</script>
