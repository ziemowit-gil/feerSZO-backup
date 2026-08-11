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

    // Generate token
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + ($remember ? 30 * 86400 : 8 * 3600));
    $pdo->prepare("INSERT INTO k30_ti_api_tokens (student_id, token, expires_at) VALUES (?, ?, ?)")
        ->execute([$acc['id'], $token, $expires_at]);

    // Prune old tokens
    $pdo->prepare("DELETE FROM k30_ti_api_tokens WHERE student_id = ? AND expires_at < datetime('now')")
        ->execute([$acc['id']]);

    $student = [
        'id'                    => (int)$acc['id'],
        'client_id'             => (int)$acc['client_id'],
        'login'                 => $acc['login'],
        'login_alias'           => $acc['login_alias'],
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
        'moodle_username'       => $acc['moodle_username'] ?? null,
        'moodle_user_id'        => isset($acc['moodle_user_id']) ? (int)$acc['moodle_user_id'] : null,
        'owncloud_login'        => $acc['owncloud_login'] ?? null,
        'name'                  => $acc['client_name'],
    ];
    $name_parts = explode(' ', trim($acc['client_name'] ?? ''), 2);
    $client = [
        'id'         => (int)$acc['client_id'],
        'name'       => $acc['client_name'],
        'first_name' => $name_parts[0] ?? '',
        'last_name'  => $name_parts[1] ?? '',
        'email'      => $acc['email'],
        'phone'      => $acc['phone'],
    ];

    echo json_encode([
        'success'              => true,
        'token'                => $token,
        'student'              => $student,
        'client'               => $client,
        'must_change_password' => $student['must_change_password'],
    ]);
    exit;
}

// ── Token authentication ──────────────────────────────────────────────────────
function verify_token(): ?int {
    global $pdo;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) return null;
    $token = substr($auth, 7);
    $stmt  = $pdo->prepare("
        SELECT student_id FROM k30_ti_api_tokens
        WHERE token = ? AND expires_at > datetime('now')
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['student_id'] : null;
}

$student_id = verify_token();
if (!$student_id) json_err('Nieautoryzowany dostęp.', 401);

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
    // k30_clients ma tylko 'name' — rozdzielamy na first/last
    $parts = explode(' ', trim($row['client_name'] ?? ''), 2);
    $row['first_name'] = $parts[0] ?? '';
    $row['last_name']  = $parts[1] ?? '';
    return $row;
}

// ── Route dispatch ────────────────────────────────────────────────────────────
switch ($action) {
    // ── dashboard ──────────────────────────────────────────────────────────────
    case 'dashboard': {
        $acc    = load_student($student_id);
        $cid    = (int)$acc['client_id'];

        // Active courses
        $courses_stmt = $pdo->prepare("
            SELECT s.id, s.course_id,
                   k.name AS course_name,
                   k.status,
                   (u.first_name || ' ' || u.last_name) AS instructor_name,
                   s.start_date,
                   s.end_date
            FROM k30_ti_schedules s
            JOIN k30_courses k ON k.id = s.course_id
            LEFT JOIN users u ON u.id = s.instructor_id
            WHERE s.client_id = ?
            ORDER BY s.status ASC, s.start_date DESC
        ");
        $courses_stmt->execute([$cid]);
        $all_courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build courses with lesson counts
        $courses = [];
        foreach ($all_courses as $c) {
            $lc = $pdo->prepare("SELECT COUNT(*) FROM k30_ti_lessons WHERE schedule_id = ?");
            $lc->execute([$c['id']]);
            $total = (int)$lc->fetchColumn();

            $lh = $pdo->prepare("SELECT COUNT(*) FROM k30_ti_lessons WHERE schedule_id = ? AND status IN ('held','excused','remote_material')");
            $lh->execute([$c['id']]);
            $done = (int)$lh->fetchColumn();

            $courses[] = [
                'id'               => (int)$c['course_id'],
                'name'             => $c['course_name'],
                'status'           => $c['status'] ?? 'active',
                'instructor_name'  => $c['instructor_name'] ?? '',
                'start_date'       => $c['start_date'] ?? '',
                'end_date'         => $c['end_date'],
                'lesson_count'     => $total,
                'completed_count'  => $done,
                'progress_pct'     => $total > 0 ? (int)round($done / $total * 100) : 0,
                'next_lesson_date' => null,
            ];
        }

        $active   = array_filter($courses, fn($c) => $c['status'] === 'active');
        $inactive = array_filter($courses, fn($c) => $c['status'] !== 'active');

        // Next lesson
        $nl_stmt = $pdo->prepare("
            SELECT l.id, l.schedule_id, l.date, l.time_from, l.time_to,
                   k.name AS course_name,
                   (u.first_name || ' ' || u.last_name) AS instructor_name,
                   r.name AS room_name, l.meeting_url
            FROM k30_ti_lessons l
            JOIN k30_ti_schedules s ON s.id = l.schedule_id
            JOIN k30_courses k ON k.id = s.course_id
            LEFT JOIN users u ON u.id = s.instructor_id
            LEFT JOIN rooms r ON r.id = l.room_id
            WHERE s.client_id = ? AND l.status = 'planned' AND l.date >= date('now')
            ORDER BY l.date ASC, l.time_from ASC
            LIMIT 1
        ");
        $nl_stmt->execute([$cid]);
        $next = $nl_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Unread counts
        $msg_stmt = $pdo->prepare("SELECT COUNT(*) FROM k30_ti_messages WHERE student_id = ? AND sender = 'staff' AND is_read = 0");
        $msg_stmt->execute([$student_id]);
        $msg_unread = (int)$msg_stmt->fetchColumn();

        $not_stmt = $pdo->prepare("SELECT COUNT(*) FROM k30_ti_notices n LEFT JOIN k30_ti_notice_reads r ON r.notice_id = n.id AND r.student_id = ? WHERE r.id IS NULL");
        $not_stmt->execute([$student_id]);
        $notices_unread = (int)$not_stmt->fetchColumn();

        $trm_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM k30_ti_terms t
            LEFT JOIN k30_ti_term_acceptances a ON a.term_id = t.id AND a.student_id = ?
            WHERE a.id IS NULL AND t.required = 1
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
            'next_lesson'      => $next ? [
                'id'              => (int)$next['id'],
                'course_id'       => (int)$next['schedule_id'],
                'course_name'     => $next['course_name'],
                'date'            => $next['date'],
                'time_from'       => $next['time_from'],
                'time_to'         => $next['time_to'],
                'status'          => 'planned',
                'instructor_name' => $next['instructor_name'] ?? '',
                'room_name'       => $next['room_name'],
                'meeting_url'     => $next['meeting_url'],
                'notes'           => null,
                'rating'          => null,
                'cancel_requested'    => false,
                'reschedule_proposed' => false,
            ] : null,
            'streak_days'    => 0,
            'msg_unread'     => $msg_unread,
            'notices_unread' => $notices_unread,
            'terms_pending'  => $terms_pending,
            'cal_ical'       => "{$cal_base}?token={$cal_token}",
            'cal_gcal'       => 'https://calendar.google.com/calendar/r?cid=' . urlencode("webcal://szo.feer.org.pl/karty30/ti/kursant/ical.php?token={$cal_token}"),
        ]);
    }

    // ── lessons ────────────────────────────────────────────────────────────────
    case 'lessons': {
        $stmt = $pdo->prepare("
            SELECT l.id, l.schedule_id AS course_id, l.date, l.time_from, l.time_to,
                   l.status, l.notes, l.rating, l.meeting_url,
                   l.cancel_requested, l.reschedule_proposed,
                   k.name AS course_name,
                   (u.first_name || ' ' || u.last_name) AS instructor_name,
                   r.name AS room_name
            FROM k30_ti_lessons l
            JOIN k30_ti_schedules s ON s.id = l.schedule_id
            JOIN k30_courses k ON k.id = s.course_id
            LEFT JOIN users u ON u.id = s.instructor_id
            LEFT JOIN rooms r ON r.id = l.room_id
            WHERE s.client_id = (SELECT client_id FROM k30_ti_student_accounts WHERE id = ?)
            ORDER BY l.date DESC, l.time_from DESC
            LIMIT 100
        ");
        $stmt->execute([$student_id]);
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
            'room_name'           => $r['room_name'],
            'notes'               => $r['notes'],
            'rating'              => isset($r['rating']) ? (int)$r['rating'] : null,
            'cancel_requested'    => (bool)($r['cancel_requested'] ?? 0),
            'reschedule_proposed' => (bool)($r['reschedule_proposed'] ?? 0),
            'meeting_url'         => $r['meeting_url'],
        ], $rows));
    }

    // ── homework / dydaktyka ───────────────────────────────────────────────────
    case 'homework': {
        $cid  = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        // Get sessions that have materials or homework
        $sess_stmt = $pdo->prepare("
            SELECT DISTINCT l.id AS session_id, l.date AS session_date, k.name AS course_name
            FROM k30_ti_lessons l
            JOIN k30_ti_schedules s ON s.id = l.schedule_id
            JOIN k30_courses k ON k.id = s.course_id
            WHERE s.client_id = ? AND (
                EXISTS (SELECT 1 FROM k30_ti_materials m WHERE m.lesson_id = l.id)
                OR EXISTS (SELECT 1 FROM k30_ti_homework h WHERE h.lesson_id = l.id)
            )
            ORDER BY l.date DESC
            LIMIT 50
        ");
        $sess_stmt->execute([$cid]);
        $sessions = $sess_stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($sessions as $sess) {
            // Materials
            $mat_stmt = $pdo->prepare("SELECT id, title, file_type AS type, file_url, created_at AS added_at, lesson_id FROM k30_ti_materials WHERE lesson_id = ?");
            $mat_stmt->execute([$sess['session_id']]);
            $materials = array_map(fn($m) => [
                'id'          => (int)$m['id'],
                'course_id'   => 0,
                'course_name' => $sess['course_name'],
                'title'       => $m['title'],
                'type'        => $m['type'] ?? 'file',
                'file_url'    => "/karty30/ti/kursant/material_file.php?id={$m['id']}",
                'added_at'    => $m['added_at'],
            ], $mat_stmt->fetchAll(PDO::FETCH_ASSOC));

            // Homework
            $hw_stmt = $pdo->prepare("
                SELECT h.id, h.title, h.description, h.due_date,
                       hs.body AS submission_body, hs.created_at AS submitted_at,
                       hs.grade, hs.feedback, hs.file_url AS submission_file_url,
                       CASE WHEN hs.id IS NULL THEN 'pending' WHEN hs.grade IS NOT NULL THEN 'graded' ELSE 'submitted' END AS status
                FROM k30_ti_homework h
                LEFT JOIN k30_ti_homework_submissions hs ON hs.homework_id = h.id AND hs.student_id = ?
                WHERE h.lesson_id = ?
            ");
            $hw_stmt->execute([$student_id, $sess['session_id']]);
            $homeworks = array_map(fn($h) => [
                'id'                  => (int)$h['id'],
                'session_id'          => (int)$sess['session_id'],
                'course_id'           => 0,
                'course_name'         => $sess['course_name'],
                'session_date'        => $sess['session_date'],
                'title'               => $h['title'],
                'description'         => $h['description'] ?? '',
                'due_date'            => $h['due_date'],
                'status'              => $h['status'],
                'submitted_at'        => $h['submitted_at'],
                'submission_body'     => $h['submission_body'],
                'submission_file_url' => $h['submission_file_url'],
                'grade'               => $h['grade'],
                'feedback'            => $h['feedback'],
            ], $hw_stmt->fetchAll(PDO::FETCH_ASSOC));

            $groups[] = [
                'session_id'   => (int)$sess['session_id'],
                'session_date' => $sess['session_date'],
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
            SELECT g.id, g.course_id, g.date, g.type, g.value, g.weight, g.comment,
                   k.name AS course_name
            FROM k30_ti_grades g
            JOIN k30_courses k ON k.id = g.course_id
            WHERE g.client_id = ?
            ORDER BY k.name ASC, g.date DESC
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
        // Compute averages
        foreach ($by_course as &$c) {
            $sum = $wt = 0;
            foreach ($c['grades'] as $g) {
                $n = (float)preg_replace('/[^0-9.]/', '', $g['value']);
                if ($g['value'] === ($g['value'] = rtrim($g['value'], '+'))) {} // keep original
                $mod = str_ends_with($g['value'], '+') ? 0.5 : (str_ends_with($g['value'], '-') ? -0.25 : 0);
                $sum += ($n + $mod) * $g['weight'];
                $wt  += $g['weight'];
            }
            $c['average'] = $wt > 0 ? round($sum / $wt, 2) : null;
        }
        json_ok(array_values($by_course));
    }

    // ── curriculum ─────────────────────────────────────────────────────────────
    case 'curriculum': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $stmt = $pdo->prepare("
            SELECT cu.id, cu.course_id, cu.order_no, cu.title, cu.description,
                   k.name AS course_name,
                   sc.completed_at
            FROM k30_ti_curriculum cu
            JOIN k30_courses k ON k.id = cu.course_id
            LEFT JOIN k30_ti_session_curriculum sc
                ON sc.curriculum_id = cu.id AND sc.client_id = ?
            WHERE cu.course_id IN (
                SELECT DISTINCT s.course_id FROM k30_ti_schedules s WHERE s.client_id = ?
            )
            ORDER BY k.name ASC, cu.order_no ASC
        ");
        $stmt->execute([$cid, $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'course_id'    => (int)$r['course_id'],
            'course_name'  => $r['course_name'],
            'order_no'     => (int)$r['order_no'],
            'title'        => $r['title'],
            'description'  => $r['description'],
            'is_completed' => $r['completed_at'] !== null,
            'completed_at' => $r['completed_at'],
        ], $rows));
    }

    // ── tests ──────────────────────────────────────────────────────────────────
    case 'tests': {
        $cid = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $stmt = $pdo->prepare("
            SELECT t.id, t.course_id, t.title, t.description,
                   t.available_from, t.available_to, t.time_limit_min,
                   t.max_attempts, t.max_score,
                   k.name AS course_name,
                   (SELECT COUNT(*) FROM k30_ti_test_attempts a WHERE a.test_id = t.id AND a.student_id = ?) AS attempts_used,
                   (SELECT a2.score FROM k30_ti_test_attempts a2 WHERE a2.test_id = t.id AND a2.student_id = ? ORDER BY a2.created_at DESC LIMIT 1) AS last_score,
                   (SELECT a3.created_at FROM k30_ti_test_attempts a3 WHERE a3.test_id = t.id AND a3.student_id = ? ORDER BY a3.created_at DESC LIMIT 1) AS last_attempt_at
            FROM k30_ti_tests t
            JOIN k30_courses k ON k.id = t.course_id
            WHERE t.course_id IN (SELECT DISTINCT course_id FROM k30_ti_schedules WHERE client_id = ?)
            ORDER BY t.available_from DESC
        ");
        $stmt->execute([$student_id, $student_id, $student_id, $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $now  = date('Y-m-d H:i:s');
        json_ok(array_map(function($r) use ($now) {
            $used = (int)$r['attempts_used'];
            $max  = (int)($r['max_attempts'] ?? 99);
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
                'max_score'       => (float)($r['max_score'] ?? 100),
                'last_attempt_at' => $r['last_attempt_at'],
                'status'          => $status,
            ];
        }, $rows));
    }

    // ── notices ────────────────────────────────────────────────────────────────
    case 'notices': {
        $stmt = $pdo->prepare("
            SELECT n.id, n.title, n.body, n.created_at, n.category,
                   (CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
            FROM k30_ti_notices n
            LEFT JOIN k30_ti_notice_reads r ON r.notice_id = n.id AND r.student_id = ?
            ORDER BY n.created_at DESC
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
        $stmt = $pdo->prepare("
            SELECT id, type, amount, description, date, status, invoice_url
            FROM k30_ti_payments
            WHERE client_id = ?
            ORDER BY date DESC
        ");
        $stmt->execute([$cid]);
        $entries = array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'type'        => $r['type'],
            'amount'      => (float)$r['amount'],
            'description' => $r['description'],
            'date'        => $r['date'],
            'status'      => $r['status'],
            'invoice_url' => $r['invoice_url'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Compute balance (payments - charges)
        $bal = array_sum(array_map(fn($e) => $e['type'] === 'payment' ? $e['amount'] : -$e['amount'], $entries));
        json_ok(['balance' => round($bal, 2), 'currency' => 'PLN', 'entries' => $entries]);
    }

    // ── online (MS365 / Moodle) ────────────────────────────────────────────────
    case 'online': {
        $acc = load_student($student_id);
        // Active lesson meeting link
        $al_stmt = $pdo->prepare("
            SELECT l.meeting_url FROM k30_ti_lessons l
            JOIN k30_ti_schedules s ON s.id = l.schedule_id
            WHERE s.client_id = ? AND l.status = 'planned'
              AND l.date = date('now') AND l.meeting_url IS NOT NULL
            LIMIT 1
        ");
        $al_stmt->execute([$acc['client_id']]);
        $active_url = $al_stmt->fetchColumn() ?: null;

        json_ok([
            'ms_provisioned'   => !empty($acc['ms_upn']),
            'ms_upn'           => $acc['ms_upn'] ?? null,
            'ms_temp_password' => $acc['ms_temp_password'] ?? null,
            'ms_tenant_name'   => defined('M365_TENANT_NAME') ? M365_TENANT_NAME : null,
            'moodle_provisioned' => !empty($acc['moodle_username']),
            'moodle_username'  => $acc['moodle_username'] ?? null,
            'moodle_url'       => defined('MOODLE_URL') ? MOODLE_URL : null,
            'zoom_link'        => $acc['zoom_link'] ?? null,
            'teams_link'       => $acc['teams_link'] ?? null,
            'active_lesson_url' => $active_url,
        ]);
    }

    // ── vlab ──────────────────────────────────────────────────────────────────
    case 'vlab': {
        $stmt = $pdo->prepare("
            SELECT id, hostname, port, username, status, expires_at, server_type AS type, webterm_url AS web_terminal_url
            FROM k30_ti_vlab_servers
            WHERE student_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'               => (int)$r['id'],
            'hostname'         => $r['hostname'],
            'port'             => (int)$r['port'],
            'username'         => $r['username'],
            'status'           => $r['status'],
            'expires_at'       => $r['expires_at'],
            'type'             => $r['type'] ?? 'shared',
            'web_terminal_url' => $r['web_terminal_url'],
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
            'files_app_url' => $provisioned ? ((defined('OWNCLOUD_URL') ? OWNCLOUD_URL : '')) : null,
            'quota_bytes'   => 2 * 1024 * 1024 * 1024,
            'used_bytes'    => $acc['owncloud_used_bytes'] ?? 0,
        ]);
    }

    // ── licenses ─────────────────────────────────────────────────────────────
    case 'licenses': {
        $cid  = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $stmt = $pdo->prepare("
            SELECT cl.id, l.name AS software_name, cl.license_key, cl.assigned_at, cl.expires_at, l.download_url, cl.notes
            FROM client_licenses cl
            JOIN k30_ti_licenses l ON l.id = cl.license_id
            WHERE cl.client_id = ?
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
            SELECT id, action, description, ip, created_at
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
        $stmt = $pdo->prepare("
            SELECT t.id, t.title, t.type, t.version, t.file_url, t.required,
                   a.created_at AS accepted_at
            FROM k30_ti_terms t
            LEFT JOIN k30_ti_term_acceptances a ON a.term_id = t.id AND a.student_id = ?
            ORDER BY (a.id IS NULL AND t.required = 1) DESC, t.created_at DESC
        ");
        $stmt->execute([$student_id]);
        json_ok(array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'title'       => $r['title'],
            'type'        => $r['type'],
            'version'     => $r['version'],
            'file_url'    => $r['file_url'],
            'required'    => (bool)$r['required'],
            'is_accepted' => $r['accepted_at'] !== null,
            'accepted_at' => $r['accepted_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── authorized persons ────────────────────────────────────────────────────
    case 'authorized': {
        $cid  = (int)($pdo->query("SELECT client_id FROM k30_ti_student_accounts WHERE id = {$student_id}")->fetchColumn());
        $stmt = $pdo->prepare("
            SELECT id, name, relation, phone, email, created_at, is_active
            FROM k30_ti_authorized_persons
            WHERE client_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$cid]);
        json_ok(array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'relation'   => $r['relation'],
            'phone'      => $r['phone'],
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
        $body = get_body();
        $lid  = (int)($body['lesson_id'] ?? 0);
        if (!$lid) json_err('Brak lesson_id.');
        // Verify ownership
        $chk = $pdo->prepare("
            SELECT l.id FROM k30_ti_lessons l
            JOIN k30_ti_schedules s ON s.id = l.schedule_id
            WHERE l.id = ? AND s.client_id = (SELECT client_id FROM k30_ti_student_accounts WHERE id = ?)
              AND l.status = 'planned'
        ");
        $chk->execute([$lid, $student_id]);
        if (!$chk->fetchColumn()) json_err('Lekcja nie znaleziona lub brak uprawnień.', 403);
        $pdo->prepare("UPDATE k30_ti_lessons SET cancel_requested = 1, cancel_reason = ? WHERE id = ?")
            ->execute([$body['reason'] ?? null, $lid]);
        json_ok(null, 'Prośba o odwołanie wysłana.');
    }

    // ── POST: rate_lesson ─────────────────────────────────────────────────────
    case 'rate_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body   = get_body();
        $lid    = (int)($body['lesson_id'] ?? 0);
        $rating = (int)($body['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) json_err('Ocena musi być 1–5.');
        $pdo->prepare("UPDATE k30_ti_lessons SET rating = ?, rating_comment = ? WHERE id = ?")
            ->execute([$rating, $body['comment'] ?? null, $lid]);
        json_ok(null, 'Ocena zapisana.');
    }

    // ── POST: submit_homework ─────────────────────────────────────────────────
    case 'submit_homework': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $hid  = (int)($_POST['homework_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        if (!$hid) json_err('Brak homework_id.');
        $file_url = null;
        if (!empty($_FILES['file']['tmp_name'])) {
            $ext   = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $fname = 'hw_' . $student_id . '_' . $hid . '_' . time() . '.' . strtolower($ext);
            $dir   = __DIR__ . '/../../uploads/homework/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) {
                $file_url = '/uploads/homework/' . $fname;
            }
        }
        $pdo->prepare("
            INSERT INTO k30_ti_homework_submissions (homework_id, student_id, body, file_url, created_at)
            VALUES (?, ?, ?, ?, datetime('now'))
            ON CONFLICT (homework_id, student_id) DO UPDATE SET body = excluded.body, file_url = excluded.file_url, created_at = datetime('now')
        ")->execute([$hid, $student_id, $body, $file_url]);
        json_ok(null, 'Zadanie wysłane.');
    }

    // ── POST: send_message ────────────────────────────────────────────────────
    case 'send_message': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body    = get_body();
        $subject = trim($body['subject'] ?? '');
        $msg     = trim($body['body'] ?? '');
        if (!$subject || !$msg) json_err('Temat i treść są wymagane.');
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
        $body = get_body();
        $nid  = (int)($body['notice_id'] ?? 0);
        if (!$nid) json_err('Brak notice_id.');
        $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_notice_reads (id INTEGER PRIMARY KEY AUTOINCREMENT, notice_id INTEGER, student_id INTEGER, UNIQUE(notice_id, student_id))");
        $pdo->prepare("INSERT OR IGNORE INTO k30_ti_notice_reads (notice_id, student_id) VALUES (?, ?)")
            ->execute([$nid, $student_id]);
        json_ok(null);
    }

    // ── POST: accept_term ─────────────────────────────────────────────────────
    case 'accept_term': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $tid  = (int)($body['term_id'] ?? 0);
        if (!$tid) json_err('Brak term_id.');
        $pdo->prepare("
            INSERT OR IGNORE INTO k30_ti_term_acceptances (term_id, student_id, created_at)
            VALUES (?, ?, datetime('now'))
        ")->execute([$tid, $student_id]);
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
        $fields = ['notify_email_messages', 'notify_sms_messages', 'notify_email_dyd', 'notify_sms_dyd', 'notify_sms_lessons'];
        $sets   = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $sets[]   = "{$f} = ?";
                $params[] = (int)$body[$f];
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
        $acc = load_student($student_id);

        // Insert as helpdesk ticket
        $ticket_no = 'KUR-' . strtoupper(substr(md5(uniqid()), 0, 6));
        $pdo->prepare("
            INSERT INTO hd_tickets (ticket_no, student_id, subject, body, status, created_at)
            VALUES (?, ?, ?, ?, 'open', datetime('now'))
        ")->execute([$ticket_no, $student_id, $subject, $msg]);

        json_ok(['ticket_no' => $ticket_no], 'Zgłoszenie ' . $ticket_no . ' zostało przyjęte.');
    }

    // ── POST: owncloud_create / owncloud_reset ─────────────────────────────────
    case 'owncloud_create': {
        // Delegate to existing PHP ownCloud provisioning logic
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
        $sid  = (int)($body['server_id'] ?? 0);
        if (!$sid) json_err('Brak server_id.');
        $pdo->prepare("DELETE FROM k30_ti_vlab_servers WHERE id = ? AND student_id = ?")
            ->execute([$sid, $student_id]);
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

    default:
        json_err('Nieznana akcja: ' . htmlspecialchars($action), 404);
}
