<?php
/**
 * karty30/ti/kursant/homework_file.php — pobieranie plików zadań przez kursanta.
 * t=attach&hw=ID  → załącznik prowadzącego (gdy kursant w kursie)
 * t=sub&id=SUBID  → własny przesłany plik kursanta
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student = student_current();
if (!$student) { http_response_code(401); exit('Sesja wygasła.'); }
$cid = (int)$student['client_id'];
$t   = $_GET['t'] ?? '';

if ($t === 'attach') {
    $hw = db_one(
        "SELECT * FROM k30_ti_homework
         WHERE id=? AND is_active=1
           AND course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')",
        [(int)($_GET['hw'] ?? 0), $cid]
    );
    if ($hw && $hw['attach_path'] !== '') k30_ti_homework_send_file($hw['attach_path'], $hw['attach_name']);
} elseif ($t === 'sub') {
    $s = db_one(
        "SELECT * FROM k30_ti_homework_submissions WHERE id=? AND client_id=?",
        [(int)($_GET['id'] ?? 0), $cid]
    );
    if ($s && $s['file_path'] !== '') k30_ti_homework_send_file($s['file_path'], $s['file_name']);
}

http_response_code(404);
exit('Plik nie istnieje.');
