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
