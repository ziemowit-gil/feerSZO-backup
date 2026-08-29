<?php
/**
 * karty30/ti/dydaktyk/dni_wolne_docx.php — Wykaz dni wolnych/przerw za dany rok (DOCX).
 * GET: year (domyślnie bieżący). Dostęp: kierownik (dyd_is_staff()).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak uprawnień.'); }
karty30_migrate();

$year = (int)($_GET['year'] ?? date('Y'));
$year = max(2020, min(2035, $year));

$items = db_all(
    "SELECT * FROM k30_ti_holidays WHERE strftime('%Y', date_from)=? OR strftime('%Y', date_to)=? ORDER BY date_from",
    [(string)$year, (string)$year]
);

$type_labels = ['holiday' => 'Dzień wolny / święto', 'break' => 'Przerwa w działalności', 'other' => 'Inne'];
$org = defined('ORG_NAME') ? ORG_NAME : '';

require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
$section = $phpWord->addSection();

$section->addText('Wykaz dni wolnych — ' . $year, ['bold' => true, 'size' => 16]);
$section->addText(
    ($org !== '' ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i'),
    ['size' => 9, 'color' => '555555']
);
$section->addTextBreak(1);

if (!$items) {
    $section->addText('Brak wpisów w kalendarzu dla roku ' . $year . '.');
} else {
    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 60]);
    $table->addRow();
    foreach (['Od', 'Do', 'Dni', 'Nazwa', 'Typ', 'Uwagi'] as $i => $h) {
        $w = [1300, 1300, 700, 2600, 2000, 2100][$i];
        $table->addCell($w, ['bgColor' => 'F1F5F9'])->addText($h, ['bold' => true, 'size' => 8]);
    }
    foreach ($items as $h) {
        $hdf = new DateTime($h['date_from']); $hdt = new DateTime($h['date_to']);
        $days = (int)$hdf->diff($hdt)->days + 1;
        $table->addRow();
        $table->addCell(1300)->addText($hdf->format('d.m.Y'), ['size' => 8]);
        $table->addCell(1300)->addText($hdt->format('d.m.Y'), ['size' => 8]);
        $table->addCell(700)->addText((string)$days, ['size' => 8]);
        $table->addCell(2600)->addText((string)$h['name'], ['size' => 8]);
        $table->addCell(2000)->addText($type_labels[(string)$h['type']] ?? (string)$h['type'], ['size' => 8]);
        $table->addCell(2100)->addText((string)($h['note'] ?? ''), ['size' => 7]);
    }
}

ti_print_log_add('dni_wolne_docx', 'Wykaz dni wolnych DOCX — ' . $year, 0, 0, ['year' => $year], $me);

$fname = 'dni_wolne_' . $year . '.docx';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fname . '"');
\PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
exit;
