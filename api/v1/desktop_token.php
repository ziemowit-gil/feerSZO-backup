<?php
/**
 * REST API — tokeny logowania aplikacji EZD Client (patrz includes/desktop_auth.php).
 *
 *   POST /api/v1/desktop_token.php   {"grant_type":"authorization_code","code":"…","code_verifier":"…"}
 *        → 200 {"access_token":"ezdc_…","token_type":"Bearer","expires_at":"…","scopes":[…],
 *               "user":{"id":N,"name":"…","email":"…"}}
 *   POST /api/v1/desktop_token.php?action=renew    (Bearer)  → przedłuża token o 30 dni, odświeża uprawnienia
 *   POST /api/v1/desktop_token.php?action=logout   (Bearer)  → unieważnia token (wylogowanie)
 * Kody są jednorazowe i krótkotrwałe (2 min), wiązane z PKCE (S256). Tokeny tworzone tu mają nazwę „EZD Client — …”
 * i są widoczne w panelu Zarządzaj API (można je unieważnić ręcznie).
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/desktop_auth.php';

api_auth_migrate();
desktop_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_error('Method Not Allowed — użyj POST.', 405);

$action = (string)($_GET['action'] ?? '');

if ($action === 'renew' || $action === 'logout') {
    api_require();
    global $api_current_key;
    if (!str_starts_with((string)($api_current_key['name'] ?? ''), DESKTOP_TOKEN_PREFIX)) {
        api_error('To nie jest token aplikacji EZD Client.', 403);
    }
    if ($action === 'logout') {
        db()->prepare("UPDATE api_keys SET is_active=0 WHERE id=?")->execute([(int)$api_current_key['id']]);
        api_json(['ok' => true]);
    }
    $user = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [(int)$api_current_key['created_by']]);
    if (!$user) {
        db()->prepare("UPDATE api_keys SET is_active=0 WHERE id=?")->execute([(int)$api_current_key['id']]);
        api_error('Konto jest nieaktywne.', 401);
    }
    $scopes  = desktop_scopes_for_user($user);
    $expires = date('Y-m-d H:i:s', time() + DESKTOP_TOKEN_TTL_DAYS * 86400);
    db()->prepare("UPDATE api_keys SET expires_at=?, permissions=? WHERE id=?")
        ->execute([$expires, json_encode($scopes), (int)$api_current_key['id']]);
    api_json(['ok' => true, 'expires_at' => $expires, 'scopes' => $scopes,
              'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'] ?? '']]);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || ($in['grant_type'] ?? '') !== 'authorization_code') api_error('Wymagane: grant_type=authorization_code.', 400);
$code     = (string)($in['code'] ?? '');
$verifier = (string)($in['code_verifier'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $code) || !preg_match('/^[A-Za-z0-9_.~-]{43,128}$/', $verifier)) api_error('Nieprawidłowy kod lub verifier.', 400);

$hash = hash('sha256', $code);
$pdo  = db();
$pdo->beginTransaction();
$row  = db_one("SELECT * FROM desktop_auth_codes WHERE code_hash=?", [$hash]);
// kod jednorazowy: oznacz jako użyty zanim cokolwiek wydamy (także przy błędnym verifierze — spalony)
$ok = $row && $row['used_at'] === null && (int)$row['expires_at'] >= time();
if ($row && $row['used_at'] === null) {
    $pdo->prepare("UPDATE desktop_auth_codes SET used_at=? WHERE code_hash=? AND used_at IS NULL")->execute([time(), $hash]);
}
$pdo->commit();
if (!$ok) api_error('Kod wygasł lub został już użyty.', 400);
if (!hash_equals((string)$row['code_challenge'], desktop_b64url(hash('sha256', $verifier, true)))) api_error('Nieprawidłowy code_verifier.', 400);

$user = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [(int)$row['user_id']]);
if (!$user) api_error('Konto jest nieaktywne.', 403);
$issued = desktop_issue_token($user, (string)$row['device']);
if (!$issued['scopes']) api_error('Konto nie ma uprawnień do EZD ani CRM.', 403);

api_json([
    'access_token' => $issued['token'], 'token_type' => 'Bearer', 'expires_at' => $issued['expires_at'],
    'scopes' => $issued['scopes'],
    'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'] ?? ''],
]);
