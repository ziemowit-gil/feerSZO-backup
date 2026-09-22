<?php
/**
 * Wyszukiwanie konta użytkownika do powiązania z pełnomocnikiem — JSON.
 * GET ?q=...  min. 2 znaki. Zwraca aktywnych użytkowników po nazwie lub e-mailu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$like = '%' . $q . '%';
try {
    $rows = db_all(
        "SELECT id, name, email FROM users
         WHERE is_active = 1 AND (name LIKE ? OR email LIKE ?)
         ORDER BY name LIMIT 20",
        [$like, $like]
    );
} catch (\Throwable $e) { $rows = []; }

echo json_encode(array_map(fn($r) => [
    'id'    => (int)$r['id'],
    'name'  => $r['name'] ?? '',
    'email' => $r['email'] ?? '',
], $rows));
