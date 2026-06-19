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
    return str_contains($_uri, $needle) ? ' nav-active' : '';
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
<link href="<?= APP_URL ?>/assets/css/app.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js" defer></script>
<script src="<?= APP_URL ?>/assets/js/utils.js" defer></script>
<style>
/* ── Layout ───────────────────────────────── */
body { display:flex; min-height:100vh; background:#f8fafc; }

#sidebar {
    width: 240px;
    min-width: 240px;
    background: #fff;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 100;
}
#sidebar::-webkit-scrollbar { width: 4px; }
#sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

#main {
    flex: 1;
    min-width: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ── Sidebar brand ────────────────────────── */
.sb-brand {
    padding: 1rem 1rem .9rem;
    border-bottom: 1px solid #e2e8f0;
    color: #0f172a;
    font-weight: 800;
    font-size: .97rem;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: .6rem;
    letter-spacing: -.01em;
}
.sb-brand:hover { background: #f8fafc; color: #0f172a; }
.sb-brand-icon {
    width: 30px; height: 30px;
    background: #eff6ff;
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0; color: #2563eb;
}
.sb-logo-img { height: 28px; width: auto; max-width: 36px; object-fit: contain; border-radius: 4px; }
.sb-brand-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-brand-sub { font-size: .64rem; color: #94a3b8; font-weight: 400; display: block; line-height: 1.1; }

/* ── Section labels ───────────────────────── */
.sb-label {
    padding: .6rem 1rem .2rem;
    font-size: .65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #b0bec5;
    display: block;
}

/* ── Nav links ────────────────────────────── */
.sb-link {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .38rem .9rem;
    color: #334155;
    text-decoration: none;
    font-size: .84rem;
    font-weight: 500;
    border-left: 3px solid transparent;
    transition: background .1s, color .1s;
}
.sb-link i { font-size: .9rem; width: 17px; text-align: center; flex-shrink: 0; color: #94a3b8; }
.sb-link:hover { background: #eff6ff; color: #2563eb; }
.sb-link:hover i { color: #2563eb; }
.sb-link.nav-active { background: #eff6ff; color: #2563eb; border-left-color: #2563eb; font-weight: 600; }
.sb-link.nav-active i { color: #2563eb; }
.sb-link .badge { font-size: .62rem; margin-left: auto; font-weight: 700; }

/* ── Collapsible type headers ─────────────── */
.sb-type-btn {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .38rem .9rem;
    color: #334155;
    font-size: .84rem;
    font-weight: 500;
    background: none;
    border: none;
    border-left: 3px solid transparent;
    width: 100%;
    text-align: left;
    cursor: pointer;
    transition: background .1s, color .1s;
    line-height: 1.4;
}
.sb-type-btn i:first-child { font-size: .9rem; width: 17px; text-align: center; flex-shrink: 0; color: #94a3b8; }
.sb-type-btn:hover { background: #eff6ff; color: #2563eb; }
.sb-type-btn:hover i:first-child { color: #2563eb; }
.sb-type-btn.type-open { color: #2563eb; background: #eff6ff; border-left-color: #2563eb; font-weight: 600; }
.sb-type-btn.type-open i:first-child { color: #2563eb; }
.sb-chevron {
    margin-left: auto;
    font-size: .65rem;
    opacity: .4;
    transition: transform .18s ease;
    flex-shrink: 0;
}
.sb-type-btn.type-open .sb-chevron { transform: rotate(90deg); opacity: .8; }

/* ── Sub-links ────────────────────────────── */
.sb-sub { padding: 0 0 2px 0; }
.sb-sub-link {
    display: flex;
    align-items: center;
    gap: .45rem;
    padding: .3rem .9rem .3rem 2.2rem;
    color: #64748b;
    text-decoration: none;
    font-size: .82rem;
    border-left: 3px solid transparent;
    transition: background .1s, color .1s;
}
.sb-sub-link i { font-size: .8rem; width: 15px; text-align: center; flex-shrink: 0; }
.sb-sub-link:hover { background: #eff6ff; color: #2563eb; }
.sb-sub-link.nav-active { color: #2563eb; font-weight: 600; border-left-color: #2563eb; background: #eff6ff; }

/* ── Separator ────────────────────────────── */
.sb-sep { height: 1px; background: #f1f5f9; margin: .35rem .85rem; }

/* ── Footer ───────────────────────────────── */
.sb-footer {
    margin-top: auto;
    padding: .75rem 1rem;
    border-top: 1px solid #e2e8f0;
    font-size: .77rem;
    color: #94a3b8;
}
.sb-footer a { color: #64748b; text-decoration: none; }
.sb-footer a:hover { color: #1e293b; }
.sb-user { color: #0f172a; font-size: .83rem; font-weight: 600; margin-bottom: .3rem; }

/* ── SaaS admin bar ─────────────────────── */
#saas-bar {
    background: #7c3aed;
    color: #fff;
    padding: .35rem 1.5rem;
    font-size: .76rem;
    display: flex;
    align-items: center;
    gap: .5rem;
    position: sticky;
    top: 0;
    z-index: 60;
}
#saas-bar .saas-bar-sep  { opacity: .45; }
#saas-bar .saas-bar-slug { opacity: .6; font-size: .7rem; }
#saas-bar .saas-bar-back { color: #ddd8fc; text-decoration: none; font-size: .74rem; }
#saas-bar .saas-bar-back:hover { color: #fff; }

.saas-tenant-bar {
    background: #1e40af;
    color: #bfdbfe;
    padding: .28rem 1.5rem;
    font-size: .74rem;
    display: flex;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 60;
}
.saas-tenant-bar strong { color: #fff; }
.saas-tenant-bar .saas-bar-slug { opacity: .65; font-size: .68rem; }

/* ── Impersonate bar ──────────────────────── */
#impersonate-bar {
    background: #f59e0b;
    color: #1c1400;
    padding: .38rem 1.2rem;
    display: flex;
    align-items: center;
    gap: .6rem;
    font-size: .82rem;
    font-weight: 500;
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 55;
}
#impersonate-bar .imp-name { font-weight: 700; }
#impersonate-bar .imp-email { opacity: .65; font-size: .78rem; }
#impersonate-bar a.imp-stop {
    margin-left: auto;
    background: rgba(0,0,0,.15);
    color: #1c1400;
    text-decoration: none;
    font-weight: 600;
    font-size: .8rem;
    border-radius: 5px;
    padding: .22rem .7rem;
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    white-space: nowrap;
}
#impersonate-bar a.imp-stop:hover { background: rgba(0,0,0,.25); }

/* ── Top bar ──────────────────────────────── */
#topbar {
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    padding: .28rem 1rem;
    display: flex;
    align-items: center;
    gap: .4rem;
    font-size: .875rem;
    color: #64748b;
    position: sticky;
    top: 0;
    z-index: 50;
}
#topbar .page-title {
    font-weight: 600; color: #1e293b; font-size: .92rem;
    flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    min-width: 0;
}

/* Kompaktowe wyszukiwanie */
.tb-search-wrap { position: relative; }
.tb-search-wrap input {
    width: 36px; height: 30px;
    border: 1px solid transparent; border-radius: 8px;
    background: #f8fafc; padding: 0 .5rem;
    font-size: .82rem; outline: none;
    transition: width .2s, border-color .2s, background .2s;
    cursor: pointer;
}
.tb-search-wrap input:focus,
.tb-search-wrap input.expanded {
    width: 200px; border-color: #cbd5e1; background: #fff; cursor: text;
}
.tb-search-wrap .tb-search-icon {
    position: absolute; left: .55rem; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .82rem; pointer-events: none;
}

.topbar-user-chip {
    display: inline-flex; align-items: center; gap: .4rem;
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 20px;
    padding: .25rem .75rem .25rem .4rem; font-size: .8rem; font-weight: 500;
    color: #334155; text-decoration: none; transition: background .15s;
}
.topbar-user-chip:hover { background: #e2e8f0; color: #1e293b; }
.topbar-user-chip .avatar {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, var(--bs-primary), #6610f2);
    color: #fff; font-size: .65rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

/* ── Content ──────────────────────────────── */
/* padding-bottom zwiększony by FAB komunikatora nie zasłaniał ostatniej karty */
#content { padding: 1.5rem 1.5rem 5rem; flex: 1; }

/* ── Mobile toggler ───────────────────────── */
@media (max-width: 991px) {
    #sidebar { position: fixed; left: -230px; transition: left .2s; }
    #sidebar.show { left: 0; }
    #main { margin-left: 0 !important; }
}

/* ── AJAX spinner ─────────────────────────────────────── */
#ajax-spinner {
    display: none;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    margin-left: .1rem;
}
#ajax-spinner::after {
    content: '';
    display: block;
    width: 13px;
    height: 13px;
    border: 2px solid #e2e8f0;
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: _ajaxSpin .65s linear infinite;
}
@keyframes _ajaxSpin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<!-- ── SIDEBAR ─────────────────────────────────────────────────── -->
<nav id="sidebar">

  <a class="sb-brand" href="<?= APP_URL ?>/index.php">
    <?php if ($_org_logo && file_exists(dirname(__DIR__) . '/assets/logo/' . $_org_logo)): ?>
    <img src="<?= APP_URL ?>/assets/logo/<?= h($_org_logo) ?>" alt="Logo" class="sb-logo-img">
    <?php else: ?>
    <span class="sb-brand-icon"><i class="bi bi-building"></i></span>
    <?php endif; ?>
    <span>
      <span class="sb-brand-name"><?= h(org_setting('org_short_name') ?: ORG_NAME) ?></span>
      <span class="sb-brand-sub">System SZO</span>
    </span>
  </a>

  <?php $_ezd_only = is_ezd_only(); ?>
  <?php if ($_user): ?>

  <?php if ($_ezd_only): ?>
  <!-- ══ WIDOK EZD-ONLY (rola ezd_user) ════════════════════════════ -->
  <?php if (module_enabled('ezd_enabled')): ?>
  <div class="sb-label">Kancelaria EZD</div>
  <a class="sb-link<?= _nav_active('/ezd/index.php') ?>" href="<?= APP_URL ?>/ezd/index.php">
    <i class="bi bi-building-gear"></i> Pulpit kancelarii
  </a>
  <a class="sb-link<?= _nav_active('/ezd/rpw/') ?>" href="<?= APP_URL ?>/ezd/rpw/index.php">
    <i class="bi bi-mailbox2"></i> Dziennik podawczy
  </a>
  <a class="sb-link<?= _nav_active('/ezd/sprawy/') ?>" href="<?= APP_URL ?>/ezd/sprawy/index.php">
    <i class="bi bi-folder2-open"></i> Sprawy
  </a>
  <a class="sb-link<?= _nav_active('/ezd/teczki/') ?>" href="<?= APP_URL ?>/ezd/teczki/index.php">
    <i class="bi bi-archive"></i> Teczki aktowe
  </a>
  <a class="sb-link<?= _nav_active('/ezd/jrwa/') ?>" href="<?= APP_URL ?>/ezd/jrwa/index.php">
    <i class="bi bi-tags"></i> Wykaz akt (JRWA)
  </a>
  <a class="sb-link<?= _nav_active('/ezd/pelnomocnictwa/') ?>" href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php">
    <i class="bi bi-person-vcard"></i> Rejestr pełnomocnictw
  </a>
  <a class="sb-link<?= _nav_active('/ezd/zaswiadczenia/') ?>" href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">
    <i class="bi bi-award"></i> Rejestr zaświadczeń
  </a>
  <?php else: ?>
  <div class="sb-label">Kancelaria EZD</div>
  <div class="px-3 py-2 text-muted" style="font-size:.8rem">Moduł EZD jest wyłączony. Skontaktuj się z administratorem.</div>
  <?php endif; ?>

  <?php else: ?>

  <?php if (!can_edit()): ?>
  <!-- ══ WIDOK UŻYTKOWNIKA (viewer) ══════════════════════════════ -->
  <div class="sb-label">Mój panel</div>
  <a class="sb-link<?= _nav_active('/panel/index') ?>" href="<?= APP_URL ?>/panel/index.php">
    <i class="bi bi-person-circle"></i> Moja umowa
  </a>
  <?php
  // Badge niepotwierdzonych zasad
  try {
    require_once __DIR__ . '/org_rules.php';
    org_rules_migrate();
    $_unread_rules = count(org_rules_unread((int)($_user['id'] ?? 0)));
  } catch (\Throwable $e) { $_unread_rules = 0; }
  ?>
  <?php if (panel_visible('zasady')): ?>
  <a class="sb-link<?= _nav_active('/org_intro/') ?>" href="<?= APP_URL ?>/org_intro/index.php">
    <i class="bi bi-building-heart"></i> Zasady organizacji
    <?php if ($_unread_rules > 0): ?>
    <span class="badge bg-danger ms-auto"><?= $_unread_rules ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <a class="sb-link<?= _nav_active('/panel/profile_edit') ?>" href="<?= APP_URL ?>/panel/profile_edit.php">
    <i class="bi bi-person-badge"></i> Mój profil
  </a>
  <?php if (panel_visible('komunikaty')): ?>
  <a class="sb-link<?= _nav_active('/komunikaty/') ?>" href="<?= APP_URL ?>/komunikaty/index.php">
    <i class="bi bi-megaphone" style="color:#F59E0B"></i> Komunikaty
    <?php try {
      $_v_ann_count = count(array_filter(ann_list_for_user((int)$_user['id'], $_user['role'] ?? 'viewer'), fn($a) => !(int)($a['is_read_by_me'] ?? 0)));
      if ($_v_ann_count > 0): ?>
    <span class="badge bg-warning text-dark ms-auto" style="font-size:.65rem"><?= $_v_ann_count ?></span>
    <?php endif; } catch (\Throwable $e) {} ?>
  </a>
  <?php if (panel_visible('komunikaty') && is_admin()): ?>
  <a class="sb-sub-link<?= _nav_active('/komunikaty/compose') ?>" href="<?= APP_URL ?>/komunikaty/compose.php">
    <i class="bi bi-plus-circle"></i> Nowe ogłoszenie
  </a>
  <?php endif; ?>
  <?php endif; ?>
  <?php if (panel_visible('katalog')): ?>
  <a class="sb-link<?= _nav_active('/directory/') ?>" href="<?= APP_URL ?>/directory/">
    <i class="bi bi-person-lines-fill"></i> Książka telefoniczna
  </a>
  <?php endif; ?>
  <?php if (module_enabled('messages_enabled') && panel_visible('wiadomosci')): ?>
  <a class="sb-link<?= _nav_active('/panel/messages') ?>" href="<?= APP_URL ?>/panel/messages.php">
    <i class="bi bi-chat-left-text"></i> Wiadomości
    <?php $_pnl_unread = 0;
      if (!empty($_SESSION['panel_contract'])) {
        try { $_pnl_unread = msg_unread_thread('contract', (int)$_SESSION['panel_contract']['id'], 'user'); }
        catch (\Throwable $e) {}
      }
      if ($_pnl_unread): ?>
    <span class="badge bg-danger ms-auto"><?= $_pnl_unread ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <?php
  $_sprawy_open = str_contains($_uri, '/panel/letters')
               || str_contains($_uri, '/panel/apply')
               || str_contains($_uri, '/panel/certificates')
               || str_contains($_uri, '/panel/terminations')
               || str_contains($_uri, '/panel/timesheets');
  // Sprawy — uwzględnij też wyłączenia dla panelu
  $_sprawy_panel_has = (module_enabled('letters_enabled') && panel_visible('pisma'))
               || panel_visible('wnioski')
               || (module_enabled('certificates_enabled') && panel_visible('zaswiadczenia'))
               || (module_enabled('terminations_enabled') && panel_visible('rozwiazanie'))
               || (module_enabled('timesheets_enabled') && panel_visible('godziny'));
  ?>
  <?php if ($_sprawy_panel_has): ?>
  <button type="button"
          class="sb-type-btn <?= $_sprawy_open ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-sprawy"
          aria-expanded="<?= $_sprawy_open ? 'true' : 'false' ?>">
    <i class="bi bi-folder2-open"></i> Sprawy umowy
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_sprawy_open ? 'show' : '' ?>" id="sb-sprawy">
    <?php if (module_enabled('letters_enabled') && panel_visible('pisma')): ?>
    <a class="sb-sub-link<?= _nav_active('/panel/letters') ?>" href="<?= APP_URL ?>/panel/letters.php">
      <i class="bi bi-archive"></i> Moje pisma
    </a>
    <?php endif; ?>
    <?php if (panel_visible('wnioski')): ?>
    <a class="sb-sub-link<?= _nav_active('/panel/apply') ?>" href="<?= APP_URL ?>/panel/apply.php">
      <i class="bi bi-send"></i> Wyślij pismo / wniosek
    </a>
    <?php endif; ?>
    <?php if (module_enabled('certificates_enabled') && panel_visible('zaswiadczenia')): ?>
    <a class="sb-sub-link<?= _nav_active('/panel/certificates') ?>" href="<?= APP_URL ?>/panel/certificates.php">
      <i class="bi bi-award"></i> Zaświadczenia
    </a>
    <?php endif; ?>
    <?php if (module_enabled('terminations_enabled') && panel_visible('rozwiazanie')): ?>
    <a class="sb-sub-link<?= _nav_active('/panel/terminations') ?>" href="<?= APP_URL ?>/panel/terminations.php">
      <i class="bi bi-file-earmark-x"></i> Rozwiązanie umowy
    </a>
    <?php endif; ?>
    <?php if (module_enabled('timesheets_enabled') && panel_visible('godziny')): ?>
    <a class="sb-sub-link<?= _nav_active('/panel/timesheets') ?>" href="<?= APP_URL ?>/panel/timesheets.php">
      <i class="bi bi-clock-history"></i> Ewidencja godzin
    </a>
    <?php endif; ?>
    <?php require_once __DIR__ . '/apaczka.php';
    if (apaczka_setting('apaczka_enabled') !== '0' && panel_visible('przesylki')): ?>
    <a class="sb-sub-link<?= _nav_active('/panel/shipments') ?>" href="<?= APP_URL ?>/panel/shipments.php">
      <i class="bi bi-box-seam"></i> Przesyłki
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (module_enabled('moodle_enabled') && panel_visible('kursy')): ?>
  <a class="sb-link<?= _nav_active('/panel/moodle') ?>" href="<?= APP_URL ?>/panel/moodle.php">
    <i class="bi bi-mortarboard"></i> Moje kursy
  </a>
  <?php endif; ?>
  <a class="sb-link<?= _nav_active('/panel/m365') ?>" href="<?= APP_URL ?>/panel/m365.php">
    <i class="bi bi-microsoft"></i> Microsoft 365
  </a>
  <a class="sb-link<?= _nav_active('/panel/sessions') ?>" href="<?= APP_URL ?>/panel/sessions.php">
    <i class="bi bi-shield-lock"></i> Sesje i bezpieczeństwo
  </a>
  <a class="sb-link<?= _nav_active('/panel/password') . _nav_active('/panel/2fa') . _nav_active('/panel/m365_password') ?>"
     href="<?= APP_URL ?>/panel/password.php">
    <i class="bi bi-gear"></i> Ustawienia konta
  </a>
  <?php
  $_ika_sb_has_token = false;
  $_ika_sb_missing   = false;
  try {
    $_ika_sb_fresh = db_one("SELECT cpc_code, kdok_ikaks_hash, ika_setup_token, ika_setup_token_expires FROM users WHERE id=?", [(int)$_user['id']]);
    $_ika_sb_has_token = !empty($_ika_sb_fresh['ika_setup_token'])
        && !empty($_ika_sb_fresh['ika_setup_token_expires'])
        && $_ika_sb_fresh['ika_setup_token_expires'] > date('Y-m-d H:i:s');
    $_ika_sb_missing = empty($_ika_sb_fresh['cpc_code']) || empty($_ika_sb_fresh['kdok_ikaks_hash']);
  } catch (\Throwable $_ika_ex) {}
  if ($_ika_sb_has_token || $_ika_sb_missing): ?>
  <?php $_ika_setup_url = APP_URL . '/contracts/ika_gate.php?mode=setup&to=' . urlencode(APP_URL . '/index.php'); ?>
  <a class="sb-link<?= _nav_active('/contracts/ika_gate') ?>"
     href="<?= $_ika_setup_url ?>"
     style="<?= $_ika_sb_has_token ? 'color:#F59E0B!important' : '' ?>">
    <i class="bi bi-shield-plus<?= $_ika_sb_has_token ? ' text-warning' : '' ?>"></i>
    Kody autoryzacyjne<?= $_ika_sb_has_token ? ' <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">Token!</span>' : '' ?>
  </a>
  <?php endif; ?>

  <?php else: ?>
  <!-- ══ WIDOK EDYTORA / ADMINA ════════════════════════════════════ -->
  <?php
  // Pomocnicze zmienne aktywności
  $_on_it       = str_contains($_uri, '/it/');
  $_on_people   = str_contains($_uri,'/persons/') || str_contains($_uri,'/contracts/wolontariat') || str_contains($_uri,'/onboarding/') || str_contains($_uri,'/contracts/rekrutacja') || str_contains($_uri,'/crm/') || str_contains($_uri,'/directory/') || str_contains($_uri,'/admin/messages') || str_contains($_uri,'/admin/terminations') || str_contains($_uri,'/admin/certificates') || str_contains($_uri,'/admin/onboarding');
  $_on_docs     = (str_contains($_uri,'/contracts/') && !str_contains($_uri,'/contracts/wolontariat') && !str_contains($_uri,'/contracts/rekrutacja')) || str_contains($_uri,'/reports/') || str_contains($_uri,'/resolutions/') || str_contains($_uri,'/correspondence/') || str_contains($_uri,'/procedures/') || str_contains($_uri,'/contracts/approvals') || str_contains($_uri,'/contracts/letters');
  $_on_fin      = str_contains($_uri,'/contracts/zwroty') || str_contains($_uri,'/grants/') || str_contains($_uri,'/actions/') || str_contains($_uri,'/strategy/') || str_contains($_uri,'/ksiegowosc/') || str_contains($_uri,'/admin/timesheets') || str_contains($_uri,'/admin/shipments') || str_contains($_uri,'/resources/') || str_contains($_uri,'/panel/timesheets');
  $_on_admin    = str_contains($_uri,'/admin/') && !str_contains($_uri,'/admin/messages') && !str_contains($_uri,'/admin/terminations') && !str_contains($_uri,'/admin/certificates') && !str_contains($_uri,'/admin/timesheets') && !str_contains($_uri,'/admin/shipments') && !str_contains($_uri,'/admin/onboarding');

  // Badges
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
  // Helpdesk badge — computed here because Obsługa is no longer collapsible
  $_hd_open = 0;
  try {
      if (module_enabled('helpdesk_enabled')) {
          $_hd_u = current_user();
          if ($_hd_u) {
              if (is_admin() || !empty($_hd_u['helpdesk_operator'])) {
                  $_hd_open = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE status NOT IN ('zamknięte')")['c'] ?? 0);
              } else {
                  $_hd_open = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')", [(int)$_hd_u['id']])['c'] ?? 0);
              }
          }
      }
  } catch (\Throwable $e) {}
  // Aliasy e-mail — liczba wniosków oczekujących (dla operatorów/adminów)
  $_alias_is_op   = is_admin() || !empty(current_user()['helpdesk_operator']);
  $_alias_pending = 0;
  if ($_alias_is_op) {
      try { $_alias_pending = (int)(db_one("SELECT COUNT(*) AS c FROM email_alias_requests WHERE status IN ('oczekuje','błąd')")['c'] ?? 0); }
      catch (\Throwable $e) {}
  }

  $_people_badge = $_msg_unread_total + $_term_pending + $_cert_pending + $_rek_new + $_ob_new;
  $_fin_badge    = $_zwr_pending + $_ts_pending + $_ship_pending + $_res_badge;
  $_docs_badge   = $_pending; // approvals
  require_once __DIR__ . '/ksiegowosc.php'; kdok_migrate();
  $_has_kdok = kdok_has_role('upload') || kdok_has_role('meryt') || kdok_has_role('formal') || kdok_has_role('zatwierdza') || is_admin();
  ?>

  <!-- ════════════════════════════════════════
       1. UMOWY — wolontariat / zlecenie-praca-dzieło / inne / obsługa
  ════════════════════════════════════════ -->
  <div class="sb-label">Umowy</div>
  <?php
  $_ct_label = fn($s) => CONTRACT_TYPES[$s] ?? ucfirst($s);
  $_ct_icon  = fn($s) => $_contract_icons[$s] ?? 'bi-file-text';
  $_um_groups = [
    'sb-um-wol'  => ['Wolontariat',               'bi-heart',             ['wolontariat']],
    'sb-um-zpd'  => ['Zlecenie / Praca / Dzieło',  'bi-person-lines-fill', ['zlecenie','praca','dzielo']],
    'sb-um-inne' => ['Inne umowy',                 'bi-file-text',         ['uslugi','powierzenie','inne']],
  ];
  foreach ($_um_groups as $gid => $g):
    [$glabel, $gicon, $slugs] = $g;
    $vis = array_values(array_filter($slugs, fn($s) => module_enabled('contract_' . $s)));
    if (!$vis) continue;
    $gactive = false; foreach ($vis as $s) { if (str_contains($_uri, "/contracts/$s/")) { $gactive = true; break; } }
  ?>
  <button type="button" class="sb-type-btn <?= $gactive?'type-open':'' ?>" data-bs-toggle="collapse" data-bs-target="#<?= $gid ?>" aria-expanded="<?= $gactive?'true':'false' ?>">
    <i class="bi <?= $gicon ?>"></i> <?= h($glabel) ?>
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $gactive?'show':'' ?>" id="<?= $gid ?>">
    <?php foreach ($vis as $s): ?>
    <a class="sb-sub-link<?= _nav_active("/contracts/$s/") ?>" href="<?= APP_URL ?>/contracts/<?= $s ?>/list.php">
      <i class="bi <?= $_ct_icon($s) ?>"></i> <?= h($_ct_label($s)) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php
  $_obs_um_has    = module_enabled('approvals_enabled') || module_enabled('letters_enabled') || module_enabled('terminations_enabled');
  $_obs_um_active = str_contains($_uri,'/contracts/approvals') || str_contains($_uri,'/contracts/letters') || str_contains($_uri,'/admin/terminations');
  if ($_obs_um_has):
  ?>
  <button type="button" class="sb-type-btn <?= $_obs_um_active?'type-open':'' ?>" data-bs-toggle="collapse" data-bs-target="#sb-um-obsluga" aria-expanded="<?= $_obs_um_active?'true':'false' ?>">
    <i class="bi bi-gear-wide-connected"></i> Obsługa umów
    <?php $_oub = $_pending + $_term_pending; if ($_oub): ?><span class="badge bg-warning text-dark ms-auto" style="font-size:.62rem"><?= $_oub ?></span><?php endif; ?>
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_obs_um_active?'show':'' ?>" id="sb-um-obsluga">
    <?php if (module_enabled('approvals_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/contracts/approvals/') ?>" href="<?= APP_URL ?>/contracts/approvals/index.php">
      <i class="bi bi-check2-square"></i> Akceptacje
      <?php if ($_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_pending ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (module_enabled('letters_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/contracts/letters/') ?>" href="<?= APP_URL ?>/contracts/letters/index.php">
      <i class="bi bi-envelope-paper"></i> Pisma
    </a>
    <?php endif; ?>
    <?php if (module_enabled('terminations_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/admin/terminations') ?>" href="<?= APP_URL ?>/admin/terminations.php">
      <i class="bi bi-file-earmark-x"></i> Rozwiązania
      <?php if ($_term_pending): ?><span class="badge bg-danger ms-auto"><?= $_term_pending ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ════════════════════════════════════════
       2. WOLONTARIAT — obsługa wolontariatu
  ════════════════════════════════════════ -->
  <div class="sb-label">Wolontariat</div>
  <?php if (module_enabled('contract_wolontariat')): ?>
  <a class="sb-link<?= _nav_active('/contracts/wolontariat/') ?>" href="<?= APP_URL ?>/contracts/wolontariat/list.php">
    <i class="bi bi-heart"></i> Wolontariusze
  </a>
  <?php endif; ?>
  <a class="sb-link<?= _nav_active('/contracts/rekrutacja/') ?>" href="<?= APP_URL ?>/contracts/rekrutacja/index.php">
    <i class="bi bi-megaphone"></i> Zgłoszenia
    <?php if ($_rek_new): ?><span class="badge bg-primary ms-auto"><?= $_rek_new ?></span><?php endif; ?>
  </a>
  <?php if (module_enabled('onboarding_enabled')): ?>
  <a class="sb-link<?= _nav_active('/onboarding/') ?>" href="<?= APP_URL ?>/onboarding/index.php">
    <i class="bi bi-person-check"></i> Onboarding
    <?php if ($_ob_new): ?><span class="badge bg-warning text-dark ms-auto"><?= $_ob_new ?></span><?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if (module_enabled('timesheets_enabled')): ?>
  <a class="sb-link<?= _nav_active('/admin/timesheets') ?>" href="<?= APP_URL ?>/admin/timesheets.php">
    <i class="bi bi-clock-history"></i> Ewidencja godzin
    <?php if ($_ts_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_ts_pending ?></span><?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if (module_enabled('certificates_enabled')): ?>
  <a class="sb-link<?= _nav_active('/admin/certificates') . _nav_active('/certificates/') ?>" href="<?= APP_URL ?>/admin/certificates.php">
    <i class="bi bi-award"></i> Zaświadczenia
    <?php if ($_cert_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_cert_pending ?></span><?php endif; ?>
  </a>
  <?php endif; ?>

  <!-- ════════════════════════════════════════
       2b. KANCELARIA EZD — samodzielny moduł
  ════════════════════════════════════════ -->
  <?php if (module_enabled('ezd_enabled')): ?>
  <div class="sb-label">Kancelaria EZD</div>
  <a class="sb-link<?= _nav_active('/ezd/index.php') ?>" href="<?= APP_URL ?>/ezd/index.php">
    <i class="bi bi-building-gear"></i> Pulpit kancelarii
  </a>
  <a class="sb-link<?= _nav_active('/ezd/rpw/') ?>" href="<?= APP_URL ?>/ezd/rpw/index.php">
    <i class="bi bi-mailbox2"></i> Dziennik podawczy
  </a>
  <a class="sb-link<?= _nav_active('/ezd/sprawy/') ?>" href="<?= APP_URL ?>/ezd/sprawy/index.php">
    <i class="bi bi-folder2-open"></i> Sprawy
  </a>
  <a class="sb-link<?= _nav_active('/ezd/teczki/') ?>" href="<?= APP_URL ?>/ezd/teczki/index.php">
    <i class="bi bi-archive"></i> Teczki aktowe
  </a>
  <a class="sb-link<?= _nav_active('/ezd/jrwa/') ?>" href="<?= APP_URL ?>/ezd/jrwa/index.php">
    <i class="bi bi-tags"></i> Wykaz akt (JRWA)
  </a>
  <a class="sb-link<?= _nav_active('/ezd/pelnomocnictwa/') ?>" href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php">
    <i class="bi bi-person-vcard"></i> Rejestr pełnomocnictw
  </a>
  <a class="sb-link<?= _nav_active('/ezd/zaswiadczenia/') ?>" href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">
    <i class="bi bi-award"></i> Rejestr zaświadczeń
  </a>
  <?php endif; ?>

  <!-- ════════════════════════════════════════
       3. IT — dostępy, konta, hasła
  ════════════════════════════════════════ -->
  <?php $_it_active = str_contains($_uri, '/it/'); ?>
  <div class="sb-label">IT</div>
  <button type="button" class="sb-type-btn <?= $_it_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-it" aria-expanded="<?= $_it_active ? 'true' : 'false' ?>">
    <i class="bi bi-hdd-network"></i> Dostępy IT
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_it_active ? 'show' : '' ?>" id="sb-it">
    <a class="sb-sub-link<?= _nav_active('/it/index') ?>" href="<?= APP_URL ?>/it/index.php"><i class="bi bi-grid-1x2"></i> Dashboard IT</a>
    <a class="sb-sub-link<?= _nav_active('/it/accounts') ?>" href="<?= APP_URL ?>/it/accounts.php"><i class="bi bi-person-badge"></i> Konta</a>
    <a class="sb-sub-link<?= _nav_active('/it/passwords') ?>" href="<?= APP_URL ?>/it/passwords.php"><i class="bi bi-key"></i> Hasła</a>
    <?php if (is_admin()): ?>
    <a class="sb-sub-link<?= _nav_active('/it/services') ?>" href="<?= APP_URL ?>/it/services.php"><i class="bi bi-gear"></i> Serwisy IT</a>
    <?php endif; ?>
  </div>

  <div class="sb-sep"></div>

  <!-- ════════════════════════════════════════
       4. FINANSE
  ════════════════════════════════════════ -->
  <div class="sb-label">Finanse</div>

  <?php if (menu_visible('grants')): ?>
  <a class="sb-link<?= _nav_active('/grants/') ?>" href="<?= APP_URL ?>/grants/index.php">
    <i class="bi bi-cash-coin"></i> Granty
  </a>
  <?php endif; ?>
  <?php if (menu_visible('actions')): ?>
  <a class="sb-link<?= _nav_active('/strategy/actions/') . _nav_active('/actions/') ?>"
     href="<?= APP_URL ?>/strategy/actions/index.php">
    <i class="bi bi-calendar-event"></i> Działania
  </a>
  <?php endif; ?>
  <?php if (can_read('umowy') || is_admin()): ?>
  <a class="sb-link<?= _nav_active('/strategy/') && !str_contains($_uri,'/strategy/actions/') ? ' active' : '' ?>"
     href="<?= APP_URL ?>/strategy/index.php">
    <i class="bi bi-bullseye"></i> Strategia
  </a>
  <?php endif; ?>

  <a class="sb-link<?= _nav_active('/contracts/zwroty/') ?>" href="<?= APP_URL ?>/contracts/zwroty/index.php">
    <i class="bi bi-receipt-cutoff"></i> Zwroty kosztów
    <?php if ($_zwr_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_zwr_pending ?></span><?php endif; ?>
  </a>

  <?php if ($_has_kdok):
    $_kdok_active = str_contains($_uri, '/ksiegowosc/');
    $_kdok_sub_has = (is_admin() || kdok_has_role('zatwierdza'))
                  || ((is_admin() || kdok_has_role('upload')) && org_setting('kdok_ksef_enabled') === '1');
  ?>
  <?php if ($_kdok_sub_has): ?>
  <button type="button" class="sb-type-btn <?= $_kdok_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-eod"
          aria-expanded="<?= $_kdok_active ? 'true' : 'false' ?>">
    <i class="bi bi-file-earmark-check"></i> EOD Dok. Księgowych
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_kdok_active ? 'show' : '' ?>" id="sb-eod">
    <a class="sb-sub-link<?= _nav_active('/ksiegowosc/index') . (_nav_active('/ksiegowosc/view') ?: _nav_active('/ksiegowosc/add') ?: _nav_active('/ksiegowosc/zip')) ?>"
       href="<?= APP_URL ?>/ksiegowosc/index.php">
      <i class="bi bi-list-ul"></i> Lista dokumentów
    </a>
    <?php if (is_admin() || kdok_has_role('zatwierdza')): ?>
    <a class="sb-sub-link<?= _nav_active('/ksiegowosc/przegladaj') ?>" href="<?= APP_URL ?>/ksiegowosc/przegladaj.php">
      <i class="bi bi-archive"></i> Archiwum EOD
    </a>
    <?php endif; ?>
    <?php if ((is_admin() || kdok_has_role('upload')) && org_setting('kdok_ksef_enabled') === '1'): ?>
    <a class="sb-sub-link<?= _nav_active('/ksiegowosc/ksef_sync') ?>" href="<?= APP_URL ?>/ksiegowosc/ksef_sync.php">
      <i class="bi bi-receipt-cutoff"></i> KSeF synchronizacja
    </a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <a class="sb-link<?= _nav_active('/ksiegowosc/') ?>" href="<?= APP_URL ?>/ksiegowosc/index.php">
    <i class="bi bi-file-earmark-check"></i> EOD Dok. Księgowych
  </a>
  <?php endif; ?>
  <?php endif; ?>

  <?php
  $_res_active = str_contains($_uri, '/resources/');
  ?>
  <button type="button" class="sb-type-btn <?= $_res_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-resources"
          aria-expanded="<?= $_res_active ? 'true' : 'false' ?>">
    <i class="bi bi-box-seam"></i> Zasoby
    <?php if ($_res_badge): ?><span class="badge bg-danger ms-auto" style="font-size:.62rem"><?= $_res_badge ?></span><?php endif; ?>
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_res_active ? 'show' : '' ?>" id="sb-resources">
    <a class="sb-sub-link<?= str_contains($_uri,'/resources/') && !str_contains($_uri,'/resources/admin') ? ' nav-active' : '' ?>" href="<?= APP_URL ?>/resources/">
      <i class="bi bi-search"></i> Przeglądaj zasoby
    </a>
    <a class="sb-sub-link<?= _nav_active('/resources/my') ?>" href="<?= APP_URL ?>/resources/my.php">
      <i class="bi bi-calendar-check"></i> Moje rezerwacje
    </a>
    <?php if (is_admin() || can_write('resources')): ?>
    <a class="sb-sub-link<?= str_contains($_uri,'/resources/admin') ? ' nav-active' : '' ?>" href="<?= APP_URL ?>/resources/admin/">
      <i class="bi bi-calendar2-check-fill"></i> Zatwierdź
      <?php if ($_res_badge): ?><span class="badge bg-danger ms-auto"><?= $_res_badge ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
  </div>

  <?php if ($_has_shipping): ?>
  <a class="sb-link<?= _nav_active('/admin/shipments') ?>" href="<?= APP_URL ?>/admin/shipments.php">
    <i class="bi bi-truck"></i> Przesyłki
    <?php if ($_ship_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_ship_pending ?></span><?php endif; ?>
  </a>
  <?php endif; ?>

  <div class="sb-sep"></div>

  <!-- ════════════════════════════════════════
       5. RODO
  ════════════════════════════════════════ -->
  <div class="sb-label">RODO</div>
  <a class="sb-link<?= _nav_active('/rodo/') ?>" href="<?= APP_URL ?>/rodo/index.php">
    <i class="bi bi-shield-lock"></i> Rejestr RODO
  </a>

  <div class="sb-sep"></div>

  <div class="sb-label">Pozostałe</div>

  <!-- LUDZIE (zwijane) -->
  <?php $_ludzie_active = str_contains($_uri,'/persons/') || str_contains($_uri,'/crm/') || str_contains($_uri,'/directory/') || str_contains($_uri,'/org/') || str_contains($_uri,'/byli/'); ?>
  <button type="button" class="sb-type-btn <?= $_ludzie_active?'type-open':'' ?>" data-bs-toggle="collapse" data-bs-target="#sb-ludzie" aria-expanded="<?= $_ludzie_active?'true':'false' ?>">
    <i class="bi bi-people"></i> Ludzie
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_ludzie_active?'show':'' ?>" id="sb-ludzie">
    <a class="sb-sub-link<?= _nav_active('/persons/') ?>" href="<?= APP_URL ?>/persons/index.php"><i class="bi bi-people"></i> Osoby</a>
    <?php if (module_enabled('crm_enabled') && can_read('crm')): ?>
    <a class="sb-sub-link<?= _nav_active('/crm/') ?>" href="<?= APP_URL ?>/crm/dashboard.php"><i class="bi bi-diagram-2"></i> CRM</a>
    <?php endif; ?>
    <a class="sb-sub-link<?= _nav_active('/directory/') ?>" href="<?= APP_URL ?>/directory/"><i class="bi bi-person-lines-fill"></i> Katalog osób</a>
    <?php if (module_enabled('org_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/org/') ?>" href="<?= APP_URL ?>/org/index.php"><i class="bi bi-diagram-3"></i> Struktura org.</a>
    <?php endif; ?>
    <?php if (module_enabled('byli_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/byli/') ?>" href="<?= APP_URL ?>/byli/index.php"><i class="bi bi-person-dash"></i> Byłe osoby</a>
    <?php endif; ?>
  </div>

  <!-- OBSŁUGA (zwijane) -->
  <?php
  $_obs_has    = module_enabled('helpdesk_enabled') || module_enabled('messages_enabled') || module_enabled('events_enabled');
  $_obs_active = str_contains($_uri,'/helpdesk/') || str_contains($_uri,'/admin/email_aliasy') || str_contains($_uri,'/admin/messages') || str_contains($_uri,'/events/');
  $_obs_badge  = (int)$_hd_open + (int)$_msg_unread_total + ($_alias_is_op ? (int)$_alias_pending : 0);
  if ($_obs_has):
  ?>
  <button type="button" class="sb-type-btn <?= $_obs_active?'type-open':'' ?>" data-bs-toggle="collapse" data-bs-target="#sb-obsluga" aria-expanded="<?= $_obs_active?'true':'false' ?>">
    <i class="bi bi-headset"></i> Obsługa
    <?php if ($_obs_badge): ?><span class="badge bg-primary ms-auto" style="font-size:.62rem"><?= $_obs_badge ?></span><?php endif; ?>
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_obs_active?'show':'' ?>" id="sb-obsluga">
    <?php if (module_enabled('helpdesk_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/helpdesk/') ?>" href="<?= APP_URL ?>/helpdesk/index.php"><i class="bi bi-ticket-perforated"></i> Helpdesk<?php if ($_hd_open): ?><span class="badge bg-primary ms-auto"><?= $_hd_open ?></span><?php endif; ?></a>
    <?php if ($_alias_is_op): ?>
    <a class="sb-sub-link<?= _nav_active('/admin/email_aliasy') ?>" href="<?= APP_URL ?>/admin/email_aliasy.php"><i class="bi bi-at"></i> Aliasy e-mail<?php if ($_alias_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_alias_pending ?></span><?php endif; ?></a>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (module_enabled('messages_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/admin/messages') ?>" href="<?= APP_URL ?>/admin/messages.php"><i class="bi bi-chat-dots"></i> Wiadomości<span class="badge bg-danger ms-auto" data-msg-sb-badge style="<?= $_msg_unread_total > 0 ? '' : 'display:none' ?>"><?= $_msg_unread_total ?></span></a>
    <?php endif; ?>
    <?php if (module_enabled('events_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/events/') ?>" href="<?= APP_URL ?>/events/dashboard.php"><i class="bi bi-calendar-event"></i> Wydarzenia</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ARCHIWUM I REJESTRY (zwijane) -->
  <?php $_arch_active = str_contains($_uri,'/reports/') || str_contains($_uri,'/correspondence/') || str_contains($_uri,'/procedures/') || str_contains($_uri,'/resolutions/'); ?>
  <button type="button" class="sb-type-btn <?= $_arch_active?'type-open':'' ?>" data-bs-toggle="collapse" data-bs-target="#sb-arch" aria-expanded="<?= $_arch_active?'true':'false' ?>">
    <i class="bi bi-archive"></i> Archiwum i rejestry
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_arch_active?'show':'' ?>" id="sb-arch">
    <?php if (module_enabled('reports_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/reports/') ?>" href="<?= APP_URL ?>/reports/index.php"><i class="bi bi-bar-chart-line"></i> Raporty</a>
    <?php endif; ?>
    <a class="sb-sub-link<?= _nav_active('/correspondence/') ?>" href="<?= APP_URL ?>/correspondence/index.php"><i class="bi bi-mailbox"></i> Korespondencja</a>
    <a class="sb-sub-link<?= _nav_active('/procedures/') ?>" href="<?= APP_URL ?>/procedures/index.php"><i class="bi bi-list-task"></i> Procedury</a>
    <a class="sb-sub-link<?= _nav_active('/resolutions/') ?>" href="<?= APP_URL ?>/resolutions/index.php"><i class="bi bi-file-ruled"></i> Uchwały</a>
  </div>

  <div class="sb-sep"></div>

  <!-- ════════════════════════════════════════
       7. ADMIN [if is_admin]
  ════════════════════════════════════════ -->
  <?php if (is_admin()): ?>
  <div class="sb-label">Admin</div>
  <a class="sb-link<?= str_contains($_uri,'/admin/') && !str_contains($_uri,'/admin/messages') && !str_contains($_uri,'/admin/terminations') && !str_contains($_uri,'/admin/certificates') && !str_contains($_uri,'/admin/timesheets') && !str_contains($_uri,'/admin/shipments') && !str_contains($_uri,'/admin/onboarding') && !str_contains($_uri,'/admin/sharepoint') && !str_contains($_uri,'/admin/m365') ? ' nav-active' : '' ?>" href="<?= APP_URL ?>/admin/index.php">
    <i class="bi bi-shield-shaded"></i> Panel admina
    <?php if ($_adm_badge): ?><span class="badge bg-danger ms-auto"><?= $_adm_badge ?></span><?php endif; ?>
  </a>
  <a class="sb-link<?= _nav_active('/admin/m365') ?>" href="<?= APP_URL ?>/admin/m365_settings.php">
    <i class="bi bi-microsoft"></i> Microsoft 365
  </a>
  <a class="sb-link<?= _nav_active('/admin/sharepoint') ?>" href="<?= APP_URL ?>/admin/sharepoint_settings.php">
    <i class="bi bi-cloud-upload"></i> SharePoint
  </a>
  <?php endif; ?>

  <?php endif; /* can_edit */ ?>
  <?php endif; /* ezd_only */ ?>
  <?php endif; /* _user */ ?>

  <!-- ── Użytkownik + wylogowanie ─────────────────────────────── -->
  <div class="sb-footer">
    <?php if ($_user): ?>
    <div class="sb-user"><i class="bi bi-person-circle"></i> <?= h($_user['name']) ?></div>
    <?php if ($_is_service_acc && $_saas_url): ?>
    <a href="<?= h($_saas_url) ?>" style="color:#a78bfa">
      <i class="bi bi-server me-1"></i>Panel SaaS
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right"></i> Wyloguj</a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/auth/login.php"><i class="bi bi-box-arrow-in-right"></i> Zaloguj</a>
    <?php endif; ?>
  </div>

</nav>

<!-- ── MAIN ────────────────────────────────────────────────────── -->
<div id="main">

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

  <div id="topbar">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" style="padding:.25rem .5rem">
      <i class="bi bi-list"></i>
    </button>
    <span class="page-title"><?= h($_page_title) ?></span>
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
    <div class="dropdown" id="mod-sw">
      <button type="button" class="mod-sw-btn"
              data-bs-toggle="dropdown" data-bs-auto-close="outside"
              aria-expanded="false"
              aria-label="Przełącz moduł — aktualnie: <?= h($_sw_label) ?>">
        <i class="bi <?= $_sw_icon ?>"></i>
        <span class="mod-sw-cur"><?= h($_sw_label) ?></span>
        <i class="bi bi-chevron-down" style="font-size:.55rem;opacity:.45;margin-left:.05rem"></i>
      </button>

      <div class="dropdown-menu p-0 shadow mod-sw-panel">
        <div class="mod-sw-head">Przejdź do modułu</div>
        <div class="mod-sw-grid">

          <a href="<?= APP_URL ?>/index.php"
             class="msw-tile <?= $_on_szo ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#2563eb;--mb:#eff6ff"><i class="bi bi-building"></i></div>
            <span>SZO</span>
          </a>

          <?php if (module_enabled('crm_enabled') && can_read('crm')): ?>
          <a href="<?= APP_URL ?>/crm/dashboard.php"
             class="msw-tile <?= $_on_crm ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#16a34a;--mb:#f0fdf4"><i class="bi bi-diagram-2-fill"></i></div>
            <span>CRM</span>
          </a>
          <?php endif; ?>

          <?php if (module_enabled('tasks_enabled')): ?>
          <a href="<?= APP_URL ?>/tasks/dashboard.php"
             class="msw-tile <?= $_on_tasks ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#ea580c;--mb:#fff7ed"><i class="bi bi-kanban"></i></div>
            <span>Zadania</span>
          </a>
          <?php endif; ?>

          <a href="<?= APP_URL ?>/contracts/wolontariat/list.php"
             class="msw-tile <?= $_on_wol ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#e11d48;--mb:#fff1f2"><i class="bi bi-heart-fill"></i></div>
            <span>Wolontariusze</span>
          </a>

          <a href="<?= APP_URL ?>/strategy/actions/index.php"
             class="msw-tile <?= $_on_actions ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#0891b2;--mb:#ecfeff"><i class="bi bi-calendar-event"></i></div>
            <span>Działania</span>
          </a>

          <a href="<?= APP_URL ?>/grants/index.php"
             class="msw-tile <?= str_contains($_uri,'/grants/') ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#15803d;--mb:#f0fdf4"><i class="bi bi-cash-coin"></i></div>
            <span>Granty</span>
          </a>

          <a href="<?= APP_URL ?>/directory/"
             class="msw-tile <?= $_on_dir ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#4338ca;--mb:#eef2ff"><i class="bi bi-person-lines-fill"></i></div>
            <span>Katalog</span>
          </a>

          <a href="<?= APP_URL ?>/strategy/index.php"
             class="msw-tile <?= (str_contains($_uri,'/strategy/') && !str_contains($_uri,'/strategy/actions/')) ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#7c3aed;--mb:#f5f3ff"><i class="bi bi-bullseye"></i></div>
            <span>Strategia</span>
          </a>

          <a href="<?= APP_URL ?>/reports/index.php"
             class="msw-tile <?= str_contains($_uri,'/reports/') ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#0284c7;--mb:#f0f9ff"><i class="bi bi-bar-chart-line"></i></div>
            <span>Raporty</span>
          </a>

          <?php if (can_read('karty30')): ?>
          <a href="<?= APP_URL ?>/karty30/index.php"
             class="msw-tile <?= $_on_k30 ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#6d28d9;--mb:#f5f3ff"><i class="bi bi-card-checklist"></i></div>
            <span>Karty 30</span>
          </a>
          <?php endif; ?>

          <?php if (module_enabled('helpdesk_enabled')): ?>
          <a href="<?= APP_URL ?>/helpdesk/index.php"
             class="msw-tile <?= str_contains($_uri,'/helpdesk/') ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#b45309;--mb:#fffbeb"><i class="bi bi-ticket-perforated"></i></div>
            <span>Helpdesk</span>
          </a>
          <?php endif; ?>

          <a href="<?= APP_URL ?>/rodo/index.php"
             class="msw-tile <?= $_on_rodo ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#475569;--mb:#f8fafc"><i class="bi bi-shield-lock"></i></div>
            <span>RODO</span>
          </a>

          <?php if (is_admin()): ?>
          <a href="<?= APP_URL ?>/admin/index.php"
             class="msw-tile <?= $_on_admin ? 'msw-on' : '' ?>">
            <div class="msw-ic" style="--mc:#1e293b;--mb:#f1f5f9"><i class="bi bi-gear-fill"></i></div>
            <span>Admin</span>
          </a>
          <?php endif; ?>

        </div><!-- /mod-sw-grid -->

        <div class="mod-sw-footer">
          <a href="<?= APP_URL ?>/portal.php">
            <i class="bi bi-grid-3x3-gap me-1"></i>Portal — wszystkie moduły
          </a>
        </div>
      </div><!-- /mod-sw-panel -->

    </div><!-- /#mod-sw -->
    <style>
    /* ── Waffle module switcher ───────────────────────────────── */
    .mod-sw-btn {
      display:inline-flex;align-items:center;gap:.3rem;
      background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;
      padding:.28rem .6rem;font-size:.8rem;font-weight:500;color:#334155;
      cursor:pointer;line-height:1.4;transition:all .12s;white-space:nowrap;flex-shrink:0;
    }
    .mod-sw-btn:hover,.mod-sw-btn[aria-expanded="true"] {
      background:#eff6ff;border-color:#93c5fd;color:#1d4ed8;
    }
    .mod-sw-cur { max-width:80px;overflow:hidden;text-overflow:ellipsis; }
    .mod-sw-panel { border-radius:14px !important;min-width:0 !important; }
    .mod-sw-head {
      padding:.6rem .7rem .25rem;
      font-size:.63rem;font-weight:700;text-transform:uppercase;
      letter-spacing:.09em;color:#94a3b8;
    }
    .mod-sw-grid {
      display:grid;grid-template-columns:repeat(3,1fr);
      gap:.15rem;padding:.15rem .45rem .35rem;
    }
    .msw-tile {
      display:flex;flex-direction:column;align-items:center;gap:.28rem;
      padding:.5rem .25rem;border-radius:10px;text-decoration:none;
      color:#374151;font-size:.7rem;font-weight:500;text-align:center;
      transition:background .1s,color .1s;line-height:1.25;
    }
    .msw-tile:hover { background:#f8fafc;color:#1e293b; }
    .msw-tile.msw-on { background:#eff6ff;color:#1d4ed8; }
    .msw-ic {
      width:38px;height:38px;border-radius:10px;
      background:var(--mb,#f8fafc);color:var(--mc,#64748b);
      display:flex;align-items:center;justify-content:center;
      font-size:1.05rem;transition:transform .12s;flex-shrink:0;
    }
    .msw-tile:hover .msw-ic { transform:scale(1.08); }
    .msw-tile.msw-on .msw-ic { background:#dbeafe;color:#1d4ed8; }
    .mod-sw-footer {
      padding:.4rem .7rem .55rem;
      border-top:1px solid #f1f5f9;margin-top:.15rem;
    }
    .mod-sw-footer a {
      font-size:.74rem;color:#64748b;text-decoration:none;
      display:inline-flex;align-items:center;gap:.25rem;
    }
    .mod-sw-footer a:hover { color:#1e293b; }
    </style>
      <!-- Szukajka — w ramach nawigacji modułów -->
      <div class="tb-search-wrap d-none d-md-block" id="qs-wrap" style="position:relative;margin-left:.25rem">
      <i class="tb-search-icon bi bi-search" aria-hidden="true"></i>
      <form method="get" action="<?= APP_URL ?>/search.php" id="qs-form" autocomplete="off">
        <input type="search" name="q" id="topbar-search"
               placeholder="Szukaj modułu lub danych… (Ctrl+K)"
               autocomplete="off"
               aria-label="Szukajka modułów i danych"
               aria-expanded="false"
               aria-controls="qs-dropdown"
               aria-autocomplete="list"
               style="padding-left:1.8rem"
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
      var wrap   = document.getElementById('qs-wrap');
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
            style="background:none;border:1px solid #e2e8f0;border-radius:6px;padding:.18rem .45rem;font-size:.72rem;color:#94a3b8;cursor:pointer;line-height:1.4;transition:all .1s;white-space:nowrap;flex-shrink:0"
            onmouseover="this.style.borderColor='#94a3b8';this.style.color='#475569'"
            onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#94a3b8'">
      <kbd style="background:none;border:none;padding:0;font-size:inherit;color:inherit;font-family:inherit">?</kbd>
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
            style="background:none;border:1px solid #fca5a5;border-radius:6px;
                   padding:.18rem .5rem;font-size:.78rem;color:#dc2626;cursor:pointer;
                   line-height:1.5;transition:all .12s;white-space:nowrap;flex-shrink:0;
                   display:inline-flex;align-items:center;gap:.3rem">
      <i class="bi bi-bug-fill" style="font-size:.85rem"></i>
      <span class="d-none d-sm-inline">Zgłoś błąd</span>
    </button>
    <?php endif; ?>

    <?php if ($_user):
    $_notif_count  = notif_unread_count((int)$_user['id']);
    $_notif_latest = notif_latest((int)$_user['id'], 6);
    ?>
    <div class="dropdown me-2" id="notif-bell">
      <button type="button" class="btn btn-sm btn-outline-secondary position-relative"
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
      <button class="topbar-user-chip border-0" type="button" data-bs-toggle="dropdown">
        <span class="avatar"><?= h($_initials_tb ?? mb_strtoupper(mb_substr($_user['name'],0,1))) ?></span>
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
