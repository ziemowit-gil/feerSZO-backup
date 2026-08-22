<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/totp.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/auth_screen.php';

auth_start();

// If already fully logged in, go home
if (current_user()) {
    header('Location: ' . APP_URL . '/tozsamosc/index.php');
    exit;
}

// Must have a pending 2FA session
if (empty($_SESSION['2fa_uid'])) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$raw_redirect = $_GET['redirect'] ?? '';
$redirect = ($raw_redirect && str_starts_with($raw_redirect, APP_URL . '/'))
    ? $raw_redirect : APP_URL . '/tozsamosc/index.php';

$uid    = (int)$_SESSION['2fa_uid'];
$method = $_SESSION['2fa_method'] ?? '';
$phone  = $_SESSION['2fa_phone']  ?? '';

$error = '';
$info  = '';

$sms_available = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled();
} catch (\Throwable $e) {}

// ── Helper: increment and check attempt counter ───────────────────────────
function twofa_fail(string $msg): void
{
    $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;
    if ($_SESSION['2fa_attempts'] >= 3) {
        // Wipe the 2FA session and kick back to login
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_method'],
              $_SESSION['2fa_phone'], $_SESSION['2fa_attempts']);
        session_regenerate_id(true);
        header('Location: ' . APP_URL . '/auth/login.php?2fa_fail=1');
        exit;
    }
    // Return so caller can set $error
    global $error;
    $error = $msg;
}

// ── For SMS: auto-send on first load (no POST yet) ────────────────────────
$_2fa_user_email = db_one("SELECT email FROM users WHERE id=?", [$uid])['email'] ?? '';

if ($method === 'sms' && $sms_available && !empty($phone)
    && $_SERVER['REQUEST_METHOD'] !== 'POST'
    && empty($_SESSION['2fa_sms_sent'])
) {
    try {
        $otp = sms_generate_otp($phone, $uid);
        $via = sms_send_with_fallback($phone, "Kod 2FA: {$otp} (ważny 5 min)", $_2fa_user_email);
        $_SESSION['2fa_sms_sent'] = true;
        $info = $via === 'email'
            ? 'Kod wysłany e-mailem (SMS niedostępny) — sprawdź skrzynkę.'
            : 'Kod jednorazowy wysłany na Twój numer telefonu.';
    } catch (\Throwable $e) {
        $error = 'Nie można wysłać kodu: ' . $e->getMessage();
    }
}

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'verify';

    // Resend SMS
    if ($action === 'sms_resend' && $method === 'sms' && $sms_available && $phone) {
        try {
            $otp = sms_generate_otp($phone, $uid);
            $via = sms_send_with_fallback($phone, "Kod 2FA: {$otp} (ważny 5 min)", $_2fa_user_email);
            $_SESSION['2fa_sms_sent'] = true;
            $info = $via === 'email' ? 'Nowy kod wysłany e-mailem (SMS niedostępny).' : 'Nowy kod SMS został wysłany.';
        } catch (\Throwable $e) {
            $error = 'Błąd wysyłki kodu: ' . $e->getMessage();
        }
    }

    // Verify code
    elseif ($action === 'verify') {
        $code = trim($_POST['code'] ?? '');

        if ($method === 'totp') {
            $db_user = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$uid]);
            $ok = false;

            if ($db_user && $db_user['totp_secret'] && $db_user['totp_confirmed']) {
                $ok = TOTP::verify($db_user['totp_secret'], $code);

                // Check backup codes if TOTP failed
                if (!$ok && !empty($db_user['totp_backup_codes'])) {
                    $backup = json_decode($db_user['totp_backup_codes'], true) ?? [];
                    // Normalize for comparison (strip dashes)
                    $normalized = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $code));
                    foreach ($backup as $idx => $bc) {
                        $bc_norm = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $bc));
                        if (hash_equals($bc_norm, $normalized)) {
                            // Remove used backup code
                            array_splice($backup, $idx, 1);
                            db()->prepare("UPDATE users SET totp_backup_codes=? WHERE id=?")
                                ->execute([json_encode(array_values($backup)), $uid]);
                            $ok = true;
                            break;
                        }
                    }
                }
            }

            if ($ok) {
                _2fa_success($db_user, $redirect);
            } else {
                twofa_fail('Nieprawidłowy kod. Spróbuj ponownie.');
            }

        } elseif ($method === 'sms' && $sms_available) {
            $user = sms_verify_otp($phone, $code);
            if ($user && (int)$user['id'] === $uid) {
                _2fa_success($user, $redirect);
            } else {
                twofa_fail('Nieprawidłowy lub wygasły kod. Spróbuj ponownie.');
            }
        } else {
            twofa_fail('Nieprawidłowa metoda 2FA.');
        }
    }
}

// ── Success handler ───────────────────────────────────────────────────────
function _2fa_success(array $user, string $redirect): void
{
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_method'],
          $_SESSION['2fa_phone'], $_SESSION['2fa_attempts'],
          $_SESSION['2fa_sms_sent']);
    login_user($user);
    $tz = APP_URL . '/tozsamosc/index.php';
    if (!$redirect || $redirect === $tz) {
        $redirect = in_array($user['role'] ?? '', ['admin', 'editor'], true)
            ? $tz : APP_URL . '/portal.php';
    }
    header('Location: ' . $redirect);
    exit;
}

// ── Mask phone for display ────────────────────────────────────────────────
function mask_phone(string $p): string
{
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) <= 4) return str_repeat('*', strlen($p));
    return str_repeat('*', strlen($p) - 4) . substr($p, -4);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weryfikacja dwuetapowa — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css(branding_load()); ?>
<?php auth_screen_bg_css(); ?>
<style>
.twofa-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
.twofa-card { width: 100%; max-width: 420px; }
/* Marka stoi bezpośrednio na kolorowym tle — biel, nie domyślna czerń Bootstrapa */
.twofa-brand, .twofa-brand h4 { color: #fff; }
.twofa-brand p { color: rgba(255,255,255,.85) !important; }
.brand-icon { font-size: 2.4rem; color: #fff; }
.code-input  { font-size: 2rem; letter-spacing: .5rem; text-align: center; font-weight: 700; }
.card { box-shadow: 0 18px 44px rgba(0,0,0,.16) !important; border: none; border-radius: 16px; }
</style>
</head>
<body>
<div class="twofa-wrap">
<div class="twofa-card">

  <!-- Brand -->
  <div class="text-center mb-4 twofa-brand">
    <i class="bi bi-shield-lock brand-icon"></i>
    <h4 class="fw-bold mt-2 mb-0"><?= h(ORG_NAME) ?></h4>
    <p class="text-muted small">Weryfikacja dwuetapowa</p>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-4">

      <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle"></i> <?= h($error) ?></div>
      <?php endif; ?>
      <?php if ($info): ?>
      <div class="alert alert-success py-2 small"><i class="bi bi-check-circle"></i> <?= h($info) ?></div>
      <?php endif; ?>

      <?php if ($method === 'totp'): ?>
      <!-- ── TOTP ── -->
      <div class="text-center mb-3">
        <i class="bi bi-phone fs-1 text-primary"></i>
        <p class="text-muted small mt-2 mb-0">
          Wpisz 6-cyfrowy kod z aplikacji uwierzytelniającej<br>
          (Google Authenticator, Microsoft Authenticator itp.)
        </p>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="verify">
        <div class="mb-3">
          <label class="form-label fw-semibold">Kod uwierzytelniający</label>
          <input type="text" name="code" class="form-control code-input"
                 inputmode="numeric" pattern="[0-9A-Fa-f\-]{6,9}"
                 maxlength="9" placeholder="______" autofocus required
                 autocomplete="one-time-code">
          <div class="form-text text-center">Możesz też wpisać kod zapasowy (format XXXX-XXXX).</div>
        </div>
        <button type="submit" class="btn btn-primary w-100">
          <i class="bi bi-shield-check"></i> Weryfikuj
        </button>
      </form>

      <?php elseif ($method === 'sms'): ?>
      <!-- ── SMS OTP ── -->
      <div class="text-center mb-3">
        <i class="bi bi-chat-dots fs-1 text-success"></i>
        <p class="text-muted small mt-2 mb-0">
          Kod jednorazowy wysłany SMS-em na numer
          <strong><?= h(mask_phone($phone)) ?></strong>.
        </p>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="verify">
        <div class="mb-3">
          <label class="form-label fw-semibold">6-cyfrowy kod SMS</label>
          <input type="text" name="code" class="form-control code-input"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                 placeholder="______" autofocus required
                 autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn btn-success w-100 mb-2">
          <i class="bi bi-box-arrow-in-right"></i> Zaloguj
        </button>
      </form>
      <form method="post" class="mt-1">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="sms_resend">
        <button type="submit" class="btn btn-link w-100 small text-muted">
          <i class="bi bi-arrow-clockwise"></i> Wyślij kod ponownie
        </button>
      </form>

      <?php else: ?>
      <div class="alert alert-danger">Nieznana metoda 2FA. <a href="<?= APP_URL ?>/auth/login.php">Wróć do logowania</a>.</div>
      <?php endif; ?>

      <hr class="my-3">
      <div class="text-center">
        <a href="<?= APP_URL ?>/auth/login.php" class="small text-muted">
          <i class="bi bi-arrow-left"></i> Wróć do logowania
        </a>
      </div>

    </div>
  </div>

</div>
</div>
</body>
</html>
