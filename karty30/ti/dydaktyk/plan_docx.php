<?php
/**
 * karty30/ti/dydaktyk/plan_docx.php — Plan zajęć prowadzącego do pobrania (DOCX).
 * Wariant plan_print.php (widok HTML) — te same dane (ti_instructor_plan_data()).
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

$PD = ti_instructor_plan_data($target_uid, (int)($_GET['weeks'] ?? 8));
$instructor = $PD['instructor'];
if (!$instructor) { http_response_code(404); exit('Nie znaleziono prowadzącego.'); }

$days_pl   = [1=>'Poniedziałek',2=>'Wtorek',3=>'Środa',4=>'Czwartek',5=>'Piątek',6=>'Sobota',7=>'Niedziela'];
$months_pl = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
$org = defined('APP_ORG') ? APP_ORG : (defined('ORG_NAME') ? ORG_NAME : '');

require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
$section = $phpWord->addSection();

$section->addText('Plan zajęć — ' . $instructor['name'], ['bold' => true, 'size' => 16]);
$section->addText(
    ($org !== '' ? $org . '   ·   ' : '') . $PD['from'] . ' – ' . $PD['to'] . ' (' . $PD['weeks'] . ' tyg.)'
    . '   ·   Wygenerowano: ' . date('d.m.Y H:i'),
    ['size' => 9, 'color' => '555555']
);
$section->addTextBreak(1);

if (!$PD['by_week']) {
    $section->addText('Brak zajęć w wybranym okresie.');
} else {
    foreach ($PD['by_week'] as $week_start => $wsessions) {
        $ws_ts = strtotime($week_start);
        $we_ts = strtotime($week_start . ' +6 days');
        $wlabel = date('j', $ws_ts) . ' ' . $months_pl[(int)date('n', $ws_ts)]
                . ' – ' . date('j', $we_ts) . ' ' . $months_pl[(int)date('n', $we_ts)] . ' ' . date('Y', $ws_ts);

        $section->addText('Tydzień ' . $wlabel, ['bold' => true, 'size' => 11], ['spaceBefore' => 200, 'spaceAfter' => 80]);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 60]);
        $table->addRow();
        foreach (['Dzień', 'Godziny', 'Kurs', 'Uczestnicy', 'Status'] as $i => $h) {
            $w = [1600, 1200, 2600, 2200, 1200][$i];
            $table->addCell($w, ['bgColor' => 'F1F5F9'])->addText($h, ['bold' => true, 'size' => 8]);
        }
        foreach ($wsessions as $s) {
            $table->addRow();
            $wd = (int)date('N', strtotime((string)$s['lesson_date']));
            $day_label = $days_pl[$wd] . ' ' . date('j.m', strtotime((string)$s['lesson_date']));
            $time_label = ($s['time_from'] && $s['time_to']) ? substr((string)$s['time_from'],0,5) . '–' . substr((string)$s['time_to'],0,5) : '—';
            $st_label = K30_TI_SESSION_STATUSES[(string)$s['status']]['label'] ?? (string)$s['status'];
            $table->addCell(1600)->addText($day_label, ['size' => 8]);
            $table->addCell(1200)->addText($time_label, ['size' => 8]);
            $table->addCell(2600)->addText((string)$s['course_name'], ['size' => 8]);
            $table->addCell(2200)->addText((string)($s['student_names'] ?? '–'), ['size' => 7]);
            $table->addCell(1200)->addText($st_label, ['size' => 8]);
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
