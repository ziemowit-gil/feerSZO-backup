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

if (current_user()) { header('Location: ' . auth_default_landing()); exit; }

$raw_redirect = $_GET['redirect'] ?? '';
$redirect = ($raw_redirect && str_starts_with($raw_redirect, APP_URL . '/'))
    ? $raw_redirect : '';

// Wejście do Systemu Tożsamości → dedykowane okno logowania z wyjaśnieniem (tozsamosc/login.php).
// Tylko czyste GET z samym ?redirect= — każdy inny parametr (err, tab, pelne=1, awaryjne…) zostaje tutaj.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $redirect !== ''
    && str_starts_with($redirect, APP_URL . '/tozsamosc/')
    && array_keys($_GET) === ['redirect'] && empty($_SESSION['sso_hint'])) {
    header('Location: ' . APP_URL . '/tozsamosc/login.php?redirect=' . urlencode($redirect)); exit;
}

// Gdzie po zalogowaniu — domyślnie ekran startowy panelu /panel/launcher.php (wyjątki: konta tylko-EZD / tylko-CRM), patrz auth_login_landing()
function _login_landing(array $user): string {
    return auth_login_landing($user);
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
                            sms_send_with_fallback($user['twofa_phone'], "Kod 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '', 'twofa_code');
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
                $via = sms_send_with_fallback($sms_phone, "Kod logowania {$org}: {$otp} (ważny 5 min)", $found['email'] ?? '', 'login_code');
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

// ── Krok 1: jaki adres e-mail ma osoba logująca się ─────────────────────
// 'fundacja' — poczta w domenie fundacji → Microsoft 365; 'prywatny' — e-mail + hasło.
// Bez Microsoft 365, w trybie awaryjnym i po błędzie hasła od razu formularz hasła.
$konto = in_array($_GET['konto'] ?? '', ['fundacja', 'prywatny'], true) ? $_GET['konto'] : '';
if ($konto === '' && (!$ms_available || $emergency || ($error && $active_tab === 'local'))) $konto = 'prywatny';
if ($konto === 'fundacja' && !$ms_available) $konto = 'prywatny';
// Poczta w domenie fundacji = od razu Microsoft 365, bez pośredniego ekranu z drugim przyciskiem.
$_ms_url = $ms_available ? ms_auth_url($redirect ?: auth_default_landing()) : '';
if ($konto === 'fundacja') { header('Location: ' . $_ms_url); exit; }
$_konto_url = function (string $k): string {
    $q = $_GET;
    unset($q['konto']);
    if ($k !== '') $q['konto'] = $k;
    return APP_URL . '/auth/login.php' . ($q ? '?' . http_build_query($q) : '');
};

// Nazwa systemu na ekranie powitania (spójna z nagłówkiem SZO)
$_sys_name = 'Systemie Wspomagania Zarządzania Organizacją';

// ── Widok: lekka karta logowania (2026-10-20: „toporne to okno”) — Microsoft 365 + e-mail/hasło na jednym ekranie,
// inne metody schowane; bez panelu z instrukcją. Logika powyżej bez zmian; modale metod alternatywnych z _login_modals.php.
ob_start();
require __DIR__ . '/_login_modals.php';
$_modals_html = ob_get_clean();
$_b = branding_load();
$_org = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_ln_show = null;
foreach ($_login_notices as $_ln_item) { if ($_ln_item['is_pinned'] ?? 0) { $_ln_show = $_ln_item; break; } }
if (!$_ln_show && !empty($_login_notices)) $_ln_show = $_login_notices[0];
$_sso_hint = $_SESSION['sso_hint'] ?? null;
if ($_sso_hint && time() - (int)($_sso_hint['at'] ?? 0) > 900) { unset($_SESSION['sso_hint']); $_sso_hint = null; }
$has_alt = ($code_available || $sms_available || $x509_available);
$_show_ms = $ms_available && !$emergency;
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie — <?= h($_org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
body{font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;background:#f3f2f1;color:#1f1f1f;min-height:100vh;display:flex;flex-direction:column;margin:0}
/* WCAG 2.4.7/2.4.11: czerwony, gruby fokus — #c50f1f na tle #f3f2f1/#fff ≥ 5:1, odsunięty od niebieskiego przycisku */
:focus-visible{outline:3px solid #c50f1f!important;outline-offset:3px!important;box-shadow:none!important}
.lg-in:focus-visible{outline-offset:1px!important}
.lg-skip{position:absolute;left:1rem;top:-4rem;background:#1f1f1f;color:#fff;padding:.6rem 1rem;font-weight:600;z-index:10}
.lg-skip:focus{top:1rem}
.lg-in[aria-invalid=true]{border:2px solid #a4262c}
.lg-wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
.lg-card{background:#fff;width:100%;max-width:460px;padding:2.4rem 2.4rem 2rem;border:1px solid #c8c8c8;box-shadow:0 2px 6px rgba(0,0,0,.08)}
.lg-brand{margin-bottom:1.5rem}
.lg-brand img{height:44px;width:auto;display:block}
.lg-brand span{display:block;font-size:.95rem;font-weight:600;color:#1f1f1f}
.lg-h1{font-size:1.75rem;font-weight:600;margin:0 0 .25rem;color:#111}
.lg-lead{color:#3b3b3b;margin:0 0 1.6rem;font-size:1rem}
/* stałe kolory (nie z brandingu — jasny kolor marki dawał biały napis na jasnym tle): #0f6fa8 na białym = 5,6:1 */
.lg-ms{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;min-height:50px;background:#0f6fa8;color:#fff!important;text-decoration:none;font-weight:600;font-size:1.02rem}
.lg-ms:hover,.lg-ms:focus{background:#0b5582}
.lg-ms-sub{font-size:.88rem;color:#3b3b3b;text-align:center;margin:.45rem 0 0}
.lg-or{display:flex;align-items:center;gap:.8rem;margin:1.5rem 0 1.1rem;color:#3b3b3b;font-size:.9rem}
.lg-or::before,.lg-or::after{content:"";flex:1;border-top:1px solid #c8c8c8}
.lg-field{margin-bottom:.9rem}
.lg-field label{display:block;font-weight:600;font-size:.95rem;margin-bottom:.35rem;color:#111}
.lg-in{width:100%;min-height:46px;border:1px solid #6b6b6b;border-radius:0;padding:.5rem .7rem;font:inherit;font-size:1rem;color:#111;background:#fff}
.lg-in:focus{border-color:#1f1f1f}
.lg-pass{position:relative}.lg-pass .lg-in{padding-right:2.8rem}
.lg-pass button{position:absolute;right:1px;top:1px;bottom:1px;width:2.6rem;border:0;background:#fff;cursor:pointer}
.lg-btn{width:100%;min-height:48px;border:0;background:#1f1f1f;color:#fff;font-weight:600;font-size:1.02rem;cursor:pointer}
.lg-btn:hover,.lg-btn:focus{background:#000}
.lg-links{display:flex;justify-content:space-between;flex-wrap:wrap;gap:.4rem;margin-top:1rem;font-size:.95rem}
.lg-links a{color:#0b5582;font-weight:600}
.lg-alert{display:flex;gap:.6rem;align-items:flex-start;padding:.65rem .8rem;margin-bottom:1rem;font-size:.9rem;border-left:4px solid}
.lg-alert.info{background:#eff6fc;border-color:#0f6fa8}.lg-alert.ok{background:#eef7ee;border-color:#107c10}
.lg-alert.err{background:#fde7e9;border-color:#a4262c}.lg-alert.warn{background:#fff4ce;border-color:#c25100}
.lg-more{margin-top:1.4rem;border-top:1px solid #c8c8c8;padding-top:1rem}
.lg-more summary{cursor:pointer;color:#0b5582;font-size:.95rem;font-weight:600}
.lg-more .lg-alt{display:grid;gap:.4rem;margin-top:.7rem}
.lg-alt button{display:flex;align-items:center;gap:.6rem;width:100%;text-align:left;border:1px solid #e1e1e1;background:#fff;padding:.55rem .7rem;font:inherit;font-size:.9rem;cursor:pointer}
.lg-alt button:hover{border-color:#1f1f1f}
.lg-foot{text-align:center;font-size:.88rem;color:#3b3b3b;padding:0 1rem 1.2rem}
.lg-foot a{color:#3b3b3b}
@media(max-width:480px){.lg-card{padding:1.6rem 1.2rem;border:0}}
/* klasy używane w modalach metod alternatywnych (auth/_login_modals.php) */
.ks-field{margin-bottom:.9rem}.ks-field label{display:block;font-weight:600;font-size:.9rem;margin-bottom:.3rem}
.ks-muted{color:#5f5f5f;font-size:.88rem}
.ks-btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;width:100%;min-height:44px;border:2px solid #1f1f1f;background:#fff;color:#1f1f1f;font-weight:600}
.ks-btn--primary{background:#1f1f1f;color:#fff}.ks-btn--primary:hover{background:#000}
.l-alert{display:flex;gap:.6rem;align-items:flex-start;padding:.6rem .8rem;margin-bottom:.9rem;font-size:.9rem;border-left:4px solid #0f6fa8;background:#eff6fc}
.l-alert-danger{border-color:#a4262c;background:#fde7e9}.l-alert-success{border-color:#107c10;background:#eef7ee}
.pass-wrap{position:relative}.pass-toggle{position:absolute;right:1px;top:1px;bottom:1px;width:2.6rem;border:0;background:#fff}
.modal-content{border-radius:0}
</style>
</head>
<body>
<a class="lg-skip" href="#f-email">Przejdź do logowania</a>
<main class="lg-wrap" id="login-main">
  <div class="lg-card">
    <div class="lg-brand">
      <?php if ($_b['logo_url']): ?><img src="<?= h($_b['logo_url']) ?>" alt="<?= h($_org) ?>"><?php else: ?><span><?= h($_org) ?></span><?php endif; ?>
    </div>
    <h1 class="lg-h1"><?= $_login_tagline ? h($_login_tagline) : 'Zaloguj się' ?></h1>
    <p class="lg-lead"><?= $_login_welcome_is_custom ? nl2br(h($_login_welcome)) : 'System Wspomagania Zarządzania Organizacją' ?></p>

    <?php if ($_ln_show): ?><div class="lg-alert info" role="region" aria-label="Komunikat"><i class="bi bi-megaphone" aria-hidden="true"></i><div><strong><?= h($_ln_show['title']) ?></strong><?php if ($_ln_show['body']): ?><br><?= nl2br(h($_ln_show['body'])) ?><?php endif; ?></div></div><?php endif; ?>
    <?php if ($_sso_hint): ?><div class="lg-alert info" role="alert" id="sso-hint"><i class="bi bi-shield-check" aria-hidden="true"></i><div>
      <?php if (!empty($_sso_hint['reauth'])): ?><strong>Wymagane ponowne logowanie.</strong> <?php endif; ?>
      Logujesz się, aby otworzyć <?= ($_sso_hint['app'] ?? '') !== '' ? 'aplikację: <strong>' . h($_sso_hint['app']) . '</strong>' : 'zewnętrzną aplikację' ?>.
      Jeśli nie otwierasz żadnej aplikacji, nie wpisuj hasła.</div></div><?php endif; ?>
    <?php if (isset($_GET['ended'])): ?><div class="lg-alert ok" role="status"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Sesja zakończona — zaloguj się ponownie.</span></div><?php endif; ?>
    <?php if ($error && !in_array($active_tab, ['code','sms','x509'], true)): ?><div class="lg-alert err" role="alert" id="login-error-box"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span id="login-error-text"><?= h($error) ?></span></div><?php endif; ?>
    <?php if ($emergency): ?><div class="lg-alert warn" role="note"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Logowanie awaryjne — użyj adresu e-mail i <strong>hasła awaryjnego</strong>.</span></div><?php endif; ?>

    <?php if ($_show_ms): ?>
    <a class="lg-ms" href="<?= h($_ms_url) ?>"><i class="bi bi-microsoft" aria-hidden="true"></i> Zaloguj przez Microsoft 365</a>
    <p class="lg-ms-sub">Poczta w domenie fundacji (@feer.org.pl)</p>
    <div class="lg-or">albo e-mail i hasło</div>
    <?php endif; ?>

    <form method="post" novalidate autocomplete="on" aria-label="Logowanie e-mailem i hasłem">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_method" value="local">
      <div class="lg-field"><label for="f-email">Adres e-mail</label>
        <input type="email" name="email" id="f-email" class="lg-in" autocomplete="email" inputmode="email" required<?= (!$_show_ms || ($error && $active_tab === 'local')) ? ' autofocus' : '' ?><?= ($error && $active_tab === 'local') ? ' aria-invalid="true" aria-describedby="login-error-text"' : '' ?>></div>
      <div class="lg-field"><label for="f-pass">Hasło</label>
        <div class="lg-pass"><input type="password" name="password" id="f-pass" class="lg-in" autocomplete="current-password" required<?= ($error && $active_tab === 'local') ? ' aria-invalid="true" aria-describedby="login-error-text"' : '' ?>>
          <button type="button" aria-label="Pokaż hasło" aria-pressed="false" onclick="var i=document.getElementById('f-pass'),s=i.type==='password';i.type=s?'text':'password';this.setAttribute('aria-pressed',s);this.firstElementChild.className=s?'bi bi-eye-slash':'bi bi-eye'"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
      <button type="submit" class="lg-btn">Zaloguj</button>
    </form>
    <div class="lg-links"><a href="<?= APP_URL ?>/user/verify_reset.php">Nie pamiętasz hasła?</a><a href="<?= APP_URL ?>/user/register.php">Załóż konto</a></div>

    <?php if ($has_alt): ?>
    <details class="lg-more"<?= in_array($active_tab, ['code','sms','x509'], true) ? ' open' : '' ?>>
      <summary>Inne metody logowania</summary>
      <div class="lg-alt">
        <?php if ($code_available): ?><button type="button" data-bs-toggle="modal" data-bs-target="#modal-code"><i class="bi bi-key" aria-hidden="true"></i> Kod jednorazowy</button><?php endif; ?>
        <?php if ($sms_available): ?><button type="button" data-bs-toggle="modal" data-bs-target="#modal-sms"><i class="bi bi-phone" aria-hidden="true"></i> Kod SMS</button><?php endif; ?>
        <?php if ($x509_available): ?><button type="button" data-bs-toggle="modal" data-bs-target="#modal-x509"><i class="bi bi-patch-check" aria-hidden="true"></i> Certyfikat X.509 (administratorzy)</button><?php endif; ?>
      </div>
    </details>
    <?php endif; ?>
  </div>
</main>
<footer class="lg-foot"><?= h($_org) ?> · <a href="<?= APP_URL ?>/auth/help.php">Pomoc w logowaniu</a> · <a href="<?= APP_URL ?>/dostepnosc/">Deklaracja dostępności</a></footer>
<?= $_modals_html ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass(id, btn){var i=document.getElementById(id);if(!i)return;var s=i.type==='password';i.type=s?'text':'password';btn.setAttribute('aria-pressed',s);var ic=btn.querySelector('i');if(ic)ic.className=s?'bi bi-eye-slash':'bi bi-eye';}
(function(){
document.querySelectorAll(".modal").forEach(function(m){m.addEventListener("shown.bs.modal",function(){var f=m.querySelector("input:not([type=hidden])");if(f)f.focus();});});
var a=<?= json_encode(in_array($active_tab, ['code','sms','x509'], true) ? $active_tab : null) ?>;
if(a){var el=document.getElementById("modal-"+a);if(el)bootstrap.Modal.getOrCreateInstance(el).show();}
})();</script>
</body>
</html>
