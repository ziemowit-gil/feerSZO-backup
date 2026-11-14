#!/usr/bin/env php
<?php
/**
 * cli/cloudPimImport.php — kalendarze i kontakty z Microsoft 365 do chmury Nextcloud (modules/cloudDav/logic/PimImport.php).
 *
 *   php cli/cloudPimImport.php --status                       # zadania i ich stan
 *   php cli/cloudPimImport.php --upn jan.kowalski@feer.org.pl # zadanie dla jednego konta Microsoft (kopia + import)
 *   php cli/cloudPimImport.php --all                          # zadania dla wszystkich aktywnych kont Microsoft 365
 *   php cli/cloudPimImport.php --reimport ID                  # sam import z gotowej kopii (np. po założeniu konta w chmurze)
 *   php cli/cloudPimImport.php --run [--budget 240]           # wykonaj teraz zaległe zadania (to samo robi agent co 5 min)
 *   … --force                                                # jednorazowo mimo wyłącznika cloud_pim_enabled (próba na jednym koncie)
 * Bez --run zadania wykona agent cron/agents/cloud_pim.php.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__) . '/modules/cloudDav/logic/PimImport.php';

$opt = getopt('', ['status', 'upn:', 'all', 'reimport:', 'run', 'budget:', 'force']);
if (isset($opt['force'])) define('PIM_FORCE', true);
$by = 'cli:' . (get_current_user() ?: 'admin');
echo "Baza: " . DB_PATH . "\n";
echo "Wyłącznik cloud_pim_enabled: " . (org_setting('cloud_pim_enabled') === '1' ? 'włączone' : 'wyłączone') . (isset($opt['force']) ? ' (--force: jednorazowo działa)' : '') . "\n";
echo "Microsoft 365: " . (PimImport::exportAvailable() ? 'skonfigurowany' : 'BRAK konfiguracji') . "; chmura na tym serwerze: "
   . (PimImport::importAvailable() ? 'tak' : 'nie (' . (NcPim::diagnose() ?: 'brak konfiguracji chmury') . ')') . "\n";
if (isset($opt['upn'])) echo 'Zadanie #' . PimImport::queue((string)$opt['upn'], '', $by) . " dla " . $opt['upn'] . "\n";
if (isset($opt['all'])) echo 'Nowe zadania: ' . PimImport::queueAll($by) . "\n";
if (isset($opt['reimport'])) echo PimImport::reimport((int)$opt['reimport'], $by) ? "Import ponowiony.\n" : "Brak kopii dla tego zadania.\n";
if (isset($opt['run'])) foreach (PimImport::runDue((int)($opt['budget'] ?? 240)) as $l) echo $l, "\n";
if (isset($opt['status']) || !$opt) {
    foreach (PimImport::jobs() as $j) {
        printf("#%d %-40s %-10s %-7s chmura=%-20s kal=%d wyd=%d kont=%d → wyd=%d kont=%d %s\n", $j['id'], $j['upn'], PimImport::LABELS[$j['status']] ?? $j['status'],
            $j['phase'], $j['cloud_uid'] ?: '-', $j['calendars'], $j['events'], $j['contacts'], $j['imported_events'], $j['imported_contacts'],
            $j['last_error'] ? '| ' . mb_substr((string)$j['last_error'], 0, 120) : '');
    }
}
