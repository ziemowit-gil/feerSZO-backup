<?php
/**
 * Wspólny nagłówek panelu kursanta/rodzica — Bootstrap 5.3 (dark) + WCAG 2.1 AA.
 * Zmienne wejściowe (opcjonalne):
 *   $KP_TITLE      — tytuł strony,
 *   $KP_TOPBAR     — ['brand'=>, 'icon'=>, 'user'=>, 'logout'=>] lub null (brak paska),
 *   $KP_BODY_CLASS — dodatkowe klasy <body>.
 */
$KP_ORG        = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$KP_TITLE      = $KP_TITLE      ?? 'Panel kursanta';
$KP_TOPBAR     = $KP_TOPBAR     ?? null;
$KP_BODY_CLASS = $KP_BODY_CLASS ?? '';
?><!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- Motyw + dostępność: zastosuj zapamiętane ustawienia przed renderem (bez mignięcia) -->
<script>try{var d=document.documentElement;
if(localStorage.getItem('kp-contrast')==='1')d.classList.add('kp-contrast');
var bgc=localStorage.getItem('kp-bg-color');if(bgc)document.documentElement.style.setProperty('--kp-custom-bg',bgc);
var fs=localStorage.getItem('kp-fontscale');if(fs&&fs!=='0')d.setAttribute('data-kp-font',fs);
if(localStorage.getItem('kp-hidemenu')==='1')d.classList.add('kp-hidemenu');
}catch(e){}</script>
<title><?= h($KP_TITLE) ?> — <?= h($KP_ORG) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --bs-primary:#2563eb; --bs-primary-rgb:37,99,235; --bs-link-color-rgb:96,165,250; --kp-custom-bg:; }
body { background-color:var(--kp-custom-bg, var(--bs-body-bg)) !important; }
.btn-primary { --bs-btn-bg:#2563eb; --bs-btn-border-color:#2563eb; --bs-btn-hover-bg:#1d4ed8; --bs-btn-hover-border-color:#1d4ed8; }
body { min-height:100vh; }
/* WCAG: widoczny, spójny focus dla klawiatury */
a:focus-visible, button:focus-visible, .btn:focus-visible,
.form-control:focus-visible, .nav-link:focus-visible, [tabindex]:focus-visible {
  outline:3px solid #60a5fa; outline-offset:2px; box-shadow:none;
}
/* WCAG 2.4.1: link „przejdź do treści" */
.skip-link { position:absolute; left:.5rem; top:.5rem; z-index:1080; transform:translateY(-200%); transition:transform .15s ease; }
.skip-link:focus { transform:translateY(0); }
.kp-brand i { color:#60a5fa; }
.nav-tabs .nav-link.active { font-weight:600; }

/* ── Ekran logowania (login.php / parent.php) ───────────────────────────── */
.kp-auth-wrap { width:100%; max-width:920px; }
.kp-auth-card { overflow:hidden; border-radius:1rem; }
/* Panel marki (lewa kolumna) — dekoracyjny gradient + lista korzyści */
.kp-auth-hero {
  background:linear-gradient(150deg,#1e3a8a 0%,#2563eb 45%,#7c3aed 100%);
  color:#fff; position:relative;
}
.kp-auth-hero::after {
  content:""; position:absolute; inset:0; pointer-events:none;
  background:radial-gradient(circle at 80% 15%, rgba(255,255,255,.18), transparent 45%),
             radial-gradient(circle at 10% 95%, rgba(255,255,255,.10), transparent 40%);
}
.kp-auth-hero > * { position:relative; z-index:1; }
.kp-auth-logo {
  width:64px; height:64px; border-radius:1rem;
  background:rgba(255,255,255,.16); backdrop-filter:blur(4px);
}
.kp-auth-feat { display:flex; gap:.65rem; align-items:flex-start; }
.kp-auth-feat i { font-size:1.15rem; opacity:.95; flex-shrink:0; margin-top:.1rem; }
/* Wzmocnione pole formularza dla większej czytelności (WCAG 1.4.11 — granice) */
.kp-auth-card .form-control { padding:.6rem .85rem; }
.kp-auth-card .form-control-lg { font-size:1rem; }
@media (max-width:767.98px){
  .kp-auth-hero { display:none !important; } /* na telefonie tylko formularz */
}
/* ── Zwijane oddane zadania (Dydaktyka / eLearning) ──────────────────────── */
.dyd-hw-summary { cursor:pointer; list-style:none; }
.dyd-hw-summary::-webkit-details-marker { display:none; }   /* Safari/Chrome */
.dyd-hw-summary::marker { content:""; }                      /* Firefox */
.dyd-hw-summary:hover { background:var(--bs-tertiary-bg); }
.dyd-hw-summary:focus-visible { outline:3px solid #60a5fa; outline-offset:-3px; }
.dyd-hw-chevron { transition:transform .15s ease; }
.dyd-hw[open] > .dyd-hw-summary .dyd-hw-chevron { transform:rotate(180deg); }
@media (prefers-reduced-motion: reduce){ .skip-link { transition:none; } .dyd-hw-chevron { transition:none; } }

/* ── Widok kalendarza lekcji (popup) ─────────────────────────────────────── */
.kp-cal { table-layout:fixed; }
.kp-cal th, .kp-cal td { width:14.28%; }
.kp-cal td { vertical-align:top; height:64px; padding:.25rem; }
.kp-cal-empty { background:var(--bs-tertiary-bg); }
.kp-cal-day.has-lesson { background:rgba(37,99,235,.12); }
.kp-cal-day.is-today { outline:2px solid var(--bs-primary); outline-offset:-2px; }
.kp-cal-num { font-size:.8rem; color:var(--bs-secondary-color); }
.kp-cal-ev { font-size:.72rem; line-height:1.2; background:var(--bs-primary); color:#fff;
  border-radius:.25rem; padding:1px 4px; margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.kp-cal-ev.cancelled { background:var(--bs-secondary-bg); color:var(--bs-secondary-color); text-decoration:line-through; }

/* ══ Menu dostępności (a11y) ══════════════════════════════════════════════ */
/* Skala czcionki — skalujemy root font-size, reszta layoutu jest w rem */
html[data-kp-font="1"] { font-size:112.5%; }
html[data-kp-font="2"] { font-size:125%; }
html[data-kp-font="3"] { font-size:140%; }

/* Wysoki kontrast — niezależny od motywu jasny/ciemny (czerń/biel + żółte akcenty) */
html.kp-contrast {
  --bs-body-bg:#000; --bs-body-color:#fff;
  --bs-emphasis-color:#fff; --bs-secondary-color:#fff; --bs-tertiary-color:#fff;
  --bs-body-bg-rgb:0,0,0; --bs-secondary-bg:#000; --bs-tertiary-bg:#000;
  --bs-border-color:#fff; --bs-border-color-translucent:#fff;
  --bs-link-color:#ffdd00; --bs-link-hover-color:#fff5b0;
  --bs-link-color-rgb:255,221,0; --bs-primary:#ffdd00;
}
html.kp-contrast body { background:#000; color:#fff; }
html.kp-contrast .card, html.kp-contrast .navbar, html.kp-contrast .list-group,
html.kp-contrast .list-group-item, html.kp-contrast .alert, html.kp-contrast .dropdown-menu,
html.kp-contrast .modal-content, html.kp-contrast .table {
  background-color:#000 !important; color:#fff !important; border-color:#fff !important;
}
html.kp-contrast .text-body-secondary, html.kp-contrast .text-muted,
html.kp-contrast .small.text-body-secondary { color:#ededed !important; }
html.kp-contrast a:not(.btn) { color:#ffdd00; text-decoration:underline; }
html.kp-contrast .nav-tabs .nav-link.active { background:#ffdd00; color:#000 !important; }
html.kp-contrast .badge { border:1px solid #fff; }
html.kp-contrast .btn-outline-secondary { color:#fff; border-color:#fff; }
html.kp-contrast .btn-primary { background:#ffdd00; border-color:#fff; color:#000; }
html.kp-contrast :focus-visible { outline:3px solid #ffdd00 !important; outline-offset:2px; }

/* Schowaj menu sekcji panelu */
html.kp-hidemenu nav[aria-label="Sekcje panelu"] { display:none !important; }

/* Przycisk i panel menu a11y — przyklejony do lewej krawędzi */
.kp-a11y-btn {
  position:fixed; left:0; top:35%; z-index:1085;
  border-radius:0 .6rem .6rem 0; padding:.6rem .55rem; font-size:1.35rem; line-height:1;
  box-shadow:0 2px 10px rgba(0,0,0,.35);
}
.kp-a11y-panel {
  position:fixed; left:.5rem; top:35%; z-index:1086;
  width:min(20rem,calc(100vw - 1rem)); border-radius:.6rem;
}
.kp-a11y-panel[hidden] { display:none; }
@media (max-width:575.98px){ .kp-a11y-panel { top:auto; bottom:.5rem; } }
</style>
</head>
<body class="<?= h($KP_BODY_CLASS) ?>">
<a class="skip-link btn btn-primary btn-sm" href="#main">Przejdź do treści</a>

<!-- ══ Menu dostępności (a11y) ══════════════════════════════════════════════ -->
<button type="button" id="kp-a11y-toggle" class="kp-a11y-btn btn btn-primary"
        aria-expanded="false" aria-controls="kp-a11y-panel" aria-label="Otwórz menu dostępności">
  <i class="bi bi-universal-access" aria-hidden="true"></i>
</button>
<div id="kp-a11y-panel" class="kp-a11y-panel card shadow" role="dialog" aria-modal="false" aria-label="Ustawienia dostępności" hidden>
  <div class="card-body p-3">
    <div class="d-flex align-items-center mb-3">
      <span class="fw-bold"><i class="bi bi-universal-access me-1" aria-hidden="true"></i>Dostępność</span>
      <button type="button" id="kp-a11y-close" class="btn-close ms-auto" aria-label="Zamknij menu dostępności"></button>
    </div>
    <div class="mb-3">
      <div class="form-label small fw-semibold mb-1" id="kp-font-lbl">Wielkość tekstu</div>
      <div class="btn-group w-100" role="group" aria-labelledby="kp-font-lbl">
        <button type="button" class="btn btn-outline-secondary" id="kp-font-dec" aria-label="Zmniejsz tekst"><span aria-hidden="true">A−</span></button>
        <button type="button" class="btn btn-outline-secondary" id="kp-font-reset" aria-label="Domyślny rozmiar tekstu">Reset</button>
        <button type="button" class="btn btn-outline-secondary fw-bold" id="kp-font-inc" aria-label="Powiększ tekst"><span aria-hidden="true">A+</span></button>
      </div>
    </div>
    <button type="button" class="btn btn-outline-secondary w-100 mb-2 d-flex align-items-center gap-2" id="kp-contrast-btn" aria-pressed="false">
      <i class="bi bi-circle-half" aria-hidden="true"></i><span>Wysoki kontrast</span>
    </button>
    <div class="mb-2">
      <label class="form-label small fw-semibold mb-1" for="kp-a11y-bg-picker">Kolor tla</label>
      <div class="d-flex gap-2 align-items-center">
        <input type="color" id="kp-a11y-bg-picker" class="form-control form-control-color" value="#ffffff" style="width:3rem;height:2rem;padding:.15rem .25rem" title="Wybierz kolor tla">
        <button type="button" class="btn btn-outline-secondary btn-sm flex-grow-1" id="kp-bg-reset-btn">Resetuj (bialy)</button>
      </div>
    </div>
    <button type="button" class="btn btn-outline-secondary w-100 d-flex align-items-center gap-2" id="kp-hidemenu-btn" aria-pressed="false">
      <i class="bi bi-list" aria-hidden="true"></i><span id="kp-hidemenu-label">Schowaj menu</span>
    </button>
  </div>
</div>
<div aria-live="polite" class="visually-hidden" id="kp-a11y-status"></div>
<?php $KP_IMP = function_exists('student_impersonator') ? student_impersonator() : null; if ($KP_IMP): ?>
<div class="alert alert-warning border-0 rounded-0 mb-0 py-2" role="alert">
  <div class="container-fluid d-flex flex-wrap align-items-center gap-2 small">
    <i class="bi bi-incognito" aria-hidden="true"></i>
    <span>Podgląd panelu jako kursant — zalogowano przez administratora<?= !empty($KP_IMP['name']) ? ' (' . h($KP_IMP['name']) . ')' : '' ?>.</span>
    <a href="index.php?stop_impersonation=1" class="btn btn-sm btn-warning py-0 ms-auto">
      <i class="bi bi-box-arrow-left me-1" aria-hidden="true"></i>Zakończ podgląd
    </a>
  </div>
</div>
<?php endif; ?>
<?php if ($KP_TOPBAR): ?>
<header>
  <nav class="navbar bg-body-tertiary border-bottom" aria-label="Pasek użytkownika">
    <div class="container-fluid">
      <span class="navbar-brand kp-brand d-flex align-items-center gap-2 mb-0 fw-bold">
        <i class="bi bi-<?= h($KP_TOPBAR['icon'] ?? 'pc-display') ?>" aria-hidden="true"></i>
        <span><?= h($KP_TOPBAR['brand'] ?? $KP_ORG) ?></span>
      </span>
      <div class="d-flex align-items-center gap-3">
        <button type="button" id="kp-bg-pick-btn" class="btn btn-outline-secondary btn-sm" aria-label="Zmień kolor tła" title="Kolor tła">
          <i class="bi bi-palette" aria-hidden="true"></i>
        </button>
        <input type="color" id="kp-bg-color-input" class="visually-hidden" aria-label="Wybierz kolor tła" value="#ffffff">
        <?php if (!empty($KP_TOPBAR['user'])): ?>
        <span class="text-body-secondary small d-flex align-items-center gap-1">
          <i class="bi bi-person-circle" aria-hidden="true"></i><?= h($KP_TOPBAR['user']) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($KP_TOPBAR['logout'])): ?>
        <a href="<?= h($KP_TOPBAR['logout']) ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Wyloguj
        </a>
        <?php endif; ?>
      </div>
    </div>
  </nav>
</header>
<?php else: ?>
<button type="button" id="kp-bg-pick-btn" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3" style="z-index:1080" aria-label="Zmień kolor tła" title="Kolor tła">
  <i class="bi bi-palette" aria-hidden="true"></i>
</button>
<input type="color" id="kp-bg-color-input" class="visually-hidden" aria-label="Wybierz kolor tła" value="#ffffff">
<?php endif; ?>
