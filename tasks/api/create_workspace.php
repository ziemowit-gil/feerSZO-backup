<?php
/**
 * API: Utwórz obszar roboczy z poziomu szczegółów zadania
 * POST { _csrf, name, color, icon, task_id? }
 * Zwraca { ok, data: { workspace_id, workspace_url } }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();
require_role('admin', 'editor');

$body = task_parse_json_body();
task_csrf_check($body);

$uid   = (int)(current_user()['id'] ?? 0);
$name  = trim($body['name']  ?? '');
$color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#2563eb';
$icon  = preg_replace('/[^a-z0-9\-]/', '', $body['icon']  ?? 'kanban');
$icon  = 'bi-' . ltrim($icon, 'bi-');
$task_id = (int)($body['task_id'] ?? 0);

if (!$name) task_api_error('Nazwa obszaru jest wymagana.');

// Unikalny slug
$base = preg_replace('/-+/', '-', trim(preg_replace('/[^a-z0-9\-]/', '-', mb_strtolower($name)), '-'));
$slug = $base ?: 'obszar';
$n    = 1;
while (db_one("SELECT id FROM task_workspaces WHERE slug=?", [$slug])) {
    $slug = $base . '-' . $n++;
}

$now = date('Y-m-d H:i:s');

$ws_id = db_insert('task_workspaces', [
    'slug'        => $slug,
    'name'        => $name,
    'description' => $task_id ? 'Obszar utworzony z zadania #' . $task_id : '',
    'color'       => $color,
    'icon'        => $icon,
    'is_active'   => 1,
    'created_by'  => $uid,
    'created_at'  => $now,
    'updated_at'  => $now,
]);

// Domyślne listy
foreach ([
    ['Nowe',             1, 0, '#e2e8f0'],
    ['W trakcie',        2, 0, '#2563eb'],
    ['Do weryfikacji',   3, 0, '#f59e0b'],
    ['Gotowe',           4, 1, '#16a34a'],
] as [$lname, $pos, $done, $lc]) {
    db_insert('task_lists', [
        'workspace_id'  => $ws_id,
        'name'          => $lname,
        'position'      => $pos,
        'color'         => $lc,
        'is_done_state' => $done,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);
}

// Dodaj twórcę + adminów jako admin obszaru
$admins = db_all("SELECT id FROM users WHERE role='admin' AND is_active=1");
$seen   = [];
foreach (array_merge([['id' => $uid]], $admins) as $a) {
    $aid = (int)$a['id'];
    if (isset($seen[$aid])) continue;
    $seen[$aid] = true;
    try {
        db()->prepare(
            "INSERT OR IGNORE INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at) VALUES (?,?,?,?,?)"
        )->execute([$ws_id, $aid, 'admin', $uid, $now]);
    } catch (\Throwable $e) {}
}

// Opcjonalnie: przenieś zadanie do nowego obszaru (pierwsza lista)
if ($task_id) {
    $first_list = db_one("SELECT id FROM task_lists WHERE workspace_id=? ORDER BY position LIMIT 1", [$ws_id]);
    if ($first_list) {
        db()->prepare(
            "UPDATE tasks SET workspace_id=?, list_id=?, updated_at=? WHERE id=?"
        )->execute([$ws_id, $first_list['id'], $now, $task_id]);
        task_log($task_id, $uid, 'moved', null, $name);
    }
}

$ws_url = rtrim(APP_URL, '/') . '/tasks/index.php?ws=' . $ws_id;

task_api_ok([
    'workspace_id'  => $ws_id,
    'workspace_url' => $ws_url,
    'name'          => $name,
]);
