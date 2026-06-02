<?php
/**
 * crm/includes/header_crm.php — CRM layout z lewym sidebarem.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

// ── Weryfikacja IKA — wymagana dla całego CRM ────────────────────────────────
// Sesja IKA jest współdzielona z systemem głównym (30 min). Użytkownicy bez
// przypisanego kodu IKA mogą poprosić admina o jego nadanie (Administracja → Kody IKA).
// Usuń bazowy prefiks APP_URL z REQUEST_URI zanim go dokleisz — inaczej
// na subpath (np. /szo-preprod/) powstaje podwójny prefiks w URL powrotu.
(function () {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    ika_require(APP_URL . $uri);
})();

$_cu        = current_user();
$_crm_title = $PAGE_TITLE ?? 'CRM';
$_uri       = $_SERVER['REQUEST_URI'] ?? '';
$_org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_crm_can_write = (is_admin() || can_write('crm'));

function _crm_nav_active(string $path): string {
    global $_uri;
    return str_contains($_uri, $path) ? ' active' : '';
}

$_cu_initials = '?';
$_cu_name     = '';
if ($_cu) {
    $name = trim(($_cu['first_name'] ?? '') . ' ' . ($_cu['last_name'] ?? ''));
    if ($name === '') $name = $_cu['name'] ?? '';
    $parts = preg_split('/\s+/', trim($name));
    $_cu_initials = '';
    foreach ($parts as $w) $_cu_initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    $_cu_initials = mb_substr($_cu_initials, 0, 2, 'UTF-8') ?: '?';
    $_cu_name = $name ?: ($_cu['email'] ?? 'Użytkownik');
}

// Statystyki do sidebara
try {
    $_crm_total = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1")['c'] ?? 0);
} catch (\Throwable $e) { $_crm_total = 0; }
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_crm_title) ?> — CRM<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/crm-module.css">
<style>
/* ── CRM Shell ───────────────────────────────────────────────────────── */
:root {
  --crm-sidebar-w: 220px;
  --crm-topbar-h: 52px;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }

body {
  background: #F0F2F5;
  font-family: system-ui, -apple-system, sans-serif;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* ══ TOPBAR ══════════════════════════════════════════════════════════ */
.crm-topbar {
  height: var(--crm-topbar-h);
  background: #fff;
  border-bottom: 1px solid #E5E7EB;
  display: flex;
  align-items: center;
  padding: 0 1.25rem 0 0;
  gap: 0;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 1040;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

/* Brand w topbarze (widoczny gdy sidebar zwinięty / mobile) */
.crm-topbar-brand {
  width: var(--crm-sidebar-w);
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: 0 1.1rem;
  flex-shrink: 0;
  text-decoration: none;
  color: var(--crm-primary-dark);
  font-weight: 800;
  font-size: 1rem;
  letter-spacing: .3px;
  border-right: 1px solid #E5E7EB;
  height: 100%;
}
.crm-topbar-brand-icon {
  width: 30px; height: 30px;
  background: linear-gradient(135deg, #194E31 0%, #2E844A 100%);
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: .9rem; flex-shrink: 0;
}
.crm-topbar-brand-org {
  font-size: .64rem; color: #9CA3AF; font-weight: 400;
  line-height: 1; letter-spacing: 0;
}

/* Breadcrumb w topbarze */
.crm-topbar-breadcrumb {
  flex: 1;
  padding: 0 1.25rem;
  font-size: .83rem;
  color: #6B7280;
  display: flex; align-items: center; gap: .4rem;
  overflow: hidden;
}
.crm-topbar-breadcrumb a { color: var(--crm-primary); text-decoration: none; }
.crm-topbar-breadcrumb a:hover { text-decoration: underline; }
.crm-topbar-breadcrumb .sep { color: #D1D5DB; font-size: .75rem; }
.crm-topbar-page { font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* User area w topbarze */
.crm-topbar-user {
  display: flex; align-items: center; gap: .75rem;
  padding-left: 1rem;
}
.crm-topbar-avatar {
  width: 32px; height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, #194E31 0%, #2E844A 100%);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; font-weight: 700;
  cursor: pointer;
  border: 2px solid #E5E7EB;
}
.crm-topbar-username { font-size: .83rem; font-weight: 500; color: #374151; }

/* Powrót do systemu głównego */
.crm-topbar-sys-link {
  display: inline-flex; align-items: center; gap: .4rem;
  font-size: .78rem; color: #9CA3AF;
  text-decoration: none; padding: .25rem .6rem;
  border: 1px solid #E5E7EB; border-radius: 6px;
  transition: all .12s;
  white-space: nowrap;
}
.crm-topbar-sys-link:hover { color: var(--crm-primary); border-color: var(--crm-primary); background: var(--crm-primary-bg); }

/* ══ SIDEBAR ══════════════════════════════════════════════════════════ */
.crm-sidebar {
  position: fixed;
  top: var(--crm-topbar-h);
  left: 0;
  bottom: 0;
  width: var(--crm-sidebar-w);
  background: #fff;
  border-right: 1px solid #E5E7EB;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  overflow-x: hidden;
  z-index: 1030;
  transition: transform .25s;
}
.crm-sidebar::-webkit-scrollbar { width: 4px; }
.crm-sidebar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 2px; }

/* Sidebar group */
.crm-nav-group { padding: .75rem .75rem .25rem; }
.crm-nav-group-label {
  font-size: .65rem; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: #9CA3AF;
  padding: 0 .5rem; margin-bottom: .25rem;
}

/* Sidebar nav link */
.crm-nav-item {
  display: flex; align-items: center; gap: .6rem;
  padding: .45rem .7rem;
  border-radius: 7px;
  font-size: .84rem; font-weight: 500;
  color: #374151;
  text-decoration: none;
  transition: background .1s, color .1s;
  margin-bottom: 1px;
  position: relative;
}
.crm-nav-item i { font-size: 1rem; width: 20px; text-align: center; flex-shrink: 0; color: #9CA3AF; transition: color .1s; }
.crm-nav-item:hover { background: #F9FAFB; color: var(--crm-primary); }
.crm-nav-item:hover i { color: var(--crm-primary); }
.crm-nav-item.active {
  background: var(--crm-primary-bg);
  color: var(--crm-primary);
  font-weight: 600;
}
.crm-nav-item.active i { color: var(--crm-primary); }
.crm-nav-item.active::before {
  content: '';
  position: absolute; left: 0; top: 20%; bottom: 20%;
  width: 3px; border-radius: 0 3px 3px 0;
  background: var(--crm-primary);
}
.crm-nav-badge {
  margin-left: auto;
  background: #E5E7EB; color: #6B7280;
  font-size: .65rem; font-weight: 700;
  padding: .1rem .4rem; border-radius: 10px;
  min-width: 18px; text-align: center;
}
.crm-nav-item.active .crm-nav-badge { background: var(--crm-primary); color: #fff; }

/* Sidebar divider */
.crm-nav-divider { height: 1px; background: #F3F4F6; margin: .5rem .75rem; }

/* Sidebar bottom section */
.crm-sidebar-bottom {
  margin-top: auto;
  border-top: 1px solid #F3F4F6;
  padding: .75rem;
}
.crm-sidebar-bottom .crm-nav-item { font-size: .8rem; }

/* ══ MAIN CONTENT ═══════════════════════════════════════════════════ */
.crm-shell {
  margin-top: var(--crm-topbar-h);
  margin-left: var(--crm-sidebar-w);
  min-height: calc(100vh - var(--crm-topbar-h));
  display: flex;
  flex-direction: column;
}
.crm-content {
  flex: 1;
  padding: 1.5rem;
  max-width: 1440px;
  width: 100%;
}
.crm-footer {
  background: #fff;
  border-top: 1px solid #E5E7EB;
  padding: .5rem 1.5rem;
  font-size: .73rem;
  color: #9CA3AF;
  display: flex; justify-content: space-between; align-items: center;
  margin-left: 0;
}

/* ══ RESPONSIVE ══════════════════════════════════════════════════════ */
@media (max-width: 768px) {
  :root { --crm-sidebar-w: 0px; }
  .crm-sidebar { transform: translateX(-220px); }
  .crm-sidebar.open { transform: translateX(0); width: 220px; }
  .crm-topbar-brand { width: auto; border-right: none; }
  .crm-topbar-username { display: none; }
  .crm-topbar-sys-link { display: none; }
  .crm-shell { margin-left: 0; }
  .crm-content { padding: 1rem .75rem; }
}

/* ══ PAGE HEADER (standardowy nagłówek strony) ═══════════════════════ */
.crm-page-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 1rem; flex-wrap: wrap;
  margin-bottom: 1.25rem;
}
.crm-page-title {
  font-size: 1.3rem; font-weight: 700; color: #111827; margin: 0 0 .15rem;
  display: flex; align-items: center; gap: .5rem;
}
.crm-page-subtitle { font-size: .82rem; color: #6B7280; }
.crm-page-actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; flex-shrink: 0; }
</style>
</head>
<body>

<!-- ══ TOPBAR ══════════════════════════════════════════════════════════════════ -->
<header class="crm-topbar" role="banner">

  <!-- Brand (lewa część topbara, nad sidebarem) -->
  <a href="<?= APP_URL ?>/crm/dashboard.php" class="crm-topbar-brand" aria-label="CRM — dashboard">
    <div class="crm-topbar-brand-icon" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
    <div>
      <div>CRM</div>
      <?php if ($_org_name): ?>
      <div class="crm-topbar-brand-org"><?= h(mb_substr($_org_name, 0, 24, 'UTF-8')) ?></div>
      <?php endif; ?>
    </div>
  </a>

  <!-- Breadcrumb / tytuł strony -->
  <div class="crm-topbar-breadcrumb">
    <span class="d-none d-sm-inline">
      <a href="<?= APP_URL ?>/crm/dashboard.php"><i class="bi bi-diagram-2-fill" style="color:var(--crm-primary)"></i></a>
      <span class="sep mx-1">/</span>
    </span>
    <span class="crm-topbar-page"><?= h($_crm_title) ?></span>
  </div>

  <!-- Prawa część: link do systemu + user -->
  <div class="crm-topbar-user">
    <a href="<?= APP_URL ?>/index.php" class="crm-topbar-sys-link" title="Wróć do systemu głównego">
      <i class="bi bi-house"></i>
      <span>System</span>
    </a>

    <?php if ($_cu): ?>
    <div class="dropdown">
      <button type="button"
              class="crm-topbar-avatar"
              data-bs-toggle="dropdown"
              aria-haspopup="true"
              aria-expanded="false"
              aria-label="Menu użytkownika">
        <?= h($_cu_initials) ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:200px;font-size:.84rem">
        <li class="px-3 py-2 border-bottom">
          <div class="fw-semibold" style="font-size:.85rem"><?= h($_cu_name) ?></div>
          <div class="text-muted" style="font-size:.75rem"><?= h($_cu['email'] ?? '') ?></div>
        </li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-person-circle me-2"></i>Moje konto</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/index.php"><i class="bi bi-house me-2"></i>System główny</a></li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Wyloguj się</a></li>
      </ul>
    </div>
    <span class="crm-topbar-username d-none d-md-inline"><?= h(explode(' ', $_cu_name)[0]) ?></span>
    <?php endif; ?>
  </div>

</header>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════════ -->
<aside class="crm-sidebar" id="crmSidebar" role="navigation" aria-label="Nawigacja CRM">

  <!-- Główna nawigacja -->
  <div class="crm-nav-group">
    <div class="crm-nav-group-label">Menu</div>

    <a href="<?= APP_URL ?>/crm/dashboard.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/dashboard') ?>">
      <i class="bi bi-grid-1x2-fill"></i>
      <span>Dashboard</span>
    </a>

    <a href="<?= APP_URL ?>/crm/index.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/index') ?>">
      <i class="bi bi-people-fill"></i>
      <span>Kontakty</span>
      <?php if ($_crm_total): ?>
      <span class="crm-nav-badge"><?= $_crm_total > 999 ? '999+' : $_crm_total ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= APP_URL ?>/crm/communicate.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/communicate') ?>">
      <i class="bi bi-send-fill"></i>
      <span>Komunikacja</span>
    </a>

    <a href="<?= APP_URL ?>/crm/mass_send.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/mass_send') ?>">
      <i class="bi bi-megaphone-fill"></i>
      <span>Wysyłka masowa</span>
    </a>

    <a href="<?= APP_URL ?>/crm/calendar.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/calendar') ?>">
      <i class="bi bi-calendar3-fill"></i>
      <span>Kalendarz</span>
    </a>

    <a href="<?= APP_URL ?>/crm/cases/index.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/cases') ?>">
      <i class="bi bi-briefcase-fill"></i>
      <span>Sprawy</span>
    </a>

    <a href="<?= APP_URL ?>/crm/groups.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/groups') ?><?= _crm_nav_active('/crm/group/') ?>">
      <i class="bi bi-collection-fill"></i>
      <span>Grupy</span>
    </a>

    <a href="<?= APP_URL ?>/crm/tags.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/tags') ?>">
      <i class="bi bi-tags-fill"></i>
      <span>Tagi</span>
    </a>
  </div>

  <?php if ($_crm_can_write): ?>
  <div class="crm-nav-divider"></div>
  <div class="crm-nav-group">
    <div class="crm-nav-group-label">Dodaj</div>
    <a href="<?= APP_URL ?>/crm/contact/add_person.php"
       class="crm-nav-item<?= _crm_nav_active('add_person') ?>">
      <i class="bi bi-person-plus"></i>
      <span>Nowa osoba</span>
    </a>
    <a href="<?= APP_URL ?>/crm/contact/add_org.php"
       class="crm-nav-item<?= _crm_nav_active('add_org') ?>">
      <i class="bi bi-building-add"></i>
      <span>Nowa firma / org.</span>
    </a>
    <?php if ($_crm_can_write): ?>
    <a href="<?= APP_URL ?>/crm/contact/import.php"
       class="crm-nav-item<?= _crm_nav_active('/crm/contact/import') ?>">
      <i class="bi bi-upload"></i>
      <span>Import CSV</span>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Dolna sekcja sidebar -->
  <div class="crm-sidebar-bottom">
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/crm/settings/" class="crm-nav-item <?= _crm_nav_active('/crm/settings') ?>">
      <i class="bi bi-gear-fill"></i>
      <span>Ustawienia CRM</span>
    </a>
    <?php endif; ?>
    <?php if (!defined('CRM_STANDALONE') || !CRM_STANDALONE): ?>
    <a href="<?= APP_URL ?>/index.php" class="crm-nav-item">
      <i class="bi bi-box-arrow-left"></i>
      <span>System główny</span>
    </a>
    <?php endif; ?>
  </div>

</aside>

<!-- ══ SHELL WRAPPER ═══════════════════════════════════════════════════════════ -->
<div class="crm-shell">
<main class="crm-content" id="crmMain" role="main">

<?php
$_flash = flash_get();
if ($_flash):
?>
<div class="alert alert-<?= h($_flash['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-3"
     role="alert" aria-live="polite">
  <i class="bi bi-<?= $_flash['type']==='success' ? 'check-circle-fill text-success' : ($_flash['type']==='danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"
     aria-hidden="true"></i>
  <span><?= h($_flash['msg']) ?></span>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>
