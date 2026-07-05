<?php
if (!defined('APP_INSTALLED')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_audit.php';
admin_audit_migrate();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/notifications.php';
notif_migrate();

$_user       = current_user();
$_page_title = $PAGE_TITLE ?? 'Rejestr Umów';

// ── Globalny status systemu: PRZESTÓJ — pełna blokada dla zwykłych użytkowników ─
// Administrator i konto serwisowe SaaS przechodzą dalej (z banerem). Pozostali widzą
// stronę informacyjną. Logowanie pozostaje dostępne (login.php nie ładuje tego nagłówka).
if ($_user && function_exists('system_is_downtime') && system_is_downtime()
    && !is_admin() && ($_user['email'] ?? '') !== 'serwis@local') {
    $_dt_reason = system_status_reason();
    http_response_code(503);
    header('Retry-After: 3600');
    ?><!doctype html>
<html lang="pl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Przerwa techniczna — <?= htmlspecialchars(defined('ORG_NAME') ? ORG_NAME : 'System', ENT_QUOTES) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head><body class="bg-body-tertiary">
<div class="container" style="max-width:600px">
  <div class="text-center" style="margin-top:12vh">
    <i class="bi bi-cone-striped text-danger" style="font-size:3.5rem"></i>
    <h1 class="h3 fw-bold mt-3">Przerwa w pracy systemu</h1>
    <p class="text-secondary">System jest tymczasowo niedostępny. Prosimy spróbować ponownie później.</p>
    <?php if ($_dt_reason !== ''): ?>
    <div class="alert alert-warning d-inline-block text-start mt-2">
      <i class="bi bi-info-circle me-1"></i><strong>Powód:</strong> <?= nl2br(htmlspecialchars($_dt_reason, ENT_QUOTES)) ?>
    </div>
    <?php endif; ?>
    <div class="mt-4">
      <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES) ?>/auth/logout.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-box-arrow-right me-1"></i>Wyloguj
      </a>
    </div>
  </div>
</div>
</body></html><?php
    exit;
}

// ── Consent / onboarding check ───────────────────────────────────────────────
// Runs for every logged-in user. Redirects to consent page if:
//   - document_form_consent is 0  OR
//   - consent text has changed since last acceptance
// Skipped for: SAAS admin, API endpoints, auth pages, the consent page itself.
if ($_user && !defined('SKIP_CONSENT_CHECK')) {
    // Pages that must never be redirected
    $_skip_consent_paths = [
        '/user/first_login_consent.php',
        '/user/verify_reset.php',
        '/auth/',
        '/admin/api/',
        '/api/',
    ];
    $_current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $_skip_consent = false;
    foreach ($_skip_consent_paths as $_sp) {
        if (str_contains($_current_path, $_sp)) { $_skip_consent = true; break; }
    }

    // SaaS system admin never needs consent
    $_is_saas_sys = ($_user['email'] ?? '') === 'serwis@local';

    if (!$_skip_consent && !$_is_saas_sys) {
        require_once __DIR__ . '/cpc.php';
        cpc_migrate();

        // Refresh consent status from DB (session may be stale)
        $_fresh_user = db_one("SELECT document_form_consent, consent_text_version_hash FROM users WHERE id=?", [(int)$_user['id']]);
        if ($_fresh_user) {
            $_has_consent = (int)($_fresh_user['document_form_consent'] ?? 0) === 1;
            $_hash_match  = ($_fresh_user['consent_text_version_hash'] ?? '') === consent_current_hash();
            if (!$_has_consent || !$_hash_match) {
                header('Location: ' . APP_URL . '/user/first_login_consent.php');
                exit;
            }
        }
    }
}

$_pending = 0;
if ($_user && is_admin()) {
    require_once __DIR__ . '/amendments.php';
    $_pending = get_workflow_pending_count();
}

$_uri = $_SERVER['REQUEST_URI'] ?? '';

// SaaS context
$_is_tenant      = defined('TENANT_SLUG') && TENANT_SLUG !== '';
$_is_service_acc = ($_user['email'] ?? '') === 'serwis@local';
$_is_saas_admin  = $_is_tenant && $_is_service_acc;
// URL panelu SaaS: dla tenanta odetnij /org/slug, dla standalone użyj APP_URL
$_saas_url = $_is_service_acc
    ? ($_is_tenant
        ? preg_replace('#/org/' . preg_quote(TENANT_SLUG, '#') . '$#', '', rtrim(APP_URL, '/')) . '/saas-tenent/x/'
        : rtrim(APP_URL, '/') . '/saas-tenent/x/')
    : null;

$_contract_icons = [
    'zlecenie'    => 'bi-person-lines-fill',
    'uslugi'      => 'bi-briefcase',
    'wolontariat' => 'bi-heart',
    'dzielo'      => 'bi-palette',
    'praca'       => 'bi-building',
    'powierzenie' => 'bi-bank',
    'inne'        => 'bi-file-text',
];

// Branding settings
$_sb_color        = org_setting('sidebar_color') ?: '#1e293b';
$_volunteer_color = org_setting('volunteer_color') ?: '#2563eb';
$_org_logo  = org_setting('org_logo');  // relative filename under assets/logo/

// Oblicz kontrast: jasny lub ciemny tekst zależnie od luminancji tła
function _sb_luminance(string $hex): float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    [$r, $g, $b] = [hexdec(substr($hex,0,2))/255, hexdec(substr($hex,2,2))/255, hexdec(substr($hex,4,2))/255];
    $lin = fn($c) => $c <= .03928 ? $c/12.92 : (($c+.055)/1.055)**2.4;
    return .2126*$lin($r) + .7152*$lin($g) + .0722*$lin($b);
}
$_sb_dark = _sb_luminance($_sb_color) < 0.35; // true = ciemne tło → jasne napisy

// Tokeny kolorów sidebara
if ($_sb_dark) {
    $_sb_text        = '#cbd5e1';   // jasny tekst główny
    $_sb_text_muted  = '#94a3b8';   // wygaszone linki
    $_sb_label_color = '#475569';   // etykiety sekcji
    $_sb_hover_bg    = 'rgba(255,255,255,.07)';
    $_sb_hover_text  = '#e2e8f0';
    $_sb_sub_color   = '#64748b';
    $_sb_sub_hover   = '#cbd5e1';
    $_sb_footer_link = '#94a3b8';
    $_sb_footer_user = '#e2e8f0';
    $_sb_border      = 'rgba(255,255,255,.08)';
    $_sb_brand_color = '#fff';
    $_sb_icon_color  = 'rgba(255,220,0,.9)';
    $_sb_type_open   = '#93c5fd';
} else {
    $_sb_text        = '#1e293b';
    $_sb_text_muted  = '#374151';
    $_sb_label_color = '#6b7280';
    $_sb_hover_bg    = 'rgba(0,0,0,.06)';
    $_sb_hover_text  = '#111827';
    $_sb_sub_color   = '#4b5563';
    $_sb_sub_hover   = '#111827';
    $_sb_footer_link = '#374151';
    $_sb_footer_user = '#111827';
    $_sb_border      = 'rgba(0,0,0,.1)';
    $_sb_brand_color = '#111827';
    $_sb_icon_color  = '#2563eb';
    $_sb_type_open   = '#1d4ed8';
}

function _nav_active(string $needle): string {
    global $_uri;
    return str_contains($_uri, $needle) ? ' active' : '';
}

$_nb_initials = '';
if ($_user) {
    $_nb_initials = implode('', array_map(
        fn($w) => mb_strtoupper(mb_substr($w, 0, 1)),
        array_slice(explode(' ', $_user['name']), 0, 2)
    ));
}

$_bug_report_on = false;
if ($_user) {
    try {
        require_once __DIR__ . '/helpdesk.php';
        helpdesk_migrate();
        $_bug_report_on = org_setting('bug_report_enabled') !== '0';
    } catch (\Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_page_title) ?> — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php $_app_css_path = dirname(__DIR__) . '/assets/css/app.css';
      $_app_css_v = @filemtime($_app_css_path) ?: date('Ymd'); ?>
<link href="<?= APP_URL ?>/assets/css/app.css?v=<?= $_app_css_v ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js" defer></script>
<script src="<?= APP_URL ?>/assets/js/utils.js" defer></script>
<style>
/* ── Layout ───────────────────────────────── */
body { background: #f8fafc; }
#content { padding: 1.5rem 1.5rem 5rem; }

/* ── Navbar ───────────────────────────────── */
#navbar {
    background: <?= h($_sb_color) ?>;
    border-bottom: 1px solid rgba(0,0,0,.12);
    padding: .3rem 1rem;
    position: sticky; top: 0; z-index: 100;
}
.nb-brand {
    display: flex; align-items: center; gap: .55rem;
    text-decoration: none; flex-shrink: 0;
    color: <?= h($_sb_brand_color) ?>;
}
.nb-brand:hover { color: <?= h($_sb_brand_color) ?>; opacity: .88; }
.nb-brand-icon {
    width: 32px; height: 32px; background: rgba(255,255,255,.15);
    border-radius: 8px; display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; flex-shrink: 0; color: <?= h($_sb_icon_color) ?>;
}
.nb-logo-img { height: 30px; width: auto; max-width: 38px; object-fit: contain; border-radius: 4px; }
.nb-brand-name { font-weight: 700; font-size: .9rem; white-space: nowrap; line-height: 1.2; }
.nb-brand-sub { font-size: .59rem; opacity: .6; font-weight: 400; display: block; }

/* Nav links */
#navbar .navbar-nav .nav-link {
    color: <?= h($_sb_text) ?> !important;
    font-size: .82rem; font-weight: 500;
    padding: .3rem .55rem !important; border-radius: 6px;
    transition: background .12s; white-space: nowrap;
    display: flex; align-items: center; gap: .35rem;
}
#navbar .navbar-nav .nav-link:hover,
#navbar .navbar-nav .nav-link.show {
    background: <?= h($_sb_hover_bg) ?>;
    color: <?= h($_sb_hover_text) ?> !important;
}
#navbar .navbar-nav .nav-link.active {
    background: <?= h($_sb_hover_bg) ?>; font-weight: 600;
    color: <?= h($_sb_hover_text) ?> !important;
}
#navbar .navbar-nav .nav-link .badge { font-size: .58rem; margin-left: .2rem; }

/* Dropdowns */
#navbar .dropdown-menu {
    min-width: 220px; border: 1px solid #e2e8f0; border-radius: 10px;
    box-shadow: 0 8px 32px rgba(2,6,23,.14); padding: .3rem .25rem; margin-top: 5px !important;
    font-size: .83rem;
}
#navbar .dropdown-item {
    border-radius: 7px; padding: .37rem .7rem; color: #334155;
    display: flex; align-items: center; gap: .5rem;
}
#navbar .dropdown-item i { font-size: .82rem; width: 16px; text-align: center; color: #94a3b8; flex-shrink: 0; }
#navbar .dropdown-item:hover,
#navbar .dropdown-item:focus { background: #eff6ff; color: #2563eb; }
#navbar .dropdown-item:hover i { color: #2563eb; }
#navbar .dropdown-item.active,
#navbar .dropdown-item:active { background: #eff6ff; color: #2563eb; font-weight: 600; }
#navbar .dropdown-item.active i { color: #2563eb; }
#navbar .dropdown-item .badge { font-size: .58rem; margin-left: auto; }
#navbar .dropdown-divider { margin: .3rem .4rem; }
.nb-section-label {
    font-size: .61rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: #94a3b8; padding: .45rem .7rem .1rem; display: block;
}

/* Right side */
#nb-right { display: flex; align-items: center; gap: .35rem; flex-shrink: 0; margin-left: auto; }

/* User chip */
.nb-user-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22);
    border-radius: 20px; padding: .2rem .6rem .2rem .32rem;
    font-size: .8rem; font-weight: 500; color: <?= h($_sb_text) ?>;
    cursor: pointer; transition: background .14s; text-decoration: none;
}
.nb-user-chip:hover { background: rgba(255,255,255,.22); color: <?= h($_sb_hover_text) ?>; }
.nb-user-chip .avatar {
    width: 22px; height: 22px; border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #6610f2);
    color: #fff; font-size: .6rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
/* Bell / icon buttons in navbar */
.nb-icon-btn {
    background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18);
    border-radius: 7px; color: <?= h($_sb_text) ?>;
    padding: .25rem .45rem; font-size: .88rem; cursor: pointer;
    display: inline-flex; align-items: center; transition: background .12s;
    position: relative;
}
.nb-icon-btn:hover { background: rgba(255,255,255,.22); color: <?= h($_sb_hover_text) ?>; }

/* Search */
.nb-search-wrap { position: relative; }
.nb-search-wrap input {
    width: 32px; height: 28px;
    border: 1px solid rgba(255,255,255,.22); border-radius: 7px;
    background: rgba(255,255,255,.1); padding: 0 .5rem 0 1.7rem;
    font-size: .8rem; outline: none; color: <?= h($_sb_text) ?>; cursor: pointer;
    transition: width .2s, border-color .2s, background .2s;
}
.nb-search-wrap input::placeholder { color: <?= h($_sb_text_muted) ?>; }
.nb-search-wrap input:focus,
.nb-search-wrap input.expanded {
    width: 180px; border-color: rgba(255,255,255,.45);
    background: rgba(255,255,255,.15); cursor: text;
}
.nb-search-icon {
    position: absolute; left: .48rem; top: 50%; transform: translateY(-50%);
    color: <?= h($_sb_text_muted) ?>; font-size: .78rem; pointer-events: none;
}

/* Page title bar */
#page-title-bar {
    background: #fff; border-bottom: 1px solid #e2e8f0;
    padding: .45rem 1.5rem; display: flex; align-items: center; gap: .6rem;
    font-size: .92rem; font-weight: 600; color: #1e293b;
}
#page-title-bar .ptb-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* SaaS / impersonate bars */
#saas-bar {
    background: #7c3aed; color: #fff; padding: .3rem 1.2rem;
    font-size: .76rem; display: flex; align-items: center; gap: .5rem;
}
#saas-bar .saas-bar-slug { opacity: .6; font-size: .7rem; }
#saas-bar .saas-bar-back { color: #ddd8fc; text-decoration: none; font-size: .74rem; }
#saas-bar .saas-bar-back:hover { color: #fff; }
.saas-tenant-bar {
    background: #1e40af; color: #bfdbfe; padding: .25rem 1.2rem;
    font-size: .74rem; display: flex; align-items: center;
}
.saas-tenant-bar strong { color: #fff; }
#impersonate-bar {
    background: #f59e0b; color: #1c1400; padding: .32rem 1.2rem;
    display: flex; align-items: center; gap: .55rem;
    font-size: .82rem; font-weight: 500;
}
#impersonate-bar .imp-name { font-weight: 700; }
#impersonate-bar .imp-email { opacity: .65; font-size: .78rem; }
#impersonate-bar a.imp-stop {
    margin-left: auto; background: rgba(0,0,0,.15); color: #1c1400;
    text-decoration: none; font-weight: 600; font-size: .8rem;
    border-radius: 5px; padding: .2rem .65rem;
    display: inline-flex; align-items: center; gap: .3rem; white-space: nowrap;
}
#impersonate-bar a.imp-stop:hover { background: rgba(0,0,0,.25); }

/* AJAX spinner */
#ajax-spinner {
    display: none; align-items: center; justify-content: center;
    width: 20px; height: 20px; flex-shrink: 0;
}
#ajax-spinner::after {
    content: ''; display: block; width: 12px; height: 12px;
    border: 2px solid rgba(255,255,255,.3); border-top-color: #fff;
    border-radius: 50%; animation: _ajaxSpin .65s linear infinite;
}
@keyframes _ajaxSpin { to { transform: rotate(360deg); } }

/* Offcanvas nav (mobile) */
.oc-link {
    display: flex; align-items: center; gap: .5rem;
    padding: .38rem .85rem; color: #334155; text-decoration: none;
    font-size: .84rem; font-weight: 500; border-radius: 7px;
    transition: background .1s;
}
.oc-link i { font-size: .85rem; width: 17px; text-align: center; color: #94a3b8; flex-shrink: 0; }
.oc-link:hover { background: #eff6ff; color: #2563eb; }
.oc-link:hover i { color: #2563eb; }
.oc-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; }
.oc-link.active i { color: #2563eb; }
.oc-link .badge { font-size: .6rem; margin-left: auto; }
.oc-section { font-size: .61rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; padding: .55rem .85rem .15rem; display: block; }
.oc-sep { height: 1px; background: #f1f5f9; margin: .3rem .6rem; }
.oc-acc-btn {
    display: flex; align-items: center; gap: .5rem; width: 100%;
    padding: .38rem .85rem; color: #334155; font-size: .84rem; font-weight: 500;
    background: none; border: none; border-radius: 7px; text-align: left; cursor: pointer;
    transition: background .1s;
}
.oc-acc-btn i:first-child { font-size: .85rem; width: 17px; text-align: center; color: #94a3b8; flex-shrink: 0; }
.oc-acc-btn:hover { background: #eff6ff; color: #2563eb; }
.oc-acc-btn:not(.collapsed) { color: #2563eb; font-weight: 600; }
.oc-acc-btn .oc-chev { margin-left: auto; font-size: .65rem; opacity: .45; transition: transform .18s; flex-shrink: 0; }
.oc-acc-btn:not(.collapsed) .oc-chev { transform: rotate(90deg); opacity: .8; }
.oc-sub { padding: 0 0 2px 0; }
.oc-sub .oc-link { padding-left: 2.3rem; }
</style>
</head>
<body>
<?= function_exists('ctx_banner_html') ? ctx_banner_html() : '' ?>
<?= function_exists('helpdesk_intro_banner_html') ? helpdesk_intro_banner_html() : '' ?>

<!-- ── NAVBAR ─────────────────────────────────────────────────── -->
<?php
// Inicjały dla wszystkich zalogowanych
$_nb_initials = '';
if ($_user) {
    $_nb_initials = implode('', array_map(
        fn($w) => mb_strtoupper(mb_substr($w, 0, 1)),
        array_slice(explode(' ', $_user['name']), 0, 2)
    ));
}
?>
<nav id="navbar" class="navbar navbar-expand-lg">

<div class="container-fluid px-3 gap-2">

  <!-- Brand -->
  <a class="nb-brand" href="<?= APP_URL ?>/index.php">
    <?php if ($_org_logo && file_exists(dirname(__DIR__) . '/assets/logo/' . $_org_logo)): ?>
    <img src="<?= APP_URL ?>/assets/logo/<?= h($_org_logo) ?>" alt="Logo" class="nb-logo-img">
    <?php else: ?>
    <span class="nb-brand-icon"><i class="bi bi-building"></i></span>
    <?php endif; ?>
    <span>
      <span class="nb-brand-name"><?= h(org_setting('org_short_name') ?: ORG_NAME) ?></span>
      <span class="nb-brand-sub">System zarządzania organizacją</span>
    </span>
  </a>

  <!-- Mobile toggle -->
  <button class="navbar-toggler ms-auto border-0 d-lg-none" type="button"
          data-bs-toggle="offcanvas" data-bs-target="#navOffcanvas"
          aria-controls="navOffcanvas" aria-label="Menu"
          style="color:<?= h($_sb_text) ?>;background:none;padding:.28rem .5rem">
    <i class="bi bi-list" style="font-size:1.4rem"></i>
  </button>

  <!-- Desktop nav -->
  <div class="collapse navbar-collapse">
    <ul class="navbar-nav me-auto align-items-center flex-wrap">

  <?php $_ezd_only = is_ezd_only(); ?>
  <?php if ($_user): ?>

  <?php if ($_ezd_only): ?>
  <!-- ══ WIDOK EZD-ONLY (rola ezd_user) ══════════════════════ -->
  <?php if (module_enabled('ezd_enabled')): ?>
  <?php $_ezd_dd_active = str_contains($_uri, '/ezd/'); ?>
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_ezd_dd_active ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-building-gear"></i> Wirtualne biurko
    </a>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item<?= _nav_active('/ezd/index.php') ?>" href="<?= APP_URL ?>/ezd/index.php"><i class="bi bi-building-gear me-2"></i>Pulpit EZD</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/rpw/') ?>" href="<?= APP_URL ?>/ezd/rpw/index.php"><i class="bi bi-mailbox2 me-2"></i>Dziennik podawczy</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/sprawy/') ?>" href="<?= APP_URL ?>/ezd/sprawy/index.php"><i class="bi bi-folder2-open me-2"></i>Koszulki</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/teczki/') ?>" href="<?= APP_URL ?>/ezd/teczki/index.php"><i class="bi bi-archive me-2"></i>Segregatory aktowe</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/jrwa/') ?>" href="<?= APP_URL ?>/ezd/jrwa/index.php"><i class="bi bi-tags me-2"></i>Wykaz akt (JRWA)</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/pelnomocnictwa/') ?>" href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php"><i class="bi bi-person-vcard me-2"></i>Pełnomocnictwa</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/zaswiadczenia/') ?>" href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php"><i class="bi bi-award me-2"></i>Zaświadczenia</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/wolontariusze/') ?>" href="<?= APP_URL ?>/ezd/wolontariusze/index.php"><i class="bi bi-heart me-2"></i>Wolontariusze bez umowy</a></li>
    </ul>
  </li>
  <?php endif; ?>

  <?php else: ?>

  <?php if (!can_edit()): ?>
  <!-- ══ WIDOK UŻYTKOWNIKA (viewer) ═══════════════════════════ -->
  <?php
  try { require_once __DIR__ . '/org_rules.php'; org_rules_migrate();
    $_unread_rules = count(org_rules_unread((int)($_user['id'] ?? 0)));
  } catch (\Throwable $e) { $_unread_rules = 0; }
  $_pnl_unread = 0;
  if (!empty($_SESSION['panel_contract'])) {
    try { $_pnl_unread = msg_unread_thread('contract', (int)$_SESSION['panel_contract']['id'], 'user'); }
    catch (\Throwable $e) {}
  }
  $_pnl_dd_active = str_contains($_uri, '/panel/') || str_contains($_uri, '/org_intro/') || str_contains($_uri, '/komunikaty/') || str_contains($_uri, '/directory/');
  ?>
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_pnl_dd_active ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-person-circle"></i> Mój panel
      <?php $_pnl_total = $_unread_rules + $_pnl_unread; if ($_pnl_total > 0): ?>
      <span class="badge bg-danger ms-1" style="font-size:.6rem"><?= $_pnl_total ?></span>
      <?php endif; ?>
    </a>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item<?= _nav_active('/panel/index') ?>" href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-person-circle me-2"></i>Moja umowa</a></li>
      <?php if (panel_visible('zasady')): ?>
      <li><a class="dropdown-item<?= _nav_active('/org_intro/') ?>" href="<?= APP_URL ?>/org_intro/index.php"><i class="bi bi-building-heart me-2"></i>Zasady organizacji<?php if ($_unread_rules > 0): ?><span class="badge bg-danger ms-2"><?= $_unread_rules ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/profile_edit') ?>" href="<?= APP_URL ?>/panel/profile_edit.php"><i class="bi bi-person-badge me-2"></i>Mój profil</a></li>
      <?php if (panel_visible('komunikaty')): ?>
      <li><a class="dropdown-item<?= _nav_active('/komunikaty/') ?>" href="<?= APP_URL ?>/komunikaty/index.php"><i class="bi bi-megaphone me-2"></i>Komunikaty</a></li>
      <?php endif; ?>
      <?php if (panel_visible('katalog')): ?>
      <li><a class="dropdown-item<?= _nav_active('/directory/') ?>" href="<?= APP_URL ?>/directory/"><i class="bi bi-person-lines-fill me-2"></i>Książka telefoniczna</a></li>
      <?php endif; ?>
      <?php if (module_enabled('messages_enabled') && panel_visible('wiadomosci')): ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/messages') ?>" href="<?= APP_URL ?>/panel/messages.php"><i class="bi bi-chat-left-text me-2"></i>Wiadomości<?php if ($_pnl_unread): ?><span class="badge bg-danger ms-2"><?= $_pnl_unread ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <?php if (module_enabled('letters_enabled') && panel_visible('pisma')): ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/letters') ?>" href="<?= APP_URL ?>/panel/letters.php"><i class="bi bi-archive me-2"></i>Moje pisma</a></li>
      <?php endif; ?>
      <?php if (panel_visible('wnioski')): ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/apply') ?>" href="<?= APP_URL ?>/panel/apply.php"><i class="bi bi-send me-2"></i>Wyślij pismo / wniosek</a></li>
      <?php endif; ?>
      <?php if (module_enabled('certificates_enabled') && panel_visible('zaswiadczenia')): ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/certificates') ?>" href="<?= APP_URL ?>/panel/certificates.php"><i class="bi bi-award me-2"></i>Zaświadczenia</a></li>
      <?php endif; ?>
      <?php if (module_enabled('terminations_enabled') && panel_visible('rozwiazanie')): ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/terminations') ?>" href="<?= APP_URL ?>/panel/terminations.php"><i class="bi bi-file-earmark-x me-2"></i>Rozwiązanie umowy</a></li>
      <?php endif; ?>
      <?php if (module_enabled('timesheets_enabled') && panel_visible('godziny')): ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/timesheets') ?>" href="<?= APP_URL ?>/panel/timesheets.php"><i class="bi bi-clock-history me-2"></i>Ewidencja godzin</a></li>
      <?php endif; ?>
      <?php if (module_enabled('moodle_enabled') && panel_visible('kursy')): ?>
      <li><a class="dropdown-item<?= _nav_active('/panel/moodle') ?>" href="<?= APP_URL ?>/panel/moodle.php"><i class="bi bi-mortarboard me-2"></i>Moje kursy</a></li>
      <?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item<?= _nav_active('/panel/m365') ?>" href="<?= APP_URL ?>/panel/m365.php"><i class="bi bi-microsoft me-2"></i>Microsoft 365</a></li>
      <li><a class="dropdown-item<?= _nav_active('/panel/sessions') ?>" href="<?= APP_URL ?>/panel/sessions.php"><i class="bi bi-shield-lock me-2"></i>Sesje i bezpieczeństwo</a></li>
      <li><a class="dropdown-item<?= _nav_active('/panel/password') . _nav_active('/panel/2fa') . _nav_active('/panel/m365_password') ?>" href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-gear me-2"></i>Ustawienia konta</a></li>
    </ul>
  </li>

  <?php else: ?>
  <!-- ══ WIDOK EDYTORA / ADMINA ════════════════════════════════ -->
  <?php
  $_on_umowy    = (str_contains($_uri,'/contracts/') && !str_contains($_uri,'/contracts/wolontariat') && !str_contains($_uri,'/contracts/rekrutacja')) || str_contains($_uri,'/contracts/approvals') || str_contains($_uri,'/contracts/letters') || str_contains($_uri,'/admin/terminations');
  $_on_wol_main = str_contains($_uri,'/contracts/wolontariat/') || str_contains($_uri,'/contracts/rekrutacja/') || str_contains($_uri,'/onboarding/') || str_contains($_uri,'/admin/timesheets') || str_contains($_uri,'/admin/certificates');
  $_on_it       = str_contains($_uri, '/it/') || str_contains($_uri, '/tools/');
  $_on_fin      = str_contains($_uri,'/contracts/zwroty') || str_contains($_uri,'/grants/') || str_contains($_uri,'/strategy/') || str_contains($_uri,'/ksiegowosc/') || str_contains($_uri,'/resources/') || str_contains($_uri,'/admin/shipments');
  $_on_rodo_nb  = str_contains($_uri, '/rodo/');
  $_on_admin_nb = str_contains($_uri,'/admin/') && !str_contains($_uri,'/admin/messages') && !str_contains($_uri,'/admin/terminations') && !str_contains($_uri,'/admin/certificates') && !str_contains($_uri,'/admin/timesheets') && !str_contains($_uri,'/admin/shipments') && !str_contains($_uri,'/admin/onboarding');
  $_wiecej_active = str_contains($_uri,'/persons/') || str_contains($_uri,'/crm/') || str_contains($_uri,'/directory/') || str_contains($_uri,'/org/') || str_contains($_uri,'/byli/') || str_contains($_uri,'/helpdesk/') || str_contains($_uri,'/admin/messages') || str_contains($_uri,'/events/') || str_contains($_uri,'/reports/') || str_contains($_uri,'/correspondence/') || str_contains($_uri,'/procedures/') || str_contains($_uri,'/resolutions/') || str_contains($_uri,'/ezd/') || str_contains($_uri,'/dostepnosc/') || str_contains($_uri,'/asysta/') || str_contains($_uri,'/extforms/');

  try { $_msg_unread_total = msg_unread_admin(); } catch(\Exception $e) { $_msg_unread_total = 0; }
  try { require_once __DIR__ . '/termination.php'; $_term_pending = get_pending_terminations_count(); } catch(\Throwable $e) { $_term_pending = 0; }
  try { require_once __DIR__ . '/certificates.php'; $_cert_pending = get_pending_certificates_count(); } catch(\Throwable $e) { $_cert_pending = 0; }
  try { require_once __DIR__ . '/timesheets.php'; $_ts_pending = ts_pending_count(); } catch(\Throwable $e) { $_ts_pending = 0; }
  try { require_once __DIR__ . '/apaczka.php'; require_once __DIR__ . '/furgonetka.php'; $_ship_pending = shipment_pending_count(); $_has_shipping = apaczka_setting('apaczka_enabled') !== '0' || furgonetka_enabled(); } catch(\Throwable $e) { $_ship_pending = 0; $_has_shipping = false; }
  try { $r = db_one("SELECT COUNT(*) AS c FROM zwroty_kosztow WHERE status IN ('oczekuje','weryfikacja')"); $_zwr_pending = (int)($r['c'] ?? 0); } catch(\Throwable $e) { $_zwr_pending = 0; }
  try { $r = db_one("SELECT COUNT(*) AS c FROM volunteer_applications WHERE status = 'new'"); $_rek_new = (int)($r['c'] ?? 0); } catch(\Throwable $e) { $_rek_new = 0; }
  try { $r = db_one("SELECT COUNT(*) AS c FROM onboarding_volunteers WHERE status='pending'"); $_ob_new = (int)($r['c'] ?? 0); } catch(\Throwable $e) { $_ob_new = 0; }
  try { $r = db_one("SELECT COUNT(*) AS c FROM resource_reservations WHERE status IN ('zlozony','pending_admin')"); $_res_badge = (int)($r['c'] ?? 0); } catch(\Throwable $e) { $_res_badge = 0; }
  $_adm_badge = 0;
  try { $r = db_one("SELECT COUNT(*) AS c FROM mail_queue WHERE status='failed'"); $_adm_badge += (int)($r['c'] ?? 0); } catch(\Throwable $e) {}
  try { $r = db_one("SELECT COUNT(*) AS c FROM user_applications WHERE status='nowy'"); $_adm_badge += (int)($r['c'] ?? 0); } catch(\Throwable $e) {}
  $_hd_open = 0;
  try {
    if (module_enabled('helpdesk_enabled')) {
      $_hd_u = current_user();
      if ($_hd_u) {
        if (is_admin() || !empty($_hd_u['helpdesk_operator']))
          $_hd_open = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE status NOT IN ('zamknięte')")['c'] ?? 0);
        else
          $_hd_open = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')", [(int)$_hd_u['id']])['c'] ?? 0);
      }
    }
  } catch (\Throwable $e) {}
  $_alias_is_op   = is_admin() || !empty(current_user()['helpdesk_operator']);
  $_alias_pending = 0;
  if ($_alias_is_op) {
    try { $_alias_pending = (int)(db_one("SELECT COUNT(*) AS c FROM email_alias_requests WHERE status IN ('oczekuje','błąd')")['c'] ?? 0); }
    catch (\Throwable $e) {}
  }
  $_obs_badge = (int)$_hd_open + (int)$_msg_unread_total + ($_alias_is_op ? (int)$_alias_pending : 0);
  $_wol_badge = $_rek_new + $_ob_new + $_ts_pending + $_cert_pending;
  $_fin_badge = $_zwr_pending + $_res_badge + (int)$_ship_pending;
  $_um_badge  = $_pending + $_term_pending;
  require_once __DIR__ . '/ksiegowosc.php'; kdok_migrate();
  $_has_kdok = kdok_has_role('upload') || kdok_has_role('meryt') || kdok_has_role('formal') || kdok_has_role('zatwierdza') || is_admin();
  $_ct_label = fn($s) => CONTRACT_TYPES[$s] ?? ucfirst($s);
  $_ct_icon  = fn($s) => $_contract_icons[$s] ?? 'bi-file-text';
  ?>

  <!-- Umowy -->
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_on_umowy ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-file-text"></i> Umowy
      <?php if ($_um_badge): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem"><?= $_um_badge ?></span><?php endif; ?>
    </a>
    <ul class="dropdown-menu">
      <?php
      $_um_groups = [
        ['Wolontariat',               'bi-heart',             ['wolontariat']],
        ['Zlecenie / Praca / Dzieło',  'bi-person-lines-fill', ['zlecenie','praca','dzielo']],
        ['Inne umowy',                 'bi-file-text',         ['uslugi','powierzenie','inne']],
      ];
      foreach ($_um_groups as [$glabel, $gicon, $slugs]):
        $vis = array_values(array_filter($slugs, fn($s) => module_enabled('contract_' . $s)));
        if (!$vis) continue;
      ?>
      <li><h6 class="dropdown-header nb-section-label"><?= h($glabel) ?></h6></li>
      <?php foreach ($vis as $s): ?>
      <li><a class="dropdown-item<?= _nav_active("/contracts/$s/") ?>" href="<?= APP_URL ?>/contracts/<?= $s ?>/list.php"><i class="bi <?= $_ct_icon($s) ?> me-2"></i><?= h($_ct_label($s)) ?></a></li>
      <?php endforeach; endforeach; ?>

      <?php if (module_enabled('approvals_enabled') || module_enabled('letters_enabled') || module_enabled('terminations_enabled')): ?>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header nb-section-label">Obsługa umów</h6></li>
      <?php if (module_enabled('approvals_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/contracts/approvals/') ?>" href="<?= APP_URL ?>/contracts/approvals/index.php"><i class="bi bi-check2-square me-2"></i>Akceptacje<?php if ($_pending): ?><span class="badge bg-warning text-dark ms-2"><?= $_pending ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <?php if (module_enabled('letters_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/contracts/letters/') ?>" href="<?= APP_URL ?>/contracts/letters/index.php"><i class="bi bi-envelope-paper me-2"></i>Pisma</a></li>
      <?php endif; ?>
      <?php if (module_enabled('terminations_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/admin/terminations') ?>" href="<?= APP_URL ?>/admin/terminations.php"><i class="bi bi-file-earmark-x me-2"></i>Rozwiązania<?php if ($_term_pending): ?><span class="badge bg-danger ms-2"><?= $_term_pending ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <?php endif; ?>
    </ul>
  </li>

  <!-- Wolontariat -->
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_on_wol_main ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-heart"></i> Wolontariat
      <?php if ($_wol_badge): ?><span class="badge bg-primary ms-1" style="font-size:.6rem"><?= $_wol_badge ?></span><?php endif; ?>
    </a>
    <ul class="dropdown-menu">
      <?php if (module_enabled('contract_wolontariat')): ?>
      <li><a class="dropdown-item<?= _nav_active('/contracts/wolontariat/') ?>" href="<?= APP_URL ?>/contracts/wolontariat/list.php"><i class="bi bi-heart me-2"></i>Wolontariusze</a></li>
      <?php endif; ?>
      <li><a class="dropdown-item<?= _nav_active('/contracts/rekrutacja/') ?>" href="<?= APP_URL ?>/contracts/rekrutacja/index.php"><i class="bi bi-megaphone me-2"></i>Zgłoszenia<?php if ($_rek_new): ?><span class="badge bg-primary ms-2"><?= $_rek_new ?></span><?php endif; ?></a></li>
      <?php if (module_enabled('onboarding_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/onboarding/') ?>" href="<?= APP_URL ?>/onboarding/index.php"><i class="bi bi-person-check me-2"></i>Onboarding<?php if ($_ob_new): ?><span class="badge bg-warning text-dark ms-2"><?= $_ob_new ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <?php if (module_enabled('timesheets_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/admin/timesheets') ?>" href="<?= APP_URL ?>/admin/timesheets.php"><i class="bi bi-clock-history me-2"></i>Ewidencja godzin<?php if ($_ts_pending): ?><span class="badge bg-warning text-dark ms-2"><?= $_ts_pending ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <?php if (module_enabled('certificates_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/admin/certificates') . _nav_active('/certificates/') ?>" href="<?= APP_URL ?>/admin/certificates.php"><i class="bi bi-award me-2"></i>Zaświadczenia<?php if ($_cert_pending): ?><span class="badge bg-warning text-dark ms-2"><?= $_cert_pending ?></span><?php endif; ?></a></li>
      <?php endif; ?>
    </ul>
  </li>


  <!-- IT -->
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_on_it ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-hdd-network"></i> IT
    </a>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item<?= _nav_active('/it/index') ?>" href="<?= APP_URL ?>/it/index.php"><i class="bi bi-grid-1x2 me-2"></i>Dashboard IT</a></li>
      <li><a class="dropdown-item<?= _nav_active('/it/accounts') ?>" href="<?= APP_URL ?>/it/accounts.php"><i class="bi bi-person-badge me-2"></i>Konta</a></li>
      <li><a class="dropdown-item<?= _nav_active('/it/passwords') ?>" href="<?= APP_URL ?>/it/passwords.php"><i class="bi bi-key me-2"></i>Hasła</a></li>
      <li><a class="dropdown-item<?= _nav_active('/tools/r2_upload') ?>" href="<?= APP_URL ?>/tools/r2_upload.php"><i class="bi bi-cloud-arrow-up me-2"></i>Upload do R2</a></li>
      <?php if (is_admin()): ?>
      <li><a class="dropdown-item<?= _nav_active('/it/services') ?>" href="<?= APP_URL ?>/it/services.php"><i class="bi bi-gear me-2"></i>Serwisy IT</a></li>
      <?php endif; ?>
    </ul>
  </li>


  <!-- Finanse -->
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_on_fin ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-cash-coin"></i> Finanse
      <?php if ($_fin_badge): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem"><?= $_fin_badge ?></span><?php endif; ?>
    </a>
    <ul class="dropdown-menu">
      <?php if (menu_visible('grants')): ?>
      <li><a class="dropdown-item<?= _nav_active('/grants/') ?>" href="<?= APP_URL ?>/grants/index.php"><i class="bi bi-cash-coin me-2"></i>Granty</a></li>
      <?php endif; ?>
      <?php if (menu_visible('actions')): ?>
      <li><a class="dropdown-item<?= _nav_active('/strategy/actions/') . _nav_active('/actions/') ?>" href="<?= APP_URL ?>/strategy/actions/index.php"><i class="bi bi-calendar-event me-2"></i>Działania</a></li>
      <?php endif; ?>
      <?php if (can_read('umowy') || is_admin()): ?>
      <li><a class="dropdown-item<?= str_contains($_uri,'/strategy/') && !str_contains($_uri,'/strategy/actions/') ? ' active' : '' ?>" href="<?= APP_URL ?>/strategy/index.php"><i class="bi bi-bullseye me-2"></i>Strategia</a></li>
      <?php endif; ?>
      <li><a class="dropdown-item<?= _nav_active('/contracts/zwroty/') ?>" href="<?= APP_URL ?>/contracts/zwroty/index.php"><i class="bi bi-receipt-cutoff me-2"></i>Zwroty kosztów<?php if ($_zwr_pending): ?><span class="badge bg-warning text-dark ms-2"><?= $_zwr_pending ?></span><?php endif; ?></a></li>
      <?php if ($_has_kdok): ?>
      <li><a class="dropdown-item<?= _nav_active('/ksiegowosc/') ?>" href="<?= APP_URL ?>/ksiegowosc/index.php"><i class="bi bi-file-earmark-check me-2"></i>EOD Dok. Księgowych</a></li>
      <?php endif; ?>
      <li><a class="dropdown-item<?= str_contains($_uri,'/resources/') ? ' active' : '' ?>" href="<?= APP_URL ?>/resources/"><i class="bi bi-box-seam me-2"></i>Zasoby<?php if ($_res_badge): ?><span class="badge bg-danger ms-2"><?= $_res_badge ?></span><?php endif; ?></a></li>
      <?php if ($_has_shipping): ?>
      <li><a class="dropdown-item<?= _nav_active('/admin/shipments') ?>" href="<?= APP_URL ?>/admin/shipments.php"><i class="bi bi-truck me-2"></i>Przesyłki<?php if ($_ship_pending): ?><span class="badge bg-warning text-dark ms-2"><?= $_ship_pending ?></span><?php endif; ?></a></li>
      <?php endif; ?>
    </ul>
  </li>

  <!-- RODO -->
  <li class="nav-item">
    <a class="nav-link<?= $_on_rodo_nb ? ' active' : '' ?>" href="<?= APP_URL ?>/rodo/index.php">
      <i class="bi bi-shield-lock"></i> RODO
    </a>
  </li>


  <!-- Więcej -->
  <?php
  $_wiecej_active = str_contains($_uri,'/persons/') || str_contains($_uri,'/crm/') || str_contains($_uri,'/directory/') || str_contains($_uri,'/org/') || str_contains($_uri,'/byli/') || str_contains($_uri,'/helpdesk/') || str_contains($_uri,'/admin/messages') || str_contains($_uri,'/events/') || str_contains($_uri,'/reports/') || str_contains($_uri,'/correspondence/') || str_contains($_uri,'/procedures/') || str_contains($_uri,'/resolutions/') || str_contains($_uri,'/ezd/') || str_contains($_uri,'/dostepnosc/') || str_contains($_uri,'/asysta/') || str_contains($_uri,'/extforms/');
  ?>
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_wiecej_active ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      Więcej
    </a>
    <ul class="dropdown-menu dropdown-menu-end" style="min-width:220px">
      <li><h6 class="dropdown-header nb-section-label">Ludzie</h6></li>
      <li><a class="dropdown-item<?= _nav_active('/persons/') ?>" href="<?= APP_URL ?>/persons/index.php"><i class="bi bi-people me-2"></i>Osoby</a></li>
      <?php if (module_enabled('crm_enabled') && can_read('crm')): ?>
      <li><a class="dropdown-item<?= _nav_active('/crm/') ?>" href="<?= APP_URL ?>/crm/dashboard.php"><i class="bi bi-diagram-2 me-2"></i>CRM</a></li>
      <?php endif; ?>
      <li><a class="dropdown-item<?= _nav_active('/directory/') ?>" href="<?= APP_URL ?>/directory/"><i class="bi bi-person-lines-fill me-2"></i>Katalog osób</a></li>
      <?php if (module_enabled('org_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/org/') ?>" href="<?= APP_URL ?>/org/index.php"><i class="bi bi-diagram-3 me-2"></i>Struktura org.</a></li>
      <?php endif; ?>
      <?php if (module_enabled('byli_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/byli/') ?>" href="<?= APP_URL ?>/byli/index.php"><i class="bi bi-person-dash me-2"></i>Byłe osoby</a></li>
      <?php endif; ?>
      <?php if (module_enabled('helpdesk_enabled') || module_enabled('messages_enabled') || module_enabled('events_enabled')): ?>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header nb-section-label">Obsługa<?php if ($_obs_badge): ?> <span class="badge bg-primary ms-1"><?= $_obs_badge ?></span><?php endif; ?></h6></li>
      <?php if (module_enabled('helpdesk_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/helpdesk/') ?>" href="<?= APP_URL ?>/helpdesk/index.php"><i class="bi bi-ticket-perforated me-2"></i>Helpdesk<?php if ($_hd_open): ?><span class="badge bg-primary ms-2"><?= $_hd_open ?></span><?php endif; ?></a></li>
      <?php if ($_alias_is_op): ?>
      <li><a class="dropdown-item<?= _nav_active('/admin/email_aliasy') ?>" href="<?= APP_URL ?>/admin/email_aliasy.php"><i class="bi bi-at me-2"></i>Aliasy e-mail<?php if ($_alias_pending): ?><span class="badge bg-warning text-dark ms-2"><?= $_alias_pending ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <?php endif; ?>
      <?php if (module_enabled('messages_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/admin/messages') ?>" href="<?= APP_URL ?>/admin/messages.php"><i class="bi bi-chat-dots me-2"></i>Wiadomości<?php if ($_msg_unread_total): ?><span class="badge bg-danger ms-2" data-msg-sb-badge><?= $_msg_unread_total ?></span><?php endif; ?></a></li>
      <?php endif; ?>
      <?php if (module_enabled('events_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/events/') ?>" href="<?= APP_URL ?>/events/dashboard.php"><i class="bi bi-calendar-event me-2"></i>Wydarzenia</a></li>
      <?php endif; ?>
      <?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header nb-section-label">Archiwum i rejestry</h6></li>
      <?php if (module_enabled('reports_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/reports/') ?>" href="<?= APP_URL ?>/reports/index.php"><i class="bi bi-bar-chart-line me-2"></i>Raporty</a></li>
      <?php endif; ?>
      <li><a class="dropdown-item<?= _nav_active('/correspondence/') ?>" href="<?= APP_URL ?>/correspondence/index.php"><i class="bi bi-mailbox me-2"></i>Korespondencja</a></li>
      <li><a class="dropdown-item<?= _nav_active('/procedures/') ?>" href="<?= APP_URL ?>/procedures/index.php"><i class="bi bi-list-task me-2"></i>Procedury</a></li>
      <?php if (module_enabled('doc_signing_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/podpisy/') ?>" href="<?= APP_URL ?>/podpisy/index.php"><i class="bi bi-pen me-2"></i>Podpisz dokument</a></li>
      <?php endif; ?>
      <?php if (module_enabled('org_documents_enabled')): ?>
      <li><a class="dropdown-item<?= _nav_active('/admin/org_documents') . _nav_active('/org_documents/') ?>" href="<?= APP_URL ?>/admin/org_documents.php"><i class="bi bi-folder2-open me-2"></i>Dokumenty organizacji</a></li>
      <?php endif; ?>
      <li><a class="dropdown-item<?= _nav_active('/resolutions/') ?>" href="<?= APP_URL ?>/resolutions/index.php"><i class="bi bi-file-ruled me-2"></i>Uchwały</a></li>
      <?php if (module_enabled('ezd_enabled')): ?>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header nb-section-label">Wirtualne biurko</h6></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/index.php') ?>" href="<?= APP_URL ?>/ezd/index.php"><i class="bi bi-building-gear me-2"></i>Pulpit EZD</a></li>
      <li><a class="dropdown-item<?= _nav_active('/ezd/sprawy/') ?>" href="<?= APP_URL ?>/ezd/sprawy/index.php"><i class="bi bi-folder2-open me-2"></i>Koszulki</a></li>
      <?php endif; ?>
      <?php if ((module_enabled('dostepnosc_ngo_enabled') && !is_viewer()) || (module_enabled('projekty_enabled') && !is_viewer())): ?>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header nb-section-label">Formularze zewnętrzne</h6></li>
      <?php if (module_enabled('dostepnosc_ngo_enabled') && !is_viewer()): ?>
      <li><a class="dropdown-item<?= _nav_active('/dostepnosc/') ?>" href="<?= APP_URL ?>/dostepnosc/admin.php"><i class="bi bi-universal-access me-2"></i>Dostępność NGO</a></li>
      <li><a class="dropdown-item<?= _nav_active('/asysta/') ?>" href="<?= APP_URL ?>/asysta/admin.php"><i class="bi bi-universal-access-circle me-2"></i>Zgłoszenia asysty</a></li>
      <?php endif; ?>
      <?php if (module_enabled('projekty_enabled') && !is_viewer()): ?>
      <li><a class="dropdown-item<?= _nav_active('/extforms/konsultacjeADNGO/') ?>" href="<?= APP_URL ?>/extforms/konsultacjeADNGO/admin.php"><i class="bi bi-clipboard2-pulse me-2"></i>Karty doradztwa ADNGO</a></li>
      <?php endif; ?>
      <?php endif; ?>
    </ul>
  </li>

  <!-- Admin -->
  <?php if (is_admin()): ?>
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle<?= $_on_admin_nb ? ' active' : '' ?>"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-shield-shaded"></i> Admin
      <?php if ($_adm_badge): ?><span class="badge bg-danger ms-1" style="font-size:.6rem"><?= $_adm_badge ?></span><?php endif; ?>
    </a>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item<?= _nav_active('/admin/index') ?>" href="<?= APP_URL ?>/admin/index.php"><i class="bi bi-shield-shaded me-2"></i>Panel admina</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header nb-section-label">Integracje</h6></li>
      <li><a class="dropdown-item<?= _nav_active('/admin/m365') ?>" href="<?= APP_URL ?>/admin/m365_settings.php"><i class="bi bi-microsoft me-2"></i>Microsoft 365</a></li>
      <li><a class="dropdown-item<?= _nav_active('/admin/sharepoint') ?>" href="<?= APP_URL ?>/admin/sharepoint_settings.php"><i class="bi bi-cloud-upload me-2"></i>SharePoint</a></li>
      <li><a class="dropdown-item<?= _nav_active('/admin/tidycal') ?>" href="<?= APP_URL ?>/admin/tidycal_settings.php"><i class="bi bi-calendar2-check me-2"></i>Szkolenia (TidyCal)</a></li>
      <li><a class="dropdown-item<?= _nav_active('/admin/stripe_settings') ?>" href="<?= APP_URL ?>/admin/stripe_settings.php"><i class="bi bi-credit-card me-2"></i>Płatności / Stripe</a></li>
      <li><a class="dropdown-item<?= _nav_active('/admin/payu_settings') ?>" href="<?= APP_URL ?>/admin/payu_settings.php"><i class="bi bi-wallet2 me-2"></i>Płatności / PayU</a></li>
      <li><a class="dropdown-item<?= _nav_active('/admin/api_manage') . _nav_active('/admin/api_keys') . _nav_active('/admin/api_audit') ?>" href="<?= APP_URL ?>/admin/api_manage.php"><i class="bi bi-key me-2"></i>API i webhooki</a></li>
    </ul>
  </li>
  <?php endif; ?>

  <?php endif; /* can_edit */ ?>
  <?php endif; /* ezd_only */ ?>
  <?php endif; /* _user */ ?>

    </ul><!-- /.navbar-nav -->

    <div id="nb-right">
      <span id="ajax-spinner" aria-hidden="true" title="Ładowanie…"></span>

    <?php if ($_user && can_edit()): ?>
    <?php
      $_uri = $_SERVER['REQUEST_URI'] ?? '';
      $_is_panel_view = str_contains($_uri, '/panel/');
      $_initials_tb = implode('', array_map(
        fn($w) => mb_strtoupper(mb_substr($w,0,1)),
        array_slice(explode(' ', $_user['name']), 0, 2)
      ));
      // Oblicz aktywne sekcje raz
      $_on_crm     = str_contains($_uri,'/crm/');
      $_on_tasks   = str_contains($_uri,'/tasks/') && !str_contains($_uri,'/admin/');
      $_on_admin   = str_contains($_uri,'/admin/') && !str_contains($_uri,'/rodo/') && !str_contains($_uri,'/certificates/') && !str_contains($_uri,'/helpdesk/');
      $_on_actions = str_contains($_uri,'/actions/');
      $_on_events  = str_contains($_uri,'/events/');
      $_on_dir     = str_contains($_uri,'/directory/');
      $_on_k30     = str_contains($_uri,'/karty30/');
      $_on_rodo    = str_contains($_uri,'/rodo/');
      $_on_certs   = str_contains($_uri,'/certificates/') || str_contains($_uri,'/admin/certificates');
      $_on_wol     = str_contains($_uri,'/contracts/wolontariat/');
      $_on_szo     = (!$_on_crm && !$_on_actions && !$_on_events && !$_on_dir && !$_on_k30 && !$_on_tasks && !$_on_admin && !$_on_rodo && !$_on_certs && !$_on_wol && !$_is_panel_view);
      $_has_more_active = $_on_actions || $_on_events || $_on_dir || $_on_k30 || $_on_rodo || $_on_certs || $_on_wol;
    ?>
    <!-- ── Waffle switcher modułów ─────────────────────────────── -->
    <?php
    // Aktywny moduł — etykieta i ikona dla przycisku
    $_sw_icon = 'bi-building'; $_sw_label = 'SZO';
    if ($_on_crm)       { $_sw_icon = 'bi-diagram-2-fill';    $_sw_label = 'CRM'; }
    elseif ($_on_tasks)   { $_sw_icon = 'bi-kanban';          $_sw_label = 'Zadania'; }
    elseif ($_on_admin)   { $_sw_icon = 'bi-gear-fill';       $_sw_label = 'Admin'; }
    elseif ($_on_wol)     { $_sw_icon = 'bi-heart-fill';      $_sw_label = 'Wolontariat'; }
    elseif ($_on_dir)     { $_sw_icon = 'bi-person-lines-fill'; $_sw_label = 'Katalog'; }
    elseif ($_on_actions) { $_sw_icon = 'bi-calendar-event';  $_sw_label = 'Działania'; }
    elseif ($_on_events)  { $_sw_icon = 'bi-calendar-event-fill'; $_sw_label = 'Wydarzenia'; }
    elseif ($_on_k30)     { $_sw_icon = 'bi-card-checklist';  $_sw_label = 'Karty 30'; }
    elseif ($_on_certs)   { $_sw_icon = 'bi-award-fill';      $_sw_label = 'Zaświadczenia'; }
    elseif ($_on_rodo)    { $_sw_icon = 'bi-shield-lock-fill'; $_sw_label = 'RODO'; }
    ?>
    <?php
    // ── Elementy launchera (pogrupowane w sekcje) ────────────────────────────
    $_sw_items = [];
    $__add = function (string $label, string $url, string $icon, string $mc, string $mb, bool $on, string $sec) use (&$_sw_items) {
        $_sw_items[] = ['label'=>$label,'url'=>$url,'icon'=>$icon,'mc'=>$mc,'mb'=>$mb,'on'=>$on,'sec'=>$sec];
    };
    // Praca i umowy
    $__add('SZO',           APP_URL.'/index.php',                      'bi-building',          '#2563eb','#eff6ff', $_on_szo,     'Praca i umowy');
    $__add('Wolontariusze', APP_URL.'/contracts/wolontariat/list.php', 'bi-heart-fill',        '#e11d48','#fff1f2', $_on_wol,     'Praca i umowy');
    $__add('Działania',     APP_URL.'/strategy/actions/index.php',     'bi-calendar-event',    '#0891b2','#ecfeff', $_on_actions, 'Praca i umowy');
    $__add('Granty',        APP_URL.'/grants/index.php',               'bi-cash-coin',         '#15803d','#f0fdf4', str_contains($_uri,'/grants/'), 'Praca i umowy');
    $__add('Strategia',     APP_URL.'/strategy/index.php',             'bi-bullseye',          '#7c3aed','#f5f3ff', (str_contains($_uri,'/strategy/') && !str_contains($_uri,'/strategy/actions/')), 'Praca i umowy');
    $__add('Raporty',       APP_URL.'/reports/index.php',              'bi-bar-chart-line',    '#0284c7','#f0f9ff', str_contains($_uri,'/reports/'), 'Praca i umowy');
    if (module_enabled('events_enabled'))
        $__add('Wydarzenia', APP_URL.'/events/dashboard.php',          'bi-calendar-event-fill','#7c3aed','#f5f3ff', str_contains($_uri,'/events/'), 'Praca i umowy');
    // Relacje i ludzie
    if (module_enabled('crm_enabled') && can_read('crm'))
        $__add('CRM',       APP_URL.'/crm/dashboard.php',              'bi-diagram-2-fill',    '#16a34a','#f0fdf4', $_on_crm,     'Relacje i ludzie');
    $__add('Katalog',       APP_URL.'/directory/',                     'bi-person-lines-fill', '#4338ca','#eef2ff', $_on_dir,     'Relacje i ludzie');
    // Dydaktyka
    if (can_read('karty30')) {
        $__add('Karty 30',  APP_URL.'/karty30/index.php',              'bi-card-checklist',    '#6d28d9','#f5f3ff', $_on_k30,     'Dydaktyka');
    } else {
        $_dyd_show = false;
        if ($_u = current_user()) { try { $_dyd_show = !empty(db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)$_u['id']])['k30_consultant']); } catch (\Throwable $e) {} }
        if ($_dyd_show)
            $__add('Dydaktyka', APP_URL.'/karty30/ti/dydaktyk/index.php', 'bi-easel2',         '#2563eb','#eff6ff', str_contains($_uri,'/karty30/ti/dydaktyk'), 'Dydaktyka');
    }
    // Obsługa i zgłoszenia
    if (module_enabled('tasks_enabled'))
        $__add('Zadania',   APP_URL.'/tasks/dashboard.php',            'bi-kanban',            '#ea580c','#fff7ed', $_on_tasks,   'Obsługa i zgłoszenia');
    if (module_enabled('helpdesk_enabled'))
        $__add('Helpdesk',  APP_URL.'/helpdesk/index.php',             'bi-ticket-perforated', '#b45309','#fffbeb', str_contains($_uri,'/helpdesk/'), 'Obsługa i zgłoszenia');
    $__add('RODO',          APP_URL.'/rodo/index.php',                 'bi-shield-lock',       '#475569','#f8fafc', $_on_rodo,    'Obsługa i zgłoszenia');
    // Administracja
    if (is_admin())
        $__add('Admin',     APP_URL.'/admin/index.php',                'bi-gear-fill',         '#1e293b','#f1f5f9', $_on_admin,   'Administracja');
    ?>
    <div id="mod-sw">
      <button type="button" class="mod-sw-btn" id="fl-trigger"
              aria-haspopup="dialog" aria-expanded="false"
              aria-label="Przełącz moduł — aktualnie: <?= h($_sw_label) ?>">
        <i class="bi <?= $_sw_icon ?>"></i>
        <span class="mod-sw-cur"><?= h($_sw_label) ?></span>
        <i class="bi bi-chevron-down" style="font-size:.55rem;opacity:.45;margin-left:.05rem"></i>
      </button>
    </div><!-- /#mod-sw -->
    <div id="fl-root" hidden></div>
    <script>window.__feerLauncher = <?= json_encode(['appUrl'=>APP_URL, 'items'=>$_sw_items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;</script>
    <style>
    /* ── Trigger (waffle) ─────────────────────────────────────── */
    .mod-sw-btn {
      display:inline-flex;align-items:center;gap:.3rem;
      background:rgba(255,255,255,.14);
      border:1px solid rgba(255,255,255,.22);
      border-bottom:2px solid <?= h($_sb_icon_color) ?>;
      border-radius:8px 8px 4px 4px;
      padding:.28rem .6rem .22rem;font-size:.8rem;font-weight:600;color:<?= h($_sb_text) ?>;
      cursor:pointer;line-height:1.4;transition:all .12s;white-space:nowrap;flex-shrink:0;
    }
    .mod-sw-btn:hover,.mod-sw-btn[aria-expanded="true"] {
      background:rgba(255,255,255,.22);color:<?= h($_sb_hover_text) ?>;
      border-bottom-color:<?= h($_sb_icon_color) ?>;
    }
    .mod-sw-cur { max-width:80px;overflow:hidden;text-overflow:ellipsis; }

    /* ── Launcher (3 układy: lista / szuflada / pełny ekran) ──── */
    #fl-root *{box-sizing:border-box}
    .fl-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:4000;animation:flFade .14s ease both}
    @keyframes flFade{from{opacity:0}to{opacity:1}}
    @keyframes flPop{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
    @keyframes flSlide{from{transform:translateX(100%)}to{transform:none}}
    /* wspólne: nagłówek, przełącznik układu, wyszukiwarka, sekcje, elementy */
    .fl-head{display:flex;align-items:center;gap:.5rem;padding:.7rem .85rem;border-bottom:1px solid #eef2f7}
    .fl-title{font-size:.8rem;font-weight:800;color:#0f172a;flex:1;letter-spacing:-.01em}
    .fl-switch{display:inline-flex;background:#f1f5f9;border-radius:8px;padding:2px;gap:2px}
    .fl-sw-btn{border:0;background:none;width:26px;height:24px;border-radius:6px;color:#64748b;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.8rem}
    .fl-sw-btn:hover{color:#1d4ed8}
    .fl-sw-on{background:#fff;color:#1d4ed8;box-shadow:0 1px 2px rgba(2,6,23,.12)}
    .fl-close{border:0;background:none;color:#94a3b8;cursor:pointer;font-size:.95rem;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center}
    .fl-close:hover{background:#f1f5f9;color:#0f172a}
    .fl-search{position:relative;padding:.6rem .85rem .25rem}
    .fl-search .bi{position:absolute;left:1.45rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;pointer-events:none}
    .fl-search input{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:.5rem .7rem .5rem 2rem;font-size:.86rem;outline:none}
    .fl-search input:focus{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
    .fl-scroll{overflow-y:auto}
    .fl-sec-head{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;padding:.55rem .9rem .2rem}
    .fl-list{display:flex;flex-direction:column;padding:0 .45rem .15rem}
    .fl-item{display:flex;align-items:center;gap:.6rem;padding:.45rem .55rem;border-radius:9px;text-decoration:none;color:#374151;transition:background .1s,color .1s}
    .fl-item:hover{background:#f8fafc;color:#0f172a}
    .fl-on{background:#eff6ff;color:#1d4ed8}
    .fl-ic{width:34px;height:34px;border-radius:9px;background:var(--mb,#f8fafc);color:var(--mc,#64748b);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
    .fl-label{font-size:.84rem;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .fl-cur-badge{font-size:.6rem;font-weight:700;color:#1d4ed8;background:#dbeafe;border-radius:20px;padding:.1rem .45rem}
    .fl-foot{padding:.45rem .85rem .6rem;border-top:1px solid #f1f5f9}
    .fl-foot a{font-size:.74rem;color:#64748b;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem}
    .fl-foot a:hover{color:#0f172a}
    .fl-empty{padding:1.1rem .9rem;color:#94a3b8;font-size:.82rem;text-align:center}
    /* układ: lista (dropdown pod przyciskiem) */
    .fl-mode-list .fl-pop{position:fixed;z-index:4001;width:300px;max-width:calc(100vw - 16px);
      background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 50px rgba(2,6,23,.22);
      overflow:hidden;animation:flPop .15s ease both;display:flex;flex-direction:column;max-height:80vh}
    .fl-mode-list .fl-scroll{max-height:60vh}
    /* układ: szuflada (prawa krawędź) */
    .fl-mode-drawer .fl-drawer{position:fixed;top:0;right:0;bottom:0;z-index:4001;width:340px;max-width:90vw;
      background:#fff;box-shadow:-12px 0 40px rgba(2,6,23,.22);display:flex;flex-direction:column;animation:flSlide .2s cubic-bezier(.16,.84,.44,1) both}
    .fl-mode-drawer .fl-scroll{flex:1}
    /* układ: pełny ekran */
    .fl-mode-overlay .fl-overlay-inner{position:fixed;inset:0;z-index:4001;display:flex;flex-direction:column;
      max-width:760px;margin:0 auto;background:#fff;animation:flPop .16s ease both}
    .fl-mode-overlay .fl-scroll{flex:1;padding-bottom:1rem}
    .fl-mode-overlay .fl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.5rem;padding:.3rem .9rem .2rem}
    .fl-mode-overlay .fl-item-big{flex-direction:column;align-items:flex-start;gap:.45rem;padding:.85rem;border:1px solid #eef2f7;min-height:96px}
    .fl-mode-overlay .fl-item-big .fl-ic{width:42px;height:42px;font-size:1.2rem}
    .fl-mode-overlay .fl-item-big .fl-label{font-size:.9rem;flex:0}
    .fl-mode-overlay .fl-title{font-size:1.05rem}
    @media(min-width:761px){.fl-mode-overlay .fl-overlay-inner{inset:0 auto 0 50%;transform:translateX(-50%);box-shadow:0 0 80px rgba(2,6,23,.3)}}
    @media(prefers-reduced-motion:reduce){.fl-backdrop,.fl-pop,.fl-drawer,.fl-overlay-inner{animation:none!important}}
    </style>
    <script>
    (function(){
      var cfg = window.__feerLauncher; if(!cfg) return;
      var trigger = document.getElementById('fl-trigger');
      var root    = document.getElementById('fl-root');
      if(!trigger || !root) return;
      if (root.parentNode !== document.body) document.body.appendChild(root); // fixed pozycjonowanie
      var KEY='feerLauncherLayout', VALID=['list','drawer','overlay'];
      var layout = localStorage.getItem(KEY); if(VALID.indexOf(layout)<0) layout='list';
      var isOpen=false, query='';

      function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
      function sections(){var order=[],map={};cfg.items.forEach(function(it){if(!map[it.sec]){map[it.sec]=[];order.push(it.sec);}map[it.sec].push(it);});return order.map(function(s){return{name:s,items:map[s]};});}
      function match(it){return !query || (it.label||'').toLowerCase().indexOf(query.toLowerCase())>=0;}
      function itemHTML(it,big){
        return '<a class="fl-item'+(big?' fl-item-big':'')+(it.on?' fl-on':'')+'" href="'+esc(it.url)+'">'
          +'<span class="fl-ic" style="--mc:'+esc(it.mc)+';--mb:'+esc(it.mb)+'"><i class="bi '+esc(it.icon)+'"></i></span>'
          +'<span class="fl-label">'+esc(it.label)+'</span>'
          +(it.on?'<span class="fl-cur-badge">Tutaj</span>':'')+'</a>';
      }
      function bodyHTML(big){
        var html='';
        sections().forEach(function(s){
          var items=s.items.filter(match); if(!items.length) return;
          html+='<div class="fl-sec-head">'+esc(s.name)+'</div><div class="'+(big?'fl-grid':'fl-list')+'">';
          items.forEach(function(it){html+=itemHTML(it,big);});
          html+='</div>';
        });
        return html || '<div class="fl-empty">Brak modułów pasujących do „'+esc(query)+'”.</div>';
      }
      function switchHTML(){
        function b(k,icon,t){return '<button type="button" class="fl-sw-btn'+(layout===k?' fl-sw-on':'')+'" data-layout="'+k+'" title="'+t+'" aria-label="'+t+'"><i class="bi '+icon+'"></i></button>';}
        return '<div class="fl-switch" role="group" aria-label="Układ launchera">'
          +b('list','bi-list-ul','Lista')+b('drawer','bi-layout-sidebar-inset-reverse','Szuflada')+b('overlay','bi-fullscreen','Pełny ekran')+'</div>';
      }
      function headHTML(){return '<div class="fl-head"><span class="fl-title">Moduły</span>'+switchHTML()+'<button type="button" class="fl-close" id="fl-close" aria-label="Zamknij"><i class="bi bi-x-lg"></i></button></div>';}
      function searchHTML(){return '<div class="fl-search"><i class="bi bi-search"></i><input type="search" id="fl-q" placeholder="Szukaj modułu…" autocomplete="off" value="'+esc(query)+'"></div>';}
      function footHTML(){return '<div class="fl-foot"><a href="'+esc(cfg.appUrl)+'/portal.php"><i class="bi bi-grid-3x3-gap"></i> Portal — wszystkie moduły</a></div>';}

      function render(){
        root.className='fl-mode-'+layout;
        var big = (layout==='overlay');
        if(layout==='list'){
          root.innerHTML='<div class="fl-pop" role="dialog" aria-label="Wybór modułu">'+headHTML()+'<div class="fl-scroll">'+bodyHTML(false)+'</div>'+footHTML()+'</div>';
          position();
        } else if(layout==='drawer'){
          root.innerHTML='<div class="fl-backdrop" data-close="1"></div><div class="fl-drawer" role="dialog" aria-modal="true" aria-label="Wybór modułu">'+headHTML()+searchHTML()+'<div class="fl-scroll">'+bodyHTML(false)+'</div>'+footHTML()+'</div>';
        } else {
          root.innerHTML='<div class="fl-backdrop" data-close="1"></div><div class="fl-overlay-inner" role="dialog" aria-modal="true" aria-label="Wybór modułu">'+headHTML()+searchHTML()+'<div class="fl-scroll">'+bodyHTML(true)+'</div>'+footHTML()+'</div>';
        }
        bind(big);
      }
      function position(){
        var pop=root.querySelector('.fl-pop'); if(!pop) return;
        var r=trigger.getBoundingClientRect();
        pop.style.top=(r.bottom+6)+'px';
        var w=pop.offsetWidth||300, left=r.left;
        if(left+w>window.innerWidth-8) left=window.innerWidth-8-w;
        pop.style.left=Math.max(8,left)+'px';
      }
      function bind(big){
        root.querySelectorAll('.fl-sw-btn').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();layout=b.getAttribute('data-layout');localStorage.setItem(KEY,layout);render();});});
        var c=document.getElementById('fl-close'); if(c)c.addEventListener('click',close);
        root.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',close);});
        var q=document.getElementById('fl-q');
        if(q){
          q.addEventListener('input',function(){query=q.value;var sc=root.querySelector('.fl-scroll');if(sc)sc.innerHTML=bodyHTML(big);});
          setTimeout(function(){q.focus();},30);
        }
      }
      function openL(){isOpen=true;root.hidden=false;trigger.setAttribute('aria-expanded','true');query='';render();document.addEventListener('keydown',onKey);document.addEventListener('click',onDoc,true);window.addEventListener('resize',position);}
      function close(){isOpen=false;root.hidden=true;root.innerHTML='';trigger.setAttribute('aria-expanded','false');document.removeEventListener('keydown',onKey);document.removeEventListener('click',onDoc,true);window.removeEventListener('resize',position);}
      function onKey(e){if(e.key==='Escape')close();}
      function onDoc(e){if(layout!=='list')return;if(root.contains(e.target)||trigger.contains(e.target))return;close();}
      trigger.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();isOpen?close():openL();});
    })();
    </script>
      <!-- Szukajka — w ramach nawigacji modułów -->
      <div class="nb-search-wrap d-none d-md-block" id="qs-wrap">
      <i class="nb-search-icon bi bi-search" aria-hidden="true"></i>
      <form method="get" action="<?= APP_URL ?>/search.php" id="qs-form" autocomplete="off">
        <input type="search" name="q" id="topbar-search"
               placeholder="Szukaj modułu lub danych… (Ctrl+K)"
               autocomplete="off"
               aria-label="Szukajka modułów i danych"
               aria-expanded="false"
               aria-controls="qs-dropdown"
               aria-autocomplete="list"
               style="padding-left:1.75rem"
               onfocus="this.classList.add('expanded')"
               onblur="if(!this.value)this.classList.remove('expanded')">
      </form>
      <!-- Dropdown wyników -->
      <div id="qs-dropdown"
           role="listbox"
           aria-label="Wyniki wyszukiwania"
           style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;
                  background:#fff;border:1px solid #E2E8F0;border-radius:10px;
                  box-shadow:0 8px 32px rgba(0,0,0,.14);z-index:2000;
                  max-height:420px;overflow-y:auto;font-family:system-ui,sans-serif">
      </div>
    </div>
    <style>
    #qs-dropdown .qs-section-head {
      font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
      color:#94A3B8;padding:.55rem .85rem .2rem;
    }
    #qs-dropdown .qs-item {
      display:flex;align-items:center;gap:.6rem;
      padding:.5rem .85rem;cursor:pointer;text-decoration:none;color:#1E293B;
      border-radius:0;transition:background .08s;
    }
    #qs-dropdown .qs-item:hover,
    #qs-dropdown .qs-item.qs-active { background:#F1F5F9; }
    #qs-dropdown .qs-item .qs-icon {
      width:26px;height:26px;border-radius:6px;flex-shrink:0;
      display:flex;align-items:center;justify-content:center;font-size:.85rem;
    }
    #qs-dropdown .qs-item .qs-label { font-size:.84rem;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
    #qs-dropdown .qs-item .qs-sub  { font-size:.73rem;color:#6B7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
    #qs-dropdown .qs-item .qs-badge{ font-size:.65rem;flex-shrink:0; }
    #qs-dropdown .qs-footer {
      font-size:.75rem;text-align:center;padding:.45rem;border-top:1px solid #F1F5F9;
      color:#94A3B8;
    }
    #qs-dropdown .qs-footer a { color:#3B82F6;text-decoration:none; }
    #qs-dropdown .qs-footer a:hover { text-decoration:underline; }
    </style>
    <script>
    (function(){
      var inp    = document.getElementById('topbar-search');
      var wrap   = document.getElementById('qs-wrap'); // nb-search-wrap
      var drop   = document.getElementById('qs-dropdown');
      var active = -1;
      var items  = [];
      var timer  = null;
      var API    = '<?= APP_URL ?>/api/search_quick.php';
      var SRCH   = '<?= APP_URL ?>/search.php';

      var colorMap = {
        success:'#16A34A',primary:'#2563EB',warning:'#D97706',
        danger:'#DC2626',info:'#0891B2',secondary:'#6B7280',dark:'#1E293B'
      };
      var bgMap = {
        success:'#F0FDF4',primary:'#EFF6FF',warning:'#FFFBEB',
        danger:'#FEF2F2',info:'#F0F9FF',secondary:'#F8FAFC',dark:'#F1F5F9'
      };

      function buildItem(data, isModule) {
        var a = document.createElement('a');
        a.href = data.url;
        a.className = 'qs-item';
        a.setAttribute('role','option');
        var color = colorMap[data.color] || '#6B7280';
        var bg    = bgMap[data.color]   || '#F8FAFC';
        var body  = '<div class="qs-icon" style="background:' + bg + ';color:' + color + '">'
                  + '<i class="bi ' + data.icon + '"></i></div>'
                  + '<div style="flex:1;min-width:0">'
                  + '<div class="qs-label">' + esc(data.label) + '</div>';
        if (data.sub) body += '<div class="qs-sub">' + esc(data.sub) + '</div>';
        body += '</div>';
        if (data.badge) body += '<span class="badge qs-badge" style="background:' + bg + ';color:' + color + ';border:1px solid ' + color + '40">' + esc(data.badge) + '</span>';
        a.innerHTML = body;
        return a;
      }

      function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }

      function render(data) {
        drop.innerHTML = '';
        items = [];
        var any = false;

        if (data.modules && data.modules.length) {
          any = true;
          var h = document.createElement('div');
          h.className = 'qs-section-head';
          h.textContent = 'Moduły';
          drop.appendChild(h);
          data.modules.forEach(function(m) {
            var el = buildItem(m, true);
            drop.appendChild(el);
            items.push(el);
          });
        }

        if (data.results && data.results.length) {
          any = true;
          var h2 = document.createElement('div');
          h2.className = 'qs-section-head';
          h2.textContent = 'Wyniki';
          drop.appendChild(h2);
          data.results.forEach(function(r) {
            var el = buildItem(r, false);
            drop.appendChild(el);
            items.push(el);
          });
        }

        if (any) {
          var foot = document.createElement('div');
          foot.className = 'qs-footer';
          foot.innerHTML = '<a href="' + SRCH + '?q=' + encodeURIComponent(data.query) + '">Pełne wyniki wyszukiwania →</a>';
          drop.appendChild(foot);
        } else {
          drop.innerHTML = '<div style="padding:.75rem .85rem;font-size:.84rem;color:#6B7280">Brak wyników dla <strong>' + esc(data.query) + '</strong></div>';
        }

        active = -1;
        show();
      }

      function show() { drop.style.display = ''; inp.setAttribute('aria-expanded','true'); }
      function hide() { drop.style.display = 'none'; inp.setAttribute('aria-expanded','false'); active = -1; }

      function setActive(i) {
        items.forEach(function(el,j){ el.classList.toggle('qs-active', j===i); });
        active = i;
      }

      inp.addEventListener('input', function() {
        var q = this.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { hide(); return; }
        timer = setTimeout(function() {
          fetch(API + '?q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(render)
            .catch(function(){ hide(); });
        }, 160);
      });

      inp.addEventListener('keydown', function(e) {
        if (drop.style.display === 'none') return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(active+1, items.length-1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(active-1, 0)); }
        else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); items[active].click(); }
        else if (e.key === 'Escape') { hide(); inp.blur(); }
      });

      document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) hide();
      });
    })();
    </script>
    <button type="button"
            id="shortcuts-hint"
            onclick="document.dispatchEvent(new KeyboardEvent('keydown',{key:'?',bubbles:true}))"
            title="Skróty klawiaturowe (?)"
            aria-label="Skróty klawiaturowe"
            class="nb-icon-btn">
      <kbd style="background:none;border:none;padding:0;font-size:.72rem;color:inherit;font-family:inherit">?</kbd>
    </button>
    <?php endif; // can_edit ?>

    <?php
    // Przycisk zgłoszenia błędu — widoczny dla wszystkich zalogowanych, gdy moduł aktywny
    $_bug_report_on = false;
    if ($_user) {
        try {
            require_once __DIR__ . '/helpdesk.php';
            helpdesk_migrate();
            $_bug_report_on = org_setting('bug_report_enabled') !== '0';
        } catch (\Throwable $e) {}
    }
    ?>
    <?php if ($_user && $_bug_report_on): ?>
    <button type="button"
            data-bs-toggle="modal" data-bs-target="#bugReportModal"
            title="Zgłoś błąd na tej stronie"
            aria-label="Zgłoś błąd"
            class="nb-icon-btn"
            style="border-color:#fca5a5;color:#dc2626">
      <i class="bi bi-bug-fill"></i>
      <span class="d-none d-sm-inline" style="font-size:.78rem">Zgłoś błąd</span>
    </button>
    <?php endif; ?>

    <?php if ($_user):
    $_notif_count  = notif_unread_count((int)$_user['id']);
    $_notif_latest = notif_latest((int)$_user['id'], 6);
    ?>
    <div class="dropdown me-2" id="notif-bell">
      <button type="button" class="nb-icon-btn position-relative"
              data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
              aria-label="Powiadomienia — <?= $_notif_count ?> nieprzeczytanych"
              id="notif-btn">
        <i class="bi bi-bell-fill"></i>
        <?php if ($_notif_count > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
              style="font-size:.55rem" aria-hidden="true">
          <?= $_notif_count > 99 ? '99+' : $_notif_count ?>
        </span>
        <?php endif; ?>
      </button>
      <div class="dropdown-menu dropdown-menu-end shadow" style="width:320px;max-height:400px;overflow-y:auto" role="menu" aria-label="Lista powiadomień">
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
          <span class="fw-semibold" style="font-size:.85rem">Powiadomienia</span>
          <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-link btn-sm p-0" style="font-size:.75rem">Wszystkie</a>
            <?php if ($_notif_count > 0): ?>
            <button type="button" class="btn btn-link btn-sm p-0 text-muted" style="font-size:.75rem" id="notif-mark-all">Oznacz przeczytane</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if (empty($_notif_latest)): ?>
        <div class="text-center py-4 text-muted" style="font-size:.82rem">
          <i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:.3"></i>
          Brak powiadomień
        </div>
        <?php else: ?>
        <?php foreach ($_notif_latest as $notif): ?>
        <a href="<?= h($notif['url'] ?: APP_URL . '/komunikaty/index.php') ?>"
           class="dropdown-item py-2 px-3 <?= $notif['is_read'] ? '' : 'fw-semibold' ?>"
           style="white-space:normal;font-size:.82rem;border-bottom:1px solid #f1f5f9"
           data-notif-id="<?= (int)$notif['id'] ?>">
          <div class="d-flex gap-2 align-items-start">
            <i class="bi <?= notif_type_icon($notif['type']) ?> mt-1 flex-shrink-0"
               style="color:<?= notif_type_color($notif['type']) ?>;font-size:.9rem" aria-hidden="true"></i>
            <div class="flex-grow-1">
              <div><?= h($notif['title']) ?></div>
              <div class="text-muted fw-normal" style="font-size:.74rem"><?= h(substr($notif['created_at'], 0, 16)) ?></div>
            </div>
            <?php if (!$notif['is_read']): ?>
            <span class="rounded-circle bg-primary flex-shrink-0" style="width:7px;height:7px;margin-top:5px" aria-hidden="true"></span>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
        <div class="px-3 py-2 border-top">
          <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-sm w-100" style="background:var(--sb-hover-bg,#f1f5f9);color:#374151;font-size:.8rem">
            <i class="bi bi-envelope-open me-1"></i>Przejdź do komunikatów
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($_user): ?>
    <!-- Chip użytkownika z dropdown -->
    <div class="dropdown">
      <button class="nb-user-chip" type="button" data-bs-toggle="dropdown">
        <span class="avatar"><?= h($_nb_initials ?: mb_strtoupper(mb_substr($_user['name'],0,1))) ?></span>
        <span class="d-none d-sm-inline"><?= h(explode(' ', $_user['name'])[0]) ?></span>
        <i class="bi bi-chevron-down" style="font-size:.65rem;opacity:.6"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:230px;font-size:.85rem">

        <!-- Dane użytkownika -->
        <li class="px-3 py-2 border-bottom">
          <div class="fw-semibold"><?= h($_user['name']) ?></div>
          <div class="text-muted" style="font-size:.74rem"><?= h($_user['email']) ?></div>
          <span class="badge bg-light text-dark border mt-1" style="font-size:.67rem"><?= h($_user['role']) ?></span>
        </li>

        <!-- Konto -->
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/index.php">
            <i class="bi bi-person-circle me-2 text-muted"></i>Mój panel
          </a>
        </li>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/m365.php">
            <i class="bi bi-microsoft me-2 text-muted"></i>Microsoft 365
          </a>
        </li>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/sessions.php">
            <i class="bi bi-shield-lock me-2 text-muted"></i>Sesje
          </a>
        </li>
        <?php if (in_array($_user['role'] ?? '', ['admin', 'editor'], true)): ?>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/webauthn.php">
            <i class="bi bi-usb-symbol me-2 text-muted"></i>Klucze WebAuthn
          </a>
        </li>
        <?php endif; ?>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/password.php">
            <i class="bi bi-gear me-2 text-muted"></i>Ustawienia konta
          </a>
        </li>

        <?php if ($_user && can_edit()): ?>
        <li><hr class="dropdown-divider my-1"></li>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/admin/users.php">
            <i class="bi bi-people me-2 text-muted"></i>Użytkownicy
          </a>
        </li>
        <?php if (is_admin()): ?>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/admin/prod_check.php">
            <i class="bi bi-clipboard2-check me-2 text-success"></i>Lista kontrolna wdrożenia
          </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <li><hr class="dropdown-divider my-1"></li>
        <li>
          <button type="button" class="dropdown-item py-2" id="a11y-spinner-toggle">
            <i class="bi bi-eye-slash me-2 text-muted" id="a11y-spinner-icon" aria-hidden="true"></i>
            <span id="a11y-spinner-lbl">Wyłącz animacje ładowania</span>
          </button>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <li>
          <a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php">
            <i class="bi bi-box-arrow-right me-2"></i>Wyloguj się
          </a>
        </li>
      </ul>
    </div>
    <?php endif; ?>
    </div><!-- /#nb-right -->
  </div><!-- /.navbar-collapse -->
</div><!-- /.container-fluid -->
</nav><!-- /#navbar -->

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="navOffcanvas"
     aria-labelledby="navOffcanvasLabel"
     style="background:<?= h($_sb_color) ?>;color:<?= h($_sb_text) ?>">
  <div class="offcanvas-header border-bottom" style="border-color:<?= $_sb_dark ? 'rgba(255,255,255,.15)' : 'rgba(0,0,0,.12)' ?>!important">
    <span id="navOffcanvasLabel" class="nb-brand-name" style="color:<?= h($_sb_text) ?>"><?= h(org_setting('org_short_name') ?: ORG_NAME) ?></span>
    <button type="button" class="btn-close<?= $_sb_dark ? ' btn-close-white' : '' ?>" data-bs-dismiss="offcanvas" aria-label="Zamknij"></button>
  </div>
  <div class="offcanvas-body p-2" style="overflow-y:auto">
    <?php if ($_user): ?>
    <div class="px-2 py-2 mb-1" style="font-size:.8rem;opacity:.7"><?= h($_user['name']) ?> · <a href="<?= APP_URL ?>/auth/logout.php" style="color:inherit">Wyloguj</a></div>
    <?php endif; ?>
    <a class="oc-link<?= _nav_active('/index') ?>" href="<?= APP_URL ?>/index.php"><i class="bi bi-house me-2"></i>Strona główna</a>
    <?php if ($_user && !is_ezd_only() && can_edit()): ?>
    <div class="oc-section">Umowy</div>
    <a class="oc-link<?= str_contains($_uri,'/contracts/') ? ' active' : '' ?>" href="<?= APP_URL ?>/contracts/wolontariat/list.php"><i class="bi bi-file-text me-2"></i>Umowy</a>
    <a class="oc-link<?= str_contains($_uri,'/contracts/rekrutacja/') ? ' active' : '' ?>" href="<?= APP_URL ?>/contracts/rekrutacja/index.php"><i class="bi bi-megaphone me-2"></i>Zgłoszenia</a>
    <div class="oc-section">Finanse</div>
    <a class="oc-link<?= _nav_active('/grants/') ?>" href="<?= APP_URL ?>/grants/index.php"><i class="bi bi-cash-coin me-2"></i>Granty</a>
    <a class="oc-link<?= _nav_active('/contracts/zwroty/') ?>" href="<?= APP_URL ?>/contracts/zwroty/index.php"><i class="bi bi-receipt-cutoff me-2"></i>Zwroty kosztów</a>
    <div class="oc-section">IT / RODO</div>
    <a class="oc-link<?= _nav_active('/it/index') ?>" href="<?= APP_URL ?>/it/index.php"><i class="bi bi-hdd-network me-2"></i>IT</a>
    <a class="oc-link<?= _nav_active('/rodo/') ?>" href="<?= APP_URL ?>/rodo/index.php"><i class="bi bi-shield-lock me-2"></i>RODO</a>
    <div class="oc-section">Więcej</div>
    <a class="oc-link<?= _nav_active('/persons/') ?>" href="<?= APP_URL ?>/persons/index.php"><i class="bi bi-people me-2"></i>Osoby</a>
    <a class="oc-link<?= _nav_active('/correspondence/') ?>" href="<?= APP_URL ?>/correspondence/index.php"><i class="bi bi-mailbox me-2"></i>Korespondencja</a>
    <?php if (is_admin()): ?>
    <div class="oc-section">Admin</div>
    <a class="oc-link<?= _nav_active('/admin/index') ?>" href="<?= APP_URL ?>/admin/index.php"><i class="bi bi-shield-shaded me-2"></i>Panel admina</a>
    <?php endif; ?>
    <?php elseif ($_user && !is_ezd_only()): ?>
    <a class="oc-link<?= _nav_active('/panel/index') ?>" href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-person-circle me-2"></i>Moja umowa</a>
    <a class="oc-link<?= _nav_active('/panel/m365') ?>" href="<?= APP_URL ?>/panel/m365.php"><i class="bi bi-microsoft me-2"></i>Microsoft 365</a>
    <a class="oc-link<?= _nav_active('/panel/sessions') ?>" href="<?= APP_URL ?>/panel/sessions.php"><i class="bi bi-shield-lock me-2"></i>Sesje</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($_is_saas_admin): ?>
<div id="saas-bar">
  <i class="bi bi-shield-lock-fill"></i>
  <strong>SaaS Admin</strong>
  <span class="saas-bar-sep">·</span>
  <span><?= h(ORG_NAME) ?></span>
  <span class="saas-bar-slug font-monospace"><?= h(TENANT_SLUG) ?></span>
  <a href="<?= h($_saas_url) ?>" class="ms-auto saas-bar-back">
    <i class="bi bi-arrow-left me-1"></i>Panel SaaS
  </a>
</div>
<?php elseif ($_is_tenant): ?>
<div class="saas-tenant-bar">
  <i class="bi bi-building me-1"></i>
  Tenant:&nbsp;<strong><?= h(ORG_NAME) ?></strong>
  <span class="saas-bar-slug font-monospace ms-2"><?= h(TENANT_SLUG) ?></span>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['_admin_original'])): ?>
<div id="impersonate-bar">
  <i class="bi bi-person-badge-fill"></i>
  <span>Podgląd jako:</span>
  <span class="imp-name"><?= h($_SESSION['user']['name'] ?? 'użytkownik') ?></span>
  <span class="imp-email">&lt;<?= h($_SESSION['user']['email'] ?? '') ?>&gt;</span>
  <a href="<?= APP_URL ?>/admin/impersonate_stop.php" class="imp-stop">
    <i class="bi bi-arrow-left"></i> Powrót do admina
  </a>
</div>
<?php endif; ?>

<div id="page-title-bar">
  <?php if (!empty($_page_title)): ?>
  <span class="ptb-title"><?= h($_page_title) ?></span>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/bug_report_widget.php'; ?>
<?php require_once __DIR__ . '/welcome_notice.php'; ?>


  <div id="content">
  <?= flash_html() ?>

<?php
// ── Baner globalnego statusu systemu (tylko do odczytu / przestój) ───────────
if ($_user && function_exists('system_is_readonly') && system_is_readonly()):
    $_ss = system_status_meta();
?>
<div class="alert alert-<?= h($_ss['color']) ?> d-flex gap-2 align-items-start mb-3 py-2" role="status">
  <i class="bi <?= h($_ss['icon']) ?> flex-shrink-0 mt-1"></i>
  <div class="flex-grow-1" style="font-size:.875rem">
    <strong>System nieaktywny — <?= h($_ss['label']) ?>.</strong>
    <?= h($_ss['desc']) ?>
    <?php if ($_ss['reason'] !== ''): ?>
    <div class="mt-1"><span class="text-body-secondary">Powód:</span> <?= nl2br(h($_ss['reason'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($_ss['since'])): ?>
    <div class="text-body-secondary small mt-1">
      Od <?= h(date('d.m.Y H:i', strtotime($_ss['since']))) ?><?= !empty($_ss['by']) ? ' · ustawił(a): '.h($_ss['by']) : '' ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if (is_admin()): ?>
  <a href="<?= h(APP_URL) ?>/admin/system_status.php" class="btn btn-sm btn-outline-dark flex-shrink-0">
    <i class="bi bi-gear me-1"></i>Zarządzaj
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// Banner systemowy — konfigurowalny przez admina w ustawieniach
$_sys_banner_text = org_setting('system_banner_text');
$_sys_banner_type = org_setting('system_banner_type') ?: 'warning'; // info|warning|danger|success
if ($_sys_banner_text && $_user):
    $banner_icons = ['warning'=>'bi-exclamation-triangle-fill','info'=>'bi-info-circle-fill','danger'=>'bi-exclamation-octagon-fill','success'=>'bi-check-circle-fill'];
    $banner_icon  = $banner_icons[$_sys_banner_type] ?? 'bi-info-circle-fill';
    $banner_key   = 'sysbanner_' . md5($_sys_banner_text);
?>
<div id="sysBanner" class="alert alert-<?= h($_sys_banner_type) ?> alert-dismissible d-flex gap-2 align-items-start mb-3 py-2"
     role="alert" style="display:none!important">
  <i class="bi <?= h($banner_icon) ?> flex-shrink-0 mt-1"></i>
  <div style="font-size:.875rem"><?= nl2br(h($_sys_banner_text)) ?></div>
  <button type="button" class="btn-close btn-sm" onclick="sysBannerDismiss()" aria-label="Zamknij"></button>
</div>
<script>
(function(){
  var KEY = <?= json_encode($banner_key) ?>;
  var el  = document.getElementById('sysBanner');
  if (el && !localStorage.getItem(KEY)) el.style.removeProperty('display');
})();
function sysBannerDismiss() {
  localStorage.setItem(<?= json_encode($banner_key) ?>, '1');
  var el = document.getElementById('sysBanner');
  if (el) el.style.display = 'none';
}
</script>
<?php endif; ?>

<?php
// Banner "Pliki do wydruku" — dla użytkowników bez drukarki
if ($_user) {
    try {
        require_once __DIR__ . '/../contracts/includes/pdf_queue.php';
        $_pdf_pending = pdf_queue_count_pending((int)$_user['id']);
    } catch (\Throwable $_e) {
        $_pdf_pending = 0;
    }
    if ($_pdf_pending > 0):
        $_pdf_word = $_pdf_pending === 1 ? 'plik' : ($_pdf_pending < 5 ? 'pliki' : 'plików');
?>
<div class="alert alert-warning d-flex gap-2 align-items-center py-2 mb-3" role="alert" id="pdfQueueBanner">
  <i class="bi bi-printer-fill flex-shrink-0 fs-5"></i>
  <div class="flex-grow-1" style="font-size:.875rem">
    <strong>Masz <?= $_pdf_pending ?> <?= $_pdf_word ?> do wydruku.</strong>
    Otwórz listę, kliknij <em>Drukuj PDF</em> i wybierz w przeglądarce „Zapisz jako PDF".
  </div>
  <a href="<?= APP_URL ?>/contracts/pdf_queue.php"
     class="btn btn-warning btn-sm fw-semibold flex-shrink-0">
    <i class="bi bi-printer me-1"></i>Pokaż pliki
  </a>
</div>
<?php endif; } ?>

<script>
(function () {
  var APP_URL = '<?= APP_URL ?>';

  // Kliknięcie w powiadomienie w dropdownie → oznacz jako przeczytane
  document.addEventListener('click', function(e) {
    var link = e.target.closest('[data-notif-id]');
    if (link) {
      var id = parseInt(link.dataset.notifId);
      fetch(APP_URL + '/api/notifications/mark_read.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({id: id})
      });
    }
  });

  // Oznacz wszystkie w topbarze
  var markAll = document.getElementById('notif-mark-all');
  if (markAll) {
    markAll.addEventListener('click', function() {
      fetch(APP_URL + '/api/notifications/mark_read.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({all: true})
      }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) {
          document.querySelectorAll('[data-notif-id]').forEach(function(el) {
            el.classList.remove('fw-semibold');
            var dot = el.querySelector('.bg-primary.rounded-circle');
            if (dot) dot.remove();
          });
          var badge = document.querySelector('#notif-btn .badge');
          if (badge) badge.remove();
          markAll.remove();
        }
      });
    });
  }
})();
</script>

<?php
// ── CPC Authorization Modal (admin + editor only) ────────────────────────────
// Injected once per page. Intercepts submits on forms with data-cpc="1".
if ($_user && in_array($_user['role'] ?? '', ['admin', 'editor'], true)):
    require_once __DIR__ . '/cpc.php';
    cpc_migrate();
    // Sesja może nie mieć cpc_code (kolumna dodana po zalogowaniu) — odczyt z DB
    $_cpc_fresh   = db_one("SELECT cpc_code, cpc_fails, cpc_blocked_until FROM users WHERE id=?", [(int)$_user['id']]);
    $_cpc_user    = array_merge($_user, $_cpc_fresh ?: []);
    $_cpc_enabled = cpc_user_can_use($_cpc_user);
?>
<!-- CPC Modal ──────────────────────────────────────────────────── -->
<div id="cpcModal" class="modal fade" tabindex="-1"
     role="dialog" aria-modal="true" aria-labelledby="cpcModalTitle" aria-describedby="cpcModalDesc">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title d-flex align-items-center gap-2" id="cpcModalTitle">
          <i class="bi bi-shield-lock-fill text-primary"></i>
          Autoryzacja operacji
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body pt-2">
        <p class="text-muted small mb-3" id="cpcModalDesc">
          Ta operacja wymaga potwierdzenia Twoim indywidualnym kodem autoryzacyjnym IKA (6 cyfr).
        </p>

        <!-- Dynamiczna tabela potwierdzenia -->
        <div id="cpcSummaryWrap" class="mb-3"></div>

        <!-- Stan: zapis po poprawnej weryfikacji -->
        <div id="cpcSavingSection" class="d-none text-center py-2">
          <div class="mb-3">
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2" style="font-size:.85rem">
              <i class="bi bi-check-circle-fill me-1"></i>Kod IKA poprawny
            </span>
          </div>
          <div class="spinner-border text-primary mb-2" role="status" style="width:2.2rem;height:2.2rem">
            <span class="visually-hidden">Zapisuję…</span>
          </div>
          <div class="fw-semibold" id="cpcSavingTitle">Zapisuję umowę…</div>
          <div class="text-muted small mt-1">Proszę czekać, nie zamykaj okna.</div>
        </div>

        <!-- CPC input -->
        <div id="cpcInputSection">
          <label for="cpcCodeInput" id="cpcInputLabel" class="form-label fw-semibold">
            Indywidualny kod autoryzacyjny IKA (6 cyfr)
          </label>
          <input type="text" id="cpcCodeInput" inputmode="numeric" pattern="\d{6}" maxlength="6"
                 class="form-control form-control-lg text-center font-monospace letter-spacing-lg"
                 autocomplete="off" placeholder="000000"
                 aria-describedby="cpcAlertBox">
          <div id="cpcAlertBox" role="alert" aria-live="assertive" class="mt-2" style="min-height:1.5rem"></div>

          <?php if ($_cpc_enabled): ?>
          <div class="text-end mt-1">
            <button type="button" id="cpcFallbackBtn" class="btn btn-link btn-sm p-0 text-muted">
              Nie pamiętam kodu IKA
            </button>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!$_cpc_enabled): ?>
        <div class="alert alert-warning small py-2 mt-2 mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Nie masz przypisanego kodu IKA (indywidualnego kodu autoryzacyjnego). Skontaktuj się z administratorem, aby kontynuować.
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
          Anuluj
        </button>
        <button type="button" id="cpcSubmitBtn" class="btn btn-primary btn-sm px-4"
                <?= !$_cpc_enabled ? 'disabled' : '' ?>>
          <span id="cpcBtnText"><i class="bi bi-check-lg me-1"></i>Zatwierdź</span>
          <span id="cpcBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
            Weryfikacja…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>

<style>
#cpcCodeInput { font-size: 1.6rem; letter-spacing: .35em; }
#cpcSummaryWrap table { font-size: .83rem; }
#cpcSummaryWrap th { width: 42%; color: #64748b; font-weight: 600; }
</style>

<script>
(function () {
  'use strict';

  var _pendingForm  = null;   // original form that triggered CPC
  var _usingSms     = false;  // true after SMS fallback requested
  var _cpcModal     = null;
  var _bsModal      = null;

  // ── Bootstrap modal instance ─────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    _cpcModal = document.getElementById('cpcModal');
    if (!_cpcModal) return;
    _bsModal = new bootstrap.Modal(_cpcModal, { backdrop: 'static', keyboard: false });

    // Focus trap: when modal shown, focus input
    _cpcModal.addEventListener('shown.bs.modal', function () {
      var inp = document.getElementById('cpcCodeInput');
      if (inp) inp.focus();
      _usingSms = false;
    });

    // ESC / close resets state
    _cpcModal.addEventListener('hidden.bs.modal', function () {
      _pendingForm = null;
      _resetCpcUi();
    });

    // ── Intercept forms with data-cpc="1" ────────────────────
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (form.dataset.cpc !== '1') return;
      e.preventDefault();
      _pendingForm = form;
      _resetCpcUi();
      _buildSummaryTable(form);
      _bsModal.show();
    }, true);

    // ── Submit button ─────────────────────────────────────────
    document.getElementById('cpcSubmitBtn').addEventListener('click', function () {
      var code = (document.getElementById('cpcCodeInput').value || '').trim();
      if (!/^\d{6}$/.test(code)) {
        _setAlert('Wpisz 6-cyfrowy kod.', 'danger'); return;
      }
      _setLoading(true);
      var endpoint = _usingSms
        ? '<?= APP_URL ?>/admin/api/verify_sms_fallback.php'
        : '<?= APP_URL ?>/admin/api/verify_cpc.php';
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: code, _csrf: _getCsrf() })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          _setLoading(false);
          if (data.ok) {
            // Pokaż stan zapisu (modal pozostaje otwarty)
            _setSaving(_pendingForm);
            // Submituj formularz — przeglądarka przejdzie na nową stronę
            if (_pendingForm) {
              _pendingForm.dataset.cpc = '0';
              _pendingForm.submit();
            }
          } else if (data.blocked) {
            _setAlert('Kod IKA zablokowany do: ' + (data.blocked_until || '') + '. Skontaktuj się z administratorem.', 'danger');
            document.getElementById('cpcCodeInput').disabled = true;
            document.getElementById('cpcSubmitBtn').disabled = true;
          } else {
            _setAlert(data.message || 'Nieprawidłowy kod.', 'danger');
            var inp = document.getElementById('cpcCodeInput');
            inp.value = ''; inp.focus();
          }
        })
        .catch(function () {
          _setLoading(false);
          _setAlert('Błąd połączenia z serwerem. Spróbuj ponownie.', 'danger');
        });
    });

    // ── SMS Fallback button ───────────────────────────────────
    var fallbackBtn = document.getElementById('cpcFallbackBtn');
    if (fallbackBtn) {
      fallbackBtn.addEventListener('click', function () {
        fallbackBtn.disabled = true;
        fetch('<?= APP_URL ?>/admin/api/send_sms_fallback.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ _csrf: _getCsrf() })
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              _usingSms = true;
              document.getElementById('cpcInputLabel').textContent = 'Kod z wiadomości SMS (6 cyfr)';
              _setAlert('Kod SMS wysłany. Wprowadź go poniżej.', 'success');
              var inp = document.getElementById('cpcCodeInput');
              inp.value = ''; inp.focus();
            } else {
              _setAlert(data.message || 'Nie udało się wysłać SMS.', 'warning');
              fallbackBtn.disabled = false;
            }
          })
          .catch(function () {
            _setAlert('Błąd wysyłki SMS. Spróbuj ponownie.', 'warning');
            fallbackBtn.disabled = false;
          });
      });
    }

    // ── Auto-submit on 6 digits ───────────────────────────────
    document.getElementById('cpcCodeInput').addEventListener('input', function () {
      if (this.value.length === 6 && /^\d{6}$/.test(this.value)) {
        document.getElementById('cpcSubmitBtn').click();
      }
    });
  });

  // ── Helpers ──────────────────────────────────────────────────

  function _getCsrf() {
    var el = document.querySelector('[name="_csrf"]');
    return el ? el.value : '';
  }

  function _setSaving(form) {
    // Komunikat — można nadpisać atrybutem data-cpc-saving na formularzu
    var msg = (form && form.dataset.cpcSaving) ? form.dataset.cpcSaving : 'Zapisuję umowę…';
    var titleEl = document.getElementById('cpcSavingTitle');
    if (titleEl) titleEl.textContent = msg;
    // Ukryj elementy wejściowe i stopkę
    ['cpcInputSection', 'cpcSummaryWrap', 'cpcModalDesc'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.classList.add('d-none');
    });
    var footer  = _cpcModal ? _cpcModal.querySelector('.modal-footer') : null;
    var closeBtn = _cpcModal ? _cpcModal.querySelector('.btn-close')   : null;
    if (footer)   footer.classList.add('d-none');
    if (closeBtn) closeBtn.classList.add('d-none');
    // Pokaż sekcję zapisu
    var sav = document.getElementById('cpcSavingSection');
    if (sav) sav.classList.remove('d-none');
  }

  function _setLoading(on) {
    document.getElementById('cpcBtnText').classList.toggle('d-none', on);
    document.getElementById('cpcBtnSpinner').classList.toggle('d-none', !on);
    document.getElementById('cpcSubmitBtn').disabled = on;
    document.getElementById('cpcCodeInput').disabled = on;
    if (on) {
      var al = document.getElementById('cpcAlertBox');
      al.className = 'mt-2 alert alert-info py-1 small';
      al.textContent = 'Trwa weryfikacja kodu…';
    }
  }

  function _setAlert(msg, type) {
    var al = document.getElementById('cpcAlertBox');
    al.className = 'mt-2 alert alert-' + type + ' py-1 small';
    al.textContent = msg;
  }

  function _resetCpcUi() {
    // Przywróć ukryte przez _setSaving()
    ['cpcInputSection', 'cpcSummaryWrap', 'cpcModalDesc'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.classList.remove('d-none');
    });
    var footer   = _cpcModal ? _cpcModal.querySelector('.modal-footer') : null;
    var closeBtn = _cpcModal ? _cpcModal.querySelector('.btn-close')    : null;
    if (footer)   footer.classList.remove('d-none');
    if (closeBtn) closeBtn.classList.remove('d-none');
    var sav = document.getElementById('cpcSavingSection');
    if (sav) sav.classList.add('d-none');
    // Reset inputów
    var inp = document.getElementById('cpcCodeInput');
    if (inp) { inp.value = ''; inp.disabled = false; }
    var al = document.getElementById('cpcAlertBox');
    if (al) { al.className = 'mt-2'; al.textContent = ''; }
    var lbl = document.getElementById('cpcInputLabel');
    if (lbl) lbl.textContent = 'Indywidualny kod autoryzacyjny IKA (6 cyfr)';
    var btn = document.getElementById('cpcSubmitBtn');
    if (btn) btn.disabled = <?= $_cpc_enabled ? 'false' : 'true' ?>;
    document.getElementById('cpcBtnText').classList.remove('d-none');
    document.getElementById('cpcBtnSpinner').classList.add('d-none');
    var fb = document.getElementById('cpcFallbackBtn');
    if (fb) fb.disabled = false;
    _usingSms = false;
  }

  // ── Build summary table from form data-* attributes ──────────
  function _buildSummaryTable(form) {
    var wrap = document.getElementById('cpcSummaryWrap');
    wrap.innerHTML = '';
    var meta = form.dataset.cpcMeta ? JSON.parse(form.dataset.cpcMeta) : null;
    if (!meta || !meta.rows) return;
    var tbl = '<table class="table table-sm table-bordered mb-0"><tbody>';
    meta.rows.forEach(function (r) {
      tbl += '<tr><th scope="row">' + _esc(r[0]) + '</th><td>' + _esc(r[1]) + '</td></tr>';
    });
    tbl += '</tbody></table>';
    wrap.innerHTML = tbl;
  }

  function _esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
})();
</script>
<?php endif; ?>
<script>
// ── Globalny helper AJAX + CSRF ───────────────────────────────────────────────
window._csrf = '<?= csrf_token() ?>';

function csrfFetch(url, data) {
  return fetch(url, {
    method : 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body   : new URLSearchParams(Object.assign({_csrf: window._csrf}, data))
  }).then(function(r) { return r.json(); });
}

// ── Toast ─────────────────────────────────────────────────────────────────────
(function() {
  var style = document.createElement('style');
  style.textContent =
    '@keyframes _toastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}' +
    '@keyframes _toastOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}';
  document.head.appendChild(style);
})();

function ajaxToast(msg, type) {
  type = type || 'success';
  var t = document.createElement('div');
  t.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;padding:.75rem 1.1rem;border-radius:10px;font-size:.85rem;font-weight:600;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.18);animation:_toastIn .2s ease;max-width:300px';
  t.style.background = (type === 'success') ? '#16a34a' : '#dc2626';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function() {
    t.style.animation = '_toastOut .2s ease forwards';
    setTimeout(function() { t.remove(); }, 200);
  }, 3000);
}
</script>
<script>
// ── Globalny spinner AJAX ─────────────────────────────────────────────────────
(function () {
  'use strict';
  var PREF   = 'feer_a11y_no_spinner';
  var active = 0;
  var el     = null;

  function noAnim() { return !!localStorage.getItem(PREF); }
  function show()   { if (el && !noAnim()) el.style.display = 'inline-flex'; }
  function hide()   { if (el) el.style.display = 'none'; }
  function inc()    { if (++active === 1) show(); }
  function dec()    { if (--active <= 0) { active = 0; hide(); } }

  // Patch fetch — obejmuje wszystkie wywołania w aplikacji
  var _origFetch = window.fetch;
  window.fetch = function () {
    inc();
    return _origFetch.apply(this, arguments).then(
      function (r) { dec(); return r; },
      function (e) { dec(); throw e; }
    );
  };

  // Patch XHR
  var _origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function () {
    inc();
    this.addEventListener('loadend', dec, { once: true });
    return _origSend.apply(this, arguments);
  };

  // Publiczne API dla specjalnych przypadków
  window.spinnerInc = inc;
  window.spinnerDec = dec;

  document.addEventListener('DOMContentLoaded', function () {
    el = document.getElementById('ajax-spinner');

    var btn  = document.getElementById('a11y-spinner-toggle');
    var lbl  = document.getElementById('a11y-spinner-lbl');
    var icon = document.getElementById('a11y-spinner-icon');

    function updateToggle() {
      var off = noAnim();
      if (lbl)  lbl.textContent = off ? 'Włącz animacje ładowania' : 'Wyłącz animacje ładowania';
      if (icon) icon.className  = 'bi me-2 text-muted ' + (off ? 'bi-eye' : 'bi-eye-slash');
    }
    updateToggle();

    if (btn) {
      btn.addEventListener('click', function () {
        if (noAnim()) {
          localStorage.removeItem(PREF);
        } else {
          localStorage.setItem(PREF, '1');
          hide();
        }
        updateToggle();
      });
    }
  });
})();
</script>
