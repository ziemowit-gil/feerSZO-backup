<?php
/**
 * karty30/ti/kursant/msg_attachment.php — pobranie załącznika wiadomości
 * od/do prowadzącego, z poziomu panelu kursanta. GET: id.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';

$student = student_require();

$id  = (int)($_GET['id'] ?? 0);
$att = $id ? db_one("SELECT * FROM k30_ti_message_attachments WHERE id=? AND kind='student'", [$id]) : null;
if (!$att) { http_response_code(404); exit('Nie znaleziono załącznika.'); }

$ok = (bool)db_one(
    "SELECT 1 FROM k30_ti_messages WHERE id=? AND student_id=?",
    [(int)$att['message_id'], (int)$student['id']]
);
if (!$ok) { http_response_code(403); exit('Brak uprawnień do tego załącznika.'); }

$path = UPLOAD_DIR . $att['stored_path'];
if (!is_file($path)) { http_response_code(404); exit('Plik nie istnieje na dysku.'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($att['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
