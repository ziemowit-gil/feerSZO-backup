<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

use PDO;

/**
 * modules/login_sentry/lib/LoginSentryDatabase.php — osobna baza SQLite LoginSentry („Staszek”), WAL i klucze obce.
 *
 * Plik: {repo}/storage/login_sentry/login_sentry_<16 losowych znaków>.sqlite. Jak u Mietka (modules/warden): na mydevil
 * repozytorium jest public_html, więc katalog leży w repozytorium, ale jest zamknięty dla WWW (^storage/ → 403 w .htaccess,
 * własny .htaccess „Require all denied”, storage/ w .gitignore). Nazwę losujemy raz i pamiętamy w ustawieniu SZO
 * `login_sentry_db_file`. Katalog przestawia stała LOGIN_SENTRY_STORAGE_DIR albo plik config/login_sentry.php.
 * Wszystkie zapytania przez przygotowane wyrażenia PDO.
 *
 * Tabele:
 *   ls_logins      — każde logowanie raz (UNIQUE source+source_id), z wynikiem oceny; to daje idempotentność przebiegów;
 *   ls_known       — „znane” sieci i przeglądarki danej osoby (wzorzec, z którym porównujemy nowe logowania);
 *   ls_incidents   — podejrzenie: jedno na osobę na dzień (UNIQUE user_id+day), więc ponowny przebieg nie dubluje maili ani blokad;
 *   ls_user_exemptions / ls_net_exemptions — wyjątki: osoby i zaufane sieci (np. biuro);
 *   ls_audit, ls_runs, ls_settings.
 */
final class LoginSentryDatabase
{
    public const INCIDENT_STATUSES = ['notified', 'suspended', 'suspend_failed', 'reverted', 'dismissed', 'preview'];

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

    /** Testy: podmiana połączenia (np. sqlite::memory:). */
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
        if (defined('LOGIN_SENTRY_STORAGE_DIR')) return rtrim((string)LOGIN_SENTRY_STORAGE_DIR, '/');
        $cfg = LoginSentryConfig::file()['storage_dir'] ?? '';
        if (is_string($cfg) && $cfg !== '') return rtrim($cfg, '/');
        return dirname(__DIR__, 3) . '/storage/login_sentry';
    }

    public static function path(): string
    {
        $dir = self::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('LoginSentry: nie mogę utworzyć katalogu bazy ' . $dir);
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
        $file = \function_exists('org_setting') ? \org_setting('login_sentry_db_file') : '';
        if (!preg_match('/^login_sentry_[a-z0-9]{16}\.sqlite$/', $file)) {
            $existing = glob($dir . '/login_sentry_*.sqlite') ?: [];
            $file = $existing ? basename($existing[0]) : 'login_sentry_' . self::randomName(16) . '.sqlite';
            if (\function_exists('org_setting_set')) \org_setting_set('login_sentry_db_file', $file);
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
        $statuses = "'" . implode("','", self::INCIDENT_STATUSES) . "'";
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            logto_id TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            score INTEGER NOT NULL DEFAULT 0,
            level TEXT NOT NULL CHECK (level IN ('warn','suspend')),
            status TEXT NOT NULL CHECK (status IN ($statuses)),
            reasons TEXT NOT NULL DEFAULT '[]',
            ips TEXT NOT NULL DEFAULT '[]',
            networks TEXT NOT NULL DEFAULT '[]',
            notified_at TEXT NULL,
            mail_id INTEGER NULL,
            suspended_at TEXT NULL,
            suspended_logto INTEGER NOT NULL DEFAULT 0,
            suspended_szo INTEGER NOT NULL DEFAULT 0,
            reverted_at TEXT NULL,
            reverted_by TEXT NOT NULL DEFAULT '',
            last_error TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
            UNIQUE (user_id, day))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ls_incidents_status ON ls_incidents(status, created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_logins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source TEXT NOT NULL CHECK (source IN ('szo','logto')),
            source_id TEXT NOT NULL,
            user_id INTEGER NULL,
            email TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            network TEXT NOT NULL DEFAULT '',
            ip_state TEXT NOT NULL DEFAULT 'ok',
            ua_family TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '',
            occurred_at TEXT NOT NULL,
            score INTEGER NOT NULL DEFAULT 0,
            reasons TEXT NOT NULL DEFAULT '[]',
            incident_id INTEGER NULL REFERENCES ls_incidents(id) ON DELETE SET NULL,
            processed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
            UNIQUE (source, source_id))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ls_logins_user ON ls_logins(user_id, occurred_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_known (
            user_id INTEGER NOT NULL,
            kind TEXT NOT NULL CHECK (kind IN ('net','ua','country')),
            value TEXT NOT NULL,
            first_seen TEXT NOT NULL,
            last_seen TEXT NOT NULL,
            hits INTEGER NOT NULL DEFAULT 1,
            trusted INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, kind, value))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_user_exemptions (
            user_id INTEGER PRIMARY KEY,
            reason TEXT NOT NULL DEFAULT '',
            expires_at TEXT NULL,
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_net_exemptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cidr TEXT NOT NULL UNIQUE,
            reason TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            incident_id INTEGER NULL REFERENCES ls_incidents(id) ON DELETE SET NULL,
            user_id INTEGER NULL,
            level TEXT NOT NULL DEFAULT 'INFO' CHECK (level IN ('INFO','WARN','ERROR')),
            event TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT '',
            actor TEXT NOT NULL DEFAULT 'staszek',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ls_audit_user ON ls_audit(user_id, id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            mode TEXT NOT NULL,
            trigger TEXT NOT NULL DEFAULT 'cron',
            summary TEXT NOT NULL DEFAULT '{}')");
    }

    public static function setting(string $key, string $default = ''): string
    {
        $st = self::pdo()->prepare('SELECT value FROM ls_settings WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::pdo()->prepare("INSERT INTO ls_settings (key, value, updated_at) VALUES (?, ?, strftime('%Y-%m-%d %H:%M:%S','now'))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")->execute([$key, $value]);
    }

    public static function audit(?int $incidentId, ?int $userId, string $event, array $detail = [], string $actor = 'staszek', string $level = 'INFO'): void
    {
        self::pdo()->prepare('INSERT INTO ls_audit (incident_id, user_id, level, event, detail, actor) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$incidentId, $userId, $level, $event, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $actor]);
    }
}
