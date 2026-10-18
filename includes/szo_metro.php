<?php
/**
 * includes/szo_metro.php — warstwa wizualna „Metro UI” samego SZO (powłoka #navbar + #content).
 *
 * Włączana przez includes/header.php jako ostatni blok <head>, gdy aktywna zakładka menu
 * (includes/menu.php) należy do SZO_METRO_GROUPS — na razie Umowy, Wolontariat i Finanse.
 * Kolejne grupy dochodzą przez dopisanie identyfikatora węzła menu i koloru akcentu.
 *
 * Ta sama szata co EZD (ezd_metro.php), CRM (crm_metro.php) i Panel (pv_metro.php):
 * ciemny app-bar #1d1d1d, Segoe UI, lekkie duże tytuły, ZERO zaokrągleń, cieni i gradientów,
 * płaskie szare powierzchnie #f2f2f2, kwadratowe przyciski/pola z ramką 2px, pasek zakładek
 * jak pivot, nagłówki tabel wersalikami z grubą linią, alerty jako pas z lewą krawędzią,
 * żółty fokus. Akcent zależy od grupy (Metro cobalt / magenta / green, wszystkie AA z bielą).
 *
 * Tylko CSS. Selektory zaczynają się od `html body`, bo Tailwind CDN (@apply w header.php
 * i klasy tw-*) wstrzykuje reguły dopiero po załadowaniu — przy równej specyficzności wygrałby.
 * Znaczniki, ID i zaczepy JS bez zmian.
 */

const SZO_METRO_GROUPS = [
    'umowy'       => ['#0050ef', '#003bb0', '#e6eefd'],   // cobalt
    'wolontariat' => ['#c0006a', '#8f004f', '#fbe5f1'],   // magenta (przyciemniona do AA)
    'finanse'     => ['#007a00', '#005700', '#e5f2e5'],   // green
];

/** Identyfikator aktywnej grupy Metro z drzewa menu albo null. */
function szo_metro_group(array $tree): ?string {
    foreach ($tree as $n)
        if (!empty($n['active']) && isset(SZO_METRO_GROUPS[$n['id'] ?? ''])) return $n['id'];
    return null;
}

function szo_metro_assets(string $group): void {
    [$acc, $accD, $acc50] = SZO_METRO_GROUPS[$group] ?? SZO_METRO_GROUPS['umowy'];
?>
<style id="szo-metro">
:root{
  --sm-acc:<?= $acc ?>;--sm-acc-d:<?= $accD ?>;--sm-acc-50:<?= $acc50 ?>;
  --sm-dark:#1d1d1d;--sm-light:#f2f2f2;--sm-light-h:#e6e6e6;--sm-line:#e1e1e1;--sm-muted:#5f5f5f;--sm-field:#8a8a8a;--sm-ink:#111827;
  --sm-red:#a20025;--sm-orange:#c25100;--sm-green:#007a00;--sm-blue:#0050ef;
}
html body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;
  background:#fff!important;background-image:none!important;color:var(--sm-ink);-webkit-font-smoothing:antialiased}
html body *:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px;box-shadow:none!important}

/* ── Zero zaokrągleń, cieni i gradientów. Wyjątki: kontrolki okrągłe z natury, spinnery, kropki. ── */
html body #navbar *:not(.rounded-circle):not(.form-check-input),
html body #content *:not(.form-check-input):not(.spinner-border):not(.spinner-grow):not(.rounded-circle):not(.form-range):not(.tw-rounded-full),
html body .dropdown-menu,html body .dropdown-menu *,html body .modal-content,html body .modal-content *:not(.form-check-input):not(.rounded-circle):not(.spinner-border),
html body .offcanvas,html body .offcanvas *:not(.form-check-input):not(.rounded-circle),html body .toast,html body .popover,html body .tooltip-inner{border-radius:0!important}
html body #navbar{box-shadow:none}
html body #content *:not(.dropdown-menu){box-shadow:none!important}
html body #content .bg-gradient,html body #content [class*="tw-bg-gradient"]{background-image:none!important}

/* ── App-bar (pasek marki) ───────────────────────────────────────────── */
html body .nb-top{background:var(--sm-dark);height:48px}
html body .nb-brand,html body .nb-brand:hover{color:#fff}
html body .nb-brand-icon{background:var(--sm-acc);color:#fff}
html body .nb-brand-name{font-weight:600;letter-spacing:0}
html body .nb-brand-sub{opacity:.65}
html body .nb-search-wrap input{background:rgba(255,255,255,.1);border:2px solid transparent;color:#fff}
html body .nb-search-wrap input::placeholder,html body .nb-search-icon{color:rgba(255,255,255,.65)}
html body .nb-search-wrap input:hover,html body .nb-search-wrap input:focus{background:rgba(255,255,255,.16);border-color:transparent}
html body .nb-search-kbd{border-color:rgba(255,255,255,.35);color:rgba(255,255,255,.7)}
html body .nb-icon-btn{background:rgba(255,255,255,.08);border:0!important;color:#fff}
html body .nb-icon-btn:hover,html body .nb-icon-btn[aria-expanded="true"]{background:var(--sm-acc);color:#fff}
html body .nb-user-chip{background:rgba(255,255,255,.08);border:0;color:#fff}
html body .nb-user-chip:hover,html body .nb-user-chip[aria-expanded="true"]{background:var(--sm-acc);color:#fff}
html body .nb-user-chip .avatar{background:var(--sm-acc)}
html body .nb-toggler{color:#fff}

/* ── Pasek zakładek → pivot ──────────────────────────────────────────── */
html body .nb-nav{height:44px;align-items:stretch;border-bottom:1px solid var(--sm-line);gap:0}
html body .nb-home{height:auto;width:42px;color:var(--sm-muted)}
html body .nb-home:hover{background:var(--sm-light);color:var(--sm-dark)}
html body .nb-home.active{background:none;color:var(--sm-acc)}
html body .nb-tabs{align-items:stretch;gap:0}
html body .nb-tabs>li{display:flex}
html body .nb-tab{height:100%;padding:3px .8rem 0;border-bottom:3px solid transparent;color:var(--sm-muted);font-size:.92rem;font-weight:400}
html body .nb-tab>.bi:first-child{color:inherit}
html body .nb-tab:hover,html body .nb-tab.show{background:var(--sm-light);color:var(--sm-dark)}
html body .nb-tab:hover>.bi:first-child,html body .nb-tab.show>.bi:first-child{color:inherit}
html body .nb-tab.active{background:none;color:var(--sm-dark);font-weight:600;border-bottom-color:var(--sm-acc)}
html body .nb-tab.active>.bi:first-child{color:var(--sm-acc)}
html body .nb-tab:focus-visible{outline-offset:-3px!important}
html body .nb-tab .badge{border-radius:0}
html body .nb-ptitle{border-left-color:var(--sm-line);color:var(--sm-muted);font-weight:300;font-size:.92rem;align-self:center}
html body .nb-ptitle-m,html body #page-title-bar.nb-ptitle-fallback{font-weight:300;color:var(--sm-dark);border-bottom-color:var(--sm-line)}

/* Rozwijane menu — płaskie, zaznaczenie pełnym kolorem akcentu */
html body .dropdown-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)}
html body #navbar .dropdown-menu{margin-top:0!important}
html body .dropdown-item:hover,html body .dropdown-item:focus,html body #navbar .dropdown-item:hover,html body #navbar .dropdown-item:focus{background:var(--sm-acc);color:#fff}
html body .dropdown-item:hover>i,html body .dropdown-item:focus>i,html body #navbar .dropdown-item:hover>i{color:#fff!important}
html body .dropdown-item.active,html body #navbar .dropdown-item.active{background:var(--sm-acc-50);color:var(--sm-dark);font-weight:600;box-shadow:inset 3px 0 0 var(--sm-acc)}
html body #navbar .dropdown-item.active>i{color:var(--sm-acc)}
html body .dropdown-header,html body .nb-section-label{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:var(--sm-muted);font-weight:700}
html body .nb-mega-col+.nb-mega-col{border-left:1px solid var(--sm-line)}

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

@media(prefers-reduced-motion:reduce){html body #content *{transition:none!important;animation:none!important}}
</style>
<?php
}
