<?php
/**
 * API: Śledzenie czasu pracy dla zadania
 * POST { _csrf, action, task_id, [note], [log_id] }
 *
 * action:
 *   start   — rozpocznij timer (zamknij poprzedni jeśli otwarty)
 *   stop    — zatrzymaj aktywny timer
 *   delete  — usuń wpis czasu
 *   manual  — ręczny wpis (started_at, ended_at, note)
 *   summary — pobierz podsumowanie dla zadania (GET)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/volunteer_hours.php';

require_login();

// GET: podsumowanie
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $task_id = (int)($_GET['task_id'] ?? 0);
    if (!$task_id) task_api_error('Brak task_id.');
    $task = db_one("SELECT workspace_id FROM tasks WHERE id=? AND deleted_at IS NULL", [$task_id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);
    task_require_workspace_access((int)$task['workspace_id']);

    $logs = db_all(
        "SELECT tl.*, u.name AS user_name
         FROM task_time_logs tl
         JOIN users u ON u.id = tl.user_id
         WHERE tl.task_id = ?
         ORDER BY tl.started_at DESC",
        [$task_id]
    );
    $total = array_sum(array_column($logs, 'duration_seconds'));
    $uid   = (int)(current_user()['id'] ?? 0);
    $active = db_one(
        "SELECT id, started_at FROM task_time_logs WHERE task_id=? AND user_id=? AND ended_at IS NULL",
        [$task_id, $uid]
    );
    task_api_ok(['logs' => $logs, 'total_seconds' => $total, 'active' => $active]);
}

$body   = task_parse_json_body();
task_csrf_check($body);

$uid     = (int)(current_user()['id'] ?? 0);
$action  = $body['action'] ?? '';
$task_id = (int)($body['task_id'] ?? 0);
$now     = date('Y-m-d H:i:s');

if (!$task_id) task_api_error('Brak task_id.');

$task = db_one(
    "SELECT id, workspace_id, title, estimated_hours FROM tasks WHERE id=? AND deleted_at IS NULL",
    [$task_id]
);
if (!$task) task_api_error('Zadanie nie istnieje.', 404);
task_require_workspace_access((int)$task['workspace_id']);

switch ($action) {

    case 'start':
        // Zamknij poprzedni aktywny timer tego usera na to zadanie
        $active = db_one(
            "SELECT id, started_at FROM task_time_logs WHERE task_id=? AND user_id=? AND ended_at IS NULL",
            [$task_id, $uid]
        );
        if ($active) {
            $secs = max(0, strtotime($now) - strtotime($active['started_at']));
            db()->prepare("UPDATE task_time_logs SET ended_at=?, duration_seconds=? WHERE id=?")
                ->execute([$now, $secs, $active['id']]);
        }
        // Zamknij też timery na INNYCH zadaniach tego usera
        $other_active = db_all(
            "SELECT id, started_at FROM task_time_logs WHERE user_id=? AND task_id!=? AND ended_at IS NULL",
            [$uid, $task_id]
        );
        foreach ($other_active as $oa) {
            $secs = max(0, strtotime($now) - strtotime($oa['started_at']));
            db()->prepare("UPDATE task_time_logs SET ended_at=?, duration_seconds=? WHERE id=?")
                ->execute([$now, $secs, $oa['id']]);
        }
        $log_id = db_insert('task_time_logs', [
            'task_id'    => $task_id,
            'user_id'    => $uid,
            'started_at' => $now,
            'note'       => null,
            'created_at' => $now,
        ]);
        task_log($task_id, $uid, 'time_started');
        // Start zamyka poprzednie timery (ustawia im duration) → przelicz godziny.
        volunteer_recompute_for_user($uid);
        task_api_ok(['log_id' => $log_id, 'started_at' => $now]);
        break;

    case 'stop':
        $note   = trim($body['note'] ?? '');
        $active = db_one(
            "SELECT id, started_at FROM task_time_logs WHERE task_id=? AND user_id=? AND ended_at IS NULL",
            [$task_id, $uid]
        );
        if (!$active) task_api_error('Brak aktywnego timera dla tego zadania.');
        $secs = max(0, strtotime($now) - strtotime($active['started_at']));
        db()->prepare(
            "UPDATE task_time_logs SET ended_at=?, duration_seconds=?, note=? WHERE id=?"
        )->execute([$now, $secs, $note ?: null, $active['id']]);
        task_log($task_id, $uid, 'time_logged', null, _fmt_duration($secs));
        volunteer_recompute_for_user($uid);
        task_api_ok(['duration_seconds' => $secs, 'formatted' => _fmt_duration($secs)]);
        break;

    case 'manual':
        $started = trim($body['started_at'] ?? '');
        $ended   = trim($body['ended_at']   ?? '');
        $note    = trim($body['note']       ?? '');
        if (!$started || !$ended) task_api_error('Podaj czas rozpoczęcia i zakończenia.');
        $secs = max(0, strtotime($ended) - strtotime($started));
        if ($secs > 86400) task_api_error('Maksymalny czas jednego wpisu to 24 godziny.');
        $log_id = db_insert('task_time_logs', [
            'task_id'          => $task_id,
            'user_id'          => $uid,
            'started_at'       => $started,
            'ended_at'         => $ended,
            'duration_seconds' => $secs,
            'note'             => $note ?: null,
            'created_at'       => $now,
        ]);
        task_log($task_id, $uid, 'time_logged', null, _fmt_duration($secs));
        volunteer_recompute_for_user($uid);
        task_api_ok(['log_id' => $log_id, 'duration_seconds' => $secs]);
        break;

    case 'delete':
        $log_id = (int)($body['log_id'] ?? 0);
        $log    = db_one("SELECT * FROM task_time_logs WHERE id=? AND task_id=?", [$log_id, $task_id]);
        if (!$log) task_api_error('Wpis nie istnieje.');
        // Można usunąć własny lub być adminem obszaru
        $can = ((int)$log['user_id'] === $uid)
            || in_array(task_workspace_role((int)$task['workspace_id'], $uid), ['admin'], true);
        if (!$can) task_api_error('Brak uprawnień do usunięcia tego wpisu.');
        db()->prepare("DELETE FROM task_time_logs WHERE id=?")->execute([$log_id]);
        volunteer_recompute_for_user((int)$log['user_id']);
        task_api_ok();
        break;

    default:
        task_api_error('Nieznana akcja: ' . $action);
}

function _fmt_duration(int $secs): string {
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    if ($h) return "{$h}h {$m}min";
    return "{$m}min";
}
