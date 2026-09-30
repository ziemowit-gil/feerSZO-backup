<?php
/**
 * karty30/ti/kursant/ledger_pdf.php — historia pobrań za lekcje (PDF) dla
 * kursanta albo opiekuna — wyłącznie własne dane (client_id z sesji / tokenu,
 * nigdy z URL). GET: ?from=Y-m-d&to=Y-m-d (domyślnie bieżący rok szkolny).
 * Logika: modules/ti_lesson_ledger/logic/lessonLedger.php.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_lesson_ledger/logic/lessonLedger.php';
require_once __DIR__ . '/auth.php';

// Token API (kursantApp) przed sesją — jak hours_pdf.php; potem kursant, opiekun, osoba upoważniona (wgląd)
$who = student_current_via_api_token() ?? student_current() ?? parent_current() ?? authp_current();
if (!$who) { header('Location: login.php'); exit; }
$client_id = (int)$who['client_id'];

$okd  = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
$sy   = (int)date('Y') - ((int)date('n') < 9 ? 1 : 0);
$from = array_key_exists('from', $_GET) ? $okd($_GET['from']) : sprintf('%04d-09-01', $sy);
$to   = $okd($_GET['to'] ?? '');

try {
    $pdf = ti_lesson_ledger_pdf($client_id, $from, $to);
} catch (\Throwable $e) {
    error_log('[kursant ledger_pdf] ' . $e->getMessage());
    http_response_code(500); exit('Nie udało się wygenerować PDF.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="historia_pobran.pdf"');
header('Cache-Control: private, no-store');
echo $pdf;
