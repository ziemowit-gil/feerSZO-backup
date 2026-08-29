<?php
/**
 * karty30/ti/lesson.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Podgląd/edycja pojedynczej lekcji stoi teraz w panelu (karta lekcji w
 * zakładce Lekcje — karty30/ti/dydaktyk/_lekcja_karta.php, otwierana przez
 * ?tab=lekcje&lesson=<id>). Operacje (obecność, status, odwołania, link)
 * mają tam pełne odpowiedniki (save_lesson, save_attendance, cancel_attendee/
 * restore_attendee, mark_no_show, confirm_cancel/reject_cancel).
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';

$session_id = (int)($_GET['id'] ?? 0);
$course_id  = $session_id ? (int)(db_one("SELECT course_id FROM k30_ti_sessions WHERE id=?", [$session_id])['course_id'] ?? 0) : 0;

$target = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/index.php?tab=lekcje'
        . ($course_id ? '&course=' . $course_id : '')
        . ($session_id ? '&lesson=' . $session_id : '');
header('Location: ' . $target, true, 302);
exit;
