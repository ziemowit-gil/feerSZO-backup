<?php
/**
 * helpdesk/intro_dismiss.php — Odrzuca baner powitalny Helpdesku (1. logowanie).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    helpdesk_intro_mark_seen((int)(current_user()['id'] ?? 0));
}

// Wróć tylko na ścieżkę względną w obrębie aplikacji — bez otwartego przekierowania.
$return = (string)($_POST['return'] ?? '/index.php');
if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
    $return = '/index.php';
}

header('Location: ' . APP_URL . $return);
exit;
