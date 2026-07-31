<?php
/**
 * AJAX — wyszukiwanie kontrahentów dla selektora w add.php.
 * GET ?q=fraza  →  JSON [{nazwa, nip, rachunek_bankowy, source}]
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
if (!module_enabled('kdok_enabled')) {
    echo json_encode(['results' => []]);
    exit;
}

kdok_migrate();

$q = trim($_GET['q'] ?? '');
$results = kdok_dostawcy_search($q);

echo json_encode(['results' => $results]);
