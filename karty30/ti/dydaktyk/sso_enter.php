<?php
/**
 * karty30/ti/dydaktyk/sso_enter.php — wejście do panelu dydaktyka przez logowanie jednokrotne (Logto).
 *
 * Polecenie 2026-11-14 „pozwól już na jednokrotne albo logowanie kursanta”: na stronie logowania ti.feer.org.pl kadra
 * (prowadzący, konsultant, kierownik) wchodzi przyciskiem „Zaloguj przez logowanie jednokrotne”, a uczestnik, rodzic
 * i osoba upoważniona swoim loginem. Przycisk prowadzi tutaj (TiLogin::ssoUrl()).
 *
 * Przebieg:
 *  1. Bez znacznika i bez sesji SZO → /sso/logowanie?redirect=<ten plik> (strona logowania Logto).
 *  2. SsoController::loginCallback rozpoznaje ten mostek: NIE zakłada sesji SZO (konta dydaktyk_ti nie mają wstępu do SZO),
 *     tylko zapisuje w sesji SZO jednorazowy znacznik sso_dyd = [uid, at] i wraca tutaj. Błędy wracają jako ?blad=konto|admin.
 *  3. Mostek zdejmuje znacznik (ważny 5 minut), sprawdza konto i uprawnienia dydaktyka (dyd_profile_from_user) i odkłada
 *     profil na obowiązkowy kod TOTP (totp_gate.php), tak samo jak logowanie Microsoft 365 w office_enter.php.
 * Osoba zalogowana już do SZO (dowolną metodą) wchodzi od razu, jak w office_enter.php.
 * Administratorzy (konta tylko Microsoft 365) logują się przez Microsoft 365, nie tędy.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_login/logic/TiLogin.php';
require_once dirname(__DIR__, 3) . '/modules/sso_logto/lib/SsoConfig.php';
require_once dirname(__DIR__, 3) . '/modules/sso_logto/lib/LogtoOidc.php';

karty30_migrate();

/** Powrót na stronę logowania TI z kodem błędu (karty30/ti/login.php: $ERR). */
function sso_enter_fail(int $code): void
{
    header('Location: ' . TiLogin::url(['e' => $code]), true, 303);
    exit;
}

$blad = (string)($_GET['blad'] ?? '');
if ($blad !== '') sso_enter_fail($blad === 'admin' ? 9 : 7);

// 1) Jednorazowy znacznik z powrotu Logto albo trwająca sesja SZO.
auth_start();
$st = $_SESSION['sso_dyd'] ?? null;
unset($_SESSION['sso_dyd']);
$uid = 0;
$method = 'sso';
if (is_array($st) && (time() - (int)($st['at'] ?? 0)) <= 300) $uid = (int)($st['uid'] ?? 0);
if (!$uid) {
    $su = current_user();
    $uid = $su ? (int)($su['id'] ?? 0) : 0;
    $method = 'szo';
}
session_write_close();

if (!$uid) {
    if (!LogtoOidc::enabled()) sso_enter_fail(6);
    $self = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/sso_enter.php';
    header('Location: ' . rtrim(APP_URL, '/') . '/sso/logowanie?redirect=' . urlencode($self), true, 303);
    exit;
}

// 2) Konto i uprawnienia dydaktyka.
$u = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
if (!$u || empty($u['is_active'])) sso_enter_fail(7);
if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) sso_enter_fail(7);
$data = dyd_profile_from_user($u);
if (!$data) sso_enter_fail(8);

// Skok na wskazany ekran panelu (ciasteczko powrotu, np. plan zajęć): tylko bezpieczne cele (dyd_safe_next()).
$target = dyd_safe_next((string)($_GET['next'] ?? ($_COOKIE['k30_dyd_next'] ?? '')));
if (isset($_COOKIE['k30_dyd_next'])) setcookie('k30_dyd_next', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

// Panel jest już otwarty na tym samym koncie: bez nowej sesji (nowy token CSRF zepsułby formularze w innych kartach).
$open = dyd_current();
if ($open && (int)($open['user_id'] ?? 0) === (int)$data['user_id']) {
    header('Location: ' . $target);
    exit;
}

// 3) Obowiązkowy kod TOTP, potem sesja panelu (totp_gate.php).
try { db()->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([(int)$data['user_id']]); } catch (\Throwable $e) {}
$data['login_method'] = $method;   // dziennik logowań panelu (dyd_login_log): sso | szo
dyd_2fa_stash($data, $target);
header('Location: totp_gate.php');
exit;
