<?php
/**
 * tasks/lib/tasksCreate.php — tworzenie zadania poza listą zadań (z e-maila, z karty CRM/EZD/TI/umowy).
 *
 * Ten sam przebieg co akcja `create` w tasks/api/task.php: pozycja na końcu kolumny, licznik czasu w kolumnie,
 * historia, przypisania (z dostępem member do obszaru), powiadomienia liderów, reguły automatyzacji.
 * Pliki zadań: katalog uploads/tasks/ (TASKS_FILES_DIR — do testów można go podmienić przed załadowaniem).
 */
require_once __DIR__ . '/tasksPlus.php';

if (!defined('TASKS_FILES_DIR')) define('TASKS_FILES_DIR', dirname(__DIR__, 2) . '/uploads/tasks');

/** Obszary, w których użytkownik może zakładać zadania (lider/edytor), z kolumnami i osobami. */
function tasksCreateTargets(int $uid): array
{
    task_teams_migrate();
    $out = [];
    foreach (tasksPlusLeaderWorkspaces($uid) as $id => $w) {
        $lists = db_all("SELECT id, name, is_done_state FROM task_lists WHERE workspace_id=? ORDER BY position", [$id]);
        if (!$lists) continue;
        $people = db_all(
            "SELECT id, name FROM users WHERE is_active=1 AND id IN (
                 SELECT user_id FROM task_workspace_members WHERE workspace_id=?
                 UNION SELECT tm.user_id FROM task_workspace_teams wt JOIN task_team_members tm ON tm.team_id=wt.team_id WHERE wt.workspace_id=?
             ) ORDER BY name", [$id, $id]);
        $out[$id] = ['id' => $id, 'name' => $w['name'], 'color' => $w['color'],
                     'lists' => array_values(array_filter($lists, fn($l) => !(int)$l['is_done_state'])) ?: $lists,
                     'people' => $people];
    }
    return $out;
}

/**
 * Tworzy zadanie. $d: workspace_id, list_id, title, description, priority, due_date, assignees[] (id),
 * notify (bool, domyślnie true). $byUid — autor (prawdziwy użytkownik: FK historii).
 * Uprawnienia sprawdza wywołujący (lider obszaru albo automat skrzynki obszaru).
 */
function tasksCreate(array $d, int $byUid): int
{
    $wsId = (int)$d['workspace_id'];
    $list = db_one("SELECT * FROM task_lists WHERE id=? AND workspace_id=?", [(int)$d['list_id'], $wsId]);
    if (!$list) throw new \RuntimeException('Wybierz kolumnę w obszarze.');
    $title = trim((string)($d['title'] ?? ''));
    if ($title === '') throw new \RuntimeException('Podaj tytuł zadania.');
    $due = (string)($d['due_date'] ?? '');
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;

    $now = date('Y-m-d H:i:s');
    $pos = (float)(db_one("SELECT MAX(position) AS m FROM tasks WHERE list_id=? AND deleted_at IS NULL", [(int)$list['id']])['m'] ?? 0) + 1;
    $id = db_insert('tasks', [
        'workspace_id' => $wsId,
        'list_id'      => (int)$list['id'],
        'title'        => mb_substr($title, 0, 300),
        'description'  => mb_substr(trim((string)($d['description'] ?? '')), 0, 20000),
        'position'     => $pos,
        'priority'     => max(1, min(4, (int)($d['priority'] ?? 2))),
        'due_date'     => $due,
        'created_by'   => $byUid,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    task_start_time_tracking($id, (int)$list['id'], (string)$list['name']);
    task_log($id, $byUid, 'created', null, $list['name'], $d['log_meta'] ?? null);

    require_once dirname(__DIR__, 2) . '/includes/task_notify.php';
    $notify = $d['notify'] ?? true;
    $asg = db()->prepare("INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by, assigned_at) VALUES (?,?,?,?)");
    $mem = db()->prepare("INSERT OR IGNORE INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at)
                          VALUES (?, ?, 'member', ?, datetime('now','localtime'))");
    foreach (array_unique(array_map('intval', (array)($d['assignees'] ?? []))) as $a) {
        $u = $a > 0 ? db_one("SELECT id, name FROM users WHERE id=? AND is_active=1", [$a]) : null;
        if (!$u) continue;
        $asg->execute([$id, $a, $byUid, $now]);
        $mem->execute([$wsId, $a, $byUid]);
        task_log($id, $byUid, 'assigned', null, $u['name']);
        if ($notify) { try { task_notify_assigned($id, $a, $byUid); } catch (\Throwable $e) {} }
    }
    if ($notify) { try { task_notify_created($id, $byUid); } catch (\Throwable $e) {} }
    task_rules_fire('created', $id, $byUid);
    return $id;
}

/** Dodaje plik (bajty) do zadania z walidacją jak przy zwykłym uploadzie; zwraca komunikat błędu albo null. */
function tasksCreateAttachBytes(int $taskId, string $name, string $bytes, int $byUid): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'tskatt');
    file_put_contents($tmp, $bytes);
    try {
        $chk = task_upload_validate($tmp, $name, strlen($bytes));
        if (is_string($chk)) return $name . ': ' . $chk;
        if (!is_dir(TASKS_FILES_DIR)) mkdir(TASKS_FILES_DIR, 0755, true);
        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $chk['ext'];
        if (!copy($tmp, rtrim(TASKS_FILES_DIR, '/') . '/' . $stored)) return $name . ': nie udało się zapisać pliku.';
        db_insert('task_files', [
            'task_id' => $taskId, 'original_name' => mb_substr($name, 0, 255), 'stored_name' => $stored,
            'file_size' => strlen($bytes), 'mime_type' => $chk['mime'], 'uploaded_by' => $byUid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        task_log($taskId, $byUid, 'uploaded_file', null, $name);
        return null;
    } finally {
        @unlink($tmp);
    }
}
