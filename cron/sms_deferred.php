<?php
/**
 * Cron — wysyłka SMS-ów odłożonych w ciszy nocnej (22:00–7:00), patrz inc/smsQuietHours.
 * Uruchamiany przez dispatcher (agent sms_deferred) co 5 min w godzinach 7–22.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko z CLI.'); }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/inc/smsQuietHours/smsQuietHours.php';

[$ok, $err] = smsQuietFlush();
echo date('Y-m-d H:i:s') . " sms_deferred: wysłano {$ok}, błędów {$err}\n";
