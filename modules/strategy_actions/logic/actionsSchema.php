<?php
/**
 * modules/strategy_actions/logic/actionsSchema.php — tabele modułu Działań poza bazowymi z includes/grants.php:
 *   action_milestones — etapy (kamienie milowe) działania: tytuł, termin, osoba odpowiedzialna, odhaczenie;
 *   action_log        — historia działania: zmiany, statusy, wskaźniki, etapy, uczestnicy i komentarze.
 * Idempotentne (CREATE … IF NOT EXISTS), raz na żądanie.
 */
function actionsSchemaEnsure(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS action_milestones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        due_date DATE,
        owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        done_at DATETIME,
        done_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_action_milestones_action ON action_milestones(action_id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS action_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
        user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        kind TEXT NOT NULL DEFAULT 'change',
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_action_log_action ON action_log(action_id, id)");
}
