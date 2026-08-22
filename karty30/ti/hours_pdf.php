<?php
/**
 * karty30/ti/hours_pdf.php — Rozpiska godzin dla beneficjenta (PDF) — wydruk pracownika.
 * GET: ?client_id=N&month=M&year=R  (opcjonalnie &course_id=N — jedna grupa)
 *      ?id=<billing_id>             — okres i grupa brane z rozliczenia
 * Dostęp: pracownik karty30 / administrator.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_hours_report.php';

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

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
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
} catch (\Throwable $e) {
    error_log('[ti_hours_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować rozpiski.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
