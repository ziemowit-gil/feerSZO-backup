<?php
/**
 * licensemanager/db.php — SQLite helper dla panelu licencji.
 */

define('LM_DB_PATH', __DIR__ . '/licenses.db');

function lm_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . LM_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    lm_migrate($pdo);
    return $pdo;
}

function lm_migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        install_url   TEXT    NOT NULL,
        org_name      TEXT    NOT NULL DEFAULT '',
        org_krs       TEXT    NOT NULL DEFAULT '',
        app_key       TEXT    NOT NULL DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'trial',
        expires_at    DATE    NOT NULL DEFAULT (date('now','+30 days')),
        cert_pem      TEXT    NULL,
        cert_key      TEXT    NULL,
        cert_sig      TEXT    NULL,
        note          TEXT    NULL,
        created_at    DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at    DATETIME NOT NULL DEFAULT (datetime('now')),
        last_ping_at  DATETIME NULL
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_lic_url ON licenses (install_url)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS license_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        license_id INTEGER NOT NULL,
        event      TEXT    NOT NULL,
        detail     TEXT    NULL,
        ip         TEXT    NULL,
        created_at DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
}

function lm_one(string $sql, array $p = []): ?array {
    $s = lm_db()->prepare($sql); $s->execute($p);
    return $s->fetch() ?: null;
}
function lm_all(string $sql, array $p = []): array {
    $s = lm_db()->prepare($sql); $s->execute($p);
    return $s->fetchAll();
}
function lm_exec(string $sql, array $p = []): void {
    lm_db()->prepare($sql)->execute($p);
}
function lm_insert(string $sql, array $p = []): int {
    lm_db()->prepare($sql)->execute($p);
    return (int)lm_db()->lastInsertId();
}
function lm_log(int $license_id, string $event, string $detail = ''): void {
    lm_exec("INSERT INTO license_log (license_id,event,detail,ip) VALUES (?,?,?,?)",
        [$license_id, $event, $detail, $_SERVER['REMOTE_ADDR'] ?? '']);
}
