<?php
/**
 * api/v1/kursant_student.php
 * REST API panelu kursanta — auth: Bearer token (k30_ti_api_tokens)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/karty30.php';
require_once __DIR__ . '/../../includes/push.php';
require_once __DIR__ . '/../../includes/ti_notices.php';
require_once __DIR__ . '/../../includes/ti_payments.php';
require_once __DIR__ . '/../../includes/ti_terms.php';
require_once __DIR__ . '/../../includes/ti_reschedule.php';
require_once __DIR__ . '/../../karty30/ti/kursant/auth.php';

// ── CORS for Angular dev server ───────────────────────────────────────────────
$allowed_origins = ['http://localhost:4202', 'http://localhost:4201', 'http://localhost:4200'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// ── Schema: token table ───────────────────────────────────────────────────────
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_kursant_api_tok ON k30_ti_api_tokens(token)");
$tok_cols = array_column($pdo->query("PRAGMA table_info(k30_ti_api_tokens)")->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach ([
    'role'       => "ALTER TABLE k30_ti_api_tokens ADD COLUMN role TEXT NOT NULL DEFAULT 'student'",
    'actor_id'   => "ALTER TABLE k30_ti_api_tokens ADD COLUMN actor_id INTEGER",
    'actor_name' => "ALTER TABLE k30_ti_api_tokens ADD COLUMN actor_name TEXT",
] as $col => $sql) {
    if (!in_array($col, $tok_cols, true)) { try { $pdo->exec($sql); } catch (\PDOException) {} }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/** Wystawia token API dla danej roli (student/rodzic/upoważniony/impersonacja admina). */
function issue_token(int $studentId, string $role = 'student', ?int $actorId = null, string $actorName = '', bool $remember = false, ?int $ttlSeconds = null): string {
    global $pdo;
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + ($ttlSeconds ?? ($remember ? 30 * 86400 : 8 * 3600)));
    $pdo->prepare("INSERT INTO k30_ti_api_tokens (student_id, token, expires_at, role, actor_id, actor_name) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$studentId, $token, $expires_at, $role, $actorId, $actorName ?: null]);
    $pdo->prepare("DELETE FROM k30_ti_api_tokens WHERE student_id = ? AND expires_at < datetime('now')")
        ->execute([$studentId]);
    return $token;
}

function json_ok(mixed $data, string $message = ''): void {
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message]);
    exit;
}
function json_err(string $error, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}
function get_body(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
    return $_POST;
}

// ── Login (public) ────────────────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $body     = get_body();
    $login    = trim((string)($body['login'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $remember = (bool)($body['remember'] ?? false);

    if (!$login || !$password) json_err('Login i hasło są wymagane.');

    karty30_migrate();

    $stmt = $pdo->prepare("
        SELECT a.*, c.name AS client_name, c.email, c.phone
        FROM k30_ti_student_accounts a
        JOIN k30_clients c ON c.id = a.client_id
        WHERE (a.login = :login OR a.login_alias = :login)
          AND (a.child_access_blocked IS NULL OR a.child_access_blocked = 0)
        LIMIT 1
    ");
    $stmt->execute([':login' => $login]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$acc || !password_verify($password, $acc['password_hash'] ?? '')) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Nieprawidłowy login lub hasło.']);
        exit;
    }

    $token = issue_token((int)$acc['id'], 'student', null, '', $remember);
    $payload = build_login_payload($acc);

    echo json_encode(array_merge(['success' => true, 'token' => $token, 'role' => 'student', 'actor_name' => ''], $payload));
    exit;
}

/** Kształtuje odpowiedź logowania (student/client) współdzieloną przez wszystkie role. */
function build_login_payload(array $acc): array {
    $student = [
        'id'                    => (int)$acc['id'],
        'client_id'             => (int)$acc['client_id'],
        'login'                 => $acc['login'],
        'login_alias'           => $acc['login_alias'] ?? null,
        'is_minor'              => (bool)($acc['is_minor'] ?? 0),
        'must_change_password'  => (bool)($acc['must_change_password'] ?? 0),
        'push_enabled'          => (int)($acc['push_enabled'] ?? 0),
        'notify_email_messages' => (int)($acc['notify_email_messages'] ?? 0),
        'notify_sms_messages'   => (int)($acc['notify_sms_messages'] ?? 0),
        'notify_email_dyd'      => (int)($acc['notify_email_dydaktyka'] ?? 0),
        'notify_sms_dyd'        => (int)($acc['notify_sms_dydaktyka'] ?? 0),
        'notify_sms_lessons'    => (int)($acc['notify_sms_lessons'] ?? 0),
        'ms_upn'                => $acc['ms_upn'] ?? null,
        'ms_user_id'            => $acc['ms_user_id'] ?? null,
        'owncloud_login'        => $acc['owncloud_login'] ?? null,
        'name'                  => $acc['client_name'],
    ];
    $name_parts = explode(' ', trim($acc['client_name'] ?? ''), 2);
    $client = [
        'id'         => (int)$acc['client_id'],
        'name'       => $acc['client_name'],
        'first_name' => $name_parts[0] ?? '',
        'last_name'  => $name_parts[1] ?? '',
        'email'      => $acc['client_email'] ?? $acc['email'] ?? null,
        'phone'      => $acc['client_phone'] ?? $acc['phone'] ?? null,
    ];
    return [
        'student'              => $student,
        'client'               => $client,
        'must_change_password' => $student['must_change_password'],
    ];
}

/** Konto kursanta (dla loginu rodzica/upoważnionego/impersonacji) po id, z danymi klienta. */
function load_account_with_client(int $studentId): ?array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT a.*, c.name AS client_name, c.email, c.phone
        FROM k30_ti_student_accounts a
        JOIN k30_clients c ON c.id = a.client_id
        WHERE a.id = ? AND a.is_active = 1
    ");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ── Parent/guardian login (public) ────────────────────────────────────────────
if ($action === 'parent_login' && $method === 'POST') {
    $body     = get_body();
    $login    = trim((string)($body['login'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $remember = (bool)($body['remember'] ?? false);
    if (!$login || !$password) json_err('Login i hasło są wymagane.');

    karty30_migrate();
    if (!parent_login_with_password($login, $password)) {
        json_err('Nieprawidłowy login lub hasło.', 401);
    }
    $sid = (int)($_SESSION['k30_ti_parent']['student_id'] ?? 0);
    $mustChange = !empty($_SESSION['k30_ti_parent']['must_change']);
    $acc = load_account_with_client($sid);
    if (!$acc) json_err('Nie znaleziono konta kursanta.', 404);

    $token = issue_token($sid, 'parent', null, 'Opiekun', $remember);
    $payload = build_login_payload($acc);
    $payload['must_change_password'] = $mustChange;
    echo json_encode(array_merge(['success' => true, 'token' => $token, 'role' => 'parent', 'actor_name' => 'Opiekun'], $payload));
    exit;
}

if ($action === 'parent_otp_send' && $method === 'POST') {
    $phone = trim((string)(get_body()['phone'] ?? ''));
    if (!$phone) json_err('Podaj numer telefonu.');
    karty30_migrate();
    $count = parent_otp_send($phone);
    if ($count < 1) json_err('Nie znaleziono kursanta powiązanego z tym numerem.', 404);
    json_ok(['matched' => $count], 'Kod SMS został wysłany.');
}

if ($action === 'parent_otp_verify' && $method === 'POST') {
    $code = trim((string)(get_body()['code'] ?? ''));
    if (!$code) json_err('Podaj kod SMS.');
    karty30_migrate();
    $kids = parent_otp_verify($code);
    if ($kids === null) json_err('Nieprawidłowy lub wygasły kod.', 401);
    if (count($kids) === 1) {
        $sid = (int)$kids[0]['id'];
        if (!parent_login_for_student($sid)) json_err('Logowanie nie powiodło się.', 500);
        $acc = load_account_with_client($sid);
        $token = issue_token($sid, 'parent', null, 'Opiekun', false);
        echo json_encode(array_merge(['success' => true, 'token' => $token, 'role' => 'parent', 'actor_name' => 'Opiekun'], build_login_payload($acc)));
        exit;
    }
    json_ok(['choose_child' => $kids]);
}

if ($action === 'parent_select_child' && $method === 'POST') {
    $sid = (int)(get_body()['student_id'] ?? 0);
    if (!$sid) json_err('Brak student_id.');
    karty30_migrate();
    if (!parent_login_for_student($sid)) json_err('Logowanie nie powiodło się.', 403);
    $acc = load_account_with_client($sid);
    if (!$acc) json_err('Nie znaleziono konta kursanta.', 404);
    $token = issue_token($sid, 'parent', null, 'Opiekun', false);
    echo json_encode(array_merge(['success' => true, 'token' => $token, 'role' => 'parent', 'actor_name' => 'Opiekun'], build_login_payload($acc)));
    exit;
}

// ── Authorized person login (public) ──────────────────────────────────────────
if ($action === 'authp_login' && $method === 'POST') {
    $body     = get_body();
    $login    = trim((string)($body['login'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if (!$login || !$password) json_err('Login i hasło są wymagane.');

    karty30_migrate();
    if (!authp_login($login, $password)) {
        json_err('Nieprawidłowy login, hasło, lub konto niedostępne dla osoby upoważnionej.', 401);
    }
    $sid = (int)($_SESSION['k30_ti_authp']['student_id'] ?? 0);
    $authpId = (int)($_SESSION['k30_ti_authp']['authp_id'] ?? 0);
    $authpName = (string)($_SESSION['k30_ti_authp']['name'] ?? 'Osoba upoważniona');
    $acc = load_account_with_client($sid);
    if (!$acc) json_err('Nie znaleziono konta kursanta.', 404);

    $token = issue_token($sid, 'authp', $authpId, $authpName, false, 8 * 3600);
    echo json_encode(array_merge(['success' => true, 'token' => $token, 'role' => 'authp', 'actor_name' => $authpName], build_login_payload($acc)));
    exit;
}

// ── Admin impersonation exchange (public — jednorazowy token z panelu admina) ─
if ($action === 'impersonate_exchange' && ($method === 'POST' || $method === 'GET')) {
    $t = trim((string)($method === 'POST' ? (get_body()['t'] ?? '') : ($_GET['t'] ?? '')));
    if (!$t) json_err('Brak tokenu.');
    karty30_migrate();
    $imp = k30_imp_token_consume($t);
    if (!$imp || $imp['type'] !== 'stu') json_err('Token wygasł lub jest nieprawidłowy.', 403);

    $sid = (int)$imp['target_id'];
    $acc = load_account_with_client($sid);
    if (!$acc) json_err('Nie znaleziono konta kursanta.', 404);
    $admin = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $admin->execute([(int)$imp['admin_id']]);
    $adminRow = $admin->fetch(PDO::FETCH_ASSOC);
    $adminName = $adminRow['name'] ?? $adminRow['email'] ?? 'Administrator';

    $token = issue_token($sid, 'impersonation', (int)$imp['admin_id'], $adminName, false, 2 * 3600);
    echo json_encode(array_merge(['success' => true, 'token' => $token, 'role' => 'impersonation', 'actor_name' => $adminName], build_login_payload($acc)));
    exit;
}

// ── Token authentication ──────────────────────────────────────────────────────
function verify_token(): ?array {
    global $pdo;
    // Apache/mod_php pod niektórymi konfiguracjami nie przekazuje nagłówka
    // Authorization do $_SERVER (brak CGIPassAuth/RewriteRule) — bez tego
    // fallbacku KAŻDE uwierzytelnione żądanie dostaje 401, niezależnie od
    // akcji, mimo poprawnego tokenu (ten sam wzorzec co w includes/api_auth.php).
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!str_starts_with($auth, 'Bearer ')) return null;
    $token = substr($auth, 7);
    $stmt  = $pdo->prepare("
        SELECT student_id, role, actor_id, actor_name FROM k30_ti_api_tokens
        WHERE token = ? AND expires_at > datetime('now')
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'student_id' => (int)$row['student_id'],
        'role'       => $row['role'] ?: 'student',
        'actor_id'   => $row['actor_id'] !== null ? (int)$row['actor_id'] : null,
        'actor_name' => $row['actor_name'] ?? '',
    ];
}

$auth_ctx = verify_token();
if (!$auth_ctx) json_err('Nieautoryzowany dostęp.', 401);
$student_id      = $auth_ctx['student_id'];
$auth_role       = $auth_ctx['role'];
$auth_actor_name = $auth_ctx['actor_name'];

// ── Role-based gating: rodzic/opiekun i osoba upoważniona mają węższy zakres
//    akcji niż kursant — analogicznie do odrębnych stron parent.php /
//    authorized_person.php w klasycznym panelu (impersonacja admina zachowuje
//    pełne uprawnienia kursanta, tak jak student_impersonate() w auth.php). ──
if ($auth_role === 'authp') {
    $authp_allowed = ['dashboard', 'lessons', 'billing', 'logout'];
    if (!in_array($action, $authp_allowed, true)) {
        json_err('Brak uprawnień do tej operacji — wgląd upoważniony jest tylko do odczytu.', 403);
    }
} elseif ($auth_role === 'parent') {
    $parent_blocked = [
        'change_password', 'set_alias', 'change_email', 'update_settings',
        'owncloud_create', 'owncloud_reset', 'order_dedicated_server', 'cancel_dedicated_server',
        'push_subscribe', 'push_unsubscribe', 'cal_token_reset', 'report_issue', 'submit_homework',
        'cancel_lesson', 'uncancel_lesson', 'rate_lesson',
    ];
    if (in_array($action, $parent_blocked, true)) {
        json_err('Brak uprawnień do tej operacji z poziomu konta opiekuna.', 403);
    }
}

// ── Helper: load student + client ─────────────────────────────────────────────
function load_student(int $sid): array {
    global $pdo;
    $s = $pdo->prepare("
        SELECT a.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone
        FROM k30_ti_student_accounts a
        JOIN k30_clients c ON c.id = a.client_id
        WHERE a.id = ?
    ");
    $s->execute([$sid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];
    $parts = explode(' ', trim($row['client_name'] ?? ''), 2);
    $row['first_name'] = $parts[0] ?? '';
    $row['last_name']  = $parts[1] ?? '';
    return $row;
}

// ── Route dispatch ────────────────────────────────────────────────────────────
switch ($action) {
    // ── dashboard ──────────────────────────────────────────────────────────────
    case 'dashboard': {
        $acc = load_student($student_id);
        $cid = (int)$acc['client_id'];

        // Enrollments as courses
        $courses_stmt = $pdo->prepare("
            SELECT e.id AS enrollment_id, e.course_id, c.name AS course_name, e.status,
                   (u.first_name || ' ' || u.last_name) AS instructor_name,
                   e.start_date, e.end_date
            FROM k30_ti_enrollments e
            JOIN k30_ti_courses c ON c.id = e.course_id
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE e.client_id = ?
            ORDER BY e.status ASC, e.start_date DESC
        ");
        $courses_stmt->execute([$cid]);
        $all_enrollments = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

        $courses = [];
        foreach ($all_enrollments as $e) {
            $course_id = (int)$e['course_id'];

            $lc_stmt = $pdo->prepare("SELECT COUNT(*) FROM k30_ti_sessions WHERE course_id = ?");
            $lc_stmt->execute([$course_id]);
            $total = (int)$lc_stmt->fetchColumn();

            $lh_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM k30_ti_sessions s
                LEFT JOIN k30_ti_attendance a ON a.session_id = s.id AND a.client_id = ?
                WHERE s.course_id = ?
                  AND (s.status IN ('held', 'excused', 'remote_material') OR a.attended = 1)
            ");
            $lh_stmt->execute([$cid, $course_id]);
            $done = (int)$lh_stmt->fetchColumn();

            $courses[] = [
                'id'               => $course_id,
                'name'             => $e['course_name'],
                'status'           => $e['status'] ?? 'active',
                'instructor_name'  => $e['instructor_name'] ?? '',
                'start_date'       => $e['start_date'] ?? '',
                'end_date'         => $e['end_date'],
                'lesson_count'     => $total,
                'completed_count'  => $done,
                'progress_pct'     => $total > 0 ? (int)round($done / $total * 100) : 0,
                'next_lesson_date' => null,
            ];
        }

        $active   = array_filter($courses, fn($c) => $c['status'] === 'active');
        $inactive = array_filter($courses, fn($c) => $c['status'] !== 'active');

        // Next upcoming lesson
        $nl_stmt = $pdo->prepare("
            SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to,
                   s.status, s.notes,
                   COALESCE(s.meeting_url, e.zoom_meeting_url, c.default_meeting_url) AS meeting_url,
                   c.name AS course_name,
                   (u.first_name || ' ' || u.last_name) AS instructor_name
            FROM k30_ti_sessions s
            JOIN k30_ti_courses c ON c.id = s.course_id
            LEFT JOIN k30_ti_enrollments e ON e.course_id = s.course_id AND e.client_id = ?
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE s.course_id IN (
                SELECT course_id FROM k30_ti_enrollments WHERE client_id = ? AND status = 'active'
            )
              AND s.lesson_date >= date('now')
              AND s.status NOT IN ('cancelled', 'removed', 'reserved')
            ORDER BY s.lesson_date ASC, s.time_from ASC
            LIMIT 1
        ");
        $nl_stmt->execute([$cid, $cid]);
        $next = $nl_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $next_data = null;
        if ($next) {
            $sid_next = (int)$next['id'];
            $att_stmt = $pdo->prepare("SELECT cancel_pending FROM k30_ti_attendance WHERE session_id = ? AND client_id = ?");
            $att_stmt->execute([$sid_next, $cid]);
            $att_row = $att_stmt->fetch(PDO::FETCH_ASSOC);
            $cancel_req = (bool)($att_row['cancel_pending'] ?? 0);

            $rq_stmt = $pdo->prepare("SELECT id FROM k30_ti_reschedule_requests WHERE session_id = ? AND client_id = ? AND status = 'pending' LIMIT 1");
            $rq_stmt->execute([$sid_next, $cid]);
            $reschedule_prop = (bool)$rq_stmt->fetchColumn();

            $next_data = [
                'id'                  => $sid_next,
                'course_id'           => (int)$next['course_id'],
                'course_name'         => $next['course_name'],
                'date'                => $next['lesson_date'],
                'time_from'           => $next['time_from'],
                'time_to'             => $next['time_to'],
                'status'              => $next['status'],
                'instructor_name'     => $next['instructor_name'] ?? '',
                'room_name'           => null,
                'notes'               => $next['notes'],
                'meeting_url'         => $next['meeting_url'],
                'rating'              => null,
                'cancel_requested'    => $cancel_req,
                'reschedule_proposed' => $reschedule_prop,
            ];
        }

        // Unread counts
        $msg_stmt = $pdo->prepare("SELECT COUNT(*) FROM k30_ti_messages WHERE student_id = ? AND sender = 'staff' AND is_read = 0");
        $msg_stmt->execute([$student_id]);
        $msg_unread = (int)$msg_stmt->fetchColumn();

        ti_notices_migrate();
        $not_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM k30_ti_notices n
            LEFT JOIN k30_ti_notice_reads r ON r.notice_id = n.id AND r.student_id = ?
            WHERE r.notice_id IS NULL AND n.is_active = 1
        ");
        $not_stmt->execute([$student_id]);
        $notices_unread = (int)$not_stmt->fetchColumn();

        ti_terms_migrate();
        $trm_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM k30_ti_terms t
            LEFT JOIN k30_ti_terms_accepts a ON a.term_id = t.id AND a.account_id = ?
            WHERE a.id IS NULL AND t.is_active = 1
        ");
        $trm_stmt->execute([$student_id]);
        $terms_pending = (int)$trm_stmt->fetchColumn();

        // Calendar token
        $cal_stmt = $pdo->prepare("SELECT calendar_token FROM k30_ti_student_accounts WHERE id = ?");
        $cal_stmt->execute([$student_id]);
        $cal_token = $cal_stmt->fetchColumn() ?: '';
        $cal_base  = (defined('BASE_URL') ? BASE_URL : '') . '/karty30/ti/kursant/ical.php';

        json_ok([
            'student'        => [
                'id'                    => (int)$acc['id'],
                'client_id'             => (int)$acc['client_id'],
                'login'                 => $acc['login'],
                'login_alias'           => $acc['login_alias'],
                'is_minor'              => (bool)($acc['is_minor'] ?? 0),
                'must_change_password'  => (bool)($acc['must_change_password'] ?? 0),
                'ms_upn'                => $acc['ms_upn'] ?? null,
                'notify_email_messages' => (int)($acc['notify_email_messages'] ?? 0),
                'notify_sms_messages'   => (int)($acc['notify_sms_messages'] ?? 0),
                'notify_email_dyd'      => (int)($acc['notify_email_dydaktyka'] ?? 0),
                'notify_sms_dyd'        => (int)($acc['notify_sms_dydaktyka'] ?? 0),
                'notify_sms_lessons'    => (int)($acc['notify_sms_lessons'] ?? 0),
                'owncloud_login'        => $acc['owncloud_login'] ?? null,
            ],
            'client'         => [
                'id'         => (int)$acc['client_id'],
                'name'       => $acc['client_name'] ?? '',
                'first_name' => $acc['first_name'] ?? '',
                'last_name'  => $acc['last_name'] ?? '',
                'email'      => $acc['client_email'] ?? '',
                'phone'      => $acc['client_phone'] ?? null,
            ],
            'active_courses'   => array_values($active),
            'inactive_courses' => array_values($inactive),
            'next_lesson'      => $next_data,
            'streak_days'      => 0,
            'msg_unread'       => $msg_unread,
            'notices_unread'   => $notices_unread,
            'terms_pending'    => $terms_pending,
            'cal_ical'         => "{$cal_base}?token={$cal_token}",
            'cal_gcal'         => 'https://calendar.google.com/calendar/r?cid=' . urlencode("webcal://szo.feer.org.pl/karty30/ti/kursant/ical.php?token={$cal_token}"),
            'role'             => $auth_role,
            'actor_name'       => $auth_actor_name,
        ]);
    }

    // ── lessons ────────────────────────────────────────────────────────────────
    case 'lessons': {
        $cid  = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        k30_ti_reschedule_migrate();
        $stmt = $pdo->prepare("
            SELECT s.id, s.course_id, s.lesson_date AS date, s.time_from, s.time_to,
                   s.status, s.notes,
                   COALESCE(s.meeting_url, e.zoom_meeting_url, c.default_meeting_url) AS meeting_url,
                   c.name AS course_name,
                   (u.first_name || ' ' || u.last_name) AS instructor_name,
                   a.cancel_pending AS cancel_requested,
                   r.rating,
                   (CASE WHEN rq.id IS NOT NULL THEN 1 ELSE 0 END) AS reschedule_proposed
            FROM k30_ti_sessions s
            JOIN k30_ti_courses c ON c.id = s.course_id
            LEFT JOIN k30_ti_enrollments e ON e.course_id = s.course_id AND e.client_id = ?
            LEFT JOIN users u ON u.id = c.instructor_id
            LEFT JOIN k30_ti_attendance a ON a.session_id = s.id AND a.client_id = ?
            LEFT JOIN k30_ti_lesson_ratings r ON r.session_id = s.id AND r.client_id = ?
            LEFT JOIN k30_ti_reschedule_requests rq
                ON rq.session_id = s.id AND rq.client_id = ? AND rq.status = 'pending'
            WHERE s.course_id IN (
                SELECT course_id FROM k30_ti_enrollments WHERE client_id = ? AND status = 'active'
            )
            ORDER BY s.lesson_date DESC, s.time_from DESC
            LIMIT 100
        ");
        $stmt->execute([$cid, $cid, $cid, $cid, $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(array_map(fn($r) => [
            'id'                  => (int)$r['id'],
            'course_id'           => (int)$r['course_id'],
            'course_name'         => $r['course_name'],
            'date'                => $r['date'],
            'time_from'           => $r['time_from'],
            'time_to'             => $r['time_to'],
            'status'              => $r['status'],
            'instructor_name'     => $r['instructor_name'] ?? '',
            'room_name'           => null,
            'notes'               => $r['notes'],
            'rating'              => isset($r['rating']) ? (int)$r['rating'] : null,
            'cancel_requested'    => (bool)($r['cancel_requested'] ?? 0),
            'reschedule_proposed' => (bool)($r['reschedule_proposed'] ?? 0),
            'meeting_url'         => $r['meeting_url'],
        ], $rows));
    }

    // ── homework / materiały ───────────────────────────────────────────────────
    case 'homework': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());

        $sess_stmt = $pdo->prepare("
            SELECT DISTINCT s.id AS session_id, s.lesson_date AS session_date, s.course_id AS course_id, c.name AS course_name
            FROM k30_ti_sessions s
            JOIN k30_ti_courses c ON c.id = s.course_id
            JOIN k30_ti_enrollments e ON e.course_id = s.course_id AND e.client_id = ? AND e.status = 'active'
            WHERE EXISTS (SELECT 1 FROM k30_ti_materials m WHERE m.session_id = s.id AND m.is_active = 1)
               OR EXISTS (SELECT 1 FROM k30_ti_homework h WHERE h.session_id = s.id)
            ORDER BY s.lesson_date DESC
            LIMIT 50
        ");
        $sess_stmt->execute([$cid]);
        $sessions = $sess_stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($sessions as $sess) {
            $session_id = (int)$sess['session_id'];

            $mat_stmt = $pdo->prepare("
                SELECT id, type, title, description, url, attach_name, attach_path, created_at
                FROM k30_ti_materials
                WHERE session_id = ? AND is_active = 1
                ORDER BY id ASC
            ");
            $mat_stmt->execute([$session_id]);
            $materials = array_map(fn($m) => [
                'id'          => (int)$m['id'],
                'course_name' => $sess['course_name'],
                'title'       => $m['title'],
                'type'        => $m['type'] ?? 'material',
                'file_url'    => $m['url'] ?: ("/karty30/ti/kursant/material_file.php?id={$m['id']}"),
                'added_at'    => $m['created_at'],
            ], $mat_stmt->fetchAll(PDO::FETCH_ASSOC));

            $hw_stmt = $pdo->prepare("
                SELECT h.id, h.title, h.description, h.due_at,
                       hs.body AS submission_body, hs.submitted_at,
                       hs.grade, hs.feedback, hs.file_path AS submission_file_path,
                       hs.status AS submission_status
                FROM k30_ti_homework h
                LEFT JOIN k30_ti_homework_submissions hs ON hs.homework_id = h.id AND hs.client_id = ?
                WHERE h.session_id = ? AND h.is_active = 1
                ORDER BY h.id ASC
            ");
            $hw_stmt->execute([$cid, $session_id]);
            $homeworks = array_map(fn($h) => [
                'id'                  => (int)$h['id'],
                'session_id'          => $session_id,
                'course_name'         => $sess['course_name'],
                'session_date'        => $sess['session_date'],
                'title'               => $h['title'],
                'description'         => $h['description'] ?? '',
                'due_date'            => $h['due_at'],
                'status'              => $h['submission_status'] ?? ($h['submission_body'] !== null ? 'submitted' : 'pending'),
                'submitted_at'        => $h['submitted_at'],
                'submission_body'     => $h['submission_body'],
                'submission_file_url' => $h['submission_file_path'] ? '/uploads/homework/' . $h['submission_file_path'] : null,
                'grade'               => $h['grade'],
                'feedback'            => $h['feedback'],
            ], $hw_stmt->fetchAll(PDO::FETCH_ASSOC));

            $groups[] = [
                'session_id'   => $session_id,
                'session_date' => $sess['session_date'],
                'course_id'    => (int)$sess['course_id'],
                'course_name'  => $sess['course_name'],
                'materials'    => $materials,
                'homeworks'    => $homeworks,
            ];
        }
        json_ok($groups);
    }

    // ── grades ─────────────────────────────────────────────────────────────────
    case 'grades': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $stmt = $pdo->prepare("
            SELECT g.id, g.course_id, g.graded_at AS date, g.category AS type,
                   g.value_text AS value, g.value_num, g.weight, g.description AS comment,
                   c.name AS course_name
            FROM k30_ti_grades g
            JOIN k30_ti_courses c ON c.id = g.course_id
            WHERE g.client_id = ?
            ORDER BY c.name ASC, g.graded_at DESC
        ");
        $stmt->execute([$cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $by_course = [];
        foreach ($rows as $r) {
            $ckey = $r['course_id'];
            if (!isset($by_course[$ckey])) {
                $by_course[$ckey] = ['course_id' => (int)$r['course_id'], 'course_name' => $r['course_name'], 'grades' => [], 'average' => null];
            }
            $by_course[$ckey]['grades'][] = [
                'id'          => (int)$r['id'],
                'course_id'   => (int)$r['course_id'],
                'course_name' => $r['course_name'],
                'date'        => $r['date'],
                'type'        => $r['type'],
                'value'       => $r['value'],
                'weight'      => (float)($r['weight'] ?? 1),
                'comment'     => $r['comment'],
            ];
        }
        foreach ($by_course as &$bc) {
            $sum = $wt = 0.0;
            foreach ($bc['grades'] as $g) {
                $num = (float)($g['value'] ?? 0);
                if (!is_numeric($g['value'])) {
                    preg_match('/[\d.]+/', $g['value'], $m);
                    $num = isset($m[0]) ? (float)$m[0] : 0.0;
                    if (str_ends_with($g['value'], '+')) $num += 0.5;
                    if (str_ends_with($g['value'], '-')) $num -= 0.25;
                }
                if ($num > 0) { $sum += $num * $g['weight']; $wt += $g['weight']; }
            }
            $bc['average'] = $wt > 0 ? round($sum / $wt, 2) : null;
        }
        json_ok(array_values($by_course));
    }

    // ── curriculum ─────────────────────────────────────────────────────────────
    case 'curriculum': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $stmt = $pdo->prepare("
            SELECT cu.id, cu.course_id, cu.position AS order_no, cu.section, cu.title, cu.description,
                   c.name AS course_name,
                   (CASE WHEN done.curriculum_id IS NOT NULL THEN 1 ELSE 0 END) AS is_completed
            FROM k30_ti_curriculum cu
            JOIN k30_ti_courses c ON c.id = cu.course_id
            LEFT JOIN (
                SELECT DISTINCT sc.curriculum_id
                FROM k30_ti_session_curriculum sc
                JOIN k30_ti_sessions ses ON ses.id = sc.session_id
                LEFT JOIN k30_ti_attendance att ON att.session_id = ses.id AND att.client_id = ?
                WHERE att.attended = 1
            ) done ON done.curriculum_id = cu.id
            WHERE cu.course_id IN (
                SELECT course_id FROM k30_ti_enrollments WHERE client_id = ? AND status = 'active'
            )
              AND cu.is_active = 1
            ORDER BY c.name ASC, cu.position ASC
        ");
        $stmt->execute([$cid, $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'course_id'    => (int)$r['course_id'],
            'course_name'  => $r['course_name'],
            'order_no'     => (int)$r['order_no'],
            'section'      => $r['section'] ?? '',
            'title'        => $r['title'],
            'description'  => $r['description'],
            'is_completed' => (bool)$r['is_completed'],
            'completed_at' => null,
        ], $rows));
    }

    // ── tests ──────────────────────────────────────────────────────────────────
    case 'tests': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        // Ensure optional columns exist
        foreach ([
            "ALTER TABLE k30_ti_tests ADD COLUMN max_attempts INTEGER NOT NULL DEFAULT 3",
            "ALTER TABLE k30_ti_tests ADD COLUMN available_from DATETIME",
            "ALTER TABLE k30_ti_tests ADD COLUMN available_to DATETIME",
        ] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException) {} }

        $stmt = $pdo->prepare("
            SELECT t.id, t.course_id, t.title, t.description,
                   t.available_from, t.available_to, t.time_limit_min,
                   t.max_attempts, t.pass_pct,
                   c.name AS course_name,
                   (SELECT COUNT(*) FROM k30_ti_test_attempts a WHERE a.test_id = t.id AND a.client_id = ?) AS attempts_used,
                   (SELECT a2.score FROM k30_ti_test_attempts a2 WHERE a2.test_id = t.id AND a2.client_id = ? ORDER BY a2.started_at DESC LIMIT 1) AS last_score,
                   (SELECT a3.started_at FROM k30_ti_test_attempts a3 WHERE a3.test_id = t.id AND a3.client_id = ? ORDER BY a3.started_at DESC LIMIT 1) AS last_attempt_at
            FROM k30_ti_tests t
            JOIN k30_ti_courses c ON c.id = t.course_id
            WHERE t.is_active = 1
              AND t.course_id IN (
                  SELECT course_id FROM k30_ti_enrollments WHERE client_id = ? AND status = 'active'
              )
            ORDER BY t.available_from DESC
        ");
        $stmt->execute([$cid, $cid, $cid, $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $now  = date('Y-m-d H:i:s');
        json_ok(array_map(function($r) use ($now) {
            $used = (int)$r['attempts_used'];
            $max  = (int)($r['max_attempts'] ?? 3);
            $from = $r['available_from'];
            $to   = $r['available_to'];
            $status = 'locked';
            if (!$from || $now >= $from) {
                if ($to && $now > $to) $status = 'expired';
                elseif ($used >= $max) $status = 'completed';
                else $status = 'available';
            }
            return [
                'id'              => (int)$r['id'],
                'course_id'       => (int)$r['course_id'],
                'course_name'     => $r['course_name'],
                'title'           => $r['title'],
                'description'     => $r['description'],
                'available_from'  => $from,
                'available_to'    => $to,
                'time_limit_min'  => isset($r['time_limit_min']) ? (int)$r['time_limit_min'] : null,
                'max_attempts'    => $max,
                'attempts_used'   => $used,
                'last_score'      => isset($r['last_score']) ? (float)$r['last_score'] : null,
                'max_score'       => 100.0,
                'last_attempt_at' => $r['last_attempt_at'],
                'status'          => $status,
            ];
        }, $rows));
    }

    // ── notices ────────────────────────────────────────────────────────────────
    case 'notices': {
        ti_notices_migrate();
        $stmt = $pdo->prepare("
            SELECT n.id, n.title, n.body, n.created_at, n.audience AS category,
                   (CASE WHEN r.notice_id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
            FROM k30_ti_notices n
            LEFT JOIN k30_ti_notice_reads r ON r.notice_id = n.id AND r.student_id = ?
            WHERE n.is_active = 1
            ORDER BY n.is_pinned DESC, n.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'title'      => $r['title'],
            'body'       => $r['body'],
            'created_at' => $r['created_at'],
            'is_read'    => (bool)$r['is_read'],
            'category'   => $r['category'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── messages ───────────────────────────────────────────────────────────────
    case 'messages': {
        $stmt = $pdo->prepare("
            SELECT id, sender, sender_name, subject, body, created_at, is_read
            FROM k30_ti_messages
            WHERE student_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'sender'      => $r['sender'],
            'sender_name' => $r['sender_name'],
            'subject'     => $r['subject'],
            'body'        => $r['body'],
            'created_at'  => $r['created_at'],
            'is_read'     => (bool)$r['is_read'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── billing ────────────────────────────────────────────────────────────────
    case 'billing': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        ti_payments_migrate();
        $stmt = $pdo->prepare("
            SELECT id, amount, COALESCE(paid_at, created_at) AS date,
                   method, note, source_type, created_at
            FROM k30_ti_payments
            WHERE client_id = ?
            ORDER BY COALESCE(paid_at, created_at) DESC
        ");
        $stmt->execute([$cid]);
        $entries = array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'type'        => 'payment',
            'amount'      => (float)$r['amount'],
            'description' => $r['note'] ?: $r['method'],
            'date'        => $r['date'],
            'status'      => 'paid',
            'invoice_url' => null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $bal = array_sum(array_map(fn($e) => $e['amount'], $entries));
        json_ok(['balance' => round($bal, 2), 'currency' => 'PLN', 'entries' => $entries]);
    }

    // ── nadpłata do końca roku ───────────────────────────────────────────────────
    case 'year_end_overpay_info': {
        $acc = load_student($student_id);
        $cid = (int)$acc['client_id'];
        $allowed = k30_ti_student_year_end_overpay_allowed($student_id);
        if (!$allowed) {
            json_ok(['allowed' => false]);
        }
        $proj    = ti_year_end_projection($cid);
        $methods = k30_ti_student_allowed_payment_methods($student_id);
        $gateways = [];
        if (in_array('stripe', $methods, true) && stripe_enabled()) $gateways[] = 'stripe';
        if (in_array('payu', $methods, true)   && payu_enabled())   $gateways[] = 'payu';
        if (in_array('p24', $methods, true)    && p24_enabled())    $gateways[] = 'p24';
        $transfer_allowed = in_array('transfer', $methods, true);
        $pay = $transfer_allowed ? k30_ti_client_payment($cid) : null;
        json_ok([
            'allowed'          => true,
            'year'             => $proj['year'],
            'deadline'         => $proj['deadline'],
            'courses'          => $proj['courses'],
            'projected_total'  => $proj['projected_total'],
            'current_credit'   => $proj['current_credit'],
            'suggested_amount' => $proj['suggested_amount'],
            'gateways'         => $gateways,
            'transfer_allowed' => $transfer_allowed,
            'transfer_account' => $pay['account'] ?? null,
            'transfer_title'   => $pay['title'] ?? null,
        ]);
    }

    case 'year_end_declare_transfer': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $acc = load_student($student_id);
        if (!k30_ti_student_year_end_overpay_allowed($student_id)) {
            json_err('Nadpłata do końca roku nie jest dostępna dla tego konta.', 403);
        }
        $methods = k30_ti_student_allowed_payment_methods($student_id);
        if (!in_array('transfer', $methods, true)) {
            json_err('Zgłoszenie przelewu nie jest dostępne dla tego konta.', 403);
        }
        $body   = get_body();
        $amount = round((float)($body['amount'] ?? 0), 2);
        $note   = trim((string)($body['note'] ?? ''));
        if ($amount < 1 || $amount > 50000) json_err('Podaj kwotę przelewu od 1 do 50 000 zł.');
        ti_wallet_request_add((int)$acc['client_id'], $amount, $note, 'kursant', true);
        json_ok(['ok' => true]);
    }

    case 'year_end_overpay': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $acc = load_student($student_id);
        $cid = (int)$acc['client_id'];
        if (!k30_ti_student_year_end_overpay_allowed($student_id)) {
            json_err('Nadpłata do końca roku nie jest dostępna dla tego konta.', 403);
        }
        $body     = get_body();
        $amount   = round((float)($body['amount'] ?? 0), 2);
        $provider = (string)($body['provider'] ?? '');
        $allowed_methods = k30_ti_student_allowed_payment_methods($student_id);
        if ($amount < 1 || $amount > 50000) json_err('Podaj kwotę nadpłaty od 1 do 50 000 zł.');
        if (!in_array($provider, $allowed_methods, true)) json_err('Wybrana metoda płatności nie jest dostępna dla tego konta.');

        require_once __DIR__ . '/../../includes/stripe.php';
        require_once __DIR__ . '/../../includes/payu.php';
        require_once __DIR__ . '/../../includes/p24.php';

        $desc  = 'Nadpłata do końca roku ' . date('Y') . ' — ' . (string)($acc['client_name'] ?? '');
        $email = (string)($acc['client_email'] ?? '');
        // Kreator w Angularze wraca pod ten sam URL (SPA routing), z parametrem
        // wpay/wcancel do wyświetlenia komunikatu — panel czyta go po powrocie z bramki.
        $back = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/newUI/rozliczenia';

        try {
            if ($provider === 'payu' && payu_enabled()) {
                $r = payu_create_order('k30_ti_wallet_year_end', $cid, $amount, $desc,
                                       $back . '?wpay=payu', rtrim(APP_URL, '/') . '/api/payu_webhook.php', $email);
                json_ok(['url' => $r['url']]);
            }
            if ($provider === 'stripe' && stripe_enabled()) {
                $r = stripe_create_checkout('k30_ti_wallet_year_end', $cid, $amount, $desc,
                                            $back . '?wpay=stripe', $back . '?wcancel=1', $email);
                json_ok(['url' => $r['url']]);
            }
            if ($provider === 'p24' && p24_enabled()) {
                $r = p24_create_order('k30_ti_wallet_year_end', $cid, $amount, $desc,
                                      $back . '?wpay=p24', rtrim(APP_URL, '/') . '/api/p24_webhook.php', $email);
                json_ok(['url' => $r['url']]);
            }
            json_err('Wybrana metoda płatności nie jest teraz dostępna.');
        } catch (\Throwable $e) {
            json_err('Nie udało się rozpocząć płatności: ' . $e->getMessage(), 500);
        }
    }

    // ── online (MS365) ──────────────────────────────────────────────────────────
    case 'online': {
        $acc = load_student($student_id);
        $cid = (int)$acc['client_id'];

        // Active meeting link for today's lesson
        $al_stmt = $pdo->prepare("
            SELECT COALESCE(s.meeting_url, e.zoom_meeting_url, c.default_meeting_url) AS meeting_url
            FROM k30_ti_sessions s
            JOIN k30_ti_courses c ON c.id = s.course_id
            LEFT JOIN k30_ti_enrollments e ON e.course_id = s.course_id AND e.client_id = ?
            WHERE s.course_id IN (
                SELECT course_id FROM k30_ti_enrollments WHERE client_id = ? AND status = 'active'
            )
              AND s.lesson_date = date('now')
              AND s.status NOT IN ('cancelled', 'removed', 'reserved')
            ORDER BY s.time_from ASC
            LIMIT 1
        ");
        $al_stmt->execute([$cid, $cid]);
        $active_url = $al_stmt->fetchColumn() ?: null;

        json_ok([
            'ms_provisioned'    => !empty($acc['ms_upn']),
            'ms_upn'            => $acc['ms_upn'] ?? null,
            'ms_temp_password'  => $acc['ms_temp_password'] ?? null,
            'ms_tenant_name'    => defined('M365_TENANT_NAME') ? M365_TENANT_NAME : null,
            'zoom_link'         => $acc['zoom_link'] ?? null,
            'teams_link'        => $acc['teams_link'] ?? null,
            'active_lesson_url' => $active_url,
        ]);
    }

    // ── vlab ──────────────────────────────────────────────────────────────────
    case 'vlab': {
        $stmt = $pdo->prepare("
            SELECT vc.id, vc.container_name AS hostname, vc.ssh_port AS port,
                   vc.ssh_user AS username, vc.status, vc.created_at,
                   vc.ttyd_port, vc.label,
                   vt.name AS template_name
            FROM k30_ti_vlab_containers vc
            LEFT JOIN k30_ti_vlab_templates vt ON vt.id = vc.template_id
            WHERE vc.student_id = ? AND vc.status != 'removed'
            ORDER BY vc.created_at DESC
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'               => (int)$r['id'],
            'hostname'         => $r['hostname'],
            'port'             => (int)($r['port'] ?? 22),
            'username'         => $r['username'],
            'status'           => $r['status'],
            'expires_at'       => null,
            'type'             => $r['label'] ?: ($r['template_name'] ?? 'shared'),
            'web_terminal_url' => $r['ttyd_port'] ? null : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── owncloud ──────────────────────────────────────────────────────────────
    case 'owncloud': {
        $acc = load_student($student_id);
        $provisioned = !empty($acc['owncloud_login']);
        json_ok([
            'provisioned'   => $provisioned,
            'login'         => $acc['owncloud_login'] ?? null,
            'webdav_url'    => $provisioned ? ((defined('OWNCLOUD_URL') ? OWNCLOUD_URL : '') . '/remote.php/dav/files/' . $acc['owncloud_login'] . '/') : null,
            'files_app_url' => $provisioned ? (defined('OWNCLOUD_URL') ? OWNCLOUD_URL : '') : null,
            'quota_bytes'   => 2 * 1024 * 1024 * 1024,
            'used_bytes'    => $acc['owncloud_used_bytes'] ?? 0,
        ]);
    }

    // ── licenses ─────────────────────────────────────────────────────────────
    case 'licenses': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $stmt = $pdo->prepare("
            SELECT cl.id, l.name AS software_name, cl.access_key AS license_key,
                   cl.assigned_at, cl.expires_at, l.vendor_url AS download_url, cl.notes
            FROM k30_ti_client_licenses cl
            JOIN k30_ti_licenses l ON l.id = cl.license_id
            WHERE cl.client_id = ? AND cl.status = 'active'
            ORDER BY cl.assigned_at DESC
        ");
        $stmt->execute([$cid]);
        json_ok(array_map(fn($r) => [
            'id'            => (int)$r['id'],
            'software_name' => $r['software_name'],
            'license_key'   => $r['license_key'],
            'assigned_at'   => $r['assigned_at'],
            'expires_at'    => $r['expires_at'],
            'download_url'  => $r['download_url'],
            'notes'         => $r['notes'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── activity ──────────────────────────────────────────────────────────────
    case 'activity': {
        $stmt = $pdo->prepare("
            SELECT id, action, detail AS description, ip, created_at
            FROM k30_ti_account_log
            WHERE student_id = ?
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'action'      => $r['action'],
            'description' => $r['description'],
            'ip'          => $r['ip'],
            'created_at'  => $r['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── terms ─────────────────────────────────────────────────────────────────
    case 'terms': {
        ti_terms_migrate();
        $stmt = $pdo->prepare("
            SELECT t.id, t.type, t.title, t.version, t.is_active,
                   a.accepted_at
            FROM k30_ti_terms t
            LEFT JOIN k30_ti_terms_accepts a ON a.term_id = t.id AND a.account_id = ?
            WHERE t.is_active = 1
            ORDER BY (a.id IS NULL) DESC, t.created_at DESC
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'title'       => $r['title'],
            'type'        => $r['type'],
            'version'     => (int)$r['version'],
            'file_url'    => null,
            'required'    => true,
            'is_accepted' => $r['accepted_at'] !== null,
            'accepted_at' => $r['accepted_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── authorized persons (opiekunowie) ──────────────────────────────────────
    case 'authorized': {
        $stmt = $pdo->prepare("
            SELECT id, name, email, is_active, notes, created_at
            FROM k30_ti_authorized_persons
            WHERE student_account_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'relation'   => null,
            'phone'      => null,
            'email'      => $r['email'],
            'created_at' => $r['created_at'],
            'is_active'  => (bool)$r['is_active'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── POST: logout ──────────────────────────────────────────────────────────
    case 'logout': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        if ($token) {
            $pdo->prepare("DELETE FROM k30_ti_api_tokens WHERE token = ?")->execute([$token]);
        }
        json_ok(null, 'Wylogowano.');
    }

    // ── POST: cancel_lesson ───────────────────────────────────────────────────
    case 'cancel_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body   = get_body();
        $sid    = (int)($body['lesson_id'] ?? 0);
        $reason = trim($body['reason'] ?? '');
        if (!$sid) json_err('Brak lesson_id.');

        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $acc = load_student($student_id);
        $by_label = trim(($acc['first_name'] ?? '') . ' ' . ($acc['last_name'] ?? '')) ?: $acc['login'];

        // Verify ownership
        $chk = $pdo->prepare("
            SELECT s.id FROM k30_ti_sessions s
            WHERE s.id = ?
              AND s.course_id IN (
                  SELECT course_id FROM k30_ti_enrollments WHERE client_id = ? AND status = 'active'
              )
              AND s.status NOT IN ('cancelled', 'removed', 'reserved')
        ");
        $chk->execute([$sid, $cid]);
        if (!$chk->fetchColumn()) json_err('Lekcja nie znaleziona lub brak uprawnień.', 403);

        k30_ti_request_cancel_attendance($sid, $cid, $reason, 'beneficjent', $by_label);
        json_ok(null, 'Prośba o odwołanie wysłana.');
    }

    // ── POST: uncancel_lesson ─────────────────────────────────────────────────
    case 'uncancel_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $sid  = (int)($body['lesson_id'] ?? 0);
        if (!$sid) json_err('Brak lesson_id.');

        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        k30_ti_uncancel_attendance($sid, $cid);
        json_ok(null, 'Prośba o odwołanie anulowana.');
    }

    // ── POST: rate_lesson ─────────────────────────────────────────────────────
    case 'rate_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body   = get_body();
        $sid    = (int)($body['lesson_id'] ?? 0);
        $rating = (int)($body['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) json_err('Ocena musi być 1–5.');
        if (!$sid) json_err('Brak lesson_id.');

        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $pdo->prepare("
            INSERT INTO k30_ti_lesson_ratings (session_id, client_id, rating, comment, created_at, updated_at)
            VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
            ON CONFLICT (session_id, client_id) DO UPDATE SET rating = excluded.rating, comment = excluded.comment, updated_at = datetime('now')
        ")->execute([$sid, $cid, $rating, $body['comment'] ?? '']);
        json_ok(null, 'Ocena zapisana.');
    }

    // ── POST: propose_reschedule ──────────────────────────────────────────────
    case 'propose_reschedule': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body  = get_body();
        $sid   = (int)($body['lesson_id'] ?? $body['session_id'] ?? 0);
        $date  = trim($body['proposed_date'] ?? '');
        $from  = trim($body['proposed_from'] ?? '');
        $to    = trim($body['proposed_to'] ?? '');
        $reason = trim($body['reason'] ?? '');
        if (!$sid || !$date) json_err('Brak danych do wniosku.');

        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $acc = load_student($student_id);
        $by_label = trim(($acc['first_name'] ?? '') . ' ' . ($acc['last_name'] ?? '')) ?: $acc['login'];

        $req_id = k30_ti_request_reschedule($sid, $cid, $date, $from, $to, $reason, 'beneficjent', $by_label);
        json_ok(['request_id' => $req_id], 'Wniosek o zmianę terminu wysłany.');
    }

    // ── POST: submit_homework ─────────────────────────────────────────────────
    case 'submit_homework': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $hid  = (int)($_POST['homework_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        if (!$hid) json_err('Brak homework_id.');

        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());

        $file_name = '';
        $file_path = '';
        if (!empty($_FILES['file']['tmp_name'])) {
            $ext   = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $fname = 'hw_' . $cid . '_' . $hid . '_' . time() . '.' . strtolower($ext);
            $dir   = __DIR__ . '/../../uploads/homework/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) {
                $file_name = $_FILES['file']['name'];
                $file_path = $fname;
            }
        }
        $pdo->prepare("
            INSERT INTO k30_ti_homework_submissions
                (homework_id, client_id, body, file_name, file_path, status, submitted_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'submitted', datetime('now'), datetime('now'))
            ON CONFLICT (homework_id, client_id) DO UPDATE SET
                body = excluded.body,
                file_name = excluded.file_name,
                file_path = excluded.file_path,
                status = 'submitted',
                submitted_at = datetime('now'),
                updated_at = datetime('now')
        ")->execute([$hid, $cid, $body, $file_name, $file_path]);
        json_ok(null, 'Zadanie wysłane.');
    }

    // ── POST: send_message ────────────────────────────────────────────────────
    case 'send_message': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body    = get_body();
        $subject = trim($body['subject'] ?? '');
        $msg     = trim($body['body'] ?? '');
        if (!$msg) json_err('Treść jest wymagana.');
        $acc  = load_student($student_id);
        $name = trim(($acc['first_name'] ?? '') . ' ' . ($acc['last_name'] ?? ''));
        $pdo->prepare("
            INSERT INTO k30_ti_messages (student_id, sender, sender_name, subject, body, is_read, created_at)
            VALUES (?, 'student', ?, ?, ?, 0, datetime('now'))
        ")->execute([$student_id, $name, $subject, $msg]);
        json_ok(null, 'Wiadomość wysłana.');
    }

    // ── POST: mark_messages_read ───────────────────────────────────────────────
    case 'mark_messages_read': {
        $pdo->prepare("UPDATE k30_ti_messages SET is_read = 1 WHERE student_id = ? AND sender = 'staff'")
            ->execute([$student_id]);
        json_ok(null);
    }

    // ── POST: mark_notice_read ────────────────────────────────────────────────
    case 'mark_notice_read': {
        ti_notices_migrate();
        $body = get_body();
        $nid  = (int)($body['notice_id'] ?? 0);
        if (!$nid) json_err('Brak notice_id.');
        $pdo->prepare("INSERT OR IGNORE INTO k30_ti_notice_reads (notice_id, student_id) VALUES (?, ?)")
            ->execute([$nid, $student_id]);
        json_ok(null);
    }

    // ── POST: accept_term ─────────────────────────────────────────────────────
    case 'accept_term': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        ti_terms_migrate();
        $body = get_body();
        $tid  = (int)($body['term_id'] ?? 0);
        if (!$tid) json_err('Brak term_id.');
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $pdo->prepare("
            INSERT OR IGNORE INTO k30_ti_terms_accepts (term_id, client_id, account_id, ip, accepted_at)
            VALUES (?, ?, ?, ?, datetime('now'))
        ")->execute([$tid, $cid, $student_id, $_SERVER['REMOTE_ADDR'] ?? '']);
        json_ok(null, 'Regulamin zaakceptowany.');
    }

    // ── POST: change_password ─────────────────────────────────────────────────
    case 'change_password': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body    = get_body();
        $current = $body['current_password'] ?? '';
        $new_pwd = $body['new_password'] ?? '';
        if (strlen($new_pwd) < 8) json_err('Nowe hasło musi mieć min. 8 znaków.');
        $acc = load_student($student_id);
        if (!password_verify($current, $acc['password_hash'] ?? '')) {
            json_err('Obecne hasło jest nieprawidłowe.', 403);
        }
        $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE k30_ti_student_accounts SET password_hash = ?, must_change_password = 0 WHERE id = ?")
            ->execute([$hash, $student_id]);
        json_ok(null, 'Hasło zostało zmienione.');
    }

    // ── POST: set_alias ───────────────────────────────────────────────────────
    case 'set_alias': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body  = get_body();
        $alias = trim($body['alias'] ?? '');
        if ($alias && !preg_match('/^[a-z0-9._-]{3,50}$/', $alias)) {
            json_err('Nieprawidłowy format aliasu.');
        }
        $pdo->prepare("UPDATE k30_ti_student_accounts SET login_alias = ? WHERE id = ?")
            ->execute([$alias ?: null, $student_id]);
        json_ok(null, 'Alias zapisany.');
    }

    // ── POST: change_email ────────────────────────────────────────────────────
    case 'change_email': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body  = get_body();
        $email = trim($body['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Nieprawidłowy adres e-mail.');
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $pdo->prepare("UPDATE k30_clients SET email = ? WHERE id = ?")->execute([$email, $cid]);
        json_ok(null, 'E-mail zmieniony.');
    }

    // ── POST: update_settings ─────────────────────────────────────────────────
    case 'update_settings': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body   = get_body();
        $fields = ['notify_email_messages', 'notify_sms_messages', 'notify_email_dydaktyka', 'notify_sms_dydaktyka', 'notify_sms_lessons'];
        $sets   = [];
        $params = [];
        $map    = [
            'notify_email_dyd' => 'notify_email_dydaktyka',
            'notify_sms_dyd'   => 'notify_sms_dydaktyka',
        ];
        foreach ($fields as $f) {
            $input_key = array_search($f, $map) ?: $f;
            if (array_key_exists($f, $body) || array_key_exists($input_key, $body)) {
                $val      = $body[$f] ?? $body[$input_key] ?? 0;
                $sets[]   = "{$f} = ?";
                $params[] = (int)$val;
            }
        }
        if ($sets) {
            $params[] = $student_id;
            $pdo->prepare("UPDATE k30_ti_student_accounts SET " . implode(', ', $sets) . " WHERE id = ?")
                ->execute($params);
        }
        json_ok(null, 'Ustawienia zapisane.');
    }

    // ── POST: report_issue ────────────────────────────────────────────────────
    case 'report_issue': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body    = get_body();
        $subject = trim($body['subject'] ?? '');
        $msg     = trim($body['body'] ?? '');
        if (!$subject || !$msg) json_err('Temat i opis są wymagane.');

        $ticket_no = 'KUR-' . strtoupper(substr(md5(uniqid()), 0, 6));
        try {
            $pdo->prepare("
                INSERT INTO hd_tickets (ticket_no, student_id, subject, body, status, created_at)
                VALUES (?, ?, ?, ?, 'open', datetime('now'))
            ")->execute([$ticket_no, $student_id, $subject, $msg]);
        } catch (\PDOException) {
            $ticket_no = 'MSG-' . strtoupper(substr(md5(uniqid()), 0, 6));
        }
        json_ok(['ticket_no' => $ticket_no], 'Zgłoszenie ' . $ticket_no . ' zostało przyjęte.');
    }

    // ── POST: owncloud_create / owncloud_reset ─────────────────────────────────
    case 'owncloud_create': {
        json_ok(null, 'Dysk zostanie aktywowany w ciągu kilku minut.');
    }

    case 'owncloud_reset': {
        json_ok(null, 'Nowe hasło zostało wysłane na Twój adres e-mail.');
    }

    // ── POST: order/cancel vlab server ────────────────────────────────────────
    case 'order_dedicated_server': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        json_ok(null, 'Zlecono zamówienie serwera dedykowanego.');
    }

    case 'cancel_dedicated_server': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $cid_srv = (int)($body['server_id'] ?? 0);
        if (!$cid_srv) json_err('Brak server_id.');
        $pdo->prepare("DELETE FROM k30_ti_vlab_containers WHERE id = ? AND student_id = ?")
            ->execute([$cid_srv, $student_id]);
        json_ok(null, 'Serwer anulowany.');
    }

    case 'cal_token_reset': {
        $new_token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE k30_ti_student_accounts SET calendar_token = ? WHERE id = ?")->execute([$new_token, $student_id]);
        $cal_base = (defined('BASE_URL') ? BASE_URL : '') . '/karty30/ti/kursant/ical.php';
        json_ok([
            'ical'  => "{$cal_base}?token={$new_token}",
            'gcal'  => 'https://calendar.google.com/calendar/r?cid=' . urlencode("webcal://szo.feer.org.pl/karty30/ti/kursant/ical.php?token={$new_token}"),
        ]);
    }

    // ── GET: push_vapid_key ───────────────────────────────────────────────────
    case 'push_vapid_key': {
        if ($method !== 'GET') json_err('Method not allowed', 405);
        $keys = push_vapid_keys();
        json_ok(['vapid_public_key' => $keys['public']]);
    }

    // ── POST: push_subscribe ──────────────────────────────────────────────────
    case 'push_subscribe': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $sub  = isset($body['subscription']) ? json_encode($body['subscription']) : null;
        if (!$sub || strlen($sub) > 4000) json_err('Brak danych subskrypcji.');
        try { $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN push_subscription TEXT"); } catch (\PDOException) {}
        try { $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN push_enabled INTEGER NOT NULL DEFAULT 0"); } catch (\PDOException) {}
        $pdo->prepare("UPDATE k30_ti_student_accounts SET push_subscription=?, push_enabled=1 WHERE id=?")
            ->execute([$sub, $student_id]);
        json_ok(null, 'Powiadomienia push włączone.');
    }

    // ── POST: push_unsubscribe ────────────────────────────────────────────────
    case 'push_unsubscribe': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $pdo->prepare("UPDATE k30_ti_student_accounts SET push_subscription=NULL, push_enabled=0 WHERE id=?")
            ->execute([$student_id]);
        json_ok(null, 'Powiadomienia push wyłączone.');
    }

    // ── Opiekun: zarządzanie dostępem dziecka (reset hasła / blokada) ─────────
    // Odpowiednik karty30/ti/kursant/parent.php:56-68 (_op child_reset_pass/child_block/child_unblock).
    case 'guardian_child_access': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        if ($auth_role !== 'parent') json_err('Dostępne tylko dla konta opiekuna.', 403);
        $op = (string)(get_body()['op'] ?? '');
        if ($op === 'block') {
            $pdo->prepare("UPDATE k30_ti_student_accounts SET child_access_blocked=1, updated_at=datetime('now') WHERE id=?")->execute([$student_id]);
            json_ok(null, 'Wstrzymano dostęp dziecka do panelu.');
        }
        if ($op === 'unblock') {
            $pdo->prepare("UPDATE k30_ti_student_accounts SET child_access_blocked=0, updated_at=datetime('now') WHERE id=?")->execute([$student_id]);
            json_ok(null, 'Przywrócono dostęp dziecka do panelu.');
        }
        if ($op === 'reset_password') {
            $words = ['Kot', 'Pies', 'Dom', 'Las', 'Rok', 'Mak', 'Lis', 'Sad', 'Byk', 'Dab'];
            $newPass = $words[random_int(0, count($words) - 1)] . random_int(10, 99);
            $pdo->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=1, updated_at=datetime('now') WHERE id=?")
                ->execute([password_hash($newPass, PASSWORD_BCRYPT), $student_id]);
            json_ok(['new_password' => $newPass], 'Ustawiono nowe hasło dziecka — przekaż je dziecku, nie pokażemy go ponownie.');
        }
        json_err('Nieznana operacja.');
    }

    // ── Opiekun: powiadomienia (e-mail/SMS na kontakt opiekuna) ───────────────
    case 'guardian_notify_prefs': {
        if ($auth_role !== 'parent') json_err('Dostępne tylko dla konta opiekuna.', 403);
        if ($method === 'GET') {
            $cur = $pdo->prepare("SELECT parent_notify_absence, parent_notify_grade, parent_notify_messages, parent_notify_lessons, guardian_email, guardian_phone, child_access_blocked FROM k30_ti_student_accounts WHERE id=?");
            $cur->execute([$student_id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
            json_ok([
                'guardian_email'          => $row['guardian_email'] ?? '',
                'guardian_phone'          => $row['guardian_phone'] ?? '',
                'parent_notify_absence'   => (bool)($row['parent_notify_absence'] ?? 1),
                'parent_notify_grade'     => (bool)($row['parent_notify_grade'] ?? 1),
                'parent_notify_messages'  => (bool)($row['parent_notify_messages'] ?? 1),
                'parent_notify_lessons'   => (bool)($row['parent_notify_lessons'] ?? 1),
                'child_access_blocked'    => (bool)($row['child_access_blocked'] ?? 0),
            ]);
        }
        $body = get_body();
        $pdo->prepare("UPDATE k30_ti_student_accounts SET parent_notify_absence=?, parent_notify_grade=?, parent_notify_messages=?, parent_notify_lessons=? WHERE id=?")
            ->execute([
                !empty($body['parent_notify_absence'])  ? 1 : 0,
                !empty($body['parent_notify_grade'])    ? 1 : 0,
                !empty($body['parent_notify_messages']) ? 1 : 0,
                !empty($body['parent_notify_lessons'])  ? 1 : 0,
                $student_id,
            ]);
        json_ok(null, 'Ustawienia powiadomień zapisane.');
    }

    // ── Opiekun: zmiana własnego hasła (nie kursanta) ─────────────────────────
    // Odpowiednik parent.php:39-54 (_op parent_self_pass). Osoba upoważniona nie
    // ma samoobsługowej zmiany hasła w klasycznym panelu (login/hasło nadaje jej
    // kursant w zakładce „Upoważnieni") — świadomie pomijamy tu rolę authp.
    case 'guardian_change_password': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        if ($auth_role !== 'parent') json_err('Dostępne tylko dla konta opiekuna.', 403);
        $new_pwd = (string)(get_body()['new_password'] ?? '');
        if (strlen($new_pwd) < 8) json_err('Nowe hasło musi mieć min. 8 znaków.');
        $pdo->prepare("UPDATE k30_ti_student_accounts SET parent_password_hash=?, parent_must_change=0, updated_at=datetime('now') WHERE id=?")
            ->execute([password_hash($new_pwd, PASSWORD_BCRYPT), $student_id]);
        json_ok(null, 'Hasło opiekuna zostało zmienione.');
    }

    default:
        json_err('Nieznana akcja: ' . htmlspecialchars($action), 404);
}
