<?php
/**
 * Migracja tasks v6 — powiązanie zadania z umową (contract_type, contract_id)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

function t6(string $sql, string $label): void {
    try {
        db()->exec($sql);
        echo "<p class='text-success'><i class='bi bi-check-circle'></i> $label</p>\n";
    } catch (\Throwable $e) {
        echo "<p class='text-warning'><i class='bi bi-exclamation-triangle'></i> $label — " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
}

$title = 'Migracja: tasks v6 — powiązanie z umową';
?><!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8">
<title><?= $title ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head><body class="p-4">
<h4><?= $title ?></h4>
<?php

t6("ALTER TABLE tasks ADD COLUMN contract_type TEXT DEFAULT NULL", 'Kolumna tasks.contract_type');
t6("ALTER TABLE tasks ADD COLUMN contract_id INTEGER DEFAULT NULL", 'Kolumna tasks.contract_id');
t6("CREATE INDEX IF NOT EXISTS idx_tasks_contract ON tasks(contract_type, contract_id)", 'Indeks tasks.contract_type/id');

?>
<div class="alert alert-success mt-3">Gotowe! <a href="<?= APP_URL ?>">Wróć do aplikacji</a></div>
</body></html>
