<?php
/**
 * includes/m365_deactivation_schema.php
 * Samonaprawa schematu — okres ochronny po rozwiązaniu umowy (idempotentna).
 *
 * Gdy administrator akceptuje wniosek o rozwiązanie umowy
 * (includes/termination.php::decide_termination), konto M365 i login do
 * panelu ("konto SZO") NIE są wyłączane od razu — cron/sync_m365.php
 * (co 15 min) sprawdza kolumnę m365_deactivate_after i dopiero po jej
 * upływie (4h od decyzji) traktuje konto jako nieaktywne — patrz
 * includes/m365.php::m365_should_be_active(). Wzorzec jak
 * includes/wolontariat_schema.php: ALTER TABLE ADD COLUMN w try/catch.
 *
 * Kolumna dotyczy tylko tabel objętych automatyczną synchronizacją M365
 * (cron/sync_m365.php: wolontariat, zlecenie, dzielo).
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $tables = ['umowy_wolontariat', 'umowy_zlecenie', 'umowy_dzielo'];
    foreach ($tables as $table) {
        try {
            db()->exec("ALTER TABLE {$table} ADD COLUMN m365_deactivate_after DATETIME");
        } catch (\Throwable $e) {
            // Kolumna już istnieje — ignorujemy.
        }
    }
})();
