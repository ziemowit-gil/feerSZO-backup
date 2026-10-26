<?php
/**
 * crm/includes/crm_metro.php — warstwa wizualna „Metro UI” modułu CRM.
 *
 * Ta sama szata co System Tożsamości, Zadania (tasks_metro.php) i Katalog
 * (dir_metro.php): ciemny app-bar #1d1d1d, Segoe UI, lekkie duże tytuły, ZERO
 * zaokrągleń, cieni i gradientów, płaskie szare grupy #f2f2f2, przyciski i pola
 * kwadratowe z ramką 2px, pasek zakładek jak pivot. Akcent CRM: Metro teal
 * #00807e (Zadania = emerald, Katalog = cobalt). Tokeny --crm-* mają te same
 * NAZWY, zmieniają się tylko wartości.
 *
 * Tylko CSS, ładowany przez header_crm.php jako ostatni blok <head>. Selektory
 * zaczynają się od `html body`, żeby wygrać ze stylami stron CRM osadzonymi w
 * <body> (dashboard.php, inbox.php, contact/view.php…) i z Tailwind CDN tam, gdzie
 * jest (triage.php). Fragmenty doładowywane AJAX-em (?_ajax=1, _crm_table_html())
 * dziedziczą te reguły, bo to zwykły CSS w nagłówku.
 * Znaczniki, ID, zaczepy JS (data-crm-dd, data-quick-open, data-search-input) bez zmian.
 */
?>
<style>
:root{
  --crm-primary:#00807e;--crm-primary-dark:#005e5c;--crm-primary-light:#7fbfbe;--crm-primary-bg:#e5f2f2;
  --crm-accent:#0050ef;--crm-accent-bg:#e6eefd;--crm-navy:#1d1d1d;--crm-navy-light:#0050ef;
  --crm-bg:#ffffff;--crm-card:#ffffff;--crm-border:#e1e1e1;--crm-border-focus:#00807e;
  --crm-text:#1d1d1d;--crm-text-muted:#3f3f3f;--crm-text-light:#5f5f5f;
  --crm-status-prospect:#0050ef;--crm-status-aktywny:#008a00;--crm-status-partner:#6a00ff;
  --crm-status-darczyńca:#c25100;--crm-status-klient:#1d1d1d;--crm-status-nieaktywny:#6d6d6d;
  --crm-radius:0;--crm-radius-lg:0;--crm-shadow:none;--crm-shadow-hover:none;
  --crm-navbar-h:48px;
  --cm-dark:#1d1d1d;--cm-light:#f2f2f2;--cm-light-h:#e6e6e6;--cm-line:#e1e1e1;--cm-muted:#5f5f5f;--cm-field:#8a8a8a;
  --cm-red:#a20025;--cm-orange:#c25100;--cm-green:#008a00;--cm-blue:#0050ef;--cm-violet:#6a00ff;--cm-magenta:#d80073;
}
html body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;
  background:#fff;background-image:none;color:var(--crm-text);-webkit-font-smoothing:antialiased}
html body *:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px!important;border-radius:0!important}

/* Zero zaokrągleń i cieni w module. Wyjątki: kontrolki okrągłe z natury, spinnery, kropki statusów. */
html body .crm-topbar *:not(.rounded-circle),html body .crm-navbar *:not(.rounded-circle),
html body .crm-content *:not(.form-check-input):not(.spinner-border):not(.spinner-grow):not(.rounded-circle):not(.crm-case-dot):not(.form-range),
html body .dropdown-menu,html body .dropdown-menu *,html body .modal-content,html body .modal-content *:not(.form-check-input):not(.rounded-circle):not(.spinner-border),
html body .crm-footer *,html body .skip-link,html body .toast{border-radius:0!important}
html body .crm-content *:not(.dropdown-menu):not(.cs-drop){box-shadow:none!important}

/* ── App-bar ─────────────────────────────────────────────────────────────── */
html body .crm-topbar{background:var(--cm-dark);border-bottom:0;box-shadow:none;color:#fff}
html body .crm-topbar-brand{color:#fff;font-weight:600;letter-spacing:0;border-right:0;padding:0 1.25rem}
html body .crm-topbar-brand:hover{color:#fff}
html body .crm-topbar-brand-icon{background:var(--crm-primary);border-radius:0}
html body .crm-topbar-brand-org{color:rgba(255,255,255,.65)}
html body .crm-topbar-search input{height:34px;background:rgba(255,255,255,.1);border:2px solid transparent;color:#fff}
html body .crm-topbar-search input::placeholder{color:rgba(255,255,255,.6)}
html body .crm-topbar-search input:hover{background:rgba(255,255,255,.16);border-color:transparent}
html body .crm-topbar-search input:focus{background:#fff;color:var(--cm-dark);border-color:var(--crm-primary);box-shadow:none}
html body .crm-topbar-search .bi{color:rgba(255,255,255,.65)}
html body .crm-topbar-search form:focus-within .bi{color:var(--cm-muted)}
html body .crm-topbar-search kbd{background:transparent;border:1px solid rgba(255,255,255,.35);color:rgba(255,255,255,.7)}
html body .crm-topbar-icon{background:rgba(255,255,255,.08);border:0;color:#fff}
html body .crm-topbar-icon:hover,html body .crm-topbar-icon[aria-expanded="true"]{background:var(--crm-primary);color:#fff;border-color:transparent}
html body .crm-topbar-avatar{background:var(--crm-primary);border:0}
html body .crm-topbar-avatar:hover{background:#fff;color:var(--cm-dark)}
html body .crm-macro{background:rgba(255,255,255,.08);border:0;color:#fff;font-weight:600}
html body .crm-macro:hover{background:rgba(255,255,255,.18);color:#fff}
html body .crm-macros>.crm-macro i,html body .crm-macros>.dropdown>.crm-macro i{color:#fff!important}
html body .crm-macro--new{background:var(--crm-primary)}
html body .crm-macro--new:hover{background:#fff;color:var(--cm-dark)}
html body .crm-macro--new:hover i{color:var(--cm-dark)!important}

/* Rozwijane menu — płaskie, bez zaokrągleń, zaznaczenie pełnym kolorem */
html body .dropdown-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)}
html body .dropdown-item{border-radius:0}
html body .dropdown-item:hover,html body .dropdown-item:focus,html body .dropdown-item:active{background:var(--crm-primary);color:#fff}
html body .dropdown-item:hover i,html body .dropdown-item:focus i{color:#fff!important}
html body .dropdown-item.active{background:var(--crm-primary-bg);color:var(--cm-dark);font-weight:600;border-left:3px solid var(--crm-primary)}
html body .dropdown-header{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:var(--cm-muted);font-weight:700}

/* ── Pasek sekcji (pivot) pod app-barem — rejestr $_crm_nav bez zmian ───── */
html body .crm-navbar{height:var(--crm-navbar-h);background:#fff;border-bottom:1px solid var(--cm-line);box-shadow:none;align-items:stretch;padding:0 1rem}
html body .crm-navbar-scroll{align-items:stretch;gap:0}
html body .crm-navbar-scroll>.dropdown{display:flex}
html body .crm-navlink{height:100%;padding:3px .85rem 0;border:0;border-bottom:3px solid transparent;border-radius:0;
  color:var(--cm-muted);font-size:.95rem;font-weight:400;background:none}
html body .crm-navlink>i{color:inherit;font-size:1rem}
html body .crm-navlink:hover,html body .crm-navlink.show{background:var(--cm-light);color:var(--cm-dark)}
html body .crm-navlink:hover>i,html body .crm-navlink.show>i{color:inherit}
html body .crm-navlink.active{background:none;color:var(--cm-dark);font-weight:600;border-bottom-color:var(--crm-primary)}
html body .crm-navlink.active>i{color:var(--crm-primary)}
html body .crm-navlink:focus-visible{outline-offset:-3px!important}
html body .crm-navlink .crm-nav-badge{background:var(--cm-light-h);color:var(--cm-muted);border-radius:0;font-size:.68rem;padding:.12rem .35rem}
html body .crm-navlink .crm-nav-badge.hot{background:var(--cm-red);color:#fff}
html body .crm-navlink.active .crm-nav-badge{background:var(--crm-primary);color:#fff}
html body .crm-navlink.active .crm-nav-badge.hot{background:var(--cm-red)}
html body .crm-navbar-title{border-left:1px solid var(--cm-line);color:var(--cm-muted);font-weight:300;font-size:.95rem}
html body .crm-navbar-title .sep{color:var(--cm-field)}
html body .crm-navbar-actions{border-left:1px solid var(--cm-line);align-self:center}
html body .crm-navbar .dropdown-menu{border:0;margin-top:0!important;padding:.35rem 0}
html body .crm-navbar .dropdown-item{border-radius:0;padding:.5rem 1rem}
html body .crm-navbar .dropdown-item>i{color:var(--cm-muted)}
html body .crm-navbar .dropdown-item:hover,html body .crm-navbar .dropdown-item:focus{background:var(--crm-primary);color:#fff}
html body .crm-navbar .dropdown-item:hover>i,html body .crm-navbar .dropdown-item:focus>i{color:#fff}
html body .crm-navbar .dropdown-item .badge{border-radius:0}
html body .crm-navbar .dropdown-menu.crm-mega{padding:.5rem 0}
html body .crm-mega-col+.crm-mega-col{border-left:1px solid var(--cm-line)}

/* ── Powłoka i treść ─────────────────────────────────────────────────────── */
html body .crm-content{padding:1.75rem 2rem 2.5rem}
@media (max-width:768px){html body .crm-content{padding:1rem .75rem}}
html body .crm-footer{background:#fff;border-top:1px solid var(--cm-line);color:var(--cm-muted)}
html body .crm-footer-logo{color:var(--crm-primary)}
html body .skip-link{background:var(--cm-dark);color:#fff}

/* Tytuły — lekkie, duże */
html body .crm-page-title{font-size:2rem;font-weight:300;letter-spacing:-.02em;line-height:1.1;color:var(--cm-dark)}
html body .crm-page-subtitle{color:var(--cm-muted);font-weight:300;font-size:.92rem}
html body .crm-object-header{background:transparent;border-bottom:1px solid var(--cm-line);padding:0 0 1rem}
html body .crm-object-icon{background:var(--crm-primary);border-radius:0}
html body .crm-object-title{font-size:1.85rem;font-weight:300;letter-spacing:-.02em;line-height:1.1}
html body .crm-object-count{color:var(--cm-muted)}
html body .crm-content h1,html body .crm-content .h1,html body .crm-content h2.h4,html body .crm-content h1.h4,html body .crm-content h1.h3{font-weight:300!important;letter-spacing:-.02em}
html body .crm-section-title,html body .crm-sect-hd{font-size:1.2rem;font-weight:300;letter-spacing:0;text-transform:none;color:var(--cm-dark)}
html body .crm-sect-hd i{color:var(--crm-primary)}

/* Karty i płaskie powierzchnie */
html body .crm-content .card,html body .crm-list-card,html body .cv-panel,html body .crm-panel,html body .crm-empty{border:1px solid var(--cm-line);box-shadow:none;background:#fff}
html body .crm-content .card-header,html body .crm-panel-header{background:var(--cm-light);border-bottom:0;font-weight:600}
html body .crm-content .card-footer{background:var(--cm-light);border-top:0}
html body .crm-panel-title{font-size:.95rem;font-weight:600;color:var(--cm-dark)}
html body .crm-filter-bar,html body .crm-adv-panel,html body .crm-stats-bar{background:var(--cm-light);border:0}
html body .crm-empty{background:var(--cm-light);border:0}
html body .cv-panel--danger{border-left:6px solid var(--cm-red)}
html body .cv-shead{border-bottom:1px solid var(--cm-line)}
html body .cv-shead__title{font-size:1.15rem;font-weight:300;letter-spacing:-.01em}
html body .cv-shead__title.danger{color:var(--cm-red)}
html body .cv-shead__icon{color:var(--crm-primary)}
html body .cv-count{background:var(--cm-dark);color:#fff;border-radius:0}
html body .cv-facts{background:var(--cm-light);border-top:0}
html body .cv-fact{border-right:1px solid #fff}
html body .cv-fact__v{font-size:1.4rem;font-weight:300}

/* Przyciski — kwadratowe, ramka 2px, bez cieni */
html body .crm-content .btn,html body .crm-navbar .btn,html body .modal .btn{font-weight:600;box-shadow:none;border-width:2px}
html body .crm-content .btn-link,html body .modal .btn-link,html body .btn-crm-ghost{border-width:0}
html body .btn-crm-primary,html body .crm-content .btn-primary,html body .modal .btn-primary{
  --bs-btn-bg:var(--crm-primary);--bs-btn-border-color:var(--crm-primary);--bs-btn-color:#fff;
  --bs-btn-hover-bg:var(--cm-dark);--bs-btn-hover-border-color:var(--cm-dark);--bs-btn-hover-color:#fff;
  --bs-btn-active-bg:var(--cm-dark);--bs-btn-active-border-color:var(--cm-dark);
  background:var(--crm-primary);border:2px solid var(--crm-primary);color:#fff;border-radius:0;box-shadow:none}
html body .btn-crm-primary:hover,html body .btn-crm-primary:focus-visible{background:var(--cm-dark);border-color:var(--cm-dark);color:#fff;box-shadow:none}
html body .btn-crm-outline,html body .crm-content .btn-outline-primary,html body .modal .btn-outline-primary{
  --bs-btn-color:var(--crm-primary-dark);--bs-btn-border-color:var(--crm-primary);--bs-btn-hover-bg:var(--crm-primary);--bs-btn-hover-border-color:var(--crm-primary);--bs-btn-hover-color:#fff;
  border:2px solid var(--crm-primary);color:var(--crm-primary-dark);background:transparent;border-radius:0}
html body .btn-crm-outline:hover{background:var(--crm-primary);color:#fff}
html body .crm-content .btn-outline-secondary,html body .crm-navbar .btn-outline-secondary,html body .modal .btn-outline-secondary{
  --bs-btn-color:var(--cm-dark);--bs-btn-border-color:var(--cm-dark);--bs-btn-hover-bg:var(--cm-dark);--bs-btn-hover-border-color:var(--cm-dark);--bs-btn-hover-color:#fff}
html body .crm-content .btn-success,html body .modal .btn-success{--bs-btn-bg:var(--cm-green);--bs-btn-border-color:var(--cm-green);--bs-btn-hover-bg:var(--cm-dark);--bs-btn-hover-border-color:var(--cm-dark)}
html body .crm-content .btn-danger,html body .modal .btn-danger{--bs-btn-bg:var(--cm-red);--bs-btn-border-color:var(--cm-red);--bs-btn-hover-bg:var(--cm-dark);--bs-btn-hover-border-color:var(--cm-dark)}
html body .crm-content .btn-outline-danger,html body .modal .btn-outline-danger{--bs-btn-color:var(--cm-red);--bs-btn-border-color:var(--cm-red);--bs-btn-hover-bg:var(--cm-red);--bs-btn-hover-border-color:var(--cm-red)}
html body .crm-content .btn-light{--bs-btn-bg:var(--cm-light);--bs-btn-border-color:var(--cm-light);--bs-btn-hover-bg:var(--cm-light-h);--bs-btn-hover-border-color:var(--cm-light-h)}
html body .btn-crm-ghost{color:var(--crm-primary-dark);font-weight:600}
html body .btn-crm-ghost:hover{color:var(--cm-dark);text-decoration:underline}

/* Pola — kwadratowe, ramka 2px, fokus w kolorze akcentu */
html body .crm-content .form-control,html body .crm-content .form-select,html body .crm-content .input-group-text,
html body .modal .form-control,html body .modal .form-select,html body .crm-filter-bar .form-control,html body .crm-filter-bar .form-select{
  border:2px solid var(--cm-field);border-radius:0;box-shadow:none}
html body .crm-content .form-control:focus,html body .crm-content .form-select:focus,html body .modal .form-control:focus,html body .modal .form-select:focus,
html body .crm-adv-panel input:focus,html body .crm-adv-panel select:focus{border-color:var(--crm-primary);box-shadow:none}
html body .crm-content .input-group-text{background:var(--cm-light)}
html body .form-check-input:checked{background-color:var(--crm-primary);border-color:var(--crm-primary)}
html body .form-check-input:focus{box-shadow:none;border-color:var(--crm-primary)}
html body .ts-wrapper .ts-control,html body .ql-toolbar.ql-snow,html body .ql-container.ql-snow{border-radius:0!important}
html body .crm-adv-field>span{color:var(--cm-muted);letter-spacing:.1em;font-size:.68rem}

/* Tabele — nagłówki wersalikami z grubą dolną linią */
html body .crm-table thead tr,html body .crm-content .table thead tr{background:#fff;border-bottom:2px solid var(--cm-dark)}
html body .crm-table th,html body .crm-content .table thead th{background:#fff;text-transform:uppercase;font-size:.68rem;font-weight:700;letter-spacing:.1em;color:var(--cm-muted);border-bottom:2px solid var(--cm-dark)}
html body .crm-content .table-light,html body .crm-content .table-light>*>*{--bs-table-bg:#fff;--bs-table-color:var(--cm-muted)}
html body .crm-table th:hover,html body .crm-table th.sorted{color:var(--cm-dark)}
html body .crm-table th.sorted{color:var(--crm-primary-dark)}
html body .crm-table tbody tr{border-bottom:1px solid var(--cm-line)}
html body .crm-table tbody tr:hover,html body .crm-content .table-hover>tbody>tr:hover>*{background:var(--cm-light);--bs-table-hover-bg:var(--cm-light)}
html body .crm-content .table{--bs-table-border-color:var(--cm-line)}
html body .crm-name-link{color:var(--cm-dark);font-weight:600}
html body .crm-name-link:hover{color:var(--crm-primary-dark)}
html body .crm-name-sub{color:var(--cm-muted)}
html body .crm-avatar{background:var(--crm-primary);border-radius:0}
html body .crm-avatar.org{background:var(--cm-dark)}
html body .crm-content .list-group{--bs-list-group-border-color:var(--cm-line)}
html body .crm-content .list-group-item.active{background:var(--crm-primary);border-color:var(--crm-primary)}
html body .crm-content .pagination{--bs-pagination-active-bg:var(--crm-primary);--bs-pagination-active-border-color:var(--crm-primary);--bs-pagination-color:var(--cm-dark)}

/* Znaczniki statusów — pełny kolor, biały tekst, wersaliki */
html body .crm-badge{border-radius:0;color:#fff!important;font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:.66rem;padding:.25rem .5rem}
html body .crm-badge::before{display:none}
html body .crm-badge-prospect{background:var(--crm-status-prospect)}
html body .crm-badge-aktywny{background:var(--crm-status-aktywny)}
html body .crm-badge-partner{background:var(--crm-status-partner)}
html body .crm-badge-darczyńca{background:var(--crm-status-darczyńca)}
html body .crm-badge-klient{background:var(--crm-status-klient)}
html body .crm-badge-nieaktywny{background:var(--crm-status-nieaktywny)}
html body .crm-content .badge,html body .modal .badge{border-radius:0;font-weight:700;letter-spacing:.03em}
html body .crm-content .rounded-pill{border-radius:0!important}

/* Chipy filtrów i danych */
html body .crm-chip{border:2px solid var(--cm-field);color:var(--cm-dark);background:#fff;font-weight:600}
html body .crm-chip:hover{border-color:var(--crm-primary);color:var(--crm-primary-dark);background:#fff}
html body .crm-chip.active{border-color:var(--crm-primary);background:var(--crm-primary);color:#fff}
html body .cv-chip{border:1px solid var(--cm-line);background:var(--cm-light);color:var(--cm-dark)}
html body a.cv-chip:hover{border-color:var(--crm-primary);background:var(--crm-primary);color:#fff}
html body a.cv-chip:hover i{color:#fff}
html body .crm-tag{border-radius:0}
/* Długie tagi (np. zgoda:… z formularzy) zawijają się zamiast wychodzić poza kartę */
html body .tag-cloud-item,html body .crm-content .crm-tag{max-width:100%;min-width:0;overflow-wrap:anywhere;word-break:break-word;white-space:normal}
html body .tag-cloud-item{border-radius:0;border:0;background:var(--crm-primary-bg);color:var(--crm-primary-dark);line-height:1.3}
html body .tag-cloud-item:hover,html body .tag-cloud-item.selected{background:var(--crm-primary);color:#fff}

/* Zakładki → pivot (karta kontaktu, sprawy, ustawienia) */
html body .cv-tabs,html body .crm-content .nav-tabs{border-bottom:1px solid var(--cm-line);gap:1.25rem}
html body .cv-tabs .nav-link,html body .crm-content .nav-tabs .nav-link{border:0;border-bottom:3px solid transparent;margin-bottom:-1px;background:none;
  color:var(--cm-muted);font-weight:300;font-size:1.05rem;padding:.4rem 0 .55rem}
html body .cv-tabs .nav-link:hover,html body .crm-content .nav-tabs .nav-link:hover{color:var(--cm-dark);background:none}
html body .cv-tabs .nav-link.active,html body .crm-content .nav-tabs .nav-link.active{color:var(--cm-dark);font-weight:600;background:none;border-bottom-color:var(--crm-primary)}
html body .cv-tabs .nav-link.active i{color:var(--crm-primary)}
html body .cv-tabs.cv-tabs--sticky{background:#fff}
html body .crm-content .nav-pills .nav-link.active{background:var(--crm-primary)}

/* Alerty — pas z grubą lewą krawędzią */
html body .crm-content .alert,html body .modal .alert{background:var(--cm-light);color:var(--cm-dark);border:0;border-left:6px solid var(--crm-primary)}
html body .crm-content .alert-success,html body .modal .alert-success{border-left-color:var(--cm-green)}
html body .crm-content .alert-danger,html body .modal .alert-danger{border-left-color:var(--cm-red)}
html body .crm-content .alert-warning,html body .modal .alert-warning{border-left-color:var(--cm-orange)}
html body .crm-content .alert-info,html body .modal .alert-info{border-left-color:var(--cm-blue)}
html body .crm-content .alert-light,html body .crm-content .alert-secondary{border-left-color:var(--cm-field)}

/* Okna modalne */
html body .modal-content{border:0;box-shadow:0 20px 50px -20px rgba(0,0,0,.45)}
html body .modal-header,html body .modal-footer{border-color:var(--cm-line)}
html body .modal-title{font-weight:300!important;font-size:1.35rem}

/* ── Pulpit: kafle „Wymaga reakcji” jako żywe kafle Metro ───────────────── */
html body .crm-sect-hd+.row{--bs-gutter-x:.5rem;--bs-gutter-y:.5rem}
html body .crm-content .crm-tile{--k:var(--cm-dark);display:grid;grid-template-columns:auto auto minmax(0,1fr);align-items:center;align-content:center;column-gap:.5rem;min-height:46px;padding:.35rem .6rem;
  background:var(--k);border:0;color:#fff;transition:filter .12s;opacity:1}
html body .crm-sect-hd+.row>:nth-child(1) .crm-tile{--k:var(--cm-red)}
html body .crm-sect-hd+.row>:nth-child(2) .crm-tile{--k:var(--cm-blue)}
html body .crm-sect-hd+.row>:nth-child(3) .crm-tile{--k:var(--cm-magenta)}
html body .crm-sect-hd+.row>:nth-child(4) .crm-tile{--k:var(--cm-violet)}
html body .crm-sect-hd+.row>:nth-child(5) .crm-tile{--k:var(--cm-orange)}
html body .crm-sect-hd+.row>:nth-child(6) .crm-tile{--k:var(--crm-primary)}
html body .crm-sect-hd+.row>* .crm-tile.crm-tile--zero{--k:#6d6d6d}
html body .crm-content .crm-tile:hover,html body .crm-content .crm-tile:focus-visible{color:#fff;filter:brightness(1.12);outline:3px solid var(--cm-dark);outline-offset:2px}
html body .crm-content .crm-tile-ico{grid-row:1/3;width:auto;height:auto;margin:0;background:none!important;color:#fff!important;font-size:.9rem;justify-content:flex-start;opacity:.9}
html body .crm-content .crm-tile-val{grid-row:1/3;margin:0;font-size:1.3rem;font-weight:300;line-height:1;color:#fff}
html body .crm-content .crm-tile-lbl{grid-column:3;grid-row:1;font-size:.7rem;font-weight:600;color:rgba(255,255,255,.92);margin:0;line-height:1.15;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html body .crm-content .crm-tile-note{grid-column:3;grid-row:2;color:rgba(255,255,255,.85)!important;font-size:.64rem!important;margin:0!important;line-height:1.15;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html body .crm-quick-action:hover{background:var(--cm-light);border-color:transparent}
html body .crm-count-chip,html body .crm-hyg-n{border-radius:0}
html body .crm-count-chip{background:var(--cm-dark);color:#fff}
html body .crm-hyg-n{background:var(--cm-orange);color:#fff}
html body .crm-hyg-n--zero{background:var(--cm-light-h);color:var(--cm-muted)}
html body .crm-contact-row,html body .crm-case-row,html body .crm-hyg-row,html body .crm-comm-row{border-bottom-color:var(--cm-line)}
html body .crm-contact-row:hover,html body .crm-case-row:hover,html body .crm-hyg-row:hover{background:var(--cm-light)}
html body .crm-case-num{color:var(--crm-primary-dark)}
html body .crm-stats-up{color:var(--cm-green)}

/* Wyszukiwarka pulpitu */
html body .cs-input{height:48px;border:2px solid var(--cm-field);border-radius:0;font-size:1rem}
html body .cs-input:focus{border-color:var(--crm-primary);box-shadow:none}
html body .cs-kbd{border-radius:0;background:var(--cm-light);border-color:var(--cm-line)}
html body .cs-drop{border:0;border-radius:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35);padding:.35rem 0}
html body .cs-item{border-radius:0}
html body .cs-item:hover,html body .cs-item.is-on{background:var(--crm-primary);color:#fff}
html body .cs-item:hover .cs-s,html body .cs-item.is-on .cs-s{color:rgba(255,255,255,.85)}
html body .cs-grp{color:var(--cm-muted);letter-spacing:.14em}
html body .cs-item .cs-flag{border-radius:0;background:var(--cm-red);color:#fff}

/* ── Okno wysyłki wiadomości (#crmComposeModal + treść z compose_modal.php) ──
   Treść przychodzi fetch-em, więc tylko zwykły CSS z nagłówka; style inline
   z fragmentu (tła odbiorcy, przycisk AI) wymagają !important. */
html body #crmComposeModal .modal-dialog{max-width:min(1000px,96vw)}
html body #crmComposeModal.cm-full .modal-dialog{max-width:98vw}
html body #crmComposeModal .ql-editor{min-height:300px}
html body #crmComposeModal.cm-full .ql-editor{min-height:48vh}
html body #crmComposeModal .cm-wide-btn{background:transparent;border:0;color:#fff;opacity:.85;font-size:1.05rem;padding:.25rem .5rem;margin-left:auto;margin-right:.35rem;cursor:pointer}
html body #crmComposeModal .cm-wide-btn:hover{opacity:1}
html body #crmComposeModal .cm-wide-btn:focus-visible{outline:3px solid #ff9800;outline-offset:2px}
html body #crmComposeModal .modal-header,html body #cm-ai-modal .modal-header,html body #cattOdModal .modal-header{
  background:var(--cm-dark);color:#fff;border-bottom:0;padding:.85rem 1.25rem!important}
html body #crmComposeModal .modal-title,html body #cm-ai-modal .modal-title,html body #cattOdModal .modal-title{font-weight:300!important;font-size:1.4rem;color:#fff}
html body #crmComposeModal .modal-title i,html body #cm-ai-modal .modal-title i,html body #cattOdModal .modal-title i{color:#fff!important}
html body #crmComposeModal .modal-header .btn-close,html body #cm-ai-modal .btn-close,html body #cattOdModal .btn-close{filter:invert(1) grayscale(1) brightness(2);opacity:.85}
html body #crmComposeModal .modal-body{padding:1.25rem 1.5rem}
html body #crmComposeModal .modal-footer{background:var(--cm-light);border-top:0;padding:.75rem 1.5rem!important}
html body #crmComposeModal .modal-footer .btn{min-width:7rem;font-weight:600}
html body #cm-root .form-label{text-transform:uppercase;font-size:.66rem!important;letter-spacing:.12em;color:var(--cm-muted);font-weight:700!important;margin-bottom:.3rem}
html body #cm-root .form-control,html body #cm-root .form-select,html body #cm-ai-modal .form-control,html body #cm-ai-modal .form-select{
  border:2px solid var(--cm-field);border-radius:0;box-shadow:none;font-size:.9rem;min-height:36px}
html body #cm-root .form-control:focus,html body #cm-root .form-select:focus,html body #cm-ai-modal .form-control:focus,html body #cm-ai-modal .form-select:focus{border-color:var(--crm-primary);box-shadow:none}
html body #cm-subject{font-size:1.05rem!important;min-height:42px}
/* Odbiorca — płaski pas z krawędzią w kolorze akcentu */
html body #cm-root>.mb-3:first-child>.d-flex,html body #cm-to-chosen{background:var(--cm-light)!important;border:0!important;border-left:6px solid var(--crm-primary)!important;border-radius:0!important;padding:.65rem .9rem!important}
html body #cm-root .crm-avatar{border-radius:0;background:var(--crm-primary)}
html body #cm-to-list{border-radius:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)!important}
html body #cm-to-list .list-group-item{border-radius:0}
html body #cm-to-list .list-group-item-action:hover,html body #cm-to-list .list-group-item-action:focus,html body #cm-to-list .list-group-item.active{background:var(--crm-primary);color:#fff}
/* Zmienne i AI */
html body #cm-root .cm-var-btn{border:0;border-radius:0;background:var(--cm-light);color:var(--cm-dark);padding:.2rem .45rem;font-size:.72rem}
html body #cm-root .cm-var-btn:hover{background:var(--crm-primary);color:#fff}
html body #cm-root [data-bs-target="#cm-ai-modal"]{background:var(--cm-violet)!important;border:0!important;color:#fff!important;border-radius:0!important;font-weight:600}
html body #cm-root [data-bs-target="#cm-ai-modal"]:hover{background:var(--cm-dark)!important}
/* Przełącznik edytora — mini pivot */
html body #cm-mode-bar{gap:1rem}
html body #cm-mode-bar .btn{border:0!important;border-bottom:3px solid transparent!important;background:none!important;color:var(--cm-muted);font-weight:400;padding:.2rem 0 .3rem}
html body #cm-mode-bar .btn.active{color:var(--cm-dark);font-weight:600;border-bottom-color:var(--crm-primary)!important}
/* Edytor Quill — kwadratowy, ramka 2px, płaski pasek narzędzi */
html body #cm-quill-wrapper .ql-toolbar.ql-snow{border:2px solid var(--cm-field);border-bottom:0;border-radius:0;background:var(--cm-light)}
html body #cm-quill-wrapper .ql-container.ql-snow{border:2px solid var(--cm-field);border-radius:0;font-family:inherit;font-size:.95rem}
html body #cm-quill-wrapper:focus-within .ql-toolbar.ql-snow,html body #cm-quill-wrapper:focus-within .ql-container.ql-snow{border-color:var(--crm-primary)}
html body #cm-quill-wrapper .ql-editor{min-height:240px}
html body #cm-root #cm-plain{min-height:240px}
/* Załączniki */
html body #cm-root .catt{background:var(--cm-light);border:0;border-radius:0}
html body #cm-root .catt-title{text-transform:uppercase;font-size:.66rem;letter-spacing:.12em;color:var(--cm-muted);font-weight:700}
html body #cm-root .catt-count,html body #cm-root .catt-src{border-radius:0;border:0;background:var(--cm-dark);color:#fff}
html body #cm-root .catt-btn{border:2px solid var(--cm-dark);border-radius:0;background:#fff;color:var(--cm-dark);font-weight:600}
html body #cm-root .catt-btn:hover{background:var(--cm-dark);color:#fff}
html body #cm-root .catt-btn--ms{border-color:var(--cm-blue);color:var(--cm-blue)}
html body #cm-root .catt-btn--ms:hover{background:var(--cm-blue);color:#fff}
html body #cm-root .catt-drop{border:2px dashed var(--cm-field);border-radius:0;background:#fff}
html body #cm-root .catt-chip{border-radius:0;border:0;background:#fff}
html body #cm-root .catt-x{border-radius:0}
/* Status kanału i błędy */
html body #cm-mail-info .badge{border:0!important;border-radius:0;color:#fff!important;font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;padding:.3rem .5rem}
html body #cm-mail-info .bg-success-subtle{background:var(--cm-green)!important}
html body #cm-mail-info .bg-info-subtle{background:var(--cm-blue)!important}
html body #cm-mail-info .bg-warning-subtle{background:var(--cm-orange)!important}
html body #cm-error,html body #cm-ai-error{background:var(--cm-light);color:var(--cm-dark);border:0;border-left:6px solid var(--cm-red);border-radius:0}
html body #cm-ai-preview{border:0!important;border-left:6px solid var(--cm-violet)!important;border-radius:0!important;background:var(--cm-light)!important}
html body #cm-ai-modal .form-label{text-transform:uppercase;font-size:.66rem!important;letter-spacing:.12em;color:var(--cm-muted)}
html body #cm-ai-modal .modal-footer{background:var(--cm-light);border-top:0}

@media(prefers-reduced-motion:reduce){html body .crm-content *{transition:none!important;animation:none!important}}
</style>
