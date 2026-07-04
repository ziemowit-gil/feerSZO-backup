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
var sc=localStorage.getItem('kp-scheme');if(sc)document.documentElement.setAttribute('data-kp-scheme',sc);
var fs=localStorage.getItem('kp-fontscale');if(fs&&fs!=='0')d.setAttribute('data-kp-font',fs);
if(localStorage.getItem('kp-hidemenu')==='1')d.classList.add('kp-hidemenu');
if(localStorage.getItem('kp-metro-notice-dismissed')==='1')d.classList.add('kp-metro-notice-dismissed');
}catch(e){}</script>
<title><?= h($KP_TITLE) ?> — <?= h($KP_ORG) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
  --bs-primary:#2563eb; --bs-primary-rgb:37,99,235; --bs-link-color-rgb:96,165,250;
  --kp-body-bg:#ffffff; --kp-primary:#2563eb; --kp-primary-rgb:37,99,235;
  --kp-primary-hover:#1d4ed8; --kp-focus:#60a5fa; --kp-brand-gradient:linear-gradient(150deg,#1e3a8a 0%,#2563eb 45%,#7c3aed 100%);
}
/* Schemat: Klasyczny (default — niebieski) */
[data-kp-scheme="classic"], :root {
  --kp-body-bg:#ffffff; --kp-primary:#2563eb; --kp-primary-rgb:37,99,235;
  --kp-primary-hover:#1d4ed8; --kp-focus:#60a5fa; --kp-brand-gradient:linear-gradient(150deg,#1e3a8a 0%,#2563eb 45%,#7c3aed 100%);
}
/* Schemat: Mieta (zielony) */
[data-kp-scheme="mint"] {
  --kp-body-bg:#f0fdf4; --kp-primary:#16a34a; --kp-primary-rgb:22,163,74;
  --kp-primary-hover:#15803d; --kp-focus:#4ade80; --kp-brand-gradient:linear-gradient(150deg,#14532d 0%,#16a34a 50%,#0d9488 100%);
}
/* Schemat: Fioletowy */
[data-kp-scheme="violet"] {
  --kp-body-bg:#faf5ff; --kp-primary:#7c3aed; --kp-primary-rgb:124,58,237;
  --kp-primary-hover:#6d28d9; --kp-focus:#c084fc; --kp-brand-gradient:linear-gradient(150deg,#3b0764 0%,#7c3aed 50%,#db2777 100%);
}
/* Schemat: Ciepły (brzoskwiniowy) */
[data-kp-scheme="warm"] {
  --kp-body-bg:#fffbeb; --kp-primary:#d97706; --kp-primary-rgb:217,119,6;
  --kp-primary-hover:#b45309; --kp-focus:#fbbf24; --kp-brand-gradient:linear-gradient(150deg,#78350f 0%,#d97706 50%,#dc2626 100%);
}
/* Schemat: Grafitowy */
[data-kp-scheme="slate"] {
  --kp-body-bg:#f1f5f9; --kp-primary:#475569; --kp-primary-rgb:71,85,105;
  --kp-primary-hover:#334155; --kp-focus:#94a3b8; --kp-brand-gradient:linear-gradient(150deg,#0f172a 0%,#475569 50%,#64748b 100%);
}
/* Schemat: Metro (plaskie kolorowe kafle w stylu Windows 8 / Modern UI) */
[data-kp-scheme="metro"] {
  --kp-body-bg:#f2f2f2; --kp-primary:#2D89EF; --kp-primary-rgb:45,137,239;
  --kp-primary-hover:#1e6fd0; --kp-focus:#2D89EF; --kp-brand-gradient:linear-gradient(150deg,#1a1a1a 0%,#2D89EF 100%);
}
/* Aplikacja schematow do BS tokens */
body { background-color:var(--kp-body-bg) !important; }
:root, [data-kp-scheme] {
  --bs-primary:var(--kp-primary); --bs-primary-rgb:var(--kp-primary-rgb);
  --bs-link-color-rgb:var(--kp-primary-rgb);
}
.btn-primary {
  --bs-btn-bg:var(--kp-primary); --bs-btn-border-color:var(--kp-primary);
  --bs-btn-hover-bg:var(--kp-primary-hover); --bs-btn-hover-border-color:var(--kp-primary-hover);
}
body { min-height:100vh; }
/* WCAG: widoczny, spójny focus dla klawiatury */
a:focus-visible, button:focus-visible, .btn:focus-visible,
.form-control:focus-visible, .nav-link:focus-visible, [tabindex]:focus-visible {
  outline:3px solid var(--kp-focus,#60a5fa); outline-offset:2px; box-shadow:none;
}
/* WCAG 2.4.1: link „przejdź do treści" */
.skip-link { position:absolute; left:.5rem; top:.5rem; z-index:1080; transform:translateY(-200%); transition:transform .15s ease; }
.skip-link:focus { transform:translateY(0); }
.kp-brand i { color:var(--kp-primary,#60a5fa); }
.nav-tabs .nav-link.active { font-weight:600; }

/* ── Ekran logowania (login.php / parent.php) — odświeżony wygląd ─────────── */
.kp-auth-wrap { width:100%; max-width:940px; }
.kp-auth-card {
  overflow:hidden; border-radius:1.25rem;
  border:1px solid rgba(255,255,255,.6);
  box-shadow:0 24px 70px rgba(2,6,23,.45), 0 2px 8px rgba(2,6,23,.18);
  animation:kpAuthIn .4s cubic-bezier(.16,.84,.44,1) both;
}
@keyframes kpAuthIn { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:none; } }
/* Panel marki (lewa kolumna) — dekoracyjny gradient + lista korzyści */
.kp-auth-hero {
  background:var(--kp-brand-gradient,linear-gradient(150deg,#1e3a8a 0%,#2563eb 45%,#7c3aed 100%));
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
  border:1px solid rgba(255,255,255,.25); box-shadow:0 8px 24px rgba(0,0,0,.18);
}
.kp-auth-feat { display:flex; gap:.65rem; align-items:flex-start; }
.kp-auth-feat i { font-size:1.15rem; opacity:.95; flex-shrink:0; margin-top:.1rem; }
/* Pola formularza — łagodniejsze zaokrąglenie, czytelne granice (WCAG 1.4.11) */
.kp-auth-card .form-control { padding:.7rem .95rem; border-radius:.6rem; border-width:1.5px; }
.kp-auth-card .form-control-lg { font-size:1rem; }
/* Przycisk główny — gradient marki + miękki cień + hover-lift (spójnie z logowaniem głównym) */
.kp-auth-card .btn-primary {
  background:linear-gradient(135deg, var(--kp-primary,#2563eb), var(--kp-primary-hover,#1d4ed8));
  border:1px solid transparent;
  box-shadow:0 8px 20px rgba(var(--kp-primary-rgb,37,99,235),.30);
  transition:filter .15s, box-shadow .15s, transform .12s;
}
.kp-auth-card .btn-primary:hover { filter:brightness(1.06); box-shadow:0 10px 26px rgba(var(--kp-primary-rgb,37,99,235),.40); transform:translateY(-1px); }
.kp-auth-card .btn-primary:active { transform:translateY(0); }
@media (max-width:767.98px){
  .kp-auth-hero { display:none !important; } /* na telefonie tylko formularz */
}
@media (prefers-reduced-motion:reduce){
  .kp-auth-card { animation:none; }
  .kp-auth-card .btn-primary { transition:none; }
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

/* ══ Motyw Metro (kafle, ostre kąty, płaskie kolory — Windows 8 / Modern UI) ══ */
/* Ogólny reset kształtu: bez zaokrągleń i cieni w całym panelu */
[data-kp-scheme="metro"] .card, [data-kp-scheme="metro"] .btn, [data-kp-scheme="metro"] .badge,
[data-kp-scheme="metro"] .alert, [data-kp-scheme="metro"] .modal-content, [data-kp-scheme="metro"] .form-control,
[data-kp-scheme="metro"] .form-select, [data-kp-scheme="metro"] .input-group-text, [data-kp-scheme="metro"] .list-group-item,
[data-kp-scheme="metro"] .dropdown-menu, [data-kp-scheme="metro"] .table, [data-kp-scheme="metro"] .nav-link,
[data-kp-scheme="metro"] .vlab-hero, [data-kp-scheme="metro"] .vlab-card, [data-kp-scheme="metro"] .vlab-tpl-card,
[data-kp-scheme="metro"] .vlab-icon-badge, [data-kp-scheme="metro"] .kp-auth-card, [data-kp-scheme="metro"] .kp-auth-logo {
  border-radius:0 !important;
}
/* Siatka bezpieczeństwa: łapie też zaokrąglenia/cienie dopisane inline (style="...") w treści
   poszczególnych zakładek, których nie widać w ogólnej liście klas powyżej — np. odznaki statusu,
   ramki list — żeby "płaski" wygląd Metro obejmował całą treść panelu, a nie tylko znane komponenty. */
[data-kp-scheme="metro"] [style*="border-radius"] { border-radius:0 !important; }
[data-kp-scheme="metro"] [style*="box-shadow"] { box-shadow:none !important; }
[data-kp-scheme="metro"] .card, [data-kp-scheme="metro"] .shadow, [data-kp-scheme="metro"] .shadow-sm,
[data-kp-scheme="metro"] .vlab-hero, [data-kp-scheme="metro"] .vlab-card, [data-kp-scheme="metro"] .vlab-tpl-card,
[data-kp-scheme="metro"] .kp-auth-card, [data-kp-scheme="metro"] .btn {
  box-shadow:none !important;
}
[data-kp-scheme="metro"] .card { border:1px solid var(--bs-border-color); }
[data-kp-scheme="metro"] .vlab-card:hover, [data-kp-scheme="metro"] .vlab-tpl-card:hover { transform:none; }
[data-kp-scheme="metro"] h1, [data-kp-scheme="metro"] h2, [data-kp-scheme="metro"] h3, [data-kp-scheme="metro"] h4,
[data-kp-scheme="metro"] .h1, [data-kp-scheme="metro"] .h2, [data-kp-scheme="metro"] .h3, [data-kp-scheme="metro"] .h4,
[data-kp-scheme="metro"] .h5, [data-kp-scheme="metro"] .h6 {
  font-weight:700; letter-spacing:.01em;
}
[data-kp-scheme="metro"] .btn { font-weight:600; }
[data-kp-scheme="metro"] .btn:hover { filter:brightness(1.08); }
/* Rozszerzenie płaskiego wyglądu na pozostałe elementy używane w zakładkach panelu */
[data-kp-scheme="metro"] .progress, [data-kp-scheme="metro"] .form-check-input,
[data-kp-scheme="metro"] .pagination .page-link, [data-kp-scheme="metro"] .accordion-button,
[data-kp-scheme="metro"] .accordion-item {
  border-radius:0 !important;
}
[data-kp-scheme="metro"] .form-check-input { border-width:2px; }
[data-kp-scheme="metro"] .form-check-input:checked { background-color:var(--kp-primary); border-color:var(--kp-primary); }
[data-kp-scheme="metro"] table thead th, [data-kp-scheme="metro"] .table > thead {
  background:#1a1a1a; color:#fff; font-weight:700; border-color:#1a1a1a;
}
[data-kp-scheme="metro"] .list-group-item { border-width:1px; }
[data-kp-scheme="metro"] .list-group-item.active { background:var(--kp-primary); border-color:var(--kp-primary); }

/* Nawigacja główna → siatka kolorowych kafli (odpowiednik ekranu Start) */
[data-kp-scheme="metro"] nav[aria-label="Sekcje panelu"] {
  background:#1a1a1a; padding:.85rem .75rem; margin-left:calc(-1 * var(--bs-gutter-x,.75rem));
  margin-right:calc(-1 * var(--bs-gutter-x,.75rem)); max-width:none;
}
[data-kp-scheme="metro"] nav[aria-label="Sekcje panelu"] .nav.nav-tabs {
  display:flex; flex-wrap:wrap; gap:.4rem; border-bottom:0;
}
[data-kp-scheme="metro"] .nav-tabs .nav-item { margin:0; }
[data-kp-scheme="metro"] .nav-tabs .nav-link {
  width:104px; height:104px; border:0; padding:.6rem .65rem;
  display:flex; flex-direction:column; justify-content:space-between; align-items:flex-start;
  color:#fff; font-size:.78rem; line-height:1.15; background:#4a4a4a; transition:transform .08s ease;
}
[data-kp-scheme="metro"] .nav-tabs .nav-link i { font-size:1.7rem; }
[data-kp-scheme="metro"] .nav-tabs .nav-link .badge { position:static; }
[data-kp-scheme="metro"] .nav-tabs .nav-link:hover { color:#fff; filter:brightness(1.12); }
[data-kp-scheme="metro"] .nav-tabs .nav-link:active { transform:scale(.96); }
[data-kp-scheme="metro"] .nav-tabs .nav-link.active,
[data-kp-scheme="metro"] .nav-tabs .nav-link.dropdown-toggle.show {
  outline:3px solid #fff; outline-offset:-3px; font-weight:700;
}
/* WCAG 2.4.11: wskaźnik fokusu klawiatury musi być widoczny na KAŻDym kolorze kafla — domyślny
   niebieski --kp-focus zlewałby się z niebieskim/fioletowym kaflem, więc na kaflach wymuszamy biały. */
[data-kp-scheme="metro"] .nav-tabs .nav-link:focus-visible,
[data-kp-scheme="metro"] .dropdown-item:focus-visible {
  outline:3px solid #fff; outline-offset:-3px; box-shadow:none;
}
/* Kolor kafli: 8 własnych barw (nie z zewnętrznej biblioteki) — każda dobrana tak, by dawać
   kontrast ≥4.5:1 wobec białego tekstu (WCAG 1.4.3, tekst kafli to ~12.5px, więc próg "normal text").
   Klasy .kp-tile-N dopisane w HTML (index.php), przypisywane po kolejności zakładki (nie po pozycji
   w drzewie DOM), więc kolor danej zakładki nie skacze, gdy pojawia się/znika sąsiednia pozycja. */
/* Specificity: musi przebić bazową regułę „.nav-tabs .nav-link”/„.dropdown-item” (stąd
   dopisane .nav-link/.dropdown-item w selektorze zamiast samego .kp-tile-N). */
[data-kp-scheme="metro"] .nav-link.kp-tile-1, [data-kp-scheme="metro"] .dropdown-item.kp-tile-1, [data-kp-scheme="metro"] .btn.kp-tile-1, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-1 { background:#186fe0; } /* niebieski */
[data-kp-scheme="metro"] .nav-link.kp-tile-2, [data-kp-scheme="metro"] .dropdown-item.kp-tile-2, [data-kp-scheme="metro"] .btn.kp-tile-2, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-2 { background:#12817d; } /* turkusowy */
[data-kp-scheme="metro"] .nav-link.kp-tile-3, [data-kp-scheme="metro"] .dropdown-item.kp-tile-3, [data-kp-scheme="metro"] .btn.kp-tile-3, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-3 { background:#da1f7c; } /* magenta */
[data-kp-scheme="metro"] .nav-link.kp-tile-4, [data-kp-scheme="metro"] .dropdown-item.kp-tile-4, [data-kp-scheme="metro"] .btn.kp-tile-4, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-4 { background:#662cd2; } /* fioletowy */
[data-kp-scheme="metro"] .nav-link.kp-tile-5, [data-kp-scheme="metro"] .dropdown-item.kp-tile-5, [data-kp-scheme="metro"] .btn.kp-tile-5, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-5 { background:#4e7e24; } /* zielony */
[data-kp-scheme="metro"] .nav-link.kp-tile-6, [data-kp-scheme="metro"] .dropdown-item.kp-tile-6, [data-kp-scheme="metro"] .btn.kp-tile-6, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-6 { background:#b35e09; } /* pomarańczowy */
[data-kp-scheme="metro"] .nav-link.kp-tile-7, [data-kp-scheme="metro"] .dropdown-item.kp-tile-7, [data-kp-scheme="metro"] .btn.kp-tile-7, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-7 { background:#e02618; } /* czerwony */
[data-kp-scheme="metro"] .nav-link.kp-tile-8, [data-kp-scheme="metro"] .dropdown-item.kp-tile-8, [data-kp-scheme="metro"] .btn.kp-tile-8, [data-kp-scheme="metro"] .kp-tile-lg.kp-tile-8 { background:#a2663d; } /* brązowy */

/* Rozwijane podgrupy (Nauka / Dostępy) → mniejsze kafle we flyoucie */
[data-kp-scheme="metro"] .dropdown-menu {
  background:#1a1a1a; border:0; padding:.5rem; flex-wrap:wrap; gap:.35rem; min-width:auto;
}
[data-kp-scheme="metro"] .dropdown-menu.show { display:flex; }
[data-kp-scheme="metro"] .dropdown-item {
  width:92px; height:84px; color:#fff; white-space:normal;
  display:flex; flex-direction:column; justify-content:space-between; font-size:.72rem; font-weight:600; padding:.5rem;
}
[data-kp-scheme="metro"] .dropdown-item i { font-size:1.35rem; }
[data-kp-scheme="metro"] .dropdown-item:hover, [data-kp-scheme="metro"] .dropdown-item:focus { filter:brightness(1.15); color:#fff; }
[data-kp-scheme="metro"] .dropdown-item.active { outline:3px solid #fff; outline-offset:-3px; }

/* ── Baner „zmieniliśmy wygląd" — tylko w motywie Metro, do odrzucenia i zapamiętania ────── */
.kp-metro-notice { display:none; }
[data-kp-scheme="metro"] .kp-metro-notice {
  display:flex; align-items:flex-start; gap:.75rem; background:#186fe0; color:#fff;
  padding:.9rem 1rem; margin-bottom:1rem; border:0;
}
html.kp-metro-notice-dismissed .kp-metro-notice { display:none !important; }
.kp-metro-notice .btn-close { filter:invert(1) grayscale(1) brightness(2); }

/* Kompaktowy pasek kafli u góry strony dublowałby ścianę Start (ten sam zestaw linków w innej
   formie) — na zakładce landingowej („Dane kursanta”) chowamy go w motywie Metro, bo ściana
   Start przejmuje w komplecie jego rolę nawigacyjną. Na pozostałych zakładkach pasek zostaje. */
[data-kp-scheme="metro"] .kp-nav-startpage { display:none; }

/* ── Ekran startowy „Start" — ściana dużych kafli, widoczna po zalogowaniu (zakładka Dane) ── */
.kp-startwall { display:none; }
[data-kp-scheme="metro"] .kp-startwall {
  display:block; background:#1a1a1a; margin:-1rem -.75rem 1.25rem; padding:1.5rem .75rem;
}
.kp-startwall-welcome { color:#fff; margin:0 0 1rem; }
.kp-startwall-welcome .kp-startwall-hello { font-size:1.5rem; font-weight:700; }
.kp-startwall-welcome .kp-startwall-sub { opacity:.75; font-size:.9rem; }
.kp-startwall-grid { display:flex; flex-wrap:wrap; gap:.5rem; }
.kp-tile-lg {
  width:132px; height:132px; border:0; padding:.75rem; color:#fff; text-decoration:none;
  display:flex; flex-direction:column; justify-content:space-between; align-items:flex-start;
  font-size:.85rem; font-weight:600; line-height:1.2; transition:transform .08s ease;
}
.kp-tile-lg:hover { color:#fff; filter:brightness(1.12); }
.kp-tile-lg:active { transform:scale(.97); }
.kp-tile-lg:focus-visible { outline:3px solid #fff; outline-offset:-3px; box-shadow:none; }
.kp-tile-lg i { font-size:2rem; }
.kp-tile-lg .badge { position:static; align-self:flex-start; }
@media (max-width:420px){ .kp-tile-lg { width:calc(50% - .25rem); height:110px; } }

/* ── Logowanie: dodatkowe opcje jako kafle Metro (zamiast pionowej listy przycisków) ──────── */
.kp-login-tiles { display:flex; flex-direction:column; gap:.5rem; }
[data-kp-scheme="metro"] .kp-login-tiles {
  flex-direction:row; flex-wrap:wrap;
}
[data-kp-scheme="metro"] .kp-login-tiles .btn {
  width:auto; flex:1 1 30%; min-width:9rem; height:6rem; color:#fff; border:0;
  display:flex; flex-direction:column; align-items:flex-start; justify-content:space-between;
  padding:.65rem .75rem; font-size:.78rem; text-align:left; white-space:normal;
}
[data-kp-scheme="metro"] .kp-login-tiles .btn i { font-size:1.4rem; }
[data-kp-scheme="metro"] .kp-login-tiles .btn:hover, [data-kp-scheme="metro"] .kp-login-tiles .btn:focus { color:#fff; filter:brightness(1.12); }
[data-kp-scheme="metro"] .dropdown-divider { display:none; }
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
      <div class="form-label small fw-semibold mb-1">Schemat kolorów</div>
      <div class="d-flex flex-wrap gap-1" role="group" aria-label="Wybierz schemat kolorów">
        <button type="button" class="kp-scheme-btn btn btn-sm" data-scheme="classic" title="Klasyczny (niebieski)"
                style="background:#2563eb;color:#fff;width:2rem;height:2rem;padding:0;border-radius:.4rem" aria-label="Klasyczny"></button>
        <button type="button" class="kp-scheme-btn btn btn-sm" data-scheme="mint" title="Mięta (zielony)"
                style="background:#16a34a;color:#fff;width:2rem;height:2rem;padding:0;border-radius:.4rem" aria-label="Mięta"></button>
        <button type="button" class="kp-scheme-btn btn btn-sm" data-scheme="violet" title="Fioletowy"
                style="background:#7c3aed;color:#fff;width:2rem;height:2rem;padding:0;border-radius:.4rem" aria-label="Fioletowy"></button>
        <button type="button" class="kp-scheme-btn btn btn-sm" data-scheme="warm" title="Ciepły (brzoskwiniowy)"
                style="background:#d97706;color:#fff;width:2rem;height:2rem;padding:0;border-radius:.4rem" aria-label="Ciepły"></button>
        <button type="button" class="kp-scheme-btn btn btn-sm" data-scheme="slate" title="Grafitowy"
                style="background:#475569;color:#fff;width:2rem;height:2rem;padding:0;border-radius:.4rem" aria-label="Grafitowy"></button>
        <button type="button" class="kp-scheme-btn btn btn-sm" data-scheme="metro" title="Metro (kafle)"
                style="background:#2D89EF;color:#fff;width:2rem;height:2rem;padding:0;border-radius:0" aria-label="Metro"></button>
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
        <?php if (!empty($KP_TOPBAR['notifications'])): ?><?= $KP_TOPBAR['notifications'] ?><?php endif; ?>
        <button type="button" id="kp-bg-pick-btn" class="btn btn-outline-secondary btn-sm" aria-label="Zmien schemat kolorow" title="Schemat kolorow">
          <i class="bi bi-palette" aria-hidden="true"></i>
        </button>
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
<button type="button" id="kp-bg-pick-btn" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3" style="z-index:1080" aria-label="Zmien schemat kolorow" title="Schemat kolorow">
  <i class="bi bi-palette" aria-hidden="true"></i>
</button>
<?php endif; ?>
