<?php
/**
 * Migration: add webngo_id column to umowy_wolontariat table.
 * Run: php migrate_wolontariat.php
 * Idempotent — checks column existence before ALTER.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// Pobieramy istniejące kolumny z tabeli umowy_wolontariat
$cols = [];
foreach ($pdo->query("PRAGMA table_info(umowy_wolontariat)") as $row) {
    $cols[] = $row['name'];
}

$col_to_add = 'webngo_id';
$sql = "ALTER TABLE umowy_wolontariat ADD COLUMN webngo_id INT NULL";

echo "Running migration for umowy_wolontariat...\n";

if (!in_array($col_to_add, $cols, true)) {
    $pdo->exec($sql);
    echo "  Success: Added column '{$col_to_add}' to umowy_wolontariat.\n";
} else {
    echo "  Notice: Column '{$col_to_add}' already exists in umowy_wolontariat.\n";
}