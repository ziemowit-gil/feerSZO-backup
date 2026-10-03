<?php
/**
 * karty30/ti/dydaktyk/analitykaEksport.php — eksport raportu Analityki TI do CSV albo Excela.
 * Tylko POST z tokenem CSRF panelu (lista zawiera dane osobowe), tylko dla dyd_can_analytics().
 * Pola: r (raport), fmt = csv | xlsx, filtry raportu. Każdy eksport trafia do audytu (bez treści pozycji).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$me = dyd_require();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Eksport wymaga formularza z raportu.'); }
dyd_token_check();
if (!dyd_can_analytics()) {
    audit_log('analytics.denied', ['endpoint' => 'export', 'report' => (string)($_POST['r'] ?? '')], (int)$me['real_user_id']);   // zapytanie z ważnym tokenem, ale bez uprawnień
    http_response_code(403); exit('Brak dostępu do Analityki TI.');
}

$key = (string)($_POST['r'] ?? '');
$fmt = (string)($_POST['fmt'] ?? '');
if (!isset(AnalyticsManager::REPORTS[$key]) || !in_array($fmt, ['csv', 'xlsx'], true)) { http_response_code(400); exit('Nieznany raport lub format.'); }

$filters = AnalyticsManager::filters($key, $_POST);
try {
    $ds = AnalyticsManager::dataset($key, $filters);
} catch (\Throwable $e) {
    error_log('analytics: eksport ' . $key . ' — ' . $e->getMessage());
    http_response_code(500); exit('Nie udało się przygotować raportu.');
}
AnalyticsManager::auditExport((int)$me['real_user_id'], $key, $fmt, count($ds['rows']), $filters);

$file = AnalyticsExport::filename($key, $fmt);
if ($fmt === 'csv') { AnalyticsExport::csv($ds, $file); exit; }
AnalyticsExport::xlsx($ds, (string)($me['name'] ?? ''), $file);
