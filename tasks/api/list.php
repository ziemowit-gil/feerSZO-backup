<?php
/**
 * API: Listy (kolumny) — rename / reorder
 * POST JSON: { _csrf, action:'rename'|'reorder', ... }
 *
 * rename:  { list_id, name }
 * reorder: { workspace_id, ordered_ids: [id, id, ...] }
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);

// ── GET: pobierz kolumny danego workspace ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'lists') {
        $ws_id = (int)($_GET['ws'] ?? 0);
        if (!$ws_id) task_api_error('Brak ws.', 400);
        task_require_workspace_access($ws_id);
        $lists = db_all(
            "SELECT id, name, position, is_done_state FROM task_lists WHERE workspace_id=? ORDER BY position, id",
            [$ws_id]
        );
        task_api_ok($lists);
    }
    task_api_error('Nieznana akcja.', 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body = task_parse_json_body();
task_csrf_check($body);

$action = $body['action'] ?? '';

// ── Zmień nazwę kolumny ────────────────────────────────────────────────────
if ($action === 'rename') {
    $list_id = (int)($body['list_id'] ?? 0);
    $name    = trim($body['name']    ?? '');

    if (!$list_id)  task_api_error('Brak list_id.');
    if (!$name)     task_api_error('Nazwa nie może być pusta.');
    if (mb_strlen($name) > 120) task_api_error('Nazwa za długa (max 120 znaków).');

    $list = db_one("SELECT * FROM task_lists WHERE id=?", [$list_id]);
    if (!$list) task_api_error('Kolumna nie istnieje.', 404);

    task_require_workspace_access((int)$list['workspace_id'], ['admin', 'editor']);

    db()->prepare(
        "UPDATE task_lists SET name=?, updated_at=datetime('now','localtime') WHERE id=?"
    )->execute([$name, $list_id]);

    task_api_ok(['list_id' => $list_id, 'name' => $name]);
}

// ── Zmień kolejność kolumn ─────────────────────────────────────────────────
if ($action === 'reorder') {
    $ws_id       = (int)($body['workspace_id'] ?? 0);
    $ordered_ids = array_map('intval', $body['ordered_ids'] ?? []);

    if (!$ws_id)         task_api_error('Brak workspace_id.');
    if (!$ordered_ids)   task_api_error('Brak ordered_ids.');

    task_require_workspace_access($ws_id, ['admin', 'editor']);

    // Weryfikuj: wszystkie id należą do tego obszaru
    $existing = db_all("SELECT id FROM task_lists WHERE workspace_id=?", [$ws_id]);
    $valid_ids = array_column($existing, 'id');

    $pos  = 1;
    $stmt = db()->prepare(
        "UPDATE task_lists SET position=?, updated_at=datetime('now','localtime')
         WHERE id=? AND workspace_id=?"
    );
    foreach ($ordered_ids as $lid) {
        if (in_array($lid, $valid_ids, true)) {
            $stmt->execute([$pos++, $lid, $ws_id]);
        }
    }

    task_api_ok(['workspace_id' => $ws_id, 'count' => $pos - 1]);
}

task_api_error('Nieznana akcja. Oczekiwano: rename, reorder.', 400);
