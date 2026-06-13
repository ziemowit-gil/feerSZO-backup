<?php
/**
 * API: Preferencje powiadomień per obszar roboczy
 * POST JSON: { _csrf, workspace_id, notify_email, notify_sms, notify_push }
 * GET  ?workspace_id=X  → zwraca bieżące prefs
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
task_areas_migrate();

$uid = (int)(current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ws_id = (int)($_GET['workspace_id'] ?? 0);
    if (!$ws_id) task_api_error('Brak workspace_id.');
    $row = db_one(
        "SELECT notify_email, notify_sms, notify_push
         FROM task_workspace_members WHERE workspace_id=? AND user_id=?",
        [$ws_id, $uid]
    );
    task_api_ok($row ?: ['notify_email' => 1, 'notify_sms' => 0, 'notify_push' => 0]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body = task_parse_json_body();
task_csrf_check($body);

$ws_id = (int)($body['workspace_id'] ?? 0);
if (!$ws_id) task_api_error('Brak workspace_id.');

$mem = db_one(
    "SELECT user_id FROM task_workspace_members WHERE workspace_id=? AND user_id=?",
    [$ws_id, $uid]
);
if (!$mem) task_api_error('Nie jesteś członkiem tego obszaru.', 403);

$email = (int)(bool)($body['notify_email'] ?? 0);
$sms   = (int)(bool)($body['notify_sms']   ?? 0);
$push  = (int)(bool)($body['notify_push']  ?? 0);

db()->prepare(
    "UPDATE task_workspace_members
     SET notify_email=?, notify_sms=?, notify_push=?
     WHERE workspace_id=? AND user_id=?"
)->execute([$email, $sms, $push, $ws_id, $uid]);

task_api_ok(['notify_email' => $email, 'notify_sms' => $sms, 'notify_push' => $push]);
