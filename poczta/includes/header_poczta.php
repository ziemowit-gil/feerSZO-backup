<?php
/**
 * poczta/includes/header_poczta.php — Standalone layout modułu Poczty
 * Wymaga: $PAGE_TITLE, opcjonalnie $PC_BREADCRUMB (string)
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/poczta.php';
require_once dirname(dirname(__DIR__)) . '/includes/permissions.php';

require_login();
require_once dirname(dirname(__DIR__)) . '/includes/poczta_acl.php';

// Moduł jest dostępny dla każdego zalogowanego — także wolontariusza. To, CO widzi,
// wynika z uprawnień do poszczególnych skrzynek (poczta_mailbox_acl): własna skrzynka
// osobista i te współdzielone, do których administrator nadał dostęp. Dane skrzynek,
// do których nie ma uprawnień, nie są w ogóle pobierane (patrz poczta_scope_sql()).
poczta_acl_migrate();
$_pc_my_mailboxes = poczta_allowed_mailbox_ids();

$_pu       = current_user();
$_pc_title = $PAGE_TITLE ?? 'Poczta';
$_pc_bc    = $PC_BREADCRUMB ?? ($PAGE_TITLE ?? '');
$_uri      = $_SERVER['REQUEST_URI'] ?? '';
$_org_name = defined('ORG_NAME') ? ORG_NAME : '';
$_is_admin = is_admin();

$_pu_name = trim(($_pu['first_name'] ?? '') . ' ' . ($_pu['last_name'] ?? ''));
if (!$_pu_name) $_pu_name = $_pu['name'] ?? 'Użytkownik';
$_pu_parts = preg_split('/\s+/', trim($_pu_name));
$_pu_inits = '';
foreach ($_pu_parts as $_w) $_pu_inits .= mb_strtoupper(mb_substr($_w,0,1,'UTF-8'),'UTF-8');
$_pu_inits = mb_substr($_pu_inits,0,2,'UTF-8') ?: '?';

// KPI liczniki (nawigacja)
$_pc_mailboxes = 0;
$_pc_attention = 0;
try {
    $_pc_mailboxes = (int)(db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes WHERE enabled=1")['n'] ?? 0);
    $_pc_attention = (int)(db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes WHERE status IN ('auth_error','rate_limited')")['n'] ?? 0);
} catch (\Throwable $e) {}

function _pc_active(string $path): bool {
    global $_uri;
    return str_contains($_uri, $path);
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($_pc_title) ?> — Poczta<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
  --pc-blue:      #1d4ed8;
  --pc-blue-dark: #1e3a8a;
  --pc-blue-mid:  #3b82f6;
  --pc-blue-bg:   #eff6ff;
  --pc-focus:     #facc15;
  --pc-text:      #0f172a;
  --pc-muted:     #64748b;
  --pc-border:    #e2e8f0;
  --pc-bg:        #f8fafc;
  --pc-sidebar-w: 240px;
  --pc-topbar-h:  52px;
}
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body { margin:0; background:var(--pc-bg); color:var(--pc-text);
  font-family:system-ui,-apple-system,'Segoe UI',sans-serif; font-size:.9375rem; line-height:1.6; }

.skip-link { position:absolute;top:-100%;left:1rem;z-index:9999;
  background:var(--pc-blue);color:#fff;padding:.65rem 1.4rem;
  border-radius:0 0 8px 8px;font-size:.95rem;font-weight:700;
  text-decoration:none;border:3px solid var(--pc-focus); }
.skip-link:focus { top:0; }
*:focus-visible { outline:3px solid var(--pc-focus)!important;outline-offset:2px!important;border-radius:3px; }
*:focus:not(:focus-visible) { outline:none; }

/* Topbar */
.pc-topbar { height:var(--pc-topbar-h);background:var(--pc-blue-dark);color:#fff;
  display:flex;align-items:center;padding:0 1.25rem 0 0;
  position:fixed;top:0;left:0;right:0;z-index:1040;
  box-shadow:0 2px 8px rgba(0,0,0,.28); }
.pc-brand { width:var(--pc-sidebar-w);display:flex;align-items:center;gap:.6rem;
  padding:0 1rem;flex-shrink:0;text-decoration:none;color:#fff;
  font-weight:800;font-size:.97rem;height:100%;
  border-right:1px solid rgba(255,255,255,.2);transition:background .15s; }
.pc-brand:hover { background:rgba(255,255,255,.08);color:#fff; }
.pc-brand-icon { width:30px;height:30px;background:rgba(255,255,255,.18);border-radius:7px;
  display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0; }
.pc-topbar-bc { flex:1;padding:0 1.25rem;font-size:.84rem;
  color:rgba(255,255,255,.8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.pc-topbar-bc strong { color:#fff; }
.pc-topbar-actions { display:flex;align-items:center;gap:.5rem;flex-shrink:0; }
.pc-user-btn { display:flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.13);
  border:1.5px solid rgba(255,255,255,.35);border-radius:6px;color:#fff;
  padding:.28rem .7rem;font-size:.82rem;font-weight:500; }
.pc-user-av { width:26px;height:26px;border-radius:50%;background:var(--pc-blue-mid);
  display:flex;align-items:center;justify-content:center;font-size:.67rem;font-weight:700;flex-shrink:0; }
.pc-menu-toggle { display:none;background:none;border:none;color:#fff;
  font-size:1.3rem;padding:.3rem .5rem;cursor:pointer;margin-right:.5rem; }

/* Sidebar */
.pc-sidebar { position:fixed;top:var(--pc-topbar-h);left:0;bottom:0;
  width:var(--pc-sidebar-w);background:#fff;border-right:1px solid var(--pc-border);
  display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;z-index:1030;transition:left .2s; }
.pc-nav-label { font-size:.68rem;font-weight:700;letter-spacing:.09em;
  text-transform:uppercase;color:#94a3b8;padding:1rem 1rem .3rem;display:block; }
.pc-nav-link { display:flex;align-items:center;gap:.6rem;padding:.52rem 1rem;
  color:#334155;text-decoration:none;font-size:.88rem;font-weight:500;
  border-left:3px solid transparent;transition:background .1s,color .1s;position:relative; }
.pc-nav-link i { font-size:.95rem;flex-shrink:0;width:18px;text-align:center; }
.pc-nav-link:hover { background:var(--pc-blue-bg);color:var(--pc-blue); }
.pc-nav-link.active { background:var(--pc-blue-bg);color:var(--pc-blue);
  border-left-color:var(--pc-blue);font-weight:700; }
.pc-nav-badge { margin-left:auto;font-size:.64rem;font-weight:700;
  background:#dbeafe;color:var(--pc-blue);border-radius:2rem;padding:.05rem .45rem;flex-shrink:0; }
.pc-nav-badge.warn { background:#fef3c7;color:#92400e; }
.pc-nav-sep { height:1px;background:var(--pc-border);margin:.5rem .75rem; }
.pc-sidebar-footer { margin-top:auto;padding:.75rem 1rem;border-top:1px solid var(--pc-border);
  font-size:.77rem;color:#94a3b8; }

/* Main */
.pc-main { margin-top:var(--pc-topbar-h);margin-left:var(--pc-sidebar-w);
  min-height:calc(100vh - var(--pc-topbar-h));padding:1.5rem 1.75rem 2.5rem; }

/* Flash */
.pc-flash { margin-bottom:1rem; }

@media(max-width:768px) {
  .pc-menu-toggle { display:block; }
  .pc-sidebar { left:calc(-1 * var(--pc-sidebar-w)); }
  .pc-sidebar.open { left:0;box-shadow:4px 0 20px rgba(0,0,0,.15); }
  .pc-main { margin-left:0;padding:1rem; }
  .pc-topbar-bc { display:none; }
}
</style>
</head>
<body>

<a class="skip-link" href="#pc-main">Przejdź do treści</a>

<!-- Topbar -->
<header class="pc-topbar" role="banner">
  <button class="pc-menu-toggle" aria-label="Otwórz menu" aria-expanded="false"
          aria-controls="pc-sidebar" onclick="pcToggleSidebar(this)">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <a class="pc-brand" href="<?= APP_URL ?>/poczta/dashboard.php">
    <span class="pc-brand-icon" aria-hidden="true"><i class="bi bi-envelope-fill"></i></span>
    <span>Poczta</span>
  </a>
  <div class="pc-topbar-bc" aria-label="Nawigacja">
    Poczta<?= $_pc_bc ? ' / <strong>' . h($_pc_bc) . '</strong>' : '' ?>
  </div>
  <div class="pc-topbar-actions">
    <?php $msw_active='poczta'; $msw_dark=true; require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>
    <span class="pc-user-btn" aria-label="Zalogowany: <?= h($_pu_name) ?>">
      <span class="pc-user-av" aria-hidden="true"><?= h($_pu_inits) ?></span>
      <span class="d-none d-sm-inline"><?= h($_pu_name) ?></span>
    </span>
  </div>
</header>
<?php $ASAI_WIDGET_SCOPE = 'poczta';
      require_once dirname(dirname(__DIR__)) . '/includes/asystent_widget.php'; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/quick_actions_widget.php'; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/search_hotkey.php'; ?>

<!-- Sidebar -->
<nav id="pc-sidebar" class="pc-sidebar" aria-label="Nawigacja modułu Poczty">

  <span class="pc-nav-label">Przegląd</span>

  <a class="pc-nav-link <?= _pc_active('/poczta/dashboard') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/poczta/dashboard.php">
    <i class="bi bi-speedometer2" aria-hidden="true"></i>Dashboard
  </a>

  <a class="pc-nav-link <?= (_pc_active('/poczta/index') || _pc_active('/poczta/add') || _pc_active('/poczta/edit')) ? 'active' : '' ?>"
     href="<?= APP_URL ?>/poczta/index.php">
    <i class="bi bi-inboxes" aria-hidden="true"></i>
    Skrzynki
    <?php if ($_pc_attention > 0): ?>
    <span class="pc-nav-badge warn" aria-label="<?= $_pc_attention ?> wymaga uwagi"><?= $_pc_attention ?></span>
    <?php elseif ($_pc_mailboxes > 0): ?>
    <span class="pc-nav-badge"><?= $_pc_mailboxes ?></span>
    <?php endif; ?>
  </a>

  <?php if ($_is_admin): ?>
  <div class="pc-nav-sep" role="separator"></div>
  <span class="pc-nav-label">Ustawienia</span>
  <a class="pc-nav-link <?= _pc_active('/admin/poczta_settings') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/admin/poczta_settings.php">
    <i class="bi bi-gear" aria-hidden="true"></i>Ustawienia skanowania
  </a>
  <?php endif; ?>

  <div class="pc-sidebar-footer" aria-hidden="true">Moduł Poczty</div>
</nav>

<!-- Main -->
<main id="pc-main" class="pc-main" tabindex="-1">
<?php
$_fm = flash_get();
if ($_fm): ?>
<div class="pc-flash" role="status" aria-live="polite">
  <div class="alert alert-<?= $_fm['type']==='error'?'danger':h($_fm['type']) ?> alert-dismissible">
    <?= h($_fm['msg']??$_fm['message']??'') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
</div>
<?php endif; ?>
<script>
function pcToggleSidebar(btn){
  const sb=document.getElementById('pc-sidebar'),open=sb.classList.toggle('open');
  btn.setAttribute('aria-expanded',open?'true':'false');
}
document.addEventListener('click',function(e){
  const sb=document.getElementById('pc-sidebar'),btn=document.querySelector('.pc-menu-toggle');
  if(sb&&btn&&!sb.contains(e.target)&&!btn.contains(e.target)){
    sb.classList.remove('open');btn.setAttribute('aria-expanded','false');
  }
});
</script>
