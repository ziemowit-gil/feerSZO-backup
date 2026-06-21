<?php
/**
 * includes/ti_moodle.php — Wieloserwerowa integracja TI z Moodle (niezależna od SZO).
 *
 * Pozwala podpiąć dowolną liczbę serwerów Moodle (URL + token Web Services) oraz
 * przypiąć już istniejące kursy Moodle do grup/kursów TI (k30_ti_courses).
 * Korzysta z klasy MoodleAPI (includes/moodle.php) z per-serwerowymi danymi.
 */
require_once __DIR__ . '/moodle.php';

function ti_moodle_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    // Rejestr serwerów Moodle
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_moodle_servers (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL DEFAULT '',
        base_url    TEXT    NOT NULL DEFAULT '',   -- np. https://moodle.example.org
        token       TEXT    NOT NULL DEFAULT '',   -- token Web Services
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Przypięcie istniejącego kursu Moodle do kursu/grupy TI
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_moodle_courses (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        ti_course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        server_id        INTEGER NOT NULL REFERENCES k30_ti_moodle_servers(id) ON DELETE CASCADE,
        moodle_course_id INTEGER NOT NULL DEFAULT 0,
        fullname         TEXT    NOT NULL DEFAULT '',
        shortname        TEXT    NOT NULL DEFAULT '',
        created_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(ti_course_id, server_id, moodle_course_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_mdl_course_ti ON k30_ti_moodle_courses(ti_course_id)");
}

// ── Serwery ─────────────────────────────────────────────────────────────────
function ti_moodle_servers(bool $active_only = false): array {
    ti_moodle_migrate();
    $w = $active_only ? "WHERE is_active=1" : "";
    return db_all("SELECT * FROM k30_ti_moodle_servers $w ORDER BY is_active DESC, name");
}

function ti_moodle_server_get(int $id): ?array {
    ti_moodle_migrate();
    return db_one("SELECT * FROM k30_ti_moodle_servers WHERE id=?", [$id]) ?: null;
}

/** Tworzy MoodleAPI nakierowany na dany serwer. */
function ti_moodle_api(array $server): MoodleAPI {
    return new MoodleAPI(['url' => $server['base_url'], 'token' => $server['token']]);
}

/** Test połączenia z serwerem. Zwraca ['ok'=>bool,'msg'=>string,'sitename'=>?]. */
function ti_moodle_test(array $server): array {
    try {
        $info = ti_moodle_api($server)->site_info();
        return ['ok' => true, 'sitename' => $info['sitename'] ?? '', 'release' => $info['release'] ?? '',
                'msg' => 'Połączono: ' . ($info['sitename'] ?? '?') . (isset($info['release']) ? ' (Moodle ' . $info['release'] . ')' : '')];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/** Lista kursów na serwerze (uproszczona). Zwraca [['id','fullname','shortname'], ...]. */
function ti_moodle_remote_courses(array $server): array {
    $out = [];
    foreach (ti_moodle_api($server)->get_courses() as $c) {
        if ((int)($c['id'] ?? 0) <= 1) continue; // pomiń kurs systemowy (id=1)
        $out[] = ['id' => (int)$c['id'], 'fullname' => $c['fullname'] ?? '', 'shortname' => $c['shortname'] ?? ''];
    }
    usort($out, fn($a, $b) => strcasecmp($a['fullname'], $b['fullname']));
    return $out;
}

// ── Przypięcia kursów ─────────────────────────────────────────────────────────
/** Przypięte kursy Moodle dla danego kursu TI (z danymi serwera). */
function ti_moodle_courses_for_ti(int $ti_course_id): array {
    ti_moodle_migrate();
    return db_all(
        "SELECT mc.*, s.name AS server_name, s.base_url, s.is_active AS server_active
         FROM k30_ti_moodle_courses mc
         JOIN k30_ti_moodle_servers s ON s.id=mc.server_id
         WHERE mc.ti_course_id=? ORDER BY s.name, mc.fullname",
        [$ti_course_id]
    );
}

/** Wszystkie przypięcia (widok admina). */
function ti_moodle_courses_all(): array {
    ti_moodle_migrate();
    return db_all(
        "SELECT mc.*, s.name AS server_name, s.base_url, c.name AS ti_course_name
         FROM k30_ti_moodle_courses mc
         JOIN k30_ti_moodle_servers s ON s.id=mc.server_id
         JOIN k30_ti_courses c ON c.id=mc.ti_course_id
         ORDER BY c.name, s.name"
    );
}

/** Kursy Moodle dostępne dla kursanta (po jego aktywnych zapisach TI). */
function ti_moodle_courses_for_client(int $client_id): array {
    ti_moodle_migrate();
    return db_all(
        "SELECT mc.*, s.name AS server_name, s.base_url, c.name AS ti_course_name
         FROM k30_ti_moodle_courses mc
         JOIN k30_ti_moodle_servers s ON s.id=mc.server_id AND s.is_active=1
         JOIN k30_ti_courses c ON c.id=mc.ti_course_id
         WHERE mc.ti_course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
         ORDER BY c.name, mc.fullname",
        [$client_id]
    );
}

/** Bezpośredni link do kursu Moodle. */
function ti_moodle_course_url(string $base_url, int $moodle_course_id): string {
    return rtrim($base_url, '/') . '/course/view.php?id=' . $moodle_course_id;
}

/**
 * „Głęboki" zapis: zapisuje aktywnych kursantów grupy TI do przypiętego kursu Moodle
 * po adresie e-mail (find-or-create + enrol). Zwraca podsumowanie ['enrolled','created','skipped','errors'].
 */
function ti_moodle_enroll_ti_course(int $mapping_id): array {
    ti_moodle_migrate();
    $m = db_one(
        "SELECT mc.*, s.base_url, s.token FROM k30_ti_moodle_courses mc
         JOIN k30_ti_moodle_servers s ON s.id=mc.server_id WHERE mc.id=?",
        [$mapping_id]
    );
    if (!$m) throw new \RuntimeException('Nie znaleziono przypięcia.');
    $api = ti_moodle_api(['base_url' => $m['base_url'], 'token' => $m['token']]);

    $students = db_all(
        "SELECT DISTINCT cl.id, cl.name, cl.email
         FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active'",
        [(int)$m['ti_course_id']]
    );
    $res = ['enrolled' => 0, 'created' => 0, 'skipped' => 0, 'errors' => []];
    foreach ($students as $st) {
        $email = trim((string)($st['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $res['skipped']++; continue; }
        try {
            $mu  = $api->find_user($email);
            $uid = $mu ? (int)$mu['id'] : 0;
            if (!$uid) { $uid = $api->create_user($st['name'], $email); $res['created']++; }
            if ($uid) { $api->enroll_user($uid, (int)$m['moodle_course_id']); $res['enrolled']++; }
        } catch (\Throwable $e) {
            $res['errors'][] = ($st['name'] ?? $email) . ': ' . $e->getMessage();
        }
    }
    return $res;
}
