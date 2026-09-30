<?php
/**
 * cron/pp_notice_send.php — wysyłka zatwierdzonych powiadomień o numerze rachunku (SMS + e-mail).
 * Wysyłka rusza po terminie send_at (08:00 następnego dnia po zatwierdzeniu treści przez admina).
 */
if (php_sapi_name() !== 'cli') { die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n"); }
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/karty30.php';
require_once $base_dir . '/modules/payment_portal/logic/paymentPortal.php';
$r = pp_notice_process();
echo '[' . date('Y-m-d H:i:s') . "] pp_notice_send: wysyłek {$r['batches']}, kursantów {$r['students']}, SMS {$r['sms']}, e-mail {$r['email']}\n";
