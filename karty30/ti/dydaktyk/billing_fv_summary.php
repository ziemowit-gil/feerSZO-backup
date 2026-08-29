<?php
/**
 * karty30/ti/dydaktyk/billing_fv_summary.php — Podsumowanie pozycji do FVAT (PDF)
 * dla kierownika (sesja panelu dydaktyka — current_user() jest tu puste).
 * Parametry jak w karty30/ti/billing_fv_summary.php; generator wspólny.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_fv_summary.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }

$_fv_course_id = (int)($_GET['course_id'] ?? 0);
$_fv_client_id = (int)($_GET['client_id'] ?? 0);
$_fv_month     = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$_fv_year      = (int)($_GET['year'] ?? date('Y'));
ti_print_log_add('billing_fv_summary', 'Zestawienie pozycji do FVAT — ' . sprintf('%04d-%02d', $_fv_year, $_fv_month),
    $_fv_course_id, $_fv_client_id, [], dyd_current());

ti_fv_summary_output((int)($_GET['id'] ?? 0), $_fv_course_id, $_fv_client_id, $_fv_month, $_fv_year);
