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
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weryfikacja dwuetapowa — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* METRO jak pozostałe ekrany wejścia: ciemny pasek, płaskie grupy, zero zaokrągleń i cieni */
:root{--m-acc:#0f6fa8;--m-dark:#1d1d1d;--m-green:#007a00;--m-red:#a20025;--m-light:#f2f2f2;--m-ink:#111827;--m-muted:#5f5f5f}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;min-height:100vh;background:#fff;color:var(--m-ink);-webkit-font-smoothing:antialiased;
  font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,Arial,sans-serif}
*:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px}
.skip{position:absolute;left:-999px}.skip:focus{left:1rem;top:1rem;z-index:10;background:#fff;padding:.5rem 1rem;font-weight:600}
.bar{background:var(--m-dark);color:#fff}
.bar .in{max-width:520px;margin:0 auto;padding:.45rem 1rem;display:flex;align-items:center;gap:.6rem}
.bar .mark{width:34px;height:34px;background:var(--m-acc);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.bar b{font-weight:700;font-size:.95rem;line-height:1.05}.bar small{display:block;font-size:.65rem;color:rgba(255,255,255,.65);font-weight:500}
main{max-width:520px;margin:0 auto;padding:2.25rem 1rem 3rem}
.kick{font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:var(--m-muted);margin-bottom:.35rem}
h1{margin:0;font-size:clamp(1.8rem,5vw,2.3rem);font-weight:300;line-height:1.05;letter-spacing:-.02em}
.lead{margin:.6rem 0 0;font-size:.95rem;font-weight:300;color:var(--m-muted)}
.grp{background:var(--m-light);padding:1.25rem;margin-top:1.5rem}
.ico{width:52px;height:52px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:#fff;margin-bottom:.9rem}
label{display:block;font-weight:600;font-size:.88rem;margin-bottom:.3rem}
.code{width:100%;border:2px solid #8a8a8a;border-radius:0;padding:.6rem .75rem;font:inherit;font-size:1.9rem;letter-spacing:.5rem;text-align:center;font-weight:700;font-variant-numeric:tabular-nums;background:#fff;color:var(--m-ink)}
.code:focus{border-color:var(--m-acc);outline:0}
.hint{font-size:.8rem;color:var(--m-muted);margin:.4rem 0 0}
.btn{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;margin-top:1rem;min-height:48px;background:var(--m-acc);color:#fff;border:2px solid var(--m-acc);border-radius:0;padding:.7rem 1rem;font:inherit;font-size:1.05rem;font-weight:600;cursor:pointer;text-decoration:none}
.btn:hover{background:var(--m-dark);border-color:var(--m-dark)}
.btn--ghost{background:transparent;color:var(--m-ink);border-color:var(--m-ink);font-size:.92rem;min-height:44px}
.btn--ghost:hover{background:var(--m-ink);color:#fff}
.msg{display:flex;gap:.6rem;align-items:flex-start;padding:.85rem 1.1rem;margin-top:1.25rem;color:#fff;font-size:.92rem}
.msg.err{background:var(--m-red)}.msg.ok{background:var(--m-green)}
.back{display:inline-flex;gap:.4rem;align-items:center;margin-top:1.5rem;color:var(--m-muted);font-size:.88rem}
</style>
</head>
<body>
<a href="#mfa-main" class="skip">Przejdź do treści</a>
<header class="bar"><div class="in">
  <span class="mark" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></span>
  <span><b><?= h(ORG_NAME) ?></b><small>Weryfikacja dwuetapowa</small></span>
</div></header>

<main id="mfa-main">
  <div class="kick">Logowanie · krok 2 z 2</div>
  <h1><?= $method === 'sms' ? 'Kod SMS' : 'Kod z aplikacji' ?></h1>

  <?php if ($error): ?><div class="msg err" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
  <?php if ($info): ?><div class="msg ok" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><div><?= h($info) ?></div></div><?php endif; ?>

  <?php if ($method === 'totp'): ?>
  <p class="lead">Wpisz 6-cyfrowy kod z aplikacji uwierzytelniającej (Google Authenticator, Microsoft Authenticator…).</p>
  <form method="post" class="grp">
    <span class="ico" style="background:var(--m-acc)" aria-hidden="true"><i class="bi bi-phone"></i></span>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="verify">
    <label for="mfa-code">Kod uwierzytelniający</label>
    <input type="text" id="mfa-code" name="code" class="code" inputmode="numeric" pattern="[0-9A-Fa-f\-]{6,9}" maxlength="9"
           placeholder="______" autofocus required autocomplete="one-time-code" aria-describedby="mfa-hint">
    <p class="hint" id="mfa-hint">Możesz też wpisać kod zapasowy (format XXXX-XXXX).</p>
    <button type="submit" class="btn"><i class="bi bi-shield-check" aria-hidden="true"></i>Weryfikuj</button>
  </form>

  <?php elseif ($method === 'sms'): ?>
  <p class="lead">Kod jednorazowy wysłaliśmy SMS-em na numer <strong><?= h(mask_phone($phone)) ?></strong>.</p>
  <form method="post" class="grp">
    <span class="ico" style="background:var(--m-green)" aria-hidden="true"><i class="bi bi-chat-dots"></i></span>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="verify">
    <label for="mfa-code">6-cyfrowy kod SMS</label>
    <input type="text" id="mfa-code" name="code" class="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
           placeholder="______" autofocus required autocomplete="one-time-code">
    <button type="submit" class="btn"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Zaloguj</button>
  </form>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="sms_resend">
    <button type="submit" class="btn btn--ghost"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Wyślij kod ponownie</button>
  </form>

  <?php else: ?>
  <div class="msg err" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div>Nieznana metoda 2FA. <a href="<?= APP_URL ?>/auth/login.php" style="color:#fff">Wróć do logowania</a>.</div></div>
  <?php endif; ?>

  <a href="<?= APP_URL ?>/auth/login.php" class="back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć do logowania</a>
</main>
</body>
</html>
