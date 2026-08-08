<?php
/**
 * includes/ti_planner.php — SZO Planner: biblioteka modułów i harmonogramy zajęć.
 *
 * Tabele:
 *   k30_szo_blocks        — biblioteka bloków modułowych per prowadzący
 *   k30_szo_schedules     — instancje harmonogramów wielodniowych
 *   k30_szo_schedule_days — dni harmonogramu (block_order = JSON array ID bloków)
 */

const SZO_CATEGORIES = [
    'theory'      => 'Teoria',
    'workshop'    => 'Warsztat',
    'break'       => 'Przerwa',
    'buffer'      => 'Bufor',
    'summary'     => 'Synteza',
    'icebreaker'  => 'Icebreaker',
    'qa'          => 'Q&A',
];

const SZO_PHASES = [
    'foundation'   => 'Fundamenty',
    'intensive'    => 'Intensywna',
    'synthesis'    => 'Synteza',
    'continuation' => 'Kontynuacja',
];

function szo_setting(string $key, mixed $default = null): mixed {
    $row = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
    return $row ? $row['value'] : $default;
}

function ti_planner_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS k30_szo_blocks (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title           TEXT    NOT NULL,
        category        TEXT    NOT NULL DEFAULT 'workshop',
        duration_min    INTEGER NOT NULL DEFAULT 60,
        difficulty      INTEGER NOT NULL DEFAULT 2,
        energy_impact   INTEGER NOT NULL DEFAULT 0,
        min_break_after INTEGER NOT NULL DEFAULT 0,
        resources       TEXT    NOT NULL DEFAULT '[]',
        tags            TEXT    NOT NULL DEFAULT '[]',
        locked          INTEGER NOT NULL DEFAULT 0,
        notes           TEXT    NOT NULL DEFAULT '',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_szo_blocks_instr ON k30_szo_blocks(instructor_id)");

    db()->exec("CREATE TABLE IF NOT EXISTS k30_szo_schedules (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        course_id       INTEGER REFERENCES k30_ti_courses(id) ON DELETE SET NULL,
        title           TEXT    NOT NULL,
        num_days        INTEGER NOT NULL DEFAULT 3,
        settings_json   TEXT    NOT NULL DEFAULT '{}',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_szo_sched_instr ON k30_szo_schedules(instructor_id)");

    db()->exec("CREATE TABLE IF NOT EXISTS k30_szo_schedule_days (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        schedule_id  INTEGER NOT NULL REFERENCES k30_szo_schedules(id) ON DELETE CASCADE,
        day_number   INTEGER NOT NULL,
        phase        TEXT    NOT NULL DEFAULT 'foundation',
        day_date     TEXT,
        block_order  TEXT    NOT NULL DEFAULT '[]'
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_szo_day_unique ON k30_szo_schedule_days(schedule_id, day_number)");
}

/* ── BLOKI ──────────────────────────────────────────────────────────── */

function szo_blocks_list(int $instructor_id): array {
    ti_planner_migrate();
    return db_all(
        "SELECT * FROM k30_szo_blocks WHERE instructor_id=? ORDER BY category, title",
        [$instructor_id]
    );
}

function szo_block_get(int $id): ?array {
    ti_planner_migrate();
    return db_one("SELECT * FROM k30_szo_blocks WHERE id=?", [$id]) ?: null;
}

function szo_block_save(array $data, ?int $id = null): int {
    ti_planner_migrate();
    $cat   = in_array($data['category'] ?? '', array_keys(SZO_CATEGORIES), true)
             ? $data['category'] : 'workshop';
    $fields = [
        'title'           => substr(trim($data['title'] ?? ''), 0, 200),
        'category'        => $cat,
        'duration_min'    => max(5, min(480, (int)($data['duration_min'] ?? 60))),
        'difficulty'      => max(1, min(5, (int)($data['difficulty'] ?? 2))),
        'energy_impact'   => max(-2, min(2, (int)($data['energy_impact'] ?? 0))),
        'min_break_after' => max(0, min(60, (int)($data['min_break_after'] ?? 0))),
        'resources'       => json_encode(array_values((array)($data['resources'] ?? []))),
        'tags'            => json_encode(array_values((array)($data['tags'] ?? []))),
        'locked'          => (int)(bool)($data['locked'] ?? false),
        'notes'           => substr(trim($data['notes'] ?? ''), 0, 500),
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_szo_blocks SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $fields['instructor_id'] = (int)$data['instructor_id'];
    $cols = implode(', ', array_keys($fields));
    $phs  = implode(', ', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_szo_blocks ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

function szo_block_delete(int $id, int $instructor_id): void {
    ti_planner_migrate();
    db_exec("DELETE FROM k30_szo_blocks WHERE id=? AND instructor_id=?", [$id, $instructor_id]);
}

/* ── HARMONOGRAMY ───────────────────────────────────────────────────── */

function szo_schedules_list(int $instructor_id): array {
    ti_planner_migrate();
    return db_all(
        "SELECT s.*, c.name AS course_name
         FROM k30_szo_schedules s
         LEFT JOIN k30_ti_courses c ON c.id = s.course_id
         WHERE s.instructor_id=?
         ORDER BY s.updated_at DESC",
        [$instructor_id]
    );
}

function szo_schedule_get(int $id, int $instructor_id): ?array {
    ti_planner_migrate();
    $s = db_one(
        "SELECT * FROM k30_szo_schedules WHERE id=? AND instructor_id=?",
        [$id, $instructor_id]
    );
    if (!$s) return null;
    $days = db_all(
        "SELECT * FROM k30_szo_schedule_days WHERE schedule_id=? ORDER BY day_number",
        [$id]
    );
    foreach ($days as &$d) {
        $d['block_order'] = json_decode($d['block_order'] ?? '[]', true) ?: [];
    }
    $s['days'] = $days;
    return $s;
}

function szo_schedule_create(int $instructor_id, string $title, int $num_days = 3, ?int $course_id = null): int {
    ti_planner_migrate();
    $num_days = max(2, min(7, $num_days));
    $settings = json_encode([
        'dailyStartTime'   => szo_setting('szo_daily_start', '09:00'),
        'dailyEndTime'     => szo_setting('szo_daily_end', '17:00'),
        'maxDailyMinutes'  => (int)szo_setting('szo_max_daily_minutes', 480),
        'lunchDuration'    => (int)szo_setting('szo_lunch_duration', 60),
        'breakIntervalMax' => (int)szo_setting('szo_break_interval', 90),
        'autoBufferMin'    => 15,
        'lunchAt'          => '12:30',
    ]);
    db_exec(
        "INSERT INTO k30_szo_schedules (instructor_id, course_id, title, num_days, settings_json) VALUES (?,?,?,?,?)",
        [$instructor_id, $course_id ?: null, $title, $num_days, $settings]
    );
    $sid = (int)db()->lastInsertId();

    $phases = ['foundation', 'intensive', 'synthesis', 'continuation', 'continuation', 'continuation', 'continuation'];
    for ($i = 1; $i <= $num_days; $i++) {
        db_exec(
            "INSERT INTO k30_szo_schedule_days (schedule_id, day_number, phase) VALUES (?,?,?)",
            [$sid, $i, $phases[$i - 1] ?? 'continuation']
        );
    }
    return $sid;
}

function szo_schedule_save_days(int $schedule_id, int $instructor_id, array $days): bool {
    ti_planner_migrate();
    if (!db_one("SELECT id FROM k30_szo_schedules WHERE id=? AND instructor_id=?", [$schedule_id, $instructor_id])) {
        return false;
    }
    foreach ($days as $day) {
        $day_number  = (int)($day['day_number'] ?? 0);
        if ($day_number < 1) continue;
        $block_order = json_encode(array_map('intval', $day['block_order'] ?? []));
        $phase       = in_array($day['phase'] ?? '', array_keys(SZO_PHASES), true)
                       ? $day['phase'] : 'foundation';
        $day_date    = !empty($day['day_date']) ? $day['day_date'] : null;
        db_exec(
            "UPDATE k30_szo_schedule_days SET block_order=?, phase=?, day_date=?
             WHERE schedule_id=? AND day_number=?",
            [$block_order, $phase, $day_date, $schedule_id, $day_number]
        );
    }
    db_exec(
        "UPDATE k30_szo_schedules SET updated_at=CURRENT_TIMESTAMP WHERE id=?",
        [$schedule_id]
    );
    return true;
}

function szo_schedule_delete(int $id, int $instructor_id): void {
    ti_planner_migrate();
    db_exec("DELETE FROM k30_szo_schedules WHERE id=? AND instructor_id=?", [$id, $instructor_id]);
}
