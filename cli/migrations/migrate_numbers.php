<?php
/** Migracja: dodaje pola numerów referencyjnych do wszystkich tabel umów. Usuń po wykonaniu! */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$tables = ['umowy_zlecenie','umowy_uslugi','umowy_wolontariat','umowy_dzielo','umowy_praca','umowy_inne'];
$cols = [
    "nr_roboczy  VARCHAR(100)",
    "nr_system   VARCHAR(100)",
    "nr_rejestru VARCHAR(100)",
];

$done = []; $skip = [];
foreach ($tables as $tbl) {
    foreach ($cols as $def) {
        $name = trim(explode(' ', trim($def))[0]);
        try {
            db()->exec("ALTER TABLE {$tbl} ADD COLUMN {$def}");
            $done[] = "{$tbl}.{$name}";
        } catch (PDOException $e) {
            $skip[] = "{$tbl}.{$name}";
        }
    }
}
?>
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migracja</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light"><div class="container mt-5" style="max-width:600px">
<div class="card shadow-sm"><div class="card-header fw-bold">Migracja — pola numerów</div>
<div class="card-body">
<?php if ($done): ?>
<div class="alert alert-success">Dodano: <?= implode(', ', array_map('htmlspecialchars', $done)) ?></div>
<?php endif; ?>
<?php if ($skip): ?>
<div class="alert alert-secondary small">Już istniały: <?= implode(', ', array_map('htmlspecialchars', $skip)) ?></div>
<?php endif; ?>
<p>Gotowe. <a href="index.php">Przejdź do rejestru →</a></p>
<p class="text-danger small">Usuń plik <code>migrate_numbers.php</code>!</p>
</div></div></div></body></html>
