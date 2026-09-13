<?php
/**
 * karty30/ti/dydaktyk/msg_attachment.php — pobranie załącznika wiadomości
 * (kursant↔prowadzący albo do kierownictwa) z poziomu panelu dydaktyka.
 * GET: id (id wiersza w k30_ti_message_attachments).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];

$id  = (int)($_GET['id'] ?? 0);
$att = $id ? db_one("SELECT * FROM k30_ti_message_attachments WHERE id=?", [$id]) : null;
if (!$att) { http_response_code(404); exit('Nie znaleziono załącznika.'); }

$course_ids = array_column(dyd_courses($uid), 'id');
$ph = implode(',', array_fill(0, max(count($course_ids), 1), '?'));
$params = $course_ids ?: [0];

$ok = false;
if ($att['kind'] === 'student') {
    $ok = (bool)db_one(
        "SELECT 1 FROM k30_ti_messages m
         JOIN k30_ti_student_accounts a ON a.id = m.student_id
         JOIN k30_ti_enrollments e ON e.client_id = a.client_id
         WHERE m.id=? AND e.course_id IN ($ph) AND e.status='active' LIMIT 1",
        array_merge([(int)$att['message_id']], $params)
    );
} elseif ($att['kind'] === 'admin') {
    // Wątek do kierownictwa: własna wiadomość albo kierownik/administrator.
    $ok = dyd_is_staff() || (bool)db_one(
        "SELECT 1 FROM k30_ti_admin_msgs WHERE id=? AND user_id=?",
        [(int)$att['message_id'], $uid]
    );
}
if (!$ok) { http_response_code(403); exit('Brak uprawnień do tego załącznika.'); }

$path = UPLOAD_DIR . $att['stored_path'];
if (!is_file($path)) { http_response_code(404); exit('Plik nie istnieje na dysku.'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($att['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
