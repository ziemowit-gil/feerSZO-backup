<?php
/**
 * karty30/ti/dydaktyk/plan_docx.php — Plan zajęć prowadzącego do pobrania (DOCX).
 * Wariant plan_print.php (widok HTML) — te same dane (ti_instructor_plan_grouped()).
 * Lista: najpierw dzień tygodnia + godzina + kurs, pod spodem konkretne daty.
 * GET: instructor_id (staff — dowolny; zwykły prowadzący tylko swój), weeks.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me       = dyd_require();
$uid      = (int)$me['user_id'];
$is_staff = dyd_is_staff();

$target_uid = $uid;
if ($is_staff && isset($_GET['instructor_id'])) {
    $target_uid = max(1, (int)$_GET['instructor_id']);
}

$PD = ti_instructor_plan_grouped($target_uid, (int)($_GET['weeks'] ?? 8));
$instructor = $PD['instructor'];
if (!$instructor) { http_response_code(404); exit('Nie znaleziono prowadzącego.'); }

$org = defined('APP_ORG') ? APP_ORG : (defined('ORG_NAME') ? ORG_NAME : '');

require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
$section = $phpWord->addSection();

$range = $PD['unbounded'] ? 'Ogólny — cały zapisany plan' : $PD['from'] . ' – ' . $PD['to'] . ' (' . $PD['weeks'] . ' tyg.)';
$section->addText('Plan zajęć — ' . $instructor['name'], ['bold' => true, 'size' => 16]);
$section->addText(
    ($org !== '' ? $org . '   ·   ' : '') . $range . '   ·   Wygenerowano: ' . date('d.m.Y H:i'),
    ['size' => 9, 'color' => '555555']
);
$section->addTextBreak(1);

if (!$PD['groups']) {
    $section->addText('Brak zajęć w wybranym okresie.');
} else {
    foreach ($PD['groups'] as $g) {
        $time_label = ($g['time_from'] && $g['time_to']) ? substr((string)$g['time_from'],0,5) . '–' . substr((string)$g['time_to'],0,5) : '—';
        $section->addText($g['day_label'] . ', ' . $time_label . ' · ' . $g['course_name'],
            ['bold' => true, 'size' => 11], ['spaceBefore' => 200, 'spaceAfter' => 80]);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 60]);
        $table->addRow();
        foreach (['Data', 'Uczestnicy', 'Status'] as $i => $h) {
            $w = [1600, 4200, 1600][$i];
            $table->addCell($w, ['bgColor' => 'F1F5F9'])->addText($h, ['bold' => true, 'size' => 8]);
        }
        foreach ($g['dates'] as $d) {
            $table->addRow();
            $st_label = K30_TI_SESSION_STATUSES[$d['status']]['label'] ?? $d['status'];
            $table->addCell(1600)->addText(date('d.m.Y', strtotime($d['date'])), ['size' => 8]);
            $table->addCell(4200)->addText($d['student_names'] !== '' ? $d['student_names'] : '–', ['size' => 7]);
            $table->addCell(1600)->addText($st_label, ['size' => 8]);
        }
    }
}

ti_print_log_add('plan_docx', 'Plan zajęć DOCX — ' . $instructor['name'], 0, 0, ['weeks' => $PD['weeks']], $me);

$fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', (string)$instructor['name']) . '.docx';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fname . '"');
\PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
exit;
