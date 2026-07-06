<?php
/**
 * includes/wolontariat_schema.php
 * Samonaprawa schematu tabeli umowy_wolontariat (idempotentna, agnostyczna silnikowo).
 *
 * Kolumny profilu wolontariusza (segmentacja, terytorium, dostępność) są od
 * dawna używane w contracts/wolontariat/{add,edit,list,view}.php, ale nigdy
 * nie zostały dołożone żadną migracją do umowy_wolontariat — stąd np.
 * "no such column: wojewodztwo" przy filtrach na liście. Wzorzec jak
 * includes/zlecenie_schema.php: ALTER TABLE ADD COLUMN per kolumna w
 * try/catch, bez PRAGMA table_info (nie działa na MySQL).
 *
 * Uruchamia się na górze add.php/edit.php/list.php/view.php — zawsze PRZED
 * odczytem/zapisem. Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        // Profil wolontariusza
        'wolontariat_typ'  => "VARCHAR(20)",
        'obszar_dzialania' => "TEXT",
        'kompetencje'      => "TEXT",
        'jezyki'           => "VARCHAR(255)",
        'wyksztalcenie'    => "VARCHAR(50)",
        // Dostępność
        'dostepnosc_dni'   => "TEXT",
        'dostepnosc_pora'  => "TEXT",
        // Terytorium
        'gmina'            => "VARCHAR(255)",
        'powiat'           => "VARCHAR(255)",
        'wojewodztwo'      => "VARCHAR(100)",
        'teryt_kod'        => "VARCHAR(20)",
        // Opiekun towarzyszący (dorosły wolontariusz nadzorujący małoletniego
        // podczas świadczeń) — informacyjne, bez wpływu na zgodę RODO/wolontariat
        // przedstawiciela ustawowego (zob. includes/guardian_consent.php).
        'opiekun_wolontariusz_id' => "INTEGER",
    ];

    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
        }
    }
})();
