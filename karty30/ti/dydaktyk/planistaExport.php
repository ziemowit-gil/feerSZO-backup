<?php
/**
 * karty30/ti/dydaktyk/planistaExport.php — wydruki planisty (kierownik): lekcje z wybranego zakresu / scenariusza
 * albo zmiany scenariusza, w PDF / Word / Excel / CSV / tekście bez tabel (html, txt).
 * GET: what=plan|diff, from, to (Y-m-d), scenario, course, instructor, room, group=none|instructor|room|course|day,
 *      cancelled=1, cols=klucze,po,przecinku, fmt=pdf|docx|xlsx|csv|html|txt.
 * Nazwa pliku: dla jednej grupy Typ_data_nazwa_planista…, inaczej Planista_data_zakres.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerExport.php';
require_once dirname(__DIR__, 3) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Wydruki planisty drukuje kierownik.'); }
ti_sched_migrate();
$by = (string)($me['name'] ?? '');
$fmt = in_array($_GET['fmt'] ?? 'pdf', ['pdf', 'docx', 'xlsx', 'csv', 'html', 'txt'], true) ? $_GET['fmt'] : 'pdf';
$what = ($_GET['what'] ?? 'plan') === 'diff' ? 'diff' : 'plan';
$scn = (int)($_GET['scenario'] ?? 0);
$date = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$k] ?? '')) ? (string)$_GET[$k] : $def;

if ($what === 'diff') {
    if (!sc_scenario_open($scn)) { http_response_code(404); exit('Scenariusz nie jest otwarty.'); }
    $ds = sc_scenario_diff_dataset($scn);
    $base = 'Planista_' . date('Y-m-d') . '_scenariusz-' . $scn . '_zmiany';
} else {
    $from = $date('from', date('Y-m-d')); $to = $date('to', $from);
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) > 93 * 86400) { http_response_code(400); exit('Zakres dat jest za duży (maks. 93 dni).'); }
    $cid = (int)($_GET['course'] ?? 0);
    $ds = sc_planner_dataset(['from' => $from, 'to' => $to, 'scenario' => $scn, 'course_id' => $cid, 'instructor_id' => (int)($_GET['instructor'] ?? 0),
        'room_id' => (int)($_GET['room'] ?? 0), 'group' => (string)($_GET['group'] ?? 'none'), 'cancelled' => !empty($_GET['cancelled'])]);
    $cols = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['cols'] ?? ''))), fn($k) => isset(SC_PLANNER_COLS[$k])));
    $ds = sc_dataset_columns($ds, $cols);
    $base = $cid ? ti_export_filename($cid, 'planista_' . $from . '_' . $to . ($scn ? '_scenariusz' . $scn : ''))
                 : 'Planista_' . date('Y-m-d') . '_' . $from . '_' . $to . ($scn ? '_scenariusz-' . $scn : '');
}
ti_print_log_add('planista_' . $what . '_' . $fmt, $ds['title'], (int)($_GET['course'] ?? 0), 0, ['scenario' => $scn], $me);
if ($fmt === 'html' || $fmt === 'txt') { sc_export_text_send($ds, $by, $fmt, $base); exit; }
if ($fmt === 'csv') { sc_export_csv($ds, $base); exit; }
if ($fmt === 'xlsx') { sc_export_xlsx($ds, $by, $base . '.xlsx'); exit; }
if ($fmt === 'docx') { sc_export_docx($ds, $by, $base . '.docx'); exit; }
$pdf = sc_export_pdf($ds, $by);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $base . '.pdf"'); header('Content-Length: ' . strlen($pdf));
echo $pdf;
