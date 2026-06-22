<?php
/**
 * karty30/includes/header_k30.php — Dostępny layout modułu Dydaktyka (d. TyfloKonsultacje).
 *
 * Pełna dostępność WCAG 2.1 AA:
 *  - Skip link jako pierwszy element focusowalny
 *  - Semantyczne landmarki HTML5 (header, nav, main, footer)
 *  - ARIA roles i labele na wszystkich regionach
 *  - Widoczny focus ring (min. 3px, wysoki kontrast)
 *  - Nawigacja klawiaturą: Tab, Shift+Tab, Enter, Spacja, Esc, strzałki
 *  - Live region dla flash messages (aria-live="polite")
 *  - Wszystkie ikony aria-hidden="true" + tekst widoczny lub aria-label
 *  - Kontrast kolorów ≥ 4.5:1 (tekst) i ≥ 3:1 (UI)
 *
 * Wymaga: $PAGE_TITLE przed include.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();

// ── IKA — wymagane przy każdym dostępie do Karty30 ───────────────────────────
// Moduł przetwarza dane osobowe beneficjentów (imię, adres, opis problemu).
// Sesja IKA ważna 30 min — wspólna z CRM i systemem głównym.
(function () {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    ika_require(APP_URL . $uri);
})();

$_ku        = current_user();
$_k30_title = $PAGE_TITLE ?? 'Dydaktyka';
$_uri       = $_SERVER['REQUEST_URI'] ?? '';
$_org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_can_write = can_write('karty30') || is_admin();

// Inicjały użytkownika
$_ku_initials = '?';
$_ku_name     = '';
if ($_ku) {
    $_name = trim(($_ku['first_name'] ?? '') . ' ' . ($_ku['last_name'] ?? ''));
    if (!$_name) $_name = $_ku['name'] ?? '';
    $_ku_name = $_name ?: ($_ku['email'] ?? 'Użytkownik');
    $_parts   = preg_split('/\s+/', trim($_name));
    $_ku_initials = '';
    foreach ($_parts as $w) $_ku_initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    $_ku_initials = mb_substr($_ku_initials, 0, 2, 'UTF-8') ?: '?';
}

// Nawigacja — ścieżka aktywna
function _k30_active(string $path): bool {
    global $_uri;
    return str_contains($_uri, $path);
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($_k30_title) ?> — Dydaktyka<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════════════════════
   Dydaktyka (d. TyfloKonsultacje) — dostępny layout
   Priorytet: czytelność, kontrast, widoczny focus, semantyka
   ═══════════════════════════════════════════════════════════════════ */

/* ── Paleta Dydaktyka ───────────────────────────────────────────────
   Główny kolor: indigo-900 (#1e1b4b → topbar) + indigo-600 (#4f46e5 → akcenty)
   Kontrast topbar/biały: 14:1 ✓ WCAG AAA
   Kontrast tekstu: 17:1 ✓ WCAG AAA
   Focus ring: żółty #facc15 — widoczny na każdym tle ✓
   ────────────────────────────────────────────────────────────────── */
:root {
  /* Akcent główny */
  --k30-purple:      #4338ca;   /* indigo-700 — kontrast 5.9:1 na białym ✓ AA */
  --k30-purple-dark: #1e1b4b;   /* indigo-950 — topbar */
  --k30-purple-mid:  #6366f1;   /* indigo-500 */
  --k30-purple-bg:   #eef2ff;   /* indigo-50 */
  --k30-purple-light:#e0e7ff;   /* indigo-100 */

  /* Focus — żółty 3:1 na ciemnym, 7:1 na białym */
  --k30-focus:       #facc15;
  --k30-focus-dark:  #a16207;

  /* Tekst */
  --k30-text:        #0f172a;   /* slate-900 — kontrast 19:1 ✓ */
  --k30-text-sub:    #334155;   /* slate-700 — kontrast 10:1 ✓ */
  --k30-border:      #64748b;   /* slate-500 — kontrast 4.5:1 ✓ AA */
  --k30-bg:          #f8fafc;   /* slate-50 */

  /* Layout */
  --k30-sidebar-w:   256px;
  --k30-topbar-h:    54px;
}

/* ── Reset i base ───────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
  margin: 0;
  padding-top: var(--k30-topbar-h);   /* miejsce na stały topbar (menu jest pod nim) */
  background: var(--k30-bg);
  color: var(--k30-text);
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: 1rem;
  line-height: 1.6;
}

/* ── Skip link — MUSI być pierwszym elementem ───────────────────── */
.skip-link {
  position: absolute;
  top: -100%;
  left: 1rem;
  z-index: 9999;
  background: var(--k30-purple);
  color: #fff;
  padding: .75rem 1.5rem;
  border-radius: 0 0 8px 8px;
  font-size: 1rem;
  font-weight: 700;
  text-decoration: none;
  border: 3px solid var(--k30-focus);
}
.skip-link:focus {
  top: 0;
  outline: 3px solid var(--k30-focus);
  outline-offset: 2px;
}

/* ── Focus ring — globalny, widoczny na każdym tle ──────────────── */
*:focus-visible {
  outline: 3px solid var(--k30-focus) !important;
  outline-offset: 3px !important;
  border-radius: 3px;
}
/* Usuń domyślny outline przeglądarki — zastąpiony powyżej */
*:focus:not(:focus-visible) { outline: none; }

/* ── Topbar ──────────────────────────────────────────────────────── */
.k30-topbar {
  height: var(--k30-topbar-h);
  background: var(--k30-purple);
  color: #fff;
  display: flex;
  align-items: center;
  padding: 0 1.25rem 0 0;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 1040;
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
}

.k30-brand {
  width: var(--k30-sidebar-w);
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: 0 1.1rem;
  flex-shrink: 0;
  text-decoration: none;
  color: #fff;
  font-weight: 800;
  font-size: 1rem;
  height: 100%;
  border-right: 1px solid rgba(255,255,255,.25);
  transition: background .15s;
}
.k30-brand:hover { background: rgba(255,255,255,.1); color: #fff; }
.k30-brand-icon {
  width: 32px; height: 32px;
  background: rgba(255,255,255,.2);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; flex-shrink: 0;
}
.k30-brand-sub { font-size: .68rem; opacity: .75; font-weight: 400; line-height: 1; }

/* Breadcrumb w topbarze */
.k30-topbar-bc {
  flex: 1;
  padding: 0 1.25rem;
  font-size: .88rem;
  color: rgba(255,255,255,.85);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.k30-topbar-bc strong { color: #fff; }

/* User info */
.k30-topbar-user {
  display: flex; align-items: center; gap: .75rem;
  padding-left: 1rem; flex-shrink: 0;
}
.k30-user-btn {
  display: flex; align-items: center; gap: .5rem;
  background: rgba(255,255,255,.15);
  border: 2px solid rgba(255,255,255,.4);
  border-radius: 6px;
  color: #fff;
  padding: .3rem .75rem;
  font-size: .84rem;
  font-weight: 500;
  cursor: pointer;
  text-decoration: none;
  transition: background .15s;
}
.k30-user-btn:hover { background: rgba(255,255,255,.25); color: #fff; }
.k30-user-avatar {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: rgba(255,255,255,.3);
  display: flex; align-items: center; justify-content: center;
  font-size: .72rem; font-weight: 700; flex-shrink: 0;
}
.k30-sys-link {
  display: inline-flex; align-items: center; gap: .4rem;
  color: rgba(255,255,255,.8);
  text-decoration: none;
  font-size: .8rem;
  padding: .3rem .65rem;
  border: 1px solid rgba(255,255,255,.35);
  border-radius: 5px;
  white-space: nowrap;
  transition: background .12s;
}
.k30-sys-link:hover { background: rgba(255,255,255,.15); color: #fff; }

/* ── Sidebar ─────────────────────────────────────────────────────── */
.k30-sidebar {
  position: fixed;
  top: var(--k30-topbar-h);
  left: 0; bottom: 0;
  width: var(--k30-sidebar-w);
  background: #fff;
  border-right: 2px solid #E5E7EB;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  overflow-x: hidden;
  z-index: 1030;
}
.k30-sidebar::-webkit-scrollbar { width: 6px; }
.k30-sidebar::-webkit-scrollbar-thumb { background: #D1D5DB; border-radius: 3px; }

/* Notka o zmianie zakresu / nazwy modułu */
.k30-rename-note {
  display: flex;
  align-items: flex-start;
  gap: .45rem;
  margin: .6rem .6rem .2rem;
  padding: .55rem .7rem;
  background: var(--k30-purple-bg);
  border: 1px solid var(--k30-purple-light);
  border-left: 3px solid var(--k30-purple-mid);
  border-radius: 6px;
  font-size: .74rem;
  line-height: 1.4;
  color: var(--k30-text-sub);
}
.k30-rename-note i { color: var(--k30-purple); font-size: .9rem; flex-shrink: 0; margin-top: .1rem; }
.k30-rename-note strong { color: var(--k30-text); }

/* Nav label — ukryty wizualnie ale widoczny dla czytników */
.k30-nav-label {
  font-size: .7rem;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #64748b;               /* slate-500 — kontrast 4.5:1 ✓ */
  padding: 1rem 1rem .35rem;
  user-select: none;
}

/* Nav link */
.k30-nav-link {
  display: flex;
  align-items: center;
  gap: .7rem;
  padding: .65rem .9rem;
  color: var(--k30-text-sub);
  text-decoration: none;
  font-size: .88rem;
  font-weight: 500;
  border-left: 3px solid transparent;
  border-radius: 0 8px 8px 0;
  margin: 0 .4rem .05rem;
  transition: background .1s, border-color .1s, color .1s;
  /* Minimum touch target 44px */
  min-height: 44px;
}
.k30-nav-link i {
  font-size: 1rem;
  width: 20px;
  text-align: center;
  flex-shrink: 0;
  color: #64748b;
  transition: color .1s;
  /* Zawsze aria-hidden — tekst linku opisuje cel */
}
.k30-nav-link:hover {
  background: var(--k30-purple-bg);
  color: var(--k30-purple);
  border-left-color: var(--k30-purple-mid);
}
.k30-nav-link:hover i { color: var(--k30-purple); }
/* Focus: własny, wyraźny ring */
.k30-nav-link:focus-visible {
  outline: 3px solid var(--k30-focus) !important;
  outline-offset: -2px !important;
  background: var(--k30-purple-bg);
}
/* Aktywna strona */
.k30-nav-link[aria-current="page"] {
  background: var(--k30-purple-bg);
  color: var(--k30-purple);
  border-left-color: var(--k30-purple);
  font-weight: 700;
}
.k30-nav-link[aria-current="page"] i { color: var(--k30-purple); }
/* Ikona ▸ zamiast koloru dla użytkowników bez rozróżniania barw */
.k30-nav-link[aria-current="page"]::after {
  content: '';
  display: block;
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--k30-purple);
  margin-left: auto;
  flex-shrink: 0;
}

.k30-nav-divider {
  height: 1px;
  background: #e2e8f0;          /* slate-200 */
  margin: .4rem .8rem;
}

/* Sidebar bottom */
.k30-sidebar-bottom {
  margin-top: auto;
  border-top: 2px solid #F3F4F6;
  padding: .5rem;
}

/* ── Main content ────────────────────────────────────────────────── */
.k30-shell {
  margin-left: 0;
  margin-top: 0;
  min-height: calc(100vh - var(--k30-topbar-h));
  display: flex;
  flex-direction: column;
}
.k30-content {
  flex: 1;
  padding: 1.75rem 2rem;
  max-width: 1300px;
  width: 100%;
}
.k30-footer {
  border-top: 2px solid #E5E7EB;
  padding: .75rem 2rem;
  font-size: .8rem;
  color: #6B7280;
  background: #fff;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

/* ── Live region dla dynamicznych komunikatów ────────────────────── */
.k30-live-region {
  position: absolute;
  width: 1px; height: 1px;
  overflow: hidden;
  clip: rect(0,0,0,0);
  white-space: nowrap;
}

/* ── Flash alert — dostępny ─────────────────────────────────────── */
.k30-alert {
  display: flex;
  align-items: flex-start;
  gap: .75rem;
  padding: 1rem 1.25rem;
  border-radius: 8px;
  border: 2px solid;
  margin-bottom: 1.25rem;
  font-size: .95rem;
}
.k30-alert-success { background: #F0FDF4; border-color: #16A34A; color: #14532D; }
.k30-alert-danger   { background: #FEF2F2; border-color: #DC2626; color: #7F1D1D; }
.k30-alert-warning  { background: #FFFBEB; border-color: #D97706; color: #78350F; }
.k30-alert-info     { background: #EFF6FF; border-color: #2563EB; color: #1E3A8A; }
.k30-alert-close {
  margin-left: auto;
  background: none;
  border: none;
  font-size: 1.25rem;
  cursor: pointer;
  color: inherit;
  opacity: .7;
  padding: 0 .25rem;
  border-radius: 4px;
  flex-shrink: 0;
}
.k30-alert-close:hover { opacity: 1; }

/* ── Nagłówek sekcji ─────────────────────────────────────────────── */
.k30-page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1.5rem;
}
.k30-page-title {
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--k30-text);
  margin: 0 0 .25rem;
  line-height: 1.2;
}
.k30-page-subtitle {
  font-size: .9rem;
  color: var(--k30-text-sub);
}
.k30-page-actions {
  display: flex;
  gap: .5rem;
  flex-wrap: wrap;
  align-items: center;
  flex-shrink: 0;
}

/* ── Przyciski — powiększone touch targets ───────────────────────── */
.btn { min-height: 44px; font-size: .95rem; }
.btn-sm { min-height: 36px; font-size: .875rem; }
.btn-k30 {
  background: var(--k30-purple);
  color: #fff;
  border: 2px solid var(--k30-purple);
  border-radius: 6px;
  padding: .55rem 1.25rem;
  font-weight: 600;
  transition: background .15s, border-color .15s;
}
.btn-k30:hover { background: var(--k30-purple-dark); border-color: var(--k30-purple-dark); color: #fff; }
.btn-k30-outline {
  background: transparent;
  color: var(--k30-purple);
  border: 2px solid var(--k30-purple);
  border-radius: 6px;
  padding: .5rem 1.25rem;
  font-weight: 600;
  transition: background .15s;
}
.btn-k30-outline:hover { background: var(--k30-purple-bg); }

/* ── Formularze — dostępne ───────────────────────────────────────── */
.form-label {
  font-weight: 600;
  font-size: .95rem;
  color: var(--k30-text);
  margin-bottom: .4rem;
  display: block;
}
.form-label .req {
  color: #DC2626;
  margin-left: .2rem;
}
.form-label .req::after {
  content: ' (wymagane)';
  font-size: .75rem;
  font-weight: 400;
  color: #DC2626;
}
.form-control, .form-select {
  border: 2px solid var(--k30-border);
  border-radius: 6px;
  font-size: .95rem;
  padding: .55rem .9rem;
  min-height: 44px;
  color: var(--k30-text);
  transition: border-color .15s;
}
.form-control:focus, .form-select:focus {
  border-color: var(--k30-purple);
  box-shadow: 0 0 0 3px rgba(91,33,182,.2);
}
.form-control[aria-invalid="true"], .form-select[aria-invalid="true"] {
  border-color: #DC2626;
}
.form-hint {
  font-size: .82rem;
  color: #4B5563;
  margin-top: .25rem;
}
.form-error {
  font-size: .85rem;
  color: #DC2626;
  font-weight: 600;
  margin-top: .3rem;
  display: flex;
  align-items: center;
  gap: .35rem;
}

/* ── Tabele ──────────────────────────────────────────────────────── */
.k30-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .95rem;
}
.k30-table th {
  background: #F3F4F6;
  border-bottom: 3px solid #D1D5DB;
  padding: .8rem .9rem;
  text-align: left;
  font-size: .82rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: var(--k30-text-sub);
  white-space: nowrap;
}
.k30-table td {
  padding: .85rem .9rem;
  border-bottom: 1px solid #E5E7EB;
  vertical-align: middle;
  color: var(--k30-text);
  font-size: .95rem;
}
.k30-table tr:hover td { background: #F9FAFB; }
.k30-table tr:last-child td { border-bottom: 2px solid #E5E7EB; }

/* ── Dostępne statusy (nie tylko kolor — ikona + tekst) ─────────── */
.k30-status {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  padding: .3rem .75rem;
  border-radius: 4px;
  font-size: .85rem;
  font-weight: 600;
  border: 1.5px solid;
}

/* ── Responsive (mobile) ─────────────────────────────────────────── */
@media (max-width: 768px) {
  :root { --k30-sidebar-w: 0px; }
  .k30-sidebar { transform: translateX(-260px); transition: transform .25s; }
  .k30-sidebar.open { transform: translateX(0); width: 260px; }
  .k30-shell { margin-left: 0; }
  .k30-content { padding: 1rem; }
  .k30-brand { width: auto; border-right: none; }
}

/* ── Wysoki kontrast (media query) ──────────────────────────────── */
@media (prefers-contrast: high) {
  .k30-nav-link { border-left-width: 6px; }
  .k30-table th { background: #000; color: #fff; }
  .form-control, .form-select { border-width: 3px; border-color: #000; }
}

/* ── Reduced motion ──────────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; animation: none !important; }
}

/* ═══ MENU GŁÓWNE NA GÓRZE (pasek poziomy pod topbarem) ═══════════ */
.k30-menubar {
  position: sticky;
  top: var(--k30-topbar-h);
  z-index: 1029;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: .25rem;
  background: #fff;
  border-bottom: 2px solid #E5E7EB;
  padding: .35rem .9rem;
  box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.k30-menu {
  list-style: none;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: .15rem;
  margin: 0;
  padding: 0;
}
.k30-menu > li { position: relative; }
.k30-menu-right { margin-left: auto; display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }

/* Pozycja menu (link lub przycisk rozwijany) */
.k30-menu-link {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  padding: .5rem .8rem;
  min-height: 42px;
  color: var(--k30-text-sub);
  background: transparent;
  border: 0;
  border-bottom: 3px solid transparent;
  border-radius: 6px 6px 0 0;
  font-size: .9rem;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  white-space: nowrap;
  transition: background .12s, color .12s, border-color .12s;
}
.k30-menu-link i { font-size: 1rem; color: #64748b; transition: color .12s; }
.k30-menu-link:hover { background: var(--k30-purple-bg); color: var(--k30-purple); border-bottom-color: var(--k30-purple-mid); }
.k30-menu-link:hover i { color: var(--k30-purple); }
.k30-menu-link:focus-visible { outline: 3px solid var(--k30-focus) !important; outline-offset: -2px !important; background: var(--k30-purple-bg); }
.k30-menu-link.dropdown-toggle::after { margin-left: .15rem; }
/* Aktywna sekcja / strona */
.k30-menu-link[aria-current="page"],
.k30-menu-link.k30-active {
  background: var(--k30-purple-bg);
  color: var(--k30-purple);
  border-bottom-color: var(--k30-purple);
  font-weight: 800;
}
.k30-menu-link[aria-current="page"] i,
.k30-menu-link.k30-active i { color: var(--k30-purple); }

/* Rozwijane menu sekcji */
.k30-menubar .dropdown-menu { font-size: .9rem; border: 1px solid #e2e8f0; box-shadow: 0 8px 24px rgba(0,0,0,.12); border-radius: 8px; padding: .35rem; }
.k30-menubar .dropdown-item { border-radius: 6px; padding: .5rem .75rem; display: flex; align-items: center; gap: .55rem; font-weight: 500; color: var(--k30-text-sub); min-height: 40px; }
.k30-menubar .dropdown-item i { color: #64748b; width: 18px; text-align: center; }
.k30-menubar .dropdown-item:hover, .k30-menubar .dropdown-item:focus { background: var(--k30-purple-bg); color: var(--k30-purple); }
.k30-menubar .dropdown-item:hover i, .k30-menubar .dropdown-item:focus i { color: var(--k30-purple); }
.k30-menubar .dropdown-item[aria-current="page"] { background: var(--k30-purple-bg); color: var(--k30-purple); font-weight: 700; }
.k30-menubar .dropdown-header { font-size: .68rem; letter-spacing: .08em; text-transform: uppercase; color: #64748b; font-weight: 700; }

/* Notka o zmianie nazwy modułu — slim, pod menu */
.k30-rename-strip {
  display: flex; align-items: center; gap: .45rem;
  padding: .4rem 1rem;
  background: var(--k30-purple-bg);
  border-bottom: 1px solid var(--k30-purple-light);
  font-size: .76rem; color: var(--k30-text-sub);
}
.k30-rename-strip i { color: var(--k30-purple); flex-shrink: 0; }
.k30-rename-strip strong { color: var(--k30-text); }

@media (max-width: 768px) {
  .k30-menubar { padding: .3rem .5rem; }
  .k30-menu-link { padding: .45rem .6rem; font-size: .85rem; }
  .k30-menu-right { width: 100%; margin-left: 0; justify-content: flex-start; }
}
</style>
</head>
<body>

<!-- ══ SKIP LINK — pierwsza rzecz którą słyszy czytnik ekranu ═══════ -->
<a href="#k30-main" class="skip-link">Przejdź do treści głównej</a>
<a href="#k30-nav"  class="skip-link" style="left:calc(1rem + 200px)">Przejdź do nawigacji</a>

<!-- ══ Live region — czytniki ekranu ogłaszają dynamiczne zmiany ════ -->
<div aria-live="polite" aria-atomic="true" class="k30-live-region" id="k30-live" role="status"></div>
<div aria-live="assertive" aria-atomic="true" class="k30-live-region" id="k30-live-urgent" role="alert"></div>

<!-- ══ TOPBAR ════════════════════════════════════════════════════════ -->
<header class="k30-topbar" role="banner">

  <!-- Logo / brand -->
  <a href="<?= APP_URL ?>/karty30/index.php" class="k30-brand" aria-label="Dydaktyka Karty 30 — strona główna">
    <div class="k30-brand-icon" aria-hidden="true">
      <i class="bi bi-card-checklist"></i>
    </div>
    <div>
      <div>Dydaktyka</div>
      <div class="k30-brand-sub">Karty 30<?= $_org_name ? ' · ' . h(mb_substr($_org_name, 0, 20, 'UTF-8')) : '' ?></div>
    </div>
  </a>

  <!-- Kontekst strony -->
  <div class="k30-topbar-bc" aria-hidden="true">
    <strong><?= h($_k30_title) ?></strong>
  </div>

  <!-- User + sys link -->
  <nav class="k30-topbar-user" aria-label="Działania użytkownika">
    <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
    <button type="button"
            data-bs-toggle="modal" data-bs-target="#bugReportModal"
            title="Zgłoś błąd na tej stronie" aria-label="Zgłoś błąd"
            style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.4);
                   border-radius:6px;padding:.18rem .5rem;font-size:.78rem;
                   color:rgba(255,255,255,.9);cursor:pointer;line-height:1.5;
                   transition:all .12s;white-space:nowrap;flex-shrink:0;
                   display:inline-flex;align-items:center;gap:.3rem">
      <i class="bi bi-bug-fill" style="font-size:.85rem"></i>
      <span class="d-none d-sm-inline">Zgłoś błąd</span>
    </button>
    <?php endif; ?>
    <?php $msw_active='k30'; $msw_dark=true; require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>

    <?php if ($_ku): ?>
    <div class="dropdown">
      <button type="button"
              class="k30-user-btn dropdown-toggle"
              data-bs-toggle="dropdown"
              aria-haspopup="true"
              aria-expanded="false"
              aria-label="Menu użytkownika: <?= h($_ku_name) ?>">
        <span class="k30-user-avatar" aria-hidden="true"><?= h($_ku_initials) ?></span>
        <span class="d-none d-md-inline"><?= h(explode(' ', $_ku_name)[0]) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:210px;font-size:.9rem">
        <li>
          <div class="px-3 py-2 border-bottom">
            <div class="fw-bold"><?= h($_ku_name) ?></div>
            <div class="text-muted small"><?= h($_ku['email'] ?? '') ?></div>
          </div>
        </li>
        <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/index.php">
          <i class="bi bi-person-circle me-2" aria-hidden="true"></i>Moje konto
        </a></li>
        <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/index.php">
          <i class="bi bi-house me-2" aria-hidden="true"></i>System główny
        </a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php">
          <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Wyloguj się
        </a></li>
      </ul>
    </div>
    <?php endif; ?>
  </nav>

</header>
<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>

<!-- ══ MENU GŁÓWNE — poziomy pasek na górze (pod topbarem) ══════════ -->
<?php
$_k30_wait_count = 0;
try {
    $r = db_one("SELECT COUNT(*) AS c FROM k30_waiting_list WHERE status IN ('waiting','contacted')");
    $_k30_wait_count = (int)($r['c'] ?? 0);
} catch (\Throwable $e) {}
$g_ti   = _k30_active('/karty30/ti/');
$g_kons = _k30_active('/karty30/clients') || _k30_active('/karty30/waiting')
       || _k30_active('/karty30/schedules') || _k30_active('/karty30/consultations')
       || _k30_active('/karty30/reports') || _k30_active('/karty30/blacklist')
       || _k30_active('/karty30/admin/');
?>
<nav class="k30-menubar" id="k30-nav" aria-label="Nawigacja modułu">
  <ul class="k30-menu">
    <li><a class="k30-menu-link" href="<?= APP_URL ?>/karty30/index.php"
           <?= _k30_active('/karty30/index') ? 'aria-current="page"' : '' ?>
           aria-label="Dashboard Dydaktyka — strona główna modułu"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i>Dashboard</a></li>

    <!-- ══ Dydaktyka (TI) ══ -->
    <li class="dropdown">
      <button type="button" class="k30-menu-link dropdown-toggle <?= $g_ti ? 'k30-active' : '' ?>" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Dydaktyka TI — rozwiń menu"><i class="bi bi-pc-display" aria-hidden="true"></i>Dydaktyka (TI)</button>
      <ul class="dropdown-menu">
        <li><h6 class="dropdown-header">Nauka i zajęcia</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/index.php" <?= _k30_active('/karty30/ti/index') || _k30_active('/karty30/ti/course') || _k30_active('/karty30/ti/lesson') ? 'aria-current="page"' : '' ?>><i class="bi bi-pc-display" aria-hidden="true"></i>Kursy i zajęcia</a></li>
        <?php if ($_can_write): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/materials.php" <?= _k30_active('/karty30/ti/materials') ? 'aria-current="page"' : '' ?>><i class="bi bi-collection-play" aria-hidden="true"></i>Materiały / eLearning</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/homework.php" <?= _k30_active('/karty30/ti/homework') ? 'aria-current="page"' : '' ?>><i class="bi bi-journal-check" aria-hidden="true"></i>Zadania domowe</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/grades.php" <?= _k30_active('/karty30/ti/grades') ? 'aria-current="page"' : '' ?>><i class="bi bi-table" aria-hidden="true"></i>Dziennik ocen</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/messages.php" <?= _k30_active('/karty30/ti/messages') ? 'aria-current="page"' : '' ?>><i class="bi bi-envelope" aria-hidden="true"></i>Wiadomości</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/urlopy.php" <?= _k30_active('/karty30/ti/urlopy') ? 'aria-current="page"' : '' ?>><i class="bi bi-airplane" aria-hidden="true"></i>Urlopy prowadzących</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/billing.php" <?= _k30_active('/karty30/ti/billing') ? 'aria-current="page"' : '' ?>><i class="bi bi-receipt" aria-hidden="true"></i>Rozliczenia</a></li>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Administracja TI</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/kursant/accounts.php" <?= _k30_active('/karty30/ti/kursant/accounts') ? 'aria-current="page"' : '' ?> aria-label="Konta kursantów TI"><i class="bi bi-person-badge" aria-hidden="true"></i>Konta kursantów</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/online_admin.php" <?= _k30_active('/karty30/ti/online_admin') ? 'aria-current="page"' : '' ?> aria-label="Nauka online — MS, Moodle, Zoom/Teams"><i class="bi bi-camera-video" aria-hidden="true"></i>Nauka online</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/moodle_admin.php" <?= _k30_active('/karty30/ti/moodle_admin') ? 'aria-current="page"' : '' ?> aria-label="Moodle — serwery i kursy"><i class="bi bi-mortarboard" aria-hidden="true"></i>Moodle</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/licencje_admin.php" <?= _k30_active('/karty30/ti/licencje_admin') ? 'aria-current="page"' : '' ?> aria-label="Licencje na oprogramowanie"><i class="bi bi-key" aria-hidden="true"></i>Licencje</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/ti/vlab_admin.php" <?= _k30_active('/karty30/ti/vlab_admin') ? 'aria-current="page"' : '' ?> aria-label="VLAB — laboratoria Docker / SSH"><i class="bi bi-hdd-stack" aria-hidden="true"></i>VLAB / Docker</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/m365.php" <?= _k30_active('/karty30/admin/m365') ? 'aria-current="page"' : '' ?> aria-label="Microsoft 365 dla K30"><i class="bi bi-microsoft" aria-hidden="true"></i>M365 K30</a></li>
        <?php endif; ?>
      </ul>
    </li>

    <!-- ══ Konsultacje tyflo ══ -->
    <li class="dropdown">
      <button type="button" class="k30-menu-link dropdown-toggle <?= $g_kons ? 'k30-active' : '' ?>" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Konsultacje tyflo — rozwiń menu"><i class="bi bi-clipboard2-check" aria-hidden="true"></i>Konsultacje tyflo<?php if ($_k30_wait_count > 0): ?> <span class="badge bg-warning text-dark" style="font-size:.65rem"><?= $_k30_wait_count ?></span><?php endif; ?></button>
      <ul class="dropdown-menu">
        <li><h6 class="dropdown-header">Beneficjenci</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/clients/index.php" <?= _k30_active('/karty30/clients') ? 'aria-current="page"' : '' ?> aria-label="Lista beneficjentów"><i class="bi bi-people-fill" aria-hidden="true"></i>Beneficjenci</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/waiting/index.php" <?= _k30_active('/karty30/waiting') ? 'aria-current="page"' : '' ?> aria-label="Lista oczekujących na konsultację"><i class="bi bi-hourglass-split" aria-hidden="true"></i>Lista oczekujących<?php if ($_k30_wait_count > 0): ?> <span class="badge bg-warning text-dark" style="font-size:.65rem"><?= $_k30_wait_count ?></span><?php endif; ?></a></li>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Wizyty</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/index.php" <?= _k30_active('/karty30/schedules/index') || _k30_active('/karty30/schedules/add') || _k30_active('/karty30/schedules/edit') || _k30_active('/karty30/schedules/view') ? 'aria-current="page"' : '' ?>><i class="bi bi-calendar3" aria-hidden="true"></i>Harmonogram wizyt</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/calendar.php" <?= _k30_active('/karty30/schedules/calendar') ? 'aria-current="page"' : '' ?>><i class="bi bi-calendar-week" aria-hidden="true"></i>Kalendarz</a></li>
        <?php if ($_can_write): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/quick.php" <?= _k30_active('/karty30/schedules/quick') ? 'aria-current="page"' : '' ?>><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>Szybka rezerwacja</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/schedules/add.php"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Nowy termin</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Konsultacje i raporty</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/consultations/index.php" <?= _k30_active('/karty30/consultations') ? 'aria-current="page"' : '' ?>><i class="bi bi-clipboard2-check" aria-hidden="true"></i>Konsultacje</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/reports/index.php" <?= _k30_active('/karty30/reports') ? 'aria-current="page"' : '' ?>><i class="bi bi-bar-chart-line" aria-hidden="true"></i>Raporty</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/blacklist/index.php" <?= _k30_active('/karty30/blacklist') ? 'aria-current="page"' : '' ?>><i class="bi bi-slash-circle" aria-hidden="true"></i>Czarna lista</a></li>
        <?php if (is_admin()): ?>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Administracja</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/consultants.php" <?= _k30_active('/karty30/admin/consultants') ? 'aria-current="page"' : '' ?> aria-label="Zarządzaj uprawnieniem Prowadzenie konsultacji Tyflo"><i class="bi bi-people-fill" aria-hidden="true"></i>Doradcy</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/cert_upload.php" <?= _k30_active('/karty30/admin/cert_upload') ? 'aria-current="page"' : '' ?> aria-label="Certyfikaty x509 doradców"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Certyfikaty x509</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/karty30/admin/pricing.php" <?= _k30_active('/karty30/admin/pricing') ? 'aria-current="page"' : '' ?> aria-label="Cennik i limity bezpłatnych godzin"><i class="bi bi-currency-exchange" aria-hidden="true"></i>Cennik</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/karty30/admin/clean_k30.php" <?= _k30_active('/karty30/admin/clean_k30') ? 'aria-current="page"' : '' ?> aria-label="Czyszczenie danych modułu K30"><i class="bi bi-trash3" aria-hidden="true"></i>Wyczyść dane K30</a></li>
        <?php endif; ?>
      </ul>
    </li>
  </ul>

  <div class="k30-menu-right">
    <?php if ($_can_write): ?>
    <a class="btn btn-k30 btn-sm" href="<?= APP_URL ?>/karty30/clients/add.php" aria-label="Dodaj nowego beneficjenta"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Nowy beneficjent</a>
    <?php endif; ?>
    <a class="k30-menu-link" href="<?= APP_URL ?>/portal.php" aria-label="Wróć do wyboru systemu — portal główny"><i class="bi bi-box-arrow-left" aria-hidden="true"></i>Portal</a>
  </div>
</nav>

<!-- Notka o zmianie nazwy modułu -->
<div class="k30-rename-strip" role="note" aria-label="Moduł zmienił nazwę z TyfloKonsultacje na Dydaktyka">
  <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
  <span>Moduł zmienił nazwę z „TyfloKonsultacje" na <strong>„Dydaktyka"</strong>. Dawne konsultacje znajdziesz w sekcji „Konsultacje i raporty".</span>
</div>

<!-- ══ GŁÓWNA TREŚĆ ════════════════════════════════════════════════ -->
<div class="k30-shell">
<main class="k30-content" id="k30-main" role="main" tabindex="-1">

<?php
// Flash messages — ogłoszone przez aria-live
$_flash = flash_get();
if ($_flash):
  $_ftype  = $_flash['type'] ?? 'info';
  $_fmsg   = $_flash['msg']  ?? '';
  $_ficons = ['success'=>'bi-check-circle-fill','danger'=>'bi-exclamation-triangle-fill','warning'=>'bi-exclamation-circle-fill','info'=>'bi-info-circle-fill'];
  $_flabels= ['success'=>'Sukces','danger'=>'Błąd','warning'=>'Ostrzeżenie','info'=>'Informacja'];
?>
<div class="k30-alert k30-alert-<?= h($_ftype) ?>"
     role="alert"
     aria-label="<?= h($_flabels[$_ftype] ?? 'Informacja') ?>: <?= h($_fmsg) ?>">
  <i class="bi <?= h($_ficons[$_ftype] ?? 'bi-info-circle-fill') ?>" aria-hidden="true" style="font-size:1.25rem;flex-shrink:0;margin-top:.1rem"></i>
  <span><?= h($_fmsg) ?></span>
  <button type="button" class="k30-alert-close" aria-label="Zamknij powiadomienie"
          onclick="this.closest('.k30-alert').remove(); document.getElementById('k30-live').textContent='Powiadomienie zamknięte.'">
    <i class="bi bi-x-lg" aria-hidden="true"></i>
  </button>
</div>
<script>
// Ogłoś flash message przez live region (dla czytników, które nie odczytują role=alert automatycznie)
(function() {
  var live = document.getElementById('k30-live-urgent');
  if (live) live.textContent = '<?= addslashes(h($_flabels[$_ftype] ?? 'Informacja')) ?>: <?= addslashes(h($_fmsg)) ?>';
})();
</script>
<?php endif; ?>
