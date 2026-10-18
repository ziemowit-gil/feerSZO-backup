<?php
/**
 * panel/includes/pv_metro.php — warstwa wizualna „Metro UI" Panelu współpracownika.
 *
 * Ta sama szata co System Tożsamości (tozsamosc/_head.php + index.php, wg
 * metroui.org.ua): ciemny app-bar #1d1d1d, Segoe UI, lekka duża typografia
 * nagłówków, ZERO zaokrągleń, cieni i gradientów, płaskie szare grupy #f2f2f2,
 * pełne kolory palety Metro na znacznikach i kafelkach.
 *
 * Akcent (znak marki, aktywne menu, pivot, przyciski) = per-user --vol-color
 * (panel/panel_color.php) — personalizacja panelu zostaje. Motywy dark / hc
 * z panel_color.php nadal działają (tokeny niżej).
 *
 * Tylko CSS — żadnych zmian w znacznikach, ID ani zaczepach skryptów.
 * Ładowany WYŁĄCZNIE przez header_panel.php (powłoka Panelu współpracownika) jako
 * ostatni blok blok stylów w sekcji head. Reguły treści mają zakres :where(#pv-main)
 * (specyficzność jednej klasy): wygrywają z pv_styles.php, ale PRZEGRYWAJĄ
 * z pulpitem w zestawie modules/panel_metro (.pvm .x) — pulpit stylizuje się sam.
 * Paleta i tryb ciemny zgodne z modules/panel_metro.
 *
 * KONTRAKT z pv_ui_styles() (panel/includes/pv_ui.php, 908398c7): pod powłoką SZO ten plik
 * jest parsowany — brane są bloki :root, @media(prefers-color-scheme…) i reguły najwyższego
 * poziomu zawierające „pv-main” (zakres podmieniany na .pv-wrap). Reguły treści trzymaj
 * z selektorem #pv-main / :where(#pv-main), komentarze tylko w /∗ ∗/.
 */
?>
<style>
/* ── Tokeny Metro ─────────────────────────────────────────────────────────── */
:root{
  /* paleta jak modules/panel_metro (przyciemniona do WCAG AA: biały tekst ≥ 4,5:1) */
  --m-blue:#0f6fa8;--m-blue-d:#0b5683;--m-green:#007a00;--m-orange:#c25100;--m-orange-d:#c25100;
  --m-red:#a20025;--m-teal:#00807e;--m-purple:#6a00ff;--m-magenta:#d80073;--m-indigo:#0050ef;
  --m-dark:#1d1d1d;--m-grey:#5f5f5f;
  --m-acc:var(--vol-color,#0f6fa8);
  --m-font:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;
}
:root:not([data-theme="hc"]):not([data-theme="dark"]){
  --m-bg:#ffffff;--m-surface:#ffffff;--m-light:#f2f2f2;--m-light-h:#e6e6e6;--m-line:#e1e1e1;
  --m-ink:#111827;--m-muted:#5f5f5f;--m-field:#8a8a8a;
  --pvl-body:#ffffff;--pvl-nav:#ffffff;--pvl-nav-b:#e1e1e1;--pvl-txt:#1f2937;--pvl-sub:#5f5f5f;--pvl-div:#e1e1e1;--pvl-brand:#111827;
  --tz-line:#e1e1e1;--tz-muted:#5f5f5f;--tz-ink:#111827;--tz-bg:#ffffff;--tz-bg-page:#f2f2f2;--tz-bg-sub:#f2f2f2;
}
@media(prefers-color-scheme:dark){
  :root:not([data-theme="light"]):not([data-theme="hc"]){
    --m-bg:#090e1a;--m-surface:#0f172a;--m-light:#1a2232;--m-light-h:#222c3f;--m-line:#1e2535;
    --m-ink:#f1f5f9;--m-muted:#94a3b8;--m-field:#475569;
    --pvl-body:#090e1a;--pvl-nav:#0f172a;--pvl-nav-b:#1e2535;--pvl-txt:#cbd5e0;--pvl-sub:#94a3b8;--pvl-div:#1e2535;--pvl-brand:#f1f5f9;
    --tz-line:#1e2535;--tz-muted:#94a3b8;--tz-ink:#f1f5f9;--tz-bg:#0f172a;--tz-bg-page:#1a2232;--tz-bg-sub:#1a2232;
  }
}
:root[data-theme="dark"]{
  --m-bg:#090e1a;--m-surface:#0f172a;--m-light:#1a2232;--m-light-h:#222c3f;--m-line:#1e2535;
  --m-ink:#f1f5f9;--m-muted:#94a3b8;--m-field:#475569;
  --pvl-body:#090e1a;--pvl-nav:#0f172a;--pvl-nav-b:#1e2535;--pvl-txt:#cbd5e0;--pvl-sub:#94a3b8;--pvl-div:#1e2535;--pvl-brand:#f1f5f9;
  --tz-line:#1e2535;--tz-muted:#94a3b8;--tz-ink:#f1f5f9;--tz-bg:#0f172a;--tz-bg-page:#1a2232;--tz-bg-sub:#1a2232;
}
:root[data-theme="hc"]{
  --m-bg:#ffffff;--m-surface:#ffffff;--m-light:#ffffff;--m-light-h:#eeeeee;--m-line:#000000;
  --m-ink:#000000;--m-muted:#111111;--m-field:#000000;--m-acc:#000000;
}

/* ── Baza ─────────────────────────────────────────────────────────────────── */
body{font-family:var(--m-font);background:var(--m-bg);color:var(--m-ink);-webkit-font-smoothing:antialiased}
.pv-skip{border-radius:0}
*:focus-visible{border-radius:0}

/* Zero zaokrągleń w całym panelu (Metro). Wyjątki: kontrolki, które muszą być
   okrągłe (radio, przełącznik), spinnery i jawne .rounded-circle. */
#pv-main *:not(.form-check-input):not(.spinner-border):not(.spinner-grow):not(.rounded-circle):not(.form-range),
.pv-offcanvas, .pv-offcanvas *, .modal-content, .modal-content *:not(.form-check-input):not(.spinner-border),
.dropdown-menu, .dropdown-menu *, .toast, .offcanvas, .popover, .tooltip-inner{border-radius:0!important}

/* ── App-bar (jak .tzbar w Tożsamości) ────────────────────────────────────── */
.pv-navbar,:root:not([data-theme="hc"]) .pv-navbar{background:var(--m-dark);border-bottom:0;box-shadow:none}
.pv-bar-inner{max-width:1100px;padding:.45rem 1rem}
.pv-bar-inner .pv-brand,.pv-bar-inner .pv-brand:hover{color:#fff;font-weight:700}
.pv-bar-inner .pv-brand-sub{color:rgba(255,255,255,.65)}
.pv-bar-inner .pv-brand-icon{width:34px;height:34px;background:var(--m-acc);color:#fff}
.pv-bar-inner .pv-menu-btn{height:34px;border:0;background:rgba(255,255,255,.08);color:#fff;font-weight:600}
.pv-bar-inner .pv-menu-btn:hover,.pv-bar-inner .pv-menu-btn:focus-visible{background:var(--m-acc);color:#fff;border-color:transparent}
.pv-lnk{color:rgba(255,255,255,.85);font-weight:500;padding:.4rem .65rem}
.pv-lnk:hover,.pv-lnk:focus-visible{background:rgba(255,255,255,.1);color:#fff}
.pv-lnk-badge{background:var(--m-acc);color:#fff}
.pv-avatar{width:30px!important;height:30px!important;border-radius:0!important;background:var(--m-acc);color:#fff}
.pv-navbar .dropdown-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35);padding:.35rem;background:var(--m-surface)}
.pv-navbar .dropdown-item{color:var(--m-ink)}
.pv-navbar .dropdown-item:hover,.pv-navbar .dropdown-item:focus{background:var(--m-light);color:var(--m-ink)}
[data-theme="hc"] .pv-navbar{background:#000;border-bottom:2px solid #000}
[data-theme="hc"] .pv-lnk{color:#fff;border:1px solid #fff}
[data-theme="hc"] .pv-lnk:hover{background:#fff;color:#000}
[data-theme="hc"] .pv-bar-inner .pv-menu-btn{background:#000;color:#fff;border:2px solid #fff}
[data-theme="hc"] .pv-bar-inner .pv-menu-btn:hover,[data-theme="hc"] .pv-bar-inner .pv-menu-btn:focus-visible{background:#fff;color:#000}
[data-theme="hc"] .pv-avatar,[data-theme="hc"] .pv-bar-inner .pv-brand-icon{background:#fff;color:#000}

/* ── Menu sekcji (offcanvas) ──────────────────────────────────────────────── */
.pv-offcanvas{--bs-offcanvas-bg:var(--m-surface);background:var(--m-surface);border-right:0!important}
.pv-offcanvas .offcanvas-header{background:var(--m-dark);color:#fff}
.pv-offcanvas .offcanvas-body{padding:.5rem 0}
.pv-nav-label{font-size:.68rem;letter-spacing:.14em;color:var(--m-muted);padding:1rem 1.1rem .35rem}
.pv-nav-link{margin:0;padding:.6rem 1.1rem;border-left:0;font-size:.9rem;color:var(--m-ink)}
.pv-nav-link i{color:var(--m-muted)}
.pv-nav-link:hover{background:var(--m-light);color:var(--m-ink);border-left:0}
.pv-nav-link:hover i{color:var(--m-ink)}
.pv-nav-link.pv-active{background:var(--m-acc);color:#fff;font-weight:600}
.pv-nav-link.pv-active i{color:#fff!important}
.pv-nav-link .pv-badge{background:var(--m-acc);color:#fff}
.pv-nav-link.pv-active .pv-badge{background:rgba(0,0,0,.28)}
.pv-nav-divider{margin:.4rem 0;background:var(--m-line)}
.pv-sidebar-bottom{border-top:1px solid var(--m-line);padding:.35rem 0}
[data-theme="hc"] .pv-offcanvas .offcanvas-header{background:#000}
[data-theme="hc"] .pv-nav-link.pv-active,[data-theme="hc"] .pv-nav-link:hover{background:#000;color:#fff}

/* ── Treść ───────────────────────────────────────────────────────────────── */
#pv-main.container{max-width:1100px;padding-top:1.75rem;padding-bottom:3rem}
.pv-footer{background:var(--m-bg);border-top:1px solid var(--m-line);color:var(--m-muted);max-width:1100px;width:100%;margin:0 auto;padding:1rem 1rem 2rem}

/* Nagłówki stron — lekkie i duże jak .mt-title */
:where(#pv-main) .pv-page-title,:where(#pv-main) .tz-h h1,:where(#pv-main) > h1:first-child{font-size:2rem;font-weight:300;letter-spacing:-.02em;line-height:1.1;color:var(--m-ink)}
:where(#pv-main) .pv-page-title i,:where(#pv-main) .tz-h h1 i{font-size:1.5rem;color:var(--m-acc)}
:where(#pv-main) .pv-page-sub,:where(#pv-main) .tz-h p{color:var(--m-muted);font-size:.9rem;font-weight:300;margin-top:.35rem}
:where(#pv-main) .pv-page-back{color:var(--m-muted);text-transform:uppercase;letter-spacing:.12em;font-size:.7rem;font-weight:600}
:where(#pv-main) h2,:where(#pv-main) .h2{font-weight:300;letter-spacing:-.01em}
:where(#pv-main) .tz-section-h{font-size:.7rem;letter-spacing:.14em;color:var(--m-muted)}

/* Karty: płaskie, ramka 1px, nagłówek w szarym pasie (.tz-card z Tożsamości) */
:where(#pv-main) .card,:where(#pv-main) .tz-card,:where(#pv-main) .pv-card,:where(#pv-main) .vol-detail-card,:where(#pv-main) .vol-activity,
:where(#pv-main) .b-tile,:where(#pv-main) .pv-stat-tile{background:var(--m-surface);border:1px solid var(--m-line)!important;box-shadow:none!important}
:where(#pv-main) .card-header,:where(#pv-main) .tz-card__hd,:where(#pv-main) .pv-card-hd,:where(#pv-main) .vol-detail-header,:where(#pv-main) .vol-activity-header{
  background:var(--m-light);border-bottom:0;color:var(--m-ink);font-weight:600}
:where(#pv-main) .card-header i,:where(#pv-main) .tz-card__hd i,:where(#pv-main) .pv-card-hd i,:where(#pv-main) .vol-detail-header i{color:var(--m-acc)}
:where(#pv-main) .card-footer,:where(#pv-main) .tz-card__ft{background:var(--m-light);border-top:0;color:var(--m-muted)}
:where(#pv-main) .tz-card__icon{background:var(--m-acc);color:#fff}
:where(#pv-main) .vol-activity-row,:where(#pv-main) .vol-detail-row,:where(#pv-main) .pv-filter-chips{border-bottom-color:var(--m-line)}
:where(#pv-main) .vol-activity-icon{border-radius:0!important}

/* Kafelki (.tz-tile) — szary prostokąt, kwadratowa ikona w akcencie */
:where(#pv-main) .tz-tile{background:var(--m-light);border:0;box-shadow:none;transform:none}
:where(#pv-main) .tz-tile:hover,:where(#pv-main) .tz-tile:focus-visible{background:var(--m-light-h);border:0;box-shadow:none;transform:none}
:where(#pv-main) .tz-tile__ico{background:var(--m-acc);color:#fff}
:where(#pv-main) .tz-tile__badge{background:var(--m-red)}

/* Usługi / dostępy */
:where(#pv-main) .tz-svc{border-top-color:var(--m-line)}
:where(#pv-main) .tz-svc__ico{background:var(--m-acc);color:#fff}
:where(#pv-main) .tz-kv code{background:var(--m-light);color:var(--m-ink)}
:where(#pv-main) .tz-copy:hover{background:var(--m-light)}

/* Siatka definicji */
:where(#pv-main) .tz-dl>div{border-top-color:var(--m-line)}
:where(#pv-main) .tz-dl dt{font-size:.68rem;letter-spacing:.1em;color:var(--m-muted)}
:where(#pv-main) .tz-dl dd{font-weight:400;font-size:1rem;color:var(--m-ink)}
:where(#pv-main) .tz-yes{color:var(--m-green)}

/* Przyciski — kwadratowe, ramka 2px */
:where(#pv-main) .btn,.pv-offcanvas .btn,.modal .btn{font-weight:600;box-shadow:none}
:where(#pv-main) .tz-btn{background:var(--m-acc);color:#fff;border:2px solid var(--m-acc);filter:none}
:where(#pv-main) .tz-btn:hover{background:var(--m-dark);border-color:var(--m-dark);color:#fff;filter:none}
:where(#pv-main) .tz-btn--ghost{background:transparent;color:var(--m-ink);border:2px solid var(--m-ink)}
:where(#pv-main) .tz-btn--ghost:hover{background:var(--m-ink);color:var(--m-bg);border-color:var(--m-ink)}
:where(#pv-main) .btn-primary{--bs-btn-bg:var(--m-acc);--bs-btn-border-color:var(--m-acc);--bs-btn-hover-bg:var(--m-dark);--bs-btn-hover-border-color:var(--m-dark)}
:where(#pv-main) .btn-outline-secondary,:where(#pv-main) .btn-light{--bs-btn-border-color:var(--m-ink);--bs-btn-color:var(--m-ink);--bs-btn-bg:transparent;--bs-btn-hover-bg:var(--m-ink);--bs-btn-hover-color:var(--m-bg);--bs-btn-hover-border-color:var(--m-ink);border-width:2px}

/* Pola formularzy — ramka 2px, fokus w akcencie bez poświaty */
:where(#pv-main) .form-control,:where(#pv-main) .form-select,.modal .form-control,.modal .form-select{border:2px solid var(--m-field);background-color:var(--m-surface);color:var(--m-ink);box-shadow:none}
:where(#pv-main) .form-control:focus,:where(#pv-main) .form-select:focus,.modal .form-control:focus,.modal .form-select:focus{border-color:var(--m-acc);box-shadow:none}
:where(#pv-main) .input-group-text{border:2px solid var(--m-field);background:var(--m-light)}
:where(#pv-main) .form-check-input:checked{background-color:var(--m-acc);border-color:var(--m-acc)}

/* Znaczniki — pełne kolory, wersaliki (jak .tz-badge w Tożsamości) */
:where(#pv-main) .tz-badge,:where(#pv-main) .pv-status-pill,:where(#pv-main) .pv-status-badge{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:.18rem .5rem;border:0;color:#fff;background:var(--m-grey)}
:where(#pv-main) .tz-badge--ok,:where(#pv-main) .tz-badge--success,:where(#pv-main) .pv-sp-ok,:where(#pv-main) .pv-status-badge.is-active{background:var(--m-green);color:#fff}
:where(#pv-main) .tz-badge--wait,:where(#pv-main) .tz-badge--warn,:where(#pv-main) .tz-badge--warning,:where(#pv-main) .pv-sp-pending{background:var(--m-orange-d);color:#fff}
:where(#pv-main) .tz-badge--danger,:where(#pv-main) .pv-sp-cancelled{background:var(--m-red);color:#fff}
:where(#pv-main) .tz-badge--off,:where(#pv-main) .tz-badge--muted,:where(#pv-main) .pv-sp-closed,:where(#pv-main) .pv-status-badge.is-ended{background:var(--m-grey);color:#fff}
:where(#pv-main) .tz-badge--new,:where(#pv-main) .pv-sp-new{background:var(--m-indigo);color:#fff}
:where(#pv-main) .pv-sp-draft{background:var(--m-purple);color:#fff}
:where(#pv-main) .badge{font-weight:700;letter-spacing:.03em}

/* Notki, alerty, puste stany — płaski pas z grubą lewą krawędzią (.tzx-aside) */
:where(#pv-main) .tz-note,:where(#pv-main) .pv-note,:where(#pv-main) .pv-alert,:where(#pv-main) .alert{background:var(--m-light);border:0;border-left:6px solid var(--m-acc);color:var(--m-ink)}
:where(#pv-main) .tz-note i,:where(#pv-main) .pv-note i{color:var(--m-acc)}
:where(#pv-main) .pv-alert-ok,:where(#pv-main) .pv-alert-success,:where(#pv-main) .alert-success,:where(#pv-main) .tz-note--ok,:where(#pv-main) .tz-note--success{border-left-color:var(--m-green)}
:where(#pv-main) .pv-alert-err,:where(#pv-main) .pv-alert-danger,:where(#pv-main) .alert-danger,:where(#pv-main) .tz-note--danger{border-left-color:var(--m-red)}
:where(#pv-main) .pv-alert-warn,:where(#pv-main) .pv-alert-warning,:where(#pv-main) .alert-warning,:where(#pv-main) .tz-note--warn,:where(#pv-main) .tz-note--warning,:where(#pv-main) .pv-note.pv-note-warn{border-left-color:var(--m-orange);background:var(--m-light)}
:where(#pv-main) .pv-alert-info,:where(#pv-main) .alert-info,:where(#pv-main) .tz-note--info{border-left-color:var(--m-blue)}
:where(#pv-main) .pv-alert-success i{color:var(--m-green)}:where(#pv-main) .pv-alert-danger i{color:var(--m-red)}:where(#pv-main) .pv-alert-warning i{color:var(--m-orange-d)}:where(#pv-main) .pv-alert-info i{color:var(--m-blue-d)}
:where(#pv-main) .tz-empty{background:var(--m-light);border:0;color:var(--m-muted)}
:where(#pv-main) .pv-empty-title{color:var(--m-ink)}

/* Tabele i listy */
:where(#pv-main) .pv-table-wrap{border:1px solid var(--m-line)}
:where(#pv-main) .pv-table th{font-size:.68rem;letter-spacing:.1em;color:var(--m-muted);border-bottom:2px solid var(--m-ink);background:var(--m-surface)}
:where(#pv-main) .pv-table td{border-bottom-color:var(--m-line);background:var(--m-surface);color:var(--m-ink)}
:where(#pv-main) .pv-table tr:hover td{background:var(--m-light)}
:where(#pv-main) .table{--bs-table-bg:transparent;--bs-table-color:var(--m-ink);--bs-table-border-color:var(--m-line)}
:where(#pv-main) .list-group{--bs-list-group-border-color:var(--m-line);--bs-list-group-bg:var(--m-surface);--bs-list-group-color:var(--m-ink)}
:where(#pv-main) .list-group-item-action:hover,:where(#pv-main) .pv-list .list-group-item-action:hover{background:var(--m-light)}

/* Chipy filtrów, pigułki statystyk, zakładki Bootstrap → pivot-owe */
:where(#pv-main) .pv-hub-chip.pv-hub-chip,:where(#pv-main) .pv-stat-pill{background:var(--m-light);border:0;color:var(--m-ink);box-shadow:none}
:where(#pv-main) .pv-hub-chip.pv-hub-chip:hover,:where(#pv-main) .pv-stat-pill:hover{background:var(--m-light-h);color:var(--m-ink)}
:where(#pv-main) .pv-hub-chip.active,:where(#pv-main) .pv-hub-chip[aria-pressed="true"],:where(#pv-main) .pv-stat-pill--on{background:var(--m-acc);color:#fff}
:where(#pv-main) .nav-tabs{border-bottom:1px solid var(--m-line);gap:1.25rem}
:where(#pv-main) .nav-tabs .nav-link{border:0;border-bottom:3px solid transparent;background:none;color:var(--m-muted);font-weight:300;font-size:1.05rem;padding:.35rem 0 .55rem}
:where(#pv-main) .nav-tabs .nav-link.active{color:var(--m-ink);font-weight:600;border-bottom-color:var(--m-acc);background:none}
:where(#pv-main) .nav-pills .nav-link.active{background:var(--m-acc)}

/* Tabele nie ucinają kolumn: karta / kontener z tabelą przewija się w poziomie (pv_styles miał overflow:hidden). */
:where(#pv-main) :is(.card,.tz-card,.pv-card,.vol-detail-card,.vol-activity,.card-body,.tz-card__bd,.pv-card-bd):has(table){overflow-x:auto}
:where(#pv-main) .pv-table-wrap{overflow-x:auto}

/* Okna modalne */
.modal-content{border:0;box-shadow:0 20px 50px -20px rgba(0,0,0,.45)}
.modal-header{border-bottom:0}
.modal-footer{border-top:0}

/* Wysoki kontrast — wzmocnione ramki tam, gdzie Metro je zdejmuje */
[data-theme="hc"] :where(#pv-main) .card,[data-theme="hc"] :where(#pv-main) .tz-card,[data-theme="hc"] :where(#pv-main) .pv-card,
[data-theme="hc"] :where(#pv-main) .vol-detail-card,[data-theme="hc"] :where(#pv-main) .vol-activity,[data-theme="hc"] :where(#pv-main) .tz-tile,
[data-theme="hc"] :where(#pv-main) .tz-note,[data-theme="hc"] :where(#pv-main) .pv-note,[data-theme="hc"] :where(#pv-main) .pv-alert,[data-theme="hc"] :where(#pv-main) .tz-empty{border:2px solid #000!important}
[data-theme="hc"] :where(#pv-main) .tz-badge,[data-theme="hc"] :where(#pv-main) .pv-status-pill,[data-theme="hc"] :where(#pv-main) .pv-status-badge{background:#fff!important;color:#000!important;border:2px solid #000}
@media(prefers-reduced-motion:reduce){:where(#pv-main) *{transition:none!important;animation:none!important}}
</style>
<script>
/* Rozwijane menu pozycjonowane „fixed” — przewijany w poziomie kontener tabeli ich nie utnie (Bootstrap 5.3). */
document.addEventListener('DOMContentLoaded', function () {
  if (window.bootstrap && bootstrap.Dropdown && bootstrap.Dropdown.Default) {
    bootstrap.Dropdown.Default.popperConfig = function (cfg) { return Object.assign({}, cfg, { strategy: 'fixed' }); };
  }
});
</script>
