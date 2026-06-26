<?php
/**
 * panel/terms_pdf.php — Pobierz PDF potwierdzenia akceptacji regulaminu panelu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/panel_terms.php';

require_login();
$user = current_user();

$accept_id = (int)($_GET['id'] ?? 0);
if (!$accept_id) { http_response_code(400); exit('Brak ID.'); }

$accept = panel_term_accept_get($accept_id, (int)$user['id']);
if (!$accept) { http_response_code(404); exit('Nie znaleziono akceptacji.'); }

panel_term_pdf($accept, $user);
