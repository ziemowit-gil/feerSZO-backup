<?php
/**
 * planner_token.php — Generuje krótkoterminowy token API planera SZO
 * dla zalogowanego dydaktyka. Wywoływany przez JS w _tab_planner.php.
 *
 * Wymaga sesji panelu dydaktyka (k30_dydaktyk). Nie wymaga tokenu API.
 * Zwraca: { token, api_url, expires_in, user_id, course_id }
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/api_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Wymaga aktywnej sesji dydaktyka
$me  = dyd_require();
$uid = (int)$me['user_id'];

$course_id = (int)($_GET['course_id'] ?? 0);

api_auth_migrate();

// Usuń wygasłe tokeny TI dla tego usera
try {
    db_exec(
        "DELETE FROM api_keys WHERE name LIKE ? AND expires_at < datetime('now')",
        ["TI-dyd:uid:{$uid}:%"]
    );
} catch (\Throwable $e) {}

// Wygeneruj nowy token (64 znaki hex)
$raw  = bin2hex(random_bytes(32));
$hash = hash('sha256', $raw);
$name = "TI-dyd:uid:{$uid}:course:{$course_id}";

db_exec(
    "INSERT INTO api_keys (key_hash, name, permissions, created_by, expires_at, is_active)
     VALUES (?,?,?,?,datetime('now','+24 hours'),1)",
    [$hash, $name, json_encode(['planner:read', 'planner:write']), $uid]
);

$api_url = rtrim(APP_URL, '/') . '/api/v1/planner.php';

echo json_encode([
    'token'      => $raw,
    'api_url'    => $api_url,
    'expires_in' => 86400,
    'user_id'    => $uid,
    'course_id'  => $course_id,
], JSON_UNESCAPED_SLASHES);
