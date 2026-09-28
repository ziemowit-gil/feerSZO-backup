<?php
/**
 * tasks/index.php
 * Lista/kanban zadań obszaru roboczego — kontroler (Tailwind, przebudowa
 * 2026-09-03). Podział na pliki, patrz tasks/includes/:
 *   - index_style.php        — style (Tailwind @apply)
 *   - index_onboarding.php   — ekran powitalny (brak obszarów)
 *   - index_toolbar.php      — nagłówek obszaru + pasek filtrów
 *   - index_list_render.php  — funkcja _tasks_list_html() (tabela/kanban)
 *   - index_modals.php       — offcanvas szczegółów + modale
 *   - assets/js/tasks-index.js — logika JS strony
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/org.php';
require_once __DIR__ . '/includes/index_list_render.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');
task_areas_migrate();

try {
    $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
    if (($_tm['value'] ?? '1') === '0') {
        flash_set('error', 'Moduł zadań jest wyłączony przez administratora.');
        header('Location: ' . APP_URL . '/index.php'); exit;
    }
} catch (\Throwable $e) {}

$user     = current_user();
$uid      = (int)$user['id'];
$is_admin = is_admin();

$workspaces   = task_user_workspaces($uid);
$ws_id_param  = (int)($_GET['ws'] ?? 0);
$ws_id        = $ws_id_param;

// Jeśli podany ws nie należy do listy dostępnych — przekieruj na pierwszy dostępny
if ($ws_id && $workspaces && !in_array($ws_id, array_column($workspaces, 'id'), false)) {
    header('Location: ' . APP_URL . '/tasks/index.php?ws=' . (int)$workspaces[0]['id']);
    exit;
}
if (!$ws_id && $workspaces) {
    $ws_id = (int)$workspaces[0]['id'];
}

// Jednostki org — do filtra, modala i znacznika "moje" w pętli zadań poniżej
$all_org_units = [];
$uid_units     = []; // ID jednostek, do których należy bieżący użytkownik
try {
    $all_org_units = db_all("SELECT id, name, short_name FROM org_units WHERE status='active' ORDER BY name");
    $uid_units = array_map('intval', array_column(
        db_all("SELECT unit_id FROM org_members WHERE user_id=? AND status='active'", [$uid]),
        'unit_id'
    ));
} catch (\Throwable $e) {}

// Inicjalizacja zmiennych obszaru (uzupełnione po wyborze $ws_id)
$ws_members_for_assign = [];
$my_notify_prefs = ['notify_email' => 1, 'notify_sms' => 0, 'notify_push' => 0];

$workspace = null;
$tasks_raw = [];
$lists_map = [];

if ($ws_id) {
    task_require_workspace_access($ws_id);
    $workspace = db_one("SELECT * FROM task_workspaces WHERE id=? AND is_active=1", [$ws_id]);
    if ($workspace) {
        // Mapa list obszaru (bez kolumny is_active — nie istnieje)
        $lists_in_ws = db_all(
            "SELECT id, name, color, is_done_state FROM task_lists WHERE workspace_id=? ORDER BY position",
            [$ws_id]
        );
        foreach ($lists_in_ws as $l) {
            $lists_map[$l['id']] = $l;
        }

        // Wszystkie zadania płasko
        $tasks_raw = db_all(
            "SELECT t.*,
                    tl.name  AS list_name,
                    tl.color AS list_color,
                    tl.is_done_state,
                    ou.name  AS unit_name,
                    ou.short_name AS unit_short,
                    (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id)            AS assignee_count,
                    (SELECT COUNT(*) FROM task_subtasks   ts WHERE ts.task_id = t.id)             AS st_total,
                    (SELECT COUNT(*) FROM task_subtasks   ts WHERE ts.task_id = t.id AND ts.is_done=1) AS st_done
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.list_id
             LEFT JOIN org_units ou ON ou.id = t.unit_id
             WHERE t.workspace_id = ? AND t.deleted_at IS NULL AND t.archived_at IS NULL
             ORDER BY
               CASE WHEN t.completed_at IS NULL AND t.due_date IS NOT NULL
                         AND t.due_date < date('now') THEN 0 ELSE 1 END,
               t.priority DESC,
               t.due_date  ASC NULLS LAST,
               t.created_at DESC",
            [$ws_id]
        );

        foreach ($tasks_raw as &$t) {
            $t['tags'] = db_all(
                "SELECT tt.* FROM task_task_tags ttt
                 JOIN task_tags tt ON tt.id = ttt.tag_id
                 WHERE ttt.task_id = ? ORDER BY tt.name",
                [$t['id']]
            );
            $t['assignees'] = db_all(
                "SELECT u.id, u.name FROM task_assignments ta
                 JOIN users u ON u.id = ta.user_id WHERE ta.task_id = ? ORDER BY u.name",
                [$t['id']]
            );
            if ($t['completed_at'] || $t['is_done_state']) {
                $t['_status'] = 'done';
            } elseif ((int)$t['assignee_count'] > 0) {
                $t['_status'] = 'taken';
            } else {
                $t['_status'] = 'open';
            }
            $t['_mine']   = in_array($uid, array_column($t['assignees'], 'id'), true)
                         || (!empty($t['unit_id']) && in_array((int)$t['unit_id'], $uid_units, true));
            $t['_overdue']= $t['due_date'] && !$t['completed_at']
                            && strtotime($t['due_date']) < strtotime('today');
        }
        unset($t);
    }
}

$available_tags = [];
if ($ws_id) {
    $available_tags = db_all(
        "SELECT * FROM task_tags WHERE is_active=1 AND (workspace_id IS NULL OR workspace_id=?) ORDER BY name",
        [$ws_id]
    );
}

$my_role = $ws_id ? task_workspace_role($ws_id, $uid) : null;
$can_add = in_array($my_role, ['admin', 'editor'], true);

// Szablony zadań — tylko do pobrania gdy lider może w ogóle dodawać zadania
$available_templates = $can_add ? task_get_templates() : [];

// Dane per-obszar: picker osób + prefs powiadomień
if ($ws_id) {
    try {
        $ws_members_for_assign = db_all(
            "SELECT twm.user_id, u.name
             FROM task_workspace_members twm
             JOIN users u ON u.id = twm.user_id
             WHERE twm.workspace_id = ? AND u.is_active = 1
             ORDER BY u.name",
            [$ws_id]
        );
        $np = db_one(
            "SELECT notify_email, notify_sms, notify_push
             FROM task_workspace_members WHERE workspace_id=? AND user_id=?",
            [$ws_id, $uid]
        );
        if ($np) $my_notify_prefs = $np;
    } catch (\Throwable $e) {}
}

// Filtry
$filter_status   = $_GET['status'] ?? 'all';
$filter_priority = (int)($_GET['pri'] ?? 0);
$filter_tag      = (int)($_GET['tag'] ?? 0);
$filter_list     = (int)($_GET['list'] ?? 0);
$filter_area     = (int)($_GET['area'] ?? 0);
$filter_unit     = (int)($_GET['unit'] ?? 0);
$filter_q        = trim($_GET['q'] ?? '');
$all_areas       = task_get_areas();

// Zastosuj filtry
$tasks = array_filter($tasks_raw, function ($t) use ($filter_status, $filter_priority, $filter_tag, $filter_list, $filter_area, $filter_unit, $filter_q, $uid) {
    if ($filter_status === 'open'  && $t['_status'] !== 'open')  return false;
    if ($filter_status === 'taken' && $t['_status'] !== 'taken') return false;
    if ($filter_status === 'done'  && $t['_status'] !== 'done')  return false;
    if ($filter_status === 'mine'  && !$t['_mine'])              return false;
    if ($filter_priority && (int)$t['priority'] !== $filter_priority) return false;
    if ($filter_tag  && !in_array($filter_tag,  array_column($t['tags'], 'id'), true)) return false;
    if ($filter_list && (int)$t['list_id'] !== $filter_list)    return false;
    if ($filter_area && (int)($t['area_id'] ?? 0) !== $filter_area) return false;
    if ($filter_unit && (int)($t['unit_id'] ?? 0) !== $filter_unit) return false;
    if ($filter_q    && mb_stripos($t['title'] . ' ' . ($t['description'] ?? ''), $filter_q) === false) return false;
    return true;
});

// Liczniki
$cnt = ['all' => count($tasks_raw), 'open' => 0, 'taken' => 0, 'done' => 0, 'mine' => 0];
foreach ($tasks_raw as $t) {
    $cnt[$t['_status']]++;
    if ($t['_mine']) $cnt['mine']++;
}

$view_mode = $_GET['view'] ?? 'list'; // 'list' | 'kanban'

// ── AJAX — zwróć tylko listę/kanban ───────────────────────────────────────
if (isset($_GET['_ajax'])) {
    ob_start();
    echo _tasks_list_html($tasks, $cnt, $lists_map, $ws_id, $view_mode, $workspace, $can_add, $is_admin, $all_org_units);
    echo json_encode(['ok'=>true,'total'=>count($tasks),'list_html'=>ob_get_clean(),'counts'=>$cnt]);
    exit;
}

$PAGE_TITLE       = $workspace ? h($workspace['name']) : 'Zadania';
$PAGE_SUBTITLE    = $view_mode === 'kanban' ? 'Widok Kanban' : 'Widok tabelaryczny';
$TASKS_BREADCRUMB = $workspace ? h($workspace['name']) : 'Zadania';
$TASKS_WS_ID      = $ws_id;
require_once __DIR__ . '/includes/header_tasks.php';
?>

<?php require_once dirname(__DIR__) . '/includes/banner_rewrite.php'; ?>
<?php require_once __DIR__ . '/includes/index_style.php'; ?>

<div id="tk-sr" aria-live="polite" aria-atomic="true"></div>

<?php if (!$workspaces): ?>
<?php require_once __DIR__ . '/includes/index_onboarding.php'; ?>
<?php else: ?>

<?php if ($workspace): ?>
<?php require_once __DIR__ . '/includes/index_toolbar.php'; ?>
<?php echo _tasks_list_html($tasks, $cnt, $lists_map, $ws_id, $view_mode, $workspace, $can_add, $is_admin, $all_org_units); ?>
<?php endif; /* workspace */ ?>
<?php endif; /* workspaces */ ?>

<?php require_once __DIR__ . '/includes/index_modals.php'; ?>

<script>
  window.TSK_INDEX = {
    csrf:   <?= json_encode(csrf_token()) ?>,
    wsId:   <?= (int)$ws_id ?>,
    canEdit: <?= $can_add ? 'true' : 'false' ?>,
    base:   <?= json_encode(rtrim(APP_URL,'/')) ?>,
    wsName: <?= json_encode($workspace['name'] ?? '') ?>
  };
</script>
<script src="<?= APP_URL ?>/assets/js/tasks-index.js?v=<?= (int)@filemtime(dirname(__DIR__) . "/assets/js/tasks-index.js") ?>" defer></script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
