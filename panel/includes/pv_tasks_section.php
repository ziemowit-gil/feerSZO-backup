<?php
/**
 * panel/includes/pv_tasks_section.php — sekcja „Moje zadania" (dane + render).
 *
 * Liczy zadania użytkownika (przypisane + dostępne) i renderuje panel
 * (pv_tasks_panel.php). Wydzielone z panel/index.php, by tę samą sekcję można
 * było pokazać wysoko w panelu wolontariusza ORAZ niżej w widoku edytora —
 * guard `_pv_tasks_section_done` gwarantuje, że renderuje się tylko raz.
 *
 * Wymaga w zasięgu: $user, APP_URL, db_all/db_one, h().
 */
if (!empty($GLOBALS['_pv_tasks_section_done'])) return;

$_tasks_panel_enabled = false;
try {
    $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
    $_tasks_panel_enabled = ($_tm['value'] ?? '1') !== '0';
} catch (\Throwable $e) {}
if (!$_tasks_panel_enabled) return;

$GLOBALS['_pv_tasks_section_done'] = true;

require_once dirname(__DIR__, 2) . '/includes/tasks.php';
require_once dirname(__DIR__, 2) . '/includes/messages.php';

$uid_panel = (int)$user['id'];

// Moje zadania — przypisane, nieukończone
$_my_tasks = db_all(
    "SELECT t.id, t.title, t.priority, t.due_date,
            tl.name AS list_name, tl.color AS list_color,
            tw.name AS ws_name, tw.color AS ws_color
     FROM tasks t
     JOIN task_assignments ta ON ta.task_id = t.id
     JOIN task_lists tl ON tl.id = t.list_id
     JOIN task_workspaces tw ON tw.id = t.workspace_id
     WHERE ta.user_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL
     ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
     LIMIT 10",
    [$uid_panel]
);

// Dostępne zadania — BEZ wymogu workspace membership (wolontariusz może wziąć każde dostępne)
$_open_tasks_total = (int)(db_one(
    "SELECT COUNT(*) AS c FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     JOIN task_workspaces tw ON tw.id = t.workspace_id AND tw.is_active = 1
     WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
       AND tl.is_done_state = 0
       AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) = 0",
    []
)['c'] ?? 0);
$_open_tasks = db_all(
    "SELECT t.id, t.title, t.priority, t.due_date,
            tl.name AS list_name, tw.name AS ws_name, tw.color AS ws_color
     FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     JOIN task_workspaces tw ON tw.id = t.workspace_id AND tw.is_active = 1
     WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
       AND tl.is_done_state = 0
       AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) = 0
     ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
     LIMIT 5",
    []
);

// Nieprzeczytane wiadomości zadaniowe
$_task_inbox_unread = task_msg_unread($uid_panel);
$csrf_panel = csrf_token();

$_pv_tasks_mine = array_map(fn($t) => [
    'id' => (int)$t['id'], 'title' => $t['title'], 'priority' => (int)$t['priority'],
    'due_date' => $t['due_date'], 'ws_name' => $t['ws_name'], 'list_name' => $t['list_name'],
    'ws_color' => $t['ws_color'],
], $_my_tasks);
$_pv_tasks_open = array_map(fn($t) => [
    'id' => (int)$t['id'], 'title' => $t['title'], 'priority' => (int)$t['priority'],
    'due_date' => $t['due_date'], 'ws_name' => $t['ws_name'], 'ws_color' => $t['ws_color'],
], $_open_tasks);

include __DIR__ . '/pv_tasks_panel.php';
