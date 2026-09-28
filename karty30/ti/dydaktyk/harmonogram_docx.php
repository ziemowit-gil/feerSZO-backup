<?php
/**
 * karty30/ti/dydaktyk/harmonogram_docx.php — Plan zajęć grupy (siatka tygodniowa)
 * jako plik DOCX (PHPWord) — wariant harmonogram_pdf.php dla ucznia/rodzica.
 * GET: course_id (wymagane). Dostęp: dydaktyk posiadający kurs (własny/staff).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id || !dyd_owns_course($uid, $course_id)) {
    http_response_code(403); exit('Brak uprawnień do tego kursu.');
}

$WP = ti_course_weekly_slots($course_id);
$course = $WP['course'];
if (!$course) { http_response_code(404); exit('Nie znaleziono grupy.'); }
$rows_time = $WP['rows_time'];
$grid      = $WP['grid'];
$DOW_COLS  = $WP['dow_cols'];
$org       = defined('ORG_NAME') ? ORG_NAME : '';
$lm_labels = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'zdalnie (Zoom)', 'zdalna_inne' => 'zdalnie'];
$_contact  = array_filter([$course['instructor_email'] ?? '', $course['instructor_phone'] ?? '']);

require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
$section = $phpWord->addSection(['orientation' => 'landscape']);
$footer  = $section->addFooter();
$footer->addText('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? ''), ['size' => 7, 'color' => '828282']);

$section->addText('Plan zajęć — ' . $course['name'], ['bold' => true, 'size' => 16]);
$section->addText(
    ($org !== '' ? $org . '   ·   ' : '') . 'Prowadzący: ' . ($course['instructor_name'] ?: '—'),
    ['size' => 9, 'color' => '555555']
);
$section->addText('Stan na dzień: ' . date('d.m.Y', strtotime($WP['as_of'])) . ($WP['source'] === 'recent' ? ' (brak nadchodzących terminów — plan wg ostatnich zajęć)' : ''), ['size' => 9, 'bold' => true]);
if ($WP['first_lesson'] !== '') {
    $section->addText('Zajęcia od: ' . date('d.m.Y', strtotime($WP['first_lesson'])), ['size' => 9, 'bold' => true]);
}
if ($_contact) {
    $section->addText('Kontakt do prowadzącego: ' . implode(' · ', $_contact), ['size' => 9]);
}
$section->addTextBreak(1);

if (!$rows_time) {
    $section->addText('Brak zaplanowanych terminów — harmonogram nie został jeszcze ustalony.');
} else {
    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);
    $table->addRow();
    $table->addCell(1300, ['bgColor' => 'E0E8F4'])->addText('Godzina', ['bold' => true, 'size' => 9]);
    foreach ($DOW_COLS as $dlabel) {
        $table->addCell(1750, ['bgColor' => 'E0E8F4'])->addText($dlabel, ['bold' => true, 'size' => 9]);
    }
    foreach ($rows_time as $tk => $t) {
        $table->addRow();
        $label = substr((string)$t['from'], 0, 5) . '–' . substr((string)$t['to'], 0, 5);
        $table->addCell(1300)->addText($label, ['bold' => true, 'size' => 9]);
        foreach (array_keys($DOW_COLS) as $dow) {
            $cell = $table->addCell(1750, ($grid[$tk][$dow] ?? null) ? ['bgColor' => 'DCEEDC'] : []);
            $slot = $grid[$tk][$dow] ?? null;
            if ($slot) {
                $lm = $lm_labels[(string)($slot['lesson_method'] ?? '')] ?? '';
                $cell->addText($course['name'], ['bold' => true, 'size' => 8]);
                if ($lm !== '') $cell->addText($lm, ['size' => 7, 'color' => '505050']);
            } else {
                $cell->addText('');
            }
        }
    }
    $section->addTextBreak(1);
    $section->addText(
        'Plan wyznaczony na podstawie ostatnio zaplanowanych/odbytych terminów — może ulec zmianie. '
        . 'Aktualny harmonogram i ewentualne odwołania zawsze widoczne w panelu kursanta.',
        ['size' => 8, 'color' => '6E6E6E']
    );
}

ti_print_log_add('harmonogram_docx', 'Plan zajęć DOCX (dla ucznia/rodzica) — ' . $course['name'], $course_id, 0, [], $me);

$fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', $course['name']) . '.docx';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fname . '"');
\PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
exit;
