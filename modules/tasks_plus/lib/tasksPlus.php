<?php
/**
 * modules/tasks_plus/lib/tasksPlus.php — wspólne pomocniki rozszerzeń modułu Zadania
 * (kalendarz + iCal, SLA i eskalacje, obciążenie zespołu, reguły automatyzacji).
 *
 * Rozszerzenia działają w powłoce modułu Zadania (tasks/includes/header_tasks.php — app-bar,
 * pasek sekcji Metro, dzwonek), ale ich logika siedzi tu, w modules/tasks_plus/.
 */
require_once dirname(__DIR__, 3) . '/includes/tasks.php';

/** Obszary dostępne dla użytkownika z jego rolą (admin systemu → 'admin' wszędzie). */
function tasksPlusWorkspaces(int $uid): array
{
    $out = [];
    foreach (task_user_workspaces($uid) as $w) {
        $w['my_role'] = $w['my_role'] ?? task_workspace_role((int)$w['id'], $uid);
        if ($w['my_role']) $out[(int)$w['id']] = $w;
    }
    return $out;
}

/** Obszary, w których użytkownik jest liderem (admin/editor obszaru). */
function tasksPlusLeaderWorkspaces(int $uid): array
{
    return array_filter(tasksPlusWorkspaces($uid), fn($w) => in_array($w['my_role'], ['admin', 'editor'], true));
}

/** Liderzy obszaru (bezpośrednio i przez zespoły), aktywni. */
function tasksPlusWorkspaceLeaders(int $wsId): array
{
    task_teams_migrate();
    return db_all(
        "SELECT DISTINCT u.id, u.name, u.email FROM users u
         WHERE u.is_active=1 AND u.id IN (
             SELECT user_id FROM task_workspace_members WHERE workspace_id=? AND role IN ('admin','editor')
             UNION
             SELECT tm.user_id FROM task_workspace_teams wt JOIN task_team_members tm ON tm.team_id=wt.team_id
             WHERE wt.workspace_id=? AND wt.role IN ('admin','editor')
         ) ORDER BY u.name",
        [$wsId, $wsId]
    );
}

/** Lista placeholderów „?,?,?” dla IN (…). */
function tasksPlusIn(array $ids): string
{
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

/** Adres szczegółów zadania (otwiera okno zadania na liście). */
function tasksPlusTaskUrl(int $taskId): string
{
    return APP_URL . '/tasks/index.php?task=' . $taskId;
}

/**
 * Strona w powłoce modułu Zadania. header_tasks.php zakłada zakres pliku — _tsk_active() czyta
 * `global $_uri`, więc przy wywołaniu z metody kontrolera ustawiamy ją w $GLOBALS.
 */
function tasksPlusPage(string $title, string $body, int $wsId = 0, string $subtitle = ''): string
{
    $GLOBALS['_uri'] = $_SERVER['REQUEST_URI'] ?? '';
    $PAGE_TITLE = $title;
    $PAGE_SUBTITLE = $subtitle;
    $TASKS_BREADCRUMB = $title;
    $TASKS_WS_ID = $wsId;
    ob_start();
    require dirname(__DIR__, 3) . '/tasks/includes/header_tasks.php';
    include dirname(__DIR__) . '/view/_style.php';
    echo $body;
    require dirname(__DIR__, 3) . '/tasks/includes/footer_tasks.php';
    return (string)ob_get_clean();
}

/** Nazwa priorytetu. */
function tasksPlusPriorityLabel(int $p): string
{
    return TASK_PRIORITIES[$p]['label'] ?? TASK_PRIORITIES[2]['label'];
}
