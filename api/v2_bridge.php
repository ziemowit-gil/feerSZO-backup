<?php
/**
 * API Bridge v2 — punkt wejścia dla nowego SZO (feerSZO-v2/Laravel).
 *
 * Obsługuje:
 * 1. POST /api/v2_bridge.php?action=auth        — weryfikacja danych logowania, zwraca token sesji
 * 2. GET  /api/v2_bridge.php?action=user         — dane zalogowanego użytkownika (wymaga tokenu)
 * 3. GET  /api/v2_bridge.php?action=export_user  — pełny eksport danych użytkownika do importu w Laravel
 *
 * Wszystkie odpowiedzi w JSON. Autoryzacja przez Bearer token (token = users.login_code ustawiony podczas handshake).
 *
 * UWAGA: Ten plik nie jest dostępny publicznie bez autoryzacji. Wymaga nagłówka
 * X-V2-Bridge-Secret zgodnego z APP_KEY z config.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (getenv('SZO2_URL') ?: 'http://localhost:8000'));
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-V2-Bridge-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Weryfikacja shared secret między starym a nowym SZO
$bridgeSecret = getenv('V2_BRIDGE_SECRET') ?: (defined('APP_KEY') ? substr(APP_KEY, 0, 32) : '');
$providedSecret = $_SERVER['HTTP_X_V2_BRIDGE_SECRET'] ?? '';

if (!$bridgeSecret || !hash_equals($bridgeSecret, $providedSecret)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden', 'code' => 'invalid_bridge_secret']);
    exit;
}

$action = $_GET['action'] ?? '';

/**
 * Wyciąga Bearer token z nagłówka Authorization.
 *
 * @return string|null
 */
function get_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Pobiera użytkownika po tokenie (login_code) lub ID z sesji.
 *
 * @return array|null  Wiersz z tabeli users lub null
 */
function get_bridge_user(): ?array
{
    $token = get_bearer_token();
    if ($token) {
        return db_one("SELECT * FROM users WHERE login_code = ? AND is_active = 1", [$token]);
    }
    return null;
}

// ── Akcja: auth — weryfikacja credentials i wydanie tokenu ───────────────────
if ($action === 'auth') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if ($email === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['error' => 'email i password są wymagane']);
        exit;
    }

    $user = db_one("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email]);

    if (!$user || !password_verify($password, (string)($user['password'] ?? ''))) {
        http_response_code(401);
        echo json_encode(['error' => 'Nieprawidłowe dane logowania']);
        exit;
    }

    // Wygeneruj jednorazowy token handshake ważny 5 minut
    $token   = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', time() + 300);

    db_run(
        "UPDATE users SET login_code = ?, sms_fallback_expires_at = ? WHERE id = ?",
        [$token, $expires, $user['id']]
    );

    echo json_encode([
        'token'      => $token,
        'expires_at' => $expires,
        'user_id'    => $user['id'],
    ]);
    exit;
}

// ── Akcja: user — podstawowe dane zalogowanego użytkownika ──────────────────
if ($action === 'user') {
    $user = get_bridge_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    echo json_encode([
        'id'          => (int) $user['id'],
        'name'        => $user['name'],
        'email'       => $user['email'],
        'role'        => $user['role'],
        'first_name'  => $user['first_name'] ?? '',
        'last_name'   => $user['last_name'] ?? '',
        'microsoft_id'=> $user['microsoft_id'],
        'm365_login'  => $user['m365_login'],
        'is_active'   => (bool) $user['is_active'],
    ]);
    exit;
}

// ── Akcja: export_user — pełny eksport danych użytkownika do importu w v2 ────
if ($action === 'export_user') {
    $user = get_bridge_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Dane zadań — obszary robocze dostępne dla tego użytkownika
    $workspaces = db_all(
        "SELECT tw.id, tw.slug, tw.name, tw.color, tw.icon
         FROM task_workspaces tw
         JOIN task_workspace_members twm ON twm.workspace_id = tw.id
         WHERE twm.user_id = ? AND tw.is_active = 1
         ORDER BY tw.name",
        [$user['id']]
    );

    // Zadania przypisane do użytkownika
    $myTasks = db_all(
        "SELECT t.id, t.title, t.priority, t.due_date, t.completed_at,
                tl.name AS list_name, tw.name AS workspace_name
         FROM tasks t
         JOIN task_lists tl ON tl.id = t.list_id
         JOIN task_workspaces tw ON tw.id = t.workspace_id
         JOIN task_assignments ta ON ta.task_id = t.id
         WHERE ta.user_id = ? AND t.deleted_at IS NULL AND t.archived_at IS NULL
         ORDER BY t.due_date ASC, t.priority DESC
         LIMIT 50",
        [$user['id']]
    );

    // Umowy wolontariackie powiązane z tym użytkownikiem (przez email)
    $contracts = db_all(
        "SELECT id, numer_umowy, status, data_zawarcia, data_zakonczenia
         FROM umowy_wolontariat
         WHERE email = ?
         ORDER BY created_at DESC
         LIMIT 10",
        [$user['email']]
    );

    echo json_encode([
        'user'       => [
            'id'                => (int) $user['id'],
            'name'              => $user['name'],
            'email'             => $user['email'],
            'first_name'        => $user['first_name'] ?? '',
            'last_name'         => $user['last_name'] ?? '',
            'role'              => $user['role'],
            'microsoft_id'      => $user['microsoft_id'],
            'm365_login'        => $user['m365_login'],
            'phone_number'      => $user['phone_number'] ?? '',
            'totp_confirmed'    => (bool) ($user['totp_confirmed'] ?? 0),
            'twofa_method'      => $user['twofa_method'] ?? '',
            'webauthn_required' => (bool) ($user['webauthn_required'] ?? 0),
            'allow_local_fallback' => (bool) ($user['allow_local_fallback'] ?? 0),
            'must_change_password' => (bool) ($user['must_change_password'] ?? 0),
        ],
        'workspaces' => $workspaces,
        'my_tasks'   => $myTasks,
        'contracts'  => $contracts,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => "Nieznana akcja: {$action}"]);
