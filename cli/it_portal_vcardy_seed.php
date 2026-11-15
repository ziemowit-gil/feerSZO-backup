<?php
declare(strict_types=1);
/**
 * cli/it_portal_vcardy_seed.php — Portal Usług IT (/uslugi-it): artykuł „Kontakty z Outlooka do pliku (VCF albo CSV)”
 * w usłudze „Moja poczta” (moja-poczta/kontakty-z-outlooka), opis strony /pomocne/vcardy (modules/vcardy).
 * Uruchamia raz automatycznie migracja '2026-11_it_portal_vcardy'. Ponowne uruchomienie nadpisuje treść artykułu.
 *
 *   php cli/it_portal_vcardy_seed.php --dry-run    # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_vcardy_seed.php              # zapisz (nowy artykuł jest od razu opublikowany)
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalVcardySeed.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n\n";

$rep = itPortalVcardyApply(ITP_SZO_URL, $dry);   // linki zawsze na szo.feer.org.pl, nie na APP_URL (z CLI to localhost)
echo "Usługa: {$rep['category']}\n";
if ($rep['saved'] !== '') echo "Artykuł: {$rep['saved']}\n";
echo "\nZobacz też (" . count($rep['linked']) . "):\n";
foreach ($rep['linked'] as $t) echo "  - {$t}\n";
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe: " . ITP_SZO_URL . "/uslugi-it/uslugi/moja-poczta/kontakty-z-outlooka\n";
