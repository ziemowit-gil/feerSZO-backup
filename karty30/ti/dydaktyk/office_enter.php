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

// 3) Logowanie Office poprawne — dwuetapowe uwierzytelnianie (TOTP) jest
// obowiązkowe dla każdego konta dydaktyka, tak samo jak przy haśle.
// Sesję panelu zakłada dopiero totp_gate.php, po weryfikacji kodu.
dyd_2fa_stash($data);
try { db()->prepare("UPDATE users SET last_login=datetime('now') WHERE id=?")->execute([$data['user_id']]); } catch (\Throwable $e) {}
header('Location: totp_gate.php');
exit;
