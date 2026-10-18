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

/* ── Pasek sekcji (pivot) — zastępuje dawne menu boczne ─────────────────── */
html body .tsk-pivot{background:#fff;border-bottom:1px solid var(--tm-line);color:var(--tsk-text)}
html body .tsk-pivot-inner{display:flex;align-items:stretch;gap:.5rem}
html body .tsk-pivot-list{display:flex;align-items:stretch;gap:0;list-style:none;margin:0;padding:0;flex:1 1 auto;min-width:0;
  overflow-x:auto;scrollbar-width:none}
html body .tsk-pivot-list::-webkit-scrollbar{display:none}
html body .tsk-pivot-list>li{flex:0 0 auto;display:flex}
html body .tsk-pivot-link{display:inline-flex;align-items:center;gap:.45rem;padding:.7rem .85rem .6rem;border:0;border-bottom:3px solid transparent;
  background:none;color:var(--tm-muted);font-size:.92rem;font-weight:400;text-decoration:none;white-space:nowrap;cursor:pointer;line-height:1.2}
html body .tsk-pivot-link i{font-size:1rem}
html body .tsk-pivot-link:hover{color:var(--tsk-text);background:var(--tm-light)}
html body .tsk-pivot-link.active{color:var(--tsk-text);font-weight:600;border-bottom-color:var(--tsk-green)}
html body .tsk-pivot-link.active i{color:var(--tsk-green)}
html body .tsk-pivot-link:focus-visible{outline-offset:-3px!important}
html body .tsk-pivot-chev{font-size:.62rem!important;opacity:.6}
html body .tsk-pivot-more{flex:0 0 auto;display:flex;border-left:1px solid var(--tm-line)}
html body .tsk-pivot-cnt{display:inline-flex;align-items:center;justify-content:center;min-width:1.25rem;height:1.25rem;padding:0 .3rem;
  background:var(--tsk-green);color:#fff;font-size:.68rem;font-weight:700;line-height:1}
html body .tsk-pivot-cnt.is-alert{background:var(--tm-red)}
html body .tsk-pivot-cnt.is-warn{background:var(--tm-orange)}
html body .tsk-pivot-cnt.is-off{background:var(--tm-light-h);color:var(--tm-muted);font-weight:600}
html body .tsk-pivot-menu{min-width:260px;padding:.35rem 0;font-size:.88rem;border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)}
html body .tsk-pivot-menu .dropdown-header{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:var(--tm-muted);font-weight:700}
html body .tsk-pivot-menu .dropdown-item{display:flex;align-items:center;gap:.6rem;padding:.5rem 1rem}
html body .tsk-pivot-menu .dropdown-item i{width:1.1rem;text-align:center;color:var(--tm-muted)}
html body .tsk-pivot-menu .dropdown-item .tsk-pivot-cnt{margin-left:auto}
html body .tsk-pivot-menu .dropdown-item:hover,html body .tsk-pivot-menu .dropdown-item:focus{background:var(--tsk-green);color:#fff}
html body .tsk-pivot-menu .dropdown-item:hover i,html body .tsk-pivot-menu .dropdown-item:focus i{color:#fff}
@media(max-width:575.98px){html body .tsk-pivot-link span:not(.tsk-pivot-cnt){display:none}html body .tsk-pivot-link{padding:.7rem .75rem .6rem}}

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

/* ── Dashboard: kafle podsumowania jak żywe kafle Metro ──────────────────── */
html body .tsk-dash .kpi-tile{display:flex;flex-direction:column;justify-content:space-between;min-height:128px;padding:.9rem 1rem .8rem;
  background:var(--k,#1d1d1d);border:0;color:#fff;text-decoration:none;transition:filter .12s,outline-color .12s;transform:none;box-shadow:none}
html body .tsk-dash .kpi-tile:hover,html body .tsk-dash .kpi-tile:focus-visible{transform:none;box-shadow:none;filter:brightness(1.12);color:#fff;outline:3px solid var(--tsk-text);outline-offset:2px}
html body .tsk-dash .kpi-tile{--k:var(--tm-dark)}
html body .tsk-dash .kpi-tile.is-green{--k:#008a00}
html body .tsk-dash .kpi-tile.is-blue{--k:#0050ef}
html body .tsk-dash .kpi-tile.is-slate{--k:#647687}
html body .tsk-dash .kpi-tile.is-violet{--k:#6a00ff}
html body .tsk-dash .kpi-tile.is-red{--k:var(--tm-red)}
html body .tsk-dash .kpi-tile .kpi-tile__ico{width:auto;height:auto;margin:0;background:none!important;font-size:1.5rem;justify-content:flex-start;color:#fff}
html body .tsk-dash .kpi-tile .kpi-tile__val{font-size:2.4rem;font-weight:300;line-height:1;color:#fff!important;margin-top:auto}
html body .tsk-dash .kpi-tile .kpi-tile__lbl{font-size:.8rem;font-weight:600;color:rgba(255,255,255,.9);margin-top:.3rem}
html body .tsk-dash .row.g-3{--bs-gutter-x:.5rem;--bs-gutter-y:.5rem}

/* ── Lista: kolumna „Zadanie” bierze resztę szerokości ───────────────────── */
html body #tsk-main .tk-table th.th-title{min-width:220px}
html body #tsk-main .tk-table th.th-check,html body #tsk-main .tk-table td.td-check{width:44px}
html body #tsk-main .tk-table .tk-title{font-weight:600;word-break:normal;overflow-wrap:anywhere}
html body #tsk-main .tk-table tbody tr.selected{background:var(--tsk-green-bg)!important}

/* ── Panel szczegółów zadania (offcanvas) — wspólny dla wszystkich stron ── */
/* Szczegóły zadania jako OKNO NA ŚRODKU EKRANU (było: wąski panel z prawej, ucinał pola).
   Element i JS bez zmian — to dalej Bootstrap Offcanvas, więc zostaje cała dostępność:
   role=dialog + aria-modal + aria-labelledby, pułapka fokusu, Esc zamyka, fokus wraca
   na element wywołujący, tło blokuje stronę. Zmienia się tylko geometria i animacja. */
html body #taskOffcanvas.offcanvas{top:50%;left:50%;right:auto;bottom:auto;width:min(1040px,94vw);max-width:94vw;
  height:auto;max-height:90vh;border:0;transform:translate(-50%,-47%);opacity:0;
  transition:transform .18s ease-out,opacity .18s ease-out,visibility .18s;box-shadow:0 24px 60px -20px rgba(0,0,0,.55)}
html body #taskOffcanvas.offcanvas.showing,html body #taskOffcanvas.offcanvas.show:not(.hiding){transform:translate(-50%,-50%);opacity:1}
html body #taskOffcanvas.offcanvas.hiding{transform:translate(-50%,-47%);opacity:0}
html body #taskOffcanvas .offcanvas-body{overflow-y:auto;overscroll-behavior:contain}
@media(min-width:900px){html body #td-root .td-props-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:575.98px){html body #taskOffcanvas.offcanvas{top:0;left:0;width:100vw;max-width:100vw;height:100dvh;max-height:100dvh;transform:translateY(3%)}
  html body #taskOffcanvas.offcanvas.showing,html body #taskOffcanvas.offcanvas.show:not(.hiding){transform:none}}
@media(prefers-reduced-motion:reduce){html body #taskOffcanvas.offcanvas{transition:none}}

/* Tabele nie ucinają kolumn: kontener przewija się w poziomie (było overflow:hidden). */
html body #tsk-main .tk-wrap,html body #tsk-main .card:has(> .table),html body #tsk-main .card-body:has(> .table){overflow-x:auto}
html body #taskOffcanvas .offcanvas-header{background:var(--tm-dark);color:#fff;border-bottom:0!important}
html body #taskOffcanvas .offcanvas-header .text-primary{color:#fff!important}
html body #taskOffcanvas .offcanvas-header .btn-close{filter:invert(1) grayscale(1) brightness(2)}
html body #td-root .td-props-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem 1rem}
html body #td-root .td-props-grid>*,html body #td-root .form-control,html body #td-root .form-select{min-width:0}
html body #td-root .td-label{color:var(--tsk-text);letter-spacing:.12em}
html body #td-root .td-label i{color:var(--tsk-green)}
html body #td-root .td-section{border-bottom-color:var(--tm-line)}
html body #td-root .td-status-row .badge,html body #td-root .td-tag-btn{border-radius:0}
@media(max-width:479.98px){html body #td-root .td-props-grid{grid-template-columns:minmax(0,1fr)}}
@media(prefers-reduced-motion:reduce){html body #tsk-main *{transition:none!important;animation:none!important}}
</style>
<script>
/* Rozwijane menu (np. priorytet w wierszu tabeli) pozycjonowane „fixed”, żeby przewijany
   w poziomie kontener tabeli ich nie ucinał. Domyślna konfiguracja Bootstrap 5.3. */
document.addEventListener('DOMContentLoaded', function () {
  if (window.bootstrap && bootstrap.Dropdown && bootstrap.Dropdown.Default) {
    bootstrap.Dropdown.Default.popperConfig = function (cfg) { return Object.assign({}, cfg, { strategy: 'fixed' }); };
  }
});
</script>
