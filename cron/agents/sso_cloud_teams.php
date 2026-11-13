#!/usr/bin/env php
<?php
/**
 * cron/agents/sso_cloud_teams.php — zespoły i obszary robocze z modułu Zadań SZO jako grupy w chmurze plików
 * (modules/sso_logto/logic/CloudTeamsSync.php).
 *
 * Uruchamiany przez cron/dispatcher.php co 2 min (polecenie 2026-11-14 „co 2 minut”). Pomija cicho (exit 0), gdy chmura nie jest skonfigurowana.
 * Ręcznie: php cli/ssoCloudTeamsSync.php [--dry-run] albo /ssoadmin#zespoly.
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 2) . '/modules/sso_logto/logic/CloudTeamsSync.php';

if (!SsoConfig::cloudConfigured()) exit(0);
@set_time_limit(600);
$res = CloudTeamsSync::run(false);
echo CloudTeamsSync::summary($res), "\n";
exit($res['error'] !== '' ? 1 : 0);
