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

/* ══ Nagłówek — delikatnie nowocześniejsze Metro (2026-11-10): niższy kontrast „pudełek”, jednolite przyciski 36 px
   z podświetleniem zamiast zielonego wypełnienia, kafelek awatara zamiast koła, separator przy przełączniku obszaru;
   pasek sekcji wyższy, z wyraźniejszym stanem aktywnym i spokojnym hover ══ */
html body .tsk-navbar{background:#2b3a4a;border-bottom:1px solid rgba(255,255,255,.08)}   /* grafitowo-niebieski (wybór użytkownika 2026-11-10) */
html body .tsk-navbar>.container-xl{min-height:56px;padding-top:.5rem!important;padding-bottom:.5rem!important;gap:.75rem!important}
html body .tsk-brand{gap:.7rem;font-weight:600;font-size:1rem;letter-spacing:-.005em}
html body .tsk-brand-icon{width:36px;height:36px;font-size:1.05rem;background:var(--tsk-green)}
html body .tsk-brand-sub{opacity:1;color:rgba(255,255,255,.72);font-size:.7rem;font-weight:400;margin-top:.15rem}
html body #tsk-ws-switch-wrap{padding-left:.75rem;border-left:1px solid rgba(255,255,255,.14)}
html body .tsk-ws-switch-btn{min-height:36px;padding:0 .75rem;gap:.5rem;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:#fff;font-size:.88rem;font-weight:600}
html body .tsk-ws-switch-btn:hover,html body .tsk-ws-switch-btn[aria-expanded="true"]{background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.28);color:#fff}
html body .tsk-ws-switch-dot{width:10px;height:10px;border-radius:0!important}
html body .tsk-navbar .btn-outline-secondary{min-height:36px;padding:0 .75rem;background:transparent;border:1px solid rgba(255,255,255,.22);color:rgba(255,255,255,.9);font-weight:600}
html body .tsk-navbar .btn-outline-secondary:hover,html body .tsk-navbar .btn-outline-secondary:focus-visible{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.35);color:#fff}
html body .tsk-notif-btn{width:36px;height:36px;background:transparent;border:1px solid rgba(255,255,255,.18);color:#fff}
html body .tsk-notif-btn:hover,html body .tsk-notif-btn[aria-expanded="true"]{background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.3);color:#fff}
html body .tsk-notif-badge{top:-5px;right:-5px;min-width:18px;height:18px;padding:0 .25rem;font-size:.66rem;line-height:18px;border:2px solid #2b3a4a}
html body .tsk-avatar{width:36px;height:36px;border-radius:0!important;font-size:.8rem;letter-spacing:.02em}
html body .tsk-navbar .fl-trigger{min-height:36px;border-radius:0}
html body .tsk-navbar :focus-visible{outline:2px solid #fff!important;outline-offset:2px!important}
html body .tsk-pivot{box-shadow:0 1px 0 rgba(0,0,0,.02)}
html body .tsk-pivot-link{min-height:48px;padding:.75rem .9rem .62rem;color:#3f3f46;font-size:.9rem;gap:.5rem;transition:background-color .12s,color .12s}
html body .tsk-pivot-link i{font-size:1.02rem;color:var(--tm-muted)}
html body .tsk-pivot-link:hover{background:#f5f5f5;color:var(--tsk-text)}
html body .tsk-pivot-link:hover i{color:var(--tsk-text)}
html body .tsk-pivot-link.active{background:transparent;color:var(--tsk-text);font-weight:600;border-bottom-color:var(--tsk-green)}
html body .tsk-pivot-link.active i{color:var(--tsk-green)}
html body .tsk-pivot-cnt{min-width:1.3rem;height:1.2rem;font-size:.68rem}
html body .tsk-pivot-more{border-left-color:var(--tm-line)}
@media(prefers-reduced-motion:reduce){html body .tsk-pivot-link{transition:none}}

/* ══ Dwa tony grafitu (wybór użytkownika 2026-11-10): pasek sekcji grafitowy, o ton jaśniejszy od górnego paska.
   Nieaktywne sekcje 80% bieli, aktywna biała z jasnozieloną kreską (kontrast z tłem ≥ 3:1), liczniki bez zmian. ══ */
html body .tsk-pivot{background:#34465a;border-bottom:0;box-shadow:0 1px 0 rgba(0,0,0,.18);color:#fff}
html body .tsk-pivot-link{color:rgba(255,255,255,.8)}
html body .tsk-pivot-link i{color:rgba(255,255,255,.7)}
html body .tsk-pivot-link:hover{background:rgba(255,255,255,.08);color:#fff}
html body .tsk-pivot-link:hover i{color:#fff}
html body .tsk-pivot-link.active{color:#fff;border-bottom-color:#6fd27a;background:rgba(255,255,255,.06)}
html body .tsk-pivot-link.active i{color:#6fd27a}
html body .tsk-pivot-link:focus-visible{outline:2px solid #fff!important;outline-offset:-3px!important}
html body .tsk-pivot-more{border-left-color:rgba(255,255,255,.16)}
html body .tsk-pivot-cnt.is-off{background:rgba(255,255,255,.16);color:#fff}
html body .tsk-pivot-chev{opacity:.8}

/* ── Treść ───────────────────────────────────────────────────────────────── */
/* Pełna szerokość okna (jak CRM) — wcześniej pas 1320 px pośrodku; górny pasek, sekcje i treść równo z marginesem bocznym */
html body #tsk-main,html body .tsk-navbar>.container-xl,html body .tsk-pivot>.tsk-pivot-inner{max-width:none;width:100%;
  padding-left:clamp(.75rem,2vw,2rem);padding-right:clamp(.75rem,2vw,2rem)}
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

/* ══ Szczegóły zadania — Metro (fragment detail.php ma własne style; tu wyższa specyficzność) ══ */
html body #td-root{--c-border:#e1e1e1;--c-soft:#f2f2f2;--c-accent:var(--tsk-green);--c-text:#111827;--c-muted:#5f5f5f;
  --c-radius:0;--c-radius-sm:0;--c-shadow-xs:none;--c-shadow-sm:none;--c-shadow-md:none;--c-section:1.1rem 1.35rem;font-size:.88rem}
html body #td-root .td-header{background:#fff;padding:1.2rem 1.35rem 1rem}
html body #td-root .td-title-input,html body #td-root .td-title-static{font-size:1.45rem;font-weight:300;letter-spacing:-.01em;line-height:1.25;padding:.15rem .3rem;border:2px solid transparent!important}
html body #td-root .td-title-input:hover{background:var(--tm-light)}
html body #td-root .td-title-input:focus{outline:0;border-color:var(--tsk-green)!important;background:#fff}
html body #td-root .td-status-row .badge{font-size:.68rem;letter-spacing:.04em;text-transform:uppercase;padding:.3rem .55rem}
html body #td-root .td-status-row .badge.bg-light{background:var(--tm-light)!important;border:0!important;color:var(--tsk-text)!important}
html body #td-root .td-status-row>span[aria-label^="Priorytet"]{width:12px!important;height:12px!important;border-radius:0!important}
html body #td-root .td-action-bar{gap:.4rem;padding-top:.8rem;border-top-color:var(--tm-line)}
html body #td-root .td-ab-btn{border:2px solid var(--tsk-text);background:#fff;color:var(--tsk-text);font-size:.8rem;padding:.35rem .8rem;box-shadow:none;transform:none}
html body #td-root .td-ab-btn:hover,html body #td-root .td-ab-btn:focus-visible{background:var(--tsk-text);border-color:var(--tsk-text);color:#fff;transform:none;box-shadow:none}
html body #td-root .td-ab-primary{background:var(--tsk-green);border-color:var(--tsk-green);color:#fff}
html body #td-root .td-ab-primary:hover,html body #td-root .td-ab-primary:focus-visible{background:var(--tm-dark);border-color:var(--tm-dark)}
html body #td-root .td-ab-confirm{background:var(--tm-violet);border-color:var(--tm-violet);color:#fff}
html body #td-root .td-ab-confirm:hover{background:var(--tm-dark);border-color:var(--tm-dark)}
html body #td-root .td-ab-danger{border-color:var(--tm-red);color:var(--tm-red)}
html body #td-root .td-ab-danger:hover,html body #td-root .td-ab-danger:focus-visible{background:var(--tm-red);border-color:var(--tm-red);color:#fff}
html body #td-root .td-ab-sep{background:var(--tm-line);height:1.6rem}
html body #td-root .td-label{font-size:.66rem;margin-bottom:.65rem}
html body #td-root .form-label{color:var(--tm-muted)!important;font-size:.74rem}
html body #td-root .td-st-row,html body #td-root .td-file-row{background:var(--tm-light);border:0;border-left:3px solid transparent;margin-bottom:2px}
html body #td-root .td-st-row:hover,html body #td-root .td-file-row:hover{background:var(--tm-light-h);border-left-color:var(--tsk-green);box-shadow:none}
html body #td-root .td-progress-track{height:6px;background:var(--tm-light-h)}
html body #td-root .td-progress-fill{background:var(--tsk-green)!important}
html body #td-root .td-comment{background:var(--tm-light);border:0;border-left:3px solid var(--tsk-green);padding:.7rem .9rem;margin-bottom:.4rem}
html body #td-root .td-comment:hover{box-shadow:none}
html body #td-root .td-drop-zone{border:2px dashed var(--tm-field);background:#fff}
html body #td-root .td-drop-zone.is-over{background:var(--tsk-green-bg);border-color:var(--tsk-green);color:var(--tsk-green-dark)}
html body #td-root .td-hi-icon{border-radius:0;color:#fff!important;background:var(--tm-field)!important}
html body #td-root .td-hi-badge,html body #td-root .td-hi-from,html body #td-root .td-hi-to,html body #td-root .td-hi-val,html body #td-root .td-mention{border-radius:0}
html body #td-root .td-history-item{border-bottom-color:var(--tm-line)}
html body #td-root .td-dep-link,html body #td-root .td-file-name{color:var(--tm-blue)}
html body #td-root .ts-wrapper .ts-control{min-height:38px}
html body #td-root .ts-wrapper .item{border-radius:0!important;background:var(--tm-light)!important;border:0!important}
html body .td-preview-bar a,html body .td-preview-bar button,html body .td-preview-body{border-radius:0}

/* ══ Lista zadań — Metro ═════════════════════════════════════════════════ */
html body #tsk-main .tk-wrap{border:0;border-top:2px solid var(--tsk-text)}
html body #tsk-main .tk-table{font-size:.86rem}
html body #tsk-main .tk-table thead th{background:#fff;padding:.7rem .75rem;border-bottom:1px solid var(--tm-line)}
html body #tsk-main .tk-table tbody tr{border-bottom:1px solid var(--tm-line)}
html body #tsk-main .tk-table tbody tr:hover{background:var(--tm-light)}
html body #tsk-main .tk-table td{padding:.75rem}
html body #tsk-main .tk-table tbody tr td:first-child{border-left-width:4px}
html body #tsk-main .tk-table .tk-subtitle{color:var(--tm-muted);display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
html body #tsk-main .tk-status{border:0;padding:.25rem .55rem;font-size:.68rem;letter-spacing:.04em;text-transform:uppercase;cursor:pointer}
html body #tsk-main .tk-status.s-open{background:var(--tsk-green);color:#fff}
html body #tsk-main .tk-status.s-taken{background:var(--tm-indigo);color:#fff}
html body #tsk-main .tk-status.s-done{background:var(--tm-light-h);color:var(--tm-muted)}
html body #tsk-main .tk-status:hover,html body #tsk-main .tk-status:focus-visible{filter:brightness(1.12)}
html body #tsk-main .tk-pri{display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;font-weight:600;color:var(--tsk-text);white-space:nowrap}
html body #tsk-main .tk-pri::before{content:"";width:10px;height:10px;background:var(--p);flex-shrink:0}
html body #tsk-main .pri-btn:hover .tk-pri{text-decoration:underline}
html body #tsk-main .tk-confirm-badge{min-width:1.6rem;height:1.3rem}
html body #tsk-main .tk-tag{padding:.12rem .45rem;letter-spacing:.02em}
html body #tsk-main .tk-prog-track{height:6px;background:var(--tm-light-h)}
html body #tsk-main .tk-av{width:28px;height:28px;font-size:.62rem;border:2px solid #fff}
html body #tsk-main .tk-av-stack .tk-av+.tk-av{margin-left:-4px}
/* Przyciski w wierszach: jedna wysokość 32px; „Otwórz” = kwadrat z ikoną, Weź = zielony, Oddaj = szary płaski */
html body #tsk-main .tk-actions{gap:4px;flex-wrap:nowrap}
html body #tsk-main .btn-open,html body #tsk-main .btn-claim,html body #tsk-main .btn-unclaim{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;
  height:32px;padding:0 .75rem;border:0;font-size:.78rem;font-weight:600;white-space:nowrap;box-shadow:none;transition:background .1s,color .1s}
html body #tsk-main .btn-open i,html body #tsk-main .btn-claim i,html body #tsk-main .btn-unclaim i{margin:0!important;font-size:.9rem}
html body #tsk-main .btn-open{width:32px;padding:0;background:var(--tm-light-h);color:var(--tsk-text)}
html body #tsk-main .btn-open:hover,html body #tsk-main .btn-open:focus-visible{background:var(--tsk-text);color:#fff}
html body #tsk-main .btn-claim{background:var(--tsk-green);color:#fff}
html body #tsk-main .btn-claim:hover,html body #tsk-main .btn-claim:focus-visible{background:var(--tm-dark);color:#fff}
html body #tsk-main .btn-unclaim{background:var(--tm-light-h);color:var(--tsk-text)}
html body #tsk-main .btn-unclaim:hover,html body #tsk-main .btn-unclaim:focus-visible{background:var(--tm-red);color:#fff}
html body #tsk-main .tk-table th:last-child{text-align:right!important;padding-right:1rem}
html body #tsk-main .tk-table td:last-child{padding-right:1rem}

/* Pasek filtrów: szary pas, białe płaskie kontrolki 34px, wybrany filtr ciemny */
html body #tsk-main .tk-toolbar{background:var(--tm-light);border:0!important;padding:.5rem;gap:.4rem}
html body #tsk-main .tk-toolbar .tk-sep{background:#cfcfcf;height:1.5rem}
html body #tsk-main .tk-search{height:34px;border:2px solid transparent!important;background:#fff}
html body #tsk-main .tk-search:focus{outline:0;border-color:var(--tsk-green)!important}
html body #tsk-main .tk-pill,html body #tsk-main .tk-filters-btn,html body #tsk-main .tk-toolbar .btn{display:inline-flex;align-items:center;gap:.4rem;
  height:34px;padding:0 .75rem;border:0!important;background:#fff;color:var(--tsk-text);font-size:.8rem;font-weight:600;box-shadow:none;line-height:1}
html body #tsk-main .tk-pill:hover,html body #tsk-main .tk-filters-btn:hover,html body #tsk-main .tk-toolbar .btn:hover,html body #tsk-main .tk-toolbar .btn:focus-visible{background:var(--tsk-text);color:#fff}
html body #tsk-main .tk-pill.active,html body #tsk-main .tk-pill[data-s].active{background:var(--tsk-text);color:#fff}
html body #tsk-main .tk-pill .pill-n{opacity:.6;font-weight:400}
html body #tsk-main .tk-filters-btn.has-active{background:var(--tsk-green-bg);color:var(--tsk-green-dark)}
html body #tsk-main .tk-filters-badge{border-radius:0;background:var(--tsk-green)}
html body #tsk-main .tk-toolbar .btn i{margin:0!important}
html body #tsk-main .tk-toolbar .btn .text-warning{color:var(--tm-orange)!important}
html body #tsk-main .tk-toolbar .btn-outline-danger{color:var(--tm-red)}
html body #tsk-main .tk-toolbar .btn-outline-danger:hover,html body #tsk-main .tk-toolbar .btn-outline-danger:focus-visible{background:var(--tm-red);color:#fff}
html body #tsk-main .tk-legend{border-top-color:var(--tm-line);color:var(--tm-muted)}
html body #tsk-main .tk-bulk-bar{background:var(--tm-dark);color:#fff;border:0}
html body #tsk-main .tk-bulk-bar .tk-bulk-btn{background:rgba(255,255,255,.1);color:#fff;border:0}
html body #tsk-main .tk-bulk-bar .tk-bulk-btn:hover{background:var(--tsk-green)}
html body #tsk-main .tk-bulk-bar .tk-bulk-btn.danger:hover{background:var(--tm-red)}
html body #tsk-main .tk-bulk-sep{background:rgba(255,255,255,.25)}
html body #tsk-main .tk-bulk-bar .tk-bulk-count,html body #tsk-main .tk-bulk-bar .tk-bulk-close{color:#fff}
/* Kanban */
html body #tsk-main .tk-kanban-col{background:var(--tm-light)}
/* Kolumny Kanbanu rozciągają się na dostępną szerokość (min. 256 px, maks. 420 px); przy wielu kolumnach — przewijanie w poziomie */
html body #tsk-main .tk-kanban-col{flex:1 0 256px;max-width:420px}
html body #tsk-main .tk-kanban-hdr{border-top-width:4px!important;background:transparent;font-weight:600}
html body #tsk-main .tk-card{border:0;background:#fff;box-shadow:none!important;margin-bottom:4px}
html body #tsk-main .tk-card:hover{outline:2px solid var(--tsk-text);outline-offset:-2px}
html body #tsk-main .tk-card-pri-bar{width:4px}
html body #tsk-main .tk-card-asgn{width:22px;height:22px;background:var(--tm-indigo);color:#fff}
html body #tsk-main .tk-card-drop-hint{border:2px dashed var(--tm-field);background:transparent}
/* Moje zadania / dashboard — wiersze */
html body .tsk-dash .tsk-row:hover,html body #tsk-main .tsk-row:hover{background:var(--tm-light)}

/* ══ Lista: kolumna obszarów po lewej + gęsta tabela ═════════════════════ */
html body #tsk-main .tk-layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:1.5rem;align-items:start}
html body #tsk-main .tk-layout-main{min-width:0}
/* Obszary jak lewa kolumna Skrzynki CRM: białe tło z linią po prawej, obszar = kafelek Metro w kolorze obszaru
   (ikona w kolorze czytelnym na tym tle — --ws-ink liczy PHP), pełna nazwa w dwóch liniach zamiast ucinania,
   licznik jako plakietka (zero wyciszone), aktywny obszar podbarwiony jego kolorem. */
html body #tsk-main .tk-layout{grid-template-columns:240px minmax(0,1fr)}
html body #tsk-main .tk-ws-side{position:sticky;top:120px;background:#fff;padding:.25rem 0 .5rem;border-right:1px solid var(--tm-line)}
html body #tsk-main .tk-ws-side-h{font-size:.66rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--tm-muted);padding:.4rem .9rem .45rem}
html body #tsk-main .tk-ws-side-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}
html body #tsk-main .tk-ws-side-link{display:flex;align-items:center;gap:.65rem;min-height:44px;padding:.35rem .75rem .35rem calc(.9rem - 3px);border-left:3px solid transparent;
  color:var(--tsk-text);text-decoration:none;font-size:.88rem;line-height:1.2}
html body #tsk-main .tk-ws-tile{width:30px;height:30px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;background:var(--ws);color:var(--ws-ink,#fff);font-size:.95rem}
html body #tsk-main .tk-ws-tile i{color:inherit}
html body #tsk-main .tk-ws-side-name{flex:1 1 auto;min-width:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
html body #tsk-main .tk-ws-side-cnt{flex-shrink:0;min-width:1.6rem;padding:.12rem .35rem;text-align:center;font-size:.74rem;font-weight:700;line-height:1.2;
  color:var(--tsk-text);background:var(--tm-light);font-variant-numeric:tabular-nums}
html body #tsk-main .tk-ws-side-cnt.is-zero{background:transparent;color:var(--tm-muted);font-weight:400}
html body #tsk-main .tk-ws-side-link:hover{background:var(--tm-light)}
html body #tsk-main .tk-ws-side-link:focus-visible{outline:2px solid var(--tsk-text);outline-offset:-2px}
html body #tsk-main .tk-ws-side-link.active{background:var(--tm-light);background:color-mix(in srgb, var(--ws) 12%, #fff);border-left-color:var(--ws);font-weight:700}
html body #tsk-main .tk-ws-side-link.active .tk-ws-side-cnt:not(.is-zero){background:var(--ws);color:var(--ws-ink,#fff)}
html body #tsk-main .tk-ws-side-manage{display:flex;align-items:center;gap:.5rem;margin:.5rem .5rem 0;padding:.6rem .4rem;border-top:1px solid var(--tm-line);
  font-size:.82rem;font-weight:600;color:var(--tsk-text);text-decoration:none}
html body #tsk-main .tk-ws-side-manage i{color:var(--tm-muted)}
html body #tsk-main .tk-ws-side-manage:hover{background:var(--tm-light)}
@media(max-width:991.98px){
  html body #tsk-main .tk-layout{grid-template-columns:minmax(0,1fr);gap:1rem}
  html body #tsk-main .tk-ws-side{position:static;padding:0;border-right:0;border-bottom:1px solid var(--tm-line);display:flex;align-items:stretch;overflow-x:auto;scrollbar-width:none}
  html body #tsk-main .tk-ws-side-h,html body #tsk-main .tk-ws-side-manage{display:none}
  html body #tsk-main .tk-ws-side-list{flex-direction:row;gap:0}
  html body #tsk-main .tk-ws-side-link{border-left:0;border-bottom:3px solid transparent;padding:.4rem .8rem;white-space:nowrap}
  html body #tsk-main .tk-ws-side-link.active{border-bottom-color:var(--ws)}
  html body #tsk-main .tk-ws-tile{width:22px;height:22px;font-size:.78rem}
  html body #tsk-main .tk-ws-side-name{display:block;white-space:nowrap}
}

html body #tsk-main .tk-table td{vertical-align:top;padding:.8rem .7rem}

/* ══ Tabela zadań v2: 6 kolumn o stałej szerokości, bez poziomego paska; priorytet i „dodane” w wierszu
   metadanych zadania. Wąski kontener (< 820px: tablet, telefon, wąskie okno) → wiersze jako karty. ══ */
html body #tsk-main .tk-table-box{width:100%;container-type:inline-size}
html body #tsk-main .tk-table--v2{width:100%;table-layout:fixed}
html body #tsk-main .tk-table--v2 th.th-title{min-width:0;width:auto}
html body #tsk-main .tk-table--v2 th.th-check{width:44px}
html body #tsk-main .tk-table--v2 th.th-status{width:150px}
html body #tsk-main .tk-table--v2 th.th-due{width:118px}
html body #tsk-main .tk-table--v2 th.th-people{width:170px}
html body #tsk-main .tk-table--v2 th.th-act{width:128px}
html body #tsk-main .tk-table--v2 td{overflow:hidden}
html body #tsk-main .tk-table--v2 td.td-title{overflow:visible}
html body #tsk-main .tk-table--v2 .tk-title{overflow-wrap:anywhere}
html body #tsk-main .tk-table--v2 .tk-people{display:flex;align-items:center;gap:.45rem;min-width:0}
html body #tsk-main .tk-table--v2 .tk-av-stack{flex-shrink:0}
html body #tsk-main .tk-table--v2 .tk-people-names{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.8rem}
html body #tsk-main .tk-table--v2 .tk-meta-pri .pri-btn{border:0;background:none;padding:0;font:inherit;color:inherit;cursor:pointer}
html body #tsk-main .tk-table--v2 .tk-meta-pri .tk-pri{font-size:.73rem;font-weight:600}
html body #tsk-main .tk-table--v2 .tk-meta-pri .tk-pri::before{width:8px;height:8px}
html body #tsk-main .tk-table--v2 .tk-meta-created{color:var(--tm-muted)}
html body #tsk-main .tk-table--v2 td.td-act .tk-actions{justify-content:flex-end}
@container (max-width: 820px){
  html body #tsk-main .tk-table--v2,html body #tsk-main .tk-table--v2 tbody{display:block;width:100%}
  html body #tsk-main .tk-table--v2 thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
  html body #tsk-main .tk-table--v2 tbody tr{display:grid;grid-template-columns:36px minmax(0,1fr) auto;
    grid-template-areas:"chk title act" ". status status" ". due people";gap:.15rem .5rem;padding:.6rem .6rem .7rem 0;border-left:4px solid #94a3b8}
  html body #tsk-main .tk-table--v2 tbody tr[data-pri="4"]{border-left-color:#dc2626}
  html body #tsk-main .tk-table--v2 tbody tr[data-pri="3"]{border-left-color:#f59e0b}
  html body #tsk-main .tk-table--v2 tbody tr[data-pri="2"]{border-left-color:#3b82f6}
  html body #tsk-main .tk-table--v2 tbody tr td{display:block;padding:.15rem 0;border:0!important;overflow:visible}
  html body #tsk-main .tk-table--v2 tbody tr td[colspan]{grid-column:1/-1}
  html body #tsk-main .tk-table--v2 td.td-check{grid-area:chk;padding:.2rem 0 0 .6rem!important}
  html body #tsk-main .tk-table--v2 td.td-title{grid-area:title}
  html body #tsk-main .tk-table--v2 td.td-act{grid-area:act}
  html body #tsk-main .tk-table--v2 td.td-status{grid-area:status}
  html body #tsk-main .tk-table--v2 td.td-due-cell{grid-area:due;min-width:0}
  html body #tsk-main .tk-table--v2 td.td-people{grid-area:people;min-width:0;max-width:55cqi}
  html body #tsk-main .tk-table--v2 td.td-due-cell::before,html body #tsk-main .tk-table--v2 td.td-people::before{content:attr(data-label);display:block;
    font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--tm-muted)}
  html body #tsk-main .tk-table--v2 td.td-act .btn-claim,html body #tsk-main .tk-table--v2 td.td-act .btn-unclaim{padding:0 .55rem}
}
html body #tsk-main .tk-table td.td-check{padding-top:.95rem}
html body #tsk-main .tk-table .tk-title{font-size:.94rem;-webkit-line-clamp:2}
html body #tsk-main .tk-table .tk-subtitle{font-size:.78rem;margin-top:.15rem}
html body #tsk-main .tk-meta{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem .8rem;margin-top:.4rem;font-size:.73rem;color:var(--tm-muted)}
html body #tsk-main .tk-meta>span{display:inline-flex;align-items:center;gap:.25rem;white-space:nowrap}
html body #tsk-main .tk-meta i{font-size:.8rem}
html body #tsk-main .tk-meta .is-warn{color:var(--tm-orange);font-weight:600}
html body #tsk-main .tk-meta .is-ok{color:var(--tsk-green)}
html body #tsk-main .tk-meta .tk-tag{font-size:.66rem;font-weight:700;padding:.08rem .4rem;color:inherit}
html body #tsk-main .tk-meta .tk-tag--more{background:var(--tm-light-h);color:var(--tm-muted)}
html body #tsk-main .tk-status-cell{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
html body #tsk-main .tk-due{display:flex;flex-direction:column;line-height:1.25}
html body #tsk-main .tk-due-d{font-size:.84rem;font-weight:600;color:var(--tsk-text)}
html body #tsk-main .tk-due-rel{font-size:.72rem;color:var(--tm-muted)}
html body #tsk-main .tk-due.is-over .tk-due-d,html body #tsk-main .tk-due.is-over .tk-due-rel{color:var(--tm-red)}
html body #tsk-main .tk-due.is-soon .tk-due-rel{color:var(--tm-orange);font-weight:600}
html body #tsk-main .tk-none{font-size:.76rem;color:var(--tm-field)}
html body #tsk-main .tk-people{display:flex;align-items:center;gap:.5rem;min-width:0}
html body #tsk-main .tk-people-names{font-size:.8rem;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:110px}
html body #tsk-main .tk-created{font-size:.8rem}
html body #tsk-main .tk-created-by{font-size:.72rem;color:var(--tm-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:110px}
html body #tsk-main .tk-table td:last-child .tk-actions{padding-top:2px}
@media(prefers-reduced-motion:reduce){html body #taskOffcanvas.offcanvas{transition:none}}
/* Kafle dodatków w oknie zadania: Powiązania, Jednostka, Zależności, Tagi, Powtarzanie */
html body #td-root .td-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:4px}
html body #td-root .td-tile{display:flex;flex-direction:column;align-items:flex-start;gap:.15rem;min-height:84px;padding:.6rem .7rem;border:0;
  background:var(--tm-light);color:var(--tsk-text);text-align:left;cursor:pointer;position:relative}
html body #td-root .td-tile i{font-size:1.15rem;color:var(--tm-muted)}
html body #td-root .td-tile-lab{font-weight:600;font-size:.85rem;margin-top:auto}
html body #td-root .td-tile-state{font-size:.72rem;color:var(--tm-muted)}
html body #td-root .td-tile.is-set{background:var(--tsk-green);color:#fff}
html body #td-root .td-tile.is-set i,html body #td-root .td-tile.is-set .td-tile-state{color:rgba(255,255,255,.85)}
html body #td-root .td-tile:hover{outline:2px solid var(--tsk-text);outline-offset:-2px}
html body #td-root .td-tile:focus-visible{outline:3px solid var(--tsk-text);outline-offset:-3px}
html body #td-root .td-tile[aria-expanded="true"]{background:var(--tm-dark);color:#fff}
html body #td-root .td-tile[aria-expanded="true"] i,html body #td-root .td-tile[aria-expanded="true"] .td-tile-state{color:rgba(255,255,255,.8)}
html body #td-root .td-tile-panel{margin-top:4px;border:2px solid var(--tm-dark)}
html body #td-root .td-tile-panel>.td-section:last-child{border-bottom:0}
/* ══ Okno „Nowe zadanie” — Metro: ciemny nagłówek, duży tytuł, kolumna w „Więcej opcji” ══ */
html body #addTaskModal .at-modal{border:0;border-radius:0}
html body #addTaskModal .modal-header{background:var(--tm-dark);color:#fff;border:0;padding:1rem 1.25rem;align-items:flex-start}
html body #addTaskModal .at-kicker{font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.65)}
html body #addTaskModal .modal-title{font-size:1.5rem;font-weight:300;margin:0;color:#fff}
html body #addTaskModal .modal-body{padding:1.25rem}
html body #addTaskModal .at-title-input{width:100%;border:0;border-bottom:2px solid var(--tm-field);border-radius:0;font-size:1.3rem;font-weight:300;padding:.4rem 0;margin-bottom:.9rem;background:transparent;color:var(--tsk-text)}
html body #addTaskModal .at-title-input:focus{outline:0;border-bottom-color:var(--tsk-green)}
html body #addTaskModal .at-title-input.is-invalid{border-bottom-color:var(--tm-red)}
html body #addTaskModal .at-in{width:100%;border:2px solid var(--tm-field);border-radius:0;background:#fff;color:var(--tsk-text);font:inherit;font-size:.9rem;padding:.4rem .55rem;min-height:2.4rem}
html body #addTaskModal .at-in:focus{outline:0;border-color:var(--tsk-green)}
html body #addTaskModal .at-desc{min-height:5.5rem;resize:vertical;margin-bottom:1rem}
html body #addTaskModal .at-l{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--tm-muted);margin-bottom:.25rem}
html body #addTaskModal .at-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.75rem}
html body #addTaskModal .at-assign{margin-top:1rem;background:var(--tm-light);padding:.75rem}
html body #addTaskModal .at-assign-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.5rem}
html body #addTaskModal .at-seg{display:inline-flex}
html body #addTaskModal .at-seg label{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .7rem;border:2px solid var(--tsk-text);font-size:.78rem;font-weight:600;cursor:pointer;background:#fff;color:var(--tsk-text)}
html body #addTaskModal .at-seg label+input+label{border-left:0}
html body #addTaskModal .at-seg .btn-check:checked+label{background:var(--tsk-text);color:#fff}
html body #addTaskModal .at-seg .btn-check:focus-visible+label{outline:2px solid var(--tsk-green);outline-offset:2px}
html body #addTaskModal .modal-footer{border-top:1px solid var(--tm-line);padding:.75rem 1.25rem;gap:.4rem}
html body #addTaskModal .at-hint{margin-right:auto;font-size:.75rem;color:var(--tm-muted)}
html body #addTaskModal .at-btn{display:inline-flex;align-items:center;border:2px solid var(--tsk-green);background:var(--tsk-green);color:#fff;font-weight:600;font-size:.88rem;padding:.4rem 1rem;border-radius:0}
html body #addTaskModal .at-btn:hover,html body #addTaskModal .at-btn:focus-visible{background:var(--tm-dark);border-color:var(--tm-dark)}
html body #addTaskModal .at-btn--ghost{background:#fff;color:var(--tsk-text);border-color:var(--tsk-text)}
html body #addTaskModal .at-btn--ghost:hover{background:var(--tsk-text);color:#fff}
html body #addTaskModal .at-btn:disabled{opacity:.6}

/* ══ Okno zadania: dwie kolumny — szczegóły po lewej, komentarze i pliki w prawej (szarej) kolumnie ══ */
html body #taskOffcanvas.offcanvas{width:min(1240px,96vw);max-width:96vw}
html body #td-root .td-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,400px);align-items:start}
html body #td-root .td-main{min-width:0}
html body #td-root .td-side{min-width:0;background:var(--tm-light);border-left:1px solid var(--tm-line);align-self:stretch}
html body #td-root .td-side>.td-section{border-bottom:1px solid #dcdcdc;padding:1rem 1.1rem}
html body #td-root .td-side .td-label{font-size:.8rem;letter-spacing:.06em;text-transform:none;font-weight:600;margin-bottom:.75rem}
html body #td-root .td-side .td-label .badge{background:var(--tsk-green)!important;border-radius:0}
html body #td-root .td-side .td-comment{background:#fff;border-left:3px solid var(--tsk-green);padding:.65rem .8rem;margin-bottom:.5rem}
html body #td-root .td-side .td-comment-author{font-weight:600}
html body #td-root .td-side #td-new-cmt{border:2px solid var(--tm-field);border-radius:0;background:#fff;min-height:4.5rem;font-size:.86rem}
html body #td-root .td-side #td-new-cmt:focus{border-color:var(--tsk-green);box-shadow:none}
html body #td-root .td-side .btn-primary{background:var(--tsk-green);border:0;border-radius:0;font-weight:600}
html body #td-root .td-side .btn-primary:hover,html body #td-root .td-side .btn-primary:focus-visible{background:var(--tm-dark)}
html body #td-root .td-side .td-file-row{background:#fff}
html body #td-root .td-side .td-drop-zone{background:#fff}
@media(min-width:992px){
  html body #td-root .td-side{position:sticky;top:0;max-height:calc(90vh - 3.5rem);overflow-y:auto;overscroll-behavior:contain}
}
@media(max-width:991.98px){
  html body #td-root .td-layout{grid-template-columns:minmax(0,1fr)}
  html body #td-root .td-side{border-left:0;border-top:4px solid var(--tsk-green)}
}


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

/* ══ Okno zadania (2026-11-10): lżejsze sekcje — pola z cienką ramką jak w Skrzynce i Wysyłce masowej,
   jeden styl małych przycisków (Obserwuj, ręczny wpis), wyraźne działania główne (Dodaj podzadanie, Start/Stop) ══ */
html body #td-root .td-main .td-section{padding:1rem 1.25rem}
html body #td-root .td-main .td-label{font-size:.7rem;letter-spacing:.1em;margin-bottom:.55rem}
html body #td-root .td-main .form-control,html body #td-root .td-main .form-select,html body #td-root .td-main .ts-wrapper .ts-control{
  border:1px solid #8a8886;border-radius:0;box-shadow:none;min-height:34px;font-size:.88rem}
html body #td-root .td-main .form-control:focus,html body #td-root .td-main .form-select:focus,html body #td-root .td-main .ts-wrapper.focus .ts-control{
  border-color:var(--tsk-green);box-shadow:inset 0 0 0 1px var(--tsk-green)}
html body #td-root .td-main .ts-wrapper .item{padding:.1rem .45rem!important;font-size:.84rem}
html body #td-root #td-desc{min-height:6.5rem;line-height:1.55;padding:.55rem .7rem;resize:vertical}
/* małe przyciski pomocnicze */
html body #td-root .td-mini-btn{display:inline-flex;align-items:center;gap:.35rem;min-height:30px;padding:0 .7rem;border:1px solid #8a8886;border-radius:0;
  background:#fff;color:var(--tsk-text);font-size:.8rem;font-weight:600;line-height:1;cursor:pointer;list-style:none;white-space:nowrap}
html body #td-root .td-mini-btn::-webkit-details-marker{display:none}
html body #td-root .td-mini-btn:hover{background:var(--tm-light)}
html body #td-root .td-mini-btn:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px}
html body #td-root .td-mini-btn i{margin:0!important;color:var(--tsk-green)}
html body #td-root #td-watch-btn[aria-pressed="true"]{background:var(--tsk-green);border-color:var(--tsk-green);color:#fff}
html body #td-root #td-watch-btn[aria-pressed="true"] i{color:#fff}
html body #td-root details[open]>summary.td-mini-btn{background:var(--tm-light)}
html body #td-root .td-watch{gap:.4rem .7rem;margin-top:.6rem!important}
html body #td-root #td-watch-list{font-size:.78rem;color:var(--tm-muted)!important}
/* podzadania: pole z dołączonym „Dodaj” */
html body #td-root #td-st-add-row{gap:0!important}
html body #td-root #td-st-add-row .form-control{border-right:0}
html body #td-root .td-add-btn{display:inline-flex;align-items:center;gap:.35rem;flex-shrink:0;min-height:34px;padding:0 .9rem;border:0;border-radius:0;
  background:var(--tsk-green);color:#fff;font-size:.82rem;font-weight:600;cursor:pointer}
html body #td-root .td-add-btn:hover,html body #td-root .td-add-btn:focus-visible{background:var(--tm-dark)}
html body #td-root .td-add-btn:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px}
html body #td-root #td-st-empty,html body #td-root #td-time-log>p{color:var(--tm-muted)!important;font-size:.82rem!important}
/* czas pracy: szacunek + Start/Stop */
html body #td-root #td-time-section label[for="td-est-hours"]{font-size:.78rem!important;color:var(--tm-muted)!important}
html body #td-root .td-est-in{width:5rem!important;min-height:30px!important;font-size:.82rem!important}
html body #td-root .td-timer-btn{display:inline-flex;align-items:center;gap:.3rem;min-height:30px;padding:0 .85rem;border:0;border-radius:0;
  background:var(--tsk-green);color:#fff;font-size:.8rem;font-weight:600;white-space:nowrap;cursor:pointer}
html body #td-root .td-timer-btn:hover{background:var(--tm-dark)}
html body #td-root .td-timer-btn:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px}
html body #td-root .td-timer-btn.is-running{background:var(--tm-red)}
html body #td-root .td-timer-btn i{margin:0!important}
html body #td-root #td-manual-time{margin-top:.6rem!important}

/* ══ Okno zadania → Folder zadania w Koszulkach (2026-11-10): pliki folderu zadania i przypięte z innych folderów,
   „Wgraj do folderu zadania”, przeglądarka folderów obszaru z „Dodaj” ══ */
html body #td-root .td-ws-head{display:flex;align-items:center;gap:.5rem}
html body #td-root .td-ws-open{margin-left:auto;font-size:.76rem;font-weight:600;color:var(--tm-blue);text-decoration:none;text-transform:none;letter-spacing:0}
html body #td-root .td-ws-open:hover{text-decoration:underline}
html body #td-root .td-ws-row{display:flex;align-items:center;gap:.55rem;padding:.4rem .55rem}
html body #td-root .td-ws-row>.bi:first-child{color:var(--tm-blue);font-size:1rem}
html body #td-root .td-ws-main{display:flex;flex-direction:column;min-width:0;flex:1}
html body #td-root .td-ws-name{display:block;max-width:100%;border:0;background:none;padding:0;text-align:left;font:inherit;font-size:.86rem;font-weight:600;
  color:var(--tsk-text);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
html body #td-root .td-ws-name:hover,html body #td-root .td-ws-name:focus-visible{text-decoration:underline;color:var(--tm-blue)}
html body #td-root .td-ws-meta{font-size:.72rem;color:var(--tm-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html body #td-root .td-ws-subhead{margin:.6rem 0 .25rem;font-size:.72rem;font-weight:700;color:var(--tm-muted)}
html body #td-root .td-ws-note{margin:.35rem 0 0;font-size:.8rem;color:var(--tm-muted)}
html body #td-root .td-ws-note.is-err{color:var(--tm-red)}
html body #td-root .td-ws-actions{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.55rem}
html body #td-root .td-ws-picker{margin-top:.5rem;border:1px solid #c8c6c4;background:#fff}
html body #td-root .td-ws-pick-bar{display:flex;gap:.3rem;padding:.4rem;background:var(--tm-light);border-bottom:1px solid var(--tm-line)}
html body #td-root .td-ws-pick-bar .form-select,html body #td-root .td-ws-pick-bar .form-control{min-height:30px;font-size:.8rem;border:1px solid #8a8886;border-radius:0}
html body #td-root .td-ws-pick-bar .form-select{max-width:48%}
html body #td-root .td-ws-results{max-height:260px;overflow-y:auto;padding:.2rem .45rem}
html body #td-root .td-ws-pick-row{display:flex;align-items:center;gap:.5rem;padding:.35rem .1rem;border-bottom:1px solid #f0f0f0}
html body #td-root .td-ws-pick-row:last-child{border-bottom:0}
html body #td-root .td-ws-pick-row>.bi{color:var(--tm-blue)}
html body #td-root .td-ws-pick-name{font-size:.84rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ══ Lista zadań (2026-11-10): nagłówek obszaru z działaniami + zakładki statusu, wyszukiwarka i filtry w jednym wierszu ══
   Nagłówek: kafelek obszaru (jak w kolumnie Obszary), przełącznik Lista/Kanban, „Nowe zadanie”, menu „⋯” (powiadomienia,
   eksport, usuń obszar). Pasek: zakładki z podkreśleniem zamiast szarych pigułek, liczniki jako plakietki (zero wyciszone). */
html body #tsk-main .tk-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem 1rem;margin-bottom:.75rem}
html body #tsk-main .tk-head-t{display:flex;align-items:center;gap:.65rem;margin:0;font-size:1.35rem;font-weight:600;letter-spacing:-.01em;color:var(--tsk-text);min-width:0}
html body #tsk-main .tk-head-t>span:last-child{overflow-wrap:anywhere}
html body #tsk-main .tk-head-tile{width:36px;height:36px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;background:var(--ws);color:var(--ws-ink,#fff);font-size:1.1rem}
html body #tsk-main .tk-head-act{display:flex;align-items:center;flex-wrap:wrap;gap:.4rem}
html body #tsk-main .tk-hbtn{display:inline-flex;align-items:center;gap:.4rem;min-height:36px;padding:0 .85rem;border:1px solid #8a8886;border-radius:0;
  background:#fff;color:var(--tsk-text);font-size:.85rem;font-weight:600;line-height:1;white-space:nowrap;cursor:pointer}
html body #tsk-main .tk-hbtn:hover{background:var(--tm-light)}
html body #tsk-main .tk-hbtn:focus-visible,html body #tsk-main .tk-viewsw-b:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px}
html body #tsk-main .tk-hbtn--cta{background:var(--tsk-green);border-color:var(--tsk-green);color:#fff}
html body #tsk-main .tk-hbtn--cta:hover{background:var(--tm-dark);border-color:var(--tm-dark)}
html body #tsk-main .tk-hbtn--icon{width:36px;padding:0;justify-content:center;font-size:1.05rem}
html body #tsk-main .tk-viewsw{display:inline-flex;border:1px solid #8a8886}
html body #tsk-main .tk-viewsw-b{display:inline-flex;align-items:center;gap:.35rem;min-height:34px;padding:0 .75rem;color:var(--tsk-text);text-decoration:none;font-size:.84rem;font-weight:600}
html body #tsk-main .tk-viewsw-b+.tk-viewsw-b{border-left:1px solid #8a8886}
html body #tsk-main .tk-viewsw-b:hover{background:var(--tm-light)}
html body #tsk-main .tk-viewsw-b.is-on{background:var(--tsk-text);color:#fff}
html body #tsk-main .tk-more{min-width:250px;padding:.3rem 0;font-size:.86rem;border-radius:0}
html body #tsk-main .tk-more .dropdown-item{display:flex;align-items:center;gap:.6rem;padding:.5rem .9rem}
html body #tsk-main .tk-more .dropdown-item>i{width:1rem;text-align:center;color:var(--tm-muted)}
html body #tsk-main .tk-more-note{margin-left:auto;font-size:.74rem;color:var(--tm-muted)}
html body #tsk-main .tk-more-danger,html body #tsk-main .tk-more-danger>i{color:var(--tm-red)!important}
html body #tsk-main .tk-more .dropdown-item:hover .tk-more-note,html body #tsk-main .tk-more .dropdown-item:focus .tk-more-note{color:inherit}

html body #tsk-main .tk-toolbar{display:flex;align-items:flex-end;flex-wrap:wrap;gap:.5rem 1rem;background:#fff;padding:0;margin-bottom:.85rem;border:0!important;border-bottom:1px solid var(--tm-line)!important}
html body #tsk-main .tk-pivot{display:flex;align-items:stretch;gap:.15rem;overflow-x:auto;scrollbar-width:none;min-width:0}
html body #tsk-main .tk-toolbar .tk-pill,html body #tsk-main .tk-toolbar .tk-pill[data-s]{height:auto;min-height:40px;padding:.45rem .7rem .4rem;background:transparent;color:var(--tm-muted);
  border:0!important;border-bottom:3px solid transparent!important;border-radius:0;font-size:.88rem;font-weight:600;gap:.4rem;white-space:nowrap}
html body #tsk-main .tk-toolbar .tk-pill:hover{background:var(--tm-light);color:var(--tsk-text)}
html body #tsk-main .tk-toolbar .tk-pill[data-s].active{background:transparent;color:var(--tsk-text);border-bottom-color:var(--tsk-green)!important}
html body #tsk-main .tk-toolbar .tk-pill:focus-visible{outline:2px solid var(--tsk-text);outline-offset:-2px}
html body #tsk-main .tk-toolbar .pill-n{min-width:1.4rem;padding:.1rem .35rem;font-size:.72rem;font-weight:700;line-height:1.2;text-align:center;opacity:1;
  background:var(--tm-light);color:var(--tsk-text);font-variant-numeric:tabular-nums}
html body #tsk-main .tk-toolbar .tk-pill.active .pill-n:not(.is-zero){background:var(--tsk-green);color:#fff}
html body #tsk-main .tk-toolbar .pill-n.is-zero{background:transparent;color:var(--tm-muted);font-weight:400}
html body #tsk-main .tk-tools{display:flex;align-items:center;gap:.4rem;margin-left:auto;padding-bottom:.45rem}
html body #tsk-main .tk-search-w{position:relative}
html body #tsk-main .tk-search-w>i{position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:var(--tm-muted);font-size:.85rem;pointer-events:none}
html body #tsk-main .tk-toolbar .tk-search{width:15rem;max-width:60vw;height:34px;padding-left:1.9rem;border:1px solid #8a8886!important;border-radius:0;background:#fff;font-size:.86rem}
html body #tsk-main .tk-toolbar .tk-search:focus{border-color:var(--tsk-green)!important;box-shadow:inset 0 0 0 1px var(--tsk-green);outline:0}
html body #tsk-main .tk-toolbar .tk-filters-btn{height:34px;border:1px solid #8a8886!important;background:#fff;color:var(--tsk-text)}
html body #tsk-main .tk-toolbar .tk-filters-btn:hover{background:var(--tm-light);color:var(--tsk-text)}
html body #tsk-main .tk-toolbar .tk-filters-btn.has-active{background:var(--tsk-green-bg);border-color:var(--tsk-green)!important;color:var(--tsk-green-dark)}
/* ══ Lista zadań (2026-11-10): wiersze pogrupowane wg kolumn (zwijane nagłówki w kolorze kolumny), spokojny status
   (zdanie, nie wersaliki; kolor tylko kropką/tłem), stopka z poprawną odmianą, legenda Z/ZP/O tylko przy ukończonych ══ */
html body #tsk-main .tk-table--v2 thead th{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#3f3f46}
html body #tsk-main .tk-table--v2 tr.tk-grp-row{border-bottom:0}
html body #tsk-main .tk-table--v2 tr.tk-grp-row:hover{background:transparent}
html body #tsk-main .tk-table--v2 tr.tk-grp-row>th{padding:.9rem .5rem .35rem;background:#fff;border-bottom:2px solid var(--lc,#8a8886);text-transform:none;letter-spacing:0}
html body #tsk-main .tk-table--v2 tbody.tk-grp:first-of-type tr.tk-grp-row>th{padding-top:.5rem}
html body #tsk-main .tk-grp-btn{display:inline-flex;align-items:center;gap:.5rem;border:0;background:none;padding:.15rem .3rem;font-size:.92rem;font-weight:700;color:var(--tsk-text);cursor:pointer}
html body #tsk-main .tk-grp-btn:hover{background:var(--tm-light)}
html body #tsk-main .tk-grp-btn:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px}
html body #tsk-main .tk-grp-btn>i{font-size:.75rem;color:var(--tm-muted);transition:transform .15s}
html body #tsk-main .tk-grp-n{min-width:1.5rem;padding:.08rem .4rem;background:var(--tm-light-h);font-size:.74rem;font-weight:700;text-align:center;font-variant-numeric:tabular-nums}
html body #tsk-main tbody.tk-grp.is-collapsed .tk-grp-btn>i{transform:rotate(-90deg)}
html body #tsk-main tbody.tk-grp.is-collapsed>tr:not(.tk-grp-row){display:none!important}
html body #tsk-main .tk-table--v2 .tk-status{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .6rem;border:1px solid #c8c6c4;border-radius:0;background:#fff;
  color:var(--tsk-text);font-size:.8rem;font-weight:600;letter-spacing:0;text-transform:none;white-space:nowrap;filter:none}
html body #tsk-main .tk-table--v2 .tk-status.s-open i{color:var(--tm-muted)}
html body #tsk-main .tk-table--v2 .tk-status.s-taken{background:#eef3ff;border-color:#b8c9f5;color:#0b3a9e}
html body #tsk-main .tk-table--v2 .tk-status.s-taken i{color:#0050ef}
html body #tsk-main .tk-table--v2 .tk-status.s-done{background:#dff6dd;border-color:#a7d8a4;color:#0e5a0e}
html body #tsk-main .tk-table--v2 .tk-status:hover{border-color:var(--tsk-text);filter:none}
html body #tsk-main .tk-table--v2 .tk-status:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px;filter:none}
html body #tsk-main .tk-table--v2 .tk-title{font-weight:600;font-size:.92rem}
html body #tsk-main .tk-foot{display:flex;align-items:center;flex-wrap:wrap;gap:.5rem 1.5rem;padding:.6rem .75rem;border-top:1px solid var(--tm-line);font-size:.8rem;color:#3f3f46}
html body #tsk-main .tk-foot .tk-legend{display:flex;flex-wrap:wrap;gap:.3rem 1rem;margin:0 0 0 auto;padding:0;border:0;font-size:.76rem;color:#3f3f46}
@container (max-width: 820px){
  html body #tsk-main .tk-table--v2 tbody tr.tk-grp-row{display:block;border-left:0;padding:0}
  html body #tsk-main .tk-table--v2 tbody tr.tk-grp-row>th{display:block}
}

/* ══ Ustawienia → Obszary robocze: układ i kafelki jak na liście zadań, zakładki z podkreśleniem ══ */
html body #tsk-main .ws-set .tk-ws-side-link.is-inactive{opacity:.6}
html body #tsk-main .tk-ws-side-empty{padding:.5rem .9rem;color:var(--tm-muted);font-size:.85rem;margin:0}
html body #tsk-main .ws-set-sub{display:block;font-size:.8rem;font-weight:400;color:var(--tm-muted);letter-spacing:0;margin-top:.1rem}
html body #tsk-main .ws-set-tabs.nav-tabs{border-bottom:1px solid var(--tm-line);gap:.15rem}
html body #tsk-main .ws-set-tabs .nav-link{border:0;border-bottom:3px solid transparent;border-radius:0;background:transparent;color:var(--tm-muted);font-weight:600;font-size:.9rem;padding:.55rem .8rem .45rem;margin-bottom:-1px}
html body #tsk-main .ws-set-tabs .nav-link:hover{background:var(--tm-light);color:var(--tsk-text)}
html body #tsk-main .ws-set-tabs .nav-link.active{color:var(--tsk-text);border-bottom-color:var(--tsk-green);background:transparent}
html body #tsk-main .ws-set-tabs .nav-link .badge{border-radius:0;background:var(--tm-light-h)!important;color:var(--tsk-text);font-weight:700}
html body #tsk-main .ws-set-tabs .nav-link.active .badge{background:var(--tsk-green)!important;color:#fff}
html body #tsk-main .ws-set-empty{display:flex;flex-direction:column;align-items:center;gap:.6rem;padding:3rem 1rem;background:var(--tm-light);text-align:center;color:#3f3f46}
html body #tsk-main .ws-set-empty>i{font-size:2rem;color:var(--tm-muted)}
html body #tsk-main .ws-set-empty p{margin:0}

@media(max-width:767.98px){
  html body #tsk-main .tk-tools{margin-left:0;width:100%}
  html body #tsk-main .tk-search-w{flex:1}
  html body #tsk-main .tk-toolbar .tk-search{width:100%;max-width:none}
}
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
