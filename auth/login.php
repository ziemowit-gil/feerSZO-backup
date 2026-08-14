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
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#0f172a}

.skip-link{position:absolute;top:-100%;left:1rem;z-index:9999;background:var(--c,#2563eb);color:#fff;padding:.5rem 1.25rem;border-radius:0 0 8px 8px;font-weight:700;text-decoration:none}
.skip-link:focus{top:0;outline:3px solid #FBBF24;outline-offset:2px}
*:focus-visible{outline:3px solid #FBBF24!important;outline-offset:3px!important}
*:focus:not(:focus-visible){outline:none}

/* ── Układ ─── */
.login-layout{min-height:100vh;display:flex;flex-direction:column;background:#fff}
@media(min-width:960px){.login-layout{flex-direction:row}}

/* ── Aside (lewy panel brandowy) ─── */
.login-aside{
  position:relative;overflow:hidden;padding:2rem 1.75rem;
  background:linear-gradient(135deg,var(--c,#2563eb),var(--c-dark,#1d4ed8));
  color:#fff;display:flex;flex-direction:column;justify-content:space-between;gap:2rem;
}
@media(min-width:960px){.login-aside{width:44%;padding:3.5rem}}
.aside-decor{position:absolute;inset:0;opacity:.18;pointer-events:none}
.aside-decor .blob{position:absolute;border-radius:50%;filter:blur(70px)}
.aside-decor .blob-1{top:-6rem;left:-6rem;width:22rem;height:22rem;background:rgba(255,255,255,.22)}
.aside-decor .blob-2{bottom:-7rem;right:-4rem;width:20rem;height:20rem;background:rgba(255,255,255,.12)}
.aside-decor .dots{position:absolute;inset:0;background-image:radial-gradient(circle at 1px 1px,rgba(255,255,255,.32) 1px,transparent 0);background-size:26px 26px}
.aside-brand{position:relative;display:inline-flex;align-items:center;gap:.7rem;text-decoration:none;color:#fff;align-self:flex-start}
.aside-brand-icon{width:3rem;height:3rem;border-radius:.75rem;background:rgba(255,255,255,.92);color:var(--c,#2563eb);display:flex;align-items:center;justify-content:center;font-size:1.5rem}
.aside-brand img{width:3rem;height:3rem;border-radius:.75rem;object-fit:contain;background:#fff;padding:.25rem}
.aside-brand-name{font-size:1.05rem;font-weight:800;letter-spacing:-.01em}
.aside-hero{position:relative}
.aside-hero h1{font-size:2rem;font-weight:800;line-height:1.2;margin:0;letter-spacing:-.02em}
.aside-hero p{margin:.8rem 0 0;color:rgba(255,255,255,.8);font-size:.95rem;line-height:1.6;max-width:24rem}
.aside-foot{position:relative;font-size:.78rem;color:rgba(255,255,255,.6)}
@media(max-width:959px){.aside-hero,.aside-foot{display:none}.login-aside{padding:1.25rem 1.5rem}}

/* ── Panel formularza ─── */
.login-panel{flex:1;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.25rem}
.login-wrap{width:100%;max-width:420px}
.login-card{animation:lIn .35s cubic-bezier(.16,.84,.44,1) both}
@keyframes lIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

.login-heading{font-size:1.55rem;font-weight:800;letter-spacing:-.02em;margin:0 0 .3rem}
.login-sub{font-size:.88rem;color:#64748b;margin:0 0 1.5rem;line-height:1.5}

/* ── Alerty ─── */
.l-alert{display:flex;gap:.55rem;align-items:flex-start;padding:.7rem .9rem;border-radius:10px;font-size:.87rem;margin-bottom:1.1rem;line-height:1.45}
.l-alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.l-alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.l-alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}

/* ── MS365 ─── */
.btn-ms365{display:flex;align-items:center;justify-content:center;gap:.7rem;width:100%;padding:.85rem 1.25rem;background:#fff;color:#1e293b;border:2px solid #d1d5db;border-radius:10px;font-size:.97rem;font-weight:700;text-decoration:none;transition:border-color .13s,box-shadow .13s;cursor:pointer;min-height:50px}
.btn-ms365:hover{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);color:#1e293b}

/* ── Separator ─── */
.or-div{display:flex;align-items:center;gap:.7rem;color:#94a3b8;font-size:.8rem;margin:1.2rem 0}
.or-div::before,.or-div::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* ── Formularz ─── */
.form-label{display:block;font-size:.84rem;font-weight:600;color:#374151;margin-bottom:.3rem}
.form-control{width:100%;padding:.65rem .85rem;border:2px solid #e2e8f0;border-radius:10px;font-size:.97rem;font-family:inherit;color:#0f172a;background:#fff;transition:border-color .13s,box-shadow .13s}
.form-control:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none}
.form-control[aria-invalid=true]{border-color:#ef4444}
.form-hint{font-size:.76rem;color:#94a3b8;margin:.3rem 0 0;line-height:1.4}
.pass-wrap{position:relative}
.pass-wrap .form-control{padding-right:2.8rem}
.pass-toggle{position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:.25rem;line-height:1;border-radius:4px}
.pass-toggle:hover{color:#475569}
.fmb{margin-bottom:1rem}
.fmb-last{margin-bottom:1.25rem}
.pass-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem}
.forgot-link{font-size:.8rem;color:#64748b;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem}
.forgot-link:hover{color:var(--c,#2563eb)}

.btn-login{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.85rem 1.25rem;background:var(--c,#2563eb);color:var(--c-text,#fff);border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;transition:filter .13s,box-shadow .13s,transform .1s;min-height:50px}
.btn-login:hover{filter:brightness(1.06);box-shadow:0 8px 22px rgba(37,99,235,.32);transform:translateY(-1px);color:var(--c-text,#fff)}
.btn-login:active{transform:translateY(0)}

/* ── Więcej opcji ─── */
.more-opts{margin-top:1.2rem;border-top:1px solid #f1f5f9;padding-top:.9rem}
.more-opts-label{font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:.55rem;display:block}
.more-opts-list{display:flex;flex-direction:column;gap:.4rem}
.more-opt-btn{display:flex;align-items:center;gap:.65rem;width:100%;padding:.55rem .7rem;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:9px;cursor:pointer;text-align:left;font-family:inherit;transition:border-color .12s}
.more-opt-btn:hover{border-color:var(--c,#2563eb);background:#eff6ff}
.more-opt-icon{width:30px;height:30px;border-radius:7px;background:var(--c-bg,#eff6ff);color:var(--c,#2563eb);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.9rem}
.more-opt-label{font-size:.84rem;font-weight:600;color:#0f172a}
.more-opt-sub{font-size:.73rem;color:#64748b;display:block;margin-top:.05rem}

/* ── Nowy wolontariusz CTA ─── */
.new-vol{display:flex;align-items:center;gap:.75rem;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:.85rem 1rem;margin-top:1.2rem;text-decoration:none;color:inherit;transition:border-color .12s,background .12s}
.new-vol:hover{border-color:#4ade80;background:#dcfce7;color:inherit}
.new-vol-icon{font-size:1.3rem;color:#16a34a;flex-shrink:0}
.new-vol-body{flex:1;min-width:0}
.new-vol-title{font-size:.9rem;font-weight:700;color:#15803d;display:block}
.new-vol-sub{font-size:.78rem;color:#4b5563;display:block;margin-top:.1rem}
.new-vol-arrow{color:#16a34a;font-size:.9rem;flex-shrink:0}

/* ── Stopka linków ─── */
.login-links{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.35rem .7rem;margin-top:1.4rem;padding-top:1.1rem;border-top:1px solid #f1f5f9}
.login-links a{font-size:.78rem;color:#94a3b8;text-decoration:none;display:inline-flex;align-items:center;gap:.2rem}
.login-links a:hover{color:var(--c,#2563eb)}
.login-links .dot{color:#e2e8f0;font-size:.65rem}

/* ── Modal ─── */
.lm-content{border:none;border-radius:16px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18)}
.lm-hd{padding:.9rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between}
.lm-title{font-size:1.02rem;font-weight:700;margin:0;display:flex;align-items:center;gap:.45rem}
.lm-body{padding:1.25rem 1.5rem 1.5rem}
.sms-otp{font-size:1.8rem;letter-spacing:.45rem;text-align:center;font-family:monospace;font-weight:700}

@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
@media(prefers-contrast:high){.form-control,.btn-login,.btn-ms365{border-width:3px}.btn-login{background:#000!important;border-color:#000!important}}
@media(max-width:520px){.login-panel{padding:1.75rem .9rem;align-items:flex-start}}
</style>
</head>
<body>

<a href="#login-main" class="skip-link">Przejdź do formularza logowania</a>
<div role="status"  aria-live="polite"    aria-atomic="true" id="login-live"  style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)"></div>
<div role="alert"   aria-live="assertive" aria-atomic="true" id="login-alert" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)"></div>

<div class="login-layout">

<!-- ══ Aside ═══════════════════════════════════════════════════════════════ -->
<aside class="login-aside">
  <div class="aside-decor" aria-hidden="true">
    <span class="blob blob-1"></span>
    <span class="blob blob-2"></span>
    <span class="dots"></span>
  </div>
  <a href="<?= APP_URL ?>" class="aside-brand">
    <?php if ($_b['logo_url']): ?>
      <img src="<?= h($_b['logo_url']) ?>" alt="">
    <?php else: ?>
      <span class="aside-brand-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></span>
    <?php endif; ?>
    <span class="aside-brand-name"><?= h($org_name) ?></span>
  </a>
  <div class="aside-hero">
    <h1><?= $_login_tagline ? h($_login_tagline) : 'Jeden login,<br>wszystkie systemy.' ?></h1>
    <p>Zaloguj się, aby przejść do panelu organizacji.</p>
  </div>
  <div class="aside-foot">&copy; <?= date('Y') ?> <?= h($org_name) ?></div>
</aside>

<!-- ══ Formularz ════════════════════════════════════════════════════════════ -->
<div class="login-panel">
<div class="login-wrap">
<main class="login-card" id="login-main" tabindex="-1">

  <?php // Admin announcement
  $_ln_show = null;
  foreach ($_login_notices as $_ln_item) {
      if ($_ln_item['is_pinned'] ?? 0) { $_ln_show = $_ln_item; break; }
  }
  if (!$_ln_show && !empty($_login_notices)) $_ln_show = $_login_notices[0];
  if ($_ln_show): ?>
  <div class="l-alert l-alert-info" role="region" aria-label="Komunikat" style="margin-bottom:1.1rem">
    <i class="bi bi-megaphone-fill flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong><?= h($_ln_show['title']) ?></strong>
      <?php if ($_ln_show['body']): ?><br><span style="font-size:.83rem"><?= nl2br(h($_ln_show['body'])) ?></span><?php endif; ?>
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
  <div class="l-alert" style="background:#7c2d12;color:#fff;border-color:#92400e" role="note">
    <i class="bi bi-shield-lock-fill flex-shrink-0" aria-hidden="true"></i>
    <span>Logowanie awaryjne — użyj <strong>adresu e-mail</strong> i <strong>hasła awaryjnego</strong>.</span>
  </div>
  <?php endif; ?>

  <?php if ($_login_welcome_is_custom): ?>
  <p style="font-size:.85rem;color:#64748b;line-height:1.6;margin:0 0 1.2rem;text-align:center"><?= nl2br(h($_login_welcome)) ?></p>
  <?php endif; ?>

  <h1 class="login-heading">Zaloguj się</h1>
  <p class="login-sub">
    <?php if ($ms_available): ?>Administracja: użyj Microsoft 365. Współpracownicy: e-mail i hasło.
    <?php else: ?>Wpisz adres e-mail i hasło.
    <?php endif; ?>
  </p>

  <?php if ($ms_available): ?>
  <!-- ── MS365 ───────────────────────────────────────────── -->
  <a href="<?= h(ms_auth_url($redirect ?: APP_URL . '/tozsamosc/index.php')) ?>"
     class="btn-ms365"
     aria-label="Zaloguj się przez Microsoft 365 — zostaniesz przekierowany do Microsoft">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
      <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
      <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
    </svg>
    Zaloguj przez Microsoft 365
  </a>
  <p style="font-size:.76rem;color:#94a3b8;text-align:center;margin:.5rem 0 0">
    Konto służbowe <strong>@feer.org.pl</strong> — SSO, bez wpisywania hasła
  </p>
  <div class="or-div"><span>lub e-mailem i hasłem</span></div>
  <?php endif; ?>

  <!-- ── Email + hasło ───────────────────────────────────── -->
  <form method="post" novalidate autocomplete="on" aria-label="Logowanie e-mailem i hasłem">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_method" value="local">

    <div class="fmb">
      <label class="form-label" for="f-email">Adres e-mail</label>
      <input type="email" name="email" id="f-email" class="form-control"
             placeholder="nazwa@domena.pl"
             autocomplete="email" inputmode="email" required
             <?= (!$ms_available) ? 'autofocus' : '' ?>
             <?php if ($error && $active_tab === 'local'): ?>aria-invalid="true"<?php endif; ?>>
    </div>

    <div class="fmb-last">
      <div class="pass-row">
        <label class="form-label" for="f-pass" style="margin:0">Hasło</label>
        <a href="<?= APP_URL ?>/auth/forgot.php" class="forgot-link" tabindex="0">
          <i class="bi bi-question-circle" aria-hidden="true"></i> Zapomniałem hasła
        </a>
      </div>
      <div class="pass-wrap">
        <input type="password" name="password" id="f-pass" class="form-control"
               autocomplete="current-password" required
               <?php if ($error && $active_tab === 'local'): ?>aria-invalid="true"<?php endif; ?>>
        <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
                onclick="togglePass('f-pass', this)">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn-login">
      Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
  </form>

  <!-- ── Nowy współpracownik ──────────────────────────────── -->
  <a href="<?= APP_URL ?>/user/register.php" class="new-vol"
     aria-label="Załóż konto współpracownika — otwiera formularz rejestracji">
    <i class="bi bi-person-plus-fill new-vol-icon" aria-hidden="true"></i>
    <span class="new-vol-body">
      <span class="new-vol-title">Nowy współpracownik?</span>
      <span class="new-vol-sub">Masz umowę lub porozumienie? Utwórz konto w 2 minuty.</span>
    </span>
    <i class="bi bi-chevron-right new-vol-arrow" aria-hidden="true"></i>
  </a>

  <!-- ── Więcej opcji ─────────────────────────────────────── -->
  <?php $has_alt = $code_available || $sms_available || $x509_available; if ($has_alt): ?>
  <div class="more-opts">
    <span class="more-opts-label">Inne metody logowania</span>
    <div class="more-opts-list" role="list">
      <?php if ($code_available): ?>
      <button class="more-opt-btn" type="button" data-bs-toggle="modal" data-bs-target="#modal-code" role="listitem">
        <span class="more-opt-icon"><i class="bi bi-key-fill" aria-hidden="true"></i></span>
        <span><span class="more-opt-label">Kod jednorazowy</span><span class="more-opt-sub">Pierwsze logowanie lub dostęp od administratora</span></span>
      </button>
      <?php endif; ?>
      <?php if ($sms_available): ?>
      <button class="more-opt-btn" type="button" data-bs-toggle="modal" data-bs-target="#modal-sms" role="listitem">
        <span class="more-opt-icon"><i class="bi bi-phone-fill" aria-hidden="true"></i></span>
        <span><span class="more-opt-label">Kod SMS</span><span class="more-opt-sub">Logowanie przez numer telefonu</span></span>
      </button>
      <?php endif; ?>
      <?php if ($x509_available): ?>
      <button class="more-opt-btn" type="button" data-bs-toggle="modal" data-bs-target="#modal-x509" role="listitem">
        <span class="more-opt-icon"><i class="bi bi-patch-check-fill" aria-hidden="true"></i></span>
        <span><span class="more-opt-label">Certyfikat X.509</span><span class="more-opt-sub">Plik .p12 — dla adminów systemu</span></span>
      </button>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Linki nawigacyjne ─────────────────────────────────── -->
  <div class="login-links">
    <a href="<?= APP_URL ?>/user/verify_reset.php"><i class="bi bi-key" aria-hidden="true"></i> Odzyskaj dostęp</a>
    <span class="dot" aria-hidden="true">·</span>
    <a href="<?= h($_url_dyd) ?>"><i class="bi bi-easel2" aria-hidden="true"></i> Panel dydaktyka</a>
    <?php if ($sel_url): ?>
    <span class="dot" aria-hidden="true">·</span>
    <a href="<?= h($sel_url) ?>"><?= $is_tenant ? 'Zmień org' : 'Wybierz org' ?></a>
    <?php endif; ?>
    <span class="dot" aria-hidden="true">·</span>
    <a href="<?= APP_URL ?>/auth/report_login_issue.php"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Pomoc</a>
  </div>

</main>
</div>
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
        <p style="font-size:.85rem;color:#64748b;margin:0 0 1rem">Kod jednorazowy wysłany przez administratora lub wygenerowany na Twoją prośbę.</p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="mcode-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="code">
          <div class="fmb-last">
            <label class="form-label" for="f-code">Kod dostępu</label>
            <input type="text" name="login_code" id="f-code" class="form-control"
                   autocomplete="off" spellcheck="false" required aria-required="true"
                   placeholder="XXXX-XXXX-XXXX">
          </div>
          <button type="submit" class="btn-login">
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
        <p style="font-size:.85rem;color:#64748b;margin:0 0 1rem">Wpisz numer telefonu powiązany z Twoim kontem.</p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="msms-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="sms_send">
          <div class="fmb-last">
            <label class="form-label" for="f-sms-phone">Numer telefonu</label>
            <div style="display:flex;gap:0">
              <span style="display:inline-flex;align-items:center;padding:.65rem .8rem;background:#f8fafc;border:2px solid #94a3b8;border-right:none;border-radius:10px 0 0 10px;font-weight:700;color:#374151;font-size:.97rem" aria-hidden="true">+48</span>
              <input type="tel" name="sms_phone" id="f-sms-phone" class="form-control"
                     style="border-radius:0 10px 10px 0" placeholder="123 456 789"
                     value="<?= h($sms_phone) ?>" inputmode="numeric" pattern="[0-9 ]{9,11}"
                     autocomplete="tel-national" required aria-required="true"
                     aria-label="Numer telefonu bez prefiksu +48">
            </div>
          </div>
          <button type="submit" class="btn-login">
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
          <div class="fmb-last">
            <label class="form-label" for="f-sms-code">6-cyfrowy kod SMS</label>
            <input type="text" name="sms_code" id="f-sms-code" class="form-control sms-otp"
                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   placeholder="000000" autocomplete="one-time-code" required aria-required="true">
          </div>
          <button type="submit" class="btn-login" style="margin-bottom:.65rem">
            Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </button>
          <button type="button"
                  style="background:none;border:none;color:#64748b;font-size:.83rem;cursor:pointer;padding:.4rem;width:100%;text-align:center;border-radius:6px"
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
        <p style="font-size:.85rem;color:#64748b;margin:0 0 1rem">Plik PKCS#12 (.p12) wygenerowany przez administratora systemu.</p>
        <form method="post" enctype="multipart/form-data" novalidate aria-labelledby="mx509-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="x509">
          <div class="fmb">
            <label class="form-label" for="f-p12">Plik certyfikatu (.p12 lub .pfx)</label>
            <input type="file" name="p12_file" id="f-p12" class="form-control"
                   accept=".p12,.pfx" required aria-required="true">
          </div>
          <div class="fmb-last">
            <label class="form-label" for="f-cert-pass">Hasło certyfikatu</label>
            <div class="pass-wrap">
              <input type="password" name="cert_password" id="f-cert-pass" class="form-control"
                     autocomplete="current-password" required aria-required="true">
              <button type="button" class="pass-toggle" aria-label="Pokaż hasło certyfikatu" aria-pressed="false"
                      onclick="togglePass('f-cert-pass', this)">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn-login">
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
