<?php
/**
 * Migracja tasks v7 — flaga "możliwe do przejęcia" (claimable)
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';

function t7(string $sql, string $label): void {
    try {
        db()->exec($sql);
        echo "<p class='text-success'><i class='bi bi-check-circle'></i> $label</p>\n";
    } catch (\Throwable $e) {
        echo "<p class='text-warning'><i class='bi bi-exclamation-triangle'></i> $label — " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
}

$title = 'Migracja: tasks v7 — flaga claimable';
?><!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8">
<title><?= $title ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head><body class="p-4">
<h4><?= $title ?></h4>
<?php

t7("ALTER TABLE tasks ADD COLUMN claimable INTEGER NOT NULL DEFAULT 0", 'Kolumna tasks.claimable (0=nie, 1=tak)');

?>
<div class="alert alert-success mt-3">Gotowe! <a href="<?= APP_URL ?>">Wróć do aplikacji</a></div>
</body></html>
