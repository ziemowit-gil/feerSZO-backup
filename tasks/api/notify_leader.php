<?php
/**
 * API: Powiadom lidera o problemie z zadaniem
 * POST { _csrf, task_id, message }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';

require_login();

$body = task_parse_json_body();
task_csrf_check($body);

$uid     = (int)(current_user()['id'] ?? 0);
$actor   = current_user();
$task_id = (int)($body['task_id'] ?? 0);
$message = trim($body['message'] ?? '');

if (!$task_id)  task_api_error('Brak task_id.');
if (!$message)  task_api_error('Wiadomość nie może być pusta.');
if (mb_strlen($message) > 1000) task_api_error('Wiadomość jest za długa (max 1000 znaków).');

$task = db_one(
    "SELECT t.*, tl.workspace_id, tl.name AS list_name, tw.name AS ws_name
     FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     JOIN task_workspaces tw ON tw.id = tl.workspace_id
     WHERE t.id = ? AND t.deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);

task_require_workspace_access((int)$task['workspace_id']);

// Zapisz jako komentarz systemowy w historii
task_log($task_id, $uid, 'leader_notified', null, mb_substr($message, 0, 120));

// Wyślij e-mail do liderów obszaru + adminów systemu
$org   = defined('ORG_NAME') ? ORG_NAME : 'System';
$url   = rtrim(APP_URL, '/') . '/tasks/index.php';
$from  = "noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$leaders = db_all(
    "SELECT u.email, u.name
     FROM task_workspace_members twm
     JOIN users u ON u.id = twm.user_id
     WHERE twm.workspace_id = ? AND twm.role IN ('admin','editor') AND u.is_active = 1",
    [(int)$task['workspace_id']]
);
$sys_admins = db_all("SELECT email, name FROM users WHERE role = 'admin' AND is_active = 1");

$seen = [];
$recipients = [];
foreach (array_merge($leaders, $sys_admins) as $r) {
    $e = $r['email'] ?? '';
    if ($e && !isset($seen[$e])) { $seen[$e] = true; $recipients[] = $r; }
}

if (!$recipients) task_api_error('Brak liderów do powiadomienia. Skontaktuj się z administratorem.');

$subject = "[{$org}] Problem z zadaniem: {$task['title']}";
$body_txt = <<<TXT
Zgłoszenie problemu od: {$actor['name']}
Obszar:  {$task['ws_name']}
Zadanie: {$task['title']} (ID: {$task_id})

Treść zgłoszenia:
{$message}

---
Szczegoly zadania: {$url}
TXT;

$headers = "From: {$from}\r\nReply-To: " . ($actor['email'] ?? $from) . "\r\n"
         . "Content-Type: text/plain; charset=utf-8\r\n";

$sent = 0;
foreach ($recipients as $r) {
    if (@mail($r['email'], $subject, $body_txt, $headers)) $sent++;
}

// Wiadomości wewnętrzne do każdego lidera
$internal_body  = "Zgłoszenie problemu z zadaniem: **{$task['title']}**\n";
$internal_body .= "Obszar: {$task['ws_name']}\n\n";
$internal_body .= "Treść:\n{$message}";

foreach ($recipients as $r) {
    if (empty($r['email'])) continue;
    // znajdź user_id po emailu
    $ru = db_one("SELECT id FROM users WHERE email=? AND is_active=1", [$r['email']]);
    if ($ru) {
        task_msg_send(
            $task_id,
            $uid,
            $actor['name'],
            (int)$ru['id'],
            "Problem: {$task['title']}",
            $internal_body
        );
    }
}

task_api_ok(['sent' => $sent]);
