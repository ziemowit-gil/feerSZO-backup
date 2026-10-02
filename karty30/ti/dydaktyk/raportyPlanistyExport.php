<?php
/**
 * karty30/ti/dydaktyk/raportyPlanistyExport.php — PDF / DOCX / CSV raportów planisty (kierownik).
 * GET: r=pay|rooms|att|cyc|week, ym=YYYY-MM, weeks (dla week), fmt=pdf|docx|csv.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerExport.php';
require_once dirname(__DIR__, 3) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Raporty planisty drukuje kierownik.'); }
ti_sched_migrate();
$key = (string)($_GET['r'] ?? ''); if (!isset(SC_REPORTS[$key])) { http_response_code(400); exit('Nieznany raport.'); }
$ym = sc_ym((string)($_GET['ym'] ?? date('Y-m'))); $weeks = (int)($_GET['weeks'] ?? 8);
$fmt = in_array($_GET['fmt'] ?? 'pdf', ['pdf', 'docx', 'csv', 'html', 'txt'], true) ? $_GET['fmt'] : 'pdf';
$ds = sc_report_dataset($key, $ym, $weeks); $by = (string)($me['name'] ?? '');
ti_print_log_add('planista_' . $key . '_' . $fmt, $ds['title'] . ' — ' . $ym, 0, 0, ['ym' => $ym], $me);
if ($fmt === 'html' || $fmt === 'txt') { sc_export_text_send($ds, $by, $fmt, 'raport_' . $key . '_' . $ym); exit; }
if ($fmt === 'csv') { sc_export_csv($ds, 'raport_' . $key . '_' . $ym); exit; }
if ($fmt === 'docx') { sc_export_docx($ds, $by, 'raport_' . $key . '_' . $ym . '.docx'); exit; }
$pdf = sc_export_pdf($ds, $by);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="raport_' . $key . '_' . $ym . '.pdf"'); header('Content-Length: ' . strlen($pdf));
echo $pdf;
