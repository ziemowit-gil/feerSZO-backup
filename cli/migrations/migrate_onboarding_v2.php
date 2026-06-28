<?php
/**
 * Migracja: onboarding v2 — dane do umowy, oświadczenie, konto portalu.
 * Bezpieczna do wielokrotnego uruchamiania.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$db = db();

$cols = [
    'miejsce_wolontariatu'   => "TEXT NOT NULL DEFAULT ''",
    'przedmiot_porozumienia' => "TEXT NOT NULL DEFAULT ''",
    'data_rozpoczecia'       => "TEXT NOT NULL DEFAULT ''",
    'data_zakonczenia'       => "TEXT NOT NULL DEFAULT ''",
    'oswiadczenie_file'      => "TEXT NOT NULL DEFAULT ''",
    'user_id'                => 'INTEGER',
];

foreach ($cols as $col => $def) {
    try {
        $db->exec("ALTER TABLE onboarding_volunteers ADD COLUMN {$col} {$def}");
        echo "  +kolumna {$col} — dodano\n";
    } catch (\Exception $e) {
        echo "  +kolumna {$col} — już istnieje\n";
    }
}

echo "\nMigracja onboarding_v2 zakończona.\n";
