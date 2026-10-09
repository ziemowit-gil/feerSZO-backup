<?php
/**
 * modules/ti_plan_public/logic/planPublicSchema.php — Publiczne linki do planów grup TI: schemat.
 *
 * Jedna grupa = jeden tajny link (token w adresie, bez logowania). Kierownik włącza,
 * wyłącza i regeneruje link per grupa; strona publiczna pokazuje siatkę tygodnia
 * i listę nadchodzących terminów — BEZ danych kursantów. Tabela: k30_ti_plan_public_links.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/selfrepairDB/logic/selfRepairDB.php';

function tiPlanPublicMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    karty30_migrate();
    if (szo_schema_current('ti_plan_public_migrate', __FILE__)) return;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_plan_public_links (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id     INTEGER NOT NULL UNIQUE REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        token         TEXT    NOT NULL UNIQUE,
        is_enabled    INTEGER NOT NULL DEFAULT 1,
        weeks         INTEGER NOT NULL DEFAULT 12,          -- zakres siatki (tygodnie od dziś)
        show_contacts INTEGER NOT NULL DEFAULT 0,           -- kontakty do prowadzących na stronie publicznej
        show_list     INTEGER NOT NULL DEFAULT 1,           -- lista nadchodzących terminów pod siatką
        note          TEXT    NOT NULL DEFAULT '',          -- komunikat dla odbiorców (np. „zmiany możliwe do 15.10”)
        views         INTEGER NOT NULL DEFAULT 0,
        last_view_at  DATETIME,
        created_by    INTEGER,
        created_at    DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at    DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_plan_public_tok ON k30_ti_plan_public_links(token)");
}
