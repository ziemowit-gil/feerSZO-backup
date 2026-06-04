<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
require_login();

$att_id = (int)($_GET['id'] ?? 0);
$att    = $att_id ? db_one("SELECT * FROM helpdesk_attachments WHERE id=?", [$att_id]) : null;
if (!$att) { http_response_code(404); die('Plik nie istnieje.'); }

$ticket = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [(int)$att['ticket_id']]);
if (!$ticket || !hd_can_view_ticket($ticket)) { http_response_code(403); die('Brak dostępu.'); }

$path = UPLOAD_DIR . $att['stored_path'];
if (!file_exists($path)) { http_response_code(404); die('Plik nie znaleziono na dysku.'); }

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes($att['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
