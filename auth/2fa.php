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
    header('Location: ' . auth_default_landing());
    exit;
}

// Must have a pending 2FA session
if (empty($_SESSION['2fa_uid'])) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$raw_redirect = $_GET['redirect'] ?? '';
$redirect = ($raw_redirect && str_starts_with($raw_redirect, APP_URL . '/'))
    ? $raw_redirect : auth_default_landing();

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
        $via = sms_send_with_fallback($phone, "Kod 2FA: {$otp} (ważny 5 min)", $_2fa_user_email, 'twofa_code');
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
            $via = sms_send_with_fallback($phone, "Kod 2FA: {$otp} (ważny 5 min)", $_2fa_user_email, 'twofa_code');
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
    $tz = auth_default_landing();
    // Domyślne lądowanie = ekran startowy panelu (konta tylko-EZD/CRM — ich pulpit); jawny, inny adres wygrywa.
    header('Location: ' . auth_login_landing($user, ($redirect && $redirect !== $tz) ? $redirect : ''));
    exit;
}

// ── Mask phone for display ────────────────────────────────────────────────
function mask_phone(string $p): string
{
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) <= 4) return str_repeat('*', strlen($p));
    return str_repeat('*', strlen($p) - 4) . substr($p, -4);
}
$_mfa_err_ids = $error ? ' mfa-err' : '';
$_mfa_inv     = $error ? ' aria-invalid="true"' : '';
// Widok: jasny wygląd ekranów wejścia (includes/auth_screen.php, tryb 'light'; przebudowa 2026-11-14). Logika powyżej bez zmian.
auth_screen_head([
    'title'           => 'Weryfikacja dwuetapowa',
    'light'           => true,
    'width'           => 460,
    'main_id'         => 'mfa-main',
    'account_buttons' => [],
]);
?>
<p class="au-kicker">Logowanie · krok 2 z 2</p>
<h1 class="ks-h1"><?= $method === 'sms' ? 'Kod SMS' : 'Kod z aplikacji' ?></h1>

<?php if ($info): ?><div class="l-alert l-alert-success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><div><?= h($info) ?></div></div><?php endif; ?>

<?php if ($method === 'totp'): ?>
<p class="ks-lead">Wpisz 6-cyfrowy kod z aplikacji uwierzytelniającej (Google Authenticator, Microsoft Authenticator…).</p>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="verify">
  <div class="ks-field">
    <label for="mfa-code">Kod uwierzytelniający</label>
    <input type="text" id="mfa-code" name="code" class="form-control ks-otp" inputmode="numeric" pattern="[0-9A-Fa-f\-]{6,9}" maxlength="9"
           autofocus required autocomplete="one-time-code" aria-describedby="mfa-hint<?= $_mfa_err_ids ?>"<?= $_mfa_inv ?>>
    <p class="ks-fieldhint" id="mfa-hint">Możesz też wpisać kod zapasowy (format XXXX-XXXX).</p>
    <?php if ($error): ?><p class="au-ferr" id="mfa-err" role="alert"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span id="login-error-text"><?= h($error) ?></span></p><?php endif; ?>
  </div>
  <button type="submit" class="ks-btn ks-btn--primary"><i class="bi bi-shield-check" aria-hidden="true"></i>Weryfikuj</button>
</form>

<?php elseif ($method === 'sms'): ?>
<p class="ks-lead">Kod jednorazowy wysłaliśmy SMS-em na numer <strong><?= h(mask_phone($phone)) ?></strong>.</p>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="verify">
  <div class="ks-field">
    <label for="mfa-code">6-cyfrowy kod SMS</label>
    <input type="text" id="mfa-code" name="code" class="form-control ks-otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
           autofocus required autocomplete="one-time-code"<?= $error ? ' aria-describedby="mfa-err"' : '' ?><?= $_mfa_inv ?>>
    <?php if ($error): ?><p class="au-ferr" id="mfa-err" role="alert"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span id="login-error-text"><?= h($error) ?></span></p><?php endif; ?>
  </div>
  <button type="submit" class="ks-btn ks-btn--primary"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Zaloguj</button>
</form>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="sms_resend">
  <button type="submit" class="ks-btn ks-btn--ghost"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Wyślij kod ponownie</button>
</form>

<?php else: ?>
<div class="l-alert l-alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div>Nieznana metoda 2FA. <a href="<?= APP_URL ?>/auth/login.php">Wróć do logowania</a>.</div></div>
<?php endif; ?>

<p class="ks-note"><a href="<?= APP_URL ?>/auth/login.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do logowania</a></p>
<?php
auth_screen_foot(['no_new_tiles' => true]);
