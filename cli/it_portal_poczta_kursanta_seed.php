#!/usr/bin/env php
<?php
/**
 * cli/it_portal_poczta_kursanta_seed.php — Portal Usług IT (/uslugi-it): usługa „Poczta kursanta” dla grupy „Kursant”
 * (logowanie na titan.sdata.net.pl, hasło startowe SMS-em, IMAP/SMTP, przekierowanie z @edu.feer.org.pl) i artykuł
 * „Dlaczego rezygnujemy z Microsoft 365”.
 *
 * Zakłada usługę albo nadpisuje jej wstęp, dodaje albo nadpisuje artykuły. Można uruchamiać wielokrotnie.
 * To samo robi raz automatycznie migracja '2026-11_it_portal_poczta_kursanta'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_poczta_kursanta_seed.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_poczta_kursanta_seed.php             # zapisz
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalPocztaKursantaSeed.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';

$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n\n";

$rep = itPortalPocztaKursantaApply(ITP_SZO_URL, $dry);   // linki zawsze na szo.feer.org.pl, nie na APP_URL (z CLI to localhost)
echo "Usługa „Poczta kursanta”: {$rep['category']}\n";
foreach (['created' => 'Nowe artykuły', 'updated' => 'Nadpisane artykuły'] as $k => $label) {
    echo "\n{$label} (" . count($rep[$k]) . "):\n";
    foreach ($rep[$k] as $line) echo "  - {$line}\n";
}
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe: " . ITP_SZO_URL . "/uslugi-it/uslugi/poczta-kursanta/logowanie\n";
