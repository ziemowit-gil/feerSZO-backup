<?php
/**
 * Logowanie do tenanta z panelu SaaS — generuje jednorazowy token (ważny 60s).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION[SAAS_SESSION_KEY])) {
    header('Location: index.php'); exit;
}

$id     = (int)($_GET['id'] ?? 0);
$tenant = saas_get($id);

if (!$tenant || !$tenant['db_ready'] || !$tenant['is_active']) {
    header('Location: index.php#err'); exit;
}

$slug    = $tenant['slug'] ?: $tenant['krs'];
$db_path = TENANTS_DIR . '/' . $slug . '/umowy.db';

if (!is_file($db_path)) {
    header('Location: index.php#err'); exit;
}

$pdo = new PDO('sqlite:' . $db_path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$admin = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
if (!$admin) {
    header('Location: index.php#err'); exit;
}

$token   = bin2hex(random_bytes(20));
$expires = date('Y-m-d H:i:s', time() + 60);

// Zapisz token w ustawieniach tenanta
$pdo->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES ('_saas_token', ?)")
    ->execute([$token . '|' . $admin['id'] . '|' . $expires]);

// Wyznacz URL bazowy aplikacji
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$appDir  = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__))), '/');
$base    = ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
$app_url = rtrim($scheme . '://' . $host . $base, '/');

header('Location: ' . $app_url . '/org/' . urlencode($slug) . '/auth/saas_login.php?token=' . urlencode($token));
exit;
