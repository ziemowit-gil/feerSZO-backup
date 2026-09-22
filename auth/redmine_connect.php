<?php
/**
 * auth/redmine_connect.php — start połączenia OAuth użytkownika z Redmine.
 * Przekierowuje do ekranu autoryzacji Redmine (Doorkeeper).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/redmine.php';

require_login();

if (!redmine_oauth_configured()) {
    flash_set('danger', 'Integracja OAuth z Redmine nie jest skonfigurowana.');
    header('Location: ' . APP_URL . '/auth/redmine_account.php');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['redmine_oauth_state'] = $state;
header('Location: ' . redmine_oauth_authorize_url($state));
exit;
