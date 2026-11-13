<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
// Sesja z logowania jednokrotnego (Logto, modules/sso_logto/lib/LogtoOidc.php) — po wylogowaniu z SZO kończymy też sesję
// w Logto, inaczej kolejna osoba na tym samym komputerze weszłaby jednym kliknięciem „Zaloguj przez logowanie jednokrotne”.
auth_start();
$logto_logout = (string)($_SESSION['logto_logout'] ?? '');
logout_user();
if ($logto_logout !== '' && str_starts_with($logto_logout, 'https://')) { header('Location: ' . $logto_logout); exit; }
header('Location: ' . APP_URL . '/auth/login.php');
exit;
