<?php
/**
 * Cron — SMS-y przyjęte przez Sendly bez potwierdzenia doręczenia: ponowna wysyłka przez innego operatora
 * (modules/sms_delivery). Uruchamiany przez dispatcher (agent sms_sendly_watch) co 2 min.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko z CLI.'); }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/modules/sms_delivery/logic/sendlyDelivery.php';

[$resent, $failed, $expired] = sendlyWatch();
echo date('Y-m-d H:i:s') . " sms_sendly_watch: ponownie wysłano {$resent}, nieudanych {$failed}, wygaszonych {$expired}\n";
