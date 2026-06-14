<?php
/**
 * Migracja: pola śledzenia zgłoszeń ZUS dla umów zlecenie.
 *
 * Dodaje do tabeli umowy_zlecenie:
 *   - zus_data_rejestracji      DATE — data zgłoszenia do ZUS (ZUA/ZZA)
 *   - zus_data_wyrejestrowania  DATE — data wyrejestrowania z ZUS (ZWUA)
 *
 * Wpisanie daty wycisza odpowiednie przypomnienia (cron/zus_reminder.php).
 * Uruchom: php migrate_zus_reminder_zlecenie.php  (lub z panelu admin/migrations.php)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$existing = [];
foreach (db_all("PRAGMA table_info(umowy_zlecenie)") as $col) {
    $existing[] = $col['name'];
}

$additions = [
    'zus_data_rejestracji'     => "DATE",
    'zus_data_wyrejestrowania' => "DATE",
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

echo $added ? "\nGotowe — dodano " . count($added) . " kolumn(y).\n"
            : "\nNic do zrobienia — wszystkie kolumny już istniały.\n";
