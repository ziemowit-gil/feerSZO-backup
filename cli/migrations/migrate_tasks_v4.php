<?php
/**
 * Migracja v4: Podzadania (task_subtasks)
 * Idempotentna — można uruchamiać wielokrotnie.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$log = [];

function t4(string $sql, string $label): void {
    global $log;
    try { db()->exec($sql); $log[] = ['ok', $label]; }
    catch (\Throwable $e) { $log[] = ['err', $label . ' — ' . $e->getMessage()]; }
}

// ── Tabela podzadań ────────────────────────────────────────────────────────
t4("CREATE TABLE IF NOT EXISTS task_subtasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id      INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    is_done      INTEGER NOT NULL DEFAULT 0,
    position     REAL    NOT NULL DEFAULT 0,
    created_by   INTEGER NOT NULL,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    completed_at TEXT,
    FOREIGN KEY (task_id)    REFERENCES tasks(id)  ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE RESTRICT
)", 'Tabela task_subtasks');

t4("CREATE INDEX IF NOT EXISTS idx_task_subtasks_task
    ON task_subtasks(task_id, position)", 'Indeks task_subtasks.task_id');

$is_cli = php_sapi_name() === 'cli';
if ($is_cli) { foreach ($log as [$s,$m]) echo ($s==='ok'?'✓':'✗')." $m\n"; exit; }
?>
<!DOCTYPE html>
<html lang="pl"><head><meta charset="UTF-8"><title>Migracja v4</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4"><div class="container" style="max-width:640px">
<h5 class="mb-3">Migracja v4 — Podzadania</h5>
<ul class="list-group mb-3">
<?php foreach ($log as [$s,$m]): ?>
<li class="list-group-item list-group-item-<?= $s==='ok'?'success':'danger' ?> py-2 small">
  <?= $s==='ok' ? '✓' : '✗' ?> <?= htmlspecialchars($m) ?>
</li>
<?php endforeach; ?>
</ul>
<?php if (!array_filter($log, fn($l)=>$l[0]==='err')): ?>
<div class="alert alert-success">Gotowe — <a href="<?= APP_URL ?>/tasks/index.php">wróć do zadań</a></div>
<?php endif; ?>
</div></body></html>
