<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (DB_TYPE === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_PATH);
        // busy_timeout: czekaj do 10s gdy DB zablokowana przez inny worker (WAL).
        // Bez tego session_write_close() rzuca SQLITE_BUSY → sesja nie zapisana →
        // użytkownik wraca na stronę logowania bez komunikatu błędu.
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON;');
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function db_insert(string $table, array $data): int {
    // Zamień puste stringi na NULL dla kolumn FK (*_id)
    foreach ($data as $k => &$v) {
        if ($v === '' && str_ends_with($k, '_id')) $v = null;
    }
    unset($v);
    $cols = implode(', ', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));
    $stmt = db()->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})");
    $stmt->execute($data);
    return (int) db()->lastInsertId();
}

function db_nullify_ids(array &$data): void {
    foreach ($data as $k => &$v) {
        if (str_ends_with($k, '_id') && $v === '') $v = null;
    }
    unset($v);
}

function db_update(string $table, array $data, int $id): void {
    // Dodaj updated_at tylko jeśli kolumna istnieje w tabeli
    static $has_updated_at = [];
    if (!isset($has_updated_at[$table])) {
        try {
            if (DB_TYPE === 'sqlite') {
                $cols = db()->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
            } else {
                $cols = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);
            }
            $has_updated_at[$table] = in_array('updated_at', $cols);
        } catch (\Throwable $e) {
            $has_updated_at[$table] = false;
        }
    }
    if ($has_updated_at[$table]) {
        $data['updated_at'] = date('Y-m-d H:i:s');
    }
    $set = implode(', ', array_map(fn($k) => "`{$k}` = :{$k}", array_keys($data)));
    $data['id'] = $id;
    db()->prepare("UPDATE `{$table}` SET {$set} WHERE id = :id")->execute($data);
}

function db_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_exec(string $sql, array $params = []): void {
    db()->prepare($sql)->execute($params);
}
