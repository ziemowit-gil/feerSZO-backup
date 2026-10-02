<?php
/**
 * modules/ti_scheduler/logic/schedulerSchema.php — Planista zajęć TI: schemat.
 *
 * Rozszerza istniejące tabele (k30_ti_sessions, k30_pl_rooms, dostępność, urlopy)
 * o: limity godzin trenera, kwalifikacje i wymagania kwalifikacyjne oraz
 * potwierdzenia lekcji (klient / trener). Prefiks tabel: k30_sc_*.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_planner_ext.php';
require_once dirname(__DIR__, 3) . '/includes/ti_periods.php';
require_once dirname(__DIR__, 3) . '/includes/ti_leaves.php';
require_once dirname(__DIR__, 3) . '/includes/ti_reschedule.php';

/** Rodzaje odniesień kwalifikacji: rodzaj zajęć (subject_type) lub ścieżka technologiczna. */
const SC_REF_KINDS = ['subject' => 'Rodzaj zajęć', 'path' => 'Ścieżka technologiczna'];

function ti_sched_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    karty30_migrate();
    ti_planner_ext_migrate();
    ti_course_close_migrate();
    ti_leaves_migrate();
    if (szo_schema_current('ti_sched_migrate', __FILE__)) return;   // raz na wersję pliku

    $pdo = db();
    // Limity godzin per trener (0 = brak limitu). hard=1: przekroczenie blokuje zapis.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_sc_instructor_limits (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id       INTEGER NOT NULL UNIQUE,
        max_day_min         INTEGER NOT NULL DEFAULT 0,
        max_week_min        INTEGER NOT NULL DEFAULT 0,
        max_month_min       INTEGER NOT NULL DEFAULT 0,
        max_consecutive_min INTEGER NOT NULL DEFAULT 0,
        hard                INTEGER NOT NULL DEFAULT 0,
        note                TEXT    NOT NULL DEFAULT '',
        updated_by          INTEGER,
        updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Kwalifikacje trenera: rodzaj zajęć albo ścieżka technologiczna, opcjonalna data ważności.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_sc_qualifications (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id INTEGER NOT NULL,
        ref_kind      TEXT    NOT NULL,
        ref_id        INTEGER NOT NULL,
        level         TEXT    NOT NULL DEFAULT '',
        valid_to      TEXT    NOT NULL DEFAULT '',
        note          TEXT    NOT NULL DEFAULT '',
        created_by    INTEGER,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(instructor_id, ref_kind, ref_id)
    )");
    // Wymaganie: zajęcia tego rodzaju/ścieżki może prowadzić wyłącznie trener z kwalifikacją.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_sc_requirements (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ref_kind   TEXT    NOT NULL,
        ref_id     INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(ref_kind, ref_id)
    )");
    // Potwierdzenia lekcji (poza K30_TI_SESSION_STATUSES, od których zależą wypłaty).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_sc_session_state (
        session_id           INTEGER PRIMARY KEY,
        client_confirmed_at  TEXT,
        client_confirmed_by  INTEGER,
        instr_confirmed_at   TEXT,
        instr_confirmed_by   INTEGER
    )");
}
