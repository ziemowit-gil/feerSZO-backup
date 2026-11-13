#!/usr/bin/env php
<?php
/**
 * cli/it_portal_stopka_seed.php — Portal Usług IT: obowiązkowa stopka e-mail.
 * Wgrywa artykuł „Obowiązkowa stopka: jak ją wygenerować”, nadpisuje „Jak ustawić podpis (stopkę) w poczcie” (link do generatora)
 * i tworzy/nadpisuje komunikat „Obowiązkowa stopka w wiadomościach e-mail”. Można uruchamiać wielokrotnie.
 * To samo robi raz automatycznie migracja '2026-11_it_portal_stopka'.
 *
 *   php cli/it_portal_stopka_seed.php --dry-run            # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_stopka_seed.php                      # zapisz (komunikat z datą 2026-11-13)
 *   php cli/it_portal_stopka_seed.php --date=2026-11-14    # nowy komunikat z inną datą
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalDpocztaSeed.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalNoticeStopkaSeed.php';

$dry  = in_array('--dry-run', $argv, true);
$date = '2026-11-13';
foreach ($argv as $arg) if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $date = $m[1];
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n\n";

$rep = itPortalDpocztaApply(ITP_SZO_URL, $dry, ['generator-stopki', 'podpis-w-poczcie']);
foreach (['created' => 'Nowe artykuły', 'updated' => 'Nadpisane artykuły'] as $k => $label) {
    echo "{$label} (" . count($rep[$k]) . "):\n";
    foreach ($rep[$k] as $line) echo "  - {$line}\n";
}
if (!$dry && is_file(dirname(__DIR__) . '/modules/it_portal/lib/itPortalVendorHelp.php')) {
    require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalVendorHelp.php';
    if (function_exists('itPortalVendorHelpApply')) itPortalVendorHelpApply();
}
$n = itPortalNoticeStopkaApply(ITP_SZO_URL, $dry, $date);
echo "\nKomunikat: {$n['notice']} — {$n['status']}\n";
echo $dry ? "\nNic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n" : "\nGotowe. Komunikat i artykuły zmienisz w panelu Portalu Usług IT.\n";
