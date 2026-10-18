<?php
/**
 * cli/cleanup_pfron_test_clients.php — usuwa klientów i umowy PFRON założone
 * przez cli/seed_pfron_test_clients.php. Dopasowuje WYŁĄCZNIE po dokładnych
 * nazwach klientów użytych przy zakładaniu — nie rusza żadnych innych danych.
 *
 * Użycie:
 *   php cli/cleanup_pfron_test_clients.php
 */

require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    cli_exit(2);
}
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';

$names = [
    'Anna Kowalska (TEST)',
    'Piotr Nowak (TEST)',
    'Olena Kowalenko (TEST)',
    'Iryna Melnyk (TEST)',
];

$removed = 0;
foreach ($names as $name) {
    $c = db_one("SELECT id FROM k30_clients WHERE name=?", [$name]);
    if (!$c) { echo "$name — nie znaleziono (już usunięty?).\n"; continue; }
    db()->prepare("DELETE FROM k30_pfron_contracts WHERE client_id=?")->execute([$c['id']]);
    db()->prepare("DELETE FROM k30_clients WHERE id=?")->execute([$c['id']]);
    echo "USUNIĘTO $name (client_id={$c['id']}) wraz z umową PFRON.\n";
    $removed++;
}

echo "\nUsunięto: $removed / " . count($names) . ".\n";
