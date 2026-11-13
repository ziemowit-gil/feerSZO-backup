#!/usr/bin/env php
<?php
/**
 * cli/it_portal_glossary_seed.php — Portal Usług IT (/uslugi-it): artykuł „Terminologia: słowniczek pojęć”
 * (migracja/terminologia). Tworzy albo nadpisuje treść, zostawia id, przypięcie i powiązane.
 * Można uruchamiać wielokrotnie. To samo robi raz automatycznie migracja '2026-11_it_portal_glossary'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_glossary_seed.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_glossary_seed.php             # zapisz
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalGlossarySeed.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n";
$rep = itPortalGlossaryApply(ITP_SZO_URL, $dry);   // linki zawsze na szo.feer.org.pl
echo "Artykuł: {$rep['article']} — {$rep['status']}\n";
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe.\n";
