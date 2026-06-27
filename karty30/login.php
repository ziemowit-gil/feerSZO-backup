<?php
/**
 * karty30/login.php — Samodzielne wejście do modułu Dydaktyka (Karty 30).
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
// Domyślnie wracamy do modułu Karty 30 (a nie do portalu).
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
$action = $APP . '/auth/login.php?redirect=' . urlencode($redirect);
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
:root { --bs-primary:#c2410c; --bs-primary-rgb:194,65,12; --bs-link-color-rgb:194,65,12; }
.btn-primary { --bs-btn-bg:#c2410c; --bs-btn-border-color:#c2410c; --bs-btn-hover-bg:#9a3412; --bs-btn-hover-border-color:#9a3412; --bs-btn-active-bg:#7c2d12; }
.text-primary { color:#c2410c !important; }
*:focus-visible { outline:3px solid #facc15 !important; outline-offset:2px !important; box-shadow:none !important; }
body { min-height:100vh; display:flex; align-items:center; background:linear-gradient(160deg,#7c2d12 0%,#c2410c 55%,#ea580c 100%); }
.k30-login-card { max-width:420px; width:100%; border:0; border-radius:1rem; box-shadow:0 18px 50px rgba(0,0,0,.35); }
.k30-login-logo { width:60px; height:60px; border-radius:.9rem; background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; }
</style>
</head>
<body>
<a href="#k30-login-main" class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-light btn-sm">Przejdź do formularza</a>
<main id="k30-login-main" class="container py-5">
  <div class="d-flex flex-column align-items-center">
    <div class="text-center text-white mb-4">
      <div class="k30-login-logo mx-auto mb-3"><i class="bi bi-card-checklist fs-2" aria-hidden="true"></i></div>
      <h1 class="h4 fw-bold mb-1">Dydaktyka — Karty 30</h1>
      <?php if ($org): ?><p class="mb-0 opacity-75 small"><?= h_($org) ?></p><?php endif; ?>
    </div>

    <div class="card k30-login-card">
      <div class="card-body p-4 p-sm-5">
        <h2 class="h5 fw-bold mb-3">Zaloguj się</h2>

        <?php if ($err !== ''): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2 py-2" role="alert">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <span><?= h_($err) ?></span>
        </div>
        <?php endif; ?>

        <form method="post" action="<?= h_($action) ?>" novalidate>
          <input type="hidden" name="_csrf" value="<?= h_($token) ?>">
          <input type="hidden" name="_method" value="local">
          <input type="hidden" name="from" value="<?= h_($APP . '/karty30/login.php?redirect=' . urlencode($redirect)) ?>">

          <div class="mb-3">
            <label for="email" class="form-label fw-semibold">Adres e-mail</label>
            <div class="input-group">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
              <input type="email" class="form-control form-control-lg" id="email" name="email"
                     autocomplete="username" required autofocus placeholder="imie.nazwisko@org.pl">
            </div>
          </div>

          <div class="mb-4">
            <label for="password" class="form-label fw-semibold">Hasło</label>
            <div class="input-group">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
              <input type="password" class="form-control form-control-lg" id="password" name="password"
                     autocomplete="current-password" required>
              <button class="btn btn-outline-secondary" type="button" id="togglePw" aria-label="Pokaż lub ukryj hasło">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj do modułu
          </button>
        </form>

        <hr class="my-4">
        <div class="d-flex flex-wrap justify-content-between gap-2 small">
          <a href="<?= h_($APP) ?>/auth/login.php?redirect=<?= h_(urlencode($redirect)) ?>" class="link-secondary text-decoration-none">
            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Inne metody logowania
          </a>
          <a href="<?= h_($APP) ?>/index.php" class="link-secondary text-decoration-none">
            <i class="bi bi-house me-1" aria-hidden="true"></i>System główny
          </a>
        </div>
      </div>
    </div>

    <p class="text-white-50 small mt-4 mb-0">Logowanie chronione: 2FA / WebAuthn obsługiwane przez system główny.</p>
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
