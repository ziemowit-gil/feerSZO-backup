<?php
/**
 * karty30/ti/dydaktyk/plan_librus_all_pdf.php — Zbiorczy plan zajęć całej
 * instytucji (siatka) do pobrania (PDF). Wariant plan_librus_all.php —
 * ti_librus_grid_institution(). GET: weeks.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak uprawnień.'); }
karty30_migrate();
ti_planner_ext_migrate();
k30_ti_reschedule_migrate();

$weeks = max(1, min(52, (int)($_GET['weeks'] ?? 4)));
$L = ti_librus_grid_institution($weeks);
$org = ti_org_contact_info();

try {
    $pdfData = ti_librus_grid_pdf($L, TI_DAYS_PL_FULL, [
        'title'  => 'Plan zajęć — cała instytucja',
        'org'    => $org,
        'footer' => 'Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? ''),
    ]);
    ti_print_log_add('plan_librus_all_pdf', 'Plan zajęć (siatka, cała instytucja, PDF)', 0, 0, ['weeks' => $weeks], $me);
    $fname = 'plan_zajec_instytucja_' . date('Ymd') . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[plan_librus_all_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
