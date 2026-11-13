#!/usr/bin/env php
<?php
/**
 * cli/it_portal_dpoczta_seed.php — Portal Usług IT (/uslugi-it): instrukcje poczty dPoczta.pl w usłudze „Moja poczta”.
 *
 * Nadpisuje wstęp „Moja poczta”, dodaje albo nadpisuje artykuły (logowanie, hasło, telefon/Outlook, 2FA, podpis,
 * autoodpowiedź, filtry, spam, kontakty, kalendarz, szablony, powiadomienia, załączniki) i ukrywa dawne artykuły
 * o poczcie w Office 365 (is_published = 0, nic nie jest kasowane). Można uruchamiać wielokrotnie.
 * To samo robi raz automatycznie migracja '2026-11_it_portal_dpoczta_articles'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_dpoczta_seed.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_dpoczta_seed.php             # zapisz
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalDpocztaSeed.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n\n";

$rep = itPortalDpocztaApply(APP_URL, $dry);
echo "Usługa „Moja poczta”: {$rep['category']}\n";
foreach (['created' => 'Nowe artykuły', 'updated' => 'Nadpisane artykuły', 'hidden' => 'Ukryte artykuły o poczcie Office 365'] as $k => $label) {
    echo "\n{$label} (" . count($rep[$k]) . "):\n";
    foreach ($rep[$k] as $line) echo "  - {$line}\n";
}
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe. Ukryte artykuły przywrócisz w panelu Portalu Usług IT (Opublikowany).\n";
