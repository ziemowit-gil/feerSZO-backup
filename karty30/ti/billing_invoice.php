<?php
/**
 * karty30/ti/billing_invoice.php — pobranie faktury (FVAT) rozliczenia przez personel.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$bid = (int)($_GET['id'] ?? 0);
$b   = $bid ? db_one("SELECT invoice_path, invoice_name FROM k30_ti_billing WHERE id=?", [$bid]) : null;
if ($b && $b['invoice_path'] !== '') {
    k30_ti_invoice_send_file($b['invoice_path'], $b['invoice_name']);
}
http_response_code(404);
exit('Faktura nie istnieje.');
