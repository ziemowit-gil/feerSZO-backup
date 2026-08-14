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

var fs=localStorage.getItem('kp-fontscale');if(fs&&fs!=='0')d.setAttribute('data-kp-font',fs);
if(localStorage.getItem('kp-hidemenu')==='1')d.classList.add('kp-hidemenu');

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
</style>
</head>
<body class="<?= h($KP_BODY_CLASS) ?>"<?php if (!empty($vapid_public_key ?? '')): ?> data-vapid-key="<?= h($vapid_public_key) ?>"<?php endif; ?>>
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
<?php endif; ?>
