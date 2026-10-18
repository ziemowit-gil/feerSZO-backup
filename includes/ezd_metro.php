<?php
/**
 * includes/ezd_metro.php — warstwa wizualna „Metro UI” modułu EZD (Wirtualne biurko).
 *
 * Ta sama szata co System Tożsamości, Panel współpracownika (pv_metro.php) i Zadania
 * (tasks_metro.php): ciemny app-bar #1d1d1d, Segoe UI, lekkie duże tytuły z kickerem,
 * ZERO zaokrągleń, cieni i gradientów, płaskie szare grupy #f2f2f2, karty z ramką 1px
 * i nagłówkiem w szarym pasie, kwadratowe przyciski/pola z ramką 2px, pełne kolory
 * palety (przyciemnionej do WCAG AA) na znacznikach, zakładki jak pivot, alerty jako
 * pas z grubą lewą krawędzią, żółty fokus. Akcent EZD = Metro blue #0f6fa8.
 *
 * Tylko CSS, ładowany przez includes/header.php jako ostatni blok <head> na stronach
 * powłoki EZD (po assets/css/ezd-tz-theme.css i app_bg.php). Selektory zaczynają się
 * od `html body`, bo Tailwind CDN wstrzykuje reguły (@apply klas ezx-*) dopiero po
 * załadowaniu — przy równej specyficzności wygrałby on. Treść w zakresie .ezd-main.
 * Znaczniki, ID, zaczepy JS (.sprawa-line .bulk-cb .ezd-sp-preview .sp-hide-btn #ksModal,
 * .ezd-rsign-btn, Alpine) bez zmian.
 */
?>
<style id="ezd-metro">
:root{
  --tz:#0f6fa8;--tz-strong:#0b5582;--tz-50:#e4eff6;--tz-line:#e1e1e1;--tz-muted:#5f5f5f;--tz-canvas:#ffffff;
  --em-acc:#0f6fa8;--em-acc-d:#0b5582;--em-dark:#1d1d1d;--em-light:#f2f2f2;--em-light-h:#e6e6e6;--em-line:#e1e1e1;
  --em-muted:#5f5f5f;--em-field:#8a8a8a;--em-ink:#111827;
  --m-blue:#0f6fa8;--m-green:#007a00;--m-orange:#c25100;--m-red:#a20025;--m-teal:#00807e;--m-violet:#6a00ff;
  --m-magenta:#d80073;--m-indigo:#0050ef;--m-grey:#5f5f5f;
}
html body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif!important;background:#fff!important;background-image:none!important;color:var(--em-ink);-webkit-font-smoothing:antialiased}
html body *:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px;box-shadow:none!important}

/* ── Zero zaokrągleń, cieni i gradientów. Wyjątki: kontrolki okrągłe z natury, spinnery, kropki osi czasu. ── */
html body .ezd-content-host *:not(.form-check-input):not(.spinner-border):not(.spinner-grow):not(.rounded-circle):not(.form-range):not(.activity-dot):not(.tl-dot):not(.ezd-step-dot):not(.ezdd-cal-today):not(.tw-rounded-full),
html body .modal-content,html body .modal-content *:not(.form-check-input):not(.rounded-circle):not(.spinner-border),
html body .offcanvas,html body .offcanvas *:not(.form-check-input):not(.rounded-circle),
html body .dropdown-menu,html body .dropdown-menu *,html body .toast,html body .popover,html body .tooltip-inner{border-radius:0!important}
html body .ezd-content-host *:not(.form-check-input):not(.form-range){box-shadow:none!important}
html body .ezd-main .ezd-hero,html body .ezd-main [class*="tw-bg-gradient"],html body .ezd-main .bg-gradient{background-image:none!important}
html body .ezd-main .tw-backdrop-blur,html body .ezx-overlay{backdrop-filter:none!important}

/* ── App-bar ─────────────────────────────────────────────────────────── */
html body .ezd-topbar{background:var(--em-dark);border-bottom:0;height:48px}
html body .ezd-tb-back,html body .ezd-tb-btn,html body .ezd-tb-avatar-btn,html body .ezd-topbar-menu{background:transparent;border:0;color:rgba(255,255,255,.85)}
html body .ezd-tb-back:hover,html body .ezd-tb-btn:hover,html body .ezd-tb-avatar-btn:hover,html body .ezd-topbar-menu:hover,
html body .ezd-tb-avatar-btn[aria-expanded="true"]{background:rgba(255,255,255,.1);color:#fff}
html body .ezd-tb-icon,html body .ezd-topbar-icon{background:var(--em-acc);color:#fff;width:32px;height:32px}
html body .ezd-tb-label{color:#fff;font-weight:700;letter-spacing:-.01em}
html body .ezd-tb-badge{background:var(--em-acc);color:#fff;border:0;letter-spacing:.08em}
html body .ezd-tb-divider{background:rgba(255,255,255,.18)}
html body .ezd-topbar-ptitle{color:rgba(255,255,255,.7);font-weight:400}
html body .ezd-topbar-avatar{background:var(--em-acc);color:#fff;font-weight:700}
html body .ezd-topbar .badge.bg-danger{background:var(--m-red)!important}
html body .ezd-topbar .dropdown-menu{border:0}
html body .ezd-topbar .dropdown-item.text-danger{color:var(--m-red)!important}

/* ── Menu boczne ─────────────────────────────────────────────────────── */
html body .ezd-sidebar{background:var(--em-light);border-right:0}
html body .ezd-sb-header{background:var(--em-light);border-bottom:1px solid var(--em-line)}
html body .ezd-sb-mod-icon{background:var(--em-acc);color:#fff}
html body .ezd-sb-mod-name{font-weight:300;font-size:1.05rem;letter-spacing:-.01em;color:var(--em-ink)}
html body .ezd-sb-mod-tag{background:transparent;color:var(--em-muted);padding:0;letter-spacing:.18em;font-weight:600}
html body .ezd-sb-actions .btn-primary{--bs-btn-bg:var(--em-acc);--bs-btn-border-color:var(--em-acc);--bs-btn-hover-bg:var(--em-dark);--bs-btn-hover-border-color:var(--em-dark);font-weight:600;min-height:38px}
html body .ezd-sb-nav{padding-left:0;padding-right:0}
html body .ezd-sb-link{margin:0;padding:.55rem 1.1rem;color:var(--em-ink);border-left:0;font-weight:400}
html body .ezd-sb-link .bi{color:var(--em-muted)}
html body .ezd-sb-link:hover{background:var(--em-light-h);color:var(--em-ink)}
html body .ezd-sb-link:hover .bi{color:var(--em-ink)}
html body .ezd-sb-link.active{background:var(--em-acc);color:#fff;font-weight:600}
html body .ezd-sb-link.active .bi{color:#fff}
html body .ezd-sb-link.active::after{display:none}
html body .ezd-sb-link.active .ezd-sb-badge{background:#fff;color:var(--em-acc)}
html body .ezd-sb-section{letter-spacing:.14em;color:var(--em-muted);font-weight:600;padding-left:1.1rem}
html body .ezd-sb-badge{background:var(--m-red);color:#fff;font-weight:700}
html body .ezd-sb-foot{border-top:1px solid var(--em-line)}
html body .ezd-sb-back{color:var(--em-ink)}

/* ── Treść: typografia ───────────────────────────────────────────────── */
html body .ezd-main{background:#fff}
/* Tytuł strony = pierwszy nagłówek strony poza kartami/modalami: kicker wersalikami + lekki duży h1 */
html body .ezd-main h1:not(.modal-title):not(.card h1):not(.modal h1):not(.ezx-card h1):not(.offcanvas h1),
html body .ezd-main h4.fw-bold:not(.modal-title):not(.card h4):not(.modal h4):not(.ezd-card h4):not(.ezx-card h4):not(.bc h4):not(.offcanvas h4):not(table h4),
html body .ezd-main > .d-flex > h4.mb-0,html body .ezd-main > div > .d-flex > div > h4,
html body .ezd-main > .d-flex > h5.fw-bold,html body .ezd-main > .d-flex > div > h5.fw-bold:not(.card h5),
html body .ezd-main > .d-flex > div > h5.fw-semibold{
  font-size:clamp(1.7rem,3.4vw,2.4rem)!important;font-weight:300!important;letter-spacing:-.02em!important;line-height:1.08!important;color:var(--em-ink)!important}
html body .ezd-main h1:not(.modal-title):not(.card h1):not(.modal h1):not(.ezx-card h1):not(.offcanvas h1)::before,
html body .ezd-main h4.fw-bold:not(.modal-title):not(.card h4):not(.modal h4):not(.ezd-card h4):not(.ezx-card h4):not(.bc h4):not(.offcanvas h4):not(table h4)::before,
html body .ezd-main > .d-flex > h4.mb-0::before,html body .ezd-main > div > .d-flex > div > h4::before,
html body .ezd-main > .d-flex > h5.fw-bold::before,html body .ezd-main > .d-flex > div > h5.fw-bold:not(.card h5)::before,
html body .ezd-main > .d-flex > div > h5.fw-semibold::before{
  content:"Wirtualne biurko · EZD";display:block;font-size:.72rem;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:var(--em-muted);margin-bottom:.45rem;font-family:inherit}
html body .ezd-main .ezx-eyebrow + h1::before,html body .ezd-main #sp-title::before{content:none!important}
html body .ezd-main .ezx-eyebrow{font-size:.72rem!important;font-weight:600!important;letter-spacing:.18em!important;color:var(--em-muted)!important}
html body .ezd-main .ezx-h2{font-weight:600}
html body .ezd-main h1 > .bi:first-child,html body .ezd-main h4.fw-bold > .bi:first-child,html body .ezd-main h5.fw-bold > .bi:first-child{color:var(--em-acc)!important;font-size:.8em}
html body .ezd-main h1 .font-monospace,html body .ezd-main h4 .font-monospace{font-weight:400}
html body .ezd-main h2,html body .ezd-main h3{letter-spacing:-.01em}
html body .ezd-main .text-primary{color:var(--em-acc)!important}
html body .ezd-main .text-danger{color:var(--m-red)!important}
html body .ezd-main .text-success{color:var(--m-green)!important}
html body .ezd-main .text-warning{color:var(--m-orange)!important}
html body .ezd-main .text-muted,html body .ezd-main .text-secondary{color:var(--em-muted)!important}
html body .ezd-main a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link):not(.list-group-item):not([class*="ezx-"]):not(.mt-tile){color:var(--em-acc-d)}
html body .breadcrumb{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em}

/* ── Karty i powierzchnie ────────────────────────────────────────────── */
html body .ezd-main .card,html body .ezd-main .ezd-card,html body .ezd-main .ezx-card,html body .ezd-main .bc,
html body .ezd-main .tl-card,html body .ezd-main .ezd-stat,html body .ezd-main .ezd-launcher-tile,html body .ezd-main .sp-meta-bar,
html body .ezd-main .sp-etap-bar,html body .ezd-main .ezd-hero,html body .modal .card{border:1px solid var(--em-line)!important;background-color:#fff}
html body .ezd-main .card-header,html body .ezd-main .ezd-card-header,html body .ezd-main .ezx-card-hd,html body .ezd-main .bc-h,html body .modal .card-header{
  background:var(--em-light)!important;border-bottom:0!important;color:var(--em-ink);font-weight:600}
html body .ezd-main .card-header .bi,html body .ezd-main .ezd-card-header .bi,html body .ezd-main .bc-h .bi{color:var(--em-acc)}
html body .ezd-main .card-footer{background:var(--em-light);border-top:0}
html body .ezd-main .ezd-hero{background:var(--em-light)!important;border:0!important}
html body .ezd-main .ezd-hero-icon{background:var(--em-acc)}
html body .ezd-main .tl-card{border-left-width:6px!important}
html body .ezd-main .tl-card.pismo-out{border-left-color:var(--m-blue)!important}
html body .ezd-main .tl-card.pismo-in{border-left-color:var(--m-teal)!important}
html body .ezd-main .tl-card.umowa-card{border-left-color:var(--m-orange)!important}
html body .ezd-main .tl-dot{background:var(--m-blue)}
html body .ezd-main .tl-dot.pismo-in{background:var(--m-teal)}
html body .ezd-main .tl-dot.umowa-card{background:var(--m-orange)}
html body .ezd-main .ezd-stat-icon,html body .ezd-main .ezd-launcher-icon,html body .ezd-main .ezx-ico,html body .ezd-main .ezx-qa-ico{border-radius:0!important}
html body .ezd-main .ezd-stat:hover,html body .ezd-main .ezd-launcher-tile:hover,html body .ezd-main .tl-card:hover{border-color:var(--em-ink)!important;transform:none}
html body .ezd-main .ezd-stat-val{font-weight:300;font-size:1.9rem;letter-spacing:-.02em}
html body .ezd-main .bg-light{background-color:var(--em-light)!important}
html body .ezd-main .border,html body .ezd-main .border-top,html body .ezd-main .border-bottom{border-color:var(--em-line)!important}

/* ── Przyciski — kwadratowe, ramka 2px ───────────────────────────────── */
html body .ezd-main .btn,html body .modal .btn,html body .offcanvas .btn{font-weight:600;border-width:2px}
html body .ezd-main .btn-primary,html body .modal .btn-primary,html body .offcanvas .btn-primary{--bs-btn-bg:var(--em-acc);--bs-btn-border-color:var(--em-acc);--bs-btn-hover-bg:var(--em-dark);--bs-btn-hover-border-color:var(--em-dark);--bs-btn-active-bg:var(--em-dark);--bs-btn-active-border-color:var(--em-dark);--bs-btn-disabled-bg:var(--em-acc);--bs-btn-disabled-border-color:var(--em-acc)}
html body .ezd-main .btn-success,html body .modal .btn-success{--bs-btn-bg:var(--m-green);--bs-btn-border-color:var(--m-green);--bs-btn-hover-bg:#005c00;--bs-btn-hover-border-color:#005c00}
html body .ezd-main .btn-danger,html body .modal .btn-danger{--bs-btn-bg:var(--m-red);--bs-btn-border-color:var(--m-red);--bs-btn-hover-bg:#7a001c;--bs-btn-hover-border-color:#7a001c}
html body .ezd-main .btn-warning,html body .modal .btn-warning{--bs-btn-bg:var(--m-orange);--bs-btn-border-color:var(--m-orange);--bs-btn-color:#fff;--bs-btn-hover-bg:#9a4000;--bs-btn-hover-border-color:#9a4000;--bs-btn-hover-color:#fff}
html body .ezd-main .btn-info,html body .modal .btn-info{--bs-btn-bg:var(--m-teal);--bs-btn-border-color:var(--m-teal);--bs-btn-color:#fff;--bs-btn-hover-bg:#005f5d;--bs-btn-hover-border-color:#005f5d;--bs-btn-hover-color:#fff}
html body .ezd-main .btn-dark,html body .modal .btn-dark{--bs-btn-bg:var(--em-dark);--bs-btn-border-color:var(--em-dark)}
html body .ezd-main .btn-outline-secondary,html body .modal .btn-outline-secondary,html body .offcanvas .btn-outline-secondary,html body .ezd-main .btn-light,html body .modal .btn-light{--bs-btn-color:var(--em-ink);--bs-btn-bg:transparent;--bs-btn-border-color:var(--em-ink);--bs-btn-hover-bg:var(--em-ink);--bs-btn-hover-border-color:var(--em-ink);--bs-btn-hover-color:#fff;--bs-btn-active-bg:var(--em-ink);--bs-btn-active-color:#fff}
html body .ezd-main .btn-outline-primary,html body .modal .btn-outline-primary{--bs-btn-color:var(--em-acc);--bs-btn-border-color:var(--em-acc);--bs-btn-hover-bg:var(--em-acc);--bs-btn-hover-border-color:var(--em-acc);--bs-btn-hover-color:#fff;--bs-btn-active-bg:var(--em-acc);--bs-btn-active-color:#fff}
html body .ezd-main .btn-outline-success,html body .modal .btn-outline-success{--bs-btn-color:var(--m-green);--bs-btn-border-color:var(--m-green);--bs-btn-hover-bg:var(--m-green);--bs-btn-hover-border-color:var(--m-green)}
html body .ezd-main .btn-outline-danger,html body .modal .btn-outline-danger{--bs-btn-color:var(--m-red);--bs-btn-border-color:var(--m-red);--bs-btn-hover-bg:var(--m-red);--bs-btn-hover-border-color:var(--m-red)}
html body .ezd-main .btn-outline-warning,html body .modal .btn-outline-warning{--bs-btn-color:var(--m-orange);--bs-btn-border-color:var(--m-orange);--bs-btn-hover-bg:var(--m-orange);--bs-btn-hover-border-color:var(--m-orange);--bs-btn-hover-color:#fff}
html body .ezd-main .btn-outline-dark{--bs-btn-color:var(--em-ink);--bs-btn-border-color:var(--em-ink)}
html body .ezd-main .btn-link{border-width:0}
html body .ezd-main .btn-group>.btn{border-width:2px}
/* przyciski pulpitu / koszulek (Tailwind ezx-*) */
html body .ezd-main .ezx-btn,html body .ezx-drawer .ezx-btn,html body .ezx-modal .ezx-btn{background:var(--em-acc);color:#fff;border:2px solid var(--em-acc);font-weight:600}
html body .ezd-main .ezx-btn:hover,html body .ezx-drawer .ezx-btn:hover,html body .ezx-modal .ezx-btn:hover{background:var(--em-dark);border-color:var(--em-dark);color:#fff}
html body .ezd-main .ezx-btn--ghost,html body .ezx-drawer .ezx-btn--ghost,html body .ezx-modal .ezx-btn--ghost{background:transparent;color:var(--em-ink);border-color:var(--em-ink)}
html body .ezd-main .ezx-btn--ghost:hover,html body .ezx-drawer .ezx-btn--ghost:hover,html body .ezx-modal .ezx-btn--ghost:hover{background:var(--em-ink);color:#fff;border-color:var(--em-ink)}
html body .ezd-main .ezx-btn--amber,html body .ezx-modal .ezx-btn--amber{background:var(--m-orange);border-color:var(--m-orange)}
html body .ezd-main .ezx-btn--emerald,html body .ezx-modal .ezx-btn--emerald{background:var(--m-green);border-color:var(--m-green)}
html body .ezd-main .ezx-btn--danger,html body .ezx-modal .ezx-btn--danger{background:transparent;color:var(--m-red);border-color:var(--m-red)}
html body .ezd-main .ezx-btn--danger:hover,html body .ezx-modal .ezx-btn--danger:hover{background:var(--m-red);color:#fff}

/* ── Pola ────────────────────────────────────────────────────────────── */
html body .ezd-main .form-control,html body .ezd-main .form-select,html body .modal .form-control,html body .modal .form-select,
html body .offcanvas .form-control,html body .offcanvas .form-select,html body .ezd-main .input-group-text,html body .modal .input-group-text{border:2px solid var(--em-field)}
html body .ezd-main .form-control:focus,html body .ezd-main .form-select:focus,html body .modal .form-control:focus,html body .modal .form-select:focus,
html body .offcanvas .form-control:focus,html body .offcanvas .form-select:focus{border-color:var(--em-acc)}
html body .ezd-main .input-group-text{background:var(--em-light)}
html body .form-check-input:checked{background-color:var(--em-acc);border-color:var(--em-acc)}
html body .ezd-main .form-label,html body .modal .form-label{font-weight:600}
html body .ts-wrapper .ts-control,html body .ts-dropdown,html body .select2-container .select2-selection{border-radius:0!important}
html body .ts-wrapper .ts-control{border:2px solid var(--em-field)}
html body .ezd-main form[role="search"] > div{border:2px solid var(--em-field)!important;background:#fff}
html body .ezd-main form[role="search"] > div:focus-within{border-color:var(--em-acc)!important}

/* ── Znaczniki — pełne kolory palety, wersaliki ──────────────────────── */
html body .ezd-main .badge,html body .modal .badge,html body .ezd-sidebar .badge{font-weight:700;letter-spacing:.03em}
html body .ezd-main .badge.bg-primary,html body .modal .badge.bg-primary,html body .ezd-main .text-bg-primary{background-color:var(--m-blue)!important;color:#fff!important}
html body .ezd-main .badge.bg-success,html body .modal .badge.bg-success,html body .ezd-main .text-bg-success{background-color:var(--m-green)!important;color:#fff!important}
html body .ezd-main .badge.bg-danger,html body .modal .badge.bg-danger,html body .ezd-main .text-bg-danger{background-color:var(--m-red)!important;color:#fff!important}
html body .ezd-main .badge.bg-warning,html body .modal .badge.bg-warning,html body .ezd-main .text-bg-warning{background-color:var(--m-orange)!important;color:#fff!important}
html body .ezd-main .badge.bg-info,html body .modal .badge.bg-info,html body .ezd-main .text-bg-info{background-color:var(--m-teal)!important;color:#fff!important}
html body .ezd-main .badge.bg-secondary,html body .modal .badge.bg-secondary,html body .ezd-main .text-bg-secondary{background-color:var(--m-grey)!important;color:#fff!important}
html body .ezd-main .badge.bg-dark,html body .ezd-main .text-bg-dark{background-color:var(--em-dark)!important}
html body .ezd-main .badge.bg-light{background-color:var(--em-light)!important;color:var(--em-ink)!important;border-color:var(--em-line)!important}
html body .ezd-main .ezx-badge{border:0!important;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff!important;background:var(--m-grey)}
html body .ezd-main .ezx-badge--amber{background:var(--m-orange)}
html body .ezd-main .ezx-badge--violet{background:var(--m-violet)}
html body .ezd-main .ezx-badge--emerald{background:var(--m-green)}
html body .ezd-main .ezx-badge--sky{background:var(--m-teal)}
html body .ezd-main .ezx-badge--blue{background:var(--m-blue)}
html body .ezd-main .ezx-badge--rose,html body .ezd-main .ezx-badge--urgent{background:var(--m-red)}
html body .ezd-main .ezx-badge--slate{background:var(--m-grey)}
html body .ezd-main .ezx-chip{border:2px solid var(--em-line)!important;background:#fff}
html body .ezd-main .ezx-chip:hover{border-color:var(--em-ink)!important}
html body .ezd-main .ezx-chip[aria-pressed="true"]{background:var(--em-acc);border-color:var(--em-acc)!important;color:#fff}

/* ── Tabele / listy ──────────────────────────────────────────────────── */
html body .ezd-main .table{--bs-table-border-color:var(--em-line);--bs-table-hover-bg:var(--em-light);--bs-table-striped-bg:#fafafa}
html body .ezd-main .table > thead th,html body .ezd-main .table-light th,html body .ezd-main .ezx-table th{text-transform:uppercase;font-size:.68rem;letter-spacing:.1em;color:var(--em-muted)!important;background:#fff!important;border-bottom:2px solid var(--em-ink)!important;font-weight:600}
html body .ezd-main .table-light{--bs-table-bg:#fff}
html body .ezd-main .list-group{--bs-list-group-border-color:var(--em-line)}
html body .ezd-main .list-group-item.active{background:var(--em-acc);border-color:var(--em-acc)}
html body .ezd-main .page-link{color:var(--em-ink);border:2px solid var(--em-line)}
html body .ezd-main .page-item.active .page-link{background:var(--em-acc);border-color:var(--em-acc);color:#fff}
html body .ezd-main .ezx-item:hover,html body .ezd-main .ezd-row-link:hover,html body .ezd-main .dekr-row:hover{background:var(--em-light)}
html body .ezd-main .ezx-item--urgent{border-left:6px solid var(--m-red)!important}

/* ── Zakładki → pivot ───────────────────────────────────────────────── */
html body .ezd-main .nav-tabs,html body .modal .nav-tabs{border-bottom:1px solid var(--em-line);gap:1.4rem;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none}
html body .ezd-main .nav-tabs .nav-link,html body .modal .nav-tabs .nav-link{border:0;border-bottom:3px solid transparent;margin-bottom:-1px;background:none;color:var(--em-muted);font-weight:300;font-size:1.08rem;padding:.35rem 0 .55rem;white-space:nowrap}
html body .ezd-main .nav-tabs .nav-link:hover,html body .modal .nav-tabs .nav-link:hover{color:var(--em-ink)}
html body .ezd-main .nav-tabs .nav-link.active,html body .modal .nav-tabs .nav-link.active{color:var(--em-ink);font-weight:600;border-bottom-color:var(--em-acc);background:none}
html body .ezd-main .nav-pills .nav-link{color:var(--em-ink)}
html body .ezd-main .nav-pills .nav-link.active{background:var(--em-acc);color:#fff}
html body .ezd-main [role="tablist"]:has(> .ezx-tab){background:transparent!important;border:0!important;border-bottom:1px solid var(--em-line)!important;padding:0!important;gap:1.4rem!important;display:flex!important;width:100%;flex-wrap:nowrap!important;overflow-x:auto;scrollbar-width:none}
html body .ezd-main .ezx-tab{background:none!important;border:0;border-bottom:3px solid transparent;margin-bottom:-1px;padding:.35rem 0 .6rem;font-size:1.2rem;font-weight:300;color:var(--em-muted);letter-spacing:-.01em}
html body .ezd-main .ezx-tab:hover{color:var(--em-ink)}
html body .ezd-main .ezx-tab[aria-selected="true"]{color:var(--em-ink)!important;font-weight:600;border-bottom-color:var(--em-acc)}
html body .ezd-main .ezx-tab > .bi{display:none}
html body .ezd-main .ezx-tab span{border-radius:0!important}
html body .ezd-main .ezx-subtab[aria-selected="true"]{color:var(--em-ink);border-bottom-color:var(--em-acc);border-bottom-width:3px}

/* ── Alerty — pas z grubą lewą krawędzią ─────────────────────────────── */
html body .ezd-main .alert,html body .modal .alert{background:var(--em-light);border:0;border-left:6px solid var(--em-acc);color:var(--em-ink)}
html body .ezd-main .alert-success,html body .modal .alert-success{border-left-color:var(--m-green)}
html body .ezd-main .alert-danger,html body .modal .alert-danger{border-left-color:var(--m-red)}
html body .ezd-main .alert-warning,html body .modal .alert-warning{border-left-color:var(--m-orange)}
html body .ezd-main .alert-info,html body .modal .alert-info{border-left-color:var(--m-teal)}
html body .ezd-main .alert-secondary,html body .ezd-main .alert-light{border-left-color:var(--m-grey)}
html body .ezd-main .alert .alert-link{color:var(--em-ink)}

/* ── Okna modalne, szuflady, menu ────────────────────────────────────── */
html body .modal-content{border:0;box-shadow:0 20px 50px -20px rgba(0,0,0,.45)!important}
html body .modal-header{background:var(--em-light);border-bottom:0}
html body .modal-header .modal-title{font-weight:600}
html body .modal-footer{border-top:1px solid var(--em-line)}
html body .ezx-drawer,html body .ezx-modal-box{box-shadow:0 20px 50px -20px rgba(0,0,0,.45)!important}
html body .ezx-drawer,html body .ezx-drawer *,html body .ezx-modal-box,html body .ezx-modal-box *{border-radius:0!important}
html body .ezx-modal-box > :first-child{background:var(--em-light)}
html body .ezx-kv{background:var(--em-light)!important;border:0!important}
html body .dropdown-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)!important}
html body .dropdown-item:active,html body .dropdown-item.active{background:var(--em-acc);color:#fff}

/* ── Postęp, stepper, oś czasu ───────────────────────────────────────── */
html body .ezd-main .progress{background:var(--em-line)}
html body .ezd-main .progress-bar{background-color:var(--em-acc)}
html body .ezd-main .ezx-step{background:var(--em-line)}
html body .ezd-main .ezx-step--done{background:var(--m-green)}
html body .ezd-main .ezx-step--current{background:var(--em-acc)}
html body .ezd-main .ezd-step-current .ezd-step-dot{background:var(--em-acc);border-color:var(--em-acc)}
html body .ezd-main .ezd-step-done .ezd-step-dot{background:var(--m-green);border-color:var(--m-green)}

/* ── Kafle Start (pulpit i inne ekrany) ──────────────────────────────── */
html body .ezd-main .mt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));grid-auto-rows:130px;gap:8px;margin:0}
@media(max-width:480px){html body .ezd-main .mt-grid{grid-template-columns:repeat(2,1fr);grid-auto-rows:120px}}
html body .ezd-main .mt-tile{position:relative;display:flex;flex-direction:column;justify-content:space-between;padding:12px 14px;color:#fff!important;text-decoration:none;background:var(--c,var(--m-blue));border:0;text-align:left;font:inherit;cursor:pointer;min-width:0;overflow:hidden;transition:filter .12s,transform .08s}
html body .ezd-main .mt-tile:hover{filter:brightness(1.12);color:#fff}
html body .ezd-main .mt-tile:active{transform:scale(.97)}
html body .ezd-main .mt-tile:focus-visible{outline-offset:-3px!important}
html body .ezd-main .mt-tile--w{grid-column:span 2}
html body .ezd-main .mt-tile .ic{font-size:1.75rem;line-height:1}
html body .ezd-main .mt-tile .val{display:block;font-size:1.9rem;font-weight:300;line-height:1;letter-spacing:-.02em;margin-bottom:.15rem}
html body .ezd-main .mt-tile .lbl{display:block;font-size:.82rem;font-weight:600;line-height:1.2}
html body .ezd-main .mt-tile .sub{display:block;font-size:.72rem;opacity:.92;margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
html body .ezd-main .mt-tile .sub *{color:#fff!important}
html body .ezd-main .mt-tile .tag{position:absolute;top:8px;right:10px;font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:rgba(0,0,0,.32);padding:2px 7px}
html body .ezd-main .mt-kicker{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:var(--em-muted);margin-bottom:.45rem}
html body .ezd-main .mt-lead{font-size:.95rem;color:var(--em-muted);font-weight:300;margin:.5rem 0 0}
html body .ezd-main .mt-group{background:var(--em-light);padding:1.1rem;margin-bottom:1rem}

/* ── Stopka ─────────────────────────────────────────────────────────── */
html body .ezd-footer{background:#fff!important;border-top:1px solid var(--em-line)!important;color:var(--em-muted)}
@media(prefers-reduced-motion:reduce){html body .ezd-main *{transition:none!important;animation:none!important}}
</style>
