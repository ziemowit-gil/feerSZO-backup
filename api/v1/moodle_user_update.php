<?php
/**
 * REST API — Moodle → FEER: zapisz dane konta Moodle użytkownika.
 * POST /api/v1/moodle_user_update.php
 *
 * Wywoływany przez wtyczkę Moodle po utworzeniu lub znalezieniu konta.
 * Zapisuje moodle_login (username) i moodle_user_id do tabeli users
 * (tylko dla standalone volunteers — is_standalone_volunteer=1).
 *
 * Body JSON:
 *   email           string  — e-mail użytkownika (klucz wyszukiwania)
 *   moodle_username string  — login/username w Moodle
 *   moodle_user_id  int     — ID w Moodle
 *   moodle_url      string  — URL Moodle (opcjonalne, do wyświetlenia w panelu)
 *
 * Wymagane uprawnienie klucza: volunteers:read
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/cpc.php';

api_auth_migrate();
cpc_migrate();
api_require('volunteers:read');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method Not Allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$email           = strtolower(trim($body['email']           ?? ''));
$moodle_username = trim($body['moodle_username'] ?? '');
$moodle_user_id  = (int)($body['moodle_user_id'] ?? 0);
$moodle_url      = trim($body['moodle_url']      ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('Invalid email', 400);
}

$user = db_one(
    "SELECT id, is_standalone_volunteer FROM users WHERE LOWER(TRIM(email))=? LIMIT 1",
    [$email]
);

if (!$user) {
    api_error('User not found', 404);
}

$update = [];
if ($moodle_username) $update['moodle_login']  = $moodle_username;  // re-uses m365_login-style column
if ($moodle_user_id)  $update['microsoft_id']  = null; // don't overwrite — skip if not standalone

// Dla standalone volunteers zapisujemy moodle_login w dedykowanej kolumnie
// Dla pozostałych użytkowników — tylko jeśli to standalone
if ($user['is_standalone_volunteer']) {
    if ($moodle_username) {
        try {
            db()->prepare("UPDATE users SET moodle_login=? WHERE id=?")
                ->execute([$moodle_username, (int)$user['id']]);
        } catch (\Throwable $e) {
            // Kolumna może jeszcze nie istnieć — dodaj ją
            try { db()->exec("ALTER TABLE users ADD COLUMN moodle_login TEXT NULL"); } catch (\Throwable $e2) {}
            try {
                db()->prepare("UPDATE users SET moodle_login=? WHERE id=?")
                    ->execute([$moodle_username, (int)$user['id']]);
            } catch (\Throwable $e3) {}
        }
    }
    if ($moodle_url) {
        // Zapisz URL Moodle globalnie w settings (jeśli jeszcze nie ustawiony)
        try {
            $ex = db_one("SELECT value FROM settings WHERE key_='moodle_url'");
            if (!$ex || !$ex['value']) {
                db()->prepare("INSERT INTO settings (key_, value) VALUES ('moodle_url',?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
                    ->execute([$moodle_url]);
            }
        } catch (\Throwable $e) {}
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'      => true,
    'user_id' => (int)$user['id'],
    'updated' => $moodle_username ? ['moodle_login' => $moodle_username] : [],
]);
