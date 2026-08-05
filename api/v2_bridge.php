<?php
/**
 * API Bridge v2 — punkt wejścia dla nowego SZO (feerSZO-v2/Laravel).
 *
 * Autoryzacja dwupoziomowa:
 *   – Każde żądanie musi zawierać X-V2-Bridge-Secret (shared secret między instancjami).
 *   – Akcje wymagające danych użytkownika wymagają dodatkowo Bearer token z handshake.
 *
 * Dostępne akcje (GET o ile nie zaznaczono inaczej):
 *
 *   POST auth          — weryfikacja credentials, zwraca jednorazowy token (5 min)
 *   GET  user          — podstawowe dane zalogowanego użytkownika
 *   GET  export_user   — pełny eksport danych użytkownika do importu w Laravel
 *   GET  contracts     — stronicowana lista umów (wszystkie typy lub wybrany)
 *   GET  contract      — pojedyncza umowa (?type=wolontariat&id=N)
 *   GET  persons       — stronicowana lista osób z tabeli persons
 *   GET  grants        — lista grantów (aktywne lub wszystkie)
 *   GET  tasks         — zadania z filtrami (workspace, status, user)
 *   POST sync_task     — zmiana statusu/ukończenia zadania z v2 → v1
 *   GET  stats         — statystyki dashboardu
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (getenv('SZO2_URL') ?: 'http://localhost:8000'));
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-V2-Bridge-Secret');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');

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

// ── Pomocnicze stałe mapowania ────────────────────────────────────────────────
// Kolumna daty końca różni się między typami umów.
const BRIDGE_END_COL = [
    'zlecenie'    => 'data_zakonczenia',
    'uslugi'      => 'data_zakonczenia',
    'wolontariat' => 'data_zakonczenia',
    'dzielo'      => 'termin_oddania',
    'praca'       => 'data_zakonczenia',
    'powierzenie' => 'data_zakonczenia',
    'inne'        => 'data_zakonczenia',
];
const BRIDGE_CONTRACT_TYPES = ['zlecenie','uslugi','wolontariat','dzielo','praca','powierzenie','inne'];

/**
 * Sprawdza czy tabela umowy_{type} istnieje w bazie.
 */
function bridge_table_exists(string $type): bool
{
    try {
        $r = db_one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", ["umowy_{$type}"]);
        return (bool)$r;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Serializuje wiersz umowy do ujednoliconej postaci.
 * Obsługuje różne nazwy kolumny daty końca.
 */
function bridge_contract_row(array $r, string $type): array
{
    $endCol = BRIDGE_END_COL[$type] ?? 'data_zakonczenia';
    return [
        'id'             => (int)$r['id'],
        'type'           => $type,
        'numer_umowy'    => $r['numer_umowy'] ?? null,
        'imie_nazwisko'  => $r['imie_nazwisko'] ?? null,
        'email'          => $r['email'] ?? null,
        'status'         => $r['status'] ?? null,
        'data_zawarcia'  => $r['data_zawarcia'] ?? null,
        'data_zakonczenia' => $r[$endCol] ?? null,
        'created_at'     => $r['created_at'] ?? null,
    ];
}

// ── Akcja: contracts — stronicowana lista umów ────────────────────────────────
if ($action === 'contracts') {
    $user = get_bridge_user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

    $type     = trim($_GET['type'] ?? 'all');
    $status   = trim($_GET['status'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to'] ?? '');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
    $offset   = ($page - 1) * $perPage;

    $types = ($type === 'all') ? BRIDGE_CONTRACT_TYPES : [$type];

    // Weryfikacja podanego typu
    if ($type !== 'all' && !in_array($type, BRIDGE_CONTRACT_TYPES, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Nieprawidłowy typ umowy', 'allowed' => BRIDGE_CONTRACT_TYPES]);
        exit;
    }

    $rows  = [];
    $total = 0;

    foreach ($types as $t) {
        if (!bridge_table_exists($t)) continue;
        $endCol = BRIDGE_END_COL[$t] ?? 'data_zakonczenia';

        $where  = [];
        $params = [];
        if ($status !== '') { $where[] = 'status = ?';           $params[] = $status; }
        if ($dateFrom !== '') { $where[] = 'data_zawarcia >= ?'; $params[] = $dateFrom; }
        if ($dateTo !== '')   { $where[] = 'data_zawarcia <= ?'; $params[] = $dateTo; }
        $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        try {
            $cnt = db_one("SELECT COUNT(*) AS c FROM umowy_{$t} {$sql}", $params);
            $total += (int)($cnt['c'] ?? 0);

            $tRows = db_all(
                "SELECT id, numer_umowy, imie_nazwisko, email, status,
                        data_zawarcia, {$endCol}, created_at
                 FROM umowy_{$t} {$sql} ORDER BY created_at DESC",
                $params
            );
            foreach ($tRows as $r) {
                $rows[] = bridge_contract_row($r, $t);
            }
        } catch (\Throwable $e) {}
    }

    // Globalne sortowanie + paginacja (po zebraniu z wielu tabel)
    usort($rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    $page_rows = array_slice($rows, $offset, $perPage);

    echo json_encode([
        'data'  => $page_rows,
        'meta'  => [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
        ],
    ]);
    exit;
}

// ── Akcja: contract — pojedyncza umowa ───────────────────────────────────────
if ($action === 'contract') {
    $user = get_bridge_user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

    $type = trim($_GET['type'] ?? '');
    $id   = (int)($_GET['id'] ?? 0);

    if (!in_array($type, BRIDGE_CONTRACT_TYPES, true) || $id <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Wymagane parametry: type i id (> 0)']);
        exit;
    }
    if (!bridge_table_exists($type)) {
        http_response_code(404);
        echo json_encode(['error' => 'Tabela dla tego typu umowy nie istnieje']);
        exit;
    }

    $endCol = BRIDGE_END_COL[$type] ?? 'data_zakonczenia';
    try {
        $r = db_one(
            "SELECT * FROM umowy_{$type} WHERE id = ?",
            [$id]
        );
    } catch (\Throwable $e) {
        $r = null;
    }

    if (!$r) {
        http_response_code(404);
        echo json_encode(['error' => 'Nie znaleziono umowy']);
        exit;
    }

    // Zwracamy wszystkie kolumny (v2 może wziąć co potrzebuje), plus ujednolicone pola
    $data = bridge_contract_row($r, $type);
    $data['_raw'] = $r; // pełny wiersz dla zaawansowanych użytkowników bridge

    echo json_encode($data);
    exit;
}

// ── Akcja: persons — lista osób ───────────────────────────────────────────────
if ($action === 'persons') {
    $user = get_bridge_user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int)($_GET['per_page'] ?? 100)));
    $offset  = ($page - 1) * $perPage;
    $q       = trim($_GET['q'] ?? '');

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[]  = "(full_name LIKE ? OR email LIKE ? OR pesel LIKE ?)";
        $params[] = "%{$q}%"; $params[] = "%{$q}%"; $params[] = "%{$q}%";
    }
    $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $cnt   = db_one("SELECT COUNT(*) AS c FROM persons {$sql}", $params);
        $total = (int)($cnt['c'] ?? 0);
        $rows  = db_all(
            "SELECT id, full_name, email, phone, birth_date, pesel,
                    address_city, nationality, created_at
             FROM persons {$sql} ORDER BY full_name LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
    } catch (\Throwable $e) {
        $total = 0; $rows = [];
    }

    echo json_encode([
        'data' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage,
                   'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1],
    ]);
    exit;
}

// ── Akcja: grants — lista grantów ────────────────────────────────────────────
if ($action === 'grants') {
    $user = get_bridge_user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

    $statusFilter = trim($_GET['status'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;

    $where = []; $params = [];
    if ($statusFilter !== '') {
        // active = nie w zakończony/anulowany; otherwise exact match
        if ($statusFilter === 'active') {
            $where[]  = "status NOT IN ('zakończony','anulowany')";
        } else {
            $where[]  = 'status = ?';
            $params[] = $statusFilter;
        }
    }
    $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $cnt   = db_one("SELECT COUNT(*) AS c FROM grants {$sql}", $params);
        $total = (int)($cnt['c'] ?? 0);
        $rows  = db_all(
            "SELECT id, name, grantor, status, amount, currency,
                    date_start, date_end, created_at
             FROM grants {$sql} ORDER BY date_start DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
    } catch (\Throwable $e) {
        $total = 0; $rows = [];
    }

    echo json_encode([
        'data' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage,
                   'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1],
    ]);
    exit;
}

// ── Akcja: tasks — lista zadań z filtrami ────────────────────────────────────
if ($action === 'tasks') {
    $user = get_bridge_user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

    $userId      = (int)($_GET['user_id'] ?? $user['id']);
    $workspaceId = (int)($_GET['workspace_id'] ?? 0);
    $status      = trim($_GET['status'] ?? '');
    $page        = max(1, (int)($_GET['page'] ?? 1));
    $perPage     = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
    $offset      = ($page - 1) * $perPage;

    $where  = ['t.deleted_at IS NULL', 't.archived_at IS NULL'];
    $params = [];

    if ($userId > 0) {
        $where[]  = 'ta.user_id = ?';
        $params[] = $userId;
    }
    if ($workspaceId > 0) {
        $where[]  = 't.workspace_id = ?';
        $params[] = $workspaceId;
    }
    if ($status !== '') {
        if ($status === 'open') {
            $where[] = "t.status NOT IN ('done','archived')";
        } else {
            $where[]  = 't.status = ?';
            $params[] = $status;
        }
    }

    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    try {
        $cnt   = db_one(
            "SELECT COUNT(*) AS c
             FROM tasks t
             JOIN task_assignments ta ON ta.task_id = t.id
             {$sqlWhere}",
            $params
        );
        $total = (int)($cnt['c'] ?? 0);

        $rows = db_all(
            "SELECT t.id, t.title, t.status, t.priority, t.due_date,
                    t.completed_at, t.created_at,
                    tl.name AS list_name, tw.name AS workspace_name, tw.id AS workspace_id,
                    tl.id AS list_id
             FROM tasks t
             JOIN task_assignments ta ON ta.task_id = t.id
             JOIN task_lists tl       ON tl.id = t.list_id
             JOIN task_workspaces tw  ON tw.id  = t.workspace_id
             {$sqlWhere}
             ORDER BY t.due_date ASC, t.priority DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
    } catch (\Throwable $e) {
        $total = 0; $rows = [];
    }

    echo json_encode([
        'data' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage,
                   'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
                   'user_id' => $userId],
    ]);
    exit;
}

// ── Akcja: sync_task — aktualizacja zadania z v2 → v1 ────────────────────────
if ($action === 'sync_task') {
    $user = get_bridge_user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH'], true)) {
        http_response_code(405);
        echo json_encode(['error' => 'Wymagane POST lub PATCH']);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $taskId = (int)($body['task_id'] ?? 0);
    $status = trim($body['status'] ?? '');

    if ($taskId <= 0 || $status === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Wymagane pola: task_id, status']);
        exit;
    }

    $allowed = ['todo','in_progress','done','archived','blocked','review'];
    if (!in_array($status, $allowed, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Nieprawidłowy status', 'allowed' => $allowed]);
        exit;
    }

    $completedAt = null;
    if ($status === 'done') {
        $completedAt = $body['completed_at'] ?? date('Y-m-d H:i:s');
    }

    try {
        $task = db_one("SELECT id FROM tasks WHERE id = ? AND deleted_at IS NULL", [$taskId]);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Zadanie nie istnieje']);
            exit;
        }

        if ($completedAt !== null) {
            db_run(
                "UPDATE tasks SET status = ?, completed_at = ?, updated_at = datetime('now') WHERE id = ?",
                [$status, $completedAt, $taskId]
            );
        } else {
            db_run(
                "UPDATE tasks SET status = ?, completed_at = NULL, updated_at = datetime('now') WHERE id = ?",
                [$status, $taskId]
            );
        }

        echo json_encode(['ok' => true, 'task_id' => $taskId, 'status' => $status]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Błąd aktualizacji zadania']);
    }
    exit;
}

// ── Akcja: stats — statystyki dashboardu ─────────────────────────────────────
if ($action === 'stats') {
    $user = get_bridge_user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

    $stats = [
        'contracts'     => ['total' => 0, 'active' => 0, 'expiring_30' => 0],
        'persons'       => 0,
        'grants'        => ['total' => 0, 'active' => 0],
        'tasks'         => ['open' => 0, 'done_today' => 0],
        'approvals'     => 0,
        'crm_contacts'  => 0,
    ];

    $today = date('Y-m-d');
    $in30  = date('Y-m-d', strtotime('+30 days'));

    foreach (BRIDGE_CONTRACT_TYPES as $t) {
        if (!bridge_table_exists($t)) continue;
        $endCol = BRIDGE_END_COL[$t] ?? 'data_zakonczenia';
        try {
            $r = db_one("SELECT COUNT(*) AS c FROM umowy_{$t}");
            $stats['contracts']['total'] += (int)($r['c'] ?? 0);

            $r = db_one("SELECT COUNT(*) AS c FROM umowy_{$t} WHERE status IN ('podpisana','w realizacji','obowiązująca')");
            $stats['contracts']['active'] += (int)($r['c'] ?? 0);

            $r = db_one(
                "SELECT COUNT(*) AS c FROM umowy_{$t}
                 WHERE bezterminowa=0 AND {$endCol} BETWEEN ? AND ?
                   AND status NOT IN ('zakończona','anulowana','rozwiązana')",
                [$today, $in30]
            );
            $stats['contracts']['expiring_30'] += (int)($r['c'] ?? 0);
        } catch (\Throwable $e) {}
    }

    try { $stats['persons']      = (int)(db_one("SELECT COUNT(*) AS c FROM persons")['c'] ?? 0); } catch(\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM grants"); $stats['grants']['total'] = (int)($r['c'] ?? 0); } catch(\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM grants WHERE status NOT IN ('zakończony','anulowany')"); $stats['grants']['active'] = (int)($r['c'] ?? 0); } catch(\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM tasks WHERE deleted_at IS NULL AND status NOT IN ('done','archived')"); $stats['tasks']['open'] = (int)($r['c'] ?? 0); } catch(\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM tasks WHERE deleted_at IS NULL AND DATE(completed_at) = DATE('now')"); $stats['tasks']['done_today'] = (int)($r['c'] ?? 0); } catch(\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM approval_requests WHERE status='pending'"); $stats['approvals'] = (int)($r['c'] ?? 0); } catch(\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1"); $stats['crm_contacts'] = (int)($r['c'] ?? 0); } catch(\Throwable $e) {}

    echo json_encode(['stats' => $stats, 'generated_at' => date('Y-m-d H:i:s')]);
    exit;
}

// ── Fallback: nieznana akcja ──────────────────────────────────────────────────
http_response_code(400);
echo json_encode([
    'error'   => "Nieznana akcja: {$action}",
    'actions' => ['auth','user','export_user','contracts','contract','persons','grants','tasks','sync_task','stats'],
]);
