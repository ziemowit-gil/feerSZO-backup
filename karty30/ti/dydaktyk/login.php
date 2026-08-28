<?php
/**
 * karty30/ti/dydaktyk/login.php — Handler logowania dydaktyka (tylko POST).
 * GET → redirect do centralnego login.php (PRG).
 * POST → weryfikacja hasłem SZO, redirect do panelu lub z kodem błędu.
 * SSO (Microsoft 365) obsługuje office_enter.php przez auth/ms365.php.
 */
require_once __DIR__ . '/auth.php';

karty30_migrate();

// Już zalogowany → panel
if (dyd_current()) {
    header('Location: index.php'); exit;
}

// Office SSO odmowił dostępu (brak uprawnień dydaktyka)
if (($_GET['office'] ?? '') === 'denied') {
    header('Location: ../login.php?tab=dydaktyk&e=4'); exit;
}

// GET → centralna strona logowania
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php?tab=dydaktyk'); exit;
}

// POST — uwierzytelnianie hasłem
$email    = trim($_POST['email']    ?? '');
$password = $_POST['password'] ?? '';
// back=alt → błędy wracają na samodzielną stronę logowania dydaktyka (logowanie.php)
$back_alt = ($_POST['back'] ?? '') === 'alt';

$data = dyd_authenticate($email, $password);
if ($data) {
    dyd_login_user($data);
    try {
        db()->prepare("UPDATE users SET last_login=datetime('now') WHERE id=?")
            ->execute([$data['user_id']]);
    } catch (\Throwable $e) {}
    header('Location: index.php'); exit;
}

// Próba odróżnienia: złe hasło (kod 1) vs brak uprawnień dydaktyka (kod 4)
$u_row = db_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
$ec = ($u_row && !empty($u_row['password']) && password_verify($password, $u_row['password'])) ? 4 : 1;

header('Location: ' . ($back_alt ? 'logowanie.php?e=' : '../login.php?tab=dydaktyk&e=')
    . $ec . '&m=' . urlencode($email)); exit;
