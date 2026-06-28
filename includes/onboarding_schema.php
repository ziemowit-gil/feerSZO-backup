<?php
/**
 * includes/onboarding_schema.php
 * Samonaprawa schematu tabeli onboarding_volunteers (idempotentna).
 * Wywoływana na początku każdego pliku modułu onboardingowego.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        // v2
        'miejsce_wolontariatu'   => 'TEXT',
        'przedmiot_porozumienia' => 'TEXT',
        'data_rozpoczecia'       => 'TEXT',
        'data_zakonczenia'       => 'TEXT',
        'oswiadczenie_file'      => 'TEXT',
        'user_id'                => 'INTEGER',
        // v3
        'typ'                    => 'TEXT',
        'seria_nr_dowodu'        => 'TEXT',
        'urzad_skarbowy'         => 'TEXT',
        'addr_street'            => 'TEXT',
        'addr_house'             => 'TEXT',
        'addr_flat'              => 'TEXT',
        'addr_postal'            => 'TEXT',
        'addr_city'              => 'TEXT',
        'rachunek_bankowy'       => 'TEXT',
        // v4
        'bank_nazwa'             => 'TEXT',
        'rachunek_podpis_at'     => 'TEXT',
        'rachunek_podpis_ip'     => 'TEXT',
        'rachunek_podpis_metoda' => 'TEXT',
    ];

    $db = db();
    foreach ($columns as $col => $type) {
        try {
            $db->exec("ALTER TABLE onboarding_volunteers ADD COLUMN {$col} {$type}");
        } catch (\Throwable $e) {
            // kolumna już istnieje — ignoruj
        }
    }
})();
