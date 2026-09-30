<?php
/**
 * platnosci/rachunek_pdf.php — PDF „nadano numer rachunku” dla zalogowanego w portalu uczestnika (wyłącznie własny numer).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php';

$pid = pp_current();
if (!$pid) { http_response_code(403); exit('Zaloguj się do portalu płatności.'); }
pp_vnrb_notice_send('client', $pid, 'uczestnik portalu');
