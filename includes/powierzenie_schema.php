<?php
/**
 * includes/powierzenie_schema.php
 * Samonaprawa schematu tabeli umowy_powierzenie (idempotentna, agnostyczna silnikowo).
 *
 * Gwarantuje istnienie WSZYSTKICH kolumn zapisywanych przez kreator/edycję
 * (add.php, edit.php — lista $allowed). Bez tego zapis (db_insert/db_update)
 * pada z „no such column" → 500, gdy w bazie brakuje którejś kolumny
 * (moduł odblokowany bez uruchomienia migracji).
 *
 * Wzorzec identyczny jak includes/zlecenie_schema.php — ALTER ADD COLUMN per
 * kolumna w try/catch (duplikat → ignorujemy). Bez PRAGMA (MySQL/SQLite-safe),
 * bez DEFAULT na TEXT. Uruchamia się na górze add.php/edit.php — PRZED zapisem.
 * Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    // Tabela może jeszcze nie istnieć (moduł odblokowany bez migracji) — utwórz.
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS umowy_powierzenie (
            id INTEGER PRIMARY KEY AUTOINCREMENT, numer_umowy VARCHAR(100) NOT NULL,
            status VARCHAR(50) DEFAULT 'projekt',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) { /* istnieje — ok */ }

    $columns = [
        'numer_umowy'            => "VARCHAR(100)",
        'status'                 => "VARCHAR(50)",
        'nazwa_zadania'          => "TEXT",
        'sfera_zadania'          => "VARCHAR(255)",
        'forma_zlecenia'         => "VARCHAR(20)",
        'tryb_zlecenia'          => "VARCHAR(50)",
        'nazwa_konkursu'         => "VARCHAR(255)",
        'zakres_rzeczowy'        => "TEXT",
        'rezultaty'              => "TEXT",
        'organ_zlecajacy'        => "VARCHAR(255)",
        'organ_reprezentacja'    => "VARCHAR(255)",
        'organ_adres'            => "TEXT",
        'email'                  => "VARCHAR(255)",
        'kwota_dotacji'          => "DECIMAL(12,2)",
        'wklad_wlasny'           => "DECIMAL(12,2)",
        'wklad_osobowy'          => "DECIMAL(12,2)",
        'calkowity_koszt'        => "DECIMAL(12,2)",
        'waluta'                 => "VARCHAR(10)",
        'rachunek_dotacji'       => "VARCHAR(50)",
        'transze'                => "TEXT",
        'koszty_kwalifikowane'   => "TEXT",
        'data_zawarcia'          => "DATE",
        'data_rozpoczecia'       => "DATE",
        'data_zakonczenia'       => "DATE",
        'termin_wykorzystania'   => "DATE",
        'termin_sprawozdania'    => "DATE",
        'numer_projektu'         => "VARCHAR(255)",
        'opiekun'                => "VARCHAR(255)",
        'forma_podpisania'       => "VARCHAR(20)",
        'platforma_el'           => "VARCHAR(100)",
        'id_dokumentu_el'        => "VARCHAR(255)",
        'epodpis_dostawca'       => "VARCHAR(100)",
        'epodpis_nr_certyfikatu' => "VARCHAR(255)",
        'epodpis_data_waznosci'  => "DATE",
        'plik_potwierdzenia'     => "VARCHAR(500)",
        'plik_umowy'             => "VARCHAR(500)",
        'zalaczniki'             => "VARCHAR(1000)",
        'uwagi'                  => "TEXT",
        'nr_roboczy'             => "VARCHAR(100)",
        'nr_system'              => "VARCHAR(100)",
        'nr_rejestru'            => "VARCHAR(100)",
        'podpisujacy_fundacja'   => "VARCHAR(255)",
        'podpisujacy_stanowisko' => "VARCHAR(255)",
        'access_level'           => "TEXT",
        'created_by'             => "INTEGER",
        'created_at'             => "DATETIME",
        'updated_at'             => "DATETIME",
    ];

    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE umowy_powierzenie ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
        }
    }
})();
