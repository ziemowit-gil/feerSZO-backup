<?php
/**
 * includes/zlecenie_schema.php
 * Samonaprawa schematu tabeli umowy_zlecenie (idempotentna, agnostyczna silnikowo).
 *
 * Gwarantuje istnienie WSZYSTKICH kolumn zapisywanych przez kreator/edycję
 * (add.php, edit.php — lista $allowed). Bez tego zapis (db_insert/db_update)
 * pada z „no such column" → 500, gdy w bazie brakuje którejś kolumny
 * (moduł odblokowany bez uruchomienia migracji).
 *
 * WAŻNE: NIE używamy `PRAGMA table_info` (działa tylko w SQLite — na MySQL
 * rzuca wyjątek i samonaprawa nic by nie zrobiła). Zamiast tego próbujemy
 * `ALTER TABLE ADD COLUMN` per kolumna w try/catch — istniejąca kolumna daje
 * błąd „duplicate", który ignorujemy. Wzorzec jak w includes/address.php.
 *
 * Typy bezpieczne dla SQLite i MySQL (bez DEFAULT na TEXT — restrykcja MySQL).
 * Uruchamia się na górze add.php/edit.php — zawsze PRZED insertem/update'em.
 * Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    // Pełny zestaw kolumn zapisywanych przez formularze umowy zlecenie.
    // Wszystkie nullable, bez DEFAULT — ALTER ADD COLUMN bezpieczny na obu
    // silnikach także dla tabeli z danymi.
    $columns = [
        'numer_umowy'              => "VARCHAR(100)",
        'status'                   => "VARCHAR(50)",
        'imie_nazwisko'            => "VARCHAR(255)",
        'pesel'                    => "VARCHAR(11)",
        'adres'                    => "TEXT",
        'email'                    => "VARCHAR(255)",
        'seria_nr_dowodu'          => "VARCHAR(50)",
        'urzad_skarbowy'           => "VARCHAR(255)",
        'addr_street'              => "VARCHAR(255)",
        'addr_house'               => "VARCHAR(50)",
        'addr_flat'                => "VARCHAR(50)",
        'addr_postal'              => "VARCHAR(20)",
        'addr_city'                => "VARCHAR(120)",
        'addr_country'             => "VARCHAR(10)",
        'rachunek_bankowy'         => "VARCHAR(50)",
        'przedmiot_zlecenia'       => "TEXT",
        'data_zawarcia'            => "DATE",
        'data_rozpoczecia'         => "DATE",
        'data_zakonczenia'         => "DATE",
        'wynagrodzenie_brutto'     => "DECIMAL(12,2)",
        'stawka_kwota'             => "DECIMAL(10,2)",
        'typ_stawki'               => "VARCHAR(20)",
        'liczba_godzin_planowana'  => "DECIMAL(8,2)",
        'sposob_rozliczenia'       => "VARCHAR(60)",
        'termin_platnosci'         => "VARCHAR(100)",
        'zus_skladki'              => "INTEGER",
        'tytul_ubezpieczenia'      => "VARCHAR(255)",
        'zus_data_rejestracji'     => "DATE",
        'zus_data_wyrejestrowania' => "DATE",
        'zwolnienie_wiek'          => "INTEGER",
        'zaliczka_podatek'         => "DECIMAL(10,2)",
        'kup'                      => "VARCHAR(10)",
        'numer_projektu'           => "VARCHAR(255)",
        'opiekun'                  => "VARCHAR(255)",
        'wymagany_rachunek'        => "INTEGER",
        'data_zl_rachunku'         => "DATE",
        'data_rachunku'            => "DATE",
        'okres_rachunku'           => "VARCHAR(120)",
        'forma_podpisania'         => "VARCHAR(20)",
        'platforma_el'             => "VARCHAR(100)",
        'id_dokumentu_el'          => "VARCHAR(255)",
        'epodpis_dostawca'         => "VARCHAR(100)",
        'epodpis_nr_certyfikatu'   => "VARCHAR(255)",
        'epodpis_data_waznosci'    => "DATE",
        'plik_potwierdzenia'       => "VARCHAR(500)",
        'plik_umowy'               => "VARCHAR(500)",
        'uwagi'                    => "TEXT",
        'notatka_do_realizacji'    => "TEXT",
        'created_by'               => "INTEGER",
        'created_at'               => "DATETIME",
        'updated_at'               => "DATETIME",
        'm365_konto'               => "INTEGER",
        'm365_login'               => "VARCHAR(255)",
        'm365_user_id'             => "VARCHAR(255)",
        'm365_konto_aktywne'       => "INTEGER",
        'm365_data_utworzenia'     => "DATETIME",
        'm365_licencja_przypisana' => "INTEGER",
        'nr_roboczy'               => "VARCHAR(100)",
        'nr_system'                => "VARCHAR(100)",
        'nr_rejestru'              => "VARCHAR(100)",
        'person_id'                => "INTEGER",
        'org_unit_id'              => "INTEGER",
        'podpisujacy_fundacja'     => "VARCHAR(255)",
        'podpisujacy_stanowisko'   => "VARCHAR(255)",
        // Instytucja Szkoleniowa
        'w_ramach_is'              => "INTEGER",
        'is_nazwa'                 => "VARCHAR(255)",
        'is_adres'                 => "TEXT",
        'is_numer_umowy'           => "VARCHAR(255)",
        'klauzula_rodo'            => "INTEGER",
        'is_uprawnienia_nr'        => "VARCHAR(255)",
        'is_dyplom_nr'             => "VARCHAR(255)",
        'is_dopuszczenie'          => "TEXT",
    ];

    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE umowy_zlecenie ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
        }
    }
})();
