<?php
/**
 * modules/ti_dydaktyka/logic/tiDydaktykaSchema.php — Dydaktyka i postępy kursantów TI: schemat.
 *
 * Moduł uzupełnia istniejącą dydaktykę TI (lekcje k30_ti_sessions, obecność k30_ti_attendance,
 * plan nauczania k30_ti_curriculum, materiały k30_ti_materials, profil dostępności
 * k30_ti_accessibility_profiles z warstwy hybrid/) o cztery brakujące elementy:
 *   k30_ti_progress_notes        — notatki o postępach i trudnościach kursanta (z widocznością)
 *   k30_ti_student_modules       — status opanowania punktu planu (modułu) przez kursanta
 *   k30_ti_material_assignments  — materiał przypisany konkretnemu kursantowi
 *   k30_ti_client_equipment      — rejestr sprzętu kursanta (z powiązaniem z licencją)
 * Lekcji, obecności ani harmonogramu nie duplikuje — czyta je z istniejących tabel.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

function ti_dydaktyka_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    karty30_migrate();                                                   // tabele bazowe TI (kursy, lekcje, materiały, licencje)
    if (szo_schema_current('ti_dydaktyka_migrate', __FILE__)) return;    // raz na wersję pliku

    $pdo = db();

    // Notatki o postępach i trudnościach. visibility: staff = prowadzący i kierownik;
    // student = widoczna także dla kursanta i opiekuna małoletniego.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_progress_notes (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id  INTEGER NOT NULL REFERENCES k30_clients(id)     ON DELETE CASCADE,
        course_id  INTEGER          REFERENCES k30_ti_courses(id)  ON DELETE SET NULL,
        session_id INTEGER          REFERENCES k30_ti_sessions(id) ON DELETE SET NULL,
        kind       TEXT NOT NULL DEFAULT 'progress' CHECK (kind IN ('progress','difficulty','goal','other')),
        topic      TEXT NOT NULL DEFAULT '',
        body       TEXT NOT NULL,
        visibility TEXT NOT NULL DEFAULT 'staff' CHECK (visibility IN ('staff','student')),
        created_by INTEGER,
        created_at DATETIME,
        updated_at DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_pnotes_client ON k30_ti_progress_notes(client_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_pnotes_course ON k30_ti_progress_notes(course_id)");

    // Opanowanie modułu (punktu planu kursu) przez kursanta.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_student_modules (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id     INTEGER NOT NULL REFERENCES k30_clients(id)       ON DELETE CASCADE,
        curriculum_id INTEGER NOT NULL REFERENCES k30_ti_curriculum(id) ON DELETE CASCADE,
        status        TEXT NOT NULL DEFAULT 'not_started' CHECK (status IN ('not_started','in_progress','mastered','needs_revision')),
        status_date   DATE,
        note          TEXT NOT NULL DEFAULT '',
        updated_by    INTEGER,
        updated_at    DATETIME,
        UNIQUE(client_id, curriculum_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_smod_curr ON k30_ti_student_modules(curriculum_id)");

    // Materiał przypisany jednemu kursantowi (poza dostępem wynikającym z zapisu na kurs).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_material_assignments (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        material_id INTEGER NOT NULL REFERENCES k30_ti_materials(id) ON DELETE CASCADE,
        client_id   INTEGER NOT NULL REFERENCES k30_clients(id)      ON DELETE CASCADE,
        note        TEXT NOT NULL DEFAULT '',
        due_at      DATE,
        assigned_by INTEGER,
        assigned_at DATETIME,
        opened_at   DATETIME,
        UNIQUE(material_id, client_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_massign_client ON k30_ti_material_assignments(client_id)");

    // Rejestr sprzętu kursanta; license_assignment_id = licencja powiązana ze sprzętem (moduł Licencje).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_client_equipment (
        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id             INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        kind                  TEXT NOT NULL CHECK (kind IN ('laptop','smartfon','monitor_brajlowski','notatnik_brajlowski','lupa','inny')),
        model                 TEXT NOT NULL DEFAULT '',
        serial                TEXT NOT NULL DEFAULT '',
        ownership             TEXT NOT NULL DEFAULT 'own' CHECK (ownership IN ('own','loan')),
        lent_at               DATE,
        returned_at           DATE,
        license_assignment_id INTEGER REFERENCES k30_ti_client_licenses(id) ON DELETE SET NULL,
        note                  TEXT NOT NULL DEFAULT '',
        created_by            INTEGER,
        created_at            DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_equip_client ON k30_ti_client_equipment(client_id)");
}
