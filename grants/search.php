<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/grants.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    $rows = db_all("SELECT id, nazwa, donator FROM grants ORDER BY nazwa LIMIT 50");
} else {
    $rows = db_all(
        "SELECT id, nazwa, donator FROM grants WHERE nazwa LIKE ? OR donator LIKE ? ORDER BY nazwa LIMIT 50",
        ['%'.$q.'%', '%'.$q.'%']
    );
}

$result = array_map(fn($r) => [
    'id'   => $r['id'],
    'text' => $r['nazwa'] . ' (' . $r['donator'] . ')',
], $rows);

echo json_encode($result);
