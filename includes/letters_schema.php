<?php
/**
 * includes/letters_schema.php
 * Samonaprawa schematu tabeli contract_letters (idempotentna, agnostyczna silnikowo).
 *
 * JEDNO źródło prawdy o kolumnach modułu pism. Wcześniej schemat był rozproszony
 * w trzech miejscach, które się rozjeżdżały:
 *   - cli/migrations/migrate_letters.php  (16 kolumn bazowych, bez Postivo)
 *   - setup/setup.php + setup/setup_sql.php (+ postivo_id, postivo_status)
 *   - includes/crm.php::crm_migrate()      (+ 11 pól rozszerzonych, lazy)
 * Efekt: zestaw kolumn zależał od tego, którą ścieżką założono bazę, a kod
 * czytał/pisał kolumny, których w danej bazie nie było („no such column" → 500,
 * np. data_odbioru w view.php czy postivo_job_id w postivo_action.php).
 *
 * Ten plik ustala pełny, kanoniczny zestaw kolumn i dogania każdą bazę na starcie
 * (dołączany z góry includes/letters.php, więc każdy konsument jest bezpieczny).
 *
 * WAŻNE (jak w includes/zlecenie_schema.php): NIE używamy `PRAGMA table_info`
 * (tylko SQLite). Próbujemy `ALTER TABLE ADD COLUMN` per kolumna w try/catch —
 * istniejąca kolumna rzuca „duplicate", który ignorujemy. Typy VARCHAR/TEXT/
 * INTEGER/DATE/DATETIME są bezpieczne na SQLite i MySQL. Bez DEFAULT na TEXT
 * (restrykcja MySQL) — dlatego pola słownikowe (sposob_doreczenia, pilnosc)
 * dostają wartość domyślną w kodzie, nie w schemacie.
 *
 * Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    // 1) Tabela musi istnieć nawet jeśli migracja/setup nie były uruchomione.
    $driver = '';
    try { $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (\Throwable $e) {}
    $auto = ($driver === 'sqlite')
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT AUTO_INCREMENT PRIMARY KEY';
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS contract_letters (
                id {$auto},
                contract_type VARCHAR(32) NOT NULL,
                contract_id INTEGER NOT NULL,
                tytul VARCHAR(512) NOT NULL
            )"
        );
    } catch (\Throwable $e) {}

    // 2) Pełny zestaw kolumn (poza kluczem/NOT NULL z CREATE powyżej).
    //    Wszystkie nullable, bez DEFAULT — ALTER ADD COLUMN bezpieczny na obu
    //    silnikach także dla tabeli z danymi.
    $columns = [
        // ── Rdzeń pisma ──────────────────────────────────────────────
        'kierunek'           => "VARCHAR(32)",
        'typ_pisma'          => "VARCHAR(32)",
        'tresc'              => "TEXT",
        'plik'               => "VARCHAR(500)",
        'data_pisma'         => "DATE",
        'nadawca'            => "VARCHAR(255)",
        'odbiorca'           => "VARCHAR(255)",
        'odbiorca_email'     => "VARCHAR(255)",
        'uwagi'              => "TEXT",
        'email_sent'         => "INTEGER",
        'data_odbioru'       => "DATETIME",
        'created_by'         => "INTEGER",
        'created_at'         => "DATETIME",
        'updated_at'         => "DATETIME",
        // ── Dane rejestrowe (metryka pisma / dziennik podawczy) ──────
        'sygnatura'          => "VARCHAR(120)",
        'miejsce'            => "VARCHAR(160)",
        'sposob_doreczenia'  => "VARCHAR(40)",
        'pilnosc'            => "VARCHAR(20)",
        'termin_odpowiedzi'  => "DATE",
        'kopia_do'           => "TEXT",
        'podpisujacy_id'     => "INTEGER",
        'podstawa_prawna'    => "TEXT",
        'nr_nadania'         => "VARCHAR(120)",
        'adres_edoreczenia'  => "VARCHAR(255)",
        'edoreczenia_ref'    => "VARCHAR(120)",
        // ── Wysyłka pocztą (Postivo.pl) ──────────────────────────────
        'postivo_job_id'      => "VARCHAR(120)",
        'postivo_status'      => "VARCHAR(40)",
        'postivo_sent_at'     => "DATETIME",
        'postivo_adres'       => "TEXT",
        'postivo_kod_pocztowy'=> "VARCHAR(20)",
        'postivo_miasto'      => "VARCHAR(120)",
    ];

    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE contract_letters ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
        }
    }
})();
