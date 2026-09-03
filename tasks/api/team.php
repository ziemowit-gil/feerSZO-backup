<?php
/**
 * API: Zespoły
 * POST JSON: { _csrf, action, ... }
 *
 * Zarządzanie zespołem (create/update/toggle/delete/add_member/remove_member)
 * wymaga is_admin() — zespoły są konceptem systemowym, jak task_areas/task_tags.
 * Przypisanie zespołu do obszaru (link_workspace/unlink_workspace) wymaga
 * roli admin/editor W TYM obszarze (lider obszaru), nie globalnego admina.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);
task_teams_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body   = task_parse_json_body();
task_csrf_check($body);
$action = $body['action'] ?? '';

// ── Akcje wymagające is_admin() ─────────────────────────────────────────────
if (in_array($action, ['create', 'update', 'toggle', 'delete', 'add_member', 'remove_member'], true)) {
    if (!is_admin()) task_api_error('Brak uprawnień administratora.', 403);
}

if ($action === 'create') {
    $name = trim($body['name'] ?? '');
    if ($name === '') task_api_error('Nazwa zespołu jest wymagana.');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#2563eb';
    $icon  = preg_replace('/[^a-z0-9\-]/', '', $body['icon'] ?? 'people-fill') ?: 'people-fill';
    $id = db_insert('task_teams', [
        'name'        => $name,
        'description' => trim($body['description'] ?? ''),
        'color'       => $color,
        'icon'        => 'bi-' . ltrim($icon, 'bi-'),
        'is_active'   => 1,
        'created_by'  => $uid,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);
    task_api_ok(['id' => $id]);
}

if ($action === 'update') {
    $team_id = (int)($body['team_id'] ?? 0);
    if (!$team_id) task_api_error('Brak team_id.');
    $name = trim($body['name'] ?? '');
    if ($name === '') task_api_error('Nazwa zespołu jest wymagana.');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#2563eb';
    $icon  = preg_replace('/[^a-z0-9\-]/', '', $body['icon'] ?? 'people-fill') ?: 'people-fill';
    db()->prepare(
        "UPDATE task_teams SET name=?, description=?, color=?, icon=?, updated_at=datetime('now','localtime') WHERE id=?"
    )->execute([$name, trim($body['description'] ?? ''), $color, 'bi-' . ltrim($icon, 'bi-'), $team_id]);
    task_api_ok(['id' => $team_id]);
}

if ($action === 'toggle') {
    $team_id = (int)($body['team_id'] ?? 0);
    $team = db_one("SELECT is_active FROM task_teams WHERE id=?", [$team_id]);
    if (!$team) task_api_error('Zespół nie istnieje.', 404);
    db()->prepare("UPDATE task_teams SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
        ->execute([$team['is_active'] ? 0 : 1, $team_id]);
    task_api_ok(['id' => $team_id, 'is_active' => $team['is_active'] ? 0 : 1]);
}

if ($action === 'delete') {
    $team_id = (int)($body['team_id'] ?? 0);
    if (!$team_id) task_api_error('Brak team_id.');
    db()->prepare("DELETE FROM task_teams WHERE id=?")->execute([$team_id]);
    task_api_ok(['id' => $team_id]);
}

if ($action === 'add_member') {
    $team_id = (int)($body['team_id'] ?? 0);
    $user_id = (int)($body['user_id'] ?? 0);
    if (!$team_id || !$user_id) task_api_error('Brak team_id lub user_id.');
    if (!db_one("SELECT 1 FROM task_teams WHERE id=?", [$team_id])) task_api_error('Zespół nie istnieje.', 404);
    try {
        db()->prepare(
            "INSERT OR IGNORE INTO task_team_members (team_id, user_id, added_by, added_at)
             VALUES (?, ?, ?, datetime('now','localtime'))"
        )->execute([$team_id, $user_id, $uid]);
    } catch (\Throwable $e) { task_api_error('Błąd zapisu: ' . $e->getMessage(), 500); }
    task_api_ok(['team_id' => $team_id, 'user_id' => $user_id]);
}

if ($action === 'remove_member') {
    $team_id = (int)($body['team_id'] ?? 0);
    $user_id = (int)($body['user_id'] ?? 0);
    db()->prepare("DELETE FROM task_team_members WHERE team_id=? AND user_id=?")->execute([$team_id, $user_id]);
    task_api_ok(['team_id' => $team_id, 'user_id' => $user_id]);
}

// ── Akcje wymagające roli lidera W DANYM OBSZARZE ───────────────────────────
if ($action === 'link_workspace') {
    $team_id = (int)($body['team_id'] ?? 0);
    $ws_id   = (int)($body['workspace_id'] ?? 0);
    $role    = in_array($body['role'] ?? '', TASK_WS_ROLES, true) ? $body['role'] : 'member';
    if (!$team_id || !$ws_id) task_api_error('Brak team_id lub workspace_id.');
    task_require_workspace_access($ws_id, ['admin', 'editor']);
    if (!db_one("SELECT 1 FROM task_teams WHERE id=? AND is_active=1", [$team_id])) task_api_error('Zespół nie istnieje.', 404);
    db()->prepare(
        "INSERT INTO task_workspace_teams (workspace_id, team_id, role, added_by, added_at)
         VALUES (?, ?, ?, ?, datetime('now','localtime'))
         ON CONFLICT(workspace_id, team_id) DO UPDATE SET role=excluded.role"
    )->execute([$ws_id, $team_id, $role, $uid]);
    task_api_ok(['workspace_id' => $ws_id, 'team_id' => $team_id, 'role' => $role]);
}

if ($action === 'unlink_workspace') {
    $team_id = (int)($body['team_id'] ?? 0);
    $ws_id   = (int)($body['workspace_id'] ?? 0);
    if (!$team_id || !$ws_id) task_api_error('Brak team_id lub workspace_id.');
    task_require_workspace_access($ws_id, ['admin', 'editor']);
    db()->prepare("DELETE FROM task_workspace_teams WHERE workspace_id=? AND team_id=?")->execute([$ws_id, $team_id]);
    task_api_ok(['workspace_id' => $ws_id, 'team_id' => $team_id]);
}

task_api_error('Nieznana akcja.', 400);
