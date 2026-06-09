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
try { $x509_available = x509_any_active(); } catch (\Throwable $e) {}

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
            if ($user && $user['password'] && password_verify($pass, $user['password'])) {
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
                            sms_send($user['twofa_phone'], "Kod 2FA: {$otp} (ważny 5 min)");
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
                if (($user['role'] ?? '') === 'crm_user') {
                    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
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
                sms_send($sms_phone, "Kod logowania {$org}: {$otp} (ważny 5 min)");
                $sms_step = 2;
                $info     = 'Kod SMS wysłany — sprawdź telefon.';
            } catch (\Throwable $e) {
                $error = 'Błąd wysyłki SMS: ' . $e->getMessage();
            }
        }
    }

    elseif ($method === 'sms_verify' && $sms_available) {
        $sms_phone  = trim($_POST['sms_phone'] ?? '');
        $sms_code   = trim($_POST['sms_code']  ?? '');
        $active_tab = 'sms';
        $user       = sms_verify_otp($sms_phone, $sms_code);
        if ($user) {
            log_auth_action((int)$user['id'], 'login_sms', 'Logowanie SMS: ' . $sms_phone);
            authlog_write((int)$user['id'], 'login_sms', $user['email'] ?? $sms_phone, 'Logowanie SMS');
            login_user($user);
            if (($user['role'] ?? '') === 'crm_user') { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
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
            if ($user) {
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
        if ($user) {
            try {
                db()->prepare("UPDATE users SET login_code=NULL WHERE id=?")->execute([$user['id']]);
            } catch (\Throwable $e) {}
            log_auth_action((int)$user['id'], 'login_code', 'Logowanie jednorazowym kodem dostępu');
            authlog_write((int)$user['id'], 'login_code', $user['email'] ?? '', 'Logowanie kodem jednorazowym');
            login_user($user);
            if (($user['role'] ?? '') === 'crm_user') { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
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

// ── Opis systemu (konfigurowalny lub domyślny) ────────────────────────────
$_login_welcome = '';
try { $_login_welcome = trim(org_setting('login_welcome_text') ?: ''); } catch (\Throwable $e) {}
if (!$_login_welcome) {
    $_login_welcome = 'System służy do zarządzania pracą organizacji pozarządowej — rejestrowania umów z wolontariuszami i współpracownikami, prowadzenia kartoteki kontaktów i spraw w CRM, a także obsługi dokumentów, zasobów i działań. Dostępne funkcje zależą od roli, jaką pełnisz w organizacji.';
}

// ── Lista dostępnych metod do pokazania w lewym panelu ────────────────────
$_avail_methods = [];
if ($ms_available)   $_avail_methods[] = ['bi-microsoft',       'Microsoft 365',   'Konto organizacji — jedno kliknięcie'];
$_avail_methods[]                      = ['bi-envelope-at-fill','E-mail i hasło',   'Konto lokalne w systemie'];
if ($sms_available)  $_avail_methods[] = ['bi-phone-fill',      'Kod SMS',          'Logowanie przez numer telefonu'];
if ($code_available) $_avail_methods[] = ['bi-key-fill',        'Kod jednorazowy',  'Pierwsze logowanie lub gość'];
if ($x509_available) $_avail_methods[] = ['bi-patch-check-fill','Certyfikat X.509', 'Plik .p12 dla administratora'];
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
html,body{height:100%;margin:0;padding:0;background:#0f172a}

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

/* ── Shell ───────────────────────────────────────────────── */
.login-shell{min-height:100vh;display:flex;align-items:stretch}

/* ── Lewa (dark) ─────────────────────────────────────────── */
.login-left{
  width:300px;flex-shrink:0;
  background:linear-gradient(160deg,#0f172a 0%,#1e293b 55%,#1e3a5f 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:2.25rem 1.75rem;
  border-right:1px solid rgba(255,255,255,.06);
  position:relative;overflow:hidden;
}
.login-left::after{
  content:'';position:absolute;width:260px;height:260px;border-radius:50%;
  border:55px solid rgba(255,255,255,.025);bottom:-80px;right:-80px;pointer-events:none;
}

.left-logo{max-height:44px;max-width:140px;object-fit:contain;filter:brightness(0)invert(1);opacity:.85;display:block;margin-bottom:1rem}
.left-icon{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;margin-bottom:1rem}
.left-org{font-size:1.05rem;font-weight:800;color:#fff;margin:0 0 .25rem;line-height:1.3}
.left-tagline{font-size:.77rem;color:rgba(255,255,255,.45);margin:0 0 1.75rem;line-height:1.5}

.left-methods-label{font-size:.63rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.28);margin-bottom:.65rem}
.left-method{display:flex;align-items:flex-start;gap:.55rem;margin-bottom:.6rem}
.left-method-icon{font-size:.9rem;color:rgba(255,255,255,.45);margin-top:.12rem;flex-shrink:0}
.left-method-name{font-size:.81rem;font-weight:600;color:rgba(255,255,255,.7);line-height:1.3}
.left-method-sub{font-size:.69rem;color:rgba(255,255,255,.32);margin-top:.06rem}

.left-footer{position:relative;z-index:1}
.left-change-org{
  display:inline-flex;align-items:center;gap:.35rem;margin-bottom:.75rem;
  font-size:.75rem;color:rgba(255,255,255,.4);text-decoration:none;
  padding:.3rem .7rem;border:1px solid rgba(255,255,255,.15);border-radius:2rem;
  transition:color .15s,border-color .15s;
}
.left-change-org:hover{color:rgba(255,255,255,.8);border-color:rgba(255,255,255,.4)}
.left-security{display:flex;align-items:center;gap:.4rem;font-size:.73rem;color:rgba(255,255,255,.3);margin-bottom:.4rem}
.left-copyright{font-size:.68rem;color:rgba(255,255,255,.2)}

/* ── Prawa (light) ───────────────────────────────────────── */
.login-right{
  flex:1;background:#F1F5F9;
  display:flex;align-items:center;justify-content:center;
  padding:2.5rem 1.5rem;overflow-y:auto;
}
.login-box{
  width:100%;max-width:440px;
  background:#fff;border-radius:16px;
  box-shadow:0 8px 40px rgba(0,0,0,.13),0 2px 8px rgba(0,0,0,.06);
  padding:2rem 2.25rem;
}

/* Mobile-only org name above the form */
.mobile-top{
  display:none;align-items:center;gap:.6rem;
  padding-bottom:1.25rem;margin-bottom:1.5rem;
  border-bottom:1px solid #e2e8f0;
}
.mobile-top-logo{max-height:26px;object-fit:contain;filter:none}
.mobile-top-org{font-size:.9rem;font-weight:700;color:#0f172a}

/* ── Nagłówek formularza ─────────────────────────────────── */
.login-heading{font-size:1.45rem;font-weight:800;color:#0f172a;margin:0 0 1.4rem;letter-spacing:-.01em}

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
  border:2px solid #94a3b8;border-radius:8px;
  font-size:1rem;padding:.65rem .9rem;min-height:48px;
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
  background:var(--c,#2563eb);color:var(--c-text,#fff);
  border:2px solid var(--c,#2563eb);border-radius:8px;
  padding:.8rem 1.25rem;font-size:1rem;font-weight:700;
  width:100%;min-height:52px;cursor:pointer;
  transition:background .15s,border-color .15s;
  text-decoration:none;
}
.btn-login:hover{background:var(--c-dark,#1d4ed8);border-color:var(--c-dark,#1d4ed8);color:var(--c-text,#fff)}

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

/* ── Alternatywne metody — zakładki ─────────────────────── */
.method-tablist{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.65rem}
.method-tab{
  display:inline-flex;align-items:center;gap:.4rem;
  padding:.45rem .95rem;border:2px solid #cbd5e1;border-radius:2rem;
  background:#fff;color:#475569;font-size:.84rem;font-weight:500;
  cursor:pointer;min-height:40px;transition:all .12s;white-space:nowrap;
  line-height:1;
}
.method-tab[aria-selected="true"]{
  background:var(--c-bg,#eff6ff);border-color:var(--c,#2563eb);
  color:var(--c,#2563eb);font-weight:700;
}
.method-tab:hover:not([aria-selected="true"]){border-color:var(--c,#2563eb);color:var(--c,#2563eb)}

.method-desc{font-size:.79rem;color:#94a3b8;margin-bottom:.75rem;line-height:1.4}

/* ── Panele metod ────────────────────────────────────────── */
.method-panel{
  border:1px solid #e2e8f0;border-radius:10px;padding:1.1rem;
  margin-top:.25rem;outline:none;
  transition:box-shadow .15s;
}
.method-panel:focus{box-shadow:0 0 0 3px rgba(37,99,235,.15)}

/* ── SMS kode input ──────────────────────────────────────── */
.sms-otp{
  font-size:2rem;letter-spacing:.45rem;text-align:center;
  font-family:monospace;font-weight:700;
}

/* ── Moduły systemu (lewa kolumna) ──────────────────────── */
.left-about{margin:.85rem 0 1.35rem}
.left-about-text{font-size:.79rem;color:rgba(255,255,255,.48);line-height:1.65;margin:0 0 .65rem}
.left-modules{display:flex;flex-wrap:wrap;gap:.3rem}
.left-module{
  display:inline-flex;align-items:center;gap:.28rem;
  font-size:.66rem;font-weight:600;
  color:rgba(255,255,255,.42);background:rgba(255,255,255,.07);
  border:1px solid rgba(255,255,255,.11);border-radius:2rem;
  padding:.18rem .5rem;white-space:nowrap;
}
.left-module i{font-size:.7rem}

/* ── Karta zaproszenia (prawa kolumna) ───────────────────── */
.login-invite{
  background:linear-gradient(135deg,#f0f7ff 0%,#eff6ff 100%);
  border:1px solid #bfdbfe;border-radius:10px;
  padding:1rem 1.1rem;margin-bottom:1.4rem;
}
.login-invite-head{
  display:flex;align-items:center;gap:.5rem;
  font-size:.88rem;font-weight:700;color:#1e3a5f;
  margin-bottom:.5rem;
}
.login-invite-head i{color:#2563eb;font-size:1rem}
.login-invite-text{font-size:.84rem;color:#475569;line-height:1.65;margin:0 0 .75rem}
.login-invite-chips{display:flex;flex-wrap:wrap;gap:.3rem}
.login-invite-chip{
  display:inline-flex;align-items:center;gap:.28rem;
  font-size:.7rem;font-weight:600;color:#3b82f6;
  background:#dbeafe;border:1px solid #bfdbfe;border-radius:2rem;
  padding:.2rem .6rem;
}
.login-invite-chip i{font-size:.72rem}

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
  .method-tab{border-width:3px}
}
/* ── Reduced motion ──────────────────────────────────────── */
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important}}

/* ── Mobile ──────────────────────────────────────────────── */
@media(max-width:680px){
  .login-shell{flex-direction:column}
  .login-left{
    width:100%;padding:.9rem 1.25rem;
    flex-direction:row;align-items:center;gap:.75rem;
    border-right:none;border-bottom:1px solid rgba(255,255,255,.07);
  }
  .login-left::after{display:none}
  .login-left .left-methods-label,
  .login-left .left-method,
  .login-left .left-tagline,
  .login-left .left-about,
  .login-left .left-footer{display:none}
  .login-left .left-org{font-size:.9rem;margin:0}
  .login-right{padding:1.25rem 1rem;align-items:flex-start;background:#F1F5F9}
  .login-box{box-shadow:none;border-radius:12px;padding:1.5rem 1.25rem}
  .mobile-top{display:flex}
  .login-left .left-logo{margin-bottom:0;max-height:28px}
  .login-left .left-icon{width:30px;height:30px;font-size:1rem;margin-bottom:0}
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

<!-- ══ Lewa — branding ══════════════════════════════════════════════════════ -->
<aside class="login-left" aria-label="Informacje o organizacji">
  <div>
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" class="left-logo">
    <?php else: ?>
    <div class="left-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <p class="left-org"><?= h($org_name) ?></p>
    <?php if ($_login_tagline): ?><p class="left-tagline"><?= h($_login_tagline) ?></p><?php endif; ?>

    <!-- Czym jest system -->
    <div class="left-about">
      <p class="left-about-text">Zarządzaj umowami, kontaktami, dokumentami i zasobami organizacji NGO — wszystko w jednym miejscu.</p>
      <div class="left-modules" aria-label="Moduły systemu">
        <span class="left-module"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Umowy</span>
        <span class="left-module"><i class="bi bi-diagram-2-fill" aria-hidden="true"></i>CRM</span>
        <span class="left-module"><i class="bi bi-lock-fill" aria-hidden="true"></i>RODO</span>
        <span class="left-module"><i class="bi bi-box-seam" aria-hidden="true"></i>Zasoby</span>
        <span class="left-module"><i class="bi bi-card-checklist" aria-hidden="true"></i>K30</span>
        <span class="left-module"><i class="bi bi-currency-euro" aria-hidden="true"></i>Granty</span>
        <span class="left-module"><i class="bi bi-lightning-fill" aria-hidden="true"></i>Działania</span>
      </div>
    </div>

    <div>
      <div class="left-methods-label" aria-label="Dostępne metody logowania">Metody logowania</div>
      <?php foreach ($_avail_methods as [$icon, $name, $sub]): ?>
      <div class="left-method">
        <i class="bi <?= $icon ?> left-method-icon" aria-hidden="true"></i>
        <div>
          <div class="left-method-name"><?= h($name) ?></div>
          <div class="left-method-sub"><?= h($sub) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="left-footer">
    <?php if ($sel_url): ?>
    <a href="<?= h($sel_url) ?>" class="left-change-org">
      <i class="bi bi-arrow-left-circle" aria-hidden="true"></i>
      <?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?>
    </a>
    <?php endif; ?>
    <div class="left-security">
      <i class="bi bi-lock-fill" aria-hidden="true"></i>
      Połączenie szyfrowane HTTPS
    </div>
    <div class="left-copyright">&copy; <?= date('Y') ?> · <?= h($org_name) ?></div>
  </div>
</aside>

<!-- ══ Prawa — formularz ═════════════════════════════════════════════════════ -->
<div class="login-right">
<main class="login-box" id="login-form-area" tabindex="-1">

  <!-- Miniaturowy nagłówek organizacji (mobile — gdy lewa kolumna zwinięta) -->
  <div class="mobile-top" aria-hidden="true">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="" class="mobile-top-logo">
    <?php endif; ?>
    <span class="mobile-top-org"><?= h($org_name) ?></span>
  </div>

  <h1 class="login-heading" id="login-title">Zaloguj się</h1>

  <!-- Zaproszenie: czym jest system i po co tu jesteś -->
  <div class="login-invite" role="note" aria-label="Informacja o systemie">
    <div class="login-invite-head">
      <i class="bi bi-building-heart" aria-hidden="true"></i>
      Panel pracy <?= h($org_name) ?>
    </div>
    <p class="login-invite-text"><?= nl2br(h($_login_welcome)) ?></p>
    <div class="login-invite-chips" aria-label="Dostępne moduły">
      <span class="login-invite-chip"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Umowy</span>
      <span class="login-invite-chip"><i class="bi bi-diagram-2-fill" aria-hidden="true"></i>CRM</span>
      <span class="login-invite-chip"><i class="bi bi-lock-fill" aria-hidden="true"></i>RODO</span>
      <span class="login-invite-chip"><i class="bi bi-box-seam" aria-hidden="true"></i>Zasoby</span>
      <span class="login-invite-chip"><i class="bi bi-card-checklist" aria-hidden="true"></i>K30</span>
      <span class="login-invite-chip"><i class="bi bi-currency-euro" aria-hidden="true"></i>Granty</span>
    </div>
  </div>

  <?php if ($_login_notices): ?>
  <div role="region" aria-label="Komunikaty administratora" style="margin-bottom:1.25rem">
    <?php foreach ($_login_notices as $_ln):
      $ln_pinned = (int)($_ln['is_pinned'] ?? 0);
    ?>
    <div class="login-notice <?= $ln_pinned ? 'pinned' : '' ?>">
      <i class="bi bi-<?= $ln_pinned ? 'pin-angle-fill' : 'megaphone-fill' ?>" aria-hidden="true"></i>
      <div>
        <div class="login-notice-title"><?= h($_ln['title']) ?></div>
        <?php if ($_ln['body']): ?><div style="margin-top:.2rem;font-size:.84rem"><?= nl2br(h($_ln['body'])) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="login-alert login-alert-danger" role="alert" id="login-error-box">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <span id="login-error-text"><?= h($error) ?></span>
  </div>
  <?php endif; ?>
  <?php if ($info): ?>
  <div class="login-alert login-alert-success" role="status">
    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
    <span><?= h($info) ?></span>
  </div>
  <?php endif; ?>

  <?php include __DIR__ . '/_login_form_body.php'; ?>

</main>
</div><!-- /login-right -->

</div><!-- /login-shell -->

<script>
(function(){
'use strict';

// ── Ogłoszenie błędu przez ARIA live region ───────────────────────────────
var errBox  = document.getElementById('login-error-text');
var liveErr = document.getElementById('login-alert');
if (errBox && liveErr) liveErr.textContent = errBox.textContent.trim();

// ── Przełączanie alternatywnych metod (ARIA Tabs) ─────────────────────────
function switchAltTab(name) {
  var panels = document.querySelectorAll('.method-panel');
  var tabs   = document.querySelectorAll('.method-tab');

  panels.forEach(function(p) {
    p.hidden = true;
    p.tabIndex = -1;
  });
  tabs.forEach(function(b) {
    var sel = b.id === 'tab-btn-' + name;
    b.setAttribute('aria-selected', sel ? 'true' : 'false');
    b.tabIndex = sel ? 0 : -1;
  });

  var panel = document.getElementById('tab-panel-' + name);
  if (panel) {
    panel.hidden = false;
    panel.tabIndex = 0;
    // Focus na pierwszy input lub na panel sam w sobie
    var first = panel.querySelector('input:not([type=hidden])');
    if (first) {
      setTimeout(function() { first.focus(); }, 60);
    } else {
      panel.focus();
    }
  }

  // Powiedz czytnikowi ekranu co się zmieniło
  var labels = {
    code: 'Kod jednorazowy',
    sms:  'Kod SMS',
    x509: 'Certyfikat X.509',
  };
  var live = document.getElementById('login-live');
  if (live) live.textContent = 'Metoda logowania: ' + (labels[name] || name);
}
window.switchAltTab = switchAltTab;

// ── Klawiatura: strzałki + Home/End w tablist ─────────────────────────────
document.addEventListener('keydown', function(e) {
  if (!e.target.matches('.method-tab')) return;
  var tabs = Array.from(document.querySelectorAll('.method-tab'));
  var idx  = tabs.indexOf(e.target);
  var next = -1;
  if      (e.key === 'ArrowRight' || e.key === 'ArrowDown')  next = (idx + 1) % tabs.length;
  else if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')    next = (idx - 1 + tabs.length) % tabs.length;
  else if (e.key === 'Home')                                  next = 0;
  else if (e.key === 'End')                                   next = tabs.length - 1;
  if (next >= 0) {
    e.preventDefault();
    tabs[next].focus();
    // Automatic activation (roving tabindex model)
    var tid = tabs[next].id.replace('tab-btn-', '');
    switchAltTab(tid);
  }
});

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
</body>
</html>
