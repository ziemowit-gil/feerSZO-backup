<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';
require_once dirname(__DIR__) . '/includes/branding.php';

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

$ms_available  = ms_login_available() && _login_method_enabled('login_method_ms365', false);
$sms_available = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled() && _login_method_enabled('login_method_sms', false);
} catch (\Throwable $e) {}
$code_available = _login_method_enabled('login_method_code', true);

// ── State ─────────────────────────────────────────────────────────────────
// Domyślna zakładka zależy od dostępności MS365
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

        // Sprawdź blokadę brute-force
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
                // Zachowaj hasło w sesji do synchronizacji z Moodle (XOR z ID sesji — nie plain text w pamięci)
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
                // WebAuthn 2FA dla adminów i edytorów
                require_once dirname(__DIR__) . '/includes/webauthn.php';
                webauthn_migrate();
                if (in_array($user['role'] ?? '', ['admin', 'editor'], true) && webauthn_user_has_keys((int)$user['id'])) {
                    $_SESSION['webauthn_pending_uid'] = (int)$user['id'];
                    header('Location: ' . APP_URL . '/auth/webauthn.php?redirect=' . urlencode($redirect));
                    exit;
                }
                login_user($user);
                // Wymuś zmianę hasła jeśli ustawione
                if (auth_must_change_password($user)) {
                    flash_set('warning', 'Administrator zresetował Twoje hasło. Ustaw nowe przed kontynuowaniem.');
                    header('Location: ' . APP_URL . '/panel/password.php?force=1'); exit;
                }
                // crm_user → zawsze do CRM
                if (($user['role'] ?? '') === 'crm_user') {
                    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
                }
                header('Location: ' . $redirect); exit;
            }
            brute_record_fail($email);
            authlog_write(null, 'login_fail', $email, 'Nieudana próba logowania');
            $error      = 'Nieprawidłowy e-mail lub hasło.';
            // Sprawdź ile prób zostało
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
if ($code_available) $valid_tabs[] = 'code';
if ($sms_available)  $valid_tabs[] = 'sms';
if ($ms_available)   $valid_tabs[] = 'ms365';
if (!in_array($active_tab, $valid_tabs, true)) $active_tab = $default_tab;

// ── Branding ─────────────────────────────────────────────────────────────
$_b = branding_load();

// ── Kontekst organizacji i link powrotu ──────────────────────────────────
$is_tenant  = defined('TENANT_SLUG') && TENANT_SLUG !== '';
$org_name   = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju FEER');

// URL do select_org.php — liczony bez org/{slug}
$sel_url = null;
if ($is_tenant) {
    $sel_base = parse_url(APP_URL, PHP_URL_SCHEME) . '://' . parse_url(APP_URL, PHP_URL_HOST);
    $sel_path = '';
    $full_path = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if (preg_match('#^(.*)/org/[^/]+$#', $full_path, $m)) {
        $sel_path = $m[1];
    }
    $sel_url = rtrim($sel_base . $sel_path, '/') . '/select_org.php';
} else {
    // Nawet w trybie FEER, jeśli master.db ma org — pokaż link do wyboru
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

$tab_labels = [
    'ms365' => 'Zaloguj się',
    'local' => 'E-mail i hasło',
    'code'  => 'Kod jednorazowy',
    'sms'   => 'Kod SMS',
];
$tab_subs = [
    'ms365' => 'Zaloguj się kontem Microsoft 365 (pracownicy, wolontariusze, zarząd).',
    'local' => 'Pierwsze logowanie lub gdy konto Microsoft nie działa — wprowadź e-mail i hasło.',
    'code'  => 'Nie masz konta Microsoft ani aktywnego konta lokalnego? Wpisz kod jednorazowy od administratora.',
    'sms'   => 'Wyślemy jednorazowy kod na Twój numer telefonu.',
];
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
/* ═══════════════════════════════════════════════════════════════════
   Logowanie — pełna dostępność WCAG 2.1 AA
   ═══════════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; padding: 0; }

/* ── Skip link ─────────────────────────────────────────────────── */
.skip-link {
  position: absolute; top: -100%; left: 1rem; z-index: 9999;
  background: var(--c); color: var(--c-text);
  padding: .75rem 1.5rem; border-radius: 0 0 8px 8px;
  font-size: 1rem; font-weight: 700; text-decoration: none;
  border: 3px solid #FBBF24;
}
.skip-link:focus { top: 0; outline: 3px solid #FBBF24; outline-offset: 2px; }

/* ── Globalny focus ring ────────────────────────────────────────── */
*:focus-visible {
  outline: 3px solid #FBBF24 !important;
  outline-offset: 3px !important;
  border-radius: 3px;
}
*:focus:not(:focus-visible) { outline: none; }

/* ── Layout ─────────────────────────────────────────────────────── */
.login-split { display: flex; min-height: 100vh; }

/* ── Lewa strona — kolor panelu wolontariusza (volunteer_color) ──── */
.login-left {
  width: 420px; flex-shrink: 0;
  background: linear-gradient(155deg, var(--c-darker) 0%, var(--c) 55%, var(--c-light) 100%);
  color: var(--c-text, #fff);
  display: flex; flex-direction: column;
  padding: 0; position: relative; overflow: hidden;
}


/* Górna część — logo + org */
.left-hero {
  position: relative;
  flex: 1;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 3.5rem 2.5rem 2rem;
  text-align: center;
}

/* Logo organizacji */
.left-logo-wrap {
  margin-bottom: 2rem;
}
.left-logo-img {
  max-height: 90px; max-width: 220px;
  object-fit: contain;
  /* Biała wersja na ciemnym tle */
  filter: brightness(0) invert(1);
  opacity: .92;
}
.left-logo-icon {
  width: 80px; height: 80px; border-radius: 20px;
  background: rgba(255,255,255,.13);
  display: flex; align-items: center; justify-content: center;
  font-size: 2.4rem; color: #fff;
  margin: 0 auto;
}

/* Nazwa organizacji — hero */
.left-org-name {
  font-size: 1.5rem; font-weight: 800;
  color: #fff; line-height: 1.2;
  margin-bottom: .5rem;
  text-shadow: 0 1px 12px rgba(0,0,0,.18);
  word-break: break-word;
}
.left-org-tagline {
  font-size: .82rem; color: rgba(255,255,255,.5);
  line-height: 1.5;
}

/* Zmień org link */
.left-change-org {
  display: inline-flex; align-items: center; gap: .35rem;
  margin-top: 1.5rem;
  font-size: .78rem; color: rgba(255,255,255,.5);
  text-decoration: none;
  padding: .35rem .8rem; border-radius: 2rem;
  border: 1px solid rgba(255,255,255,.15);
  transition: color .15s, border-color .15s, background .15s;
}
.left-change-org:hover { color: #fff; border-color: rgba(255,255,255,.4); background: rgba(255,255,255,.07); }

/* Dolny pasek */
.left-footer {
  position: relative;
  padding: 1rem 2.5rem;
  border-top: 1px solid rgba(255,255,255,.07);
  color: rgba(255,255,255,.28); font-size: .72rem;
  display: flex; align-items: center; justify-content: space-between;
}
.left-footer a { color: rgba(255,255,255,.38); text-decoration: none; transition: color .15s; }
.left-footer a:hover { color: rgba(255,255,255,.75); }

/* ── Prawa strona — karta formularza ────────────────────────────── */
.login-right {
  flex: 1;
  background: #EEF2F7;
  display: flex; align-items: center; justify-content: center;
  padding: 2.5rem 2rem; overflow-y: auto;
}
.login-box {
  width: 100%; max-width: 400px;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 4px 32px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
  padding: 2.25rem 2rem;
}

/* Nagłówek mobilny (zamiast lewego panelu) */
.org-mobile {
  display: none;
  text-align: center;
  margin-bottom: 1.75rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid #E2E8F0;
}
.org-mobile-logo { max-height: 52px; max-width: 160px; object-fit: contain; margin-bottom: .75rem; display: block; margin-left: auto; margin-right: auto; }
.org-mobile-icon { font-size: 1.8rem; color: var(--c); display: block; margin-bottom: .5rem; }
.org-mobile-name { font-size: 1rem; font-weight: 700; color: #1E293B; }

/* Heading i podtytuł */
.form-heading { font-size: 1.3rem; font-weight: 700; color: #0F172A; margin-bottom: .2rem; }
.form-sub { font-size: .88rem; color: #64748B; margin-bottom: 1.5rem; line-height: 1.5; }

/* Inputy — min 44px height, wysoki kontrast border */
.login-box .form-label {
  font-size: .88rem; font-weight: 600; color: #1E293B; margin-bottom: .35rem; display: block;
}
.login-box .form-control {
  border: 2px solid #6B7280; border-radius: 6px;
  font-size: .97rem; padding: .6rem .9rem; min-height: 44px;
  color: #0F172A; transition: border-color .15s;
  background: #fff;
}
.login-box .form-control:focus { border-color: var(--c); box-shadow: 0 0 0 3px var(--c-ring); }
.login-box .form-control[aria-invalid="true"] { border-color: #DC2626; background: #FFF5F5; }
.form-hint { font-size: .8rem; color: #4B5563; margin-top: .3rem; }

/* Przycisk główny */
.btn-login {
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  background: var(--c); color: var(--c-text); border: 2px solid var(--c);
  border-radius: 6px; padding: .72rem 1.25rem; font-size: .97rem; font-weight: 600;
  width: 100%; min-height: 48px; cursor: pointer; transition: background .15s, border-color .15s;
  text-decoration: none;
}
.btn-login:hover, .btn-login:focus { background: var(--c-dark); border-color: var(--c-dark); color: var(--c-text); }

/* Przycisk pokaż/ukryj hasło */
.pass-wrap { position: relative; }
.pass-toggle {
  position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
  background: none; border: 2px solid transparent; padding: .25rem;
  color: #6B7280; cursor: pointer; font-size: 1rem; border-radius: 4px; line-height: 1;
  min-width: 36px; min-height: 36px; display: flex; align-items: center; justify-content: center;
}
.pass-toggle:hover { color: var(--c); }

/* Divider */
.or-div {
  display: flex; align-items: center; gap: .75rem;
  color: #94A3B8; font-size: .8rem; margin: 1.35rem 0 1rem;
}
.or-div::before, .or-div::after { content: ''; flex: 1; height: 1px; background: #E2E8F0; }

/* Alternatywne metody */
.alt-methods { display: flex; flex-wrap: wrap; gap: .4rem; }
.alt-btn {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem .9rem; border: 2px solid #CBD5E1; border-radius: 2rem;
  background: #fff; color: #374151; font-size: .82rem; font-weight: 500;
  cursor: pointer; min-height: 40px; transition: border-color .12s, color .12s, background .12s;
  white-space: nowrap;
}
.alt-btn:hover { border-color: var(--c); color: var(--c); background: var(--c-bg); }

/* SMS kod — duże cyfry */
.sms-code {
  font-size: 1.9rem; letter-spacing: .45rem; text-align: center;
  font-family: 'Courier New', monospace; font-weight: 700;
}

/* Alert */
.a11y-alert {
  display: flex; align-items: flex-start; gap: .65rem;
  padding: .85rem 1rem; border-radius: 6px; border: 2px solid;
  margin-bottom: 1.25rem; font-size: .9rem; line-height: 1.5;
}
.a11y-alert-danger  { background: #FEF2F2; border-color: #DC2626; color: #7F1D1D; }
.a11y-alert-success { background: #F0FDF4; border-color: #16A34A; color: #14532D; }
.a11y-alert-icon    { font-size: 1.1rem; flex-shrink: 0; margin-top: .05rem; }

/* Zakładki — tablist */
[role="tablist"] { display: flex; flex-wrap: wrap; gap: .4rem; }
[role="tab"] {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem .9rem; border: 2px solid #CBD5E1; border-radius: 2rem;
  background: #fff; color: #374151; font-size: .82rem; font-weight: 500;
  cursor: pointer; min-height: 40px; transition: all .12s; white-space: nowrap;
}
[role="tab"][aria-selected="true"] { background: var(--c-bg); border-color: var(--c); color: var(--c); font-weight: 600; }
[role="tab"]:hover:not([aria-selected="true"]) { border-color: var(--c); color: var(--c); }

/* Microsoft logo */
.ms-logo { flex-shrink: 0; }

/* Responsive */
@media (max-width: 780px) {
  .login-split { flex-direction: column; }
  .login-left {
    width: 100%; flex-direction: row; min-height: auto;
    padding: .9rem 1.25rem;
  }
  .login-left::before, .login-left::after { display: none; }
  .left-hero {
    flex-direction: row; padding: 0; gap: .8rem; justify-content: flex-start;
    text-align: left;
  }
  .left-logo-wrap { margin-bottom: 0; }
  .left-logo-img { max-height: 34px; max-width: 120px; }
  .left-logo-icon { width: 34px; height: 34px; font-size: 1.1rem; border-radius: 8px; }
  .left-org-name { font-size: .95rem; margin-bottom: 0; }
  .left-org-tagline, .left-change-org, .left-footer { display: none; }
  .org-mobile { display: block; }
  .login-right {
    padding: 1.25rem 1rem;
    background: #F8FAFC;
    background-image: none;
    align-items: flex-start;
  }
  .login-box {
    box-shadow: none;
    border-radius: 12px;
    padding: 1.75rem 1.25rem;
  }
}
@media (prefers-contrast: high) {
  .login-box .form-control { border-width: 3px; border-color: #000; }
  .btn-login { background: #000 !important; border-color: #000 !important; color: #fff !important; }
  .a11y-alert-danger { border-width: 3px; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; }
}
</style>
</head>
<body>

<!-- ══ Skip link ═══════════════════════════════════════════════════════════ -->
<a href="#login-main" class="skip-link">Przejdź do formularza logowania</a>

<!-- ══ Live region dla czytników ════════════════════════════════════════════ -->
<div role="status" aria-live="polite" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)" id="login-live"></div>
<div role="alert" aria-live="assertive" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)" id="login-alert"></div>

<div class="login-split">

<!-- ══ Lewa — dekoracyjna, aria-hidden ═════════════════════════════════════ -->
<aside class="login-left" aria-hidden="true">

  <!-- Hero: logo + nazwa org -->
  <div class="left-hero">
    <div class="left-logo-wrap">
      <?php if ($_b['logo_url']): ?>
      <img src="<?= h($_b['logo_url']) ?>" alt="" class="left-logo-img">
      <?php else: ?>
      <div class="left-logo-icon">
        <i class="bi bi-building-heart"></i>
      </div>
      <?php endif; ?>
    </div>

    <div class="left-org-name"><?= h($org_name) ?></div>
    <div class="left-org-tagline">System Zarządzania<br>Organizacją i Wolontariatem</div>

    <?php if ($sel_url): ?>
    <a href="<?= h($sel_url) ?>" class="left-change-org">
      <i class="bi bi-arrow-left-circle"></i>
      <?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?>
    </a>
    <?php endif; ?>
  </div>

  <!-- Stopka -->
  <div class="left-footer">
  </div>

</aside>

<!-- ══ Prawa — treść, dostępna ═════════════════════════════════════════════ -->
<div class="login-right">
<main class="login-box" id="login-main" role="main" tabindex="-1">

  <!-- Nagłówek organizacji (mobile) -->
  <div class="org-mobile" aria-label="Informacja o organizacji">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" class="org-mobile-logo">
    <?php else: ?>
    <i class="bi bi-building-heart org-mobile-icon" aria-hidden="true"></i>
    <?php endif; ?>
    <div class="org-mobile-name"><?= h($org_name) ?></div>
    <?php if ($sel_url): ?>
    <a href="<?= h($sel_url) ?>" style="font-size:.78rem;color:#64748b;display:inline-flex;align-items:center;gap:.3rem;margin-top:.5rem;text-decoration:none">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>
      <?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?>
    </a>
    <?php endif; ?>
  </div>

  <!-- Nagłówek (aria-labelledby dla formularza) -->
  <h1 class="form-heading" id="login-heading"><?= h($tab_labels[$active_tab] ?? 'Zaloguj się') ?></h1>
  <p class="form-sub" id="login-sub"><?= h($tab_subs[$active_tab] ?? '') ?></p>

  <!-- Komunikaty błędów i sukcesu -->
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

  <!-- ══ ZAKŁADKI ════════════════════════════════════════════════════════ -->

  <!-- Microsoft 365 — metoda główna -->
  <?php if ($ms_available): ?>
  <section id="tab-ms365" aria-labelledby="login-heading"
           <?= $active_tab !== 'ms365' ? 'hidden' : '' ?>>
    <a href="<?= h(ms_auth_url($redirect)) ?>"
       class="btn-login"
       aria-label="Zaloguj się przez konto Microsoft 365 — zostaniesz przekierowany na stronę Microsoft">
      <svg class="ms-logo" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true">
        <path fill="#f35325" d="M1 1h10v10H1z"/>
        <path fill="#81bc06" d="M12 1h10v10H12z"/>
        <path fill="#05a6f0" d="M1 12h10v10H12z"/>
        <path fill="#ffba08" d="M12 12h10v10H12z"/>
      </svg>
      Zaloguj przez Microsoft 365
    </a>
    <p style="text-align:center;font-size:.82rem;color:#6B7280;margin-top:.85rem">
      Zostaniesz przekierowany na stronę logowania Microsoft.
    </p>
  </section>
  <?php endif; ?>

  <!-- E-mail + hasło — zapasowa (pierwsze logowanie / brak MS) -->
  <section id="tab-local" aria-labelledby="login-heading"
           <?= $active_tab !== 'local' ? 'hidden' : '' ?>>
    <form method="post" novalidate aria-label="Formularz logowania — e-mail i hasło" autocomplete="on">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_method" value="local">
      <div class="mb-3">
        <label class="form-label" for="f-email">Adres e-mail</label>
        <input type="email" name="email" id="f-email"
               class="form-control"
               placeholder="nazwa@domena.pl"
               autocomplete="email"
               required aria-required="true"
               <?= ($active_tab === 'local' && !$error) ? 'autofocus' : '' ?>
               <?= $error ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>>
        <?php if ($error && str_contains($error, 'mail')): ?>
        <div id="email-err" class="form-hint" style="color:#DC2626"><?= h($error) ?></div>
        <?php endif; ?>
      </div>
      <div class="mb-4">
        <label class="form-label" for="f-pass">Hasło</label>
        <div class="pass-wrap">
          <input type="password" name="password" id="f-pass"
                 class="form-control"
                 autocomplete="current-password"
                 required aria-required="true"
                 aria-describedby="pass-hint">
          <button type="button" class="pass-toggle"
                  id="pass-toggle-btn"
                  aria-label="Pokaż hasło"
                  aria-pressed="false"
                  onclick="togglePass('f-pass', this)">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
        <div id="pass-hint" class="form-hint">
          Użyj tego formularza przy pierwszym logowaniu lub gdy konto Microsoft nie działa.
        </div>
      </div>
      <button type="submit" class="btn-login">
        Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </button>
      <div class="text-center mt-3">
        <a href="<?= APP_URL ?>/user/verify_reset.php"
           style="font-size:.84rem;color:#4B5563;text-decoration:none"
           aria-label="Zresetuj zapomniane hasło — przejdź do formularza resetowania">
          <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Zapomniałem hasła
        </a>
      </div>
    </form>
  </section>

  <!-- Kod jednorazowy — awaryjny (brak MS365 i konta lokalnego) -->
  <?php if ($code_available): ?>
  <section id="tab-code" aria-labelledby="login-heading"
           <?= $active_tab !== 'code' ? 'hidden' : '' ?>>
    <form method="post" novalidate aria-label="Formularz logowania — jednorazowy kod dostępu" autocomplete="off">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_method" value="code">
      <div class="mb-4">
        <label class="form-label" for="f-code">Kod jednorazowy</label>
        <input type="text" name="login_code" id="f-code"
               class="form-control"
               style="font-family:monospace;letter-spacing:.1em;text-align:center;font-size:1.1rem"
               placeholder="XXXXXXXX"
               spellcheck="false"
               autocomplete="one-time-code"
               required aria-required="true"
               aria-describedby="code-hint"
               <?= $active_tab === 'code' ? 'autofocus' : '' ?>>
        <div id="code-hint" class="form-hint">
          Kod jednorazowy dostępu nadany przez administratora — dla osób bez konta Microsoft i bez aktywnego konta lokalnego.
        </div>
      </div>
      <button type="submit" class="btn-login">
        Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </button>
    </form>
  </section>
  <?php endif; ?>

  <!-- SMS -->
  <?php if ($sms_available): ?>
  <section id="tab-sms" aria-labelledby="login-heading"
           <?= $active_tab !== 'sms' ? 'hidden' : '' ?>>
    <?php if ($sms_step === 1): ?>
    <form method="post" novalidate aria-label="Formularz logowania — krok 1: podaj numer telefonu" autocomplete="off">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_method" value="sms_send">
      <div class="mb-4">
        <label class="form-label" for="f-sms-phone">Numer telefonu</label>
        <div class="input-group">
          <span class="input-group-text fw-semibold" style="border:2px solid #6B7280;border-right:none;color:#374151">+48</span>
          <input type="tel" name="sms_phone" id="f-sms-phone"
                 class="form-control"
                 style="border-left:none"
                 placeholder="123 456 789"
                 value="<?= h($sms_phone) ?>"
                 inputmode="numeric" pattern="[0-9 ]{9,11}"
                 autocomplete="tel-national"
                 required aria-required="true"
                 aria-describedby="sms-phone-hint"
                 <?= $active_tab === 'sms' ? 'autofocus' : '' ?>>
        </div>
        <div id="sms-phone-hint" class="form-hint">
          Numer powiązany z umową wolontariacką — 9 cyfr, bez spacji.
        </div>
      </div>
      <button type="submit" class="btn-login">
        <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij kod SMS
      </button>
    </form>
    <?php else: ?>
    <form method="post" novalidate aria-label="Formularz logowania — krok 2: wpisz kod SMS" autocomplete="off">
      <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
      <input type="hidden" name="_method"   value="sms_verify">
      <input type="hidden" name="sms_phone" value="<?= h($sms_phone) ?>">
      <p style="font-size:.88rem;color:#374151;margin-bottom:1rem">
        Kod wysłany na numer <strong><?= h($sms_phone) ?></strong>. Ważny 5 minut.
      </p>
      <div class="mb-4">
        <label class="form-label" for="f-sms-code">6-cyfrowy kod SMS</label>
        <input type="text" name="sms_code" id="f-sms-code"
               class="form-control sms-code"
               inputmode="numeric"
               pattern="[0-9]{6}"
               maxlength="6"
               placeholder="• • • • • •"
               autocomplete="one-time-code"
               required aria-required="true"
               aria-describedby="sms-code-hint"
               autofocus>
        <div id="sms-code-hint" class="form-hint">
          Wpisz 6 cyfr z otrzymanego SMS. Kod wygaśnie za 5 minut.
        </div>
      </div>
      <button type="submit" class="btn-login mb-3">
        Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </button>
      <button type="button"
              class="btn-link w-100 text-center"
              style="background:none;border:none;color:#4B5563;font-size:.84rem;cursor:pointer;padding:.4rem;text-decoration:underline"
              aria-label="Wróć — zmień numer telefonu (krok 1)"
              onclick="document.querySelector('[name=_method]').value='sms_send';this.closest('form').submit()">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Zmień numer telefonu
      </button>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- ══ Inne metody — role="tablist" ══════════════════════════════════ -->
  <?php
  $all_tabs = [];
  if ($ms_available)   $all_tabs['ms365'] = ['icon' => 'bi-microsoft',  'label' => 'Microsoft 365'];
  $all_tabs['local'] = ['icon' => 'bi-person-fill', 'label' => 'E-mail i hasło'];
  if ($code_available) $all_tabs['code']  = ['icon' => 'bi-key-fill',   'label' => 'Kod jednorazowy'];
  if ($sms_available)  $all_tabs['sms']   = ['icon' => 'bi-phone-fill', 'label' => 'Kod SMS'];
  ?>
  <?php if (count($all_tabs) > 1): ?>
  <div class="or-div" aria-hidden="true"><span>inne metody logowania</span></div>
  <nav aria-label="Metody logowania" id="tab-nav">
    <div role="tablist" aria-label="Wybierz metodę logowania">
      <?php foreach ($all_tabs as $key => $m): ?>
      <button role="tab"
              id="tab-btn-<?= $key ?>"
              aria-selected="<?= $active_tab === $key ? 'true' : 'false' ?>"
              aria-controls="tab-<?= $key ?>"
              onclick="switchTab('<?= $key ?>')"
              <?= $active_tab === $key ? '' : 'tabindex="-1"' ?>>
        <i class="bi <?= $m['icon'] ?>" aria-hidden="true"></i>
        <?= h($m['label']) ?>
      </button>
      <?php endforeach; ?>
    </div>
  </nav>
  <?php endif; ?>

</main>
</div><!-- /login-right -->
</div><!-- /login-split -->

<script>
var _tabLabels = <?= json_encode($tab_labels, JSON_UNESCAPED_UNICODE) ?>;
var _tabSubs   = <?= json_encode($tab_subs,   JSON_UNESCAPED_UNICODE) ?>;

function switchTab(name) {
  // Ukryj wszystkie sekcje
  document.querySelectorAll('.login-box section[id^="tab-"]').forEach(function(s) {
    s.hidden = true;
  });
  // Pokaż wybraną
  var section = document.getElementById('tab-' + name);
  if (section) {
    section.hidden = false;
    // Fokus na pierwszy input
    var first = section.querySelector('input:not([type=hidden]),a.btn-login');
    if (first) setTimeout(function() { first.focus(); }, 60);
  }
  // Aktualizuj tablist ARIA
  document.querySelectorAll('[role="tab"]').forEach(function(btn) {
    var sel = btn.id === 'tab-btn-' + name;
    btn.setAttribute('aria-selected', sel ? 'true' : 'false');
    btn.tabIndex = sel ? 0 : -1;
  });
  // Aktualizuj nagłówek
  var h = document.getElementById('login-heading');
  var s = document.getElementById('login-sub');
  if (h) h.textContent = _tabLabels[name] || 'Zaloguj się';
  if (s) s.textContent = _tabSubs[name]   || '';
  // Ogłoś zmianę zakładki
  var live = document.getElementById('login-live');
  if (live) live.textContent = 'Metoda logowania: ' + (_tabLabels[name] || name) + '. ' + (_tabSubs[name] || '');
}

// Klawiatura: strzałki w tablist
document.addEventListener('keydown', function(e) {
  if (!e.target.matches('[role="tab"]')) return;
  var tabs = Array.from(document.querySelectorAll('[role="tab"]'));
  var idx  = tabs.indexOf(e.target);
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
    e.preventDefault();
    var next = tabs[(idx + 1) % tabs.length];
    next.focus();
    switchTab(next.id.replace('tab-btn-', ''));
  }
  if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
    e.preventDefault();
    var prev = tabs[(idx - 1 + tabs.length) % tabs.length];
    prev.focus();
    switchTab(prev.id.replace('tab-btn-', ''));
  }
  if (e.key === 'Home') { e.preventDefault(); tabs[0].focus(); switchTab(tabs[0].id.replace('tab-btn-','')); }
  if (e.key === 'End')  { e.preventDefault(); var l=tabs[tabs.length-1]; l.focus(); switchTab(l.id.replace('tab-btn-','')); }
});

// Pokaż/ukryj hasło
function togglePass(id, btn) {
  var input = document.getElementById(id);
  if (!input) return;
  var showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
  btn.setAttribute('aria-label', showing ? 'Pokaż hasło' : 'Ukryj hasło');
  btn.querySelector('i').className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
}

// Ogłoś błąd przy załadowaniu (jeśli jest)
(function() {
  var err = document.querySelector('.a11y-alert-danger');
  var live = document.getElementById('login-alert');
  if (err && live) live.textContent = err.textContent.trim();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
