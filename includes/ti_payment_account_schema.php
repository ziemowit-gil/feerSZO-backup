<?php
/**
 * includes/ti_payment_account_schema.php
 * Samonaprawa schematu — zatwierdzanie numeru konta do wpłat per kursant
 * (karty30/ti/dydaktyk/konta.php, akcja `payment_account_confirm`).
 *
 * payment_bank_account_source: 'org' (wybrany z listy rachunków organizacji,
 * ustawienia → Rachunki, patrz includes/karty30.php::k30_ti_org_account())
 * albo 'custom' (numer wpisany ręcznie / indywidualny dla tego kursanta).
 *
 * k30_ti_payment_account_log: historia zmian numeru konta — dotąd pole
 * payment_bank_account było nadpisywane bez żadnego audytu.
 *
 * Wzorzec jak includes/wolontariat_schema.php: ALTER TABLE ADD COLUMN
 * i CREATE TABLE IF NOT EXISTS w try/catch, idempotentne.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN payment_bank_account_source VARCHAR(10)");
    } catch (\Throwable $e) {
        // Kolumna już istnieje — ignorujemy.
    }

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_payment_account_log (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            student_account_id  INTEGER NOT NULL,
            old_account         TEXT,
            new_account         TEXT,
            source              VARCHAR(10),
            changed_by          INTEGER,
            changed_by_name     TEXT,
            emailed             INTEGER NOT NULL DEFAULT 0,
            created_at          DATETIME NOT NULL
        )");
    } catch (\Throwable $e) {}
})();
