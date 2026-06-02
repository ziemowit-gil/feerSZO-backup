<?php
/**
 * Migracja: dodaje brakujące kolumny webNGO i rodzica/opiekuna prawnego
 * do tabeli umowy_wolontariat.
 * Uruchom: php migrate_webngo_guardian.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$existing = [];
foreach (db_all("PRAGMA table_info(umowy_wolontariat)") as $col) {
    $existing[] = $col['name'];
}

$additions = [
    // Brakujące pola webNGO (fix błędu dodawania)
    'z_webngo'                    => "INTEGER DEFAULT 0",
    'webngo_numer_umowy'          => "TEXT",
    // Dane rodzica/opiekuna prawnego osoby niepełnoletniej
    'rodzic_imie_nazwisko'        => "TEXT",
    'rodzic_email'                => "TEXT",
    'rodzic_telefon'              => "TEXT",
];

$added = [];
foreach ($additions as $col => $def) {
    if (!in_array($col, $existing)) {
        db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN {$col} {$def}");
        $added[] = $col;
        echo "  ✓ Dodano kolumnę: {$col}\n";
    } else {
        echo "  · Kolumna już istnieje: {$col}\n";
    }
}

echo $added ? "\nGotowe — dodano " . count($added) . " kolumn(y).\n"
            : "\nNic do zrobienia — wszystkie kolumny już istniały.\n";
