<?php
/**
 * karty30/ti/dydaktyk/plan_librus_instructor_pdf.php — Plan zajęć prowadzącego
 * (siatka) do pobrania (PDF). Wariant plan_librus_instructor.php —
 * ti_librus_grid_instructor(). GET: instructor_id (staff — dowolny; zwykły
 * prowadzący tylko swój), weeks.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me       = dyd_require();
$uid      = (int)$me['user_id'];
$is_staff = dyd_is_staff();
karty30_migrate();
ti_planner_ext_migrate();
k30_ti_reschedule_migrate();

$target_uid = $uid;
if ($is_staff && isset($_GET['instructor_id'])) {
    $target_uid = max(0, (int)$_GET['instructor_id']);
}

$weeks = max(1, min(52, (int)($_GET['weeks'] ?? 12)));
$L = $target_uid ? ti_librus_grid_instructor($target_uid, $weeks) : null;
if (!$target_uid || !$L || !$L['instructor']) { http_response_code(404); exit('Nie znaleziono prowadzącego.'); }
$name = trim((string)$L['instructor']['name']);
$org  = ti_org_contact_info();

try {
    $pdfData = ti_librus_grid_pdf($L, TI_DAYS_PL_FULL, [
        'title'  => 'Plan zajęć (siatka) — ' . $name,
        'org'    => $org,
        'footer' => 'Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? ''),
    ]);
    ti_print_log_add('plan_librus_instructor_pdf', 'Plan zajęć (siatka, PDF) — ' . $name, 0, 0, ['weeks' => $weeks, 'instructor_id' => $target_uid], $me);
    $fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', $name) . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[plan_librus_instructor_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
