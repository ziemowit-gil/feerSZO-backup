<?php
/**
 * karty30/ti/dydaktyk/plan_nauczania_export.php — plan nauczania kursu do Worda/Excela.
 * GET: course (id), format=docx|xlsx. Dostęp: prowadzący kursu lub kierownik (D3).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_curriculum_export/logic/export.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
$cid = (int)($_GET['course'] ?? 0);
if (!dyd_owns_course($uid, $cid)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }
$course = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid]);
if (!$course) { http_response_code(404); exit('Nie znaleziono kursu.'); }

$rows = ti_curr_export_rows($cid, false);
$fmt  = ($_GET['format'] ?? 'docx') === 'xlsx' ? 'xlsx' : 'docx';
$fmt === 'xlsx'
    ? ti_curr_export_xlsx((string)$course['name'], $rows, true, (string)($me['name'] ?? ''))
    : ti_curr_export_docx((string)$course['name'], $rows, true, (string)($me['name'] ?? ''));
