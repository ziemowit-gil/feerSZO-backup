<?php
/**
 * karty30/ti/dydaktyk/plan_librus_pdf.php — Plan zajęć grupy (siatka) do pobrania (PDF).
 * Wariant plan_librus.php (widok HTML) — te same dane (ti_librus_grid()).
 * GET: course_id, weeks.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
karty30_migrate();
ti_planner_ext_migrate();
k30_ti_reschedule_migrate();

$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id || !dyd_owns_course($uid, $course_id)) {
    http_response_code(403); exit('Brak dostępu do planu tej grupy.');
}

$weeks = max(1, min(52, (int)($_GET['weeks'] ?? 12)));
$L = ti_librus_grid($course_id, $weeks);
if (!$L['course']) { http_response_code(404); exit('Nie znaleziono grupy.'); }
$course = $L['course'];
$org = ti_org_contact_info();

try {
    $pdfData = ti_librus_grid_pdf($L, TI_DAYS_PL_FULL, [
        'title'    => 'Plan zajęć (siatka) — ' . $course['name'],
        'subtitle' => 'Prowadzący: ' . ($course['instructor_name'] ?? '—') . '   ·   ' . $weeks . ' tyg.',
        'org'      => $org,
        'footer'   => 'Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? ''),
    ]);
    ti_print_log_add('plan_librus_pdf', 'Plan zajęć (siatka, PDF) — ' . $course['name'], $course_id, 0, ['weeks' => $weeks], $me);
    $fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', (string)$course['name']) . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[plan_librus_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
