<?php
/**
 * tasks/export.php
 * Eksport CSV listy zadań bieżącego obszaru roboczego — respektuje te same
 * filtry GET co tasks/index.php (status, pri, tag, list, area, unit, q).
 * Wzorzec CSV (BOM + średnik + fputcsv) skopiowany z crm/export.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid   = (int)(current_user()['id'] ?? 0);
$ws_id = (int)($_GET['ws'] ?? 0);
if (!$ws_id) { http_response_code(400); die('Brak parametru ws.'); }

task_require_workspace_access($ws_id);
$workspace = db_one("SELECT * FROM task_workspaces WHERE id=? AND is_active=1", [$ws_id]);
if (!$workspace) { http_response_code(404); die('Obszar nie istnieje.'); }

// ── Jednostki org (do filtra "unit" i znacznika "moje") — jak w index.php ──
$uid_units = [];
try {
    $uid_units = array_map('intval', array_column(
        db_all("SELECT unit_id FROM org_members WHERE user_id=? AND status='active'", [$uid]),
        'unit_id'
    ));
} catch (\Throwable $e) {}

// ── Zadania obszaru (identyczne zapytanie co tasks/index.php) ─────────────
$tasks_raw = db_all(
    "SELECT t.*,
            tl.name  AS list_name,
            ou.name  AS unit_name,
            ou.short_name AS unit_short,
            (SELECT COUNT(*) FROM task_subtasks ts WHERE ts.task_id = t.id)             AS st_total,
            (SELECT COUNT(*) FROM task_subtasks ts WHERE ts.task_id = t.id AND ts.is_done=1) AS st_done
     FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     LEFT JOIN org_units ou ON ou.id = t.unit_id
     WHERE t.workspace_id = ? AND t.deleted_at IS NULL AND t.archived_at IS NULL
     ORDER BY t.priority DESC, t.due_date ASC NULLS LAST, t.created_at DESC",
    [$ws_id]
);

foreach ($tasks_raw as &$t) {
    $t['tags'] = db_all(
        "SELECT tt.name FROM task_task_tags ttt
         JOIN task_tags tt ON tt.id = ttt.tag_id
         WHERE ttt.task_id = ? ORDER BY tt.name",
        [$t['id']]
    );
    $t['assignees'] = db_all(
        "SELECT u.id, u.name FROM task_assignments ta
         JOIN users u ON u.id = ta.user_id WHERE ta.task_id = ? ORDER BY u.name",
        [$t['id']]
    );
    if ($t['completed_at']) {
        $t['_status'] = 'done';
    } elseif ($t['assignees']) {
        $t['_status'] = 'taken';
    } else {
        $t['_status'] = 'open';
    }
    $t['_mine'] = in_array($uid, array_column($t['assignees'], 'id'), true)
               || (!empty($t['unit_id']) && in_array((int)$t['unit_id'], $uid_units, true));
}
unset($t);

// ── Filtry — identyczne z tasks/index.php ──────────────────────────────────
$filter_status   = $_GET['status'] ?? 'all';
$filter_priority = (int)($_GET['pri'] ?? 0);
$filter_tag      = (int)($_GET['tag'] ?? 0);
$filter_list     = (int)($_GET['list'] ?? 0);
$filter_area     = (int)($_GET['area'] ?? 0);
$filter_unit     = (int)($_GET['unit'] ?? 0);
$filter_q        = trim($_GET['q'] ?? '');

$all_areas_by_id = array_column(task_get_areas(), 'name', 'id');

$tasks = array_filter($tasks_raw, function ($t) use ($filter_status, $filter_priority, $filter_tag, $filter_list, $filter_area, $filter_unit, $filter_q) {
    if ($filter_status === 'open'  && $t['_status'] !== 'open')  return false;
    if ($filter_status === 'taken' && $t['_status'] !== 'taken') return false;
    if ($filter_status === 'done'  && $t['_status'] !== 'done')  return false;
    if ($filter_status === 'mine'  && !$t['_mine'])              return false;
    if ($filter_priority && (int)$t['priority'] !== $filter_priority) return false;
    if ($filter_tag  && !in_array($filter_tag,  array_column(db_all("SELECT tag_id FROM task_task_tags WHERE task_id=?", [$t['id']]), 'tag_id'), true)) return false;
    if ($filter_list && (int)$t['list_id'] !== $filter_list)    return false;
    if ($filter_area && (int)($t['area_id'] ?? 0) !== $filter_area) return false;
    if ($filter_unit && (int)($t['unit_id'] ?? 0) !== $filter_unit) return false;
    if ($filter_q    && mb_stripos($t['title'] . ' ' . ($t['description'] ?? ''), $filter_q) === false) return false;
    return true;
});

$status_labels = ['open' => 'Do zrobienia', 'taken' => 'Przydzielone', 'done' => 'Ukończone'];
$pri_labels    = [1 => 'Niski', 2 => 'Normalny', 3 => 'Wysoki', 4 => 'Krytyczny'];

$headers = ['ID', 'Tytuł', 'Opis', 'Kolumna', 'Obszar zadania', 'Jednostka', 'Priorytet', 'Status',
            'Weryfikacja', 'Termin', 'Przypisani', 'Tagi', 'Podzadania', 'Utworzono', 'Zakończono'];

$rows = [];
foreach ($tasks as $t) {
    $verif = '';
    if ($t['_status'] === 'done') {
        $verif = !empty($t['confirmed_at']) ? 'Potwierdzone' : (!empty($t['rejected_at']) ? 'Odrzucone' : 'Niepotwierdzone');
    }
    $rows[] = [
        $t['id'],
        $t['title'],
        $t['description'] ?? '',
        $t['list_name'] ?? '',
        $all_areas_by_id[$t['area_id'] ?? 0] ?? '',
        $t['unit_short'] ?: ($t['unit_name'] ?? ''),
        $pri_labels[(int)$t['priority']] ?? '',
        $status_labels[$t['_status']] ?? '',
        $verif,
        $t['due_date'] ?? '',
        implode(', ', array_column($t['assignees'], 'name')),
        implode(', ', $t['tags']),
        $t['st_total'] > 0 ? $t['st_done'] . '/' . $t['st_total'] : '',
        $t['created_at'] ?? '',
        $t['completed_at'] ?? '',
    ];
}

$slug = preg_replace('/[^a-z0-9\-]/', '-', mb_strtolower($workspace['slug'] ?: $workspace['name']));
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="zadania-' . $slug . '-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $headers, ';', '"', '\\');
foreach ($rows as $row) fputcsv($out, $row, ';', '"', '\\');
fclose($out);
exit;
