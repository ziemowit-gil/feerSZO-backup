<?php
/**
 * Migration: add postal address columns to umowy_wolontariat (Postivo.pl integration).
 * Run: php migrate_adres_wolontariat.php
 * Idempotent — checks column existence before ALTER.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// Fetch existing columns
$cols = [];
foreach ($pdo->query("PRAGMA table_info(umowy_wolontariat)") as $row) {
    $cols[] = $row['name'];
}

$added = [];

$new_cols = [
    'adres_odbiorca'      => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_odbiorca TEXT",
    'adres_linia1'        => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_linia1 TEXT",
    'adres_linia2'        => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_linia2 TEXT",
    'adres_kod_pocztowy'  => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_kod_pocztowy TEXT",
    'adres_miasto'        => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_miasto TEXT",
    'adres_kraj'          => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_kraj TEXT DEFAULT 'PL'",
];

foreach ($new_cols as $col => $sql) {
    if (!in_array($col, $cols, true)) {
        $pdo->exec($sql);
        $added[] = $col;
        echo "  Added column: {$col}\n";
    } else {
        echo "  Column already exists: {$col}\n";
    }
}

if ($added) {
    echo "\nMigration complete. Added " . count($added) . " column(s).\n";
} else {
    echo "\nNothing to do — all columns already present.\n";
}
