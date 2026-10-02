<?php
/**
 * karty30/ti/kursant/lessons_export.php — harmonogram zajęć kursanta do Worda/Excela.
 * GET: format=docx|xlsx, all=1 => wszystkie lekcje (domyślnie: ostatnie 30 dni + nadchodzące).
 * Wariant lessons_pdf.php; tabela z wierszem nagłówkowym, status i obecność jako tekst.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student = student_require();
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];
$name    = trim((string)($client['name'] ?? $student['login'] ?? 'Kursant'));
$all     = !empty($_GET['all']);

$lessons = db_all(
    "SELECT s.lesson_date, s.time_from, s.time_to, s.topic, s.status, c.name AS course_name,
            a.attended, a.cancelled AS att_cancelled
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
     WHERE s.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
     " . ($all ? '' : "AND s.lesson_date >= date('now','-30 days')") . "
     ORDER BY s.lesson_date ASC, s.time_from ASC LIMIT 2000",
    [$student['client_id'], $student['client_id']]
);

$days = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
$head = ['Data', 'Dzień tygodnia', 'Godziny', 'Kurs', 'Temat', 'Status', 'Obecność'];
$rows = [];
foreach ($lessons as $l) {
    $ts = strtotime((string)$l['lesson_date']);
    $att = (int)($l['att_cancelled'] ?? 0) === 1 ? 'Odwołana przez uczestnika'
         : ($l['attended'] === null ? '—' : ((int)$l['attended'] === 1 ? 'Obecny' : 'Nieobecny'));
    $rows[] = [
        $ts ? date('d.m.Y', $ts) : '', $ts ? $days[(int)date('w', $ts)] : '',
        ($l['time_from'] && $l['time_to']) ? substr((string)$l['time_from'], 0, 5) . '–' . substr((string)$l['time_to'], 0, 5) : '—',
        (string)$l['course_name'], trim((string)$l['topic']) !== '' ? (string)$l['topic'] : '—',
        K30_TI_SESSION_STATUSES[$l['status']]['label'] ?? (string)$l['status'], $att,
    ];
}
$scope = $all ? 'wszystkie lekcje' : 'ostatnie 30 dni i nadchodzące';
$fname = 'harmonogram_zajec_' . date('Ymd');

if (($_GET['format'] ?? 'docx') === 'xlsx') {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
    $x = new XlsxWriter();
    $x->addSheet('Harmonogram zajęć');
    $x->writeRow($head, ['header']);          // nagłówek w A1 — czytnik ekranu rozpozna kolumny
    foreach ($rows as $r) $x->writeRow($r);
    $x->addSheet('Informacje');
    $x->writeRow(['Uczestnik', 'Zakres', 'Wygenerowano'], ['header']);
    $x->writeRow([$name, $scope, date('d.m.Y H:i')]);
    $x->output($fname . '.xlsx');
}

require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';
error_reporting(error_reporting() & ~E_DEPRECATED);
$pw = new \PhpOffice\PhpWord\PhpWord();
$pw->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
$pw->getDocInfo()->setTitle('Harmonogram zajęć — ' . $name);
$pw->addTitleStyle(1, ['bold' => true, 'size' => 16]);
$sec = $pw->addSection(['orientation' => 'landscape']);
$sec->addFooter()->addText('Wygenerowano: ' . date('d.m.Y H:i'), ['size' => 8]);
$sec->addTitle('Harmonogram zajęć — ' . htmlspecialchars($name, ENT_XML1), 1);
$sec->addText('Zakres: ' . $scope . '. Liczba zajęć: ' . count($rows) . '.', ['size' => 10]);
if (!$rows) {
    $sec->addText('Brak zajęć w wybranym zakresie.');
} else {
    $w = [1400, 1700, 1500, 3000, 4000, 1500, 1800];
    $t = $sec->addTable(['borderSize' => 6, 'borderColor' => '666666', 'cellMargin' => 60]);
    $t->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
    foreach ($head as $i => $h) $t->addCell($w[$i], ['bgColor' => 'E2E8F0'])->addText($h, ['bold' => true, 'size' => 10]);
    foreach ($rows as $r) {
        $t->addRow(null, ['cantSplit' => true]);
        foreach ($r as $i => $v) $t->addCell($w[$i])->addText(htmlspecialchars((string)$v, ENT_XML1), ['size' => 10]);
    }
}
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fname . '.docx"');
header('Cache-Control: no-cache');
$pw->save('php://output', 'Word2007');
exit;
