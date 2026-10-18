<?php
/**
 * includes/szo_metro.php — warstwa wizualna „Metro UI” samego SZO (powłoka #navbar + #content).
 *
 * Włączana przez includes/header.php jako ostatni blok <head>, gdy aktywna zakładka menu
 * (includes/menu.php) należy do SZO_METRO_GROUPS — Umowy, Wolontariat, Finanse, Ludzie, IT, Biuro i Admin.
 * Nagłówek (szo_metro_header_assets) jest w Metro na wszystkich stronach powłoki SZO.
 * Kolejne grupy dochodzą przez dopisanie identyfikatora węzła menu i koloru akcentu.
 *
 * Ta sama szata co EZD (ezd_metro.php), CRM (crm_metro.php) i Panel (pv_metro.php):
 * ciemny app-bar #1d1d1d, Segoe UI, lekkie duże tytuły, ZERO zaokrągleń, cieni i gradientów,
 * płaskie szare powierzchnie #f2f2f2, kwadratowe przyciski/pola z ramką 2px, pasek zakładek
 * jak pivot, nagłówki tabel wersalikami z grubą linią, alerty jako pas z lewą krawędzią,
 * żółty fokus. Akcent zależy od grupy (Metro cobalt / magenta / green / violet / teal / orange / crimson, wszystkie AA z bielą).
 *
 * Tylko CSS. Selektory zaczynają się od `html body`, bo Tailwind CDN (@apply w header.php
 * i klasy tw-*) wstrzykuje reguły dopiero po załadowaniu — przy równej specyficzności wygrałby.
 * Znaczniki, ID i zaczepy JS bez zmian.
 */

const SZO_METRO_GROUPS = [
    'umowy'       => ['#0050ef', '#003bb0', '#e6eefd'],   // cobalt
    'wolontariat' => ['#c0006a', '#8f004f', '#fbe5f1'],   // magenta (przyciemniona do AA)
    'finanse'     => ['#007a00', '#005700', '#e5f2e5'],   // green
    'ludzie'      => ['#6a00ff', '#4f00bf', '#f0e5ff'],   // violet
    'it'          => ['#00807e', '#005f5d', '#e5f2f2'],   // teal
    'biuro'       => ['#c25100', '#8f3c00', '#fbeee5'],   // orange (przyciemniony do AA)
    'admin'       => ['#a20025', '#76001b', '#f6e5e9'],   // crimson
];

/** Identyfikator aktywnej grupy Metro z drzewa menu albo null. */
function szo_metro_group(array $tree): ?string {
    foreach ($tree as $n)
        if (!empty($n['active']) && isset(SZO_METRO_GROUPS[$n['id'] ?? ''])) return $n['id'];
    return null;
}

/** Domyślny akcent poza grupami Metro (np. pulpit, RODO) — Metro cobalt. */
const SZO_METRO_DEFAULT = ['#0050ef', '#003bb0', '#e6eefd'];

/**
 * Nagłówek SZO (app-bar, pivot zakładek, menu, launcher, paleta Ctrl+K) i pływające
 * przyciski w szacie Metro — na KAŻDEJ stronie powłoki SZO (poza EZD, które ma własną).
 * Akcent bierze z aktywnej grupy, jeśli należy do SZO_METRO_GROUPS.
 */
function szo_metro_header_assets(?string $group): void {
    [$acc, $accD, $acc50] = SZO_METRO_GROUPS[$group ?? ''] ?? SZO_METRO_DEFAULT;
?>
<style id="szo-metro-head">
:root{
  --sm-acc:<?= $acc ?>;--sm-acc-d:<?= $accD ?>;--sm-acc-50:<?= $acc50 ?>;
  --sm-dark:#1d1d1d;--sm-light:#f2f2f2;--sm-light-h:#e6e6e6;--sm-line:#e1e1e1;--sm-muted:#5f5f5f;--sm-field:#8a8a8a;--sm-ink:#111827;
  --sm-red:#a20025;--sm-orange:#c25100;--sm-green:#007a00;--sm-blue:#0050ef;
  --sm-font:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;
}
html body{font-family:var(--sm-font);-webkit-font-smoothing:antialiased}
html body *:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px;box-shadow:none!important}

/* ── Zero zaokrągleń i cieni w nagłówku i jego menu ── */
html body #navbar,html body #navbar *:not(.form-check-input),html body #navbar .dropdown-menu *,
html body #navOffcanvas,html body #navOffcanvas *:not(.form-check-input),
html body .fl-trigger,html body .fl-trigger *,html body #cmdk-root *{border-radius:0!important}
html body #navbar{box-shadow:none;font-family:var(--sm-font)}

/* ── App-bar (pasek marki) — zawsze #1d1d1d, niezależnie od koloru organizacji ── */
html body .nb-top{background:var(--sm-dark);height:48px;gap:.25rem;padding:0 0 0 1rem}
html body .nb-brand,html body .nb-brand:hover{color:#fff;opacity:1}
html body .nb-brand-icon{background:var(--sm-acc);color:#fff;width:32px;height:32px}
html body .nb-logo-img{background:#fff;padding:2px}
html body .nb-brand-name{font-weight:600;letter-spacing:0;font-size:.9rem}
html body .nb-brand-sub{opacity:.6;letter-spacing:.04em}
html body .nb-search-wrap input{height:32px;background:rgba(255,255,255,.1);border:2px solid transparent;color:#fff}
html body .nb-search-wrap input::placeholder,html body .nb-search-icon{color:rgba(255,255,255,.65)}
html body .nb-search-wrap input:hover{background:rgba(255,255,255,.16);border-color:transparent}
html body .nb-search-wrap input:focus{background:#fff;color:var(--sm-dark);border-color:var(--sm-acc)}
html body .nb-search-kbd{border-color:rgba(255,255,255,.35);color:rgba(255,255,255,.7)}
/* Przyciski app-bara: płaskie kwadraty na pełną wysokość paska */
html body #nb-right{gap:0;align-self:stretch;align-items:stretch}
html body #nb-right>.dropdown,html body #nb-right>#mod-sw{display:flex;align-items:stretch;margin:0!important}
html body #nb-right>#ajax-spinner{align-self:center;margin:0 .5rem}
html body .nb-icon-btn{width:48px;height:48px;background:transparent;border:0!important;color:#fff}
html body .nb-icon-btn>i{color:#fff!important}
html body .nb-icon-btn:hover,html body .nb-icon-btn[aria-expanded="true"]{background:rgba(255,255,255,.12);color:#fff}
html body .nb-icon-btn[aria-expanded="true"]{background:var(--sm-acc)}
html body #navbar .nb-icon-btn .badge,html body #navbar .fl-trigger-dot{top:9px!important;left:auto!important;right:4px;transform:none!important;
  border-radius:0!important;background:var(--sm-red)!important;color:#fff;font-size:.58rem;padding:.15em .35em;min-width:0}
html body .nb-user-chip{height:48px;padding:0 .85rem 0 .6rem;background:transparent;border:0;color:#fff;font-weight:400}
html body .nb-user-chip:hover{background:rgba(255,255,255,.12);color:#fff}
html body .nb-user-chip[aria-expanded="true"]{background:var(--sm-acc);color:#fff}
html body .nb-user-chip .avatar{width:28px;height:28px;background:var(--sm-acc);font-size:.66rem}
html body .nb-user-chip[aria-expanded="true"] .avatar{background:var(--sm-dark)}
html body .nb-toggler{color:#fff;width:48px;height:48px}
/* Launcher modułów (modules/launcher) w app-barze */
html body #navbar .fl-trigger{height:48px;padding:0 .9rem;background:transparent;border:0;color:#fff;font-weight:600;position:relative}
html body #navbar .fl-trigger:hover{background:rgba(255,255,255,.12);color:#fff}
html body #navbar .fl-trigger[aria-expanded="true"]{background:var(--sm-acc);color:#fff}
html body #navbar .fl-trigger-grid i{border-radius:0}
html body #navbar .fl-trigger-dot{position:absolute;border:0;height:auto}

/* ── Pasek zakładek → pivot ──────────────────────────────────────────── */
html body .nb-nav{height:44px;align-items:stretch;border-bottom:1px solid var(--sm-line);gap:0;padding:0 1rem 0 0}
html body .nb-home{height:auto;width:48px;color:var(--sm-muted)}
html body .nb-home:hover{background:var(--sm-light);color:var(--sm-dark)}
html body .nb-home.active{background:none;color:var(--sm-dark);box-shadow:inset 0 -3px 0 var(--sm-acc)}
html body .nb-tabs{align-items:stretch;gap:0}
html body .nb-tabs>li{display:flex}
html body .nb-tab{height:100%;padding:3px .8rem 0;border-bottom:3px solid transparent;color:var(--sm-muted);font-size:.92rem;font-weight:400}
html body .nb-tab>.bi:first-child{color:inherit}
html body .nb-tab:hover,html body .nb-tab.show{background:var(--sm-light);color:var(--sm-dark)}
html body .nb-tab:hover>.bi:first-child,html body .nb-tab.show>.bi:first-child{color:inherit}
html body .nb-tab.active{background:none;color:var(--sm-dark);font-weight:600;border-bottom-color:var(--sm-acc)}
html body .nb-tab.active>.bi:first-child{color:var(--sm-acc)}
html body .nb-tab.nb-ezd-link,html body .nb-tab.nb-ezd-link>.bi:first-child{color:var(--sm-red)}
html body .nb-tab.nb-ezd-link:hover,html body .nb-tab.nb-ezd-link.show{background:var(--sm-light);color:var(--sm-red)}
html body .nb-tab:focus-visible{outline-offset:-3px!important}
html body .nb-tab .badge{font-size:.6rem;padding:.2em .4em}
html body .nb-tab .badge.bg-warning{background:var(--sm-orange)!important;color:#fff!important}
html body .nb-ptitle{border-left-color:var(--sm-line);color:var(--sm-muted);font-weight:300;font-size:.92rem;align-self:center}
html body .nb-ptitle .bi{color:var(--sm-field)!important}
html body .nb-ptitle-m,html body #page-title-bar.nb-ptitle-fallback{font-weight:300;color:var(--sm-dark);border-bottom-color:var(--sm-line)}

/* Rozwijane menu nagłówka — płaskie, zaznaczenie pełnym kolorem akcentu */
html body #navbar .dropdown-menu{border:0;margin-top:0!important;box-shadow:0 12px 32px -12px rgba(16,24,40,.35);padding:.35rem 0}
html body #navbar .dropdown-item{padding:.5rem 1rem}
html body #navbar .dropdown-item:hover,html body #navbar .dropdown-item:focus{background:var(--sm-acc);color:#fff}
html body #navbar .dropdown-item:hover>i,html body #navbar .dropdown-item:focus>i,html body #navbar .dropdown-item:hover i{color:#fff!important}
html body #navbar .dropdown-item.active{background:var(--sm-acc-50);color:var(--sm-dark);font-weight:600;box-shadow:inset 3px 0 0 var(--sm-acc)}
html body #navbar .dropdown-item.active>i{color:var(--sm-acc)}
html body #navbar .dropdown-header,html body .nb-section-label{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:var(--sm-muted);font-weight:700}
html body .nb-mega-col+.nb-mega-col{border-left:1px solid var(--sm-line)}
html body #navbar .dropdown-menu .btn{border:2px solid var(--sm-line)!important;background:var(--sm-light)!important;color:var(--sm-dark)!important;font-weight:600}
html body #navbar .dropdown-menu .btn:hover{background:var(--sm-acc)!important;border-color:var(--sm-acc)!important;color:#fff!important}
html body #navbar .dropdown-menu .btn-link{border:0!important;background:none!important;color:var(--sm-acc-d)!important}
html body #navbar .dropdown-menu .badge{border-radius:0!important}
/* Menu mobilne (offcanvas) */
html body #navOffcanvas .oc-link.active{background:var(--sm-acc-50);color:var(--sm-dark);box-shadow:inset 3px 0 0 var(--sm-acc)}
html body #navOffcanvas .oc-user .avatar{background:var(--sm-acc)}
/* Paleta Ctrl+K */
html body #cmdk-root .cmdk-on{background:var(--sm-acc)!important;color:#fff!important}
html body #cmdk-root .cmdk-on .cmdk-sb,html body #cmdk-root .cmdk-on .cmdk-ic{color:rgba(255,255,255,.85)!important}

/* ── Pływające przyciski: szybkie akcje, asystent, czat — kwadratowe kafle Metro ── */
html body #qaw-fab,html body #asaiw-fab,html body #msg-fab{border-radius:0!important;box-shadow:none!important;width:48px;height:48px;transition:filter .12s}
html body #qaw-fab:hover,html body #asaiw-fab:hover,html body #msg-fab:hover{transform:none!important;filter:brightness(1.15);box-shadow:none!important}
html body #qaw-fab{background:var(--sm-acc)}
html body #asaiw-fab{background:#6a00ff}
html body #msg-fab{background:var(--sm-dark)}
html body #msg-fab-badge{border-radius:0!important;background:var(--sm-red)!important}
/* Panel szybkich akcji */
html body #qaw-panel,html body #qaw-panel *{border-radius:0!important}
html body #qaw-panel{font-family:var(--sm-font);border:0;box-shadow:0 20px 50px -20px rgba(0,0,0,.45)}
html body #qaw-panel .qaw-head{background:var(--sm-dark);color:#fff;border-bottom:0;font-weight:600}
html body #qaw-panel .qaw-head .qaw-x{color:rgba(255,255,255,.75)}
html body #qaw-panel .qaw-head .qaw-x:hover{background:rgba(255,255,255,.12);color:#fff}
html body #qaw-panel .qaw-tabs{background:none;padding:0;gap:0;border-bottom:1px solid var(--sm-line)}
html body #qaw-panel .qaw-tab{border-bottom:3px solid transparent;margin-bottom:-1px;color:var(--sm-muted);padding:.4rem .2rem}
html body #qaw-panel .qaw-tab.is-on{background:none;box-shadow:none;color:var(--sm-dark);border-bottom-color:var(--sm-acc)}
html body #qaw-panel .qaw-in{border:2px solid var(--sm-field)}
html body #qaw-panel .qaw-in:focus{border-color:var(--sm-acc);box-shadow:none}
html body #qaw-panel .qaw-lbl{color:var(--sm-dark)}
html body #qaw-panel .qaw-hit:hover{background:var(--sm-acc);color:#fff}
html body #qaw-panel .qaw-btn{background:var(--sm-acc)}
html body #qaw-panel .qaw-btn:hover{background:var(--sm-dark)}
html body #qaw-panel .qaw-link{border:2px solid var(--sm-line);background:var(--sm-light);color:var(--sm-dark);font-weight:600}
html body #qaw-panel .qaw-link:hover{background:var(--sm-acc);border-color:var(--sm-acc);color:#fff}
html body #qaw-panel .qaw-err{border:0;border-left:6px solid var(--sm-red);background:var(--sm-light);color:var(--sm-red)}
html body #qaw-panel .qaw-foot{background:var(--sm-light);border-top:0;color:var(--sm-muted)}
html body #qaw-panel .qaw-picked{color:var(--sm-green)}
html body #qaw-panel input[type=checkbox]{accent-color:var(--sm-acc)}
</style>
<?php
}

/** Treść strony (#content, okna modalne) w szacie Metro — tylko dla grup z SZO_METRO_GROUPS. */
function szo_metro_assets(string $group): void {
?>
<style id="szo-metro">
html body{background:#fff!important;background-image:none!important;color:var(--sm-ink)}
html body #content *:not(.form-check-input):not(.spinner-border):not(.spinner-grow):not(.rounded-circle):not(.form-range):not(.tw-rounded-full),
html body .modal-content,html body .modal-content *:not(.form-check-input):not(.rounded-circle):not(.spinner-border),
html body .offcanvas,html body .offcanvas *:not(.form-check-input):not(.rounded-circle),html body .toast,html body .popover,html body .tooltip-inner{border-radius:0!important}
html body #content *:not(.dropdown-menu){box-shadow:none!important}
html body #content .bg-gradient,html body #content [class*="tw-bg-gradient"]{background-image:none!important}
html body .dropdown-menu,html body .dropdown-menu *{border-radius:0!important}
html body .dropdown-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)}
html body .dropdown-item:hover,html body .dropdown-item:focus{background:var(--sm-acc);color:#fff}
html body .dropdown-item:hover>i,html body .dropdown-item:focus>i{color:#fff!important}
html body .dropdown-item.active{background:var(--sm-acc-50);color:var(--sm-dark);font-weight:600;box-shadow:inset 3px 0 0 var(--sm-acc)}
html body .dropdown-header{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:var(--sm-muted);font-weight:700}

/* ── Treść ───────────────────────────────────────────────────────────── */
html body #content{padding:1.75rem 2rem 5rem}
@media (max-width:768px){html body #content{padding:1rem .75rem 4rem}}
html body #content h1,html body #content .h1,html body #content h2.h3,html body #content h1.h3,html body #content h1.h4,html body #content h2.h4{font-weight:300!important;letter-spacing:-.02em;color:var(--sm-dark)}
html body #content h1,html body #content .h1{font-size:2rem;line-height:1.1}
html body #content a:not(.btn):not(.dropdown-item):not(.nav-link):not(.page-link):not(.list-group-item){color:var(--sm-acc-d)}
html body #content a:not(.btn):not(.dropdown-item):not(.nav-link):not(.page-link):not(.list-group-item):hover{color:var(--sm-dark)}
html body #content .text-primary{color:var(--sm-acc-d)!important}
html body #content .bg-primary{background-color:var(--sm-acc)!important}
html body #content .border-primary{border-color:var(--sm-acc)!important}

/* Karty i płaskie powierzchnie */
html body #content .card,html body #content .list-group{border:1px solid var(--sm-line);background:#fff}
html body #content .card-header{background:var(--sm-light);border-bottom:0;font-weight:600}
html body #content .card-footer{background:var(--sm-light);border-top:0}
html body #content .bg-light{background-color:var(--sm-light)!important}
html body #content .cv-section{border-bottom-color:var(--sm-line)}
html body #content .cv-section-title,html body #content .section-title{font-weight:300;font-size:1.15rem;letter-spacing:0;text-transform:none;color:var(--sm-dark)}
html body #content .cv-section-icon{background:var(--sm-acc);color:#fff}
html body #content .cv-label,html body #content .detail-label,html body #content .adv-label{color:var(--sm-muted);letter-spacing:.1em;text-transform:uppercase;font-size:.66rem;font-weight:700}

/* Przyciski — kwadratowe, ramka 2px */
html body #content .btn,html body .modal .btn{font-weight:600;border-width:2px}
html body #content .btn-link,html body .modal .btn-link{border-width:0;color:var(--sm-acc-d)}
html body #content .btn-primary,html body .modal .btn-primary{
  --bs-btn-bg:var(--sm-acc);--bs-btn-border-color:var(--sm-acc);--bs-btn-color:#fff;
  --bs-btn-hover-bg:var(--sm-dark);--bs-btn-hover-border-color:var(--sm-dark);--bs-btn-hover-color:#fff;
  --bs-btn-active-bg:var(--sm-dark);--bs-btn-active-border-color:var(--sm-dark);
  --bs-btn-disabled-bg:var(--sm-acc);--bs-btn-disabled-border-color:var(--sm-acc)}
html body #content .btn-outline-primary,html body .modal .btn-outline-primary{
  --bs-btn-color:var(--sm-acc-d);--bs-btn-border-color:var(--sm-acc);--bs-btn-hover-bg:var(--sm-acc);--bs-btn-hover-border-color:var(--sm-acc);--bs-btn-hover-color:#fff;
  --bs-btn-active-bg:var(--sm-acc);--bs-btn-active-border-color:var(--sm-acc)}
html body #content .btn-outline-secondary,html body .modal .btn-outline-secondary{
  --bs-btn-color:var(--sm-dark);--bs-btn-border-color:var(--sm-dark);--bs-btn-hover-bg:var(--sm-dark);--bs-btn-hover-border-color:var(--sm-dark);--bs-btn-hover-color:#fff}
html body #content .btn-secondary,html body .modal .btn-secondary{--bs-btn-bg:var(--sm-dark);--bs-btn-border-color:var(--sm-dark);--bs-btn-hover-bg:#000;--bs-btn-hover-border-color:#000}
html body #content .btn-success,html body .modal .btn-success{--bs-btn-bg:var(--sm-green);--bs-btn-border-color:var(--sm-green);--bs-btn-hover-bg:var(--sm-dark);--bs-btn-hover-border-color:var(--sm-dark)}
html body #content .btn-danger,html body .modal .btn-danger{--bs-btn-bg:var(--sm-red);--bs-btn-border-color:var(--sm-red);--bs-btn-hover-bg:var(--sm-dark);--bs-btn-hover-border-color:var(--sm-dark)}
html body #content .btn-outline-danger,html body .modal .btn-outline-danger{--bs-btn-color:var(--sm-red);--bs-btn-border-color:var(--sm-red);--bs-btn-hover-bg:var(--sm-red);--bs-btn-hover-border-color:var(--sm-red)}
html body #content .btn-outline-success{--bs-btn-color:var(--sm-green);--bs-btn-border-color:var(--sm-green);--bs-btn-hover-bg:var(--sm-green);--bs-btn-hover-border-color:var(--sm-green)}
html body #content .btn-light{--bs-btn-bg:var(--sm-light);--bs-btn-border-color:var(--sm-light);--bs-btn-hover-bg:var(--sm-light-h);--bs-btn-hover-border-color:var(--sm-light-h)}

/* Pola — kwadratowe, ramka 2px, fokus w kolorze akcentu */
html body #content .form-control,html body #content .form-select,html body #content .input-group-text,
html body .modal .form-control,html body .modal .form-select{border:2px solid var(--sm-field);box-shadow:none}
html body #content .form-control:focus,html body #content .form-select:focus,html body .modal .form-control:focus,html body .modal .form-select:focus{border-color:var(--sm-acc);box-shadow:none}
html body #content .input-group-text{background:var(--sm-light)}
html body .form-check-input:checked{background-color:var(--sm-acc);border-color:var(--sm-acc)}
html body .form-check-input:focus{box-shadow:none;border-color:var(--sm-acc)}
html body #content .form-label{font-weight:600;color:var(--sm-dark)}
html body .ts-wrapper .ts-control,html body .tox-tinymce{border-radius:0!important}

/* Tabele — nagłówki wersalikami z grubą dolną linią */
html body #content .table{--bs-table-border-color:var(--sm-line)}
html body #content .table>thead>tr>th,html body #content .table>thead>tr>td{background:#fff;text-transform:uppercase;font-size:.68rem;font-weight:700;letter-spacing:.1em;color:var(--sm-muted);border-bottom:2px solid var(--sm-dark)}
html body #content .table-light,html body #content .table-light>*>*{--bs-table-bg:#fff;--bs-table-color:var(--sm-muted)}
html body #content .table-hover>tbody>tr:hover>*{--bs-table-hover-bg:var(--sm-light);--bs-table-bg-state:var(--sm-light)}
html body #content .table-active,html body #content .table-active>*{--bs-table-bg-state:var(--sm-acc-50)}

/* Znaczniki, paginacja, listy */
html body #content .badge,html body .modal .badge{border-radius:0;font-weight:700;letter-spacing:.03em}
html body #content .rounded-pill{border-radius:0!important}
html body #content .pagination{--bs-pagination-active-bg:var(--sm-acc);--bs-pagination-active-border-color:var(--sm-acc);--bs-pagination-color:var(--sm-dark);--bs-pagination-hover-color:var(--sm-dark)}
html body #content .list-group{--bs-list-group-border-color:var(--sm-line)}
html body #content .list-group-item.active{background:var(--sm-acc);border-color:var(--sm-acc)}
html body #content .progress-bar{background-color:var(--sm-acc)}

/* Zakładki → pivot */
html body #content .nav-tabs{border-bottom:1px solid var(--sm-line);gap:1.25rem}
html body #content .nav-tabs .nav-link{border:0;border-bottom:3px solid transparent;margin-bottom:-1px;background:none;color:var(--sm-muted);font-weight:300;font-size:1.05rem;padding:.4rem 0 .55rem}
html body #content .nav-tabs .nav-link:hover{color:var(--sm-dark);background:none}
html body #content .nav-tabs .nav-link.active{color:var(--sm-dark);font-weight:600;background:none;border-bottom-color:var(--sm-acc)}
html body #content .nav-pills .nav-link{color:var(--sm-dark)}
html body #content .nav-pills .nav-link.active{background:var(--sm-acc);color:#fff}

/* Alerty — pas z grubą lewą krawędzią */
html body #content .alert,html body .modal .alert{background:var(--sm-light);color:var(--sm-dark);border:0;border-left:6px solid var(--sm-acc)}
html body #content .alert-success,html body .modal .alert-success{border-left-color:var(--sm-green)}
html body #content .alert-danger,html body .modal .alert-danger{border-left-color:var(--sm-red)}
html body #content .alert-warning,html body .modal .alert-warning{border-left-color:var(--sm-orange)}
html body #content .alert-info,html body .modal .alert-info{border-left-color:var(--sm-blue)}
html body #content .alert-light,html body #content .alert-secondary{border-left-color:var(--sm-field)}

/* Okna modalne i offcanvas */
html body .modal-content{border:0;box-shadow:0 20px 50px -20px rgba(0,0,0,.45)}
html body .modal-header,html body .modal-footer{border-color:var(--sm-line)}
html body .modal-title{font-weight:300!important;font-size:1.35rem}
html body .offcanvas .oc-link.active{background:var(--sm-acc-50);color:var(--sm-dark);box-shadow:inset 3px 0 0 var(--sm-acc)}

/* ── Widok umowy (includes/contract_view_header.php + contracts/includes/cv_layout.php) ──
   Hero bez karty: nadtytuł w akcencie, lekki duży tytuł, statystyki jako płaskie szare kafle,
   cienki kwadratowy pasek postępu. Zakładki umowy jak pivot, treść bez ramki-karty. */
html body #content .contract-hero{background:none;border:0;padding:0 0 1.1rem;margin-bottom:1.25rem;border-bottom:1px solid var(--sm-line)}
html body #content .contract-hero-top{margin-bottom:1rem}
html body #content .contract-hero-eyebrow{color:var(--sm-acc-d);letter-spacing:.16em;font-size:.7rem}
html body #content .contract-hero-eyebrow .cvh-num{color:var(--sm-muted);opacity:1}
html body #content .contract-hero-title{font-size:2.25rem;font-weight:300;letter-spacing:-.02em;line-height:1.1;color:var(--sm-dark)}
html body #content .contract-hero-subtitle{color:var(--sm-muted);font-size:.85rem}
html body #content .contract-hero-subtitle a{color:var(--sm-muted)}
html body #content .contract-hero-actions{gap:.35rem}
html body #content .contract-hero-actions .btn{padding:.3rem .7rem}
html body #content .contract-hero-stats{border-top:0;padding-top:0;gap:.25rem;align-items:stretch}
html body #content .contract-hero-stat{background:var(--sm-light);padding:.6rem 1rem .65rem;min-width:150px}
html body #content .contract-hero-stat--wide{flex:1 1 300px}
html body #content .cvh-stat-label{color:var(--sm-muted);letter-spacing:.12em;font-size:.62rem;margin-bottom:.25rem}
html body #content .cvh-stat-val{font-size:1.05rem;color:var(--sm-dark)}
html body #content .cvh-stat-val.text-success{color:var(--sm-green)!important;font-weight:600!important}
html body #content .cvh-status-select{font-size:1.05rem;font-weight:600;padding-left:0}
html body #content .cvh-status-select:hover{background-color:var(--sm-light-h)}
html body #content .contract-hero-progress{border-top:0;margin-top:.25rem;padding-top:.6rem}
html body #content .cvh-prog-bar{height:4px;background:var(--sm-light-h)}
html body #content .cvh-prog-fill{background:var(--sm-acc)}
html body #content .cvh-prog-fill.cvh-prog-warn{background:var(--sm-orange)}
html body #content .cvh-prog-fill.cvh-prog-end{background:var(--sm-field)}
html body #content .cvh-prog-label{color:var(--sm-muted)}

html body #content .cv-side-tabs,html body #content #wolontariatTabs{background:none;border:0;border-bottom:1px solid var(--sm-line);padding:0;gap:0;margin:0 0 .25rem;align-items:stretch}
html body #content .cv-side-tabs .nav-link,html body #content #wolontariatTabs .nav-link{border:0!important;border-bottom:3px solid transparent!important;margin-bottom:-1px;padding:.55rem .85rem .5rem;
  color:var(--sm-muted)!important;font-size:.95rem;font-weight:400!important;background:none!important}
html body #content .cv-side-tabs .nav-link:hover,html body #content #wolontariatTabs .nav-link:hover{color:var(--sm-dark)!important;background:var(--sm-light)!important}
html body #content .cv-side-tabs .nav-link.active,html body #content .cv-side-tabs .nav-link[aria-selected="true"],
html body #content #wolontariatTabs .nav-link.active,html body #content #wolontariatTabs .nav-link[aria-selected="true"]{
  color:var(--sm-dark)!important;font-weight:600!important;background:none!important;border-bottom-color:var(--sm-acc)!important}
html body #content #wolontariatTabs .nav-link.active .bi,html body #content .cv-side-tabs .nav-link.active .bi,html body #content .cv-side-tabs .nav-link[aria-selected="true"] .bi{color:var(--sm-acc)}
html body #content .cv-side-tabs .nav-link .badge,html body #content #wolontariatTabs .nav-link .badge{font-size:.62rem;padding:.2em .45em}
html body #content .cv-side-tabs-content,html body #content #wolontariatTabsContent{background:#fff;border:0!important;padding:1rem 0 0!important}
html body #content #wolontariatTabsContent>div+div{border-top-width:1px;border-top-color:var(--sm-line)}
html body #content .cv-section{border-bottom-color:var(--sm-line);padding:1.4rem 0}
html body #content .cv-section-icon{width:32px;height:32px;background:var(--sm-acc)!important;color:#fff!important}
html body #content .cv-table th{color:var(--sm-muted);border-bottom:2px solid var(--sm-dark);letter-spacing:.1em}
html body #content .cv-table td{border-bottom-color:var(--sm-line)}
html body #content .cv-value,html body #content .detail-value{color:var(--sm-dark)}

/* ── Widok umowy: typowy układ Metro ─────────────────────────────────────────
   Tytuł nad paskiem poleceń (app bar): płaskie przyciski bez ramek na szarym pasie,
   rozwinięte menu w kolorze akcentu. Pivot: duże lekkie nagłówki bez ikon. */
html body #content .contract-hero-top{flex-direction:column;align-items:stretch;gap:.9rem}
html body #content .contract-hero-actions{order:2;gap:0;background:var(--sm-light);flex-wrap:wrap;align-items:stretch}
html body #content .contract-hero-actions>.dropdown,html body #content .contract-hero-actions>.btn-group,html body #content .contract-hero-actions>form{display:flex}
html body #content .contract-hero-actions .btn{height:40px;padding:0 1rem!important;border:0!important;background:transparent!important;
  color:var(--sm-dark)!important;font-weight:400;font-size:.88rem;display:inline-flex;align-items:center;gap:.45rem}
html body #content .contract-hero-actions .btn:hover,html body #content .contract-hero-actions .btn:focus-visible{background:var(--sm-light-h)!important}
html body #content .contract-hero-actions .btn.show,html body #content .contract-hero-actions .btn[aria-expanded="true"]{background:var(--sm-acc)!important;color:#fff!important}
html body #content .contract-hero-actions .btn.show .bi,html body #content .contract-hero-actions .btn[aria-expanded="true"] .bi{color:#fff!important}
html body #content .contract-hero-actions .btn .bi{font-size:1rem}
html body #content .contract-hero-actions .btn-outline-danger{color:var(--sm-red)!important}
html body #content .contract-hero-actions .btn-outline-success .bi{color:var(--sm-green)}
html body #content .contract-hero-actions .btn.disabled{opacity:.45}
html body #content .contract-hero-actions>a:last-child,html body #content .contract-hero-actions>.btn:last-child{margin-left:auto}

html body #content .cv-side-tabs .nav-link,html body #content #wolontariatTabs .nav-link{font-size:1.15rem;font-weight:300!important;padding:.45rem .9rem .5rem}
html body #content .cv-side-tabs .nav-link>.bi:first-child,html body #content #wolontariatTabs .nav-link>.bi:first-child{display:none}
html body #content .cv-side-tabs .nav-link.active,html body #content .cv-side-tabs .nav-link[aria-selected="true"],
html body #content #wolontariatTabs .nav-link.active,html body #content #wolontariatTabs .nav-link[aria-selected="true"]{font-weight:400!important}
html body #content #wolontariatTabs .cv-tab-all{margin-left:auto;display:flex}
html body #content #wolontariatTabs .cv-tab-all .nav-link{font-size:.85rem;color:var(--sm-muted)!important}
html body #content #wolontariatTabs .cv-tab-all .nav-link>.bi:first-child{display:inline-block}
@media (max-width:860px){html body #content #wolontariatTabs{flex-wrap:nowrap;overflow-x:auto}}

/* Znaczniki Bootstrapa w treści — pełne kolory Metro, biały tekst */
html body #content .badge.bg-info,html body #content .badge.text-bg-info{background:var(--sm-blue)!important;color:#fff!important}
html body #content .badge.bg-warning,html body #content .badge.text-bg-warning{background:var(--sm-orange)!important;color:#fff!important}
html body #content .badge.bg-success,html body #content .badge.text-bg-success{background:var(--sm-green)!important;color:#fff!important}
html body #content .badge.bg-danger,html body #content .badge.text-bg-danger{background:var(--sm-red)!important;color:#fff!important}
html body #content .badge.bg-primary,html body #content .badge.text-bg-primary{background:var(--sm-acc)!important;color:#fff!important}
html body #content .badge.bg-secondary,html body #content .badge.text-bg-secondary{background:var(--sm-muted)!important;color:#fff!important}
html body #content .badge.bg-light{background:var(--sm-light)!important;color:var(--sm-dark)!important;border:0!important}
html body #content .badge.bg-dark{background:var(--sm-dark)!important}

/* Status umowy — duży kafel Metro po prawej (obok tytułu, paska poleceń i statystyk).
   Kolor kafla z klasy statusu: startowo z data-st, po zmianie w selekcie z klasy cvh-status-* (:has). */
html body #content .contract-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(240px,320px);
  grid-template-areas:"top status" "stats status" "prog prog";column-gap:1rem;align-items:start}
html body #content .contract-hero>.contract-hero-top{grid-area:top;min-width:0}
html body #content .contract-hero>.contract-hero-stats{grid-area:stats;margin-top:.25rem}
html body #content .contract-hero>.contract-hero-progress{grid-area:prog}
html body #content .contract-hero>.contract-hero-stat--status{--t:#6d6d6d;grid-area:status;align-self:stretch;display:flex;flex-direction:column;
  min-height:150px;padding:1rem 1.15rem;background:var(--t);color:#fff;min-width:0}
html body #content .contract-hero-stat--status .cvh-stat-label{color:rgba(255,255,255,.85);font-size:.66rem;letter-spacing:.16em}
html body #content .contract-hero-stat--status>span,html body #content .contract-hero-stat--status>.badge{margin-top:auto}
html body #content .contract-hero-stat--status .cvh-status-select{color:#fff!important;font-size:2rem;font-weight:300;line-height:1.15;padding:0 1.6rem 0 0;width:100%;
  background-color:transparent;background-size:14px 9px;background-position:right .1rem center;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23ffffff'/%3E%3C/svg%3E")}
html body #content .contract-hero-stat--status .cvh-status-select:hover{background-color:rgba(255,255,255,.12)}
html body #content .contract-hero-stat--status .cvh-status-select option{color:#1d1d1d;background:#fff;font-size:1rem}
html body #content .contract-hero-stat--status .badge{background:transparent!important;color:#fff!important;font-size:2rem!important;font-weight:300;padding:0;letter-spacing:0;text-align:left;white-space:normal}
html body #content .contract-hero-stat--status .badge+.badge,html body #content .contract-hero-stat--status .badge.ms-1{font-size:.72rem!important;font-weight:700;background:rgba(0,0,0,.25)!important;padding:.25rem .45rem;margin-top:.5rem}
html body #content .contract-hero-stat--status .spinner-border{color:#fff!important}
html body #content .contract-hero-stat--status[data-st="primary"]{--t:#0050ef}
html body #content .contract-hero-stat--status[data-st="info"]{--t:#0f6fa8}
html body #content .contract-hero-stat--status[data-st="success"]{--t:#007a00}
html body #content .contract-hero-stat--status[data-st="warning"]{--t:#c25100}
html body #content .contract-hero-stat--status[data-st="orange"]{--t:#c25100}
html body #content .contract-hero-stat--status[data-st="danger"]{--t:#a20025}
html body #content .contract-hero-stat--status[data-st="teal"]{--t:#00807e}
html body #content .contract-hero-stat--status[data-st="indigo"]{--t:#6a00ff}
html body #content .contract-hero-stat--status[data-st="purple"]{--t:#6a00ff}
html body #content .contract-hero-stat--status[data-st="dark"]{--t:#1d1d1d}
html body #content .contract-hero-stat--status[data-st="secondary"]{--t:#6d6d6d}
/* po zmianie w selekcie — wyższa specyficzność niż data-st */
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-primary){--t:#0050ef}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-info){--t:#0f6fa8}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-success){--t:#007a00}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-warning){--t:#c25100}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-orange){--t:#c25100}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-danger){--t:#a20025}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-teal){--t:#00807e}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-indigo){--t:#6a00ff}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-purple){--t:#6a00ff}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-dark){--t:#1d1d1d}
html body #content .contract-hero-stat--status:has(.cvh-status-select.cvh-status-secondary){--t:#6d6d6d}
@media (max-width:991.98px){
  html body #content .contract-hero{grid-template-columns:1fr;grid-template-areas:"top" "status" "stats" "prog"}
  html body #content .contract-hero>.contract-hero-stat--status{min-height:0;margin-top:.75rem}
}

/* ── Kreator umowy (contracts/wolontariat/add.php) — tylko elementy usprawnień:
   znacznik brakujących pól na kroku, lista „Brakuje” w podglądzie, „Krok X z 6”, skróty, baner szkicu.
   Wygląd samego kreatora (.wiz-root) jest w stylach strony. */
html body #content .wiz-hstep-miss{position:absolute;top:0;left:34px;min-width:18px;height:18px;padding:0 .25rem;background:var(--sm-red);color:#fff;font-size:.62rem;font-weight:700;display:flex;align-items:center;justify-content:center}
html body #content .wiz-missing{background:#fff;border:1px solid var(--sm-line);border-top:0;border-left:6px solid var(--sm-red);padding:.6rem .8rem}
html body #content .wiz-missing.is-ok{border-left-color:var(--sm-green);color:var(--sm-green);font-weight:600;font-size:.84rem}
html body #content .wiz-missing-h{font-size:.66rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--sm-red);margin-bottom:.35rem}
html body #content .wiz-missing-i{display:flex;align-items:center;gap:.5rem;width:100%;text-align:left;background:none;border:0;padding:.25rem 0;font-size:.84rem;color:var(--sm-dark);cursor:pointer}
html body #content .wiz-missing-i:hover{color:var(--sm-acc-d);text-decoration:underline}
html body #content .wiz-missing-i span{flex:0 0 20px;height:20px;display:inline-flex;align-items:center;justify-content:center;background:var(--sm-dark);color:#fff;font-size:.66rem;font-weight:700}
html body #content .wiz-nav-info{font-size:.9rem;font-weight:300;color:var(--sm-dark)}
html body #content .wiz-nav-keys{font-size:.72rem;color:var(--sm-muted);margin-left:1rem}
html body #content .wiz-nav-keys kbd{background:#fff;color:var(--sm-dark);border:1px solid var(--sm-line);font-size:.66rem;padding:.05rem .3rem}
html body #content .wiz-draft:not([hidden]){display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;background:var(--sm-light);border-left:6px solid var(--sm-acc);padding:.7rem 1rem;margin:0 0 1rem}
html body #content .wiz-draft>.bi{font-size:1.3rem;color:var(--sm-acc)}
html body #content .wiz-draft-t{flex:1;min-width:220px;font-size:.9rem}
html body #content .wiz-draft-t small{color:var(--sm-muted)}

/* Baner „Wyślij rachunek” i inne banery-alerty widoku umowy */
html body #content .cv-banner{background:var(--sm-light);border:0;border-left:6px solid var(--sm-acc);padding:1rem 1.25rem}
html body #content .cv-banner-ico{width:46px;height:46px;background:var(--sm-acc);color:#fff;font-size:1.4rem}
html body #content .cv-banner-title{font-size:1.25rem;font-weight:300;color:var(--sm-dark)}
html body #content .cv-banner--danger{border-left-color:var(--sm-red)}
html body #content .cv-banner--danger .cv-banner-ico{background:var(--sm-red)}
html body #content .cv-banner--warn{border-left-color:var(--sm-orange)}
html body #content .cv-banner--warn .cv-banner-ico{background:var(--sm-orange)}

@media(prefers-reduced-motion:reduce){html body #content *{transition:none!important;animation:none!important}}
</style>
<?php
}
