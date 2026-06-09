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
$_b          = branding_load();
$_login_bg   = org_setting('login_bg_color') ?: '#EEF2F7';
if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $_login_bg)) $_login_bg = '#EEF2F7';
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
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
:root { --login-bg: <?= h($_login_bg) ?>; }
*, *::before, *::after { box-sizing: border-box; }
html, body { min-height: 100%; margin: 0; background: var(--login-bg, #EEF2F7); }

.skip-link {
  position: absolute; top: -100%; left: 1rem; z-index: 9999;
  background: var(--c); color: var(--c-text);
  padding: .5rem 1rem; border-radius: 0 0 6px 6px; font-weight: 700; text-decoration: none;
}
.skip-link:focus { top: 0; outline: 3px solid #FBBF24; }

*:focus-visible { outline: 3px solid #FBBF24 !important; outline-offset: 3px !important; }
*:focus:not(:focus-visible) { outline: none; }

.page-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 2rem 1rem;
}
.login-card {
  width: 100%; max-width: 420px;
  background: #fff; border-radius: 16px;
  box-shadow: 0 4px 32px rgba(0,0,0,.1);
  overflow: hidden;
}
.login-card.wide { max-width: 700px; }

.card-top {
  background: linear-gradient(155deg, var(--c-darker) 0%, var(--c) 55%, var(--c-light) 100%);
  padding: 1.75rem 2rem 1.5rem; text-align: center;
}
.card-top img {
  max-height: 56px; max-width: 160px; object-fit: contain;
  filter: brightness(0) invert(1); opacity: .9;
  display: block; margin: 0 auto .75rem;
}
.card-top-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; color: #fff; margin: 0 auto .75rem;
}
.card-org { font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0; }
.card-tagline { font-size: .78rem; color: rgba(255,255,255,.65); margin: .35rem 0 0; }
.change-org {
  display: inline-flex; align-items: center; gap: .3rem;
  margin-top: .75rem; font-size: .73rem; color: rgba(255,255,255,.55);
  text-decoration: none; padding: .2rem .6rem;
  border: 1px solid rgba(255,255,255,.2); border-radius: 2rem;
  transition: color .15s, border-color .15s;
}
.change-org:hover { color: #fff; border-color: rgba(255,255,255,.5); }

.card-body { padding: 2rem; }

.form-heading { font-size: 1.2rem; font-weight: 700; color: #0F172A; margin-bottom: 1.5rem; }
.form-label { font-size: .88rem; font-weight: 600; color: #1E293B; margin-bottom: .35rem; display: block; }
.form-control {
  border: 2px solid #6B7280; border-radius: 6px;
  font-size: .97rem; padding: .6rem .9rem; min-height: 44px;
  color: #0F172A; width: 100%; transition: border-color .15s; background: #fff;
}
.form-control:focus { border-color: var(--c); box-shadow: 0 0 0 3px var(--c-ring); outline: none; }
.form-control[aria-invalid="true"] { border-color: #DC2626; background: #FFF5F5; }
.form-hint { font-size: .8rem; color: #4B5563; margin-top: .3rem; }

.pass-wrap { position: relative; }
.pass-toggle {
  position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
  background: none; border: 2px solid transparent; padding: .25rem;
  color: #6B7280; cursor: pointer; font-size: 1rem; border-radius: 4px;
  min-width: 36px; min-height: 36px; display: flex; align-items: center; justify-content: center;
}
.pass-toggle:hover { color: var(--c); }

.btn-login {
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  background: var(--c); color: var(--c-text); border: 2px solid var(--c);
  border-radius: 6px; padding: .72rem 1.25rem; font-size: .97rem; font-weight: 600;
  width: 100%; min-height: 48px; cursor: pointer; transition: background .15s, border-color .15s;
  text-decoration: none;
}
.btn-login:hover { background: var(--c-dark); border-color: var(--c-dark); color: var(--c-text); }

.or-div {
  display: flex; align-items: center; gap: .75rem;
  color: #94A3B8; font-size: .8rem; margin: 1.35rem 0 1rem;
}
.or-div::before, .or-div::after { content: ''; flex: 1; height: 1px; background: #E2E8F0; }

[role="tablist"] { display: flex; flex-wrap: wrap; gap: .4rem; }
[role="tab"] {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem .9rem; border: 2px solid #CBD5E1; border-radius: 2rem;
  background: #fff; color: #374151; font-size: .82rem; font-weight: 500;
  cursor: pointer; min-height: 40px; transition: all .12s; white-space: nowrap;
}
[role="tab"][aria-selected="true"] { background: var(--c-bg); border-color: var(--c); color: var(--c); font-weight: 600; }
[role="tab"]:hover:not([aria-selected="true"]) { border-color: var(--c); color: var(--c); }

.sms-code { font-size: 1.9rem; letter-spacing: .45rem; text-align: center; font-family: monospace; font-weight: 700; }

.a11y-alert {
  display: flex; align-items: flex-start; gap: .65rem;
  padding: .85rem 1rem; border-radius: 6px; border: 2px solid;
  margin-bottom: 1.25rem; font-size: .9rem; line-height: 1.5;
}
.a11y-alert-danger  { background: #FEF2F2; border-color: #DC2626; color: #7F1D1D; }
.a11y-alert-success { background: #F0FDF4; border-color: #16A34A; color: #14532D; }
.a11y-alert-icon    { font-size: 1.1rem; flex-shrink: 0; margin-top: .05rem; }

.ms-logo { flex-shrink: 0; }
.login-cols { display: grid; grid-template-columns: 1fr 1px 1fr; gap: 0 2rem; margin-bottom: .5rem; }
.login-col-divider { background: #E2E8F0; }
.login-col-ms { display: flex; flex-direction: column; justify-content: center; padding-right: 1rem; }
.login-col-local { padding-left: 1rem; }
.col-heading { font-size: .82rem; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 1rem; }
.ms-col-note { font-size: .8rem; color: #94A3B8; margin-top: .75rem; line-height: 1.5; text-align: center; }

@media (max-width: 600px) {
  .login-cols { grid-template-columns: 1fr; gap: 1.5rem 0; }
  .login-col-divider { display: none; }
  .login-col-ms { padding-right: 0; padding-bottom: 1.5rem; border-bottom: 1px solid #E2E8F0; }
  .login-col-local { padding-left: 0; }
  .card-body { padding: 1.5rem 1.25rem; }
}
@media (prefers-contrast: high) {
  .form-control { border-width: 3px; border-color: #000; }
  .btn-login { background: #000 !important; border-color: #000 !important; color: #fff !important; }
  .a11y-alert-danger { border-width: 3px; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; }
}
</style>
</head>
<body>

<a href="#login-main" class="skip-link">Przejdź do formularza logowania</a>

<div role="status" aria-live="polite" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)" id="login-live"></div>
<div role="alert" aria-live="assertive" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)" id="login-alert"></div>

<div class="page-wrap">
<main class="login-card <?= $ms_available ? 'wide' : '' ?>" id="login-main" role="main" tabindex="-1">

  <div class="card-top">
    <?php if ($_b['logo_url']): ?>
      <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>">
    <?php else: ?>
      <div class="card-top-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></div>
    <?php endif; ?>
    <p class="card-org"><?= h($org_name) ?></p>
    <?php if ($_login_tagline): ?><p class="card-tagline"><?= h($_login_tagline) ?></p><?php endif; ?>
    <?php if ($sel_url): ?>
      <a href="<?= h($sel_url) ?>" class="change-org">
        <i class="bi bi-arrow-left-circle" aria-hidden="true"></i>
        <?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?>
      </a>
    <?php endif; ?>
  </div>

  <div class="card-body">
    <h1 class="form-heading">Zaloguj się</h1>

    <?php if ($_login_notices): ?>
    <div style="margin-bottom:1.25rem">
      <?php foreach ($_login_notices as $_ln):
        $ln_pinned = (int)($_ln['is_pinned'] ?? 0);
        $ln_color  = $ln_pinned ? '#F59E0B' : '#2563EB';
        $ln_bg     = $ln_pinned ? '#FFFBEB' : '#EFF6FF';
      ?>
      <div style="background:<?= $ln_bg ?>;border-left:3px solid <?= $ln_color ?>;border-radius:8px;padding:.75rem 1rem;margin-bottom:.6rem;font-size:.84rem;color:#1e293b">
        <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.2rem;flex-wrap:wrap">
          <i class="bi bi-<?= $ln_pinned ? 'pin-angle-fill' : 'megaphone-fill' ?>" style="color:<?= $ln_color ?>"></i>
          <strong><?= h($_ln['title']) ?></strong>
          <span style="font-size:.7rem;color:#94a3b8;margin-left:auto"><?= h(substr($_ln['created_at'] ?? '', 0, 10)) ?></span>
        </div>
        <?php if ($_ln['body']): ?><div style="color:#374151;margin-top:.15rem;line-height:1.5"><?= nl2br(h($_ln['body'])) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="a11y-alert a11y-alert-danger" role="alert" aria-label="Błąd logowania: <?= h($error) ?>">
      <i class="bi bi-exclamation-triangle-fill a11y-alert-icon" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($info): ?>
    <div class="a11y-alert a11y-alert-success" role="status">
      <i class="bi bi-check-circle-fill a11y-alert-icon" aria-hidden="true"></i>
      <span><?= h($info) ?></span>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/_login_form_body.php'; ?>

  </div>
</main>
</div>

<script>
function switchAltTab(name) {
  ['code','sms','x509'].forEach(function(k) {
    var s = document.getElementById('tab-' + k);
    if (s) s.hidden = true;
  });
  var section = document.getElementById('tab-' + name);
  if (section) {
    section.hidden = false;
    var first = section.querySelector('input:not([type=hidden])');
    if (first) setTimeout(function() { first.focus(); }, 60);
  }
  document.querySelectorAll('[role="tab"]').forEach(function(btn) {
    var sel = btn.id === 'tab-btn-' + name;
    btn.setAttribute('aria-selected', sel ? 'true' : 'false');
    btn.tabIndex = sel ? 0 : -1;
  });
  var live = document.getElementById('login-live');
  var labels = {'code': 'Kod jednorazowy', 'sms': 'Kod SMS', 'x509': 'Certyfikat X.509'};
  if (live) live.textContent = 'Metoda logowania: ' + (labels[name] || name);
}

document.addEventListener('keydown', function(e) {
  if (!e.target.matches('[role="tab"]')) return;
  var tabs = Array.from(document.querySelectorAll('[role="tab"]'));
  var idx  = tabs.indexOf(e.target);
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
    e.preventDefault();
    tabs[(idx + 1) % tabs.length].focus();
  }
  if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
    e.preventDefault();
    tabs[(idx - 1 + tabs.length) % tabs.length].focus();
  }
});

function togglePass(id, btn) {
  var input = document.getElementById(id);
  if (!input) return;
  var showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
  btn.setAttribute('aria-label', showing ? 'Pokaż hasło' : 'Ukryj hasło');
  btn.querySelector('i').className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
}

(function() {
  var err = document.querySelector('.a11y-alert-danger');
  var live = document.getElementById('login-alert');
  if (err && live) live.textContent = err.textContent.trim();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
