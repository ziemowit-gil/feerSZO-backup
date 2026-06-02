<?php
/**
 * Jednorazowe logowanie SaaS → CRM (SSO token).
 * Admin panelu SaaS trafia bezpośrednio na dashboard CRM bez widoku systemu głównego.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (!defined('TENANT_SLUG')) {
    http_response_code(403);
    die('Logowanie SaaS-CRM niedostępne w trybie standalone.');
}

$token = trim($_GET['token'] ?? '');
if (!$token) {
    header('Location: ' . APP_URL . '/auth/login.php'); exit;
}

$row = db_one("SELECT value FROM settings WHERE key_='_saas_crm_token'");
db()->exec("DELETE FROM settings WHERE key_='_saas_crm_token'");

if (!$row) {
    header('Location: ' . APP_URL . '/auth/login.php?err=token'); exit;
}

$parts = explode('|', $row['value']);
if (count($parts) !== 3) {
    header('Location: ' . APP_URL . '/auth/login.php?err=token'); exit;
}
[$stored_token, $uid, $expires] = $parts;

if (!hash_equals($stored_token, $token) || strtotime($expires) < time()) {
    header('Location: ' . APP_URL . '/auth/login.php?err=expired'); exit;
}

$user = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [(int)$uid]);
if (!$user) {
    header('Location: ' . APP_URL . '/auth/login.php?err=user'); exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role']  = $user['role'];
$_SESSION['user_name']  = $user['name'];

header('Location: ' . APP_URL . '/crm/dashboard.php');
exit;
