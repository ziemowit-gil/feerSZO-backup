<?php
/**
 * Migracja: onboarding v3 — typ osoby (wolontariusz/zleceniobiorca),
 * pola specyficzne dla zleceniobiorcy.
 * Bezpieczna do wielokrotnego uruchamiania.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$db = db();

$cols = [
    'typ'             => "TEXT NOT NULL DEFAULT 'wolontariusz'",
    'seria_nr_dowodu' => "TEXT NOT NULL DEFAULT ''",
    'urzad_skarbowy'  => "TEXT NOT NULL DEFAULT ''",
    'addr_street'     => "TEXT NOT NULL DEFAULT ''",
    'addr_house'      => "TEXT NOT NULL DEFAULT ''",
    'addr_flat'       => "TEXT NOT NULL DEFAULT ''",
    'addr_postal'     => "TEXT NOT NULL DEFAULT ''",
    'addr_city'       => "TEXT NOT NULL DEFAULT ''",
    'rachunek_bankowy'=> "TEXT NOT NULL DEFAULT ''",
];

foreach ($cols as $col => $def) {
    try {
        $db->exec("ALTER TABLE onboarding_volunteers ADD COLUMN {$col} {$def}");
        echo "  +kolumna {$col} — dodano\n";
    } catch (\Exception $e) {
        echo "  +kolumna {$col} — już istnieje\n";
    }
}

echo "\nMigracja onboarding_v3 zakończona.\n";
