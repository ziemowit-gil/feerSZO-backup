<?php
/**
 * Wspólny nagłówek panelu kursanta/rodzica — Bootstrap 5.3 (dark) + WCAG 2.1 AA.
 * Zmienne wejściowe (opcjonalne):
 *   $KP_TITLE      — tytuł strony,
 *   $KP_TOPBAR     — ['brand'=>, 'icon'=>, 'user'=>, 'logout'=>, 'extra'=>HTML] lub null (brak paska),
 *   $KP_BODY_CLASS — dodatkowe klasy <body>,
 *   $KP_EXTRA_CSS  — lista arkuszy dokładanych PO bloku <style> (skórki panelu).
 */
$KP_ORG        = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$KP_TITLE      = $KP_TITLE      ?? 'Panel kursanta';
$KP_TOPBAR     = $KP_TOPBAR     ?? null;
$KP_BODY_CLASS = $KP_BODY_CLASS ?? '';
// Panel dydaktyka: sekcje w pasku + menu użytkownika (dydaktyk/_nav.php).
// Panel kursanta nie ładuje tej funkcji — jego pasek zostaje jak był.
if ($KP_TOPBAR && function_exists('dyd_topbar_enrich') && ($KP_TOPBAR['brand'] ?? '') === 'Panel dydaktyka') {
    $KP_TOPBAR = dyd_topbar_enrich($KP_TOPBAR, str_contains((string)$KP_BODY_CLASS, 'ti-skin'));
}
$KP_EXTRA_CSS  = $KP_EXTRA_CSS  ?? [];
?><!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($KP_TITLE) ?> — <?= h($KP_ORG) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php
// FullCalendar v6 (~270 KB) — duży kalendarz przy wyborze terminu (zmiana terminu lekcji).
// Panel kursanta: jak dotąd zawsze. Panel dydaktyka: tylko gdy strona go zamówi
// ($KP_FULLCALENDAR = true — index.php na zakładce Zajęcia), inaczej ładował się na próżno.
$KP_FULLCALENDAR = $KP_FULLCALENDAR ?? (($KP_TOPBAR['brand'] ?? '') !== 'Panel dydaktyka');
if ($KP_FULLCALENDAR): ?>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<?php endif; ?>
<style>
:root {
  --bs-primary:#2563eb; --bs-primary-rgb:37,99,235; --bs-link-color-rgb:96,165,250;
  --kp-body-bg:#ffffff; --kp-primary:#2563eb; --kp-primary-rgb:37,99,235;
  --kp-primary-hover:#1d4ed8; --kp-focus:#60a5fa; --kp-brand-gradient:linear-gradient(150deg,#1e3a8a 0%,#2563eb 45%,#7c3aed 100%);
}
:root {
  --bs-primary:var(--kp-primary); --bs-primary-rgb:var(--kp-primary-rgb);
  --bs-link-color-rgb:var(--kp-primary-rgb);
}
.btn-primary {
  --bs-btn-bg:var(--kp-primary); --bs-btn-border-color:var(--kp-primary);
  --bs-btn-hover-bg:var(--kp-primary-hover); --bs-btn-hover-border-color:var(--kp-primary-hover);
}
body { min-height:100vh; }
a:focus-visible, button:focus-visible, .btn:focus-visible,
.form-control:focus-visible, .nav-link:focus-visible, [tabindex]:focus-visible {
  outline:3px solid var(--kp-focus,#60a5fa); outline-offset:2px; box-shadow:none;
}
.skip-link { position:absolute; left:.5rem; top:.5rem; z-index:1080; transform:translateY(-200%); transition:transform .15s ease; }
.skip-link:focus { transform:translateY(0); }
.kp-brand i { color:var(--kp-primary,#60a5fa); }
.nav-tabs .nav-link.active { font-weight:600; }

/* ── Ekran logowania ────────────────────────────────────────────────────── */
body:has(.kp-auth-wrap) {
  background:
    radial-gradient(ellipse 900px 620px at 12% -8%, rgba(var(--kp-primary-rgb,37,99,235),.12), transparent 60%),
    radial-gradient(ellipse 760px 560px at 105% 108%, rgba(var(--kp-primary-rgb,37,99,235),.09), transparent 55%);
  background-repeat:no-repeat;
}
.kp-auth-wrap { width:100%; max-width:940px; }
.kp-auth-card {
  overflow:hidden; border-radius:1.25rem;
  border:1px solid rgba(255,255,255,.6);
  box-shadow:0 24px 70px rgba(2,6,23,.45), 0 2px 8px rgba(2,6,23,.18);
  animation:kpAuthIn .4s cubic-bezier(.16,.84,.44,1) both;
}
@keyframes kpAuthIn { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:none; } }
.kp-auth-logo {
  width:64px; height:64px; border-radius:1rem;
  background:var(--kp-brand-gradient,linear-gradient(135deg,#2563eb,#7c3aed));
  color:#fff; box-shadow:0 10px 24px rgba(var(--kp-primary-rgb,37,99,235),.35);
  animation:kpLogoFloat 5s ease-in-out infinite;
}
@keyframes kpLogoFloat { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-4px); } }
.kp-auth-card .form-control { padding:.7rem .95rem; border-radius:.6rem; border-width:1.5px; }
.kp-auth-card .form-control-lg { font-size:1rem; }
.kp-auth-card .input-group-text { transition:color .15s ease, border-color .15s ease; }
.kp-auth-card .input-group:focus-within .input-group-text {
  color:var(--kp-primary,#2563eb); border-color:var(--kp-primary,#2563eb);
}
.kp-auth-card .btn-primary {
  background:linear-gradient(135deg, var(--kp-primary,#2563eb), var(--kp-primary-hover,#1d4ed8));
  border:1px solid transparent;
  box-shadow:0 8px 20px rgba(var(--kp-primary-rgb,37,99,235),.30);
  transition:filter .15s, box-shadow .15s, transform .12s;
}
.kp-auth-card .btn-primary:hover { filter:brightness(1.06); box-shadow:0 10px 26px rgba(var(--kp-primary-rgb,37,99,235),.40); transform:translateY(-1px); }
.kp-auth-card .btn-primary:active { transform:translateY(0); }
@media (prefers-reduced-motion:reduce){
  .kp-auth-card,.kp-auth-logo { animation:none; }
  .kp-auth-card .btn-primary { transition:none; }
}
.kp-term-hero {
  background:#0b1120; color:#fff; position:relative; overflow:hidden;
  display:flex; flex-direction:column; justify-content:space-between;
}
.kp-term-hero::before {
  content:""; position:absolute; inset:0; pointer-events:none;
  background:radial-gradient(circle at 20% 12%, rgba(var(--kp-primary-rgb,37,99,235),.35), transparent 55%),
             radial-gradient(circle at 92% 92%, rgba(var(--kp-primary-rgb,37,99,235),.18), transparent 50%);
}
.kp-term-hero > * { position:relative; z-index:1; }
.kp-term-window {
  background:#111827; border:1px solid rgba(255,255,255,.08); border-radius:.75rem;
  box-shadow:0 20px 45px rgba(0,0,0,.45); overflow:hidden;
}
.kp-term-bar {
  display:flex; align-items:center; gap:.35rem; padding:.55rem .75rem;
  background:#1a2233; border-bottom:1px solid rgba(255,255,255,.06);
}
.kp-term-dot { width:.6rem; height:.6rem; border-radius:50%; display:inline-block; }
.kp-term-dot-r { background:#ff5f57; } .kp-term-dot-y { background:#febc2e; } .kp-term-dot-g { background:#28c840; }
.kp-term-title { margin-left:.5rem; font-size:.72rem; color:rgba(255,255,255,.5); font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
.kp-term-body { padding:.9rem 1rem 1.1rem; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.82rem; line-height:1.7; }
.kp-term-line { white-space:pre; }
.kp-term-prompt { color:#4ade80; font-weight:700; margin-right:.4rem; }
.kp-term-dim { color:rgba(255,255,255,.55); }
.kp-term-cursor { display:inline-block; width:.5rem; height:1em; background:#e5e7eb; vertical-align:text-bottom; animation:kpTermBlink 1s steps(2,start) infinite; }
@keyframes kpTermBlink { to { visibility:hidden; } }
@media (prefers-reduced-motion:reduce){ .kp-term-cursor { animation:none; } }
@media (max-width:767.98px){ .kp-term-hero { display:none !important; } }

/* ── eLearning / zadania ───────────────────────────────────────────────── */
.dyd-hw-summary { cursor:pointer; list-style:none; }
.dyd-hw-summary::-webkit-details-marker { display:none; }
.dyd-hw-summary::marker { content:""; }
.dyd-hw-summary:hover { background:var(--bs-tertiary-bg); }
.dyd-hw-summary:focus-visible { outline:3px solid #60a5fa; outline-offset:-3px; }
.dyd-hw-chevron { transition:transform .15s ease; }
.dyd-hw[open] > .dyd-hw-summary .dyd-hw-chevron { transform:rotate(180deg); }
@media (prefers-reduced-motion:reduce){ .skip-link,.dyd-hw-chevron { transition:none; } }

/* ── Kalendarz lekcji ──────────────────────────────────────────────────── */
.kp-cal { table-layout:fixed; }
.kp-cal th,.kp-cal td { width:14.28%; }
.kp-cal td { vertical-align:top; height:64px; padding:.25rem; }
.kp-cal-empty { background:var(--bs-tertiary-bg); }
.kp-cal-day.has-lesson { background:rgba(37,99,235,.12); }
.kp-cal-day.is-today { outline:2px solid var(--bs-primary); outline-offset:-2px; }
.kp-cal-num { font-size:.8rem; color:var(--bs-secondary-color); }
.kp-cal-ev { font-size:.72rem; line-height:1.2; background:var(--bs-primary); color:#fff; border-radius:.25rem; padding:1px 4px; margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.kp-cal-ev.cancelled { background:var(--bs-secondary-bg); color:var(--bs-secondary-color); text-decoration:line-through; }

/* ── Duży kalendarz przy wyborze terminu (FullCalendar) ─────────────────── */
.ti-term-calendar { padding:.5rem; }
.ti-term-calendar .fc-toolbar-title { font-size:1rem; }
.ti-term-calendar .fc-daygrid-day.ti-selected-day { background:rgba(var(--kp-primary-rgb,37,99,235),.18); }
.ti-term-calendar .fc-daygrid-day.ti-selected-day .fc-daygrid-day-number { font-weight:700; color:var(--kp-primary,#2563eb); }
.ti-term-calendar .fc-daygrid-day-frame { cursor:pointer; }

/* ── Menu dostępności (a11y) ───────────────────────────────────────────── */
html[data-kp-font="1"] { font-size:112.5%; }
html[data-kp-font="2"] { font-size:125%; }
html[data-kp-font="3"] { font-size:140%; }
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
html.kp-hidemenu nav[aria-label="Sekcje panelu"] { display:none !important; }
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

/* ── Dashboard / mobile nav ────────────────────────────────────────────── */
@media (max-width:991.98px) { main { padding-bottom:70px !important; } }
.kp-progress { height:8px; border-radius:4px; }
.kp-dash-card { transition:box-shadow .15s; }
.kp-dash-card:hover { box-shadow:0 .25rem .75rem rgba(0,0,0,.12) !important; }
a.kp-dash-card:focus-visible { outline:3px solid rgba(var(--bs-primary-rgb),.55); outline-offset:2px; }
/* Dolny pasek (telefon) i panel „Więcej” — index.php */
.kp-bottomnav { z-index:1030; box-shadow:0 -.25rem .75rem rgba(0,0,0,.06); }
.kp-bn-item { display:flex; flex-direction:column; align-items:center; min-width:60px; min-height:48px; padding:.25rem .4rem; text-decoration:none; border:0; background:none; }
.kp-bn-item .bi { font-size:1.35rem; line-height:1.1; }
.kp-bn-label { font-size:.72rem; line-height:1.2; }
.kp-bn-badge { position:absolute; top:0; right:6px; font-size:.62rem; padding:.2em .45em; }
.kp-bn-item:focus-visible, .kp-more-item:focus-visible { outline:3px solid rgba(var(--bs-primary-rgb),.55); outline-offset:1px; border-radius:.4rem; }
.kp-more { height:auto; max-height:85vh; border-top-left-radius:1rem; border-top-right-radius:1rem; }
.kp-more-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:.35rem; }
@media (min-width:576px) { .kp-more-grid { grid-template-columns:repeat(3, minmax(0,1fr)); } }
.kp-more-item { display:flex; align-items:center; gap:.5rem; min-height:44px; padding:.45rem .6rem; border-radius:.5rem;
  text-decoration:none; color:var(--bs-body-color); background:var(--bs-tertiary-bg); font-size:.9rem; }
.kp-more-item .bi { font-size:1.1rem; color:var(--bs-primary); flex-shrink:0; }
.kp-more-item.active { background:rgba(var(--bs-primary-rgb),.12); color:var(--bs-primary); font-weight:600; }
.kp-more-item:hover { background:rgba(var(--bs-primary-rgb),.08); }
.kp-mini-cal { width:100%; table-layout:fixed; font-size:.82rem; }
.kp-mini-cal th { text-align:center; padding:.25rem; color:var(--bs-secondary-color); font-weight:600; }
.kp-mini-cal td { text-align:center; padding:.3rem .1rem; vertical-align:top; }
.kp-mini-cal .cal-today { background:rgba(var(--bs-primary-rgb),.1); border-radius:.3rem; }
.kp-mini-cal .cal-dot { width:6px; height:6px; border-radius:50%; display:inline-block; margin:1px; }
.kp-mini-cal .cal-dot-held { background:#22c55e; }
.kp-mini-cal .cal-dot-planned { background:#3b82f6; }
.kp-mini-cal .cal-dot-cancelled { background:#94a3b8; }

/* ── Login tiles / logout-alternatives ─────────────────────────────────── */
.kp-login-tiles { display:flex; flex-direction:column; gap:.5rem; }
</style>
<?php /* Skórki panelu linkujemy PO bloku <style> — inaczej bazowe reguły
         panelu wygrywają przy równej specyficzności. */ ?>
<?php foreach ($KP_EXTRA_CSS as $_css): ?>
<link rel="stylesheet" href="<?= h($_css) ?>">
<?php endforeach; ?>
</head>
<body class="<?= h($KP_BODY_CLASS) ?>"<?php if (!empty($vapid_public_key ?? '')): ?> data-vapid-key="<?= h($vapid_public_key) ?>"<?php endif; ?>>
<a class="skip-link btn btn-primary btn-sm" href="#main">Przejdź do treści</a>

<?php $KP_IMP = function_exists('student_impersonator') ? student_impersonator() : null; if ($KP_IMP): ?>
<div class="alert alert-warning border-0 rounded-0 mb-0 py-2" role="alert">
  <div class="container-fluid d-flex flex-wrap align-items-center gap-2 small">
    <i class="bi bi-incognito" aria-hidden="true"></i>
    <span>Podgląd panelu jako kursant — zalogowano przez administratora<?= !empty($KP_IMP['name']) ? ' (' . h($KP_IMP['name']) . ')' : '' ?>.</span>
    <a href="<?= APP_URL ?>/karty30/ti/dydaktyk/index.php"
       class="btn btn-sm btn-outline-dark py-0 ms-auto"
       title="Pełny interfejs modułu Dydaktyka">
      <i class="bi bi-grid-1x2-fill me-1" aria-hidden="true"></i>Pełny interfejs
    </a>
    <a href="index.php?stop_impersonation=1" class="btn btn-sm btn-warning py-0">
      <i class="bi bi-box-arrow-left me-1" aria-hidden="true"></i>Zakończ podgląd
    </a>
  </div>
</div>
<?php endif; ?>
<?php if ($KP_TOPBAR): ?>
<header>
  <nav class="navbar bg-body-tertiary border-bottom" aria-label="Pasek użytkownika">
    <?php /* flex-wrap: przy wielu elementach po prawej (np. przełącznik grup +
             badge kierownika + przełącznik widoku) pasek ma się ZAWIJAĆ, a nie
             wypychać ostatnich przycisków poza ekran. */ ?>
    <div class="container-fluid flex-wrap gap-2">
      <span class="navbar-brand kp-brand d-flex align-items-center gap-2 mb-0 fw-bold">
        <i class="bi bi-<?= h($KP_TOPBAR['icon'] ?? 'pc-display') ?>" aria-hidden="true"></i>
        <span><?= h($KP_TOPBAR['brand'] ?? $KP_ORG) ?></span>
      </span>
      <?php if (!empty($KP_TOPBAR['nav'])): ?><?= $KP_TOPBAR['nav'] ?><?php endif; ?>
      <div class="d-flex align-items-center flex-wrap gap-2 gap-lg-3 ms-auto">
        <?php if (!empty($KP_TOPBAR['notifications'])): ?><?= $KP_TOPBAR['notifications'] ?><?php endif; ?>
        <?php // Panel dydaktyka (user_menu): „Nowy panel” schowany do menu użytkownika — pasek bez zbędnych przycisków
        if (defined('KURSANT_NEW_UI_ENABLED') && KURSANT_NEW_UI_ENABLED && defined('KURSANT_NEW_UI_URL') && empty($KP_TOPBAR['user_menu'])): ?>
        <a href="<?= h(rtrim(KURSANT_NEW_UI_URL, '/') . '/') ?>"
           class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1"
           title="Przejdź do nowej wersji panelu">
          <i class="bi bi-stars" aria-hidden="true"></i>
          <span class="d-none d-sm-inline">Nowy panel</span>
        </a>
        <?php endif; ?>
        <?php if (!empty($KP_TOPBAR['extra'])): ?><?= $KP_TOPBAR['extra'] ?><?php endif; ?>
        <?php if (!empty($KP_TOPBAR['user_menu'])): $_um = $KP_TOPBAR['user_menu']; ?>
        <?php if (!empty($_um['work_as'])): $_wa = $_um['work_as']; ?>
        <span class="kp-work-as d-none d-md-inline-flex align-items-center gap-1<?= !empty($_wa['acting']) ? ' kp-acting' : '' ?>">
          <i class="bi bi-<?= h($_wa['icon'] ?? 'person-gear') ?>" aria-hidden="true"></i>Pracujesz jako: <strong><?= h($_wa['label']) ?></strong>
          <?php if (!empty($_wa['change_href'])): ?>&middot; <a href="<?= h($_wa['change_href']) ?>"><?= h($_wa['change_label']) ?></a><?php endif; ?>
        </span>
        <?php endif; ?>
        <div class="dropdown kp-user-menu">
          <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-2 py-1"
                  data-bs-toggle="dropdown" aria-expanded="false" title="<?= h($_um['name']) ?> — <?= h($_um['role']) ?>">
            <i class="bi bi-<?= h($_um['icon'] ?? 'person-circle') ?>" aria-hidden="true"></i>
            <span class="d-none d-md-flex flex-column align-items-start lh-sm text-start">
              <span class="fw-semibold text-truncate" style="max-width:11rem"><?= h($_um['name']) ?></span>
              <span class="kp-user-role"><?= h($_um['role']) ?></span>
            </span>
            <span class="visually-hidden">Menu użytkownika</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" style="min-width:15rem">
            <li class="px-3 py-2 lh-sm">
              <div class="fw-semibold text-truncate"><?= h($_um['name']) ?></div>
              <div class="small text-body-secondary"><i class="bi bi-<?= h($_um['icon'] ?? 'person') ?> me-1" aria-hidden="true"></i><?= h($_um['role']) ?></div>
              <?php if (!empty($_um['note'])): ?><div class="small text-warning-emphasis"><?= h($_um['note']) ?></div><?php endif; ?>
            </li>
            <li><hr class="dropdown-divider my-1"></li>
            <?php foreach (($_um['items'] ?? []) as [$_l, $_h, $_i]): ?>
            <li><a class="dropdown-item d-flex align-items-center gap-2" href="<?= h($_h) ?>"><i class="bi bi-<?= h($_i) ?>" aria-hidden="true"></i><?= h($_l) ?></a></li>
            <?php endforeach; ?>
            <?php if (defined('KURSANT_NEW_UI_ENABLED') && KURSANT_NEW_UI_ENABLED && defined('KURSANT_NEW_UI_URL')): ?>
            <li><a class="dropdown-item d-flex align-items-center gap-2" href="<?= h(rtrim(KURSANT_NEW_UI_URL, '/') . '/') ?>"><i class="bi bi-stars" aria-hidden="true"></i>Nowy panel</a></li>
            <?php endif; ?>
            <?php if (!empty($KP_TOPBAR['logout'])): ?>
            <li><hr class="dropdown-divider my-1"></li>
            <li><a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="<?= h($KP_TOPBAR['logout']) ?>"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Wyloguj</a></li>
            <?php endif; ?>
          </ul>
        </div>
        <?php else: ?>
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
        <?php endif; ?>
      </div>
    </div>
  </nav>
</header>
<?php else: ?>
<?php endif; ?>
