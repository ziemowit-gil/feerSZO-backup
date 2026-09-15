<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tz_auth.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/x509_login.php';

auth_start();

// Tenant CRM-standalone — cały ruch kieruj do CRM
if (defined('CRM_STANDALONE') && CRM_STANDALONE) {
    if (current_user()) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
    header('Location: ' . APP_URL . '/crm/login.php'); exit;
}

if (current_user()) { header('Location: ' . APP_URL . '/tozsamosc/index.php'); exit; }

$raw_redirect = $_GET['redirect'] ?? '';
$redirect = ($raw_redirect && str_starts_with($raw_redirect, APP_URL . '/'))
    ? $raw_redirect : '';

// Gdzie po zalogowaniu — ezd_only → EZD, admin/editor → Tożsamość, reszta → portal
function _login_landing(array $user): string {
    if (function_exists('is_ezd_only') && is_ezd_only()) return APP_URL . '/ezd/index.php';
    return in_array($user['role'] ?? '', ['admin', 'editor'], true)
        ? APP_URL . '/tozsamosc/index.php'
        : APP_URL . '/portal.php';
}

// ── Feature flags ─────────────────────────────────────────────────────────
function _login_method_enabled(string $key, bool $default = true): bool {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        return $r !== null ? (bool)$r['value'] : $default;
    } catch (\Throwable $e) { return $default; }
}

$ms_available   = ms_login_available() && _login_method_enabled('login_method_ms365', false);
$sms_available  = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled() && _login_method_enabled('login_method_sms', false);
} catch (\Throwable $e) {}
$code_available  = _login_method_enabled('login_method_code', true);
$x509_available  = false;
try { $x509_available = x509_any_active() && _login_method_enabled('login_method_x509', true); } catch (\Throwable $e) {}

// ── State ─────────────────────────────────────────────────────────────────
$default_tab = $ms_available ? 'ms365' : 'local';
$active_tab = $_GET['tab'] ?? $default_tab;
$sms_step   = 1;
$sms_phone  = '';
$error      = '';
$info       = '';

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $method = $_POST['_method'] ?? 'local';

    if ($method === 'local') {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        $blocked_sec = brute_check($email);
        if ($blocked_sec !== null) {
            $mins = (int)ceil($blocked_sec / 60);
            $error = "Konto tymczasowo zablokowane po zbyt wielu nieudanych próbach. Spróbuj ponownie za {$mins} min.";
            authlog_write(null, 'login_blocked', $email, 'Zablokowany dostęp z IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            $active_tab = 'local';
        } else {
            $user = db_one("SELECT * FROM users WHERE email=? AND (is_active=1 OR email='serwis@local')", [$email]);
            // Polityka „tylko Office" — z wyjątkiem kont, które ustawiły hasło awaryjne
            // przez /auth/convert_account.php (flaga allow_local_fallback).
            if ($user && account_is_ti_panel_only($user)) {
                authlog_write((int)$user['id'], 'login_blocked_ti_panel', $user['email'], 'Konto panelu dydaktyka TI — logowanie tylko w panelu TI');
                $error = 'To konto działa wyłącznie w panelu dydaktyka TI — zaloguj się pod adresem karty30/ti/dydaktyk/.';
                $active_tab = 'local';
            } elseif ($user && account_is_office_only($user) && empty($user['allow_local_fallback'])) {
                authlog_write((int)$user['id'], 'login_blocked_office', $user['email'], 'Konto służbowe — wymagane logowanie przez Microsoft 365');
                $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office). Użyj przycisku „Zaloguj przez Microsoft 365”.';
                $active_tab = 'local';
            } elseif ($user && $user['password'] && password_verify($pass, $user['password'])) {
                brute_clear($email);
                auth_start();
                // Role "admin" pomijają obowiązkowe 2FA (TOTP/SMS) — spójnie z
                // panelem dydaktyka TI (karty30/ti/dydaktyk/auth.php, dyd_require()).
                if (!empty($user['twofa_method']) && ($user['role'] ?? '') !== 'admin') {
                    auth_start();
                    $_SESSION['2fa_uid']      = $user['id'];
                    $_SESSION['2fa_method']   = $user['twofa_method'];
                    $_SESSION['2fa_phone']    = $user['twofa_phone'] ?? '';
                    $_SESSION['2fa_attempts'] = 0;
                    if ($user['twofa_method'] === 'sms' && !empty($user['twofa_phone'])) {
                        try {
                            $otp = sms_generate_otp($user['twofa_phone'], $user['id']);
                            sms_send_with_fallback($user['twofa_phone'], "Kod 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '');
                            $_SESSION['2fa_sms_sent'] = true;
                        } catch (\Throwable $e) {}
                    }
                    header('Location: ' . APP_URL . '/auth/2fa.php?redirect=' . urlencode($redirect));
                    exit;
                }
                log_auth_action((int)$user['id'], 'login', 'Logowanie lokalne: ' . $user['email']);
                authlog_write((int)$user['id'], 'login', $user['email'], 'Logowanie lokalne');
                require_once dirname(__DIR__) . '/includes/webauthn.php';
                if (webauthn_login_gate($user, $redirect)) exit;
                login_user($user);
                if (auth_must_change_password($user)) {
                    flash_set('warning', 'Administrator zresetował Twoje hasło. Ustaw nowe przed kontynuowaniem.');
                    header('Location: ' . APP_URL . '/panel/password.php?force=1'); exit;
                }
                header('Location: ' . ($redirect ?: _login_landing($user))); exit;
            }
            brute_record_fail($email);
            authlog_write(null, 'login_fail', $email, 'Nieudana próba logowania');
            $error = 'Nieprawidłowy e-mail lub hasło.';
            $since = date('Y-m-d H:i:s', time() - BRUTE_WINDOW_SEC);
            $cnt = db_one("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at > ?", [$email, $since]);
            $left = max(0, BRUTE_MAX_ATTEMPTS - (int)($cnt['c'] ?? 0));
            if ($left <= 2 && $left > 0) $error .= " Pozostało prób: $left.";
            $active_tab = 'local';
        }
    }

    elseif ($method === 'sms_send' && $sms_available) {
        $sms_phone  = trim($_POST['sms_phone'] ?? '');
        $active_tab = 'sms';
        $found      = sms_find_user_by_phone($sms_phone);
        if (!$found) {
            $error = 'Nie znaleziono aktywnego konta powiązanego z tym numerem.';
        } else {
            try {
                $otp = sms_generate_otp($sms_phone, (int)$found['id']);
                $org = defined('ORG_NAME') ? ORG_NAME : 'System';
                $via = sms_send_with_fallback($sms_phone, "Kod logowania {$org}: {$otp} (ważny 5 min)", $found['email'] ?? '');
                $sms_step = 2;
                $info = $via === 'email'
                    ? 'Nie udało się wysłać SMS — kod wysłany na adres e-mail powiązany z kontem.'
                    : 'Kod SMS wysłany — sprawdź telefon.';
            } catch (\Throwable $e) {
                $error = 'Błąd wysyłki kodu: ' . $e->getMessage();
            }
        }
    }

    elseif ($method === 'sms_verify' && $sms_available) {
        $sms_phone  = trim($_POST['sms_phone'] ?? '');
        $sms_code   = trim($_POST['sms_code']  ?? '');
        $active_tab = 'sms';
        $user       = sms_verify_otp($sms_phone, $sms_code);
        if ($user && account_is_ti_panel_only($user)) {
            authlog_write((int)$user['id'], 'login_blocked_ti_panel', $user['email'] ?? '', 'Konto panelu dydaktyka TI — logowanie tylko w panelu TI');
            $error = 'To konto działa wyłącznie w panelu dydaktyka TI — zaloguj się pod adresem karty30/ti/dydaktyk/.';
            $active_tab = 'sms';
        } elseif ($user && account_is_office_only($user)) {
            authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365');
            $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
            $active_tab = 'sms';
        } elseif ($user) {
            log_auth_action((int)$user['id'], 'login_sms', 'Logowanie SMS: ' . $sms_phone);
            authlog_write((int)$user['id'], 'login_sms', $user['email'] ?? $sms_phone, 'Logowanie SMS');
            require_once dirname(__DIR__) . '/includes/webauthn.php';
            if (webauthn_login_gate($user, $redirect)) exit;
            login_user($user);
            header('Location: ' . ($redirect ?: _login_landing($user))); exit;
        }
        $sms_step = 2;
        $error    = 'Nieprawidłowy lub wygasły kod. Spróbuj ponownie.';
    }

    elseif ($method === 'x509' && $x509_available) {
        $active_tab = 'x509';
        $p12_file   = $_FILES['p12_file'] ?? null;
        $cert_pass  = $_POST['cert_password'] ?? '';

        if (!$p12_file || $p12_file['error'] !== UPLOAD_ERR_OK || $p12_file['size'] === 0) {
            $error = 'Nie przesłano pliku certyfikatu (.p12).';
        } else {
            $p12_data = file_get_contents($p12_file['tmp_name']);
            $user     = null;
            try { $user = x509_verify_login($p12_data, $cert_pass); } catch (\Throwable $e) {}
            if ($user && account_is_office_only($user)) {
                authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365');
                $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
                $active_tab = 'x509';
            } elseif ($user) {
                log_auth_action((int)$user['id'], 'login_x509', 'Logowanie X.509: ' . $user['email']);
                authlog_write((int)$user['id'], 'login_x509', $user['email'], 'Logowanie certyfikatem X.509');
                require_once dirname(__DIR__) . '/includes/webauthn.php';
                if (webauthn_login_gate($user, $redirect)) exit;
                login_user($user);
                header('Location: ' . ($redirect ?: _login_landing($user))); exit;
            }
            $error = 'Nieprawidłowy certyfikat, błędne hasło lub certyfikat wygasł/unieważniony.';
        }
    }

    elseif ($method === 'x509_app' && $x509_available) {
        // Challenge-response przez aplikację kliencką SzoCert — klucz prywatny
        // nigdy nie opuszcza komputera użytkownika (patrz includes/x509_login.php
        // x509_verify_challenge() i bin/szocert-app).
        $active_tab   = 'x509';
        $challenge_id = $_POST['challenge_id']  ?? '';
        $cert_pem     = $_POST['cert_pem']      ?? '';
        $signature_b64 = $_POST['signature_b64'] ?? '';

        $user = null;
        if ($challenge_id && $cert_pem && $signature_b64) {
            try { $user = x509_verify_challenge($challenge_id, $cert_pem, $signature_b64); } catch (\Throwable $e) {}
        }
        if ($user && account_is_office_only($user)) {
            authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365');
            $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
            $active_tab = 'x509';
        } elseif ($user) {
            log_auth_action((int)$user['id'], 'login_x509_app', 'Logowanie X.509 (SzoCert): ' . $user['email']);
            authlog_write((int)$user['id'], 'login_x509_app', $user['email'], 'Logowanie certyfikatem X.509 (aplikacja SzoCert)');
            require_once dirname(__DIR__) . '/includes/webauthn.php';
            if (webauthn_login_gate($user, $redirect)) exit;
            login_user($user);
            header('Location: ' . ($redirect ?: _login_landing($user))); exit;
        } else {
            $error = 'Logowanie aplikacją SzoCert nie powiodło się — wyzwanie wygasło albo certyfikat jest nieprawidłowy/unieważniony.';
        }
    }

    elseif ($method === 'code') {
        $code = trim($_POST['login_code'] ?? '');
        $user = auth_login_by_code($code);
        if ($user && account_is_office_only($user)) {
            authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365');
            $error      = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
            $active_tab = 'code';
        } elseif ($user) {
            try {
                db()->prepare("UPDATE users SET login_code=NULL WHERE id=?")->execute([$user['id']]);
            } catch (\Throwable $e) {}
            log_auth_action((int)$user['id'], 'login_code', 'Logowanie jednorazowym kodem dostępu');
            authlog_write((int)$user['id'], 'login_code', $user['email'] ?? '', 'Logowanie kodem jednorazowym');
            require_once dirname(__DIR__) . '/includes/webauthn.php';
            if (webauthn_login_gate($user, $redirect)) exit;
            login_user($user);
            header('Location: ' . ($redirect ?: _login_landing($user))); exit;
        }
        $error      = 'Nieprawidłowy lub nieaktywny kod dostępu.';
        $active_tab = 'code';
    }
}

$valid_tabs = ['local'];
if ($code_available)  $valid_tabs[] = 'code';
if ($sms_available)   $valid_tabs[] = 'sms';
if ($ms_available)    $valid_tabs[] = 'ms365';
if ($x509_available)  $valid_tabs[] = 'x509';
if (!in_array($active_tab, $valid_tabs, true)) $active_tab = $default_tab;

// Tryb logowania awaryjnego — osobny adres /auth/awaryjne.php kieruje tu z
// ?awaryjne=1. Wymuszamy formularz lokalny (e-mail + hasło awaryjne).
$emergency = !empty($_GET['awaryjne']);
if ($emergency) $active_tab = 'local';

// ── Powrót na brandowany ekran modułu po nieudanym logowaniu ─────────────
// Gdy logowanie zostało zainicjowane z dedykowanego ekranu (np. karty30/login.php),
// w polu „from" jest jego adres — wracamy tam z komunikatem zamiast pokazywać
// widok logowania systemu głównego.
$login_from = $_POST['from'] ?? '';
if ($error !== '' && $login_from !== '' && str_starts_with($login_from, APP_URL . '/')) {
    $sep = str_contains($login_from, '?') ? '&' : '?';
    header('Location: ' . $login_from . $sep . 'err=' . urlencode($error));
    exit;
}

// ── Publiczne komunikaty administratora ──────────────────────────────────
$_login_notices = [];
try {
    require_once dirname(__DIR__) . '/includes/notifications.php';
    notif_migrate();
    $_login_notices = ann_public_list();
} catch (\Throwable $_) {}

// ── Branding ─────────────────────────────────────────────────────────────
$_b             = branding_load();
$_login_tagline = org_setting('login_tagline') ?: '';

$is_tenant = defined('TENANT_SLUG') && TENANT_SLUG !== '';
$org_name  = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju FEER');

$sel_url = null;
if ($is_tenant) {
    $sel_base = parse_url(APP_URL, PHP_URL_SCHEME) . '://' . parse_url(APP_URL, PHP_URL_HOST);
    $full_path = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    $sel_path  = preg_match('#^(.*)/org/[^/]+$#', $full_path, $m) ? $m[1] : '';
    $sel_url   = rtrim($sel_base . $sel_path, '/') . '/select_org.php';
} else {
    $master_db = dirname(__DIR__) . '/saas-tenent/x/master.db';
    if (is_file($master_db)) {
        try {
            $mpdo = new PDO('sqlite:' . $master_db);
            $cnt  = $mpdo->query("SELECT COUNT(*) FROM tenants WHERE is_active=1 AND db_ready=1")->fetchColumn();
            if ($cnt > 0) {
                $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
                $appDir  = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__))), '/');
                $base    = ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
                $sel_url = rtrim($scheme . '://' . $host . $base, '/') . '/select_org.php';
            }
        } catch (\Throwable $e) {}
    }
}

// ── Opis systemu (konfigurowalny — pokazujemy tylko gdy admin go ustawił) ──
$_login_welcome = '';
try { $_login_welcome = trim(org_setting('login_welcome_text') ?: ''); } catch (\Throwable $e) {}
$_login_welcome_is_custom = ($_login_welcome !== '');

// Dydaktyk loguje się we własnym panelu
$_url_dyd = APP_URL . '/karty30/ti/dydaktyk/login.php';

// Nazwa systemu na ekranie powitania (spójna z nagłówkiem SZO)
$_sys_name = 'Systemie Wspomagania Zarządzania Organizacją';

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'     => 'Logowanie',
    'tab'       => 'login',
    'bootstrap' => true,
    'main_id'   => 'login-main',
]);

// Modale alternatywnych metod renderujemy do bufora — trafiają poza kartę.
ob_start();
require __DIR__ . '/_login_modals.php';
$_modals_html = ob_get_clean();
?>

    <?php auth_screen_news(); ?>

    <?php
    $_ln_show = null;
    foreach ($_login_notices as $_ln_item) {
        if ($_ln_item['is_pinned'] ?? 0) { $_ln_show = $_ln_item; break; }
    }
    if (!$_ln_show && !empty($_login_notices)) $_ln_show = $_login_notices[0];
    if ($_ln_show): ?>
    <div class="l-alert l-alert-info" role="region" aria-label="Komunikat">
      <i class="bi bi-megaphone-fill flex-shrink-0" aria-hidden="true"></i>
      <div>
        <strong><?= h($_ln_show['title']) ?></strong>
        <?php if ($_ln_show['body']): ?><br><span style="font-size:.85rem"><?= nl2br(h($_ln_show['body'])) ?></span><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['ended'])): ?>
    <div class="l-alert l-alert-success" role="status">
      <i class="bi bi-box-arrow-right flex-shrink-0" aria-hidden="true"></i>
      <span>Sesja zakończona — zaloguj się ponownie.</span>
    </div>
    <?php endif; ?>

    <?php if ($error && !in_array($active_tab, ['code','sms','x509'], true)): ?>
    <div class="l-alert l-alert-danger" role="alert" id="login-error-box">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
      <span id="login-error-text"><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($emergency): ?>
    <div class="l-alert" style="background:#7c2d12;color:#fff;border:1px solid #92400e" role="note">
      <i class="bi bi-shield-lock-fill flex-shrink-0" aria-hidden="true"></i>
      <span>Logowanie awaryjne — użyj <strong>adresu e-mail</strong> i <strong>hasła awaryjnego</strong>.</span>
    </div>
    <?php endif; ?>

    <h1 class="ks-h1"><?= $_login_tagline ? h($_login_tagline) : 'Witaj w ' . h($_sys_name) ?></h1>
    <p class="ks-lead">
      <?php if ($_login_welcome_is_custom): ?><?= nl2br(h($_login_welcome)) ?>
      <?php elseif ($ms_available): ?>Konto służbowe — zaloguj się przez Microsoft 365. Współpracownicy — e-mailem i hasłem.
      <?php else: ?>Zaloguj się adresem e-mail i hasłem.
      <?php endif; ?>
    </p>

    <?php if ($ms_available): ?>
    <a href="<?= h(ms_auth_url($redirect ?: APP_URL . '/tozsamosc/index.php')) ?>" class="ks-btn ks-btn--ghost"
       aria-label="Zaloguj się przez Microsoft 365 — zostaniesz przekierowany do Microsoft">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
        <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
        <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
      </svg>
      Zaloguj przez Microsoft 365
    </a>
    <p class="ks-hint">Konto <strong>@feer.org.pl</strong> — SSO, bez wpisywania hasła</p>
    <div class="ks-or"><span>lub e-mailem i hasłem</span></div>
    <?php endif; ?>

    <!-- ══ E-mail + hasło ═══════════════════════════════════════════════ -->
    <form method="post" novalidate autocomplete="on" aria-label="Logowanie e-mailem i hasłem">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_method" value="local">

      <div class="ks-field">
        <label for="f-email">Adres e-mail</label>
        <input type="email" name="email" id="f-email" class="form-control"
               autocomplete="email" inputmode="email" required
               <?= (!$ms_available) ? 'autofocus' : '' ?>
               <?php if ($error && $active_tab === 'local'): ?>aria-invalid="true"<?php endif; ?>>
      </div>

      <div class="ks-field">
        <label for="f-pass">Hasło</label>
        <div class="pass-wrap">
          <input type="password" name="password" id="f-pass" class="form-control"
                 autocomplete="current-password" required
                 <?php if ($error && $active_tab === 'local'): ?>aria-invalid="true"<?php endif; ?>>
          <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
                  onclick="togglePass('f-pass', this)">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
        <a href="<?= APP_URL ?>/user/verify_reset.php" class="ks-forgot">Nie pamiętasz hasła?</a>
      </div>

      <button type="submit" class="ks-btn ks-btn--primary">Zaloguj</button>
    </form>

    <hr class="ks-sep">

    <p class="ks-sub">Jeszcze nie masz konta?</p>
    <?php if (register_is_open()): ?>
    <a href="<?= APP_URL ?>/user/register.php" class="ks-btn ks-btn--ghost">Zarejestruj się</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/user/verify_reset.php" class="ks-btn ks-btn--ghost">Odzyskaj dostęp do konta</a>

    <?php $has_alt = $code_available || $sms_available || $x509_available; if ($has_alt): ?>
    <hr class="ks-sep">
    <p class="ks-sub">Inne metody logowania</p>
    <?php if ($code_available): ?>
    <button class="ks-btn ks-btn--ghost" type="button" data-bs-toggle="modal" data-bs-target="#modal-code">
      <span><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Kod jednorazowy
        <span class="ks-optsub">Pierwsze logowanie lub dostęp od administratora</span></span>
    </button>
    <?php endif; ?>
    <?php if ($sms_available): ?>
    <button class="ks-btn ks-btn--ghost" type="button" data-bs-toggle="modal" data-bs-target="#modal-sms">
      <span><i class="bi bi-phone-fill me-1" aria-hidden="true"></i>Kod SMS
        <span class="ks-optsub">Logowanie przez numer telefonu</span></span>
    </button>
    <?php endif; ?>
    <?php if ($x509_available): ?>
    <button class="ks-btn ks-btn--ghost" type="button" data-bs-toggle="modal" data-bs-target="#modal-x509">
      <span><i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Certyfikat X.509
        <span class="ks-optsub">Plik .p12 — dla administratorów systemu</span></span>
    </button>
    <?php endif; ?>
    <?php endif; ?>

<?php
auth_screen_foot([
    'extra_html' => $_modals_html,
    'extra_js'   => '<script>(function(){'
        . 'document.querySelectorAll(".modal").forEach(function(m){'
        . 'm.addEventListener("shown.bs.modal",function(){var f=m.querySelector("input:not([type=hidden])");if(f)f.focus();});});'
        . 'var a=' . json_encode(in_array($active_tab, ['code','sms','x509'], true) ? $active_tab : null) . ';'
        . 'if(a){var el=document.getElementById("modal-"+a);if(el)bootstrap.Modal.getOrCreateInstance(el).show();}'
        . '})();</script>',
]);
