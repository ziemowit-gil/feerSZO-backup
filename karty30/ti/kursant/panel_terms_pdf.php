<?php
/**
 * karty30/ti/kursant/panel_terms_pdf.php — PDF potwierdzenia akceptacji regulaminu panelu przez kursanta.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/panel_terms.php';
require_once __DIR__ . '/auth.php';

$student = student_require();

$accept_id = (int)($_GET['id'] ?? 0);
if (!$accept_id) { http_response_code(400); exit('Brak ID.'); }

$accept = panel_term_kursant_accept_get($accept_id, (int)$student['id']);
if (!$accept) { http_response_code(404); exit('Nie znaleziono akceptacji.'); }

$client = db_one("SELECT name, email FROM k30_clients WHERE id=?", [$student['client_id']]) ?? [];

panel_term_pdf($accept, [
    'name'  => $client['name'] ?? $student['login'] ?? '',
    'email' => $client['email'] ?? '',
]);
