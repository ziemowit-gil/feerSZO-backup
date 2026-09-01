<?php
/**
 * karty30/ti/dydaktyk/plan_librus_client_pdf.php — Plan zajęć kursanta, siatka
 * (wszystkie grupy), do pobrania (PDF). Wariant plan_librus_client.php —
 * te same dane (ti_librus_grid_client()). GET: client_id, weeks.
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

$client_id = (int)($_GET['client_id'] ?? 0);
$weeks     = max(1, min(52, (int)($_GET['weeks'] ?? 12)));

$L = $client_id ? ti_librus_grid_client($client_id, $weeks) : null;
if (!$client_id || !$L || !$L['client']) { http_response_code(404); exit('Nie znaleziono kursanta.'); }
$name = trim((string)($L['client']['name'] ?? ''));
$org  = ti_org_contact_info();

try {
    $pdfData = ti_librus_grid_pdf($L, TI_DAYS_PL_FULL, [
        'title'    => 'Plan zajęć (siatka, wszystkie grupy) — ' . $name,
        'subtitle' => $weeks . ' tyg.',
        'org'      => $org,
        'footer'   => 'Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? ''),
    ]);
    ti_print_log_add('plan_librus_client_pdf', 'Plan zajęć (siatka, wszystkie grupy, PDF) — ' . $name, 0, $client_id, ['weeks' => $weeks], $me);
    $fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', $name) . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[plan_librus_client_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
