<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// Lista wszystkich tabel umów w systemie
$tables = ['praca', 'zlecenie', 'dzielo', 'wolontariat'];

echo "=== Rozpoczęcie migracji: Kompleksowa integracja z webNGO ===\n";

foreach ($tables as $type) {
    $table_name = "umowy_{$type}";
    echo "\nPrzetwarzanie tabeli: {$table_name}...\n";

    if (DB_TYPE === 'sqlite') {
        // --- S Q L I T E ---
        
        // 1. Dodanie flagi z_webngo
        try {
            $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN z_webngo BOOLEAN DEFAULT 0");
            echo "  ✓ Kolumna z_webngo dodana\n";
        } catch (PDOException $e) {
            echo "  — Kolumna z_webngo już istnieje lub błąd: " . $e->getMessage() . "\n";
        }

        // 2. Dodanie zewnętrznego ID z webNGO
        try {
            $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN webngo_id VARCHAR(100) DEFAULT NULL");
            echo "  ✓ Kolumna webngo_id dodana\n";
        } catch (PDOException $e) {
            echo "  — Kolumna webngo_id już istnieje lub błąd: " . $e->getMessage() . "\n";
        }

        // 3. Dodanie numeru poprzedniej umowy z webNGO
        try {
            $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN webngo_numer_umowy VARCHAR(255) DEFAULT NULL");
            echo "  ✓ Kolumna webngo_numer_umowy dodana\n";
        } catch (PDOException $e) {
            echo "  — Kolumna webngo_numer_umowy już istnieje lub błąd: " . $e->getMessage() . "\n";
        }

    } else {
        // --- M Y S Q L ---
        
        // 1. Dodanie flagi z_webngo
        $cols_flag = $pdo->query("SHOW COLUMNS FROM {$table_name} LIKE 'z_webngo'")->fetchAll();
        if (!$cols_flag) {
            $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN z_webngo TINYINT(1) NOT NULL DEFAULT 0");
            echo "  ✓ Kolumna z_webngo dodana\n";
        } else {
            echo "  — Kolumna z_webngo już istnieje\n";
        }

        // 2. Dodanie zewnętrznego ID z webNGO
        $cols_id = $pdo->query("SHOW COLUMNS FROM {$table_name} LIKE 'webngo_id'")->fetchAll();
        if (!$cols_id) {
            $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN webngo_id VARCHAR(100) DEFAULT NULL AFTER z_webngo");
            echo "  ✓ Kolumna webngo_id dodana\n";
        } else {
            echo "  — Kolumna webngo_id już istnieje\n";
        }

        // 3. Dodanie numeru poprzedniej umowy z webNGO
        $cols_num = $pdo->query("SHOW COLUMNS FROM {$table_name} LIKE 'webngo_numer_umowy'")->fetchAll();
        if (!$cols_num) {
            $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN webngo_numer_umowy VARCHAR(255) DEFAULT NULL AFTER webngo_id");
            echo "  ✓ Kolumna webngo_numer_umowy dodana\n";
        } else {
            echo "  — Kolumna webngo_numer_umowy już istnieje\n";
        }
    }
}

echo "\n=== Migracja zakończona sukcesem. Gotowe. ===\n";