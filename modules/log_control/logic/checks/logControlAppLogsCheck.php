<?php
/** Dzienniki aplikacji i agentów CRON (logs/*.log, LOG_PATH/cron_*.log, umowy_cron.log). */

require_once __DIR__ . '/logControlPhpErrorsCheck.php';

class LogControlAppLogsCheck extends ItControlCheck
{
    public function id(): string { return 'logs.app_logs'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Dzienniki aplikacji i CRON'; }
    public function description(): string { return 'Błędy i ostrzeżenia w logach aplikacji oraz wyjściu agentów CRON.'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        logControlCheckFileSummary($ctx, $r, ['app', 'cron'], 'aplikacji/CRON',
            'Brak plików logs/*.log, LOG_PATH/*.log i /var/log/umowy_cron.log. Zdefiniuj LOG_PATH w config.php, żeby agenci CRON zapisywali swoje wyjście.');
    }
}
