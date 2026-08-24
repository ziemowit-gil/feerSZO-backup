<?php
/**
 * directory/includes/header_dir.php — shell modułu Katalog współpracowników.
 * WCAG 2.1 AA: skip link, aria-current, aria-label, focus-visible, live regions.
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/directory.php';
require_once dirname(dirname(__DIR__)) . '/includes/branding.php';

$_b         = branding_load();
$_cu        = current_user();
$_dir_title = $PAGE_TITLE ?? 'Katalog';
$_uri       = $_SERVER['REQUEST_URI'] ?? '';
$_org_name  = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : '');

function _dir_active(string $path, bool $exact = false): string {
    global $_uri;
    $rel = strtok($_uri, '?');
    return ($exact ? ($rel === $path) : str_contains($rel, $path))
        ? ' active" aria-current="page' : '';
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
$_cu_id = (int)($_cu['id'] ?? 0);

// Sprawdź czy jesteśmy na profilu własnym
$_uri_rel = strtok($_uri, '?');
$_on_index   = str_ends_with($_uri_rel, '/directory/') || str_ends_with($_uri_rel, '/directory/index.php');
$_on_own_profile = str_contains($_uri_rel, '/directory/profile.php') && ((int)($_GET['id'] ?? 0) === $_cu_id);
$_on_edit    = str_contains($_uri_rel, '/directory/profile_edit.php');
$_on_org     = str_contains($_uri_rel, '/directory/org_chart.php');
$_on_admin_fields = str_contains($_uri_rel, '/admin/profile_fields.php');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_dir_title) ?> — Katalog<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/directory-module.css">
<?php branding_css($_b); ?>
<style>
/* ── Skip link ─────────────────────────────────────────────────── */
.dir-skip {
  position: absolute; top: -100%; left: .5rem; z-index: 9999;
  background: var(--dir-primary); color: #fff;
  padding: .6rem 1.2rem; border-radius: 0 0 8px 8px;
  font-weight: 700; text-decoration: none; font-size: .9rem;
}
.dir-skip:focus { top: 0; outline: 3px solid #FBBF24; outline-offset: 2px; }

/* ── Focus ring global ─────────────────────────────────────────── */
*:focus-visible {
  outline: 3px solid var(--dir-primary) !important;
  outline-offset: 2px !important;
  border-radius: 3px;
}
*:focus:not(:focus-visible) { outline: none; }

/* ── Sidebar nav: sr-only labels ───────────────────────────────── */
.dir-nav-section-label {
  font-size: .62rem; font-weight: 700; letter-spacing: .09em;
  text-transform: uppercase; color: var(--dir-text-light);
  padding: .6rem .75rem .2rem; display: block;
}
</style>
</head>
<body>

<!-- Skip link — pierwsza pozycja focusu, widoczna tylko przy Tab -->
<a class="dir-skip" href="#dirMain">Przejdź do treści</a>

<!-- ══ TOPBAR ══════════════════════════════════════════════════════════════ -->
<header class="dir-topbar" role="banner">

  <button class="dir-topbar-hamburger" id="dirSidebarToggle"
          aria-label="Otwórz menu nawigacyjne" aria-expanded="false"
          aria-controls="dirSidebar">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>

  <a href="<?= APP_URL ?>/directory/" class="dir-topbar-brand"
     aria-label="Katalog współpracowników — strona główna">
    <div class="dir-topbar-brand-icon" aria-hidden="true">
      <?php if ($_b['logo_url']): ?>
        <img src="<?= h($_b['logo_url']) ?>" alt=""
             style="max-height:22px;max-width:22px;object-fit:contain;filter:brightness(0) invert(1)">
      <?php else: ?>
        <i class="bi bi-person-lines-fill"></i>
      <?php endif; ?>
    </div>
    <div>
      <div>Katalog</div>
      <?php if ($_org_name): ?>
      <div class="dir-topbar-brand-org" aria-hidden="true"><?= h(mb_substr($_org_name, 0, 24, 'UTF-8')) ?></div>
      <?php endif; ?>
    </div>
  </a>

  <!-- Breadcrumb -->
  <nav class="dir-topbar-breadcrumb" aria-label="Ścieżka nawigacyjna">
    <a href="<?= APP_URL ?>/directory/" aria-label="Katalog — strona główna">
      <i class="bi bi-person-lines-fill" aria-hidden="true" style="color:var(--dir-primary)"></i>
    </a>
    <span class="sep" aria-hidden="true">/</span>
    <span class="dir-topbar-page" aria-current="location"><?= h($_dir_title) ?></span>
  </nav>

  <div class="dir-topbar-user" role="navigation" aria-label="Menu użytkownika">
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
    <?php $msw_active='directory'; $msw_dark=true; require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>

    <?php if ($_cu): ?>
    <div class="dropdown">
      <button type="button"
              class="dir-topbar-avatar"
              data-bs-toggle="dropdown"
              aria-haspopup="true" aria-expanded="false"
              aria-label="<?= h($_cu_name) ?> — opcje konta">
        <span aria-hidden="true"><?= h($_cu_initials) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:220px;font-size:.84rem"
          role="menu">
        <li role="none" class="px-3 py-2 border-bottom">
          <div class="fw-semibold" style="font-size:.85rem"><?= h($_cu_name) ?></div>
          <div class="text-muted" style="font-size:.75rem"><?= h($_cu['email'] ?? '') ?></div>
        </li>
        <li role="none"><a class="dropdown-item" role="menuitem"
            href="<?= APP_URL ?>/directory/profile.php?id=<?= $_cu_id ?>">
          <i class="bi bi-person-badge me-2" aria-hidden="true"></i>Mój profil</a></li>
        <li role="none"><a class="dropdown-item" role="menuitem"
            href="<?= APP_URL ?>/directory/profile_edit.php">
          <i class="bi bi-pencil me-2" aria-hidden="true"></i>Edytuj profil</a></li>
        <li role="none"><a class="dropdown-item" role="menuitem"
            href="<?= APP_URL ?>/index.php">
          <i class="bi bi-house me-2" aria-hidden="true"></i>System główny</a></li>
        <li role="none"><hr class="dropdown-divider my-1" aria-hidden="true"></li>
        <li role="none"><a class="dropdown-item text-danger" role="menuitem"
            href="<?= APP_URL ?>/auth/logout.php">
          <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Wyloguj się</a></li>
      </ul>
    </div>
    <span class="dir-topbar-username d-none d-md-inline" aria-hidden="true">
      <?= h(explode(' ', $_cu_name)[0]) ?>
    </span>
    <?php endif; ?>
  </div>

</header>
<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>
<?php $ASAI_WIDGET_SCOPE = 'katalog';
      require_once dirname(dirname(__DIR__)) . '/includes/asystent_widget.php'; ?>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════════════ -->
<nav class="dir-sidebar" id="dirSidebar"
     aria-label="Nawigacja modułu Katalog współpracowników">

  <!-- Karta zalogowanego użytkownika -->
  <?php if ($_cu): ?>
  <div class="dir-sidebar-user">
    <a href="<?= APP_URL ?>/directory/profile.php?id=<?= $_cu_id ?>"
       class="dir-sidebar-user-avatar" aria-label="Mój profil: <?= h($_cu_name) ?>">
      <?= h($_cu_initials) ?>
    </a>
    <div class="dir-sidebar-user-body">
      <div class="dir-sidebar-user-name"><?= h(explode(' ', $_cu_name)[0] ?? $_cu_name) ?></div>
      <a href="<?= APP_URL ?>/directory/profile.php?id=<?= $_cu_id ?>"
         class="dir-sidebar-user-link">Mój profil</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Nawigacja główna -->
  <div class="dir-nav-group" role="list">
    <span class="dir-nav-section-label" role="presentation">Katalog</span>

    <div role="listitem">
      <a href="<?= APP_URL ?>/directory/"
         class="dir-nav-item<?= $_on_index ? ' active" aria-current="page' : '' ?>">
        <span class="dir-nav-icon" style="--c:#EEF2FF;--t:#4F46E5" aria-hidden="true">
          <i class="bi bi-people-fill"></i>
        </span>
        <span>Wszyscy</span>
      </a>
    </div>

    <?php if ($_cu_id): ?>
    <div role="listitem">
      <a href="<?= APP_URL ?>/directory/profile.php?id=<?= $_cu_id ?>"
         class="dir-nav-item<?= $_on_own_profile ? ' active" aria-current="page' : '' ?>">
        <span class="dir-nav-icon" style="--c:#F0FDFA;--t:#0D9488" aria-hidden="true">
          <i class="bi bi-person-badge"></i>
        </span>
        <span>Mój profil</span>
      </a>
    </div>
    <div role="listitem">
      <a href="<?= APP_URL ?>/directory/profile_edit.php"
         class="dir-nav-item<?= $_on_edit ? ' active" aria-current="page' : '' ?>">
        <span class="dir-nav-icon" style="--c:#FFFBEB;--t:#D97706" aria-hidden="true">
          <i class="bi bi-pencil-square"></i>
        </span>
        <span>Edytuj profil</span>
      </a>
    </div>
    <?php endif; ?>

    <div role="listitem">
      <a href="<?= APP_URL ?>/directory/org_chart.php"
         class="dir-nav-item<?= $_on_org ? ' active" aria-current="page' : '' ?>">
        <span class="dir-nav-icon" style="--c:#E0F2FE;--t:#0284C7" aria-hidden="true">
          <i class="bi bi-diagram-3"></i>
        </span>
        <span>Struktura org.</span>
      </a>
    </div>
  </div>

  <?php if (is_admin()): ?>
  <div class="dir-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="dir-nav-group" role="list">
    <span class="dir-nav-section-label" role="presentation">Administrator</span>
    <div role="listitem">
      <a href="<?= APP_URL ?>/admin/profile_fields.php"
         class="dir-nav-item<?= $_on_admin_fields ? ' active" aria-current="page' : '' ?>">
        <span class="dir-nav-icon" style="--c:#FFF1F2;--t:#E11D48" aria-hidden="true">
          <i class="bi bi-card-list"></i>
        </span>
        <span>Pola profilu</span>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <div class="dir-sidebar-bottom" role="list">
    <div role="listitem">
      <a href="<?= APP_URL ?>/index.php" class="dir-nav-item">
        <span class="dir-nav-icon" style="--c:#F1F5F9;--t:#64748B" aria-hidden="true">
          <i class="bi bi-box-arrow-left"></i>
        </span>
        <span>System główny</span>
      </a>
    </div>
  </div>

</nav>

<!-- ══ SHELL ══════════════════════════════════════════════════════════════ -->
<div class="dir-shell">

<!-- Region live — ogłoszenia dla czytników ekranu -->
<div role="status" aria-live="polite" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)"
     id="dirLiveStatus"></div>
<div role="alert" aria-live="assertive" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)"
     id="dirLiveAlert"></div>

<main class="dir-content" id="dirMain" tabindex="-1">

<?php
$_flash = flash_get();
if ($_flash): ?>
<div class="alert alert-<?= h($_flash['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-3"
     role="alert" aria-live="polite">
  <i class="bi bi-<?= $_flash['type']==='success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill' ?>"
     aria-hidden="true"></i>
  <span><?= h($_flash['msg']) ?></span>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij komunikat"></button>
</div>
<?php endif; ?>
