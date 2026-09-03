<?php
/**
 * API: Szablony zadań (checklisty)
 * POST JSON: { _csrf, action, ... }
 *
 * Zarządzanie szablonem (create/update/toggle/delete/add_item/delete_item)
 * wymaga is_admin() — jak Zespoły/Obszary zadań. Zastosowanie szablonu
 * (apply) wymaga roli admin/editor W OBSZARZE, do którego się go stosuje.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);
task_templates_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body   = task_parse_json_body();
task_csrf_check($body);
$action = $body['action'] ?? '';

if (in_array($action, ['create', 'update', 'toggle', 'delete', 'add_item', 'update_item', 'delete_item'], true)) {
    if (!is_admin()) task_api_error('Brak uprawnień administratora.', 403);
}

if ($action === 'create') {
    $name = trim($body['name'] ?? '');
    if ($name === '') task_api_error('Nazwa szablonu jest wymagana.');
    $id = db_insert('task_templates', [
        'name'        => $name,
        'description' => trim($body['description'] ?? ''),
        'is_active'   => 1,
        'created_by'  => $uid,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);
    task_api_ok(['id' => $id]);
}

if ($action === 'update') {
    $template_id = (int)($body['template_id'] ?? 0);
    $name = trim($body['name'] ?? '');
    if (!$template_id || $name === '') task_api_error('Brak template_id lub nazwy.');
    db()->prepare(
        "UPDATE task_templates SET name=?, description=?, updated_at=datetime('now','localtime') WHERE id=?"
    )->execute([$name, trim($body['description'] ?? ''), $template_id]);
    task_api_ok(['id' => $template_id]);
}

if ($action === 'toggle') {
    $template_id = (int)($body['template_id'] ?? 0);
    $t = db_one("SELECT is_active FROM task_templates WHERE id=?", [$template_id]);
    if (!$t) task_api_error('Szablon nie istnieje.', 404);
    db()->prepare("UPDATE task_templates SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
        ->execute([$t['is_active'] ? 0 : 1, $template_id]);
    task_api_ok(['id' => $template_id, 'is_active' => $t['is_active'] ? 0 : 1]);
}

if ($action === 'delete') {
    $template_id = (int)($body['template_id'] ?? 0);
    if (!$template_id) task_api_error('Brak template_id.');
    db()->prepare("DELETE FROM task_templates WHERE id=?")->execute([$template_id]);
    task_api_ok(['id' => $template_id]);
}

if ($action === 'add_item') {
    $template_id = (int)($body['template_id'] ?? 0);
    $title       = trim($body['title'] ?? '');
    if (!$template_id || $title === '') task_api_error('Brak template_id lub tytułu pozycji.');
    if (!db_one("SELECT 1 FROM task_templates WHERE id=?", [$template_id])) task_api_error('Szablon nie istnieje.', 404);
    $next_pos = (int)(db_one("SELECT COUNT(*) AS n FROM task_template_items WHERE template_id=?", [$template_id])['n'] ?? 0);
    $id = db_insert('task_template_items', [
        'template_id' => $template_id,
        'title'       => $title,
        'description' => trim($body['description'] ?? ''),
        'priority'    => max(1, min(4, (int)($body['priority'] ?? 2))),
        'position'    => $next_pos,
    ]);
    task_api_ok(['id' => $id]);
}

if ($action === 'update_item') {
    $item_id = (int)($body['item_id'] ?? 0);
    $title   = trim($body['title'] ?? '');
    if (!$item_id || $title === '') task_api_error('Brak item_id lub tytułu.');
    db()->prepare("UPDATE task_template_items SET title=?, description=?, priority=? WHERE id=?")
        ->execute([$title, trim($body['description'] ?? ''), max(1, min(4, (int)($body['priority'] ?? 2))), $item_id]);
    task_api_ok(['id' => $item_id]);
}

if ($action === 'delete_item') {
    $item_id = (int)($body['item_id'] ?? 0);
    if (!$item_id) task_api_error('Brak item_id.');
    db()->prepare("DELETE FROM task_template_items WHERE id=?")->execute([$item_id]);
    task_api_ok(['id' => $item_id]);
}

if ($action === 'apply') {
    $template_id  = (int)($body['template_id'] ?? 0);
    $workspace_id = (int)($body['workspace_id'] ?? 0);
    $list_id      = (int)($body['list_id'] ?? 0);
    if (!$template_id || !$workspace_id || !$list_id) task_api_error('Brak template_id, workspace_id lub list_id.');
    task_require_workspace_access($workspace_id, ['admin', 'editor']);
    if (!db_one("SELECT 1 FROM task_templates WHERE id=? AND is_active=1", [$template_id])) {
        task_api_error('Szablon nie istnieje.', 404);
    }
    try {
        $ids = task_apply_template($template_id, $workspace_id, $list_id, $uid);
    } catch (\RuntimeException $e) {
        task_api_error($e->getMessage(), 400);
    }
    task_api_ok(['created' => $ids, 'count' => count($ids)]);
}

task_api_error('Nieznana akcja.', 400);
