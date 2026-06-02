<?php
/**
 * Moodle integration — DB migration, settings helpers, REST API wrapper, enrollment logic.
 */

/**
 * Odczytuje hasło panelu zapisane w sesji podczas logowania.
 * Hasło jest zaciemnione XOR z session_id() — nie jest plain text w pamięci PHP.
 * Zwraca string lub '' jeśli niedostępne.
 */
function _moodle_recover_pwd(): string {
    if (session_status() === PHP_SESSION_NONE) return '';
    $enc = $_SESSION['_moodle_pwd'] ?? '';
    if (!$enc) return '';
    $raw = base64_decode($enc);
    $key = str_repeat(session_id(), (int)ceil(strlen($raw) / 32));
    return substr($raw ^ $key, 0, strlen($raw));
}

function _moodle_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // Ustawienia integracji w tabeli settings (moodle_url, moodle_token, moodle_enabled)
    // Kursy — definicja po stronie systemu (synchronizacja lub ręczne dodanie)
    $pdo->exec("CREATE TABLE IF NOT EXISTS moodle_courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        moodle_course_id INTEGER NOT NULL DEFAULT 0,
        shortname TEXT NOT NULL DEFAULT '',
        fullname TEXT NOT NULL,
        summary TEXT NOT NULL DEFAULT '',
        category TEXT NOT NULL DEFAULT '',
        visible INTEGER NOT NULL DEFAULT 1,
        requires_approval INTEGER NOT NULL DEFAULT 0,
        max_participants INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Zapisy na kursy
    $pdo->exec("CREATE TABLE IF NOT EXISTS moodle_enrollments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        course_id INTEGER NOT NULL REFERENCES moodle_courses(id) ON DELETE CASCADE,
        status TEXT NOT NULL DEFAULT 'oczekuje',
        moodle_enrolled INTEGER NOT NULL DEFAULT 0,
        moodle_user_id INTEGER DEFAULT NULL,
        note TEXT NOT NULL DEFAULT '',
        admin_note TEXT NOT NULL DEFAULT '',
        enrolled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, course_id)
    )");
}

// ── Helpery ustawień ──────────────────────────────────────────────────────────

function moodle_setting(string $key): string {
    return org_setting('moodle_' . $key);
}

function moodle_enabled(): bool {
    return moodle_setting('enabled') === '1';
}

function moodle_configured(): bool {
    return moodle_setting('url') !== '' && moodle_setting('token') !== '';
}

// ── Kursy ─────────────────────────────────────────────────────────────────────

function moodle_courses_all(bool $visible_only = false): array {
    _moodle_init();
    $w = $visible_only ? 'WHERE visible=1' : '';
    return db_all("SELECT * FROM moodle_courses $w ORDER BY sort_order, fullname");
}

function moodle_course_get(int $id): ?array {
    _moodle_init();
    return db_one("SELECT * FROM moodle_courses WHERE id=?", [$id]);
}

// ── Zapisy ────────────────────────────────────────────────────────────────────

function moodle_user_enrollments(int $user_id): array {
    _moodle_init();
    return db_all(
        "SELECT e.*, c.fullname, c.shortname, c.category, c.moodle_course_id, c.summary
         FROM moodle_enrollments e JOIN moodle_courses c ON c.id = e.course_id
         WHERE e.user_id = ? ORDER BY e.created_at DESC",
        [$user_id]
    );
}

function moodle_enrollment_exists(int $user_id, int $course_id): bool {
    _moodle_init();
    return (bool)db_one("SELECT id FROM moodle_enrollments WHERE user_id=? AND course_id=?", [$user_id, $course_id]);
}

function moodle_enrollments_all(string $status = ''): array {
    _moodle_init();
    $w = $status ? 'WHERE e.status=?' : '';
    $p = $status ? [$status] : [];
    return db_all(
        "SELECT e.*, u.name AS user_name, u.email AS user_email,
                c.fullname AS course_name, c.moodle_course_id
         FROM moodle_enrollments e
         JOIN users u ON u.id = e.user_id
         JOIN moodle_courses c ON c.id = e.course_id
         $w ORDER BY e.created_at DESC",
        $p
    );
}

// ── REST API wrapper ──────────────────────────────────────────────────────────

class MoodleAPI {
    private string $base;
    private string $token;

    public function __construct() {
        $url = rtrim(moodle_setting('url'), '/');
        $this->base  = $url . '/webservice/rest/server.php';
        $this->token = moodle_setting('token');
    }

    private function call(string $fn, array $params = []): mixed {
        $params['wstoken']       = $this->token;
        $params['wsfunction']    = $fn;
        $params['moodlewsrestformat'] = 'json';

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query($params),
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $resp = @file_get_contents($this->base, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Brak połączenia z Moodle.');
        $data = json_decode($resp, true);
        if (isset($data['exception'])) {
            throw new \RuntimeException($data['message'] ?? $data['exception']);
        }
        return $data;
    }

    /** Pobierz listę kursów z Moodle. */
    public function get_courses(): array {
        return $this->call('core_course_get_courses') ?? [];
    }

    /** Znajdź użytkownika Moodle po emailu. */
    public function find_user(string $email): ?array {
        $r = $this->call('core_user_get_users', [
            'criteria[0][key]'   => 'email',
            'criteria[0][value]' => $email,
        ]);
        return !empty($r['users'][0]) ? $r['users'][0] : null;
    }

    /** Utwórz użytkownika w Moodle i zwróć jego ID. */
    public function create_user(string $name, string $email, string $password = ''): int {
        $parts    = explode(' ', $name . ' ', 2);
        $username = strtolower(preg_replace('/[^a-z0-9._-]/', '', $email));
        if (!$username) $username = 'user_' . time();

        // Użyj hasła z panelu jeśli dostępne, w przeciwnym razie wygeneruj
        if (!$password) {
            $password = _moodle_recover_pwd() ?: (bin2hex(random_bytes(6)) . 'Aa1!');
        }

        $r = $this->call('core_user_create_users', [
            'users[0][username]'  => $username,
            'users[0][firstname]' => trim($parts[0]) ?: $name,
            'users[0][lastname]'  => trim($parts[1]) ?: '.',
            'users[0][email]'     => $email,
            'users[0][password]'  => $password,
            'users[0][auth]'      => 'manual',
        ]);
        return (int)($r[0]['id'] ?? 0);
    }

    /** Zapisz użytkownika na kurs. */
    public function enroll_user(int $moodle_user_id, int $moodle_course_id, int $role_id = 5): void {
        $this->call('enrol_manual_enrol_users', [
            'enrolments[0][roleid]'   => $role_id,
            'enrolments[0][userid]'   => $moodle_user_id,
            'enrolments[0][courseid]' => $moodle_course_id,
        ]);
    }

    /** Sprawdź połączenie — zwraca dane serwisu lub rzuca wyjątek. */
    public function site_info(): array {
        return $this->call('core_webservice_get_site_info') ?? [];
    }
}

/**
 * Zatwierdź zapis: znajdź/utwórz użytkownika w Moodle, zapisz na kurs, zaktualizuj DB.
 * Rzuca RuntimeException przy błędzie.
 */
function moodle_approve_enrollment(int $enrollment_id): void {
    _moodle_init();
    $enr = db_one(
        "SELECT e.*, u.name AS user_name, u.email AS user_email, c.moodle_course_id
         FROM moodle_enrollments e
         JOIN users u ON u.id = e.user_id
         JOIN moodle_courses c ON c.id = e.course_id
         WHERE e.id=?",
        [$enrollment_id]
    );
    if (!$enr) throw new \RuntimeException('Nie znaleziono zapisu.');

    $api = new MoodleAPI();

    // Znajdź lub utwórz użytkownika
    $mu = $api->find_user($enr['user_email']);
    $moodle_uid = $mu ? (int)$mu['id'] : $api->create_user($enr['user_name'], $enr['user_email'], _moodle_recover_pwd());
    if (!$moodle_uid) throw new \RuntimeException('Nie można znaleźć/stworzyć użytkownika w Moodle.');

    // Zapis na kurs
    $api->enroll_user($moodle_uid, (int)$enr['moodle_course_id']);

    db_update('moodle_enrollments', [
        'status'         => 'zatwierdzony',
        'moodle_enrolled'=> 1,
        'moodle_user_id' => $moodle_uid,
        'enrolled_at'    => date('Y-m-d H:i:s'),
    ], $enrollment_id);
}
