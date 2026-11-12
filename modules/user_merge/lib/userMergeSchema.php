<?php
/**
 * modules/user_merge/lib/userMergeSchema.php — samonaprawa schematu scalania kont (Tożsamość).
 *
 * users.merged_into_id — id konta głównego, do którego scalono to konto (NULL = konto zwykłe);
 * users.merged_at      — kiedy scalono.
 * Konto scalone zostaje w bazie jako archiwum (is_active = 0) — nigdy go nie kasujemy.
 *
 * Bez PRAGMA (MySQL): ALTER w try/catch, duplikat kolumny = ignoruj. Bramka szo_schema_current — raz na wersję pliku.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

function userMergeMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('user_merge_migrate', __FILE__)) return;
    foreach (['merged_into_id INTEGER NULL', 'merged_at VARCHAR(19) NULL'] as $col) {
        try { db()->exec("ALTER TABLE users ADD COLUMN {$col}"); } catch (\Throwable $e) { /* kolumna już jest */ }
    }
}
