<?php
/**
 * Migration: add 2FA columns to the users table.
 * Run: php migrate_2fa.php
 * Idempotent — checks column existence before ALTER.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// Fetch existing columns
$cols = [];
foreach ($pdo->query("PRAGMA table_info(users)") as $row) {
    $cols[] = $row['name'];
}

$added = [];

$new_cols = [
    'totp_secret'      => "ALTER TABLE users ADD COLUMN totp_secret TEXT",
    'totp_confirmed'   => "ALTER TABLE users ADD COLUMN totp_confirmed INTEGER DEFAULT 0",
    'twofa_method'     => "ALTER TABLE users ADD COLUMN twofa_method TEXT DEFAULT ''",
    'twofa_phone'      => "ALTER TABLE users ADD COLUMN twofa_phone TEXT",
    'totp_backup_codes'=> "ALTER TABLE users ADD COLUMN totp_backup_codes TEXT",
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
