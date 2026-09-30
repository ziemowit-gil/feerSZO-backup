<?php
/**
 * karty30/ti/kursant/rachunek_pdf.php — PDF „nadano numer rachunku do wpłat” pobierany przez kursanta/opiekuna.
 * Dostęp: zalogowany kursant (sesja panelu) albo token API (kursantApp) — wyłącznie własny numer (client_id z sesji,
 * bez parametru z URL). Nic nie wysyła — generuje i zwraca dokument.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/payment_portal/logic/paymentPortal.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student   = student_current_via_api_token() ?? student_require();
$client_id = (int)$student['client_id'];
pp_vnrb_notice_send('client', $client_id, 'kursant');
