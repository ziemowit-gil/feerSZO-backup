#!/usr/bin/env php
<?php
/**
 * cli/it_portal_cms_seed.php — Portal Usług IT (/uslugi-it): usługa „Strona feer.org.pl (CMS)” (poprawiator, antyspam, poczta SMTP)
 * ze zrzutami ekranu. Bezpieczny do ponownego uruchomienia.
 * Raz automatycznie: migracja '2026-11_it_portal_cms_docs'.
 *
 * Użycie (w katalogu aplikacji):
 *   php cli/it_portal_cms_seed.php --dry-run    # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_cms_seed.php              # zapisz i opublikuj
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalCmsSeed.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n\n";
foreach (itPortalCmsApply(ITP_SZO_URL, $dry) as $line) echo "  - {$line}\n";   // linki zawsze na szo.feer.org.pl, nie na APP_URL
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe: " . ITP_SZO_URL . "/uslugi-it/uslugi/strona-cms\n";
