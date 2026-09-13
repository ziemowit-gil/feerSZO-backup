<?php
/**
 * karty30/ti/kursant/invoice_file.php — pobranie faktury (FVAT) rozliczenia
 * przez kursanta lub opiekuna (rodzica). Weryfikuje przynależność rozliczenia
 * do client_id zalogowanej sesji.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

// Sesja kursanta/rodzica (klasyczny panel) albo token API (kursantApp — link
// pobierania nie ma wspólnej sesji PHP z Angularem, patrz auth.php).
$cid = 0;
$st  = student_current();
if ($st) {
    $cid = (int)$st['client_id'];
} else {
    $pr = function_exists('parent_current') ? parent_current() : null;
    if ($pr) {
        $cid = (int)$pr['client_id'];
    } else {
        $tok = student_current_via_api_token();
        if ($tok) $cid = (int)$tok['client_id'];
    }
}
if (!$cid) { http_response_code(401); exit('Sesja wygasła.'); }

$bid = (int)($_GET['id'] ?? 0);
$b   = $bid ? db_one(
    "SELECT invoice_path, invoice_name FROM k30_ti_billing WHERE id=? AND client_id=? AND status!='cancelled'",
    [$bid, $cid]
) : null;
if ($b && $b['invoice_path'] !== '') {
    k30_ti_invoice_send_file($b['invoice_path'], $b['invoice_name']);
}
http_response_code(404);
exit('Faktura nie istnieje.');
