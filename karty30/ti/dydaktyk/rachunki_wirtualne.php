<?php
/**
 * karty30/ti/dydaktyk/rachunki_wirtualne.php — wydruk raportu rachunków wirtualnych kursantów TI (kierownik).
 * ?scope=all (domyślnie: wszyscy kursanci) | last (numery z ostatniego importu od banku).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/payment_portal/logic/paymentPortal.php';
$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
pp_vnrb_report_print(($_GET['scope'] ?? 'all') === 'last' ? 'last' : 'all', (string)($me['name'] ?? ''));
