#!/usr/bin/env php
<?php
/**
 * cli/it_portal_szo_links.php — Portal Usług IT (/uslugi-it): linki do stron SZO w artykułach i wstępach usług na https://szo.feer.org.pl.
 *
 * Zamienia hosta (localhost, 127.0.0.1, inne domeny *.feer.org.pl) przed ścieżkami stron SZO: helpdesk, migracja poczty,
 * nowe hasło, portal usług IT, Tożsamość. Linków zewnętrznych nie rusza. Można uruchamiać wielokrotnie.
 * To samo robi raz automatycznie migracja '2026-11_it_portal_szo_links'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_szo_links.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_szo_links.php             # zapisz
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\nLinki do stron SZO → " . ITP_SZO_URL . "\n";
$rep = itPortalSzoLinksApply($dry);
foreach (['articles' => 'Artykuły', 'categories' => 'Wstępy usług'] as $k => $label) {
    echo "\n{$label} z poprawionymi linkami (" . count($rep[$k]) . "):\n";
    foreach ($rep[$k] as $line) echo "  - {$line}\n";
}
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe.\n";
