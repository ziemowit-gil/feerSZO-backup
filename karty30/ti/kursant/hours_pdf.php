<?php
/**
 * karty30/ti/kursant/hours_pdf.php — Rozpiska godzin (PDF) pobierana przez kursanta/opiekuna.
 * GET: ?month=M&year=R (domyślnie bieżący miesiąc), opcjonalnie &course_id=N.
 * Dostęp: zalogowany kursant (sesja panelu kursanta) — wyłącznie własne dane.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_hours_report.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
// Token API (kursantApp) — link pobierania nie ma wspólnej sesji PHP z
// Angularem; sprawdzany PRZED wymuszeniem sesji klasycznego panelu, żeby
// student_require() nie zdążył przekierować na login.php.
$student = student_current_via_api_token() ?? student_require();
$client_id = (int)$student['client_id'];          // zawsze własny kursant — bez parametru z URL
$month     = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year      = (int)($_GET['year'] ?? date('Y'));
$course_id = (int)($_GET['course_id'] ?? 0);
// Grupa tylko z listy własnych zapisów
if ($course_id > 0 && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$client_id, $course_id])) {
    $course_id = 0;
}

try {
    $data = ti_hours_data($client_id, $year, $month, $course_id);
    $pdf  = ti_hours_pdf($data);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="rozpiska_godzin_' . sprintf('%04d-%02d', $year, $month) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
} catch (\Throwable $e) {
    error_log('[kursant hours_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować rozpiski.\n";
    exit;
}
