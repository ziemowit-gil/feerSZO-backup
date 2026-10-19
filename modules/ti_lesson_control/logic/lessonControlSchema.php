<?php
/**
 * modules/ti_lesson_control/logic/lessonControlSchema.php — schemat Kontroli Lekcji i Wpisów (TI).
 *
 * Wpisu z lekcji NIE kopiujemy — żyje tam, gdzie zawsze (k30_ti_sessions: temat, notatki,
 * k30_ti_attendance, k30_ti_session_curriculum, materiały i zadania). Moduł zapisuje tylko
 * wynik kontroli i ślad rewizyjny:
 *  - k30_ti_lc_rules  — polityka kompletności i terminu: globalna (course_id = 0) albo grupy,
 *  - k30_ti_lc_checks — stan kontroli, jeden wiersz na lekcję (automat + weryfikacja ręczna),
 *  - k30_ti_lc_events — ślad rewizyjny, tylko dopisywanie (nigdy UPDATE/DELETE z aplikacji).
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

function tiLcMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('tiLcMigrate', __FILE__)) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_lc_rules (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id            INTEGER NOT NULL DEFAULT 0 UNIQUE,   -- 0 = reguła globalna
        deadline_mode        TEXT    NOT NULL DEFAULT 'end_of_day', -- end_of_day | hours_after_end
        deadline_hours       INTEGER NOT NULL DEFAULT 24,
        grace_min            INTEGER NOT NULL DEFAULT 0,
        req_topic            INTEGER NOT NULL DEFAULT 1,
        topic_min_len        INTEGER NOT NULL DEFAULT 3,
        req_attendance       INTEGER NOT NULL DEFAULT 1,
        req_curriculum       INTEGER NOT NULL DEFAULT 1,          -- tylko gdy grupa ma sylabus
        req_materials        INTEGER NOT NULL DEFAULT 0,
        req_homework_if_flag INTEGER NOT NULL DEFAULT 1,
        updated_by           INTEGER,
        updated_at           DATETIME
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_lc_checks (
        session_id          INTEGER PRIMARY KEY,
        course_id           INTEGER NOT NULL DEFAULT 0,
        instructor_id       INTEGER,
        lesson_date         DATE,
        auto_status         TEXT    NOT NULL DEFAULT 'OCZEKUJE',
        is_late             INTEGER NOT NULL DEFAULT 0,
        missing_json        TEXT    NOT NULL DEFAULT '[]',
        due_at              DATETIME,
        entry_submitted_at  DATETIME,
        delay_min           INTEGER NOT NULL DEFAULT 0,
        entry_hash          TEXT    NOT NULL DEFAULT '',
        rule_snapshot_json  TEXT    NOT NULL DEFAULT '{}',
        review_status       TEXT    NOT NULL DEFAULT 'NIEZWERYFIKOWANY',
        reviewed_by         INTEGER,
        reviewed_at         DATETIME,
        review_comment      TEXT    NOT NULL DEFAULT '',
        revision            INTEGER NOT NULL DEFAULT 1,
        reminder_count      INTEGER NOT NULL DEFAULT 0,
        last_reminder_at    DATETIME,
        escalated_at        DATETIME,
        last_checked_at     DATETIME
    )");
    // Zerowanie zaległości (kontrolaWpisow.php → „Zeruj zaległe wpisy dla”): kto, kiedy, dlaczego.
    $cols = array_column($pdo->query("PRAGMA table_info(k30_ti_lc_checks)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
    foreach (['waived_at' => 'DATETIME', 'waived_by' => 'INTEGER', 'waive_comment' => "TEXT NOT NULL DEFAULT ''"] as $c => $def) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE k30_ti_lc_checks ADD COLUMN $c $def");
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_lc_checks_instr  ON k30_ti_lc_checks(instructor_id, auto_status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_lc_checks_date   ON k30_ti_lc_checks(lesson_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_lc_checks_review ON k30_ti_lc_checks(review_status)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_lc_events (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id    INTEGER NOT NULL,
        event_type    TEXT    NOT NULL,
        from_status   TEXT    NOT NULL DEFAULT '',
        to_status     TEXT    NOT NULL DEFAULT '',
        actor_user_id INTEGER,
        actor_role    TEXT    NOT NULL DEFAULT 'system',
        comment       TEXT    NOT NULL DEFAULT '',
        payload_json  TEXT    NOT NULL DEFAULT '{}',
        entry_hash    TEXT    NOT NULL DEFAULT '',
        created_at    DATETIME NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_lc_events_session ON k30_ti_lc_events(session_id, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_lc_events_created ON k30_ti_lc_events(created_at)");
    $pdo->exec("INSERT OR IGNORE INTO k30_ti_lc_rules (course_id) VALUES (0)");
}
