<?php
/**
 * includes/supervisors.php
 * Opiekunowie umów — przypisanie użytkownika admin/editor do umowy
 */

function supervisor_ensure_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS contract_supervisors (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type TEXT NOT NULL,
        contract_id   INTEGER NOT NULL,
        user_id       INTEGER NOT NULL,
        user_name     TEXT NOT NULL DEFAULT '',
        user_email    TEXT NOT NULL DEFAULT '',
        assigned_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(contract_type, contract_id)
    )");
}

// Jednorazowe wywołanie przy pierwszym załadowaniu pliku
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try { supervisor_ensure_table(); } catch (\Throwable $e) {}
})();

/**
 * Pobierz opiekuna dla danej umowy.
 * @return array{user_id:int,user_name:string,user_email:string}|null
 */
function supervisor_get(string $type, int $id): ?array {
    try {
        $r = db_one(
            "SELECT user_id, user_name, user_email FROM contract_supervisors
             WHERE contract_type=? AND contract_id=?",
            [$type, $id]
        );
        return $r ?: null;
    } catch (\Throwable $e) { return null; }
}

/**
 * Przypisz opiekuna. Gdy user_id=0 — usuwa przypisanie.
 */
function supervisor_set(string $type, int $id, int $user_id): void {
    if (!$user_id) {
        supervisor_clear($type, $id);
        return;
    }
    try {
        $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$user_id]);
        if (!$u) return;
        db()->prepare(
            "INSERT INTO contract_supervisors
                (contract_type, contract_id, user_id, user_name, user_email, assigned_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(contract_type, contract_id) DO UPDATE SET
               user_id     = excluded.user_id,
               user_name   = excluded.user_name,
               user_email  = excluded.user_email,
               assigned_at = excluded.assigned_at"
        )->execute([$type, $id, (int)$u['id'], $u['name'], $u['email'], date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {}
}

/**
 * Usuń przypisanie opiekuna.
 */
function supervisor_clear(string $type, int $id): void {
    try {
        db()->prepare(
            "DELETE FROM contract_supervisors WHERE contract_type=? AND contract_id=?"
        )->execute([$type, $id]);
    } catch (\Throwable $e) {}
}

/**
 * Lista wszystkich aktywnych administratorów i edytorów.
 * @return array<array{id:int,name:string,email:string}>
 */
function supervisors_all_editors(): array {
    try {
        return db_all(
            "SELECT id, name, email FROM users
             WHERE role IN ('admin','editor') AND is_active=1
             ORDER BY name"
        );
    } catch (\Throwable $e) { return []; }
}
