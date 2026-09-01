<?php
/**
 * karty30/ti/kursant/plan_librus_pdf.php — Mój plan zajęć, siatka (wszystkie
 * grupy), do pobrania (PDF). Wariant plan_librus.php — ti_librus_grid_client().
 * GET: weeks.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once __DIR__ . '/auth.php';

$student = student_require();
karty30_migrate();
ti_planner_ext_migrate();
k30_ti_reschedule_migrate();

$client = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];
$name   = trim((string)($client['name'] ?? $student['login'] ?? 'Kursant'));
$weeks  = max(1, min(52, (int)($_GET['weeks'] ?? 12)));
$L      = ti_librus_grid_client((int)$student['client_id'], $weeks);
$org    = ti_org_contact_info();

try {
    $pdfData = ti_librus_grid_pdf($L, TI_DAYS_PL_FULL, [
        'title'    => 'Mój plan zajęć (siatka) — ' . $name,
        'org'      => $org,
        'footer'   => 'Wygenerowano: ' . date('d.m.Y H:i'),
    ]);
    $fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', $name) . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[kursant plan_librus_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
