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

    // Cache zadań (mod_assign) pobranych z Moodle dla przypiętych kursów.
    // first_seen_at = data pierwszego pojawienia się zadania w systemie („dodano”,
    // gdy Moodle nie zwraca daty otwarcia/allowsubmissionsfromdate).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_moodle_assignments (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        mapping_id       INTEGER NOT NULL REFERENCES k30_ti_moodle_courses(id) ON DELETE CASCADE,
        server_id        INTEGER NOT NULL DEFAULT 0,
        ti_course_id     INTEGER NOT NULL DEFAULT 0,
        moodle_course_id INTEGER NOT NULL DEFAULT 0,
        assign_id        INTEGER NOT NULL DEFAULT 0,   -- id instancji assign (do WS)
        cmid             INTEGER NOT NULL DEFAULT 0,   -- course module id (do linku)
        name             TEXT    NOT NULL DEFAULT '',
        intro            TEXT    NOT NULL DEFAULT '',
        open_at          DATETIME,                     -- allowsubmissionsfromdate (gdy > 0)
        due_at           DATETIME,                     -- duedate (gdy > 0)
        cutoff_at        DATETIME,                     -- cutoffdate (gdy > 0)
        time_modified    DATETIME,
        first_seen_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        synced_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(mapping_id, assign_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_mdl_assign_course ON k30_ti_moodle_assignments(ti_course_id)");

    // Status oddania per kursant (z mapowania e-mail → konto Moodle).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_moodle_submissions (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        assignment_row_id INTEGER NOT NULL REFERENCES k30_ti_moodle_assignments(id) ON DELETE CASCADE,
        client_id         INTEGER NOT NULL DEFAULT 0,
        moodle_user_id    INTEGER NOT NULL DEFAULT 0,
        status            TEXT    NOT NULL DEFAULT '',  -- new|draft|submitted|reopened
        submitted_at      DATETIME,
        graded            INTEGER NOT NULL DEFAULT 0,
        grade             TEXT    NOT NULL DEFAULT '',
        synced_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(assignment_row_id, client_id)
    )");
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

// ── Zadania (mod_assign) ────────────────────────────────────────────────────

/** Pomocniczo: epoch (sekundy) > 0 → 'Y-m-d H:i:s', inaczej null. */
function _ti_moodle_ts(?int $epoch): ?string {
    $epoch = (int)$epoch;
    return $epoch > 0 ? date('Y-m-d H:i:s', $epoch) : null;
}

/**
 * Synchronizuje zadania (definicje + terminy) z Moodle dla kursów przypiętych
 * do aktywnych zapisów kursanta. Pobiera świeże dane gdy cache starszy niż $ttl
 * sekund. Bezpieczne przy błędach (best-effort, nie przerywa panelu).
 */
function ti_moodle_sync_client_assignments(int $client_id, int $ttl = 1800): void {
    ti_moodle_migrate();
    // Przypięcia kursów Moodle dla aktywnych zapisów + dane serwera
    $maps = db_all(
        "SELECT mc.id AS mapping_id, mc.ti_course_id, mc.moodle_course_id,
                s.id AS server_id, s.base_url, s.token
         FROM k30_ti_moodle_courses mc
         JOIN k30_ti_moodle_servers s ON s.id=mc.server_id AND s.is_active=1
         WHERE mc.ti_course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')",
        [$client_id]
    );
    if (!$maps) return;

    // Grupuj po serwerze (jedno zapytanie WS na serwer)
    $byServer = [];
    foreach ($maps as $m) $byServer[(int)$m['server_id']][] = $m;

    foreach ($byServer as $server_maps) {
        // Świeżość: pomiń serwer, gdy wszystkie jego przypięcia odświeżone w TTL
        $mids = array_map(fn($m) => (int)$m['mapping_id'], $server_maps);
        $in   = implode(',', array_fill(0, count($mids), '?'));
        $stale = db_one(
            "SELECT COUNT(*) AS c FROM k30_ti_moodle_courses mc
             WHERE mc.id IN ($in)
               AND NOT EXISTS (
                 SELECT 1 FROM k30_ti_moodle_assignments a
                 WHERE a.mapping_id=mc.id AND a.synced_at > datetime('now', ?))",
            array_merge($mids, ['-' . (int)$ttl . ' seconds'])
        );
        // Gdy istnieją przypięcia bez świeżych zadań → odśwież (też pierwszy raz)
        $needs = ((int)($stale['c'] ?? 0)) > 0;
        if (!$needs) continue;

        $first = $server_maps[0];
        try {
            $api = ti_moodle_api(['base_url' => $first['base_url'], 'token' => $first['token']]);
            // mapa moodle_course_id → mapping (w obrębie serwera)
            $courseIds = []; $mapByCourse = [];
            foreach ($server_maps as $m) {
                $cid = (int)$m['moodle_course_id'];
                if ($cid > 0) { $courseIds[] = $cid; $mapByCourse[$cid] = $m; }
            }
            if (!$courseIds) continue;
            $courses = $api->get_assignments(array_values(array_unique($courseIds)));
            foreach ($courses as $co) {
                $cid = (int)($co['id'] ?? 0);
                $m   = $mapByCourse[$cid] ?? null;
                if (!$m) continue;
                foreach (($co['assignments'] ?? []) as $as) {
                    $assign_id = (int)($as['id'] ?? 0);
                    if (!$assign_id) continue;
                    $row = [
                        'mapping_id'       => (int)$m['mapping_id'],
                        'server_id'        => (int)$m['server_id'],
                        'ti_course_id'     => (int)$m['ti_course_id'],
                        'moodle_course_id' => $cid,
                        'assign_id'        => $assign_id,
                        'cmid'             => (int)($as['cmid'] ?? 0),
                        'name'             => mb_substr((string)($as['name'] ?? ''), 0, 300),
                        'intro'            => mb_substr(trim(strip_tags((string)($as['intro'] ?? ''))), 0, 1000),
                        'open_at'          => _ti_moodle_ts($as['allowsubmissionsfromdate'] ?? 0),
                        'due_at'           => _ti_moodle_ts($as['duedate'] ?? 0),
                        'cutoff_at'        => _ti_moodle_ts($as['cutoffdate'] ?? 0),
                        'time_modified'    => _ti_moodle_ts($as['timemodified'] ?? 0),
                        'synced_at'        => date('Y-m-d H:i:s'),
                    ];
                    $ex = db_one("SELECT id FROM k30_ti_moodle_assignments WHERE mapping_id=? AND assign_id=?",
                                 [(int)$m['mapping_id'], $assign_id]);
                    if ($ex) {
                        $set=[]; $p=[]; foreach ($row as $k=>$v){ $set[]="$k=?"; $p[]=$v; } $p[]=(int)$ex['id'];
                        db()->prepare("UPDATE k30_ti_moodle_assignments SET ".implode(',',$set)." WHERE id=?")->execute($p);
                    } else {
                        $row['first_seen_at'] = date('Y-m-d H:i:s');
                        db_insert('k30_ti_moodle_assignments', $row);
                    }
                }
            }
        } catch (\Throwable $e) { /* best-effort — pomiń serwer */ }
    }

    // Status oddania per kursant (osobny TTL, ograniczona liczba zapytań WS)
    ti_moodle_sync_client_submissions($client_id, 600, 25);
}

/**
 * Pobiera status oddania zadań dla kursanta (mod_assign_get_submission_status).
 * Resolwuje konto Moodle po e-mailu klienta. Odświeża gdy starsze niż $ttl;
 * ogranicza do $limit zapytań WS na wywołanie. Best-effort.
 */
function ti_moodle_sync_client_submissions(int $client_id, int $ttl = 600, int $limit = 25): void {
    $client = db_one("SELECT email FROM k30_clients WHERE id=?", [$client_id]);
    $email  = trim((string)($client['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    // Zadania kursanta wymagające odświeżenia statusu (najbliższe terminy first)
    $rows = db_all(
        "SELECT a.id, a.assign_id, a.server_id,
                sv.base_url, sv.token,
                sub.moodle_user_id AS prev_uid
         FROM k30_ti_moodle_assignments a
         JOIN k30_ti_moodle_servers sv ON sv.id=a.server_id AND sv.is_active=1
         LEFT JOIN k30_ti_moodle_submissions sub ON sub.assignment_row_id=a.id AND sub.client_id=?
         WHERE a.ti_course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
           AND (sub.synced_at IS NULL OR sub.synced_at <= datetime('now', ?))
         ORDER BY COALESCE(a.due_at,'9999') ASC
         LIMIT ?",
        [$client_id, $client_id, '-' . (int)$ttl . ' seconds', $limit]
    );
    if (!$rows) return;

    $uidCache = []; // server_id → moodle user id (rozwiązany raz na serwer)
    foreach ($rows as $r) {
        $sid = (int)$r['server_id'];
        try {
            $api = ti_moodle_api(['base_url' => $r['base_url'], 'token' => $r['token']]);
            if (!array_key_exists($sid, $uidCache)) {
                $prev = (int)($r['prev_uid'] ?? 0);
                if ($prev > 0) { $uidCache[$sid] = $prev; }
                else { $mu = $api->find_user($email); $uidCache[$sid] = $mu ? (int)$mu['id'] : 0; }
            }
            $uid = $uidCache[$sid];
            if ($uid <= 0) continue;

            $st     = $api->get_submission_status((int)$r['assign_id'], $uid);
            $la     = $st['lastattempt']['submission'] ?? [];
            $status = (string)($la['status'] ?? 'new');
            $subAt  = _ti_moodle_ts(($status === 'submitted') ? (int)($la['timemodified'] ?? 0) : 0);
            $gradeI = $st['feedback']['grade']['grade'] ?? null;
            $graded = ($gradeI !== null && $gradeI !== '' && $gradeI !== false) ? 1 : 0;
            $grade  = $graded ? (string)round((float)$gradeI, 2) : '';

            $data = [
                'moodle_user_id' => $uid, 'status' => $status, 'submitted_at' => $subAt,
                'graded' => $graded, 'grade' => $grade, 'synced_at' => date('Y-m-d H:i:s'),
            ];
            $ex = db_one("SELECT id FROM k30_ti_moodle_submissions WHERE assignment_row_id=? AND client_id=?",
                         [(int)$r['id'], $client_id]);
            if ($ex) {
                $set=[]; $p=[]; foreach ($data as $k=>$v){ $set[]="$k=?"; $p[]=$v; } $p[]=(int)$ex['id'];
                db()->prepare("UPDATE k30_ti_moodle_submissions SET ".implode(',',$set)." WHERE id=?")->execute($p);
            } else {
                db_insert('k30_ti_moodle_submissions', array_merge($data,
                    ['assignment_row_id' => (int)$r['id'], 'client_id' => $client_id]));
            }
        } catch (\Throwable $e) { /* best-effort */ }
    }
}

/**
 * Zadania Moodle kursanta (z cache) wraz ze statusem oddania.
 * Zwraca wiersze z: name, course/server, open_at, due_at, first_seen_at, cmid,
 * base_url (do linku), sub_status, sub_submitted_at, sub_graded, sub_grade.
 */
function ti_moodle_assignments_for_client(int $client_id): array {
    ti_moodle_migrate();
    return db_all(
        "SELECT a.*, c.name AS ti_course_name, sv.name AS server_name, sv.base_url,
                sub.status AS sub_status, sub.submitted_at AS sub_submitted_at,
                sub.graded AS sub_graded, sub.grade AS sub_grade
         FROM k30_ti_moodle_assignments a
         JOIN k30_ti_courses c ON c.id=a.ti_course_id
         JOIN k30_ti_moodle_servers sv ON sv.id=a.server_id
         LEFT JOIN k30_ti_moodle_submissions sub ON sub.assignment_row_id=a.id AND sub.client_id=?
         WHERE a.ti_course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
         ORDER BY COALESCE(a.due_at,'9999') ASC, a.name",
        [$client_id, $client_id]
    );
}

/** Bezpośredni link do zadania (mod/assign) w Moodle. */
function ti_moodle_assign_url(string $base_url, int $cmid): string {
    return rtrim($base_url, '/') . '/mod/assign/view.php?id=' . $cmid;
}
