#!/usr/bin/env php
<?php
/**
 * cli/it_portal_migracja_seed.php — Portal Usług IT (/uslugi-it): usługa „Migracja” z artykułem „migracja”
 * (terminy, powody odejścia od Microsoft 365, co zrobić, loginy i hasła, zmiany z 13.11.2026).
 *
 * Zakłada usługę albo nadpisuje jej wstęp, dodaje albo nadpisuje artykuł. Można uruchamiać wielokrotnie.
 * To samo robi raz automatycznie migracja '2026-11_it_portal_migracja_article'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_migracja_seed.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_migracja_seed.php             # zapisz
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalMigracjaSeed.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n\n";

$rep = itPortalMigracjaApply(APP_URL, $dry);
echo "Usługa „Migracja”: {$rep['category']}\n";
foreach (['created' => 'Nowe artykuły', 'updated' => 'Nadpisane artykuły'] as $k => $label) {
    echo "\n{$label} (" . count($rep[$k]) . "):\n";
    foreach ($rep[$k] as $line) echo "  - {$line}\n";
}
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe: " . rtrim(APP_URL, '/') . "/uslugi-it/uslugi/migracja/migracja\n";
