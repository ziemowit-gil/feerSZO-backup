<?php
/**
 * cli/ssoNcUidSync.php — zapisuje login w chmurze (imie.nazwisko) w customData.nc_uid kont Logto, żeby Nextcloud (aplikacja
 * user_oidc, mapowanie uid = custom_data.nc_uid) przy logowaniu jednokrotnym trafiał w istniejące konta.
 * Nowe konta dostają nc_uid same (SsoAccounts::provision); to jednorazowe uzupełnienie istniejących. Haseł nie zmienia, nic nie wysyła.
 * To samo z przeglądarki: /ssoadmin#ncuid.
 *
 * Użycie:
 *   php cli/ssoNcUidSync.php --dry-run   # pokaż, co się zmieni
 *   php cli/ssoNcUidSync.php             # zapisz
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Tylko CLI.\n"); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/sso_logto/logic/SsoAccounts.php';

$dry = in_array('--dry-run', $argv, true);
if (!SsoConfig::logtoConfigured()) { fwrite(STDERR, "Logto nie jest skonfigurowane (/ssoadmin).\n"); cli_exit(2); }
echo ($dry ? 'PODGLĄD (bez zapisu)' : 'ZAPIS') . " — kont z logowaniem jednokrotnym i chmurą: " . SsoAccounts::cloudUidCandidates() . "\n\n";
$sum = [];
foreach (SsoAccounts::syncAllCloudUids($dry) as $r) {
    $k = preg_replace('/[:(].*$/u', '', $r['result']);
    $sum[trim($k)] = ($sum[trim($k)] ?? 0) + 1;
    if ($r['result'] !== 'bez zmian') echo "  {$r['email']} → {$r['cloud_uid']}: {$r['result']}\n";
}
echo "\nRazem: " . ($sum ? implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($sum), $sum)) : 'brak kont') . "\n";
if ($dry) echo "Nic nie zapisano. Uruchom bez --dry-run, żeby zapisać.\n";
