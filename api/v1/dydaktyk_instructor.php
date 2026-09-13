<?php
/**
 * api/v1/dydaktyk_instructor.php
 * REST API panelu prowadzącego (dydaktyka TI) — auth: Bearer token
 * (k30_ti_instructor_api_tokens). Odpowiednik api/v1/kursant_student.php,
 * ale dla konta SZO (users), nie k30_ti_student_accounts.
 *
 * Zakres wyłącznie prowadzącego: każdy endpoint filtruje dane przez
 * k30_ti_instructor_courses()/k30_ti_instructor_owns_*() z includes/karty30.php
 * (te same funkcje, których używa dyd_courses()/dyd_owns_*() w sesyjnym panelu
 * PHP, gdy dyd_is_staff() zwraca false). To API NIGDY nie daje zakresu
 * kierownika (dyd_is_staff()) — funkcje kierownika zostają wyłącznie w
 * klasycznym panelu karty30/ti/dydaktyk/.
 *
 * TOTP jest obowiązkowe dla każdego konta dydaktyka (patrz dyd_require()) —
 * logowanie jest dwuetapowe: action=login (hasło) → action=verify_totp (kod).
 * Zakładanie 2FA od zera (QR) nie jest tu obsługiwane — konto musi mieć
 * TOTP już aktywne (ustawione w klasycznym panelu, totp_gate.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/karty30.php';
require_once __DIR__ . '/../../includes/totp.php';
require_once __DIR__ . '/../../includes/ti_notices.php';
require_once __DIR__ . '/../../karty30/ti/dydaktyk/auth.php'; // dyd_authenticate()/dyd_profile_from_user() — czyste, bez sesji

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

// ── Schema: token tables ───────────────────────────────────────────────────────
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instructor_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_dyd_instr_api_tok ON k30_ti_instructor_api_tokens(token)");

// Token pośredni: hasło już zweryfikowane, czeka na kod TOTP (odpowiednik
// dyd_2fa_stash() z sesji, ale bez sesji — panel jest bezstanowy).
$pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_2fa_pending (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instructor_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

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

/** Wystawia właściwy token API (sesja bezstanowa, TTL 8h / 30 dni z "zapamiętaj mnie"). */
function issue_instructor_token(int $instructorId, bool $remember = false): string {
    global $pdo;
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + ($remember ? 30 * 86400 : 8 * 3600));
    $pdo->prepare("INSERT INTO k30_ti_instructor_api_tokens (instructor_id, token, expires_at) VALUES (?, ?, ?)")
        ->execute([$instructorId, $token, $expires_at]);
    $pdo->prepare("DELETE FROM k30_ti_instructor_api_tokens WHERE instructor_id = ? AND expires_at < datetime('now')")
        ->execute([$instructorId]);
    return $token;
}

/** Token pośredni "hasło OK, czeka na TOTP" — TTL 10 min, jak DYD_2FA_TTL w panelu sesyjnym. */
function issue_2fa_pending_token(int $instructorId): string {
    global $pdo;
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + 600);
    $pdo->prepare("INSERT INTO k30_ti_instructor_2fa_pending (instructor_id, token, expires_at) VALUES (?, ?, ?)")
        ->execute([$instructorId, $token, $expires_at]);
    $pdo->prepare("DELETE FROM k30_ti_instructor_2fa_pending WHERE expires_at < datetime('now')")->execute();
    return $token;
}

function instructor_public_profile(array $u): array {
    return [
        'id'    => (int)$u['id'],
        'name'  => (string)($u['name'] ?? $u['email'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
    ];
}

// ── Krok 1: login hasłem ──────────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $body     = get_body();
    $email    = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if (!$email || !$password) json_err('E-mail i hasło są wymagane.');

    $profile = dyd_authenticate($email, $password);
    if (!$profile) json_err('Nieprawidłowy e-mail lub hasło.', 401);

    $uid  = (int)$profile['user_id'];
    $totp = db_one("SELECT totp_confirmed, totp_secret FROM users WHERE id=?", [$uid]);
    if (!$totp || empty($totp['totp_confirmed']) || empty($totp['totp_secret'])) {
        json_err('To konto nie ma jeszcze skonfigurowanej weryfikacji dwuetapowej (TOTP) — dokończ jej założenie w klasycznym panelu prowadzącego, potem wróć tutaj.', 409);
    }

    $pending = issue_2fa_pending_token($uid);
    json_ok(['totp_required' => true, 'pending_token' => $pending]);
}

// ── Krok 2: kod TOTP (albo kod zapasowy) ──────────────────────────────────────
if ($action === 'verify_totp' && $method === 'POST') {
    $body    = get_body();
    $pending = (string)($body['pending_token'] ?? '');
    $code    = trim((string)($body['code'] ?? ''));
    $remember = (bool)($body['remember'] ?? false);
    if (!$pending || !$code) json_err('Brak tokenu logowania lub kodu.');

    $row = $pdo->prepare("SELECT instructor_id FROM k30_ti_instructor_2fa_pending WHERE token=? AND expires_at > datetime('now')");
    $row->execute([$pending]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_err('Sesja logowania wygasła — zaloguj się ponownie.', 401);

    $uid = (int)$r['instructor_id'];
    $u   = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$uid]);
    if (!$u) json_err('Konto nie istnieje lub zostało dezaktywowane.', 401);

    $ok = TOTP::verify((string)$u['totp_secret'], $code);
    if (!$ok && !empty($u['totp_backup_codes'])) {
        $backup     = json_decode((string)$u['totp_backup_codes'], true) ?? [];
        $normalized = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $code));
        foreach ($backup as $idx => $bc) {
            $bc_norm = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$bc));
            if ($normalized !== '' && hash_equals($bc_norm, $normalized)) {
                array_splice($backup, $idx, 1);
                $pdo->prepare("UPDATE users SET totp_backup_codes=? WHERE id=?")
                    ->execute([json_encode(array_values($backup)), $uid]);
                $ok = true;
                break;
            }
        }
    }
    if (!$ok) json_err('Nieprawidłowy kod.', 401);

    $pdo->prepare("DELETE FROM k30_ti_instructor_2fa_pending WHERE token=?")->execute([$pending]);
    $token = issue_instructor_token($uid, $remember);
    json_ok(['token' => $token, 'instructor' => instructor_public_profile($u)]);
}

// ── Token authentication ──────────────────────────────────────────────────────
function verify_instructor_token(): ?int {
    global $pdo;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!str_starts_with($auth, 'Bearer ')) return null;
    $token = substr($auth, 7);
    $stmt  = $pdo->prepare("SELECT instructor_id FROM k30_ti_instructor_api_tokens WHERE token=? AND expires_at > datetime('now') LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['instructor_id'] : null;
}

if ($action === 'logout' && $method === 'POST') {
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    if ($token) $pdo->prepare("DELETE FROM k30_ti_instructor_api_tokens WHERE token = ?")->execute([$token]);
    json_ok(null, 'Wylogowano.');
}

$instructor_id = verify_instructor_token();
if (!$instructor_id) json_err('Nieautoryzowany dostęp.', 401);

// ── Route dispatch ────────────────────────────────────────────────────────────
switch ($action) {
    // ── pulpit ─────────────────────────────────────────────────────────────────
    case 'dashboard': {
        $u = db_one("SELECT id, name, email FROM users WHERE id=?", [$instructor_id]);
        if (!$u) json_err('Konto nie istnieje.', 404);

        // Wyłącznie WŁASNE kursy (główny lub coProwadzący) — nigdy zakres
        // kierownika (odpowiednik dyd_courses($uid) dla !dyd_is_staff()).
        $courses    = k30_ti_instructor_courses($instructor_id, false);
        $course_ids = array_map(fn($c) => (int)$c['id'], $courses);

        $today = [];
        $upcoming = [];
        $pending_cancel = 0;
        if ($course_ids) {
            $ph = implode(',', array_fill(0, count($course_ids), '?'));
            $today = db_all(
                "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.status, s.topic,
                        s.meeting_url, c.name AS course_name, c.id AS course_id, c.default_meeting_url,
                        (SELECT COUNT(*) FROM k30_ti_attendance a
                         WHERE a.session_id=s.id AND COALESCE(a.cancelled,0)=0 AND COALESCE(a.cancel_pending,0)=0) AS enrolled
                 FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
                 WHERE s.course_id IN ($ph) AND s.lesson_date = date('now','localtime')
                 ORDER BY s.time_from",
                $course_ids
            );
            $upcoming = db_all(
                "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.status, s.topic,
                        s.meeting_url, c.name AS course_name, c.id AS course_id, c.default_meeting_url
                 FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
                 WHERE s.course_id IN ($ph) AND s.lesson_date > date('now','localtime')
                   AND s.lesson_date <= date('now','localtime','+7 days') AND s.status != 'cancelled'
                 ORDER BY s.lesson_date, s.time_from LIMIT 10",
                $course_ids
            );
            $pending_cancel = (int)(db_one(
                "SELECT COUNT(*) n FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
                 WHERE s.course_id IN ($ph) AND a.cancel_pending=1",
                $course_ids
            )['n'] ?? 0);
        }

        $notices_unread = ti_notices_unread_count_instructor($instructor_id);

        $msg_unread_total = 0;
        if ($course_ids) {
            $msg_unread_total = (int)(db_one(
                "SELECT COALESCE(SUM(CASE WHEN m.sender='student' AND m.is_read=0 THEN 1 ELSE 0 END),0) n
                 FROM k30_ti_messages m
                 JOIN k30_ti_student_accounts a ON a.id=m.student_id
                 WHERE a.id IN (
                     SELECT DISTINCT sa.id FROM k30_ti_student_accounts sa
                     JOIN k30_ti_enrollments e ON e.client_id=sa.client_id
                     WHERE e.course_id IN (" . implode(',', $course_ids) . ") AND e.status='active'
                 )",
            )['n'] ?? 0);
        }

        $attendance_month = [];
        if ($course_ids) {
            $ph2 = implode(',', array_fill(0, count($course_ids), '?'));
            $attendance_month = db_all(
                "SELECT c.id AS course_id, c.name AS course_name,
                        COUNT(DISTINCT s.id) AS lessons,
                        SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=1 THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=0 THEN 1 ELSE 0 END) AS absent
                   FROM k30_ti_sessions s
                   JOIN k30_ti_courses c ON c.id = s.course_id
                   LEFT JOIN k30_ti_attendance a ON a.session_id = s.id
                  WHERE s.course_id IN ($ph2)
                    AND s.status IN ('held','individual_change')
                    AND strftime('%Y-%m', s.lesson_date) = strftime('%Y-%m','now','localtime')
                  GROUP BY c.id
                  ORDER BY c.name COLLATE NOCASE",
                $course_ids
            );
        }

        json_ok([
            'instructor'        => instructor_public_profile($u),
            'courses_count'     => count($courses),
            'today'             => $today,
            'upcoming'          => $upcoming,
            'pending_cancel'    => $pending_cancel,
            'notices_unread'    => $notices_unread,
            'msg_unread_total'  => $msg_unread_total,
            'attendance_month'  => $attendance_month,
        ]);
    }

    default:
        json_err('Nieznana akcja.', 404);
}
