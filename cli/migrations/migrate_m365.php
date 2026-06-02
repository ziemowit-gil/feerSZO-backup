<?php
/** Migracja: dodaje kolumny M365 do tabel umów. Usuń po wykonaniu! */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$tables = ['umowy_zlecenie', 'umowy_dzielo', 'umowy_wolontariat'];

$cols = [
    "m365_konto          INTEGER DEFAULT 0",
    "m365_login          VARCHAR(255)",
    "m365_user_id        VARCHAR(255)",
    "m365_konto_aktywne  INTEGER DEFAULT 0",
    "m365_data_utworzenia DATETIME",
    "m365_licencja_przypisana INTEGER DEFAULT 0",
];

$done = []; $skip = [];

foreach ($tables as $tbl) {
    foreach ($cols as $col_def) {
        $col_name = trim(explode(' ', trim($col_def))[0]);
        try {
            db()->exec("ALTER TABLE {$tbl} ADD COLUMN {$col_def}");
            $done[] = "{$tbl}.{$col_name}";
        } catch (PDOException $e) {
            // Kolumna już istnieje — OK
            $skip[] = "{$tbl}.{$col_name}";
        }
    }
}

// Ustawienia M365 w tabeli settings
$defaults = [
    'm365_enabled'         => '0',
    'm365_graph_client_id' => '',
    'm365_graph_client_secret' => '',
    'm365_domain'          => 'feer.org.pl',
    'm365_license_sku_id'  => '',
    'm365_sender_user_id'  => '',
];
foreach ($defaults as $k => $v) {
    try {
        if (DB_TYPE === 'sqlite') {
            db()->prepare("INSERT OR IGNORE INTO settings (key_, value) VALUES (?, ?)")->execute([$k, $v]);
        } else {
            db()->prepare("INSERT IGNORE INTO settings (key_, value) VALUES (?, ?)")->execute([$k, $v]);
        }
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migracja M365</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light"><div class="container mt-5" style="max-width:600px">
<div class="card shadow-sm"><div class="card-header fw-bold">Migracja bazy — moduł M365</div>
<div class="card-body">
<?php if ($done): ?>
<div class="alert alert-success">Dodano kolumny:<br><?= implode(', ', array_map('htmlspecialchars', $done)) ?></div>
<?php endif; ?>
<?php if ($skip): ?>
<div class="alert alert-secondary small">Już istniały: <?= implode(', ', array_map('htmlspecialchars', $skip)) ?></div>
<?php endif; ?>
<p>Migracja zakończona. <a href="admin/m365_settings.php">Przejdź do ustawień M365 →</a></p>
<p class="text-danger small">Usuń plik <code>migrate_m365.php</code> po wykonaniu!</p>
</div></div></div></body></html>
