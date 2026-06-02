<?php
/**
 * API: Poproś osobę z komórki org o przejęcie zadania
 * POST { _csrf, task_id, target_user_id, message? }
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

$uid            = (int)(current_user()['id'] ?? 0);
$actor          = current_user();
$task_id        = (int)($body['task_id']        ?? 0);
$target_user_id = (int)($body['target_user_id'] ?? 0);
$message        = trim($body['message'] ?? '');

if (!$task_id)        task_api_error('Brak task_id.');
if (!$target_user_id) task_api_error('Wybierz osobę.');

// Pobierz zadanie
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
if ($task['completed_at']) task_api_error('Zadanie jest już ukończone.');

// Pobierz docelowego użytkownika
$target = db_one(
    "SELECT id, name, email FROM users WHERE id = ? AND is_active = 1",
    [$target_user_id]
);
if (!$target) task_api_error('Wybrany użytkownik nie istnieje lub jest nieaktywny.');

// Sprawdź czy target jest w tej samej komórce co actor
// (admini systemu mogą prosić kogokolwiek z aktywnych)
$is_admin_sys = (db_one("SELECT role FROM users WHERE id=?", [$uid])['role'] ?? '') === 'admin';

if (!$is_admin_sys) {
    $actor_units = db_all(
        "SELECT unit_id FROM org_members WHERE user_id = ? AND status = 'active'",
        [$uid]
    );
    $actor_unit_ids = array_column($actor_units, 'unit_id');

    if ($actor_unit_ids) {
        $ph     = implode(',', array_fill(0, count($actor_unit_ids), '?'));
        $shared = db_one(
            "SELECT COUNT(*) AS n FROM org_members
             WHERE user_id = ? AND unit_id IN ({$ph}) AND status = 'active'",
            array_merge([$target_user_id], $actor_unit_ids)
        );
        if ((int)($shared['n'] ?? 0) === 0) {
            task_api_error('Wybrany użytkownik nie należy do Twojej komórki organizacyjnej.');
        }
    }
}

// Zapisz zdarzenie w historii
task_log($task_id, $uid, 'takeover_requested', null, $target['name'],
    ['target_user_id' => $target_user_id, 'message' => $message ?: null]);

// Wyślij e-mail do wybranej osoby
$org     = defined('ORG_NAME') ? ORG_NAME : 'System';
$url     = rtrim(APP_URL, '/') . '/tasks/index.php';
$from    = "noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$headers = "From: {$from}\r\nReply-To: " . ($actor['email'] ?? $from) . "\r\n"
         . "Content-Type: text/plain; charset=utf-8\r\n";

$body_txt  = "Czesc {$target['name']},\n\n";
$body_txt .= "{$actor['name']} chce przekazac Ci zadanie — wymagana jest Twoja decyzja.\n\n";
$body_txt .= "Zadanie: \"{$task['title']}\"\n";
$body_txt .= "Obszar: {$task['ws_name']}\n";
if ($message) {
    $body_txt .= "\nKomentarz:\n{$message}\n";
}
$body_txt .= "\nAkceptuj lub odrzuc prosbe w skrzynce zadan:\n{$url}\n";

// E-mail
@mail($target['email'], "[{$org}] Przekazanie zadania — wymagana decyzja: {$task['title']}", $body_txt, $headers);

// Wiadomość wewnętrzna — subject z markerem 'transfer:task_id' odczytywanym przez inbox.php
$internal_body  = "Chce przekazac Ci zadanie: **{$task['title']}**\n";
$internal_body .= "Obszar: {$task['ws_name']}\n";
if ($message) $internal_body .= "\nMoj komentarz:\n{$message}";
$internal_body .= "\n\nUzyj przyciskow ponizej aby zaakceptowac lub odrzucic.";

task_msg_send(
    $task_id,
    $uid,
    $actor['name'],
    $target_user_id,
    "transfer:{$task_id}:{$task['title']}",
    $internal_body
);

task_api_ok(['target_name' => $target['name']]);
