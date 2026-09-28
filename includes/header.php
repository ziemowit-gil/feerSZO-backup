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

// Oblicz kontrast: jasny lub ciemny tekst zależnie od tła navbara.
// Zamiast pojedynczego progu luminancji (błędnie klasyfikował niektóre
// nasycone/ciemne kolory jako "jasne", dając ciemny tekst na ciemnym tle),
// liczymy realny współczynnik kontrastu WCAG między tłem a OBOMA wariantami
// tekstu i wybieramy ten, który faktycznie się z tłem lepiej kontrastuje.
function _sb_luminance(string $hex): float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) $hex = '1e293b'; // niepoprawna wartość — bezpieczny fallback
    [$r, $g, $b] = [hexdec(substr($hex,0,2))/255, hexdec(substr($hex,2,2))/255, hexdec(substr($hex,4,2))/255];
    $lin = fn($c) => $c <= .03928 ? $c/12.92 : (($c+.055)/1.055)**2.4;
    return .2126*$lin($r) + .7152*$lin($g) + .0722*$lin($b);
}
/** Współczynnik kontrastu WCAG między dwiema luminancjami (1:1 do 21:1). */
function _sb_contrast(float $l1, float $l2): float {
    $lighter = max($l1, $l2) + 0.05;
    $darker  = min($l1, $l2) + 0.05;
    return $lighter / $darker;
}
$_sb_bg_lum = _sb_luminance($_sb_color);
// Reprezentatywne kolory tekstu z obu gałęzi poniżej (#cbd5e1 jasny / #1e293b ciemny) —
// wygrywa ten, który daje wyższy realny kontrast względem skonfigurowanego tła.
$_sb_dark = _sb_contrast($_sb_bg_lum, _sb_luminance('#cbd5e1')) > _sb_contrast($_sb_bg_lum, _sb_luminance('#1e293b'));

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
<?php if (str_contains($_uri, '/ezd/')):
      $_ezd_css_v = @filemtime(dirname(__DIR__) . '/assets/css/ezd-tz-theme.css') ?: '1'; ?>
<link href="<?= APP_URL ?>/assets/css/ezd-tz-theme.css?v=<?= $_ezd_css_v ?>" rel="stylesheet">
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js" defer></script>
<script src="<?= APP_URL ?>/assets/js/utils.js" defer></script>
<!-- Alpine.js — komponenty potrzebujące płynnych, dostępnych modali bez przeładowania strony.
     Wtyczka focus MUSI ładować się przed rdzeniem Alpine (rejestruje się na obiekcie Alpine przed jego startem). -->
<script src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>
<!-- Tailwind — nowy standard wizualny dla przebudowywanych stron (na razie: moduł umów).
     Klasy z prefiksem "tw-" i wyłączonym preflight, żeby NIGDY nie kolidować z Bootstrapem,
     który zostaje globalnym frameworkiem dla reszty, jeszcze nieprzebudowanej części systemu. -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    prefix: 'tw-',
    corePlugins: { preflight: false },
  };
</script>
<style type="text/tailwindcss">
/* ── Layout ───────────────────────────────── */
body { background: #fff; }
#content { padding: 1.5rem 1.5rem 5rem; }
[x-cloak] { display: none !important; }

/* ── Nagłówek dwupoziomowy ─────────────────────────────────────────────
   1) .nb-top  — pasek marki w kolorze organizacji: logo, wyszukiwarka
                 (Ctrl+K), launcher modułów, dzwonki, pomoc, użytkownik;
   2) .nb-nav  — biały pasek zakładek z rejestru includes/menu.php
                 (mega-menu dla zakładek z kilkoma grupami) + tytuł strony.
   Dropdowny to nadal Bootstrap (bez klas .navbar — pozycjonuje je Popper,
   więc menu wystające poza ekran samo się przesuwa). ────────────────── */
#navbar { @apply tw-sticky tw-top-0 tw-z-[100] tw-shadow-sm; }
.nb-top {
    @apply tw-flex tw-items-center tw-gap-3 tw-px-4;
    height: 50px;
    background: <?= h($_sb_color) ?>;
}
.nb-brand {
    @apply tw-flex tw-items-center tw-gap-2 tw-no-underline tw-shrink-0 tw-min-w-0;
    color: <?= h($_sb_brand_color) ?>;
}
.nb-brand:hover { color: <?= h($_sb_brand_color) ?>; opacity: .88; }
.nb-brand-icon {
    @apply tw-w-8 tw-h-8 tw-rounded-lg tw-flex tw-items-center tw-justify-center tw-text-base tw-shrink-0;
    background: rgba(255,255,255,.15);
    color: <?= h($_sb_icon_color) ?>;
}
.nb-logo-img { @apply tw-h-[30px] tw-w-auto tw-max-w-[38px] tw-object-contain tw-rounded; }
.nb-brand-name { @apply tw-font-bold tw-text-sm tw-whitespace-nowrap tw-leading-tight; }
.nb-brand-sub { @apply tw-text-[.59rem] tw-opacity-60 tw-font-normal tw-block tw-whitespace-nowrap; }

/* Wyszukiwarka (wyzwalacz palety Ctrl+K) — na środku paska marki */
.nb-center { @apply tw-flex-1 tw-justify-center tw-min-w-0 tw-px-2; }
.nb-search-wrap { position: relative; width: 100%; max-width: 460px; }
.nb-search-wrap input {
    width: 100%; height: 34px;
    border: 1px solid rgba(255,255,255,.22); border-radius: 9px;
    background: rgba(255,255,255,.12); padding: 0 4.4rem 0 2.1rem;
    font-size: .84rem; outline: none; color: <?= h($_sb_text) ?>; cursor: pointer;
    transition: background .15s, border-color .15s;
}
.nb-search-wrap input::placeholder { color: <?= h($_sb_text_muted) ?>; }
.nb-search-wrap input:hover, .nb-search-wrap input:focus {
    background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.45);
}
.nb-search-icon {
    position: absolute; left: .7rem; top: 50%; transform: translateY(-50%);
    color: <?= h($_sb_text_muted) ?>; font-size: .82rem; pointer-events: none;
}
.nb-search-kbd {
    position: absolute; right: .55rem; top: 50%; transform: translateY(-50%);
    font-size: .6rem; font-weight: 700; letter-spacing: .03em; pointer-events: none;
    color: <?= h($_sb_text_muted) ?>; border: 1px solid rgba(255,255,255,.28);
    border-radius: 5px; padding: .06rem .35rem; line-height: 1.2;
}

/* Prawa strona paska marki */
#nb-right { display: flex; align-items: center; gap: .35rem; flex-shrink: 0; margin-left: auto; }
.nb-icon-btn {
    @apply tw-rounded-lg tw-text-[.9rem] tw-cursor-pointer tw-inline-flex tw-items-center tw-justify-center tw-relative tw-transition-colors tw-no-underline;
    width: 34px; height: 34px;
    background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18);
    color: <?= h($_sb_text) ?>;
}
.nb-icon-btn:hover, .nb-icon-btn[aria-expanded="true"] { background: rgba(255,255,255,.22); color: <?= h($_sb_hover_text) ?>; }
.nb-icon-btn .badge { font-size: .55rem; }
.nb-user-chip {
    @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-full tw-cursor-pointer tw-no-underline tw-transition-colors;
    background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22);
    padding: .2rem .6rem .2rem .3rem; height: 34px;
    font-size: .8rem; font-weight: 500; color: <?= h($_sb_text) ?>;
}
.nb-user-chip:hover, .nb-user-chip[aria-expanded="true"] { background: rgba(255,255,255,.22); color: <?= h($_sb_hover_text) ?>; }
.nb-user-chip .avatar {
    @apply tw-w-[24px] tw-h-[24px] tw-rounded-full tw-text-white tw-text-[.62rem] tw-font-bold tw-flex tw-items-center tw-justify-center tw-shrink-0;
    background: linear-gradient(135deg, #2563eb, #6610f2);
}
.nb-toggler {
    @apply tw-inline-flex tw-items-center tw-justify-center tw-rounded-lg tw-cursor-pointer;
    width: 34px; height: 34px; border: 0; background: none; font-size: 1.35rem;
    color: <?= h($_sb_text) ?>;
}

/* Pasek zakładek */
.nb-nav {
    @apply tw-bg-white tw-border-b tw-border-slate-200 tw-items-center tw-gap-1 tw-px-3;
    height: 40px;
}
.nb-home {
    @apply tw-inline-flex tw-items-center tw-justify-center tw-rounded-lg tw-no-underline tw-shrink-0 tw-transition-colors;
    width: 30px; height: 30px; color: #64748b; font-size: .95rem;
}
.nb-home:hover { background: #f1f5f9; color: #0f172a; }
.nb-home.active { background: #eff6ff; color: #1d4ed8; }
.nb-tabs { @apply tw-flex tw-items-center tw-gap-0.5 tw-list-none tw-m-0 tw-p-0 tw-min-w-0; }
.nb-tabs > li { position: relative; }
.nb-tab {
    @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-no-underline tw-whitespace-nowrap tw-transition-colors tw-cursor-pointer;
    font-size: .83rem; font-weight: 500; color: #334155; padding: .32rem .62rem; line-height: 1.2;
    background: none; border: 0;
}
.nb-tab > .bi:first-child { color: #94a3b8; font-size: .85rem; }
.nb-tab:hover, .nb-tab.show { background: #f1f5f9; color: #0f172a; }
.nb-tab:hover > .bi:first-child, .nb-tab.show > .bi:first-child { color: #475569; }
.nb-tab.active { background: #eff6ff; color: #1d4ed8; font-weight: 600; }
.nb-tab.active > .bi:first-child { color: #1d4ed8; }
.nb-tab .badge { font-size: .58rem; padding: .22em .45em; }
.nb-tab.dropdown-toggle::after { margin-left: .1rem; opacity: .45; vertical-align: .12em; }
.nb-tab.nb-ezd-link { color: #b91c1c; }
.nb-tab.nb-ezd-link > .bi:first-child { color: #dc2626; }
.nb-tab.nb-ezd-link:hover, .nb-tab.nb-ezd-link.show, .nb-tab.nb-ezd-link.active { background: #fef2f2; color: #991b1b; }
@media (max-width: 1279.98px) { .nb-tab > .bi:first-child { display: none; } }

/* Tytuł strony — prawy koniec paska zakładek (desktop) / cienki pasek (mobile) */
.nb-ptitle {
    @apply tw-items-center tw-gap-1.5 tw-min-w-0 tw-ml-auto tw-pl-3 tw-shrink;
    border-left: 1px solid #e2e8f0; font-size: .8rem; font-weight: 600; color: #475569; max-width: 36vw;
}
.nb-ptitle .ptb-title { @apply tw-min-w-0 tw-overflow-hidden tw-text-ellipsis tw-whitespace-nowrap; }
.nb-ptitle-m {
    @apply tw-bg-white tw-border-b tw-border-slate-200 tw-text-[.85rem] tw-font-semibold tw-text-slate-800;
    padding: .4rem 1rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
#page-title-bar.nb-ptitle-fallback {
    @apply tw-bg-white tw-border-b tw-border-slate-200 tw-flex tw-items-center tw-gap-2.5 tw-text-[.92rem] tw-font-semibold tw-text-slate-800;
    padding: .45rem 1.5rem;
}

/* Dropdowny (zakładki, dzwonki, pomoc, użytkownik) */
#navbar .dropdown-menu {
    min-width: 230px; border: 1px solid #e2e8f0; border-radius: 12px;
    box-shadow: 0 12px 36px rgba(2,6,23,.16); padding: .35rem .3rem; margin-top: 6px !important;
    font-size: .83rem;
}
#navbar .dropdown-item {
    border-radius: 8px; padding: .4rem .7rem; color: #334155;
    display: flex; align-items: center; gap: .55rem; background: none; border: 0; width: 100%; text-align: left;
}
#navbar .dropdown-item > i { font-size: .85rem; width: 16px; text-align: center; color: #94a3b8; flex-shrink: 0; }
#navbar .dropdown-item:hover,
#navbar .dropdown-item:focus { background: #eff6ff; color: #2563eb; }
#navbar .dropdown-item:hover > i { color: #2563eb; }
#navbar .dropdown-item.active,
#navbar .dropdown-item:active { background: #eff6ff; color: #2563eb; font-weight: 600; }
#navbar .dropdown-item.active > i { color: #2563eb; }
#navbar .dropdown-item .badge { font-size: .58rem; margin-left: auto; }
#navbar .dropdown-item kbd {
    margin-left: auto; font-size: .62rem; font-weight: 700; color: #64748b; background: #f1f5f9;
    border: 1px solid #e2e8f0; border-radius: 5px; padding: .05rem .35rem; font-family: inherit;
}
#navbar .dropdown-divider { margin: .3rem .4rem; }
.nb-section-label {
    font-size: .61rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: #94a3b8; padding: .45rem .7rem .15rem; display: flex; align-items: center; gap: .35rem;
}
.nb-section-label .badge { font-size: .55rem; }

/* Mega-menu — zakładka z kilkoma grupami rozkłada je w kolumny */
#navbar .dropdown-menu.nb-mega { width: max-content; max-width: min(900px, calc(100vw - 1.5rem)); padding: .5rem .55rem .55rem; }
.nb-mega-grid { display: grid; grid-template-columns: repeat(var(--cols, 2), minmax(200px, 1fr)); gap: .1rem .7rem; }
.nb-mega-col { min-width: 0; }
.nb-mega-col + .nb-mega-col { border-left: 1px solid #f1f5f9; padding-left: .7rem; }
@media (max-width: 991.98px) { .nb-mega-grid { grid-template-columns: 1fr; } .nb-mega-col + .nb-mega-col { border: 0; padding: 0; } }

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

/* Offcanvas nav (mobile) — białe menu z sekcjami rejestru + moduły + konto */
#navOffcanvas { width: 300px; background: #fff; color: #0f172a; }
#navOffcanvas .offcanvas-header { padding: .75rem 1rem; border-bottom: 1px solid #e2e8f0; }
#navOffcanvas .offcanvas-body { padding: .4rem .5rem 1.5rem; overflow-y: auto; }
.oc-user { display: flex; align-items: center; gap: .6rem; padding: .5rem .6rem .7rem; }
.oc-user .avatar {
    width: 34px; height: 34px; border-radius: 50%; color: #fff; font-size: .72rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    background: linear-gradient(135deg, #2563eb, #6610f2);
}
.oc-user-name { font-size: .86rem; font-weight: 600; line-height: 1.2; }
.oc-user-mail { font-size: .7rem; color: #94a3b8; }
.oc-link {
    display: flex; align-items: center; gap: .55rem;
    padding: .42rem .7rem; color: #334155; text-decoration: none;
    font-size: .86rem; font-weight: 500; border-radius: 8px;
    transition: background .1s;
}
.oc-link i { font-size: .88rem; width: 18px; text-align: center; color: #94a3b8; flex-shrink: 0; }
.oc-link:hover { background: #f1f5f9; color: #0f172a; }
.oc-link:hover i { color: #475569; }
.oc-link.active { background: #eff6ff; color: #1d4ed8; font-weight: 600; }
.oc-link.active i { color: #1d4ed8; }
.oc-link .badge { font-size: .6rem; margin-left: auto; }
.oc-section {
    font-size: .61rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: #94a3b8; padding: .7rem .7rem .2rem; display: flex; align-items: center; gap: .4rem;
}
.oc-section i { font-size: .75rem; }
.oc-sep { height: 1px; background: #f1f5f9; margin: .45rem .5rem; }
.oc-mods { display: grid; grid-template-columns: repeat(3, 1fr); gap: .35rem; padding: .2rem .4rem; }
.oc-mod {
    display: flex; flex-direction: column; align-items: center; gap: .3rem; text-decoration: none;
    padding: .55rem .2rem; border-radius: 10px; border: 1px solid #eef2f7; color: #334155;
    font-size: .68rem; font-weight: 600; text-align: center; line-height: 1.15;
}
.oc-mod .oc-mod-ic {
    width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center;
    font-size: 1rem; background: var(--mb, #f8fafc); color: var(--mc, #64748b);
}
.oc-mod:hover { background: #f8fafc; color: #0f172a; }
.oc-mod.on { border-color: #bfdbfe; background: #eff6ff; color: #1d4ed8; }
</style>

<?php
require_once __DIR__ . '/app_bg.php';
/* EZD ma gęste tabele i podglądy dokumentów — tam geometria ledwie zaznaczona */
$__bg_subtle = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/ezd/') || !empty($APP_BG_SUBTLE);
app_bg_css($__bg_subtle ? '#EFF1F5' : '#E8EBF0', $__bg_subtle ? 'subtle' : 'normal');
?>
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
// ── Nawigacja z rejestru menu (includes/menu.php) — jedno źródło prawdy ──
require_once __DIR__ . '/menu.php';
$_menu = $_user ? menu_build() : ['mode' => 'guest', 'tree' => []];
$_nb_nav_rendered = $_user && !empty($_menu['tree']);
$_nb_on_home = preg_match('#/index\.php(\?|$)#', $_uri) === 1 && !preg_match('#/[a-z0-9_]+/index\.php#i', $_uri);
?>
<div id="navbar">

<!-- Pasek marki -->
<div class="nb-top">

  <a class="nb-brand" href="<?= APP_URL ?>/index.php" title="Strona główna">
    <?php if ($_org_logo && file_exists(dirname(__DIR__) . '/assets/logo/' . $_org_logo)): ?>
    <img src="<?= APP_URL ?>/assets/logo/<?= h($_org_logo) ?>" alt="Logo" class="nb-logo-img">
    <?php else: ?>
    <span class="nb-brand-icon"><i class="bi bi-building"></i></span>
    <?php endif; ?>
    <span class="tw-min-w-0">
      <span class="nb-brand-name"><?= h(org_setting('org_short_name') ?: ORG_NAME) ?></span>
      <span class="nb-brand-sub d-none d-sm-block">System Wspomagania Zarządzania Organizacją</span>
    </span>
  </a>

  <?php if ($_user): ?>
  <!-- Wyszukiwarka menu i danych — otwiera paletę poleceń (Ctrl+K) -->
  <div class="nb-center d-none d-md-flex">
    <div class="nb-search-wrap">
      <i class="nb-search-icon bi bi-search" aria-hidden="true"></i>
      <input type="search" id="cmdk-trigger" readonly
             placeholder="Szukaj w menu i danych…"
             aria-label="Otwórz wyszukiwarkę menu i danych (Ctrl+K)">
      <span class="nb-search-kbd" aria-hidden="true">Ctrl K</span>
    </div>
  </div>
  <?php endif; ?>

  <div id="nb-right">
    <span id="ajax-spinner" aria-hidden="true" title="Ładowanie…"></span>

    <?php if ($_user && can_edit()): ?>
    <?php /* Launcher modułów — wspólny komponent modules/launcher/ (ustawia $_sw_items
             dla offcanvasu i kafli na pulpicie). Aktywny moduł wykrywany z URI. */
      $launcherActive = ''; $launcherDark = $_sb_dark;
      require dirname(__DIR__) . '/modules/launcher/launcher.php'; ?>
    <?php endif; // can_edit ?>

    <?php if (str_contains($_uri, '/ezd/')): ?>
    <button type="button" id="ezd-fs-btn" class="nb-icon-btn"
            title="Ukryj menu górne — EZD na całą stronę" aria-label="Ukryj menu górne">
      <i class="bi bi-arrows-fullscreen"></i>
    </button>
    <?php endif; ?>

    <?php if ($_user):
    $_notif_count  = notif_unread_count_excl((int)$_user['id'], 'task');
    $_notif_latest = notif_latest_excl((int)$_user['id'], 'task', 6);
    $_task_notif_count  = module_enabled('tasks_enabled') ? notif_unread_count_type((int)$_user['id'], 'task')  : 0;
    $_task_notif_latest = module_enabled('tasks_enabled') ? notif_latest_type((int)$_user['id'], 'task', 5)    : [];
    ?>

    <?php if (module_enabled('tasks_enabled') && ($_task_notif_count > 0 || true)): ?>
    <!-- ── Dzwonek Zadania ──────────────────────────────────────────────── -->
    <div class="dropdown me-1" id="tsk-bell-main">
      <button type="button" class="nb-icon-btn position-relative"
              data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
              aria-label="Zadania — <?= $_task_notif_count ?> nieprzeczytanych"
              id="tsk-bell-main-btn"
              title="Powiadomienia zadań"
              style="border-color:rgba(234,88,12,.35)">
        <i class="bi bi-kanban-fill" style="color:#ea580c"></i>
        <?php if ($_task_notif_count > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill"
              id="tsk-bell-main-badge"
              style="background:#ea580c;font-size:.55rem" aria-hidden="true">
          <?= $_task_notif_count > 99 ? '99+' : $_task_notif_count ?>
        </span>
        <?php else: ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill d-none"
              id="tsk-bell-main-badge"
              style="background:#ea580c;font-size:.55rem" aria-hidden="true"></span>
        <?php endif; ?>
      </button>
      <div class="dropdown-menu dropdown-menu-end shadow"
           style="width:300px;max-height:360px;overflow-y:auto" role="menu"
           aria-label="Powiadomienia modułu Zadania">
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
          <span class="fw-semibold d-flex align-items-center gap-2" style="font-size:.85rem">
            <i class="bi bi-kanban-fill" style="color:#ea580c"></i>Powiadomienia — Zadania
          </span>
          <?php if ($_task_notif_count > 0): ?>
          <button type="button" class="btn btn-link btn-sm p-0 text-muted" style="font-size:.75rem"
                  id="tsk-bell-main-mark-all">Oznacz przeczytane</button>
          <?php endif; ?>
        </div>
        <?php if (empty($_task_notif_latest)): ?>
        <div class="text-center py-4 text-muted" style="font-size:.82rem">
          <i class="bi bi-check2-all d-block mb-1" style="font-size:1.5rem;opacity:.3"></i>
          Brak powiadomień
        </div>
        <?php else: foreach ($_task_notif_latest as $_tn): ?>
        <a href="<?= h($_tn['url'] ?: APP_URL . '/tasks/dashboard.php') ?>"
           class="dropdown-item py-2 px-3 <?= $_tn['is_read'] ? '' : 'fw-semibold' ?>"
           style="white-space:normal;font-size:.82rem;border-bottom:1px solid #f1f5f9"
           data-notif-id="<?= (int)$_tn['id'] ?>">
          <div class="d-flex gap-2 align-items-start">
            <i class="bi bi-kanban-fill mt-1 flex-shrink-0" style="color:#8B5CF6;font-size:.9rem"></i>
            <div class="flex-grow-1">
              <div><?= h($_tn['title']) ?></div>
              <?php if ($_tn['body']): ?>
              <div class="text-muted fw-normal" style="font-size:.74rem"><?= h(mb_substr($_tn['body'], 0, 80)) ?></div>
              <?php endif; ?>
              <div class="text-muted fw-normal" style="font-size:.72rem"><?= h(substr($_tn['created_at'], 0, 16)) ?></div>
            </div>
            <?php if (!$_tn['is_read']): ?>
            <span class="rounded-circle flex-shrink-0" style="width:7px;height:7px;margin-top:5px;background:#ea580c"></span>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; endif; ?>
        <div class="px-3 py-2 border-top d-flex gap-2">
          <a href="<?= APP_URL ?>/tasks/dashboard.php"
             class="btn btn-sm flex-fill" style="background:#fff7ed;color:#c2410c;font-size:.8rem;border:1px solid #fed7aa">
            <i class="bi bi-kanban me-1"></i>Zadania
          </a>
          <a href="<?= APP_URL ?>/tasks/notification_settings.php"
             class="btn btn-sm flex-fill" style="background:#f1f5f9;color:#374151;font-size:.8rem">
            <i class="bi bi-gear me-1"></i>Ustawienia
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

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
    <!-- Pomoc: zgłoszenie błędu, skróty, procedury -->
    <div class="dropdown" id="nb-help">
      <button type="button" class="nb-icon-btn" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
              title="Pomoc" aria-label="Pomoc">
        <i class="bi bi-question-lg"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><h6 class="dropdown-header nb-section-label">Pomoc</h6></li>
        <?php if ($_bug_report_on): ?>
        <li>
          <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#bugReportModal">
            <i class="bi bi-bug-fill" style="color:#dc2626"></i>Zgłoś błąd na tej stronie
          </button>
        </li>
        <?php endif; ?>
        <li>
          <button type="button" class="dropdown-item" id="shortcuts-hint"
                  onclick="document.dispatchEvent(new KeyboardEvent('keydown',{key:'?',bubbles:true}))">
            <i class="bi bi-keyboard"></i>Skróty klawiaturowe <kbd>?</kbd>
          </button>
        </li>
        <li>
          <button type="button" class="dropdown-item" data-cmdk-open>
            <i class="bi bi-search"></i>Wyszukiwarka <kbd>Ctrl K</kbd>
          </button>
        </li>
        <?php if (can_edit()): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/procedures/index.php"><i class="bi bi-list-task"></i>Procedury i instrukcje</a></li>
        <?php if (function_exists('asai_enabled') && asai_enabled()): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/procedures/asystent.php"><i class="bi bi-stars"></i>Asystent AI</a></li>
        <?php endif; ?>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/portal.php"><i class="bi bi-grid-3x3-gap"></i>Portal modułów</a></li>
      </ul>
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
    <?php if ($_nb_nav_rendered): ?>
    <!-- Mobile toggle -->
    <button class="nb-toggler d-lg-none" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#navOffcanvas"
            aria-controls="navOffcanvas" aria-label="Menu">
      <i class="bi bi-list"></i>
    </button>
    <?php endif; ?>
  </div><!-- /#nb-right -->
</div><!-- /.nb-top -->

<?php if ($_nb_nav_rendered): ?>
<!-- Pasek zakładek (desktop) -->
<nav class="nb-nav d-none d-lg-flex" aria-label="Menu główne">
  <a class="nb-home<?= $_nb_on_home ? ' active' : '' ?>" href="<?= APP_URL ?>/index.php" title="Strona główna" aria-label="Strona główna">
    <i class="bi bi-house-door-fill"></i>
  </a>
  <ul class="nb-tabs">
  <?php foreach ($_menu['tree'] as $_n):
      $_n_cls = (!empty($_n['active']) ? ' active' : '') . (!empty($_n['ezd']) ? ' nb-ezd-link' : '');
      if (!empty($_n['path']) && empty($_n['groups'])): // zakładka-link (np. RODO, Admin, Wirtualne biurko)
  ?>
    <li>
      <a class="nb-tab<?= $_n_cls ?>" href="<?= APP_URL . h($_n['path']) ?>">
        <i class="bi <?= h($_n['icon']) ?>"></i><?= h($_n['label']) ?>
        <?php if (!empty($_n['badge'])): ?><span class="badge bg-danger"><?= (int)$_n['badge'] ?></span><?php endif; ?>
      </a>
    </li>
  <?php else:
      $_mega = count($_n['groups']) > 1;
  ?>
    <li class="dropdown">
      <button type="button" class="nb-tab dropdown-toggle<?= $_n_cls ?>" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi <?= h($_n['icon']) ?>"></i><?= h($_n['label']) ?>
        <?php if (!empty($_n['badge'])): ?><span class="badge bg-warning text-dark"><?= (int)$_n['badge'] ?></span><?php endif; ?>
      </button>
      <?php if ($_mega): ?>
      <div class="dropdown-menu nb-mega<?= !empty($_n['end']) ? ' dropdown-menu-end' : '' ?>">
        <div class="nb-mega-grid" style="--cols:<?= min(4, count($_n['groups'])) ?>">
          <?php foreach ($_n['groups'] as $_g): ?>
          <div class="nb-mega-col">
            <?php if (!empty($_g['label'])): ?>
            <h6 class="dropdown-header nb-section-label"><?= h($_g['label']) ?><?php if (!empty($_g['badge'])): ?><span class="badge bg-primary"><?= (int)$_g['badge'] ?></span><?php endif; ?></h6>
            <?php endif; ?>
            <?php foreach ($_g['items'] as $_it): ?>
            <a class="dropdown-item<?= !empty($_it['active']) ? ' active' : '' ?><?= !empty($_it['danger']) ? ' text-danger' : '' ?>" href="<?= APP_URL . h($_it['path']) ?>"><i class="bi <?= h($_it['icon']) ?>"></i><?= h($_it['label']) ?><?php if (!empty($_it['badge'])): ?><span class="badge bg-warning text-dark"<?= !empty($_it['attr']) ? ' ' . $_it['attr'] : '' ?>><?= (int)$_it['badge'] ?></span><?php endif; ?></a>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
      <ul class="dropdown-menu<?= !empty($_n['end']) ? ' dropdown-menu-end' : '' ?>">
        <?php foreach ($_n['groups'] as $_g): ?>
          <?php if (!empty($_g['label'])): ?>
          <li><h6 class="dropdown-header nb-section-label"><?= h($_g['label']) ?><?php if (!empty($_g['badge'])): ?><span class="badge bg-primary"><?= (int)$_g['badge'] ?></span><?php endif; ?></h6></li>
          <?php endif; ?>
          <?php foreach ($_g['items'] as $_it): ?>
          <li><a class="dropdown-item<?= !empty($_it['active']) ? ' active' : '' ?><?= !empty($_it['danger']) ? ' text-danger' : '' ?>" href="<?= APP_URL . h($_it['path']) ?>"><i class="bi <?= h($_it['icon']) ?>"></i><?= h($_it['label']) ?><?php if (!empty($_it['badge'])): ?><span class="badge bg-warning text-dark"<?= !empty($_it['attr']) ? ' ' . $_it['attr'] : '' ?>><?= (int)$_it['badge'] ?></span><?php endif; ?></a></li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </li>
  <?php endif; ?>
  <?php endforeach; ?>
  </ul>
  <?php if (!empty($_page_title)): ?>
  <span class="nb-ptitle d-none d-xl-flex" id="page-title-bar" aria-label="Bieżąca strona">
    <i class="bi bi-chevron-right" style="font-size:.6rem;color:#cbd5e1" aria-hidden="true"></i>
    <span class="ptb-title"><?= h($_page_title) ?></span>
  </span>
  <?php endif; ?>
</nav>
<?php if (!empty($_page_title)): ?>
<div class="nb-ptitle-m d-lg-none"><?= h($_page_title) ?></div>
<?php endif; ?>
<?php endif; ?>

</div><!-- /#navbar -->


<?php /* ── Command palette (Ctrl+K) — wyszukiwarka menu + danych ─────────────── */ ?>
<?php if ($_user):
  $_cmdk_index = function_exists('menu_search_index') ? menu_search_index($_menu['tree'] ?? []) : [];
?>
<div id="cmdk-root" hidden></div>
<script>window.__feerCmdk = <?= json_encode([
  'appUrl' => APP_URL,
  'items'  => $_cmdk_index,
  'api'    => APP_URL . '/api/search_quick.php',
  'search' => APP_URL . '/search.php',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<style>
#cmdk-root *{box-sizing:border-box}
.cmdk-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:5000;display:flex;align-items:flex-start;justify-content:center;animation:cmdkFade .12s ease both}
@keyframes cmdkFade{from{opacity:0}to{opacity:1}}
@keyframes cmdkPop{from{opacity:0;transform:translateY(-8px) scale(.985)}to{opacity:1;transform:none}}
.cmdk-panel{margin-top:9vh;width:min(640px,calc(100vw - 24px));max-height:74vh;background:#fff;border-radius:16px;
  box-shadow:0 24px 70px rgba(2,6,23,.35);display:flex;flex-direction:column;overflow:hidden;animation:cmdkPop .16s ease both}
.cmdk-inbar{display:flex;align-items:center;gap:.6rem;padding:.85rem 1rem;border-bottom:1px solid #eef2f7}
.cmdk-inbar>.bi{color:#94a3b8;font-size:1.05rem}
.cmdk-inbar input{flex:1;border:0;outline:0;font-size:1rem;color:#0f172a;background:transparent}
.cmdk-esc{font-size:.62rem;font-weight:700;color:#64748b;background:#f1f5f9;border-radius:6px;padding:.15rem .4rem;flex-shrink:0}
.cmdk-scroll{overflow-y:auto;padding:.3rem .4rem .45rem}
.cmdk-sec{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;padding:.6rem .85rem .25rem}
.cmdk-item{display:flex;align-items:center;gap:.7rem;padding:.5rem .7rem;border-radius:10px;text-decoration:none;color:#334155;cursor:pointer}
.cmdk-ic{width:32px;height:32px;border-radius:9px;background:#f1f5f9;color:#475569;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.cmdk-tx{flex:1;min-width:0}
.cmdk-lb{font-size:.9rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cmdk-sb{font-size:.74rem;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cmdk-item .badge{flex-shrink:0}
.cmdk-item.cmdk-on,.cmdk-item:hover{background:#eff6ff;color:#1d4ed8}
.cmdk-item.cmdk-on .cmdk-ic,.cmdk-item:hover .cmdk-ic{background:#dbeafe;color:#1d4ed8}
.cmdk-empty{padding:1.5rem;text-align:center;color:#94a3b8;font-size:.86rem}
.cmdk-foot{border-top:1px solid #f1f5f9;padding:.5rem .9rem;font-size:.72rem;color:#94a3b8;display:flex;gap:1.1rem;align-items:center;flex-wrap:wrap}
.cmdk-foot kbd{background:#f1f5f9;border-radius:5px;padding:.05rem .35rem;font-size:.7rem;color:#475569;border:0}
@media(prefers-reduced-motion:reduce){.cmdk-backdrop,.cmdk-panel{animation:none!important}}
</style>
<script>
(function(){
  var cfg = window.__feerCmdk; if(!cfg) return;
  var root = document.getElementById('cmdk-root'); if(!root) return;
  if(root.parentNode !== document.body) document.body.appendChild(root);

  var open=false, query='', active=0, flat=[], menuRes=[], dataRes=[], timer=null, seq=0;

  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}

  function filterMenu(q){
    if(!q) return cfg.items.slice(0,60);
    var lq=q.toLowerCase();
    var starts=[], contains=[];
    cfg.items.forEach(function(it){
      var lbl=(it.label||'').toLowerCase();
      var hay=(lbl+' '+(it.sub||'')+' '+(it.kw||'')).toLowerCase();
      if(lbl.indexOf(lq)===0) starts.push(it);
      else if(hay.indexOf(lq)>=0) contains.push(it);
    });
    return starts.concat(contains).slice(0,60);
  }

  function itemHTML(it, idx, kind){
    var url = kind==='menu' ? (cfg.appUrl + it.path) : it.url;
    var badge = it.badge ? '<span class="badge bg-warning text-dark">'+esc(it.badge)+'</span>' : '';
    var sub = it.sub ? '<div class="cmdk-sb">'+esc(it.sub)+'</div>' : '';
    return '<a class="cmdk-item'+(idx===active?' cmdk-on':'')+'" data-idx="'+idx+'" href="'+esc(url)+'">'
      +'<span class="cmdk-ic"><i class="bi '+esc(it.icon)+'"></i></span>'
      +'<span class="cmdk-tx"><div class="cmdk-lb">'+esc(it.label)+'</div>'+sub+'</span>'+badge+'</a>';
  }

  function renderList(){
    var scroll = document.getElementById('cmdk-scroll'); if(!scroll) return;
    flat = [];
    var html='';
    if(menuRes.length){
      html+='<div class="cmdk-sec">Menu</div>';
      menuRes.forEach(function(m){ var i=flat.length; flat.push({kind:'menu',d:m}); html+=itemHTML(m,i,'menu'); });
    }
    if(dataRes.length){
      html+='<div class="cmdk-sec">Dane</div>';
      dataRes.forEach(function(d){ var i=flat.length; flat.push({kind:'data',d:d}); html+=itemHTML(d,i,'data'); });
    }
    if(!flat.length) html='<div class="cmdk-empty">Brak wyników'+(query?' dla „'+esc(query)+'”':'')+'.</div>';
    scroll.innerHTML=html;
    if(active>=flat.length) active=Math.max(0,flat.length-1);
    markActive(false);
  }

  function markActive(scrollTo){
    var scroll=document.getElementById('cmdk-scroll'); if(!scroll) return;
    var nodes=scroll.querySelectorAll('.cmdk-item');
    nodes.forEach(function(n){ n.classList.toggle('cmdk-on', +n.getAttribute('data-idx')===active); });
    if(scrollTo && nodes[active]) nodes[active].scrollIntoView({block:'nearest'});
  }

  function fetchData(q){
    if(q.length<2){ dataRes=[]; renderList(); return; }
    var mine=++seq;
    fetch(cfg.api+'?q='+encodeURIComponent(q))
      .then(function(r){return r.json();})
      .then(function(d){ if(mine!==seq) return; dataRes=(d && d.results)?d.results:[]; renderList(); })
      .catch(function(){ if(mine===seq){ dataRes=[]; renderList(); } });
  }

  function onInput(v){
    query=v; active=0;
    menuRes=filterMenu(query);
    renderList();
    clearTimeout(timer);
    timer=setTimeout(function(){ fetchData(query.trim()); }, 170);
  }

  function go(){
    if(!flat.length) return;
    var e=flat[active]; if(!e) return;
    var url = e.kind==='menu' ? (cfg.appUrl + e.d.path) : e.d.url;
    window.location.href = url;
  }

  function openPalette(){
    if(open) return; open=true;
    query=''; active=0; menuRes=filterMenu(''); dataRes=[];
    root.hidden=false;
    root.innerHTML='<div class="cmdk-backdrop" data-cmdk-close="1">'
      +'<div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="Wyszukiwarka menu">'
      +'<div class="cmdk-inbar"><i class="bi bi-search"></i>'
      +'<input id="cmdk-input" type="text" placeholder="Szukaj w menu i danych…" autocomplete="off" spellcheck="false" aria-label="Szukaj w menu i danych">'
      +'<span class="cmdk-esc">ESC</span></div>'
      +'<div class="cmdk-scroll" id="cmdk-scroll"></div>'
      +'<div class="cmdk-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> nawigacja</span><span><kbd>↵</kbd> otwórz</span><span><kbd>esc</kbd> zamknij</span></div>'
      +'</div></div>';
    renderList();
    var inp=document.getElementById('cmdk-input');
    inp.addEventListener('input', function(){ onInput(this.value); });
    document.getElementById('cmdk-scroll').addEventListener('mousemove', function(e){
      var a=e.target.closest('.cmdk-item'); if(a){ active=+a.getAttribute('data-idx'); markActive(false); }
    });
    root.querySelector('.cmdk-backdrop').addEventListener('click', function(e){
      if(e.target && e.target.getAttribute('data-cmdk-close')==='1') closePalette();
    });
    setTimeout(function(){ inp.focus(); }, 20);
  }

  function closePalette(){
    if(!open) return; open=false;
    root.hidden=true; root.innerHTML='';
  }

  document.addEventListener('keydown', function(e){
    // Ctrl/Cmd+K → otwórz/zamknij
    if((e.ctrlKey||e.metaKey) && !e.altKey && (e.key==='k'||e.key==='K')){
      e.preventDefault(); open?closePalette():openPalette(); return;
    }
    if(!open) return;
    if(e.key==='Escape'){ e.preventDefault(); closePalette(); }
    else if(e.key==='ArrowDown'){ e.preventDefault(); if(flat.length){ active=(active+1)%flat.length; markActive(true);} }
    else if(e.key==='ArrowUp'){ e.preventDefault(); if(flat.length){ active=(active-1+flat.length)%flat.length; markActive(true);} }
    else if(e.key==='Enter'){ e.preventDefault(); go(); }
  });

  // Wyzwalacze otwarcia: pole w topbarze + dowolny element [data-cmdk-open]
  document.addEventListener('click', function(e){
    var t=e.target.closest('#cmdk-trigger,[data-cmdk-open]');
    if(t){ e.preventDefault(); openPalette(); }
  });
})();
</script>
<?php endif; ?>

<?php if (str_contains($_uri, '/ezd/')): ?>
<!-- Tryb pełnoekranowy EZD — chowa menu górne, stan zapamiętany w localStorage -->
<style>
body.ezd-fs #navbar, body.ezd-fs #page-title-bar, body.ezd-fs .nb-ptitle-m,
body.ezd-fs #saas-bar, body.ezd-fs .saas-tenant-bar { display:none !important; }
#ezd-fs-exit {
  display:none; position:fixed; top:.6rem; right:.9rem; z-index:1080;
  align-items:center; gap:.4rem; background:#0f172a; color:#fff; border:none;
  border-radius:999px; padding:.35rem .85rem; font-size:.78rem; font-weight:600;
  box-shadow:0 4px 12px rgba(2,6,23,.3); opacity:.5; transition:opacity .15s; cursor:pointer;
}
#ezd-fs-exit:hover, #ezd-fs-exit:focus-visible { opacity:1; }
body.ezd-fs #ezd-fs-exit { display:inline-flex; }
</style>
<button type="button" id="ezd-fs-exit" title="Pokaż menu górne" aria-label="Pokaż menu górne">
  <i class="bi bi-arrows-angle-contract"></i> Pokaż menu
</button>
<script>
(function () {
  var KEY = 'feerEzdFullscreen';
  function apply(on) { document.body.classList.toggle('ezd-fs', on); }
  function set(on)   { try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {} apply(on); }
  try { apply(localStorage.getItem(KEY) === '1'); } catch (e) {}
  var btn  = document.getElementById('ezd-fs-btn');
  var exit = document.getElementById('ezd-fs-exit');
  if (btn)  btn.addEventListener('click',  function () { set(true);  });
  if (exit) exit.addEventListener('click', function () { set(false); });
})();
</script>
<?php endif; ?>

<!-- Mobile offcanvas -->
<?php if ($_nb_nav_rendered): ?>
<div class="offcanvas offcanvas-start" tabindex="-1" id="navOffcanvas" aria-labelledby="navOffcanvasLabel">
  <div class="offcanvas-header">
    <span id="navOffcanvasLabel" class="nb-brand-name" style="color:#0f172a"><?= h(org_setting('org_short_name') ?: ORG_NAME) ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Zamknij"></button>
  </div>
  <div class="offcanvas-body">
    <div class="oc-user">
      <span class="avatar"><?= h($_nb_initials ?: mb_strtoupper(mb_substr($_user['name'],0,1))) ?></span>
      <div class="tw-min-w-0">
        <div class="oc-user-name"><?= h($_user['name']) ?></div>
        <div class="oc-user-mail"><?= h($_user['email']) ?></div>
      </div>
    </div>
    <a class="oc-link<?= $_nb_on_home ? ' active' : '' ?>" href="<?= APP_URL ?>/index.php"><i class="bi bi-house-door"></i>Strona główna</a>
    <button type="button" class="oc-link tw-w-full tw-text-left" style="background:none;border:0" data-cmdk-open data-bs-dismiss="offcanvas"><i class="bi bi-search"></i>Szukaj w menu i danych</button>
    <?php foreach (($_menu['tree'] ?? []) as $_n): ?>
      <?php if (!empty($_n['path']) && empty($_n['groups'])): ?>
    <a class="oc-link<?= !empty($_n['active']) ? ' active' : '' ?>" href="<?= APP_URL . h($_n['path']) ?>"><i class="bi <?= h($_n['icon']) ?>"></i><?= h($_n['label']) ?><?php if (!empty($_n['badge'])): ?><span class="badge bg-danger"><?= (int)$_n['badge'] ?></span><?php endif; ?></a>
      <?php else: ?>
    <div class="oc-section"><i class="bi <?= h($_n['icon']) ?>"></i><?= h($_n['label']) ?></div>
        <?php foreach ($_n['groups'] as $_g): foreach ($_g['items'] as $_it): ?>
    <a class="oc-link<?= !empty($_it['active']) ? ' active' : '' ?>" href="<?= APP_URL . h($_it['path']) ?>"><i class="bi <?= h($_it['icon']) ?>"></i><?= h($_it['label']) ?><?php if (!empty($_it['badge'])): ?><span class="badge bg-warning text-dark"><?= (int)$_it['badge'] ?></span><?php endif; ?></a>
        <?php endforeach; endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!empty($_sw_items)): ?>
    <div class="oc-sep"></div>
    <div class="oc-section"><i class="bi bi-grid-3x3-gap-fill"></i>Moduły</div>
    <div class="oc-mods">
      <?php foreach ($_sw_items as $_m): ?>
      <a class="oc-mod<?= !empty($_m['on']) ? ' on' : '' ?>" href="<?= h($_m['url']) ?>" style="--mc:<?= h($_m['mc']) ?>;--mb:<?= h($_m['mb']) ?>">
        <span class="oc-mod-ic"><i class="bi <?= h($_m['icon']) ?>"></i></span><?= h($_m['label']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="oc-sep"></div>
    <div class="oc-section"><i class="bi bi-person-circle"></i>Konto</div>
    <a class="oc-link" href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-person-circle"></i>Mój panel</a>
    <a class="oc-link" href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-gear"></i>Ustawienia konta</a>
    <a class="oc-link" href="<?= APP_URL ?>/portal.php"><i class="bi bi-grid-3x3-gap"></i>Portal modułów</a>
    <a class="oc-link text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" style="color:#dc2626"></i>Wyloguj się</a>
  </div>
</div>
<?php endif; ?>

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

<?php if (empty($_nb_nav_rendered) && !empty($_page_title)): // tytuł strony bez paska zakładek (gość / brak menu) ?>
<div id="page-title-bar" class="nb-ptitle-fallback"><span class="ptb-title"><?= h($_page_title) ?></span></div>
<?php endif; ?>

<?php require_once __DIR__ . '/bug_report_widget.php'; ?>
<?php // Pływający czat admina (includes/chat_widget.php) siedzi w tym samym
      // narożniku — dla can_edit() podnosimy przycisk asystenta ponad niego.
      $ASAI_WIDGET_SCOPE  = 'system';
      $ASAI_WIDGET_BOTTOM = (function_exists('can_edit') && can_edit()) ? '7.5rem' : '1.5rem';
      require_once __DIR__ . '/asystent_widget.php'; ?>
<?php require_once __DIR__ . '/quick_actions_widget.php'; ?>
<?php require_once __DIR__ . '/search_hotkey.php'; ?>
<?php require_once __DIR__ . '/welcome_notice.php'; ?>
<?php require_once __DIR__ . '/mobywatel_notice.php'; ?>


<?php if (str_contains($_uri, '/ezd/')): ?>
<div id="content" class="ezd-content-host">

<!-- ── EZD Topbar (zastępuje globalny navbar na stronach /ezd/) ─── -->
<header class="ezd-topbar" role="banner">

  <!-- Lewa: toggle mobilny + Powrót do SZO + branding EZD -->
  <div class="ezd-topbar-l">
    <button type="button" class="ezd-topbar-menu d-lg-none" id="ezdSbToggle" aria-label="Menu EZD">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <a href="<?= APP_URL ?>/index.php" class="ezd-tb-back" title="Powrót do głównego systemu SZO">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>
      <span>Powrót do SZO</span>
    </a>

    <span class="ezd-tb-divider d-none d-sm-block" aria-hidden="true"></span>

    <div class="ezd-tb-mod-wrap d-none d-sm-flex">
      <?php if (!empty($_org_logo) && file_exists(dirname(__DIR__).'/assets/logo/'.basename($_org_logo))): ?>
      <img src="<?= APP_URL ?>/assets/logo/<?= h(basename($_org_logo)) ?>" alt="" class="ezd-topbar-logo">
      <?php else: ?>
      <span class="ezd-tb-icon"><i class="bi bi-building-gear" aria-hidden="true"></i></span>
      <?php endif; ?>
      <span class="ezd-tb-label d-none d-md-inline">Wirtualne biurko</span>
      <span class="ezd-tb-badge">EZD</span>
    </div>
  </div>

  <!-- Centrum: tytuł strony -->
  <div class="ezd-topbar-c d-none d-lg-block">
    <?php if (!empty($_page_title) && !in_array($_page_title, ['Wirtualne biurko', 'EZD', 'Rejestr Umów'], true)): ?>
    <span class="ezd-topbar-ptitle"><?= h($_page_title) ?></span>
    <?php endif; ?>
  </div>

  <!-- Prawa: akcje + user -->
  <div class="ezd-topbar-r">

    <?php if ($_user && function_exists('can_edit') && can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php"
       class="ezd-tb-btn d-none d-md-flex"
       title="Nowa koszulka" aria-label="Nowa koszulka">
      <i class="bi bi-folder-plus" aria-hidden="true"></i>
    </a>
    <a href="<?= APP_URL ?>/ezd/rpw/add.php"
       class="ezd-tb-btn d-none d-lg-flex"
       title="Nowe pismo / RPW" aria-label="Nowe pismo / RPW">
      <i class="bi bi-envelope-plus" aria-hidden="true"></i>
    </a>
    <?php endif; ?>

    <?php
    // Licznik dekretacji oczekujących
    $_ezd_dekr_tb = 0;
    try {
        if ($_user) {
            $_ezd_dekr_tb = (int)(db_one(
                "SELECT COUNT(*) c FROM ezd_dekretacje WHERE wykonawca_id=? AND status='oczekuje'",
                [(int)$_user['id']]
            )['c'] ?? 0);
        }
    } catch (\Throwable $e) {}
    ?>
    <a href="<?= APP_URL ?>/ezd/index.php"
       class="ezd-tb-btn position-relative"
       title="<?= $_ezd_dekr_tb ? "Moje zadania EZD ($_ezd_dekr_tb oczekujących)" : 'Moje zadania EZD' ?>"
       aria-label="Moje zadania EZD">
      <i class="bi bi-bell-fill" aria-hidden="true"></i>
      <?php if ($_ezd_dekr_tb > 0): ?>
      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
            style="font-size:.5rem;padding:.2rem .32rem" aria-hidden="true">
        <?= $_ezd_dekr_tb > 9 ? '9+' : $_ezd_dekr_tb ?>
      </span>
      <?php endif; ?>
    </a>

    <?php if ($_user): ?>
    <div class="dropdown">
      <button class="ezd-tb-avatar-btn" type="button"
              data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
              title="<?= h($_user['name']) ?>">
        <span class="ezd-topbar-avatar"><?= h($_nb_initials ?: mb_strtoupper(mb_substr($_user['name'],0,1))) ?></span>
        <span class="d-none d-sm-inline" style="font-size:.75rem;font-weight:500;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= h(explode(' ', $_user['name'])[0]) ?>
        </span>
        <i class="bi bi-chevron-down d-none d-sm-inline" style="font-size:.55rem;opacity:.5" aria-hidden="true"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:220px;font-size:.83rem">
        <li class="px-3 py-2 border-bottom">
          <div class="fw-semibold"><?= h($_user['name']) ?></div>
          <div class="text-muted" style="font-size:.73rem"><?= h($_user['email']) ?></div>
          <span class="badge bg-light text-dark border mt-1" style="font-size:.64rem"><?= h($_user['role']) ?></span>
        </li>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/index.php">
            <i class="bi bi-building me-2 text-muted"></i>Panel SZO
          </a>
        </li>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/index.php">
            <i class="bi bi-person-circle me-2 text-muted"></i>Mój panel
          </a>
        </li>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/password.php">
            <i class="bi bi-gear me-2 text-muted"></i>Ustawienia konta
          </a>
        </li>
        <?php if (function_exists('is_admin') && is_admin()): ?>
        <li><hr class="dropdown-divider my-1"></li>
        <li>
          <a class="dropdown-item py-2" href="<?= APP_URL ?>/admin/ezd_settings.php">
            <i class="bi bi-sliders me-2 text-muted"></i>Ustawienia EZD
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
</header>

<div class="ezd-body">
<?php require_once __DIR__ . '/ezd_sidebar.php'; ?>
<div class="ezd-main">
<?= flash_html() ?>
<?php else: ?>
  <div id="content">
  <?= flash_html() ?>
<?php endif; ?>

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

  // Oznacz wszystkie w topbarze (ogólne)
  var markAll = document.getElementById('notif-mark-all');
  if (markAll) {
    markAll.addEventListener('click', function() {
      fetch(APP_URL + '/api/notifications/mark_read.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({all: true})
      }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) {
          document.querySelectorAll('#notif-bell [data-notif-id]').forEach(function(el) {
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

  // Oznacz wszystkie — dzwonek Zadania
  var tskMarkAll = document.getElementById('tsk-bell-main-mark-all');
  if (tskMarkAll) {
    tskMarkAll.addEventListener('click', function () {
      fetch(APP_URL + '/tasks/api/notif_poll.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'mark_all'})
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) return;
        document.querySelectorAll('#tsk-bell-main [data-notif-id]').forEach(function (el) {
          el.classList.remove('fw-semibold');
          var dot = el.querySelector('[style*="ea580c"]');
          if (dot) dot.remove();
        });
        var badge = document.getElementById('tsk-bell-main-badge');
        if (badge) badge.classList.add('d-none');
        tskMarkAll.remove();
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
