<?php
/**
 * karty30/ti/kursant/terms_pdf.php — pobierz PDF potwierdzenia akceptacji regulaminu.
 * Parametr: ?id=<accept_id>
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_terms.php';
require_once __DIR__ . '/auth.php';

$student = student_require();
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];

$accept_id = (int)($_GET['id'] ?? 0);
if (!$accept_id) { http_response_code(400); exit('Brak ID.'); }

$accept = ti_terms_accept_get($accept_id, (int)$student['client_id']);
if (!$accept) { http_response_code(404); exit('Nie znaleziono akceptacji.'); }

ti_term_pdf($accept, $client);
