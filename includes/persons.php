<?php
/**
 * Persons module — auto-migration + helper functions
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    // Create persons table
    $pdo->exec("CREATE TABLE IF NOT EXISTS persons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        imie_nazwisko VARCHAR(255) NOT NULL,
        pesel VARCHAR(11),
        email VARCHAR(255),
        telefon VARCHAR(20),
        adres TEXT,
        adres_korespondencyjny TEXT,
        data_urodzenia DATE,
        seria_nr_dowodu VARCHAR(50),
        urzad_skarbowy VARCHAR(255),
        rachunek_bankowy VARCHAR(50),
        uwagi TEXT,
        questionnaire_token TEXT,
        questionnaire_sent_at DATETIME,
        questionnaire_filled_at DATETIME,
        questionnaire_email TEXT,
        zgoda_rodo INTEGER DEFAULT 0,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Migracje dla istniejących baz
    foreach ([
        "ALTER TABLE persons ADD COLUMN adres_korespondencyjny TEXT",
        "ALTER TABLE persons ADD COLUMN questionnaire_token TEXT",
        "ALTER TABLE persons ADD COLUMN questionnaire_sent_at DATETIME",
        "ALTER TABLE persons ADD COLUMN questionnaire_filled_at DATETIME",
        "ALTER TABLE persons ADD COLUMN questionnaire_email TEXT",
        "ALTER TABLE persons ADD COLUMN zgoda_rodo INTEGER DEFAULT 0",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_persons_questionnaire_token ON persons(questionnaire_token) WHERE questionnaire_token IS NOT NULL"); } catch (\Throwable $e) {}

    // Add person_id and org_unit_id to the 4 personal contract tables
    foreach (['umowy_zlecenie', 'umowy_dzielo', 'umowy_wolontariat', 'umowy_praca'] as $tbl) {
        try {
            $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN person_id INTEGER REFERENCES persons(id) ON DELETE SET NULL");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN org_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL");
        } catch (\Throwable $e) {}
    }
})();

function persons_all(): array {
    return db_all("SELECT * FROM persons ORDER BY imie_nazwisko");
}

function person_by_id(int $id): ?array {
    $row = db_one("SELECT * FROM persons WHERE id = ?", [$id]);
    return $row ?: null;
}

function persons_search(string $q): array {
    $like = '%' . $q . '%';
    return db_all(
        "SELECT * FROM persons
         WHERE imie_nazwisko LIKE ? OR pesel LIKE ? OR email LIKE ?
         ORDER BY imie_nazwisko
         LIMIT 20",
        [$like, $like, $like]
    );
}

/**
 * Generuje unikalny token kwestionariusza i zapisuje go dla danej osoby.
 */
function person_generate_questionnaire_token(int $person_id): string {
    $token = bin2hex(random_bytes(20)); // 40 znaków hex
    db()->prepare(
        "UPDATE persons SET questionnaire_token=?, questionnaire_sent_at=NULL, questionnaire_filled_at=NULL WHERE id=?"
    )->execute([$token, $person_id]);
    return $token;
}

/**
 * Zwraca osobę po tokenie kwestionariusza (lub null jeśli nie istnieje).
 */
function person_by_questionnaire_token(string $token): ?array {
    $row = db_one("SELECT * FROM persons WHERE questionnaire_token=?", [$token]);
    return $row ?: null;
}

/**
 * URL do kwestionariusza wolontariusza.
 */
function questionnaire_url(string $token): string {
    return APP_URL . '/persons/questionnaire.php?token=' . urlencode($token);
}

/**
 * Status kwestionariusza: 'filled' | 'sent' | 'generated' | 'none'
 */
function questionnaire_status(array $person): string {
    if (!empty($person['questionnaire_filled_at'])) return 'filled';
    if (!empty($person['questionnaire_sent_at']))   return 'sent';
    if (!empty($person['questionnaire_token']))      return 'generated';
    return 'none';
}
