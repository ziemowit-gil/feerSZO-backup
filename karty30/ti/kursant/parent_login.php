<?php
/**
 * karty30/ti/kursant/parent_login.php — Handler logowania rodzica/opiekuna hasłem (tylko POST).
 * GET → redirect do centralnego login.php?tab=rodzic (PRG).
 * POST → weryfikacja login+hasło, redirect do parent.php lub z kodem błędu.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

if (parent_current()) {
    header('Location: parent.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php?tab=rodzic'); exit;
}

$login = trim($_POST['login'] ?? '');
$pass  = $_POST['password'] ?? '';

if (parent_login_with_password($login, $pass)) {
    header('Location: parent.php'); exit;
}

header('Location: ../login.php?tab=rodzic&e=6&l=' . urlencode($login)); exit;
