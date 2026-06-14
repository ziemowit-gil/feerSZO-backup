<?php
/**
 * Migracja: pola rachunku dla umów zlecenie + odblokowanie modułu.
 *
 * Dodaje do tabeli umowy_zlecenie:
 *   - data_rachunku   DATE          — data wystawienia rachunku
 *   - okres_rachunku  VARCHAR(120)  — za jaki okres jest rachunek
 *
 * Oba pola trafiają do bloku e-mail do księgowego (contracts/zlecenie + PDF).
 *
 * Dodatkowo ustawia contract_preview_zlecenie = 'enabled' — zdejmuje tryb
 * „moduł w przygotowaniu" z umów zlecenie (banery, blokada add/edit).
 *
 * Uruchom: php migrate_rachunek_pola_zlecenie.php  (lub z panelu admin/migrations.php)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$existing = [];
foreach (db_all("PRAGMA table_info(umowy_zlecenie)") as $col) {
    $existing[] = $col['name'];
}

$additions = [
    'data_rachunku'  => "DATE",
    'okres_rachunku' => "VARCHAR(120)",
];

$added = [];
foreach ($additions as $col => $def) {
    if (!in_array($col, $existing, true)) {
        db()->exec("ALTER TABLE umowy_zlecenie ADD COLUMN {$col} {$def}");
        $added[] = $col;
        echo "  ✓ Dodano kolumnę: {$col}\n";
    } else {
        echo "  · Kolumna już istnieje: {$col}\n";
    }
}

// ── Odblokowanie modułu umów zlecenie ──────────────────────────────────────────
db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
    ->execute(['contract_preview_zlecenie', 'enabled']);
echo "  ✓ Ustawiono contract_preview_zlecenie = enabled (moduł odblokowany)\n";

echo $added ? "\nGotowe — dodano " . count($added) . " kolumn(y).\n"
            : "\nKolumny już istniały — odblokowano moduł.\n";
