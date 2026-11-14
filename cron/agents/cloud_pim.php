#!/usr/bin/env php
<?php
/**
 * cron/agents/cloud_pim.php — kalendarze i kontakty z Microsoft 365 do chmury Nextcloud (modules/cloudDav/logic/PimImport.php).
 *
 * Uruchamiany przez cron/dispatcher.php co 5 min; jedna porcja ~4 min: najpierw kopie z Microsoft 365 (zaszyfrowane pliki .ics/.vcf),
 * potem import do chmury (occ calendar:import i modules/cloudDav/nc/szoNcPim.php). Niedostępność = ponowienie za 6 h.
 * Pomija cicho (exit 0), gdy nie ma zadań. Ręcznie: php cron/agents/cloud_pim.php albo php cli/cloudPimImport.php --run
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 2) . '/modules/cloudDav/logic/PimImport.php';

@set_time_limit(0);
foreach (PimImport::runDue(240) as $l) echo $l, "\n";
exit(0);
