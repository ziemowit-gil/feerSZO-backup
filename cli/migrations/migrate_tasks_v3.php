<?php
/**
 * Migracja v3:
 *  1. Admini systemowi → automatyczni adminowie wszystkich obszarów roboczych
 *  2. (Idempotentna — można uruchamiać wielokrotnie)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$log = [];

function t3($sql, $label): void {
    global $log;
    try { db()->exec($sql); $log[] = ['ok', $label]; }
    catch (\Throwable $e) { $log[] = ['err', $label . ' — ' . $e->getMessage()]; }
}

// 1. Pobierz wszystkich adminów systemowych (aktywnych)
$admins = db_all("SELECT id, name FROM users WHERE role='admin' AND is_active=1");
$workspaces = db_all("SELECT id, name FROM task_workspaces WHERE is_active=1");

$now = date('Y-m-d H:i:s');
$added = 0;
$skipped = 0;

foreach ($admins as $admin) {
    foreach ($workspaces as $ws) {
        $existing = db_one(
            "SELECT 1 FROM task_workspace_members WHERE workspace_id=? AND user_id=?",
            [$ws['id'], $admin['id']]
        );
        if ($existing) {
            // Już jest — upewnij się że ma 'admin'
            db()->prepare(
                "UPDATE task_workspace_members SET role='admin' WHERE workspace_id=? AND user_id=?"
            )->execute([$ws['id'], $admin['id']]);
            $skipped++;
        } else {
            db()->prepare(
                "INSERT INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at)
                 VALUES (?, ?, 'admin', ?, ?)"
            )->execute([$ws['id'], $admin['id'], $admin['id'], $now]);
            $added++;
            $log[] = ['ok', "Dodano {$admin['name']} → {$ws['name']} (admin)"];
        }
    }
}

if ($added === 0 && $skipped > 0) {
    $log[] = ['ok', "Wszyscy admini już byli przypisani do wszystkich obszarów ($skipped wpisów)"];
} elseif ($added > 0) {
    $log[] = ['ok', "Dodano łącznie $added nowych przypisań adminów do obszarów"];
}

// 2. Trigger: nowe obszary też dostają adminów — obsługa w admin/tasks_workspaces.php

$is_cli = php_sapi_name() === 'cli';
if ($is_cli) { foreach ($log as [$s,$m]) echo ($s==='ok'?'✓':'✗')." $m\n"; exit; }
?>
<!DOCTYPE html>
<html lang="pl"><head><meta charset="UTF-8"><title>Migracja v3</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4"><div class="container" style="max-width:640px">
<h5 class="mb-3">Migracja v3 — Admini w obszarach roboczych</h5>
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
