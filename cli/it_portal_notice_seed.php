#!/usr/bin/env php
<?php
/**
 * cli/it_portal_notice_seed.php — Portal Usług IT (/uslugi-it/komunikaty): komunikat „Ważne zmiany: odchodzimy od Microsoft 365”.
 * Tworzy komunikat albo nadpisuje treść istniejącego o tym tytule (data i publikacja zostają). Można uruchamiać wielokrotnie.
 * To samo robi raz automatycznie migracja '2026-11_it_portal_notice_zmiany'.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/it_portal_notice_seed.php --dry-run            # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_notice_seed.php                      # zapisz (nowy komunikat z datą 2026-11-13)
 *   php cli/it_portal_notice_seed.php --date=2026-11-14    # nowy komunikat z inną datą
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalNoticeSeed.php';

$dry  = in_array('--dry-run', $argv, true);
$date = '2026-11-13';
foreach ($argv as $arg) if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $date = $m[1];
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n";
$rep = itPortalNoticeSeedApply(ITP_SZO_URL, $dry, $date);   // linki zawsze na szo.feer.org.pl
echo "Komunikat: {$rep['notice']} — {$rep['status']}\n";
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe. Komunikat ukryjesz albo zmienisz w panelu Portalu Usług IT.\n";
