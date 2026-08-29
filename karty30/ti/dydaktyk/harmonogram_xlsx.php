<?php
/**
 * karty30/ti/dydaktyk/harmonogram_xlsx.php — Plan zajęć grupy (siatka tygodniowa)
 * jako plik XLSX — wariant harmonogram_pdf.php dla ucznia/rodzica.
 * GET: course_id (wymagane). Dostęp: dydaktyk posiadający kurs (własny/staff).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';

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

$lm_labels = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'zdalnie (Zoom)', 'zdalna_inne' => 'zdalnie'];

$_contact = array_filter([$course['instructor_email'] ?? '', $course['instructor_phone'] ?? '']);

$x = new XlsxWriter();
$x->addSheet('Plan zajęć');
$x->writeRow(['Plan zajęć — ' . $course['name']], ['header']);
$x->writeRow(['Prowadzący: ' . ($course['instructor_name'] ?: '—')]);
if ($WP['first_lesson'] !== '') $x->writeRow(['Zajęcia od: ' . date('d.m.Y', strtotime($WP['first_lesson']))]);
if ($_contact) $x->writeRow(['Kontakt do prowadzącego: ' . implode(' · ', $_contact)]);
$x->writeRow([]);

$header = ['Godzina'];
foreach ($DOW_COLS as $dlabel) $header[] = $dlabel;
$x->writeRow($header, ['header']);

foreach ($rows_time as $tk => $t) {
    $row = [substr((string)$t['from'], 0, 5) . '–' . substr((string)$t['to'], 0, 5)];
    foreach (array_keys($DOW_COLS) as $dow) {
        $slot = $grid[$tk][$dow] ?? null;
        if (!$slot) { $row[] = ''; continue; }
        $lm = $lm_labels[(string)($slot['lesson_method'] ?? '')] ?? '';
        $row[] = $course['name'] . ($lm !== '' ? ' (' . $lm . ')' : '');
    }
    $x->writeRow($row);
}

if (!$rows_time) {
    $x->writeRow(['Brak zaplanowanych terminów — harmonogram nie został jeszcze ustalony.']);
}

$x->writeRow([]);
$x->writeRow(['Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? '')]);

ti_print_log_add('harmonogram_xlsx', 'Plan zajęć XLSX (dla ucznia/rodzica) — ' . $course['name'], $course_id, 0, [], $me);
$fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', $course['name']) . '.xlsx';
$x->output($fname);
