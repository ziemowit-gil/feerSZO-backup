<?php
/**
 * karty30/ti/kursant/plan_nauczania_export.php — plan nauczania kursu kursanta do Worda/Excela.
 * GET: course (id), format=docx|xlsx. Tylko kursy, na które kursant jest zapisany (aktywnie).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_curriculum_export/logic/export.php';

karty30_migrate();
$student = student_require();
$cid = (int)($_GET['course'] ?? 0);
$course = db_one(
    "SELECT c.name FROM k30_ti_courses c
     JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=? AND e.status='active'
     WHERE c.id=?", [(int)$student['client_id'], $cid]);
if (!$course) { http_response_code(403); exit('Brak dostępu do tego kursu.'); }

$rows = ti_curr_export_rows($cid, true);
$fmt  = ($_GET['format'] ?? 'docx') === 'xlsx' ? 'xlsx' : 'docx';
$fmt === 'xlsx'
    ? ti_curr_export_xlsx((string)$course['name'], $rows, false, '')
    : ti_curr_export_docx((string)$course['name'], $rows, false, '');
