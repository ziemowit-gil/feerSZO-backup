<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = db();

if (DB_TYPE === 'sqlite') {
    try {
        $pdo->exec("ALTER TABLE umowy_praca ADD COLUMN email_login VARCHAR(255)");
        echo "✓ umowy_praca.email_login dodana\n";
    } catch (PDOException $e) {
        echo "— umowy_praca.email_login już istnieje\n";
    }
} else {
    // MySQL
    $cols = $pdo->query("SHOW COLUMNS FROM umowy_praca LIKE 'email_login'")->fetchAll();
    if (!$cols) {
        $pdo->exec("ALTER TABLE umowy_praca ADD COLUMN email_login VARCHAR(255) AFTER rachunek_bankowy");
        echo " umowy_praca.email_login dodana\n";
    } else {
        echo " umowy_praca.email_login już istnieje\n";
    }
}

echo "Gotowe.\n";
