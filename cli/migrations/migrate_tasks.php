<?php
/**
 * Migracja: Moduł Zadań (Kanban)
 * Uruchom JEDNORAZOWO przez przeglądarkę lub CLI: php migrate_tasks.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$log = [];

function t_exec(string $sql, string $label): void {
    global $log;
    try {
        db()->exec($sql);
        $log[] = ['ok', $label];
    } catch (\Throwable $e) {
        $log[] = ['err', $label . ' — ' . $e->getMessage()];
    }
}

// ── Obszary robocze ────────────────────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_workspaces (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    slug        TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT,
    color       TEXT    NOT NULL DEFAULT '#2563eb',
    icon        TEXT    NOT NULL DEFAULT 'bi-kanban',
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_by  INTEGER NOT NULL,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
)", 'Tabela task_workspaces');

// ── Listy (kolumny) ────────────────────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_lists (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    name         TEXT    NOT NULL,
    position     REAL    NOT NULL DEFAULT 0,
    color        TEXT,
    is_done_state INTEGER NOT NULL DEFAULT 0,
    wip_limit    INTEGER,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE
)", 'Tabela task_lists');

// ── Tagi ──────────────────────────────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_tags (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER,
    name         TEXT    NOT NULL,
    color        TEXT    NOT NULL DEFAULT '#64748b',
    text_color   TEXT    NOT NULL DEFAULT '#ffffff',
    is_active    INTEGER NOT NULL DEFAULT 1,
    created_by   INTEGER NOT NULL,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE RESTRICT
)", 'Tabela task_tags');

// ── Zadania ────────────────────────────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS tasks (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id    INTEGER NOT NULL,
    list_id         INTEGER NOT NULL,
    title           TEXT    NOT NULL,
    description     TEXT,
    position        REAL    NOT NULL DEFAULT 0,
    priority        INTEGER NOT NULL DEFAULT 2,
    start_date      TEXT,
    due_date        TEXT,
    estimated_hours REAL,
    created_by      INTEGER NOT NULL,
    completed_at    TEXT,
    deleted_at      TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE,
    FOREIGN KEY (list_id)      REFERENCES task_lists(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE RESTRICT
)", 'Tabela tasks');

t_exec("CREATE INDEX IF NOT EXISTS idx_tasks_list     ON tasks(list_id, position)", 'Indeks tasks.list_id');
t_exec("CREATE INDEX IF NOT EXISTS idx_tasks_due      ON tasks(due_date, completed_at, deleted_at)", 'Indeks tasks.due_date');
t_exec("CREATE INDEX IF NOT EXISTS idx_tasks_ws       ON tasks(workspace_id, deleted_at)", 'Indeks tasks.workspace_id');

// ── Powiązanie zadanie–tag ─────────────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_task_tags (
    task_id     INTEGER NOT NULL,
    tag_id      INTEGER NOT NULL,
    assigned_by INTEGER NOT NULL,
    assigned_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (task_id, tag_id),
    FOREIGN KEY (task_id)     REFERENCES tasks(id)     ON DELETE CASCADE,
    FOREIGN KEY (tag_id)      REFERENCES task_tags(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id)     ON DELETE RESTRICT
)", 'Tabela task_task_tags');

// ── Przypisania użytkowników ───────────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_assignments (
    task_id     INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    assigned_by INTEGER NOT NULL,
    assigned_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (task_id, user_id),
    FOREIGN KEY (task_id)     REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
)", 'Tabela task_assignments');

t_exec("CREATE INDEX IF NOT EXISTS idx_task_asgn_user ON task_assignments(user_id)", 'Indeks task_assignments.user_id');

// ── Komentarze ────────────────────────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER NOT NULL,
    author_id  INTEGER NOT NULL,
    body       TEXT    NOT NULL,
    is_edited  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (task_id)   REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT
)", 'Tabela task_comments');

t_exec("CREATE INDEX IF NOT EXISTS idx_task_comments ON task_comments(task_id, created_at)", 'Indeks task_comments');

// ── Historia zdarzeń (audit log) ──────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id     INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    event_type  TEXT    NOT NULL,
    from_value  TEXT,
    to_value    TEXT,
    metadata    TEXT,
    occurred_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
)", 'Tabela task_history');

t_exec("CREATE INDEX IF NOT EXISTS idx_task_hist_task  ON task_history(task_id, occurred_at)", 'Indeks task_history.task_id');
t_exec("CREATE INDEX IF NOT EXISTS idx_task_hist_user  ON task_history(user_id, occurred_at)", 'Indeks task_history.user_id');
t_exec("CREATE INDEX IF NOT EXISTS idx_task_hist_event ON task_history(event_type, occurred_at)", 'Indeks task_history.event_type');

// ── Czas w kolumnie (analityka) ───────────────────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_list_time (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id          INTEGER NOT NULL,
    list_id          INTEGER NOT NULL,
    list_name        TEXT    NOT NULL,
    entered_at       TEXT    NOT NULL,
    exited_at        TEXT,
    duration_seconds INTEGER,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
)", 'Tabela task_list_time');

t_exec("CREATE INDEX IF NOT EXISTS idx_tlt_task ON task_list_time(task_id)", 'Indeks task_list_time.task_id');

// ── Członkowie obszarów (RBAC per workspace) ──────────────────────────────
t_exec("CREATE TABLE IF NOT EXISTS task_workspace_members (
    workspace_id INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    role         TEXT    NOT NULL DEFAULT 'editor',
    added_by     INTEGER NOT NULL,
    added_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (workspace_id, user_id),
    FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE
)", 'Tabela task_workspace_members');

// ── Domyślne dane ─────────────────────────────────────────────────────────
// Jeśli nie ma żadnych tagów globalnych, stwórz przykładowe
$tag_count = db_one("SELECT COUNT(*) AS c FROM task_tags WHERE workspace_id IS NULL");
if ((int)($tag_count['c'] ?? 0) === 0) {
    $admin = db_one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");
    if ($admin) {
        $uid = $admin['id'];
        $tags = [
            ['Pilne',      '#dc2626', '#ffffff'],
            ['Bug',        '#ea580c', '#ffffff'],
            ['Feature',    '#2563eb', '#ffffff'],
            ['Do weryfikacji', '#9333ea', '#ffffff'],
            ['Blokada',    '#475569', '#ffffff'],
        ];
        foreach ($tags as [$name, $color, $tc]) {
            try {
                db()->prepare(
                    "INSERT OR IGNORE INTO task_tags (workspace_id, name, color, text_color, created_by)
                     VALUES (NULL, ?, ?, ?, ?)"
                )->execute([$name, $color, $tc, $uid]);
            } catch (\Throwable $e) {}
        }
        $log[] = ['ok', 'Dodano domyślne tagi globalne'];
    }
}

// ── Output ────────────────────────────────────────────────────────────────
$is_cli = php_sapi_name() === 'cli';
if ($is_cli) {
    foreach ($log as [$s, $m]) echo ($s === 'ok' ? '✓' : '✗') . " $m\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Migracja: Moduł Zadań</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container" style="max-width:680px">
  <h4 class="mb-4"><i class="bi bi-kanban"></i> Migracja: Moduł Zadań</h4>
  <ul class="list-group mb-4">
  <?php foreach ($log as [$status, $msg]): ?>
    <li class="list-group-item d-flex gap-2 align-items-center py-2
               <?= $status === 'ok' ? 'list-group-item-success' : 'list-group-item-danger' ?>">
      <i class="bi bi-<?= $status === 'ok' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
      <small><?= h($msg) ?></small>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php $errs = array_filter($log, fn($l) => $l[0] === 'err'); ?>
  <?php if (empty($errs)): ?>
  <div class="alert alert-success">
    <strong>Migracja zakończona.</strong>
    Możesz teraz przejść do <a href="<?= APP_URL ?>/tasks/index.php">Zadań</a>
    lub <a href="<?= APP_URL ?>/admin/tasks_workspaces.php">zarządzać obszarami</a>.
  </div>
  <?php else: ?>
  <div class="alert alert-warning">Niektóre kroki zwróciły błędy — sprawdź powyżej.</div>
  <?php endif; ?>
</div>
</body>
</html>
