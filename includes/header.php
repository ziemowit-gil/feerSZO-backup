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

/* Moduły główne — kompaktowe */
.tb-mods {
    display: inline-flex; align-items: center; gap: 2px;
    background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 10px; padding: 3px;
}
.tb-mod {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .28rem .55rem; border-radius: 7px;
    font-size: .78rem; font-weight: 500;
    text-decoration: none; color: #64748b;
    transition: background .1s, color .1s;
    white-space: nowrap;
}
.tb-mod i { font-size: .95rem; }
/* Domyślnie: tylko ikona */
.tb-mod .tb-label { display: none; }
/* Aktywny: ikona + etykieta */
.tb-mod.active-mode {
    background: #fff; color: #2563eb; font-weight: 700;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
}
.tb-mod.active-mode .tb-label { display: inline; }
.tb-mod:hover:not(.active-mode) { background: #fff; color: #334155; }

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

/* Dropdown Więcej */
.tb-more-btn {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .28rem .55rem; border-radius: 7px;
    font-size: .78rem; font-weight: 500; color: #64748b;
    background: none; border: none; cursor: pointer;
    transition: background .1s;
}
.tb-more-btn:hover { background: #f1f5f9; color: #334155; }
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

  <?php if ($_user): ?>

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

  <?php else: ?>
  <!-- ══ WIDOK EDYTORA / ADMINA — 4 GRUPY ════════════════════════ -->
  <?php
  // Pomocnicze zmienne aktywności
  $_on_it       = str_contains($_uri, '/it/');
  $_on_people   = str_contains($_uri,'/persons/') || str_contains($_uri,'/contracts/wolontariat') || str_contains($_uri,'/onboarding/') || str_contains($_uri,'/contracts/rekrutacja') || str_contains($_uri,'/crm/') || str_contains($_uri,'/directory/') || str_contains($_uri,'/admin/messages') || str_contains($_uri,'/admin/terminations') || str_contains($_uri,'/admin/certificates') || str_contains($_uri,'/admin/onboarding');
  $_on_docs     = (str_contains($_uri,'/contracts/') && !str_contains($_uri,'/contracts/wolontariat') && !str_contains($_uri,'/contracts/rekrutacja')) || str_contains($_uri,'/reports/') || str_contains($_uri,'/resolutions/') || str_contains($_uri,'/correspondence/') || str_contains($_uri,'/procedures/') || str_contains($_uri,'/ezd/') || str_contains($_uri,'/contracts/approvals') || str_contains($_uri,'/contracts/letters');
  $_on_fin      = str_contains($_uri,'/contracts/zwroty') || str_contains($_uri,'/grants/') || str_contains($_uri,'/actions/') || str_contains($_uri,'/ksiegowosc/') || str_contains($_uri,'/admin/timesheets') || str_contains($_uri,'/admin/shipments') || str_contains($_uri,'/resources/') || str_contains($_uri,'/panel/timesheets');
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

  $_people_badge = $_msg_unread_total + $_term_pending + $_cert_pending + $_rek_new + $_ob_new;
  $_fin_badge    = $_zwr_pending + $_ts_pending + $_ship_pending + $_res_badge;
  $_docs_badge   = $_pending; // approvals
  require_once __DIR__ . '/ksiegowosc.php'; kdok_migrate();
  $_has_kdok = kdok_has_role('upload') || kdok_has_role('meryt') || kdok_has_role('formal') || kdok_has_role('zatwierdza') || is_admin();
  ?>

  <!-- ════════════════════════════════════════
       LUDZIE — wolontariat, osoby, CRM, obsługa
  ════════════════════════════════════════ -->
  <div class="sb-label">Ludzie</div>

  <?php if (module_enabled('contract_wolontariat')): ?>
  <a class="sb-link<?= _nav_active('/contracts/wolontariat/') ?>" href="<?= APP_URL ?>/contracts/wolontariat/list.php">
    <i class="bi bi-heart"></i> Wolontariusze
  </a>
  <?php endif; ?>

  <a class="sb-link<?= _nav_active('/persons/') ?>" href="<?= APP_URL ?>/persons/index.php">
    <i class="bi bi-people"></i> Osoby
  </a>

  <?php if (module_enabled('crm_enabled') && can_read('crm')): ?>
  <a class="sb-link<?= _nav_active('/crm/') ?>" href="<?= APP_URL ?>/crm/dashboard.php">
    <i class="bi bi-diagram-2"></i> CRM
  </a>
  <?php endif; ?>

  <?php
  $_rek_ob_active = str_contains($_uri,'/contracts/rekrutacja') || str_contains($_uri,'/onboarding/');
  $_rek_ob_badge  = $_rek_new + $_ob_new;
  if (can_edit()):
  ?>
  <button type="button" class="sb-type-btn <?= $_rek_ob_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-rekrutacja"
          aria-expanded="<?= $_rek_ob_active ? 'true' : 'false' ?>">
    <i class="bi bi-person-plus"></i> Rekrutacja
    <?php if ($_rek_ob_badge): ?><span class="badge bg-primary ms-auto" style="font-size:.62rem"><?= $_rek_ob_badge ?></span><?php endif; ?>
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_rek_ob_active ? 'show' : '' ?>" id="sb-rekrutacja">
    <a class="sb-sub-link<?= _nav_active('/contracts/rekrutacja/') ?>" href="<?= APP_URL ?>/contracts/rekrutacja/index.php">
      <i class="bi bi-megaphone"></i> Zgłoszenia
      <?php if ($_rek_new): ?><span class="badge bg-primary ms-auto"><?= $_rek_new ?></span><?php endif; ?>
    </a>
    <?php if (module_enabled('onboarding_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/onboarding/') ?>" href="<?= APP_URL ?>/onboarding/index.php">
      <i class="bi bi-person-check"></i> Onboarding
      <?php if ($_ob_new): ?><span class="badge bg-warning text-dark ms-auto"><?= $_ob_new ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php
  $_obsługa_active = str_contains($_uri,'/admin/messages') || str_contains($_uri,'/admin/terminations') || str_contains($_uri,'/admin/certificates');
  $_obs_badge = $_msg_unread_total + $_term_pending + $_cert_pending;
  ?>
  <button type="button" class="sb-type-btn <?= $_obsługa_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-obsluga"
          aria-expanded="<?= $_obsługa_active ? 'true' : 'false' ?>">
    <i class="bi bi-headset"></i> Obsługa
    <?php if ($_obs_badge): ?><span class="badge bg-danger ms-auto" style="font-size:.62rem"><?= $_obs_badge ?></span><?php endif; ?>
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_obsługa_active ? 'show' : '' ?>" id="sb-obsluga">
    <?php if (module_enabled('messages_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/admin/messages') ?>" href="<?= APP_URL ?>/admin/messages.php">
      <i class="bi bi-chat-dots"></i> Wiadomości
      <span class="badge bg-danger ms-auto" data-msg-sb-badge style="<?= $_msg_unread_total > 0 ? '' : 'display:none' ?>"><?= $_msg_unread_total ?></span>
    </a>
    <?php endif; ?>
    <?php if (module_enabled('terminations_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/admin/terminations') ?>" href="<?= APP_URL ?>/admin/terminations.php">
      <i class="bi bi-file-earmark-x"></i> Rozwiązania
      <?php if ($_term_pending): ?><span class="badge bg-danger ms-auto"><?= $_term_pending ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (module_enabled('certificates_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/admin/certificates') . _nav_active('/certificates/') ?>" href="<?= APP_URL ?>/admin/certificates.php">
      <i class="bi bi-award"></i> Zaświadczenia
      <?php if ($_cert_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_cert_pending ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <a class="sb-sub-link<?= _nav_active('/directory/') ?>" href="<?= APP_URL ?>/directory/">
      <i class="bi bi-person-lines-fill"></i> Katalog osób
    </a>
  </div>

  <div class="sb-sep"></div>

  <!-- ════════════════════════════════════════
       IT — Dostępy i Infrastruktura
  ════════════════════════════════════════ -->
  <?php if (can_edit()): ?>
  <?php
  $_it_active = str_contains($_uri, '/it/');
  ?>
  <button type="button" class="sb-type-btn <?= $_it_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-it"
          aria-expanded="<?= $_it_active ? 'true' : 'false' ?>">
    <i class="bi bi-hdd-network" style="color:#fd7e14"></i>
    <span style="<?= $_it_active ? '' : '' ?>">Dostępy IT</span>
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_it_active ? 'show' : '' ?>" id="sb-it">
    <a class="sb-sub-link<?= _nav_active('/it/index') ?>" href="<?= APP_URL ?>/it/index.php">
      <i class="bi bi-grid-1x2" style="color:#fd7e14"></i> Dashboard IT
    </a>
    <a class="sb-sub-link<?= _nav_active('/it/accounts') ?>" href="<?= APP_URL ?>/it/accounts.php">
      <i class="bi bi-person-badge"></i> Konta
    </a>
    <a class="sb-sub-link<?= _nav_active('/it/passwords') ?>" href="<?= APP_URL ?>/it/passwords.php">
      <i class="bi bi-key" style="color:#fd7e14"></i> Hasła
    </a>
    <?php if (is_admin()): ?>
    <a class="sb-sub-link<?= _nav_active('/it/services') ?>" href="<?= APP_URL ?>/it/services.php">
      <i class="bi bi-gear"></i> Serwisy IT
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="sb-sep"></div>

  <!-- ════════════════════════════════════════
       DOKUMENTY — umowy, pisma, raporty
  ════════════════════════════════════════ -->
  <div class="sb-label">Dokumenty</div>

  <?php
  $_contracts_visible = array_filter(array_keys(CONTRACT_TYPES), fn($s) => module_enabled('contract_' . $s) && $s !== 'wolontariat');
  $_contracts_active  = str_contains($_uri, '/contracts/') && !str_contains($_uri,'/contracts/wolontariat') && !str_contains($_uri,'/contracts/rekrutacja') && !str_contains($_uri,'/contracts/zwroty') && !str_contains($_uri,'/contracts/letters') && !str_contains($_uri,'/contracts/approvals');
  if ($_contracts_visible):
  ?>
  <button type="button" class="sb-type-btn <?= $_contracts_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-umowy"
          aria-expanded="<?= $_contracts_active ? 'true' : 'false' ?>">
    <i class="bi bi-file-earmark-text"></i> Umowy
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_contracts_active ? 'show' : '' ?>" id="sb-umowy">
    <?php foreach (CONTRACT_TYPES as $slug => $label):
      if ($slug === 'wolontariat') continue;
      if (!module_enabled('contract_' . $slug)) continue;
      $icon = $_contract_icons[$slug] ?? 'bi-file-text';
    ?>
    <a class="sb-sub-link<?= _nav_active("/contracts/{$slug}/") ?>" href="<?= APP_URL ?>/contracts/<?= $slug ?>/list.php">
      <i class="bi <?= $icon ?>"></i> <?= h($label) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (module_enabled('approvals_enabled')): ?>
  <a class="sb-link<?= _nav_active('/contracts/approvals/') ?>" href="<?= APP_URL ?>/contracts/approvals/index.php">
    <i class="bi bi-check2-square"></i> Akceptacje
    <?php if ($_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_pending ?></span><?php endif; ?>
  </a>
  <?php endif; ?>

  <?php if (module_enabled('letters_enabled')): ?>
  <a class="sb-link<?= _nav_active('/contracts/letters/') ?>" href="<?= APP_URL ?>/contracts/letters/index.php">
    <i class="bi bi-envelope-paper"></i> Pisma
  </a>
  <?php endif; ?>

  <?php
  $_more_docs_active = str_contains($_uri,'/reports/') || str_contains($_uri,'/resolutions/') || str_contains($_uri,'/correspondence/') || str_contains($_uri,'/procedures/') || str_contains($_uri,'/ezd/');
  ?>
  <button type="button" class="sb-type-btn <?= $_more_docs_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-docs-more"
          aria-expanded="<?= $_more_docs_active ? 'true' : 'false' ?>">
    <i class="bi bi-folder2"></i> Więcej
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_more_docs_active ? 'show' : '' ?>" id="sb-docs-more">
    <?php if (module_enabled('reports_enabled')): ?>
    <a class="sb-sub-link<?= _nav_active('/reports/') ?>" href="<?= APP_URL ?>/reports/index.php">
      <i class="bi bi-bar-chart-line"></i> Raporty
    </a>
    <?php endif; ?>
    <a class="sb-sub-link<?= _nav_active('/resolutions/') ?>" href="<?= APP_URL ?>/resolutions/index.php">
      <i class="bi bi-file-ruled"></i> Uchwały
    </a>
    <a class="sb-sub-link<?= _nav_active('/correspondence/') ?>" href="<?= APP_URL ?>/correspondence/index.php">
      <i class="bi bi-mailbox"></i> Korespondencja
    </a>
    <a class="sb-sub-link<?= _nav_active('/procedures/') ?>" href="<?= APP_URL ?>/procedures/index.php">
      <i class="bi bi-list-task"></i> Procedury
    </a>
    <a class="sb-sub-link<?= _nav_active('/ezd/') ?>" href="<?= APP_URL ?>/ezd/index.php">
      <i class="bi bi-archive"></i> EZD
    </a>
  </div>

  <div class="sb-sep"></div>

  <!-- ════════════════════════════════════════
       FINANSE — granty, zwroty, eObieg, zasoby
  ════════════════════════════════════════ -->
  <div class="sb-label">Finanse i zasoby</div>

  <?php if (can_edit()): ?>
  <?php if (menu_visible('grants')): ?>
  <a class="sb-link<?= _nav_active('/grants/') ?>" href="<?= APP_URL ?>/grants/index.php">
    <i class="bi bi-cash-coin"></i> Granty
  </a>
  <?php endif; ?>
  <?php if (menu_visible('actions')): ?>
  <a class="sb-link<?= _nav_active('/actions/') ?>" href="<?= APP_URL ?>/actions/index.php">
    <i class="bi bi-calendar-event"></i> Działania
  </a>
  <?php endif; ?>
  <?php endif; ?>

  <a class="sb-link<?= _nav_active('/contracts/zwroty/') ?>" href="<?= APP_URL ?>/contracts/zwroty/index.php">
    <i class="bi bi-receipt-cutoff"></i> Zwroty kosztów
    <?php if ($_zwr_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_zwr_pending ?></span><?php endif; ?>
  </a>

  <?php if (module_enabled('timesheets_enabled')): ?>
  <a class="sb-link<?= _nav_active('/admin/timesheets') ?>" href="<?= APP_URL ?>/admin/timesheets.php">
    <i class="bi bi-clock-history"></i> Ewidencja godzin
    <?php if ($_ts_pending): ?><span class="badge bg-warning text-dark ms-auto"><?= $_ts_pending ?></span><?php endif; ?>
  </a>
  <?php endif; ?>

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
       ADMIN — panel, ustawienia
  ════════════════════════════════════════ -->
  <?php if (is_admin()): ?>
  <div class="sb-label">Admin</div>
  <a class="sb-link<?= str_contains($_uri,'/admin/') && !$_obsługa_active ? ' nav-active' : '' ?>" href="<?= APP_URL ?>/admin/index.php">
    <i class="bi bi-shield-shaded"></i> Panel admina
  </a>
  <div class="sb-sep"></div>
  <?php endif; ?>

  <!-- ════════════════════════════════════════
       MÓJ OBSZAR — zwinięty domyślnie
  ════════════════════════════════════════ -->
  <?php
  $_my_active = str_contains($_uri,'/panel/') || str_contains($_uri,'/komunikaty/');
  ?>
  <button type="button" class="sb-type-btn <?= $_my_active ? 'type-open' : '' ?>"
          data-bs-toggle="collapse" data-bs-target="#sb-myarea"
          aria-expanded="<?= $_my_active ? 'true' : 'false' ?>">
    <i class="bi bi-person-circle"></i> Mój obszar
    <i class="bi bi-chevron-right sb-chevron"></i>
  </button>
  <div class="collapse sb-sub <?= $_my_active ? 'show' : '' ?>" id="sb-myarea">
    <a class="sb-sub-link<?= _nav_active('/panel/index') ?>" href="<?= APP_URL ?>/panel/index.php">
      <i class="bi bi-house"></i> Mój panel
    </a>
    <a class="sb-sub-link<?= _nav_active('/komunikaty/') ?>" href="<?= APP_URL ?>/komunikaty/index.php">
      <i class="bi bi-megaphone"></i> Komunikaty
      <?php try { $_e_ann_count = count(array_filter(ann_list_for_user((int)$_user['id'], $_user['role'] ?? 'editor'), fn($a) => !(int)($a['is_read_by_me'] ?? 0))); if ($_e_ann_count > 0): ?><span class="badge bg-warning text-dark ms-auto"><?= $_e_ann_count ?></span><?php endif; } catch(\Throwable $e) {} ?>
    </a>
    <a class="sb-sub-link<?= _nav_active('/panel/profile_edit') ?>" href="<?= APP_URL ?>/panel/profile_edit.php">
      <i class="bi bi-person-badge"></i> Mój profil
    </a>
    <a class="sb-sub-link<?= _nav_active('/panel/m365') ?>" href="<?= APP_URL ?>/panel/m365.php">
      <i class="bi bi-microsoft"></i> Microsoft 365
    </a>
  </div>

  <?php endif; /* can_edit */ ?>
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

    <?php if ($_user && can_edit()): ?>
    <?php
      $_uri = $_SERVER['REQUEST_URI'] ?? '';
      $_is_panel_view = str_contains($_uri, '/panel/');
      $_initials_tb = implode('', array_map(
        fn($w) => mb_strtoupper(mb_substr($w,0,1)),
        array_slice(explode(' ', $_user['name']), 0, 2)
      ));
      // Oblicz aktywne sekcje raz
      $_on_szo     = (!str_contains($_uri,'/crm/') && !str_contains($_uri,'/actions/') && !str_contains($_uri,'/events/') && !str_contains($_uri,'/directory/') && !str_contains($_uri,'/karty30/') && !str_contains($_uri,'/tasks/') && !str_contains($_uri,'/admin/') && !$_is_panel_view);
      $_on_crm     = str_contains($_uri,'/crm/');
      $_on_tasks   = str_contains($_uri,'/tasks/') && !str_contains($_uri,'/admin/');
      $_on_admin   = str_contains($_uri,'/admin/');
      $_on_actions = str_contains($_uri,'/actions/');
      $_on_events  = str_contains($_uri,'/events/');
      $_on_dir     = str_contains($_uri,'/directory/');
      $_on_k30     = str_contains($_uri,'/karty30/');
      $_has_more_active = $_on_actions || $_on_events || $_on_dir || $_on_k30;
    ?>
    <!-- Moduły główne — kompaktowe -->
    <nav class="tb-mods" aria-label="Moduły systemu">
      <a href="<?= APP_URL ?>/index.php" class="tb-mod <?= $_on_szo ? 'active-mode' : '' ?>"
         title="SZO — System Zarządzania Organizacją">
        <i class="bi bi-building"></i><span class="tb-label">SZO</span>
      </a>
      <?php if (module_enabled('crm_enabled') && can_read('crm')): ?>
      <a href="<?= APP_URL ?>/crm/dashboard.php" class="tb-mod <?= $_on_crm ? 'active-mode' : '' ?>"
         title="CRM">
        <i class="bi bi-diagram-2-fill"></i><span class="tb-label">CRM</span>
      </a>
      <?php endif; ?>
      <?php if (module_enabled('tasks_enabled')): ?>
      <a href="<?= APP_URL ?>/tasks/dashboard.php" class="tb-mod <?= $_on_tasks ? 'active-mode' : '' ?>"
         title="Zadania">
        <i class="bi bi-kanban"></i><span class="tb-label">Zadania</span>
      </a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/admin/index.php" class="tb-mod <?= $_on_admin ? 'active-mode' : '' ?>"
         title="Panel admina">
        <i class="bi bi-gear-fill"></i><span class="tb-label">Admin</span>
      </a>
      <?php endif; ?>

      <!-- Więcej ▾ -->
      <div class="dropdown">
        <button type="button" class="tb-more-btn <?= $_has_more_active ? 'active-mode' : '' ?>"
                data-bs-toggle="dropdown" aria-expanded="false" title="Więcej modułów">
          <?php if ($_has_more_active): ?>
          <?php // pokaż aktywną ikonę
          if ($_on_actions) echo '<i class="bi bi-calendar-event"></i><span class="tb-label">Działania</span>';
          elseif ($_on_events) echo '<i class="bi bi-calendar-event-fill"></i><span class="tb-label">Wydarzenia</span>';
          elseif ($_on_dir) echo '<i class="bi bi-person-lines-fill"></i><span class="tb-label">Katalog</span>';
          elseif ($_on_k30) echo '<i class="bi bi-card-checklist"></i><span class="tb-label">Karty30</span>';
          ?>
          <?php else: ?>
          <i class="bi bi-grid-3x3-gap"></i>
          <?php endif; ?>
          <i class="bi bi-chevron-down" style="font-size:.6rem;opacity:.6"></i>
        </button>
        <ul class="dropdown-menu shadow" style="min-width:175px;font-size:.83rem">
          <li><h6 class="dropdown-header py-1" style="font-size:.68rem">Więcej modułów</h6></li>
          <li>
            <a class="dropdown-item <?= $_on_actions ? 'active' : '' ?>" href="<?= APP_URL ?>/actions/index.php">
              <i class="bi bi-calendar-event me-2"></i>Działania
            </a>
          </li>
          <?php if (module_enabled('events_enabled')): ?>
          <li>
            <a class="dropdown-item <?= $_on_events ? 'active' : '' ?>" href="<?= APP_URL ?>/events/dashboard.php">
              <i class="bi bi-calendar-event-fill me-2"></i>Wydarzenia
            </a>
          </li>
          <?php endif; ?>
          <li>
            <a class="dropdown-item <?= $_on_dir ? 'active' : '' ?>" href="<?= APP_URL ?>/directory/">
              <i class="bi bi-person-lines-fill me-2"></i>Katalog osób
            </a>
          </li>
          <?php if (can_read('karty30')): ?>
          <li>
            <a class="dropdown-item <?= $_on_k30 ? 'active' : '' ?>" href="<?= APP_URL ?>/karty30/index.php">
              <i class="bi bi-card-checklist me-2"></i>Karty 30
            </a>
          </li>
          <?php endif; ?>
          <li><hr class="dropdown-divider my-1"></li>
          <li>
            <a class="dropdown-item" href="<?= APP_URL ?>/search.php">
              <i class="bi bi-search me-2"></i>Globalne wyszukiwanie
            </a>
          </li>
        </ul>
      </div>
    </nav>
    <?php endif; ?>

    <?php if ($_user): ?>
    <!-- Kompaktowe wyszukiwanie -->
    <div class="tb-search-wrap d-none d-md-block">
      <i class="tb-search-icon bi bi-search"></i>
      <form method="get" action="<?= APP_URL ?>/search.php">
        <input type="search" name="q" id="topbar-search"
               placeholder="Szukaj… (Ctrl+K)"
               autocomplete="off"
               aria-label="Globalne wyszukiwanie"
               style="padding-left:1.8rem"
               onfocus="this.classList.add('expanded')"
               onblur="if(!this.value)this.classList.remove('expanded')">
      </form>
    </div>
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
        <?php endif; ?>

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

  <div id="content">
  <?= flash_html() ?>

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
