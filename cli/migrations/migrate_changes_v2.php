<?php
/** Migracja v2: dodaje proposed_changes i applied_at do contract_amendments. Usuń po wykonaniu! */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$sqls = [
    "ALTER TABLE contract_amendments ADD COLUMN proposed_changes TEXT",
    "ALTER TABLE contract_amendments ADD COLUMN applied_at DATETIME",
];

$done = []; $err = [];
foreach ($sqls as $sql) {
    try { db()->exec($sql); $done[] = $sql; }
    catch (PDOException $e) {
        // "duplicate column" = already exists, ignore
        if (!str_contains($e->getMessage(), 'duplicate column') &&
            !str_contains($e->getMessage(), 'already exists')) {
            $err[] = $e->getMessage();
        } else {
            $done[] = '(pominięto — kolumna już istnieje)';
        }
    }
}
?>
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migracja v2</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light"><div class="container mt-5" style="max-width:640px">
<div class="card shadow-sm"><div class="card-header fw-bold">Migracja v2 — Aneksy: proposed_changes</div>
<div class="card-body">
<?php if ($done): ?>
<div class="alert alert-success small"><b>Wykonano:</b><ul class="mb-0">
<?php foreach($done as $d) echo "<li><code>".htmlspecialchars($d)."</code></li>"; ?>
</ul></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alert alert-danger small"><?= implode('<br>', array_map('htmlspecialchars', $err)) ?></div>
<?php endif; ?>
<p>Gotowe. <a href="index.php">Przejdź do rejestru →</a></p>
<p class="text-danger small">Usuń <code>migrate_changes_v2.php</code>!</p>
</div></div></div></body></html>
