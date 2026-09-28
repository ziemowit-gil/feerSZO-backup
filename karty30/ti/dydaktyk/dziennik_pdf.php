<?php
/**
 * karty30/ti/dydaktyk/dziennik_pdf.php — „Dziennik zajęć” grupy do druku (PDF).
 * Tylko kierownik (dyd_is_staff). GET: course_id; dane=1 — z danymi osobowymi
 * (data urodzenia, PESEL, opiekun). Generator: modules/ti_pdf/logic/dziennik.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pdf/logic/dziennik.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Dziennik zajęć drukuje kierownik.'); }
karty30_migrate();

$course_id = (int)($_GET['course_id'] ?? 0);
$course    = $course_id ? k30_ti_course_get($course_id) : null;
if (!$course) { http_response_code(404); exit('Nie znaleziono grupy.'); }
$personal  = !empty($_GET['dane']);

$pdf = ti_dziennik_pdf($course_id, ['personal' => $personal]);
if ($pdf === null) { http_response_code(500); exit('Nie udało się wygenerować dziennika.'); }

ti_print_log_add('dziennik_pdf', 'Dziennik zajęć — ' . $course['name'] . ($personal ? ' (z danymi osobowymi)' : ''), $course_id, 0, ['dane' => $personal ? 1 : 0], $me);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . ti_dziennik_filename($course) . '"');
header('Cache-Control: private, no-store');
echo $pdf;
