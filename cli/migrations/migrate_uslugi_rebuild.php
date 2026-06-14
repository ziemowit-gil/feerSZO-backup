<?php
/**
 * Migracja: przebudowa rejestru umów o świadczenie usług (umowy_uslugi).
 *
 * Dodaje pola modelu danych potrzebne nowej liście:
 *   - telefon            — kontakt do wykonawcy (e-mail już istnieje)
 *   - status_rozliczenia — nierozliczone / częściowo / rozliczone
 *   - kwota_rozliczona   — ile z wartości brutto już rozliczono
 *   - data_rozliczenia   — data ostatniego/pełnego rozliczenia
 * oraz indeksy przyspieszające listę (status, data_zawarcia, opiekun, created_at).
 *
 * Idempotentna — ADD COLUMN pomijany jeśli kolumna już istnieje,
 * CREATE INDEX IF NOT EXISTS. Wykrywana automatycznie przez admin/migrations.php
 * (parsuje "ALTER TABLE umowy_uslugi ADD COLUMN <kol>").
 *
 * Uruchom: php migrate_uslugi_rebuild.php  (lub z panelu admin/migrations.php)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

/** Zwraca true jeśli kolumna istnieje w tabeli (SQLite/MySQL). */
function _uslugi_col_exists(string $table, string $col): bool {
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $st = db()->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $st->execute([$col]);
            return $st->rowCount() > 0;
        }
        foreach (db()->query("PRAGMA table_info(" . db()->quote($table) . ")")->fetchAll() as $c) {
            if (strtolower($c['name']) === strtolower($col)) return true;
        }
    } catch (\Throwable $e) {}
    return false;
}

$cols = [
    // ALTER TABLE umowy_uslugi ADD COLUMN telefon
    'telefon'            => "VARCHAR(40)",
    // ALTER TABLE umowy_uslugi ADD COLUMN status_rozliczenia
    'status_rozliczenia' => "VARCHAR(30) DEFAULT 'nierozliczone'",
    // ALTER TABLE umowy_uslugi ADD COLUMN kwota_rozliczona
    'kwota_rozliczona'   => "DECIMAL(12,2)",
    // ALTER TABLE umowy_uslugi ADD COLUMN data_rozliczenia
    'data_rozliczenia'   => "DATE",
];

$ok = true;
foreach ($cols as $col => $type) {
    if (_uslugi_col_exists('umowy_uslugi', $col)) {
        echo "  · kolumna umowy_uslugi.$col — już istnieje, pomijam\n";
        continue;
    }
    try {
        db()->exec("ALTER TABLE umowy_uslugi ADD COLUMN $col $type");
        echo "  ✓ dodano umowy_uslugi.$col\n";
    } catch (\Throwable $e) {
        echo "  ✗ błąd dodawania $col: " . $e->getMessage() . "\n";
        $ok = false;
    }
}

$indexes = [
    'idx_uslugi_status'   => 'status',
    'idx_uslugi_zawarcia' => 'data_zawarcia',
    'idx_uslugi_opiekun'  => 'opiekun',
    'idx_uslugi_created'  => 'created_at',
];
foreach ($indexes as $name => $expr) {
    try {
        db()->exec("CREATE INDEX IF NOT EXISTS $name ON umowy_uslugi($expr)");
        echo "  ✓ indeks $name — OK\n";
    } catch (\Throwable $e) {
        echo "  ✗ błąd indeksu $name: " . $e->getMessage() . "\n";
        $ok = false;
    }
}

echo $ok
    ? "\nGotowe — rejestr umów o świadczenie usług gotowy na przebudowaną listę.\n"
    : "\nZakończono z błędami — sprawdź komunikaty powyżej.\n";
if (!$ok) exit(1);
