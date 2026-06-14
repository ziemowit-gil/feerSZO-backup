<?php
/**
 * includes/zlecenie_schema.php
 * Samonaprawa schematu tabeli umowy_zlecenie (idempotentna).
 *
 * Gwarantuje istnienie kolumn dokładanych przez migracje (m.in.
 * migrate_rachunek_pola_zlecenie.php), nawet jeśli moduł odblokowano bez
 * uruchomienia migracji. Bez tego zapis umowy z polami rachunku kończy się
 * błędem SQL „no such column” → 500.
 *
 * Wzorzec jak w includes/rozliczenia.php. Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'data_rachunku'  => 'DATE',
        'okres_rachunku' => 'VARCHAR(120)',
    ];

    try {
        $existing = [];
        foreach (db_all("PRAGMA table_info(umowy_zlecenie)") as $c) {
            $existing[] = $c['name'];
        }
        foreach ($columns as $name => $def) {
            if (!in_array($name, $existing, true)) {
                try { db()->exec("ALTER TABLE umowy_zlecenie ADD COLUMN {$name} {$def}"); }
                catch (\Throwable $e) { /* kolumna mogła powstać równolegle */ }
            }
        }
    } catch (\Throwable $e) {
        error_log('[zlecenie_schema] ' . $e->getMessage());
    }
})();
