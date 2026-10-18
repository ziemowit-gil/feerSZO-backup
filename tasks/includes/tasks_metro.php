<?php
/**
 * tasks/includes/tasks_metro.php — warstwa wizualna „Metro UI” modułu Zadania.
 *
 * Ta sama szata co System Tożsamości i Panel współpracownika (pv_metro.php): ciemny
 * app-bar #1d1d1d, Segoe UI, lekkie duże tytuły, ZERO zaokrągleń i cieni, płaskie
 * szare grupy, pełne kolory na znacznikach. Kolor modułu zostaje zielony (Metro
 * emerald) — tokeny --tsk-* mają te same NAZWY, zmieniają się tylko wartości.
 *
 * Tylko CSS, ładowany przez header_tasks.php jako ostatni blok <head>. Selektory
 * zaczynają się od `html body`, bo Tailwind CDN wstrzykuje swoje reguły (@apply)
 * dopiero po załadowaniu — przy równej specyficzności wygrałby on.
 * Znaczniki, ID, zaczepy JS (tasks-header.js, tasks-index.js, detail_js_*) bez zmian.
 */
?>
<style>
:root{
  --tsk-green:#007a00;--tsk-green-dark:#004d00;--tsk-green-mid:#008a00;--tsk-green-bg:#e8f3e8;--tsk-green-light:#cfe6cf;
  --tsk-text:#111827;--tsk-text-sub:#3f3f46;--tsk-border:#e1e1e1;--tsk-bg:#ffffff;
  --tm-dark:#1d1d1d;--tm-light:#f2f2f2;--tm-light-h:#e6e6e6;--tm-line:#e1e1e1;--tm-muted:#5f5f5f;--tm-field:#8a8a8a;
  --tm-red:#a20025;--tm-orange:#c25100;--tm-blue:#0f6fa8;--tm-indigo:#0050ef;--tm-violet:#6a00ff;
}
html body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;background:#fff;-webkit-font-smoothing:antialiased}
html body *:focus-visible{border-radius:0}

/* Zero zaokrągleń w module. Wyjątki: kontrolki okrągłe z natury, spinnery, kropki kolorów obszarów. */
html body #tsk-main *:not(.form-check-input):not(.spinner-border):not(.spinner-grow):not(.rounded-circle):not(.tsk-ws-dot):not(.tsk-row__dot):not(.form-range),
html body .tsk-navbar *:not(.tsk-ws-dot):not(.tsk-ws-switch-dot):not(.rounded-circle),
html body .offcanvas, html body .offcanvas *:not(.form-check-input):not(.rounded-circle):not(.tsk-ws-dot),
html body .modal-content, html body .modal-content *:not(.form-check-input):not(.rounded-circle):not(.spinner-border),
html body .dropdown-menu, html body .dropdown-menu *:not(.rounded-circle):not(.tsk-ws-dot), html body .toast,
html body .skip-link{border-radius:0!important}

/* ── App-bar ─────────────────────────────────────────────────────────────── */
html body .tsk-navbar{background:var(--tm-dark);border-bottom:0;box-shadow:none;color:#fff}
html body .tsk-brand,html body .tsk-brand:hover{color:#fff}
html body .tsk-brand-sub{color:rgba(255,255,255,.65)}
html body .tsk-brand-icon{background:var(--tsk-green)}
html body .tsk-menu-btn,html body .tsk-notif-btn,html body .tsk-ws-switch-btn{background:rgba(255,255,255,.08);border:0;color:#fff}
html body .tsk-menu-btn:hover,html body .tsk-notif-btn:hover,html body .tsk-ws-switch-btn:hover{background:var(--tsk-green);color:#fff;border-color:transparent}
html body .tsk-notif-badge{background:var(--tm-red)}
html body .tsk-avatar{background:var(--tsk-green)}
html body .tsk-navbar .dropdown-menu,html body .tsk-ws-switch-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)}
html body .tsk-ws-switch-item.active{background:var(--tsk-green);color:#fff}

/* ── Menu boczne (offcanvas) ─────────────────────────────────────────────── */
html body .tsk-offcanvas .offcanvas-header{background:var(--tm-dark);color:#fff}
html body .tsk-nav-label{letter-spacing:.14em;color:var(--tm-muted)}
html body .tsk-nav-link{margin:0;border-left:0;padding:.6rem 1.1rem;color:var(--tsk-text)}
html body .tsk-nav-link:hover{background:var(--tm-light);color:var(--tsk-text);border-left:0}
html body .tsk-nav-link:hover i{color:var(--tsk-text)}
html body .tsk-nav-link.active{background:var(--tsk-green);color:#fff;font-weight:600;border-left:0}
html body .tsk-nav-link.active i{color:#fff}
html body .tsk-nav-link.tsk-danger,html body .tsk-nav-link.tsk-danger i{color:var(--tm-red)}
html body .tsk-nav-sep,html body .tsk-sidebar-bottom{border-color:var(--tm-line)}

/* ── Treść ───────────────────────────────────────────────────────────────── */
html body #tsk-main{max-width:1320px}
html body #tsk-main h1,html body #tsk-main .tk-title{font-weight:300;letter-spacing:-.02em;line-height:1.1}
html body #tsk-main .tk-subtitle{color:var(--tm-muted);font-weight:300}
html body #tsk-main h2,html body #tsk-main .h2,html body #tsk-main h3.tk-section{font-weight:300;letter-spacing:-.01em}

/* Karty i płaskie powierzchnie */
html body #tsk-main .card,html body #tsk-main .tk-card,html body #tsk-main .tsk-card,html body #tsk-main .tk-toolbar,
html body #tsk-main .tk-wrap,html body .offcanvas .card{box-shadow:none!important;border:1px solid var(--tm-line)}
html body #tsk-main .card-header,html body #tsk-main .tk-card-hd{background:var(--tm-light);border-bottom:0;font-weight:600}
html body #tsk-main .card-footer{background:var(--tm-light);border-top:0}
html body #tsk-main .tsk-empty{background:var(--tm-light);border:0}

/* Przyciski — kwadratowe, ramka 2px, bez cieni */
html body #tsk-main .btn,html body .modal .btn,html body .offcanvas .btn{font-weight:600;box-shadow:none}
html body #tsk-main .btn-primary,html body .modal .btn-primary,html body .offcanvas .btn-primary,
html body #tsk-main .btn-success,html body .modal .btn-success{--bs-btn-bg:var(--tsk-green);--bs-btn-border-color:var(--tsk-green);--bs-btn-hover-bg:var(--tm-dark);--bs-btn-hover-border-color:var(--tm-dark);--bs-btn-active-bg:var(--tm-dark);--bs-btn-active-border-color:var(--tm-dark)}
html body #tsk-main .btn-outline-secondary,html body .modal .btn-outline-secondary,html body .offcanvas .btn-outline-secondary{--bs-btn-color:var(--tsk-text);--bs-btn-border-color:var(--tsk-text);--bs-btn-hover-bg:var(--tsk-text);--bs-btn-hover-border-color:var(--tsk-text);--bs-btn-hover-color:#fff;border-width:2px}
html body #tsk-main .btn-outline-primary{--bs-btn-color:var(--tsk-green);--bs-btn-border-color:var(--tsk-green);--bs-btn-hover-bg:var(--tsk-green);--bs-btn-hover-border-color:var(--tsk-green);border-width:2px}

/* Pola */
html body #tsk-main .form-control,html body #tsk-main .form-select,html body #tsk-main .tk-select,html body #tsk-main .tk-search,
html body .modal .form-control,html body .modal .form-select,html body .offcanvas .form-control,html body .offcanvas .form-select{border:2px solid var(--tm-field);box-shadow:none}
html body #tsk-main .form-control:focus,html body #tsk-main .form-select:focus,html body .modal .form-control:focus,html body .modal .form-select:focus,
html body .offcanvas .form-control:focus,html body .offcanvas .form-select:focus{border-color:var(--tsk-green);box-shadow:none}
html body .form-check-input:checked{background-color:var(--tsk-green);border-color:var(--tsk-green)}
html body .ts-wrapper .ts-control{border-radius:0!important;border:2px solid var(--tm-field);box-shadow:none}

/* Znaczniki i tagi — płaskie, wersaliki */
html body #tsk-main .badge,html body .offcanvas .badge,html body .modal .badge{font-weight:700;letter-spacing:.03em}
html body #tsk-main .tk-tag,html body #tsk-main .tk-confirm-badge,html body #tsk-main .tk-status{letter-spacing:.03em}

/* Tabele / listy */
html body #tsk-main .table,html body #tsk-main .tk-table{--bs-table-border-color:var(--tm-line)}
html body #tsk-main .tk-table thead th,html body #tsk-main .table thead th{text-transform:uppercase;font-size:.68rem;letter-spacing:.1em;color:var(--tm-muted);border-bottom:2px solid var(--tsk-text);background:#fff}
html body #tsk-main .list-group{--bs-list-group-border-color:var(--tm-line)}

/* Zakładki → pivot */
html body #tsk-main .nav-tabs{border-bottom:1px solid var(--tm-line);gap:1.25rem}
html body #tsk-main .nav-tabs .nav-link{border:0;border-bottom:3px solid transparent;background:none;color:var(--tm-muted);font-weight:300;font-size:1.05rem;padding:.35rem 0 .55rem}
html body #tsk-main .nav-tabs .nav-link.active{color:var(--tsk-text);font-weight:600;border-bottom-color:var(--tsk-green)}
html body #tsk-main .nav-pills .nav-link.active{background:var(--tsk-green)}

/* Banery i alerty — pas z grubą lewą krawędzią */
html body #tsk-setup-banner,html body #tsk-notif-banner,html body #tsk-main .alert{background:var(--tm-light);border:0;border-left:6px solid var(--tsk-green);animation:none}
html body #tsk-setup-banner{border-left-color:var(--tm-orange)}
html body #tsk-main .alert-danger{border-left-color:var(--tm-red)}
html body #tsk-main .alert-warning{border-left-color:var(--tm-orange)}
html body #tsk-main .alert-info{border-left-color:var(--tm-blue)}

/* Okna modalne, offcanvas szczegółów */
html body .modal-content{border:0;box-shadow:0 20px 50px -20px rgba(0,0,0,.45)}
html body .modal-header,html body .modal-footer{border-color:var(--tm-line)}

/* Stopka */
html body .tsk-footer{background:#fff;border-top:1px solid var(--tm-line);color:var(--tm-muted)}
@media(prefers-reduced-motion:reduce){html body #tsk-main *{transition:none!important;animation:none!important}}
</style>
