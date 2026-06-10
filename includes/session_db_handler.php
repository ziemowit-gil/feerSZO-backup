<?php
/**
 * DbSessionHandler — przechowuje sesje PHP w SQLite/MySQL.
 * Rejestrowany w auth_start() przez session_set_save_handler().
 * Eliminuje zależność od Redis — działa z samą bazą aplikacji.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->_migrate();
    }

    private function _migrate(): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
                id      TEXT    PRIMARY KEY,
                data    TEXT    NOT NULL DEFAULT '',
                expires INTEGER NOT NULL DEFAULT 0
            )");
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_sessions_expires ON php_sessions(expires)"
            );
        } catch (\Throwable $e) {
            error_log('[session_db] migrate: ' . $e->getMessage());
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT data FROM php_sessions WHERE id = ? AND expires > ?"
            );
            $stmt->execute([$id, time()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? (string)$row['data'] : '';
        } catch (\Throwable $e) {
            error_log('[session_db] read: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $expires  = time() + max(1, (int)ini_get('session.gc_maxlifetime'));
            $stmt = $this->pdo->prepare(
                "INSERT OR REPLACE INTO php_sessions (id, data, expires) VALUES (?, ?, ?)"
            );
            return $stmt->execute([$id, $data, $expires]);
        } catch (\Throwable $e) {
            error_log('[session_db] write: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (\Throwable $e) {
            error_log('[session_db] destroy: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE expires < ?");
            $stmt->execute([time()]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[session_db] gc: ' . $e->getMessage());
            return false;
        }
    }
}
