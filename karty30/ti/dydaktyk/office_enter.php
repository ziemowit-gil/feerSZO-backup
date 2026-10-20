<?php
/**
 * karty30/ti/dydaktyk/office_enter.php — wejście do panelu dydaktyka po Office.
 *
 * Logowanie Microsoft 365 korzysta ze wspólnego callbacku aplikacji
 * (auth/microsoft.php, stały redirect_uri w Azure). Po zalogowaniu MS365
 * użytkownik trafia tu z aktywną sesją GŁÓWNĄ SZO. Ten mostek odczytuje
 * konto z sesji SZO, sprawdza uprawnienia dydaktyka i zakłada ODDZIELNĄ sesję
 * panelu dydaktyka (k30_dydaktyk), po czym przekierowuje do panelu.
 *
 * Start logowania: link na login.php →
 *   <APP_URL>/auth/ms365.php?redirect=<APP_URL>/karty30/ti/dydaktyk/office_enter.php
 */
require_once __DIR__ . '/auth.php';

karty30_migrate();

// 1) Odczyt konta z sesji głównej SZO (osobna nazwa sesji: umowy_*).
auth_start();
$su = current_user();
$uid = $su ? (int)($su['id'] ?? 0) : 0;
// Czy sesję główną założył dopiero co mostek Office (a nie wcześniejsze logowanie)?
$via_bridge = !empty($_SESSION['ms_dyd_bridge']);
unset($_SESSION['ms_dyd_bridge']); // znacznik jednorazowy

// Domknij sesję główną, by móc otworzyć osobną sesję panelu dydaktyka
// (inna nazwa ciasteczka — nie kolidują).
session_write_close();

// Nie zalogowany w SZO → zacznij logowanie Office i wróć tutaj.
if (!$uid) {
    $self = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/office_enter.php';
    header('Location: ' . rtrim(APP_URL, '/') . '/auth/ms365.php?redirect=' . urlencode($self));
    exit;
}

// 2) Pobierz pełny wiersz konta i zweryfikuj uprawnienia dydaktyka.
$u    = db_one("SELECT * FROM users WHERE id=?", [$uid]);
$data = $u ? dyd_profile_from_user($u) : null;

if (!$data) {
    // Zalogowany w SZO, ale bez uprawnień dydaktyka TI.
    // Mostek dydaktyka jest jedynym wyjątkiem od reguły „Office tylko dla
    // administracji/koordynatorów" (auth/microsoft.php). Gdyby ktoś spoza tej
    // grupy (nie office-only) wszedł tędy, by obejść blokadę — wycofaj sesję
    // główną SZO, ale TYLKO jeśli założył ją dopiero ten mostek (znacznik
    // ms_dyd_bridge). Wcześniej zalogowanego użytkownika nie wylogowujemy.
    if ($via_bridge && $u && !account_is_office_only($u)) {
        auth_start();
        $_SESSION = [];
        session_destroy();
    }
    header('Location: login.php?office=denied'); exit;
}

// Opcjonalny skok na wskazany ekran panelu (np. ze starych ekranów karty30 albo z launchera): tylko istniejący plik
// .php z tego katalogu + prosty query (dyd_safe_next()). Konta z TOTP trafiają tam dopiero po kodzie (totp_gate.php).
$target = dyd_safe_next((string)($_GET['next'] ?? ($_COOKIE['k30_dyd_next'] ?? '')));   // ciasteczko: powrót na plan zajęć
if (isset($_COOKIE['k30_dyd_next'])) setcookie('k30_dyd_next', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

// Panel jest już otwarty na tym samym koncie (drugi link z SZO, launcher, stara zakładka): sesji nie zakładamy od nowa —
// nowy token CSRF unieważniłby formularze otwarte w innych kartach panelu, a TOTP był już sprawdzony.
$open = dyd_current();
if ($open && (int)($open['user_id'] ?? 0) === (int)$data['user_id']) {
    header('Location: ' . $target);
    exit;
}

// 3) Logowanie Office poprawne.
try { db()->prepare("UPDATE users SET last_login=datetime('now') WHERE id=?")->execute([$data['user_id']]); } catch (\Throwable $e) {}

// Każde konto (także administrator): dwuetapowe uwierzytelnianie (TOTP) jest obowiązkowe, tak
// samo jak przy haśle. Sesję panelu zakłada dopiero totp_gate.php, po
// weryfikacji kodu.
$data['login_method'] = 'ms365';   // dziennik logowań panelu (dyd_login_log)
dyd_2fa_stash($data, $target);
header('Location: totp_gate.php');
exit;
