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
            if ($user && account_is_office_only($user) && empty($user['allow_local_fallback'])) {
                authlog_write((int)$user['id'], 'login_blocked_office', $user['email'], 'Konto służbowe — wymagane logowanie przez Microsoft 365');
                $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office). Użyj przycisku „Zaloguj przez Microsoft 365”.';
                $active_tab = 'local';
            } elseif ($user && $user['password'] && password_verify($pass, $user['password'])) {
                brute_clear($email);
                auth_start();
                $_SESSION['_moodle_pwd'] = base64_encode($pass ^ str_repeat(session_id(), (int)ceil(strlen($pass) / 32)));
                if (!empty($user['twofa_method'])) {
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
        if ($user && account_is_office_only($user)) {
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
$_sys_name = 'Systemie Zarządzania Organizacją';
?><!DOCTYPE html>
<html lang="pl" data-fs="m">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<script>
/* Ustawienia dostępności przed pierwszym malowaniem — bez mignięcia */
(function(){try{
  var fs=localStorage.getItem('szoFs'); if(fs==='L'||fs==='XL') document.documentElement.dataset.fs=fs;
  if(localStorage.getItem('szoHc')==='1') document.documentElement.dataset.theme='hc';
}catch(e){}})();
</script>
<style>
*,*::before,*::after{box-sizing:border-box}
:root{
  --ks:var(--c,#DC2626);
  --ks-dark:var(--c-dark,#B91C1C);
  --ks-on:var(--c-text,#fff);
  --ks-ink:#1f2937;
  --ks-line:#d1d5db;
  --ks-muted:#6b7280;
  --ks-card:#fff;
  --ks-radius:18px;
}
html{font-size:16px}
html[data-fs="L"]{font-size:18px}
html[data-fs="XL"]{font-size:20px}
html,body{margin:0;padding:0;min-height:100%}
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:var(--ks-ink);background:var(--ks)}
*:focus-visible{outline:3px solid #FBBF24!important;outline-offset:2px!important}
*:focus:not(:focus-visible){outline:none}
.skip-link{position:absolute;top:-100%;left:1rem;z-index:9999;background:#fff;color:var(--ks);padding:.5rem 1.25rem;border-radius:0 0 8px 8px;font-weight:700;text-decoration:none}
.skip-link:focus{top:0}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}

/* ── Pasek dostępności ─────────────────────────────────────────────────── */
.ks-a11y{background:var(--ks-dark);color:#fff;font-size:.8rem}
.ks-a11y .in{max-width:1200px;margin:0 auto;padding:.35rem 1rem;display:flex;align-items:center;justify-content:flex-end;gap:1.25rem;flex-wrap:wrap}
.ks-a11y .grp{display:flex;align-items:center;gap:.4rem}
.ks-a11y .lbl{opacity:.9}
.ks-a11y button{background:transparent;border:1px solid rgba(255,255,255,.45);color:#fff;border-radius:4px;
  min-width:30px;min-height:26px;padding:0 .4rem;font-family:inherit;font-weight:700;cursor:pointer;line-height:1}
.ks-a11y button:hover{background:rgba(255,255,255,.18)}
.ks-a11y button[aria-pressed="true"]{background:#fff;color:var(--ks-dark);border-color:#fff}
.ks-a11y .fs-m{font-size:.72rem}.ks-a11y .fs-l{font-size:.82rem}.ks-a11y .fs-xl{font-size:.92rem}

/* ── Tło z geometrią ───────────────────────────────────────────────────── */
.ks-hero{position:relative;min-height:calc(100vh - 34px);padding:2.25rem 1rem 3rem;overflow:hidden}
.ks-hero::before{content:'';position:absolute;inset:0;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='420' height='420' viewBox='0 0 420 420'%3E%3Cg fill='%23000' fill-opacity='.055'%3E%3Crect x='24' y='40' width='120' height='120' rx='8'/%3E%3Ccircle cx='330' cy='96' r='58'/%3E%3Crect x='210' y='250' width='150' height='150' rx='8'/%3E%3Cpath d='M0 210l70-70v46l-24 24zm52 132l96-96v46l-50 50z'/%3E%3Cpath d='M300 0l60 60-24 24-60-60z'/%3E%3C/g%3E%3C/svg%3E"),
    repeating-linear-gradient(135deg,rgba(0,0,0,.045) 0 3px,transparent 3px 26px);
  background-size:420px 420px,auto}
.ks-shell{position:relative;max-width:700px;margin:0 auto}

/* ── Marka ─────────────────────────────────────────────────────────────── */
.ks-brand{display:flex;flex-direction:column;align-items:center;gap:.55rem;text-decoration:none;color:#fff;margin-bottom:2.25rem}
.ks-brand img{max-height:74px;max-width:260px;object-fit:contain}
.ks-brand .mark{width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:1.7rem}
.ks-brand .nm{font-size:1.2rem;font-weight:800;letter-spacing:-.01em;text-align:center;line-height:1.25}

/* ── Zakładki nad kartą ────────────────────────────────────────────────── */
.ks-toprow{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.ks-back{display:inline-flex;align-items:center;gap:.4rem;color:#fff;text-decoration:none;font-size:.88rem;padding:.4rem .2rem .7rem}
.ks-back:hover{color:#fff;text-decoration:underline}
.ks-tabs{display:flex;gap:.2rem;margin-left:auto}
.ks-tab{padding:.55rem 1.15rem;border-radius:10px 10px 0 0;text-decoration:none;font-size:.92rem;font-weight:600;color:#fff}
.ks-tab:hover{background:rgba(255,255,255,.16);color:#fff}
.ks-tab[aria-current="page"]{background:var(--ks-card);color:var(--ks)}

/* ── Karta ─────────────────────────────────────────────────────────────── */
.ks-card{background:var(--ks-card);border-radius:var(--ks-radius);padding:2.75rem 1.5rem 2.5rem;
  box-shadow:0 18px 44px rgba(0,0,0,.16)}
@media(min-width:576px){.ks-card{padding:3rem 3.5rem 2.75rem}}
.ks-h1{font-size:1.75rem;font-weight:800;letter-spacing:-.02em;text-align:center;margin:0 0 .5rem;line-height:1.25}
.ks-lead{text-align:center;color:var(--ks-muted);font-size:.9rem;line-height:1.55;margin:0 0 2rem}
.ks-inner{max-width:420px;margin:0 auto}

/* ── Formularz ─────────────────────────────────────────────────────────── */
.ks-field{margin-bottom:1.35rem}
.ks-field label{display:block;font-size:.92rem;color:var(--ks-ink);margin-bottom:.4rem}
.form-control{width:100%;padding:.7rem .9rem;border:1px solid var(--ks-line);border-radius:6px;font-size:1rem;
  font-family:inherit;color:var(--ks-ink);background:#fff;min-height:46px}
.form-control:focus{border-color:var(--ks);box-shadow:0 0 0 3px var(--c-ring,rgba(220,38,38,.18));outline:none}
.form-control[aria-invalid=true]{border-color:#dc2626}
.pass-wrap{position:relative}
.pass-wrap .form-control{padding-right:2.9rem}
.pass-toggle{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);background:none;border:none;
  color:var(--ks-ink);cursor:pointer;padding:.3rem;border-radius:4px;line-height:1}
.ks-forgot{display:inline-block;margin-top:.75rem;font-size:.9rem;color:var(--ks);text-decoration:underline}
.ks-forgot:hover{color:var(--ks-dark)}
.ks-btn{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;min-height:48px;
  padding:.75rem 1.25rem;border-radius:6px;font-size:1rem;font-weight:600;font-family:inherit;
  cursor:pointer;text-decoration:none;border:1px solid transparent;transition:background .13s,border-color .13s}
.ks-btn--primary{background:var(--ks);color:var(--ks-on);border-color:var(--ks)}
.ks-btn--primary:hover{background:var(--ks-dark);border-color:var(--ks-dark);color:var(--ks-on)}
.ks-btn--ghost{background:#fff;color:var(--ks-ink);border-color:var(--ks-line)}
.ks-btn--ghost:hover{border-color:var(--ks);color:var(--ks-ink);background:#fff}
.ks-btn + .ks-btn{margin-top:.75rem}
.ks-sep{border:0;border-top:1px solid #e5e7eb;margin:2rem 0 1.5rem}
.ks-sub{text-align:center;font-size:.95rem;font-weight:600;margin:0 0 1rem}
.ks-hint{font-size:.8rem;color:var(--ks-muted);text-align:center;margin:.5rem 0 0;line-height:1.5}
.ks-or{display:flex;align-items:center;gap:.75rem;color:var(--ks-muted);font-size:.82rem;margin:1.5rem 0}
.ks-or::before,.ks-or::after{content:'';flex:1;height:1px;background:#e5e7eb}
.ks-optsub{display:block;font-size:.78rem;color:var(--ks-muted);font-weight:400;margin-top:.1rem}

/* ── Alerty ────────────────────────────────────────────────────────────── */
.l-alert{display:flex;gap:.6rem;align-items:flex-start;padding:.8rem 1rem;border-radius:8px;font-size:.9rem;
  margin-bottom:1.25rem;line-height:1.5}
.l-alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.l-alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.l-alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}

/* ── Stopka ────────────────────────────────────────────────────────────── */
.ks-links{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.3rem .9rem;margin:1.5rem 0 .5rem}
.ks-links a{font-size:.85rem;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;opacity:.92}
.ks-links a:hover{color:#fff;text-decoration:underline;opacity:1}
.ks-links .dot{color:rgba(255,255,255,.5);font-size:.7rem}
.ks-copy{text-align:center;color:rgba(255,255,255,.75);font-size:.78rem}

/* ── Modale ────────────────────────────────────────────────────────────── */
.lm-content{border:none;border-radius:14px;overflow:hidden}
.lm-hd{padding:.9rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.lm-title{font-size:1.02rem;font-weight:700;margin:0;display:flex;align-items:center;gap:.45rem}
.lm-body{padding:1.25rem 1.5rem 1.5rem}
.sms-otp{font-size:1.8rem;letter-spacing:.45rem;text-align:center;font-family:monospace;font-weight:700}

/* ── Wysoki kontrast ───────────────────────────────────────────────────── */
html[data-theme="hc"]{--ks:#000;--ks-dark:#000;--ks-on:#fff;--ks-ink:#000;--ks-line:#000;--ks-muted:#000}
html[data-theme="hc"] body{background:#000}
html[data-theme="hc"] .ks-hero::before{display:none}
html[data-theme="hc"] .ks-card{box-shadow:none;border:3px solid #000}
html[data-theme="hc"] .form-control{border-width:2px}
html[data-theme="hc"] .ks-btn{border-width:2px}
html[data-theme="hc"] .ks-btn--ghost{background:#fff;color:#000;border-color:#000}
html[data-theme="hc"] .ks-btn--ghost:hover{background:#000;color:#fff}
html[data-theme="hc"] .l-alert{background:#fff;border:2px solid #000;color:#000}
html[data-theme="hc"] .ks-a11y{border-bottom:2px solid #fff}
html[data-theme="hc"] .ks-tab[aria-current="page"]{background:#fff;color:#000}

@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
@media(max-width:575.98px){.ks-h1{font-size:1.45rem}.ks-hero{padding-top:1.5rem}}
</style>
</head>
<body>

<a href="#login-main" class="skip-link">Przejdź do formularza logowania</a>
<div role="status" aria-live="polite"    aria-atomic="true" id="login-live"  class="sr"></div>
<div role="alert"  aria-live="assertive" aria-atomic="true" id="login-alert" class="sr"></div>

<!-- ══ Pasek dostępności ════════════════════════════════════════════════ -->
<div class="ks-a11y">
  <div class="in">
    <div class="grp" role="group" aria-label="Rozmiar tekstu">
      <span class="lbl">Rozmiar tekstu:</span>
      <button type="button" class="fs-m"  data-fs="m"  aria-pressed="true">m</button>
      <button type="button" class="fs-l"  data-fs="L"  aria-pressed="false">L</button>
      <button type="button" class="fs-xl" data-fs="XL" aria-pressed="false">XL</button>
    </div>
    <div class="grp">
      <span class="lbl" id="hc-lbl">Wysoki kontrast:</span>
      <button type="button" id="hc-btn" aria-pressed="false" aria-labelledby="hc-lbl hc-btn" aria-label="Wysoki kontrast — włącz">
        <i class="bi bi-circle-half" aria-hidden="true"></i>
      </button>
    </div>
  </div>
</div>

<div class="ks-hero">
<div class="ks-shell">

  <!-- ══ Marka ═════════════════════════════════════════════════════════ -->
  <a href="<?= APP_URL ?>" class="ks-brand">
    <?php if ($_b['logo_url']): ?>
      <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>">
    <?php else: ?>
      <span class="mark" aria-hidden="true"><i class="bi bi-building-heart"></i></span>
      <span class="nm"><?= h($org_name) ?></span>
    <?php endif; ?>
  </a>

  <!-- ══ Powrót + zakładki ═════════════════════════════════════════════ -->
  <div class="ks-toprow">
    <a href="<?= APP_URL ?>" class="ks-back"><i class="bi bi-chevron-left" aria-hidden="true"></i>Strona publiczna</a>
    <nav class="ks-tabs" aria-label="Logowanie lub rejestracja">
      <a class="ks-tab" href="<?= APP_URL ?>/auth/login.php" aria-current="page">Zaloguj</a>
      <a class="ks-tab" href="<?= APP_URL ?>/user/register.php">Rejestracja</a>
    </nav>
  </div>

  <!-- ══ Karta ═════════════════════════════════════════════════════════ -->
  <main class="ks-card" id="login-main" tabindex="-1">
  <div class="ks-inner">

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
    <a href="<?= APP_URL ?>/user/register.php" class="ks-btn ks-btn--ghost">Zarejestruj się</a>
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

  </div>
  </main>

  <!-- ══ Linki ═════════════════════════════════════════════════════════ -->
  <div class="ks-links">
    <a href="<?= h($_url_dyd) ?>"><i class="bi bi-easel2" aria-hidden="true"></i>Panel dydaktyka</a>
    <span class="dot" aria-hidden="true">•</span>
    <?php if ($sel_url): ?>
    <a href="<?= h($sel_url) ?>"><i class="bi bi-buildings" aria-hidden="true"></i><?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?></a>
    <span class="dot" aria-hidden="true">•</span>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/auth/report_login_issue.php"><i class="bi bi-life-preserver" aria-hidden="true"></i>Problem z logowaniem</a>
  </div>
  <div class="ks-copy">&copy; <?= date('Y') ?> <?= h($org_name) ?></div>

</div>
</div>

<!-- ══ Modale ════════════════════════════════════════════════════════════════ -->

<?php if ($code_available): ?>
<div class="modal fade" id="modal-code" tabindex="-1" aria-labelledby="mcode-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content lm-content">
      <div class="lm-hd">
        <h2 class="lm-title" id="mcode-title"><i class="bi bi-key-fill" aria-hidden="true"></i> Kod jednorazowy</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="lm-body">
        <?php if ($error && $active_tab === 'code'): ?>
        <div class="l-alert l-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <p style="font-size:.85rem;color:var(--ks-muted);margin:0 0 1rem">Kod jednorazowy wysłany przez administratora lub wygenerowany na Twoją prośbę.</p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="mcode-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="code">
          <div class="ks-field">
            <label for="f-code">Kod dostępu</label>
            <input type="text" name="login_code" id="f-code" class="form-control"
                   autocomplete="off" spellcheck="false" required aria-required="true"
                   placeholder="XXXX-XXXX-XXXX">
          </div>
          <button type="submit" class="ks-btn ks-btn--primary">
            <i class="bi bi-key-fill" aria-hidden="true"></i> Zaloguj kodem
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($sms_available): ?>
<div class="modal fade" id="modal-sms" tabindex="-1" aria-labelledby="msms-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content lm-content">
      <div class="lm-hd">
        <h2 class="lm-title" id="msms-title"><i class="bi bi-phone-fill" aria-hidden="true"></i> Kod SMS</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="lm-body">
        <?php if ($error && $active_tab === 'sms'): ?>
        <div class="l-alert l-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($info && $active_tab === 'sms'): ?>
        <div class="l-alert l-alert-success" role="status">
          <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
          <span><?= h($info) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($sms_step === 1): ?>
        <p style="font-size:.85rem;color:var(--ks-muted);margin:0 0 1rem">Wpisz numer telefonu powiązany z Twoim kontem.</p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="msms-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="sms_send">
          <div class="ks-field">
            <label for="f-sms-phone">Numer telefonu</label>
            <div style="display:flex;gap:0">
              <span style="display:inline-flex;align-items:center;padding:.65rem .8rem;background:#f8fafc;border:1px solid var(--ks-line);border-right:none;border-radius:10px 0 0 10px;font-weight:700;color:#374151;font-size:.97rem" aria-hidden="true">+48</span>
              <input type="tel" name="sms_phone" id="f-sms-phone" class="form-control"
                     style="border-radius:0 10px 10px 0" placeholder="123 456 789"
                     value="<?= h($sms_phone) ?>" inputmode="numeric" pattern="[0-9 ]{9,11}"
                     autocomplete="tel-national" required aria-required="true"
                     aria-label="Numer telefonu bez prefiksu +48">
            </div>
          </div>
          <button type="submit" class="ks-btn ks-btn--primary">
            <i class="bi bi-send" aria-hidden="true"></i> Wyślij kod SMS
          </button>
        </form>
        <?php else: ?>
        <p style="font-size:.87rem;color:#374151;margin:0 0 1rem;line-height:1.5">
          Kod wysłany na <strong><?= h($sms_phone) ?></strong>. Ważny 5 minut.
        </p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="msms-title">
          <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
          <input type="hidden" name="_method"   value="sms_verify">
          <input type="hidden" name="sms_phone" value="<?= h($sms_phone) ?>">
          <div class="ks-field">
            <label for="f-sms-code">6-cyfrowy kod SMS</label>
            <input type="text" name="sms_code" id="f-sms-code" class="form-control sms-otp"
                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   placeholder="000000" autocomplete="one-time-code" required aria-required="true">
          </div>
          <button type="submit" class="ks-btn ks-btn--primary" style="margin-bottom:.65rem">
            Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </button>
          <button type="button"
                  style="background:none;border:none;color:var(--ks-muted);font-size:.83rem;cursor:pointer;padding:.4rem;width:100%;text-align:center;border-radius:6px"
                  onclick="document.querySelector('[name=_method]').value='sms_send';this.closest('form').submit()">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Zmień numer
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($x509_available): ?>
<div class="modal fade" id="modal-x509" tabindex="-1" aria-labelledby="mx509-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content lm-content">
      <div class="lm-hd">
        <h2 class="lm-title" id="mx509-title"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Certyfikat X.509</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="lm-body">
        <?php if ($error && $active_tab === 'x509'): ?>
        <div class="l-alert l-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <p style="font-size:.85rem;color:var(--ks-muted);margin:0 0 1rem">Plik PKCS#12 (.p12) wygenerowany przez administratora systemu.</p>
        <form method="post" enctype="multipart/form-data" novalidate aria-labelledby="mx509-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="x509">
          <div class="ks-field">
            <label for="f-p12">Plik certyfikatu (.p12 lub .pfx)</label>
            <input type="file" name="p12_file" id="f-p12" class="form-control"
                   accept=".p12,.pfx" required aria-required="true">
          </div>
          <div class="ks-field">
            <label for="f-cert-pass">Hasło certyfikatu</label>
            <div class="pass-wrap">
              <input type="password" name="cert_password" id="f-cert-pass" class="form-control"
                     autocomplete="current-password" required aria-required="true">
              <button type="button" class="pass-toggle" aria-label="Pokaż hasło certyfikatu" aria-pressed="false"
                      onclick="togglePass('f-cert-pass', this)">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="ks-btn ks-btn--primary">
            <i class="bi bi-patch-check-fill" aria-hidden="true"></i> Zaloguj certyfikatem
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
'use strict';
function togglePass(id, btn){
  var inp=document.getElementById(id); if(!inp) return;
  var h=inp.type!=='password'; inp.type=h?'password':'text';
  btn.setAttribute('aria-pressed',h?'false':'true');
  btn.setAttribute('aria-label',h?'Pokaż hasło':'Ukryj hasło');
  btn.querySelector('i').className=h?'bi bi-eye':'bi bi-eye-slash';
}
window.togglePass=togglePass;

/* ── Pasek dostępności: rozmiar tekstu + wysoki kontrast ── */
var root=document.documentElement;
function setFs(v){
  root.dataset.fs=v;
  try{localStorage.setItem('szoFs',v);}catch(e){}
  document.querySelectorAll('[data-fs]').forEach(function(b){
    if(b.tagName==='BUTTON') b.setAttribute('aria-pressed', b.dataset.fs===v?'true':'false');
  });
}
document.querySelectorAll('.ks-a11y button[data-fs]').forEach(function(b){
  b.addEventListener('click',function(){setFs(b.dataset.fs);});
});
setFs(root.dataset.fs||'m');

var hcBtn=document.getElementById('hc-btn');
function setHc(on){
  if(on) root.dataset.theme='hc'; else root.removeAttribute('data-theme');
  hcBtn.setAttribute('aria-pressed',on?'true':'false');
  hcBtn.setAttribute('aria-label',on?'Wysoki kontrast — wyłącz':'Wysoki kontrast — włącz');
  try{localStorage.setItem('szoHc',on?'1':'0');}catch(e){}
}
hcBtn.addEventListener('click',function(){setHc(root.dataset.theme!=='hc');});
setHc(root.dataset.theme==='hc');


var errText=document.getElementById('login-error-text');
var liveErr=document.getElementById('login-alert');
if(errText && liveErr) liveErr.textContent=errText.textContent.trim();

document.querySelectorAll('.modal').forEach(function(m){
  m.addEventListener('shown.bs.modal',function(){
    var f=m.querySelector('input:not([type=hidden])'); if(f) f.focus();
  });
});
var autoOpen=<?= json_encode(in_array($active_tab,['code','sms','x509'],true)?$active_tab:null) ?>;
if(autoOpen){var el=document.getElementById('modal-'+autoOpen);if(el) bootstrap.Modal.getOrCreateInstance(el).show();}
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
