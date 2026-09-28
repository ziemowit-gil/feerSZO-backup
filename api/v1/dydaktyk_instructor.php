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
require_once __DIR__ . '/../../includes/ti_reschedule.php';
require_once __DIR__ . '/../../includes/ti_periods.php';
require_once __DIR__ . '/../../includes/ti_planner_ext.php';
require_once __DIR__ . '/../../includes/ti_messages.php';
require_once __DIR__ . '/../../includes/ti_room_reports.php';
require_once __DIR__ . '/../../includes/ti_print_log.php';
require_once __DIR__ . '/../../includes/helpdesk.php';
require_once __DIR__ . '/../../includes/owncloud.php';
require_once __DIR__ . '/../../includes/ti_zoom_calendar.php';
require_once __DIR__ . '/../../includes/ti_protocols.php';
require_once __DIR__ . '/../../includes/ti_syllabus.php';
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
k30_ti_reschedule_migrate(); // k30_ti_reschedule_requests — jak w index.php (klasyczny panel)
ti_protocols_migrate();
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
        'is_admin' => ($u['role'] ?? '') === 'admin',
    ];
}

/** Id własnych kursów prowadzącego (główny lub coProwadzący) — nigdy zakres kierownika. */
function instructor_course_ids(int $instructorId): array {
    return array_map(fn($c) => (int)$c['id'], k30_ti_instructor_courses($instructorId, false));
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

// ── Bridge: logowanie przez Microsoft 365 (auth/ms365_prowadzacy.php) ─────────
// Konsumuje jednorazowy token 'dyd' wystawiony w auth/microsoft.php
// (k30_imp_token_create) po zweryfikowaniu tożsamości Microsoft — ten sam
// mechanizm co impersonacja admina (k30_imp_tokens), ale admin_id=0.
// TOTP jest obowiązkowe jak przy logowaniu hasłem (dyd_require()) — wyjątek
// tylko dla roli 'admin' — więc zwracamy tu ten sam kształt odpowiedzi co
// action=login (pending_token), chyba że konto jest zwolnione z 2FA.
if ($action === 'impersonate_exchange' && $method === 'POST') {
    $t = trim((string)(get_body()['t'] ?? ''));
    if (!$t) json_err('Brak tokenu.');

    $imp = k30_imp_token_consume($t);
    if (!$imp || $imp['type'] !== 'dyd') json_err('Token wygasł lub jest nieprawidłowy.', 403);

    $uid = (int)$imp['target_id'];
    $u   = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$uid]);
    if (!$u || !dyd_profile_from_user($u)) json_err('Konto nie istnieje, zostało dezaktywowane, lub nie ma dostępu do panelu.', 403);

    if ($u['role'] === 'admin') {
        $token = issue_instructor_token($uid, false);
        json_ok(['token' => $token, 'instructor' => instructor_public_profile($u)]);
    }

    if (empty($u['totp_confirmed']) || empty($u['totp_secret'])) {
        json_err('To konto nie ma jeszcze skonfigurowanej weryfikacji dwuetapowej (TOTP) — dokończ jej założenie w klasycznym panelu prowadzącego, potem wróć tutaj.', 409);
    }

    $pending = issue_2fa_pending_token($uid);
    json_ok(['totp_required' => true, 'pending_token' => $pending]);
}

// ── Token authentication ──────────────────────────────────────────────────────
// Dla zwykłych wywołań XHR token idzie w nagłówku Authorization; dla pobierania
// załącznika materiału (zwykły <a href>, przeglądarka nie dołoży nagłówka)
// dopuszczamy ten sam token jako ?token= w URL-u — to nadal ten sam losowy
// 256-bitowy sekret co w nagłówku, tylko inny transport.
function verify_instructor_token(): ?int {
    global $pdo;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : (string)($_GET['token'] ?? '');
    if ($token === '') return null;
    $stmt = $pdo->prepare("SELECT instructor_id FROM k30_ti_instructor_api_tokens WHERE token=? AND expires_at > datetime('now') LIMIT 1");
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

// Zamknięty okres nauczania: operacje na ISTNIEJĄCEJ lekcji z tego okresu są
// zablokowane (jak w index.php — okres jest rozliczony protokołami). Data
// docelowa (nowa lekcja / nowy termin) jest sprawdzana osobno w danej akcji.
if ($method === 'POST' && in_array($action, ['mark_attendance', 'cancel_lesson', 'uncancel_lesson', 'cancel_attendee',
        'restore_attendee', 'save_lesson', 'reschedule_lesson', 'reschedule_decide'], true)) {
    $_gb  = get_body();
    $_gsid = (int)($_gb['session_id'] ?? 0);
    if (!$_gsid && !empty($_gb['request_id'])) {
        $_greq = k30_ti_reschedule_get((int)$_gb['request_id']);
        $_gsid = (int)($_greq['session_id'] ?? 0);
    }
    if ($_gsid && ($_gpc = ti_period_closed_for_session($_gsid))) json_err(ti_period_closed_msg($_gpc));
}

// ── Route dispatch ────────────────────────────────────────────────────────────
switch ($action) {
    // Lista własnych kursów (id+nazwa) — dla wspólnego selektora grupy w
    // topbarze (InstructorCourseContextService), jedno źródło zamiast
    // wyprowadzania listy z każdej zakładki osobno (lessons/homework/materials).
    case 'courses': {
        json_ok(array_map(
            fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']],
            k30_ti_instructor_courses($instructor_id, false)
        ));
    }

    // ── formalności ───────────────────────────────────────────────────────────────
    // Odpowiednik _tab_formalnosci.php: dane kontaktowe własnego konta +
    // odczyt WŁASNYCH umów z rejestru (umowy_zlecenie/wolontariat/dzielo/praca,
    // dopasowanie po email/microsoft_id — ten sam sposób co klasyczny panel).
    // Wyłącznie odczyt umów — edycja/generowanie dokumentów zostaje w module
    // Rejestr Umów (poza zakresem prowadzącego).
    case 'formalnosci': {
        $u = db_one("SELECT email, microsoft_id, phone_number, alt_email, share_contact FROM users WHERE id=?", [$instructor_id]);
        if (!$u) json_err('Konto nie istnieje.', 404);

        $email = trim((string)($u['email'] ?? ''));
        $msId  = trim((string)($u['microsoft_id'] ?? ''));

        $contracts = [];
        foreach ([
            ['zlecenie', 'data_zakonczenia'], ['wolontariat', 'data_zakonczenia'],
            ['dzielo', 'termin_oddania'], ['praca', 'data_zakonczenia'],
        ] as [$ctype, $end_col]) {
            $table = "umowy_{$ctype}";
            try {
                $conds = []; $params = [];
                if ($email) { $conds[] = 'email=?'; $params[] = $email; }
                if ($msId)  { $conds[] = 'm365_user_id=?'; $params[] = $msId; }
                if (!$conds) continue;
                $rows = db_all(
                    "SELECT id, '{$ctype}' AS contract_type, numer_umowy, status, data_zawarcia,
                            {$end_col} AS data_zakonczenia, imie_nazwisko, stanowisko,
                            miejsce_wolontariatu, przedmiot_porozumienia
                     FROM {$table} WHERE (" . implode(' OR ', $conds) . ") ORDER BY data_zawarcia DESC",
                    $params
                );
                foreach ($rows as $r) $contracts[] = $r;
            } catch (\Throwable $e) {}
        }
        usort($contracts, function ($a, $b) {
            $active = fn($s) => in_array($s, ['podpisana', 'w realizacji'], true) ? 0 : 1;
            return $active($a['status']) <=> $active($b['status'])
                ?: strcmp((string)($b['data_zawarcia'] ?? ''), (string)($a['data_zawarcia'] ?? ''));
        });

        json_ok([
            'contact' => [
                'email' => $email,
                'phone_number' => (string)($u['phone_number'] ?? ''),
                'alt_email' => (string)($u['alt_email'] ?? ''),
                'share_contact' => !empty($u['share_contact']),
            ],
            'contracts' => $contracts,
        ]);
    }

    case 'update_contact': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $phone = trim((string)($body['phone_number'] ?? ''));
        $alt   = trim((string)($body['alt_email'] ?? ''));
        if ($alt !== '' && !filter_var($alt, FILTER_VALIDATE_EMAIL)) json_err('Podaj poprawny adres e-mail kontaktowy.');
        $pdo->prepare("UPDATE users SET phone_number=?, alt_email=?, share_contact=? WHERE id=?")
            ->execute([$phone, $alt ?: null, !empty($body['share_contact']) ? 1 : 0, $instructor_id]);
        json_ok(null, 'Dane kontaktowe zostały zapisane.');
    }

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

    // ── lekcje ─────────────────────────────────────────────────────────────────
    case 'lessons': {
        $course_ids = instructor_course_ids($instructor_id);
        $filter_course = (int)($_GET['course_id'] ?? 0);
        if ($filter_course && !in_array($filter_course, $course_ids, true)) {
            json_err('Ten kurs nie jest Twój.', 403);
        }
        $scope_ids = $filter_course ? [$filter_course] : $course_ids;
        if (!$scope_ids) json_ok([]);

        $ph = implode(',', array_fill(0, count($scope_ids), '?'));
        $rows = db_all(
            "SELECT s.id, s.course_id, c.name AS course_name, s.lesson_date, s.time_from, s.time_to,
                    s.status, s.topic, s.notes, s.meeting_url, c.default_meeting_url, " . k30_ti_docs_complete_sql() . " AS docs_complete,
                    s.lesson_method, s.room_id, s.has_homework, s.self_prep_remote,
                    s.rescheduled_from_date, s.instructor_id,
                    (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id AND a.attended=1) AS attended_count,
                    (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id) AS total_count,
                    (SELECT COUNT(*) FROM k30_ti_reschedule_requests r WHERE r.session_id=s.id AND r.status='pending') AS pending_reschedule_count
             FROM k30_ti_sessions s
             JOIN k30_ti_courses c ON c.id=s.course_id
             WHERE s.course_id IN ($ph)
             ORDER BY (s.lesson_date < date('now', 'localtime')),
                      CASE WHEN s.lesson_date >= date('now', 'localtime') THEN s.lesson_date END ASC,
                      CASE WHEN s.lesson_date >= date('now', 'localtime') THEN s.time_from END ASC,
                      CASE WHEN s.lesson_date <  date('now', 'localtime') THEN s.lesson_date END DESC,
                      CASE WHEN s.lesson_date <  date('now', 'localtime') THEN s.time_from END DESC",
            $scope_ids
        );
        $lessons = array_map(function ($s) {
            $s['is_substitution'] = !empty($s['instructor_id']);
            unset($s['instructor_id']);
            return $s;
        }, $rows);
        json_ok($lessons);
    }

    case 'session_attendance': {
        $sid = (int)($_GET['session_id'] ?? 0);
        if (!$sid || !k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);
        json_ok(k30_ti_session_attendance($sid));
    }

    case 'mark_attendance': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $sid  = (int)($body['session_id'] ?? 0);
        if (!$sid || !k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);

        $sess = db_one("SELECT lesson_date, status FROM k30_ti_sessions WHERE id=?", [$sid]);
        if ((string)($sess['lesson_date'] ?? '') > date('Y-m-d')) json_err('Nie można oznaczyć jako odbytej lekcji z przyszłości.');

        if (($sess['status'] ?? '') === 'remote_material') {
            $pdo->prepare("UPDATE k30_ti_attendance SET attended=1 WHERE session_id=? AND COALESCE(cancelled,0)=0 AND COALESCE(no_show,0)=0")->execute([$sid]);
            json_ok(null, 'Praca prowadzącego — wszyscy kursanci oznaczeni jako obecni.');
        }

        $attended = array_map('intval', (array)($body['attended'] ?? []));
        k30_ti_save_attendance($sid, $attended);

        // Lekcja się odbyła (gdy była zaplanowana) — zmiana indywidualna gdy:
        // podgrupa LUB ≥1 nieobecny LUB kurs jednosobowy (patrz index.php op=save_attendance).
        $any_absent = !empty(array_filter(
            db_all("SELECT attended FROM k30_ti_attendance WHERE session_id=? AND COALESCE(cancelled,0)=0", [$sid]),
            fn($r) => !$r['attended']
        ));
        $s_course   = db_one("SELECT course_id FROM k30_ti_sessions WHERE id=?", [$sid]);
        $cid        = (int)($s_course['course_id'] ?? 0);
        $course_row = db_one("SELECT is_subgroup FROM k30_ti_courses WHERE id=?", [$cid]);
        $is_sub     = !empty($course_row['is_subgroup']);
        $enrolled_n = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid])['n'] ?? 0);
        $new_status = ($is_sub || $any_absent || $enrolled_n <= 1) ? 'individual_change' : 'held';
        $pdo->prepare("UPDATE k30_ti_sessions SET status=?, updated_at=datetime('now') WHERE id=? AND status='planned'")->execute([$new_status, $sid]);

        foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]) as $er) {
            try { k30_ti_check_low_attendance($cid, (int)$er['client_id']); } catch (\Throwable $e) {}
        }
        json_ok(null, 'Obecność zapisana.');
    }

    case 'cancel_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body   = get_body();
        $sid    = (int)($body['session_id'] ?? 0);
        $reason = trim((string)($body['reason'] ?? ''));
        if (!$sid || !k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);
        if ($reason === '') json_err('Podaj powód odwołania lekcji.');

        $sess_date = (string)(db_one("SELECT lesson_date FROM k30_ti_sessions WHERE id=?", [$sid])['lesson_date'] ?? '');
        if ($sess_date && $sess_date < date('Y-m-d')) json_err('Nie można odwołać lekcji z przeszłości.');

        $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
        $sms_sent = k30_ti_cancel_session($sid, $reason, 'doradca', (string)($u['name'] ?? ''));
        json_ok(null, 'Lekcja odwołana — nie zostanie policzona do ceny.' . ($sms_sent ? " Wysłano SMS: {$sms_sent}." : ''));
    }

    case 'uncancel_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $sid  = (int)($body['session_id'] ?? 0);
        if (!$sid || !k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);
        $pdo->prepare(
            "UPDATE k30_ti_sessions SET status='planned', cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL, updated_at=datetime('now') WHERE id=?"
        )->execute([$sid]);
        json_ok(null, 'Lekcja przywrócona (zaplanowana).');
    }

    case 'cancel_attendee':
    case 'restore_attendee': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $sid  = (int)($body['session_id'] ?? 0);
        $cid  = (int)($body['client_id'] ?? 0);
        if (!$sid || !$cid || !k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);
        if ($action === 'cancel_attendee') {
            $reason = trim((string)($body['reason'] ?? '')) ?: 'Odwołane przez prowadzącego';
            $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
            k30_ti_cancel_attendance($sid, $cid, $reason, 'doradca', (string)($u['name'] ?? ''));
            json_ok(null, 'Udział kursanta odwołany — nie będzie liczony do ceny.');
        }
        k30_ti_uncancel_attendance($sid, $cid);
        json_ok(null, 'Udział kursanta przywrócony.');
    }

    // Lista pokoi (stacjonarne) do wyboru w formularzu lekcji.
    case 'rooms': {
        json_ok(pl_rooms_list(['is_active' => 1]));
    }

    // Dodanie/edycja pojedynczej lekcji — 1:1 z index.php op=save_lesson, ale
    // wyłącznie w zakresie prowadzącego: bez zastępstwa (pole widoczne tylko
    // kierownikowi), pomijania sprawdzenia dostępności, rezerwacji „na PESEL",
    // wersji roboczej i powiązania z programem nauczania (curriculum_ids) —
    // te zostają na razie w klasycznym panelu.
    case 'save_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();

        $sid  = (int)($body['session_id'] ?? 0);
        $cid  = (int)($body['course_id'] ?? 0);
        $course_ids = instructor_course_ids($instructor_id);
        if (!$cid || !in_array($cid, $course_ids, true)) json_err('Ten kurs nie jest Twój.', 403);

        $date  = trim((string)($body['lesson_date'] ?? ''));
        $tf    = trim((string)($body['time_from'] ?? ''));
        $tt    = trim((string)($body['time_to'] ?? ''));
        $topic = trim((string)($body['topic'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));
        $hw    = !empty($body['has_homework']) ? 1 : 0;
        $spr   = !empty($body['self_prep_remote']) ? 1 : 0;
        $lm    = in_array($body['lesson_method'] ?? '', ['stacjonarna', 'zdalna_zoom', 'zdalna_inne'], true) ? $body['lesson_method'] : '';
        $meet_url = in_array($lm, ['zdalna_zoom', 'zdalna_inne'], true) ? trim((string)($body['meeting_url'] ?? '')) : '';
        $room_id  = max(0, (int)($body['room_id'] ?? 0));
        $status   = $sid && in_array($body['status'] ?? '', ['planned', 'held', 'individual_change', 'remote_material'], true) ? $body['status'] : 'planned';
        // Status lekcji zmienia ręcznie tylko administrator — pozostali zachowują bieżący
        // (odbyta wynika z zapisu obecności, patrz mark_attendance).
        if ($sid && (string)(db_one("SELECT role FROM users WHERE id=?", [$instructor_id])['role'] ?? '') !== 'admin') {
            $status = (string)(db_one("SELECT status FROM k30_ti_sessions WHERE id=?", [$sid])['status'] ?? 'planned');
        }

        if ($date === '') json_err('Data lekcji jest wymagana.');

        $dur = 60;
        if ($tf && $tt) {
            $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60;
            if ($m > 0) $dur = (int)$m;
        }

        $eff_instr = ti_course_instructor_id($cid);
        $av = ti_instructor_available_at($eff_instr, $date, $tf, $tt);
        if (!$av['ok']) json_err($av['reason']);

        if ($pc = ti_period_closed_for_date($date)) json_err(ti_period_closed_msg($pc));

        $zc = ti_zoom_slot_check($cid, $lm, $date, $tf, $tt, $sid);
        if (!$zc['ok']) json_err($zc['reason']);
        $zw = $zc['warning'] !== '' ? ' ' . $zc['warning'] : '';

        if ($room_id) {
            $rc = pl_check_conflicts(['lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt, 'room_id' => $room_id, 'skip_id' => $sid]);
            if ($rc['hard']) json_err($rc['hard'][0]['msg'] ?? 'Sala zajęta w tym terminie.');
        }

        if ($sid) {
            if (!k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);
            $pdo->prepare(
                "UPDATE k30_ti_sessions
                 SET lesson_date=?, time_from=?, time_to=?, duration_min=?, topic=?, notes=?, has_homework=?, self_prep_remote=?, status=?, lesson_method=?, meeting_url=?, room_id=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$date, $tf, $tt, $dur, $topic, $notes, $hw, $spr, $status, $lm, $meet_url, $room_id ?: null, $sid]);
            if ($status === 'remote_material') {
                $pdo->prepare("UPDATE k30_ti_attendance SET attended=1 WHERE session_id=? AND COALESCE(cancelled,0)=0 AND COALESCE(no_show,0)=0")->execute([$sid]);
            }
            json_ok(null, 'Lekcja zaktualizowana.' . $zw);
        }

        $new_sid = db_insert('k30_ti_sessions', [
            'course_id' => $cid, 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt, 'duration_min' => $dur,
            'status' => 'planned', 'topic' => $topic, 'notes' => $notes,
            'has_homework' => $hw, 'self_prep_remote' => $spr,
            'lesson_method' => $lm, 'meeting_url' => $meet_url, 'room_id' => $room_id ?: null,
            'created_by' => $instructor_id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]) as $e) {
            try { db_insert('k30_ti_attendance', ['session_id' => $new_sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
            catch (\Throwable $ex) {}
        }
        $msg = 'Lekcja dodana.' . $zw;
        if (!empty($body['notify']) && !$spr) {
            $cn   = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid]);
            $when = $date . ($tf !== '' ? ' o ' . $tf : '');
            $n = ti_lesson_sms_notify($cid, 'Nowe zajecia: ' . ($cn['name'] ?? '') . ' — ' . $when . '. Szczegoly w panelu kursanta.');
            if ($n) $msg .= " Wysłano SMS: {$n}.";
        }
        json_ok(null, $msg);
    }

    // Seria lekcji — odpowiednik index.php op=save_lesson_series, w zakresie
    // prowadzącego: bez zastępstwa/pomijania dostępności (kierownik-only),
    // rezerwacji "na PESEL" i wersji roboczej — każda lekcja serii to zawsze
    // zwykła 'planned' lekcja, jak pojedyncze dodawanie (save_lesson).
    case 'save_lesson_series': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();

        $cid = (int)($body['course_id'] ?? 0);
        $course_ids = instructor_course_ids($instructor_id);
        if (!$cid || !in_array($cid, $course_ids, true)) json_err('Ten kurs nie jest Twój.', 403);

        $date  = trim((string)($body['lesson_date'] ?? ''));
        $tf    = trim((string)($body['time_from'] ?? ''));
        $tt    = trim((string)($body['time_to'] ?? ''));
        $topic = trim((string)($body['topic'] ?? ''));
        $lm    = in_array($body['lesson_method'] ?? '', ['stacjonarna', 'zdalna_zoom', 'zdalna_inne'], true) ? $body['lesson_method'] : '';
        $meet_url = in_array($lm, ['zdalna_zoom', 'zdalna_inne'], true) ? trim((string)($body['meeting_url'] ?? '')) : '';
        $room_id  = max(0, (int)($body['room_id'] ?? 0));

        if ($date === '' || !DateTime::createFromFormat('Y-m-d', $date)) json_err('Podaj poprawną datę startową serii.');

        $dur = 60;
        if ($tf && $tt) {
            $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60;
            if ($m > 0) $dur = (int)$m;
        }

        $end_mode = in_array($body['end_mode'] ?? '', ['until', 'hours'], true) ? $body['end_mode'] : 'count';
        $until    = trim((string)($body['until'] ?? ''));
        $count    = max(1, min(104, (int)($body['count'] ?? 1)));
        if ($end_mode === 'until' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < $date)) {
            json_err('Podaj poprawną datę końcową (nie wcześniejszą niż data startowa).');
        }
        $target_hours = null;
        if ($end_mode === 'hours') {
            $target_hours = max(0.5, (float)str_replace(',', '.', (string)($body['target_hours'] ?? '0')));
            $count = max(1, min(104, (int)ceil($target_hours * 60 / $dur)));
        }

        $dates = ti_recurrence_dates([
            'mode'     => ($body['recur_mode'] ?? '') === 'monthly' ? 'monthly' : 'weekly',
            'start'    => $date,
            'every'    => max(1, min(8, (int)($body['weeks'] ?? 1))),
            'dow'      => max(0, min(6, (int)($body['recur_dow'] ?? 1))),
            'position' => (string)($body['recur_position'] ?? '1'),
            'end_mode' => $end_mode === 'until' ? 'until' : 'count',
            'count'    => $count,
            'until'    => $until,
        ]);
        if (!$dates) json_err('Wzorzec nie wygenerował żadnego terminu — sprawdź datę startową i warunek zakończenia.');

        $eff_instr = ti_course_instructor_id($cid);
        $av = ti_instructor_available_at($eff_instr, $dates[0], $tf, $tt);
        if (!$av['ok']) json_err($av['reason'] . ' Seria nie została utworzona.');

        foreach ($dates as $d) {
            if ($pc = ti_period_closed_for_date($d)) json_err(ti_period_closed_msg($pc) . ' Seria nie została utworzona.');
        }

        $zs = ti_zoom_dates_check($cid, $lm, $dates, $tf, $tt);
        if (!$zs['ok']) json_err(ti_zoom_conflicts_msg($zs['conflicts']) . ' Seria nie została utworzona.');
        $zw = $zs['warning'] !== '' ? ' ' . $zs['warning'] : '';

        if ($room_id) {
            foreach ($dates as $d) {
                $rc = pl_check_conflicts(['lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt, 'room_id' => $room_id]);
                if ($rc['hard']) json_err(($rc['hard'][0]['msg'] ?? 'Sala zajęta.') . " ({$d}) Seria nie została utworzona.");
            }
        }

        $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]);
        $created = 0;
        foreach ($dates as $d) {
            $sid = db_insert('k30_ti_sessions', [
                'course_id' => $cid, 'lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt,
                'duration_min' => $dur, 'status' => 'planned', 'topic' => $topic, 'notes' => '',
                'lesson_method' => $lm, 'meeting_url' => $meet_url, 'room_id' => $room_id ?: null,
                'created_by' => $instructor_id, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($enrollees as $e) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
                catch (\Throwable $ex) {}
            }
            $created++;
        }

        $pattern_label = ($body['recur_mode'] ?? '') === 'monthly' ? 'wzorzec miesięczny' : ('co ' . max(1, min(8, (int)($body['weeks'] ?? 1))) . ' tyg.');
        $hours_note = $target_hours !== null
            ? ' — łącznie ' . number_format($created * $dur / 60, 1, ',', '') . ' godz. (cel: ' . number_format($target_hours, 1, ',', '') . ')'
            : '';
        json_ok(null, "Utworzono serię: {$created} lekcji ({$pattern_label}){$hours_note}." . $zw);
    }

    // ── zmiana terminu ────────────────────────────────────────────────────────────
    case 'reschedule_pending': {
        $sid = (int)($_GET['session_id'] ?? 0);
        if (!$sid || !k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);
        json_ok(k30_ti_reschedule_pending_for_session($sid));
    }

    case 'reschedule_lesson': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $sid  = (int)($body['session_id'] ?? 0);
        $date = trim((string)($body['lesson_date'] ?? ''));
        $tf   = trim((string)($body['time_from'] ?? ''));
        $tt   = trim((string)($body['time_to'] ?? ''));
        if (!$sid || !k30_ti_instructor_owns_session($instructor_id, $sid)) json_err('Brak dostępu do tej lekcji.', 403);
        if ($date === '') json_err('Podaj nowy termin lekcji.');

        $s = db_one("SELECT course_id FROM k30_ti_sessions WHERE id=?", [$sid]);
        $v = ti_validate_reschedule($sid, (int)$s['course_id'], $date, $tf, $tt);
        if (!$v['ok']) json_err($v['reason']);

        $old = k30_ti_do_reschedule($sid, $date, $tf, $tt);
        if ($old !== null && !empty($body['notify'])) {
            k30_ti_reschedule_notify_parties($sid, $old, !empty($body['notify_sms']));
            json_ok(null, 'Termin lekcji zmieniony. Powiadomiono uczestników.');
        }
        json_ok(null, 'Termin lekcji zmieniony.');
    }

    case 'reschedule_decide': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body   = get_body();
        $rid    = (int)($body['request_id'] ?? 0);
        $accept = !empty($body['accept']);
        $req    = $rid ? k30_ti_reschedule_get($rid) : null;
        if (!$req || !k30_ti_instructor_owns_session($instructor_id, (int)$req['session_id'])) {
            json_err('Brak dostępu do tej propozycji.', 403);
        }
        if ($accept) {
            $cid = (int)$req['course_id'];
            $av  = ti_instructor_available_at(ti_course_instructor_id($cid), (string)$req['proposed_date'], (string)$req['proposed_from'], (string)$req['proposed_to']);
            if (!$av['ok']) json_err('Nie można zaakceptować: ' . $av['reason']);
            if ($pc = ti_period_closed_for_date((string)$req['proposed_date'])) json_err('Nie można zaakceptować: ' . ti_period_closed_msg($pc));
            $lm = (string)(db_one("SELECT lesson_method FROM k30_ti_sessions WHERE id=?", [(int)$req['session_id']])['lesson_method'] ?? '');
            $zc = ti_zoom_slot_check($cid, $lm, (string)$req['proposed_date'], (string)$req['proposed_from'], (string)$req['proposed_to'], (int)$req['session_id']);
            if (!$zc['ok']) json_err('Nie można zaakceptować: ' . $zc['reason']);
        }
        $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
        k30_ti_reschedule_decide($rid, $accept, (string)($u['name'] ?? ''), trim((string)($body['note'] ?? '')));
        json_ok(null, $accept
            ? 'Propozycja zaakceptowana — termin lekcji zmieniony, kursant powiadomiony.'
            : 'Propozycja odrzucona — kursant powiadomiony.');
    }

    // ── zadania domowe ───────────────────────────────────────────────────────────
    case 'homework': {
        $course_ids = instructor_course_ids($instructor_id);
        $filter_course = (int)($_GET['course_id'] ?? 0);
        if ($filter_course && !in_array($filter_course, $course_ids, true)) {
            json_err('Ten kurs nie jest Twój.', 403);
        }
        $scope_ids = $filter_course ? [$filter_course] : $course_ids;

        $homeworks = [];
        foreach ($scope_ids as $cid) {
            foreach (k30_ti_homework_list($cid) as $hw) $homeworks[] = $hw;
        }
        usort($homeworks, fn($a, $b) =>
            ((int)$b['is_active'] <=> (int)$a['is_active'])
            ?: strcmp((string)($b['due_at'] ?? '9999'), (string)($a['due_at'] ?? '9999'))
            ?: ((int)$b['id'] <=> (int)$a['id'])
        );
        $homeworks = array_map(function ($hw) {
            $hw['has_file']    = $hw['attach_path'] !== '';
            $hw['availability'] = k30_ti_avail_status($hw['open_at'] ?? null, $hw['close_at'] ?? null);
            unset($hw['attach_path']);
            return $hw;
        }, $homeworks);
        json_ok($homeworks);
    }

    // Zwykły <a href> (nie XHR) — patrz komentarz przy verify_instructor_token().
    case 'homework_file': {
        $hid = (int)($_GET['id'] ?? 0);
        $hw  = $hid ? k30_ti_homework_get($hid) : null;
        if (!$hw || !k30_ti_instructor_owns_course($instructor_id, (int)$hw['course_id'])) {
            json_err('Brak dostępu do tego zadania.', 403);
        }
        if ($hw['attach_path'] !== '') k30_ti_homework_send_file((string)$hw['attach_path'], (string)$hw['attach_name']);
        json_err('Plik nie istnieje.', 404);
    }

    // Dodanie/edycja zadania domowego — 1:1 z index.php op=save_homework/
    // delete_homework, bez wyboru pliku z dysku ownCloud (cloud_pick.php).
    case 'save_homework': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();

        $hid = (int)($body['homework_id'] ?? 0);
        $cid = (int)($body['course_id'] ?? 0);
        $course_ids = instructor_course_ids($instructor_id);
        if (!$cid || !in_array($cid, $course_ids, true)) json_err('Ten kurs nie jest Twój.', 403);

        $title = trim((string)($body['title'] ?? ''));
        $desc  = trim((string)($body['description'] ?? ''));
        $hint  = trim((string)($body['hint'] ?? ''));
        $session_id = (int)($body['session_id'] ?? 0) ?: null;
        $dt_in = function (string $k) use ($body): ?string {
            $v = trim((string)($body[$k] ?? ''));
            return $v !== '' ? str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '') : null;
        };
        $due_at   = $dt_in('due_at');
        $open_at  = $dt_in('open_at');
        $close_at = $dt_in('close_at');

        if ($title === '') json_err('Podaj tytuł zadania.');
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $cid])) $session_id = null;

        try {
            $up = k30_ti_homework_upload('attach', 'hw');
        } catch (\Throwable $e) {
            json_err($e->getMessage());
        }

        if ($hid) {
            $hw = k30_ti_homework_get($hid);
            if (!$hw || !k30_ti_instructor_owns_course($instructor_id, (int)$hw['course_id'])) {
                json_err('Brak dostępu do tego zadania.', 403);
            }
            $set = [
                'course_id' => $cid, 'session_id' => $session_id, 'title' => $title, 'description' => $desc,
                'hint' => $hint, 'due_at' => $due_at, 'open_at' => $open_at, 'close_at' => $close_at,
                'is_active' => !empty($body['is_active']) ? 1 : 0,
            ];
            if ($up) {
                if ($hw['attach_path'] !== '') k30_ti_homework_delete_file($hw['attach_path']);
                $set['attach_name'] = $up['name'];
                $set['attach_path'] = $up['stored'];
            }
            $cols = []; $params = [];
            foreach ($set as $k => $v) { $cols[] = "$k=?"; $params[] = $v; }
            $params[] = $hid;
            $pdo->prepare('UPDATE k30_ti_homework SET ' . implode(',', $cols) . ' WHERE id=?')->execute($params);
            $msg = 'Zadanie zaktualizowane.';
        } else {
            db_insert('k30_ti_homework', [
                'course_id' => $cid, 'session_id' => $session_id, 'title' => $title, 'description' => $desc,
                'hint' => $hint, 'due_at' => $due_at, 'open_at' => $open_at, 'close_at' => $close_at,
                'attach_name' => $up['name'] ?? '', 'attach_path' => $up['stored'] ?? '',
                'is_active' => 1, 'created_by' => $instructor_id,
            ]);
            $msg = 'Zadanie dodane.';
        }

        if (!empty($body['notify'])) {
            k30_ti_notify_dydaktyka(
                $cid, 'Zmiana w zadaniu: ' . $title,
                'Prowadzący zaktualizował zadanie domowe „' . htmlspecialchars($title, ENT_QUOTES) . '".',
                rtrim(APP_URL, '/') . '/karty30/ti/kursant/index.php?tab=zadania',
                (defined('ORG_NAME') ? ORG_NAME : 'TI') . ': zmiana w zadaniu "' . $title . '".'
            );
        }
        json_ok(null, $msg);
    }

    case 'delete_homework': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $hid  = (int)($body['homework_id'] ?? 0);
        $hw   = $hid ? k30_ti_homework_get($hid) : null;
        if (!$hw || !k30_ti_instructor_owns_course($instructor_id, (int)$hw['course_id'])) {
            json_err('Brak dostępu do tego zadania.', 403);
        }
        foreach (db_all("SELECT file_path FROM k30_ti_homework_submissions WHERE homework_id=?", [$hid]) as $s) {
            k30_ti_homework_delete_file($s['file_path']);
        }
        k30_ti_homework_delete_file($hw['attach_path']);
        $pdo->prepare('DELETE FROM k30_ti_homework WHERE id=?')->execute([$hid]);
        json_ok(null, 'Zadanie usunięte.');
    }

    case 'homework_submissions': {
        $hid = (int)($_GET['homework_id'] ?? 0);
        $hw  = $hid ? k30_ti_homework_get($hid) : null;
        if (!$hw || !k30_ti_instructor_owns_course($instructor_id, (int)$hw['course_id'])) {
            json_err('Brak dostępu do tego zadania.', 403);
        }
        json_ok([
            'homework'    => $hw,
            'submissions' => k30_ti_homework_submissions($hid),
        ]);
    }

    case 'grade_submission': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $sid  = (int)($body['submission_id'] ?? 0);
        $sub  = $sid ? db_one(
            "SELECT s.id, h.course_id FROM k30_ti_homework_submissions s
             JOIN k30_ti_homework h ON h.id=s.homework_id WHERE s.id=?", [$sid]
        ) : null;
        if (!$sub || !k30_ti_instructor_owns_course($instructor_id, (int)$sub['course_id'])) {
            json_err('Brak dostępu do tego oddania.', 403);
        }
        $grade = trim((string)($body['grade'] ?? ''));
        $fb    = trim((string)($body['feedback'] ?? ''));
        $pdo->prepare(
            "UPDATE k30_ti_homework_submissions
             SET grade=?, feedback=?, status=?, graded_by=?, graded_at=datetime('now'), updated_at=datetime('now')
             WHERE id=?"
        )->execute([$grade, $fb, ($grade !== '' || $fb !== '') ? 'graded' : 'submitted', $instructor_id, $sid]);
        k30_ti_grade_sync_from_homework($sid, $instructor_id);
        json_ok(null, 'Ocena zapisana' . ($grade !== '' ? ' i dodana do dziennika ocen.' : '.'));
    }

    // ── materiały ─────────────────────────────────────────────────────────────────
    case 'materials': {
        $course_ids = instructor_course_ids($instructor_id);
        $filter_course = (int)($_GET['course_id'] ?? 0);
        if ($filter_course && !in_array($filter_course, $course_ids, true)) {
            json_err('Ten kurs nie jest Twój.', 403);
        }
        $scope_ids = $filter_course ? [$filter_course] : $course_ids;

        $materials = [];
        foreach ($scope_ids as $cid) {
            foreach (k30_ti_materials_list($cid) as $m) $materials[] = $m;
        }
        usort($materials, fn($a, $b) =>
            ((int)$b['is_active'] <=> (int)$a['is_active'])
            ?: ((int)$b['id'] <=> (int)$a['id'])
        );
        $materials = array_map(function ($m) {
            $m['has_file']    = $m['attach_path'] !== '';
            $m['availability'] = k30_ti_avail_status($m['open_at'] ?? null, $m['close_at'] ?? null);
            unset($m['attach_path']);
            return $m;
        }, $materials);
        json_ok($materials);
    }

    // Zwykły <a href> (nie XHR) — patrz komentarz przy verify_instructor_token().
    case 'material_file': {
        $mid = (int)($_GET['id'] ?? 0);
        $m   = $mid ? k30_ti_material_get($mid) : null;
        if (!$m || !k30_ti_instructor_owns_course($instructor_id, (int)$m['course_id'])) {
            json_err('Brak dostępu do tego materiału.', 403);
        }
        if ($m['attach_path'] !== '') k30_ti_homework_send_file((string)$m['attach_path'], (string)$m['attach_name']);
        json_err('Plik nie istnieje.', 404);
    }

    // Dodanie/edycja — multipart/form-data (upload pliku), jak submit_homework.
    // Logika 1:1 z index.php op=save_material, bez opcji wklejenia pliku z
    // dysku ownCloud (cloud_pick.php — zostaje w klasycznym panelu).
    case 'save_material': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();

        $mid       = (int)($body['material_id'] ?? 0);
        $cid       = (int)($body['course_id'] ?? 0);
        $types     = k30_ti_material_types();
        $type      = trim((string)($body['type'] ?? 'inne'));
        if (!isset($types[$type])) $type = 'inne';
        $title     = trim((string)($body['title'] ?? ''));
        $desc      = trim((string)($body['description'] ?? ''));
        $url       = trim((string)($body['url'] ?? ''));
        $session_id = (int)($body['session_id'] ?? 0) ?: null;
        $dt_in     = function (string $k) use ($body): ?string {
            $v = trim((string)($body[$k] ?? ''));
            return $v !== '' ? str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '') : null;
        };
        $open_at  = $dt_in('open_at');
        $close_at = $dt_in('close_at');

        if ($title === '') json_err('Podaj tytuł materiału.');
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $cid])) $session_id = null;

        try {
            $up = k30_ti_homework_upload('attach', 'mat');
        } catch (\Throwable $e) {
            json_err($e->getMessage());
        }

        if ($mid) {
            $m = k30_ti_material_get($mid);
            if (!$m || !k30_ti_instructor_owns_course($instructor_id, (int)$m['course_id'])) {
                json_err('Brak dostępu do tego materiału.', 403);
            }
            $set = [
                'course_id' => $cid, 'session_id' => $session_id, 'type' => $type,
                'title' => $title, 'description' => $desc, 'url' => $url,
                'open_at' => $open_at, 'close_at' => $close_at,
                'is_active' => !empty($body['is_active']) ? 1 : 0,
            ];
            if ($up) {
                if ($m['attach_path'] !== '') k30_ti_homework_delete_file($m['attach_path']);
                $set['attach_name'] = $up['name'];
                $set['attach_path'] = $up['stored'];
            }
            $cols = []; $params = [];
            foreach ($set as $k => $v) { $cols[] = "$k=?"; $params[] = $v; }
            $params[] = $mid;
            $pdo->prepare('UPDATE k30_ti_materials SET ' . implode(',', $cols) . ' WHERE id=?')->execute($params);
            $msg = 'Materiał zaktualizowany.';
        } else {
            db_insert('k30_ti_materials', [
                'course_id' => $cid, 'session_id' => $session_id, 'type' => $type,
                'title' => $title, 'description' => $desc, 'url' => $url,
                'open_at' => $open_at, 'close_at' => $close_at,
                'attach_name' => $up['name'] ?? '', 'attach_path' => $up['stored'] ?? '',
                'is_active' => 1, 'created_by' => $instructor_id,
            ]);
            $msg = 'Materiał dodany.';
        }

        if (!empty($body['notify'])) {
            k30_ti_notify_dydaktyka(
                $cid, 'Zmiana w materiale: ' . $title,
                'Prowadzący zaktualizował materiał „' . htmlspecialchars($title, ENT_QUOTES) . '" w sekcji Dydaktyka / eLearning.',
                rtrim(APP_URL, '/') . '/karty30/ti/kursant/index.php?tab=zadania',
                (defined('ORG_NAME') ? ORG_NAME : 'TI') . ': zmiana w materiałach — "' . $title . '".'
            );
        }
        json_ok(null, $msg);
    }

    case 'delete_material': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $mid  = (int)($body['material_id'] ?? 0);
        $m    = $mid ? k30_ti_material_get($mid) : null;
        if (!$m || !k30_ti_instructor_owns_course($instructor_id, (int)$m['course_id'])) {
            json_err('Brak dostępu do tego materiału.', 403);
        }
        k30_ti_homework_delete_file($m['attach_path']);
        $pdo->prepare('DELETE FROM k30_ti_materials WHERE id=?')->execute([$mid]);
        json_ok(null, 'Materiał usunięty.');
    }

    // ── wiadomości ────────────────────────────────────────────────────────────────
    // Odpowiednik _tab_wiadomosci.php: wątki z kursantami (k30_ti_messages, per
    // student_id) i osobno z kierownictwem (k30_ti_admin_msgs, jeden wiersz =
    // wiadomość+ewentualna odpowiedź, nie symetryczny wątek). Załączniki,
    // blokowanie kursanta, archiwizacja i przekierowanie do Helpdesk IT
    // zostają na razie w klasycznym panelu.
    case 'message_threads': {
        $course_ids = instructor_course_ids($instructor_id);
        $in = $course_ids ? implode(',', $course_ids) : '0';

        $student_threads = db_all(
            "SELECT a.id AS account_id, COALESCE(cl.name, a.login) AS name, a.login,
                    MAX(m.created_at) AS last_at,
                    SUM(CASE WHEN m.sender='student' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread
             FROM k30_ti_messages m
             JOIN k30_ti_student_accounts a ON a.id=m.student_id
             LEFT JOIN k30_clients cl ON cl.id=a.client_id
             WHERE a.id IN (
                 SELECT DISTINCT sa.id FROM k30_ti_student_accounts sa
                 JOIN k30_ti_enrollments e ON e.client_id=sa.client_id
                 WHERE e.course_id IN ($in) AND e.status='active'
             )
             GROUP BY a.id ORDER BY last_at DESC"
        );
        $recipients = db_all(
            "SELECT DISTINCT a.id, COALESCE(cl.name, a.login) AS name, c.name AS course_name
             FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             JOIN k30_ti_courses c ON c.id=e.course_id
             LEFT JOIN k30_clients cl ON cl.id=a.client_id
             WHERE e.course_id IN ($in) AND e.status='active' AND a.is_active=1
             ORDER BY name"
        );

        $admin_users   = ti_admin_users();
        $admin_threads = array_map(function ($t) use ($admin_users) {
            $t['to_admin_id'] = (int)$t['to_admin_id'];
            $t['label']       = ti_admin_thread_label($t['to_admin_id'], $admin_users);
            $t['unseen']      = (int)$t['unseen'];
            $t['msg_count']   = (int)$t['msg_count'];
            return $t;
        }, ti_admin_msg_thread_list($instructor_id));
        $admin_recipients = array_merge(
            [['id' => -1, 'label' => 'Kierownik Instytucji'], ['id' => 0, 'label' => 'Administratorzy (wszyscy)']],
            array_map(fn($a) => ['id' => (int)$a['id'], 'label' => (string)$a['name']], $admin_users)
        );

        json_ok([
            'student_threads'  => $student_threads,
            'recipients'       => $recipients,
            'admin_threads'    => $admin_threads,
            'admin_recipients' => $admin_recipients,
            'admin_unseen_total' => ti_admin_msg_unseen_total($instructor_id),
        ]);
    }

    case 'message_thread': {
        $kind = (string)($_GET['kind'] ?? 'student');
        $id   = (int)($_GET['id'] ?? 0);

        if ($kind === 'admin') {
            $msgs = ti_admin_msg_list_for_thread($instructor_id, $id);
            ti_admin_msg_mark_instructor_seen($instructor_id, $id);
            json_ok($msgs);
        }

        $course_ids = instructor_course_ids($instructor_id);
        $in = $course_ids ? implode(',', $course_ids) : '0';
        $owns = $id ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN ($in) AND e.status='active' LIMIT 1",
            [$id]
        ) : null;
        if (!$owns) json_err('Ten kursant nie jest zapisany do Twojego kursu.', 403);
        ti_msg_mark_read_for_staff($id);
        json_ok(ti_msg_list_for_student($id));
    }

    case 'send_message': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body    = get_body();
        $kind    = (string)($body['kind'] ?? 'student');
        $text    = trim((string)($body['body'] ?? ''));
        $subject = trim((string)($body['subject'] ?? ''));
        if ($text === '') json_err('Treść wiadomości jest wymagana.');
        $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
        $senderName = (string)($u['name'] ?? 'Prowadzący');

        if ($kind === 'admin') {
            $toAdminId = (int)($body['to_admin_id'] ?? 0);
            ti_admin_msg_send($instructor_id, $senderName, $subject, $text, $toAdminId);
            json_ok(null, 'Wiadomość wysłana.');
        }

        $accId = (int)($body['account_id'] ?? 0);
        $course_ids = instructor_course_ids($instructor_id);
        $in = $course_ids ? implode(',', $course_ids) : '0';
        $owns = $accId ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN ($in) AND e.status='active' LIMIT 1",
            [$accId]
        ) : null;
        if (!$owns) json_err('Nie możesz pisać do tego kursanta.', 403);
        ti_msg_post_to_student($accId, $subject, $text, $instructor_id, $senderName, true);
        json_ok(null, 'Wiadomość wysłana.');
    }

    // ── wydruki ───────────────────────────────────────────────────────────────────
    // Odpowiedniki wydruków dostępnych zwykłemu prowadzącemu w klasycznym panelu
    // (plan_librus_instructor_pdf.php, attendance_csv.php) — NIE katalog
    // wydruki.php/raporty.php, ten jest kierownik-only (dyd_is_staff()) i
    // zostaje wyłącznie w klasycznym panelu. Zwykłe <a href>, nie XHR —
    // patrz komentarz przy verify_instructor_token().
    case 'plan_pdf': {
        $weeks = max(1, min(52, (int)($_GET['weeks'] ?? 12)));
        $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
        $L = ti_librus_grid_instructor($instructor_id, $weeks);
        if (!$L || !$L['instructor']) json_err('Nie znaleziono danych planu.', 404);
        $name = trim((string)$L['instructor']['name']);
        try {
            $pdfData = ti_librus_grid_pdf($L, TI_DAYS_PL_FULL, [
                'title'  => 'Plan zajęć (siatka) — ' . $name,
                'org'    => ti_org_contact_info(),
                'footer' => 'Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . (string)($u['name'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            json_err('Błąd generowania PDF: ' . $e->getMessage(), 500);
        }
        ti_print_log_add('plan_librus_instructor_pdf', 'Plan zajęć (siatka, PDF) — ' . $name, 0, 0, ['weeks' => $weeks], ['user_id' => $instructor_id, 'name' => (string)($u['name'] ?? '')]);
        $fname = 'plan_zajec_' . preg_replace('/[^a-z0-9]+/i', '_', $name) . '.pdf';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($pdfData));
        echo $pdfData;
        exit;
    }

    case 'attendance_csv': {
        $month = (string)($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $course_ids = instructor_course_ids($instructor_id);
        $cid_filter = (int)($_GET['course_id'] ?? 0);
        if ($cid_filter) {
            if (!in_array($cid_filter, $course_ids, true)) json_err('Brak uprawnień do tego kursu.', 403);
            $course_ids = [$cid_filter];
        }
        if (!$course_ids) json_err('Brak kursów.', 404);
        $my_courses = k30_ti_instructor_courses($instructor_id, false);

        $ph = implode(',', array_fill(0, count($course_ids), '?'));
        $sessions = db_all(
            "SELECT s.id, s.lesson_date, s.time_from, c.id AS course_id, c.name AS course_name
             FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
             WHERE s.course_id IN ($ph)
               AND s.status IN ('held', 'individual_change')
               AND COALESCE(c.track_attendance, 1)=1
               AND strftime('%Y-%m', s.lesson_date)=?
             ORDER BY c.name COLLATE NOCASE, s.lesson_date, s.time_from",
            array_merge($course_ids, [$month])
        );
        $sess_by_course = [];
        foreach ($sessions as $s) $sess_by_course[$s['course_id']][] = $s;

        $att_all = db_all(
            "SELECT a.client_id, a.session_id, a.attended, a.cancelled
             FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
             WHERE s.course_id IN ($ph) AND s.status IN ('held', 'individual_change') AND strftime('%Y-%m', s.lesson_date)=?",
            array_merge($course_ids, [$month])
        );
        $att_map = [];
        foreach ($att_all as $a) $att_map[$a['session_id']][$a['client_id']] = $a;

        $students_by_course = [];
        foreach ($course_ids as $cid) {
            $students_by_course[$cid] = db_all(
                "SELECT cl.id, cl.name FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id
                 WHERE e.course_id=? AND e.status='active' ORDER BY cl.name COLLATE NOCASE",
                [(int)$cid]
            );
        }

        $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
        while (ob_get_level() > 0) ob_end_clean();
        $fname = 'frekwencja_' . $month . ($cid_filter ? '_kurs' . $cid_filter : '') . '.csv';
        ti_print_log_add('attendance_csv', 'Eksport CSV frekwencji — ' . $month, $cid_filter, 0, [], ['user_id' => $instructor_id, 'name' => (string)($u['name'] ?? '')]);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-cache, no-store');
        echo "\xEF\xBB\xBF";

        $f = fopen('php://output', 'w');
        foreach ($course_ids as $cid) {
            $course_sessions = $sess_by_course[$cid] ?? [];
            $students        = $students_by_course[$cid] ?? [];
            if (!$course_sessions || !$students) continue;
            $cname = '';
            foreach ($my_courses as $mc) { if ((int)$mc['id'] === $cid) { $cname = (string)$mc['name']; break; } }

            fputcsv($f, ['Kurs: ' . $cname, 'Miesiąc: ' . $month]);
            $header = ['Kursant'];
            foreach ($course_sessions as $s) {
                $label = date('d.m', strtotime($s['lesson_date']));
                if ($s['time_from']) $label .= ' ' . substr((string)$s['time_from'], 0, 5);
                $header[] = $label;
            }
            $header[] = 'Obecności'; $header[] = 'Lekcji'; $header[] = 'Frekwencja %';
            fputcsv($f, $header);

            foreach ($students as $st) {
                $row = [$st['name']];
                $present = 0; $total = 0;
                foreach ($course_sessions as $s) {
                    $a = $att_map[$s['id']][$st['id']] ?? null;
                    $total++;
                    if ($a === null) { $row[] = '?'; }
                    elseif ((int)$a['cancelled']) { $row[] = 'odw.'; }
                    elseif ((int)$a['attended']) { $present++; $row[] = '1'; }
                    else { $row[] = '0'; }
                }
                $row[] = $present; $row[] = $total;
                $row[] = $total > 0 ? round($present / $total * 100) . '%' : '—';
                fputcsv($f, $row);
            }
            fputcsv($f, []);
        }
        fclose($f);
        exit;
    }

    // ── helpdesk ──────────────────────────────────────────────────────────────────
    // Zgłoszenia własne prowadzącego do Helpdesk IT — odpowiednik
    // karty30/ti/dydaktyk/api_helpdesk.php (tam: sesja panelu; tu: Bearer
    // token). Ląduje w tym samym module Helpdesk (helpdesk_tickets,
    // source='dydaktyk'), obsługiwane przez ten sam panel operatorów.
    case 'helpdesk_tickets': {
        json_ok(db_all(
            "SELECT id, number, title, category, priority, status, created_at, updated_at
             FROM helpdesk_tickets WHERE requester_id=? ORDER BY created_at DESC",
            [$instructor_id]
        ));
    }

    case 'helpdesk_create': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $title       = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $category    = (string)($body['category'] ?? 'it_inne');
        $priority    = (string)($body['priority'] ?? 'normalny');
        if ($title === '' || $description === '') json_err('Temat i opis są wymagane.');

        $u = db_one("SELECT name, email FROM users WHERE id=?", [$instructor_id]);
        $ticket_id = hd_ticket_quick_create(
            ['id' => $instructor_id, 'name' => (string)($u['name'] ?? ''), 'email' => (string)($u['email'] ?? '')],
            $title, $description, $category, $priority, 'dydaktyk'
        );
        if (!empty($_FILES['attachments']['name'][0])) hd_save_attachments($ticket_id, $_FILES['attachments'], $instructor_id);
        json_ok(null, 'Zgłoszenie zarejestrowane.');
    }

    // ── zasoby: mój dysk (ownCloud) ─────────────────────────────────────────────────
    // Odpowiednik _tab_dysk.php — samoobsługowe konto ownCloud prowadzącego.
    // W klasycznym panelu hasło pokazuje się raz przez $_SESSION['owncloud_reveal'];
    // tu, bez sesji, po prostu zwracamy je wprost w odpowiedzi create/reset/recreate
    // (i tak wyświetlane tylko raz, w oknie modalnym frontendu).
    case 'owncloud_status': {
        json_ok([
            'enabled' => owncloud_enabled() && owncloud_admin_configured(),
            'account' => owncloud_instructor_account($instructor_id),
            'url'     => rtrim(owncloud_setting('url'), '/'),
        ]);
    }

    case 'owncloud_create': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $r = owncloud_create_instructor_account($instructor_id);
        if (!$r['ok']) json_err($r['msg']);
        json_ok($r, $r['msg']);
    }

    case 'owncloud_reset': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $r = owncloud_reset_instructor_password($instructor_id);
        if (!$r['ok']) json_err($r['msg']);
        json_ok($r, $r['msg']);
    }

    case 'owncloud_recreate': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $r = owncloud_recreate_instructor_account($instructor_id);
        if (!$r['ok']) json_err($r['msg']);
        json_ok($r, $r['msg']);
    }

    // ── zasoby: zajętość Zoom ────────────────────────────────────────────────────────
    // Odpowiednik _tab_zoom.php — zajęte terminy Zoom w miesiącu, żeby prowadzący
    // rozumiał dlaczego pewne godziny są niedostępne przy zdalnych zajęciach.
    // Nazwy kursów widoczne tylko dla WŁASNYCH grup, reszta jako "Inne zajęcia zdalne"
    // (ten sam maskujący warunek co $zc_visible w klasycznym panelu, tam dla staff=null=brak maski).
    case 'zoom_busy': {
        $month = (string)($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));

        if (!zoom_enabled()) json_ok(['enabled' => false, 'days' => []]);

        $course_ids = instructor_course_ids($instructor_id);
        $busy = ti_zoom_busy_range($from, $to);
        $days = [];
        foreach ($busy['days'] as $date => $slots) {
            $days[$date] = array_map(function ($s) use ($course_ids) {
                $own = isset($s['course_id']) && in_array((int)$s['course_id'], $course_ids, true);
                return [
                    'start' => $s['start'], 'end' => $s['end'],
                    'title' => $own ? $s['title'] : 'Inne zajęcia zdalne',
                ];
            }, $slots);
        }
        json_ok(['enabled' => true, 'ok' => $busy['ok'], 'days' => $days]);
    }

    // ── protokoły ─────────────────────────────────────────────────────────────────
    // Odpowiednik protokoly_moje.php (widok prowadzącego — kreator "zamknij
    // miesiąc", OSOBNY tor od protokoly.php/protokol_pdf.php widoku kierownika).
    case 'protocols_pending': {
        json_ok(ti_protocol_pending_months_for_instructor($instructor_id));
    }

    // Lista miesięcy per grupa ze stanem protokołu (approved/overdue/current/empty)
    // — bieżący miesiąc można zamknąć wcześniej, gdy nie ma już zajęć.
    case 'protocols_months': {
        json_ok(ti_protocol_months_for_instructor($instructor_id));
    }

    case 'protocols_closed': {
        // Tylko protokoły miesięczne — per-okres (year_month='') to inny tor.
        json_ok(array_map(function ($r) use ($instructor_id) {
            $p = ti_protocol_get((int)$r['protocol_id']) ?: [];
            return [
                'course_id'     => (int)$r['course_id'], 'course_name' => (string)$r['course_name'],
                'year_month'    => (string)$r['year_month'], 'protocol_id' => (int)$r['protocol_id'],
                'approved_name' => (string)($r['approved_name'] ?? ''),
                'approved_at'   => $r['approved_at'] ?? null,
                'hours_ack_at'  => $p['hours_ack_at'] ?? null,
                'hours_ack_label' => $p ? ti_protocol_hours_ack_label($p) : '',
                'org_ack_at'    => $p['org_ack_at'] ?? null,
                'org_ack_name'  => (string)($p['org_ack_name'] ?? ''),
                'drift'         => $p ? ti_protocol_snapshot_drift($p) : false,
                'can_ack'       => ti_protocol_can_approve($instructor_id, (int)$r['course_id']),
            ];
        }, ti_protocol_closed_months_for_instructor($instructor_id)));
    }

    case 'protocol_summary': {
        $cid = (int)($_GET['course_id'] ?? 0);
        $ym  = (string)($_GET['year_month'] ?? '');
        if (!$cid || !preg_match('/^\d{4}-\d{2}$/', $ym) || !k30_ti_instructor_owns_course($instructor_id, $cid)) {
            json_err('Nieprawidłowe dane protokołu.', 403);
        }
        // Ewidencja i wypłata za miesiąc — liczone na żywo jak w PDF (protokół
        // jeszcze nie zatwierdzony). Bez get-or-create: tylko zakres miesiąca.
        $hp = ti_protocol_hours_and_payout_live([
            'course_id' => $cid,
            'date_from' => $ym . '-01',
            'date_to'   => date('Y-m-t', strtotime($ym . '-01')),
        ]);
        json_ok(ti_protocol_month_summary($cid, $ym) + [
            'total_min'   => (int)$hp['total_min'],
            'total_hours' => ti_protocol_rows_hours($hp['rows']),
            'has_rate'    => (bool)$hp['has_rate'],
            'netto'       => round((float)$hp['payout']['netto'], 2),
            'brutto_brutto' => round((float)$hp['payout']['brutto_brutto'], 2),
            'own_lessons' => (int)$hp['payout']['lessons'],
            'subs'        => array_map(fn($s) => [
                'name' => (string)$s['name'], 'lessons' => (int)$s['lessons'], 'netto' => round((float)$s['netto'], 2),
            ], $hp['subs']),
            'can_approve' => ti_protocol_can_approve($instructor_id, $cid),
        ]);
    }

    // Lista godzin (data — liczba godzin) do okna „Sprawdź listę godzin” przed
    // zatwierdzeniem/podpisem, oraz jej wydruk PDF (?pdf=1, zwykły <a href>).
    // Po id protokołu albo course_id+year_month (bez zakładania protokołu).
    case 'protocol_hours_list': {
        $pid = (int)($_GET['id'] ?? 0);
        $cid = (int)($_GET['course_id'] ?? 0);
        $ym  = (string)($_GET['year_month'] ?? '');
        if (!$pid && $cid && !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Brak dostępu do tego protokołu.', 403);
        $pr = $pid ? ti_protocol_get($pid)
            : (($cid && preg_match('/^\d{4}-\d{2}$/', $ym)) ? ti_protocol_month_stub($cid, $ym) : null);
        if (!$pr || !k30_ti_instructor_owns_course($instructor_id, (int)$pr['course_id'])) json_err('Brak dostępu do tego protokołu.', 403);

        if (!empty($_GET['pdf'])) {
            $pdf = ti_protocol_hours_list_pdf($pr);
            if ($pdf === null) json_err('Nie udało się wygenerować PDF listy godzin.', 500);
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . ti_protocol_hours_list_filename($pr) . '"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }
        $hl = ti_protocol_hours_list($pr);
        json_ok([
            'course_name' => (string)($pr['course_name'] ?? ''),
            'period_name' => (string)($pr['period_name'] ?? ''),
            'rows'        => $hl['rows'],
            'total_hours' => $hl['total_hours'],
        ]);
    }

    // Potwierdzenie ewidencji godzin i wypłaty (podpis prowadzącego) na
    // ZATWIERDZONYM protokole — jak op=protocol_hours_ack w klasycznym panelu.
    case 'protocol_hours_ack': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $pid  = (int)($body['protocol_id'] ?? 0);
        $pr   = $pid ? ti_protocol_get($pid) : null;
        if (!$pr || !k30_ti_instructor_owns_course($instructor_id, (int)$pr['course_id'])) json_err('Brak dostępu do tego protokołu.', 403);
        // Oświadczenie składa główny prowadzący (jego ewidencja i wypłata), nie współprowadzący.
        if (!ti_protocol_can_approve($instructor_id, (int)$pr['course_id'])) json_err('Ewidencję potwierdza główny prowadzący kursu.', 403);
        $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
        try {
            ti_protocol_hours_ack($pid, $instructor_id, (string)($u['name'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        } catch (\Throwable $e) {
            json_err($e->getMessage());
        }
        json_ok(null, 'Ewidencja godzin i naliczenie wypłaty potwierdzone.');
    }

    case 'protocol_approve': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $cid = (int)($body['course_id'] ?? 0);
        $ym  = (string)($body['year_month'] ?? '');
        if (!$cid || !preg_match('/^\d{4}-\d{2}$/', $ym) || $ym > date('Y-m') || !k30_ti_instructor_owns_course($instructor_id, $cid)) {
            json_err('Nieprawidłowe dane protokołu.', 403);
        }
        if (!ti_protocol_can_approve($instructor_id, $cid)) json_err(TI_PROTOCOL_APPROVE_DENIED, 403);
        $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
        try {
            $prot = ti_protocol_get_or_create_for_month($cid, $ym);
            ti_protocol_approve((int)$prot['id'], $instructor_id, (string)($u['name'] ?? ''));
        } catch (\Throwable $e) {
            json_err($e->getMessage());
        }
        json_ok(null, 'Protokół za ' . $ym . ' zatwierdzony.');
    }

    // ── wykresy: trend frekwencji ────────────────────────────────────────────────
    // Frekwencja % per miesiąc (ostatnie N miesięcy), zbiorczo dla własnych
    // kursów lub zawężone do jednej grupy (?course_id=) — dla wykresu na
    // Pulpicie. Liczone tak samo jak 'attendance_month' w case 'dashboard',
    // tylko rozbite na kolejne miesiące zamiast tylko bieżącego.
    case 'attendance_trend': {
        $course_ids = instructor_course_ids($instructor_id);
        $filter_course = (int)($_GET['course_id'] ?? 0);
        if ($filter_course && !in_array($filter_course, $course_ids, true)) {
            json_err('Ten kurs nie jest Twój.', 403);
        }
        $scope_ids = $filter_course ? [$filter_course] : $course_ids;
        if (!$scope_ids) json_ok([]);

        $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
        $ph = implode(',', array_fill(0, count($scope_ids), '?'));
        $rows = db_all(
            "SELECT strftime('%Y-%m', s.lesson_date) AS ym,
                    SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=1 THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 THEN 1 ELSE 0 END) AS total
             FROM k30_ti_sessions s
             LEFT JOIN k30_ti_attendance a ON a.session_id = s.id
             WHERE s.course_id IN ($ph) AND s.status IN ('held', 'individual_change')
               AND s.lesson_date >= date('now', 'localtime', '-' || ? || ' months')
             GROUP BY ym ORDER BY ym",
            array_merge($scope_ids, [$months])
        );
        json_ok(array_map(function ($r) {
            $total = (int)$r['total'];
            return [
                'year_month' => (string)$r['ym'],
                'present'    => (int)$r['present'],
                'total'      => $total,
                'pct'        => $total > 0 ? round((int)$r['present'] * 100 / $total) : null,
            ];
        }, $rows));
    }

    // Wydruk protokołu do PDF — działa zarówno dla zamkniętego (zatwierdzonego)
    // jak i wciąż otwartego/roboczego protokołu (identycznie jak
    // protokol_pdf.php w klasycznym panelu — ti_protocol_pdf() renderuje
    // treść w zależności od statusu). Zwykły <a href>, patrz komentarz przy
    // verify_instructor_token().
    case 'protocol_pdf': {
        $pid = (int)($_GET['id'] ?? 0);
        $cid = (int)($_GET['course_id'] ?? 0);
        $ym  = (string)($_GET['year_month'] ?? '');

        $pr = null;
        if ($pid) {
            $pr = ti_protocol_get($pid);
        } elseif ($cid && preg_match('/^\d{4}-\d{2}$/', $ym)) {
            // Najpierw uprawnienie do kursu, dopiero potem get-or-create — inaczej
            // dowolny prowadzący zakładałby GET-em protokoły cudzych kursów.
            if (!k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Brak dostępu do tego protokołu.', 403);
            try {
                $created = ti_protocol_get_or_create_for_month($cid, $ym);
            } catch (\Throwable $e) {
                json_err('Nie udało się przygotować protokołu: ' . $e->getMessage(), 500);
            }
            $pr = ti_protocol_get((int)$created['id']);
        }
        if (!$pr || !k30_ti_instructor_owns_course($instructor_id, (int)$pr['course_id'])) {
            json_err('Brak dostępu do tego protokołu.', 403);
        }

        $pdf = ti_protocol_pdf($pr);
        if ($pdf === null) json_err('Nie udało się wygenerować PDF protokołu.', 500);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . ti_protocol_pdf_filename($pr) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // ── nieobecności (widok zbiorczy) ───────────────────────────────────────────────
    // Odpowiednik _tab_nieobecnosci.php, ale zbiorczo dla WSZYSTKICH własnych
    // kursów naraz (klasyczny panel pokazuje jeden kurs na raz, po
    // przełączeniu $cur_course) — filtrowanie po grupie robi się po stronie
    // frontu (ten sam wzorzec co Lekcje/Zadania/Materiały: InstructorCourseContextService).
    // Akcje (usprawiedliw/cofnij) reużywają istniejące action=cancel_attendee/
    // restore_attendee z Lekcji — to dokładnie te same funkcje
    // (k30_ti_cancel_attendance/k30_ti_uncancel_attendance) co
    // excuse_absence/unexcuse_absence w klasycznym panelu. Oznaczanie "nie
    // pojawił się" (z uploadem zrzutu ekranu jako dowodu) zostaje na razie w
    // klasycznym panelu — tu tylko podgląd i cofnięcie takiego oznaczenia.
    case 'absences': {
        $course_ids = instructor_course_ids($instructor_id);
        if (!$course_ids) json_ok([]);
        $tracked_ids = array_values(array_filter($course_ids, fn($cid) => k30_ti_course_tracks_attendance($cid)));
        if (!$tracked_ids) json_ok([]);
        $ph = implode(',', array_fill(0, count($tracked_ids), '?'));
        $rows = db_all(
            "SELECT a.session_id, a.client_id, a.cancelled, a.cancel_reason, a.cancelled_by, a.cancelled_at,
                    a.no_show, a.no_show_billing, a.no_show_reason,
                    s.course_id, c.name AS course_name, s.lesson_date, s.time_from, s.topic, cl.name AS client_name
             FROM k30_ti_attendance a
             JOIN k30_ti_sessions s ON s.id=a.session_id
             JOIN k30_ti_courses c ON c.id=s.course_id
             JOIN k30_clients cl ON cl.id=a.client_id
             WHERE s.course_id IN ($ph) AND s.status IN ('held', 'individual_change')
               AND COALESCE(a.attended,0)=0 AND COALESCE(a.cancel_pending,0)=0
             ORDER BY s.lesson_date DESC, s.time_from DESC, cl.name COLLATE NOCASE",
            $tracked_ids
        );
        json_ok($rows);
    }

    // ── program nauczania (sylabus kursu) ───────────────────────────────────────────
    // Odpowiednik _tab_program.php: CRUD tematów realizowanych w tym kursie
    // (k30_ti_curriculum) + zmiana kolejności. Świadomie NIE przeniesione w
    // tym kroku: import CSV (curr_import), formularz zbiorczy 5 wierszy
    // (curr_bulk) i podgląd wymagań/kryteriów z sylabusa wzorcowego przedmiotu
    // (_syllabus_ref.php, ti_course_syllabus/ti_syllabus_coverage) — to
    // dodatkowe, drugorzędne ścieżki wobec podstawowego CRUD-u, zostają na
    // razie w klasycznym panelu.
    case 'curriculum': {
        $cid = (int)($_GET['course_id'] ?? 0);
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);
        json_ok(k30_ti_curriculum_list($cid));
    }

    case 'curriculum_save': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $cid  = (int)($body['course_id'] ?? 0);
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') json_err('Podaj temat.');

        $itemId = (int)($body['item_id'] ?? 0);
        if ($itemId) {
            $existing = k30_ti_curriculum_get($itemId);
            if (!$existing || (int)$existing['course_id'] !== $cid) json_err('Brak dostępu do tej pozycji.', 403);
        }
        $data = [
            'course_id' => $cid, 'section' => (string)($body['section'] ?? ''), 'title' => $title,
            'description' => (string)($body['description'] ?? ''), 'est_minutes' => (int)($body['est_minutes'] ?? 0),
            'is_active' => !empty($body['is_active']),
        ];
        k30_ti_curriculum_save($data, $itemId ?: null, $instructor_id);
        json_ok(null, $itemId ? 'Pozycja zaktualizowana.' : 'Pozycja dodana.');
    }

    case 'curriculum_delete': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $cid  = (int)($body['course_id'] ?? 0);
        $itemId = (int)($body['item_id'] ?? 0);
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);
        $existing = $itemId ? k30_ti_curriculum_get($itemId) : null;
        if (!$existing || (int)$existing['course_id'] !== $cid) json_err('Brak dostępu do tej pozycji.', 403);
        k30_ti_curriculum_delete($itemId);
        json_ok(null, 'Pozycja usunięta.');
    }

    case 'curriculum_move': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $cid    = (int)($body['course_id'] ?? 0);
        $itemId = (int)($body['item_id'] ?? 0);
        $dir    = (string)($body['dir'] ?? '');
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);
        if (!in_array($dir, ['up', 'down'], true)) json_err('Nieprawidłowy kierunek.');

        $ids = array_map(fn($r) => (int)$r['id'], k30_ti_curriculum_list($cid));
        $idx = array_search($itemId, $ids, true);
        if ($idx === false) json_err('Brak dostępu do tej pozycji.', 403);
        $swapWith = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swapWith >= 0 && $swapWith < count($ids)) {
            [$ids[$idx], $ids[$swapWith]] = [$ids[$swapWith], $ids[$idx]];
            k30_ti_curriculum_reorder($cid, $ids);
        }
        json_ok(null, 'Kolejność zaktualizowana.');
    }

    // Dodawanie kilku tematów naraz bez pliku CSV — odpowiednik op=curr_bulk
    // (formularz kilku wierszy w _tab_program.php): wiersze z pustym tematem
    // są pomijane, reszta dokłada się na koniec sylabusa (position rosnąco).
    case 'curriculum_bulk': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $cid  = (int)($body['course_id'] ?? 0);
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $added = 0;
        foreach ($items as $it) {
            $title = trim((string)($it['title'] ?? ''));
            if ($title === '') continue;
            k30_ti_curriculum_save([
                'course_id' => $cid, 'section' => (string)($it['section'] ?? ''), 'title' => $title,
                'description' => (string)($it['description'] ?? ''), 'est_minutes' => (int)($it['est_minutes'] ?? 0),
                'is_active' => 1,
            ], null, $instructor_id);
            $added++;
        }
        json_ok(null, $added ? "Dodano tematów: {$added}." : 'Nie dodano nic — wpisz przynajmniej jeden temat.');
    }

    // Import sylabusa z pliku CSV albo wklejonej treści — odpowiednik
    // op=curr_import. Plik ma pierwszeństwo nad wklejoną treścią, jak w klasyku.
    case 'curriculum_import': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $cid  = (int)($body['course_id'] ?? 0);
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);

        $raw = (string)($body['csv'] ?? '');
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            if ((int)($_FILES['csv_file']['size'] ?? 0) > 2 * 1024 * 1024) {
                json_err('Plik jest za duży — sylabus w CSV nie powinien przekraczać 2 MB.');
            }
            $raw = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
            if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1250');
        }
        if (trim($raw) === '') json_err('Wgraj plik CSV albo wklej treść do zaimportowania.');

        $result = k30_ti_curriculum_import_csv($cid, $raw, $instructor_id);
        $msg = 'Zaimportowano tematów: ' . $result['added'] . '.';
        if ($result['errors']) {
            $msg .= ' Pominięto ' . count($result['errors']) . ' wiersz(y): '
                . implode('; ', array_map(fn($e) => 'linia ' . $e['line'] . ' — ' . $e['msg'], array_slice($result['errors'], 0, 5)));
        }
        json_ok(['added' => $result['added'], 'errors' => $result['errors']], $msg);
    }

    // Podgląd wymagań i kryteriów oceniania z sylabusa wzorcowego przedmiotu
    // (prowadzi je administracja, tu tylko odczyt) — odpowiednik _syllabus_ref.php.
    case 'syllabus_ref': {
        $cid = (int)($_GET['course_id'] ?? 0);
        if (!$cid || !k30_ti_instructor_owns_course($instructor_id, $cid)) json_err('Ten kurs nie jest Twój.', 403);

        $syl = ti_course_syllabus($cid);
        if (!$syl) json_ok(['syllabus' => null, 'coverage' => null, 'requirement' => [], 'criterion' => []]);

        $coverage = ti_syllabus_coverage((int)$syl['id'], $cid);
        json_ok([
            'syllabus' => ['title' => $syl['title'], 'version' => $syl['version'], 'inherited' => !empty($syl['inherited'])],
            'coverage' => $coverage,
            'requirement' => ti_syllabus_items((int)$syl['id'], 'requirement', true),
            'criterion'   => ti_syllabus_items((int)$syl['id'], 'criterion', true),
        ]);
    }

    // ── zajęcia stałe (reguła cykliczna) ─────────────────────────────────────────────
    // Odpowiednik op=save_recurring_rule/delete_recurring_rule w index.php —
    // OSOBNE od Serii lekcji (save_lesson_series): tu zapisuje się trwała
    // reguła w k30_ti_series, a wygenerowane lekcje dostają session.series_id
    // (usuwanie reguły może pociągnąć za sobą przyszłe lekcje albo je
    // zachować, odłączając od reguły). Zakres zawsze "until" (data końcowa
    // wymagana) — bez trybów count/hours jak w Serii.
    case 'recurring_rules': {
        $course_ids = instructor_course_ids($instructor_id);
        if (!$course_ids) json_ok([]);
        $ph = implode(',', array_fill(0, count($course_ids), '?'));
        $rows = db_all(
            "SELECT r.*, c.name AS course_name,
                    (SELECT COUNT(*) FROM k30_ti_sessions s WHERE s.series_id=r.id) AS sessions_count,
                    (SELECT COUNT(*) FROM k30_ti_sessions s WHERE s.series_id=r.id AND s.lesson_date >= date('now')) AS future_count
             FROM k30_ti_series r JOIN k30_ti_courses c ON c.id=r.course_id
             WHERE r.course_id IN ($ph) ORDER BY r.date_from DESC",
            $course_ids
        );
        json_ok($rows);
    }

    case 'save_recurring_rule': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();

        $cid = (int)($body['course_id'] ?? 0);
        $course_ids = instructor_course_ids($instructor_id);
        if (!$cid || !in_array($cid, $course_ids, true)) json_err('Ten kurs nie jest Twój.', 403);

        $date_from = trim((string)($body['date_from'] ?? ''));
        $date_to   = trim((string)($body['date_to'] ?? ''));
        $tf = trim((string)($body['time_from'] ?? ''));
        $tt = trim((string)($body['time_to'] ?? ''));
        $topic = trim((string)($body['topic'] ?? ''));
        $room_id = max(0, (int)($body['room_id'] ?? 0));
        if (!$date_from || !$date_to || $date_to < $date_from) json_err('Podaj poprawny zakres dat.');

        $dur = 60;
        if ($tf && $tt) {
            $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60;
            if ($m > 0) $dur = (int)$m;
        }

        $recur_mode = ($body['recur_mode'] ?? '') === 'monthly' ? 'monthly' : 'weekly';
        $every = max(1, min(8, (int)($body['weeks'] ?? 1)));
        $recur_dow = max(0, min(6, (int)($body['recur_dow'] ?? 1)));
        $recur_pos = in_array((string)($body['recur_position'] ?? '1'), ['1', '2', '3', '4', 'last'], true) ? (string)$body['recur_position'] : '1';

        $rule_dates = ti_recurrence_dates([
            'mode' => $recur_mode, 'start' => $date_from, 'every' => $every,
            'dow' => $recur_dow, 'position' => $recur_pos, 'end_mode' => 'until', 'until' => $date_to,
        ]);
        if (!$rule_dates) json_err('Wzorzec nie wygenerował żadnego terminu w podanym zakresie dat.');

        foreach ($rule_dates as $d) {
            if ($pc = ti_period_closed_for_date($d)) json_err(ti_period_closed_msg($pc) . ' Zajęcia stałe nie zostały dodane.');
        }
        // Zajęcia stałe nie mają wybranej metody (jak w klasyku) — Zoom obciążają tylko, gdy kurs ma stały link.
        $zs = ti_zoom_dates_check($cid, '', $rule_dates, $tf, $tt);
        if (!$zs['ok']) json_err(ti_zoom_conflicts_msg($zs['conflicts']) . ' Zajęcia stałe nie zostały dodane.');
        $zw = $zs['warning'] !== '' ? ' ' . $zs['warning'] : '';

        if ($room_id) {
            foreach ($rule_dates as $d) {
                $rc = pl_check_conflicts(['lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt, 'room_id' => $room_id]);
                if ($rc['hard']) json_err(($rc['hard'][0]['msg'] ?? 'Sala zajęta.') . " ({$d}) Zajęcia stałe nie zostały dodane.");
            }
        }

        $rule_id = db_insert('k30_ti_series', [
            'course_id' => $cid, 'time_from' => $tf, 'time_to' => $tt, 'interval_weeks' => $every,
            'recur_mode' => $recur_mode, 'recur_dow' => $recur_mode === 'monthly' ? $recur_dow : null,
            'recur_position' => $recur_mode === 'monthly' ? $recur_pos : '',
            'date_from' => $date_from, 'date_to' => $date_to, 'topic' => $topic, 'room_id' => $room_id ?: null,
            'created_by' => $instructor_id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]);
        $created = 0;
        foreach ($rule_dates as $d) {
            $sid = db_insert('k30_ti_sessions', [
                'course_id' => $cid, 'lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt,
                'duration_min' => $dur, 'status' => 'planned', 'topic' => $topic, 'notes' => '',
                'room_id' => $room_id ?: null, 'created_by' => $instructor_id, 'created_at' => date('Y-m-d H:i:s'),
                'series_id' => $rule_id,
            ]);
            foreach ($enrollees as $e) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
                catch (\Throwable $ex) {}
            }
            $created++;
        }
        $pattern_label = $recur_mode === 'monthly' ? 'wzorzec miesięczny' : "co {$every} tyg.";
        json_ok(null, "Zajęcia stałe dodane: {$created} lekcji ({$pattern_label})." . $zw);
    }

    case 'delete_recurring_rule': {
        if ($method !== 'POST') json_err('Method not allowed', 405);
        $body = get_body();
        $ruleId = (int)($body['rule_id'] ?? 0);
        $rule = $ruleId ? db_one("SELECT id, course_id FROM k30_ti_series WHERE id=?", [$ruleId]) : null;
        if (!$rule || !k30_ti_instructor_owns_course($instructor_id, (int)$rule['course_id'])) {
            json_err('Brak dostępu do tej reguły.', 403);
        }
        if (!empty($body['del_future'])) {
            $pdo->prepare("DELETE FROM k30_ti_sessions WHERE series_id=? AND lesson_date >= date('now') AND status='planned'")->execute([$ruleId]);
            $pdo->prepare("DELETE FROM k30_ti_series WHERE id=?")->execute([$ruleId]);
            json_ok(null, 'Usunięto regułę zajęć stałych i nadchodzące lekcje.');
        }
        $pdo->prepare("UPDATE k30_ti_sessions SET series_id=NULL WHERE series_id=?")->execute([$ruleId]);
        $pdo->prepare("DELETE FROM k30_ti_series WHERE id=?")->execute([$ruleId]);
        json_ok(null, 'Usunięto regułę zajęć stałych (istniejące lekcje zachowane).');
    }

    default:
        json_err('Nieznana akcja.', 404);
}
