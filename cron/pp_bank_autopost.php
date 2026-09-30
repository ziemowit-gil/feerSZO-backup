<?php
/**
 * cron/pp_bank_autopost.php — codzienne księgowanie wpłat po numerze wirtualnym (koniec dnia, ok. 20:00).
 * Wpływy z wyciągów EODoK na indywidualne numery kursantów → księga TI (pp_bank_autopost).
 * Każdy wpływ tylko raz; wyłączysz agenta w panelu crona (pp_bank_autopost).
 */
if (php_sapi_name() !== 'cli') { die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n"); }
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/karty30.php';
require_once $base_dir . '/modules/payment_portal/logic/paymentPortal.php';
$r = pp_bank_autopost('cron 20:00', null, false);
echo '[' . date('Y-m-d H:i:s') . "] pp_bank_autopost: zaksięgowano {$r['posted']} wpłat na " . number_format($r['amount'], 2, ',', ' ') . " zł, pominięto {$r['skipped']}\n";
