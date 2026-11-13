#!/usr/bin/env php
<?php
/**
 * cli/it_portal_gosposia_seed.php — Portal Usług IT (/uslugi-it): artykuł „Gosposia: sprzątanie reklam i newsletterów ze skrzynki”
 * w usłudze „Moja poczta” (narzędzie /pomocne/gosposia).
 *
 * Domyślnie zapisuje SZKIC (już opublikowanego nie ukrywa); --publish publikuje. Łączy artykuł w „Zobacz też” z artykułami o poczcie.
 * Publikuje raz automatycznie migracja '2026-11_it_portal_gosposia_publish'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_gosposia_seed.php --dry-run    # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_gosposia_seed.php              # zapisz jako szkic
 *   php cli/it_portal_gosposia_seed.php --publish    # zapisz i opublikuj
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalGosposiaSeed.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';

$dry = in_array('--dry-run', $argv, true);
$pub = in_array('--publish', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n\n";

$rep = itPortalGosposiaApply(ITP_SZO_URL, $dry, $pub);   // linki zawsze na szo.feer.org.pl, nie na APP_URL (z CLI to localhost)
echo "Usługa: {$rep['category']}\n";
foreach (['created' => 'Nowe artykuły', 'updated' => 'Nadpisane artykuły', 'linked' => 'Gosposia dopisana do „Zobacz też”'] as $k => $label) {
    echo "\n{$label} (" . count($rep[$k]) . "):\n";
    foreach ($rep[$k] as $line) echo "  - {$line}\n";
}
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe: " . ITP_SZO_URL . "/uslugi-it/uslugi/moja-poczta/gosposia\n";
