<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
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

if (current_user()) { header('Location: ' . APP_URL . '/portal.php'); exit; }

$raw_redirect = $_GET['redirect'] ?? '';
$redirect = ($raw_redirect && str_starts_with($raw_redirect, APP_URL . '/'))
    ? $raw_redirect : APP_URL . '/portal.php';

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
            if ($user && account_is_office_only($user['email'] ?? '') && empty($user['allow_local_fallback'])) {
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
                webauthn_migrate();
                if (in_array($user['role'] ?? '', ['admin', 'editor'], true) && webauthn_user_has_keys((int)$user['id'])) {
                    $_SESSION['webauthn_pending_uid'] = (int)$user['id'];
                    header('Location: ' . APP_URL . '/auth/webauthn.php?redirect=' . urlencode($redirect));
                    exit;
                }
                login_user($user);
                if (auth_must_change_password($user)) {
                    flash_set('warning', 'Administrator zresetował Twoje hasło. Ustaw nowe przed kontynuowaniem.');
                    header('Location: ' . APP_URL . '/panel/password.php?force=1'); exit;
                }
                // Konta zawężone → portal.php zdecyduje: launcher (gdy są
                // dodatkowe moduły) albo przekierowanie do modułu bazowego.
                if (($user['role'] ?? '') === 'crm_user' || ($user['role'] ?? '') === 'ezd_user') {
                    header('Location: ' . APP_URL . '/portal.php'); exit;
                }
                header('Location: ' . $redirect); exit;
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
        if ($user && account_is_office_only($user['email'] ?? '')) {
            authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365');
            $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
            $active_tab = 'sms';
        } elseif ($user) {
            log_auth_action((int)$user['id'], 'login_sms', 'Logowanie SMS: ' . $sms_phone);
            authlog_write((int)$user['id'], 'login_sms', $user['email'] ?? $sms_phone, 'Logowanie SMS');
            login_user($user);
            if (($user['role'] ?? '') === 'crm_user' || ($user['role'] ?? '') === 'ezd_user') {
                header('Location: ' . APP_URL . '/portal.php'); exit;
            }
            header('Location: ' . $redirect); exit;
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
            if ($user && account_is_office_only($user['email'] ?? '')) {
                authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365');
                $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
                $active_tab = 'x509';
            } elseif ($user) {
                log_auth_action((int)$user['id'], 'login_x509', 'Logowanie X.509: ' . $user['email']);
                authlog_write((int)$user['id'], 'login_x509', $user['email'], 'Logowanie certyfikatem X.509');
                login_user($user);
                header('Location: ' . $redirect); exit;
            }
            $error = 'Nieprawidłowy certyfikat, błędne hasło lub certyfikat wygasł/unieważniony.';
        }
    }

    elseif ($method === 'code') {
        $code = trim($_POST['login_code'] ?? '');
        $user = auth_login_by_code($code);
        if ($user && account_is_office_only($user['email'] ?? '')) {
            authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '', 'Konto służbowe — wymagane logowanie przez Microsoft 365');
            $error      = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
            $active_tab = 'code';
        } elseif ($user) {
            try {
                db()->prepare("UPDATE users SET login_code=NULL WHERE id=?")->execute([$user['id']]);
            } catch (\Throwable $e) {}
            log_auth_action((int)$user['id'], 'login_code', 'Logowanie jednorazowym kodem dostępu');
            authlog_write((int)$user['id'], 'login_code', $user['email'] ?? '', 'Logowanie kodem jednorazowym');
            login_user($user);
            if (($user['role'] ?? '') === 'crm_user' || ($user['role'] ?? '') === 'ezd_user') {
                header('Location: ' . APP_URL . '/portal.php'); exit;
            }
            header('Location: ' . $redirect); exit;
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

// ── Widok: najpierw wybór grupy, potem dopasowany formularz ──────────────
//   choose → ekran wyboru rodzaju konta
//   priv   → wolontariusze i zleceniobiorcy (prywatny e-mail)
//   feer   → administracja i koordynatorzy (konto @feer.org.pl)
$view = $_GET['view'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($active_tab === 'local')                          $view = $_POST['_view'] ?? 'priv';
    elseif (in_array($active_tab, ['code','sms'], true))  $view = 'priv';
    elseif ($active_tab === 'x509')                       $view = 'feer';
}
if (!in_array($view, ['priv','feer'], true)) $view = 'choose';

// Adresy nawigacji między widokami (zachowują parametr redirect)
$_q          = $raw_redirect ? ('&redirect=' . urlencode($raw_redirect)) : '';
$_url_choose = APP_URL . '/auth/login.php' . ($raw_redirect ? ('?redirect=' . urlencode($raw_redirect)) : '');
$_url_priv   = APP_URL . '/auth/login.php?view=priv' . $_q;
$_url_feer   = APP_URL . '/auth/login.php?view=feer' . $_q;
// Dydaktyk loguje się we własnym panelu (osobna sesja — działa też na subdomenie ti.*)
$_url_dyd    = APP_URL . '/karty30/ti/dydaktyk/login.php';
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
html,body{height:100%;margin:0;padding:0}
body{
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  background:
    radial-gradient(900px 480px at 15% -10%, rgba(96,165,250,.18), transparent 60%),
    radial-gradient(1000px 560px at 100% 110%, rgba(99,102,241,.16), transparent 55%),
    linear-gradient(155deg,#0b1220 0%,#111c30 55%,#0f1e34 100%);
  background-attachment:fixed;
}

/* ── Skip link ───────────────────────────────────────────── */
.skip-link{
  position:absolute;top:-100%;left:1rem;z-index:9999;
  background:var(--c,#2563eb);color:#fff;
  padding:.5rem 1.25rem;border-radius:0 0 8px 8px;
  font-weight:700;text-decoration:none;font-size:.95rem;
}
.skip-link:focus{top:0;outline:3px solid #FBBF24;outline-offset:2px}

/* ── Global focus ────────────────────────────────────────── */
*:focus-visible{outline:3px solid #FBBF24!important;outline-offset:3px!important}
*:focus:not(:focus-visible){outline:none}

/* ── Powłoka — wyśrodkowana karta ────────────────────────── */
.login-shell{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2.5rem 1rem}
.login-wrap{width:100%;max-width:440px}

/* ── Karta ───────────────────────────────────────────────── */
.login-card{
  position:relative;overflow:hidden;
  background:#fff;border-radius:20px;border:1px solid rgba(255,255,255,.6);
  box-shadow:0 24px 70px rgba(2,6,23,.45),0 2px 8px rgba(2,6,23,.18);
  padding:2.4rem 2.25rem 2rem;
  animation:loginIn .4s cubic-bezier(.16,.84,.44,1) both;
}
.login-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:4px;
  background:linear-gradient(90deg,var(--c,#2563eb),var(--c-dark,#1d4ed8));
}
@keyframes loginIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}

/* ── Branding (góra karty) ───────────────────────────────── */
.brand{text-align:center;margin-bottom:1.6rem}
.brand-logo{max-height:48px;max-width:210px;object-fit:contain;display:inline-block;margin-bottom:.7rem}
.brand-icon{
  width:58px;height:58px;border-radius:17px;margin:0 auto .8rem;
  background:linear-gradient(135deg,var(--c,#2563eb),var(--c-dark,#1d4ed8));color:var(--c-text,#fff);
  box-shadow:0 8px 20px rgba(37,99,235,.28);
  display:flex;align-items:center;justify-content:center;font-size:1.75rem;
}
.brand-org{font-size:1.15rem;font-weight:800;color:#0f172a;margin:0;line-height:1.3;letter-spacing:-.01em}
.brand-tagline{font-size:.82rem;color:#64748b;margin:.3rem 0 0;line-height:1.5}

/* ── Nagłówek widoku ─────────────────────────────────────── */
.view-head{margin-bottom:1.4rem}
.view-head.center{text-align:center}
.view-title{font-size:1.3rem;font-weight:800;color:#0f172a;margin:0;letter-spacing:-.01em;line-height:1.25}
.view-sub{font-size:.9rem;color:#64748b;margin:.4rem 0 0;line-height:1.55}
.view-sub strong{color:#334155;font-weight:700}
.back-link{
  display:inline-flex;align-items:center;gap:.4rem;margin-bottom:.95rem;
  font-size:.82rem;font-weight:600;color:#64748b;text-decoration:none;
  padding:.32rem .7rem;border:1px solid #e2e8f0;border-radius:2rem;
  transition:color .12s,border-color .12s,background .12s;
}
.back-link:hover{color:var(--c,#2563eb);border-color:#cbd5e1;background:#f8fafc}

/* ── Wybór grupy ─────────────────────────────────────────── */
.chooser{display:flex;flex-direction:column;gap:.7rem}
.choice{
  display:flex;align-items:center;gap:1rem;
  padding:1.05rem 1.1rem;border:1.5px solid #e2e8f0;border-radius:14px;
  text-decoration:none;background:#fff;cursor:pointer;width:100%;text-align:left;
  box-shadow:0 1px 2px rgba(2,6,23,.04);
  transition:border-color .14s,box-shadow .14s,transform .12s,background .14s;
}
.choice:hover{border-color:var(--c,#2563eb);background:#f8fafc;box-shadow:0 8px 22px rgba(37,99,235,.14);transform:translateY(-2px)}
.choice:active{transform:translateY(0)}
.choice-icon{
  width:50px;height:50px;border-radius:13px;flex-shrink:0;
  background:var(--c-bg,#eff6ff);color:var(--c,#2563eb);
  display:flex;align-items:center;justify-content:center;font-size:1.5rem;
}
.choice-icon.alt{background:#f1f5f9;color:#475569}
.choice-body{flex:1;min-width:0}
.choice-title{display:block;font-size:1rem;font-weight:700;color:#0f172a;line-height:1.3}
.choice-sub{display:block;font-size:.81rem;color:#64748b;margin-top:.12rem;line-height:1.4}
.choice-arrow{color:#cbd5e1;font-size:1rem;flex-shrink:0}
.choice:hover .choice-arrow{color:var(--c,#2563eb)}

/* ── Link krzyżowy (wolontariusz → konto @feer.org.pl) ───── */
.cross-link{
  display:flex;align-items:center;gap:.65rem;margin-top:1.1rem;
  padding:.7rem .85rem;border:1px solid #e2e8f0;border-radius:10px;
  background:#f8fafc;text-decoration:none;
  transition:border-color .12s,background .12s;
}
.cross-link:hover{border-color:var(--c,#2563eb);background:#fff}
.cross-link > i:first-child{color:var(--c,#2563eb);font-size:1.05rem;flex-shrink:0}
.cross-link-body{flex:1;min-width:0}
.cross-link-title{display:block;font-size:.83rem;font-weight:600;color:#334155;line-height:1.3}
.cross-link-sub{display:block;font-size:.75rem;color:#64748b;margin-top:.05rem}
.cross-link .arr{color:#cbd5e1;flex-shrink:0;font-size:.8rem}

/* ── Stopka pod kartą ────────────────────────────────────── */
.login-foot{margin-top:1.3rem;text-align:center}
.login-foot .sec{display:inline-flex;align-items:center;gap:.35rem;color:rgba(255,255,255,.5);font-size:.77rem}
.login-foot .links{margin-top:.55rem}
.login-foot a{color:rgba(255,255,255,.78);text-decoration:none;font-size:.8rem;font-weight:500}
.login-foot a:hover{color:#fff;text-decoration:underline}
.login-foot .dot{color:rgba(255,255,255,.3);margin:0 .5rem}
.login-foot .cpy{display:block;margin-top:.55rem;color:rgba(255,255,255,.38);font-size:.72rem}

/* ── Komunikaty ──────────────────────────────────────────── */
.login-notice{
  display:flex;align-items:flex-start;gap:.6rem;
  padding:.75rem .9rem;border-radius:8px;background:#eff6ff;
  border-left:3px solid #2563eb;margin-bottom:.65rem;font-size:.85rem;color:#1e293b;line-height:1.5;
}
.login-notice.pinned{background:#fffbeb;border-left-color:#f59e0b}
.login-notice i{flex-shrink:0;color:#2563eb;margin-top:.15rem}
.login-notice.pinned i{color:#d97706}
.login-notice-title{font-weight:600}

.login-alert{
  display:flex;align-items:flex-start;gap:.7rem;
  padding:.9rem 1rem;border-radius:8px;border:2px solid;
  margin-bottom:1.25rem;font-size:.9rem;line-height:1.5;
}
.login-alert i{font-size:1.1rem;flex-shrink:0;margin-top:.05rem}
.login-alert-danger {background:#fef2f2;border-color:#dc2626;color:#7f1d1d}
.login-alert-success{background:#f0fdf4;border-color:#16a34a;color:#14532d}

/* ── Pola formularza ─────────────────────────────────────── */
.form-label{font-size:.9rem;font-weight:600;color:#1e293b;margin-bottom:.38rem;display:block}
.form-control{
  border:1.5px solid #94a3b8;border-radius:10px;
  font-size:1rem;padding:.7rem .95rem;min-height:48px;
  color:#0f172a;width:100%;background:#fff;
  transition:border-color .15s,box-shadow .15s;
}
.form-control:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.15);outline:none}
.form-control[aria-invalid="true"]{border-color:#dc2626;background:#fff8f8}
.form-control[aria-invalid="true"]:focus{box-shadow:0 0 0 3px rgba(220,38,38,.15)}
.form-hint{font-size:.8rem;color:#64748b;margin-top:.3rem;line-height:1.45}
.form-error{font-size:.8rem;color:#b91c1c;margin-top:.3rem;font-weight:500;display:flex;align-items:center;gap:.3rem}

/* ── Hasło — przycisk reveal ─────────────────────────────── */
.pass-wrap{position:relative}
.pass-toggle{
  position:absolute;right:.65rem;top:50%;transform:translateY(-50%);
  background:none;border:2px solid transparent;padding:0;
  color:#64748b;cursor:pointer;font-size:1.05rem;border-radius:6px;
  width:36px;height:36px;display:flex;align-items:center;justify-content:center;
  transition:color .12s,border-color .12s;
}
.pass-toggle:hover{color:var(--c,#2563eb);border-color:#e2e8f0}

/* ── Przycisk główny ─────────────────────────────────────── */
.btn-login{
  display:flex;align-items:center;justify-content:center;gap:.55rem;
  background:linear-gradient(135deg,var(--c,#2563eb),var(--c-dark,#1d4ed8));color:var(--c-text,#fff);
  border:2px solid transparent;border-radius:10px;
  padding:.85rem 1.25rem;font-size:1rem;font-weight:700;
  width:100%;min-height:52px;cursor:pointer;
  box-shadow:0 8px 20px rgba(37,99,235,.28);
  transition:filter .15s,box-shadow .15s,transform .12s;
  text-decoration:none;
}
.btn-login:hover{filter:brightness(1.06);box-shadow:0 10px 26px rgba(37,99,235,.36);transform:translateY(-1px);color:var(--c-text,#fff)}
.btn-login:active{transform:translateY(0)}

/* ── Microsoft 365 ───────────────────────────────────────── */
.btn-ms365{
  display:flex;align-items:center;justify-content:center;gap:.75rem;
  background:#fff;color:#1e293b;
  border:2px solid #d1d5db;border-radius:8px;
  padding:.85rem 1.25rem;font-size:1rem;font-weight:700;
  width:100%;min-height:52px;cursor:pointer;
  transition:border-color .15s,box-shadow .15s;
  text-decoration:none;
}
.btn-ms365:hover{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);color:#1e293b}
.ms-note{font-size:.8rem;color:#64748b;text-align:center;margin:.6rem 0 0;line-height:1.4}

/* ── Separator ───────────────────────────────────────────── */
.or-div{display:flex;align-items:center;gap:.75rem;color:#94a3b8;font-size:.8rem;margin:1.3rem 0}
.or-div::before,.or-div::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* ── Alternatywne metody — przyciski do modali ───────────── */
.method-triggers{display:flex;flex-direction:column;gap:.4rem}
.method-trigger-btn{
  display:flex;align-items:center;gap:.75rem;
  width:100%;padding:.65rem .85rem;
  background:#fff;border:2px solid #e2e8f0;border-radius:10px;
  cursor:pointer;text-align:left;
  transition:border-color .12s,box-shadow .12s;
}
.method-trigger-btn:hover{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.method-trigger-icon{
  width:36px;height:36px;border-radius:8px;flex-shrink:0;
  background:var(--c-bg,#eff6ff);
  display:flex;align-items:center;justify-content:center;
  font-size:1rem;color:var(--c,#2563eb);
}
.method-trigger-body{flex:1;min-width:0}
.method-trigger-label{display:block;font-size:.88rem;font-weight:600;color:#0f172a;line-height:1.3}
.method-trigger-sub{display:block;font-size:.75rem;color:#64748b;margin-top:.06rem}
.method-trigger-arrow{color:#cbd5e1;font-size:.8rem;flex-shrink:0}

/* ── Modal logowania ─────────────────────────────────────── */
.login-modal-content{border:none;border-radius:14px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.2)}
.login-modal-header{
  background:linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%);
  border-bottom:1px solid #e2e8f0;padding:1rem 1.25rem;
  display:flex;align-items:center;justify-content:space-between;
}
.login-modal-title-wrap{display:flex;align-items:center;gap:.55rem}
.login-modal-title-wrap > i{font-size:1.1rem;color:var(--c,#2563eb)}
.login-modal-title{font-size:1.05rem;font-weight:700;color:#0f172a;margin:0}
.login-modal-body{padding:1.25rem 1.5rem 1.5rem}
.login-modal-desc{font-size:.84rem;color:#64748b;margin:0 0 1rem;line-height:1.5}

/* ── SMS kode input ──────────────────────────────────────── */
.sms-otp{
  font-size:2rem;letter-spacing:.45rem;text-align:center;
  font-family:monospace;font-weight:700;
}

/* ── Tekst powitalny (konfigurowalny przez administratora) ── */
.login-welcome-text{font-size:.84rem;color:#64748b;line-height:1.6;margin:0 0 1.2rem;text-align:center}

/* ── Podpis pod blokiem metody (dla kogo) ─────────────────── */
.method-for{font-size:.78rem;color:#64748b;text-align:center;margin:.45rem 0 0;line-height:1.45}

/* ── Więcej opcji — rozwijane ─────────────────────────────── */
.more-options{margin-top:1.3rem;border-top:1px solid #e2e8f0;padding-top:1rem}
.more-options > summary{
  list-style:none;cursor:pointer;user-select:none;
  display:flex;align-items:center;justify-content:center;gap:.4rem;
  font-size:.83rem;font-weight:600;color:#64748b;
  padding:.45rem;border-radius:8px;transition:color .12s,background .12s;
}
.more-options > summary::-webkit-details-marker{display:none}
.more-options > summary:hover{color:var(--c,#2563eb);background:#f8fafc}
.more-options > summary .chev{transition:transform .15s}
.more-options[open] > summary .chev{transform:rotate(180deg)}
.more-options-body{margin-top:.7rem}

/* ── Zapomniałem hasła ───────────────────────────────────── */
.forgot-link{
  display:inline-flex;align-items:center;gap:.35rem;
  font-size:.83rem;color:#64748b;text-decoration:none;
  padding:.3rem;border-radius:4px;
  transition:color .12s;
}
.forgot-link:hover{color:var(--c,#2563eb)}

/* ── High contrast ───────────────────────────────────────── */
@media(prefers-contrast:high){
  .form-control{border-width:3px;border-color:#000}
  .btn-login,.btn-ms365{border-width:3px}
  .btn-login{background:#000!important;border-color:#000!important;color:#fff!important}
  .login-alert-danger{border-width:3px}
  .method-trigger-btn{border-width:3px}
}
/* ── Reduced motion ──────────────────────────────────────── */
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}

/* ── Mobile ──────────────────────────────────────────────── */
@media(max-width:520px){
  .login-shell{padding:1.25rem .75rem;align-items:flex-start}
  .login-card{padding:1.6rem 1.35rem 1.4rem;border-radius:14px}
  .brand-org{font-size:1.05rem}
  .choice{padding:.95rem .9rem;gap:.8rem}
  .choice-icon{width:44px;height:44px;font-size:1.3rem}
}
</style>
</head>
<body>

<a href="#login-form-area" class="skip-link">Przejdź do formularza logowania</a>

<!-- Regiony ARIA live — ogłaszają zmiany dla czytników ekranu -->
<div role="status" aria-live="polite" aria-atomic="true"
     id="login-live"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)"></div>
<div role="alert" aria-live="assertive" aria-atomic="true"
     id="login-alert"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)"></div>

<div class="login-shell">
<div class="login-wrap">

<main class="login-card" id="login-form-area" tabindex="-1">

  <!-- ══ Branding ══════════════════════════════════════════════════════════ -->
  <div class="brand">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" class="brand-logo">
    <?php else: ?>
    <div class="brand-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <p class="brand-org"><?= h($org_name) ?></p>
    <?php if ($_login_tagline): ?><p class="brand-tagline"><?= h($_login_tagline) ?></p><?php endif; ?>
  </div>

  <?php
  // Komunikat administratora — pokazywany na każdym widoku (preferuj przypięty)
  $_ln_show = null;
  foreach ($_login_notices as $_ln_item) {
    if ($_ln_item['is_pinned'] ?? 0) { $_ln_show = $_ln_item; break; }
  }
  if (!$_ln_show && !empty($_login_notices)) $_ln_show = $_login_notices[0];
  ?>
  <?php if ($_ln_show): $ln_pinned = (int)($_ln_show['is_pinned'] ?? 0); ?>
  <div role="region" aria-label="Komunikat administratora" style="margin-bottom:1.25rem">
    <div class="login-notice <?= $ln_pinned ? 'pinned' : '' ?>">
      <i class="bi bi-<?= $ln_pinned ? 'pin-angle-fill' : 'megaphone-fill' ?>" aria-hidden="true"></i>
      <div>
        <div class="login-notice-title"><?= h($_ln_show['title']) ?></div>
        <?php if ($_ln_show['body']): ?><div style="margin-top:.2rem;font-size:.84rem"><?= nl2br(h($_ln_show['body'])) ?></div><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($_GET['ended'])): ?>
  <div class="login-alert login-alert-success" role="status" style="border-color:#0ea5e9;background:#f0f9ff;color:#075985">
    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
    <span>Twoja sesja została zakończona. Zaloguj się ponownie.</span>
  </div>
  <?php endif; ?>

  <?php if ($view === 'choose'): ?>
  <!-- ══ Widok: wybór rodzaju konta ════════════════════════════════════════ -->
  <div class="view-head center">
    <h1 class="view-title" id="login-title">Zaloguj się</h1>
    <p class="view-sub">Wybierz, kim jesteś — pokażemy właściwy sposób logowania.</p>
  </div>

  <?php if ($_login_welcome_is_custom): ?>
  <p class="login-welcome-text"><?= nl2br(h($_login_welcome)) ?></p>
  <?php endif; ?>

  <div class="chooser" role="group" aria-label="Wybierz rodzaj konta">
    <a href="<?= h($_url_priv) ?>" class="choice">
      <span class="choice-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
      <span class="choice-body">
        <span class="choice-title">Wolontariusz / zleceniobiorca</span>
        <span class="choice-sub">Logowanie prywatnym e-mailem i hasłem</span>
      </span>
      <i class="bi bi-chevron-right choice-arrow" aria-hidden="true"></i>
    </a>
    <a href="<?= h($_url_feer) ?>" class="choice">
      <span class="choice-icon alt" aria-hidden="true"><i class="bi bi-building-fill"></i></span>
      <span class="choice-body">
        <span class="choice-title">Administracja / koordynator</span>
        <span class="choice-sub">Logowanie kontem służbowym @feer.org.pl</span>
      </span>
      <i class="bi bi-chevron-right choice-arrow" aria-hidden="true"></i>
    </a>
    <a href="<?= h($_url_dyd) ?>" class="choice">
      <span class="choice-icon" aria-hidden="true"><i class="bi bi-easel2"></i></span>
      <span class="choice-body">
        <span class="choice-title">Dydaktyk / prowadzący zajęcia TI</span>
        <span class="choice-sub">Logowanie e-mailem do panelu dydaktyka</span>
      </span>
      <i class="bi bi-chevron-right choice-arrow" aria-hidden="true"></i>
    </a>
  </div>

  <?php else: ?>
  <!-- ══ Widok: formularz wybranej grupy ═══════════════════════════════════ -->
  <a href="<?= h($_url_choose) ?>" class="back-link">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Zmień rodzaj konta
  </a>
  <div class="view-head">
    <h1 class="view-title" id="login-title">
      <?= $view === 'feer' ? 'Administracja i koordynatorzy' : 'Wolontariusze i zleceniobiorcy' ?>
    </h1>
    <p class="view-sub">
      <?php if ($view === 'feer'): ?>
      Zaloguj się <strong>wyłącznie</strong> kontem służbowym <strong>@feer.org.pl</strong>.
      <?php else: ?>
      Zaloguj się swoim <strong>prywatnym e-mailem</strong> podanym do WiadomościFEER.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($error && $active_tab === 'local'): ?>
  <div class="login-alert login-alert-danger" role="alert" id="login-error-box">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <span id="login-error-text"><?= h($error) ?></span>
  </div>
  <?php endif; ?>
  <?php if ($info && $active_tab !== 'sms'): ?>
  <div class="login-alert login-alert-success" role="status">
    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
    <span><?= h($info) ?></span>
  </div>
  <?php endif; ?>

  <?php include __DIR__ . '/_login_form_body.php'; ?>
  <?php endif; /* /view */ ?>

</main>

<!-- ══ Stopka pod kartą ════════════════════════════════════════════════════ -->
<div class="login-foot">
  <span class="sec"><i class="bi bi-lock-fill" aria-hidden="true"></i> Połączenie szyfrowane HTTPS</span>
  <div class="links">
    <a href="<?= APP_URL ?>/auth/help.php"
       aria-label="Otwórz instrukcję: jak się zalogować i jak ustalić login i hasło">Jak się zalogować?</a>
    <?php if ($sel_url): ?>
    <span class="dot" aria-hidden="true">·</span>
    <a href="<?= h($sel_url) ?>"><?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?></a>
    <?php endif; ?>
  </div>
  <span class="cpy">&copy; <?= date('Y') ?> · <?= h($org_name) ?></span>
</div>

</div><!-- /login-wrap -->
</div><!-- /login-shell -->

<!-- ══ Modale metod logowania (poza shell — prawidłowy stacking context) ════ -->

<?php if ($code_available): ?>
<div class="modal fade" id="modal-code"
     tabindex="-1"
     aria-hidden="true"
     aria-labelledby="modal-code-title">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content login-modal-content">
      <div class="login-modal-header">
        <div class="login-modal-title-wrap">
          <i class="bi bi-key-fill" aria-hidden="true"></i>
          <h2 class="login-modal-title" id="modal-code-title">Kod jednorazowy</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="Zamknij okno logowania kodem jednorazowym"></button>
      </div>
      <div class="login-modal-body">
        <?php if ($error && $active_tab === 'code'): ?>
        <div class="login-alert login-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <p class="login-modal-desc">
          Kod wysłany e-mailem lub podany przez administratora —
          ważny wyłącznie do pierwszego użycia.
        </p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="modal-code-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="code">
          <div style="margin-bottom:1.25rem">
            <label class="form-label" for="f-code">Kod dostępu</label>
            <input type="text"
                   name="login_code"
                   id="f-code"
                   class="form-control"
                   style="font-family:monospace;letter-spacing:.12em;text-align:center;font-size:1.05rem"
                   placeholder="XXXXXXXXXX"
                   spellcheck="false"
                   autocomplete="one-time-code"
                   required
                   aria-required="true"
                   <?php if ($error && $active_tab === 'code'): ?>
                   aria-invalid="true"
                   aria-errormessage="login-error-box"
                   <?php endif; ?>>
          </div>
          <button type="submit" class="btn-login">
            Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($sms_available): ?>
<div class="modal fade" id="modal-sms"
     tabindex="-1"
     aria-hidden="true"
     aria-labelledby="modal-sms-title">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content login-modal-content">
      <div class="login-modal-header">
        <div class="login-modal-title-wrap">
          <i class="bi bi-phone-fill" aria-hidden="true"></i>
          <h2 class="login-modal-title" id="modal-sms-title">
            Kod SMS
            <?php if ($sms_step === 2): ?>
            <span style="font-size:.8rem;font-weight:500;color:#64748b;margin-left:.35rem">— krok 2: wpisz kod</span>
            <?php endif; ?>
          </h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="Zamknij okno logowania SMS"></button>
      </div>
      <div class="login-modal-body">
        <?php if ($error && $active_tab === 'sms'): ?>
        <div class="login-alert login-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($info && $active_tab === 'sms'): ?>
        <div class="login-alert login-alert-success" role="status">
          <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
          <span><?= h($info) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($sms_step === 1): ?>
        <p class="login-modal-desc">Wpisz numer telefonu powiązany z Twoim kontem.</p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="modal-sms-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="sms_send">
          <div style="margin-bottom:1.25rem">
            <label class="form-label" for="f-sms-phone">Numer telefonu</label>
            <div style="display:flex;gap:0">
              <span style="display:inline-flex;align-items:center;padding:.65rem .8rem;background:#f8fafc;border:2px solid #94a3b8;border-right:none;border-radius:8px 0 0 8px;font-weight:700;color:#374151;font-size:1rem;white-space:nowrap"
                    aria-hidden="true">+48</span>
              <input type="tel"
                     name="sms_phone"
                     id="f-sms-phone"
                     class="form-control"
                     style="border-radius:0 8px 8px 0"
                     placeholder="123 456 789"
                     value="<?= h($sms_phone) ?>"
                     inputmode="numeric"
                     pattern="[0-9 ]{9,11}"
                     autocomplete="tel-national"
                     required
                     aria-required="true"
                     aria-label="Numer telefonu bez prefiksu +48"
                     aria-describedby="f-sms-hint"
                     <?php if ($error && $active_tab === 'sms'): ?>
                     aria-invalid="true"
                     aria-errormessage="login-error-box"
                     <?php endif; ?>>
            </div>
            <div id="f-sms-hint" class="form-hint">
              Numer wpisany w umowie wolontariackiej lub udostępniony administratorowi.
            </div>
          </div>
          <button type="submit" class="btn-login">
            <i class="bi bi-send" aria-hidden="true"></i> Wyślij kod SMS
          </button>
        </form>
        <?php else: ?>
        <p style="font-size:.88rem;color:#374151;margin-bottom:.9rem;line-height:1.5">
          Kod wysłany na numer <strong><?= h($sms_phone) ?></strong>.<br>Ważny przez <strong>5 minut</strong>.
        </p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="modal-sms-title">
          <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
          <input type="hidden" name="_method"   value="sms_verify">
          <input type="hidden" name="sms_phone" value="<?= h($sms_phone) ?>">
          <div style="margin-bottom:1.25rem">
            <label class="form-label" for="f-sms-code">6-cyfrowy kod SMS</label>
            <input type="text"
                   name="sms_code"
                   id="f-sms-code"
                   class="form-control sms-otp"
                   inputmode="numeric"
                   pattern="[0-9]{6}"
                   maxlength="6"
                   placeholder="000000"
                   autocomplete="one-time-code"
                   required
                   aria-required="true"
                   <?php if ($error && $active_tab === 'sms'): ?>
                   aria-invalid="true"
                   aria-errormessage="login-error-box"
                   <?php endif; ?>>
            <div class="form-hint" style="margin-top:.3rem">Sprawdź wiadomości SMS — wpisz 6 cyfr.</div>
          </div>
          <button type="submit" class="btn-login" style="margin-bottom:.65rem">
            Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </button>
          <button type="button"
                  style="background:none;border:none;color:#64748b;font-size:.83rem;cursor:pointer;padding:.4rem;width:100%;text-align:center;border-radius:6px"
                  aria-label="Wróć — zmień numer telefonu"
                  onclick="document.querySelector('[name=_method]').value='sms_send';this.closest('form').submit()">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Zmień numer telefonu
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($x509_available): ?>
<div class="modal fade" id="modal-x509"
     tabindex="-1"
     aria-hidden="true"
     aria-labelledby="modal-x509-title">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content login-modal-content">
      <div class="login-modal-header">
        <div class="login-modal-title-wrap">
          <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
          <h2 class="login-modal-title" id="modal-x509-title">Certyfikat X.509</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="Zamknij okno logowania certyfikatem"></button>
      </div>
      <div class="login-modal-body">
        <?php if ($error && $active_tab === 'x509'): ?>
        <div class="login-alert login-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <p class="login-modal-desc">
          Plik PKCS#12 (.p12 lub .pfx) wygenerowany przez administratora systemu.
        </p>
        <form method="post" enctype="multipart/form-data" novalidate aria-labelledby="modal-x509-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="x509">
          <div style="margin-bottom:1rem">
            <label class="form-label" for="f-p12">Plik certyfikatu (.p12 lub .pfx)</label>
            <input type="file"
                   name="p12_file"
                   id="f-p12"
                   class="form-control"
                   accept=".p12,.pfx"
                   required
                   aria-required="true"
                   aria-describedby="f-p12-hint"
                   <?php if ($error && $active_tab === 'x509'): ?>
                   aria-invalid="true"
                   aria-errormessage="login-error-box"
                   <?php endif; ?>>
            <div id="f-p12-hint" class="form-hint">Plik PKCS#12 wygenerowany przez administratora systemu.</div>
          </div>
          <div style="margin-bottom:1.25rem">
            <label class="form-label" for="f-cert-pass">Hasło certyfikatu</label>
            <div class="pass-wrap">
              <input type="password"
                     name="cert_password"
                     id="f-cert-pass"
                     class="form-control"
                     autocomplete="current-password"
                     required
                     aria-required="true"
                     aria-describedby="f-cert-pass-hint"
                     <?php if ($error && $active_tab === 'x509'): ?>
                     aria-invalid="true"
                     aria-errormessage="login-error-box"
                     <?php endif; ?>>
              <button type="button"
                      class="pass-toggle"
                      aria-label="Pokaż hasło certyfikatu"
                      aria-pressed="false"
                      onclick="togglePass('f-cert-pass', this)">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
            <div id="f-cert-pass-hint" class="form-hint">Hasło podane przez administratora przy generowaniu pliku .p12.</div>
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

// ── Reveal hasła ──────────────────────────────────────────────────────────
function togglePass(inputId, btn) {
  var inp = document.getElementById(inputId);
  if (!inp) return;
  var nowHidden = inp.type !== 'password';
  inp.type      = nowHidden ? 'password' : 'text';
  btn.setAttribute('aria-pressed', nowHidden ? 'false' : 'true');
  btn.setAttribute('aria-label',   nowHidden ? 'Pokaż hasło' : 'Ukryj hasło');
  btn.querySelector('i').className = nowHidden ? 'bi bi-eye' : 'bi bi-eye-slash';
}
window.togglePass = togglePass;

})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
// ── Ogłoszenie błędu przez ARIA live region ───────────────────────────────
var errText = document.getElementById('login-error-text');
var liveErr = document.getElementById('login-alert');
if (errText && liveErr) liveErr.textContent = errText.textContent.trim();

// ── Fokus na pierwszy input przy otwieraniu modala ────────────────────────
document.querySelectorAll('.modal').forEach(function(m) {
  m.addEventListener('shown.bs.modal', function() {
    var first = m.querySelector('input:not([type=hidden]),textarea');
    if (first) first.focus();
  });
});

// ── Auto-otwarcie modala gdy POST zwrócił błąd metody alternatywnej ───────
var autoOpen = <?= json_encode(in_array($active_tab, ['code','sms','x509'], true) ? $active_tab : null) ?>;
if (autoOpen) {
  var el = document.getElementById('modal-' + autoOpen);
  if (el) bootstrap.Modal.getOrCreateInstance(el).show();
}
})();
</script>
</body>
</html>
