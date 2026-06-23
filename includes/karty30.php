<?php
/**
 * includes/karty30.php — Moduł Dydaktyka / Karty 30 (d. TyfloKonsultacje).
 * Auto-migracja tabel k30_* + funkcje pomocnicze.
 */

function karty30_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_clients (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        name            TEXT    NOT NULL,
        email           TEXT,
        phone           TEXT,
        status          TEXT    NOT NULL DEFAULT 'enrolled',
        problem         TEXT,
        equipment       TEXT,
        date_of_birth   DATE,
        gender          TEXT,
        address         TEXT,
        notes           TEXT,
        preferred_contact_method TEXT DEFAULT 'email',
        consent         INTEGER NOT NULL DEFAULT 0,
        available_days  TEXT,
        time_slots      TEXT,
        available_hours REAL    NOT NULL DEFAULT 0,
        used            REAL    NOT NULL DEFAULT 0,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_schedules (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        assigned_to     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        start_time      DATETIME NOT NULL,
        duration_minutes INTEGER NOT NULL DEFAULT 60,
        status          TEXT    NOT NULL DEFAULT 'preliminary',
        description     TEXT,
        cancel_reason   TEXT,
        approved_by_name TEXT,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_k30_sched_client ON k30_schedules(client_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_k30_sched_time   ON k30_schedules(start_time)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_consultations (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        schedule_id     INTEGER REFERENCES k30_schedules(id) ON DELETE SET NULL,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        consultant_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        consultation_datetime DATETIME NOT NULL,
        duration_minutes INTEGER,
        description     TEXT,
        next_action     TEXT,
        status          TEXT    NOT NULL DEFAULT 'draft',
        sign_type       TEXT,
        confirmed       INTEGER NOT NULL DEFAULT 0,
        approved_by_name TEXT,
        sha1sum         TEXT,
        xml_path        TEXT,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_k30_cons_client ON k30_consultations(client_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_k30_cons_dt     ON k30_consultations(consultation_datetime)");

    // Certyfikaty x509 doradców K30 — wymagane do zatwierdzania kart konsultacji
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_consultant_certs (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        cert_pem         TEXT    NOT NULL,
        cert_subject     TEXT    NOT NULL DEFAULT '',
        cert_fingerprint TEXT    NOT NULL DEFAULT '',
        cert_serial      TEXT    NOT NULL DEFAULT '',
        cert_valid_from  INTEGER NOT NULL DEFAULT 0,
        cert_valid_to    INTEGER NOT NULL DEFAULT 0,
        is_active        INTEGER NOT NULL DEFAULT 1,
        uploaded_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        uploaded_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_k30_certs_user ON k30_consultant_certs(user_id)");

    // Kolumny certyfikatu na karcie konsultacji
    foreach ([
        "ALTER TABLE k30_consultations ADD COLUMN cert_fingerprint TEXT",
        "ALTER TABLE k30_consultations ADD COLUMN cert_subject      TEXT",
        "ALTER TABLE k30_consultations ADD COLUMN ika_verified_at   DATETIME",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // Uprawnienie "Doradca TyfloKonsultacje" — może prowadzić konsultacje
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN k30_consultant INTEGER NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {}

    // Zasób zarezerwowany na termin (sala, stanowisko itp.)
    try {
        $pdo->exec("ALTER TABLE k30_schedules ADD COLUMN resource_id INTEGER REFERENCES resources(id) ON DELETE SET NULL");
    } catch (\Throwable $e) {}
    // Flaga "Zdalnie" — termin odbywa się zdalnie (brak fizycznego zasobu)
    try {
        $pdo->exec("ALTER TABLE k30_schedules ADD COLUMN is_remote INTEGER NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {}

    // Dane do faktury — zbierane przy rezerwacji terminu
    foreach ([
        "ALTER TABLE k30_schedules ADD COLUMN needs_invoice    INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_schedules ADD COLUMN invoice_type     TEXT    NOT NULL DEFAULT 'company'",
        "ALTER TABLE k30_schedules ADD COLUMN invoice_name     TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_schedules ADD COLUMN invoice_nip      TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_schedules ADD COLUMN invoice_address  TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_schedules ADD COLUMN invoice_email    TEXT    NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // ── Cennik ────────────────────────────────────────────────────────────────
    // Progi godzinowe: np. 0-2h = 0 zł/h (bezpłatny limit), 2-5h = 80 zł/h, >5h = 100 zł/h
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_price_tiers (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        hours_from  REAL    NOT NULL DEFAULT 0,
        hours_to    REAL,
        rate        REAL    NOT NULL DEFAULT 0,
        label       TEXT    NOT NULL DEFAULT '',
        sort_order  INTEGER NOT NULL DEFAULT 0
    )");
    // Kolumny czasu i kwoty na terminie
    foreach ([
        "ALTER TABLE k30_schedules ADD COLUMN time_from       TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_schedules ADD COLUMN time_to         TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_schedules ADD COLUMN billed_hours    REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_schedules ADD COLUMN free_hours      REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_schedules ADD COLUMN charged_hours   REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_schedules ADD COLUMN amount_due      REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_schedules ADD COLUMN pricing_note    TEXT NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
    // Godziny odpłatne (odrębne od bezpłatnych) na kliencie
    try { $pdo->exec("ALTER TABLE k30_clients ADD COLUMN used_paid REAL NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // ── Umowy PFRON ───────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pfron_contracts (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        contract_number TEXT    NOT NULL,
        hours_limit     REAL    NOT NULL DEFAULT 0,
        hours_used      REAL    NOT NULL DEFAULT 0,
        valid_from      DATE,
        valid_to        DATE,
        status          TEXT    NOT NULL DEFAULT 'active',
        notes           TEXT    NOT NULL DEFAULT '',
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Kolumny trybu rozliczenia i PFRON na terminie
    foreach ([
        "ALTER TABLE k30_schedules ADD COLUMN billing_type      TEXT NOT NULL DEFAULT 'free'",
        "ALTER TABLE k30_schedules ADD COLUMN pfron_contract_id INTEGER REFERENCES k30_pfron_contracts(id) ON DELETE SET NULL",
        "ALTER TABLE k30_schedules ADD COLUMN pfron_status      TEXT NOT NULL DEFAULT ''",
        // Wizyty cykliczne
        "ALTER TABLE k30_schedules ADD COLUMN series_id         TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_schedules ADD COLUMN series_index      INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_schedules ADD COLUMN recurrence_rule   TEXT NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // ── Zajęcia TI (informatyka) ──────────────────────────────────────────────
    // Kurs TI = kontener (nazwa + uczestnicy + stawki).
    // Harmonogram NALEŻY do lekcji — kurs nie ma stałych dni/godzin.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_courses (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        description   TEXT    NOT NULL DEFAULT '',
        instructor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        location      TEXT    NOT NULL DEFAULT '',
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Zapisy klientów do kursu z indywidualną stawką godzinową
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_enrollments (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id)  ON DELETE CASCADE,
        client_id     INTEGER NOT NULL REFERENCES k30_clients(id)     ON DELETE CASCADE,
        hourly_rate   REAL    NOT NULL DEFAULT 0,
        start_date    DATE,
        end_date      DATE,
        status        TEXT    NOT NULL DEFAULT 'active',
        notes         TEXT    NOT NULL DEFAULT '',
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(course_id, client_id)
    )");

    // Konkretne lekcje zajęć
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_sessions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        lesson_date  DATE    NOT NULL,
        time_from     TEXT    NOT NULL DEFAULT '',
        time_to       TEXT    NOT NULL DEFAULT '',
        duration_min  INTEGER NOT NULL DEFAULT 60,
        status        TEXT    NOT NULL DEFAULT 'planned',
        notes         TEXT    NOT NULL DEFAULT '',
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Obecność klientów na lekcji
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_attendance (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id    INTEGER NOT NULL REFERENCES k30_ti_sessions(id)   ON DELETE CASCADE,
        client_id     INTEGER NOT NULL REFERENCES k30_clients(id)        ON DELETE CASCADE,
        attended      INTEGER NOT NULL DEFAULT 0,
        notes         TEXT    NOT NULL DEFAULT '',
        UNIQUE(session_id, client_id)
    )");

    // Miesięczne rozliczenia per klient
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_billing (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id     INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        month         INTEGER NOT NULL,
        year          INTEGER NOT NULL,
        hours_billed  REAL    NOT NULL DEFAULT 0,
        hourly_rate   REAL    NOT NULL DEFAULT 0,
        amount        REAL    NOT NULL DEFAULT 0,
        status        TEXT    NOT NULL DEFAULT 'draft',
        notes         TEXT    NOT NULL DEFAULT '',
        issued_at     DATETIME,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(client_id, month, year)
    )");
    // Migracja: korekta rozliczenia — opłata dodatkowa (+) lub rabat (−)
    foreach ([
        "ALTER TABLE k30_ti_billing ADD COLUMN adjustment      REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_billing ADD COLUMN adjustment_note TEXT NOT NULL DEFAULT ''",
        // Znacznik wysłanego powiadomienia o wystawieniu rozliczenia (SMS/e-mail)
        "ALTER TABLE k30_ti_billing ADD COLUMN notified_at     DATETIME",
        // Termin płatności: data na rozliczeniu (indywidualnie per płatność),
        // domyślna liczba dni na kursie i nadpisanie indywidualne kursanta (zapis).
        "ALTER TABLE k30_ti_billing      ADD COLUMN due_date     DATE",
        "ALTER TABLE k30_ti_courses      ADD COLUMN pay_due_days INTEGER",
        "ALTER TABLE k30_ti_enrollments  ADD COLUMN pay_due_days INTEGER",
        // Płatnik rozliczenia (beneficjent|rodzic|pfron|feer) + faktura (FVAT) załączona przez admina.
        "ALTER TABLE k30_ti_billing ADD COLUMN payer_type   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_billing ADD COLUMN payer_name   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_billing ADD COLUMN invoice_path TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_billing ADD COLUMN invoice_name TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_billing ADD COLUMN invoice_at   DATETIME",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
    // Migracja: miękkie usuwanie kursów TI (status 'active'|'cancelled')
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN status TEXT NOT NULL DEFAULT 'active'"); } catch (\Throwable $e) {}
    // Migracja: session_date → lesson_date (SQLite 3.25+)
    try { $pdo->exec("ALTER TABLE k30_ti_sessions RENAME COLUMN session_date TO lesson_date"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_sessions_course ON k30_ti_sessions(course_id,lesson_date)"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_attend_session  ON k30_ti_attendance(session_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_attend_client   ON k30_ti_attendance(client_id)");

    // Konta kursantów — osobny system logowania, bez dostępu do K30/systemu głównego
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_student_accounts (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id   INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        login       TEXT    NOT NULL UNIQUE,
        password_hash TEXT  NOT NULL,
        is_active   INTEGER NOT NULL DEFAULT 1,
        last_login  DATETIME,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Dodatkowe pola lekcji
    foreach ([
        "ALTER TABLE k30_ti_sessions ADD COLUMN topic            TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_sessions ADD COLUMN instructor_notes TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_sessions ADD COLUMN has_homework     INTEGER NOT NULL DEFAULT 0",
        // Praca własna prowadzącego — przygotowanie materiału do wykonania zdalnie
        "ALTER TABLE k30_ti_sessions ADD COLUMN self_prep_remote INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_sessions ADD COLUMN updated_at       DATETIME",
        // Link do lekcji online (per-lekcja) + stały link grupy (kurs)
        "ALTER TABLE k30_ti_sessions ADD COLUMN meeting_url      TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_courses  ADD COLUMN default_meeting_url TEXT NOT NULL DEFAULT ''",
        // Model rozliczania kursu: 1=miesięczny, 2=godzinowy (domyślny), 3=stały
        "ALTER TABLE k30_ti_courses ADD COLUMN billing_model  INTEGER NOT NULL DEFAULT 2",
        "ALTER TABLE k30_ti_courses ADD COLUMN billing_amount REAL    NOT NULL DEFAULT 0",
        // Override modelu na kursancie (zapisie): 0=dziedziczy z kursu, >0=indywidualny (kod 9999)
        "ALTER TABLE k30_ti_enrollments ADD COLUMN billing_model  INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN billing_amount REAL    NOT NULL DEFAULT 0",
        // Dane do wpłat: domyślne na kursie + indywidualne na kursancie (używane gdy kod 9999)
        "ALTER TABLE k30_ti_courses ADD COLUMN pay_account TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_courses ADD COLUMN pay_title   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN pay_account TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN pay_title   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_attendance ADD COLUMN ind_notes      TEXT NOT NULL DEFAULT ''",
        // Odwołanie całej lekcji (Doradca/admin) — z powodem i autorem
        "ALTER TABLE k30_ti_sessions ADD COLUMN cancel_reason     TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_sessions ADD COLUMN cancelled_by_role TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_sessions ADD COLUMN cancelled_by      TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_sessions ADD COLUMN cancelled_at      DATETIME",
        // Odwołanie udziału pojedynczego uczestnika (Beneficjent/Doradca/admin) — nie liczone do ceny
        "ALTER TABLE k30_ti_attendance ADD COLUMN cancelled         INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_attendance ADD COLUMN cancel_reason     TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_attendance ADD COLUMN cancelled_by_role TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_attendance ADD COLUMN cancelled_by      TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_attendance ADD COLUMN cancelled_at      DATETIME",
        // Prośba kursanta o odwołanie udziału czeka na potwierdzenie prowadzącego
        "ALTER TABLE k30_ti_attendance ADD COLUMN cancel_pending    INTEGER NOT NULL DEFAULT 0",
        // Token prywatnego kanału iCal (subskrypcja lekcji w Google/Apple/Outlook)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN calendar_token TEXT NOT NULL DEFAULT ''",
        // Zgoda kursanta/beneficjenta na powiadomienia SMS o zajęciach (opt-in)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_sms_lessons INTEGER NOT NULL DEFAULT 0",
        // Powiadomienia o nowych wiadomościach w panelu (do wyboru przez kursanta)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_email_messages INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_sms_messages   INTEGER NOT NULL DEFAULT 0",
        // Wymuszenie zmiany hasła przy następnym logowaniu (np. po nadaniu hasła przez admina)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN must_change_password  INTEGER NOT NULL DEFAULT 0",
        // Dodatkowe numery telefonu do powiadomień SMS (np. rodzic/opiekun)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone2 TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone3 TEXT NOT NULL DEFAULT ''",
        // Powiadomienia o zmianach w dydaktyce/eLearningu (nowe materiały, zadania, terminy)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_email_dydaktyka INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_sms_dydaktyka   INTEGER NOT NULL DEFAULT 0",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // ── Moduł wiadomości kursant ↔ prowadzący ─────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_messages (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id     INTEGER NOT NULL REFERENCES k30_ti_student_accounts(id) ON DELETE CASCADE,
        sender         TEXT    NOT NULL DEFAULT 'staff',   -- 'staff' | 'student'
        sender_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        sender_name    TEXT    NOT NULL DEFAULT '',
        subject        TEXT    NOT NULL DEFAULT '',
        body           TEXT    NOT NULL DEFAULT '',
        is_read        INTEGER NOT NULL DEFAULT 0,         -- czy odczytane przez drugą stronę
        read_at        DATETIME,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_msg_student ON k30_ti_messages(student_id,created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_msg_unread  ON k30_ti_messages(student_id,sender,is_read)");

    // ── Oceny lekcji przez kursantów (1–5) ────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_lesson_ratings (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id  INTEGER NOT NULL REFERENCES k30_ti_sessions(id) ON DELETE CASCADE,
        client_id   INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        rating      INTEGER NOT NULL,                    -- 1..5
        comment     TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(session_id, client_id)
    )");

    // ── Licencje na oprogramowanie (inne niż MS365) ───────────────────────────
    // Katalog licencji/oprogramowania (np. Adobe, Canva, antywirus, IDE)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_licenses (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        name         TEXT    NOT NULL,                   -- np. 'Adobe Creative Cloud'
        vendor       TEXT    NOT NULL DEFAULT '',        -- producent/dostawca
        category     TEXT    NOT NULL DEFAULT '',        -- np. grafika, antywirus, IDE
        vendor_url   TEXT    NOT NULL DEFAULT '',        -- link do logowania/pobrania
        seats_total  INTEGER NOT NULL DEFAULT 0,         -- liczba miejsc (0 = bez limitu)
        notes        TEXT    NOT NULL DEFAULT '',
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Przypisania licencji do kursanta (po client_id — jak panel kursanta)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_client_licenses (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        license_id   INTEGER NOT NULL REFERENCES k30_ti_licenses(id) ON DELETE CASCADE,
        client_id    INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        login        TEXT    NOT NULL DEFAULT '',        -- login/konto w danym sofcie
        access_key   TEXT    NOT NULL DEFAULT '',        -- klucz licencyjny / hasło
        notes        TEXT    NOT NULL DEFAULT '',
        expires_at   DATE,                               -- ważność (opcjonalnie)
        status       TEXT    NOT NULL DEFAULT 'active',  -- active | revoked
        assigned_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        assigned_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_cl_lic_client  ON k30_ti_client_licenses(client_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_cl_lic_license ON k30_ti_client_licenses(license_id)");

    // ── Zadania domowe (definicje) + oddawanie (submissions) ──────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_homework (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id    INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        session_id   INTEGER REFERENCES k30_ti_sessions(id) ON DELETE SET NULL, -- opcjonalnie powiązane z lekcją
        title        TEXT    NOT NULL DEFAULT '',
        description  TEXT    NOT NULL DEFAULT '',
        due_at       DATETIME,                       -- termin oddania (opcjonalnie)
        attach_name  TEXT    NOT NULL DEFAULT '',     -- załącznik prowadzącego (oryg. nazwa)
        attach_path  TEXT    NOT NULL DEFAULT '',     -- nazwa pliku na dysku
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_homework_submissions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        homework_id  INTEGER NOT NULL REFERENCES k30_ti_homework(id) ON DELETE CASCADE,
        client_id    INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        body         TEXT    NOT NULL DEFAULT '',     -- treść / komentarz kursanta
        file_name    TEXT    NOT NULL DEFAULT '',     -- oryginalna nazwa pliku
        file_path    TEXT    NOT NULL DEFAULT '',     -- nazwa pliku na dysku
        status       TEXT    NOT NULL DEFAULT 'submitted', -- submitted | graded
        grade        TEXT    NOT NULL DEFAULT '',     -- ocena (dowolny format, np. 4 / 85%)
        feedback     TEXT    NOT NULL DEFAULT '',     -- komentarz prowadzącego
        graded_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        graded_at    DATETIME,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(homework_id, client_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_hw_course ON k30_ti_homework(course_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_hw_sub_hw ON k30_ti_homework_submissions(homework_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_hw_sub_cl ON k30_ti_homework_submissions(client_id)");

    // ── Materiały dydaktyczne / eLearning (powiązane z lekcją) ─────────────────
    // Typ materiału: zadanie | link | plik | dokumentacja | wideo | prezentacja | inne.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_materials (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id    INTEGER NOT NULL REFERENCES k30_ti_courses(id)  ON DELETE CASCADE,
        session_id   INTEGER REFERENCES k30_ti_sessions(id)          ON DELETE SET NULL, -- opcjonalne powiązanie z lekcją
        type         TEXT    NOT NULL DEFAULT 'material', -- zadanie|link|plik|dokumentacja|wideo|prezentacja|inne
        title        TEXT    NOT NULL DEFAULT '',
        description  TEXT    NOT NULL DEFAULT '',
        url          TEXT    NOT NULL DEFAULT '',         -- dla typu link/wideo/dokumentacja online
        attach_name  TEXT    NOT NULL DEFAULT '',         -- załączony plik (oryg. nazwa)
        attach_path  TEXT    NOT NULL DEFAULT '',         -- nazwa pliku na dysku
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_mat_course  ON k30_ti_materials(course_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_mat_session ON k30_ti_materials(session_id)");

    // ── Oceny (e-dziennik) ─────────────────────────────────────────────────────
    // value_text = ocena widoczna (np. '5', '4+', '2-', 'np', 'bz'); value_num = wartość
    // do średniej ważonej (NULL → nie liczy się). weight = waga oceny.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_grades (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        client_id   INTEGER NOT NULL REFERENCES k30_clients(id)    ON DELETE CASCADE,
        session_id  INTEGER REFERENCES k30_ti_sessions(id)         ON DELETE SET NULL,
        category    TEXT    NOT NULL DEFAULT 'inne',  -- sprawdzian|kartkowka|odpowiedz|zadanie|projekt|aktywnosc|inne
        value_text  TEXT    NOT NULL DEFAULT '',
        value_num   REAL,                              -- NULL = nie liczona do średniej
        weight      REAL    NOT NULL DEFAULT 1,
        description TEXT    NOT NULL DEFAULT '',
        graded_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        graded_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_grade_course ON k30_ti_grades(course_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_grade_client ON k30_ti_grades(client_id)");

    // Migracja: daty otwarcia/zamknięcia materiałów i zadań + powiązanie oceny z oddaniem zadania
    foreach ([
        "ALTER TABLE k30_ti_materials ADD COLUMN open_at  DATETIME",
        "ALTER TABLE k30_ti_materials ADD COLUMN close_at DATETIME",
        "ALTER TABLE k30_ti_homework  ADD COLUMN open_at  DATETIME",
        "ALTER TABLE k30_ti_homework  ADD COLUMN close_at DATETIME",
        // Podpowiedź do zadania (wskazówka dla kursanta)
        "ALTER TABLE k30_ti_homework  ADD COLUMN hint     TEXT NOT NULL DEFAULT ''",
        // Ocena w dzienniku wygenerowana z oceny zadania domowego (auto-sync) — by aktualizować, nie duplikować
        "ALTER TABLE k30_ti_grades    ADD COLUMN hw_submission_id INTEGER",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_grade_hwsub ON k30_ti_grades(hw_submission_id)"); } catch (\Throwable $e) {}

    // ── Konta dydaktyków (prowadzących) — osobny panel, bez dostępu do modułu głównego ──
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_accounts (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        login         TEXT    NOT NULL UNIQUE,
        password_hash TEXT    NOT NULL,
        is_active     INTEGER NOT NULL DEFAULT 1,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        last_login    DATETIME,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_instr_user ON k30_ti_instructor_accounts(user_id)");

    // ── Lista oczekujących ────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_waiting_list (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        priority        TEXT    NOT NULL DEFAULT 'zwykly',
        reason          TEXT    NOT NULL DEFAULT '',
        notes           TEXT    NOT NULL DEFAULT '',
        status          TEXT    NOT NULL DEFAULT 'waiting',
        scheduled_id    INTEGER REFERENCES k30_schedules(id) ON DELETE SET NULL,
        sms_sent_at     DATETIME,
        sms_count       INTEGER NOT NULL DEFAULT 0,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_k30_wl_status   ON k30_waiting_list(status,priority,created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_k30_wl_client   ON k30_waiting_list(client_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_blacklist (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id   INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        reason      TEXT,
        added_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(client_id)
    )");

    // ── VLAB — wirtualne maszyny (kontenery Docker) dla kursantów TI ───────────
    // Konfiguracja zdalnego hosta Dockera (pojedynczy wiersz id=1).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_vlab_config (
        id              INTEGER PRIMARY KEY CHECK (id = 1),
        ssh_host        TEXT    NOT NULL DEFAULT '',
        ssh_port        INTEGER NOT NULL DEFAULT 22,
        ssh_user        TEXT    NOT NULL DEFAULT '',
        ssh_auth        TEXT    NOT NULL DEFAULT 'key',   -- 'key' | 'password'
        ssh_key_path    TEXT    NOT NULL DEFAULT '',
        ssh_password    TEXT    NOT NULL DEFAULT '',
        public_host     TEXT    NOT NULL DEFAULT '',      -- host/IP dla linków ttyd i SSH
        ttyd_enabled    INTEGER NOT NULL DEFAULT 1,
        ttyd_scheme     TEXT    NOT NULL DEFAULT 'http',  -- 'http' | 'https'
        max_per_student INTEGER NOT NULL DEFAULT 3,
        default_cpus    TEXT    NOT NULL DEFAULT '1',
        default_mem     TEXT    NOT NULL DEFAULT '512m',
        is_enabled      INTEGER NOT NULL DEFAULT 0,
        updated_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Domyślny wiersz konfiguracji
    $pdo->exec("INSERT OR IGNORE INTO k30_ti_vlab_config (id) VALUES (1)");

    // Katalog szablonów (obrazów Docker) dostępnych dla kursantów
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_vlab_templates (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        description   TEXT    NOT NULL DEFAULT '',
        docker_image  TEXT    NOT NULL,
        run_cmd       TEXT    NOT NULL DEFAULT '',
        cpus          TEXT    NOT NULL DEFAULT '',
        mem           TEXT    NOT NULL DEFAULT '',
        expose_ssh    INTEGER NOT NULL DEFAULT 1,
        expose_ttyd   INTEGER NOT NULL DEFAULT 1,
        is_active     INTEGER NOT NULL DEFAULT 1,
        sort          INTEGER NOT NULL DEFAULT 0,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Kontenery kursantów
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_vlab_containers (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id     INTEGER NOT NULL REFERENCES k30_ti_student_accounts(id) ON DELETE CASCADE,
        client_id      INTEGER REFERENCES k30_clients(id) ON DELETE SET NULL,
        template_id    INTEGER REFERENCES k30_ti_vlab_templates(id) ON DELETE SET NULL,
        label          TEXT    NOT NULL DEFAULT '',
        container_name TEXT    NOT NULL UNIQUE,
        container_id   TEXT    NOT NULL DEFAULT '',
        status         TEXT    NOT NULL DEFAULT 'provisioning', -- provisioning|running|stopped|error|removed
        ssh_port       INTEGER,
        ttyd_port      INTEGER,
        ssh_user       TEXT    NOT NULL DEFAULT '',
        ssh_password   TEXT    NOT NULL DEFAULT '',
        ttyd_user      TEXT    NOT NULL DEFAULT '',
        ttyd_password  TEXT    NOT NULL DEFAULT '',
        error_msg      TEXT    NOT NULL DEFAULT '',
        last_action_at DATETIME,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        removed_at     DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_cont_student ON k30_ti_vlab_containers(student_id,status)");
    // Konto systemowe na hoście, którego logowanie SSH wpuszcza kursanta do kontenera
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_containers ADD COLUMN host_user TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    // Wymuszona zmiana hasła SSH przy najbliższym logowaniu (1 = oczekuje, 0 = brak)
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_containers ADD COLUMN force_pw_pending INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // Domyślne wymuszanie zmiany hasła SSH przy pierwszym logowaniu (konfiguracja globalna)
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_config ADD COLUMN force_pw_first_login INTEGER NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}
    // Czasowe wyłączenie VLAB dla kursantów + komunikat wyświetlany w panelu
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_config ADD COLUMN is_disabled     INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_config ADD COLUMN disabled_notice TEXT    NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    // Zarządzanie portami: zapora hosta (UFW) sterowana przez SSH + reguły NSG w Microsoft Azure (ARM API)
    foreach ([
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN ufw_enabled     INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN az_enabled      INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN az_tenant       TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN az_client_id    TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN az_client_secret TEXT   NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN az_subscription TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN az_resource_group TEXT  NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN az_nsg          TEXT    NOT NULL DEFAULT ''",
        // Czy kursant może sam otwierać/zamykać porty swoich maszyn (w obrębie ich mapowań)
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN ports_self_service INTEGER NOT NULL DEFAULT 1",
    ] as $_sql) { try { $pdo->exec($_sql); } catch (\Throwable $e) {} }
    // Rejestr otwartych portów per kontener (UFW + Azure NSG)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_vlab_ports (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        container_id INTEGER NOT NULL REFERENCES k30_ti_vlab_containers(id) ON DELETE CASCADE,
        host_port    INTEGER NOT NULL,
        proto        TEXT    NOT NULL DEFAULT 'tcp',     -- 'tcp' | 'udp'
        ufw_ok       INTEGER NOT NULL DEFAULT 0,
        az_ok        INTEGER NOT NULL DEFAULT 0,
        az_rule      TEXT    NOT NULL DEFAULT '',
        az_priority  INTEGER,
        note         TEXT    NOT NULL DEFAULT '',
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(container_id, host_port, proto)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_ports_cont ON k30_ti_vlab_ports(container_id)");
    // Sugerowane porty do wystawienia dla szablonu (np. „80,443") — podpowiedź przy tworzeniu maszyny
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_templates ADD COLUMN default_ports TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // ── Dostęp rodzica / małoletni kursant ───────────────────────────────────
    foreach ([
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN is_minor       INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN guardian_name  TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN guardian_phone TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN guardian_email TEXT    NOT NULL DEFAULT ''",
        // Numer kursanta — nadawany przez administratora
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN student_no     TEXT    NOT NULL DEFAULT ''",
        // Blokada dostępu dziecka do panelu nałożona przez opiekuna (kontrola rodzicielska)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN child_access_blocked INTEGER NOT NULL DEFAULT 0",
        // Konto rodzica/opiekuna (login + hasło) — login = pierwsza litera imienia.nazwisko-r
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_login         TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_password_hash TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_must_change   INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_last_login    DATETIME",
        // ── Nauka online: konto MS (tenant szkoleniowy) + konto Moodle ────────
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN ms_user_id        TEXT NOT NULL DEFAULT ''", // objectId w tenancie szkoleniowym
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN ms_upn            TEXT NOT NULL DEFAULT ''", // login MS = login Moodle
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN ms_created_at     DATETIME",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN moodle_user_id    INTEGER",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN moodle_username   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN moodle_created_at DATETIME",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
    // Tokeny linku magicznego dla rodzica (dostęp do rozliczeń dziecka)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_parent_tokens (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id  INTEGER NOT NULL REFERENCES k30_ti_student_accounts(id) ON DELETE CASCADE,
        token       TEXT    NOT NULL UNIQUE,
        expires_at  DATETIME,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parent_tokens_student ON k30_ti_parent_tokens(student_id)");

    // Audyt operacji VLAB
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_vlab_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        container_id INTEGER REFERENCES k30_ti_vlab_containers(id) ON DELETE SET NULL,
        student_id   INTEGER,
        action       TEXT    NOT NULL DEFAULT '',
        ok           INTEGER NOT NULL DEFAULT 0,
        detail       TEXT    NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // ── Szkolenia online (linki ręczne; Teams/Zoom dociągane na żywo z API) ───
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_meetings (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        title       TEXT    NOT NULL DEFAULT '',
        platform    TEXT    NOT NULL DEFAULT 'other',  -- 'zoom' | 'teams' | 'other'
        join_url    TEXT    NOT NULL DEFAULT '',
        course_id   INTEGER REFERENCES k30_ti_courses(id) ON DELETE SET NULL,
        starts_at   DATETIME,
        ends_at     DATETIME,
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_meetings_starts ON k30_ti_meetings(is_active,starts_at)");

    // ── Plan nauczania (program / sylabus kursu) ──────────────────────────────
    // Pozycje planu uporządkowane w obrębie kursu, opcjonalnie zgrupowane w działy
    // (section). Lekcja realizuje N punktów planu (tabela łącząca poniżej).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_curriculum (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id    INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        section      TEXT    NOT NULL DEFAULT '',   -- dział / moduł programu (grupowanie)
        position     INTEGER NOT NULL DEFAULT 0,    -- kolejność w obrębie kursu
        title        TEXT    NOT NULL DEFAULT '',   -- temat / punkt planu
        description  TEXT    NOT NULL DEFAULT '',   -- szczegóły, efekty kształcenia
        est_minutes  INTEGER NOT NULL DEFAULT 0,    -- szacowany czas realizacji (min)
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_curr_course ON k30_ti_curriculum(course_id,position)");

    // Powiązanie: które punkty planu realizuje dana lekcja (wiele-do-wielu)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_session_curriculum (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id    INTEGER NOT NULL REFERENCES k30_ti_sessions(id)    ON DELETE CASCADE,
        curriculum_id INTEGER NOT NULL REFERENCES k30_ti_curriculum(id)  ON DELETE CASCADE,
        UNIQUE(session_id, curriculum_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_sc_session ON k30_ti_session_curriculum(session_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_sc_curr    ON k30_ti_session_curriculum(curriculum_id)");

    // ── Testy / quizy (kreator + podejścia kursanta) ──────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_tests (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id      INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        title          TEXT    NOT NULL DEFAULT '',
        description    TEXT    NOT NULL DEFAULT '',
        time_limit_min INTEGER NOT NULL DEFAULT 0,   -- 0 = bez limitu czasu
        pass_pct       INTEGER NOT NULL DEFAULT 0,   -- próg zaliczenia w % (0 = brak)
        shuffle        INTEGER NOT NULL DEFAULT 0,   -- losowa kolejność pytań
        is_active      INTEGER NOT NULL DEFAULT 0,   -- udostępniony kursantom
        sync_grade     INTEGER NOT NULL DEFAULT 0,   -- wynik trafia do e-dziennika
        created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_tests_course ON k30_ti_tests(course_id)");

    // Pytania: single = jedna poprawna, multi = wiele poprawnych, open = otwarte (ocena ręczna)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_test_questions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        test_id      INTEGER NOT NULL REFERENCES k30_ti_tests(id) ON DELETE CASCADE,
        position     INTEGER NOT NULL DEFAULT 0,
        type         TEXT    NOT NULL DEFAULT 'single', -- single | multi | open
        prompt       TEXT    NOT NULL DEFAULT '',
        points       REAL    NOT NULL DEFAULT 1,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_tq_test ON k30_ti_test_questions(test_id,position)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_test_options (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        question_id  INTEGER NOT NULL REFERENCES k30_ti_test_questions(id) ON DELETE CASCADE,
        position     INTEGER NOT NULL DEFAULT 0,
        label        TEXT    NOT NULL DEFAULT '',
        is_correct   INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_topt_q ON k30_ti_test_options(question_id,position)");

    // Podejście kursanta do testu
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_test_attempts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        test_id      INTEGER NOT NULL REFERENCES k30_ti_tests(id)   ON DELETE CASCADE,
        client_id    INTEGER NOT NULL REFERENCES k30_clients(id)    ON DELETE CASCADE,
        status       TEXT    NOT NULL DEFAULT 'in_progress', -- in_progress | submitted | graded
        score        REAL    NOT NULL DEFAULT 0,
        max_score    REAL    NOT NULL DEFAULT 0,
        needs_review INTEGER NOT NULL DEFAULT 0,   -- czeka na ręczną ocenę pytań otwartych
        started_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        submitted_at DATETIME,
        graded_at    DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_tatt_test   ON k30_ti_test_attempts(test_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_tatt_client ON k30_ti_test_attempts(client_id)");

    // Odpowiedzi w podejściu
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_test_answers (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        attempt_id     INTEGER NOT NULL REFERENCES k30_ti_test_attempts(id)  ON DELETE CASCADE,
        question_id    INTEGER NOT NULL REFERENCES k30_ti_test_questions(id) ON DELETE CASCADE,
        option_ids     TEXT    NOT NULL DEFAULT '',   -- wybrane id wariantów (CSV) dla single/multi
        answer_text    TEXT    NOT NULL DEFAULT '',   -- treść dla pytań otwartych
        points_awarded REAL,                          -- NULL = jeszcze nieoceniona (otwarte)
        is_correct     INTEGER NOT NULL DEFAULT 0,
        UNIQUE(attempt_id, question_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_tans_att ON k30_ti_test_answers(attempt_id)");

    // ── Dostępność prowadzących w tygodniu (okna godzinowe per dzień) ─────────
    // day_of_week zgodne z PHP date('w') i K30_TI_DAYS: 0=Nd, 1=Pn … 6=Sb.
    // Dozwolone wiele okien w jednym dniu (np. 9:00–12:00 i 15:00–18:00).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_availability (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        day_of_week   INTEGER NOT NULL,
        time_from     TEXT    NOT NULL DEFAULT '',
        time_to       TEXT    NOT NULL DEFAULT '',
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_avail_instr ON k30_ti_instructor_availability(instructor_id, day_of_week)");
}

// Konfiguracja statusów harmonogramu
const K30_SCHEDULE_STATUSES = [
    'preliminary'        => ['label' => 'Wstępna',                  'color' => '#F59E0B', 'bg' => '#FEF3E2', 'icon' => 'bi-clock'],
    'confirmed'          => ['label' => 'Potwierdzona',              'color' => '#2E844A', 'bg' => '#EFF7ED', 'icon' => 'bi-check-circle'],
    'attended'           => ['label' => 'Odbyta',                    'color' => '#0176D3', 'bg' => '#EEF4FF', 'icon' => 'bi-person-check'],
    'cancelled_by_feer'  => ['label' => 'Odwołana przez FEER',       'color' => '#DC2626', 'bg' => '#FEF2F2', 'icon' => 'bi-x-circle'],
    'cancelled_by_client'=> ['label' => 'Odwołana przez beneficjenta','color'=> '#D97706', 'bg' => '#FEF3E2', 'icon' => 'bi-x-octagon'],
    'no_show'            => ['label' => 'Nie pojawił się',            'color' => '#7C3AED', 'bg' => '#F5F3FF', 'icon' => 'bi-dash-circle'],
    'cancelled'          => ['label' => 'Odwołana',                  'color' => '#9CA3AF', 'bg' => '#F3F4F6', 'icon' => 'bi-slash-circle'],
];

const K30_CLIENT_STATUSES = [
    'enrolled'  => ['label' => 'Zarejestrowany', 'color' => '#0176D3', 'bg' => '#EEF4FF'],
    'ready'     => ['label' => 'Aktywny',         'color' => '#2E844A', 'bg' => '#EFF7ED'],
    'to_settle' => ['label' => 'Do rozliczenia',  'color' => '#D97706', 'bg' => '#FEF3E2'],
    'other'     => ['label' => 'Inny',            'color' => '#9CA3AF', 'bg' => '#F3F4F6'],
];

const K30_CONSULTATION_STATUSES = [
    'draft'     => ['label' => 'Robocza',      'color' => '#9CA3AF', 'bg' => '#F3F4F6'],
    'completed' => ['label' => 'Zatwierdzona', 'color' => '#2E844A', 'bg' => '#EFF7ED'],
    'cancelled' => ['label' => 'Anulowana',    'color' => '#DC2626', 'bg' => '#FEF2F2'],
];

// Tryby rozliczenia terminu
const K30_BILLING_TYPES = [
    'free'  => ['label' => 'Bezpłatne',  'color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'bi-gift'],
    'paid'  => ['label' => 'Odpłatne',   'color' => '#2563eb', 'bg' => '#eff6ff', 'icon' => 'bi-credit-card'],
    'pfron' => ['label' => 'PFRON',      'color' => '#7c3aed', 'bg' => '#f5f3ff', 'icon' => 'bi-building-fill-check'],
];

// Statusy rozliczenia PFRON
const K30_PFRON_STATUSES = [
    'pending'   => ['label' => 'Do złożenia',    'color' => '#9CA3AF', 'bg' => '#F3F4F6'],
    'submitted' => ['label' => 'Złożone',        'color' => '#2563EB', 'bg' => '#EFF6FF'],
    'approved'  => ['label' => 'Zatwierdzone',   'color' => '#16A34A', 'bg' => '#F0FDF4'],
    'rejected'  => ['label' => 'Odrzucone',      'color' => '#DC2626', 'bg' => '#FEF2F2'],
    'resubmit'  => ['label' => 'Do ponowienia',  'color' => '#D97706', 'bg' => '#FEF3E2'],
];

// Statusy umowy PFRON
const K30_PFRON_CONTRACT_STATUSES = [
    'active'   => ['label' => 'Aktywna',   'color' => '#16A34A', 'bg' => '#F0FDF4'],
    'expired'  => ['label' => 'Wygasła',   'color' => '#9CA3AF', 'bg' => '#F3F4F6'],
    'closed'   => ['label' => 'Zamknięta', 'color' => '#DC2626', 'bg' => '#FEF2F2'],
];

function k30_status_badge(string $status, string $type = 'schedule'): string {
    $cfg = match($type) {
        'client'       => K30_CLIENT_STATUSES,
        'consultation' => K30_CONSULTATION_STATUSES,
        default        => K30_SCHEDULE_STATUSES,
    };
    $s = $cfg[$status] ?? ['label' => $status, 'color' => '#6B7280', 'bg' => '#F3F4F6'];
    return '<span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:2rem;font-size:.73rem;font-weight:600;background:' . h($s['bg']) . ';color:' . h($s['color']) . '">'
        . (isset($s['icon']) ? '<i class="bi ' . h($s['icon']) . '"></i>' : '')
        . h($s['label']) . '</span>';
}

function k30_available_hours(int $client_id): float {
    $c = db_one("SELECT available_hours, used FROM k30_clients WHERE id=?", [$client_id]);
    if (!$c) return 0;
    return max(0, (float)$c['available_hours'] - (float)$c['used']);
}

/**
 * Zwraca listę aktywnych doradców TyfloKonsultacje (k30_consultant=1).
 * Wolontariusze i inni użytkownicy mogą być doradcami niezależnie od roli.
 */
function k30_get_consultants(): array {
    return db_all(
        "SELECT id,
                CASE WHEN first_name != '' AND last_name != ''
                     THEN first_name || ' ' || last_name
                     ELSE name END AS display_name,
                name, email, role
         FROM users
         WHERE is_active=1 AND k30_consultant=1
         ORDER BY display_name"
    );
}

function k30_require_access(): void {
    require_login();
    if (can_read('karty30') || is_admin()) return;

    // Doradcy K30 mają dostęp przez flagę k30_consultant w tabeli users
    $user = current_user();
    try {
        $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)($user['id'] ?? 0)]);
        if (!empty($row['k30_consultant'])) return;
    } catch (\Throwable $e) {}

    flash_set('danger', 'Brak dostępu do modułu Karty 30.');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

function k30_is_consultant(): bool {
    if (is_admin() || can_read('karty30')) return true;
    $user = current_user();
    if (!$user) return false;
    try {
        $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)$user['id']]);
        return !empty($row['k30_consultant']);
    } catch (\Throwable $e) { return false; }
}

// ── Certyfikaty x509 doradców ─────────────────────────────────────────────────

/**
 * Parsuje certyfikat PEM i zwraca kluczowe dane lub null przy błędzie.
 */
function k30_parse_cert(string $pem): ?array {
    if (!extension_loaded('openssl')) return null;
    $parsed = @openssl_x509_parse($pem);
    if (!$parsed) return null;

    // Fingerprint SHA1 (openssl x509 -fingerprint)
    $der        = '';
    openssl_x509_export_to_file($pem, 'php://memory');  // nie używamy — obliczamy inaczej
    $fingerprint = '';
    $res = openssl_x509_read($pem);
    if ($res) {
        $derData = '';
        openssl_x509_export($res, $certPem);
        // Wyciągnij base64 z PEM i zdekoduj do DER
        $b64 = preg_replace('/-----[^-]+-----|\s/', '', $certPem);
        $derData = base64_decode($b64);
        $fingerprint = strtoupper(implode(':', str_split(sha1($derData), 2)));
    }

    $subject = '';
    foreach (['CN','O','emailAddress','E'] as $k) {
        if (!empty($parsed['subject'][$k])) {
            $subject .= ($subject ? ', ' : '') . $k . '=' . $parsed['subject'][$k];
        }
    }

    return [
        'subject'     => $subject ?: ($parsed['name'] ?? 'Nieznany'),
        'fingerprint' => $fingerprint,
        'serial'      => $parsed['serialNumberHex'] ?? '',
        'valid_from'  => $parsed['validFrom_time_t'] ?? 0,
        'valid_to'    => $parsed['validTo_time_t']   ?? 0,
        'email'       => $parsed['subject']['emailAddress'] ?? ($parsed['subject']['E'] ?? ''),
        'cn'          => $parsed['subject']['CN'] ?? '',
    ];
}

/**
 * Zwraca aktywny certyfikat doradcy lub null.
 */
function k30_consultant_cert(int $user_id): ?array {
    return db_one("SELECT * FROM k30_consultant_certs WHERE user_id=? AND is_active=1", [$user_id]) ?: null;
}

/**
 * Weryfikuje certyfikat doradcy:
 * - czy istnieje
 * - czy nie wygasł
 * Zwraca ['ok'=>bool, 'error'=>string|null, 'cert'=>array|null]
 */
function k30_verify_consultant_cert(int $user_id): array {
    $cert = k30_consultant_cert($user_id);
    if (!$cert) {
        return ['ok' => false, 'error' => 'Brak certyfikatu x509. Skontaktuj się z administratorem.', 'cert' => null];
    }
    if ((int)$cert['cert_valid_to'] < time()) {
        return ['ok' => false, 'error' => 'Certyfikat x509 wygasł ' . date('d.m.Y', (int)$cert['cert_valid_to']) . '. Skontaktuj się z administratorem.', 'cert' => $cert];
    }
    // Weryfikacja parsowania (certyfikat nadal odczytywalny)
    $parsed = k30_parse_cert($cert['cert_pem']);
    if (!$parsed) {
        return ['ok' => false, 'error' => 'Certyfikat x509 jest uszkodzony lub nieczytelny.', 'cert' => $cert];
    }
    return ['ok' => true, 'error' => null, 'cert' => $cert, 'parsed' => $parsed];
}

// ── Integracja z CRM ──────────────────────────────────────────────────────────

/**
 * Znajdź lub utwórz grupę CRM "Beneficjenci — Konsultacje Tyflo".
 * Zwraca ID grupy.
 */
function k30_crm_group_id(): int {
    static $gid = null;
    if ($gid !== null) return $gid;

    try {
        // Sprawdź czy crm_groups istnieje
        $existing = db_one("SELECT id FROM crm_groups WHERE auto_source='k30_beneficjenci'");
        if ($existing) {
            $gid = (int)$existing['id'];
            return $gid;
        }

        $gid = db_insert('crm_groups', [
            'name'        => 'Beneficjenci — Konsultacje Tyflo',
            'description' => 'Beneficjenci programu TyfloKonsultacje / Karty 30 — dodawani automatycznie',
            'color'       => '#7C3AED',
            'icon'        => 'bi-card-checklist',
            'auto_source' => 'k30_beneficjenci',
            'sort_order'  => 10,
            'created_by'  => null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return $gid;
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Synchronizuje beneficjenta K30 z kontaktem CRM.
 * - Tworzy lub aktualizuje kontakt CRM po email/nazwisku
 * - Dodaje do grupy "Beneficjenci — Konsultacje Tyflo"
 * - Dodaje tag "beneficjent-tyflo"
 * Zwraca CRM contact_id lub 0 przy błędzie.
 */
function k30_sync_to_crm(array $client, ?int $created_by = null): int {
    try {
        require_once __DIR__ . '/crm.php';
        crm_migrate();

        $email = trim($client['email'] ?? '');
        $name  = trim($client['name']  ?? '');
        if (!$name) return 0;

        // Znajdź istniejący kontakt
        $contact_id = null;
        if ($email) {
            $ex = db_one("SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1", [$email]);
            if ($ex) $contact_id = (int)$ex['id'];
        }
        if (!$contact_id) {
            $ex = db_one("SELECT id FROM crm_contacts WHERE imie_nazwisko=? AND crm_active=1 ORDER BY id DESC LIMIT 1", [$name]);
            if ($ex) $contact_id = (int)$ex['id'];
        }

        if ($contact_id) {
            // Zaktualizuj istniejący
            $upd = [];
            if ($email && $email !== (db_one("SELECT email FROM crm_contacts WHERE id=?",[$contact_id])['email']??''))
                $upd['email'] = $email;
            if ($client['phone'] ?? '') $upd['telefon'] = $client['phone'];
            if ($upd) CrmManager::updateContact($contact_id, $upd);
        } else {
            // Stwórz nowy
            $contact_id = CrmManager::createContact([
                'type'          => 'osoba',
                'imie_nazwisko' => $name,
                'email'         => $email ?: null,
                'telefon'       => $client['phone'] ?? null,
                'adres'         => $client['address'] ?? null,
                'status'        => 'aktywny',
                'source'        => 'k30_auto',
                'created_by'    => $created_by,
            ]);
        }

        // Tag beneficjent-tyflo
        try {
            db()->prepare("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)")
                ->execute([$contact_id, 'beneficjent-tyflo']);
        } catch (\Throwable $e) {}

        // Dodaj do grupy
        $gid = k30_crm_group_id();
        if ($gid) CrmManager::addToGroup($gid, $contact_id, $created_by);

        return $contact_id;
    } catch (\Throwable $e) {
        error_log('[k30_crm] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Zapisuje aktywność K30 (konsultacja/odwołanie) jako planowane działanie CRM
 * na karcie kontaktu. Wymaga wcześniejszego k30_sync_to_crm().
 *
 * @param int    $contact_id   CRM contact_id
 * @param string $type         Typ: 'meeting' | 'call' | 'task'
 * @param string $title        Tytuł aktywności
 * @param string $description  Opis
 * @param string $scheduled_at Datetime ISO
 * @param string $status       'done' | 'planned' | 'cancelled'
 * @param string $outcome      Wynik (dla zakończonych)
 */
function k30_log_crm_activity(
    int    $contact_id,
    string $type,
    string $title,
    string $description = '',
    string $scheduled_at = '',
    string $status = 'done',
    string $outcome = '',
    ?int   $created_by = null
): void {
    try {
        require_once __DIR__ . '/crm.php';
        crm_migrate();

        db_insert('crm_activities', [
            'contact_id'   => $contact_id,
            'type'         => $type,
            'title'        => $title,
            'description'  => $description ?: null,
            'scheduled_at' => $scheduled_at ?: null,
            'status'       => $status,
            'outcome'      => $outcome ?: null,
            'assigned_to'  => $created_by,
            'created_by'   => $created_by,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null,
        ]);
    } catch (\Throwable $e) {
        error_log('[k30_crm_act] ' . $e->getMessage());
    }
}

/**
 * Pobiera CRM contact_id powiązany z beneficjentem K30.
 * Szuka po emailu lub imieniu i nazwisku.
 */
function k30_get_crm_contact(array $client): int {
    $email = trim($client['email'] ?? '');
    $name  = trim($client['name']  ?? '');
    try {
        if ($email) {
            $r = db_one("SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1", [$email]);
            if ($r) return (int)$r['id'];
        }
        if ($name) {
            $r = db_one("SELECT id FROM crm_contacts WHERE imie_nazwisko=? AND crm_active=1 ORDER BY id DESC LIMIT 1", [$name]);
            if ($r) return (int)$r['id'];
        }
    } catch (\Throwable $e) {}
    return 0;
}

// ── Cennik ────────────────────────────────────────────────────────────────────

/** Globalny darmowy limit godzin (ustawienie admina). */
function k30_free_hours_limit(): float {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_='k30_free_hours_limit'");
        return (float)($r['value'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Wszystkie progi cennika, posortowane. */
function k30_price_tiers(): array {
    try {
        return db_all("SELECT * FROM k30_price_tiers ORDER BY sort_order, hours_from");
    } catch (\Throwable $e) { return []; }
}

/**
 * Oblicza kwotę do zapłaty za termin.
 *
 * Logika:
 *  1. Policz łączne godziny już wykorzystane przez klienta (used).
 *  2. Globalny darmowy limit = k30_free_hours_limit().
 *     Indywidualny darmowy limit = k30_clients.available_hours (0 = brak).
 *     Efektywny limit = max(globalny, indywidualny).
 *  3. Godziny bezpłatne tego terminu = max(0, limit - used).
 *  4. Godziny płatne = billed_hours - free_hours.
 *  5. Kwota = sumuj wg progów cennika dla godzin płatnych.
 *
 * @return array [
 *   billed_hours   float  — czas trwania w h
 *   free_hours     float  — godziny bezpłatne
 *   charged_hours  float  — godziny płatne
 *   amount_due     float  — kwota PLN
 *   pricing_note   string — opis kalkulacji
 *   tiers_used     array  — progi użyte
 * ]
 */
function k30_calculate_amount(int $client_id, float $billed_hours, int $exclude_schedule_id = 0): array {
    // Dotychczasowe godziny klienta (bez bieżącego terminu)
    $excl  = $exclude_schedule_id ? "AND s.id != {$exclude_schedule_id}" : '';
    $used_row = db_one(
        "SELECT COALESCE(SUM(s.billed_hours), 0) AS total
         FROM k30_schedules s
         WHERE s.client_id=? AND s.status NOT IN ('cancelled','rejected') {$excl}",
        [$client_id]
    );
    $already_used = (float)($used_row['total'] ?? 0);

    // Efektywny darmowy limit
    $global_limit = k30_free_hours_limit();
    $client = db_one("SELECT available_hours FROM k30_clients WHERE id=?", [$client_id]);
    $ind_limit    = (float)($client['available_hours'] ?? 0);
    $free_limit   = max($global_limit, $ind_limit);

    // Godziny bezpłatne w tym terminie
    $free_used    = min($already_used, $free_limit);
    $free_remaining = max(0, $free_limit - $free_used);
    $free_hours   = min($billed_hours, $free_remaining);
    $charged_hours = round($billed_hours - $free_hours, 4);

    // Wylicz kwotę według progów
    $tiers = k30_price_tiers();
    $amount_due = 0.0;
    $notes = [];
    $tiers_used = [];

    if ($charged_hours > 0 && $tiers) {
        // Punkt startowy w cennikowych godzinach odpłatnych
        $client_paid = (float)(db_one(
            "SELECT COALESCE(SUM(s.charged_hours), 0) AS total FROM k30_schedules s
             WHERE s.client_id=? AND s.status NOT IN ('cancelled','rejected') {$excl}",
            [$client_id]
        )['total'] ?? 0);
        $remaining = $charged_hours;
        $pos = $client_paid; // aktualna pozycja na skali płatnych godzin

        foreach ($tiers as $tier) {
            if ($remaining <= 0) break;
            $t_from = (float)$tier['hours_from'];
            $t_to   = $tier['hours_to'] !== null ? (float)$tier['hours_to'] : PHP_FLOAT_MAX;
            if ($pos >= $t_to) continue;
            $start  = max($pos, $t_from);
            $avail  = $t_to - $start;
            $in_tier= min($remaining, $avail);
            if ($in_tier <= 0) continue;
            $cost = round($in_tier * (float)$tier['rate'], 2);
            $amount_due += $cost;
            $tiers_used[] = ['tier' => $tier, 'hours' => $in_tier, 'cost' => $cost];
            $notes[] = sprintf('%s h × %.2f zł/h = %.2f zł%s',
                number_format($in_tier, 2, ',', ''),
                $tier['rate'],
                $cost,
                $tier['label'] ? " ({$tier['label']})" : ''
            );
            $remaining -= $in_tier;
            $pos += $in_tier;
        }
    }

    $pricing_note_parts = [];
    if ($free_hours > 0) {
        $pricing_note_parts[] = number_format($free_hours, 2, ',', '') . ' h bezpłatnie (limit: ' . number_format($free_limit, 2, ',', '') . ' h)';
    }
    $pricing_note_parts = array_merge($pricing_note_parts, $notes);
    if ($amount_due == 0 && $charged_hours == 0) {
        $pricing_note_parts[] = 'Bezpłatne';
    }

    return [
        'billed_hours'  => $billed_hours,
        'free_hours'    => $free_hours,
        'charged_hours' => $charged_hours,
        'amount_due'    => round($amount_due, 2),
        'pricing_note'  => implode(' + ', $pricing_note_parts),
        'tiers_used'    => $tiers_used,
    ];
}

// ── Umowy PFRON ───────────────────────────────────────────────────────────────

function k30_pfron_contracts_for_client(int $client_id): array {
    return db_all(
        "SELECT * FROM k30_pfron_contracts WHERE client_id=? ORDER BY created_at DESC",
        [$client_id]
    );
}

function k30_pfron_contract_get(int $id): ?array {
    return db_one("SELECT * FROM k30_pfron_contracts WHERE id=?", [$id]) ?: null;
}

function k30_pfron_contract_save(array $data, ?int $id = null): int {
    $fields = ['client_id','contract_number','hours_limit','valid_from','valid_to','status','notes'];
    $data['updated_at'] = date('Y-m-d H:i:s');
    if ($id) {
        $set = []; $params = [];
        foreach (array_merge($fields, ['updated_at']) as $f) {
            if (array_key_exists($f, $data)) { $set[] = "$f=?"; $params[] = $data[$f]; }
        }
        $params[] = $id;
        db()->prepare("UPDATE k30_pfron_contracts SET " . implode(',', $set) . " WHERE id=?")->execute($params);
        return $id;
    }
    $data['created_by'] = (int)(current_user()['id'] ?? 0);
    $data['created_at'] = date('Y-m-d H:i:s');
    return db_insert('k30_pfron_contracts', array_intersect_key($data, array_flip(
        array_merge($fields, ['created_by','created_at','updated_at'])
    )));
}

function k30_pfron_hours_remaining(int $contract_id): float {
    $c = db_one("SELECT hours_limit, hours_used FROM k30_pfron_contracts WHERE id=?", [$contract_id]);
    if (!$c) return 0;
    return max(0, (float)$c['hours_limit'] - (float)$c['hours_used']);
}

/**
 * Oblicza kwotę z uwzględnieniem trybu rozliczenia (free / paid / pfron).
 *
 * billing_type='pfron': godziny wliczają się w limit umowy PFRON (bezpłatnie),
 * nadwyżka ponad limit — odpłatnie według cennika.
 * billing_type='free':  bezpłatnie w ramach globalnego/indywidualnego limitu.
 * billing_type='paid':  całość odpłatnie według cennika (bez limitu bezpłatnego).
 */
function k30_calculate_amount_v2(
    int    $client_id,
    float  $billed_hours,
    string $billing_type = 'free',
    ?int   $pfron_contract_id = null,
    int    $exclude_schedule_id = 0
): array {
    $excl = $exclude_schedule_id ? "AND s.id != {$exclude_schedule_id}" : '';

    // ── PFRON ─────────────────────────────────────────────────────────────────
    if ($billing_type === 'pfron' && $pfron_contract_id) {
        $remaining = k30_pfron_hours_remaining($pfron_contract_id);
        $free_hours    = min($billed_hours, $remaining);
        $charged_hours = max(0, round($billed_hours - $free_hours, 4));
        $contract      = k30_pfron_contract_get($pfron_contract_id);
        $cn            = $contract['contract_number'] ?? '';

        $amount_due = 0.0;
        $notes = [];
        if ($free_hours > 0) {
            $notes[] = number_format($free_hours,2,',','') . " h bezpłatnie (PFRON {$cn})";
        }
        // Nadwyżka → cennik
        if ($charged_hours > 0) {
            $paid_already = (float)(db_one(
                "SELECT COALESCE(SUM(s.charged_hours),0) AS t FROM k30_schedules s
                 WHERE s.client_id=? AND s.billing_type='pfron'
                 AND s.status NOT IN ('cancelled','rejected') {$excl}", [$client_id]
            )['t'] ?? 0);
            [$amount_due, $tier_notes] = _k30_apply_tiers($charged_hours, $paid_already);
            $notes = array_merge($notes, $tier_notes);
        }

        return [
            'billed_hours'  => $billed_hours,
            'free_hours'    => $free_hours,
            'charged_hours' => $charged_hours,
            'amount_due'    => round($amount_due, 2),
            'pricing_note'  => implode(' + ', $notes) ?: 'PFRON',
            'billing_type'  => 'pfron',
        ];
    }

    // ── ODPŁATNE — całość wg cennika ─────────────────────────────────────────
    if ($billing_type === 'paid') {
        $paid_already = (float)(db_one(
            "SELECT COALESCE(SUM(s.charged_hours),0) AS t FROM k30_schedules s
             WHERE s.client_id=? AND s.billing_type='paid'
             AND s.status NOT IN ('cancelled','rejected') {$excl}", [$client_id]
        )['t'] ?? 0);
        [$amount_due, $notes] = _k30_apply_tiers($billed_hours, $paid_already);
        return [
            'billed_hours'  => $billed_hours,
            'free_hours'    => 0,
            'charged_hours' => $billed_hours,
            'amount_due'    => round($amount_due, 2),
            'pricing_note'  => implode(' + ', $notes) ?: 'Odpłatne',
            'billing_type'  => 'paid',
        ];
    }

    // ── BEZPŁATNE — w ramach globalnego/indywidualnego limitu ────────────────
    $already_used = (float)(db_one(
        "SELECT COALESCE(SUM(s.billed_hours),0) AS t FROM k30_schedules s
         WHERE s.client_id=? AND s.billing_type='free'
         AND s.status NOT IN ('cancelled','rejected') {$excl}", [$client_id]
    )['t'] ?? 0);

    $global_limit = k30_free_hours_limit();
    $client       = db_one("SELECT available_hours FROM k30_clients WHERE id=?", [$client_id]);
    $ind_limit    = (float)($client['available_hours'] ?? 0);
    $free_limit   = max($global_limit, $ind_limit);

    $free_remaining = max(0, $free_limit - $already_used);
    $free_hours     = min($billed_hours, $free_remaining);
    $charged_hours  = round($billed_hours - $free_hours, 4);

    $amount_due = 0.0; $notes = [];
    if ($free_hours > 0) {
        $notes[] = number_format($free_hours,2,',','') . ' h bezpłatnie (limit: ' . number_format($free_limit,2,',','') . ' h)';
    }
    if ($charged_hours > 0) {
        $paid_already = (float)(db_one(
            "SELECT COALESCE(SUM(s.charged_hours),0) AS t FROM k30_schedules s
             WHERE s.client_id=? AND s.billing_type='free'
             AND s.status NOT IN ('cancelled','rejected') {$excl}", [$client_id]
        )['t'] ?? 0);
        [$amount_due, $tier_notes] = _k30_apply_tiers($charged_hours, $paid_already);
        $notes = array_merge($notes, $tier_notes);
    }
    if (!$notes) $notes[] = 'Bezpłatne';

    return [
        'billed_hours'  => $billed_hours,
        'free_hours'    => $free_hours,
        'charged_hours' => $charged_hours,
        'amount_due'    => round($amount_due, 2),
        'pricing_note'  => implode(' + ', $notes),
        'billing_type'  => 'free',
    ];
}

/** Pomocnicza: wylicza kwotę z cennika dla zadanej liczby godzin odpłatnych,
 *  zaczynając od pozycji $already_paid_hours na skali. */
function _k30_apply_tiers(float $hours, float $already_paid_hours): array {
    $tiers  = k30_price_tiers();
    $amount = 0.0; $notes = [];
    $pos    = $already_paid_hours;
    $rem    = $hours;
    foreach ($tiers as $t) {
        if ($rem <= 0) break;
        $tTo   = $t['hours_to'] !== null ? (float)$t['hours_to'] : PHP_FLOAT_MAX;
        if ($pos >= $tTo) continue;
        $start  = max($pos, (float)$t['hours_from']);
        $avail  = $tTo - $start;
        $inTier = min($rem, $avail);
        if ($inTier <= 0) continue;
        $cost   = round($inTier * (float)$t['rate'], 2);
        $amount += $cost;
        $notes[] = sprintf('%s h × %s zł/h = %s zł%s',
            number_format($inTier,2,',',''),
            number_format((float)$t['rate'],2,',',''),
            number_format($cost,2,',',''),
            $t['label'] ? " ({$t['label']})" : ''
        );
        $rem -= $inTier;
        $pos += $inTier;
    }
    return [$amount, $notes];
}

// ── Wizyty cykliczne ─────────────────────────────────────────────────────────

/**
 * Generuje daty dla serii wizyt na podstawie reguły powtarzania.
 *
 * @param string $date_start  Data pierwszej wizyty (Y-m-d)
 * @param string $freq        Częstotliwość: 'daily'|'weekly'|'biweekly'|'monthly'
 * @param array  $week_days   Dla 'weekly'/'biweekly': dni tygodnia [0=Nd,1=Pn…6=Sb]
 * @param int    $count       Liczba powtórzeń (0 = użyj until)
 * @param string $until       Data końca serii (Y-m-d), używana gdy count=0
 * @param int    $max         Maksymalna liczba terminów (safety cap)
 * @return string[]           Tablica dat Y-m-d
 */
function k30_generate_series_dates(
    string $date_start,
    string $freq,
    array  $week_days = [],
    int    $count     = 0,
    string $until     = '',
    int    $max       = 52
): array {
    $dates   = [];
    $current = new DateTimeImmutable($date_start);
    $end_dt  = $until ? new DateTimeImmutable($until) : null;
    $limit   = $count > 0 ? min($count, $max) : $max;

    // Normalizuj dni tygodnia
    $week_days = array_unique(array_map('intval', $week_days));
    if (empty($week_days)) $week_days = [(int)$current->format('w')]; // domyślnie dzień startowy

    $i = 0;
    $iter = clone $current;

    while ($i < $limit) {
        if ($end_dt && $iter > $end_dt) break;

        switch ($freq) {
            case 'daily':
                $dates[] = $iter->format('Y-m-d');
                $iter = $iter->modify('+1 day');
                $i++;
                break;

            case 'weekly':
            case 'biweekly':
                $step = $freq === 'biweekly' ? 2 : 1;
                // Wygeneruj wszystkie dni w bieżącym tygodniu (lub co-tygodniu)
                $week_start = $iter->modify('this week monday');
                // Upewnij się że idziemy do przodu
                if ($week_start > $iter) $week_start = $week_start->modify('-7 days');

                foreach (range(0, 6) as $d) {
                    $day = $week_start->modify("+{$d} days");
                    $dow = (int)$day->format('w'); // 0=Nd..6=Sb
                    if (!in_array($dow, $week_days)) continue;
                    if ($day < $current) continue;
                    if ($end_dt && $day > $end_dt) { $i = $limit; break; }
                    $dates[] = $day->format('Y-m-d');
                    $i++;
                    if ($i >= $limit) break;
                }
                $iter = $week_start->modify("+{$step} weeks");
                break;

            case 'monthly':
                $dates[] = $iter->format('Y-m-d');
                $iter = $iter->modify('+1 month');
                $i++;
                break;
        }
    }

    return array_unique($dates);
}

/**
 * Tworzy serię terminów K30.
 * Zwraca tablicę ID nowo utworzonych terminów.
 */
function k30_create_series(array $base_data, array $dates): array {
    $series_id   = bin2hex(random_bytes(8));
    $rule        = $base_data['recurrence_rule'] ?? '';
    $created_ids = [];

    foreach ($dates as $idx => $date) {
        $start_time = $date . ' ' . substr($base_data['start_time'], 11, 8);
        $billed     = round((int)$base_data['duration_minutes'] / 60, 4);
        $pricing    = k30_calculate_amount_v2(
            (int)$base_data['client_id'],
            $billed,
            $base_data['billing_type'] ?? 'free',
            $base_data['pfron_contract_id'] ?? null
        );

        $row = array_merge($base_data, [
            'start_time'    => $start_time,
            'series_id'     => $series_id,
            'series_index'  => $idx,
            'recurrence_rule'=> $rule,
            'billed_hours'  => $pricing['billed_hours'],
            'free_hours'    => $pricing['free_hours'],
            'charged_hours' => $pricing['charged_hours'],
            'amount_due'    => $pricing['amount_due'],
            'pricing_note'  => $pricing['pricing_note'],
        ]);
        unset($row['recurrence_rule']); // przechowujemy tylko w pierwszym terminie
        if ($idx === 0) $row['recurrence_rule'] = $rule;

        $id = db_insert('k30_schedules', $row);
        $created_ids[] = $id;

        // Aktualizuj liczniki
        db()->prepare(
            "UPDATE k30_clients SET used=used+?, used_paid=used_paid+?, updated_at=datetime('now') WHERE id=?"
        )->execute([$pricing['billed_hours'], $pricing['charged_hours'], $base_data['client_id']]);

        if (!empty($base_data['pfron_contract_id']) && $pricing['free_hours'] > 0) {
            db()->prepare(
                "UPDATE k30_pfron_contracts SET hours_used=hours_used+?, updated_at=datetime('now') WHERE id=?"
            )->execute([$pricing['free_hours'], $base_data['pfron_contract_id']]);
        }
    }
    return $created_ids;
}

// ── Zajęcia TI — helpers ──────────────────────────────────────────────────────

const K30_TI_DAYS = [1=>'Poniedziałek',2=>'Wtorek',3=>'Środa',4=>'Czwartek',5=>'Piątek',6=>'Sobota',0=>'Niedziela'];

const K30_TI_SESSION_STATUSES = [
    'planned'   => ['label'=>'Zaplanowana',  'color'=>'#F59E0B', 'bg'=>'#FFFBEB'],
    'held'      => ['label'=>'Odbyła się',   'color'=>'#16A34A', 'bg'=>'#F0FDF4'],
    'cancelled' => ['label'=>'Odwołana',     'color'=>'#DC2626', 'bg'=>'#FEF2F2'],
];

// Role osób mogących odwołać lekcję / udział w lekcji (z podaniem powodu)
const K30_TI_CANCEL_ROLES = [
    'beneficjent' => 'Beneficjent',
    'doradca'     => 'Doradca',
    'admin'       => 'Administrator',
];

const K30_TI_BILLING_STATUSES = [
    'draft'  => ['label'=>'Robocze',     'color'=>'#9CA3AF', 'bg'=>'#F9FAFB'],
    'issued' => ['label'=>'Wystawione',  'color'=>'#2563EB', 'bg'=>'#EFF6FF'],
    'paid'   => ['label'=>'Opłacone',    'color'=>'#16A34A', 'bg'=>'#F0FDF4'],
    'cancelled' => ['label'=>'Anulowane', 'color'=>'#DC2626', 'bg'=>'#FEF2F2'],
];

/**
 * Modele rozliczania zajęć TI (kod → opis).
 *   1 — miesięczny (stała kwota za miesiąc)
 *   2 — godzinowy  (stawka × godziny obecności) — domyślny, zgodny z dotychczasowym
 *   3 — stały      (jednorazowa stała kwota)
 *   9999 — indywidualny — gdy ustawiony override na kursancie (zapisie)
 */
const K30_TI_BILLING_MODELS = [
    1    => ['label' => 'Miesięczny', 'desc' => 'stała kwota za miesiąc'],
    2    => ['label' => 'Godzinowy',  'desc' => 'stawka × godziny obecności'],
    3    => ['label' => 'Stały',      'desc' => 'jednorazowa stała kwota'],
    9999 => ['label' => 'Indywidualny', 'desc' => 'ustalenia indywidualne kursanta'],
];

function k30_ti_billing_model_label(int $code): string {
    return K30_TI_BILLING_MODELS[$code]['label'] ?? ('model ' . $code);
}

/** Domyślny termin płatności (dni od wystawienia rozliczenia), gdy nie ustawiono na kursie/kursancie. */
const K30_TI_PAY_DUE_DAYS_DEFAULT = 7;

/** Płatnik rozliczenia (kod → etykieta). Domyślnie: beneficjent (pełnoletni) lub rodzic (małoletni). */
const K30_TI_PAYERS = [
    'beneficjent' => 'Beneficjent',
    'rodzic'      => 'Inny – Rodzic',
    'pfron'       => 'PFRON',
    'feer'        => 'FEER',
];

/** Czy kursant jest małoletni (wg konta kursanta). */
function k30_ti_client_is_minor(int $client_id): bool {
    $a = db_one("SELECT is_minor FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$client_id]);
    return $a && !empty($a['is_minor']);
}

/** Domyślny płatnik dla klienta: 'rodzic' gdy małoletni, inaczej 'beneficjent'. */
function k30_ti_default_payer(int $client_id): string {
    return k30_ti_client_is_minor($client_id) ? 'rodzic' : 'beneficjent';
}

/** Efektywny kod płatnika rozliczenia (z rekordu lub domyślny wg wieku). */
function k30_ti_billing_payer_type(array $b): string {
    $t = (string)($b['payer_type'] ?? '');
    if ($t !== '' && isset(K30_TI_PAYERS[$t])) return $t;
    return k30_ti_default_payer((int)$b['client_id']);
}

/**
 * Pełna etykieta płatnika z nazwą podmiotu/osoby.
 * beneficjent → imię klienta; rodzic → opiekun (lub jawna nazwa); pfron/feer → nazwa stała.
 */
function k30_ti_billing_payer_label(array $b): string {
    $type = k30_ti_billing_payer_type($b);
    $base = K30_TI_PAYERS[$type] ?? $type;
    $name = trim((string)($b['payer_name'] ?? ''));
    if ($name !== '') return $base . ' — ' . $name;
    if ($type === 'beneficjent') {
        $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [(int)$b['client_id']]);
        return $base . ($cl ? ' — ' . $cl['name'] : '');
    }
    if ($type === 'rodzic') {
        $g = db_one("SELECT guardian_name FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [(int)$b['client_id']]);
        return $base . (!empty($g['guardian_name']) ? ' — ' . $g['guardian_name'] : '');
    }
    if ($type === 'feer') return defined('ORG_NAME') ? (string)ORG_NAME : 'FEER';
    return $base;
}

/** Zapisuje przesłaną fakturę (PDF) do UPLOAD_DIR/ti_invoices/. Zwraca ['name','stored'] lub null. */
function k30_ti_invoice_upload(string $field, string $prefix): ?array {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Błąd przesyłania pliku.');
    if ($f['size'] > 25 * 1024 * 1024) throw new RuntimeException('Plik zbyt duży (maks. 25 MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') throw new RuntimeException('Faktura musi być plikiem PDF.');
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_invoices/';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); @file_put_contents($dir . '.htaccess', "Deny from all\nOptions -Indexes\n"); }
    $stored = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.pdf';
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) throw new RuntimeException('Nie udało się zapisać pliku.');
    return ['name' => mb_substr($f['name'], 0, 200), 'stored' => $stored];
}

/** Wysyła fakturę do przeglądarki (download). Kończy skrypt. */
function k30_ti_invoice_send_file(string $stored, string $orig = ''): void {
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_invoices/' . basename($stored);
    if ($stored === '' || !is_file($path)) { http_response_code(404); exit('Plik nie istnieje.'); }
    $name = preg_replace('/[\r\n"]+/', '', $orig !== '' ? $orig : basename($stored));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** Usuwa plik faktury z dysku (jeśli istnieje). */
function k30_ti_invoice_delete_file(string $stored): void {
    if ($stored === '') return;
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_invoices/' . basename($stored);
    if (is_file($path)) @unlink($path);
}

/**
 * Efektywny termin płatności (liczba dni) dla zapisu kursanta.
 * Pierwszeństwo: indywidualnie na kursancie → domyślnie na kursie → globalna stała (7 dni).
 * 0/null = brak ustawienia (dziedzicz wyżej).
 */
function k30_ti_effective_due_days(array $enr, array $course): int {
    $e = (int)($enr['pay_due_days'] ?? 0);
    if ($e > 0) return $e;
    $c = (int)($course['pay_due_days'] ?? 0);
    if ($c > 0) return $c;
    return K30_TI_PAY_DUE_DAYS_DEFAULT;
}

/**
 * Wyznacza efektywny model rozliczania dla zapisu (override na kursancie ma
 * pierwszeństwo nad modelem kursu). Override → kod 9999 (indywidualny).
 * Zwraca ['model'=>int(1|2|3), 'code'=>int, 'individual'=>bool, 'amount'=>float, 'hourly_rate'=>float, 'label'=>string].
 */
function k30_ti_effective_billing(array $enr, array $course): array {
    $course_acct  = (string)($course['pay_account'] ?? '');
    $course_title = (string)($course['pay_title'] ?? '');
    $emodel = (int)($enr['billing_model'] ?? 0);
    if ($emodel > 0) { // override na kursancie → kod 9999 (indywidualny)
        // Dane do wpłat: indywidualne na kursancie mają pierwszeństwo (gdy ustawione), inaczej domyślne kursu
        $acct  = trim((string)($enr['pay_account'] ?? '')) !== '' ? $enr['pay_account'] : $course_acct;
        $title = trim((string)($enr['pay_title'] ?? ''))   !== '' ? $enr['pay_title']   : $course_title;
        return [
            'model'       => $emodel,
            'code'        => 9999,
            'individual'  => true,
            'amount'      => (float)($enr['billing_amount'] ?? 0),
            'hourly_rate' => (float)($enr['hourly_rate'] ?? 0),
            'label'       => 'Indywidualny',
            'pay_account' => $acct,
            'pay_title'   => $title,
            'due_days'    => k30_ti_effective_due_days($enr, $course),
        ];
    }
    $cmodel = (int)($course['billing_model'] ?? 2) ?: 2;
    // Kod != 9999 → zawsze dane domyślne kursu (override kursanta nieaktywny)
    return [
        'model'       => $cmodel,
        'code'        => $cmodel,
        'individual'  => false,
        'amount'      => (float)($course['billing_amount'] ?? 0),
        'hourly_rate' => (float)($enr['hourly_rate'] ?? 0),
        'label'       => k30_ti_billing_model_label($cmodel),
        'pay_account' => $course_acct,
        'pay_title'   => $course_title,
        'due_days'    => k30_ti_effective_due_days($enr, $course),
    ];
}

/**
 * Efektywne dane do wpłat + kody modeli dla klienta (po aktywnych zapisach).
 * Indywidualne dane (kod 9999 z ustawionym kontem) mają pierwszeństwo, inaczej
 * domyślne kursu. Zwraca ['account'=>str, 'title'=>str, 'codes'=>int[]].
 */
function k30_ti_client_payment(int $client_id): array {
    $enrs = db_all(
        "SELECT e.*, c.billing_model AS course_billing_model, c.billing_amount AS course_billing_amount,
                c.pay_account AS course_pay_account, c.pay_title AS course_pay_title,
                c.pay_due_days AS course_pay_due_days
         FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id
         WHERE e.client_id=? AND e.status='active' ORDER BY e.id",
        [$client_id]
    );
    $codes = []; $indivPick = null; $firstPick = null;
    foreach ($enrs as $e) {
        $eff = k30_ti_effective_billing($e, [
            'billing_model'  => $e['course_billing_model'],
            'billing_amount' => $e['course_billing_amount'],
            'pay_account'    => $e['course_pay_account'],
            'pay_title'      => $e['course_pay_title'],
            'pay_due_days'   => $e['course_pay_due_days'],
        ]);
        $codes[$eff['code']] = true;
        if ($firstPick === null) $firstPick = $eff;
        if ($indivPick === null && $eff['individual'] && trim((string)$eff['pay_account']) !== '') $indivPick = $eff;
    }
    $pick = $indivPick ?? $firstPick;
    return [
        'account'  => $pick['pay_account'] ?? '',
        'title'    => $pick['pay_title'] ?? '',
        'codes'    => array_keys($codes),
        'due_days' => (int)($pick['due_days'] ?? K30_TI_PAY_DUE_DAYS_DEFAULT),
    ];
}

// Kursy — pomija usunięte (status='cancelled')
function k30_ti_courses(bool $active_only = true): array {
    $w = "WHERE c.status!='cancelled'";
    if ($active_only) $w .= ' AND c.is_active=1';
    return db_all(
        "SELECT c.*, u.name AS instructor_name,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS enrolled_count
         FROM k30_ti_courses c
         LEFT JOIN users u ON u.id=c.instructor_id
         $w ORDER BY c.name",
    );
}

function k30_ti_course_get(int $id): ?array {
    return db_one(
        "SELECT c.*, u.name AS instructor_name FROM k30_ti_courses c
         LEFT JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$id]
    ) ?: null;
}

// Zapisy
function k30_ti_enrollments(int $course_id): array {
    return db_all(
        "SELECT e.*, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone
         FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? ORDER BY cl.name", [$course_id]
    );
}

function k30_ti_client_courses(int $client_id): array {
    return db_all(
        "SELECT e.*, c.name AS course_name, c.time_from, c.time_to, c.day_of_week,
                u.name AS instructor_name
         FROM k30_ti_enrollments e
         JOIN k30_ti_courses c ON c.id=e.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE e.client_id=? ORDER BY c.name", [$client_id]
    );
}

/** Wystawione/robocze rozliczenia kursanta (do widoku kursanta i rodzica). */
function k30_ti_client_billing(int $client_id): array {
    return db_all(
        "SELECT * FROM k30_ti_billing
         WHERE client_id=? AND status!='cancelled'
         ORDER BY year DESC, month DESC", [$client_id]
    );
}

/** Ostatnie lekcje kursanta z obecnością (współdzielone: panel kursanta + rodzica). */
function k30_ti_client_lessons(int $client_id, int $limit = 40): array {
    return db_all(
        "SELECT s.*, c.name AS course_name, c.default_meeting_url AS course_meeting_url,
                a.attended, a.ind_notes,
                a.cancelled AS att_cancelled, a.cancel_pending AS att_cancel_pending,
                a.cancel_reason AS att_cancel_reason,
                a.cancelled_by_role AS att_cancelled_by_role,
                r.rating AS my_rating, r.comment AS my_comment
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
         LEFT JOIN k30_ti_lesson_ratings r ON r.session_id=s.id AND r.client_id=?
         WHERE s.course_id IN (
             SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active'
         )
         ORDER BY s.lesson_date DESC, s.time_from DESC
         LIMIT " . max(1, $limit),
        [$client_id, $client_id, $client_id]
    );
}

/**
 * Opcje <option> z gotowymi slotami godzinowymi — czytelny wybór godzin lekcji.
 * Domyślnie 07:00–21:00 co 15 min. Zachowuje wartość spoza zakresu (np. zapisaną wcześniej).
 */
function ti_time_options(string $selected = '', string $from = '07:00', string $to = '21:00', int $step = 15): string {
    $sel   = substr(trim($selected), 0, 5);
    $start = (int)substr($from, 0, 2) * 60 + (int)substr($from, 3, 2);
    $end   = (int)substr($to, 0, 2) * 60 + (int)substr($to, 3, 2);
    $vals  = [];
    for ($m = $start; $m <= $end; $m += $step) {
        $vals[] = str_pad((string)intdiv($m, 60), 2, '0', STR_PAD_LEFT) . ':'
                . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT);
    }
    if ($sel !== '' && !in_array($sel, $vals, true)) $vals[] = $sel; // nietypowa zapisana godzina
    sort($vals);
    $out = '<option value="">— godz. —</option>';
    foreach ($vals as $v) {
        $out .= '<option value="' . $v . '"' . ($v === $sel ? ' selected' : '') . '>' . $v . '</option>';
    }
    return $out;
}

/**
 * Wysyła SMS o zajęciach do aktywnych kursantów grupy, którzy WŁĄCZYLI powiadomienia
 * (notify_sms_lessons=1) i mają numer telefonu. Zwraca liczbę wysłanych. Bezpieczne,
 * gdy SMS wyłączony lub brak odbiorców (zwraca 0).
 */
function ti_lesson_sms_notify(int $courseId, string $message): int {
    require_once __DIR__ . '/sms.php';
    if (!function_exists('sms_is_enabled') || !sms_is_enabled()) return 0;
    $rows = db_all(
        "SELECT cl.phone, a.notify_phone2, a.notify_phone3
         FROM k30_ti_enrollments e
         JOIN k30_ti_student_accounts a ON a.client_id = e.client_id AND a.is_active = 1 AND a.notify_sms_lessons = 1
         JOIN k30_clients cl ON cl.id = e.client_id
         WHERE e.course_id = ? AND e.status = 'active'",
        [$courseId]
    );
    $sent = 0;
    foreach ($rows as $r) {
        foreach (k30_ti_sms_numbers($r) as $num) {
            try { sms_send($num, $message); $sent++; } catch (\Throwable $e) { /* pojedynczy błąd nie blokuje */ }
        }
    }
    return $sent;
}

/**
 * Zwraca listę unikalnych, niepustych numerów SMS dla kursanta z wiersza zawierającego
 * `phone` (główny, z k30_clients) oraz opcjonalne `notify_phone2` / `notify_phone3`.
 */
function k30_ti_sms_numbers(array $row): array {
    $out = [];
    foreach (['phone', 'notify_phone2', 'notify_phone3'] as $k) {
        $p = trim((string)($row[$k] ?? ''));
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
    }
    return $out;
}

// ── Kanał iCal lekcji kursanta (subskrypcja Google/Apple/Outlook) ─────────────

/** Token prywatnego kanału iCal kursanta (utwórz, jeśli brak). */
function k30_ti_calendar_token(int $account_id): string {
    $row = db_one("SELECT calendar_token FROM k30_ti_student_accounts WHERE id=?", [$account_id]);
    $tok = (string)($row['calendar_token'] ?? '');
    if ($tok === '') {
        $tok = bin2hex(random_bytes(20));
        db()->prepare("UPDATE k30_ti_student_accounts SET calendar_token=? WHERE id=?")->execute([$tok, $account_id]);
    }
    return $tok;
}

/** Nowy token kanału iCal — unieważnia poprzedni adres subskrypcji. */
function k30_ti_calendar_token_reset(int $account_id): string {
    $tok = bin2hex(random_bytes(20));
    db()->prepare("UPDATE k30_ti_student_accounts SET calendar_token=? WHERE id=?")->execute([$tok, $account_id]);
    return $tok;
}

/** Buduje treść pliku iCal (VCALENDAR) z lekcjami kursanta. */
function k30_ti_calendar_ics(int $client_id, string $cal_name = 'Lekcje TI'): string {
    $lessons = k30_ti_client_lessons($client_id, 500);
    $host = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?: 'szo';

    // Escapowanie tekstu wg RFC 5545.
    $esc = static fn(string $s): string =>
        str_replace(["\\", "\n", "\r", ",", ";"], ["\\\\", "\\n", "", "\\,", "\\;"], $s);

    // Zawijanie linii do 75 oktetów (na granicy znaków UTF-8).
    $fold = static function (string $line): string {
        if (strlen($line) <= 75) return $line;
        $out = ''; $cur = ''; $len = 0;
        foreach (mb_str_split($line) as $ch) {
            $cl = strlen($ch);
            if ($len + $cl > 73) { $out .= ($out === '' ? '' : "\r\n") . $cur; $cur = ' ' . $ch; $len = 1 + $cl; }
            else { $cur .= $ch; $len += $cl; }
        }
        $out .= ($out === '' ? '' : "\r\n") . $cur;
        return $out;
    };

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//SZO//Karty30 TI//PL',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . $esc($cal_name),
        'X-WR-TIMEZONE:Europe/Warsaw',
    ];

    $now = gmdate('Ymd\THis\Z');

    foreach ($lessons as $l) {
        if (!empty($l['self_prep_remote'])) continue; // praca własna prowadzącego — nie lekcja kursanta
        $date = (string)($l['lesson_date'] ?? '');
        if ($date === '') continue;

        $tf = trim((string)($l['time_from'] ?? ''));
        $tt = trim((string)($l['time_to'] ?? ''));
        $allDay = ($tf === '');

        try {
            if ($allDay) {
                $start = new DateTime($date);
                $end   = (clone $start)->modify('+1 day');
                $dtStart = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
                $dtEnd   = 'DTEND;VALUE=DATE:'   . $end->format('Ymd');
            } else {
                $start = new DateTime($date . ' ' . $tf);
                if ($tt !== '') {
                    $end = new DateTime($date . ' ' . $tt);
                    if ($end <= $start) $end = (clone $start)->modify('+' . max(15, (int)($l['duration_min'] ?? 60)) . ' minutes');
                } else {
                    $end = (clone $start)->modify('+' . max(15, (int)($l['duration_min'] ?? 60)) . ' minutes');
                }
                // Czas lokalny „floating" — kalendarze interpretują w strefie użytkownika.
                $dtStart = 'DTSTART:' . $start->format('Ymd\THis');
                $dtEnd   = 'DTEND:'   . $end->format('Ymd\THis');
            }
        } catch (\Throwable $e) { continue; }

        $course  = trim((string)($l['course_name'] ?? ''));
        $topic   = trim((string)($l['topic'] ?? ''));
        $summary = $course !== '' ? $course : 'Lekcja TI';
        if ($topic !== '') $summary .= ' — ' . $topic;

        $descParts = [];
        if ($topic !== '')                 $descParts[] = 'Temat: ' . $topic;
        if (!empty($l['has_homework']))    $descParts[] = 'Zadanie domowe: tak';
        $desc = implode('\\n', array_map($esc, $descParts));

        $cancelled = ((string)($l['status'] ?? '') === 'cancelled') || ((int)($l['att_cancelled'] ?? 0) === 1);

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:k30ti-' . (int)$l['id'] . '@' . $host;
        $lines[] = 'DTSTAMP:' . $now;
        $lines[] = $dtStart;
        $lines[] = $dtEnd;
        $lines[] = $fold('SUMMARY:' . $esc($summary));
        if ($desc !== '') $lines[] = $fold('DESCRIPTION:' . $desc);
        $lines[] = 'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED');
        if ($cancelled) $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

// Lekcje
function k30_ti_sessions(int $course_id, string $from='', string $to=''): array {
    $where = ['s.course_id=?']; $params = [$course_id];
    if ($from) { $where[] = 's.lesson_date>=?'; $params[] = $from; }
    if ($to)   { $where[] = 's.lesson_date<=?'; $params[] = $to; }
    return db_all(
        "SELECT s.*,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id AND a.attended=1) AS attended_count,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id) AS total_count
         FROM k30_ti_sessions s
         WHERE " . implode(' AND ', $where) . " ORDER BY s.lesson_date, s.time_from",
        $params
    );
}

function k30_ti_session_get(int $id): ?array {
    return db_one(
        "SELECT s.*, c.name AS course_name, c.id AS course_id,
                c.default_meeting_url AS course_meeting_url,
                u.name AS instructor_name
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE s.id=?", [$id]
    ) ?: null;
}

/**
 * Lekcja, do której kursant może teraz dołączyć (aktywny link) — lub null.
 * Kryteria: dziś, status 'planned', udział nieodwołany, istnieje link (lekcji
 * lub stały link grupy) i bieżąca godzina mieści się w oknie
 * [początek − 30 min, koniec]. Gdy lekcja nie ma godzin — aktywna cały dzień.
 * Zwraca wiersz lekcji z dodatkowym kluczem 'eff_link'.
 */
function k30_ti_active_lesson_link(int $client_id): ?array {
    $today = date('Y-m-d');
    $rows = db_all(
        "SELECT s.*, c.name AS course_name, c.default_meeting_url AS course_meeting_url,
                a.cancelled AS att_cancelled
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
         WHERE s.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
           AND s.lesson_date=? AND s.status='planned'
         ORDER BY s.time_from",
        [$client_id, $client_id, $today]
    );
    $now = time();
    foreach ($rows as $r) {
        if ((int)($r['att_cancelled'] ?? 0) === 1) continue;
        $link = trim((string)($r['meeting_url'] ?? '')) !== '' ? $r['meeting_url'] : (string)($r['course_meeting_url'] ?? '');
        if ($link === '') continue;
        $tf = trim((string)($r['time_from'] ?? ''));
        if ($tf === '') { $r['eff_link'] = $link; return $r; } // brak godzin — aktywne cały dzień
        $start = strtotime($today.' '.$tf) - 30*60;
        $tt    = trim((string)($r['time_to'] ?? ''));
        $end   = $tt !== '' ? strtotime($today.' '.$tt) : strtotime($today.' '.$tf) + max(15,(int)$r['duration_min'])*60;
        if ($now >= $start && $now <= $end) { $r['eff_link'] = $link; return $r; }
    }
    return null;
}

// ── Licencje na oprogramowanie ────────────────────────────────────────────────

/** Katalog licencji. $active_only=true → tylko aktywne. Z liczbą przypisań. */
function k30_ti_licenses_all(bool $active_only = false): array {
    $where = $active_only ? "WHERE l.is_active=1" : "";
    return db_all(
        "SELECT l.*,
                (SELECT COUNT(*) FROM k30_ti_client_licenses cl
                  WHERE cl.license_id=l.id AND cl.status='active') AS assigned_count
         FROM k30_ti_licenses l $where ORDER BY l.is_active DESC, l.name"
    );
}

/** Przypisania licencji dla danego kursanta (po client_id), z danymi katalogu. */
function k30_ti_client_licenses(int $client_id, bool $active_only = true): array {
    $where = "WHERE cl.client_id=?" . ($active_only ? " AND cl.status='active'" : "");
    return db_all(
        "SELECT cl.*, l.name AS license_name, l.vendor, l.category, l.vendor_url, l.is_active AS license_active
         FROM k30_ti_client_licenses cl
         JOIN k30_ti_licenses l ON l.id=cl.license_id
         $where
         ORDER BY l.name",
        [$client_id]
    );
}

/** Wszystkie przypisania danej licencji (dla widoku admina) — z nazwą kursanta. */
function k30_ti_license_assignments(int $license_id): array {
    return db_all(
        "SELECT cl.*, c.name AS client_name
         FROM k30_ti_client_licenses cl
         JOIN k30_clients c ON c.id=cl.client_id
         WHERE cl.license_id=? AND cl.status='active'
         ORDER BY c.name",
        [$license_id]
    );
}

// ── Zadania domowe ────────────────────────────────────────────────────────────

/** Katalog dozwolonych rozszerzeń plików zadań. */
function k30_ti_homework_allowed_ext(): array {
    return ['pdf','doc','docx','odt','rtf','txt','xls','xlsx','ods','csv','ppt','pptx','odp',
            'png','jpg','jpeg','gif','webp','bmp','svg','zip','7z','rar','gz',
            'py','java','c','cpp','cs','js','ts','html','css','sql','json','ipynb','md'];
}

/**
 * Zapisuje przesłany plik zadania do UPLOAD_DIR/ti_homework/.
 * @return array|null ['name'=>oryg, 'stored'=>nazwa-na-dysku] lub null gdy nie przesłano pliku.
 * @throws RuntimeException przy błędzie/niedozwolonym pliku.
 */
function k30_ti_homework_upload(string $field, string $prefix): ?array {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Błąd przesyłania pliku.');
    if ($f['size'] > 25 * 1024 * 1024) throw new RuntimeException('Plik zbyt duży (maks. 25 MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, k30_ti_homework_allowed_ext(), true)) throw new RuntimeException('Niedozwolony typ pliku: .' . $ext);
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_homework/';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); @file_put_contents($dir . '.htaccess', "Deny from all\nOptions -Indexes\n"); }
    $stored = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) throw new RuntimeException('Nie udało się zapisać pliku.');
    return ['name' => mb_substr($f['name'], 0, 200), 'stored' => $stored];
}

/** Wysyła plik zadania do przeglądarki (download). Kończy skrypt. */
function k30_ti_homework_send_file(string $stored, string $orig = ''): void {
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_homework/' . basename($stored);
    if ($stored === '' || !is_file($path)) { http_response_code(404); exit('Plik nie istnieje.'); }
    $name = preg_replace('/[\r\n"]+/', '', $orig !== '' ? $orig : basename($stored));
    header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** Usuwa plik zadania z dysku (jeśli istnieje). */
function k30_ti_homework_delete_file(string $stored): void {
    if ($stored === '') return;
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_homework/' . basename($stored);
    if (is_file($path)) @unlink($path);
}

/** Lista zadań (dla prowadzącego); $course_id=0 → wszystkie. Z licznikiem oddań. */
function k30_ti_homework_list(int $course_id = 0): array {
    $where = $course_id ? "WHERE h.course_id=?" : "";
    $params = $course_id ? [$course_id] : [];
    return db_all(
        "SELECT h.*, c.name AS course_name,
                (SELECT COUNT(*) FROM k30_ti_homework_submissions s WHERE s.homework_id=h.id) AS sub_count,
                (SELECT COUNT(*) FROM k30_ti_homework_submissions s WHERE s.homework_id=h.id AND s.status='graded') AS graded_count
         FROM k30_ti_homework h
         JOIN k30_ti_courses c ON c.id=h.course_id
         $where
         ORDER BY h.is_active DESC, COALESCE(h.due_at,'9999') DESC, h.id DESC",
        $params
    );
}

function k30_ti_homework_get(int $id): ?array {
    return db_one(
        "SELECT h.*, c.name AS course_name FROM k30_ti_homework h
         JOIN k30_ti_courses c ON c.id=h.course_id WHERE h.id=?", [$id]
    ) ?: null;
}

/** Oddania danego zadania — z nazwą kursanta i informacją czy zapisany w kursie. */
function k30_ti_homework_submissions(int $homework_id): array {
    return db_all(
        "SELECT s.*, cl.name AS client_name
         FROM k30_ti_homework_submissions s
         JOIN k30_clients cl ON cl.id=s.client_id
         WHERE s.homework_id=? ORDER BY s.submitted_at DESC",
        [$homework_id]
    );
}

/** Zadania widoczne dla kursanta (jego aktywne kursy) wraz z jego oddaniem (jeśli jest). */
function k30_ti_homework_for_client(int $client_id): array {
    return db_all(
        "SELECT h.*, c.name AS course_name,
                les.lesson_date AS session_date, les.topic AS session_topic,
                s.id AS sub_id, s.body AS sub_body, s.file_name AS sub_file_name, s.file_path AS sub_file_path,
                s.status AS sub_status, s.grade AS sub_grade, s.feedback AS sub_feedback, s.submitted_at AS sub_at
         FROM k30_ti_homework h
         JOIN k30_ti_courses c ON c.id=h.course_id
         LEFT JOIN k30_ti_sessions les ON les.id=h.session_id
         LEFT JOIN k30_ti_homework_submissions s ON s.homework_id=h.id AND s.client_id=?
         WHERE h.is_active=1
           AND h.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
         ORDER BY COALESCE(h.due_at,'9999') ASC, h.id DESC",
        [$client_id, $client_id]
    );
}

// ── Materiały dydaktyczne / eLearning ─────────────────────────────────────────

/** Katalog typów materiału: slug => ['label','icon' (Bootstrap Icons)]. */
function k30_ti_material_types(): array {
    return [
        'zadanie'      => ['label' => 'Zadanie',      'icon' => 'pencil-square'],
        'link'         => ['label' => 'Link',         'icon' => 'link-45deg'],
        'plik'         => ['label' => 'Plik',         'icon' => 'file-earmark-arrow-down'],
        'dokumentacja' => ['label' => 'Dokumentacja', 'icon' => 'journal-text'],
        'wideo'        => ['label' => 'Wideo',         'icon' => 'play-btn'],
        'prezentacja'  => ['label' => 'Prezentacja',  'icon' => 'easel'],
        'inne'         => ['label' => 'Inne',          'icon' => 'collection'],
    ];
}

/** Etykieta typu materiału (z fallbackiem). */
function k30_ti_material_type_label(string $type): string {
    $t = k30_ti_material_types();
    return $t[$type]['label'] ?? ucfirst($type);
}
/** Ikona typu materiału (Bootstrap Icons). */
function k30_ti_material_type_icon(string $type): string {
    $t = k30_ti_material_types();
    return $t[$type]['icon'] ?? 'collection';
}

/** Lista materiałów (dla prowadzącego); $course_id=0 → wszystkie. Z kursem i lekcją. */
function k30_ti_materials_list(int $course_id = 0): array {
    $where  = $course_id ? "WHERE m.course_id=?" : "";
    $params = $course_id ? [$course_id] : [];
    return db_all(
        "SELECT m.*, c.name AS course_name,
                s.lesson_date AS session_date, s.topic AS session_topic
         FROM k30_ti_materials m
         JOIN k30_ti_courses c   ON c.id=m.course_id
         LEFT JOIN k30_ti_sessions s ON s.id=m.session_id
         $where
         ORDER BY m.is_active DESC, c.name, m.id DESC",
        $params
    );
}

/** Pojedynczy materiał z nazwą kursu i lekcją. */
function k30_ti_material_get(int $id): ?array {
    return db_one(
        "SELECT m.*, c.name AS course_name,
                s.lesson_date AS session_date, s.topic AS session_topic
         FROM k30_ti_materials m
         JOIN k30_ti_courses c   ON c.id=m.course_id
         LEFT JOIN k30_ti_sessions s ON s.id=m.session_id
         WHERE m.id=?", [$id]
    ) ?: null;
}

/** Materiały widoczne dla kursanta (jego aktywne kursy). */
function k30_ti_materials_for_client(int $client_id): array {
    return db_all(
        "SELECT m.*, c.name AS course_name,
                s.lesson_date AS session_date, s.topic AS session_topic
         FROM k30_ti_materials m
         JOIN k30_ti_courses c   ON c.id=m.course_id
         LEFT JOIN k30_ti_sessions s ON s.id=m.session_id
         WHERE m.is_active=1
           AND m.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
         ORDER BY COALESCE(s.lesson_date,'') DESC, m.id DESC",
        [$client_id]
    );
}

// ── Oceny / e-dziennik ────────────────────────────────────────────────────────

/** Katalog kategorii ocen: slug => ['label','weight' (domyślna waga),'short']. */
function k30_ti_grade_categories(): array {
    return [
        'sprawdzian' => ['label' => 'Sprawdzian',     'weight' => 3, 'short' => 'Spr'],
        'kartkowka'  => ['label' => 'Kartkówka',      'weight' => 2, 'short' => 'Kar'],
        'odpowiedz'  => ['label' => 'Odpowiedź',      'weight' => 2, 'short' => 'Odp'],
        'projekt'    => ['label' => 'Projekt',        'weight' => 3, 'short' => 'Prj'],
        'zadanie'    => ['label' => 'Zadanie domowe', 'weight' => 1, 'short' => 'ZD'],
        'aktywnosc'  => ['label' => 'Aktywność',      'weight' => 1, 'short' => 'Akt'],
        'inne'       => ['label' => 'Inne',           'weight' => 1, 'short' => 'In'],
    ];
}
function k30_ti_grade_category_label(string $c): string {
    $cats = k30_ti_grade_categories(); return $cats[$c]['label'] ?? ucfirst($c);
}

/**
 * Zamienia ocenę tekstową na wartość liczbową do średniej ważonej.
 * '4+' → 4.5, '3-' → 2.75, '5' → 5; 'np','bz','nb','0','+','-' → null (nie liczona).
 */
function k30_ti_grade_parse_num(string $text): ?float {
    $t = trim($text);
    if ($t === '') return null;
    if (preg_match('/^([1-6])\s*([+\-])?$/u', $t, $m)) {
        $v = (float)$m[1];
        if (($m[2] ?? '') === '+') $v += 0.5;
        elseif (($m[2] ?? '') === '-') $v -= 0.25;
        return $v;
    }
    // czysta liczba (np. wartość ułamkowa wpisana ręcznie)
    if (is_numeric($t)) { $v = (float)$t; return ($v >= 1 && $v <= 6) ? $v : null; }
    return null; // np / bz / nb / nieobecność itp.
}

/** Kolor (hex) tła oznaczenia oceny wg wartości liczbowej — styl e-dziennika. */
function k30_ti_grade_color(?float $num): array {
    if ($num === null) return ['#6c757d', '#fff'];          // szary — nie liczona
    $f = (int)floor($num + 0.001);
    return [
        1 => ['#dc3545', '#fff'],  // czerwony
        2 => ['#fd7e14', '#fff'],  // pomarańczowy
        3 => ['#ffc107', '#212529'],// żółty (ciemny tekst)
        4 => ['#0dcaf0', '#212529'],// błękit
        5 => ['#198754', '#fff'],  // zielony
        6 => ['#6f42c1', '#fff'],  // fiolet
    ][$f] ?? ['#6c757d', '#fff'];
}

/** HTML oznaczenia (badge) oceny z kolorem i tooltipem (kategoria/opis/waga). */
function k30_ti_grade_badge(array $g): string {
    [$bg, $fg] = k30_ti_grade_color(isset($g['value_num']) ? (float)$g['value_num'] : null);
    if (!isset($g['value_num']) || $g['value_num'] === null) [$bg, $fg] = ['#6c757d', '#fff'];
    $cat   = k30_ti_grade_category_label((string)($g['category'] ?? 'inne'));
    $w     = rtrim(rtrim((string)($g['weight'] ?? 1), '0'), '.');
    $title = $cat . ' · waga ' . ($w === '' ? '1' : $w)
           . (($g['description'] ?? '') !== '' ? ' · ' . $g['description'] : '')
           . (($g['graded_at'] ?? '') !== '' ? ' · ' . substr((string)$g['graded_at'], 0, 10) : '');
    return '<span class="badge" style="background:' . $bg . ';color:' . $fg
         . ';font-size:.85rem;min-width:1.6rem" title="' . h($title) . '">' . h((string)$g['value_text']) . '</span>';
}

/** Średnia ważona z tablicy ocen (pomija value_num = null). Zwraca null gdy brak. */
function k30_ti_grades_average(array $grades): ?float {
    $sum = 0.0; $w = 0.0;
    foreach ($grades as $g) {
        if (!isset($g['value_num']) || $g['value_num'] === null) continue;
        $gw   = (float)($g['weight'] ?? 1);
        $sum += (float)$g['value_num'] * $gw;
        $w   += $gw;
    }
    return $w > 0 ? round($sum / $w, 2) : null;
}

/** Wszystkie oceny w kursie (e-dziennik) — z nazwą kursanta, lekcją i autorem. */
function k30_ti_course_grades(int $course_id): array {
    return db_all(
        "SELECT g.*, cl.name AS client_name, s.lesson_date AS session_date, s.topic AS session_topic,
                u.name AS graded_by_name
         FROM k30_ti_grades g
         JOIN k30_clients cl ON cl.id=g.client_id
         LEFT JOIN k30_ti_sessions s ON s.id=g.session_id
         LEFT JOIN users u ON u.id=g.graded_by
         WHERE g.course_id=?
         ORDER BY g.graded_at DESC, g.id DESC", [$course_id]
    );
}

/** Pojedyncza ocena z nazwą kursu i kursanta. */
function k30_ti_grade_get(int $id): ?array {
    return db_one(
        "SELECT g.*, c.name AS course_name, cl.name AS client_name
         FROM k30_ti_grades g
         JOIN k30_ti_courses c ON c.id=g.course_id
         JOIN k30_clients cl   ON cl.id=g.client_id
         WHERE g.id=?", [$id]
    ) ?: null;
}

/** Oceny kursanta z jego aktywnych kursów — z nazwą kursu, lekcją i autorem (widok kursanta). */
function k30_ti_client_grades(int $client_id): array {
    return db_all(
        "SELECT g.*, c.name AS course_name, s.lesson_date AS session_date, s.topic AS session_topic,
                u.name AS graded_by_name
         FROM k30_ti_grades g
         JOIN k30_ti_courses c ON c.id=g.course_id
         LEFT JOIN k30_ti_sessions s ON s.id=g.session_id
         LEFT JOIN users u ON u.id=g.graded_by
         WHERE g.client_id=?
           AND g.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
         ORDER BY c.name, g.graded_at DESC, g.id DESC", [$client_id, $client_id]
    );
}

// ── Dostępność (daty otwarcia/zamknięcia) + powiadomienia o zmianach ───────────

/**
 * Status dostępności materiału/zadania wg dat otwarcia i zamknięcia.
 * Zwraca ['state'=>'upcoming'|'open'|'closed', 'label'=>string, 'open_at'=>?, 'close_at'=>?].
 */
function k30_ti_avail_status(?string $open_at, ?string $close_at, ?string $now = null): array {
    $now = $now ?: date('Y-m-d H:i:s');
    $open  = ($open_at  ?? '') !== '' ? $open_at  : null;
    $close = ($close_at ?? '') !== '' ? $close_at : null;
    if ($open && $now < $open)  return ['state'=>'upcoming', 'label'=>'dostępne od '  . substr($open,0,16),  'open_at'=>$open, 'close_at'=>$close];
    if ($close && $now > $close) return ['state'=>'closed',   'label'=>'zamknięte '    . substr($close,0,16), 'open_at'=>$open, 'close_at'=>$close];
    return ['state'=>'open', 'label'=>$close ? 'dostępne do ' . substr($close,0,16) : 'dostępne', 'open_at'=>$open, 'close_at'=>$close];
}
/** Czy zasób jest teraz dostępny dla kursanta (otwarte okno). */
function k30_ti_is_available(?string $open_at, ?string $close_at, ?string $now = null): bool {
    return k30_ti_avail_status($open_at, $close_at, $now)['state'] === 'open';
}

/**
 * Powiadamia kursantów aktywnie zapisanych w kursie o zmianie w dydaktyce/eLearningu
 * (nowy/zmieniony materiał lub zadanie), zgodnie z ich ustawieniami (e-mail / SMS).
 */
function k30_ti_notify_dydaktyka(int $course_id, string $subject, string $bodyHtml, string $url, string $smsText): void {
    $rows = db_all(
        "SELECT a.id, a.notify_email_dydaktyka, a.notify_sms_dydaktyka, a.notify_phone2, a.notify_phone3,
                cl.name, cl.email, cl.phone
         FROM k30_ti_enrollments e
         JOIN k30_ti_student_accounts a ON a.client_id=e.client_id AND a.is_active=1
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active'",
        [$course_id]
    );
    if (!$rows) return;
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    foreach ($rows as $acc) {
        // E-mail
        if (!empty($acc['notify_email_dydaktyka'])) {
            $email = trim((string)($acc['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
                if (function_exists('mail_queue_add')) {
                    $name = htmlspecialchars((string)($acc['name'] ?? ''), ENT_QUOTES);
                    $html = "<p>Cześć {$name},</p><p>{$bodyHtml}</p>"
                          . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Otwórz panel kursanta</a>.</p>"
                          . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}. Powiadomienia możesz wyłączyć w Ustawieniach.</p>";
                    try { mail_queue_add($email, (string)($acc['name'] ?? ''), "{$org}: " . $subject, $html, '', 'ti_dydaktyka', (int)$acc['id'], '', true); } catch (\Throwable $e) {}
                }
            }
        }
        // SMS
        if (!empty($acc['notify_sms_dydaktyka'])) {
            if (!function_exists('sms_send')) @require_once __DIR__ . '/sms.php';
            if (function_exists('sms_send') && function_exists('sms_is_enabled') && sms_is_enabled()) {
                $nums = function_exists('k30_ti_sms_numbers') ? k30_ti_sms_numbers($acc) : array_filter([trim((string)($acc['phone'] ?? ''))]);
                foreach ($nums as $num) { try { sms_send($num, $smsText); } catch (\Throwable $e) {} }
            }
        }
    }
}

/**
 * Synchronizuje ocenę z dziennika z oceną zadania domowego (oddanie).
 * Tworzy/aktualizuje wpis w k30_ti_grades powiązany przez hw_submission_id.
 * $gradeText pusty → usuwa powiązaną ocenę z dziennika.
 */
function k30_ti_grade_sync_from_homework(int $submission_id, ?int $byUserId = null): void {
    $s = db_one(
        "SELECT s.*, h.course_id, h.session_id, h.title
         FROM k30_ti_homework_submissions s
         JOIN k30_ti_homework h ON h.id=s.homework_id
         WHERE s.id=?", [$submission_id]
    );
    if (!$s) return;
    $existing = db_one("SELECT id FROM k30_ti_grades WHERE hw_submission_id=?", [$submission_id]);
    $grade = trim((string)($s['grade'] ?? ''));
    if ($grade === '') { // brak oceny → usuń ewentualny wpis
        if ($existing) db()->prepare("DELETE FROM k30_ti_grades WHERE id=?")->execute([(int)$existing['id']]);
        return;
    }
    $vnum = k30_ti_grade_parse_num($grade);
    $desc = 'Zadanie domowe: ' . mb_substr((string)$s['title'], 0, 120);
    if ($existing) {
        db()->prepare(
            "UPDATE k30_ti_grades SET value_text=?, value_num=?, description=?, session_id=?, graded_at=datetime('now') WHERE id=?"
        )->execute([$grade, $vnum, $desc, $s['session_id'] ?: null, (int)$existing['id']]);
    } else {
        db_insert('k30_ti_grades', [
            'course_id'=>(int)$s['course_id'], 'client_id'=>(int)$s['client_id'], 'session_id'=>$s['session_id'] ?: null,
            'category'=>'zadanie', 'value_text'=>$grade, 'value_num'=>$vnum, 'weight'=>1,
            'description'=>$desc, 'graded_by'=>$byUserId, 'hw_submission_id'=>$submission_id,
        ]);
    }
}

// ── Konta dydaktyków (prowadzących) ───────────────────────────────────────────

/** Konto dydaktyka po loginie (z danymi użytkownika). */
function k30_ti_instructor_account_by_login(string $login): ?array {
    return db_one(
        "SELECT a.*, u.name AS user_name, u.email AS user_email
         FROM k30_ti_instructor_accounts a JOIN users u ON u.id=a.user_id
         WHERE a.login=?", [$login]
    ) ?: null;
}
/** Konto dydaktyka po id konta. */
function k30_ti_instructor_account_get(int $id): ?array {
    return db_one(
        "SELECT a.*, u.name AS user_name, u.email AS user_email
         FROM k30_ti_instructor_accounts a JOIN users u ON u.id=a.user_id
         WHERE a.id=?", [$id]
    ) ?: null;
}
/** Konto dydaktyka powiązane z użytkownikiem (jeśli istnieje). */
function k30_ti_instructor_account_for_user(int $user_id): ?array {
    return db_one("SELECT * FROM k30_ti_instructor_accounts WHERE user_id=?", [$user_id]) ?: null;
}

/** Kursy prowadzone przez danego użytkownika (dydaktyka). */
function k30_ti_instructor_courses(int $user_id, bool $active_only = false): array {
    $w = "c.instructor_id=?" . ($active_only ? " AND c.status='active'" : "");
    return db_all(
        "SELECT c.*,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS enrolled_count
         FROM k30_ti_courses c WHERE $w ORDER BY c.status='active' DESC, c.name", [$user_id]
    );
}
/** Czy dany kurs prowadzi ten dydaktyk. */
function k30_ti_instructor_owns_course(int $user_id, int $course_id): bool {
    return (bool) db_one("SELECT 1 FROM k30_ti_courses WHERE id=? AND instructor_id=?", [$course_id, $user_id]);
}
/** Czy dana lekcja należy do kursu prowadzonego przez tego dydaktyka. */
function k30_ti_instructor_owns_session(int $user_id, int $session_id): bool {
    return (bool) db_one(
        "SELECT 1 FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.id=? AND c.instructor_id=?", [$session_id, $user_id]
    );
}

/** Lista dydaktyków (użytkownicy będący prowadzącymi kursów) + status konta panelu. */
function k30_ti_instructor_list(): array {
    return db_all(
        "SELECT u.id AS user_id, u.name AS user_name, u.email AS user_email,
                a.id AS account_id, a.login, a.is_active, a.last_login,
                (SELECT COUNT(*) FROM k30_ti_courses c WHERE c.instructor_id=u.id) AS course_count
         FROM users u
         JOIN k30_ti_courses c2 ON c2.instructor_id=u.id
         LEFT JOIN k30_ti_instructor_accounts a ON a.user_id=u.id
         GROUP BY u.id
         ORDER BY u.name"
    );
}

/** Oceny lekcji (1–5) od kursantów — z nazwą kursanta. Dla widoku prowadzącego. */
function k30_ti_session_ratings(int $session_id): array {
    return db_all(
        "SELECT r.*, cl.name AS client_name
         FROM k30_ti_lesson_ratings r
         JOIN k30_clients cl ON cl.id=r.client_id
         WHERE r.session_id=? ORDER BY r.updated_at DESC",
        [$session_id]
    );
}

/**
 * Zapisuje/aktualizuje ocenę lekcji (1–5) przez kursanta. Upsert po (session_id, client_id).
 * Waliduje, że lekcja należy do aktywnego zapisu kursanta i jest odbyta (held).
 * Zwraca true gdy zapisano.
 */
function k30_ti_rate_lesson(int $session_id, int $client_id, int $rating, string $comment = ''): bool {
    $rating = max(1, min(5, $rating));
    $ok = db_one(
        "SELECT s.id FROM k30_ti_sessions s
         JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=? AND e.status='active'
         WHERE s.id=? AND s.status='held'",
        [$client_id, $session_id]
    );
    if (!$ok) return false;
    $comment = mb_substr(trim($comment), 0, 1000);
    try {
        db()->prepare(
            "INSERT INTO k30_ti_lesson_ratings (session_id, client_id, rating, comment)
             VALUES (?,?,?,?)
             ON CONFLICT(session_id, client_id)
             DO UPDATE SET rating=excluded.rating, comment=excluded.comment, updated_at=datetime('now')"
        )->execute([$session_id, $client_id, $rating, $comment]);
        return true;
    } catch (\Throwable $e) { return false; }
}

// Obecność — pobierz lub utwórz domyślną listę dla lekcji
function k30_ti_session_attendance(int $session_id): array {
    // Pobierz zapisanych klientów kursu
    $s = db_one("SELECT course_id FROM k30_ti_sessions WHERE id=?", [$session_id]);
    if (!$s) return [];
    $enrolled = db_all(
        "SELECT e.client_id, cl.name AS client_name, cl.email AS client_email, e.hourly_rate
         FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active' ORDER BY cl.name",
        [(int)$s['course_id']]
    );
    // Pobierz istniejącą obecność
    $att = db_all("SELECT * FROM k30_ti_attendance WHERE session_id=?", [$session_id]);
    $att_map = [];
    foreach ($att as $a) $att_map[(int)$a['client_id']] = $a;
    // Uzupełnij
    foreach ($enrolled as &$e) {
        $cid = (int)$e['client_id'];
        $e['attended']          = isset($att_map[$cid]) ? (int)$att_map[$cid]['attended'] : 0;
        $e['att_id']            = $att_map[$cid]['id'] ?? null;
        $e['att_notes']         = $att_map[$cid]['notes'] ?? '';
        $e['cancelled']         = isset($att_map[$cid]) ? (int)($att_map[$cid]['cancelled'] ?? 0) : 0;
        $e['cancel_reason']     = $att_map[$cid]['cancel_reason'] ?? '';
        $e['cancelled_by_role'] = $att_map[$cid]['cancelled_by_role'] ?? '';
        $e['cancelled_by']      = $att_map[$cid]['cancelled_by'] ?? '';
    }
    return $enrolled;
}

// Zapis obecności (bulk — tablica [client_id => attended])
function k30_ti_save_attendance(int $session_id, array $attended_ids): void {
    $s = db_one("SELECT course_id FROM k30_ti_sessions WHERE id=?", [$session_id]);
    if (!$s) return;
    $enrolled = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [(int)$s['course_id']]);
    // Uczestnicy z odwołanym udziałem — nie liczeni do ceny, więc nie oznaczamy ich jako obecnych
    $cancelled_ids = array_map('intval', array_column(
        db_all("SELECT client_id FROM k30_ti_attendance WHERE session_id=? AND COALESCE(cancelled,0)=1", [$session_id]),
        'client_id'
    ));
    foreach ($enrolled as $e) {
        $cid      = (int)$e['client_id'];
        $attended = (in_array($cid, $attended_ids) && !in_array($cid, $cancelled_ids)) ? 1 : 0;
        try {
            db()->prepare(
                "INSERT INTO k30_ti_attendance (session_id, client_id, attended) VALUES (?,?,?)
                 ON CONFLICT(session_id, client_id) DO UPDATE SET attended=excluded.attended"
            )->execute([$session_id, $cid, $attended]);
        } catch (\Throwable $ex) {
            $ex2 = db_one("SELECT id FROM k30_ti_attendance WHERE session_id=? AND client_id=?", [$session_id, $cid]);
            if ($ex2) db()->prepare("UPDATE k30_ti_attendance SET attended=? WHERE session_id=? AND client_id=?")->execute([$attended, $session_id, $cid]);
            else      db_insert('k30_ti_attendance', ['session_id'=>$session_id,'client_id'=>$cid,'attended'=>$attended]);
        }
    }
}

/**
 * Odwołuje udział pojedynczego uczestnika w lekcji (Beneficjent / Doradca / admin).
 * Tworzy lub aktualizuje wiersz obecności: cancelled=1, attended=0.
 * Taki udział nie jest liczony do ceny w rozliczeniu miesięcznym.
 */
function k30_ti_cancel_attendance(int $session_id, int $client_id, string $reason, string $role, string $by_label): void {
    $role = array_key_exists($role, K30_TI_CANCEL_ROLES) ? $role : 'doradca';
    $ex = db_one("SELECT id FROM k30_ti_attendance WHERE session_id=? AND client_id=?", [$session_id, $client_id]);
    if ($ex) {
        db()->prepare(
            "UPDATE k30_ti_attendance
             SET attended=0, cancelled=1, cancel_pending=0, cancel_reason=?, cancelled_by_role=?, cancelled_by=?, cancelled_at=datetime('now')
             WHERE id=?"
        )->execute([$reason, $role, $by_label, (int)$ex['id']]);
    } else {
        db_insert('k30_ti_attendance', [
            'session_id'        => $session_id,
            'client_id'         => $client_id,
            'attended'          => 0,
            'cancelled'         => 1,
            'cancel_pending'    => 0,
            'cancel_reason'     => $reason,
            'cancelled_by_role' => $role,
            'cancelled_by'      => $by_label,
            'cancelled_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}

/** Przywraca udział uczestnika (cofa odwołanie). */
function k30_ti_uncancel_attendance(int $session_id, int $client_id): void {
    db()->prepare(
        "UPDATE k30_ti_attendance
         SET cancelled=0, cancel_pending=0, cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL
         WHERE session_id=? AND client_id=?"
    )->execute([$session_id, $client_id]);
}

/**
 * Prośba o odwołanie udziału (np. od beneficjenta) — NIE odwołuje od razu,
 * tylko ustawia stan „czeka na potwierdzenie" i powiadamia prowadzącego mailem.
 * Potwierdzenie (k30_ti_confirm_cancel_attendance) zmienia to w faktyczne odwołanie.
 */
function k30_ti_request_cancel_attendance(int $session_id, int $client_id, string $reason, string $role, string $by_label): void {
    $role = array_key_exists($role, K30_TI_CANCEL_ROLES) ? $role : 'beneficjent';
    $ex = db_one("SELECT id FROM k30_ti_attendance WHERE session_id=? AND client_id=?", [$session_id, $client_id]);
    if ($ex) {
        db()->prepare(
            "UPDATE k30_ti_attendance
             SET cancel_pending=1, cancelled=0, cancel_reason=?, cancelled_by_role=?, cancelled_by=?, cancelled_at=datetime('now')
             WHERE id=?"
        )->execute([$reason, $role, $by_label, (int)$ex['id']]);
    } else {
        db_insert('k30_ti_attendance', [
            'session_id' => $session_id, 'client_id' => $client_id, 'attended' => 0,
            'cancel_pending' => 1, 'cancelled' => 0, 'cancel_reason' => $reason,
            'cancelled_by_role' => $role, 'cancelled_by' => $by_label, 'cancelled_at' => date('Y-m-d H:i:s'),
        ]);
    }
    k30_ti_notify_instructor_cancel_request($session_id, $client_id, $reason);
}

/** Potwierdzenie prośby o odwołanie przez prowadzącego — udział staje się odwołany. */
function k30_ti_confirm_cancel_attendance(int $session_id, int $client_id): void {
    db()->prepare(
        "UPDATE k30_ti_attendance SET cancelled=1, cancel_pending=0, attended=0 WHERE session_id=? AND client_id=?"
    )->execute([$session_id, $client_id]);
}

/** E-mail do prowadzącego o prośbie kursanta o odwołanie udziału w lekcji. */
function k30_ti_notify_instructor_cancel_request(int $session_id, int $client_id, string $reason): void {
    $row = db_one(
        "SELECT s.lesson_date, s.time_from, c.name AS course_name, c.instructor_id,
                COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name,
                u.email AS instructor_email, cl.name AS client_name
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         JOIN k30_clients cl ON cl.id=?
         WHERE s.id=?",
        [$client_id, $session_id]
    );
    if (!$row) return;
    $email = trim((string)($row['instructor_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $when = date('d.m.Y', strtotime($row['lesson_date'])) . ($row['time_from'] ? ' o ' . substr($row['time_from'], 0, 5) : '');
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/dydaktyk/index.php';
    $html = "<p>Dzień dobry,</p>"
          . "<p><strong>" . htmlspecialchars((string)$row['client_name'], ENT_QUOTES) . "</strong> prosi o odwołanie udziału w lekcji "
          . "<strong>" . htmlspecialchars((string)$row['course_name'], ENT_QUOTES) . "</strong> (" . htmlspecialchars($when, ENT_QUOTES) . ").</p>"
          . ($reason !== '' ? "<p>Powód: " . htmlspecialchars($reason, ENT_QUOTES) . "</p>" : "")
          . "<p>Prośba czeka na Twoje potwierdzenie. Otwórz panel dydaktyka, aby potwierdzić lub odrzucić odwołanie.</p>"
          . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Panel dydaktyka</a></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";
    try { mail_queue_add($email, (string)($row['instructor_name'] ?? ''), "{$org}: prośba o odwołanie lekcji — {$when}", $html, '', 'ti_cancel_req', $session_id, '', false); }
    catch (\Throwable $e) {}
}

/**
 * Powiadom kursanta (i opiekuna małoletniego) o decyzji prowadzącego ws. odwołania:
 * $confirmed=true → potwierdzone, false → odrzucone (udział przywrócony).
 */
function k30_ti_notify_student_cancel_decision(int $session_id, int $client_id, bool $confirmed): void {
    $row = db_one(
        "SELECT s.lesson_date, s.time_from, c.name AS course_name, cl.name AS client_name, cl.email,
                a.is_minor, a.guardian_email
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         JOIN k30_clients cl ON cl.id=?
         LEFT JOIN k30_ti_student_accounts a ON a.client_id=cl.id AND a.is_active=1
         WHERE s.id=? LIMIT 1",
        [$client_id, $session_id]
    );
    if (!$row) return;
    $emails = [];
    $primary = trim((string)($row['email'] ?? ''));
    if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = (string)$row['client_name'];
    $gemail = trim((string)($row['guardian_email'] ?? ''));
    if (!empty($row['is_minor']) && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) $emails[$gemail] = (string)$row['client_name'];
    if (!$emails) return;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
    $when = date('d.m.Y', strtotime($row['lesson_date'])) . ($row['time_from'] ? ' o ' . substr($row['time_from'], 0, 5) : '');
    $crs  = htmlspecialchars((string)$row['course_name'], ENT_QUOTES);
    if ($confirmed) {
        $subject = "potwierdzono odwołanie udziału — {$when}";
        $lead = "Twoja prośba o odwołanie udziału w lekcji <strong>{$crs}</strong> ({$when}) została <strong>potwierdzona</strong>. Udział nie zostanie policzony do ceny.";
    } else {
        $subject = "odrzucono prośbę o odwołanie — {$when}";
        $lead = "Twoja prośba o odwołanie udziału w lekcji <strong>{$crs}</strong> ({$when}) została <strong>odrzucona</strong> — udział pozostaje aktualny.";
    }
    $html = "<p>Dzień dobry,</p><p>{$lead}</p>"
          . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Otwórz panel kursanta</a></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";
    foreach ($emails as $addr => $nm) {
        try { mail_queue_add($addr, $nm, "{$org}: {$subject}", $html, '', 'ti_cancel_decision', $session_id, '', false); }
        catch (\Throwable $e) {}
    }
}

/** Powiadom kursanta (i opiekuna małoletniego) e-mailem o nowej/zmienionej ocenie. */
function k30_ti_notify_grade(int $course_id, int $client_id, string $valueText, string $categoryLabel, string $description): void {
    $row = db_one(
        "SELECT c.name AS course_name, cl.name AS client_name, cl.email,
                a.is_minor, a.guardian_email, a.notify_email_dydaktyka
         FROM k30_ti_courses c
         JOIN k30_clients cl ON cl.id=?
         LEFT JOIN k30_ti_student_accounts a ON a.client_id=cl.id AND a.is_active=1
         WHERE c.id=? LIMIT 1",
        [$client_id, $course_id]
    );
    if (!$row) return;
    // Szanuj wyłączone powiadomienia dydaktyczne (gdy konto istnieje i pref=0)
    if ($row['notify_email_dydaktyka'] !== null && (int)$row['notify_email_dydaktyka'] === 0) return;
    $emails = [];
    $primary = trim((string)($row['email'] ?? ''));
    if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = (string)$row['client_name'];
    $gemail = trim((string)($row['guardian_email'] ?? ''));
    if (!empty($row['is_minor']) && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) $emails[$gemail] = (string)$row['client_name'];
    if (!$emails) return;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;
    $org = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=oceny';
    $crs = htmlspecialchars((string)$row['course_name'], ENT_QUOTES);
    $val = htmlspecialchars($valueText, ENT_QUOTES);
    $catTxt = $categoryLabel !== '' ? ' (' . htmlspecialchars($categoryLabel, ENT_QUOTES) . ')' : '';
    $html = "<p>Dzień dobry,</p>"
          . "<p>W kursie <strong>{$crs}</strong> wystawiono ocenę: <strong>{$val}</strong>{$catTxt}.</p>"
          . ($description !== '' ? "<p>" . htmlspecialchars($description, ENT_QUOTES) . "</p>" : "")
          . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Zobacz oceny w panelu kursanta</a></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";
    foreach ($emails as $addr => $nm) {
        try { mail_queue_add($addr, $nm, "{$org}: nowa ocena — " . (string)$row['course_name'], $html, '', 'ti_grade', $course_id, '', false); }
        catch (\Throwable $e) {}
    }
}

/**
 * Odwołuje całą lekcję (Doradca / admin) — status='cancelled', z powodem i autorem.
 * Odwołana lekcja nie jest liczona do ceny (rozliczenie bierze tylko status='held').
 */
function k30_ti_cancel_session(int $session_id, string $reason, string $role, string $by_label): void {
    $role = array_key_exists($role, K30_TI_CANCEL_ROLES) ? $role : 'doradca';
    db()->prepare(
        "UPDATE k30_ti_sessions
         SET status='cancelled', cancel_reason=?, cancelled_by_role=?, cancelled_by=?, cancelled_at=datetime('now'),
             updated_at=datetime('now')
         WHERE id=?"
    )->execute([$reason, $role, $by_label, $session_id]);
}

/**
 * Oblicza miesięczne rozliczenie klienta w TI.
 * Zwraca godziny i kwotę na podstawie lekcji odbyłych w danym miesiącu.
 */
function k30_ti_calculate_billing(int $client_id, int $month, int $year): array {
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));

    // Aktywne zapisy klienta wraz z modelem rozliczania kursu
    $enrs = db_all(
        "SELECT e.*, c.billing_model AS course_billing_model, c.billing_amount AS course_billing_amount
         FROM k30_ti_enrollments e
         JOIN k30_ti_courses c ON c.id=e.course_id
         WHERE e.client_id=? AND e.status='active'",
        [$client_id]
    );

    $hours  = 0.0;
    $amount = 0.0;
    $models = [];
    foreach ($enrs as $e) {
        // Godziny obecności w tym kursie w danym miesiącu
        $rows = db_all(
            "SELECT s.duration_min
             FROM k30_ti_attendance a
             JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status='held'
                  AND s.course_id=? AND s.lesson_date BETWEEN ? AND ?
             WHERE a.client_id=? AND a.attended=1 AND COALESCE(a.cancelled,0)=0",
            [(int)$e['course_id'], $from, $to, $client_id]
        );
        $ch = 0.0;
        foreach ($rows as $r) $ch += (float)$r['duration_min'] / 60;
        $hours += $ch;

        $eff = k30_ti_effective_billing($e, [
            'billing_model'  => $e['course_billing_model'],
            'billing_amount' => $e['course_billing_amount'],
        ]);
        $models[$eff['code']] = true;
        if ($eff['model'] === 1 || $eff['model'] === 3) {
            // miesięczny / stały — kwota niezależna od godzin (naliczana gdy zapis aktywny)
            $amount += $eff['amount'];
        } else {
            // godzinowy
            $amount += $ch * $eff['hourly_rate'];
        }
    }

    return [
        'client_id'   => $client_id,
        'month'       => $month,
        'year'        => $year,
        'hours_billed'=> round($hours, 4),
        'amount'      => round($amount, 2),
        'models'      => array_keys($models), // kody zastosowanych modeli (info)
    ];
}

/** Generuje / aktualizuje rozliczenie miesięczne klienta. */
function k30_ti_issue_billing(int $client_id, int $month, int $year, string $notes = ''): int {
    $calc = k30_ti_calculate_billing($client_id, $month, $year);
    // Pobierz stawkę — używamy sredniej lub ze zróżnicowanych kursów (uproszczenie: sumujemy w calculate)
    // Zwróć istniejące lub utwórz
    $ex = db_one("SELECT id, due_date FROM k30_ti_billing WHERE client_id=? AND month=? AND year=?",
                 [$client_id, $month, $year]);
    // Termin płatności = data wystawienia + efektywna liczba dni (kursant → kurs → 7)
    $pay      = k30_ti_client_payment($client_id);
    $due_days = (int)($pay['due_days'] ?? K30_TI_PAY_DUE_DAYS_DEFAULT) ?: K30_TI_PAY_DUE_DAYS_DEFAULT;
    $due_date = date('Y-m-d', strtotime("+{$due_days} days"));
    $data = [
        'hours_billed' => $calc['hours_billed'],
        'amount'       => $calc['amount'],
        'notes'        => $notes,
        'issued_at'    => date('Y-m-d H:i:s'),
        'status'       => 'issued',
    ];
    if ($ex) {
        // Zachowaj indywidualnie ustawiony termin; uzupełnij tylko gdy go brak.
        if (empty($ex['due_date'])) $data['due_date'] = $due_date;
        $set = []; $p = [];
        foreach ($data as $k => $v) { $set[] = "$k=?"; $p[] = $v; }
        $p[] = $ex['id'];
        db()->prepare("UPDATE k30_ti_billing SET " . implode(',', $set) . " WHERE id=?")->execute($p);
        return (int)$ex['id'];
    }
    return db_insert('k30_ti_billing', array_merge($data, [
        'client_id' => $client_id, 'month' => $month, 'year' => $year,
        'due_date'  => $due_date,
        'created_at'=> date('Y-m-d H:i:s'),
    ]));
}

/**
 * Powiadomienie o wystawieniu rozliczenia — SMS (kwota za okres) + e-mail.
 * Adresat: dla małoletnich opiekun (telefon/e-mail), inaczej kursant (k30_clients).
 * Domyślnie wysyła tylko raz (gdy notified_at puste); $force=true wymusza ponowną wysyłkę.
 * Zwraca ['ok','sms'=>bool,'email'=>bool,'skipped'=>bool,'msg'].
 */
function k30_ti_billing_notify(int $billing_id, bool $force = false): array {
    $b = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$billing_id]);
    if (!$b) return ['ok' => false, 'msg' => 'Brak rozliczenia.'];
    if (!$force && !empty($b['notified_at'])) return ['ok' => false, 'skipped' => true, 'msg' => 'Powiadomienie już wysłano.'];

    $client = db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$b['client_id']]) ?: [];
    $acc    = db_one("SELECT is_minor, guardian_name, guardian_phone, guardian_email
                      FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [(int)$b['client_id']]);
    $minor  = $acc && !empty($acc['is_minor']);
    $phone  = $minor && !empty($acc['guardian_phone']) ? $acc['guardian_phone'] : (string)($client['phone'] ?? '');
    $email  = $minor && !empty($acc['guardian_email']) ? $acc['guardian_email'] : (string)($client['email'] ?? '');
    $toName = $minor && !empty($acc['guardian_name'])  ? $acc['guardian_name']  : (string)($client['name'] ?? '');

    $months = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
               7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
    $period   = ($months[(int)$b['month']] ?? $b['month']) . ' ' . (int)$b['year'];
    $amount   = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
    $amount_s = number_format($amount, 2, ',', ' ');
    $due_s    = !empty($b['due_date']) ? date('d.m.Y', strtotime($b['due_date'])) : '';
    $org      = defined('ORG_NAME') ? ORG_NAME : 'Placówka';
    $pay      = k30_ti_client_payment((int)$b['client_id']);
    $base     = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $portal   = $base . '/karty30/ti/kursant/login.php';

    $sms_sent = false; $mail_sent = false;

    // ── SMS ──
    if ($phone !== '') {
        require_once __DIR__ . '/sms.php';
        if (function_exists('sms_is_enabled') && sms_is_enabled()) {
            // bez polskich znaków — bramki SMS
            $msg = "{$org}: rozliczenie za {$period}: {$amount_s} zl."
                 . ($due_s !== '' ? " Termin platnosci: {$due_s}." : '')
                 . ($pay['account'] !== '' ? " Wplata na: {$pay['account']}." : '')
                 . " Szczegoly w panelu kursanta.";
            $msg = strtr($msg, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                                'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);
            try { sms_send($phone, $msg); $sms_sent = true; } catch (\Throwable $e) {}
        }
    }

    // ── E-mail ──
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/mail_queue.php';
        $rows = "<tr><td style='padding:4px 12px 4px 0;color:#555'>Okres:</td><td><strong>" . h($period) . "</strong></td></tr>"
              . "<tr><td style='padding:4px 12px 4px 0;color:#555'>Kwota do zapłaty:</td><td><strong>" . h($amount_s) . " zł</strong></td></tr>";
        if ($due_s !== '')          $rows .= "<tr><td style='padding:4px 12px 4px 0;color:#555'>Termin płatności:</td><td><strong>" . h($due_s) . "</strong></td></tr>";
        if ($pay['account'] !== '') $rows .= "<tr><td style='padding:4px 12px 4px 0;color:#555'>Nr konta:</td><td><strong>" . h($pay['account']) . "</strong></td></tr>";
        if ($pay['title'] !== '')   $rows .= "<tr><td style='padding:4px 12px 4px 0;color:#555'>Tytuł wpłaty:</td><td>" . h($pay['title']) . "</td></tr>";
        $html = "<p>Dzień dobry" . ($toName ? ', ' . h($toName) : '') . ",</p>"
              . "<p>Wystawiliśmy rozliczenie za zajęcia (" . h($org) . ") za okres <strong>" . h($period) . "</strong>.</p>"
              . "<table style='border-collapse:collapse;font-family:Arial,sans-serif'>" . $rows . "</table>"
              . "<p>Szczegóły i historia rozliczeń w panelu kursanta: <a href='" . h($portal) . "'>" . h($portal) . "</a></p>"
              . "<p style='color:#888;font-size:12px'>Wiadomość wygenerowana automatycznie.</p>";
        try {
            mail_queue_add($email, $toName, "Rozliczenie za {$period} — {$org}", $html, '', 'ti_billing', $billing_id, '', false);
            $mail_sent = true;
        } catch (\Throwable $e) {}
    }

    db()->prepare("UPDATE k30_ti_billing SET notified_at=datetime('now') WHERE id=?")->execute([$billing_id]);
    return ['ok' => true, 'sms' => $sms_sent, 'email' => $mail_sent, 'phone' => $phone, 'email_addr' => $email];
}

// ── Lista oczekujących ─────────────────────────────────────────────────────────

const K30_WAIT_PRIORITIES = [
    'pilny'  => ['label' => 'Pilny',   'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'bi-exclamation-circle-fill', 'order' => 1],
    'pfron'  => ['label' => 'PFRON',   'color' => '#7c3aed', 'bg' => '#f5f3ff', 'icon' => 'bi-building-fill-check',      'order' => 2],
    'zwykly' => ['label' => 'Zwykły',  'color' => '#2563eb', 'bg' => '#eff6ff', 'icon' => 'bi-clock',                    'order' => 3],
];

const K30_WAIT_STATUSES = [
    'waiting'   => ['label' => 'Oczekuje',    'color' => '#f59e0b', 'bg' => '#fffbeb'],
    'contacted' => ['label' => 'Skontaktowano','color'=> '#2563eb', 'bg' => '#eff6ff'],
    'scheduled' => ['label' => 'Zaplanowane', 'color' => '#16a34a', 'bg' => '#f0fdf4'],
    'cancelled' => ['label' => 'Anulowane',   'color' => '#9ca3af', 'bg' => '#f9fafb'],
    'done'      => ['label' => 'Zakończone',  'color' => '#6b7280', 'bg' => '#f3f4f6'],
];

function k30_waiting_list(string $status = 'waiting', int $client_id = 0): array {
    $where = []; $params = [];
    if ($status === 'active') {
        $where[] = "w.status IN ('waiting','contacted')";
    } elseif ($status) {
        $where[] = "w.status=?"; $params[] = $status;
    }
    if ($client_id) { $where[] = "w.client_id=?"; $params[] = $client_id; }
    $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    // Sortuj: pilny → pfron → zwykły, potem data zapisu
    return db_all(
        "SELECT w.*,
                cl.name AS client_name, cl.phone AS client_phone, cl.email AS client_email,
                u.name AS created_by_name,
                s.start_time AS scheduled_time
         FROM k30_waiting_list w
         JOIN k30_clients cl ON cl.id=w.client_id
         LEFT JOIN users u ON u.id=w.created_by
         LEFT JOIN k30_schedules s ON s.id=w.scheduled_id
         $sql_where
         ORDER BY
           CASE w.priority WHEN 'pilny' THEN 1 WHEN 'pfron' THEN 2 ELSE 3 END,
           w.created_at ASC",
        $params
    );
}

function k30_waiting_add(int $client_id, string $priority, string $reason, string $notes): int {
    $id = db_insert('k30_waiting_list', [
        'client_id'  => $client_id,
        'priority'   => array_key_exists($priority, K30_WAIT_PRIORITIES) ? $priority : 'zwykly',
        'reason'     => $reason,
        'notes'      => $notes,
        'status'     => 'waiting',
        'created_by' => (int)(current_user()['id'] ?? 0),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Aktywność CRM
    try {
        require_once __DIR__ . '/crm.php';
        crm_migrate();
        $client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]);
        if ($client) {
            $contact_id = k30_sync_to_crm($client, (int)(current_user()['id'] ?? 0));
            if ($contact_id) {
                $prio_label = K30_WAIT_PRIORITIES[$priority]['label'] ?? $priority;
                k30_log_crm_activity(
                    $contact_id, 'task',
                    "Dodano do kolejki K30 [{$prio_label}]",
                    $reason ?: "Beneficjent oczekuje na termin konsultacji. Priorytet: {$prio_label}.",
                    '', 'planned', '',
                    (int)(current_user()['id'] ?? 0)
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('[k30_wait_crm] ' . $e->getMessage());
    }

    return $id;
}

function k30_waiting_change_status(int $id, string $status, ?int $schedule_id = null): void {
    $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if ($schedule_id) $data['scheduled_id'] = $schedule_id;
    $set = []; $p = [];
    foreach ($data as $k => $v) { $set[] = "$k=?"; $p[] = $v; }
    $p[] = $id;
    db()->prepare("UPDATE k30_waiting_list SET " . implode(',', $set) . " WHERE id=?")->execute($p);
}

function k30_waiting_send_sms(int $id, string $message): bool {
    try {
        require_once __DIR__ . '/sms.php';
        if (!sms_is_enabled()) return false;
        $row = db_one(
            "SELECT w.*, cl.phone AS client_phone, cl.name AS client_name
             FROM k30_waiting_list w JOIN k30_clients cl ON cl.id=w.client_id WHERE w.id=?",
            [$id]
        );
        if (!$row || !$row['client_phone']) return false;
        sms_send($row['client_phone'], $message);
        db()->prepare(
            "UPDATE k30_waiting_list SET sms_sent_at=datetime('now'), sms_count=sms_count+1, status='contacted', updated_at=datetime('now') WHERE id=?"
        )->execute([$id]);
        return true;
    } catch (\Throwable $e) {
        error_log('[k30_wait_sms] ' . $e->getMessage());
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  PLAN NAUCZANIA (k30_ti_curriculum) — CRUD, powiązanie z lekcją, import CSV
// ═══════════════════════════════════════════════════════════════════════════

/** Pozycje planu kursu, uporządkowane: dział → kolejność. */
function k30_ti_curriculum_list(int $course_id, bool $only_active = false): array {
    $sql = "SELECT * FROM k30_ti_curriculum WHERE course_id=?"
         . ($only_active ? " AND is_active=1" : "")
         . " ORDER BY section COLLATE NOCASE, position, id";
    return db_all($sql, [$course_id]);
}

function k30_ti_curriculum_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_curriculum WHERE id=?", [$id]);
}

/** Następna pozycja (na końcu listy kursu). */
function k30_ti_curriculum_next_position(int $course_id): int {
    $r = db_one("SELECT COALESCE(MAX(position),0)+1 AS p FROM k30_ti_curriculum WHERE course_id=?", [$course_id]);
    return (int)($r['p'] ?? 1);
}

/** Zapis pozycji planu. Zwraca id (nowe lub istniejące). */
function k30_ti_curriculum_save(array $data, ?int $id = null, ?int $created_by = null): int {
    $fields = [
        'section'     => trim((string)($data['section'] ?? '')),
        'title'       => trim((string)($data['title'] ?? '')),
        'description' => trim((string)($data['description'] ?? '')),
        'est_minutes' => max(0, (int)($data['est_minutes'] ?? 0)),
        'is_active'   => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        db_update('k30_ti_curriculum', $fields, $id);
        return $id;
    }
    $fields['course_id']  = (int)$data['course_id'];
    $fields['position']   = isset($data['position'])
        ? (int)$data['position']
        : k30_ti_curriculum_next_position((int)$data['course_id']);
    $fields['created_by'] = $created_by;
    return db_insert('k30_ti_curriculum', $fields);
}

function k30_ti_curriculum_delete(int $id): void {
    db()->prepare("DELETE FROM k30_ti_curriculum WHERE id=?")->execute([$id]);
}

/** Zapis nowej kolejności pozycji w obrębie kursu (lista id w docelowej kolejności). */
function k30_ti_curriculum_reorder(int $course_id, array $ordered_ids): void {
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE k30_ti_curriculum SET position=?, updated_at=datetime('now') WHERE id=? AND course_id=?");
    $pos = 1;
    foreach ($ordered_ids as $cid) {
        $stmt->execute([$pos++, (int)$cid, $course_id]);
    }
}

/** Identyfikatory punktów planu realizowanych przez lekcję. */
function k30_ti_session_curriculum_ids(int $session_id): array {
    $rows = db_all("SELECT curriculum_id FROM k30_ti_session_curriculum WHERE session_id=?", [$session_id]);
    return array_map(fn($r) => (int)$r['curriculum_id'], $rows);
}

/** Pełne wiersze punktów planu realizowanych przez lekcję (do wyświetlenia). */
function k30_ti_session_curriculum_items(int $session_id): array {
    return db_all(
        "SELECT c.* FROM k30_ti_session_curriculum sc
         JOIN k30_ti_curriculum c ON c.id=sc.curriculum_id
         WHERE sc.session_id=?
         ORDER BY c.section COLLATE NOCASE, c.position, c.id",
        [$session_id]
    );
}

/** Ustaw (zastąp) zestaw punktów planu realizowanych przez lekcję. */
function k30_ti_session_set_curriculum(int $session_id, array $curriculum_ids): void {
    $pdo = db();
    $pdo->prepare("DELETE FROM k30_ti_session_curriculum WHERE session_id=?")->execute([$session_id]);
    if (!$curriculum_ids) return;
    $ins = $pdo->prepare("INSERT OR IGNORE INTO k30_ti_session_curriculum (session_id, curriculum_id) VALUES (?,?)");
    foreach (array_unique(array_map('intval', $curriculum_ids)) as $cid) {
        if ($cid > 0) $ins->execute([$session_id, $cid]);
    }
}

/**
 * Import planu nauczania z CSV.
 * Kolumny (z separatorem ; lub ,): dział, temat[, opis][, czas_min].
 * Pierwszy wiersz traktowany jako nagłówek, jeśli wygląda na etykiety.
 * Zwraca ['added'=>int, 'errors'=>[['line'=>int,'msg'=>string], ...]].
 */
function k30_ti_curriculum_import_csv(int $course_id, string $raw, ?int $created_by = null): array {
    $added  = 0;
    $errors = [];
    // Usuń BOM, ujednolić końce linii
    $raw   = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);

    // Wykryj separator po pierwszej niepustej linii
    $delim = ';';
    foreach ($lines as $ln) {
        if (trim($ln) === '') continue;
        $delim = (substr_count($ln, ';') >= substr_count($ln, ',')) ? ';' : ',';
        break;
    }

    $pos      = k30_ti_curriculum_next_position($course_id);
    $first    = true;
    foreach ($lines as $i => $line) {
        $lineNo = $i + 1;
        if (trim($line) === '') { $first = false; continue; }
        $cols = str_getcsv($line, $delim, '"', '');
        $cols = array_map(fn($c) => trim((string)$c), $cols);

        // Pomiń wiersz nagłówka (etykiety kolumn)
        if ($first) {
            $first = false;
            $joined = mb_strtolower(implode(' ', $cols), 'UTF-8');
            if (preg_match('/(dzia|temat|section|title|opis|czas|minut)/u', $joined)
                && !preg_match('/\d{2,}/', $joined)) {
                continue;
            }
        }

        $section = $cols[0] ?? '';
        $title   = $cols[1] ?? '';
        $desc    = $cols[2] ?? '';
        $minsRaw = $cols[3] ?? '';

        if ($title === '') {
            $errors[] = ['line' => $lineNo, 'msg' => 'brak tematu (kolumna 2 „temat" jest pusta)'];
            continue;
        }
        $mins = 0;
        if ($minsRaw !== '') {
            if (!preg_match('/^\d+$/', $minsRaw)) {
                $errors[] = ['line' => $lineNo, 'msg' => 'czas „' . $minsRaw . '" nie jest liczbą minut (kolumna 4)'];
                continue;
            }
            $mins = (int)$minsRaw;
        }
        k30_ti_curriculum_save([
            'course_id'   => $course_id,
            'section'     => $section,
            'title'       => $title,
            'description' => $desc,
            'est_minutes' => $mins,
            'is_active'   => 1,
            'position'    => $pos++,
        ], null, $created_by);
        $added++;
    }
    return ['added' => $added, 'errors' => $errors];
}

// ═══════════════════════════════════════════════════════════════════════════
//  TESTY / QUIZY (k30_ti_tests…) — kreator, podejścia, ocena, sync do dziennika
// ═══════════════════════════════════════════════════════════════════════════

const K30_TI_QUESTION_TYPES = [
    'single' => 'Jednokrotny wybór',
    'multi'  => 'Wielokrotny wybór',
    'open'   => 'Pytanie otwarte',
];

function k30_ti_tests_list(int $course_id, bool $only_active = false): array {
    $sql = "SELECT t.*,
                   (SELECT COUNT(*) FROM k30_ti_test_questions q WHERE q.test_id=t.id) AS n_questions,
                   (SELECT COUNT(*) FROM k30_ti_test_attempts a WHERE a.test_id=t.id)   AS n_attempts
            FROM k30_ti_tests t WHERE t.course_id=?"
         . ($only_active ? " AND t.is_active=1" : "")
         . " ORDER BY t.title COLLATE NOCASE, t.id";
    return db_all($sql, [$course_id]);
}

function k30_ti_test_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_tests WHERE id=?", [$id]);
}

function k30_ti_test_save(array $data, ?int $id = null, ?int $created_by = null): int {
    $f = [
        'title'          => trim((string)($data['title'] ?? '')),
        'description'    => trim((string)($data['description'] ?? '')),
        'time_limit_min' => max(0, (int)($data['time_limit_min'] ?? 0)),
        'pass_pct'       => max(0, min(100, (int)($data['pass_pct'] ?? 0))),
        'shuffle'        => !empty($data['shuffle'])    ? 1 : 0,
        'is_active'      => !empty($data['is_active'])  ? 1 : 0,
        'sync_grade'     => !empty($data['sync_grade']) ? 1 : 0,
    ];
    if ($id) { db_update('k30_ti_tests', $f, $id); return $id; }
    $f['course_id']  = (int)$data['course_id'];
    $f['created_by'] = $created_by;
    return db_insert('k30_ti_tests', $f);
}

function k30_ti_test_delete(int $id): void {
    db()->prepare("DELETE FROM k30_ti_tests WHERE id=?")->execute([$id]);
}

function k30_ti_test_questions(int $test_id): array {
    return db_all("SELECT * FROM k30_ti_test_questions WHERE test_id=? ORDER BY position, id", [$test_id]);
}

function k30_ti_test_question_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_test_questions WHERE id=?", [$id]);
}

function k30_ti_test_options(int $question_id): array {
    return db_all("SELECT * FROM k30_ti_test_options WHERE question_id=? ORDER BY position, id", [$question_id]);
}

function k30_ti_test_max_score(int $test_id): float {
    $r = db_one("SELECT COALESCE(SUM(points),0) AS s FROM k30_ti_test_questions WHERE test_id=?", [$test_id]);
    return (float)($r['s'] ?? 0);
}

/**
 * Zapis pytania wraz z wariantami. $data: type, prompt, points, options (array
 * elementów ['label'=>..,'is_correct'=>0|1]). Dla 'open' warianty ignorowane.
 * Zwraca id pytania.
 */
function k30_ti_test_question_save(array $data, ?int $id = null): int {
    $type = in_array($data['type'] ?? '', ['single','multi','open'], true) ? $data['type'] : 'single';
    $f = [
        'type'   => $type,
        'prompt' => trim((string)($data['prompt'] ?? '')),
        'points' => max(0, (float)str_replace(',', '.', (string)($data['points'] ?? 1))) ?: 1,
    ];
    if ($id) {
        db()->prepare("UPDATE k30_ti_test_questions SET type=?, prompt=?, points=? WHERE id=?")
            ->execute([$f['type'], $f['prompt'], $f['points'], $id]);
    } else {
        $tid = (int)$data['test_id'];
        $pos = (int)(db_one("SELECT COALESCE(MAX(position),0)+1 AS p FROM k30_ti_test_questions WHERE test_id=?", [$tid])['p'] ?? 1);
        $id  = db_insert('k30_ti_test_questions', ['test_id'=>$tid, 'position'=>$pos] + $f);
    }
    // Warianty: zastąp komplet (tylko dla pytań zamkniętych)
    db()->prepare("DELETE FROM k30_ti_test_options WHERE question_id=?")->execute([$id]);
    if ($type !== 'open') {
        $pos = 1;
        foreach (($data['options'] ?? []) as $opt) {
            $label = trim((string)($opt['label'] ?? ''));
            if ($label === '') continue;
            db_insert('k30_ti_test_options', [
                'question_id' => $id, 'position' => $pos++,
                'label' => $label, 'is_correct' => !empty($opt['is_correct']) ? 1 : 0,
            ]);
        }
    }
    return $id;
}

function k30_ti_test_question_delete(int $id): void {
    db()->prepare("DELETE FROM k30_ti_test_questions WHERE id=?")->execute([$id]);
}

function k30_ti_test_question_reorder(int $test_id, array $ordered_ids): void {
    $stmt = db()->prepare("UPDATE k30_ti_test_questions SET position=? WHERE id=? AND test_id=?");
    $pos = 1;
    foreach ($ordered_ids as $qid) { $stmt->execute([$pos++, (int)$qid, $test_id]); }
}

function k30_ti_test_attempt_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_test_attempts WHERE id=?", [$id]);
}

function k30_ti_test_attempts_for_client(int $client_id, ?int $test_id = null): array {
    $sql = "SELECT * FROM k30_ti_test_attempts WHERE client_id=?";
    $p = [$client_id];
    if ($test_id) { $sql .= " AND test_id=?"; $p[] = $test_id; }
    $sql .= " ORDER BY id DESC";
    return db_all($sql, $p);
}

function k30_ti_test_attempts_for_test(int $test_id): array {
    return db_all(
        "SELECT a.*, cl.name AS client_name FROM k30_ti_test_attempts a
         JOIN k30_clients cl ON cl.id=a.client_id WHERE a.test_id=? ORDER BY a.id DESC",
        [$test_id]
    );
}

/** Najlepsze (najwyższy wynik) zakończone podejście kursanta do testu. */
function k30_ti_test_best_attempt(int $test_id, int $client_id): ?array {
    return db_one(
        "SELECT * FROM k30_ti_test_attempts
         WHERE test_id=? AND client_id=? AND status IN ('submitted','graded')
         ORDER BY score DESC, id DESC LIMIT 1",
        [$test_id, $client_id]
    );
}

/** Rozpocznij podejście (lub zwróć trwające). Zwraca id podejścia. */
function k30_ti_test_start_attempt(int $test_id, int $client_id): int {
    $open = db_one("SELECT id FROM k30_ti_test_attempts WHERE test_id=? AND client_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1", [$test_id, $client_id]);
    if ($open) return (int)$open['id'];
    return db_insert('k30_ti_test_attempts', [
        'test_id' => $test_id, 'client_id' => $client_id,
        'status' => 'in_progress', 'max_score' => k30_ti_test_max_score($test_id),
    ]);
}

/**
 * Zapisz i oceń podejście. $answers: question_id => ['option_ids'=>[..], 'text'=>..].
 * Pytania zamknięte oceniane automatycznie, otwarte → ręczna ocena (needs_review).
 */
function k30_ti_test_submit(int $attempt_id, array $answers): void {
    $att = k30_ti_test_attempt_get($attempt_id);
    if (!$att || $att['status'] !== 'in_progress') return;
    $questions = k30_ti_test_questions((int)$att['test_id']);
    $score = 0.0; $max = 0.0; $needs_review = false;
    $pdo = db();
    $pdo->prepare("DELETE FROM k30_ti_test_answers WHERE attempt_id=?")->execute([$attempt_id]);

    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $pts = (float)$q['points'];
        $max += $pts;
        $a   = $answers[$qid] ?? [];
        $sel = array_map('intval', (array)($a['option_ids'] ?? []));
        $txt = trim((string)($a['text'] ?? ''));

        if ($q['type'] === 'open') {
            $needs_review = true;
            db_insert('k30_ti_test_answers', [
                'attempt_id'=>$attempt_id, 'question_id'=>$qid,
                'answer_text'=>$txt, 'points_awarded'=>null, 'is_correct'=>0,
            ]);
            continue;
        }
        // Zamknięte: poprawne = dokładnie zbiór poprawnych wariantów
        $correct = array_map(fn($o)=>(int)$o['id'], array_filter(k30_ti_test_options($qid), fn($o)=>(int)$o['is_correct']===1));
        sort($sel); sort($correct);
        $ok = ($sel === $correct && $correct !== []);
        if ($ok) $score += $pts;
        db_insert('k30_ti_test_answers', [
            'attempt_id'=>$attempt_id, 'question_id'=>$qid,
            'option_ids'=>implode(',', $sel), 'points_awarded'=>$ok ? $pts : 0, 'is_correct'=>$ok ? 1 : 0,
        ]);
    }

    $status = $needs_review ? 'submitted' : 'graded';
    $pdo->prepare(
        "UPDATE k30_ti_test_attempts SET status=?, score=?, max_score=?, needs_review=?, submitted_at=datetime('now'), graded_at=" . ($needs_review ? "NULL" : "datetime('now')") . " WHERE id=?"
    )->execute([$status, $score, $max, $needs_review ? 1 : 0, $attempt_id]);

    if (!$needs_review) k30_ti_test_sync_grade($attempt_id);
}

/** Ręczna ocena pytań otwartych. $points: question_id => liczba punktów. */
function k30_ti_test_grade_open(int $attempt_id, array $points): void {
    $att = k30_ti_test_attempt_get($attempt_id);
    if (!$att) return;
    $upd = db()->prepare("UPDATE k30_ti_test_answers SET points_awarded=?, is_correct=? WHERE attempt_id=? AND question_id=?");
    foreach ($points as $qid => $p) {
        $q = k30_ti_test_question_get((int)$qid);
        if (!$q) continue;
        $val = max(0, min((float)$q['points'], (float)str_replace(',', '.', (string)$p)));
        $upd->execute([$val, $val >= (float)$q['points'] && $val > 0 ? 1 : 0, $attempt_id, (int)$qid]);
    }
    // Przelicz wynik z sumy przyznanych punktów
    $row = db_one("SELECT COALESCE(SUM(points_awarded),0) AS s, SUM(CASE WHEN points_awarded IS NULL THEN 1 ELSE 0 END) AS pending FROM k30_ti_test_answers WHERE attempt_id=?", [$attempt_id]);
    $pending = (int)($row['pending'] ?? 0);
    db()->prepare(
        "UPDATE k30_ti_test_attempts SET score=?, needs_review=?, status=?, graded_at=" . ($pending ? "NULL" : "datetime('now')") . " WHERE id=?"
    )->execute([(float)$row['s'], $pending ? 1 : 0, $pending ? 'submitted' : 'graded', $attempt_id]);
    if (!$pending) k30_ti_test_sync_grade($attempt_id);
}

/** Jeśli test ma sync_grade — zapisz wynik podejścia do e-dziennika (1–6 wg %). */
function k30_ti_test_sync_grade(int $attempt_id): void {
    $att = k30_ti_test_attempt_get($attempt_id);
    if (!$att || $att['status'] !== 'graded') return;
    $test = k30_ti_test_get((int)$att['test_id']);
    if (!$test || empty($test['sync_grade'])) return;
    $max = (float)$att['max_score'];
    if ($max <= 0) return;
    $pct  = 100 * (float)$att['score'] / $max;
    // Skala szkolna z procentów
    $grade = $pct >= 90 ? '5' : ($pct >= 75 ? '4' : ($pct >= 60 ? '3' : ($pct >= 50 ? '2' : '1')));
    $vnum  = (float)$grade;
    $desc  = 'Test: ' . ($test['title'] ?? '') . ' (' . round($pct) . '%)';
    // Aktualizuj istniejący wpis dla tego podejścia albo utwórz nowy
    $existing = db_one("SELECT id FROM k30_ti_grades WHERE hw_submission_id IS NULL AND course_id=? AND client_id=? AND description=?", [(int)$test['course_id'], (int)$att['client_id'], $desc]);
    if ($existing) {
        db()->prepare("UPDATE k30_ti_grades SET value_text=?, value_num=?, graded_at=datetime('now') WHERE id=?")
            ->execute([$grade, $vnum, (int)$existing['id']]);
    } else {
        db_insert('k30_ti_grades', [
            'course_id'=>(int)$test['course_id'], 'client_id'=>(int)$att['client_id'],
            'category'=>'sprawdzian', 'value_text'=>$grade, 'value_num'=>$vnum,
            'weight'=>3, 'description'=>$desc,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  DOSTĘPNOŚĆ PROWADZĄCYCH (k30_ti_instructor_availability) — sloty tygodniowe
// ═══════════════════════════════════════════════════════════════════════════

/** Minuty od północy z 'HH:MM' (0 dla pustej). */
function ti_hm2min(string $hm): int {
    $hm = trim($hm);
    if (strlen($hm) < 4) return 0;
    return (int)substr($hm, 0, 2) * 60 + (int)substr($hm, 3, 2);
}

/** Aktywne okna dostępności prowadzącego, posortowane Pn→Nd, potem od godziny. */
function ti_instructor_availability(int $instructor_id): array {
    if (!$instructor_id) return [];
    return db_all(
        "SELECT * FROM k30_ti_instructor_availability
         WHERE instructor_id=? AND is_active=1
         ORDER BY (day_of_week + 6) % 7, time_from",
        [$instructor_id]
    );
}

/** Dodaj okno dostępności. Zwraca false przy błędnych godzinach. */
function ti_avail_add(int $instructor_id, int $dow, string $from, string $to): bool {
    $from = substr(trim($from), 0, 5);
    $to   = substr(trim($to), 0, 5);
    if (!$instructor_id || $dow < 0 || $dow > 6) return false;
    if ($from === '' || $to === '' || ti_hm2min($from) >= ti_hm2min($to)) return false;
    db_insert('k30_ti_instructor_availability', [
        'instructor_id' => $instructor_id, 'day_of_week' => $dow,
        'time_from' => $from, 'time_to' => $to, 'is_active' => 1,
    ]);
    return true;
}

function ti_avail_delete(int $id, int $instructor_id): void {
    db()->prepare("DELETE FROM k30_ti_instructor_availability WHERE id=? AND instructor_id=?")
        ->execute([$id, $instructor_id]);
}

/**
 * Czy prowadzący jest dostępny w danym terminie?
 * Zwraca ['ok'=>bool, 'configured'=>bool, 'reason'=>string].
 *  - brak instruktora lub brak zdefiniowanej dostępności → ok=true (nie egzekwujemy),
 *  - kolizja z urlopem/nieobecnością → ok=false,
 *  - poza oknami danego dnia → ok=false.
 */
function ti_instructor_available_at(int $instructor_id, string $date, string $time_from, string $time_to = ''): array {
    $res = ['ok' => true, 'configured' => false, 'reason' => ''];
    if (!$instructor_id || $date === '') return $res;

    // Kolizja z nieobecnością (urlop/chorobowe) — jeśli moduł urlopów dostępny
    if (function_exists('ti_instructor_on_leave')) {
        $lv = ti_instructor_on_leave($instructor_id, $date);
        if ($lv) {
            $t = trim((string)($lv['type'] ?? '')) ?: 'nieobecność';
            return ['ok' => false, 'configured' => true, 'reason' => 'Prowadzący ma w tym dniu nieobecność (' . $t . ').'];
        }
    }

    $rows = ti_instructor_availability($instructor_id);
    $res['configured'] = (bool)$rows;
    if (!$rows) return $res;   // brak zdefiniowanej dostępności → bez ograniczeń

    $dow = (int)date('w', strtotime($date));
    $day = array_values(array_filter($rows, fn($r) => (int)$r['day_of_week'] === $dow));
    if (!$day) {
        $dname = K30_TI_DAYS[$dow] ?? '';
        return ['ok' => false, 'configured' => true, 'reason' => 'Prowadzący nie jest dostępny w wybranym dniu' . ($dname ? ' (' . $dname . ')' : '') . '.'];
    }
    $f = ti_hm2min($time_from);
    $t = $time_to !== '' ? ti_hm2min($time_to) : $f;
    foreach ($day as $w) {
        if ($f >= ti_hm2min($w['time_from']) && $t <= ti_hm2min($w['time_to'])) {
            return ['ok' => true, 'configured' => true, 'reason' => ''];
        }
    }
    $win = implode(', ', array_map(fn($w) => substr($w['time_from'],0,5) . '–' . substr($w['time_to'],0,5), $day));
    return ['ok' => false, 'configured' => true, 'reason' => 'Termin poza godzinami dostępności prowadzącego (' . $win . ').'];
}

/** Skrót: id prowadzącego kursu. */
function ti_course_instructor_id(int $course_id): int {
    if (!$course_id) return 0;
    return (int)(db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id])['instructor_id'] ?? 0);
}
