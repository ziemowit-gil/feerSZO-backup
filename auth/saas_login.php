<?php
/**
 * Jednorazowe logowanie z panelu SaaS (SSO token).
 * Dostępne tylko w trybie multi-tenant (TENANT_SLUG zdefiniowane).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

if (!defined('TENANT_SLUG')) {
    http_response_code(403);
    die('Logowanie SaaS niedostępne w trybie standalone.');
}

$token = trim($_GET['token'] ?? '');
if (!$token) {
    header('Location: ' . APP_URL . '/auth/login.php'); exit;
}

$row = db_one("SELECT value FROM settings WHERE key_='_saas_token'");

if (!$row) {
    header('Location: ' . APP_URL . '/auth/login.php?err=token'); exit;
}

$parts = explode('|', $row['value']);
if (count($parts) !== 3) {
    header('Location: ' . APP_URL . '/auth/login.php?err=token'); exit;
}
[$stored_token, $uid, $expires] = $parts;

// Zawsze usuń token (jednorazowy)
db()->exec("DELETE FROM settings WHERE key_='_saas_token'");

if (!hash_equals($stored_token, $token) || strtotime($expires) < time()) {
    header('Location: ' . APP_URL . '/auth/login.php?err=expired'); exit;
}

$user = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [(int)$uid]);
if (!$user) {
    header('Location: ' . APP_URL . '/auth/login.php?err=user'); exit;
}

auth_start();
login_user($user);
log_auth_action((int)$user['id'], 'login', 'Logowanie z panelu SaaS (SSO)');

header('Location: ' . APP_URL . '/index.php'); exit;
