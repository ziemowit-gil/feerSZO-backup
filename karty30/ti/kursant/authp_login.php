<?php
/**
 * karty30/ti/kursant/authp_login.php — Handler logowania osoby upoważnionej (tylko POST).
 * GET → redirect do centralnego login.php?tab=up (PRG).
 * POST → weryfikacja login+hasło, redirect do authorized_person.php lub z kodem błędu.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

if (authp_current()) {
    header('Location: authorized_person.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php?tab=up'); exit;
}

$login = trim($_POST['login'] ?? '');
$pass  = $_POST['password'] ?? '';

if (authp_login($login, $pass)) {
    header('Location: authorized_person.php'); exit;
}

header('Location: ../login.php?tab=up&e=7&l=' . urlencode($login)); exit;
