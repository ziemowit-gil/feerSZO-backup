<?php
/**
 * db.php — pojedyncze połączenie PDO SQLite + warstwa ustawień.
 * Wszystkie zapytania w aplikacji korzystają z prepared statements.
 */
declare(strict_types=1);

/** Uchwyt PDO (singleton). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

// ── Skróty zapytań ────────────────────────────────────────────────────

/** Wykonaj zapytanie z parametrami i zwróć PDOStatement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Jeden wiersz albo null. */
function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Wszystkie wiersze. */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Pierwsza kolumna pierwszego wiersza. */
function q_val(string $sql, array $params = [], mixed $default = null): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** INSERT z tablicy asocjacyjnej → id nowego wiersza. */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn($c) => '"' . $c . '"', $cols)),
        implode(', ', array_map(fn($c) => ':' . $c, $cols))
    );
    q($sql, $data);
    return (int)db()->lastInsertId();
}

/** UPDATE z tablicy asocjacyjnej po kolumnie id. */
function db_update(string $table, int $id, array $data): void
{
    if (!$data) return;
    $sets = implode(', ', array_map(fn($c) => '"' . $c . '" = :' . $c, array_keys($data)));
    $data['__id'] = $id;
    q("UPDATE {$table} SET {$sets} WHERE id = :__id", $data);
}

// ── Ustawienia (tabela settings: key => value) ─────────────────────────

/** Wszystkie ustawienia jako tablica (cache w pamięci procesu). */
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (q_all('SELECT key, value FROM settings') as $r) {
            $cache[$r['key']] = $r['value'];
        }
    }
    return $cache;
}

/** Pobierz ustawienie. */
function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
}

/** Ustawienie logiczne. */
function setting_bool(string $key, bool $default = false): bool
{
    $all = settings_all();
    if (!array_key_exists($key, $all) || $all[$key] === '') return $default;
    return in_array(strtolower($all[$key]), ['1', 'true', 'yes', 'on'], true);
}

/** Zapisz jedno ustawienie (UPSERT). */
function setting_set(string $key, string $value): void
{
    q(
        "INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value = :v, updated_at = datetime('now')",
        ['k' => $key, 'v' => $value]
    );
    settings_all(true);
}

/** Zapisz wiele ustawień w transakcji. */
function settings_save(array $pairs): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($pairs as $k => $v) {
            setting_set((string)$k, (string)$v);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    settings_all(true);
}
