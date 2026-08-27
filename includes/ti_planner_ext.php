<?php
/**
 * includes/ti_planner_ext.php — SZO Planner Extended: infrastruktura, ścieżki, drafty, żetony.
 *
 * Rozbudowuje podstawowy ti_planner.php o:
 *   k30_pl_rooms, k30_pl_laptop_pool, k30_pl_laptop_loans, k30_pl_online_meetings
 *   k30_pl_tech_paths, k30_pl_session_staff, k30_pl_cycle_templates
 *   k30_pl_schedule_drafts, k30_pl_audit_log
 *   k30_pl_token_wallets, k30_pl_token_transactions, k30_pl_token_prices
 *   k30_pl_baskets, k30_pl_basket_items
 * Dodaje kolumny do k30_ti_courses i k30_ti_sessions (bezpieczny ALTER).
 */

declare(strict_types=1);

const PL_BREAK_RULES = [
    'theory'      => ['max_min' => 120, 'break_min' => 10],
    'workshop'    => ['max_min' => 90,  'break_min' => 15],
    'lab'         => ['max_min' => 60,  'break_min' => 10],
    'code_review' => ['max_min' => 60,  'break_min' => 10],
    'project'     => ['max_min' => 120, 'break_min' => 20],
];

const PL_STAFF_LIMITS = [
    'main'      => ['day_h' => 8,  'week_h' => 40, 'gap_min' => 30],
    'mentor'    => ['day_h' => 6,  'week_h' => 30, 'gap_min' => 15],
    'assistant' => ['day_h' => 8,  'week_h' => 40, 'gap_min' => 0],
];

/* ── MIGRACJA ─────────────────────────────────────────────────────────────── */

function ti_planner_ext_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_rooms (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        name            TEXT    NOT NULL,
        capacity        INTEGER NOT NULL DEFAULT 20,
        workstations    INTEGER NOT NULL DEFAULT 0,
        has_projector   INTEGER NOT NULL DEFAULT 0,
        has_dual_mon    INTEGER NOT NULL DEFAULT 0,
        laptop_pool_cnt INTEGER NOT NULL DEFAULT 0,
        mode_support    TEXT    NOT NULL DEFAULT 'onsite',
        location        TEXT    NOT NULL DEFAULT '',
        notes           TEXT    NOT NULL DEFAULT '',
        is_active       INTEGER NOT NULL DEFAULT 1,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_laptop_pool (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        asset_tag TEXT    NOT NULL UNIQUE,
        model     TEXT    NOT NULL DEFAULT '',
        condition TEXT    NOT NULL DEFAULT 'good',
        room_id   INTEGER REFERENCES k30_pl_rooms(id) ON DELETE SET NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        notes     TEXT    NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_laptop_loans (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        laptop_id   INTEGER NOT NULL REFERENCES k30_pl_laptop_pool(id),
        session_id  INTEGER NOT NULL REFERENCES k30_ti_sessions(id) ON DELETE CASCADE,
        client_id   INTEGER,
        loaned_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        returned_at DATETIME
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_laptop_active
        ON k30_pl_laptop_loans(laptop_id) WHERE returned_at IS NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_online_meetings (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id  INTEGER NOT NULL UNIQUE REFERENCES k30_ti_sessions(id) ON DELETE CASCADE,
        platform    TEXT    NOT NULL DEFAULT 'zoom',
        meeting_url TEXT    NOT NULL DEFAULT '',
        meeting_id  TEXT    NOT NULL DEFAULT '',
        password    TEXT    NOT NULL DEFAULT '',
        host_email  TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_tech_paths (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        name           TEXT    NOT NULL,
        code           TEXT    NOT NULL UNIQUE,
        description    TEXT    NOT NULL DEFAULT '',
        color_hex      TEXT    NOT NULL DEFAULT '#60A5FA',
        icon           TEXT    NOT NULL DEFAULT '',
        prereq_path_id INTEGER REFERENCES k30_pl_tech_paths(id),
        is_active      INTEGER NOT NULL DEFAULT 1
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_session_staff (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL REFERENCES k30_ti_sessions(id) ON DELETE CASCADE,
        user_id    INTEGER NOT NULL REFERENCES users(id),
        role       TEXT    NOT NULL DEFAULT 'mentor',
        is_primary INTEGER NOT NULL DEFAULT 0,
        added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(session_id, user_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ss_session ON k30_pl_session_staff(session_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ss_user    ON k30_pl_session_staff(user_id, session_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_cycle_templates (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        name           TEXT    NOT NULL,
        repeat_type    TEXT    NOT NULL DEFAULT 'weekly',
        days_of_week   TEXT    NOT NULL DEFAULT '[1,3]',
        time_from      TEXT    NOT NULL DEFAULT '09:00',
        time_to        TEXT    NOT NULL DEFAULT '17:00',
        duration_weeks INTEGER NOT NULL DEFAULT 12,
        skip_holidays  INTEGER NOT NULL DEFAULT 1,
        block_type     TEXT    NOT NULL DEFAULT 'theory',
        created_by     INTEGER,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_schedule_drafts (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        title         TEXT    NOT NULL,
        status        TEXT    NOT NULL DEFAULT 'draft',
        base_draft_id INTEGER REFERENCES k30_pl_schedule_drafts(id),
        created_by    INTEGER,
        published_at  DATETIME,
        published_by  INTEGER,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_audit_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT    NOT NULL,
        entity_id   INTEGER NOT NULL,
        action      TEXT    NOT NULL,
        changed_by  INTEGER,
        before_json TEXT    NOT NULL DEFAULT '{}',
        after_json  TEXT    NOT NULL DEFAULT '{}',
        ip_address  TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_entity ON k30_pl_audit_log(entity_type, entity_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_time   ON k30_pl_audit_log(created_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_token_wallets (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id     INTEGER NOT NULL UNIQUE,
        balance       INTEGER NOT NULL DEFAULT 0,
        balance_hold  INTEGER NOT NULL DEFAULT 0,
        total_granted INTEGER NOT NULL DEFAULT 0,
        total_spent   INTEGER NOT NULL DEFAULT 0,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_token_transactions (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        wallet_id  INTEGER NOT NULL REFERENCES k30_pl_token_wallets(id),
        amount     INTEGER NOT NULL,
        direction  TEXT    NOT NULL,
        reason     TEXT    NOT NULL,
        ref_type   TEXT    NOT NULL DEFAULT '',
        ref_id     INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tok_wallet ON k30_pl_token_transactions(wallet_id, created_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_token_prices (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id       INTEGER,
        mentor_id       INTEGER,
        path_id         INTEGER,
        level           TEXT,
        tokens_required INTEGER NOT NULL DEFAULT 1,
        valid_from      DATE,
        valid_to        DATE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_baskets (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id      INTEGER NOT NULL,
        status         TEXT    NOT NULL DEFAULT 'open',
        expires_at     DATETIME,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        checked_out_at DATETIME
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_basket_open
        ON k30_pl_baskets(client_id) WHERE status = 'open'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_basket_items (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        basket_id       INTEGER NOT NULL REFERENCES k30_pl_baskets(id) ON DELETE CASCADE,
        session_id      INTEGER NOT NULL,
        mentor_id       INTEGER,
        tokens_reserved INTEGER NOT NULL DEFAULT 0,
        status          TEXT    NOT NULL DEFAULT 'reserved',
        added_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(basket_id, session_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_token_pools (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        color         TEXT NOT NULL DEFAULT '#6366f1',
        description   TEXT NOT NULL DEFAULT '',
        period_key    TEXT NOT NULL DEFAULT '',
        valid_from    DATE,
        valid_to      DATE,
        default_grant INTEGER NOT NULL DEFAULT 0,
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_at    TEXT DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_token_pool_wallets (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        pool_id    INTEGER NOT NULL REFERENCES k30_pl_token_pools(id) ON DELETE CASCADE,
        client_id  INTEGER NOT NULL,
        granted    INTEGER NOT NULL DEFAULT 0,
        spent      INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT DEFAULT (datetime('now')),
        UNIQUE(pool_id, client_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pool_wallets ON k30_pl_token_pool_wallets(pool_id, client_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pl_token_pool_txns (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        wallet_id  INTEGER NOT NULL REFERENCES k30_pl_token_pool_wallets(id) ON DELETE CASCADE,
        amount     INTEGER NOT NULL,
        direction  TEXT NOT NULL,
        reason     TEXT NOT NULL DEFAULT '',
        created_by INTEGER,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pool_txns ON k30_pl_token_pool_txns(wallet_id, created_at)");

    // ── Bezpieczny ALTER TABLE na istniejących tabelach ───────────────
    $exist_courses  = array_column(db_all("PRAGMA table_info(k30_ti_courses)"), 'name');
    $exist_sessions = array_column(db_all("PRAGMA table_info(k30_ti_sessions)"), 'name');

    foreach ([
        'max_students'  => 'INTEGER DEFAULT 15',
        'min_students'  => 'INTEGER DEFAULT 3',
        'format'        => "TEXT DEFAULT 'regular'",
        'tech_path_id'  => 'INTEGER',
        'level'         => "TEXT DEFAULT 'beginner'",
    ] as $col => $def) {
        if (!in_array($col, $exist_courses, true)) {
            try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN $col $def"); } catch (\Throwable) {}
        }
    }

    foreach ([
        'room_id'    => 'INTEGER',
        'mode'       => "TEXT DEFAULT 'onsite'",
        'block_type' => "TEXT DEFAULT 'theory'",
        'draft_id'   => 'INTEGER',
    ] as $col => $def) {
        if (!in_array($col, $exist_sessions, true)) {
            try { $pdo->exec("ALTER TABLE k30_ti_sessions ADD COLUMN $col $def"); } catch (\Throwable) {}
        }
    }

    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_room  ON k30_ti_sessions(room_id, lesson_date)"); } catch (\Throwable) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_draft ON k30_ti_sessions(draft_id)"); } catch (\Throwable) {}
}

/* ── AUDIT ────────────────────────────────────────────────────────────────── */

function pl_audit(string $type, int $id, string $action, array $before = [], array $after = [], ?int $by = null): void {
    db_exec(
        "INSERT INTO k30_pl_audit_log (entity_type,entity_id,action,changed_by,before_json,after_json,ip_address)
         VALUES (?,?,?,?,?,?,?)",
        [$type, $id, $action, $by, json_encode($before), json_encode($after), $_SERVER['REMOTE_ADDR'] ?? '']
    );
}

function pl_audit_list(array $f = []): array {
    $where = []; $params = [];
    if (!empty($f['entity'])) { $where[] = 'entity_type=?'; $params[] = $f['entity']; }
    if (!empty($f['entity_id'])) { $where[] = 'entity_id=?'; $params[] = (int)$f['entity_id']; }
    if (!empty($f['changed_by'])) { $where[] = 'changed_by=?'; $params[] = (int)$f['changed_by']; }
    if (!empty($f['from'])) { $where[] = 'created_at>=?'; $params[] = $f['from']; }
    if (!empty($f['to']))   { $where[] = 'created_at<=?'; $params[] = $f['to']; }
    $sql = "SELECT * FROM k30_pl_audit_log" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY created_at DESC LIMIT 200";
    return db_all($sql, $params);
}

/* ── SALE ─────────────────────────────────────────────────────────────────── */

function pl_rooms_list(array $f = []): array {
    $where = ['1=1']; $params = [];
    if (isset($f['is_active'])) { $where[] = 'is_active=?'; $params[] = (int)$f['is_active']; }
    if (!empty($f['mode']))     { $where[] = "(mode_support='all' OR mode_support=?)"; $params[] = $f['mode']; }
    return db_all("SELECT * FROM k30_pl_rooms WHERE " . implode(' AND ', $where) . " ORDER BY name", $params);
}

function pl_room_get(int $id): ?array {
    return db_one("SELECT * FROM k30_pl_rooms WHERE id=?", [$id]) ?: null;
}

function pl_room_save(array $d, ?int $id = null): int {
    $fields = [
        'name'            => substr(trim($d['name'] ?? ''), 0, 120),
        'capacity'        => max(1, (int)($d['capacity'] ?? 20)),
        'workstations'    => max(0, (int)($d['workstations'] ?? 0)),
        'has_projector'   => (int)(bool)($d['has_projector'] ?? false),
        'has_dual_mon'    => (int)(bool)($d['has_dual_mon'] ?? false),
        'laptop_pool_cnt' => max(0, (int)($d['laptop_pool_cnt'] ?? 0)),
        'mode_support'    => in_array($d['mode_support'] ?? '', ['onsite','remote','hybrid','all']) ? $d['mode_support'] : 'onsite',
        'location'        => substr($d['location'] ?? '', 0, 200),
        'notes'           => substr($d['notes'] ?? '', 0, 500),
        'is_active'       => (int)(bool)($d['is_active'] ?? true),
    ];
    if ($id) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_pl_rooms SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_pl_rooms ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

/** Zwraca sesje kolidujące z podanym oknem czasowym w tej sali. */
function pl_room_availability(int $room_id, string $date, string $from, string $to, int $skip_id = 0): array {
    return db_all(
        "SELECT id,course_id,lesson_date,time_from,time_to,status FROM k30_ti_sessions
         WHERE room_id=? AND lesson_date=? AND draft_id IS NULL
           AND status NOT IN ('cancelled')
           AND time_from<? AND time_to>?" . ($skip_id ? " AND id!=?" : ''),
        $skip_id ? [$room_id, $date, $to, $from, $skip_id] : [$room_id, $date, $to, $from]
    );
}

/* ── LAPTOPY ──────────────────────────────────────────────────────────────── */

function pl_laptops_list(): array {
    return db_all(
        "SELECT lp.*,
                CASE WHEN ll.id IS NOT NULL THEN 1 ELSE 0 END AS is_loaned,
                ll.session_id AS loaned_to_session,
                ll.client_id  AS loaned_to_client
         FROM k30_pl_laptop_pool lp
         LEFT JOIN k30_pl_laptop_loans ll ON ll.laptop_id=lp.id AND ll.returned_at IS NULL
         WHERE lp.is_active=1 ORDER BY lp.asset_tag",
        []
    );
}

function pl_laptop_loan(int $laptop_id, int $session_id, ?int $client_id): int {
    $active = db_one("SELECT id FROM k30_pl_laptop_loans WHERE laptop_id=? AND returned_at IS NULL", [$laptop_id]);
    if ($active) throw new \RuntimeException('LAPTOP_BUSY');
    db_exec("INSERT INTO k30_pl_laptop_loans (laptop_id,session_id,client_id) VALUES (?,?,?)",
        [$laptop_id, $session_id, $client_id]);
    return (int)db()->lastInsertId();
}

function pl_laptop_return(int $laptop_id): bool {
    $rows = db()->prepare("UPDATE k30_pl_laptop_loans SET returned_at=CURRENT_TIMESTAMP
                           WHERE laptop_id=? AND returned_at IS NULL");
    $rows->execute([$laptop_id]);
    return $rows->rowCount() > 0;
}

/* ── SPOTKANIA ONLINE ─────────────────────────────────────────────────────── */

function pl_meeting_get(int $session_id): ?array {
    return db_one("SELECT * FROM k30_pl_online_meetings WHERE session_id=?", [$session_id]) ?: null;
}

function pl_meeting_save(int $session_id, array $d): int {
    $existing = db_one("SELECT id FROM k30_pl_online_meetings WHERE session_id=?", [$session_id]);
    $fields = [
        'platform'    => in_array($d['platform'] ?? '', ['zoom','teams','meet']) ? $d['platform'] : 'zoom',
        'meeting_url' => trim($d['meeting_url'] ?? ''),
        'meeting_id'  => trim($d['meeting_id'] ?? ''),
        'password'    => trim($d['password'] ?? ''),
        'host_email'  => trim($d['host_email'] ?? ''),
    ];
    if ($existing) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_pl_online_meetings SET $sets WHERE session_id=?", [...array_values($fields), $session_id]);
        return (int)$existing['id'];
    }
    $fields['session_id'] = $session_id;
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_pl_online_meetings ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

function pl_meeting_delete(int $session_id): void {
    db_exec("DELETE FROM k30_pl_online_meetings WHERE session_id=?", [$session_id]);
}

/* ── ŚCIEŻKI TECH ─────────────────────────────────────────────────────────── */

function pl_tech_paths_list(): array {
    return db_all("SELECT * FROM k30_pl_tech_paths WHERE is_active=1 ORDER BY name", []);
}

function pl_tech_path_save(array $d, ?int $id = null): int {
    $fields = [
        'name'           => substr(trim($d['name'] ?? ''), 0, 100),
        'code'           => strtoupper(substr(trim($d['code'] ?? ''), 0, 20)),
        'description'    => $d['description'] ?? '',
        'color_hex'      => preg_match('/^#[0-9a-fA-F]{6}$/', $d['color_hex'] ?? '') ? $d['color_hex'] : '#60A5FA',
        'icon'           => substr($d['icon'] ?? '', 0, 50),
        'prereq_path_id' => !empty($d['prereq_path_id']) ? (int)$d['prereq_path_id'] : null,
        'is_active'      => (int)(bool)($d['is_active'] ?? true),
    ];
    if ($id) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_pl_tech_paths SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_pl_tech_paths ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

/* ── KADRA SESJI ─────────────────────────────────────────────────────────── */

function pl_session_staff_list(int $session_id): array {
    return db_all(
        "SELECT ss.*, u.name AS user_name, u.email AS user_email
         FROM k30_pl_session_staff ss
         JOIN users u ON u.id=ss.user_id
         WHERE ss.session_id=? ORDER BY ss.is_primary DESC, ss.role",
        [$session_id]
    );
}

function pl_session_staff_add(int $session_id, int $user_id, string $role = 'mentor', bool $primary = false): int {
    $role = in_array($role, ['main','mentor','assistant']) ? $role : 'mentor';
    db_exec(
        "INSERT INTO k30_pl_session_staff (session_id,user_id,role,is_primary) VALUES (?,?,?,?)
         ON CONFLICT(session_id,user_id) DO UPDATE SET role=excluded.role, is_primary=excluded.is_primary",
        [$session_id, $user_id, $role, (int)$primary]
    );
    return (int)db()->lastInsertId() ?: (int)(db_one("SELECT id FROM k30_pl_session_staff WHERE session_id=? AND user_id=?", [$session_id, $user_id])['id'] ?? 0);
}

function pl_session_staff_remove(int $session_id, int $user_id): void {
    db_exec("DELETE FROM k30_pl_session_staff WHERE session_id=? AND user_id=?", [$session_id, $user_id]);
}

/* ── OBCIĄŻENIE I DOSTĘPNOŚĆ PROWADZĄCEGO ─────────────────────────────────── */

function pl_instructor_workload(int $user_id, string $week): array {
    // $week = 'YYYY-Www' → przelicz na zakres dat
    $dt  = new \DateTimeImmutable($week . '-1'); // poniedziałek
    $mon = $dt->format('Y-m-d');
    $sun = $dt->modify('+6 days')->format('Y-m-d');

    $sessions = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, s.duration_min, ss.role
         FROM k30_pl_session_staff ss
         JOIN k30_ti_sessions s ON s.id=ss.session_id
         WHERE ss.user_id=? AND s.lesson_date BETWEEN ? AND ?
           AND s.draft_id IS NULL AND s.status NOT IN ('cancelled')
         ORDER BY s.lesson_date, s.time_from",
        [$user_id, $mon, $sun]
    );

    $by_day = []; $total_min = 0;
    foreach ($sessions as $s) {
        $min = (int)$s['duration_min'] ?: (int)(strtotime($s['time_to']) - strtotime($s['time_from'])) / 60;
        $by_day[$s['lesson_date']] = ($by_day[$s['lesson_date']] ?? 0) + $min;
        $total_min += $min;
    }
    return ['week' => $week, 'by_day' => $by_day, 'total_min' => $total_min, 'sessions' => $sessions];
}

function pl_instructor_availability(int $user_id, string $week): array {
    $dt  = new \DateTimeImmutable($week . '-1');
    $mon = $dt->format('Y-m-d');
    $sun = $dt->modify('+6 days')->format('Y-m-d');

    return db_all(
        "SELECT s.id,s.course_id,s.lesson_date,s.time_from,s.time_to,s.status,ss.role
         FROM k30_pl_session_staff ss
         JOIN k30_ti_sessions s ON s.id=ss.session_id
         WHERE ss.user_id=? AND s.lesson_date BETWEEN ? AND ?
           AND s.draft_id IS NULL ORDER BY s.lesson_date, s.time_from",
        [$user_id, $mon, $sun]
    );
}

/* ── DETEKCJA KONFLIKTÓW ──────────────────────────────────────────────────── */

/**
 * Sprawdza konflikty dla proponowanej sesji.
 * Zwraca ['hard'=>[...], 'soft'=>[...]].
 */
function pl_check_conflicts(array $p): array {
    $hard = []; $soft = [];
    $date    = $p['lesson_date']  ?? '';
    $from    = $p['time_from']    ?? '';
    $to      = $p['time_to']      ?? '';
    $room_id = (int)($p['room_id'] ?? 0);
    $skip_id = (int)($p['skip_id'] ?? 0); // aktualny session_id przy edycji
    $staff   = array_map('intval', (array)($p['staff_ids'] ?? []));
    $course  = (int)($p['course_id'] ?? 0);
    $block   = $p['block_type'] ?? 'theory';

    if (!$date || !$from || !$to) return ['hard' => ['Brak wymaganych pól: lesson_date, time_from, time_to.'], 'soft' => []];

    // HARD: sala zajęta
    if ($room_id) {
        $conflicts = pl_room_availability($room_id, $date, $from, $to, $skip_id);
        if ($conflicts) {
            $hard[] = ['code' => 'CONFLICT_ROOM', 'msg' => 'Sala zajęta w tym oknie czasowym.', 'conflicts' => $conflicts];
        }
    }

    // HARD: prowadzący/mentor koliduje
    foreach ($staff as $uid) {
        $c = db_all(
            "SELECT s.id,s.course_id,s.time_from,s.time_to FROM k30_pl_session_staff ss
             JOIN k30_ti_sessions s ON s.id=ss.session_id
             WHERE ss.user_id=? AND s.lesson_date=? AND s.draft_id IS NULL
               AND s.status NOT IN ('cancelled') AND s.time_from<? AND s.time_to>?" .
            ($skip_id ? " AND s.id!=?" : ''),
            $skip_id ? [$uid, $date, $to, $from, $skip_id] : [$uid, $date, $to, $from]
        );
        if ($c) {
            $hard[] = ['code' => 'CONFLICT_INSTRUCTOR', 'msg' => "Prowadzący (id=$uid) ma inne zajęcia w tym oknie.", 'user_id' => $uid, 'conflicts' => $c];
        }
    }

    // HARD: kurs ma już sesję w tym oknie
    if ($course) {
        $c = db_all(
            "SELECT id,lesson_date,time_from,time_to FROM k30_ti_sessions
             WHERE course_id=? AND lesson_date=? AND draft_id IS NULL
               AND status NOT IN ('cancelled') AND time_from<? AND time_to>?" .
            ($skip_id ? " AND id!=?" : ''),
            $skip_id ? [$course, $date, $to, $from, $skip_id] : [$course, $date, $to, $from]
        );
        if ($c) {
            $hard[] = ['code' => 'CONFLICT_COURSE', 'msg' => 'Kurs ma już sesję w tym oknie czasowym.', 'conflicts' => $c];
        }
    }

    // HARD: konto Zoom zajęte — jeden host Zoom nie prowadzi dwóch spotkań naraz
    if ($course && function_exists('ti_zoom_slot_check')) {
        $zc = ti_zoom_slot_check(
            (int)$course, (string)($p['lesson_method'] ?? ''),
            (string)$date, (string)$from, (string)$to, (int)$skip_id
        );
        if (!$zc['ok']) {
            $hard[] = ['code' => 'CONFLICT_ZOOM', 'msg' => $zc['reason']];
        } elseif ($zc['checked'] && !$zc['api_ok']) {
            $soft[] = ['code' => 'ZOOM_API_UNAVAILABLE', 'msg' => $zc['warning']];
        }
    }

    // SOFT: długość bloku przekracza limit ergonomii
    if (isset(PL_BREAK_RULES[$block])) {
        $from_ts = strtotime($date . ' ' . $from);
        $to_ts   = strtotime($date . ' ' . $to);
        $dur_min = (int)(($to_ts - $from_ts) / 60);
        $limit   = PL_BREAK_RULES[$block]['max_min'];
        if ($dur_min > $limit) {
            $soft[] = ['code' => 'ERGONOMICS_BLOCK_TOO_LONG',
                       'msg' => "Blok '$block' trwa {$dur_min} min, zalecane max {$limit} min bez przerwy."];
        }
    }

    // SOFT: sprawdzenie limitu obciążenia kadry (uproszczone — dzienny)
    foreach ($staff as $uid) {
        $day_min = db_one(
            "SELECT SUM(s.duration_min) AS total FROM k30_pl_session_staff ss
             JOIN k30_ti_sessions s ON s.id=ss.session_id
             WHERE ss.user_id=? AND s.lesson_date=? AND s.draft_id IS NULL
               AND s.status NOT IN ('cancelled')" . ($skip_id ? " AND s.id!=?" : ''),
            $skip_id ? [$uid, $date, $skip_id] : [$uid, $date]
        )['total'] ?? 0;
        $role_row = db_one("SELECT role FROM k30_pl_session_staff WHERE user_id=? LIMIT 1", [$uid]);
        $role     = $role_row['role'] ?? 'mentor';
        $limit_h  = PL_STAFF_LIMITS[$role]['day_h'] ?? 6;
        $new_min  = (int)(($to - $from) * 1); // heuristic; real: parse times
        $from_ts  = strtotime($date . ' ' . $from);
        $to_ts    = strtotime($date . ' ' . $to);
        $new_min  = (int)(($to_ts - $from_ts) / 60);
        if ((((int)$day_min + $new_min) / 60) > $limit_h) {
            $soft[] = ['code' => 'WORKLOAD_EXCEEDED',
                       'msg' => "Prowadzący (id=$uid) przekroczy zalecany dzienny limit {$limit_h}h."];
        }
    }

    return ['hard' => $hard, 'soft' => $soft];
}

/* ── DRAFTY ──────────────────────────────────────────────────────────────── */

function pl_drafts_list(?int $created_by = null): array {
    $where = $created_by ? "WHERE created_by=?" : "";
    $params = $created_by ? [$created_by] : [];
    return db_all("SELECT * FROM k30_pl_schedule_drafts $where ORDER BY created_at DESC LIMIT 100", $params);
}

function pl_draft_get(int $id): ?array {
    $d = db_one("SELECT * FROM k30_pl_schedule_drafts WHERE id=?", [$id]);
    if (!$d) return null;
    $d['sessions'] = db_all(
        "SELECT id,course_id,lesson_date,time_from,time_to,block_type,mode,room_id,status FROM k30_ti_sessions WHERE draft_id=? ORDER BY lesson_date,time_from",
        [$id]
    );
    return $d;
}

function pl_draft_create(array $d, int $created_by): int {
    db_exec("INSERT INTO k30_pl_schedule_drafts (title,status,base_draft_id,created_by) VALUES (?,?,?,?)",
        [substr($d['title'] ?? 'Nowy draft', 0, 200), 'draft', $d['base_draft_id'] ?? null, $created_by]);
    return (int)db()->lastInsertId();
}

function pl_draft_update(int $id, array $d): void {
    $allowed = ['title', 'status'];
    $sets = []; $params = [];
    foreach ($allowed as $k) {
        if (isset($d[$k])) { $sets[] = "$k=?"; $params[] = $d[$k]; }
    }
    if (!$sets) return;
    $params[] = $id;
    db_exec("UPDATE k30_pl_schedule_drafts SET " . implode(',', $sets) . " WHERE id=?", $params);
}

function pl_draft_fork(int $id, int $created_by): int {
    $src = db_one("SELECT * FROM k30_pl_schedule_drafts WHERE id=?", [$id]);
    if (!$src) throw new \RuntimeException('DRAFT_NOT_FOUND');
    $new_id = pl_draft_create(['title' => $src['title'] . ' (kopia)', 'base_draft_id' => $id], $created_by);
    // Skopiuj sesje draftu
    $sessions = db_all("SELECT * FROM k30_ti_sessions WHERE draft_id=?", [$id]);
    foreach ($sessions as $s) {
        unset($s['id'], $s['created_at'], $s['updated_at']);
        $s['draft_id'] = $new_id;
        $cols = implode(',', array_keys($s));
        $phs  = implode(',', array_fill(0, count($s), '?'));
        db_exec("INSERT INTO k30_ti_sessions ($cols) VALUES ($phs)", array_values($s));
    }
    return $new_id;
}

function pl_draft_publish(int $id, int $published_by): bool {
    $draft = db_one("SELECT * FROM k30_pl_schedule_drafts WHERE id=?", [$id]);
    if (!$draft) throw new \RuntimeException('DRAFT_NOT_FOUND');
    if ($draft['status'] !== 'review') throw new \RuntimeException('DRAFT_NOT_REVIEW');

    $sessions = db_all("SELECT * FROM k30_ti_sessions WHERE draft_id=?", [$id]);
    foreach ($sessions as $s) {
        // Zaznacz konflikty przed publikacją
        $conflicts = pl_check_conflicts([
            'lesson_date' => $s['lesson_date'], 'time_from' => $s['time_from'],
            'time_to' => $s['time_to'], 'room_id' => $s['room_id'] ?? 0,
            'course_id' => $s['course_id'],
        ]);
        if ($conflicts['hard']) throw new \RuntimeException('HARD_CONFLICT_IN_DRAFT');
    }
    // Opublikuj: usuń draft_id
    db_exec("UPDATE k30_ti_sessions SET draft_id=NULL WHERE draft_id=?", [$id]);
    db_exec("UPDATE k30_pl_schedule_drafts SET status='published', published_at=CURRENT_TIMESTAMP, published_by=? WHERE id=?",
        [$published_by, $id]);
    pl_audit('draft', $id, 'publish', [], [], $published_by);
    return true;
}

function pl_draft_diff(int $id, int $base_id): array {
    $new_sessions  = db_all("SELECT id,course_id,lesson_date,time_from,time_to,room_id,block_type FROM k30_ti_sessions WHERE draft_id=?", [$id]);
    $base_sessions = $base_id === 0
        ? db_all("SELECT id,course_id,lesson_date,time_from,time_to,room_id,block_type FROM k30_ti_sessions WHERE draft_id IS NULL", [])
        : db_all("SELECT id,course_id,lesson_date,time_from,time_to,room_id,block_type FROM k30_ti_sessions WHERE draft_id=?", [$base_id]);

    $new_ids  = array_column($new_sessions, null, 'id');
    $base_ids = array_column($base_sessions, null, 'id');

    $added    = array_values(array_diff_key($new_ids, $base_ids));
    $removed  = array_values(array_diff_key($base_ids, $new_ids));
    $changed  = [];
    foreach (array_intersect_key($new_ids, $base_ids) as $sid => $new) {
        $old = $base_ids[$sid];
        if ($new !== $old) $changed[] = ['old' => $old, 'new' => $new];
    }
    return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
}

/* ── SZABLONY CYKLI ──────────────────────────────────────────────────────── */

function pl_cycle_templates_list(): array {
    return db_all("SELECT * FROM k30_pl_cycle_templates ORDER BY name", []);
}

function pl_cycle_template_save(array $d, ?int $id = null, ?int $by = null): int {
    $fields = [
        'name'           => substr(trim($d['name'] ?? ''), 0, 120),
        'repeat_type'    => in_array($d['repeat_type'] ?? '', ['weekly','biweekly','daily','evening']) ? $d['repeat_type'] : 'weekly',
        'days_of_week'   => json_encode(array_map('intval', (array)($d['days_of_week'] ?? [1, 3]))),
        'time_from'      => $d['time_from'] ?? '09:00',
        'time_to'        => $d['time_to'] ?? '17:00',
        'duration_weeks' => max(1, (int)($d['duration_weeks'] ?? 12)),
        'skip_holidays'  => (int)(bool)($d['skip_holidays'] ?? true),
        'block_type'     => in_array($d['block_type'] ?? '', ['theory','workshop','lab','code_review','project']) ? $d['block_type'] : 'theory',
    ];
    if ($id) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_pl_cycle_templates SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $fields['created_by'] = $by;
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_pl_cycle_templates ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

/** Rozpisuje szablon na listę proponowanych sesji (nie zapisuje do bazy). */
function pl_cycle_template_expand(int $id, int $course_id, string $start_date): array {
    $tpl = db_one("SELECT * FROM k30_pl_cycle_templates WHERE id=?", [$id]);
    if (!$tpl) throw new \RuntimeException('TEMPLATE_NOT_FOUND');

    $days_of_week = json_decode($tpl['days_of_week'], true) ?: [1, 3];
    $step = ($tpl['repeat_type'] === 'biweekly') ? 14 : 7;
    $weeks = (int)$tpl['duration_weeks'];

    $result = [];
    $cur = new \DateTimeImmutable($start_date);
    for ($w = 0; $w < $weeks; $w++) {
        foreach ($days_of_week as $dow) {
            // Znajdź następny dzień tygodnia >= cur
            $candidate = $cur->modify("this week " . ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][$dow] ?? 'Monday');
            if ($candidate < $cur) $candidate = $candidate->modify('+1 week');
            if ($w > 0) $candidate = $candidate->modify("+{$w} weeks");
            $result[] = [
                'course_id'  => $course_id,
                'lesson_date'=> $candidate->format('Y-m-d'),
                'time_from'  => $tpl['time_from'],
                'time_to'    => $tpl['time_to'],
                'block_type' => $tpl['block_type'],
            ];
        }
    }
    usort($result, fn($a, $b) => $a['lesson_date'] <=> $b['lesson_date']);
    return $result;
}

/* ── PORTFELE ŻETONÓW ─────────────────────────────────────────────────────── */

function pl_wallet_get_or_create(int $client_id): array {
    $w = db_one("SELECT * FROM k30_pl_token_wallets WHERE client_id=?", [$client_id]);
    if ($w) return $w;
    db_exec("INSERT OR IGNORE INTO k30_pl_token_wallets (client_id) VALUES (?)", [$client_id]);
    return db_one("SELECT * FROM k30_pl_token_wallets WHERE client_id=?", [$client_id]);
}

function pl_wallet_transactions(int $wallet_id, int $limit = 50): array {
    return db_all("SELECT * FROM k30_pl_token_transactions WHERE wallet_id=? ORDER BY created_at DESC LIMIT ?", [$wallet_id, $limit]);
}

function pl_tokens_grant(int $client_id, int $amount, string $reason, ?int $by = null): void {
    if ($amount <= 0) throw new \RuntimeException('INVALID_AMOUNT');
    $w = pl_wallet_get_or_create($client_id);
    db_exec("UPDATE k30_pl_token_wallets SET balance=balance+?, total_granted=total_granted+?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
        [$amount, $amount, $w['id']]);
    db_exec("INSERT INTO k30_pl_token_transactions (wallet_id,amount,direction,reason,ref_type,created_by) VALUES (?,?,'credit',?,?,?)",
        [$w['id'], $amount, $reason, 'admin', $by]);
}

/* ── CENNIK ŻETONÓW ──────────────────────────────────────────────────────── */

function pl_prices_list(array $f = []): array {
    $where = []; $params = [];
    if (!empty($f['course_id'])) { $where[] = 'course_id=?'; $params[] = (int)$f['course_id']; }
    if (!empty($f['path_id']))   { $where[] = 'path_id=?';   $params[] = (int)$f['path_id']; }
    if (!empty($f['mentor_id'])) { $where[] = 'mentor_id=?'; $params[] = (int)$f['mentor_id']; }
    $sql = "SELECT * FROM k30_pl_token_prices" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id";
    return db_all($sql, $params);
}

function pl_price_save(array $d, ?int $id = null): int {
    $fields = [
        'course_id'       => !empty($d['course_id']) ? (int)$d['course_id'] : null,
        'mentor_id'       => !empty($d['mentor_id']) ? (int)$d['mentor_id'] : null,
        'path_id'         => !empty($d['path_id'])   ? (int)$d['path_id']   : null,
        'level'           => $d['level'] ?? null,
        'tokens_required' => max(1, (int)($d['tokens_required'] ?? 1)),
        'valid_from'      => $d['valid_from'] ?? null,
        'valid_to'        => $d['valid_to'] ?? null,
    ];
    if ($id) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_pl_token_prices SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_pl_token_prices ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

/** Zwraca cenę (tokens_required) dla sesji + opcjonalnego mentora. Priorytet: kurs > mentor > ścieżka+poziom > ścieżka > 1 */
function pl_price_for_session(int $session_id, ?int $mentor_id): int {
    $s = db_one("SELECT s.course_id, c.tech_path_id, c.level FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$session_id]);
    if (!$s) return 1;

    $today = date('Y-m-d');
    $valid = "(valid_from IS NULL OR valid_from<=?) AND (valid_to IS NULL OR valid_to>=?)";

    // 1. Per kurs
    $r = db_one("SELECT tokens_required FROM k30_pl_token_prices WHERE course_id=? AND $valid LIMIT 1", [(int)$s['course_id'], $today, $today]);
    if ($r) return (int)$r['tokens_required'];

    // 2. Per mentor
    if ($mentor_id) {
        $r = db_one("SELECT tokens_required FROM k30_pl_token_prices WHERE mentor_id=? AND course_id IS NULL AND $valid LIMIT 1", [$mentor_id, $today, $today]);
        if ($r) return (int)$r['tokens_required'];
    }

    // 3. Per ścieżka + poziom
    if ($s['tech_path_id'] && $s['level']) {
        $r = db_one("SELECT tokens_required FROM k30_pl_token_prices WHERE path_id=? AND level=? AND course_id IS NULL AND $valid LIMIT 1",
            [(int)$s['tech_path_id'], $s['level'], $today, $today]);
        if ($r) return (int)$r['tokens_required'];
    }

    // 4. Per ścieżka
    if ($s['tech_path_id']) {
        $r = db_one("SELECT tokens_required FROM k30_pl_token_prices WHERE path_id=? AND level IS NULL AND course_id IS NULL AND $valid LIMIT 1",
            [(int)$s['tech_path_id'], $today, $today]);
        if ($r) return (int)$r['tokens_required'];
    }

    return 1; // domyślna cena
}

/* ── PULE ŻETONÓW (USOS-style kategorie) ────────────────────────────────── */

function pl_pools_list(bool $only_active = false): array {
    $where = $only_active ? "WHERE is_active=1" : "";
    return db_all("SELECT p.*, (SELECT COUNT(*) FROM k30_pl_token_pool_wallets WHERE pool_id=p.id) AS n_wallets,
        (SELECT COALESCE(SUM(granted),0) FROM k30_pl_token_pool_wallets WHERE pool_id=p.id) AS total_granted,
        (SELECT COALESCE(SUM(spent),0)   FROM k30_pl_token_pool_wallets WHERE pool_id=p.id) AS total_spent
        FROM k30_pl_token_pools p $where ORDER BY is_active DESC, valid_from DESC, id DESC", []);
}

function pl_pool_get(int $id): ?array {
    return db_one("SELECT * FROM k30_pl_token_pools WHERE id=?", [$id]) ?: null;
}

function pl_pool_save(array $d, ?int $id = null): int {
    $fields = [
        'name'          => trim($d['name'] ?? ''),
        'color'         => $d['color'] ?? '#6366f1',
        'description'   => trim($d['description'] ?? ''),
        'period_key'    => trim($d['period_key'] ?? ''),
        'valid_from'    => $d['valid_from'] ?? null,
        'valid_to'      => $d['valid_to'] ?? null,
        'default_grant' => max(0, (int)($d['default_grant'] ?? 0)),
        'is_active'     => !empty($d['is_active']) ? 1 : 0,
        // Rodzaj puli: 'zwr' = zbiera zwroty niewykorzystanych żetonów
        // (kolumnę dokłada ti_rk_migrate — zetony.php woła ją przy starcie)
        'kind'          => ($d['kind'] ?? '') === 'zwr' ? 'zwr' : 'normal',
    ];
    if ($id) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_pl_token_pools SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_pl_token_pools ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

function pl_pool_wallet_get_or_create(int $pool_id, int $client_id): array {
    db_exec("INSERT OR IGNORE INTO k30_pl_token_pool_wallets (pool_id, client_id) VALUES (?,?)", [$pool_id, $client_id]);
    return db_one("SELECT * FROM k30_pl_token_pool_wallets WHERE pool_id=? AND client_id=?", [$pool_id, $client_id]);
}

/**
 * Przyznaj żetony z puli jednemu kursantowi.
 */
function pl_pool_grant(int $pool_id, int $client_id, int $amount, string $reason = '', ?int $by = null): void {
    if ($amount <= 0) return;
    $w = pl_pool_wallet_get_or_create($pool_id, $client_id);
    db_exec("UPDATE k30_pl_token_pool_wallets SET granted=granted+?, updated_at=datetime('now') WHERE id=?",
        [$amount, $w['id']]);
    db_exec("INSERT INTO k30_pl_token_pool_txns (wallet_id,amount,direction,reason,created_by) VALUES (?,?,'credit',?,?)",
        [$w['id'], $amount, $reason ?: 'przyznanie', $by]);
}

/**
 * Masowe przyznanie żetonów z puli wszystkim wybranym kursantom.
 * $client_ids — array of client IDs.
 * Zwraca liczbę kursantów, którym dodano żetony.
 */
function pl_pool_grant_bulk(int $pool_id, array $client_ids, int $amount, string $reason = '', ?int $by = null): int {
    if ($amount <= 0 || !$client_ids) return 0;
    $n = 0;
    foreach ($client_ids as $cid) {
        pl_pool_grant($pool_id, (int)$cid, $amount, $reason, $by);
        $n++;
    }
    return $n;
}

function pl_pool_wallets(int $pool_id): array {
    return db_all(
        "SELECT w.*, c.name AS client_name, c.email AS client_email,
                (w.granted - w.spent) AS balance
         FROM k30_pl_token_pool_wallets w
         JOIN k30_clients c ON c.id=w.client_id
         WHERE w.pool_id=?
         ORDER BY c.name COLLATE NOCASE",
        [$pool_id]
    );
}

function pl_pool_txns(int $wallet_id, int $limit = 30): array {
    return db_all("SELECT * FROM k30_pl_token_pool_txns WHERE wallet_id=? ORDER BY created_at DESC LIMIT ?",
        [$wallet_id, $limit]);
}

/* ── KOSZYK ──────────────────────────────────────────────────────────────── */

function pl_basket_get(int $client_id): ?array {
    $b = db_one("SELECT * FROM k30_pl_baskets WHERE client_id=? AND status='open'", [$client_id]);
    if (!$b) return null;
    // Wygaśnięty?
    if ($b['expires_at'] && strtotime($b['expires_at']) < time()) {
        db_exec("UPDATE k30_pl_baskets SET status='expired' WHERE id=?", [$b['id']]);
        // Odblokuj żetony
        $items = db_all("SELECT * FROM k30_pl_basket_items WHERE basket_id=? AND status='reserved'", [$b['id']]);
        foreach ($items as $item) {
            $w = pl_wallet_get_or_create($client_id);
            db_exec("UPDATE k30_pl_token_wallets SET balance=balance+?, balance_hold=balance_hold-?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$item['tokens_reserved'], $item['tokens_reserved'], $w['id']]);
            db_exec("UPDATE k30_pl_basket_items SET status='cancelled' WHERE id=?", [$item['id']]);
        }
        return null;
    }
    $b['items'] = db_all("SELECT bi.*, s.lesson_date, s.time_from, s.time_to, s.course_id FROM k30_pl_basket_items bi JOIN k30_ti_sessions s ON s.id=bi.session_id WHERE bi.basket_id=? AND bi.status='reserved'", [$b['id']]);
    $b['total_tokens'] = array_sum(array_column($b['items'], 'tokens_reserved'));
    return $b;
}

function pl_basket_get_or_create(int $client_id): array {
    $b = pl_basket_get($client_id);
    if ($b) return $b;
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    db_exec("INSERT INTO k30_pl_baskets (client_id,expires_at) VALUES (?,?)", [$client_id, $expires]);
    return pl_basket_get($client_id);
}

function pl_basket_add_item(int $client_id, int $session_id, ?int $mentor_id): array {
    // Sprawdź sesję
    $s = db_one("SELECT s.*, c.max_students FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$session_id]);
    if (!$s || $s['status'] === 'cancelled') throw new \RuntimeException('SESSION_UNAVAILABLE');

    // Sprawdź wolne miejsca
    $enrolled = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_attendance WHERE session_id=?", [$session_id])['n'] ?? 0);
    $max = (int)($s['max_students'] ?? 999);
    if ($enrolled >= $max) throw new \RuntimeException('SLOT_FULL');

    // Cena
    $price = pl_price_for_session($session_id, $mentor_id);

    // Portfel
    $w = pl_wallet_get_or_create($client_id);
    $free_balance = (int)$w['balance'];
    if ($free_balance < $price) throw new \RuntimeException('INSUFFICIENT_TOKENS');

    // Koszyk
    $b = pl_basket_get_or_create($client_id);

    // Dodaj pozycję (ON CONFLICT ignoruje duplikat)
    try {
        db_exec("INSERT INTO k30_pl_basket_items (basket_id,session_id,mentor_id,tokens_reserved) VALUES (?,?,?,?)",
            [$b['id'], $session_id, $mentor_id, $price]);
    } catch (\Throwable) {
        throw new \RuntimeException('ALREADY_IN_BASKET');
    }

    // HOLD żetony
    db_exec("UPDATE k30_pl_token_wallets SET balance=balance-?, balance_hold=balance_hold+?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
        [$price, $price, $w['id']]);
    db_exec("INSERT INTO k30_pl_token_transactions (wallet_id,amount,direction,reason,ref_type,ref_id) VALUES (?,?,'hold','basket_add','basket_item',last_insert_rowid())",
        [$w['id'], $price]);

    return pl_basket_get($client_id);
}

function pl_basket_remove_item(int $client_id, int $item_id): void {
    $b = pl_basket_get($client_id);
    if (!$b) throw new \RuntimeException('NO_OPEN_BASKET');
    $item = db_one("SELECT * FROM k30_pl_basket_items WHERE id=? AND basket_id=? AND status='reserved'", [$item_id, $b['id']]);
    if (!$item) throw new \RuntimeException('ITEM_NOT_FOUND');

    db_exec("UPDATE k30_pl_basket_items SET status='cancelled' WHERE id=?", [$item_id]);
    $w = pl_wallet_get_or_create($client_id);
    db_exec("UPDATE k30_pl_token_wallets SET balance=balance+?, balance_hold=balance_hold-?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
        [$item['tokens_reserved'], $item['tokens_reserved'], $w['id']]);
    db_exec("INSERT INTO k30_pl_token_transactions (wallet_id,amount,direction,reason,ref_type,ref_id) VALUES (?,?,'unhold','basket_remove','basket_item',?)",
        [$w['id'], $item['tokens_reserved'], $item_id]);
}

function pl_basket_checkout(int $client_id): array {
    $b = pl_basket_get($client_id);
    if (!$b || empty($b['items'])) throw new \RuntimeException('EMPTY_BASKET');

    $w = pl_wallet_get_or_create($client_id);
    $total = (int)$b['total_tokens'];

    // Weryfikacja końcowa salda (balance + hold = dostępne, hold = zarezerwowane)
    $total_available = (int)$w['balance'] + (int)$w['balance_hold'];
    if ($total_available < $total) throw new \RuntimeException('INSUFFICIENT_TOKENS');

    $purchased = [];
    foreach ($b['items'] as $item) {
        // Finalny check miejsc
        $enrolled = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_attendance WHERE session_id=?", [$item['session_id']])['n'] ?? 0);
        $s = db_one("SELECT c.max_students FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$item['session_id']]);
        if ($enrolled >= (int)($s['max_students'] ?? 999)) throw new \RuntimeException('SLOT_FULL_AT_CHECKOUT');

        // Utwórz rekord obecności
        db_exec("INSERT OR IGNORE INTO k30_ti_attendance (session_id,client_id,attended,no_show,cancel_pending) VALUES (?,?,0,0,0)",
            [$item['session_id'], $client_id]);

        // Przesuń żetony HOLD → SPENT
        db_exec("UPDATE k30_pl_token_wallets SET balance_hold=balance_hold-?, total_spent=total_spent+?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$item['tokens_reserved'], $item['tokens_reserved'], $w['id']]);
        db_exec("INSERT INTO k30_pl_token_transactions (wallet_id,amount,direction,reason,ref_type,ref_id) VALUES (?,?,'debit','purchase','basket_item',?)",
            [$w['id'], $item['tokens_reserved'], $item['id']]);
        db_exec("UPDATE k30_pl_basket_items SET status='purchased' WHERE id=?", [$item['id']]);

        $purchased[] = [
            'session_id'     => $item['session_id'],
            'mentor_id'      => $item['mentor_id'],
            'tokens'         => $item['tokens_reserved'],
            'lesson_date'    => $item['lesson_date'],
        ];
    }

    db_exec("UPDATE k30_pl_baskets SET status='checked_out', checked_out_at=CURRENT_TIMESTAMP WHERE id=?", [$b['id']]);

    $w_new = db_one("SELECT * FROM k30_pl_token_wallets WHERE id=?", [$w['id']]);
    return [
        'purchased'      => count($purchased),
        'tokens_spent'   => $total,
        'wallet_balance' => (int)$w_new['balance'],
        'sessions'       => $purchased,
    ];
}

function pl_basket_cancel(int $client_id): void {
    $b = pl_basket_get($client_id);
    if (!$b) throw new \RuntimeException('NO_OPEN_BASKET');

    $w = pl_wallet_get_or_create($client_id);
    foreach ($b['items'] as $item) {
        db_exec("UPDATE k30_pl_token_wallets SET balance=balance+?, balance_hold=balance_hold-?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$item['tokens_reserved'], $item['tokens_reserved'], $w['id']]);
        db_exec("INSERT INTO k30_pl_token_transactions (wallet_id,amount,direction,reason,ref_type,ref_id) VALUES (?,?,'credit','refund','basket_item',?)",
            [$w['id'], $item['tokens_reserved'], $item['id']]);
        db_exec("UPDATE k30_pl_basket_items SET status='cancelled' WHERE id=?", [$item['id']]);
    }
    db_exec("UPDATE k30_pl_baskets SET status='cancelled' WHERE id=?", [$b['id']]);
}
