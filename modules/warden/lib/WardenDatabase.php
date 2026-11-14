<?php
declare(strict_types=1);

namespace Feer\Szo\Warden;

use PDO;

/**
 * modules/warden/lib/WardenDatabase.php — osobna baza SQLite strażnika kont (WAL, klucze obce).
 *
 * Plik leży POZA katalogiem publicznym (repozytorium = public_html na mydevil): domyślnie w katalogu obok repozytorium,
 * {rodzic repo}/szo-storage/warden_<16 losowych znaków>.sqlite. Nazwę pliku losujemy przy pierwszym użyciu i zapamiętujemy
 * w ustawieniu SZO `warden_db_file`. Katalog można przestawić stałą WARDEN_STORAGE_DIR (config.local.php) albo w teście.
 * Wszystkie zapytania przez przygotowane wyrażenia PDO.
 */
final class WardenDatabase
{
    public const OPEN_STATUSES = ['warning_1_sent', 'warning_2_sent', 'sms_sent'];
    public const STATUSES = ['warning_1_sent', 'warning_2_sent', 'sms_sent', 'deactivated', 'resolved_by_login', 'reactivated', 'cancelled'];

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO('sqlite:' . self::path(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 10000');
            self::migrate($pdo);
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** Tylko testy: podmiana połączenia (np. na plik w scratchpadzie). */
    public static function useConnection(?PDO $pdo): void
    {
        if ($pdo !== null) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::migrate($pdo);
        }
        self::$pdo = $pdo;
    }

    public static function storageDir(): string
    {
        if (defined('WARDEN_STORAGE_DIR')) return rtrim((string)WARDEN_STORAGE_DIR, '/');
        return dirname(__DIR__, 4) . '/szo-storage';
    }

    public static function path(): string
    {
        $dir = self::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('WARDEN: nie mogę utworzyć katalogu bazy ' . $dir);
        }
        $file = \function_exists('org_setting') ? \org_setting('warden_db_file') : '';
        if (!preg_match('/^warden_[a-z0-9]{16}\.sqlite$/', $file)) {
            $file = 'warden_' . self::randomName(16) . '.sqlite';
            if (\function_exists('org_setting_set')) \org_setting_set('warden_db_file', $file);
        }
        return $dir . '/' . $file;
    }

    private static function randomName(int $len): string
    {
        $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
        return $s;
    }

    public static function migrate(PDO $pdo): void
    {
        $statuses = "'" . implode("','", self::STATUSES) . "'";
        $pdo->exec("CREATE TABLE IF NOT EXISTS warden_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warden_users (
            user_id INTEGER PRIMARY KEY,
            logto_id TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            szo_last_login_at TEXT NULL,
            logto_last_login_at TEXT NULL,
            effective_last_activity TEXT NULL,
            activity_floor_at TEXT NULL,
            checked_at TEXT NULL,
            last_error TEXT NOT NULL DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warden_cycles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES warden_users(user_id) ON DELETE CASCADE,
            logto_id TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL CHECK (status IN ($statuses)),
            cycle_started_at TEXT NOT NULL,
            deadline_at TEXT NOT NULL,
            last_activity_at_start TEXT NULL,
            warning_1_sent_at TEXT NULL,
            warning_2_sent_at TEXT NULL,
            sms_sent_at TEXT NULL,
            sms_skipped TEXT NOT NULL DEFAULT '',
            deactivated_at TEXT NULL,
            resolved_at TEXT NULL,
            resolved_source TEXT NOT NULL DEFAULT '',
            last_error TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_warden_cycles_open ON warden_cycles(user_id)
            WHERE status IN ('warning_1_sent','warning_2_sent','sms_sent')");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_warden_cycles_status ON warden_cycles(status, cycle_started_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warden_exemptions (
            user_id INTEGER PRIMARY KEY,
            reason TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warden_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NULL REFERENCES warden_cycles(id) ON DELETE SET NULL,
            user_id INTEGER NULL,
            event TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT '',
            actor TEXT NOT NULL DEFAULT 'warden',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_warden_audit_user ON warden_audit(user_id, id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warden_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            mode TEXT NOT NULL,
            trigger TEXT NOT NULL DEFAULT 'cron',
            summary TEXT NOT NULL DEFAULT '{}')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warden_announcements (
            user_id INTEGER PRIMARY KEY,
            email TEXT NOT NULL DEFAULT '',
            mail_id INTEGER NULL,
            sent_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
    }

    // ── Ustawienia ───────────────────────────────────────────────────────────────────────

    public static function setting(string $key, string $default = ''): string
    {
        $st = self::pdo()->prepare('SELECT value FROM warden_settings WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::pdo()->prepare("INSERT INTO warden_settings (key, value, updated_at) VALUES (?, ?, strftime('%Y-%m-%d %H:%M:%S','now'))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")->execute([$key, $value]);
    }

    // ── Audyt ────────────────────────────────────────────────────────────────────────────

    public static function audit(?int $cycleId, ?int $userId, string $event, array $detail = [], string $actor = 'warden'): void
    {
        self::pdo()->prepare('INSERT INTO warden_audit (cycle_id, user_id, event, detail, actor) VALUES (?, ?, ?, ?, ?)')
            ->execute([$cycleId, $userId, $event, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $actor]);
    }
}
