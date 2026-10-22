<?php
/**
 * karty30/ti/dydaktyk/noclegiEksport.php — eksport zestawienia noclegów kadry (CSV / Excel). Tylko POST z tokenem panelu.
 * Kierownik dostaje wszystkich, prowadzący tylko siebie.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_lodging/logic/lodging.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$me = dyd_require();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Eksport wymaga formularza z zestawienia.'); }
dyd_token_check();
karty30_migrate();
$fmt = (string)($_POST['fmt'] ?? '');
if (!in_array($fmt, ['csv', 'xlsx'], true)) { http_response_code(400); exit('Nieznany format.'); }
try { $ds = TiLodging::dataset(dyd_is_staff() ? 0 : (int)$me['user_id']); }
catch (\Throwable $e) { error_log('noclegi: eksport — ' . $e->getMessage()); http_response_code(500); exit('Nie udało się przygotować zestawienia.'); }
$file = 'noclegi_kadry_' . date('Y-m-d') . '.' . $fmt;
if ($fmt === 'csv') { AnalyticsExport::csv($ds, $file); exit; }
AnalyticsExport::xlsx($ds, (string)($me['name'] ?? ''), $file);
