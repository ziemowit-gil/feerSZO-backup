<?php
/**
 * karty30/ti/dydaktyk/sale_rezerwacje_export.php — Wykaz sal do rezerwacji w Wordzie/Excelu.
 * Wariant sale_rezerwacje_pdf.php — te same dane i parametry:
 * GET: format=docx|xlsx, range (week|month|quarter), w, view (day|operator), operator.
 * Płaska tabela (jeden wiersz = jeden termin, data/operator w kolumnie, bez nagłówków
 * grupujących), z wierszem nagłówkowym — czytelna dla czytników ekranu.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak dostępu.'); }
karty30_migrate();
ti_planner_ext_migrate();

$range = in_array($_GET['range'] ?? '', ['week', 'month', 'quarter'], true) ? $_GET['range'] : 'week';
$view  = ($_GET['view'] ?? '') === 'operator' ? 'operator' : 'day';
$status = ti_room_reservation_status_param((string)($_GET['status'] ?? ''));
$RR    = ti_room_reservation_range((string)($_GET['w'] ?? ''), $range);
$from  = $RR['from']; $to = $RR['to'];

$by_group = $view === 'operator' ? ti_room_reservation_report_by_operator($from, $to) : ti_room_reservation_report($from, $to);
$by_group = ti_room_reservation_filter_status($by_group, $status);
$operator = $view === 'operator' ? trim((string)($_GET['operator'] ?? '')) : '';
if ($operator !== '') $by_group = [$operator => $by_group[$operator] ?? []];

$title = 'Wykaz sal do rezerwacji' . ($operator !== '' ? ' — ' . $operator : ($view === 'operator' ? ' — wg operatora' : ''))
       . ($status !== 'all' ? ' — ' . ti_room_reservation_status_label($status) : '');

if ($view === 'operator') {
    $head = ['Operator', 'Dzień tygodnia', 'Od', 'Do', 'Godziny', 'Sala (nazwa operatora)', 'Grupa', 'Prowadzący', 'Status'];
    $w = [1800, 1500, 1200, 1200, 1400, 2400, 2200, 1800, 1800];
} else {
    $head = ['Data', 'Dzień tygodnia', 'Godziny', 'Sala / lokalizacja', 'Grupa', 'Prowadzący', 'Status'];
    $w = [1400, 1700, 1500, 3000, 3000, 2200, 1700];
}
$rows = [];
foreach ($by_group as $key => $items) {
    foreach ($items as $r) {
        if ($view === 'operator') {
            $rows[] = [(string)$key, TI_DAYS_PL_FULL[$r['dow']] ?? '', date('d.m.Y', strtotime($r['date_from'])), date('d.m.Y', strtotime($r['date_to'])),
                $r['time_from'] . '–' . $r['time_to'], (string)$r['room_customary'], (string)$r['course_name'], (string)$r['instructor_label'],
                $r['pending'] === 0 ? 'Potwierdzone' : (int)$r['pending'] . '/' . (int)$r['count'] . ' do rezerwacji'];
        } else {
            $rows[] = [date('d.m.Y', strtotime($key)), TI_DAYS_PL_FULL[(int)date('N', strtotime($key))] ?? '',
                substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5), (string)$r['room_label'],
                (string)$r['course_name'], (string)$r['instructor_label'],
                $r['room_reservation_status'] === 'potwierdzone' ? 'Potwierdzone' : 'Do rezerwacji'];
        }
    }
}

$fmt   = ($_GET['format'] ?? 'docx') === 'xlsx' ? 'xlsx' : 'docx';
$fname = 'wykaz_sal' . ($status !== 'all' ? '_' . $status : '') . ($operator !== '' ? '_' . preg_replace('/[^a-z0-9]+/i', '_', $operator) : ($view === 'operator' ? '_operator' : ''))
       . '_' . preg_replace('/[^a-z0-9]+/i', '_', $from . '_' . $to) . '.' . $fmt;
ti_print_log_add('sale_rezerwacje_' . $fmt, $title . ' ' . strtoupper($fmt) . ' — ' . $RR['label'], 0, 0, ['range' => $range, 'view' => $view, 'operator' => $operator], $me);

if ($fmt === 'xlsx') {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
    $x = new XlsxWriter();
    $x->addSheet('Rezerwacje sal');
    $x->writeRow($head, ['header']);
    foreach ($rows as $r) $x->writeRow($r);
    $x->addSheet('Informacje');
    $x->writeRow(['Raport', 'Okres', 'Wygenerowano', 'Przez'], ['header']);
    $x->writeRow([$title, $RR['label'], date('d.m.Y H:i'), (string)($me['name'] ?? '')]);
    $x->output($fname);
}

require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';
error_reporting(error_reporting() & ~E_DEPRECATED);
$pw = new \PhpOffice\PhpWord\PhpWord();
$pw->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
$pw->getDocInfo()->setTitle($title . ' — ' . $RR['label']);
$pw->addTitleStyle(1, ['bold' => true, 'size' => 16]);
$sec = $pw->addSection(['orientation' => 'landscape']);
$sec->addFooter()->addText('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . htmlspecialchars((string)($me['name'] ?? ''), ENT_XML1), ['size' => 8]);
$sec->addTitle(htmlspecialchars($title, ENT_XML1), 1);
$sec->addText(htmlspecialchars($RR['label'], ENT_XML1) . '. Liczba terminów: ' . count($rows) . '.', ['size' => 10]);
if (!$rows) {
    $sec->addText('Brak terminów z przypisaną salą w wybranym okresie.');
} else {
    $t = $sec->addTable(['borderSize' => 6, 'borderColor' => '666666', 'cellMargin' => 60]);
    $t->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
    foreach ($head as $i => $h) $t->addCell($w[$i], ['bgColor' => 'E2E8F0'])->addText($h, ['bold' => true, 'size' => 9]);
    foreach ($rows as $r) {
        $t->addRow(null, ['cantSplit' => true]);
        foreach ($r as $i => $v) $t->addCell($w[$i])->addText(htmlspecialchars((string)$v, ENT_XML1), ['size' => 9]);
    }
}
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache');
$pw->save('php://output', 'Word2007');
exit;
