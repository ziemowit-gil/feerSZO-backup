<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/lib/NoweHasloLimit.php — liczniki prób serwisu /nowehaslo w tabeli login_attempts
 * (ta sama, której używa logowanie i DhostingGuest). Identyfikatory z prefiksem NOWEHASLO:, np. NOWEHASLO:ip:1.2.3.4.
 */
final class NoweHasloLimit
{
    /** Ile wpisów o tym identyfikatorze było w ostatnich $window sekundach. */
    public static function count(string $id, int $window): int
    {
        self::table();
        $since = date('Y-m-d H:i:s', time() - $window);
        try {
            return (int)(db_one("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at>?", ['NOWEHASLO:' . $id, $since])['c'] ?? 0);
        } catch (\Throwable $e) {
            error_log('[nowehaslo] limit ' . $id . ': ' . $e->getMessage());
            return 0;
        }
    }

    public static function hit(string $id): void
    {
        self::table();
        try {
            db()->prepare("INSERT INTO login_attempts (identifier, ip, created_at) VALUES (?, ?, ?)")
                ->execute(['NOWEHASLO:' . $id, self::ip(), date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('[nowehaslo] limit zapis ' . $id . ': ' . $e->getMessage());
        }
    }

    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function table(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL,
                ip TEXT NOT NULL DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        } catch (\Throwable $e) { /* jest (MySQL ma własną definicję) */ }
    }
}
