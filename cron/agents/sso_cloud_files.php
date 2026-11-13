#!/usr/bin/env php
<?php
/**
 * cron/agents/sso_cloud_files.php — kopiowanie plików z OneDrive (Microsoft 365) do chmury Nextcloud / ownCloud (modules/sso_logto).
 *
 * Uruchamiany przez cron/dispatcher.php co 5 min; jedna porcja ~4 min (kolejne przebiegi wznawiają bez duplikatów).
 * Gdy Microsoft 365 albo chmura są niedostępne, zadanie czeka 6 h i próbuje ponownie (najdłużej 7 dni bez postępu).
 * Pomija cicho (exit 0), gdy kopiowanie nie jest skonfigurowane albo nie ma zadań. Ręcznie: php cron/agents/sso_cloud_files.php
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 2) . '/modules/sso_logto/logic/CloudFilesCopy.php';

@set_time_limit(0);
$lines = CloudFilesCopy::runDue(240);
foreach ($lines as $l) echo $l, "\n";
exit(0);
