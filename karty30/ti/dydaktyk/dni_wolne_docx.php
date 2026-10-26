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

// Tabelę zakłada dopiero ekran dni_wolne.php — bez jego odwiedzin eksport kończył się
// błędem krytycznym „no such table”; brak tabeli = pusty wykaz.
try {
    $items = db_all(
    "SELECT * FROM k30_ti_holidays WHERE strftime('%Y', date_from)=? OR strftime('%Y', date_to)=? ORDER BY date_from",
    [(string)$year, (string)$year]
    );
} catch (\Throwable $e) { $items = []; }

$type_labels = ['holiday' => 'Dzień wolny / święto', 'break' => 'Przerwa w działalności', 'other' => 'Inne'];
$org = defined('ORG_NAME') ? ORG_NAME : '';

require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
$section = $phpWord->addSection();
$footer  = $section->addFooter();
$footer->addText('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? ''), ['size' => 7, 'color' => '828282']);
// Stopka z kodami Code128 + QR (rejestr dokumentów) — pliki tymczasowe z PNG usuwane po zapisie; błąd kodów nie blokuje DOCX
$tmp_imgs = [];
try {
    require_once dirname(dirname(dirname(__DIR__))) . '/modules/doc_registry/lib/DocRegistry.php';
    if ($fc = DocRegistry::printCodes('ti_dni_wolne', (string)$year, 'Wykaz dni wolnych ' . $year, 'karty30/ti/dydaktyk/dni_wolne.php')) {
        $tmp_png = function (?string $png) use (&$tmp_imgs) {
            if (!$png || !($p = tempnam(sys_get_temp_dir(), 'dcimg')) || file_put_contents($p, $png) === false) return null;
            return $tmp_imgs[] = $p;
        };
        $run = $footer->addTextRun(['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        if ($p = $tmp_png($fc['bar'])) $run->addImage($p, ['width' => 130, 'height' => 17, 'wrappingStyle' => 'inline']);
        if ($p = $tmp_png($fc['qr'])) $run->addImage($p, ['width' => 26, 'height' => 26, 'wrappingStyle' => 'inline']);
        $footer->addText($fc['code'] . ' · ' . $fc['doc_number'] . ' · weryfikacja: ' . $fc['url'], ['size' => 6.5, 'name' => 'Consolas'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
    }
} catch (\Throwable $e) { error_log('[docx kody w stopce] ' . $e->getMessage()); }

$section->addText('Wykaz dni wolnych — ' . $year, ['bold' => true, 'size' => 16]);
if ($org !== '') {
    $section->addText($org, ['size' => 9, 'color' => '555555']);
}
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
foreach ($tmp_imgs as $p) @unlink($p);
exit;
