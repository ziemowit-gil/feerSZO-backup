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

// ── Publiczne komunikaty administratora ──────────────────────────────────
$_login_notices = [];
try {
    require_once dirname(__DIR__) . '/includes/notifications.php';
    notif_migrate();
    $_login_notices = ann_public_list();
} catch (\Throwable $_) {}

// ── Branding + layout ─────────────────────────────────────────────────────
$_b           = branding_load();
$_login_layout   = org_setting('login_layout')   ?: 'split';
if (!in_array($_login_layout, ['split','simple'], true)) $_login_layout = 'split';
$_login_tagline  = org_setting('login_tagline')  ?: '';
$_login_bg       = org_setting('login_bg_color') ?: '#EEF2F7';
if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $_login_bg)) $_login_bg = '#EEF2F7';

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
    'ms365' => 'Konto Microsoft',
    'local' => 'E-mail i hasło',
    'code'  => 'Kod jednorazowy',
    'sms'   => 'Kod SMS',
];

// Kto używa której metody — wyświetlane jako przewodnik na stronie logowania
$login_guide = [];
if ($ms_available) {
    $login_guide[] = [
        'icon'  => 'bi-microsoft',
        'color' => '#2563eb',
        'who'   => 'Masz konto Microsoft organizacji?',
        'how'   => 'Użyj przycisku Microsoft 365 — jedno kliknięcie, bez hasła.',
        'tab'   => 'ms365',
    ];
}
$login_guide[] = [
    'icon'  => 'bi-person-lock',
    'color' => '#0f766e',
    'who'   => 'Administrator lub koordynator?',
    'how'   => 'Wpisz e-mail i hasło nadane przez system.',
    'tab'   => 'local',
];
if ($code_available) {
    $login_guide[] = [
        'icon'  => 'bi-key',
        'color' => '#7c3aed',
        'who'   => 'Logujesz się po raz pierwszy?',
        'how'   => 'Użyj kodu jednorazowego — otrzymałeś/aś go od administratora.',
        'tab'   => 'code',
    ];
}
if ($sms_available) {
    $login_guide[] = [
        'icon'  => 'bi-phone',
        'color' => '#b45309',
        'who'   => 'Wolontariusz bez konta Microsoft?',
        'how'   => 'Wyślemy kod SMS na numer podany w umowie — nie potrzebujesz hasła.',
        'tab'   => 'sms',
    ];
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

/* ── Przewodnik metod logowania (lewa strona) ───────────────────── */
.left-guide {
  padding: 1.25rem 2rem 1.5rem;
  border-top: 1px solid rgba(255,255,255,.10);
}
.left-guide-title {
  font-size: .68rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  color: rgba(255,255,255,.45); margin-bottom: .75rem;
}
.left-guide-item {
  display: flex; align-items: flex-start; gap: .65rem; margin-bottom: .6rem;
}
.left-guide-item:last-child { margin-bottom: 0; }
.left-guide-icon {
  width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: .85rem;
}
.left-guide-who {
  font-size: .78rem; font-weight: 600; color: rgba(255,255,255,.92); line-height: 1.2;
}
.left-guide-how {
  font-size: .7rem; color: rgba(255,255,255,.52); margin-top: .1rem; line-height: 1.3;
}

/* ── Prawa strona — karta formularza ────────────────────────────── */
.login-right {
  flex: 1;
  background: var(--login-bg, #EEF2F7);
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
.login-box.has-ms-split {
  max-width: 780px;
}

/* Dwukolumnowy układ MS365 | formularz */
.login-cols {
  display: grid;
  grid-template-columns: 1fr 1px 1fr;
  gap: 0 2rem;
  margin-bottom: .5rem;
}
.login-col-divider {
  background: #E2E8F0;
  align-self: stretch;
  margin: 0;
}
.login-col-ms {
  display: flex; flex-direction: column;
  justify-content: center; align-items: stretch;
  padding-right: 1rem;
}
.ms-col-heading {
  font-size: .82rem; font-weight: 700; color: #64748B;
  text-transform: uppercase; letter-spacing: .06em;
  margin-bottom: 1rem;
}
.ms-col-note {
  font-size: .8rem; color: #94A3B8; margin-top: .75rem;
  line-height: 1.5; text-align: center;
}
.login-col-local {
  padding-left: 1rem;
}
.local-col-heading {
  font-size: .82rem; font-weight: 700; color: #64748B;
  text-transform: uppercase; letter-spacing: .06em;
  margin-bottom: 1rem;
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

/* Responsive — split layout */
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
  .left-org-tagline, .left-change-org, .left-footer, .left-guide { display: none; }
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
  .login-cols {
    grid-template-columns: 1fr;
    gap: 1.5rem 0;
  }
  .login-col-divider { display: none; }
  .login-col-ms { padding-right: 0; padding-bottom: 1.5rem; border-bottom: 1px solid #E2E8F0; }
  .login-col-local { padding-left: 0; }
}

/* ── Layout: simple ────────────────────────────────────────────── */
.login-simple-wrap {
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 2rem 1rem;
  background: var(--login-bg, #EEF2F7);
}
.login-simple-box {
  width: 100%; max-width: 420px;
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 4px 40px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.05);
  overflow: hidden;
}
.login-simple-box.has-ms-split { max-width: 640px; }
.login-simple-header {
  padding: 2rem 2rem 1.5rem;
  text-align: center;
  background: linear-gradient(155deg, var(--c-darker) 0%, var(--c) 55%, var(--c-light) 100%);
  color: var(--c-text, #fff);
}
.login-simple-logo {
  max-height: 60px; max-width: 180px;
  object-fit: contain;
  filter: brightness(0) invert(1); opacity: .92;
  display: block; margin: 0 auto 1rem;
}
.login-simple-icon {
  width: 64px; height: 64px; border-radius: 16px;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.8rem; color: #fff;
  margin: 0 auto 1rem;
}
.login-simple-orgname {
  font-size: 1.15rem; font-weight: 800; color: #fff;
  text-shadow: 0 1px 8px rgba(0,0,0,.18);
  margin: 0; line-height: 1.2;
}
.login-simple-tagline {
  font-size: .78rem; color: rgba(255,255,255,.6);
  margin: .4rem 0 0; line-height: 1.4;
}
.login-simple-change {
  display: inline-flex; align-items: center; gap: .3rem;
  margin-top: .85rem; font-size: .73rem; color: rgba(255,255,255,.5);
  text-decoration: none; padding: .25rem .6rem;
  border: 1px solid rgba(255,255,255,.2); border-radius: 2rem;
  transition: color .15s, border-color .15s;
}
.login-simple-change:hover { color: #fff; border-color: rgba(255,255,255,.5); }
.login-simple-body { padding: 2rem; }
@media (max-width: 480px) {
  .login-simple-box { border-radius: 12px; }
  .login-simple-body { padding: 1.5rem 1.25rem; }
}

@media (prefers-contrast: high) {
  .login-box .form-control,
  .login-simple-box .form-control { border-width: 3px; border-color: #000; }
  .btn-login { background: #000 !important; border-color: #000 !important; color: #fff !important; }
  .a11y-alert-danger { border-width: 3px; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; }
}
</style>
<style>
  :root { --login-bg: <?= h($_login_bg) ?>; }
</style>
</head>
<body>

<!-- ══ Wykrywanie przeglądarki — modal ══════════════════════════════════════ -->
<div id="browser-warn-overlay" style="display:none;position:fixed;inset:0;z-index:99999;
     background:rgba(15,23,42,.82);backdrop-filter:blur(6px);
     align-items:center;justify-content:center;padding:1rem">
  <div style="background:#fff;border-radius:20px;max-width:480px;width:100%;
              box-shadow:0 24px 64px rgba(0,0,0,.35);overflow:hidden;
              animation:_bwIn .3s cubic-bezier(.34,1.56,.64,1) both">

    <!-- Kolorowy nagłówek -->
    <div style="background:linear-gradient(135deg,#7f1d1d,#dc2626);padding:1.75rem 1.5rem 1.25rem;text-align:center">
      <div style="font-size:2.75rem;margin-bottom:.5rem">⚠️</div>
      <div style="color:#fff;font-size:1.15rem;font-weight:800;letter-spacing:-.02em">
        Nieobsługiwana przeglądarka
      </div>
      <div id="bw-browser-name" style="color:rgba(255,255,255,.75);font-size:.85rem;margin-top:.25rem"></div>
    </div>

    <!-- Treść -->
    <div style="padding:1.75rem 1.75rem 1.5rem">
      <p id="bw-msg" style="font-size:.95rem;color:#111827;line-height:1.7;margin:0 0 1.25rem;text-align:center;font-weight:500"></p>

      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1rem;margin-bottom:1.25rem">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;text-align:center;margin-bottom:.75rem">
          Zalecane przeglądarki
        </div>
        <div style="display:flex;gap:.75rem;justify-content:center">
          <a href="https://www.mozilla.org/firefox/" target="_blank" rel="noopener"
             style="display:flex;flex-direction:column;align-items:center;gap:.35rem;
                    background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;
                    padding:.75rem 1.25rem;text-decoration:none;color:#374151;
                    font-size:.8rem;font-weight:600;transition:border-color .1s;min-width:100px"
             onmouseover="this.style.borderColor='#ff9500'" onmouseout="this.style.borderColor='#e2e8f0'">
            <span style="font-size:2rem">🦊</span>
            <span>Firefox</span>
            <span style="font-size:.68rem;color:#94a3b8;font-weight:400">mozilla.org</span>
          </a>
          <a href="https://www.apple.com/safari/" target="_blank" rel="noopener"
             style="display:flex;flex-direction:column;align-items:center;gap:.35rem;
                    background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;
                    padding:.75rem 1.25rem;text-decoration:none;color:#374151;
                    font-size:.8rem;font-weight:600;transition:border-color .1s;min-width:100px"
             onmouseover="this.style.borderColor='#0071e3'" onmouseout="this.style.borderColor='#e2e8f0'">
            <span style="font-size:2rem">🧭</span>
            <span>Safari</span>
            <span style="font-size:.68rem;color:#94a3b8;font-weight:400">apple.com</span>
          </a>
        </div>
      </div>

      <button id="bw-dismiss"
              style="width:100%;background:#f1f5f9;border:1.5px solid #d1d5db;border-radius:10px;
                     padding:.9rem 1rem;font-size:.9rem;font-weight:700;color:#374151;
                     cursor:pointer;transition:all .1s;letter-spacing:-.01em"
              onmouseover="this.style.background='#e5e7eb';this.style.borderColor='#9ca3af'" onmouseout="this.style.background='#f1f5f9';this.style.borderColor='#d1d5db'">
        Rozumiem ryzyko — kontynuuj mimo to →
      </button>
    </div>

  </div>
</div>
<style>
@keyframes _bwIn {
  from { opacity:0; transform:scale(.9) translateY(20px); }
  to   { opacity:1; transform:none; }
}
</style>
<script>
(function() {
  var KEY = 'bw_ok_v2';
  if (sessionStorage.getItem(KEY)) return;
  var ua = navigator.userAgent;
  var info = null; // { browser, msg }

  if (/Vivaldi/i.test(ua)) {
    info = {
      browser: 'Vivaldi',
      msg: 'Przeglądarka <strong>Vivaldi</strong> nie jest oficjalnie obsługiwana przez ten system. Mogą wystąpić problemy z logowaniem, formularzami i wyświetlaniem stron.'
    };
  } else if (/Trident\/|MSIE /i.test(ua)) {
    info = {
      browser: 'Internet Explorer',
      msg: '<strong>Internet Explorer</strong> nie jest obsługiwany i nie otrzymuje już aktualizacji bezpieczeństwa. System może nie działać w ogóle.'
    };
  } else if (/OPR\//i.test(ua)) {
    info = {
      browser: 'Opera',
      msg: 'Przeglądarka <strong>Opera</strong> może powodować problemy z niektórymi funkcjami systemu. Dla pewności użyj Firefox lub Safari.'
    };
  } else if (navigator.brave !== undefined) {
    info = {
      browser: 'Brave',
      msg: 'Przeglądarka <strong>Brave</strong> agresywnie blokuje zasoby, co może utrudniać pracę z systemem (blokowanie formularzy, skryptów, plików).'
    };
  } else if (/SamsungBrowser/i.test(ua)) {
    info = {
      browser: 'Samsung Browser',
      msg: 'Przeglądarka <strong>Samsung</strong> może nie obsługiwać wszystkich funkcji systemu. Dla najlepszego doświadczenia użyj Firefox lub Safari.'
    };
  }

  if (!info) return;

  var overlay = document.getElementById('browser-warn-overlay');
  document.getElementById('bw-browser-name').textContent = 'Wykryta: ' + info.browser;
  document.getElementById('bw-msg').innerHTML = info.msg;
  overlay.style.display = 'flex';

  document.getElementById('bw-dismiss').addEventListener('click', function() {
    overlay.style.display = 'none';
    sessionStorage.setItem(KEY, '1');
  });
})();
</script>

<!-- ══ Skip link ═══════════════════════════════════════════════════════════ -->
<a href="#login-main" class="skip-link">Przejdź do formularza logowania</a>

<!-- ══ Live region dla czytników ════════════════════════════════════════════ -->
<div role="status" aria-live="polite" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)" id="login-live"></div>
<div role="alert" aria-live="assertive" aria-atomic="true"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)" id="login-alert"></div>

<!-- ══ Baner: technologia asystująca ════════════════════════════════════════ -->
<div id="at-banner" role="region" aria-label="Informacja o dostępności"
     style="display:none;position:fixed;top:0;left:0;right:0;z-index:9999;
            background:#1e40af;color:#fff;padding:.65rem 1.25rem;
            font-size:.88rem;line-height:1.4;box-shadow:0 2px 8px rgba(0,0,0,.25)">
  <div style="max-width:860px;margin:0 auto;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
    <span style="font-size:1.2rem" aria-hidden="true">♿</span>
    <div style="flex:1;min-width:200px">
      <strong id="at-banner-title">Wykryto technologię asystującą</strong>
      <div id="at-banner-msg" style="opacity:.92;margin-top:.1rem">
        System SZO jest w pełni dostosowany do pracy z czytnikami ekranu JAWS, NVDA i VoiceOver —
        wszystkie elementy mają etykiety ARIA, kolejność fokusa i ogłoszenia na żywo.
      </div>
    </div>
    <button id="at-banner-close"
            style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.4);
                   color:#fff;border-radius:6px;padding:.3rem .8rem;cursor:pointer;
                   font-size:.82rem;white-space:nowrap;flex-shrink:0"
            aria-label="Zamknij powiadomienie o technologii asystującej">
      Rozumiem ✕
    </button>
  </div>
</div>

<!-- Przycisk ułatwień dostępu (zawsze widoczny w rogu) -->
<button id="at-toggle-btn"
        aria-label="Informacja o dostępności i technologiach asystujących"
        title="Dostępność — JAWS, NVDA, VoiceOver"
        style="position:fixed;bottom:1rem;left:1rem;z-index:9998;
               background:#1e40af;color:#fff;border:none;border-radius:50%;
               width:2.4rem;height:2.4rem;font-size:1.1rem;cursor:pointer;
               box-shadow:0 2px 8px rgba(0,0,0,.3);line-height:1;
               display:flex;align-items:center;justify-content:center"
        aria-pressed="false">
  ♿
</button>

<style>
@media (prefers-reduced-motion: no-preference) {
  #at-banner { transition: transform .2s ease; }
  #at-banner.at-hidden { transform: translateY(-100%); }
}
</style>

<script>
(function () {
  var STORAGE_KEY  = 'szo_at_acknowledged';
  var ACTIVE_KEY   = 'szo_at_active';
  var banner       = document.getElementById('at-banner');
  var closeBtn     = document.getElementById('at-banner-close');
  var toggleBtn    = document.getElementById('at-toggle-btn');
  var liveRegion   = document.getElementById('login-live');
  var titleEl      = document.getElementById('at-banner-title');
  var msgEl        = document.getElementById('at-banner-msg');

  /* ── Sygnały detekcji ────────────────────────────────────────────── */
  var mq = window.matchMedia;
  var signals = {
    forcedColors:   mq && mq('(forced-colors: active)').matches,       // Windows HC → JAWS/NVDA
    highContrastMS: mq && mq('(-ms-high-contrast: active)').matches,   // IE/Edge HC
    moreContrast:   mq && mq('(prefers-contrast: more)').matches,      // systemowe ułatwienia
    lessMotion:     mq && mq('(prefers-reduced-motion: reduce)').matches,
    isIOS: /iPhone|iPad|iPod/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1,
    isMacOS: /Macintosh/i.test(navigator.userAgent) && !('ontouchend' in document),
  };

  /* Etykieta wykrytego czytnika */
  function detectedLabel() {
    if (signals.forcedColors || signals.highContrastMS) {
      return { who: 'JAWS lub NVDA', hint: 'Wykryto Tryb wysokiego kontrastu Windows.' };
    }
    if (signals.isIOS) {
      return { who: 'VoiceOver (iOS)', hint: 'Wykryto urządzenie iOS.' };
    }
    if (signals.isMacOS && signals.lessMotion) {
      return { who: 'VoiceOver (macOS)', hint: 'Wykryto macOS z włączonymi ułatwieniami dostępu.' };
    }
    if (signals.moreContrast) {
      return { who: 'czytnik ekranu lub technologię asystującą', hint: 'Wykryto systemowe ustawienie wyższego kontrastu.' };
    }
    return null;
  }

  /* ── Pokaż/ukryj baner ───────────────────────────────────────────── */
  function showBanner(label, reason) {
    if (label) {
      titleEl.textContent = 'Wykryto: ' + label.who;
      if (reason) {
        msgEl.innerHTML = '<em style="opacity:.7;font-size:.8rem">' + reason + '</em><br>'
          + 'System SZO jest w pełni dostosowany do współpracy z czytnikami ekranu '
          + '— etykiety ARIA, zarządzanie fokusem, ogłoszenia na żywo.';
      }
    } else {
      titleEl.textContent = 'Tryb dostępności';
      msgEl.innerHTML = 'System SZO obsługuje czytniki ekranu JAWS, NVDA i VoiceOver. '
        + 'Wszystkie elementy mają etykiety ARIA i ogłoszenia na żywo.';
    }
    banner.style.display = 'block';
    toggleBtn.setAttribute('aria-pressed', 'true');
    /* Ogłoś przez live region po chwili (żeby strona zdążyła się załadować) */
    setTimeout(function () {
      if (liveRegion) liveRegion.textContent = titleEl.textContent + '. ' + msgEl.textContent;
    }, 600);
  }

  function hideBanner() {
    banner.style.display = 'none';
    toggleBtn.setAttribute('aria-pressed', 'false');
  }

  /* ── Init ────────────────────────────────────────────────────────── */
  var acknowledged = sessionStorage.getItem(STORAGE_KEY);
  var manualActive = localStorage.getItem(ACTIVE_KEY) === '1';
  var detected     = detectedLabel();

  if (manualActive) {
    showBanner(null);
  } else if (detected && !acknowledged) {
    showBanner(detected, detected.hint);
  }

  /* ── Zamknij ─────────────────────────────────────────────────────── */
  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      hideBanner();
      sessionStorage.setItem(STORAGE_KEY, '1');
      /* Nie czyść manualActive — tylko ukryj na tę sesję */
    });
  }

  /* ── Przycisk ♿ — toggle ─────────────────────────────────────────── */
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      var isVisible = banner.style.display !== 'none';
      if (isVisible) {
        hideBanner();
        localStorage.removeItem(ACTIVE_KEY);
        sessionStorage.setItem(STORAGE_KEY, '1');
      } else {
        localStorage.setItem(ACTIVE_KEY, '1');
        sessionStorage.removeItem(STORAGE_KEY);
        showBanner(detected);
      }
    });
  }

  /* ── Nasłuchuj zmiany trybu HC (np. user włącza HC podczas sesji) ─── */
  if (mq) {
    try {
      mq('(forced-colors: active)').addEventListener('change', function (e) {
        if (e.matches && !sessionStorage.getItem(STORAGE_KEY)) {
          signals.forcedColors = true;
          showBanner(detectedLabel());
        }
      });
    } catch (_) {}
  }
})();
</script>

<?php if ($_login_layout === 'simple'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     LAYOUT: SIMPLE — wyśrodkowana karta
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="login-simple-wrap">
  <main class="login-simple-box <?= $ms_available ? 'has-ms-split' : '' ?>" id="login-main" role="main" tabindex="-1">

    <!-- Kolorowy nagłówek z logo + nazwą org -->
    <div class="login-simple-header">
      <?php if ($_b['logo_url']): ?>
        <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" class="login-simple-logo">
      <?php else: ?>
        <div class="login-simple-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></div>
      <?php endif; ?>
      <h1 class="login-simple-orgname"><?= h($org_name) ?></h1>
      <?php if ($_login_tagline): ?>
        <p class="login-simple-tagline"><?= h($_login_tagline) ?></p>
      <?php endif; ?>
      <?php if ($sel_url): ?>
        <a href="<?= h($sel_url) ?>" class="login-simple-change">
          <i class="bi bi-arrow-left-circle" aria-hidden="true"></i>
          <?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?>
        </a>
      <?php endif; ?>
    </div>

    <!-- Formularz -->
    <div class="login-simple-body">
      <p class="form-sub" style="margin-bottom:1.25rem">
        <?php if ($ms_available): ?>
          Masz konto Microsoft organizacji? Użyj przycisku poniżej.
          Nie masz konta MS? Wpisz e-mail i hasło lub użyj kodu jednorazowego.
        <?php elseif (count($login_guide) > 1): ?>
          Wybierz metodę pasującą do Twojej roli — każda jest opisana poniżej.
        <?php else: ?>
          Wpisz swój adres e-mail i hasło, aby wejść do systemu.
        <?php endif; ?>
      </p>

      <!-- Komunikaty administratora (publiczne) — layout simple -->
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
            <strong style="font-size:.88rem"><?= h($_ln['title']) ?></strong>
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

    </div><!-- /login-simple-body -->
  </main>
</div><!-- /login-simple-wrap -->
<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     LAYOUT: SPLIT — lewa dekoracja + prawa forma
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="login-split" style="background: <?= h($_login_bg) ?>"><?php
// Tagline override (zamiast hardcoded "System Zarządzania...")
$_left_tagline = $_login_tagline ?: 'System Zarządzania<br>Organizacją i Wolontariatem';
?>

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
    <?php if ($_left_tagline): ?><div class="left-org-tagline"><?= $_left_tagline ?></div><?php endif; ?>

    <?php if ($sel_url): ?>
    <a href="<?= h($sel_url) ?>" class="left-change-org">
      <i class="bi bi-arrow-left-circle"></i>
      <?= $is_tenant ? 'Zmień organizację' : 'Wybierz organizację' ?>
    </a>
    <?php endif; ?>
  </div>

  <!-- Przewodnik metod logowania -->
  <?php if (count($login_guide) > 1): ?>
  <div class="left-guide" aria-hidden="true">
    <div class="left-guide-title">Jak się zalogować?</div>
    <?php foreach ($login_guide as $g): ?>
    <div class="left-guide-item">
      <span class="left-guide-icon" style="background:<?= $g['color'] ?>22;color:<?= $g['color'] ?>">
        <i class="bi <?= $g['icon'] ?>"></i>
      </span>
      <div>
        <div class="left-guide-who"><?= h($g['who']) ?></div>
        <div class="left-guide-how"><?= h($g['how']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Stopka -->
  <div class="left-footer">
    <?php
    $__v = '';
    try {
        require_once dirname(__DIR__) . '/includes/version.php';
        $__vd = app_version();
        $__v  = 'v' . $__vd['main'];
    } catch (\Throwable $e) {}
    ?>
    <?php if ($__v): ?>
    <span style="color:#fff;opacity:.45;font-size:.68rem;font-family:monospace"><?= h($__v) ?></span>
    <?php endif; ?>
  </div>

</aside>

<!-- ══ Prawa — treść, dostępna ═════════════════════════════════════════════ -->
<div class="login-right">
<main class="login-box <?= $ms_available ? 'has-ms-split' : '' ?>" id="login-main" role="main" tabindex="-1">

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

  <!-- Nagłówek -->
  <h1 class="form-heading" id="login-heading">Zaloguj się</h1>
  <p class="form-sub" id="login-sub" style="margin-bottom:1.5rem">
    <?php if ($ms_available): ?>
      Nie wiesz jak się zalogować? <strong>Sprawdź ściągawkę po lewej stronie.</strong>
    <?php elseif (count($login_guide) > 1): ?>
      Dostępnych jest kilka metod logowania — wybierz tę pasującą do Twojej roli.
    <?php else: ?>
      Wpisz swój adres e-mail i hasło, aby wejść do systemu.
    <?php endif; ?>
  </p>

  <!-- Komunikaty administratora (publiczne) -->
  <?php if ($_login_notices): ?>
  <div style="margin-bottom:1.25rem">
    <?php foreach ($_login_notices as $_ln):
      $ln_body   = nl2br(h($_ln['body']));
      $ln_pinned = (int)($_ln['is_pinned'] ?? 0);
      $ln_color  = $ln_pinned ? '#F59E0B' : '#2563EB';
      $ln_bg     = $ln_pinned ? '#FFFBEB' : '#EFF6FF';
    ?>
    <div style="background:<?= $ln_bg ?>;border-left:3px solid <?= $ln_color ?>;border-radius:8px;padding:.75rem 1rem;margin-bottom:.6rem;font-size:.84rem;color:#1e293b">
      <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.2rem;flex-wrap:wrap">
        <i class="bi bi-<?= $ln_pinned ? 'pin-angle-fill' : 'megaphone-fill' ?>" style="color:<?= $ln_color ?>"></i>
        <strong style="font-size:.88rem"><?= h($_ln['title']) ?></strong>
        <span style="font-size:.7rem;color:#94a3b8;margin-left:auto"><?= h(substr($_ln['created_at'] ?? '', 0, 10)) ?></span>
      </div>
      <?php if ($_ln['body']): ?>
      <div style="color:#374151;margin-top:.15rem;line-height:1.5"><?= $ln_body ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

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

  <?php include __DIR__ . '/_login_form_body.php'; ?>

</main>
</div><!-- /login-right -->
</div><!-- /login-split -->
<?php endif; // end layout split vs simple ?>

<script>
function switchAltTab(name) {
  // Ukryj wszystkie alternatywne sekcje
  ['code','sms'].forEach(function(k) {
    var s = document.getElementById('tab-' + k);
    if (s) s.hidden = true;
  });
  // Pokaż wybraną
  var section = document.getElementById('tab-' + name);
  if (section) {
    section.hidden = false;
    var first = section.querySelector('input:not([type=hidden])');
    if (first) setTimeout(function() { first.focus(); }, 60);
  }
  // Aktualizuj ARIA
  document.querySelectorAll('[role="tab"]').forEach(function(btn) {
    var sel = btn.id === 'tab-btn-' + name;
    btn.setAttribute('aria-selected', sel ? 'true' : 'false');
    btn.tabIndex = sel ? 0 : -1;
  });
  // Ogłoś zmianę
  var live = document.getElementById('login-live');
  var labels = {'code': 'Kod jednorazowy', 'sms': 'Kod SMS'};
  if (live) live.textContent = 'Metoda logowania: ' + (labels[name] || name);
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
    switchAltTab(next.id.replace('tab-btn-', ''));
  }
  if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
    e.preventDefault();
    var prev = tabs[(idx - 1 + tabs.length) % tabs.length];
    prev.focus();
    switchAltTab(prev.id.replace('tab-btn-', ''));
  }
  if (e.key === 'Home') { e.preventDefault(); tabs[0].focus(); switchAltTab(tabs[0].id.replace('tab-btn-','')); }
  if (e.key === 'End')  { e.preventDefault(); var l=tabs[tabs.length-1]; l.focus(); switchAltTab(l.id.replace('tab-btn-','')); }
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
