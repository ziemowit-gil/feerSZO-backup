<?php
/**
 * karty30/ti/terms_pdf.php — pobierz oświadczenie administratora (pominięcie wymogu
 * akceptacji regulaminu / akceptacja zdalna w imieniu kursanta).
 * Parametr: ?id=<accept_id>
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_terms.php';

k30_require_access();
karty30_migrate();
ti_terms_migrate();

$accept_id = (int)($_GET['id'] ?? 0);
if (!$accept_id) { http_response_code(400); exit('Brak ID.'); }

$accept = db_one(
    "SELECT a.*, t.type, t.title, t.body_html, t.version AS term_version
     FROM k30_ti_terms_accepts a
     JOIN k30_ti_terms t ON t.id=a.term_id
     WHERE a.id=?",
    [$accept_id]
);
if (!$accept || strpos((string)$accept['accepted_by_role'], 'admin_') !== 0) {
    http_response_code(404); exit('Nie znaleziono oświadczenia.');
}

$client = db_one("SELECT * FROM k30_clients WHERE id=?", [$accept['client_id']]) ?: [];
$admin  = !empty($accept['admin_id']) ? db_one("SELECT * FROM users WHERE id=?", [$accept['admin_id']]) : null;

ti_term_admin_pdf($accept, $client, $admin);
