#!/usr/bin/env php
<?php
/**
 * cli/it_portal_cloud_seed.php — Portal Usług IT (/uslugi-it): usługa „Chmura plików” z wprowadzeniem i artykułem
 * „Nextcloud: pierwsze kroki w chmurze plików”. Tworzy usługę albo nadpisuje jej wstęp; artykuł tworzy albo nadpisuje.
 * Można uruchamiać wielokrotnie. To samo robi raz automatycznie migracja '2026-11_it_portal_cloud'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_cloud_seed.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_cloud_seed.php             # zapisz
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalCloudSeed.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n";
$rep = itPortalCloudApply(ITP_SZO_URL, $dry);   // linki zawsze na szo.feer.org.pl
echo "Usługa „Chmura plików”: {$rep['category']}\nArtykuł: {$rep['article']}\n";
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe.\n";
