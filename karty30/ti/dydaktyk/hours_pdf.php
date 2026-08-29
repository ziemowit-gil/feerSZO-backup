<?php
/**
 * karty30/ti/dydaktyk/hours_pdf.php — Rozpiska godzin dla beneficjenta (PDF)
 * dla kierownika (sesja panelu dydaktyka — current_user() jest tu puste).
 * GET: ?client_id=N&month=M&year=R  (opcjonalnie &course_id=N — jedna grupa)
 *      ?id=<billing_id>             — okres i grupa brane z rozliczenia
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_hours_report.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }
karty30_migrate();

$bid = (int)($_GET['id'] ?? 0);
if ($bid) {
    $b = db_one("SELECT client_id, month, year, COALESCE(course_id,0) AS course_id FROM k30_ti_billing WHERE id=?", [$bid]);
    if (!$b) { http_response_code(404); die('Nie znaleziono rozliczenia.'); }
    $client_id = (int)$b['client_id']; $month = (int)$b['month']; $year = (int)$b['year']; $course_id = (int)$b['course_id'];
} else {
    $client_id = (int)($_GET['client_id'] ?? 0);
    $month     = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
    $year      = (int)($_GET['year'] ?? date('Y'));
    $course_id = (int)($_GET['course_id'] ?? 0);
}
if (!$client_id) { http_response_code(400); die('Brak parametru client_id.'); }

try {
    $data = ti_hours_data($client_id, $year, $month, $course_id);
    $pdf  = ti_hours_pdf($data);
    $name = 'rozpiska_godzin_' . preg_replace('/[^a-z0-9]+/i', '_', (string)($data['client']['name'] ?? $client_id))
          . '_' . sprintf('%04d-%02d', $year, $month) . '.pdf';
    ti_print_log_add('hours_pdf', 'Rozpiska godzin — ' . sprintf('%04d-%02d', $year, $month), $course_id, $client_id, [], dyd_current());
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
} catch (\Throwable $e) {
    error_log('[dyd hours_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować rozpiski.\n";
    exit;
}
