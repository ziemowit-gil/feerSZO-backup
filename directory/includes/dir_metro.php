<?php
/**
 * directory/includes/dir_metro.php — warstwa wizualna „Metro UI” Katalogu współpracowników.
 *
 * Ta sama szata co System Tożsamości, Panel współpracownika i Zadania: ciemny app-bar
 * #1d1d1d, Segoe UI, lekkie duże tytuły, ZERO zaokrągleń, cieni i gradientów, płaskie
 * szare powierzchnie, pełne kolory na znacznikach. Kolor modułu: Metro cobalt #0050ef
 * (tokeny --dir-* mają te same NAZWY, zmieniają się tylko wartości).
 *
 * Tylko CSS, ładowany przez header_dir.php jako ostatni blok <head>. Selektory zaczynają
 * się od `html body`, a część reguł ma !important, bo org_chart.php i org_unit_detail.php
 * mają własne <style>/style="" w treści. Znaczniki, ID i skrypty bez zmian.
 */
?>
<style>
:root{
  --dir-primary:#0050ef;--dir-primary-dark:#003bb3;--dir-primary-light:#7fa7f7;--dir-primary-bg:#e6eefd;
  --dir-accent:#00aba9;--dir-accent-bg:#e5f6f6;
  --dir-bg:#ffffff;--dir-card:#ffffff;--dir-border:#e1e1e1;
  --dir-text:#1d1d1d;--dir-text-muted:#5f5f5f;--dir-text-light:#767676;
  --dm-dark:#1d1d1d;--dm-light:#f2f2f2;--dm-light-h:#e6e6e6;--dm-line:#e1e1e1;--dm-field:#8a8a8a;
  --dm-red:#a20025;--dm-orange:#c25100;--dm-green:#007a00;--dm-teal:#00807e;
}
html body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;background:#fff;-webkit-font-smoothing:antialiased;color:var(--dir-text)}

/* Zero zaokrągleń, cieni i gradientów. Wyjątki: kontrolki okrągłe z natury, spinnery. */
html body .dir-topbar *:not(.form-check-input), html body .dir-sidebar *, html body .dir-content *:not(.form-check-input):not(.spinner-border):not(.form-range),
html body .dropdown-menu, html body .dropdown-menu *, html body .modal-content, html body .modal-content *:not(.form-check-input),
html body .dir-skip{border-radius:0!important}
html body .dir-content *:not(.dropdown-menu){box-shadow:none!important}
html body .dir-content [style*="gradient"], html body .dir-content .dir-profile-hero{background-image:none!important}
html body *:focus-visible{outline:3px solid var(--dir-primary)!important;outline-offset:2px!important}

/* ── App-bar ─────────────────────────────────────────────────────────────── */
html body .dir-topbar{background:var(--dm-dark);box-shadow:none;border-bottom:0}
html body .dir-topbar-brand{border-right:0;font-weight:600;letter-spacing:0}
html body .dir-topbar-brand-icon{background:var(--dir-primary);border:0}
html body .dir-topbar-brand-org{color:rgba(255,255,255,.65)}
html body .dir-topbar-breadcrumb{color:rgba(255,255,255,.65)}
html body .dir-topbar-breadcrumb .bi{color:#fff!important}
html body .dir-topbar-avatar{background:var(--dir-primary);border:0}
html body .dir-topbar-avatar:hover{background:#fff;color:var(--dm-dark)}
html body .dir-topbar-user > button[data-bs-target="#bugReportModal"]{border-radius:0!important;border:0!important;background:rgba(255,255,255,.08)!important}
html body .dropdown-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)}
html body .dropdown-item:active{background:var(--dir-primary)}

/* ── Menu boczne ─────────────────────────────────────────────────────────── */
html body .dir-sidebar{background:var(--dm-light);border-right:0}
html body .dir-sidebar-user{border-bottom:0;background:var(--dm-light-h)}
html body .dir-sidebar-user-avatar{background:var(--dir-primary);border:0}
html body .dir-sidebar-user-link{color:var(--dir-primary-dark);font-weight:600}
html body .dir-nav-section-label{letter-spacing:.14em;color:var(--dir-text-muted);padding-left:1rem}
html body .dir-nav-item{margin:0;padding:.6rem 1rem;color:var(--dir-text);font-weight:400}
html body .dir-nav-item:hover{background:var(--dm-light-h);color:var(--dir-text)}
html body .dir-nav-item:hover .dir-nav-icon{transform:none}
html body .dir-nav-item.active{background:var(--dir-primary);color:#fff;font-weight:600}
html body .dir-nav-item.active::before{display:none}
html body .dir-nav-item.active .dir-nav-icon{background:transparent;color:#fff;transform:none}
html body .dir-nav-icon{background:transparent!important;color:var(--t,var(--dir-primary))!important;font-size:1rem}
html body .dir-nav-item.active .dir-nav-icon{color:#fff!important}
html body .dir-nav-divider{background:var(--dm-line);margin:.4rem 0}
html body .dir-sidebar-bottom{border-top:1px solid var(--dm-line);padding:.4rem 0}
html body .dir-nav-badge{background:var(--dm-red);font-weight:700}
html body .dir-sidebar .dir-nav-item.active .dir-nav-badge{background:#fff;color:var(--dm-red)}

/* ── Treść ───────────────────────────────────────────────────────────────── */
html body .dir-content{padding:1.75rem 2rem}
html body .dir-content h1,html body .dir-content .h4{font-weight:300!important;font-size:2rem;letter-spacing:-.02em;line-height:1.1;color:var(--dir-text)!important}
html body .dir-content h1 .bi{display:none}
html body .dir-content h2,html body .dir-content .h5{font-weight:300;letter-spacing:-.01em}
html body .dir-footer{background:#fff;border-top:1px solid var(--dm-line);color:var(--dir-text-muted)}

/* Pasek wyszukiwania i przełącznik widoku */
html body .dir-search-bar{background:var(--dm-light);border:0;padding:1rem 1.1rem}
html body .dir-search-bar .input-group-text{border:2px solid var(--dm-field);border-right:0;background:#fff}
html body .dir-view-toggle .btn{border:2px solid var(--dir-text);color:var(--dir-text)}
html body .dir-view-toggle .btn.active,html body .dir-view-toggle .btn[aria-pressed="true"]{background:var(--dir-text);border-color:var(--dir-text);color:#fff}

/* Kafle osób — płaskie kafle Start: szare tło, kwadratowy avatar, hover = pełny kolor */
html body .dir-people-grid{--bs-gutter-x:.5rem;--bs-gutter-y:.5rem}
html body .dir-person-card{background:var(--dm-light);border:0;padding:1.25rem .9rem 1rem;transition:background .12s,color .12s}
html body .dir-person-card::before{display:none}
html body .dir-person-card:hover,html body .dir-person-card:focus-visible{background:var(--dir-primary);color:#fff;transform:none}
html body .dir-person-card:hover .dir-name,html body .dir-person-card:hover .dir-position,
html body .dir-person-card:hover .dir-unit,html body .dir-person-card:hover .dir-phone,
html body .dir-person-card:focus-visible .dir-name,html body .dir-person-card:focus-visible .dir-position,
html body .dir-person-card:focus-visible .dir-unit,html body .dir-person-card:focus-visible .dir-phone{color:#fff}
html body .dir-person-card .dir-name{font-weight:600;font-size:.95rem}
html body .dir-person-card .dir-unit{color:var(--dir-text-muted)}
html body .dir-person-card .dir-phone{color:var(--dm-green);font-weight:600}
html body .dir-people-grid.list-view > li{margin-bottom:2px}
html body .dir-people-grid.list-view .dir-person-card{padding:.6rem 1rem}
html body .dir-people-grid.list-view .dir-person-card:hover{transform:none!important}
html body .dir-avatar,html body .dir-avatar img{border-radius:0!important}

/* Profil — płaski pas zamiast gradientowego hero */
html body .dir-profile-hero{background:var(--dm-dark)!important;padding:1.5rem 1.75rem}
html body .dir-profile-hero::before,html body .dir-profile-hero::after{display:none}
html body .dir-profile-hero-avatar{border:0}
html body .dir-profile-hero-name{font-weight:300;font-size:2rem;letter-spacing:-.02em}
html body .dir-profile-hero-actions .btn{background:rgba(255,255,255,.1);border:0;font-weight:600}
html body .dir-profile-hero-actions .btn:hover{background:var(--dir-primary)}
html body .dir-profile-hero-actions .btn-edit{background:var(--dir-primary);color:#fff!important}
html body .dir-profile-hero-actions .btn-edit:hover{background:#fff;color:var(--dm-dark)!important}

/* Karty informacji — szare grupy, nagłówki wersalikami */
html body .dir-info-card,html body .dir-profile-sidebar,html body .oc-detail{background:var(--dm-light);border:0}
html body .dir-info-card > .h6,html body .dir-info-card > h6,html body .dir-info-card legend.h6{text-transform:uppercase;letter-spacing:.1em;font-size:.72rem!important;font-weight:700;color:var(--dir-text-muted)}
html body .dir-info-card > .h6 .bi,html body .dir-info-card legend.h6 .bi{color:var(--dir-text-muted)!important}
html body .dir-skill-tag{background:var(--dir-primary);color:#fff;border:0;font-weight:600}
html body .dir-skill-tag .btn-close,html body #skillsContainer .btn-close{filter:invert(1)}

/* Przyciski i pola */
html body .dir-content .btn{font-weight:600;box-shadow:none}
html body .dir-content .btn[style*="--dir-primary"],html body .dir-content .btn-primary{background:var(--dir-primary)!important;border:2px solid var(--dir-primary)!important;color:#fff!important}
html body .dir-content .btn[style*="--dir-primary"]:hover,html body .dir-content .btn-primary:hover{background:var(--dm-dark)!important;border-color:var(--dm-dark)!important}
html body .dir-content .btn-outline-secondary{--bs-btn-color:var(--dir-text);--bs-btn-border-color:var(--dir-text);--bs-btn-hover-bg:var(--dir-text);--bs-btn-hover-border-color:var(--dir-text);--bs-btn-hover-color:#fff;border-width:2px}
html body .dir-content .form-control,html body .dir-content .form-select,html body #skillsContainer{border:2px solid var(--dm-field)!important;box-shadow:none}
html body .dir-content .form-control:focus,html body .dir-content .form-select:focus{border-color:var(--dir-primary)!important;box-shadow:none}
html body .dir-search-bar .input-group .form-control{border-left:0!important}
html body .form-check-input:checked{background-color:var(--dir-primary);border-color:var(--dir-primary)}
html body .dir-content .alert{background:var(--dm-light);border:0;border-left:6px solid var(--dm-green);color:var(--dir-text)}
html body .dir-content .alert-danger{border-left-color:var(--dm-red)}
html body .dir-content .alert-warning{border-left-color:var(--dm-orange)}
html body .dir-content .alert-info{border-left-color:var(--dir-primary)}
html body .dir-content .badge{font-weight:700;letter-spacing:.03em}

/* Wymóg uzupełnienia profilu (profile_edit.php) */
html body .dir-req{color:var(--dm-red);font-weight:700}
html body .dir-req-note{display:flex;gap:.9rem;align-items:center;background:var(--dm-light);border-left:6px solid var(--dm-orange);padding:.85rem 1.1rem;margin-bottom:1rem;max-width:720px}
html body .dir-req-note.is-require{border-left-color:var(--dm-red)}
html body .dir-req-note > .bi{font-size:1.5rem;color:var(--dm-orange)}
html body .dir-req-note.is-require > .bi{color:var(--dm-red)}
html body .dir-req-note strong{display:block;font-weight:600}
html body .dir-req-note span{font-size:.86rem;color:var(--dir-text-muted)}

/* Struktura organizacyjna */
html body .oc-row,html body .oc-ext-card,html body .oc-member-card{border:0!important;background:var(--dm-light)}
html body .oc-row:hover,html body .oc-member-card:hover,html body .oc-ext-card:hover{background:var(--dm-light-h)}
html body .oc-row.focused,html body .oc-ext-card.focused{background:var(--dir-primary-bg);border-left:6px solid var(--dir-primary)!important}
html body .oc-children{border-left:2px solid var(--dm-line)}
html body .oc-icon,html body .oc-ext-icon{background:var(--dir-primary)!important;color:#fff!important}
html body .oc-count,html body .oc-ext-type{background:var(--dir-primary);color:#fff;border:0;font-weight:700}
html body .oc-code{background:#fff;color:var(--dir-text-muted)}
html body .oc-member-head-badge{background:var(--dm-orange);color:#fff;border:0}
html body .dir-content div[style*="background:#fff"][style*="border:1px solid"]{background:var(--dm-light)!important;border:0!important}

@media(max-width:768px){html body .dir-content{padding:1rem .75rem}html body .dir-content h1,html body .dir-content .h4{font-size:1.6rem}}
@media(prefers-reduced-motion:reduce){html body .dir-content *{transition:none!important;animation:none!important}}
</style>
