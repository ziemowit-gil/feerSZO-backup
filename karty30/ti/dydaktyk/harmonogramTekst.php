<?php
/**
 * karty30/ti/dydaktyk/harmonogramTekst.php — lista lekcji grupy (z zaplanowanymi) w formie tekstowej
 * bez tabeli, dla czytników ekranu. GET: course_id, fmt=html|txt.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pdf/logic/exportName.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerExport.php';
require_once dirname(__DIR__, 3) . '/includes/ti_print_log.php';
karty30_migrate();
$me = dyd_require(); $uid = (int)$me['user_id'];
$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id || !dyd_owns_course($uid, $course_id)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }
$fmt = ($_GET['fmt'] ?? 'html') === 'txt' ? 'txt' : 'html';
$ds = sc_lesson_list_dataset($course_id);
ti_print_log_add('harmonogram_' . $fmt, $ds['title'], $course_id, 0, [], $me);
sc_export_text_send($ds, (string)($me['name'] ?? ''), $fmt, ti_export_filename($course_id, 'lista_zajec'));
