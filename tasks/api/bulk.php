<?php
/**
 * API: Zbiorcze akcje na zadaniach
 * POST { _csrf, action, task_ids[], ...params }
 *
 * action:
 *   assign_me    — przypisz bieżącego usera do wszystkich
 *   unassign_me  — odepnij bieżącego usera
 *   priority     — zmień priorytet (priority: 1-4)
 *   move         — przenieś do listy (list_id)
 *   complete     — oznacz jako ukończone
 *   delete       — usuń (soft-delete)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();

$body   = task_parse_json_body();
task_csrf_check($body);

$uid    = (int)(current_user()['id'] ?? 0);
$action = $body['action'] ?? '';
$ids    = array_map('intval', (array)($body['task_ids'] ?? []));
$ids    = array_filter($ids);

if (!$ids)    task_api_error('Nie wybrano żadnych zadań.');
if (!$action) task_api_error('Brak akcji.');
if (count($ids) > 100) task_api_error('Maksymalnie 100 zadań naraz.');

$now = date('Y-m-d H:i:s');
$ph  = implode(',', array_fill(0, count($ids), '?'));

// Pobierz zadania i sprawdź dostęp do obszarów
$tasks = db_all(
    "SELECT t.id, t.workspace_id, t.list_id, t.priority, t.completed_at, t.deleted_at
     FROM tasks t WHERE t.id IN ($ph)",
    $ids
);

$allowed = [];
foreach ($tasks as $t) {
    if ($t['deleted_at']) continue;
    $role = task_workspace_role((int)$t['workspace_id'], $uid);
    if ($role) $allowed[] = $t;
}

if (!$allowed) task_api_error('Brak dostępu do wybranych zadań.');

$allowed_ids = array_column($allowed, 'id');
$aph = implode(',', array_fill(0, count($allowed_ids), '?'));
$affected = 0;

switch ($action) {

    case 'assign_me':
        $stmt = db()->prepare(
            "INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by, assigned_at)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($allowed as $t) {
            if ($t['completed_at']) continue;
            $stmt->execute([$t['id'], $uid, $uid, $now]);
            task_log($t['id'], $uid, 'assigned', null, current_user()['name']);
            $affected++;
        }
        break;

    case 'unassign_me':
        db()->prepare("DELETE FROM task_assignments WHERE task_id IN ($aph) AND user_id=?")
            ->execute(array_merge($allowed_ids, [$uid]));
        foreach ($allowed_ids as $tid) {
            task_log($tid, $uid, 'unassigned', current_user()['name'], null);
        }
        $affected = count($allowed_ids);
        break;

    case 'priority':
        $pri = (int)($body['priority'] ?? 2);
        if (!in_array($pri, [1,2,3,4], true)) task_api_error('Nieprawidłowy priorytet.');
        $pri_labels = [1=>'Niski',2=>'Normalny',3=>'Wysoki',4=>'Krytyczny'];
        db()->prepare("UPDATE tasks SET priority=?, updated_at=? WHERE id IN ($aph)")
            ->execute(array_merge([$pri, $now], $allowed_ids));
        foreach ($allowed_ids as $tid) {
            task_log($tid, $uid, 'priority_changed', null, $pri_labels[$pri]);
        }
        $affected = count($allowed_ids);
        break;

    case 'move':
        $list_id = (int)($body['list_id'] ?? 0);
        if (!$list_id) task_api_error('Brak list_id.');
        $list = db_one("SELECT id, name, is_done_state FROM task_lists WHERE id=?", [$list_id]);
        if (!$list) task_api_error('Lista nie istnieje.');
        $completed_at = $list['is_done_state'] ? $now : null;
        db()->prepare(
            "UPDATE tasks SET list_id=?, completed_at=?, updated_at=? WHERE id IN ($aph)"
        )->execute(array_merge([$list_id, $completed_at, $now], $allowed_ids));
        foreach ($allowed_ids as $tid) {
            task_log($tid, $uid, 'moved', null, $list['name']);
        }
        $affected = count($allowed_ids);
        break;

    case 'complete':
        $uncompleted = array_filter($allowed, fn($t) => !$t['completed_at']);
        $unc_ids = array_column($uncompleted, 'id');
        if ($unc_ids) {
            $uph = implode(',', array_fill(0, count($unc_ids), '?'));
            db()->prepare("UPDATE tasks SET completed_at=?, updated_at=? WHERE id IN ($uph)")
                ->execute(array_merge([$now, $now], $unc_ids));
            foreach ($unc_ids as $tid) {
                task_log($tid, $uid, 'completed');
            }
            $affected = count($unc_ids);
        }
        break;

    case 'delete':
        $role_check = array_filter($allowed, function($t) use ($uid) {
            return in_array(task_workspace_role((int)$t['workspace_id'], $uid), ['admin','editor'], true);
        });
        if (!$role_check) task_api_error('Brak uprawnień do usunięcia wybranych zadań.');
        $del_ids = array_column($role_check, 'id');
        $dph = implode(',', array_fill(0, count($del_ids), '?'));
        db()->prepare("UPDATE tasks SET deleted_at=?, updated_at=? WHERE id IN ($dph)")
            ->execute(array_merge([$now, $now], $del_ids));
        foreach ($del_ids as $tid) {
            task_log($tid, $uid, 'deleted');
        }
        $affected = count($del_ids);
        break;

    default:
        task_api_error('Nieznana akcja: ' . $action);
}

task_api_ok(['affected' => $affected, 'action' => $action]);
