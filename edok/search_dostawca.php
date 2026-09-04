<?php
/**
 * AJAX — wyszukiwanie kontrahentów dla selektora w edok/add.php.
 * Reużywa kdok_dostawcy_search() (includes/ksiegowosc.php) — wspólna kartoteka
 * kontrahentów (lokalny rejestr dostawców + kontakty CRM organizacyjne).
 * GET ?q=fraza → JSON [{nazwa, nip, rachunek_bankowy, source}]
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
kdok_migrate();

$q = trim($_GET['q'] ?? '');
echo json_encode(['results' => kdok_dostawcy_search($q)]);
