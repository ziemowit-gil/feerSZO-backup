#!/usr/bin/env php
<?php
/**
 * cli/migrate_addresses.php
 *
 * One-time migration: parses existing `adres` (single-field) values
 * and fills in addr_street / addr_house / addr_flat / addr_postal / addr_city
 * for rows that still have empty structured fields.
 *
 * Usage:
 *   php cli/migrate_addresses.php [--dry-run] [--table=wolontariat]
 *
 * Options:
 *   --dry-run   Show what would be updated, do not write to DB.
 *   --table=X   Only process this contract type (wolontariat, zlecenie, dzielo, praca).
 */

define('CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/address.php';

$dry_run    = in_array('--dry-run', $argv);
$only_table = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--table=')) {
        $only_table = preg_replace('/[^a-z]/', '', substr($arg, 8));
    }
}

$tables = ['wolontariat', 'zlecenie', 'dzielo', 'praca'];
if ($only_table) {
    if (!in_array($only_table, $tables)) {
        echo "Nieznany typ: $only_table\n"; exit(1);
    }
    $tables = [$only_table];
}

// Ensure columns exist
address_migrate();

$pdo = db();
$total_updated = 0;
$total_skipped = 0;

foreach ($tables as $type) {
    $tbl = "umowy_{$type}";
    echo "\n=== $tbl ===\n";

    try {
        $rows = $pdo->query(
            "SELECT id, adres, addr_street, addr_postal, addr_city FROM {$tbl}
             WHERE (addr_street = '' OR addr_street IS NULL)
               AND (addr_postal = '' OR addr_postal IS NULL)
               AND (addr_city   = '' OR addr_city   IS NULL)
               AND adres IS NOT NULL AND adres != ''"
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        echo "  Błąd odczytu: " . $e->getMessage() . "\n";
        continue;
    }

    echo "  Znaleziono " . count($rows) . " rekordów do migracji.\n";

    foreach ($rows as $row) {
        // Use address_from_row() parser
        $a = address_from_row($row);

        if (!$a['street'] && !$a['postal'] && !$a['city']) {
            // Parser couldn't extract anything useful
            echo "  [SKIP] id={$row['id']} → nie udało się sparsować: " . substr($row['adres'], 0, 60) . "\n";
            $total_skipped++;
            continue;
        }

        $formatted = address_format($a);
        echo "  [" . ($dry_run ? 'DRY' : 'UPD') . "] id={$row['id']} → $formatted\n";

        if (!$dry_run) {
            $stmt = $pdo->prepare(
                "UPDATE {$tbl} SET
                    addr_street  = ?,
                    addr_house   = ?,
                    addr_flat    = ?,
                    addr_postal  = ?,
                    addr_city    = ?,
                    addr_country = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $a['street'],
                $a['house'],
                $a['flat'],
                $a['postal'],
                $a['city'],
                $a['country'] ?: 'PL',
                $row['id'],
            ]);
        }

        $total_updated++;
    }
}

echo "\n";
echo "===========================================\n";
if ($dry_run) {
    echo "DRY RUN — nie zapisano żadnych zmian.\n";
    echo "Do uruchomienia z zapisem: php cli/migrate_addresses.php\n";
} else {
    echo "Zaktualizowano: $total_updated rekordów\n";
}
echo "Pominięto (nierozpoznany format): $total_skipped rekordów\n";
echo "===========================================\n";
