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

$raw_redirect = $_GET['redirect'] ?? '';
$redirect = ($raw_redirect && str_starts_with($raw_redirect, APP_URL . '/'))
    ? $raw_redirect : '';

// Tenant CRM-standalone — cały ruch kieruj do CRM. Logowanie jest tutaj (ekran SZO), bo crm/login.php
// od 14.11.2026 nie ma własnego logowania Microsoft 365 — tylko przekierowuje na ten ekran.
if (defined('CRM_STANDALONE') && CRM_STANDALONE) {
    if (current_user()) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
    if ($redirect === '') $redirect = APP_URL . '/crm/dashboard.php';
}

// Już zalogowany: wejście z ?redirect= (np. z CRM) wraca tam, a nie na ekran startowy
if (current_user()) { header('Location: ' . ($redirect !== '' ? $redirect : auth_default_landing())); exit; }

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

// Logowanie jednokrotne (Logto, OpenID Connect) — modules/sso_logto/lib/LogtoOidc.php; od 13.11.2026 główna metoda obok e-maila,
// Microsoft 365 tylko dla administratorów (account_is_office_only).
$logto_available = false;
try { require_once dirname(__DIR__) . '/modules/sso_logto/lib/LogtoOidc.php'; $logto_available = LogtoOidc::enabled(); } catch (\Throwable $e) {}

// ── State ─────────────────────────────────────────────────────────────────
$default_tab = $ms_available ? 'ms365' : 'local';
$active_tab = $_GET['tab'] ?? $default_tab;
$sms_step   = 1;
$sms_phone  = '';
$error      = '';
$info       = '';
// Błąd z logowania przez Logto (SsoController::loginFail) — pokazujemy raz, w zwykłym miejscu komunikatów.
if (!empty($_SESSION['login_notice_error'])) { $error = (string)$_SESSION['login_notice_error']; unset($_SESSION['login_notice_error']); }

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
                $error = 'Konto administratora @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office). Użyj przycisku „Microsoft 365 — administratorzy”.';
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
            $error = 'Konto administratora @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
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
                $error = 'Konto administratora @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
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
            $error = 'Konto administratora @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
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
            $error      = 'Konto administratora @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).';
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

// ── Widok: karta logowania — logowanie jednokrotne + e-mail/hasło, inne metody schowane; modale metod alternatywnych z _login_modals.php.
ob_start();
require __DIR__ . '/_login_modals.php';
$_modals_html = ob_get_clean();
require_once dirname(__DIR__) . '/includes/auth_screen.php';
$_b = branding_load();
$_org = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_ln_show = null;
foreach ($_login_notices as $_ln_item) { if ($_ln_item['is_pinned'] ?? 0) { $_ln_show = $_ln_item; break; } }
if (!$_ln_show && !empty($_login_notices)) $_ln_show = $_login_notices[0];
$_sso_hint = $_SESSION['sso_hint'] ?? null;
if ($_sso_hint && time() - (int)($_sso_hint['at'] ?? 0) > 900) { unset($_SESSION['sso_hint']); $_sso_hint = null; }
$has_alt = ($code_available || $sms_available || $x509_available);
// Przebudowa 2026-11-14 („przebuduj widok”): Microsoft 365 (tylko administratorzy) schowany w „Inne metody logowania”, kafle „Pierwszy raz w SZO?”,
// „Załóż konto” tylko przy otwartej rejestracji (PIN), odnośnik do instrukcji w Portalu Usług IT.
$_reg_open = function_exists('register_is_open') && register_is_open();
$_help_url = APP_URL . '/uslugi-it/uslugi/moj-login/logowanie-do-szo';
$_show_ms = $ms_available && !$emergency;
$_show_logto = $logto_available && !$emergency;
$_logto_url = APP_URL . '/sso/logowanie' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : '');
// Druga kolumna: „Odchodzimy od Microsoft 365” — powody, prośba o logowanie do IAM, data wyłączenia (modules/sso_logto/view/loginInfo.php).
$LOGIN_INFO = ['panel' => '', 'mini' => ''];
try { require dirname(__DIR__) . '/modules/sso_logto/view/loginInfo.php'; }
catch (\Throwable $e) { $LOGIN_INFO = ['panel' => '', 'mini' => '']; error_log('[login] kolumna informacji: ' . $e->getMessage()); }
$_two = $LOGIN_INFO['panel'] !== '';
// Błąd formularza e-mail/hasło stoi przy polach (w formularzu); inne błędy (np. nieudane logowanie jednokrotne) nad przyciskami.
$_err_form = $error !== '' && $active_tab === 'local' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$_err_top  = $error !== '' && !$_err_form && !in_array($active_tab, ['code', 'sms', 'x509'], true);
$_err_attr = $_err_form ? ' aria-invalid="true"' : '';
$_err_ids  = $_err_form ? ' login-error-text' : '';

// ── Widok (przebudowa 2026-11-14, jasny wygląd ekranów wejścia): powłoka includes/auth_screen.php w trybie 'light'
// (inc/loginShell/authUi.php), druga kolumna „Odchodzimy od Microsoft 365” obok karty. Logika powyżej bez zmian.
$_login_css = <<<'CSS'
<style>
.lg-cpl{margin:-1rem 0 1.5rem;font-size:.9rem;color:var(--au-ink2)}
.lg-cpl a{font-weight:600}
.lg-sso-h{margin:.45rem 0 0;font-size:.875rem;text-align:center;color:var(--au-muted)}
.lg-first{margin:1.75rem 0 0;padding-top:1.25rem;border-top:1px solid var(--au-line)}
.lg-first-h{margin:0 0 .75rem;font-size:1.05rem;font-weight:700;color:var(--au-ink)}
.au .lg-first .lg-new{grid-template-columns:1fr;margin:0}
.lg-first-pin{margin:.8rem 0 0;font-size:.9rem;color:var(--au-ink2)}
.lg-first-pin a{font-weight:600}
.lg-more{margin:1.5rem 0 0;padding-top:.75rem;border-top:1px solid var(--au-line)}
.lg-more summary{display:flex;align-items:center;min-height:44px;font-weight:600;color:var(--au-acc);cursor:pointer}
.lg-alt{display:grid;gap:.5rem;margin-top:.5rem}
.au .lg-alt a,.au .lg-alt button{display:flex;align-items:center;gap:.6rem;width:100%;min-height:48px;padding:.6rem .85rem;
  border:1px solid var(--au-line2);border-radius:var(--au-rs);background:#fff;color:var(--au-ink);font:inherit;font-size:.95rem;
  text-align:left;text-decoration:none;cursor:pointer}
.au .lg-alt a:hover,.au .lg-alt button:hover{border-color:var(--au-acc);background:#f9fafb}
.lg-alt i{font-size:1.1rem;color:var(--au-acc)}
/* Kolumna „Odchodzimy od Microsoft 365” (modules/sso_logto/view/loginInfo.php). #a33b00 na białym = 6,6:1 */
.lg-info{padding:1.6rem 1.75rem;background:#fff;border:1px solid var(--au-line);border-radius:var(--au-r);font-size:.95rem;line-height:1.55;color:var(--au-ink)}
.lg-info-kicker{margin:0 0 .25rem;font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#a33b00}
.lg-info-h{margin:0 0 .5rem;font-size:1.35rem;font-weight:700;color:var(--au-ink)}
.lg-info-lead{margin:0;color:var(--au-ink2)}
.lg-info-h3{margin:1.25rem 0 .4rem;font-size:1rem;font-weight:700;color:var(--au-ink)}
.lg-info-steps,.lg-info-list{margin:.4rem 0 0;padding-left:1.25rem}
.lg-info-steps li,.lg-info-list li{margin:0 0 .4rem}
.lg-info-box{display:flex;gap:.6rem;align-items:flex-start;margin:1rem 0 0;padding:.75rem .9rem;border-left:4px solid var(--au-acc);border-radius:var(--au-rs);background:var(--au-acc-soft)}
.lg-info-box>i{font-size:1.1rem;line-height:1.4;color:var(--au-acc)}
.lg-info-box--warn{background:var(--au-warn-bg);border-left-color:var(--au-warn);color:var(--au-warn-ink)}
.lg-info-box--warn>i{color:var(--au-warn-ink)}
.lg-info-small{display:block;margin:.6rem 0 0;font-size:.875rem;color:var(--au-ink2)}
.lg-info-help{margin:1rem 0 0;font-size:.9rem;color:var(--au-ink2)}
.lg-info a{font-weight:600;color:var(--au-acc);text-decoration:underline;text-underline-offset:2px}
.lg-info-why{margin:1.1rem 0 0;padding-top:.5rem;border-top:1px solid var(--au-line)}
.lg-info-why summary{display:flex;align-items:center;min-height:44px;font-weight:700;color:var(--au-acc);cursor:pointer}
@media(min-width:900px){.lg-info-mini{display:none!important}}
@media(max-width:520px){.lg-info{padding:1.25rem}}
</style>
CSS;

auth_screen_head([
    'title'           => 'Logowanie',
    'light'           => true,
    'width'           => 480,
    'bootstrap'       => true,
    'main_id'         => 'login-main',
    'account_buttons' => [],
    'aside_html'      => $_two ? $LOGIN_INFO['panel'] : '',
    'head_html'       => $_login_css,
]);
?>
    <h1 class="ks-h1"><?= $_login_tagline ? h($_login_tagline) : 'Zaloguj się' ?></h1>
    <p class="ks-lead"><?= $_login_welcome_is_custom ? nl2br(h($_login_welcome)) : 'System Wspomagania Zarządzania Organizacją' ?></p>
    <p class="lg-cpl">Logujesz się przez CPL, Centralny Portal Logowania Fundacji. Inny profil wybierzesz w <a href="<?= h(APP_URL) ?>/tozsamosc/cpl.php">pełnym oknie CPL</a>.</p>

    <?php if ($_ln_show): ?><div class="l-alert l-alert-info" role="region" aria-label="Komunikat"><i class="bi bi-megaphone" aria-hidden="true"></i><div><strong><?= h($_ln_show['title']) ?></strong><?php if ($_ln_show['body']): ?><br><?= nl2br(h($_ln_show['body'])) ?><?php endif; ?></div></div><?php endif; ?>
    <?php if ($_sso_hint): ?><div class="l-alert l-alert-info" role="alert" id="sso-hint"><i class="bi bi-shield-check" aria-hidden="true"></i><div>
      <?php if (!empty($_sso_hint['reauth'])): ?><strong>Wymagane ponowne logowanie.</strong> <?php endif; ?>
      Logujesz się, aby otworzyć <?= ($_sso_hint['app'] ?? '') !== '' ? 'aplikację: <strong>' . h($_sso_hint['app']) . '</strong>' : 'zewnętrzną aplikację' ?>.
      Jeśli nie otwierasz żadnej aplikacji, nie wpisuj hasła.</div></div><?php endif; ?>
    <?php if (isset($_GET['ended'])): ?><div class="l-alert l-alert-success" role="status"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Sesja zakończona — zaloguj się ponownie.</span></div><?php endif; ?>
    <?php if ($_err_top): ?><div class="l-alert l-alert-danger" role="alert" id="login-error-box"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span id="login-error-text"><?= h($error) ?></span></div><?php endif; ?>
    <?php if ($emergency): ?><div class="l-alert l-alert-warn" role="note"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Logowanie awaryjne — użyj adresu e-mail i <strong>hasła awaryjnego</strong>.</span></div><?php endif; ?>
    <?php if ($_two) echo $LOGIN_INFO['mini']; /* skrót informacji — tylko na wąskim ekranie (< 900 px), pełna treść pod kartą */ ?>

    <?php if ($_show_logto): ?>
    <a class="ks-btn ks-btn--primary" href="<?= h($_logto_url) ?>"><i class="bi bi-key" aria-hidden="true"></i> Zaloguj przez logowanie jednokrotne</a>
    <p class="lg-sso-h">Jedno konto Fundacji, to samo co do chmury plików</p>
    <div class="ks-or">albo e-mail i hasło</div>
    <?php endif; ?>

    <form method="post" id="login-form" novalidate autocomplete="on" aria-label="Logowanie e-mailem i hasłem">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_method" value="local">
      <?php if ($_err_form): ?><div class="l-alert l-alert-danger" role="alert" id="login-error-box"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span id="login-error-text"><?= h($error) ?></span></div><?php endif; ?>
      <div class="ks-field">
        <label for="f-email">Adres e-mail</label>
        <input type="email" name="email" id="f-email" class="form-control" autocomplete="email" inputmode="email" required
               aria-describedby="f-email-err<?= $_err_ids ?>"<?= $_err_attr ?><?= (!$_show_logto || $_err_form) ? ' autofocus' : '' ?>>
        <p class="au-ferr" id="f-email-err" role="alert"></p>
      </div>
      <div class="ks-field">
        <label for="f-pass">Hasło</label>
        <div class="pass-wrap">
          <input type="password" name="password" id="f-pass" class="form-control" autocomplete="current-password" required
                 aria-describedby="f-pass-err<?= $_err_ids ?>"<?= $_err_attr ?>>
          <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" aria-controls="f-pass" onclick="togglePass('f-pass', this)"><i class="bi bi-eye" aria-hidden="true"></i></button>
        </div>
        <p class="au-ferr" id="f-pass-err" role="alert"></p>
      </div>
      <button type="submit" class="ks-btn ks-btn--primary">Zaloguj</button>
    </form>
    <div class="lg-links"><a href="<?= APP_URL ?>/user/verify_reset.php">Nie pamiętasz hasła?</a><a href="<?= h($_help_url) ?>">Instrukcja logowania</a></div>

    <section class="lg-first" aria-labelledby="lg-first-h">
      <h2 id="lg-first-h" class="lg-first-h">Pierwszy raz w SZO?</h2>
      <nav class="lg-new" aria-label="Pierwsze logowanie">
        <a href="<?= APP_URL ?>/user/verify_reset.php"><i class="bi bi-person-plus" aria-hidden="true"></i><span>Nowy wolontariusz<strong>Aktywuj konto</strong><em>Dane z umowy i kod SMS</em></span></a>
        <?php if ($code_available): ?>
        <button type="button" data-bs-toggle="modal" data-bs-target="#modal-code"><i class="bi bi-key" aria-hidden="true"></i><span>Masz kod od koordynatora?<strong>Kod aktywacyjny</strong><em>Kod działa tylko raz</em></span></button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/zlecenie"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>Nowy zleceniobiorca<strong>Oświadczenia</strong><em>Tylko przy umowie zlecenie</em></span></a>
      </nav>
      <?php if ($_reg_open): ?><p class="lg-first-pin">Masz PIN rejestracji od administratora? <a href="<?= APP_URL ?>/user/register.php">Załóż konto</a>.</p><?php endif; ?>
    </section>

    <?php if ($has_alt || $_show_ms): ?>
    <details class="lg-more"<?= in_array($active_tab, ['code','sms','x509'], true) ? ' open' : '' ?>>
      <summary>Inne metody logowania i administratorzy</summary>
      <div class="lg-alt">
        <?php if ($_show_ms): ?><a href="<?= h($_ms_url) ?>"><i class="bi bi-microsoft" aria-hidden="true"></i> Microsoft 365, tylko administratorzy @feer.org.pl</a><?php endif; ?>
        <?php if ($code_available): ?><button type="button" data-bs-toggle="modal" data-bs-target="#modal-code"><i class="bi bi-key" aria-hidden="true"></i> Kod jednorazowy</button><?php endif; ?>
        <?php if ($sms_available): ?><button type="button" data-bs-toggle="modal" data-bs-target="#modal-sms"><i class="bi bi-phone" aria-hidden="true"></i> Kod SMS</button><?php endif; ?>
        <?php if ($x509_available): ?><button type="button" data-bs-toggle="modal" data-bs-target="#modal-x509"><i class="bi bi-patch-check" aria-hidden="true"></i> Certyfikat X.509 (administratorzy)</button><?php endif; ?>
      </div>
    </details>
    <?php endif; ?>
<?php
// Bootstrap (okna metod alternatywnych) ładuje się po extra_js — stąd DOMContentLoaded.
$_login_js = '<script>
document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(".modal").forEach(function (m) {
    m.addEventListener("shown.bs.modal", function () { var f = m.querySelector("input:not([type=hidden])"); if (f) f.focus(); });
  });
  var a = ' . json_encode(in_array($active_tab, ['code', 'sms', 'x509'], true) ? $active_tab : null) . ';
  if (a && window.bootstrap) { var el = document.getElementById("modal-" + a); if (el) bootstrap.Modal.getOrCreateInstance(el).show(); }
  // Puste pole: komunikat przy polu (role=alert) zamiast wysyłania pustego formularza.
  var form = document.getElementById("login-form"); if (!form) return;
  function fieldErr(inp, msg) {
    var box = document.getElementById(inp.id + "-err"); if (!box) return;
    box.innerHTML = msg ? "<i class=\"bi bi-exclamation-circle-fill\" aria-hidden=\"true\"></i><span></span>" : "";
    if (msg) { box.lastChild.textContent = msg; inp.setAttribute("aria-invalid", "true"); }
  }
  var em = document.getElementById("f-email"), pw = document.getElementById("f-pass");
  [em, pw].forEach(function (i) { i.addEventListener("input", function () { if (i.value) fieldErr(i, ""); }); });
  form.addEventListener("submit", function (ev) {
    var noE = !em.value.trim(), noP = !pw.value;
    fieldErr(em, noE ? "Wpisz adres e-mail." : "");
    fieldErr(pw, noP ? "Wpisz hasło." : "");
    if (noE || noP) { ev.preventDefault(); (noE ? em : pw).focus(); }
  });
});
</script>';

auth_screen_foot([
    'no_new_tiles' => true,
    'extra_html'   => $_modals_html,
    'extra_js'     => $_login_js,
]);
