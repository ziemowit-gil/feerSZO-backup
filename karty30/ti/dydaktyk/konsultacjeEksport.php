<?php
/**
 * karty30/ti/dydaktyk/konsultacjeEksport.php — eksport zestawień konsultacji/SzK do CSV/Excel (POST + token panelu)
 * oraz lista obecności szkolenia krótkiego do podpisu (GET ?lista=<id kursu>, PDF). Eksport trafia do audytu (bez danych osób).
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consult_reports/logic/consultReports.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

if (isset($_GET['lista'])) {
    $cid = (int)$_GET['lista'];
    if (!$cid || !dyd_owns_course($uid, $cid)) { http_response_code(403); exit('Brak dostępu do szkolenia.'); }
    try { $pdf = TiConsultReports::attendanceSheetPdf($cid); } catch (\Throwable $e) { error_log('listaObecnosciSzk: ' . $e->getMessage()); http_response_code(500); exit('Nie udało się przygotować PDF.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="lista-obecnosci-' . preg_replace('/[^A-Za-z0-9.]+/', '-', (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid])['name'] ?? 'szk')) . '.pdf"');
    header('Cache-Control: no-store, private');
    echo $pdf; exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Eksport wymaga formularza z zestawienia.'); }
dyd_token_check();
$key = (string)($_POST['r'] ?? ''); $fmt = (string)($_POST['fmt'] ?? '');
if (!isset(TiConsultReports::REPORTS[$key]) || !in_array($fmt, ['csv', 'xlsx'], true)) { http_response_code(400); exit('Nieznane zestawienie lub format.'); }
$range = TiConsultReports::range($_POST);
$ds = TiConsultReports::dataset($key, $range, $uid, $staff);
try {
    require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';
    audit_log('ti.consult_reports.export', ['report' => $key, 'format' => $fmt, 'rows' => count($ds['rows']), 'from' => $range['from'], 'to' => $range['to']], (int)($me['real_user_id'] ?? $uid));
} catch (\Throwable $e) {}
$file = AnalyticsExport::filename('konsultacje-' . $key, $fmt);
if ($fmt === 'csv') { AnalyticsExport::csv($ds, $file); exit; }
AnalyticsExport::xlsx($ds, $byName, $file);
