<?php
/**
 * cli/pp_bank_autopost.php — księgowanie wpłat po numerze wirtualnym (cron).
 *
 *   php cli/pp_bank_autopost.php          podgląd (nic nie zapisuje)
 *   php cli/pp_bank_autopost.php --apply  zaksięguj wpływy z wyciągów na numery wirtualne kursantów
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/modules/payment_portal/logic/paymentPortal.php';

$apply = in_array('--apply', $argv, true);
$r = pp_bank_autopost('cron', null, !$apply);
echo ($apply ? 'ZAKSIĘGOWANO' : 'PODGLĄD (dodaj --apply)') . ": {$r['posted']} wpłat, " . number_format($r['amount'], 2, ',', ' ') . " zł, pominięto {$r['skipped']}\n";
foreach ($r['rows'] as $x) printf("  %s  %-28s %10.2f  %s\n", substr($x['date'], 0, 10), $x['name'], $x['amount'], mb_strimwidth($x['payer'] . ' — ' . $x['title'], 0, 60, '…'));
