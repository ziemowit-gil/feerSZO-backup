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
<html lang="pl" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- Motyw jasny/ciemny: zastosuj zapamiętany wybór przed renderem (bez mignięcia) -->
<script>try{var t=localStorage.getItem('kp-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}</script>
<title><?= h($KP_TITLE) ?> — <?= h($KP_ORG) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --bs-primary:#2563eb; --bs-primary-rgb:37,99,235; --bs-link-color-rgb:96,165,250; }
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
</style>
</head>
<body class="<?= h($KP_BODY_CLASS) ?>">
<a class="skip-link btn btn-primary btn-sm" href="#main">Przejdź do treści</a>
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
        <button type="button" id="kp-theme-toggle" class="btn btn-outline-secondary btn-sm" aria-label="Przełącz motyw jasny/ciemny" title="Jasny / ciemny">
          <i class="bi bi-circle-half" aria-hidden="true"></i>
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
<button type="button" id="kp-theme-toggle" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3" style="z-index:1080" aria-label="Przełącz motyw jasny/ciemny" title="Jasny / ciemny">
  <i class="bi bi-circle-half" aria-hidden="true"></i>
</button>
<?php endif; ?>
