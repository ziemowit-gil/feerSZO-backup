<?php
/**
 * API Key Authentication Middleware
 * Provides Bearer token auth for REST API endpoints.
 */

declare(strict_types=1);

/** @var array|null $api_current_key — set on successful auth */
$api_current_key = null;

function api_auth_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            key_hash      TEXT    NOT NULL UNIQUE,
            name          TEXT    NOT NULL,
            permissions   TEXT    NOT NULL DEFAULT '[]',
            last_used_at  TEXT,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            is_active     INTEGER NOT NULL DEFAULT 1,
            created_by    INTEGER
        )
    ");

    // Per-key limit zapytań/min (0/NULL = limit domyślny API_RATE_PER_MIN)
    try {
        $cols = array_column(db_all("PRAGMA table_info(api_keys)"), 'name');
        if (!in_array('rate_limit', $cols, true)) {
            db()->exec("ALTER TABLE api_keys ADD COLUMN rate_limit INTEGER");
        }
    } catch (\Throwable $e) {}

    // Licznik zapytań w oknie 1-minutowym (fixed window) per klucz
    db()->exec("
        CREATE TABLE IF NOT EXISTS api_rate_limit (
            key_id       INTEGER NOT NULL,
            window_start INTEGER NOT NULL,
            count        INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (key_id, window_start)
        )
    ");
}

/**
 * Egzekwuje limit zapytań na minutę dla klucza API.
 * Na przekroczeniu: 429 + Retry-After. Ustawia nagłówki X-RateLimit-*.
 */
function api_rate_limit(array $key): void {
    $per_min = (int)($key['rate_limit'] ?? 0);
    if ($per_min <= 0) $per_min = defined('API_RATE_PER_MIN') ? (int)API_RATE_PER_MIN : 120;
    if ($per_min <= 0) return; // wyłączone

    $window = intdiv(time(), 60);
    try {
        db()->prepare(
            "INSERT INTO api_rate_limit (key_id, window_start, count) VALUES (?, ?, 1)
             ON CONFLICT(key_id, window_start) DO UPDATE SET count = count + 1"
        )->execute([(int)$key['id'], $window]);
        $used = (int)(db_one(
            "SELECT count FROM api_rate_limit WHERE key_id=? AND window_start=?",
            [(int)$key['id'], $window]
        )['count'] ?? 0);
        // Sprzątanie starych okien (utrzymuje tabelę małą)
        db()->prepare("DELETE FROM api_rate_limit WHERE window_start < ?")->execute([$window]);
    } catch (\Throwable $e) {
        return; // gdy licznik zawiedzie — nie blokuj ruchu
    }

    header('X-RateLimit-Limit: ' . $per_min);
    header('X-RateLimit-Remaining: ' . max(0, $per_min - $used));
    if ($used > $per_min) {
        $retry = 60 - (time() % 60);
        header('Retry-After: ' . $retry);
        api_error("Przekroczono limit zapytań ({$per_min}/min). Spróbuj ponownie za {$retry} s.", 429);
    }
}

/**
 * Require API authentication and optionally check permissions.
 * On failure: outputs JSON 401 and exits.
 * On success: sets global $api_current_key.
 *
 * @param string ...$permissions  All listed permissions must be present.
 */
function api_require(string ...$permissions): void {
    global $api_current_key;

    // Extract raw key from Authorization header or query param
    $raw = null;

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (str_starts_with($header, 'Bearer ')) {
        $raw = trim(substr($header, 7));
    } elseif (!empty($_GET['api_key'])) {
        $raw = trim($_GET['api_key']);
    }

    if ($raw === null || $raw === '') {
        api_error('Unauthorized', 401);
    }

    $hash = hash('sha256', $raw);
    $row  = db_one(
        "SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1",
        [$hash]
    );

    if (!$row) {
        api_error('Unauthorized', 401);
    }

    // Check permissions
    if (!empty($permissions)) {
        $granted = json_decode($row['permissions'] ?? '[]', true);
        if (!is_array($granted)) $granted = [];
        foreach ($permissions as $perm) {
            if (!in_array($perm, $granted, true)) {
                api_error('Unauthorized', 401);
            }
        }
    }

    // Update last_used_at
    db()->prepare("UPDATE api_keys SET last_used_at = datetime('now') WHERE id = ?")
        ->execute([$row['id']]);

    // Limit zapytań na minutę (per klucz) — może zakończyć żądanie 429
    api_rate_limit($row);

    $api_current_key = $row;
}

/**
 * Output JSON response and exit.
 *
 * @param mixed $data
 * @param int   $code  HTTP status code
 * @return never
 */
function api_json(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Output JSON error response and exit.
 *
 * @param string $msg
 * @param int    $code  HTTP status code
 * @return never
 */
function api_error(string $msg, int $code = 400): never {
    api_json(['error' => $msg], $code);
}
