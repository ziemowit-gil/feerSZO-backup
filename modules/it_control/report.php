<?php
/**
 * modules/it_control/report.php — pełny raport przebiegu kontroli (ten sam HTML co załącznik e-maila).
 *   ?run=ID            — raport przebiegu
 *   ?latest=1          — ostatnie przebiegi obu kontroli razem
 *   &download=1        — pobranie pliku .html
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/itControlRunner.php';
require_once __DIR__ . '/logic/itControlMail.php';

require_role('admin');

if (!empty($_GET['latest'])) {
    $runs = array_values(array_filter([itControlLatestRun('logs'), itControlLatestRun('ops')]));
    $title = 'Raport kontroli IT — stan bieżący';
} else {
    $run = itControlLoadRun((int)($_GET['run'] ?? 0));
    $runs = $run ? [$run] : [];
    $title = $run ? (ITC_SUITES[$run['suite']]['label'] ?? 'Kontrola') . ' — przebieg #' . (int)$run['id'] : '';
}
if (!$runs) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Nie znaleziono przebiegu kontroli.\n");
}

$html = itControlReportHtml($runs, ['title' => $title, 'dashboard_url' => itControlDashboardUrl()]);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (!empty($_GET['download'])) {
    $name = 'raport_kontroli_IT_' . (count($runs) === 1 ? (int)$runs[0]['id'] . '_' : '') . date('Ymd_His') . '.html';
    header('Content-Disposition: attachment; filename="' . $name . '"');
}
echo $html;
