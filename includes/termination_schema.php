<?php
/**
 * includes/termination_schema.php
 * Samonaprawa schematu tabeli contract_termination_requests (idempotentna).
 *
 * Rozszerza wniosek o rozwiązanie umowy o dane wymagane przez klauzule
 * rezygnacji/rozwiązania porozumienia wolontariackiego: która strona
 * wypowiada porozumienie, w jakim trybie (§ 7 contracts/wolontariat/print.php)
 * oraz jaki z tego wynika okres wypowiedzenia i efektywna data zakończenia
 * współpracy. Wzorzec jak includes/wolontariat_schema.php: ALTER TABLE ADD
 * COLUMN per kolumna w try/catch, bez PRAGMA table_info.
 *
 * Uruchamia się na górze includes/termination.php — zawsze PRZED
 * odczytem/zapisem tabeli. Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        // Strona wypowiadająca: 'wolontariusz' | 'korzystajacy' | 'porozumienie_stron'
        'initiator'      => "VARCHAR(20)",
        // Tryb rozwiązania — klucz z TERMINATION_VARIANTS (includes/termination.php)
        'variant'        => "VARCHAR(30)",
        // Okres wypowiedzenia w dniach wynikający z wybranego trybu (0 = natychmiastowy)
        'notice_days'    => "INTEGER",
        // Wyliczona data, z którą porozumienie faktycznie się rozwiązuje
        'effective_date' => "DATE",
    ];

    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE contract_termination_requests ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
        }
    }
})();
