<?php
/**
 * api/internal/user_sync.php — Endpoint odbiorczy synchronizacji kont.
 *
 * Działa na środowisku TESTOWYM. Przyjmuje JSON z tablicą użytkowników
 * z produkcji i upsertuje je do lokalnej bazy.
 *
 * Zabezpieczenie: nagłówek X-Sync-Key musi pasować do TEST_SYNC_KEY z config.local.php.
 * Nigdy nie nadpisuje: id, microsoft_id, totp_*, twofa_*, created_at.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function _sync_json(bool $ok, array $extra = []): never {
    echo json_encode(array_merge(['ok' => $ok], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    _sync_json(false, ['msg' => 'Method not allowed']);
}

// Sprawdź czy klucz synchronizacji jest skonfigurowany
if (!defined('TEST_SYNC_KEY') || strlen((string)TEST_SYNC_KEY) < 16) {
    http_response_code(503);
    _sync_json(false, ['msg' => 'Sync not configured on this environment (TEST_SYNC_KEY missing)']);
}

// Weryfikacja klucza
$incoming = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';
if (!$incoming || !hash_equals((string)TEST_SYNC_KEY, $incoming)) {
    http_response_code(403);
    _sync_json(false, ['msg' => 'Forbidden']);
}

// Parsowanie ciała żądania
$body  = file_get_contents('php://input');
$data  = json_decode($body, true);
$users = $data['users'] ?? [];

if (!is_array($users) || !$users) {
    _sync_json(false, ['msg' => 'No users array in payload']);
}

// Pola dozwolone do synchronizacji (wrażliwe dane bezpieczeństwa pomijane)
const SYNC_FIELDS = ['name', 'first_name', 'last_name', 'email', 'password', 'role', 'is_active'];

$created = 0;
$updated = 0;
$skipped = 0;
$pdo     = db();

// Idempotentne dodanie first_name/last_name jeśli jeszcze nie ma kolumny
foreach (['first_name TEXT', 'last_name TEXT'] as $_col) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN {$_col}"); } catch (\Throwable $e) {}
}

foreach ($users as $user) {
    $email = trim($user['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped++;
        continue;
    }

    $payload = [];
    foreach (SYNC_FIELDS as $f) {
        if ($f !== 'email' && array_key_exists($f, $user)) {
            $payload[$f] = $user[$f];
        }
    }
    if (!$payload) { $skipped++; continue; }

    $existing = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $existing->execute([$email]);
    $row = $existing->fetch(\PDO::FETCH_ASSOC);

    if ($row) {
        $sets   = implode(', ', array_map(fn($k) => "{$k}=?", array_keys($payload)));
        $vals   = array_values($payload);
        $vals[] = (int)$row['id'];
        $pdo->prepare("UPDATE users SET {$sets}, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute($vals);
        $updated++;
    } else {
        $payload['email']      = $email;
        $payload['created_at'] = date('Y-m-d H:i:s');
        $cols = implode(', ', array_keys($payload));
        $ph   = implode(', ', array_fill(0, count($payload), '?'));
        $pdo->prepare("INSERT INTO users ({$cols}) VALUES ({$ph})")->execute(array_values($payload));
        $created++;
    }
}

_sync_json(true, ['created' => $created, 'updated' => $updated, 'skipped' => $skipped]);
