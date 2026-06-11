<?php
/**
 * directory/avatar_thumb.php — serwuje zdjęcie profilowe (zatwierdzone lub oczekujące).
 * Oczekujące zdjęcia widoczne tylko dla właściciela profilu i administratorów.
 *
 * Parametry GET:
 *   uid  — ID użytkownika
 *   t    — typ: 'approved' (domyślnie) | 'pending'
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';

require_login();

$uid  = (int)($_GET['uid'] ?? 0);
$type = ($_GET['t'] ?? 'approved') === 'pending' ? 'pending' : 'approved';
$cu   = current_user();

// Sprawdź uprawnienia do oczekującego
if ($type === 'pending' && $uid !== (int)$cu['id'] && !is_admin()) {
    http_response_code(403);
    exit;
}

$row = db_one(
    "SELECT avatar_file, avatar_pending_file FROM user_profiles WHERE user_id=?",
    [$uid]
);

if (!$row) { http_response_code(404); exit; }

$file = $type === 'pending' ? $row['avatar_pending_file'] : $row['avatar_file'];
if (!$file) { http_response_code(404); exit; }

$base_dir = dirname(__DIR__) . '/uploads/avatars/';
$path     = $type === 'pending' ? $base_dir . 'pending/' . $file : $base_dir . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = $mime_map[$ext] ?? 'image/jpeg';

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
