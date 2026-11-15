#!/usr/bin/env php
<?php
/**
 * cli/it_portal_sso_seed.php — Portal Usług IT: artykuł „Logowanie jednokrotne (SSO): jak się zalogować” (usługa „Mój login”).
 * Tworzy albo nadpisuje treść. To samo robi raz automatycznie migracja '2026-11_it_portal_sso_article'.
 *   php cli/it_portal_sso_seed.php --dry-run   # pokaż, co się zmieni, bez zapisu
 *   php cli/it_portal_sso_seed.php             # zapisz
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSzoLinks.php';
require_once dirname(__DIR__) . '/modules/it_portal/lib/itPortalSsoSeed.php';
$dry = in_array('--dry-run', $argv, true);
echo ($dry ? "PODGLĄD (bez zapisu)" : "ZAPIS") . " — baza: " . DB_PATH . "\n";
$rep = itPortalSsoApply(ITP_SZO_URL, $dry);
echo "Artykuł: {$rep['article']} — {$rep['status']}\n";
echo $dry ? "\nNic nie zapisano.\n" : "\nGotowe. Adres: " . ITP_SZO_URL . "/uslugi-it/uslugi/moj-login/logowanie-jednokrotne\n";
