<?php
/**
 * karty30/ti/dydaktyk/godzinyEksport.php — eksport zestawienia godzinowego (godziny.php) do CSV / Excela.
 * Tylko POST z tokenem panelu. Prowadzący eksportuje tylko własne dane; kierownik dowolnego prowadzącego (i).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_hours_insights/logic/hoursInsights.php';
require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';

$me = dyd_require();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Eksport wymaga formularza z zestawienia.'); }
dyd_token_check();
$uid = (int)$me['user_id'];
$key = (string)($_POST['r'] ?? ''); $fmt = (string)($_POST['fmt'] ?? '');
if (!isset(TiHoursInsights::REPORTS[$key]) || !in_array($fmt, ['csv', 'xlsx'], true)) { http_response_code(400); exit('Nieznane zestawienie lub format.'); }
$who = dyd_is_staff() && (int)($_POST['i'] ?? 0) > 0 ? (int)$_POST['i'] : $uid;
$range = TiHoursInsights::range($_POST);
try { $ds = TiHoursInsights::dataset($key, $who, $range); }
catch (\Throwable $e) { error_log('godziny: eksport ' . $key . ' — ' . $e->getMessage()); http_response_code(500); exit('Nie udało się przygotować zestawienia.'); }
$file = 'godziny_' . $key . '_' . ($range['m'] ?: $range['from'] . '_' . $range['to']) . '.' . $fmt;
if ($fmt === 'csv') { AnalyticsExport::csv($ds, $file); exit; }
AnalyticsExport::xlsx($ds, (string)($me['name'] ?? ''), $file);
