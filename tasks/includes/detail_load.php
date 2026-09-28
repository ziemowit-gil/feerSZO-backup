<?php
/**
 * tasks/includes/detail_load.php
 * Wczytanie danych zadania + funkcje pomocnicze — wydzielone z tasks/detail.php.
 * Wymaga $id (int, GET) już ustawionego przez detail.php. Ustawia $task, $my_role,
 * $can_edit, $task_tags, $avail_tags, $assignees, $can_manage_files, $all_users,
 * $comments, $history, $files, $ws_linked_files, $ws_for_task, $subtasks,
 * $st_total/$st_done/$st_pct, $lists_in_ws, $csrf, $is_done, $overdue,
 * $is_confirmed/$is_rejected/$can_review/$can_confirm/$can_reject,
 * $_members_by_unit, $cur_user/$has_ms/$ms_app_id, oraz td_render_mentions().
 * config.php/db.php/auth.php/functions.php/tasks.php/org.php, require_login(),
 * $uid i $id już wczytane przez tasks/detail.php przed include tego pliku.
 */
$task = db_one(
    "SELECT t.*, tl.name AS list_name, tl.workspace_id
     FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$id]
);
if (!$task) { echo '<div class="alert alert-danger m-3">Zadanie nie istnieje lub zostało usunięte.</div>'; exit; }

task_require_workspace_access((int)$task['workspace_id']);
$my_role  = task_workspace_role((int)$task['workspace_id'], $uid);
$can_edit = in_array($my_role, ['admin', 'editor'], true);

$task_tags  = db_all(
    "SELECT tt.id, tt.name, tt.color, tt.text_color
     FROM task_task_tags ttt JOIN task_tags tt ON tt.id=ttt.tag_id
     WHERE ttt.task_id=? ORDER BY tt.name", [$id]);
$tag_ids    = array_column($task_tags, 'id');

$avail_tags = db_all(
    "SELECT * FROM task_tags WHERE is_active=1
     AND (workspace_id IS NULL OR workspace_id=?) ORDER BY name",
    [$task['workspace_id']]);

$assignees  = db_all(
    "SELECT u.id, u.name FROM task_assignments ta
     JOIN users u ON u.id=ta.user_id WHERE ta.task_id=? ORDER BY u.name", [$id]);
$assign_ids = array_column($assignees, 'id');
$watchers    = task_watchers($id);
$cover_row   = db_one("SELECT " . task_cover_sql() . " AS cover_id, t.cover_file_id FROM tasks t WHERE t.id=?", [$id]);
$cover_id    = (int)($cover_row['cover_id'] ?? 0);
$cover_mode  = $cover_row['cover_file_id'] === null ? 'auto' : ((int)$cover_row['cover_file_id'] === -1 ? 'none' : 'manual');
$blockers    = task_blockers($id);
$blocking    = task_blocking($id);
$dep_candidates = [];
if ($can_edit) {
    $dep_exclude = array_merge([$id], array_map(fn($b) => (int)$b['id'], $blockers));
    $dep_candidates = array_values(array_filter(db_all(
        "SELECT t.id, t.title, t.completed_at FROM tasks t
         WHERE t.workspace_id=? AND t.deleted_at IS NULL AND t.archived_at IS NULL
         ORDER BY (t.completed_at IS NOT NULL), t.title LIMIT 400",
        [(int)$task['workspace_id']]
    ), fn($c) => !in_array((int)$c['id'], $dep_exclude, true)));
}
$i_watch     = in_array($uid, array_map('intval', array_column($watchers, 'id')), true);
// Uprawnienie do plików wymaga też przypisania do zadania (dla ról poza admin/editor) —
// tak samo jak sprawdza to tasks/api/upload.php.
$can_manage_files = $can_edit || (task_field_editable('files', $my_role) && in_array($uid, $assign_ids, true));

$all_users  = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");

$comments   = db_all(
    "SELECT tc.*, u.name AS author_name FROM task_comments tc
     JOIN users u ON u.id=tc.author_id
     WHERE tc.task_id=? AND tc.deleted_at IS NULL ORDER BY tc.created_at", [$id]);

$history    = db_all(
    "SELECT th.*, u.name AS actor_name FROM task_history th
     JOIN users u ON u.id=th.user_id
     WHERE th.task_id=? ORDER BY th.occurred_at DESC LIMIT 40", [$id]);

$files = [];
try { $files = db_all("SELECT * FROM task_files WHERE task_id=? ORDER BY created_at", [$id]); }
catch (\Throwable $e) {}

// Pliki z Koszulek (workspaces) powiązane z zadaniem
$ws_linked_files = [];
$ws_for_task     = null;
try {
    require_once dirname(dirname(__DIR__)) . '/includes/workspaces.php';
    $ws_linked_files = ws_files_for_task($id);
    $ws_for_task     = db_one("SELECT id, name FROM task_workspaces WHERE id=?", [$task['workspace_id']]);
} catch (\Throwable $e) {}

$subtasks = [];
try { $subtasks = db_all("SELECT * FROM task_subtasks WHERE task_id=? ORDER BY position, id", [$id]); }
catch (\Throwable $e) {}
$st_total = count($subtasks);
$st_done  = count(array_filter($subtasks, fn($s) => (bool)$s['is_done']));
$st_pct   = $st_total ? round($st_done / $st_total * 100) : 0;

$lists_in_ws = db_all(
    "SELECT id, name FROM task_lists WHERE workspace_id=? ORDER BY position",
    [$task['workspace_id']]);

$csrf    = csrf_token();
$is_done = (bool)$task['completed_at'];
task_extras_schema_heal();   // kolumna due_time
$overdue = !$is_done && task_is_overdue($task);

// ── Potwierdzenie / odrzucenie wykonania przez lidera ─────────────────────
$is_confirmed = !empty($task['confirmed_at'] ?? null);
$is_rejected  = !empty($task['rejected_at'] ?? null);
$can_review   = $is_done && !$is_confirmed
                && ((int)$task['created_by'] === $uid || $can_edit);
$can_confirm  = $can_review;
$can_reject   = $can_review;

// ── Komórka organizacyjna aktora — do „Poproś o przejęcie" ───────────────
$_actor_is_sys_admin = (db_one("SELECT role FROM users WHERE id=?", [$uid])['role'] ?? '') === 'admin';

// Czy bieżący user jest liderem (admin/editor) w obszarze tego zadania
$_tsk_is_leader_here = $_actor_is_sys_admin || in_array(
    task_workspace_role((int)$task['workspace_id'], $uid),
    ['admin', 'editor'],
    true
);

// Jednostki, do których należy bieżący użytkownik
$_actor_unit_ids = [];
try {
    $_au = db_all(
        "SELECT unit_id FROM org_members WHERE user_id=? AND status='active'",
        [$uid]
    );
    $_actor_unit_ids = array_column($_au, 'unit_id');
} catch (\Throwable $e) {}

// Osoby z tych samych jednostek (bez siebie)
$_unit_members = [];
if ($_actor_is_sys_admin) {
    // Admin widzi wszystkich aktywnych
    try {
        $_unit_members = db_all(
            "SELECT u.id, u.name,
                    COALESCE(ou.name,'') AS unit_name,
                    COALESCE(om.position_name,'') AS position_name
             FROM users u
             LEFT JOIN org_members om ON om.user_id=u.id AND om.status='active' AND om.is_primary=1
             LEFT JOIN org_units ou ON ou.id=om.unit_id
             WHERE u.is_active=1 AND u.id != ?
             ORDER BY ou.name, u.name",
            [$uid]
        );
    } catch (\Throwable $e) {}
} elseif ($_actor_unit_ids) {
    try {
        $ph = implode(',', array_fill(0, count($_actor_unit_ids), '?'));
        $_unit_members = db_all(
            "SELECT DISTINCT u.id, u.name,
                    ou.name AS unit_name,
                    COALESCE(om2.position_name,'') AS position_name
             FROM org_members om
             JOIN users u ON u.id=om.user_id AND u.is_active=1 AND u.id != ?
             JOIN org_units ou ON ou.id=om.unit_id
             LEFT JOIN org_members om2 ON om2.user_id=u.id AND om2.unit_id=om.unit_id AND om2.status='active'
             WHERE om.unit_id IN ({$ph}) AND om.status='active'
             ORDER BY ou.name, u.name",
            array_merge([$uid], $_actor_unit_ids)
        );
    } catch (\Throwable $e) {}
}

// Pogrupuj wg jednostki
$_members_by_unit = [];
foreach ($_unit_members as $m) {
    $_members_by_unit[$m['unit_name'] ?: 'Bez jednostki'][] = $m;
}

$cur_user     = current_user();
$has_ms       = !empty($cur_user['microsoft_id']);
[, $ms_app_id] = _ms_creds();
$ms_available  = $ms_app_id !== '';

function td_render_mentions(string $text, array $users): string {
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (empty($users)) return $html;
    $names = array_map(fn($u) => $u['name'], $users);
    usort($names, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
    foreach ($names as $name) {
        if (!$name) continue;
        $esc = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = str_replace('@' . $esc,
            '<mark class="td-mention">@' . $esc . '</mark>', $html);
    }
    return $html;
}
?>
