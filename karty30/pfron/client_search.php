<?php
/**
 * karty30/pfron/client_search.php — Szybkie wyszukiwanie beneficjenta PFRON.
 * GET ?q=... → JSON [{id, name, pesel, address, phone, email}]
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

header('Content-Type: application/json; charset=utf-8');
k30_require_access();

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$like = '%' . $q . '%';
$rows = db()->prepare(
    "SELECT id, name, pesel, address, phone, email
     FROM k30_clients
     WHERE (name LIKE ? OR pesel LIKE ?)
     ORDER BY name
     LIMIT 20"
);
$rows->execute([$like, $like]);
$result = $rows->fetchAll(\PDO::FETCH_ASSOC);

echo json_encode(array_map(fn($r) => [
    'id'      => (int)$r['id'],
    'name'    => $r['name']    ?? '',
    'pesel'   => $r['pesel']   ?? '',
    'address' => $r['address'] ?? '',
    'phone'   => $r['phone']   ?? '',
    'email'   => $r['email']   ?? '',
], $result));
