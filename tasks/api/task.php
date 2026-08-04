<?php
/**
 * API: Zadania — create / update / delete / detail
 * POST body JSON: { _csrf, action, ... }
 * GET ?action=detail&id=X
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);

// ── GET: detail ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action !== 'detail') task_api_error('Nieznana akcja.', 400);

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.', 400);

    $task = db_one(
        "SELECT t.*, tl.name AS list_name, tl.workspace_id
         FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
         WHERE t.id=? AND t.deleted_at IS NULL",
        [$id]
    );
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);

    task_require_workspace_access((int)$task['workspace_id']);

    $task['tags'] = db_all(
        "SELECT tt.id, tt.name, tt.color, tt.text_color
         FROM task_task_tags ttt JOIN task_tags tt ON tt.id = ttt.tag_id
         WHERE ttt.task_id=? ORDER BY tt.name",
        [$id]
    );
    $task['assignees'] = db_all(
        "SELECT u.id, u.name, u.email
         FROM task_assignments ta JOIN users u ON u.id = ta.user_id
         WHERE ta.task_id=? ORDER BY u.name",
        [$id]
    );
    $task['comments'] = db_all(
        "SELECT tc.*, u.name AS author_name
         FROM task_comments tc JOIN users u ON u.id = tc.author_id
         WHERE tc.task_id=? AND tc.deleted_at IS NULL
         ORDER BY tc.created_at",
        [$id]
    );
    $task['history'] = db_all(
        "SELECT th.*, u.name AS actor_name
         FROM task_history th JOIN users u ON u.id = th.user_id
         WHERE th.task_id=?
         ORDER BY th.occurred_at DESC LIMIT 50",
        [$id]
    );

    task_api_ok($task);
}

// ── POST: mutacje ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') task_api_error('Metoda niedozwolona.', 405);

$body   = task_parse_json_body();
task_csrf_check($body);

$action = $body['action'] ?? '';

// ── Utwórz zadanie ─────────────────────────────────────────────────────────
if ($action === 'create') {
    $list_id     = (int)($body['list_id']      ?? 0);
    $workspace_id= (int)($body['workspace_id'] ?? 0);
    $title       = trim($body['title']         ?? '');

    if (!$list_id || !$workspace_id || !$title) {
        task_api_error('Brak wymaganych pól: title, list_id, workspace_id.');
    }
    task_require_workspace_access($workspace_id, ['admin', 'editor']);

    $list = db_one("SELECT * FROM task_lists WHERE id=? AND workspace_id=?", [$list_id, $workspace_id]);
    if (!$list) task_api_error('Lista nie istnieje w tym obszarze.', 404);

    $max_pos = db_one("SELECT MAX(position) AS m FROM tasks WHERE list_id=? AND deleted_at IS NULL", [$list_id]);
    $new_pos = (float)($max_pos['m'] ?? 0) + 1;

    $now = date('Y-m-d H:i:s');
    $id  = db_insert('tasks', [
        'workspace_id'    => $workspace_id,
        'list_id'         => $list_id,
        'title'           => $title,
        'description'     => trim($body['description'] ?? ''),
        'position'        => $new_pos,
        'priority'        => max(1, min(4, (int)($body['priority'] ?? 2))),
        'start_date'      => $body['start_date'] ?? null,
        'due_date'        => $body['due_date']   ?? null,
        'estimated_hours' => isset($body['estimated_hours']) ? (float)$body['estimated_hours'] : null,
        'area_id'         => isset($body['area_id']) ? ((int)$body['area_id'] ?: null) : null,
        'unit_id'         => isset($body['unit_id']) ? ((int)$body['unit_id'] ?: null) : null,
        'created_by'      => $uid,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    task_start_time_tracking($id, $list_id, $list['name']);
    task_log($id, $uid, 'created', null, $list['name']);

    require_once dirname(__DIR__, 2) . '/includes/task_notify.php';
    require_once dirname(__DIR__, 2) . '/includes/task_notification_service.php';

    // Opcjonalne przypisania (tryb "Osoba")
    if (!empty($body['assignees']) && is_array($body['assignees'])) {
        $stmt  = db()->prepare(
            "INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by, assigned_at)
             VALUES (?, ?, ?, ?)"
        );
        $stmtM = db()->prepare(
            "INSERT OR IGNORE INTO task_workspace_members
             (workspace_id, user_id, role, added_by, added_at)
             VALUES (?, ?, 'member', ?, datetime('now','localtime'))"
        );
        $ts = date('Y-m-d H:i:s');
        foreach ($body['assignees'] as $auid) {
            $auid = (int)$auid;
            if ($auid <= 0) continue;
            $stmt->execute([$id, $auid, $uid, $ts]);
            // Gwarantuj dostęp do obszaru (nie nadpisuje istniejącej wyższej roli)
            $stmtM->execute([$workspace_id, $auid, $uid]);
            $u = db_one("SELECT name FROM users WHERE id=?", [$auid]);
            if ($u) task_log($id, $uid, 'assigned', null, $u['name']);
            try { task_notify_assigned($id, $auid, $uid); } catch (\Throwable $e) {}
        }
    }

    // Powiadamia liderów obszaru o nowym zadaniu + diagnostyka post-save
    try {
        task_notify_created($id, $uid);
        $diag = (new TaskNotificationService(db()))->runPostSaveTest($id, 'created');
        if (!$diag['ok']) {
            error_log('[NOTIF_DIAG create] task=' . $id . ' ' . json_encode(array_column(
                array_filter($diag['checks'], fn($c) => $c['pass'] === false), 'msg'
            )));
        }
    } catch (\Throwable $e) {
        error_log('[task.php create] notify exception: ' . $e->getMessage());
    }

    $task = db_one("SELECT * FROM tasks WHERE id=?", [$id]);
    task_api_ok($task);
}

// ── Aktualizuj zadanie ─────────────────────────────────────────────────────
if ($action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.');

    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);

    $ws_role = task_workspace_role((int)$task['workspace_id']);
    if (!in_array($ws_role, ['admin', 'editor'], true)) {
        // member/viewer może edytować tylko przypisane sobie zadania
        if (!in_array($ws_role, ['member', 'viewer'], true) || !task_is_assigned($id)) {
            task_api_error('Brak uprawnień do edycji tego zadania.', 403);
        }
    }

    // 'comments'/'files' są w TASK_GOVERNED_FIELDS (współdzielą mechanizm
    // uprawnień z ustawieniami admina), ale NIE są kolumnami tabeli tasks —
    // rządzą nimi osobne endpointy (comment.php, upload.php). Wykluczone tu,
    // żeby spreparowane żądanie z takim kluczem nie wywołało UPDATE tasks
    // SET comments=... na nieistniejącej kolumnie.
    $allowed = array_values(array_filter(
        array_keys(TASK_GOVERNED_FIELDS),
        fn($f) => !in_array($f, ['comments', 'files'], true) && task_field_editable($f, $ws_role)
    ));
    $changes = [];
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $body)) continue;
        $val = $body[$f];
        if ($f === 'title')   $val = trim($val);
        if ($f === 'priority') $val = max(1, min(4, (int)$val));
        if ($f === 'estimated_hours') $val = $val !== '' && $val !== null ? (float)$val : null;
        if ($f === 'claimable') $val = $val ? 1 : 0;
        if ($f === 'start_date' || $f === 'due_date') $val = ($val === '' ? null : $val);
        if ($f === 'area_id') $val = ($val ? (int)$val : null);
        if ($f === 'unit_id') $val = ($val ? (int)$val : null);

        // Loguj zmiany
        if ((string)($task[$f] ?? '') !== (string)($val ?? '')) {
            $event_map = ['title'=>'title_changed','description'=>'description_changed',
                          'priority'=>'priority_changed','due_date'=>'due_changed'];
            if (isset($event_map[$f])) {
                task_log($id, $uid, $event_map[$f], (string)($task[$f] ?? ''), (string)($val ?? ''));
            }
        }
        $changes[$f] = $val;
    }

    if ($changes) {
        $changes['updated_at'] = date('Y-m-d H:i:s');
        $changes['id'] = $id;
        $set = implode(', ', array_map(fn($k) => "$k=:$k", array_keys(array_diff_key($changes, ['id'=>1]))));
        db()->prepare("UPDATE tasks SET $set WHERE id=:id")->execute($changes);
    }

    // Opcjonalne czyszczenie przypisań osobistych (przy zmianie na tryb jednostki)
    if (!empty($body['clear_assignees']) && in_array($ws_role, ['admin', 'editor'], true)) {
        db()->prepare("DELETE FROM task_assignments WHERE task_id=?")->execute([$id]);
        task_log($id, $uid, 'unassigned', 'wszystkie', null);
    }

    task_api_ok(db_one("SELECT * FROM tasks WHERE id=?", [$id]));
}

// ── Soft-delete zadania ────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.');

    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);

    task_require_workspace_access((int)$task['workspace_id'], ['admin', 'editor']);

    $now = date('Y-m-d H:i:s');
    db()->prepare("UPDATE tasks SET deleted_at=?, updated_at=? WHERE id=?")->execute([$now, $now, $id]);

    // Zamknij otwarty rekord czasu
    $open = db_one("SELECT id, entered_at FROM task_list_time WHERE task_id=? AND exited_at IS NULL", [$id]);
    if ($open) {
        $secs = max(0, strtotime($now) - strtotime($open['entered_at']));
        db()->prepare("UPDATE task_list_time SET exited_at=?, duration_seconds=? WHERE id=?")
            ->execute([$now, $secs, $open['id']]);
    }

    task_log($id, $uid, 'deleted');
    task_api_ok(['id' => $id]);
}

// ── Oznacz jako ukończone ──────────────────────────────────────────────────
if ($action === 'complete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.');
    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);
    $ws_role = task_workspace_role((int)$task['workspace_id']);
    if (!in_array($ws_role, ['admin', 'editor'], true)) {
        if (!in_array($ws_role, ['member', 'viewer'], true) || !task_is_assigned($id)) {
            task_api_error('Brak uprawnień do zmiany statusu tego zadania.', 403);
        }
    }

    $done_list = db_one(
        "SELECT id, name FROM task_lists WHERE workspace_id=? AND is_done_state=1 ORDER BY position LIMIT 1",
        [$task['workspace_id']]
    );
    $now = date('Y-m-d H:i:s');
    if ($done_list && (int)$task['list_id'] !== (int)$done_list['id']) {
        task_move((int)$task['id'], (int)$done_list['id'], 9999, $uid);
    } else {
        db()->prepare("UPDATE tasks SET completed_at=?, updated_at=? WHERE id=?")->execute([$now, $now, $id]);
        task_log($id, $uid, 'completed');
    }
    task_api_ok(db_one("SELECT * FROM tasks WHERE id=?", [$id]));
}

// ── Wznów zadanie ──────────────────────────────────────────────────────────
if ($action === 'reopen') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.');
    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);
    $ws_role = task_workspace_role((int)$task['workspace_id']);
    if (!in_array($ws_role, ['admin', 'editor'], true)) {
        if (!in_array($ws_role, ['member', 'viewer'], true) || !task_is_assigned($id)) {
            task_api_error('Brak uprawnień do zmiany statusu tego zadania.', 403);
        }
    }

    task_review_schema_heal();

    $first_list = db_one(
        "SELECT id, name FROM task_lists WHERE workspace_id=? AND is_done_state=0 ORDER BY position LIMIT 1",
        [$task['workspace_id']]
    );
    $now = date('Y-m-d H:i:s');
    $clear_review = ", confirmed_at=NULL, confirmed_by=NULL, rejected_at=NULL, rejected_by=NULL, rejection_reason=NULL";
    if ($first_list) {
        db()->prepare("UPDATE tasks SET list_id=?, completed_at=NULL{$clear_review}, updated_at=? WHERE id=?")
            ->execute([$first_list['id'], $now, $id]);
        task_log($id, $uid, 'reopened', null, $first_list['name']);
    } else {
        db()->prepare("UPDATE tasks SET completed_at=NULL{$clear_review}, updated_at=? WHERE id=?")->execute([$now, $id]);
        task_log($id, $uid, 'reopened');
    }
    task_api_ok(db_one("SELECT * FROM tasks WHERE id=?", [$id]));
}

// ── Potwierdź wykonanie (lider akceptuje ukończenie) ────────────────────────
if ($action === 'confirm') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.');
    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);

    task_require_workspace_access((int)$task['workspace_id']);

    if (!$task['completed_at']) task_api_error('Zadanie nie jest jeszcze ukończone.');
    if (!empty($task['confirmed_at'])) task_api_error('Wykonanie zostało już potwierdzone.');

    $ws_role    = task_workspace_role((int)$task['workspace_id']);
    $is_creator = (int)$task['created_by'] === $uid;
    if (!$is_creator && !in_array($ws_role, ['admin', 'editor'], true)) {
        task_api_error('Tylko lider obszaru może potwierdzić wykonanie.', 403);
    }

    task_review_schema_heal();

    $now = date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE tasks SET confirmed_at=?, confirmed_by=?, rejected_at=NULL, rejected_by=NULL, rejection_reason=NULL, updated_at=? WHERE id=?"
    )->execute([$now, $uid, $now, $id]);
    task_log($id, $uid, 'confirmed');

    require_once dirname(__DIR__, 2) . '/includes/task_notify.php';
    task_notify_confirmed($id, $uid);

    task_api_ok(db_one("SELECT * FROM tasks WHERE id=?", [$id]));
}

// ── Odrzuć wykonanie (lider wskazuje powód) ─────────────────────────────────
if ($action === 'reject') {
    $id     = (int)($body['id'] ?? 0);
    $reason = trim((string)($body['reason'] ?? ''));
    if (!$id) task_api_error('Brak ID zadania.');
    if ($reason === '') task_api_error('Podaj powód odrzucenia.');

    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);

    task_require_workspace_access((int)$task['workspace_id']);

    if (!$task['completed_at']) task_api_error('Zadanie nie jest jeszcze ukończone.');
    if (!empty($task['confirmed_at'])) task_api_error('Wykonanie zostało już potwierdzone — nie można go odrzucić.');

    $ws_role    = task_workspace_role((int)$task['workspace_id']);
    $is_creator = (int)$task['created_by'] === $uid;
    if (!$is_creator && !in_array($ws_role, ['admin', 'editor'], true)) {
        task_api_error('Tylko lider obszaru może odrzucić wykonanie.', 403);
    }

    task_review_schema_heal();

    $now = date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE tasks SET rejected_at=?, rejected_by=?, rejection_reason=?, updated_at=? WHERE id=?"
    )->execute([$now, $uid, mb_substr($reason, 0, 1000), $now, $id]);
    task_log($id, $uid, 'rejected', null, mb_substr($reason, 0, 120));

    require_once dirname(__DIR__, 2) . '/includes/task_notify.php';
    task_notify_rejected($id, $uid, $reason);

    task_api_ok(db_one("SELECT * FROM tasks WHERE id=?", [$id]));
}

// ── Przywróć z archiwum ──────────────────────────────────────────────────────
if ($action === 'unarchive') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.');
    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);
    task_require_workspace_access((int)$task['workspace_id'], ['admin', 'editor']);

    db()->prepare("UPDATE tasks SET archived_at=NULL, updated_at=? WHERE id=?")
        ->execute([date('Y-m-d H:i:s'), $id]);
    task_log($id, $uid, 'unarchived');

    task_api_ok(db_one("SELECT * FROM tasks WHERE id=?", [$id]));
}

// ── Duplikuj zadanie ───────────────────────────────────────────────────────
if ($action === 'duplicate') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) task_api_error('Brak ID zadania.');
    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$task) task_api_error('Zadanie nie istnieje.', 404);
    task_require_workspace_access((int)$task['workspace_id'], ['admin', 'editor']);

    $now     = date('Y-m-d H:i:s');
    $new_pos = (float)(db_one(
        "SELECT COALESCE(MAX(position),0)+1 AS p FROM tasks WHERE list_id=?",
        [$task['list_id']]
    )['p'] ?? 1);

    $new_id = db_insert('tasks', [
        'workspace_id' => $task['workspace_id'],
        'list_id'      => $task['list_id'],
        'title'        => 'Kopia: ' . $task['title'],
        'description'  => $task['description'],
        'priority'     => $task['priority'],
        'start_date'   => $task['start_date'],
        'due_date'     => $task['due_date'],
        'position'     => $new_pos,
        'created_by'   => $uid,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    $tags = db_all("SELECT tag_id FROM task_task_tags WHERE task_id=?", [$id]);
    foreach ($tags as $t) {
        try {
            db()->prepare("INSERT INTO task_task_tags (task_id, tag_id) VALUES (?,?)")
                ->execute([$new_id, $t['tag_id']]);
        } catch (\Throwable $e) {}
    }

    task_log($new_id, $uid, 'created', null, 'Duplikat zadania #' . $id);
    task_api_ok(db_one("SELECT * FROM tasks WHERE id=?", [$new_id]));
}

task_api_error('Nieznana akcja.', 400);
