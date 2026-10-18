<?php
/**
 * includes/contract_block_schema.php
 * Samonaprawa kolumn blokady umowy (is_blocked, unblocked_by, unblocked_at).
 *
 * Widoki umów (contracts/<typ>/view.php, POST _set_status) przy wyjściu ze
 * statusu z grupy „zablokowana” zapisują te kolumny. Miały je tylko tabele
 * wolontariatu (wolontariat_schema.php) i powierzenia (powierzenie_schema.php),
 * więc w pozostałych typach zmiana statusu kończyła się „no such column”.
 *
 * ALTER ADD COLUMN per kolumna w try/catch (duplikat → ignorujemy), bez PRAGMA
 * (MySQL/SQLite-safe). Bramka szo_schema_current() (modules/selfrepairDB) —
 * DDL raz na wersję pliku i tabelę, nie przy każdym wejściu w widok.
 * Wymaga wcześniejszego includes/db.php.
 */

function contract_block_schema_ensure(string $table): void {
    if (!preg_match('/^umowy_[a-z_]+$/', $table)) return;
    if (szo_schema_current('contract_block:' . $table, __FILE__)) return;
    $columns = [
        'is_blocked'   => "INTEGER",
        'unblocked_by' => "INTEGER",
        'unblocked_at' => "DATETIME",
    ];
    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
        }
    }
}
