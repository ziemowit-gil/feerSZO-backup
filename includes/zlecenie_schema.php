<?php
/**
 * includes/zlecenie_schema.php
 * Samonaprawa schematu tabeli umowy_zlecenie (idempotentna).
 *
 * Gwarantuje istnienie WSZYSTKICH kolumn, które zapisuje kreator/edycja
 * (add.php, edit.php) — nawet jeśli moduł odblokowano bez uruchomienia
 * migracji. Bez tego zapis umowy (db_insert/db_update) kończy się błędem
 * SQL „no such column" → 500.
 *
 * Uruchamia się przy starcie add.php/edit.php (przed POST) — czyli zawsze
 * PRZED insertem/update'em. Wzorzec jak w includes/rozliczenia.php.
 * Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    // Kolumny zapisywane przez formularze umowy zlecenie.
    $columns = [
        // Rachunek / rozliczenie
        'data_rachunku'          => "DATE",
        'okres_rachunku'         => "VARCHAR(120)",
        'sposob_rozliczenia'     => "VARCHAR(60)",
        // Podpisanie
        'podpisujacy_fundacja'   => "VARCHAR(255)",
        'podpisujacy_stanowisko' => "VARCHAR(255)",
        'epodpis_dostawca'       => "VARCHAR(100)",
        'epodpis_nr_certyfikatu' => "VARCHAR(255)",
        'epodpis_data_waznosci'  => "DATE",
        // Adres strukturalny (jak w wolontariacie)
        'addr_street'            => "TEXT NOT NULL DEFAULT ''",
        'addr_house'             => "TEXT NOT NULL DEFAULT ''",
        'addr_flat'              => "TEXT NOT NULL DEFAULT ''",
        'addr_postal'            => "TEXT NOT NULL DEFAULT ''",
        'addr_city'              => "TEXT NOT NULL DEFAULT ''",
        'addr_country'           => "TEXT NOT NULL DEFAULT 'PL'",
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
