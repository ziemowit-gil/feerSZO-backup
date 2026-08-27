<?php
/**
 * includes/ti_exams_schema.php
 * Samonaprawa schematu modułu „Testy wiedzy i umiejętności" (Egzaminy TI).
 *
 * Wzorzec jak w includes/letters_schema.php i includes/zlecenie_schema.php:
 * jedno źródło prawdy o tabelach i kolumnach, dociągane na starcie z każdej
 * ścieżki wejścia (panel admina, panel dydaktyka, panel kursanta, API).
 * Dzięki temu instalacja założona starszym setupem nie wywala „no such column".
 *
 * WAŻNE: nie używamy `PRAGMA table_info` (to wyłącznie SQLite). Kolumny
 * dokładamy pojedynczym `ALTER TABLE ADD COLUMN` w try/catch — powtórzone
 * dodanie rzuca wyjątek, który świadomie połykamy. Typy TEXT/INTEGER/REAL/
 * DATETIME są bezpieczne na SQLite i MySQL, a kolumny TEXT nie dostają DEFAULT
 * (restrykcja MySQL) — wartości domyślne ustawia kod zapisujący.
 *
 * Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $driver = '';
    try { $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (\Throwable $e) { return; }

    $auto = ($driver === 'sqlite')
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT AUTO_INCREMENT PRIMARY KEY';

    $exec = function (string $sql): void {
        try { db()->exec($sql); } catch (\Throwable $e) { /* istnieje albo nie dotyczy tego silnika */ }
    };

    // ── Egzamin (zestaw egzaminacyjny) ────────────────────────────────────────
    $exec("CREATE TABLE IF NOT EXISTS k30_ti_exams (
        id                $auto,
        course_id         INTEGER NOT NULL,
        session_id        INTEGER,
        title             TEXT,
        description       TEXT,
        mode              TEXT,                              -- exam | quiz | training
        time_limit_min    INTEGER NOT NULL DEFAULT 0,        -- 0 = bez limitu
        pass_pct          INTEGER NOT NULL DEFAULT 0,        -- próg zaliczenia w %
        max_attempts      INTEGER NOT NULL DEFAULT 1,        -- 0 = bez limitu podejść
        shuffle_questions INTEGER NOT NULL DEFAULT 0,
        shuffle_options   INTEGER NOT NULL DEFAULT 0,
        fixed_draw        INTEGER NOT NULL DEFAULT 0,        -- ile losować z pytań stałych (0 = wszystkie)
        bank_draw         INTEGER NOT NULL DEFAULT 0,        -- ile losować z banku pytań
        show_feedback     TEXT,                              -- never | after_submit | immediate
        neg_marking       TEXT,                              -- partial | none | all_or_nothing
        open_at           DATETIME,
        close_at          DATETIME,
        is_active         INTEGER NOT NULL DEFAULT 0,
        sync_grade        INTEGER NOT NULL DEFAULT 0,        -- wynik trafia do e-dziennika
        grade_weight      INTEGER NOT NULL DEFAULT 3,
        created_by        INTEGER,
        created_at        DATETIME,
        updated_at        DATETIME
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ti_exams_course ON k30_ti_exams(course_id)");

    // ── Pytania ───────────────────────────────────────────────────────────────
    // Specyfika typu siedzi w kolumnie `config` (JSON) — dzięki temu dodanie
    // nowego rodzaju pytania nie wymaga migracji schematu, tylko modelu w Javie.
    $exec("CREATE TABLE IF NOT EXISTS k30_ti_exam_questions (
        id          $auto,
        exam_id     INTEGER NOT NULL,
        position    INTEGER NOT NULL DEFAULT 0,
        type        TEXT,
        prompt      TEXT,
        points      REAL NOT NULL DEFAULT 1,
        in_bank     INTEGER NOT NULL DEFAULT 0,
        explanation TEXT,
        config      TEXT,
        created_at  DATETIME
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ti_examq_exam ON k30_ti_exam_questions(exam_id, position)");

    // ── Warianty odpowiedzi / twierdzenia prawda-fałsz ────────────────────────
    $exec("CREATE TABLE IF NOT EXISTS k30_ti_exam_options (
        id          $auto,
        question_id INTEGER NOT NULL,
        position    INTEGER NOT NULL DEFAULT 0,
        label       TEXT,
        is_correct  INTEGER NOT NULL DEFAULT 0,
        feedback    TEXT
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ti_examo_q ON k30_ti_exam_options(question_id, position)");

    // ── Przypadki testowe wejście/wyjście dla zadań programistycznych ─────────
    $exec("CREATE TABLE IF NOT EXISTS k30_ti_exam_cases (
        id          $auto,
        question_id INTEGER NOT NULL,
        position    INTEGER NOT NULL DEFAULT 0,
        name        TEXT,
        stdin       TEXT,
        expected    TEXT,
        match_mode  TEXT,
        weight      REAL NOT NULL DEFAULT 1,
        is_hidden   INTEGER NOT NULL DEFAULT 0,
        tolerance   REAL NOT NULL DEFAULT 0.000001
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ti_examc_q ON k30_ti_exam_cases(question_id, position)");

    // ── Podejścia kursantów ───────────────────────────────────────────────────
    // drawn_ids / option_order trzymają wariant wylosowany dla tego podejścia,
    // żeby wgląd po fakcie pokazywał dokładnie to, co widział kursant.
    $exec("CREATE TABLE IF NOT EXISTS k30_ti_exam_attempts (
        id            $auto,
        exam_id       INTEGER NOT NULL,
        client_id     INTEGER NOT NULL,
        attempt_no    INTEGER NOT NULL DEFAULT 1,
        status        TEXT,                                  -- in_progress | submitted | graded
        score         REAL NOT NULL DEFAULT 0,
        max_score     REAL NOT NULL DEFAULT 0,
        needs_review  INTEGER NOT NULL DEFAULT 0,
        seed          INTEGER NOT NULL DEFAULT 0,
        drawn_ids     TEXT,
        option_order  TEXT,
        engine_status TEXT,                                  -- ok | pending | error
        engine_error  TEXT,
        is_late       INTEGER NOT NULL DEFAULT 0,
        started_at    DATETIME,
        deadline_at   DATETIME,
        submitted_at  DATETIME,
        graded_at     DATETIME,
        graded_by     INTEGER
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ti_examatt_exam   ON k30_ti_exam_attempts(exam_id)");
    $exec("CREATE INDEX IF NOT EXISTS idx_ti_examatt_client ON k30_ti_exam_attempts(client_id)");

    // ── Odpowiedzi ────────────────────────────────────────────────────────────
    // payload  — odpowiedź kursanta (JSON, kształt zależny od typu pytania),
    // result   — szczegóły oceny zwrócone przez silnik (JSON),
    // points_awarded NULL = jeszcze nieoceniona.
    $exec("CREATE TABLE IF NOT EXISTS k30_ti_exam_answers (
        id             $auto,
        attempt_id     INTEGER NOT NULL,
        question_id    INTEGER NOT NULL,
        payload        TEXT,
        result         TEXT,
        points_awarded REAL,
        is_correct     INTEGER NOT NULL DEFAULT 0,
        needs_review   INTEGER NOT NULL DEFAULT 0,
        feedback       TEXT,
        teacher_note   TEXT,
        answered_at    DATETIME
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ti_exama_att ON k30_ti_exam_answers(attempt_id)");
    // Jedna odpowiedź na pytanie w podejściu — zapis idzie przez UPDATE-albo-INSERT.
    $exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_ti_exama ON k30_ti_exam_answers(attempt_id, question_id)");

    // ── Doczepienie do e-dziennika ────────────────────────────────────────────
    // Osobna kolumna od `attempt_id` używanej przez starszy moduł Testy, żeby
    // oba moduły mogły wystawiać oceny bez kolizji przy deduplikacji.
    $exec("ALTER TABLE k30_ti_grades ADD COLUMN exam_attempt_id INTEGER");

    // ── Kolumny dokładane po pierwszym wdrożeniu ──────────────────────────────
    foreach ([
        "ALTER TABLE k30_ti_exams         ADD COLUMN grade_weight  INTEGER NOT NULL DEFAULT 3",
        "ALTER TABLE k30_ti_exam_attempts ADD COLUMN engine_status TEXT",
        "ALTER TABLE k30_ti_exam_attempts ADD COLUMN engine_error  TEXT",
        "ALTER TABLE k30_ti_exam_attempts ADD COLUMN is_late       INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_exam_answers  ADD COLUMN teacher_note  TEXT",
    ] as $sql) { $exec($sql); }
})();
