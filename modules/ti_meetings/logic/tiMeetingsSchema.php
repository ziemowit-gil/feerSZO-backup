<?php
/**
 * modules/ti_meetings/logic/tiMeetingsSchema.php — Protokoły spotkań TI: schemat.
 *
 * Nowy obieg protokołów zastępuje dotychczasowy widok protokołów (lista, edytor, obecność,
 * zadania, zatwierdzanie). Dane godzinowe pozostają w k30_ti_protocols / k30_ti_protocol_entries
 * i nie są tutaj kopiowane ani zmieniane — rozliczenia i wypłaty liczą się jak dotąd.
 *
 * Tabele (prefiks k30_ti_ jak reszta TI):
 *   k30_ti_meetings            — protokół spotkania (kurs, tytuł, data, status, wersja)
 *   k30_ti_meeting_items       — punkty protokołu (obecność, przebieg, uchwała, zadanie, inne)
 *   k30_ti_meeting_attendees   — obecność uczestników (kursant z listy albo osoba spoza listy)
 *   k30_ti_meeting_tasks       — zadania i uchwały z przypisaniem, terminem i statusem
 *   k30_ti_meeting_attachments — załączniki (ścieżka w storage, sha256, tekst alternatywny)
 *   k30_ti_meeting_signatures  — podpis WEWNĘTRZNY: kto, kiedy, hash treści (bez podpisu kwalifikowanego)
 *   k30_ti_meeting_log         — ślad zmian; wymagany przy zatwierdzeniu i odblokowaniu
 *
 * Uprawnienia do tworzenia: prowadzący kursu i kierownik (egzekwowane w logice, nie w schemacie).
 */

function ti_meetings_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('ti_meetings_migrate', __FILE__)) return;   // raz na wersję pliku

    $pdo = db();
    $tables = [
        "CREATE TABLE IF NOT EXISTS k30_ti_meetings (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
            title         TEXT    NOT NULL,
            meeting_date  DATE    NOT NULL,
            status        TEXT    NOT NULL DEFAULT 'szkic'
                          CHECK (status IN ('szkic','do_zatwierdzenia','zatwierdzony','odblokowany')),
            version       INTEGER NOT NULL DEFAULT 1,
            created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_items (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meetings(id) ON DELETE CASCADE,
            kind        TEXT    NOT NULL CHECK (kind IN ('obecnosc','przebieg','uchwala','zadanie','inne')),
            position    INTEGER NOT NULL DEFAULT 0,
            title       TEXT    NOT NULL DEFAULT '',
            body        TEXT    NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_attendees (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meetings(id) ON DELETE CASCADE,
            client_id   INTEGER REFERENCES k30_clients(id) ON DELETE SET NULL,
            user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
            name_text   TEXT    NOT NULL DEFAULT '',
            present     INTEGER NOT NULL DEFAULT 1,
            UNIQUE (meeting_id, client_id)
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_tasks (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id   INTEGER NOT NULL REFERENCES k30_ti_meetings(id) ON DELETE CASCADE,
            item_id      INTEGER REFERENCES k30_ti_meeting_items(id) ON DELETE SET NULL,
            description  TEXT    NOT NULL,
            assignee_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            due_date     DATE,
            status       TEXT    NOT NULL DEFAULT 'otwarte' CHECK (status IN ('otwarte','zrobione','anulowane')),
            reminded_at  TEXT
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_attachments (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meetings(id) ON DELETE CASCADE,
            filename    TEXT    NOT NULL,
            mime        TEXT    NOT NULL,
            path        TEXT    NOT NULL,
            sha256      TEXT    NOT NULL,
            alt_text    TEXT    NOT NULL DEFAULT '',
            uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            uploaded_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_signatures (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id   INTEGER NOT NULL REFERENCES k30_ti_meetings(id) ON DELETE CASCADE,
            role         TEXT    NOT NULL CHECK (role IN ('prowadzacy','kontrasygnata')),
            user_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
            signed_name  TEXT    NOT NULL,
            content_hash TEXT    NOT NULL,
            signed_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meetings(id) ON DELETE CASCADE,
            action      TEXT    NOT NULL,
            reason      TEXT    NOT NULL DEFAULT '',
            by_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            by_name     TEXT    NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
    ];
    foreach ($tables as $sql) {
        try { $pdo->exec($sql); }
        catch (\Throwable $e) { error_log('ti_meetings_migrate: ' . $e->getMessage()); }
    }
    // Protokół zajęć przypisany do okresu rozliczeniowego (godziny i oceny liczone za ten okres).
    try { $pdo->exec("ALTER TABLE k30_ti_meetings ADD COLUMN period_id INTEGER REFERENCES k30_ti_periods(id) ON DELETE SET NULL"); }
    catch (\Throwable $e) { /* kolumna już istnieje */ }
    // Powiązanie z protokołem godzinowym, z którego skopiowano wpis (jeden wpis = jeden protokół).
    try { $pdo->exec("ALTER TABLE k30_ti_meetings ADD COLUMN legacy_protocol_id INTEGER"); }
    catch (\Throwable $e) { /* kolumna już istnieje */ }
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_meetings_legacy ON k30_ti_meetings(legacy_protocol_id) WHERE legacy_protocol_id IS NOT NULL"); }
    catch (\Throwable $e) { error_log('ti_meetings legacy idx: ' . $e->getMessage()); }
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_meetings_course ON k30_ti_meetings(course_id, meeting_date)"); }
    catch (\Throwable $e) { error_log('ti_meetings_migrate index: ' . $e->getMessage()); }
}
