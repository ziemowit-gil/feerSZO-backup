<?php
/**
 * Cron — kampanie mailowe: uruchamia kampanie zaplanowane, których termin minął,
 * i odświeża liczniki wysłanych/nieudanych dla kampanii w trakcie wysyłki.
 *
 * Użycie (crontab):
 *   * * * * * php /var/www/umowy/cron/campaign_send.php >> /var/log/campaign_send.log 2>&1
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/crm_campaign.php';
    require_role('admin');
} else {
    define('APP_CLI', true);
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/crm_campaign.php';
}

crm_migrate();

$started = 0;
$due = db_all("SELECT id FROM crm_campaigns WHERE status='scheduled' AND scheduled_at <= datetime('now')");
foreach ($due as $c) {
    crm_campaign_queue_send((int)$c['id']);
    $started++;
}

$refreshed = 0;
$sending = db_all("SELECT id FROM crm_campaigns WHERE status='sending'");
foreach ($sending as $c) {
    crm_campaign_refresh_stats((int)$c['id']);
    $refreshed++;
}

$msg = sprintf(
    '[%s] campaign_send: uruchomiono=%d, odświeżono=%d',
    date('Y-m-d H:i:s'), $started, $refreshed
);

if ($is_cli) {
    echo $msg . PHP_EOL;
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['started' => $started, 'refreshed' => $refreshed, 'ts' => date('Y-m-d H:i:s')]);
