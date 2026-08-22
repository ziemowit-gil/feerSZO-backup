<?php
/**
 * karty30/login.php — Samodzielne wejście do modułu Dydaktyka (Dydaktyka 3).
 *
 * Branded ekran logowania modułu. Aby NIE obchodzić zabezpieczeń systemu
 * (brute-force, 2FA, WebAuthn, wymuszona zmiana hasła), formularz wysyła dane
 * do istniejącego /auth/login.php z parametrem redirect wracającym do modułu.
 *
 * Odporność: korzysta wyłącznie z rdzenia (config/db/auth/functions). Gdy część
 * opcjonalna jest nieobecna, strona i tak się renderuje.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$APP   = rtrim(APP_URL, '/');
$index = $APP . '/karty30/index.php';

// Dokąd wrócić po zalogowaniu — tylko adresy w obrębie aplikacji.
$raw      = $_GET['redirect'] ?? '';
$redirect = ($raw && str_starts_with($raw, $APP . '/')) ? $raw : $index;
// Domyślnie wracamy do modułu Dydaktyka 3 (a nie do portalu).
if (!str_contains($redirect, '/karty30/')) $redirect = $index;

// Już zalogowany z dostępem → prosto do modułu.
if (function_exists('current_user') && current_user()) {
    $has = (function_exists('can_read') && can_read('karty30')) || (function_exists('is_admin') && is_admin());
    if (!$has) {
        try { $u = current_user(); $r = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)($u['id'] ?? 0)]); $has = !empty($r['k30_consultant']); } catch (\Throwable $e) {}
    }
    if ($has) { header('Location: ' . $redirect); exit; }
}

$org   = function_exists('org_setting') ? (org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '')) : (defined('ORG_NAME') ? ORG_NAME : '');
$token = function_exists('csrf_token') ? csrf_token() : '';
$err   = trim((string)($_GET['err'] ?? ''));
// Akcja formularza: pełne zabezpieczenia obsługuje /auth/login.php
$action    = $APP . '/auth/login.php?redirect=' . urlencode($redirect);
$dyd_url   = $APP . '/karty30/ti/dydaktyk/login.php';            // panel prowadzącego (osobna sesja)
$admin_url = $APP . '/auth/login.php?redirect=' . urlencode($redirect); // pełne logowanie (admin: 2FA/WebAuthn/x509/MS365)
// Microsoft 365 SSO — dostępne, gdy skonfigurowano tenant + client_id
$ms_ok  = function_exists('ms_login_available') && ms_login_available();
$ms_url = $ms_ok && function_exists('ms_auth_url') ? ms_auth_url($redirect) : '';
function h_($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie — Dydaktyka<?= $org ? ' · ' . h_($org) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
:root { --bs-primary:#2563eb; --bs-primary-rgb:37,99,235; --bs-link-color-rgb:37,99,235; }
.btn-primary {
  --bs-btn-bg:#2563eb; --bs-btn-border-color:#2563eb;
  --bs-btn-hover-bg:#1d4ed8; --bs-btn-hover-border-color:#1d4ed8;
  --bs-btn-active-bg:#1e3a8a;
}
.btn-outline-primary {
  --bs-btn-color:#2563eb; --bs-btn-border-color:#2563eb;
  --bs-btn-hover-bg:#2563eb; --bs-btn-hover-border-color:#2563eb;
  --bs-btn-hover-color:#fff;
}
.text-primary { color:#1d4ed8 !important; }
*:focus-visible { outline:3px solid #facc15 !important; outline-offset:2px !important; box-shadow:none !important; }

body {
  min-height:100vh;
  display:flex;
  background:#1b2e45;
}

/* Lewa kolumna — dekoracyjna */
.k30-brand-side {
  display:none;
  width:400px;
  flex-shrink:0;
  position:relative;
  overflow:hidden;
  background:linear-gradient(160deg,#13233a 0%,#1b2e45 55%,#2563eb 100%);
}
@media (min-width:900px){ .k30-brand-side { display:flex; flex-direction:column; justify-content:center; padding:3rem; } }

.k30-brand-side .brand-dots {
  position:absolute; inset:0;
  background-image:radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px);
  background-size:24px 24px;
}
.k30-brand-side .brand-ring {
  position:absolute;
  border:1px solid rgba(255,255,255,.12);
  border-radius:50%;
}

/* Prawa kolumna — formularz */
.k30-form-side {
  flex:1;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:2rem 1rem;
  background:#fff;
  border-radius:0;
}
@media (min-width:900px){
  .k30-form-side { border-radius:0 0 0 0; }
}

.k30-login-card {
  max-width:400px;
  width:100%;
}
.k30-login-logo {
  width:56px; height:56px;
  border-radius:14px;
  background:linear-gradient(135deg,#1b2e45,#2563eb);
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 4px 14px rgba(194,65,12,.35);
}

.form-control { border-radius:9px !important; border-color:#e2e8f0; }
.form-control:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.14) !important; }
.input-group-text { border-color:#e2e8f0; background:#f8fafc; border-radius:9px 0 0 9px !important; }
.input-group .form-control { border-left:0; }
.btn-outline-secondary { border-color:#e2e8f0; border-radius:0 9px 9px 0 !important; }
.btn-outline-secondary:hover { background:#f1f5f9; }
</style>
</head>
<body>
<a href="#k30-login-main" class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-light btn-sm">Przejdź do formularza</a>

<!-- Lewa kolumna — branding -->
<div class="k30-brand-side" aria-hidden="true">
  <div class="brand-dots"></div>
  <!-- Pierścienie dekoracyjne -->
  <div class="brand-ring" style="width:320px;height:320px;top:-80px;right:-120px"></div>
  <div class="brand-ring" style="width:180px;height:180px;bottom:60px;left:-60px"></div>

  <div style="position:relative;z-index:1">
    <div class="d-inline-flex align-items-center justify-content-center rounded-3 mb-4"
         style="width:64px;height:64px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25)">
      <i class="bi bi-card-checklist text-white" style="font-size:2rem"></i>
    </div>
    <h1 class="text-white fw-black mb-1" style="font-size:1.6rem;letter-spacing:-.02em;line-height:1.2">Dydaktyka 3</h1>
    <p class="text-white mb-4" style="opacity:.65;font-size:.875rem;max-width:280px;line-height:1.5">Zintegrowany system obsługi beneficjentów, konsultacji i edukacji.</p>

    <div class="d-flex flex-column gap-2" style="max-width:260px">
      <?php foreach ([['bi-clipboard2-pulse','Konsultacje i wizyty'],['bi-pc-display','Dydaktyka (TI)'],['bi-building-fill-check','Dokumenty PFRON']] as [$ico,$lbl]): ?>
      <div class="d-flex align-items-center gap-2 text-white" style="font-size:.83rem;opacity:.8">
        <i class="bi <?= $ico ?>" style="width:18px;text-align:center"></i><?= $lbl ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Prawa kolumna — formularz -->
<main id="k30-login-main" class="k30-form-side">
  <div class="k30-login-card">

    <div class="d-flex align-items-center gap-3 mb-4">
      <div class="k30-login-logo flex-shrink-0">
        <i class="bi bi-card-checklist text-white" style="font-size:1.5rem" aria-hidden="true"></i>
      </div>
      <div>
        <h1 class="h5 fw-black mb-0" style="letter-spacing:-.01em">Zaloguj się</h1>
        <p class="text-muted mb-0" style="font-size:.78rem">Moduł Dydaktyka<?= $org ? ' · ' . h_($org) : '' ?></p>
      </div>
    </div>

    <?php if ($err !== ''): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2 py-2 mb-3 rounded-3" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
      <span style="font-size:.875rem"><?= h_($err) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($ms_ok): ?>
    <a href="<?= h_($ms_url) ?>" class="btn btn-outline-primary w-100 mb-3 d-flex align-items-center justify-content-center gap-2 rounded-3" style="padding:.65rem">
      <i class="bi bi-microsoft" aria-hidden="true"></i>Zaloguj przez Microsoft 365
    </a>
    <div class="d-flex align-items-center gap-2 text-muted mb-3" style="font-size:.78rem">
      <span class="flex-grow-1 border-top"></span>lub e-mailem i hasłem<span class="flex-grow-1 border-top"></span>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= h_($action) ?>" novalidate>
      <input type="hidden" name="_csrf" value="<?= h_($token) ?>">
      <input type="hidden" name="_method" value="local">
      <input type="hidden" name="from" value="<?= h_($APP . '/karty30/login.php?redirect=' . urlencode($redirect)) ?>">

      <div class="mb-3">
        <label for="email" class="form-label fw-semibold" style="font-size:.875rem">Adres e-mail</label>
        <div class="input-group">
          <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope text-muted"></i></span>
          <input type="email" class="form-control" id="email" name="email"
                 autocomplete="username" required autofocus placeholder="imie.nazwisko@org.pl"
                 style="padding:.65rem .85rem">
        </div>
      </div>

      <div class="mb-4">
        <label for="password" class="form-label fw-semibold" style="font-size:.875rem">Hasło</label>
        <div class="input-group">
          <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock text-muted"></i></span>
          <input type="password" class="form-control" id="password" name="password"
                 autocomplete="current-password" required style="padding:.65rem .85rem">
          <button class="btn btn-outline-secondary" type="button" id="togglePw" aria-label="Pokaż lub ukryj hasło">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 fw-semibold rounded-3" style="padding:.7rem">
        <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj do modułu
      </button>
    </form>

    <hr class="my-4" style="border-color:#f1f5f9">
    <p class="fw-semibold text-muted mb-2" style="font-size:.8rem">Logujesz się w innej roli?</p>
    <div class="d-grid gap-2">
      <a href="<?= h_($dyd_url) ?>" class="btn btn-outline-secondary btn-sm text-start d-flex align-items-center gap-2 rounded-3" style="font-size:.82rem">
        <i class="bi bi-easel2 flex-shrink-0 text-muted" aria-hidden="true"></i>
        <span>Jesteś <strong>dydaktykiem (prowadzącym)</strong>? Zaloguj się tutaj</span>
      </a>
      <a href="<?= h_($admin_url) ?>" class="btn btn-outline-secondary btn-sm text-start d-flex align-items-center gap-2 rounded-3" style="font-size:.82rem">
        <i class="bi bi-shield-lock flex-shrink-0 text-muted" aria-hidden="true"></i>
        <span>Jesteś <strong>administratorem</strong>? Zaloguj się tutaj<?= $ms_ok ? ' — także przez Microsoft 365' : '' ?></span>
      </a>
    </div>
    <div class="text-center mt-3">
      <a href="<?= h_($APP) ?>/index.php" class="link-secondary text-decoration-none" style="font-size:.8rem">
        <i class="bi bi-house me-1" aria-hidden="true"></i>System główny
      </a>
    </div>

    <p class="text-muted text-center mt-4 mb-0" style="font-size:.72rem">Logowanie chronione: 2FA / WebAuthn obsługiwane przez system główny.</p>
  </div>
</main>

<script>
(function(){
  var b = document.getElementById('togglePw'), p = document.getElementById('password');
  if (b && p) b.addEventListener('click', function(){
    var show = p.type === 'password'; p.type = show ? 'text' : 'password';
    b.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  });
})();
</script>
</body>
</html>
