<?php
/**
 * tozsamosc/login.php — Osobne logowanie do modułu „Tożsamość".
 *
 * Dedykowany, brandowany ekran logowania portalu tożsamości (Entra ID), odrębny
 * od głównego ekranu logowania SZO. Reużywa TEN SAM, sprawdzony silnik auth:
 * anty-brute (brute_*), politykę „tylko Office", 2FA (/auth/2fa.php), bramkę
 * WebAuthn oraz login_user() — nie duplikuje kryptografii ani obsługi sesji.
 *
 * Metody: hasło lokalne, logowanie Microsoft 365 (SSO), odzyskiwanie dostępu.
 * Po zalogowaniu ląduje w /tozsamosc/index.php.
 *
 * WCAG 2.1 AA: etykiety powiązane z polami, komunikaty role=alert, widoczny
 * fokus, obsługa klawiaturą, dwujęzyczne etykiety pomocnicze (PL/EN).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/auth_screen.php';
require_once dirname(__DIR__) . '/includes/approval.php'; // log_auth_action()

auth_start();

$SELF     = APP_URL . '/tozsamosc/login.php';
$redirect = APP_URL . '/tozsamosc/index.php';

// Już zalogowany → prosto do portalu tożsamości.
if (current_user()) { header('Location: ' . $redirect); exit; }

// Zunifikowane logowanie eTożsamości: przekieruj do głównej strony logowania SZO.
// Ścieżki M365/Azure (SSO, callback) pozostają bez zmian w auth/ms_callback.php.
header('Location: ' . APP_URL . '/auth/login.php?redirect=' . urlencode($redirect));
exit;

$ms_available = function_exists('ms_login_available') && ms_login_available();
$org_name     = defined('ORG_NAME') ? ORG_NAME : '';
$error = ''; $active = 'local';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $blocked_sec = brute_check($email);
    if ($blocked_sec !== null) {
        $mins  = (int)ceil($blocked_sec / 60);
        $error = "Konto tymczasowo zablokowane po zbyt wielu nieudanych próbach. Spróbuj ponownie za {$mins} min.";
        authlog_write(null, 'login_blocked', $email, 'Zablokowany dostęp (Tożsamość) z IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    } else {
        $user = db_one("SELECT * FROM users WHERE email=? AND (is_active=1 OR email='serwis@local')", [$email]);

        if ($user && account_is_office_only($user) && empty($user['allow_local_fallback'])) {
            authlog_write((int)$user['id'], 'login_blocked_office', $user['email'], 'Konto służbowe — wymagane logowanie przez Microsoft 365 (Tożsamość)');
            $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365. Użyj przycisku „Zaloguj przez Microsoft 365”.';
        } elseif ($user && $user['password'] && password_verify($pass, $user['password'])) {
            brute_clear($email);
            // 2FA — delegujemy do wspólnego ekranu /auth/2fa.php
            if (!empty($user['twofa_method'])) {
                $_SESSION['2fa_uid']      = $user['id'];
                $_SESSION['2fa_method']   = $user['twofa_method'];
                $_SESSION['2fa_phone']    = $user['twofa_phone'] ?? '';
                $_SESSION['2fa_attempts'] = 0;
                if ($user['twofa_method'] === 'sms' && !empty($user['twofa_phone'])) {
                    try {
                        require_once dirname(__DIR__) . '/includes/sms.php';
                        $otp = sms_generate_otp($user['twofa_phone'], $user['id']);
                        sms_send_with_fallback($user['twofa_phone'], "Kod 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '');
                        $_SESSION['2fa_sms_sent'] = true;
                    } catch (\Throwable $e) {}
                }
                header('Location: ' . APP_URL . '/auth/2fa.php?redirect=' . urlencode($redirect));
                exit;
            }
            log_auth_action((int)$user['id'], 'login', 'Logowanie do Tożsamości: ' . $user['email']);
            authlog_write((int)$user['id'], 'login', $user['email'], 'Logowanie (moduł Tożsamość)');
            require_once dirname(__DIR__) . '/includes/webauthn.php';
            if (webauthn_login_gate($user, $redirect)) exit;
            login_user($user);
            if (auth_must_change_password($user)) {
                flash_set('warning', 'Administrator zresetował Twoje hasło. Ustaw nowe przed kontynuowaniem.');
                header('Location: ' . APP_URL . '/panel/password.php?force=1'); exit;
            }
            header('Location: ' . $redirect); exit;
        } else {
            brute_record_fail($email);
            authlog_write(null, 'login_fail', $email, 'Nieudana próba logowania (Tożsamość)');
            $error = 'Nieprawidłowy e-mail lub hasło.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Logowanie — System Tożsamości · <?= h($org_name) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root{--tz:#1E6DFF;--tz-strong:#1656d6;--tz-50:#eef4ff;--tz-line:#E5E9F0;}
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#111827}
    .wrap{max-width:440px;width:100%}
    .brandbar{display:flex;align-items:center;justify-content:center;gap:.55rem;margin-bottom:1.25rem}
    .brandbar .mark{width:36px;height:36px;border-radius:10px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
    /* Marka i stopka stoją bezpośrednio na tle marki — muszą być białe */
    .brandbar .mark{background:rgba(255,255,255,.16)}
    .brandbar .txt{font-weight:700;color:#fff;line-height:1.05}
    .brandbar .txt small{display:block;font-weight:500;font-size:.68rem;letter-spacing:.06em;color:rgba(255,255,255,.85);text-transform:uppercase}
    .page-foot{color:rgba(255,255,255,.85)!important}
    .card{border:1px solid var(--tz-line);border-radius:16px;box-shadow:0 12px 40px -12px rgba(30,109,255,.25)}
    .btn-primary{--bs-btn-bg:var(--tz-strong);--bs-btn-border-color:var(--tz-strong);--bs-btn-hover-bg:#0f3c9c;--bs-btn-hover-border-color:#0f3c9c}
    .btn-ms{background:#fff;border:1px solid var(--tz-line);color:#1f2937;font-weight:600}
    .btn-ms:hover{background:var(--tz-50);border-color:var(--tz)}
    .form-control:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem rgba(30,109,255,.18)}
    a{color:var(--tz-strong)}
    .sep{display:flex;align-items:center;gap:.6rem;color:#9aa4b2;font-size:.8rem;margin:1rem 0}
    .sep::before,.sep::after{content:"";height:1px;background:var(--tz-line);flex:1}
    .foot-link{font-size:.85rem}
    .skip{position:absolute;left:-999px;top:auto}
    .skip:focus{left:1rem;top:1rem;background:var(--tz);color:#fff;padding:.5rem 1rem;border-radius:8px;z-index:10}
  </style>
<?php branding_css(branding_load()); ?>
<?php auth_screen_bg_css(); ?>
</head>
<body>
<a href="#login-form" class="skip">Przejdź do formularza logowania</a>
<div class="wrap">

  <div class="brandbar">
    <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
    <span class="txt">System Tożsamości<small><?= h($org_name) ?></small></span>
  </div>

  <div class="card">
    <div class="card-body p-4 p-sm-4">
      <div class="text-center mb-4">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:60px;height:60px;background:#eef4ff">
          <i class="bi bi-box-arrow-in-right fs-2" style="color:#1E6DFF"></i>
        </div>
        <h1 class="h5 fw-bold mb-1">Zaloguj się do Tożsamości</h1>
        <p class="text-muted small mb-0">Zarządzaj kontem w Entra ID · Sign in to manage your identity</p>
      </div>

      <?= function_exists('flash_html') ? flash_html() : '' ?>

      <div aria-live="assertive">
      <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
          <div class="small"><?= h($error) ?></div>
        </div>
      <?php endif; ?>
      </div>

      <?php if ($ms_available): ?>
      <a href="<?= h(ms_auth_url($redirect)) ?>" class="btn btn-ms w-100 d-flex align-items-center justify-content-center gap-2 mb-1">
        <svg width="18" height="18" viewBox="0 0 23 23" aria-hidden="true"><rect width="10" height="10" fill="#F25022"/><rect x="12" width="10" height="10" fill="#7FBA00"/><rect y="12" width="10" height="10" fill="#00A4EF"/><rect x="12" y="12" width="10" height="10" fill="#FFB900"/></svg>
        Zaloguj przez Microsoft 365
      </a>
      <div class="sep">albo hasłem lokalnym</div>
      <?php endif; ?>

      <form method="post" id="login-form" novalidate>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="mb-3">
          <label for="email" class="form-label fw-semibold small">Adres e-mail / login główny <span class="text-muted">· Network ID</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= h($_POST['email'] ?? '') ?>" placeholder="imie.nazwisko@…" required autofocus>
          </div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center">
            <label for="password" class="form-label fw-semibold small mb-0">Hasło <span class="text-muted">· Password</span></label>
            <a href="<?= APP_URL ?>/user/verify_reset.php" class="foot-link">Nie pamiętasz hasła?</a>
          </div>
          <div class="input-group mt-1">
            <span class="input-group-text"><i class="bi bi-key" aria-hidden="true"></i></span>
            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
            <button type="button" class="btn btn-outline-secondary" id="pw_toggle" aria-label="Pokaż lub ukryj hasło" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100">
          Zaloguj się <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
        </button>
      </form>

      <hr class="my-4">
      <div class="d-grid gap-2">
        <a href="<?= APP_URL ?>/user/verify_reset.php" class="foot-link text-decoration-none">
          <i class="bi bi-key-fill me-1" aria-hidden="true"></i>Odzyskiwanie dostępu (reset hasła kodem SMS)
        </a>
        <a href="<?= APP_URL ?>/auth/login.php" class="foot-link text-decoration-none text-muted">
          <i class="bi bi-box-arrow-in-left me-1" aria-hidden="true"></i>Wróć do logowania do SZO
        </a>
      </div>
    </div>
  </div>

  <p class="text-center text-muted mt-3 page-foot" style="font-size:.75rem">
    <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Połączenie szyfrowane · © <?= date('Y') ?> <?= h($org_name) ?>
  </p>
</div>

<script>
(function(){
  var t=document.getElementById('pw_toggle'), p=document.getElementById('password');
  if(t&&p)t.addEventListener('click',function(){
    var s=p.type==='password';p.type=s?'text':'password';
    t.setAttribute('aria-pressed',s?'true':'false');
    t.querySelector('i').className=s?'bi bi-eye-slash':'bi bi-eye';
  });
})();
</script>
</body>
</html>
