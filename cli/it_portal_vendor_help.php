#!/usr/bin/env php
<?php
/**
 * cli/it_portal_vendor_help.php — Portal Usług IT (/uslugi-it): sekcja „Pomoc producenta” (linki do pomocy dhosting.pl)
 * na dole instrukcji w usłudze „Moja poczta”. Odświeża sekcję, jeśli już jest. Można uruchamiać wielokrotnie.
 * Uruchom ponownie po każdym php cli/it_portal_dpoczta_seed.php (seeder nadpisuje artykuły bez tej sekcji).
 * To samo robi raz automatycznie migracja '2026-11_it_portal_vendor_help'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_vendor_help.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_vendor_help.php             # zapisz
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalVendorHelp.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n";
$rep = itPortalVendorHelpApply($dry);
foreach (['updated' => 'Artykuły z sekcją „Pomoc producenta”', 'missing' => 'Brak artykułu (pominięte)'] as $k => $label) {
    echo "\n{$label} (" . count($rep[$k]) . "):\n";
    foreach ($rep[$k] as $line) echo "  - {$line}\n";
}
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe.\n";
