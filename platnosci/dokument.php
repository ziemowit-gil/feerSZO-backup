<?php
/**
 * platnosci/dokument.php — dokumenty rozliczeń dla zalogowanego w portalu uczestnika (wyłącznie własne):
 *   ?type=hours&month=M&year=R[&course_id=N]  — rozpiska godzin (PDF)
 *   ?type=invoice&id=N                        — faktura (FVAT) rozliczenia
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_hours_report.php';
require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php';

$pid = pp_current();
if (!$pid) { http_response_code(403); exit('Zaloguj się do portalu płatności.'); }
karty30_migrate();
$type = (string)($_GET['type'] ?? '');
if ($type === 'invoice') {
    $b = db_one("SELECT invoice_path, invoice_name FROM k30_ti_billing WHERE id=? AND client_id=? AND status!='cancelled'", [(int)($_GET['id'] ?? 0), $pid]);
    if ($b && $b['invoice_path'] !== '') { k30_ti_invoice_send_file($b['invoice_path'], $b['invoice_name']); exit; }
    http_response_code(404); exit('Faktura nie istnieje.');
}
if ($type === 'hours') {
    $month = max(1, min(12, (int)($_GET['month'] ?? date('n')))); $year = (int)($_GET['year'] ?? date('Y')); $cid = (int)($_GET['course_id'] ?? 0);
    if ($cid > 0 && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$pid, $cid])) $cid = 0;
    try {
        $pdf = ti_hours_pdf(ti_hours_data($pid, $year, $month, $cid));
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="rozpiska_godzin_' . sprintf('%04d-%02d', $year, $month) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf; exit;
    } catch (\Throwable $e) { error_log('[platnosci hours] ' . $e->getMessage()); http_response_code(500); exit('Nie udało się wygenerować rozpiski.'); }
}
http_response_code(400); exit('Nieznany dokument.');
