<?php
/**
 * directory/includes/dir_metro.php — powłoka „Metro UI” Katalogu współpracowników.
 *
 * Treść stron (kafle osób i jednostek, profil) to ekran Start z modules/katalog_metro
 * (kontener .pvm.ktm, zestaw modules/panel_metro). Tu zostaje tylko powłoka: ciemny
 * app-bar #1d1d1d, pivot nawigacji zamiast menu bocznego, Segoe UI, oraz płaski wygląd
 * formularzy edycji profilu (pola 2px, kwadratowe przyciski, alerty jako pas z lewą krawędzią).
 *
 * Ładowany przez header_dir.php jako ostatni blok <head>; selektory od `html body`,
 * żeby wygrać z directory-module.css. Tokeny --dir-* te same nazwy, wartości Metro.
 */
?>
<style>
:root{
  --dir-primary:#0050ef;--dir-primary-dark:#003bb3;--dir-primary-light:#7fa7f7;--dir-primary-bg:#e6eefd;
  --dir-accent:#00807e;--dir-bg:#ffffff;--dir-card:#ffffff;--dir-border:#e1e1e1;
  --dir-text:#1d1d1d;--dir-text-muted:#5f5f5f;--dir-text-light:#767676;
  --dir-sidebar-w:0px;--dir-pivot-h:48px;
  --dm-dark:#1d1d1d;--dm-light:#f2f2f2;--dm-light-h:#e6e6e6;--dm-line:#e1e1e1;--dm-field:#8a8a8a;
  --dm-red:#a20025;--dm-orange:#c25100;--dm-green:#007a00;
}
html body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;background:#fff;color:var(--dir-text);-webkit-font-smoothing:antialiased}
html body *:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px!important;border-radius:0!important}
html body .dir-topbar *, html body .dir-pivot *, html body .dir-content *:not(.form-check-input):not(.spinner-border):not(.form-range),
html body .dropdown-menu, html body .dropdown-menu *, html body .modal-content, html body .modal-content *:not(.form-check-input){border-radius:0!important}
html body .dir-content *:not(.dropdown-menu){box-shadow:none!important}

/* ── App-bar ─────────────────────────────────────────────────────────────── */
html body .dir-topbar{background:var(--dm-dark);box-shadow:none;border-bottom:0}
html body .dir-topbar-brand{width:auto;border-right:0;font-weight:600;letter-spacing:0;padding:0 1.25rem}
html body .dir-topbar-brand-icon{background:var(--dir-primary);border:0}
html body .dir-topbar-brand-org{color:rgba(255,255,255,.65)}
html body .dir-topbar-breadcrumb{color:rgba(255,255,255,.65)}
html body .dir-topbar-breadcrumb .bi{color:#fff!important}
html body .dir-topbar-avatar{background:var(--dir-primary);border:0}
html body .dir-topbar-avatar:hover{background:#fff;color:var(--dm-dark)}
html body .dir-topbar-user > button[data-bs-target="#bugReportModal"]{border:0!important;background:rgba(255,255,255,.08)!important}
html body .dropdown-menu{border:0;box-shadow:0 12px 32px -12px rgba(16,24,40,.35)}
html body .dropdown-item:active{background:var(--dir-primary)}

/* ── Pivot nawigacji (pod paskiem) ───────────────────────────────────────── */
html body .dir-pivot{position:fixed;top:var(--dir-topbar-h);left:0;right:0;z-index:1035;height:var(--dir-pivot-h);background:#fff;border-bottom:1px solid var(--dm-line);
  display:flex;align-items:stretch;gap:1.6rem;padding:0 2rem;overflow-x:auto;scrollbar-width:none}
html body .dir-pivot::-webkit-scrollbar{display:none}
html body .dir-pivot a{display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;text-decoration:none;color:var(--dir-text-muted);font-size:1.15rem;font-weight:300;
  border-bottom:3px solid transparent;margin-bottom:-1px;padding-top:3px}
html body .dir-pivot a:hover{color:var(--dir-text)}
html body .dir-pivot a.on{color:var(--dir-text);font-weight:600;border-bottom-color:var(--dir-primary)}
html body .dir-pivot .cnt{font-size:.7rem;font-weight:700;background:var(--dm-red);color:#fff;padding:1px 6px;line-height:1.4}

/* ── Powłoka bez menu bocznego ───────────────────────────────────────────── */
html body .dir-shell{margin-left:0;margin-top:calc(var(--dir-topbar-h) + var(--dir-pivot-h))}
html body .dir-content{padding:1.75rem 2rem 2.5rem;max-width:1384px;margin:0 auto}
html body .dir-footer{background:#fff;border-top:1px solid var(--dm-line);color:var(--dir-text-muted)}

/* ── Formularze (edycja profilu, komunikaty) ─────────────────────────────── */
html body .dir-content > nav .btn, html body .dir-content form .btn{font-weight:600}
html body .dir-content h1.h4{font-weight:300!important;font-size:2rem;letter-spacing:-.02em}
html body .dir-info-card{background:var(--dm-light);border:0}
html body .dir-info-card > .h6, html body .dir-info-card legend.h6{text-transform:uppercase;letter-spacing:.1em;font-size:.72rem!important;font-weight:700;color:var(--dir-text-muted)}
html body .dir-info-card > .h6 .bi, html body .dir-info-card legend.h6 .bi{color:var(--dir-text-muted)!important}
html body .dir-skill-tag{background:var(--dir-primary);color:#fff;border:0;font-weight:600}
html body #skillsContainer .btn-close{filter:invert(1)}
html body .dir-content .btn[style*="--dir-primary"], html body .dir-content .btn-primary{background:var(--dir-primary)!important;border:2px solid var(--dir-primary)!important;color:#fff!important}
html body .dir-content .btn[style*="--dir-primary"]:hover, html body .dir-content .btn-primary:hover{background:var(--dm-dark)!important;border-color:var(--dm-dark)!important}
html body .dir-content .btn-outline-secondary{--bs-btn-color:var(--dir-text);--bs-btn-border-color:var(--dir-text);--bs-btn-hover-bg:var(--dir-text);--bs-btn-hover-border-color:var(--dir-text);--bs-btn-hover-color:#fff;border-width:2px}
html body .dir-content .form-control, html body .dir-content .form-select, html body #skillsContainer{border:2px solid var(--dm-field)!important;box-shadow:none}
html body .dir-content .form-control:focus, html body .dir-content .form-select:focus{border-color:var(--dir-primary)!important}
html body .form-check-input:checked{background-color:var(--dir-primary);border-color:var(--dir-primary)}
html body .dir-content .alert{background:var(--dm-light);border:0;border-left:6px solid var(--dm-green);color:var(--dir-text)}
html body .dir-content .alert-danger{border-left-color:var(--dm-red)}
html body .dir-content .alert-warning{border-left-color:var(--dm-orange)}
html body .dir-content .alert-info{border-left-color:var(--dir-primary)}
html body .dir-avatar, html body .dir-avatar img, html body .dir-content img.rounded-circle{border-radius:0!important}

/* Wymóg uzupełnienia profilu (profile_edit.php) */
html body .dir-req{color:var(--dm-red);font-weight:700}
html body .dir-req-note{display:flex;flex-wrap:wrap;gap:.9rem;align-items:center;background:var(--dm-light);border-left:6px solid var(--dm-orange);padding:.85rem 1.1rem;margin-bottom:1rem;max-width:720px}
html body .dir-req-note.is-require{border-left-color:var(--dm-red)}
html body .dir-req-note > .bi{font-size:1.5rem;color:var(--dm-orange)}
html body .dir-req-note.is-require > .bi{color:var(--dm-red)}
html body .dir-req-note strong{display:block;font-weight:600}
html body .dir-req-note span{font-size:.86rem;color:var(--dir-text-muted)}

/* Drzewo struktury (zakładka „Drzewo” w org_chart.php) */
html body .oc-row{border:0!important;background:var(--dm-light)!important}
html body .oc-row:hover{background:var(--dm-light-h)!important}
html body .oc-node.focused > .oc-row{background:var(--dir-primary-bg)!important;border-left:6px solid var(--dir-primary)!important}
html body .oc-children{border-left:2px solid var(--dm-line)!important}
html body .oc-unit-icon{background:var(--dir-primary)!important;color:#fff!important}
html body .oc-count{background:var(--dir-primary);color:#fff;border:0;font-weight:700}

@media(max-width:768px){html body .dir-content{padding:1rem .75rem 2rem}html body .dir-pivot{padding:0 .75rem;gap:1.1rem}html body .dir-pivot a{font-size:1rem}}
@media(prefers-reduced-motion:reduce){html body .dir-content *{transition:none!important;animation:none!important}}
</style>
