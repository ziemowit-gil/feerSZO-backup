<?php
/**
 * cli/ssoCloudTeamsSync.php — grupy z SZO w chmurze plików (Nextcloud / ownCloud): zespoły i obszary Zadań, Działania i jednostki
 * organizacyjne („szo-zespol-”, „szo-obszar-”, „szo-dzialanie-”, „szo-jednostka-” + numer; logic/CloudTeamsSync.php). Ten sam przebieg
 * co /ssoadmin#zespoly i agent CRON sso_cloud_teams (co 2 min). Ruszamy tylko grupy z tymi przedrostkami; grupa wyłączonej albo
 * usuniętej pozycji zostaje, ale bez członków.
 *
 * Użycie:
 *   php cli/ssoCloudTeamsSync.php --dry-run   # pokaż, co się zmieni
 *   php cli/ssoCloudTeamsSync.php             # zapisz w chmurze
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Tylko CLI.\n"); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/sso_logto/logic/CloudTeamsSync.php';

$dry = in_array('--dry-run', $argv, true);
if (!SsoConfig::cloudConfigured()) { fwrite(STDERR, "Chmura plików nie jest skonfigurowana (/ssoadmin).\n"); cli_exit(2); }
echo ($dry ? 'PODGLĄD (bez zmian w chmurze)' : 'ZAPIS') . "\n\n";
$res = CloudTeamsSync::run($dry);
if ($res['error'] !== '') { fwrite(STDERR, 'Błąd: ' . $res['error'] . "\n"); cli_exit(1); }
foreach ($res['teams'] as $r) {
    echo '• ' . ucfirst((string)($r['kind'] ?? '')) . ': ' . $r['name'] . ($r['active'] ? '' : ' [wyłączony]') . ' → ' . $r['gid'] . ' (' . $r['group'] . ($r['renamed'] ? ', nazwa' : '') . ')' . "\n";
    if ($r['added'])   echo '    ' . ($dry ? 'do dopisania' : 'dopisani') . ': ' . implode(', ', $r['added']) . "\n";
    if ($r['removed']) echo '    ' . ($dry ? 'do wypisania' : 'wypisani') . ': ' . implode(', ', $r['removed']) . "\n";
    if ($r['without']) echo '    bez konta w chmurze: ' . implode(', ', $r['without']) . "\n";
    if ($r['error'] !== '') echo '    BŁĄD: ' . $r['error'] . "\n";
}
echo "\n" . CloudTeamsSync::summary($res) . "\n";
if ($dry) echo "Nic nie zmieniono. Uruchom bez --dry-run, żeby zapisać.\n";
