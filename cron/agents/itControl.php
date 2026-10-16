<?php
/**
 * cron/agents/itControl.php — agent CRON Kontroli IT.
 *
 * Rejestr (modules/cron_dispatcher/logic/registry.php) uruchamia go trzykrotnie
 * z różnymi argumentami:
 *   it_control_logs   --suite=logs --cron   co godzinę — Kontrola Dzienników + alert krytyczny
 *   it_control_ops    --suite=ops  --cron   co godzinę — Kontrola ogólna + alert krytyczny
 *   it_control_report --report     --cron   co godzinę — raport e-mail, gdy należny wg ustawień
 * Wysyłki są domyślnie wyłączone (itc_report_enabled / itc_alert_enabled).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

define('APP_CLI', true);
$base = dirname(__DIR__, 2);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/modules/it_control/logic/itControlCli.php';

$args = $argv;
if (!in_array('--cron', $args, true)) $args[] = '--cron';
exit(itControlCliMain($args));
