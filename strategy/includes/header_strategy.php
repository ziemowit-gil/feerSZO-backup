<?php
/**
 * strategy/includes/header_strategy.php — Layout modułu Strategii Rozwoju NGO.
 * Wymaga: $PAGE_TITLE przed include.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/strategy.php';

require_login();
if (!can_read('umowy') && !is_admin()) {
    flash_set('error', 'Brak dostępu do modułu Strategii.');
    header('Location: ' . APP_URL . '/portal.php'); exit;
}

$_su         = current_user();
$_strat_title = $PAGE_TITLE ?? 'Strategia Rozwoju NGO';
$_uri        = $_SERVER['REQUEST_URI'] ?? '';
$_org_name   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_can_edit   = can_edit() || is_admin();

function _strat_active(string $path): bool {
    global $_uri;
    return str_contains($_uri, $path);
}

// Inicjały
$_su_initials = '?';
$_su_name     = '';
if ($_su) {
    $_n = $_su['name'] ?? '';
    $_su_name = $_n ?: ($_su['email'] ?? '');
    $_parts   = preg_split('/\s+/', trim($_n));
    $_su_initials = '';
    foreach ($_parts as $w) $_su_initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    $_su_initials = mb_substr($_su_initials, 0, 2, 'UTF-8') ?: '?';
}

// Liczniki dla sidebara
$_strat_at_risk = 0;
try {
    $all_objs = db_all("SELECT * FROM v_strategy_dashboard WHERE status='aktywny'");
    foreach ($all_objs as $_o) {
        if (strategy_health_score($_o) === 'red') $_strat_at_risk++;
    }
} catch (\Throwable $e) {}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($_strat_title) ?> — Strategia NGO<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
:root {
  --strat-accent:      #7c3aed;
  --strat-accent-dark: #4c1d95;
  --strat-accent-mid:  #a78bfa;
  --strat-accent-bg:   #f5f3ff;
  --strat-accent-light:#ede9fe;
  --strat-focus:       #fbbf24;
  --strat-text:        #0f172a;
  --strat-text-sub:    #334155;
  --strat-border:      #64748b;
  --strat-bg:          #f8fafc;
  --strat-sidebar-w:   252px;
  --strat-topbar-h:    52px;
  --health-green:      #16a34a;
  --health-yellow:     #d97706;
  --health-red:        #dc2626;
}
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
  margin: 0; background: var(--strat-bg);
  color: var(--strat-text);
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: 1rem; line-height: 1.6;
}

/* Skip link */
.skip-link {
  position: absolute; top: -100%; left: 1rem; z-index: 9999;
  background: var(--strat-accent); color: #fff;
  padding: .75rem 1.5rem; border-radius: 0 0 8px 8px;
  font-size: 1rem; font-weight: 700; text-decoration: none;
  border: 3px solid var(--strat-focus);
}
.skip-link:focus { top: 0; }

/* Focus ring */
*:focus-visible {
  outline: 3px solid var(--strat-focus) !important;
  outline-offset: 3px !important; border-radius: 3px;
}
*:focus:not(:focus-visible) { outline: none; }

/* Topbar */
.strat-topbar {
  height: var(--strat-topbar-h);
  background: var(--strat-accent);
  color: #fff;
  display: flex; align-items: center;
  padding: 0 1.25rem 0 0;
  position: fixed; top: 0; left: 0; right: 0;
  z-index: 1040;
  box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.strat-brand {
  width: var(--strat-sidebar-w);
  display: flex; align-items: center; gap: .6rem;
  padding: 0 1rem; flex-shrink: 0;
  text-decoration: none; color: #fff;
  font-weight: 800; font-size: .95rem; height: 100%;
  border-right: 1px solid rgba(255,255,255,.25);
  transition: background .15s;
}
.strat-brand:hover { background: rgba(255,255,255,.1); color: #fff; }
.strat-brand-icon {
  width: 30px; height: 30px;
  background: rgba(255,255,255,.2); border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.strat-brand-sub { font-size: .65rem; opacity: .75; font-weight: 400; line-height: 1; }
.strat-topbar-title {
  flex: 1; padding: 0 1.25rem; font-size: .88rem;
  color: rgba(255,255,255,.9); white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
}
.strat-topbar-title strong { color: #fff; }
.strat-topbar-nav {
  display: flex; align-items: center; gap: .5rem;
  padding-left: .75rem; flex-shrink: 0;
}
.strat-sys-link {
  display: inline-flex; align-items: center; gap: .4rem;
  color: rgba(255,255,255,.8); text-decoration: none;
  font-size: .79rem; padding: .28rem .6rem;
  border: 1px solid rgba(255,255,255,.35); border-radius: 5px;
  white-space: nowrap; transition: background .12s;
}
.strat-sys-link:hover { background: rgba(255,255,255,.15); color: #fff; }
.strat-user-btn {
  display: flex; align-items: center; gap: .45rem;
  background: rgba(255,255,255,.15); border: 2px solid rgba(255,255,255,.4);
  border-radius: 6px; color: #fff; padding: .25rem .7rem;
  font-size: .82rem; font-weight: 500; cursor: pointer;
  text-decoration: none; transition: background .15s;
}
.strat-user-btn:hover { background: rgba(255,255,255,.25); color: #fff; }
.strat-user-avatar {
  width: 26px; height: 26px; border-radius: 50%;
  background: rgba(255,255,255,.3);
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem; font-weight: 700; flex-shrink: 0;
}

/* Sidebar */
.strat-sidebar {
  position: fixed; top: var(--strat-topbar-h); left: 0; bottom: 0;
  width: var(--strat-sidebar-w);
  background: #fff; border-right: 2px solid #E5E7EB;
  display: flex; flex-direction: column;
  overflow-y: auto; overflow-x: hidden; z-index: 1030;
}
.strat-sidebar::-webkit-scrollbar { width: 6px; }
.strat-sidebar::-webkit-scrollbar-thumb { background: #D1D5DB; border-radius: 3px; }
.strat-nav-label {
  font-size: .68rem; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: #64748b;
  padding: .9rem 1rem .3rem; user-select: none;
}
.strat-nav-link {
  display: flex; align-items: center; gap: .65rem;
  padding: .6rem .85rem;
  color: var(--strat-text-sub); text-decoration: none;
  font-size: .86rem; font-weight: 500;
  border-left: 3px solid transparent;
  border-radius: 0 8px 8px 0;
  margin: 0 .4rem .05rem;
  transition: background .1s, border-color .1s, color .1s;
  min-height: 42px;
}
.strat-nav-link i {
  font-size: .95rem; width: 18px; text-align: center;
  flex-shrink: 0; color: #64748b; transition: color .1s;
}
.strat-nav-link:hover {
  background: var(--strat-accent-bg); color: var(--strat-accent);
  border-left-color: var(--strat-accent-mid);
}
.strat-nav-link:hover i { color: var(--strat-accent); }
.strat-nav-link[aria-current="page"] {
  background: var(--strat-accent-bg); color: var(--strat-accent);
  border-left-color: var(--strat-accent); font-weight: 700;
}
.strat-nav-link[aria-current="page"] i { color: var(--strat-accent); }
.strat-nav-link[aria-current="page"]::after {
  content: ''; display: block;
  width: 5px; height: 5px; border-radius: 50%;
  background: var(--strat-accent); margin-left: auto; flex-shrink: 0;
}
.strat-nav-divider {
  height: 1px; background: #e2e8f0; margin: .4rem .8rem;
}
.strat-sidebar-bottom {
  margin-top: auto; border-top: 2px solid #F3F4F6; padding: .5rem;
}

/* Main */
.strat-shell {
  margin-left: var(--strat-sidebar-w);
  margin-top: var(--strat-topbar-h);
  min-height: calc(100vh - var(--strat-topbar-h));
  display: flex; flex-direction: column;
}
.strat-content {
  flex: 1; padding: 1.75rem 2rem;
  max-width: 1300px; width: 100%;
}
.strat-footer {
  border-top: 2px solid #E5E7EB;
  padding: .65rem 2rem; font-size: .78rem; color: #6B7280;
  background: #fff;
  display: flex; justify-content: space-between; align-items: center;
}

/* Alert */
.strat-alert {
  display: flex; align-items: flex-start; gap: .7rem;
  padding: .9rem 1.1rem; border-radius: 8px; border: 2px solid;
  margin-bottom: 1.25rem; font-size: .93rem;
}
.strat-alert-success { background: #F0FDF4; border-color: #16A34A; color: #14532D; }
.strat-alert-danger   { background: #FEF2F2; border-color: #DC2626; color: #7F1D1D; }
.strat-alert-warning  { background: #FFFBEB; border-color: #D97706; color: #78350F; }
.strat-alert-info     { background: #F5F3FF; border-color: #7C3AED; color: #4C1D95; }
.strat-alert-close {
  margin-left: auto; background: none; border: none;
  font-size: 1.2rem; cursor: pointer; color: inherit;
  opacity: .7; padding: 0 .2rem; border-radius: 4px; flex-shrink: 0;
}
.strat-alert-close:hover { opacity: 1; }

/* Health badges */
.health-green  { display:inline-flex;align-items:center;gap:.35rem;padding:.2em .6em;border-radius:4px;font-size:.8rem;font-weight:600;background:#dcfce7;color:#15803d;border:1px solid #86efac; }
.health-yellow { display:inline-flex;align-items:center;gap:.35rem;padding:.2em .6em;border-radius:4px;font-size:.8rem;font-weight:600;background:#fef3c7;color:#92400e;border:1px solid #fcd34d; }
.health-red    { display:inline-flex;align-items:center;gap:.35rem;padding:.2em .6em;border-radius:4px;font-size:.8rem;font-weight:600;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5; }

/* Responsive */
@media (max-width: 768px) {
  :root { --strat-sidebar-w: 0px; }
  .strat-sidebar { transform: translateX(-260px); transition: transform .25s; }
  .strat-sidebar.open { transform: translateX(0); width: 260px; }
  .strat-shell { margin-left: 0; }
  .strat-content { padding: 1rem; }
  .strat-brand { width: auto; border-right: none; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; }
}
</style>
</head>
<body>

<a href="#strat-main" class="skip-link">Przejdź do treści</a>

<div aria-live="polite" aria-atomic="true" class="visually-hidden" id="strat-live" role="status"></div>

<!-- Topbar -->
<header class="strat-topbar" role="banner">
  <a href="<?= APP_URL ?>/strategy/index.php" class="strat-brand"
     aria-label="Strategia Rozwoju NGO — strona główna modułu">
    <div class="strat-brand-icon" aria-hidden="true">
      <i class="bi bi-bullseye"></i>
    </div>
    <div>
      <div>Strategia NGO</div>
      <div class="strat-brand-sub"><?= $_org_name ? h(mb_substr($_org_name, 0, 22, 'UTF-8')) : 'Rozwój organizacji' ?></div>
    </div>
  </a>

  <div class="strat-topbar-title" aria-hidden="true">
    <strong><?= h($_strat_title) ?></strong>
  </div>

  <nav class="strat-topbar-nav" aria-label="Działania">
    <a href="<?= APP_URL ?>/portal.php" class="strat-sys-link">
      <i class="bi bi-house" aria-hidden="true"></i>
      <span class="d-none d-md-inline">System</span>
    </a>
    <?php if ($_su): ?>
    <div class="dropdown">
      <button class="strat-user-btn dropdown-toggle"
              data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
              aria-label="Menu użytkownika: <?= h($_su_name) ?>">
        <span class="strat-user-avatar" aria-hidden="true"><?= h($_su_initials) ?></span>
        <span class="d-none d-md-inline"><?= h(explode(' ', $_su_name)[0]) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:200px;font-size:.9rem">
        <li>
          <div class="px-3 py-2 border-bottom">
            <div class="fw-bold"><?= h($_su_name) ?></div>
            <div class="text-muted small"><?= h($_su['email'] ?? '') ?></div>
          </div>
        </li>
        <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/index.php">
          <i class="bi bi-person-circle me-2"></i>Moje konto</a></li>
        <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/portal.php">
          <i class="bi bi-house me-2"></i>Portal główny</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php">
          <i class="bi bi-box-arrow-right me-2"></i>Wyloguj się</a></li>
      </ul>
    </div>
    <?php endif; ?>
  </nav>
</header>

<!-- Sidebar -->
<nav class="strat-sidebar" id="strat-nav" aria-label="Nawigacja Strategia NGO">

  <div class="strat-nav-label" aria-hidden="true">Przegląd</div>

  <a href="<?= APP_URL ?>/strategy/index.php" class="strat-nav-link"
     <?= _strat_active('/strategy/index') || preg_match('#/strategy/?$#', $_uri) ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-grid-1x2-fill" aria-hidden="true"></i>
    Dashboard
    <?php if ($_strat_at_risk > 0): ?>
    <span class="ms-auto badge" style="background:#dc2626;font-size:.65rem" aria-label="<?= $_strat_at_risk ?> celów zagrożonych"><?= $_strat_at_risk ?></span>
    <?php endif; ?>
  </a>

  <div class="strat-nav-divider" aria-hidden="true"></div>
  <div class="strat-nav-label" aria-hidden="true">Działania</div>

  <a href="<?= APP_URL ?>/strategy/actions/index.php" class="strat-nav-link"
     <?= _strat_active('/strategy/actions/index') ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-calendar-event" aria-hidden="true"></i>
    Lista działań
  </a>

  <?php if ($_can_edit): ?>
  <a href="<?= APP_URL ?>/strategy/actions/add.php" class="strat-nav-link"
     <?= _strat_active('/strategy/actions/add') ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-plus-circle" aria-hidden="true"></i>
    Nowe działanie
  </a>
  <?php endif; ?>

  <div class="strat-nav-divider" aria-hidden="true"></div>
  <div class="strat-nav-label" aria-hidden="true">Cele strategiczne</div>

  <a href="<?= APP_URL ?>/strategy/objectives/index.php" class="strat-nav-link"
     <?= _strat_active('/strategy/objectives/index') ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-bullseye" aria-hidden="true"></i>
    Wszystkie cele
  </a>

  <?php if ($_can_edit): ?>
  <a href="<?= APP_URL ?>/strategy/objectives/view.php?new=1" class="strat-nav-link"
     <?= _strat_active('/strategy/objectives/view') && isset($_GET['new']) ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-plus-circle" aria-hidden="true"></i>
    Nowy cel
  </a>
  <?php endif; ?>

  <div class="strat-nav-divider" aria-hidden="true"></div>
  <div class="strat-nav-label" aria-hidden="true">Organizacja</div>

  <a href="<?= APP_URL ?>/strategy/spheres/index.php" class="strat-nav-link"
     <?= _strat_active('/strategy/spheres') ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-globe2" aria-hidden="true"></i>
    Sfery pożytku
  </a>

  <a href="<?= APP_URL ?>/strategy/reports/index.php" class="strat-nav-link"
     <?= _strat_active('/strategy/reports/index') ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
    Raporty strategiczne
  </a>

  <a href="<?= APP_URL ?>/strategy/reports/foundation_report.php" class="strat-nav-link"
     <?= _strat_active('/strategy/reports/foundation_report') ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-file-earmark-ruled" aria-hidden="true"></i>
    Sprawozdanie fundacji
  </a>

  <?php if (is_admin()): ?>
  <div class="strat-nav-divider" aria-hidden="true"></div>
  <div class="strat-nav-label" aria-hidden="true">Administracja</div>

  <a href="<?= APP_URL ?>/strategy/admin/settings.php" class="strat-nav-link"
     <?= _strat_active('/strategy/admin/settings') ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-gear" aria-hidden="true"></i>
    Ustawienia
  </a>
  <?php endif; ?>

  <div class="strat-sidebar-bottom">
    <div class="strat-nav-divider" aria-hidden="true"></div>
    <a href="<?= APP_URL ?>/portal.php" class="strat-nav-link">
      <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
      Wróć do portalu
    </a>
  </div>
</nav>

<!-- Główna treść -->
<div class="strat-shell">
<main class="strat-content" id="strat-main" role="main" tabindex="-1">

<?php
$_flash = flash_get();
if ($_flash):
    $_ft  = $_flash['type'] ?? 'info';
    $_fm  = $_flash['msg']  ?? '';
    $_fi  = ['success'=>'bi-check-circle-fill','danger'=>'bi-exclamation-triangle-fill','warning'=>'bi-exclamation-circle-fill','info'=>'bi-info-circle-fill'];
?>
<div class="strat-alert strat-alert-<?= h($_ft) ?>" role="alert">
  <i class="bi <?= h($_fi[$_ft] ?? 'bi-info-circle-fill') ?>" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0;margin-top:.1rem"></i>
  <span><?= h($_fm) ?></span>
  <button type="button" class="strat-alert-close" aria-label="Zamknij">
    <i class="bi bi-x-lg" aria-hidden="true"></i>
  </button>
</div>
<script>(function(){var a=document.querySelector('.strat-alert-close');if(a)a.addEventListener('click',function(){this.closest('.strat-alert').remove();})})();</script>
<?php endif; ?>
