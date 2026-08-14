<?php
/**
 * karty30/ti/kursant/login.php — Handler logowania kursanta (tylko POST).
 * GET → redirect do centralnego login.php (PRG).
 * POST → weryfikacja, redirect do panelu lub z kodem błędu.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/pfron.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

// Już zalogowany → panel
if (student_current()) {
    header('Location: index.php'); exit;
}

// GET → centralna strona logowania
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php?tab=kursant'); exit;
}

// POST — uwierzytelnianie kursanta
$login    = trim($_POST['login']    ?? '');
$password = $_POST['password'] ?? '';
$ip_log   = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

$account = db_one(
    "SELECT * FROM k30_ti_student_accounts
     WHERE (login=? OR (login_alias!='' AND login_alias=?)) AND is_active=1",
    [$login, $login]
);

if ($account && password_verify($password, $account['password_hash'])) {
    if (!empty($account['child_access_blocked'])) {
        // Kod 2: dostęp wstrzymany przez opiekuna
        header('Location: ../login.php?tab=kursant&e=2'); exit;
    }
    student_login_user($account, 'password', "login: {$login}");
    header('Location: index.php'); exit;
}

// Nieudana próba — zaloguj jeśli konto istnieje
if ($account && function_exists('ti_account_log')) {
    ti_account_log((int)$account['id'], 'login_failed', "Nieudana próba dla: {$login} | IP: {$ip_log}");
}

// Kod 1: nieprawidłowy login lub hasło; prefill login w formularzu
header('Location: ../login.php?tab=kursant&e=1&l=' . urlencode($login)); exit;
