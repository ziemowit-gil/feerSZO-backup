<?php
/**
 * Migracja v2: Pliki załączone do zadań
 * Uruchom JEDNORAZOWO przez przeglądarkę lub CLI
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$log = [];

function t2_exec(string $sql, string $label): void {
    global $log;
    try { db()->exec($sql); $log[] = ['ok', $label]; }
    catch (\Throwable $e) { $log[] = ['err', $label . ' — ' . $e->getMessage()]; }
}

t2_exec("CREATE TABLE IF NOT EXISTS task_files (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id       INTEGER NOT NULL,
    original_name TEXT    NOT NULL,
    stored_name   TEXT    NOT NULL,
    file_size     INTEGER NOT NULL DEFAULT 0,
    mime_type     TEXT,
    uploaded_by   INTEGER NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (task_id)     REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
)", 'Tabela task_files');

t2_exec("CREATE INDEX IF NOT EXISTS idx_task_files_task ON task_files(task_id)", 'Indeks task_files.task_id');

// Katalog uploads
$dir = __DIR__ . '/uploads/tasks';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    $log[] = ['ok', 'Katalog uploads/tasks utworzony'];
} else {
    $log[] = ['ok', 'Katalog uploads/tasks już istnieje'];
}

// .htaccess — zablokuj wykonanie PHP w uploads/tasks
$htaccess = $dir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Options -ExecCGI\nAddHandler cgi-script .php .pl .py\n<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n");
    $log[] = ['ok', '.htaccess w uploads/tasks'];
}

$is_cli = php_sapi_name() === 'cli';
if ($is_cli) { foreach ($log as [$s,$m]) echo ($s==='ok'?'✓':'✗')." $m\n"; exit; }
?>
<!DOCTYPE html>
<html lang="pl"><head><meta charset="UTF-8"><title>Migracja v2</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4"><div class="container" style="max-width:600px">
<h5 class="mb-3">Migracja v2: Pliki zadań</h5>
<ul class="list-group mb-3">
<?php foreach ($log as [$s,$m]): ?>
<li class="list-group-item list-group-item-<?= $s==='ok'?'success':'danger' ?> py-2 small">
  <i class="bi bi-<?= $s==='ok'?'check-circle-fill':'x-circle-fill' ?>"></i> <?= h($m ?? '') ?>
</li>
<?php endforeach; ?>
</ul>
<?php if (!array_filter($log, fn($l)=>$l[0]==='err')): ?>
<div class="alert alert-success">Gotowe — <a href="<?= APP_URL ?>/tasks/index.php">wróć do zadań</a></div>
<?php endif; ?>
</div></body></html>
