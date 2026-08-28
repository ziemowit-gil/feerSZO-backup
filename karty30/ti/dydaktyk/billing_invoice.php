<?php
/**
 * karty30/ti/dydaktyk/billing_invoice.php — pobranie skanu faktury (FVAT) rozliczenia
 * przez kierownika (sesja panelu dydaktyka — current_user() jest tu puste).
 */
require_once __DIR__ . '/auth.php';

if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }
karty30_migrate();

$bid = (int)($_GET['id'] ?? 0);
$b   = $bid ? db_one("SELECT invoice_path, invoice_name FROM k30_ti_billing WHERE id=?", [$bid]) : null;
if ($b && $b['invoice_path'] !== '') {
    k30_ti_invoice_send_file($b['invoice_path'], $b['invoice_name']);
}
http_response_code(404);
exit('Faktura nie istnieje.');
