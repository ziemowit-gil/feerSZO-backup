<?php
/**
 * API: Usuń obszar roboczy przez lidera (admin lub editor obszaru)
 * POST { _csrf, workspace_id }
 *
 * Kolejność usuwania (respektuje FK ON DELETE RESTRICT):
 *   1. Pliki fizyczne
 *   2. Dane podrzędne zadań (child tables z FK → tasks)
 *   3. Twarde usunięcie zadań (zwalnia FK list_id → task_lists)
 *   4. Listy, członkowie, tagi obszaru
 *   5. Wiadomości wewnętrzne task
 *   6. Sam obszar
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();

$body = task_parse_json_body();
task_csrf_check($body);

$uid          = (int)(current_user()['id'] ?? 0);
$workspace_id = (int)($body['workspace_id'] ?? 0);

if (!$workspace_id) task_api_error('Brak workspace_id.');

$role = task_workspace_role($workspace_id, $uid);
if (!in_array($role, ['admin', 'editor'], true)) {
    task_api_error('Brak uprawnień do usunięcia tego obszaru.', 403);
}

$ws = db_one("SELECT * FROM task_workspaces WHERE id=?", [$workspace_id]);
if (!$ws) task_api_error('Obszar nie istnieje.', 404);

// Pobierz ID wszystkich zadań (aktywnych i soft-deleted)
$task_ids = array_column(
    db_all("SELECT id FROM tasks WHERE workspace_id=?", [$workspace_id]),
    'id'
);

$task_count = count($task_ids);

$pdo = db();
$pdo->beginTransaction();

try {
    // ── 1. Pliki fizyczne ────────────────────────────────────────────────
    if ($task_ids) {
        $ph    = implode(',', array_fill(0, count($task_ids), '?'));
        $files = db_all("SELECT stored_name FROM task_files WHERE task_id IN ({$ph})", $task_ids);
        foreach ($files as $f) {
            $path = dirname(dirname(__DIR__)) . '/uploads/tasks/' . $f['stored_name'];
            if (file_exists($path)) @unlink($path);
        }

        // ── 2. Child tables zadań (wszystkie z FK → tasks) ──────────────
        // ON DELETE CASCADE obsłużyłoby to automatycznie przy DELETE tasks,
        // ale dla pewności usuwamy ręcznie w bezpiecznej kolejności.
        foreach ([
            'task_task_tags',
            'task_subtasks',
            'task_comments',
            'task_files',
            'task_list_time',
            'task_history',
            'task_assignments',
        ] as $tbl) {
            $pdo->prepare("DELETE FROM {$tbl} WHERE task_id IN ({$ph})")
                ->execute($task_ids);
        }

        // ── 3. Twarde usunięcie zadań ────────────────────────────────────
        // Zwalnia referencję list_id → task_lists (ON DELETE RESTRICT)
        $pdo->prepare("DELETE FROM tasks WHERE workspace_id=?")
            ->execute([$workspace_id]);
    }

    // ── 4. Listy kolumn ──────────────────────────────────────────────────
    $pdo->prepare("DELETE FROM task_lists WHERE workspace_id=?")
        ->execute([$workspace_id]);

    // ── 5. Członkowie, tagi obszaru ──────────────────────────────────────
    $pdo->prepare("DELETE FROM task_workspace_members WHERE workspace_id=?")
        ->execute([$workspace_id]);

    $pdo->prepare("DELETE FROM task_tags WHERE workspace_id=?")
        ->execute([$workspace_id]);

    // ── 6. Wiadomości wewnętrzne zadaniowe ───────────────────────────────
    if ($task_ids) {
        $ph2 = implode(',', array_fill(0, count($task_ids), '?'));
        $pdo->prepare(
            "DELETE FROM messages WHERE context_type='task' AND context_id IN ({$ph2})"
        )->execute($task_ids);
    }

    // ── 7. Sam obszar ────────────────────────────────────────────────────
    $pdo->prepare("DELETE FROM task_workspaces WHERE id=?")
        ->execute([$workspace_id]);

    $pdo->commit();

    task_api_ok([
        'workspace_name' => $ws['name'],
        'tasks_removed'  => $task_count,
    ]);

} catch (\Throwable $e) {
    $pdo->rollBack();
    task_api_error('Błąd usuwania: ' . $e->getMessage());
}
