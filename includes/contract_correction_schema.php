<?php
/**
 * includes/contract_correction_schema.php
 * Samonaprawa kolumn audytu korekty — wspólna dla WSZYSTKICH typów umów
 * (jeden plik zamiast duplikowania ALTER w każdym {typ}_schema.php).
 * Wzorzec identyczny jak zlecenie_schema.php: ALTER TABLE ADD COLUMN w try/catch,
 * "duplicate column" ignorowane. Wymaga wcześniejszego includes/db.php
 * i includes/functions.php (CONTRACT_TYPES, table_for_type()).
 *
 * Uruchamia się na górze contracts/mark_correction.php i w includes/contract_view_header.php
 * — zawsze PRZED zapisem/odczytem pól needs_correction/correction_reason.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'needs_correction'         => "INTEGER",   // 0/1, NULL traktowane jak 0
        'correction_reason'        => "TEXT",
        'correction_requested_by'  => "INTEGER",
        'correction_requested_at'  => "DATETIME",
        'correction_resolved_at'   => "DATETIME",
    ];

    $tables = array_unique(array_map(
        static fn($type) => table_for_type($type),
        array_keys(CONTRACT_TYPES)
    ));

    foreach ($tables as $table) {
        foreach ($columns as $name => $def) {
            try {
                db()->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$def}");
            } catch (\Throwable $e) {
                // Kolumna już istnieje — normalne, ignorujemy.
            }
        }
    }
})();
