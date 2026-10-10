<?php
/**
 * modules/ti_meetings/logic/tiMeetingsSchema.php — Protokoły spotkań TI: schemat.
 *
 * Nowy obieg protokołów zastępuje dotychczasowy widok protokołów (lista, edytor, obecność,
 * zadania, zatwierdzanie). Dane godzinowe pozostają w k30_ti_protocols / k30_ti_protocol_entries
 * i nie są tutaj kopiowane ani zmieniane — rozliczenia i wypłaty liczą się jak dotąd.
 *
 * Tabele (prefiks k30_ti_ jak reszta TI):
 *   k30_ti_meeting_records     — protokół spotkania (kurs, tytuł, data, status, wersja)
 *
 * PUŁAPKA: nazwa k30_ti_meetings jest ZAJĘTA przez spotkania online TI (linki Zoom/Teams,
 * includes/karty30.php, online_admin.php). Pierwsza wersja tego modułu użyła tej samej nazwy —
 * CREATE IF NOT EXISTS nic nie robił, a zapytania padały na „no such column: meeting_date”.
 * ti_meetings_heal_names() naprawia bazy, w których powstały już tabele podrzędne z kluczem
 * do k30_ti_meetings.
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
    ti_meetings_heal_names($pdo);
    $tables = [
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_records (
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
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meeting_records(id) ON DELETE CASCADE,
            kind        TEXT    NOT NULL CHECK (kind IN ('obecnosc','przebieg','uchwala','zadanie','inne')),
            position    INTEGER NOT NULL DEFAULT 0,
            title       TEXT    NOT NULL DEFAULT '',
            body        TEXT    NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_attendees (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meeting_records(id) ON DELETE CASCADE,
            client_id   INTEGER REFERENCES k30_clients(id) ON DELETE SET NULL,
            user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
            name_text   TEXT    NOT NULL DEFAULT '',
            present     INTEGER NOT NULL DEFAULT 1,
            UNIQUE (meeting_id, client_id)
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_tasks (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id   INTEGER NOT NULL REFERENCES k30_ti_meeting_records(id) ON DELETE CASCADE,
            item_id      INTEGER REFERENCES k30_ti_meeting_items(id) ON DELETE SET NULL,
            description  TEXT    NOT NULL,
            assignee_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            due_date     DATE,
            status       TEXT    NOT NULL DEFAULT 'otwarte' CHECK (status IN ('otwarte','zrobione','anulowane')),
            reminded_at  TEXT
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_attachments (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meeting_records(id) ON DELETE CASCADE,
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
            meeting_id   INTEGER NOT NULL REFERENCES k30_ti_meeting_records(id) ON DELETE CASCADE,
            role         TEXT    NOT NULL CHECK (role IN ('prowadzacy','kontrasygnata')),
            user_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
            signed_name  TEXT    NOT NULL,
            content_hash TEXT    NOT NULL,
            signed_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE TABLE IF NOT EXISTS k30_ti_meeting_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id  INTEGER NOT NULL REFERENCES k30_ti_meeting_records(id) ON DELETE CASCADE,
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
    try { $pdo->exec("ALTER TABLE k30_ti_meeting_records ADD COLUMN period_id INTEGER REFERENCES k30_ti_periods(id) ON DELETE SET NULL"); }
    catch (\Throwable $e) { /* kolumna już istnieje */ }
    // Powiązanie z protokołem godzinowym, z którego skopiowano wpis (jeden wpis = jeden protokół).
    try { $pdo->exec("ALTER TABLE k30_ti_meeting_records ADD COLUMN legacy_protocol_id INTEGER"); }
    catch (\Throwable $e) { /* kolumna już istnieje */ }
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_meeting_records_legacy ON k30_ti_meeting_records(legacy_protocol_id) WHERE legacy_protocol_id IS NOT NULL"); }
    catch (\Throwable $e) { error_log('ti_meetings legacy idx: ' . $e->getMessage()); }
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_meeting_records_course ON k30_ti_meeting_records(course_id, meeting_date)"); }
    catch (\Throwable $e) { error_log('ti_meetings_migrate index: ' . $e->getMessage()); }
}

/**
 * Naprawa kolizji nazw z pierwszej wersji modułu (patrz PUŁAPKA w nagłówku).
 *
 * 1. Gdy k30_ti_meetings jest tabelą PROTOKOŁÓW (ma meeting_date), a nowej nazwy jeszcze nie ma —
 *    zmieniamy jej nazwę na k30_ti_meeting_records (baza, w której moduł zdążył ją utworzyć).
 * 2. Tabele podrzędne utworzone z kluczem do k30_ti_meetings (tabeli spotkań online) — jeśli
 *    wszystkie są puste, usuwamy je; migracja utworzy je od nowa z kluczem do k30_ti_meeting_records.
 *    Puste muszą być: protokołu nie dało się zapisać, więc nic do nich nie trafiło. Gdyby jednak
 *    były w nich dane — nic nie ruszamy i zapisujemy błąd do logu (do ręcznej poprawki).
 * Kolumn period_id/legacy_protocol_id dopisanych omyłkowo do tabeli spotkań online nie usuwamy —
 * są puste i nikomu nie przeszkadzają.
 */
function ti_meetings_heal_names(\PDO $pdo): void {
    $sql_of = function (string $t) use ($pdo): string {
        $st = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $st->execute([$t]);
        return (string)($st->fetchColumn() ?: '');
    };
    try {
        $old = $sql_of('k30_ti_meetings');
        if ($old !== '' && stripos($old, 'meeting_date') !== false && $sql_of('k30_ti_meeting_records') === '') {
            $pdo->exec("ALTER TABLE k30_ti_meetings RENAME TO k30_ti_meeting_records");
            error_log('ti_meetings: przeniesiono tabelę protokołów k30_ti_meetings → k30_ti_meeting_records');
            return;   // RENAME przepisał też klucze w tabelach podrzędnych
        }
        $children = ['k30_ti_meeting_tasks', 'k30_ti_meeting_attendees', 'k30_ti_meeting_attachments',
                     'k30_ti_meeting_signatures', 'k30_ti_meeting_log', 'k30_ti_meeting_items'];   // items na końcu (tasks → items)
        $bad = [];
        foreach ($children as $t) {
            $sql = $sql_of($t);
            if ($sql !== '' && preg_match('/REFERENCES\s+k30_ti_meetings\s*\(/i', $sql)) $bad[] = $t;
        }
        if (!$bad) return;
        foreach ($bad as $t) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) {
                error_log('ti_meetings: tabela ' . $t . ' ma dane i klucz do k30_ti_meetings — wymaga ręcznej poprawki');
                return;
            }
        }
        foreach ($bad as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
        error_log('ti_meetings: usunięto puste tabele z błędnym kluczem: ' . implode(', ', $bad));
    } catch (\Throwable $e) {
        error_log('ti_meetings_heal_names: ' . $e->getMessage());
    }
}
