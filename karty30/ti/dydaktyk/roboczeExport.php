<?php
/**
 * karty30/ti/dydaktyk/roboczeExport.php — wydruk WERSJI ROBOCZYCH lekcji grupy (PDF / Word / Excel).
 * GET: course_id, fmt=pdf|docx|xlsx. Dostęp: dydaktyk posiadający kurs (własny / kierownik).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerExport.php';
require_once dirname(__DIR__, 3) . '/includes/ti_print_log.php';

karty30_migrate();
$me = dyd_require(); $uid = (int)$me['user_id'];
$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id || !dyd_owns_course($uid, $course_id)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }
$fmt = in_array($_GET['fmt'] ?? 'pdf', ['pdf', 'docx', 'xlsx'], true) ? $_GET['fmt'] : 'pdf';
$ds = sc_drafts_dataset($course_id); $by = (string)($me['name'] ?? '');
$cols = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['cols'] ?? ''))), fn($k) => isset(SC_DRAFT_COLS[$k])));
$ds = sc_dataset_columns($ds, $cols);   // wybór kolumn (pusty = wszystkie)
$base = 'robocze_lekcje_' . preg_replace('/[^a-z0-9]+/i', '_', (string)(k30_ti_course_get($course_id)['name'] ?? $course_id));
ti_print_log_add('robocze_' . $fmt, $ds['title'], $course_id, 0, ['rows' => count($ds['rows'])], $me);
if ($fmt === 'xlsx') { sc_export_xlsx($ds, $by, $base . '.xlsx'); exit; }
if ($fmt === 'docx') { sc_export_docx($ds, $by); exit; }
$pdf = sc_export_pdf($ds, $by);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $base . '.pdf"'); header('Content-Length: ' . strlen($pdf));
echo $pdf;
