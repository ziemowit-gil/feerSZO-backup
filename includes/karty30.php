<?php
/**
 * includes/karty30.php — Moduł Dydaktyka / Dydaktyka 3 (d. TyfloKonsultacje).
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

    // Prowadzący: zgoda na pokazanie danych kontaktowych kursantom
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN share_contact INTEGER NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {}

    // Prowadzący-student: brak składek ZUS — BB = netto
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN ti_is_student INTEGER NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {}

    // Forma rozliczenia prowadzącego: zlecenie / student / b2b
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN ti_payout_form TEXT NOT NULL DEFAULT 'zlecenie'");
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
    // PESEL (do dokumentów PFRON)
    try { $pdo->exec("ALTER TABLE k30_clients ADD COLUMN pesel TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // Migracja statusów beneficjentów: stare wartości → nowe klucze
    try { $pdo->exec("UPDATE k30_clients SET status='learning'  WHERE status='ready'"); }     catch (\Throwable $e) {}
    try { $pdo->exec("UPDATE k30_clients SET status='graduated' WHERE status='to_settle'"); } catch (\Throwable $e) {}
    try { $pdo->exec("UPDATE k30_clients SET status='enrolled'  WHERE status='other'"); }     catch (\Throwable $e) {}

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
    // Pola skierowania/umowy głównej PFRON (do generowania dokumentów)
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN main_contract_date DATE NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN main_contract_sign TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    // Liczba godzin szkolenia wg umowy (domyślnie 30 total / 25 właściwych)
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN hours_total    INTEGER NOT NULL DEFAULT 30"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN hours_training INTEGER NOT NULL DEFAULT 25"); } catch (\Throwable $e) {}
    // Kara umowna (kwota i słownie) — do umowy
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN penalty_amount TEXT    NOT NULL DEFAULT '100,00'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN penalty_words  TEXT    NOT NULL DEFAULT 'sto'");    } catch (\Throwable $e) {}
    // Numer dokumentu umowy (PFRON-AS/xx/yyyy), podpis i data podpisania
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN doc_number     TEXT    NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN signed_at      DATETIME"); }                  catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN signature_data TEXT    NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN signed_doc_path  TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN signed_doc2_path TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN registered_by    INTEGER REFERENCES users(id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN registered_at    DATETIME"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN board_approval_status TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN board_notified_at     DATETIME"); }               catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN board_approved_by     INTEGER"); }                catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_pfron_contracts ADD COLUMN board_approved_at     DATETIME"); }               catch (\Throwable $e) {}
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
    // Migracja: kolumny harmonogramu kursu (mogą nie istnieć gdy tabela created po ich usunięciu z CREATE TABLE)
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN day_of_week  INTEGER"); } catch (\Throwable $e) {}
    // Czytelna nazwa dla kursanta (np. „Informatyka — grupa 1”); techniczna
    // nazwa (schemat OKRES-RODZAJ-…) zostaje dla kadry. Pusta = pokaż name.
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN display_name TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN time_from    TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN time_to      TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN duration_min INTEGER NOT NULL DEFAULT 60"); } catch (\Throwable $e) {}
    // Migracja: miękkie usuwanie kursów TI (status 'active'|'cancelled')
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN status TEXT NOT NULL DEFAULT 'active'"); } catch (\Throwable $e) {}
    // Migracja: session_date → lesson_date (SQLite 3.25+)
    try { $pdo->exec("ALTER TABLE k30_ti_sessions RENAME COLUMN session_date TO lesson_date"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_sessions_course ON k30_ti_sessions(course_id,lesson_date)"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_attend_session  ON k30_ti_attendance(session_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_attend_client   ON k30_ti_attendance(client_id)");

    // Zapisane sprawozdania do WUP — pola narracyjne + wgrany podpisany PDF
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_wup_reports (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        period_from   DATE    NOT NULL,
        period_to     DATE    NOT NULL,
        instructor_id INTEGER NOT NULL DEFAULT 0,
        fields_json   TEXT    NOT NULL DEFAULT '{}',
        generated_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        generated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        signed_path   TEXT    NOT NULL DEFAULT '',
        signed_name   TEXT    NOT NULL DEFAULT '',
        signed_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        signed_at     DATETIME,
        UNIQUE(period_from, period_to, instructor_id)
    )");

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
        "ALTER TABLE k30_ti_sessions ADD COLUMN meeting_url         TEXT NOT NULL DEFAULT ''",
        // Link do przygotowanego materiału zdalnego (status=remote_material)
        "ALTER TABLE k30_ti_sessions ADD COLUMN material_url        TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_courses  ADD COLUMN default_meeting_url TEXT NOT NULL DEFAULT ''",
        // ID spotkania Zoom powiązanego z kursem (wygenerowanego przez API)
        "ALTER TABLE k30_ti_courses  ADD COLUMN zoom_meeting_id     TEXT NOT NULL DEFAULT ''",
        // Oceny (e-dziennik) włączone dla kursu (0=wyłączone — np. kurs dla dorosłych)
        "ALTER TABLE k30_ti_courses ADD COLUMN grades_enabled INTEGER NOT NULL DEFAULT 1",
        // Liczenie frekwencji (obecność/nieobecność) dla kursu (0=wyłączone — np. kurs bez list obecności)
        "ALTER TABLE k30_ti_courses ADD COLUMN track_attendance INTEGER NOT NULL DEFAULT 1",
        // Tryb zdalny/online grupy (do sprawozdania WUP: online vs stacjonarne)
        "ALTER TABLE k30_ti_courses ADD COLUMN is_online INTEGER NOT NULL DEFAULT 0",
        // Wyłączenie grupy ze sprawozdania do WUP
        "ALTER TABLE k30_ti_courses ADD COLUMN wup_exclude INTEGER NOT NULL DEFAULT 0",
        // Kurs jednorazowy (pojedyncze szkolenie/warsztat): termin realizacji
        // + powiązane działanie w Strategii (tabela actions, sekcja Działania)
        "ALTER TABLE k30_ti_courses ADD COLUMN is_oneoff INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_courses ADD COLUMN oneoff_date DATE",
        "ALTER TABLE k30_ti_courses ADD COLUMN action_id INTEGER NOT NULL DEFAULT 0",
        // Oceny włączone dla osoby globalnie (per osoba) — niezależnie od kursu
        "ALTER TABLE k30_clients   ADD COLUMN ti_grades_enabled INTEGER NOT NULL DEFAULT 1",
        // Model rozliczania kursu: 1=miesięczny, 2=godzinowy (domyślny), 3=stały
        // Grupa wyłączona z fakturowania (np. finansowana z dotacji, gdzie faktury
        // się nie wystawia). Rozliczenia nadal powstają — nie znika ewidencja
        // godzin i saldo; blokujemy wyłącznie wystawianie faktur.
        "ALTER TABLE k30_ti_courses ADD COLUMN no_invoice INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_courses ADD COLUMN billing_model  INTEGER NOT NULL DEFAULT 2",
        "ALTER TABLE k30_ti_courses ADD COLUMN billing_amount REAL    NOT NULL DEFAULT 0",
        // Wynagrodzenie prowadzącego: stała kwota brutto-brutto za przeprowadzoną lekcję
        "ALTER TABLE k30_ti_courses ADD COLUMN lesson_payout_bb REAL NOT NULL DEFAULT 0",
        // Override modelu na kursancie (zapisie): 0=dziedziczy z kursu, >0=indywidualny (kod 9999)
        "ALTER TABLE k30_ti_enrollments ADD COLUMN billing_model  INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN billing_amount REAL    NOT NULL DEFAULT 0",
        // Dane do wpłat: domyślne na kursie + indywidualne na kursancie (używane gdy kod 9999)
        "ALTER TABLE k30_ti_courses ADD COLUMN pay_account  TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_courses ADD COLUMN pay_title    TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_courses ADD COLUMN is_subgroup  INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN pay_account TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN pay_title   TEXT NOT NULL DEFAULT ''",
        // Dedup alertu niskiej frekwencji (1=już powiadomiono; reset gdy frekwencja wróci powyżej progu)
        "ALTER TABLE k30_ti_enrollments ADD COLUMN low_att_alerted INTEGER NOT NULL DEFAULT 0",
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
        // Beneficjent nie pojawił się na zajęciach (lekcja się odbyła, prowadzący był) + model rozliczenia
        "ALTER TABLE k30_ti_attendance ADD COLUMN no_show          INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_attendance ADD COLUMN no_show_billing  TEXT    NOT NULL DEFAULT 'full'",
        "ALTER TABLE k30_ti_attendance ADD COLUMN no_show_reason   TEXT    NOT NULL DEFAULT ''",
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
        // Weryfikacja dodatkowych numerów (kodem SMS wysłanym samodzielnie lub ręcznym
        // zatwierdzeniem przez administratora) — dopóki numer nie jest zweryfikowany,
        // nie trafiają na niego żadne powiadomienia (k30_ti_sms_numbers go pomija).
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone2_verified INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone3_verified INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone2_otp TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone3_otp TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone2_otp_expires TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_phone3_otp_expires TEXT NOT NULL DEFAULT ''",
        // Powiadomienia o zmianach w dydaktyce/eLearningu (nowe materiały, zadania, terminy)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_email_dydaktyka INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN notify_sms_dydaktyka   INTEGER NOT NULL DEFAULT 0",
        // Stały link Zoom per kursant (per zapis) — nadrzędny nad stałym linkiem kursu
        "ALTER TABLE k30_ti_enrollments ADD COLUMN zoom_meeting_id  TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN zoom_meeting_url TEXT NOT NULL DEFAULT ''",
        // Metoda lekcji: stacjonarna | zdalna_zoom | zdalna_inne ('' = nie wybrano)
        "ALTER TABLE k30_ti_sessions ADD COLUMN lesson_method TEXT NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // ── Rodzaje zajęć TI ──────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_subject_types (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        abbreviation TEXT    NOT NULL UNIQUE,
        name         TEXT    NOT NULL,
        is_active    INTEGER NOT NULL DEFAULT 1,
        sort_order   INTEGER NOT NULL DEFAULT 0,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    foreach ([
        "ALTER TABLE k30_ti_courses ADD COLUMN subject_type_id INTEGER REFERENCES k30_ti_subject_types(id) ON DELETE SET NULL",
        "ALTER TABLE k30_ti_courses ADD COLUMN group_code      TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_courses ADD COLUMN class_type      TEXT NOT NULL DEFAULT 'individual'",
        "ALTER TABLE k30_ti_subject_types ADD COLUMN requires_certificate INTEGER NOT NULL DEFAULT 0",
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
        // Ocena wygenerowana przez system z podejścia do testu — klucz do deduplicacji
        "ALTER TABLE k30_ti_grades    ADD COLUMN attempt_id       INTEGER",
        // Nazwa wystawcy jako tekst (gdy brak konta użytkownika, np. „System")
        "ALTER TABLE k30_ti_grades    ADD COLUMN graded_by_text   TEXT NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_grade_hwsub   ON k30_ti_grades(hw_submission_id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_grade_attempt  ON k30_ti_grades(attempt_id)");      } catch (\Throwable $e) {}

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

    // Prywatny token kanału iCal prowadzącego (subskrypcja kalendarza lekcji).
    // Tożsamość dydaktyka = users.id (logowanie danymi SZO), więc token trzymamy
    // per użytkownik — niezależnie od istnienia konta panelu dydaktyka.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_cal_tokens (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        token   TEXT NOT NULL DEFAULT ''
    )");

    // Samoobsługowe konto ownCloud prowadzącego — panel dydaktyka, zakładka „dysk"
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_owncloud (
        user_id             INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        owncloud_username   TEXT NOT NULL DEFAULT '',
        owncloud_created_at DATETIME,
        owncloud_quota_mb   INTEGER NOT NULL DEFAULT 0
    )");

    // CoProwadzący kursu — dodatkowe osoby z dostępem do kursu w panelu dydaktyka
    // (bez odpowiedzialności rozliczeniowej — ta pozostaje przy instructor_id kursu).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_course_coinstructors (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id  INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        user_id    INTEGER NOT NULL REFERENCES users(id)          ON DELETE CASCADE,
        role       TEXT    NOT NULL DEFAULT 'co_instructor',
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(course_id, user_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_coinstr_course ON k30_ti_course_coinstructors(course_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_coinstr_user   ON k30_ti_course_coinstructors(user_id)");

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
        // Płatna usługa: dedykowany adres IP dla maszyny (aktywacja jednorazowa + opłata miesięczna)
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_ip_enabled        INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_ip_activation_fee REAL    NOT NULL DEFAULT 100",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_ip_monthly_fee    REAL    NOT NULL DEFAULT 30",
        // Lista zablokowanego oprogramowania (treść HTML, edytowana WYSIWYG) — informacja dla kursanta
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN blocked_software TEXT NOT NULL DEFAULT ''",
        // Dedykowany serwer u zewnętrznego partnera (8 GB RAM / 50 GB SSD, ze zniżką) — cennik
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_enabled  INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_domain  TEXT    NOT NULL DEFAULT 'edukacja.cloud'",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_specs   TEXT    NOT NULL DEFAULT '8 GB RAM / 50 GB SSD'",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_monthly_price         REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_monthly_regular_price REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_annual_price          REAL NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_annual_regular_price  REAL NOT NULL DEFAULT 0",
        // Opłata aktywacyjna VPS (jednorazowa, realny koszt uruchomienia u partnera)
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN dedicated_server_activation_fee REAL NOT NULL DEFAULT 0",
        // Opłata manipulacyjna przy samodzielnej rezygnacji kursanta z abonamentu (IP/VPS)
        "ALTER TABLE k30_ti_vlab_config ADD COLUMN self_cancel_fee REAL NOT NULL DEFAULT 10",
    ] as $_sql) { try { $pdo->exec($_sql); } catch (\Throwable $e) {} }
    // Domyślny cennik/specyfikacja VPS wg realnej oferty partnera — wgrywany jednorazowo, tylko gdy
    // konfiguracja jest jeszcze przy pierwotnych wartościach placeholder (nie nadpisuje ustawień admina).
    $_dsrv_row = db_one("SELECT dedicated_server_specs, dedicated_server_monthly_price FROM k30_ti_vlab_config WHERE id=1");
    if ($_dsrv_row !== null
        && trim((string)$_dsrv_row['dedicated_server_specs']) === '8 GB RAM / 50 GB SSD'
        && (float)$_dsrv_row['dedicated_server_monthly_price'] === 0.0
    ) {
        db()->prepare(
            "UPDATE k30_ti_vlab_config
             SET dedicated_server_specs='8 vCPU AMD Epyc (min. 3 GHz) / 16 GB RAM ECC REG / 100 GB NVMe / bez limitu transferu / Ubuntu 24.04 / 1× adres IPv4 / bez panelu hostingowego',
                 dedicated_server_activation_fee=200,
                 dedicated_server_monthly_price=160
             WHERE id=1"
        )->execute();
    }
    // Domyślna treść listy zabronionego oprogramowania — wgrywana jednorazowo, tylko gdy pole jest
    // jeszcze puste (nie nadpisuje treści już zmienionej przez administratora w panelu VLAB).
    $_bsw_row = db_one("SELECT blocked_software FROM k30_ti_vlab_config WHERE id=1");
    if ($_bsw_row !== null && trim((string)$_bsw_row['blocked_software']) === '') {
        $_bsw_default = <<<'HTML'
<h3>1. Oprogramowanie do wymiany plików i P2P</h3>
<ul>
<li>Klienci sieci torrent oraz wszelkie narzędzia służące do masowej wymiany plików (np. uTorrent, qBittorrent, Transmission, Deluge).</li>
<li>Programy i skrypty służące do nieautoryzowanego pobierania lub udostępniania treści chronionych prawem autorskim.</li>
</ul>
<h3>2. Narzędzia do kryptominingu i analizy blockchain</h3>
<ul>
<li>Oprogramowanie służące do wydobywania kryptowalut (tzw. koparki, np. XMRig, CGMiner, BFGMiner).</li>
<li>Uruchamianie pełnych węzłów sieciowych (tzw. full nodes) bez wyraźnej, pisemnej zgody Administratora Głównego Fundacji.</li>
</ul>
<h3>3. Serwery rozrywkowe i usługi wysokiego obciążenia</h3>
<ul>
<li>Prywatne oraz publiczne serwery gier wieloosobowych (np. Minecraft, Counter-Strike, Rust, ARK i pokrewne).</li>
<li>Serwery komunikacyjne (np. TeamSpeak3, Murmur/Mumble) oraz boty automatyzujące (np. zaawansowane boty Discord/IRC), o ile nie stanowią one bezpośredniego przedmiotu realizowanych warsztatów edukacyjnych Fundacji.</li>
</ul>
<h3>4. Narzędzia ofensywnego cyberbezpieczeństwa (poza celami szkoleniowymi)</h3>
<ul>
<li>Skanery sieciowe, frameworki do testów penetracyjnych i łamacze haseł (np. Nmap, Metasploit, John the Ripper, Wireshark, ZMap).</li>
</ul>
<p><em>Wyjątek: użycie wyżej wymienionych narzędzi jest dozwolone wyłącznie w ramach odizolowanych środowisk laboratoryjnych (np. vLAB) w celach prowadzenia zatwierdzonych szkoleń i warsztatów z zakresu IT.</em></p>
<h3>5. Narzędzia do anonimizacji i tunelowania ruchu</h3>
<ul>
<li>Oprogramowanie służące do omijania zabezpieczeń sieciowych i całkowitego ukrywania ruchu (np. węzły wyjściowe sieci Tor, nieautoryzowane konfiguracje OpenVPN, WireGuard działające jako bramy wyjściowe).</li>
<li>Konfigurowanie otwartych serwerów proxy (Open Proxy).</li>
</ul>
<h3>6. Usługi sieciowe podatne na nadużycia (brak zabezpieczeń)</h3>
<ul>
<li>Uruchamianie serwerów pocztowych w konfiguracji otwartego przekazywania (Open Relay SMTP).</li>
<li>Narzędzia służące do masowej wysyłki wiadomości (SPAM), o ile nie są to oficjalnie wdrożone i zabezpieczone systemy marketingowe Fundacji.</li>
</ul>
<h3>7. Oprogramowanie nielegalne i komercyjne bez licencji</h3>
<ul>
<li>Wszelkie oprogramowanie komercyjne, na które Fundacja lub Użytkownik nie posiadają ważnej, udokumentowanej licencji (w tym wersji akademickiej lub open-source pozwalającej na dane zastosowanie).</li>
</ul>
HTML;
        db()->prepare("UPDATE k30_ti_vlab_config SET blocked_software=? WHERE id=1")->execute([$_bsw_default]);
    }
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_vlab_port_requests (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        container_id INTEGER NOT NULL REFERENCES k30_ti_vlab_containers(id) ON DELETE CASCADE,
        action       TEXT    NOT NULL DEFAULT 'open',  -- 'open' | 'close'
        host_port    INTEGER NOT NULL,
        proto        TEXT    NOT NULL DEFAULT 'tcp',
        port_row_id  INTEGER REFERENCES k30_ti_vlab_ports(id) ON DELETE SET NULL, -- tylko dla 'close'
        note         TEXT    NOT NULL DEFAULT '',
        status       TEXT    NOT NULL DEFAULT 'pending', -- 'pending'|'approved'|'rejected'
        requested_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        requested_by_student INTEGER REFERENCES k30_ti_student_accounts(id) ON DELETE SET NULL,
        approved_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        approved_at  DATETIME,
        reject_reason TEXT   NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_port_req_cont   ON k30_vlab_port_requests(container_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_port_req_status ON k30_vlab_port_requests(status)");

    // Sugerowane porty do wystawienia dla szablonu (np. „80,443") — podpowiedź przy tworzeniu maszyny
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_templates ADD COLUMN default_ports TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // Zamówienia dedykowanego adresu IP (płatna usługa: aktywacja jednorazowa + opłata miesięczna)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_vlab_dedicated_ip (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        container_id        INTEGER NOT NULL REFERENCES k30_ti_vlab_containers(id) ON DELETE CASCADE,
        student_id          INTEGER NOT NULL REFERENCES k30_ti_student_accounts(id) ON DELETE CASCADE,
        client_id           INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        status              TEXT    NOT NULL DEFAULT 'requested', -- requested|active|cancelled
        ip_address          TEXT    NOT NULL DEFAULT '',
        activation_fee      REAL    NOT NULL DEFAULT 0,
        monthly_fee         REAL    NOT NULL DEFAULT 0,
        last_billed_period  TEXT    NOT NULL DEFAULT '',           -- ostatni rozliczony miesiąc abonamentu, format 'YYYY-MM'
        activation_charge_id INTEGER REFERENCES k30_ti_billing(id) ON DELETE SET NULL,
        requested_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        activated_at        DATETIME,
        activated_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        cancelled_at        DATETIME,
        cancelled_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        note                TEXT    NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_dedip_container ON k30_ti_vlab_dedicated_ip(container_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_dedip_status    ON k30_ti_vlab_dedicated_ip(status)");

    // Zamówienia dedykowanego serwera u zewnętrznego partnera (xxx.edukacja.cloud, 8 GB RAM / 50 GB SSD)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_vlab_dedicated_server (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id        INTEGER NOT NULL REFERENCES k30_ti_student_accounts(id) ON DELETE CASCADE,
        client_id         INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        hostname_prefix   TEXT    NOT NULL DEFAULT '',   -- 'xxx' z xxx.edukacja.cloud
        server_username   TEXT    NOT NULL DEFAULT '',
        billing_period    TEXT    NOT NULL DEFAULT 'monthly', -- monthly|annual
        price             REAL    NOT NULL DEFAULT 0,    -- cena promocyjna za okres, zamrożona z chwili zamówienia
        regular_price     REAL    NOT NULL DEFAULT 0,    -- cena regularna (do pokazania wysokości zniżki)
        activation_fee    REAL    NOT NULL DEFAULT 0,    -- jednorazowa opłata aktywacyjna, zamrożona z chwili zamówienia
        status            TEXT    NOT NULL DEFAULT 'requested', -- requested|paid|active|cancelled
        server_hostname   TEXT    NOT NULL DEFAULT '',   -- pełny hostname po realizacji (zwykle = prefix + domena)
        partner_order_ref TEXT    NOT NULL DEFAULT '',   -- numer zamówienia u zewnętrznego partnera (notatka admina)
        billing_charge_id INTEGER REFERENCES k30_ti_billing(id) ON DELETE SET NULL,
        next_renewal_at   DATE,
        requested_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        paid_at           DATETIME,
        paid_by           INTEGER REFERENCES users(id) ON DELETE SET NULL,
        activated_at      DATETIME,
        activated_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        cancelled_at      DATETIME,
        cancelled_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        note              TEXT    NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_dedsrv_student ON k30_ti_vlab_dedicated_server(student_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vlab_dedsrv_status  ON k30_ti_vlab_dedicated_server(status)");
    // Dla instalacji, w których tabela istniała jeszcze bez opłaty aktywacyjnej
    try { $pdo->exec("ALTER TABLE k30_ti_vlab_dedicated_server ADD COLUMN activation_fee REAL NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

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
        // Alias logowania — własny login ustawiony przez kursanta (opcjonalny, unikalny)
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN login_alias TEXT NOT NULL DEFAULT ''",
        // Adres IP ostatniego logowania/wejścia do panelu (logowanie hasłem lub ciche wznowienie „zapamiętaj mnie")
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN last_login_ip TEXT NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
    // Samoobsługowe konto ownCloud kursanta (2 GB) — panel kursanta, zakładka „dysk"
    foreach ([
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN owncloud_username   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN owncloud_created_at DATETIME",
        "ALTER TABLE k30_ti_student_accounts ADD COLUMN owncloud_quota_mb   INTEGER NOT NULL DEFAULT 0",
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

    // Osoby upoważnione przez pełnoletniego kursanta do wglądu w jego konto
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_authorized_persons (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        student_account_id INTEGER NOT NULL REFERENCES k30_ti_student_accounts(id) ON DELETE CASCADE,
        name               TEXT    NOT NULL DEFAULT '',
        email              TEXT    NOT NULL DEFAULT '',
        login              TEXT    NOT NULL UNIQUE,
        password_hash      TEXT    NOT NULL DEFAULT '',
        is_active          INTEGER NOT NULL DEFAULT 1,
        notes              TEXT    NOT NULL DEFAULT '',
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login         DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_authp_student ON k30_ti_authorized_persons(student_account_id)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_authp_login  ON k30_ti_authorized_persons(login)");
    foreach ([
        "ALTER TABLE k30_ti_authorized_persons ADD COLUMN scan_path       TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_authorized_persons ADD COLUMN added_by_name   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_authorized_persons ADD COLUMN reason          TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_authorized_persons ADD COLUMN revoked_at      DATETIME",
        "ALTER TABLE k30_ti_authorized_persons ADD COLUMN revoked_by_name TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_authorized_persons ADD COLUMN revoke_scan_path TEXT NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // Migracja: planned lekcje bez żadnych kursantów → cancelled
    try {
        $pdo->exec(
            "UPDATE k30_ti_sessions SET status='cancelled', cancel_reason='Brak zapisanych kursantów'
             WHERE status='planned'
               AND NOT EXISTS (SELECT 1 FROM k30_ti_attendance a WHERE a.session_id=k30_ti_sessions.id)"
        );
    } catch (\Throwable $e) {}

    // Migracja statusu: held + (nieobecny beneficjent LUB kurs jednosobowy LUB podgrupa) → individual_change
    try {
        $pdo->exec(
            "UPDATE k30_ti_sessions SET status='individual_change'
             WHERE status='held'
               AND (
                   EXISTS (
                       SELECT 1 FROM k30_ti_attendance a
                       WHERE a.session_id=k30_ti_sessions.id
                         AND COALESCE(a.attended,0)=0
                         AND COALESCE(a.cancelled,0)=0
                   )
                   OR (
                       SELECT COUNT(*) FROM k30_ti_enrollments e
                       WHERE e.course_id=k30_ti_sessions.course_id AND e.status='active'
                   ) <= 1
                   OR EXISTS (
                       SELECT 1 FROM k30_ti_courses c
                       WHERE c.id=k30_ti_sessions.course_id AND COALESCE(c.is_subgroup,0)=1
                   )
               )"
        );
    } catch (\Throwable $e) {}

    // Wnioski o wypisanie z kursu (wymagane zatwierdzenie rodzica+admina dla małoletnich)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_unenroll_requests (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        enrollment_id INTEGER NOT NULL REFERENCES k30_ti_enrollments(id) ON DELETE CASCADE,
        client_id     INTEGER NOT NULL REFERENCES k30_clients(id)        ON DELETE CASCADE,
        course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id)     ON DELETE CASCADE,
        reason        TEXT    NOT NULL DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'pending_parent',
        parent_token  TEXT    UNIQUE,
        parent_ok_at  DATETIME,
        admin_id      INTEGER REFERENCES users(id),
        admin_ok_at   DATETIME,
        admin_note    TEXT    NOT NULL DEFAULT '',
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_unenroll_req_enroll ON k30_ti_unenroll_requests(enrollment_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_unenroll_req_client ON k30_ti_unenroll_requests(client_id)");

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

    foreach ([
        "ALTER TABLE k30_ti_tests          ADD COLUMN bank_draw        INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_tests          ADD COLUMN fixed_draw       INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_test_questions ADD COLUMN in_bank          INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_test_attempts  ADD COLUMN drawn_ids        TEXT",
        "ALTER TABLE k30_ti_tests          ADD COLUMN retake_pass_pct  INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_test_attempts  ADD COLUMN attempt_label    TEXT    NOT NULL DEFAULT ''",
    ] as $_sql) { try { $pdo->exec($_sql); } catch (\Throwable $e) {} }

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
    // Ważność okna (np. dostępność tylko na czas tury zapisów) + notatka.
    // Puste daty = okno bezterminowe — zachowanie dotychczasowe.
    foreach ([
        "ALTER TABLE k30_ti_instructor_availability ADD COLUMN valid_from DATE",
        "ALTER TABLE k30_ti_instructor_availability ADD COLUMN valid_to   DATE",
        "ALTER TABLE k30_ti_instructor_availability ADD COLUMN notes      TEXT NOT NULL DEFAULT ''",
    ] as $_q) { try { $pdo->exec($_q); } catch (\Throwable $e) {} }

    // ── Blokada wiadomości + archiwizacja ────────────────────────────────────
    try { $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN msg_blocked INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_ti_messages ADD COLUMN is_archived INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // Powiadomienia e-mail dla rodzica/opiekuna
    try { $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_notify_absence  INTEGER NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_notify_grade    INTEGER NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_notify_messages INTEGER NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}
    // SMS do opiekuna o zmianach/odwołaniach zajęć (niezależnie od opt-inu dziecka notify_sms_lessons)
    try { $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN parent_notify_lessons  INTEGER NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}

    // ── Dziennik zdarzeń na koncie kursanta ───────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_account_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id  INTEGER NOT NULL REFERENCES k30_ti_student_accounts(id) ON DELETE CASCADE,
        action      TEXT    NOT NULL DEFAULT '',
        detail      TEXT    NOT NULL DEFAULT '',
        by_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        by_name     TEXT    NOT NULL DEFAULT '',
        ip          TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_aclog_student ON k30_ti_account_log(student_id, created_at)");
    try { $pdo->exec("ALTER TABLE k30_ti_account_log ADD COLUMN user_agent TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // ── Komunikacja: log masowych wysyłek e-mail/SMS do kursantów ─────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_comm_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        channel      TEXT    NOT NULL DEFAULT '',   -- email | sms | email+sms
        filter_type  TEXT    NOT NULL DEFAULT '',   -- grupa | prowadzacy | dzien
        filter_label TEXT    NOT NULL DEFAULT '',
        subject      TEXT    NOT NULL DEFAULT '',
        body         TEXT    NOT NULL DEFAULT '',
        recipients   INTEGER NOT NULL DEFAULT 0,
        sent_ok      INTEGER NOT NULL DEFAULT 0,
        sent_fail    INTEGER NOT NULL DEFAULT 0,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_comm_log_created ON k30_ti_comm_log(created_at)");

    // Reguły zajęć stałych (cyklicznych) — wzorzec przechowywany w DB
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_series (
        id             INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        course_id      INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        time_from      TEXT    NOT NULL DEFAULT '',
        time_to        TEXT    NOT NULL DEFAULT '',
        interval_weeks INTEGER NOT NULL DEFAULT 1,
        date_from      TEXT    NOT NULL,
        date_to        TEXT    NOT NULL,
        topic          TEXT    NOT NULL DEFAULT '',
        created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { $pdo->exec("ALTER TABLE k30_ti_sessions ADD COLUMN series_id INTEGER"); } catch (\Throwable $e) {}

    // ── Status dostępności prowadzących (zatwierdzona / szkic) ────────────────
    try { $pdo->exec("ALTER TABLE k30_ti_instructor_availability ADD COLUMN status TEXT NOT NULL DEFAULT 'approved'"); } catch (\Throwable $e) {}

    // ── Tygodniowy plan zajęć cyklicznych (Planer IT) ─────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_weekly_plan (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        course_id       INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        day_of_week     INTEGER NOT NULL,
        time_from       TEXT    NOT NULL,
        duration_min    INTEGER NOT NULL DEFAULT 90,
        status          TEXT    NOT NULL DEFAULT 'draft',
        notes           TEXT    NOT NULL DEFAULT '',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_wplan_course_day ON k30_ti_weekly_plan(course_id, day_of_week)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_wplan_instr ON k30_ti_weekly_plan(instructor_id, day_of_week)");

    // Jednorazowe tokeny impersonacji dla paneli dydaktyk/kursant
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_imp_tokens (
        token      TEXT     NOT NULL PRIMARY KEY,
        type       TEXT     NOT NULL,
        target_id  INTEGER  NOT NULL,
        admin_id   INTEGER  NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL
    )");

    // Certyfikaty X.509 wystawiane kursantom przez EJBCA
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_certs (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id       INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id)    ON DELETE CASCADE,
        ejbca_username  TEXT    NOT NULL UNIQUE,
        fingerprint     TEXT    NOT NULL DEFAULT '',
        serial_hex      TEXT    NOT NULL DEFAULT '',
        valid_from      DATETIME,
        valid_to        DATETIME,
        issued_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at      DATETIME,
        revoked_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        notes           TEXT    NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_certs_course  ON k30_ti_certs(course_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_certs_client  ON k30_ti_certs(client_id)");

    // ── Audit log operacji Zoom (tworzenie/aktualizacja/usuwanie spotkań) ─────
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_zoom_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER  REFERENCES k30_ti_courses(id) ON DELETE SET NULL,
        action      TEXT     NOT NULL DEFAULT '',
        meeting_id  TEXT     NOT NULL DEFAULT '',
        detail      TEXT     NOT NULL DEFAULT '',
        status      TEXT     NOT NULL DEFAULT 'ok',
        created_by  INTEGER  REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_zoom_log_course ON k30_ti_zoom_log(course_id, created_at)");

    // E-mail prowadzącego zapisany w chwili tworzenia spotkania Zoom kursu
    try { $pdo->exec("ALTER TABLE k30_ti_courses ADD COLUMN zoom_host_email TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // Upewnij się że paid_amount istnieje zanim uruchomimy rekonstrukcję tabeli
    try { $pdo->exec("ALTER TABLE k30_ti_billing ADD COLUMN paid_amount REAL NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // Rekonstrukcja k30_ti_billing — dodanie course_id i zmiana UNIQUE na (client_id,month,year,course_id).
    // Istniejące wiersze dostają course_id=0 (rozliczenie łączne / sprzed rozdzielenia).
    $has_cid = db_one("SELECT 1 FROM pragma_table_info('k30_ti_billing') WHERE name='course_id'");
    if (!$has_cid) {
        try {
            $pdo->exec("PRAGMA foreign_keys=OFF");
            $pdo->exec("BEGIN");
            $pdo->exec("CREATE TABLE k30_ti_billing_v2 (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
                month           INTEGER NOT NULL,
                year            INTEGER NOT NULL,
                course_id       INTEGER NOT NULL DEFAULT 0,
                hours_billed    REAL    NOT NULL DEFAULT 0,
                hourly_rate     REAL    NOT NULL DEFAULT 0,
                amount          REAL    NOT NULL DEFAULT 0,
                status          TEXT    NOT NULL DEFAULT 'draft',
                notes           TEXT    NOT NULL DEFAULT '',
                issued_at       DATETIME,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                adjustment      REAL    NOT NULL DEFAULT 0,
                adjustment_note TEXT    NOT NULL DEFAULT '',
                notified_at     DATETIME,
                due_date        DATE,
                payer_type      TEXT    NOT NULL DEFAULT '',
                payer_name      TEXT    NOT NULL DEFAULT '',
                invoice_path    TEXT    NOT NULL DEFAULT '',
                invoice_name    TEXT    NOT NULL DEFAULT '',
                invoice_at      DATETIME,
                paid_amount     REAL    NOT NULL DEFAULT 0,
                UNIQUE(client_id, month, year, course_id)
            )");
            $pdo->exec("INSERT INTO k30_ti_billing_v2
                SELECT id, client_id, month, year, 0,
                       hours_billed, COALESCE(hourly_rate,0), amount, status, notes, issued_at, created_at,
                       COALESCE(adjustment,0), COALESCE(adjustment_note,''), notified_at, due_date,
                       COALESCE(payer_type,''), COALESCE(payer_name,''),
                       COALESCE(invoice_path,''), COALESCE(invoice_name,''), invoice_at,
                       COALESCE(paid_amount,0)
                FROM k30_ti_billing");
            $pdo->exec("DROP TABLE k30_ti_billing");
            $pdo->exec("ALTER TABLE k30_ti_billing_v2 RENAME TO k30_ti_billing");
            $pdo->exec("COMMIT");
            $pdo->exec("PRAGMA foreign_keys=ON");
        } catch (\Throwable $e) {
            try { $pdo->exec("ROLLBACK"); } catch (\Throwable $r) {}
            $pdo->exec("PRAGMA foreign_keys=ON");
        }
    }

    // Faktura jednorazowa wystawiana poza panelem (np. Comarch ERP Optima): rodzaj, numer,
    // data wystawienia i system źródłowy. Skan PDF (invoice_path) jest wymagany przy zapisie.
    // UWAGA: musi być PO rekonstrukcji k30_ti_billing powyżej — odtwarza ona tabelę ze stałej
    // listy kolumn i skasowałaby te dodane wcześniej.
    foreach ([
        "ALTER TABLE k30_ti_billing ADD COLUMN invoice_kind      TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_billing ADD COLUMN invoice_no        TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_billing ADD COLUMN invoice_system    TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_billing ADD COLUMN invoice_issued_on DATE",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // Jednorazowy reset: lekcje przyszłe błędnie oznaczone jako odbyte → zaplanowana
    try {
        $pdo->exec("UPDATE k30_ti_sessions SET status='planned'
                    WHERE status IN ('held','individual_change','remote_material')
                    AND lesson_date > date('now','localtime')");
    } catch (\Throwable $e) {}
}

// ── Impersonation helpers ─────────────────────────────────────────────────────

/**
 * Tworzy jednorazowy token impersonacji (ważny 30 s).
 * @param  string $type      'dyd' lub 'stu'
 * @param  int    $target_id users.id (dyd) lub k30_ti_student_accounts.id (stu)
 * @param  int    $admin_id  aktywny admin SZO
 * @return string token (hex 32)
 */
function k30_imp_token_create(string $type, int $target_id, int $admin_id): string {
    $token = bin2hex(random_bytes(32));
    db_insert('k30_imp_tokens', [
        'token'     => $token,
        'type'      => $type,
        'target_id' => $target_id,
        'admin_id'  => $admin_id,
        'expires_at' => date('Y-m-d H:i:s', time() + 30),
    ]);
    // Czyść stare tokeny przy okazji
    db_exec("DELETE FROM k30_imp_tokens WHERE expires_at < datetime('now')");
    return $token;
}

/**
 * Weryfikuje i konsumuje token. Zwraca ['type'=>..., 'target_id'=>..., 'admin_id'=>...] lub null.
 */
function k30_imp_token_consume(string $token): ?array {
    $row = db_one("SELECT * FROM k30_imp_tokens WHERE token=? AND expires_at >= datetime('now')", [$token]);
    if (!$row) return null;
    db_exec("DELETE FROM k30_imp_tokens WHERE token=?", [$token]);
    return $row;
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
    'enrolled'  => ['label' => 'Zapisany',    'color' => '#0176D3', 'bg' => '#EEF4FF',  'icon' => 'bi-person-plus'],
    'learning'  => ['label' => 'Nauka',       'color' => '#2E844A', 'bg' => '#EFF7ED',  'icon' => 'bi-mortarboard'],
    'graduated' => ['label' => 'Zakończył',   'color' => '#6D28D9', 'bg' => '#F5F3FF',  'icon' => 'bi-patch-check'],
    'resigned'  => ['label' => 'Rezygnacja',  'color' => '#D97706', 'bg' => '#FEF3E2',  'icon' => 'bi-door-open'],
    'expelled'  => ['label' => 'Skreślony',   'color' => '#DC2626', 'bg' => '#FEF2F2',  'icon' => 'bi-x-circle'],
    // Stare wartości — wyświetlane poprawnie dla istniejących rekordów, niedostępne w formularzu
    'ready'     => ['label' => 'Aktywny',        'color' => '#2E844A', 'bg' => '#EFF7ED',  'icon' => 'bi-check-circle', '_legacy' => true],
    'to_settle' => ['label' => 'Do rozliczenia', 'color' => '#D97706', 'bg' => '#FEF3E2',  'icon' => 'bi-clock',        '_legacy' => true],
    'other'     => ['label' => 'Inny',           'color' => '#9CA3AF', 'bg' => '#F3F4F6',  'icon' => 'bi-three-dots',   '_legacy' => true],
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
    // Samodzielne wejście do modułu: niezalogowany → własny ekran logowania
    // (gdy plik istnieje). W przeciwnym razie standardowe logowanie systemu.
    if (!current_user()) {
        $loginFile = dirname(__DIR__) . '/karty30/login.php';
        if (is_file($loginFile)) {
            $uri  = $_SERVER['REQUEST_URI'] ?? '';
            $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
            if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }
            header('Location: ' . APP_URL . '/karty30/login.php?redirect=' . urlencode(APP_URL . $uri));
            exit;
        }
    }
    require_login();
    if (can_read('karty30') || is_admin()) return;

    // Doradcy K30 mają dostęp przez flagę k30_consultant w tabeli users
    $user = current_user();
    try {
        $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)($user['id'] ?? 0)]);
        if (!empty($row['k30_consultant'])) return;
    } catch (\Throwable $e) {}

    flash_set('danger', 'Brak dostępu do modułu Dydaktyka 3.');
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

// ── Rejestr dostępu do danych wrażliwych (RODO / PFRON) ──────────────────────
// Loguje wgląd/edycję/wydruk kart beneficjentów, konsultacji i wizyt.
// Przechowuje KTO, CO i KIEDY — bez kopiowania samych danych wrażliwych.
function k30_access_log_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_access_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER,
            user_name    TEXT,
            entity_type  TEXT,
            entity_id    INTEGER,
            entity_label TEXT,
            action       TEXT,
            ip           TEXT,
            created_at   TEXT DEFAULT (datetime('now','localtime'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_k30_access_entity  ON k30_access_log(entity_type, entity_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_k30_access_created ON k30_access_log(created_at)");
    } catch (\Throwable $e) {}
}

/**
 * Zapisz dostęp do danych wrażliwych.
 * @param string $entity_type  client | consultation | schedule
 * @param int    $entity_id
 * @param string $action       view | edit | print | export
 * @param string $label        czytelna etykieta (np. imię i nazwisko) — skracana
 */
function k30_log_access(string $entity_type, int $entity_id, string $action = 'view', string $label = ''): void {
    k30_access_log_migrate();
    try {
        $u = function_exists('current_user') ? current_user() : null;
        db()->prepare(
            "INSERT INTO k30_access_log (user_id, user_name, entity_type, entity_id, entity_label, action, ip)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            (int)($u['id'] ?? 0) ?: null,
            $u['name'] ?? null,
            $entity_type,
            $entity_id ?: null,
            $label !== '' ? mb_substr($label, 0, 120, 'UTF-8') : null,
            $action,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (\Throwable $e) {}
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
            'description' => 'Beneficjenci programu TyfloKonsultacje / Dydaktyka 3 — dodawani automatycznie',
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

/**
 * Generuje kolejny numer dokumentu umowy PFRON w formacie PFRON-AS/xx/yyyy.
 * Blokuje wiersz i liczy istniejące numery w danym roku, żeby uniknąć duplikatów.
 */
function k30_pfron_next_doc_number(int $year = 0): string {
    if (!$year) $year = (int)date('Y');
    $prefix = "PFRON-AS/%/$year";
    $row = db_one(
        "SELECT COUNT(*) AS cnt FROM k30_pfron_contracts
         WHERE doc_number LIKE ? AND doc_number != ''",
        ["PFRON-AS/%/$year"]
    );
    $seq = (int)($row['cnt'] ?? 0) + 1;
    return sprintf('PFRON-AS/%02d/%d', $seq, $year);
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
    'draft'             => ['label'=>'Szkic (SZO Planner)',                           'color'=>'#9CA3AF', 'bg'=>'#F9FAFB'],
    'planned'           => ['label'=>'Zaplanowana',                                   'color'=>'#F59E0B', 'bg'=>'#FFFBEB'],
    'held'              => ['label'=>'Odbyła się',                                    'color'=>'#16A34A', 'bg'=>'#F0FDF4'],
    'individual_change' => ['label'=>'Zajęcia indywidualne',                          'color'=>'#7C3AED', 'bg'=>'#F5F3FF'],
    'remote_material'   => ['label'=>'Praca prowadzącego (materiał zdalny)',   'color'=>'#0694A2', 'bg'=>'#CCFBF1'],
    'cancelled'         => ['label'=>'Odwołana',                                      'color'=>'#DC2626', 'bg'=>'#FEF2F2'],
];

/** Statusy lekcji liczone jako „odbyła się" (do rozliczeń i wypłat). */
const K30_TI_HELD_STATUSES = ['held', 'individual_change', 'remote_material'];

/**
 * Statusy liczone do FREKWENCJI (obecność / nieobecność kursantów).
 * UWAGA: „Praca własna prowadzącego" (remote_material) jest CELOWO wykluczona —
 * to zdalna praca prowadzącego, nie zajęcia z listą obecności, więc nie liczymy
 * ani obecności, ani nieobecności. (Rozliczenia/wypłaty pozostają wg K30_TI_HELD_STATUSES.)
 */
const K30_TI_ATTENDANCE_STATUSES = ['held', 'individual_change'];

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

/**
 * Domyślne stawki potrąceń od wynagrodzenia prowadzącego (konfigurowalne w
 * ustawieniach — Zajęcia TI). Klucze tabeli settings → wartość % (string).
 */
const K30_TI_PAYOUT_DEFAULTS = [
    'ti_payout_zus_employer_pct' => '20.48', // składki finansowane przez płatnika (od brutto) — część brutto-brutto
    'ti_payout_zus_employee_pct' => '13.71', // składki społeczne zleceniobiorcy (od brutto)
    'ti_payout_health_pct'       => '9.00',  // składka zdrowotna (od podstawy = brutto − społeczne pracownika)
    'ti_payout_kup_pct'          => '20.00', // koszty uzyskania przychodu (od podstawy j.w.)
    'ti_payout_pit_pct'          => '12.00', // zaliczka na podatek dochodowy
];

/** Odczyt stawki potrącenia (%) z ustawień, z fallbackiem do wartości domyślnej. */
function k30_ti_payout_rate(string $key): float {
    $def = K30_TI_PAYOUT_DEFAULTS[$key] ?? '0';
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $v = ($r && $r['value'] !== '' && $r['value'] !== null) ? $r['value'] : $def;
    } catch (\Throwable $e) { $v = $def; }
    return (float)str_replace(',', '.', (string)$v);
}

/**
 * Rozbicie wynagrodzenia za lekcję z kwoty BRUTTO-BRUTTO na netto „na rękę".
 * brutto-brutto = brutto + składki płatnika; od brutto odliczane są składki
 * społeczne i zdrowotna pracownika oraz zaliczka PIT (z uwzględnieniem KUP).
 * Zwraca komplet składowych (wszystkie kwoty zaokrąglone do groszy).
 */
function k30_ti_payout_breakdown(float $bb, bool $is_student = false): array {
    $bb = max(0.0, $bb);
    $r  = fn($x) => round($x, 2);

    // Prowadzący-student: brak ZUS/PIT, BB = netto
    if ($is_student) {
        return [
            'brutto_brutto' => $r($bb),
            'zus_employer'  => 0.0,
            'brutto'        => $r($bb),
            'zus_employee'  => 0.0,
            'health'        => 0.0,
            'skladki'       => 0.0,
            'pit'           => 0.0,
            'netto'         => $r($bb),
        ];
    }

    $emp_pct    = k30_ti_payout_rate('ti_payout_zus_employer_pct');
    $ee_pct     = k30_ti_payout_rate('ti_payout_zus_employee_pct');
    $health_pct = k30_ti_payout_rate('ti_payout_health_pct');
    $kup_pct    = k30_ti_payout_rate('ti_payout_kup_pct');
    $pit_pct    = k30_ti_payout_rate('ti_payout_pit_pct');

    $brutto       = $emp_pct > 0 ? $bb / (1 + $emp_pct / 100) : $bb;
    $zus_employer = $bb - $brutto;
    $zus_employee = $brutto * $ee_pct / 100;
    $base         = max(0.0, $brutto - $zus_employee);   // podstawa zdrowotnej i KUP
    $health       = $base * $health_pct / 100;
    $kup          = $base * $kup_pct / 100;
    $pit_base     = max(0.0, $base - $kup);
    $pit          = round($pit_base * $pit_pct / 100);   // zaliczka PIT — w pełnych złotych
    $skladki      = $zus_employee + $health;             // potrącone pracownikowi
    $netto        = max(0.0, $brutto - $zus_employee - $health - $pit);

    return [
        'brutto_brutto' => $r($bb),
        'zus_employer'  => $r($zus_employer),
        'brutto'        => $r($brutto),
        'zus_employee'  => $r($zus_employee),
        'health'        => $r($health),
        'skladki'       => $r($skladki),
        'pit'           => $r($pit),
        'netto'         => $r($netto),
    ];
}

/** Pusty agregat wypłat (do sumowania po lekcjach). */
function _k30_ti_payout_zero(): array {
    return ['lessons'=>0,'brutto_brutto'=>0.0,'zus_employer'=>0.0,'brutto'=>0.0,'skladki'=>0.0,'pit'=>0.0,'netto'=>0.0];
}

/** Dodaj rozbicie pojedynczej lekcji do agregatu (PIT zaokrąglany per lekcja → sumy dokładne). */
function _k30_ti_payout_accumulate(array &$acc, array $b): void {
    $acc['lessons']++;
    $acc['brutto_brutto'] += $b['brutto_brutto'];
    $acc['zus_employer']  += $b['zus_employer'];
    $acc['brutto']        += $b['brutto'];
    $acc['skladki']       += $b['skladki'];
    $acc['pit']           += $b['pit'];
    $acc['netto']         += $b['netto'];
}

/**
 * Miesięczne sumy wypłat per prowadzący. $ym = 'YYYY-MM'.
 * Liczy tylko lekcje odbyte (status='held') z kursów o stawce > 0.
 * Zwraca wiersze posortowane wg nazwiska, z agregatem składowych + listą kursów.
 */
function k30_ti_payouts_by_instructor(string $ym): array {
    $rows = db_all(
        "SELECT c.instructor_id AS iid, COALESCE(u.name,'(brak prowadzącego)') AS iname,
                COALESCE(u.ti_payout_form, CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS payout_form,
                COALESCE(s.self_prep_remote, 0) AS self_prep_remote,
                c.id AS course_id, c.name AS course_name, c.lesson_payout_bb AS bb
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id = s.course_id
         LEFT JOIN users u ON u.id = c.instructor_id
         WHERE s.status IN ('held','individual_change','remote_material') AND c.lesson_payout_bb > 0
           AND strftime('%Y-%m', s.lesson_date) = ?
         ORDER BY iname, c.name",
        [$ym]
    );
    $by = [];
    foreach ($rows as $r) {
        $iid = $r['iid'] !== null ? (int)$r['iid'] : 0;
        if (!isset($by[$iid])) {
            $by[$iid] = _k30_ti_payout_zero();
            $by[$iid]['instructor_id'] = $iid;
            $by[$iid]['name']         = $r['iname'];
            $by[$iid]['payout_form']  = $r['payout_form'];
            $by[$iid]['courses']      = [];
        }
        // Forma B2B/student → 100% bez ZUS/US; self_prep_remote też jest bezskladkowe
        $form_exempt = in_array($r['payout_form'], ['student','b2b'], true);
        $eff_student = $form_exempt || (bool)$r['self_prep_remote'];
        $b = k30_ti_payout_breakdown((float)$r['bb'], $eff_student);
        _k30_ti_payout_accumulate($by[$iid], $b);
        $cid = (int)$r['course_id'];
        if (!isset($by[$iid]['courses'][$cid])) {
            $by[$iid]['courses'][$cid] = _k30_ti_payout_zero();
            $by[$iid]['courses'][$cid]['name'] = $r['course_name'];
        }
        _k30_ti_payout_accumulate($by[$iid]['courses'][$cid], $b);
    }
    return array_values($by);
}

/** Miesięczna suma wypłat dla jednego kursu (status='held'). $ym = 'YYYY-MM'. */
function k30_ti_payout_month_for_course(int $course_id, string $ym): array {
    $course = db_one(
        "SELECT c.lesson_payout_bb,
                COALESCE(u.ti_payout_form, CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS payout_form
         FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id
         WHERE c.id=?",
        [$course_id]
    );
    $bb = (float)($course['lesson_payout_bb'] ?? 0);
    $acc = _k30_ti_payout_zero();
    if ($bb <= 0) return $acc;
    $form_exempt = in_array($course['payout_form'] ?? 'zlecenie', ['student','b2b'], true);
    // Lekcje regularne i praca własna (self_prep_remote) rozliczane osobno
    $counts = db_all(
        "SELECT COALESCE(self_prep_remote,0) AS spr, COUNT(*) AS n
         FROM k30_ti_sessions
         WHERE course_id=? AND status IN ('held','individual_change','remote_material') AND strftime('%Y-%m', lesson_date)=?
         GROUP BY spr",
        [$course_id, $ym]
    );
    foreach ($counts as $row) {
        $eff_student = $form_exempt || (bool)$row['spr'];
        $b = k30_ti_payout_breakdown($bb, $eff_student);
        for ($i = 0; $i < (int)$row['n']; $i++) _k30_ti_payout_accumulate($acc, $b);
    }
    return $acc;
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
                st.abbreviation AS subject_abbr, st.name AS subject_name,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS enrolled_count
         FROM k30_ti_courses c
         LEFT JOIN users u ON u.id=c.instructor_id
         LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id
         $w ORDER BY c.name",
    );
}

function k30_ti_course_get(int $id): ?array {
    return db_one(
        "SELECT c.*, u.name AS instructor_name,
                st.abbreviation AS subject_abbr, st.name AS subject_name,
                st.requires_certificate
         FROM k30_ti_courses c
         LEFT JOIN users u ON u.id=c.instructor_id
         LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id
         WHERE c.id=?", [$id]
    ) ?: null;
}

function k30_ti_subject_types(bool $active_only = true): array {
    $w = $active_only ? 'WHERE is_active=1' : '';
    return db_all("SELECT * FROM k30_ti_subject_types $w ORDER BY sort_order, abbreviation");
}

function k30_ti_generate_group_code(): string {
    return str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT) . date('y');
}

/**
 * Generuje certyfikat X.509 (PKCS#12) dla kursanta — bez EJBCA, przez OpenSSL.
 * Próbuje podpisać przez CA aplikacji (certs/app.crt + app.key);
 * jeśli brak — generuje self-signed.
 *
 * @return array ['p12_data'=>string, 'p12_pass'=>string, 'fingerprint'=>string,
 *               'serial_hex'=>string, 'valid_from'=>string, 'valid_to'=>string,
 *               'cert_pem'=>string, 'key_pem'=>string, 'ca'=>'app'|'self']
 */
function k30_ti_issue_cert(string $cn, string $org = 'TI', string $country = 'PL', int $days = 730): array {
    if (!extension_loaded('openssl')) throw new \RuntimeException('Rozszerzenie openssl jest wymagane.');

    $cn  = substr(preg_replace('/[^\p{L}0-9 \-]/u', '', $cn), 0, 64);
    $org = substr(preg_replace('/[^\p{L}0-9 \-]/u', '', $org), 0, 64);

    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'encrypt_key' => false]);
    if (!$pkey) throw new \RuntimeException('Generowanie klucza: ' . openssl_error_string());

    $dn  = array_filter(['C' => $country, 'O' => $org, 'CN' => $cn]);
    $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
    if (!$csr) throw new \RuntimeException('CSR: ' . openssl_error_string());

    $serial = random_int(1, PHP_INT_MAX);

    // Spróbuj podpisać przez CA aplikacji
    require_once __DIR__ . '/app_cert.php';
    $ca_paths  = app_cert_paths();
    $ca_cert   = null; $ca_key = null; $ca_label = 'self';
    if (is_file($ca_paths['crt']) && is_file($ca_paths['key'])) {
        $ca_cert = openssl_x509_read(file_get_contents($ca_paths['crt']));
        $ca_key  = openssl_pkey_get_private(file_get_contents($ca_paths['key']));
        if ($ca_cert && $ca_key) $ca_label = 'app';
    }

    $cert = openssl_csr_sign($csr, $ca_cert ?: null, $ca_key ?: $pkey, $days, ['digest_alg' => 'sha256'], $serial);
    if (!$cert) throw new \RuntimeException('Podpisywanie certyfikatu: ' . openssl_error_string());

    $cert_pem = ''; $key_pem = '';
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($pkey, $key_pem);
    if ($cert_pem === '') throw new \RuntimeException('Eksport PEM certyfikatu nie powiódł się.');

    $parsed  = openssl_x509_parse($cert_pem);
    $fp      = openssl_x509_fingerprint($cert, 'sha256');
    $ser_hex = strtoupper($parsed['serialNumberHex'] ?? dechex($serial));

    $p12_pass = bin2hex(random_bytes(8));
    $p12_data = '';
    $extra    = ($ca_label === 'app') ? ['extracerts' => [$ca_cert]] : [];
    if (!openssl_pkcs12_export($cert, $p12_data, $pkey, $p12_pass, $extra)) {
        throw new \RuntimeException('Eksport PKCS#12: ' . openssl_error_string());
    }

    return [
        'p12_data'   => $p12_data,
        'p12_pass'   => $p12_pass,
        'fingerprint'=> $fp,
        'serial_hex' => $ser_hex,
        'valid_from' => date('Y-m-d H:i:s', (int)($parsed['validFrom_time_t'] ?? time())),
        'valid_to'   => date('Y-m-d H:i:s', (int)($parsed['validTo_time_t'] ?? time() + $days * 86400)),
        'cert_pem'   => $cert_pem,
        'key_pem'    => $key_pem,
        'ca'         => $ca_label,
    ];
}

// Zapisy
function k30_ti_enrollments(int $course_id): array {
    return db_all(
        "SELECT e.*, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone,
                cl.ti_grades_enabled AS client_grades_enabled
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

// ── Wnioski wypisania z kursu (małoletni: wymagana zgoda rodzica + admina) ──

/**
 * Złóż wniosek o wypisanie z kursu.
 * Dla małoletnich: status=pending_parent, generuje token dla rodzica, wysyła e-mail.
 * Dla pełnoletnich: od razu wykonuje wypisanie i wysyła potwierdzenie.
 * Zwraca 'done' (wykonano) lub 'pending' (czeka na zatwierdzenie).
 */
function k30_ti_unenroll_request(int $enrollment_id, int $client_id, string $reason): string {
    $enroll = db_one(
        "SELECT e.*, c.name AS course_name, c.id AS course_id,
                u.name AS instructor_name, u.email AS instructor_email,
                cl.name AS client_name, cl.email AS client_email,
                acc.is_minor, acc.guardian_email, acc.guardian_name
         FROM k30_ti_enrollments e
         JOIN k30_ti_courses c   ON c.id=e.course_id
         JOIN k30_clients cl     ON cl.id=e.client_id
         LEFT JOIN users u       ON u.id=c.instructor_id
         LEFT JOIN k30_ti_student_accounts acc ON acc.client_id=e.client_id
         WHERE e.id=? AND e.client_id=? AND e.status='active'",
        [$enrollment_id, $client_id]
    );
    if (!$enroll) return 'error';

    $is_minor    = !empty($enroll['is_minor']);
    $org         = defined('ORG_NAME') ? ORG_NAME : 'FEER';
    $app_url     = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $stu_name    = $enroll['client_name'];
    $course_name = $enroll['course_name'];

    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';

    if (!$is_minor) {
        // Pełnoletni — wypisz od razu
        k30_ti_unenroll_execute($enrollment_id, $client_id, $reason, null, null);
        return 'done';
    }

    // Małoletni — utwórz wniosek z tokenem dla rodzica
    // Usuń stary wniosek pending jeśli istnieje
    db()->prepare("DELETE FROM k30_ti_unenroll_requests WHERE enrollment_id=? AND status IN ('pending_parent','pending_admin')")->execute([$enrollment_id]);

    $token = bin2hex(random_bytes(24));
    db_insert('k30_ti_unenroll_requests', [
        'enrollment_id' => $enrollment_id,
        'client_id'     => $client_id,
        'course_id'     => (int)$enroll['course_id'],
        'reason'        => $reason,
        'status'        => 'pending_parent',
        'parent_token'  => $token,
    ]);

    // E-mail do opiekuna
    $guardian_email = trim((string)($enroll['guardian_email'] ?? ''));
    $guardian_name  = trim((string)($enroll['guardian_name'] ?? '')) ?: 'Opiekunie';
    $approve_url    = $app_url . '/karty30/ti/kursant/unenroll_parent.php?token=' . urlencode($token);

    if ($guardian_email !== '' && function_exists('mail_queue_add')) {
        mail_queue_add($guardian_email, $guardian_name,
            "Wniosek o wypisanie z kursu: {$course_name}",
            "<p>Drogi/a {$guardian_name},</p>"
            . "<p>Kursant <strong>" . htmlspecialchars($stu_name, ENT_QUOTES) . "</strong> złożył(a) wniosek o wypisanie z kursu <strong>" . htmlspecialchars($course_name, ENT_QUOTES) . "</strong>.</p>"
            . ($reason !== '' ? "<p><em>Podany powód:</em> " . nl2br(htmlspecialchars($reason, ENT_QUOTES)) . "</p>" : '')
            . "<p>Jako opiekun prawny musisz zatwierdzić tę decyzję, klikając poniższy link:</p>"
            . "<p><a href=\"{$approve_url}\">{$approve_url}</a></p>"
            . "<p>Jeśli nie wyrażasz zgody, możesz zignorować tę wiadomość — wniosek wygaśnie bez skutku.</p>"
            . "<p>Pozdrawiamy,<br>" . htmlspecialchars($org, ENT_QUOTES) . "</p>"
        );
    }

    return 'pending';
}

/**
 * Zatwierdź wniosek przez rodzica (via token z e-maila).
 * Zmienia status na pending_admin i powiadamia adminów.
 * Zwraca tablicę ['ok'=>bool, 'msg'=>string] lub null gdy nie znaleziono tokenu.
 */
function k30_ti_unenroll_parent_approve(string $token): ?array {
    $req = db_one(
        "SELECT r.*, c.name AS course_name, cl.name AS client_name,
                acc.guardian_name
         FROM k30_ti_unenroll_requests r
         JOIN k30_ti_courses c  ON c.id=r.course_id
         JOIN k30_clients cl    ON cl.id=r.client_id
         LEFT JOIN k30_ti_student_accounts acc ON acc.client_id=r.client_id
         WHERE r.parent_token=?",
        [$token]
    );
    if (!$req) return null;
    if ($req['status'] !== 'pending_parent') {
        return ['ok' => false, 'msg' => $req['status'] === 'approved' ? 'Wniosek został już zatwierdzony.' : 'Wniosek nie jest już aktywny.'];
    }

    db()->prepare(
        "UPDATE k30_ti_unenroll_requests SET status='pending_admin', parent_ok_at=datetime('now'), updated_at=datetime('now') WHERE id=?"
    )->execute([(int)$req['id']]);

    // Powiadom adminów
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    $org     = defined('ORG_NAME') ? ORG_NAME : 'FEER';
    $app_url = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $admins  = db_all("SELECT email, name FROM users WHERE role IN ('admin','super') AND is_active=1");
    $admin_url = $app_url . '/karty30/ti/unenroll_admin.php';
    foreach ($admins as $adm) {
        $adm_email = trim((string)($adm['email'] ?? ''));
        if ($adm_email === '' || !function_exists('mail_queue_add')) continue;
        mail_queue_add($adm_email, $adm['name'] ?? '',
            "[TI] Wniosek wypisania czeka na zatwierdzenie: {$req['course_name']}",
            "<p>Opiekun prawny zatwierdził wniosek wypisania kursanta <strong>"
            . htmlspecialchars($req['client_name'], ENT_QUOTES) . "</strong> z kursu <strong>"
            . htmlspecialchars($req['course_name'], ENT_QUOTES) . "</strong>.</p>"
            . "<p>Przejdź do panelu administracyjnego, aby zatwierdzić lub odrzucić wniosek:<br>"
            . "<a href=\"{$admin_url}\">{$admin_url}</a></p>"
            . "<p>Pozdrawiamy,<br>" . htmlspecialchars($org, ENT_QUOTES) . "</p>"
        );
    }

    return ['ok' => true, 'msg' => 'Dziękujemy. Wniosek został przekazany do administratora.'];
}

/**
 * Admin zatwierdza lub odrzuca wniosek wypisania małoletniego.
 * $approve=true → wypisuje kursanta; $approve=false → odrzuca wniosek.
 */
function k30_ti_unenroll_admin_decide(int $req_id, int $admin_user_id, bool $approve, string $note = ''): bool {
    $req = db_one(
        "SELECT r.*, c.name AS course_name, cl.name AS client_name, cl.email AS client_email,
                acc.guardian_email, acc.guardian_name
         FROM k30_ti_unenroll_requests r
         JOIN k30_ti_courses c  ON c.id=r.course_id
         JOIN k30_clients cl    ON cl.id=r.client_id
         LEFT JOIN k30_ti_student_accounts acc ON acc.client_id=r.client_id
         WHERE r.id=? AND r.status IN ('pending_parent','pending_admin')",
        [$req_id]
    );
    if (!$req) return false;

    if ($approve) {
        k30_ti_unenroll_execute((int)$req['enrollment_id'], (int)$req['client_id'], $req['reason'], $admin_user_id, $note);
        db()->prepare(
            "UPDATE k30_ti_unenroll_requests SET status='approved', admin_id=?, admin_ok_at=datetime('now'), admin_note=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$admin_user_id, $note, $req_id]);
    } else {
        db()->prepare(
            "UPDATE k30_ti_unenroll_requests SET status='rejected', admin_id=?, admin_ok_at=datetime('now'), admin_note=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$admin_user_id, $note, $req_id]);
        // Powiadom kursanta o odrzuceniu
        if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
        $org = defined('ORG_NAME') ? ORG_NAME : 'FEER';
        $email = trim((string)($req['client_email'] ?? ''));
        if ($email !== '' && function_exists('mail_queue_add')) {
            mail_queue_add($email, $req['client_name'],
                "Wniosek wypisania z kursu odrzucony: {$req['course_name']}",
                "<p>Drogi/a " . htmlspecialchars($req['client_name'], ENT_QUOTES) . ",</p>"
                . "<p>Wniosek o wypisanie z kursu <strong>" . htmlspecialchars($req['course_name'], ENT_QUOTES) . "</strong> został odrzucony przez administratora.</p>"
                . ($note !== '' ? "<p><em>Powód:</em> " . nl2br(htmlspecialchars($note, ENT_QUOTES)) . "</p>" : '')
                . "<p>Pozostajesz zapisany/a na kurs. W razie pytań skontaktuj się z administracją.</p>"
                . "<p>Pozdrawiamy,<br>" . htmlspecialchars($org, ENT_QUOTES) . "</p>"
            );
        }
    }
    return true;
}

/**
 * Wykonaj wypisanie kursanta z kursu (finalna operacja wspólna).
 * $admin_user_id — ID admina gdy admin wymusza; null gdy automatycznie.
 */
function k30_ti_unenroll_execute(int $enrollment_id, int $client_id, string $reason, ?int $admin_user_id, ?string $admin_note): void {
    $enroll = db_one(
        "SELECT e.*, c.name AS course_name, u.name AS instructor_name, u.email AS instructor_email,
                cl.name AS client_name, cl.email AS client_email,
                acc.guardian_email, acc.guardian_name
         FROM k30_ti_enrollments e
         JOIN k30_ti_courses c   ON c.id=e.course_id
         JOIN k30_clients cl     ON cl.id=e.client_id
         LEFT JOIN users u       ON u.id=c.instructor_id
         LEFT JOIN k30_ti_student_accounts acc ON acc.client_id=e.client_id
         WHERE e.id=? AND e.client_id=?",
        [$enrollment_id, $client_id]
    );
    if (!$enroll) return;

    $note_suffix = $reason !== '' ? "\nWypisanie: " . $reason : "\nWypisanie z kursu.";
    if ($admin_user_id) $note_suffix .= " [admin ID {$admin_user_id}]";
    db()->prepare(
        "UPDATE k30_ti_enrollments SET status='inactive', notes=? WHERE id=?"
    )->execute([(($enroll['notes'] ?? '') . $note_suffix), $enrollment_id]);

    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;

    $org         = defined('ORG_NAME') ? ORG_NAME : 'FEER';
    $stu_email   = trim((string)($enroll['client_email'] ?? ''));
    $stu_name    = $enroll['client_name'];
    $course_name = $enroll['course_name'];
    $reason_html = $reason !== '' ? '<p><em>Podany powód:</em> ' . nl2br(htmlspecialchars($reason, ENT_QUOTES)) . '</p>' : '';

    // E-mail do kursanta
    if ($stu_email !== '') {
        mail_queue_add($stu_email, $stu_name,
            "Potwierdzenie wypisania z kursu: {$course_name}",
            "<p>Drogi/a " . htmlspecialchars($stu_name, ENT_QUOTES) . ",</p>"
            . "<p>Potwierdzamy wypisanie z kursu <strong>" . htmlspecialchars($course_name, ENT_QUOTES) . "</strong>.</p>"
            . $reason_html
            . ($admin_note ? '<p><em>Uwaga admina:</em> ' . nl2br(htmlspecialchars($admin_note, ENT_QUOTES)) . '</p>' : '')
            . "<p>Jeśli to pomyłka, skontaktuj się z administracją.</p>"
            . "<p>Pozdrawiamy,<br>" . htmlspecialchars($org, ENT_QUOTES) . "</p>"
        );
    }
    // E-mail do opiekuna (jeśli podany)
    $guardian_email = trim((string)($enroll['guardian_email'] ?? ''));
    $guardian_name  = trim((string)($enroll['guardian_name'] ?? ''));
    if ($guardian_email !== '' && $guardian_email !== $stu_email) {
        mail_queue_add($guardian_email, $guardian_name ?: $stu_name,
            "Potwierdzenie wypisania z kursu: {$course_name}",
            "<p>Drogi/a " . htmlspecialchars($guardian_name ?: $stu_name, ENT_QUOTES) . ",</p>"
            . "<p>Kursant <strong>" . htmlspecialchars($stu_name, ENT_QUOTES) . "</strong> został wypisany z kursu <strong>" . htmlspecialchars($course_name, ENT_QUOTES) . "</strong>.</p>"
            . $reason_html
            . "<p>Pozdrawiamy,<br>" . htmlspecialchars($org, ENT_QUOTES) . "</p>"
        );
    }
    // E-mail do prowadzącego
    $instr_email = trim((string)($enroll['instructor_email'] ?? ''));
    if ($instr_email !== '') {
        mail_queue_add($instr_email, $enroll['instructor_name'] ?? '',
            "[TI] Kursant wypisał się z kursu: {$course_name}",
            "<p>Kursant <strong>" . htmlspecialchars($stu_name, ENT_QUOTES) . "</strong> wypisał się z kursu <strong>" . htmlspecialchars($course_name, ENT_QUOTES) . "</strong>.</p>"
            . $reason_html
        );
    }
}

/** Lista wniosków wypisania oczekujących na decyzję admina. */
function k30_ti_unenroll_pending_admin(): array {
    return db_all(
        "SELECT r.*, c.name AS course_name, cl.name AS client_name
         FROM k30_ti_unenroll_requests r
         JOIN k30_ti_courses c ON c.id=r.course_id
         JOIN k30_clients cl   ON cl.id=r.client_id
         WHERE r.status IN ('pending_parent','pending_admin')
         ORDER BY r.created_at ASC"
    );
}

/** Lista wszystkich wniosków wypisania (historia). */
function k30_ti_unenroll_requests_all(): array {
    return db_all(
        "SELECT r.*, c.name AS course_name, cl.name AS client_name,
                u.name AS admin_name
         FROM k30_ti_unenroll_requests r
         JOIN k30_ti_courses c  ON c.id=r.course_id
         JOIN k30_clients cl    ON cl.id=r.client_id
         LEFT JOIN users u      ON u.id=r.admin_id
         ORDER BY r.created_at DESC
         LIMIT 200"
    );
}

/** Wystawione/robocze rozliczenia kursanta (do widoku kursanta i rodzica). */
function k30_ti_client_billing(int $client_id): array {
    try {
        return db_all(
            "SELECT b.*, COALESCE(c.name,'') AS course_name
             FROM k30_ti_billing b
             LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
             WHERE b.client_id=? AND b.status!='cancelled'
             ORDER BY b.year DESC, b.month DESC, b.course_id ASC",
            [$client_id]
        );
    } catch (\Throwable $e) {
        return db_all(
            "SELECT *, '' AS course_name FROM k30_ti_billing
             WHERE client_id=? AND status!='cancelled'
             ORDER BY year DESC, month DESC",
            [$client_id]
        );
    }
}

/** Ostatnie lekcje kursanta z obecnością (współdzielone: panel kursanta + rodzica). */
function k30_ti_client_lessons(int $client_id, int $limit = 40): array {
    // $extra: kolumna track_attendance; $enroll: kolumna zoom_meeting_url.
    // Osobne fallbacki, bo obie mogą brakować przed migracją.
    $build = fn(string $extra, string $enroll = 'e.zoom_meeting_url AS enrollment_meeting_url,') =>
        "SELECT s.*, c.name AS course_name, c.default_meeting_url AS course_meeting_url,
                {$enroll}
                {$extra}
                a.attended, a.ind_notes,
                a.cancelled AS att_cancelled, a.cancel_pending AS att_cancel_pending,
                a.cancel_reason AS att_cancel_reason,
                a.cancelled_by_role AS att_cancelled_by_role,
                a.no_show AS att_no_show, a.no_show_billing AS att_no_show_billing,
                r.rating AS my_rating, r.comment AS my_comment
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=?
         LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
         LEFT JOIN k30_ti_lesson_ratings r ON r.session_id=s.id AND r.client_id=?
         WHERE s.course_id IN (
             SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active'
         )
         ORDER BY s.lesson_date DESC, s.time_from DESC
         LIMIT " . max(1, $limit);
    $p = [$client_id, $client_id, $client_id, $client_id];
    try {
        return db_all($build("c.track_attendance AS course_track_attendance,"), $p);
    } catch (\Throwable $e) {
        try {
            return db_all($build(""), $p);
        } catch (\Throwable $e2) {
            // Kolumna zoom_meeting_url jeszcze nie istnieje — zwróć pusty literal
            return db_all($build("", "'' AS enrollment_meeting_url,"), $p);
        }
    }
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
 * Wysyła SMS o zajęciach do aktywnych kursantów grupy. Numer kursanta trafia na listę,
 * gdy kursant WŁĄCZYŁ powiadomienia (notify_sms_lessons=1); dla małoletnich SMS idzie
 * dodatkowo na numer opiekuna (guardian_phone), gdy opiekun ma to włączone
 * (parent_notify_lessons=1) — niezależnie od opt-inu dziecka. Zwraca liczbę wysłanych.
 * Bezpieczne, gdy SMS wyłączony lub brak odbiorców (zwraca 0).
 */
function ti_lesson_sms_notify(int $courseId, string $message): int {
    require_once __DIR__ . '/sms.php';
    if (!function_exists('sms_channel_ready') || !sms_channel_ready()) return 0;
    $rows = db_all(
        "SELECT cl.phone, a.notify_phone2, a.notify_phone2_verified, a.notify_phone3, a.notify_phone3_verified,
                a.is_minor, a.guardian_phone, a.notify_sms_lessons, a.parent_notify_lessons
         FROM k30_ti_enrollments e
         JOIN k30_ti_student_accounts a ON a.client_id = e.client_id AND a.is_active = 1
         JOIN k30_clients cl ON cl.id = e.client_id
         WHERE e.course_id = ? AND e.status = 'active'",
        [$courseId]
    );
    $sent = 0;
    foreach ($rows as $r) {
        $nums = [];
        // Numery kursanta — tylko gdy kursant sam włączył SMS o lekcjach.
        if (!empty($r['notify_sms_lessons'])) $nums = k30_ti_sms_numbers($r);
        // Małoletni — powiadom również opiekuna, jeśli tego nie wyłączył.
        if (!empty($r['is_minor']) && !empty($r['parent_notify_lessons'])) {
            $gp = trim((string)($r['guardian_phone'] ?? ''));
            if ($gp !== '' && !in_array($gp, $nums, true)) $nums[] = $gp;
        }
        foreach ($nums as $num) {
            try { sms_send($num, $message); $sent++; } catch (\Throwable $e) { /* pojedynczy błąd nie blokuje */ }
        }
    }
    return $sent;
}

/**
 * Zwraca listę unikalnych, niepustych numerów SMS dla kursanta z wiersza zawierającego
 * `phone` (główny, z k30_clients) oraz opcjonalne `notify_phone2` / `notify_phone3`.
 * Numery dodatkowe trafiają na listę tylko, gdy są zweryfikowane (kodem SMS albo ręcznie
 * przez administratora) — inaczej kursant mógłby wpisać cudzy numer i podsłuchiwać
 * powiadomienia kogoś innego.
 */
function k30_ti_sms_numbers(array $row): array {
    $out = [];
    $p = trim((string)($row['phone'] ?? ''));
    if ($p !== '') $out[] = $p;
    foreach ([2, 3] as $n) {
        if (empty($row["notify_phone{$n}_verified"])) continue;
        $p = trim((string)($row["notify_phone{$n}"] ?? ''));
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
    }
    return $out;
}

/**
 * Buduje treść SMS z planem lekcji bieżącego tygodnia dla podanego kursu.
 * Bez polskich liter (GSM-7, 1 wiadomość). Format:
 *   FEER <nazwa> tyg. DD-DD.MM:
 *   Pn DD.MM: HH:MM-HH:MM[, HH:MM-HH:MM]
 *   …
 *   Razem: N lekcji
 * Zwraca pusty string gdy brak sesji.
 */
function ti_build_week_sms(array $sessions): string {
    if (empty($sessions)) return '';
    static $short = [1=>'Pn',2=>'Wt',3=>'Sr',4=>'Czw',5=>'Pt',6=>'Sb',0=>'Nd'];
    $replace = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
    $ascii = fn(string $s) => str_replace(array_keys($replace), array_values($replace), $s);

    $mon = date('Y-m-d', strtotime('monday this week'));
    $sun = date('Y-m-d', strtotime('sunday this week'));
    $header_range = date('d', strtotime($mon)) . '-' . date('d.m', strtotime($sun));

    $course_name = $ascii(mb_substr((string)($sessions[0]['course_name'] ?? ''), 0, 15));

    $by_day = [];
    foreach ($sessions as $s) {
        $by_day[(string)$s['lesson_date']][] = $s;
    }
    ksort($by_day);

    $lines = ["FEER {$course_name} tyg. {$header_range}:"];
    $total = 0;
    foreach ($by_day as $date => $day_s) {
        $dow = (int)date('w', strtotime($date));
        $label = ($short[$dow] ?? '?') . ' ' . date('d.m', strtotime($date));
        $slots = [];
        foreach ($day_s as $s) {
            $tf = substr((string)($s['time_from'] ?? ''), 0, 5);
            $tt = substr((string)($s['time_to']   ?? ''), 0, 5);
            $slots[] = ($tf && $tt) ? "{$tf}-{$tt}" : ($tf ?: '?');
            $total++;
        }
        $lines[] = "{$label}: " . implode(', ', $slots);
    }
    $lines[] = 'Razem: ' . $total . ' ' . ($total === 1 ? 'lekcja' : ($total < 5 ? 'lekcje' : 'lekcji'));
    return implode("\n", $lines);
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

        $lm = (string)($l['lesson_method'] ?? '');
        $enroll_link = trim((string)($l['enrollment_meeting_url'] ?? ''));
        $eff_link = $lm === 'stacjonarna' ? ''
                  : (trim((string)($l['meeting_url'] ?? '')) !== '' ? trim((string)$l['meeting_url'])
                  : ($enroll_link !== '' ? $enroll_link
                  : trim((string)($l['course_meeting_url'] ?? ''))));

        $lm_label = match($lm) {
            'stacjonarna' => 'Stacjonarna',
            'zdalna_zoom' => 'Zdalna — Zoom',
            'zdalna_inne' => 'Zdalna — Inne',
            default       => '',
        };

        $descParts = [];
        if ($topic !== '')                 $descParts[] = 'Temat: ' . $topic;
        if ($lm_label !== '')              $descParts[] = 'Metoda: ' . $lm_label;
        if ($eff_link !== '')              $descParts[] = 'Link: ' . $eff_link;
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
        if ($eff_link !== '') {
            $lines[] = $fold('URL:' . $esc($eff_link));
            $lines[] = $fold('LOCATION:' . $esc($eff_link));
            $lines[] = $fold('X-GOOGLE-CONFERENCE:' . $esc($eff_link));
        }
        $lines[] = 'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED');
        if ($cancelled) $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

/** Token kanału iCal prowadzącego — tworzy przy pierwszym użyciu. */
function k30_ti_instructor_cal_token(int $user_id): string {
    $row = db_one("SELECT token FROM k30_ti_instructor_cal_tokens WHERE user_id=?", [$user_id]);
    $tok = (string)($row['token'] ?? '');
    if ($tok === '') {
        $tok = bin2hex(random_bytes(20));
        db()->prepare("INSERT INTO k30_ti_instructor_cal_tokens(user_id, token) VALUES(?,?)
                       ON CONFLICT(user_id) DO UPDATE SET token=excluded.token")
            ->execute([$user_id, $tok]);
    }
    return $tok;
}

/** Nowy token kanału iCal prowadzącego — unieważnia poprzedni adres subskrypcji. */
function k30_ti_instructor_cal_token_reset(int $user_id): string {
    $tok = bin2hex(random_bytes(20));
    db()->prepare("INSERT INTO k30_ti_instructor_cal_tokens(user_id, token) VALUES(?,?)
                   ON CONFLICT(user_id) DO UPDATE SET token=excluded.token")
        ->execute([$user_id, $tok]);
    return $tok;
}

/**
 * Buduje treść pliku iCal (VCALENDAR) z lekcjami prowadzącego.
 * Każde zdarzenie ma tytuł w formie „Lekcja — kursant" (temat/kurs + nazwiska
 * zapisanych kursantów). Obejmuje wszystkie kursy, w których jest prowadzącym.
 */
function k30_ti_instructor_calendar_ics(int $user_id, string $cal_name = 'Lekcje TI'): string {
    $lessons = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min,
                s.topic, s.status, s.has_homework, s.self_prep_remote,
                s.lesson_method, s.meeting_url,
                c.name AS course_name, c.default_meeting_url AS course_meeting_url,
                (SELECT group_concat(cl.name, ', ')
                   FROM k30_ti_attendance a
                   JOIN k30_clients cl ON cl.id=a.client_id
                  WHERE a.session_id=s.id AND a.cancelled=0) AS student_names
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE c.instructor_id=?
         ORDER BY s.lesson_date DESC, s.time_from
         LIMIT 500",
        [$user_id]
    );
    $host = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?: 'szo';

    $esc = static fn(string $s): string =>
        str_replace(["\\", "\n", "\r", ",", ";"], ["\\\\", "\\n", "", "\\,", "\\;"], $s);

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
                $dtStart = 'DTSTART:' . $start->format('Ymd\THis');
                $dtEnd   = 'DTEND:'   . $end->format('Ymd\THis');
            }
        } catch (\Throwable $e) { continue; }

        $topic    = trim((string)($l['topic'] ?? ''));
        $course   = trim((string)($l['course_name'] ?? ''));
        $students = trim((string)($l['student_names'] ?? ''));

        // Tytuł: „Lekcja — kursant" (temat lekcji lub nazwa kursu + kursanci).
        $lesson  = $topic !== '' ? $topic : ($course !== '' ? $course : 'Lekcja TI');
        if (!empty($l['self_prep_remote'])) $lesson .= ' (praca własna)';
        $summary = $students !== '' ? ($lesson . ' — ' . $students) : $lesson;

        $lm_d = (string)($l['lesson_method'] ?? '');
        $eff_link_d = $lm_d === 'stacjonarna' ? ''
                    : (trim((string)($l['meeting_url'] ?? '')) !== '' ? trim((string)$l['meeting_url'])
                    : trim((string)($l['course_meeting_url'] ?? '')));

        $lm_label_d = match($lm_d) {
            'stacjonarna' => 'Stacjonarna',
            'zdalna_zoom' => 'Zdalna — Zoom',
            'zdalna_inne' => 'Zdalna — Inne',
            default       => '',
        };

        $descParts = [];
        if ($course !== '')              $descParts[] = 'Kurs: ' . $course;
        if ($students !== '')            $descParts[] = 'Kursant: ' . $students;
        if ($lm_label_d !== '')          $descParts[] = 'Metoda: ' . $lm_label_d;
        if ($eff_link_d !== '')          $descParts[] = 'Link: ' . $eff_link_d;
        if (!empty($l['has_homework']))  $descParts[] = 'Zadanie domowe: tak';
        $desc = implode('\\n', array_map($esc, $descParts));

        $cancelled = ((string)($l['status'] ?? '') === 'cancelled');

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:k30ti-dyd-' . (int)$l['id'] . '@' . $host;
        $lines[] = 'DTSTAMP:' . $now;
        $lines[] = $dtStart;
        $lines[] = $dtEnd;
        $lines[] = $fold('SUMMARY:' . $esc($summary));
        if ($desc !== '') $lines[] = $fold('DESCRIPTION:' . $desc);
        if ($eff_link_d !== '') {
            $lines[] = $fold('URL:' . $esc($eff_link_d));
            $lines[] = $fold('LOCATION:' . $esc($eff_link_d));
            $lines[] = $fold('X-GOOGLE-CONFERENCE:' . $esc($eff_link_d));
        }
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
        "SELECT s.*, c.default_meeting_url,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id AND a.attended=1) AS attended_count,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id) AS total_count
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
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
 * Zapisuje przesłany plik zadania do UPLOAD_DIR/ti_homework/. Gdy integracja
 * ownCloud jest włączona (includes/owncloud.php), plik trafia tam (magazyn
 * docelowy) — lokalna kopia zostaje tylko jeśli wysyłka się nie powiedzie,
 * jako zabezpieczenie przed utratą pliku przy awarii sieci/serwera ownCloud.
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

    if (function_exists('owncloud_enabled') && owncloud_enabled()) {
        $up = owncloud_put('ti_homework/' . $stored, $dir . $stored);
        if ($up['ok']) @unlink($dir . $stored);
        else error_log('[owncloud] upload ti_homework/' . $stored . ': ' . $up['msg']);
    }
    return ['name' => mb_substr($f['name'], 0, 200), 'stored' => $stored];
}

/**
 * Wysyła plik zadania do przeglądarki (download). Kończy skrypt.
 * Sprawdza najpierw dysk lokalny (pliki sprzed włączenia ownCloud), potem
 * ownCloud — dzięki temu migracja jest nieprzerywająca dla starych plików.
 */
function k30_ti_homework_send_file(string $stored, string $orig = ''): void {
    if ($stored === '') { http_response_code(404); exit('Plik nie istnieje.'); }
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_homework/' . basename($stored);
    $name = preg_replace('/[\r\n"]+/', '', $orig !== '' ? $orig : basename($stored));

    if (is_file($path)) {
        header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    if (function_exists('owncloud_enabled') && owncloud_enabled()) {
        $data = owncloud_get('ti_homework/' . basename($stored));
        if ($data !== null) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($data));
            echo $data;
            exit;
        }
    }

    http_response_code(404); exit('Plik nie istnieje.');
}

/** Usuwa plik zadania z dysku i/lub ownCloud (jeśli istnieje). */
function k30_ti_homework_delete_file(string $stored): void {
    if ($stored === '') return;
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_homework/' . basename($stored);
    if (is_file($path)) @unlink($path);
    if (function_exists('owncloud_enabled') && owncloud_enabled()) {
        owncloud_delete('ti_homework/' . basename($stored));
    }
}

/**
 * Importuje plik z zewnętrznego URL do magazynu TI.
 * Zwraca ['name'=>..., 'stored'=>...] lub null przy błędzie.
 */
function k30_ti_import_from_url(string $url, string $filename = '', string $prefix = 'mat'): ?array {
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
    if (!in_array($scheme, ['http', 'https'], true)) return null;

    $ctx  = stream_context_create(['http' => [
        'timeout'         => 30,
        'follow_location' => 1,
        'max_redirects'   => 3,
        'ignore_errors'   => true,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || $data === '') return null;

    if ($filename === '') {
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'plik');
    }
    $filename = basename($filename);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, k30_ti_homework_allowed_ext(), true)) return null;

    $stamp  = date('Ymd_His');
    $suffix = bin2hex(random_bytes(4));
    $stored = "{$prefix}_{$stamp}_{$suffix}.{$ext}";
    $dir    = rtrim(UPLOAD_DIR, '/') . '/ti_homework/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (file_put_contents($dir . $stored, $data) === false) return null;

    if (function_exists('owncloud_enabled') && owncloud_enabled()) {
        owncloud_put('ti_homework/' . $stored, $dir . $stored);
    }
    return ['name' => $filename, 'stored' => $stored];
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
        "SELECT a.id, a.notify_email_dydaktyka, a.notify_sms_dydaktyka, a.notify_phone2, a.notify_phone2_verified, a.notify_phone3, a.notify_phone3_verified,
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
                    if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';
                    $firstName = (string)(explode(' ', trim((string)($acc['name'] ?? '')))[0] ?: ($acc['name'] ?? ''));
                    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_dydaktyka', [
                        'org'      => $org,
                        'name'     => htmlspecialchars($firstName, ENT_QUOTES),
                        'subject'  => htmlspecialchars($subject, ENT_QUOTES),
                        'body_html'=> $bodyHtml,
                        'url'      => htmlspecialchars($url, ENT_QUOTES),
                    ]) : ['subject' => "{$org}: {$subject}", 'html' => "<p>{$bodyHtml}</p>", 'enabled' => true];
                    try { mail_queue_add($email, (string)($acc['name'] ?? ''), $r['subject'], $r['html'], '', 'ti_dydaktyka', (int)$acc['id'], '', true); } catch (\Throwable $e) {}
                }
            }
        }
        // SMS
        if (!empty($acc['notify_sms_dydaktyka'])) {
            if (!function_exists('sms_send')) @require_once __DIR__ . '/sms.php';
            if (function_exists('sms_send') && function_exists('sms_channel_ready') && sms_channel_ready()) {
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

/** Kursy prowadzone przez danego użytkownika (główny lub coProwadzący). */
function k30_ti_instructor_courses(int $user_id, bool $active_only = false): array {
    $ao = $active_only ? " AND c.status='active'" : '';
    return db_all(
        "SELECT c.*,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS enrolled_count,
                CASE WHEN c.instructor_id=? THEN 0 ELSE 1 END AS is_co_instructor
         FROM k30_ti_courses c
         WHERE (c.instructor_id=? OR EXISTS (
             SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id=c.id AND ci.user_id=?
         ))$ao
         ORDER BY c.status='active' DESC, c.name",
        [$user_id, $user_id, $user_id]
    );
}
/** Czy dany kurs prowadzi ten dydaktyk (główny lub coProwadzący). */
function k30_ti_instructor_owns_course(int $user_id, int $course_id): bool {
    return (bool) db_one(
        "SELECT 1 FROM k30_ti_courses WHERE id=? AND instructor_id=?
         UNION
         SELECT 1 FROM k30_ti_course_coinstructors WHERE course_id=? AND user_id=?",
        [$course_id, $user_id, $course_id, $user_id]
    );
}
/** Czy dana lekcja należy do kursu prowadzonego przez tego dydaktyka (główny lub coProwadzący). */
function k30_ti_instructor_owns_session(int $user_id, int $session_id): bool {
    return (bool) db_one(
        "SELECT 1 FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.id=? AND (c.instructor_id=? OR EXISTS (
             SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id=c.id AND ci.user_id=?
         ))",
        [$session_id, $user_id, $user_id]
    );
}

/** Lista coProwadzących kursu (z danymi users). */
function k30_ti_course_coinstructors(int $course_id): array {
    return db_all(
        "SELECT ci.*, u.name AS user_name, u.email AS user_email
         FROM k30_ti_course_coinstructors ci
         JOIN users u ON u.id=ci.user_id
         WHERE ci.course_id=? ORDER BY u.name",
        [$course_id]
    );
}

/** Dodaj coProwadzącego do kursu. Idempotent (INSERT OR IGNORE). */
function k30_ti_coinstruct_add(int $course_id, int $user_id, int $by): void {
    db()->prepare(
        "INSERT OR IGNORE INTO k30_ti_course_coinstructors(course_id, user_id, created_by, created_at)
         VALUES(?,?,?,datetime('now'))"
    )->execute([$course_id, $user_id, $by]);
}

/** Usuń coProwadzącego z kursu. */
function k30_ti_coinstruct_remove(int $course_id, int $user_id): void {
    db()->prepare(
        "DELETE FROM k30_ti_course_coinstructors WHERE course_id=? AND user_id=?"
    )->execute([$course_id, $user_id]);
}

/** Adresy e-mail coProwadzących kursu jako string rozdzielony przecinkiem (dla Zoom alternative_hosts). */
function k30_ti_course_coinstructor_emails(int $course_id): string {
    $rows = db_all(
        "SELECT u.email FROM k30_ti_course_coinstructors ci
         JOIN users u ON u.id=ci.user_id WHERE ci.course_id=? AND u.email != ''",
        [$course_id]
    );
    return implode(',', array_column($rows, 'email'));
}

/**
 * Adresy e-mail do Zoom alternative_hosts: prowadzący kursu + coProwadzący.
 * Pozwala każdemu z nich rozpocząć spotkanie stworzone na koncie hosta (edukacja@).
 */
function k30_ti_course_zoom_alt_hosts(int $course_id): string {
    $rows = db_all(
        "SELECT u.email FROM k30_ti_courses c
         JOIN users u ON u.id=c.instructor_id
         WHERE c.id=? AND u.email != ''
         UNION
         SELECT u.email FROM k30_ti_course_coinstructors ci
         JOIN users u ON u.id=ci.user_id WHERE ci.course_id=? AND u.email != ''",
        [$course_id, $course_id]
    );
    return implode(',', array_column($rows, 'email'));
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
         WHERE s.id=? AND s.status IN ('held','individual_change','remote_material')",
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
        $e['no_show']           = isset($att_map[$cid]) ? (int)($att_map[$cid]['no_show'] ?? 0) : 0;
        $e['no_show_billing']   = $att_map[$cid]['no_show_billing'] ?? 'full';
        $e['no_show_reason']    = $att_map[$cid]['no_show_reason'] ?? '';
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
         SET cancelled=0, cancel_pending=0, cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL,
             no_show=0, no_show_billing='full'
         WHERE session_id=? AND client_id=?"
    )->execute([$session_id, $client_id]);
}

/**
 * Oznacza uczestnika jako „nie pojawił się" (no_show).
 * Lekcja się odbyła, ale beneficjent nie stawił się — nadal rozliczany wg wybranego modelu:
 *   'full' = cała lekcja, '1h' = tylko 1 godzina rozpoczęta.
 */
function k30_ti_mark_no_show(int $session_id, int $client_id, string $billing, string $role, string $by_label, string $reason = '', array $attachment = []): void {
    $billing = in_array($billing, ['full', '1h'], true) ? $billing : 'full';
    $ex = db_one("SELECT id FROM k30_ti_attendance WHERE session_id=? AND client_id=?", [$session_id, $client_id]);
    if ($ex) {
        db()->prepare(
            "UPDATE k30_ti_attendance
             SET attended=0, cancelled=0, cancel_pending=0,
                 no_show=1, no_show_billing=?, no_show_reason=?,
                 cancelled_by_role=?, cancelled_by=?, cancelled_at=datetime('now')
             WHERE id=?"
        )->execute([$billing, $reason, $role, $by_label, (int)$ex['id']]);
    } else {
        db_insert('k30_ti_attendance', [
            'session_id'        => $session_id,
            'client_id'         => $client_id,
            'attended'          => 0,
            'cancelled'         => 0,
            'cancel_pending'    => 0,
            'no_show'           => 1,
            'no_show_billing'   => $billing,
            'no_show_reason'    => $reason,
            'cancelled_by_role' => $role,
            'cancelled_by'      => $by_label,
            'cancelled_at'      => date('Y-m-d H:i:s'),
        ]);
    }
    k30_ti_notify_no_show($session_id, $client_id, $billing, $reason, $attachment);
}

/**
 * Wysyła e-mail do rodzica/opiekuna (i kursanta) o niepojawieniu się na zajęciach.
 * Dla małoletnich: główny adresat = guardian_email (jeśli ustawiony i parent_notify_absence=1).
 * Dla pełnoletnich: adresat = email kursanta.
 */
function k30_ti_notify_no_show(int $session_id, int $client_id, string $billing, string $reason, array $attachment = []): void {
    $row = db_one(
        "SELECT s.lesson_date, s.time_from, s.duration_min, c.name AS course_name,
                cl.name AS client_name, cl.email,
                a.is_minor, a.guardian_email, a.guardian_name,
                a.parent_notify_absence
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         JOIN k30_clients cl ON cl.id=?
         LEFT JOIN k30_ti_student_accounts a ON a.client_id=cl.id AND a.is_active=1
         WHERE s.id=? LIMIT 1",
        [$client_id, $session_id]
    );
    if (!$row) return;
    // Szanuj opt-out rodzica (gdy konto istnieje i flaga jawnie wyłączona)
    if ($row['parent_notify_absence'] !== null && (int)$row['parent_notify_absence'] === 0) return;

    $is_minor   = !empty($row['is_minor']);
    $gemail     = trim((string)($row['guardian_email'] ?? ''));
    $gname      = trim((string)($row['guardian_name'] ?? ''));
    $stu_email  = trim((string)($row['email'] ?? ''));
    $stu_name   = (string)$row['client_name'];

    // Wyślij do rodzica (małoletni) lub do samego kursanta (pełnoletni)
    $emails = [];
    if ($is_minor && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) {
        $emails[$gemail] = $gname ?: $stu_name;
    } elseif (!$is_minor && $stu_email !== '' && filter_var($stu_email, FILTER_VALIDATE_EMAIL)) {
        $emails[$stu_email] = $stu_name;
    }
    if (!$emails) return;

    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;
    if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';

    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
    $when = date('d.m.Y', strtotime($row['lesson_date']));
    if (!empty($row['time_from'])) $when .= ' o ' . substr((string)$row['time_from'], 0, 5);
    $dur_h = round((int)$row['duration_min'] / 60, 2);
    $billing_label = $billing === '1h' ? '1 godzina (rozpoczęta)' : 'cała lekcja (' . number_format($dur_h, 0) . ' h)';
    $reason_row = $reason !== ''
        ? '<tr><td style="padding:3px 14px 3px 0;color:#555;white-space:nowrap">Opis sytuacji:</td><td>' . htmlspecialchars($reason, ENT_QUOTES) . '</td></tr>'
        : '';
    $attach_row = !empty($attachment['name'])
        ? '<tr><td style="padding:3px 14px 3px 0;color:#555;white-space:nowrap">Załącznik:</td><td>📎 ' . htmlspecialchars($attachment['name'], ENT_QUOTES) . '</td></tr>'
        : '';

    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_no_show', [
        'org'           => $org,
        'client_name'   => htmlspecialchars($stu_name, ENT_QUOTES),
        'course_name'   => htmlspecialchars((string)$row['course_name'], ENT_QUOTES),
        'when'          => htmlspecialchars($when, ENT_QUOTES),
        'billing_label' => htmlspecialchars($billing_label, ENT_QUOTES),
        'reason_row'    => $reason_row . $attach_row,
        'url'           => htmlspecialchars($url, ENT_QUOTES),
    ]) : [
        'subject' => "{$org}: nieobecność na zajęciach — {$when}",
        'html'    => "<p>{$stu_name} nie pojawił/a się na zajęciach {$row['course_name']} ({$when}). Rozliczenie: {$billing_label}." . ($reason ? " Opis: {$reason}." : '') . "</p>",
        'enabled' => true,
    ];
    if (empty($r['enabled'])) return;
    $atts = !empty($attachment['path']) ? [$attachment] : [];
    foreach ($emails as $addr => $nm) {
        try { mail_queue_add($addr, $nm, $r['subject'], $r['html'], '', 'ti_no_show', $session_id, '', false, $atts); }
        catch (\Throwable $e) {}
    }
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
    if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $when = date('d.m.Y', strtotime($row['lesson_date'])) . ($row['time_from'] ? ' o ' . substr($row['time_from'], 0, 5) : '');
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/dydaktyk/index.php';
    $reason_block = $reason !== '' ? '<p>Powód: ' . htmlspecialchars($reason, ENT_QUOTES) . '</p>' : '';
    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_cancel_req', [
        'org'          => $org,
        'client_name'  => htmlspecialchars((string)$row['client_name'], ENT_QUOTES),
        'course_name'  => htmlspecialchars((string)$row['course_name'], ENT_QUOTES),
        'when'         => htmlspecialchars($when, ENT_QUOTES),
        'reason_block' => $reason_block,
        'url'          => htmlspecialchars($url, ENT_QUOTES),
    ]) : ['subject' => "{$org}: prośba o odwołanie lekcji — {$when}", 'html' => "<p>Prośba od {$row['client_name']}.</p>", 'enabled' => true];
    try { mail_queue_add($email, (string)($row['instructor_name'] ?? ''), $r['subject'], $r['html'], '', 'ti_cancel_req', $session_id, '', false); }
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
    if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
    $when = date('d.m.Y', strtotime($row['lesson_date'])) . ($row['time_from'] ? ' o ' . substr($row['time_from'], 0, 5) : '');
    $crs  = htmlspecialchars((string)$row['course_name'], ENT_QUOTES);
    if ($confirmed) {
        $subject_suffix = "potwierdzono odwołanie udziału — {$when}";
        $header_color   = 'linear-gradient(135deg,#16a34a,#22c55e)';
        $header_icon    = '✅';
        $header_title   = 'odwołanie potwierdzone';
        $lead_html      = "Twoja prośba o odwołanie udziału w lekcji <strong>{$crs}</strong> ({$when}) została <strong>potwierdzona</strong>. Udział nie zostanie policzony do ceny.";
    } else {
        $subject_suffix = "odrzucono prośbę o odwołanie — {$when}";
        $header_color   = 'linear-gradient(135deg,#dc2626,#ef4444)';
        $header_icon    = '❌';
        $header_title   = 'odwołanie odrzucone';
        $lead_html      = "Twoja prośba o odwołanie udziału w lekcji <strong>{$crs}</strong> ({$when}) została <strong>odrzucona</strong> — udział pozostaje aktualny.";
    }
    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_cancel_decision', [
        'org'            => $org,
        'subject_suffix' => $subject_suffix,
        'header_color'   => $header_color,
        'header_icon'    => $header_icon,
        'header_title'   => $header_title,
        'lead_html'      => $lead_html,
        'url'            => htmlspecialchars($url, ENT_QUOTES),
    ]) : ['subject' => "{$org}: {$subject_suffix}", 'html' => "<p>{$lead_html}</p>", 'enabled' => true];
    foreach ($emails as $addr => $nm) {
        try { mail_queue_add($addr, $nm, $r['subject'], $r['html'], '', 'ti_cancel_decision', $session_id, '', false); }
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
    if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';
    $org = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=oceny';
    $category_block   = $categoryLabel !== '' ? '<div style="font-size:.85em;color:#555;margin-top:4px">(' . htmlspecialchars($categoryLabel, ENT_QUOTES) . ')</div>' : '';
    $description_block = $description !== '' ? '<p style="color:#555;font-size:.9em">' . htmlspecialchars($description, ENT_QUOTES) . '</p>' : '';
    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_grade', [
        'org'               => $org,
        'course_name'       => htmlspecialchars((string)$row['course_name'], ENT_QUOTES),
        'value'             => htmlspecialchars($valueText, ENT_QUOTES),
        'category_block'    => $category_block,
        'description_block' => $description_block,
        'url'               => htmlspecialchars($url, ENT_QUOTES),
    ]) : ['subject' => "{$org}: nowa ocena — {$row['course_name']}", 'html' => "<p>Ocena: {$valueText}</p>", 'enabled' => true];
    foreach ($emails as $addr => $nm) {
        try { mail_queue_add($addr, $nm, $r['subject'], $r['html'], '', 'ti_grade', $course_id, '', false); }
        catch (\Throwable $e) {}
    }
}

/**
 * Odwołuje całą lekcję (Doradca / admin) — status='cancelled', z powodem i autorem.
 * Odwołana lekcja nie jest liczona do ceny (rozliczenie bierze tylko status='held').
 */
function k30_ti_cancel_session(int $session_id, string $reason, string $role, string $by_label): int {
    $role = array_key_exists($role, K30_TI_CANCEL_ROLES) ? $role : 'doradca';
    db()->prepare(
        "UPDATE k30_ti_sessions
         SET status='cancelled', cancel_reason=?, cancelled_by_role=?, cancelled_by=?, cancelled_at=datetime('now'),
             updated_at=datetime('now')
         WHERE id=?"
    )->execute([$reason, $role, $by_label, $session_id]);

    // Powiadomienie SMS o odwołaniu — tylko do kursantów, którzy włączyli SMS o lekcjach.
    // Zwraca liczbę wysłanych SMS-ów (0 gdy SMS wyłączony / brak odbiorców).
    $row = db_one(
        "SELECT s.course_id, s.lesson_date, s.time_from, c.name AS course_name
         FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.id=? LIMIT 1",
        [$session_id]
    );
    if (!$row || !function_exists('ti_lesson_sms_notify')) return 0;
    $when = date('d.m.Y', strtotime((string)$row['lesson_date']));
    if (!empty($row['time_from'])) $when .= ' o ' . substr((string)$row['time_from'], 0, 5);
    return ti_lesson_sms_notify(
        (int)$row['course_id'],
        'Odwolane zajecia: ' . (string)$row['course_name'] . ' — ' . $when . '. Szczegoly w panelu kursanta.'
    );
}

/**
 * Oblicza miesięczne rozliczenie klienta w TI.
 * Zwraca godziny i kwotę na podstawie lekcji odbyłych w danym miesiącu.
 */
function k30_ti_calculate_billing(int $client_id, int $month, int $year, int $course_id_filter = 0): array {
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));

    // Aktywne zapisy klienta wraz z modelem rozliczania kursu
    $enrs = db_all(
        "SELECT e.*, c.billing_model AS course_billing_model, c.billing_amount AS course_billing_amount
         FROM k30_ti_enrollments e
         JOIN k30_ti_courses c ON c.id=e.course_id
         WHERE e.client_id=? AND e.status='active'"
        . ($course_id_filter > 0 ? ' AND e.course_id=?' : ''),
        $course_id_filter > 0 ? [$client_id, $course_id_filter] : [$client_id]
    );

    $hours   = 0.0;
    $amount  = 0.0;
    $models  = [];
    $courses = [];
    foreach ($enrs as $e) {
        // Godziny obecności w tym kursie w danym miesiącu
        $rows = db_all(
            "SELECT s.duration_min
             FROM k30_ti_attendance a
             JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
                  AND s.course_id=? AND s.lesson_date BETWEEN ? AND ?
             WHERE a.client_id=? AND a.attended=1 AND COALESCE(a.cancelled,0)=0",
            [(int)$e['course_id'], $from, $to, $client_id]
        );
        $ch = 0.0;
        foreach ($rows as $r) $ch += (float)ceil((int)$r['duration_min'] / 60);
        // No-show: nalicz wg wybranego modelu (pełna lekcja lub 1h)
        $ns_rows = db_all(
            "SELECT s.duration_min, a.no_show_billing
             FROM k30_ti_attendance a
             JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
                  AND s.course_id=? AND s.lesson_date BETWEEN ? AND ?
             WHERE a.client_id=? AND COALESCE(a.no_show,0)=1",
            [(int)$e['course_id'], $from, $to, $client_id]
        );
        foreach ($ns_rows as $nr) {
            $ch += ($nr['no_show_billing'] === '1h') ? 1.0 : (float)ceil((int)$nr['duration_min'] / 60);
        }
        $hours += $ch;

        $eff = k30_ti_effective_billing($e, [
            'billing_model'  => $e['course_billing_model'],
            'billing_amount' => $e['course_billing_amount'],
        ]);
        $models[$eff['code']] = true;
        $course_amount = 0.0;
        if ($eff['model'] === 1 || $eff['model'] === 3) {
            // miesięczny / stały — kwota niezależna od godzin (naliczana gdy zapis aktywny)
            $course_amount = $eff['amount'];
        } else {
            // godzinowy
            $course_amount = $ch * $eff['hourly_rate'];
        }
        $amount += $course_amount;

        $course_name = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$e['course_id']])['name'] ?? '?';
        $courses[] = [
            'course_id'    => (int)$e['course_id'],
            'course_name'  => $course_name,
            'hours_billed' => round($ch, 4),
            'amount'       => round($course_amount, 2),
            'model'        => $eff['model'],
        ];
    }

    return [
        'client_id'   => $client_id,
        'month'       => $month,
        'year'        => $year,
        'hours_billed'=> round($hours, 4),
        'amount'      => round($amount, 2),
        'models'      => array_keys($models), // kody zastosowanych modeli (info)
        'courses'     => $courses,            // rozbicie per kurs
    ];
}

/**
 * Generuje / aktualizuje rozliczenie miesięczne klienta.
 * $course_id=0 → łączne (wszystkie kursy), >0 → tylko dany kurs.
 */
function k30_ti_issue_billing(int $client_id, int $month, int $year, string $notes = '', int $course_id = 0): int {
    $calc = k30_ti_calculate_billing($client_id, $month, $year, $course_id);
    $ex = db_one("SELECT id, due_date FROM k30_ti_billing WHERE client_id=? AND month=? AND year=? AND course_id=?",
                 [$client_id, $month, $year, $course_id]);
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
        if (empty($ex['due_date'])) $data['due_date'] = $due_date;
        $set = []; $p = [];
        foreach ($data as $k => $v) { $set[] = "$k=?"; $p[] = $v; }
        $p[] = $ex['id'];
        db()->prepare("UPDATE k30_ti_billing SET " . implode(',', $set) . " WHERE id=?")->execute($p);
        return (int)$ex['id'];
    }
    return db_insert('k30_ti_billing', array_merge($data, [
        'client_id' => $client_id, 'month' => $month, 'year' => $year, 'course_id' => $course_id,
        'due_date'  => $due_date,
        'created_at'=> date('Y-m-d H:i:s'),
    ]));
}

/**
 * MODEL KOMBINOWANY — wystawia OSOBNE rozliczenie na każdą grupę (przedmiot), w której
 * kursant ma w danym miesiącu podstawę do naliczenia:
 *   - grupy z lekcjami (obecność lub no-show) w tym miesiącu, ORAZ
 *   - grupy z aktywnym zapisem rozliczanym ryczałtem (model 1 miesięczny / 3 stały),
 *     gdzie kwota należy się niezależnie od liczby lekcji.
 * Kursant w kilku grupach dostaje kilka rozliczeń — każde ze swoim modelem i saldem.
 * Gdy nie ma żadnej grupy do naliczenia, wystawiane jest rozliczenie łączne (course_id=0),
 * które obsługuje też opłaty spoza zajęć (ti_billing_add_charge).
 *
 * Zwraca tablicę id wystawionych rozliczeń.
 */
function k30_ti_issue_billing_split(int $client_id, int $month, int $year, string $notes = ''): array {
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));
    $ids  = [];

    // (a) grupy z lekcjami w miesiącu
    $with_sessions = db_all(
        "SELECT DISTINCT s.course_id FROM k30_ti_attendance a
         JOIN k30_ti_sessions s ON s.id=a.session_id
              AND s.status IN ('held','individual_change','remote_material')
              AND s.lesson_date BETWEEN ? AND ?
         JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=a.client_id AND e.status='active'
         WHERE a.client_id=? AND (a.attended=1 OR COALESCE(a.no_show,0)=1)",
        [$from, $to, $client_id]
    );
    foreach ($with_sessions as $c) $ids[(int)$c['course_id']] = true;

    // (b) grupy z ryczałtem (miesięczny / stały) — naliczane mimo braku lekcji
    $enrs = db_all(
        "SELECT e.*, c.billing_model AS course_billing_model, c.billing_amount AS course_billing_amount
         FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id
         WHERE e.client_id=? AND e.status='active'",
        [$client_id]
    );
    foreach ($enrs as $e) {
        $eff = k30_ti_effective_billing($e, [
            'billing_model'  => $e['course_billing_model'],
            'billing_amount' => $e['course_billing_amount'],
        ]);
        if (in_array($eff['model'], [1, 3], true) && (float)$eff['amount'] > 0) {
            $ids[(int)$e['course_id']] = true;
        }
    }

    if (!$ids) return [k30_ti_issue_billing($client_id, $month, $year, $notes, 0)];

    $bids = [];
    foreach (array_keys($ids) as $cid) {
        $bids[] = k30_ti_issue_billing($client_id, $month, $year, $notes, (int)$cid);
    }
    // Rozliczenie łączne z tego samego miesiąca zostaje zredukowane do samych opłat
    // spoza zajęć (adjustment), żeby zajęcia nie policzyły się drugi raz.
    k30_ti_billing_absorb_combined($client_id, $month, $year);
    return $bids;
}

/**
 * Po rozdzieleniu rozliczeń per grupa: stare rozliczenie łączne (course_id=0) za ten sam
 * miesiąc traci część „za zajęcia" (amount/hours → 0). Gdy nie ma na nim żadnej korekty
 * (opłat spoza zajęć), jest anulowane. Chroni przed podwójnym naliczeniem.
 */
function k30_ti_billing_absorb_combined(int $client_id, int $month, int $year): void {
    $c = db_one("SELECT id, amount, hours_billed, adjustment, status FROM k30_ti_billing
                 WHERE client_id=? AND month=? AND year=? AND COALESCE(course_id,0)=0
                   AND status IN ('draft','issued','paid')",
                [$client_id, $month, $year]);
    if (!$c) return;
    $adj = round((float)($c['adjustment'] ?? 0), 2);
    if (abs($adj) > 0.005) {
        if ((float)$c['amount'] > 0.005 || (float)$c['hours_billed'] > 0.005) {
            db()->prepare("UPDATE k30_ti_billing SET amount=0, hours_billed=0,
                           notes=TRIM(COALESCE(notes,'') || ' Zajęcia rozdzielone na rozliczenia per grupa.')
                           WHERE id=?")->execute([(int)$c['id']]);
        }
        return;
    }
    db()->prepare("UPDATE k30_ti_billing SET status='cancelled',
                   notes=TRIM(COALESCE(notes,'') || ' Zastąpione rozliczeniami per grupa.')
                   WHERE id=?")->execute([(int)$c['id']]);
}

/** Nazwa systemu, w którym wystawiane są faktury (ustawienie `ti_invoice_system`). */
function k30_ti_invoice_system(): string {
    $v = trim(org_setting('ti_invoice_system'));
    return $v !== '' ? $v : 'Comarch ERP Optima';
}

/** Stawka VAT drukowana na podsumowaniu pozycji do faktury (ustawienie `ti_invoice_vat`). */
function k30_ti_invoice_vat(): string {
    $v = trim(org_setting('ti_invoice_vat'));
    return $v !== '' ? $v : 'zw';
}

/**
 * Pozycje do faktury VAT dla rozliczenia — gotowe do przepisania do systemu fakturowego.
 * Dla rozliczenia per grupa (course_id>0) jedna pozycja za tę grupę; dla łącznego —
 * pozycja na każdą grupę z naliczeniem. Korekta (opłata dodatkowa / rabat) to osobna pozycja.
 *
 * @return array<int,array{name:string,unit:string,qty:float,unit_price:float,value:float}>
 */
function k30_ti_billing_fv_positions(array $b): array {
    $months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                  7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
    $m      = (int)$b['month'];
    $y      = (int)$b['year'];
    $period = ($months_pl[$m] ?? $m) . ' ' . $y;
    $cid    = (int)($b['course_id'] ?? 0);
    $calc   = k30_ti_calculate_billing((int)$b['client_id'], $m, $y, $cid);

    $pos = [];
    foreach ($calc['courses'] as $c) {
        if ((float)$c['amount'] <= 0.005 && (float)$c['hours_billed'] <= 0.005) continue;
        $hourly = ((int)$c['model'] === 2);
        $qty    = $hourly ? round((float)$c['hours_billed'], 2) : 1.0;
        $val    = round((float)$c['amount'], 2);
        $pos[] = [
            'name'       => 'Zajęcia TI — ' . $c['course_name'] . ' (' . $period . ')',
            'unit'       => $hourly ? 'godz.' : 'usł.',
            'qty'        => $qty,
            'unit_price' => $qty > 0 ? round($val / $qty, 2) : $val,
            'value'      => $val,
        ];
    }
    // Fallback: zapis nieaktywny / dane kursu zmienione → pozycja wprost z rozliczenia,
    // żeby wydruk nigdy nie wyszedł pusty przy niezerowej kwocie.
    if (!$pos && (float)($b['amount'] ?? 0) > 0.005) {
        $hours = round((float)($b['hours_billed'] ?? 0), 2);
        $val   = round((float)$b['amount'], 2);
        $cname = $cid > 0 ? (db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid])['name'] ?? ('grupa #'.$cid)) : 'zajęcia';
        $pos[] = [
            'name'       => 'Zajęcia TI — ' . $cname . ' (' . $period . ')',
            'unit'       => $hours > 0 ? 'godz.' : 'usł.',
            'qty'        => $hours > 0 ? $hours : 1.0,
            'unit_price' => $hours > 0 ? round($val / $hours, 2) : $val,
            'value'      => $val,
        ];
    }
    $adj = round((float)($b['adjustment'] ?? 0), 2);
    if (abs($adj) > 0.005) {
        $note = trim((string)($b['adjustment_note'] ?? ''));
        $pos[] = [
            'name'       => ($adj > 0 ? 'Opłata dodatkowa' : 'Rabat') . ($note !== '' ? ' — ' . $note : '') . ' (' . $period . ')',
            'unit'       => 'usł.',
            'qty'        => 1.0,
            'unit_price' => $adj,
            'value'      => $adj,
        ];
    }
    return $pos;
}

/**
 * Powiadomienie o wystawieniu rozliczenia — SMS (kwota za okres) + e-mail.
 * $channels: 'all' (domyślnie) | 'mail' (tylko e-mail) | 'sms' (tylko SMS).
 * Adresat: dla małoletnich opiekun (telefon/e-mail), inaczej kursant (k30_clients).
 * Domyślnie wysyła tylko raz (gdy notified_at puste); $force=true wymusza ponowną wysyłkę.
 * Zwraca ['ok','sms'=>bool,'email'=>bool,'skipped'=>bool,'msg'].
 */
function k30_ti_billing_notify(int $billing_id, bool $force = false, string $channels = 'all'): array {
    $b = db_one("SELECT b.*, c.name AS course_name, c.group_code AS course_group_code
                 FROM k30_ti_billing b
                 LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
                 WHERE b.id=?", [$billing_id]);
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
    $period       = ($months[(int)$b['month']] ?? $b['month']) . ' ' . (int)$b['year'];
    $course_label = !empty($b['course_name']) ? ' (' . $b['course_name'] . ')' : '';
    $amount   = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
    $amount_s = number_format($amount, 2, ',', ' ');
    $due_s    = !empty($b['due_date']) ? date('d.m.Y', strtotime($b['due_date'])) : '';
    $org      = defined('ORG_NAME') ? ORG_NAME : 'Placówka';
    $pay      = k30_ti_client_payment((int)$b['client_id']);
    $base     = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $portal   = $base . '/karty30/ti/kursant/login.php';

    $sms_sent = false; $mail_sent = false;

    // ── SMS ──
    if ($phone !== '' && in_array($channels, ['all', 'sms'], true)) {
        require_once __DIR__ . '/sms.php';
        if (function_exists('sms_channel_ready') && sms_channel_ready()) {
            // bez polskich znaków — bramki SMS
            $msg = "{$org}: rozliczenie za {$period}{$course_label}: {$amount_s} zl."
                 . ($due_s !== '' ? " Termin platnosci: {$due_s}." : '')
                 . ($pay['account'] !== '' ? " Wplata na: {$pay['account']}." : '')
                 . " Szczegoly w panelu kursanta.";
            $msg = strtr($msg, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                                'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);
            try { sms_send($phone, $msg); $sms_sent = true; } catch (\Throwable $e) {}
        }
    }

    // ── E-mail: zestawienie należności (faktura wystawiana osobno w systemie fakturującym) ──
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($channels, ['all', 'mail'], true)) {
        require_once __DIR__ . '/mail_queue.php';
        if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';

        $client_name = trim((string)($client['name'] ?? ''));
        $parts       = preg_split('/\s+/', $client_name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $surname     = $parts ? (string)end($parts) : $client_name;
        // Kod grupy: z kursu rozliczenia; dla rozliczenia łącznego — kody wszystkich aktywnych grup
        $group_code  = trim((string)($b['course_group_code'] ?? ''));
        if ($group_code === '') {
            if ((int)($b['course_id'] ?? 0) > 0) {
                $group_code = (string)($b['course_name'] ?? '—');
            } else {
                $codes = db_all("SELECT COALESCE(NULLIF(c.group_code,''), c.name) AS code
                                 FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id
                                 WHERE e.client_id=? AND e.status='active' ORDER BY c.name", [(int)$b['client_id']]);
                $group_code = $codes ? implode(', ', array_column($codes, 'code')) : '—';
            }
        }
        $adj        = round((float)($b['adjustment'] ?? 0), 2);
        $base_amt   = round((float)$b['amount'], 2);
        $inv_no     = trim((string)($b['invoice_no'] ?? ''));
        $zl         = fn($x) => number_format((float)$x, 2, ',', ' ') . ' PLN';
        $period_ttl = ($b['course_name'] ? $b['course_name'] . ' — ' : 'Zajęcia TI — ') . $period;
        $extra_desc = trim((string)($b['adjustment_note'] ?? ''));
        if ($extra_desc === '') $extra_desc = abs($adj) > 0.005 ? ($adj > 0 ? 'opłata dodatkowa' : 'rabat') : 'brak';
        $transfer   = $inv_no !== ''
            ? 'Faktura nr ' . $inv_no . ' – ' . $surname
            : 'Rozliczenie ' . $period . ' – ' . $surname;

        $tpl_vars = [
            'org'             => h($org),
            'client_name'     => h($client_name),
            'surname'         => h($surname),
            'group_code'      => h($group_code),
            'invoice_no'      => h($inv_no),
            'invoice_subject' => $inv_no !== '' ? ' – faktura nr ' . $inv_no : '',
            'period_title'    => h($period_ttl),
            'amount_main'     => $zl($base_amt),
            'extra_desc'      => h($extra_desc),
            'extra_amount'    => $zl($adj),
            'total'           => $zl($base_amt + $adj),
            'transfer_title'  => h($transfer),
            'portal'          => h($portal),
        ];
        $r = function_exists('email_tpl_render')
            ? email_tpl_render('ti_billing', $tpl_vars)
            : ['subject' => 'Rozliczenie należności za zajęcia'
                            . ($inv_no !== '' ? ' – faktura nr ' . $inv_no : '') . ' – ' . $surname,
               'html'    => '<p>Razem do zapłaty: <strong>' . $zl($base_amt + $adj) . '</strong></p>',
               'enabled' => true];
        try {
            mail_queue_add($email, $toName, $r['subject'], $r['html'], '', 'ti_billing', $billing_id, '', false);
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
        if (!sms_channel_ready()) return false;
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
        'pass_pct'        => max(0, min(100, (int)($data['pass_pct'] ?? 0))),
        'retake_pass_pct' => max(0, min(100, (int)($data['retake_pass_pct'] ?? 0))),
        'shuffle'         => !empty($data['shuffle'])    ? 1 : 0,
        'is_active'       => !empty($data['is_active'])  ? 1 : 0,
        'sync_grade'      => !empty($data['sync_grade']) ? 1 : 0,
        'bank_draw'       => max(0, (int)($data['bank_draw'] ?? 0)),
        'fixed_draw'      => max(0, (int)($data['fixed_draw'] ?? 0)),
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

/** Pytania zwykłe (zawsze w teście). */
function k30_ti_test_fixed_questions(int $test_id): array {
    return db_all("SELECT * FROM k30_ti_test_questions WHERE test_id=? AND in_bank=0 ORDER BY position, id", [$test_id]);
}

/** Pytania z bazy (pula do losowania). */
function k30_ti_test_bank_questions(int $test_id): array {
    return db_all("SELECT * FROM k30_ti_test_questions WHERE test_id=? AND in_bank=1 ORDER BY position, id", [$test_id]);
}

/**
 * Pytania testu do wyświetlenia w podejściu.
 * Jeśli podejście ma drawn_ids, użyj ich. Inaczej zwróć wszystkie (backward compat).
 */
function k30_ti_test_questions_for_attempt(array $attempt): array {
    if (!empty($attempt['drawn_ids'])) {
        $ids = array_filter(array_map('intval', json_decode($attempt['drawn_ids'], true) ?: []));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $qs = db_all("SELECT * FROM k30_ti_test_questions WHERE id IN ($ph)", $ids);
        // Zachowaj kolejność z drawn_ids
        $map = [];
        foreach ($qs as $q) $map[(int)$q['id']] = $q;
        return array_values(array_filter(array_map(fn($id) => $map[$id] ?? null, $ids)));
    }
    return k30_ti_test_questions((int)$attempt['test_id']);
}

function k30_ti_test_question_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_test_questions WHERE id=?", [$id]);
}

function k30_ti_test_options(int $question_id): array {
    return db_all("SELECT * FROM k30_ti_test_options WHERE question_id=? ORDER BY position, id", [$question_id]);
}

function k30_ti_test_max_score(int $test_id, ?array $drawn_ids = null): float {
    if ($drawn_ids !== null) {
        if (!$drawn_ids) return 0.0;
        $ph = implode(',', array_fill(0, count($drawn_ids), '?'));
        $r  = db_one("SELECT COALESCE(SUM(points),0) AS s FROM k30_ti_test_questions WHERE id IN ($ph) AND test_id=?", [...$drawn_ids, $test_id]);
    } else {
        $r = db_one("SELECT COALESCE(SUM(points),0) AS s FROM k30_ti_test_questions WHERE test_id=?", [$test_id]);
    }
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
        'type'    => $type,
        'prompt'  => trim((string)($data['prompt'] ?? '')),
        'points'  => max(0, (float)str_replace(',', '.', (string)($data['points'] ?? 1))) ?: 1,
        'in_bank' => isset($data['in_bank']) ? ((int)(bool)$data['in_bank']) : 0,
    ];
    if ($id) {
        db()->prepare("UPDATE k30_ti_test_questions SET type=?, prompt=?, points=?, in_bank=? WHERE id=?")
            ->execute([$f['type'], $f['prompt'], $f['points'], $f['in_bank'], $id]);
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
function k30_ti_test_start_attempt(int $test_id, int $client_id, string $label = ''): int {
    $open = db_one("SELECT id FROM k30_ti_test_attempts WHERE test_id=? AND client_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1", [$test_id, $client_id]);
    if ($open) return (int)$open['id'];

    $test        = k30_ti_test_get($test_id);
    $bank_draw   = (int)($test['bank_draw']  ?? 0);
    $fixed_draw  = (int)($test['fixed_draw'] ?? 0);
    $bank        = k30_ti_test_bank_questions($test_id);
    $fixed       = k30_ti_test_fixed_questions($test_id);

    // Losowanie pytań stałych
    if ($fixed_draw > 0 && $fixed) {
        shuffle($fixed);
        $fixed = array_slice($fixed, 0, min($fixed_draw, count($fixed)));
    }
    // Losowanie pytań z bazy
    if ($bank_draw > 0 && $bank) {
        shuffle($bank);
        $bank = array_slice($bank, 0, min($bank_draw, count($bank)));
    }

    $drawn_ids = null;
    if ($fixed_draw > 0 || $bank_draw > 0) {
        $all_picked = [...$fixed, ...$bank];
        $drawn_ids  = json_encode(array_map(fn($q) => (int)$q['id'], $all_picked));
    }

    $max = $drawn_ids !== null
        ? k30_ti_test_max_score($test_id, json_decode($drawn_ids, true))
        : k30_ti_test_max_score($test_id);

    return db_insert('k30_ti_test_attempts', [
        'test_id'       => $test_id, 'client_id' => $client_id,
        'status'        => 'in_progress', 'max_score' => $max,
        'drawn_ids'     => $drawn_ids,
        'attempt_label' => $label,
    ]);
}

/**
 * Zapisz i oceń podejście. $answers: question_id => ['option_ids'=>[..], 'text'=>..].
 * Pytania zamknięte oceniane automatycznie, otwarte → ręczna ocena (needs_review).
 */
function k30_ti_test_submit(int $attempt_id, array $answers): void {
    $att = k30_ti_test_attempt_get($attempt_id);
    if (!$att || $att['status'] !== 'in_progress') return;
    $questions = k30_ti_test_questions_for_attempt($att);
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
    // Oceny wyłączone dla kursu lub osoby → nie zapisuj do e-dziennika
    if (!k30_ti_grades_allowed((int)$test['course_id'], (int)$att['client_id'])) return;
    $max = (float)$att['max_score'];
    if ($max <= 0) return;
    $pct  = 100 * (float)$att['score'] / $max;
    // Skala szkolna z procentów
    $grade = $pct >= 90 ? '5' : ($pct >= 75 ? '4' : ($pct >= 60 ? '3' : ($pct >= 50 ? '2' : '1')));
    $vnum  = (float)$grade;
    $label = trim((string)($att['attempt_label'] ?? ''));
    $desc  = 'Test: ' . ($test['title'] ?? '') . ($label !== '' ? " [$label]" : '') . ' (' . round($pct) . '%)';
    // Deduplikacja po attempt_id — odporna na zmianę opisu po dograniu pytań otwartych
    $existing = db_one("SELECT id FROM k30_ti_grades WHERE attempt_id=?", [$attempt_id]);
    if ($existing) {
        db()->prepare("UPDATE k30_ti_grades SET value_text=?, value_num=?, description=?, graded_at=datetime('now') WHERE id=?")
            ->execute([$grade, $vnum, $desc, (int)$existing['id']]);
    } else {
        db_insert('k30_ti_grades', [
            'course_id'      => (int)$test['course_id'],
            'client_id'      => (int)$att['client_id'],
            'attempt_id'     => $attempt_id,
            'category'       => 'sprawdzian',
            'value_text'     => $grade,
            'value_num'      => $vnum,
            'weight'         => 3,
            'description'    => $desc,
            'graded_by_text' => 'System',
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

const TI_AVAIL_STATUS = [
    'approved' => ['label' => 'Zatwierdzona',    'color' => 'success'],
    'draft'    => ['label' => 'Planowana (szkic)', 'color' => 'warning'],
];

const TI_WEEKLY_DH_MIN   = 45;   // 1 godzina dydaktyczna = 45 min
const TI_WEEKLY_MAX_DH    = 4;    // maks. 4 godziny dydaktyczne dziennie
const TI_WEEKLY_MAX_MIN   = TI_WEEKLY_DH_MIN * TI_WEEKLY_MAX_DH;  // = 180 min

/**
 * Aktywne okna dostępności prowadzącego, posortowane Pn→Nd, potem od godziny.
 * @param string|null $status  null = wszystkie, 'approved'|'draft' = filtr
 */
function ti_instructor_availability(int $instructor_id, ?string $status = null): array {
    if (!$instructor_id) return [];
    $where = "instructor_id=? AND is_active=1";
    $params = [$instructor_id];
    if ($status !== null) { $where .= " AND status=?"; $params[] = $status; }
    return db_all(
        "SELECT * FROM k30_ti_instructor_availability
         WHERE $where ORDER BY (day_of_week + 6) % 7, time_from",
        $params
    );
}

/**
 * Dodaj okno dostępności. Zwraca false przy błędnych godzinach.
 * $valid_from/$valid_to (YYYY-MM-DD, '' = bezterminowo) ograniczają
 * obowiązywanie okna — np. dostępność tylko na czas tury zapisów.
 */
function ti_avail_add(int $instructor_id, int $dow, string $from, string $to, string $status = 'approved',
                      string $valid_from = '', string $valid_to = '', string $notes = ''): bool {
    $from = substr(trim($from), 0, 5);
    $to   = substr(trim($to), 0, 5);
    if (!$instructor_id || $dow < 0 || $dow > 6) return false;
    if ($from === '' || $to === '' || ti_hm2min($from) >= ti_hm2min($to)) return false;
    if (!array_key_exists($status, TI_AVAIL_STATUS)) $status = 'approved';
    $vf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_from) ? $valid_from : null;
    $vt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_to)   ? $valid_to   : null;
    if ($vf && $vt && $vt < $vf) return false;
    // Identyczne okno (dzień, godziny, obowiązywanie) nie dubluje się —
    // duplikaty rozjeżdżały wydruki i podwajały sloty w generatorze
    $dup = db_one(
        "SELECT 1 FROM k30_ti_instructor_availability
          WHERE instructor_id=? AND day_of_week=? AND time_from=? AND time_to=? AND is_active=1
            AND COALESCE(valid_from,'') = COALESCE(?,'') AND COALESCE(valid_to,'') = COALESCE(?,'')",
        [$instructor_id, $dow, $from, $to, $vf, $vt]);
    if ($dup) return true;   // okno już istnieje — cicho OK, bez drugiego wiersza
    db_insert('k30_ti_instructor_availability', [
        'instructor_id' => $instructor_id, 'day_of_week' => $dow,
        'time_from' => $from, 'time_to' => $to, 'is_active' => 1, 'status' => $status,
        'valid_from' => $vf, 'valid_to' => $vt, 'notes' => substr(trim($notes), 0, 200),
    ]);
    return true;
}

/**
 * Aktualizuje okno dostępności (dzień, godziny, ważność, notatka).
 * Zwraca false przy błędnych danych — walidacja jak w ti_avail_add.
 */
function ti_avail_update(int $id, int $instructor_id, int $dow, string $from, string $to,
                         string $valid_from = '', string $valid_to = '', string $notes = ''): bool {
    $from = substr(trim($from), 0, 5);
    $to   = substr(trim($to), 0, 5);
    if (!$id || !$instructor_id || $dow < 0 || $dow > 6) return false;
    if ($from === '' || $to === '' || ti_hm2min($from) >= ti_hm2min($to)) return false;
    $vf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_from) ? $valid_from : null;
    $vt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_to)   ? $valid_to   : null;
    if ($vf && $vt && $vt < $vf) return false;
    db_exec(
        "UPDATE k30_ti_instructor_availability
            SET day_of_week=?, time_from=?, time_to=?, valid_from=?, valid_to=?, notes=?
          WHERE id=? AND instructor_id=?",
        [$dow, $from, $to, $vf, $vt, substr(trim($notes), 0, 200), $id, $instructor_id]
    );
    return true;
}

/** Zatwierdza wszystkie szkice okien prowadzącego. Zwraca liczbę zatwierdzonych. */
function ti_avail_approve_all(int $instructor_id): int {
    $st = db()->prepare("UPDATE k30_ti_instructor_availability
                          SET status='approved' WHERE instructor_id=? AND status='draft' AND is_active=1");
    $st->execute([$instructor_id]);
    return $st->rowCount();
}

/**
 * Zatwierdzone okna prowadzącego obowiązujące danego dnia (ważność + dzień
 * tygodnia). Wspólna definicja dla generatora terminów i walidacji zajęć.
 */
function ti_avail_windows_for_day(int $instructor_id, string $date): array {
    $dow = (int)date('w', strtotime($date));
    return db_all(
        "SELECT * FROM k30_ti_instructor_availability
          WHERE instructor_id=? AND is_active=1 AND status='approved' AND day_of_week=?
            AND (valid_from IS NULL OR valid_from <= ?)
            AND (valid_to   IS NULL OR valid_to   >= ?)
          ORDER BY time_from",
        [$instructor_id, $dow, $date, $date]
    );
}

function ti_avail_delete(int $id, int $instructor_id): void {
    db()->prepare("DELETE FROM k30_ti_instructor_availability WHERE id=? AND instructor_id=?")
        ->execute([$id, $instructor_id]);
}

function ti_avail_set_status(int $id, int $instructor_id, string $status): void {
    if (!array_key_exists($status, TI_AVAIL_STATUS)) return;
    db_exec("UPDATE k30_ti_instructor_availability SET status=? WHERE id=? AND instructor_id=?",
            [$status, $id, $instructor_id]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  TYGODNIOWY PLAN ZAJĘĆ CYKLICZNYCH (k30_ti_weekly_plan)
// ═══════════════════════════════════════════════════════════════════════════

const TI_WEEKLY_STATUS = [
    'draft'    => ['label' => 'Szkic',          'color' => 'warning'],
    'approved' => ['label' => 'Zatwierdzona',   'color' => 'success'],
];

function ti_weekly_plan_list(int $instructor_id): array {
    return db_all(
        "SELECT wp.*, c.name AS course_name, c.duration_min AS course_dur
         FROM k30_ti_weekly_plan wp
         JOIN k30_ti_courses c ON c.id = wp.course_id
         WHERE wp.instructor_id=?
         ORDER BY (wp.day_of_week + 6) % 7, wp.time_from",
        [$instructor_id]
    );
}


/**
 * Generuje lekcje z tygodniowego plannera godzin w podanym zakresie dat.
 *
 * Planner godzin (k30_ti_weekly_plan) opisywał tylko wzorzec tygodnia i nie miał
 * odbiorcy — slajdy nie stawały się nigdy lekcjami. Ta funkcja jest tym odbiorcą:
 * dla każdego slotu przechodzi po datach zakresu wypadających w jego dniu
 * tygodnia i zakłada lekcję, respektując te same reguły co pozostałe ścieżki
 * dodawania zajęć:
 *   • dni wolne i przerwy      → [[project_ti_periods]] ti_date_is_off(),
 *   • zamknięty okres          → ti_period_closed_for_date(),
 *   • dostępność prowadzącego  → ti_instructor_available_at(),
 *   • zajętość konta Zoom      → ti_zoom_slot_check() ([[project_ti_zoom_busy]]),
 *   • duplikat (ta sama grupa, data i godzina) → pomijany.
 *
 * @param array $opts only_approved (bool, domyślnie true), course_id (0 = wszystkie),
 *                    skip_off_days (bool, domyślnie true), status ('planned'),
 *                    limit (bezpiecznik, domyślnie 400 lekcji)
 * @return array{created:int, skipped:array<string,int>, reasons:array<int,string>, slots:int}
 */
function ti_weekly_plan_generate(int $instructor_id, string $date_from, string $date_to, array $opts = []): array {
    $only_approved = !array_key_exists('only_approved', $opts) || !empty($opts['only_approved']);
    $course_id     = (int)($opts['course_id'] ?? 0);
    $skip_off      = !array_key_exists('skip_off_days', $opts) || !empty($opts['skip_off_days']);
    $status        = (string)($opts['status'] ?? 'planned');
    if (!in_array($status, ['planned', 'draft'], true)) $status = 'planned';
    $limit         = max(1, min(1000, (int)($opts['limit'] ?? 400)));

    $out = [
        'created' => 0,
        'skipped' => ['exists' => 0, 'off' => 0, 'closed' => 0, 'avail' => 0, 'zoom' => 0, 'limit' => 0],
        'reasons' => [],
        'slots'   => 0,
    ];
    $from = trim($date_from); $to = trim($date_to);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
        throw new \RuntimeException('Podaj poprawny zakres dat (od ≤ do).');
    }

    $sql = "SELECT wp.*, c.name AS course_name
              FROM k30_ti_weekly_plan wp
              JOIN k30_ti_courses c ON c.id = wp.course_id
             WHERE wp.instructor_id = ? AND c.status != 'cancelled'";
    $params = [$instructor_id];
    if ($only_approved) { $sql .= " AND wp.status = 'approved'"; }
    if ($course_id)     { $sql .= " AND wp.course_id = ?"; $params[] = $course_id; }
    $sql .= " ORDER BY wp.day_of_week, wp.time_from";
    $slots = db_all($sql, $params);
    $out['slots'] = count($slots);
    if (!$slots) return $out;

    // Slajdy pogrupowane po dniu tygodnia — po datach chodzimy raz
    $by_dow = [];
    foreach ($slots as $s) { $by_dow[(int)$s['day_of_week']][] = $s; }

    require_once __DIR__ . '/ti_periods.php';

    $enrollees = [];   // course_id => [client_id, ...] (raz na kurs)
    $note      = 'Z plannera godzin';

    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $dow = (int)date('w', strtotime($d));
        if (empty($by_dow[$dow])) continue;

        if ($skip_off && ti_date_is_off($d)) {
            $out['skipped']['off'] += count($by_dow[$dow]);
            continue;
        }
        if ($pc = ti_period_closed_for_date($d)) {
            $out['skipped']['closed'] += count($by_dow[$dow]);
            if (count($out['reasons']) < 10) $out['reasons'][] = date('d.m.Y', strtotime($d)) . ': ' . ti_period_closed_msg($pc);
            continue;
        }

        foreach ($by_dow[$dow] as $s) {
            if ($out['created'] >= $limit) { $out['skipped']['limit']++; continue; }

            $cid  = (int)$s['course_id'];
            $tf   = substr((string)$s['time_from'], 0, 5);
            $dur  = max(15, (int)$s['duration_min']);
            $tt   = sprintf('%02d:%02d', intdiv(ti_hm2min($tf) + $dur, 60), (ti_hm2min($tf) + $dur) % 60);

            // Duplikat: ta sama grupa, data i godzina rozpoczęcia
            $dup = db_one(
                "SELECT id FROM k30_ti_sessions WHERE course_id=? AND lesson_date=? AND time_from=?",
                [$cid, $d, $tf]
            );
            if ($dup) { $out['skipped']['exists']++; continue; }

            $av = ti_instructor_available_at(ti_course_instructor_id($cid), $d, $tf, $tt);
            if (!$av['ok']) {
                $out['skipped']['avail']++;
                if (count($out['reasons']) < 10) $out['reasons'][] = date('d.m.Y', strtotime($d)) . ' ' . $tf . ': ' . $av['reason'];
                continue;
            }

            $zc = ti_zoom_slot_check($cid, '', $d, $tf, $tt);
            if (!$zc['ok']) {
                $out['skipped']['zoom']++;
                if (count($out['reasons']) < 10) $out['reasons'][] = date('d.m.Y', strtotime($d)) . ' ' . $tf . ': ' . $zc['reason'];
                continue;
            }

            $sid = db_insert('k30_ti_sessions', [
                'course_id'    => $cid,
                'lesson_date'  => $d,
                'time_from'    => $tf,
                'time_to'      => $tt,
                'duration_min' => $dur,
                'status'       => $status,
                'topic'        => trim((string)($s['notes'] ?? '')),
                'notes'        => $note,
                'created_by'   => $instructor_id,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            if (!isset($enrollees[$cid])) {
                $enrollees[$cid] = array_map(
                    fn($r) => (int)$r['client_id'],
                    db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid])
                );
            }
            foreach ($enrollees[$cid] as $client_id) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => $client_id, 'attended' => 0]); }
                catch (\Throwable $e) {}
            }
            $out['created']++;
        }
    }
    return $out;
}

/** Podsumowanie generowania jednym zdaniem — do komunikatu w panelu. */
function ti_weekly_plan_generate_msg(array $r): string {
    $msg = 'Utworzono lekcji: ' . (int)$r['created'] . '.';
    $parts = [];
    $labels = [
        'exists' => 'już istniały', 'off' => 'dni wolne', 'closed' => 'zamknięty okres',
        'avail'  => 'poza dostępnością', 'zoom' => 'zajęty Zoom', 'limit' => 'limit bezpieczeństwa',
    ];
    foreach ($labels as $k => $lbl) {
        if (!empty($r['skipped'][$k])) $parts[] = $lbl . ': ' . (int)$r['skipped'][$k];
    }
    if ($parts) $msg .= ' Pominięto — ' . implode(', ', $parts) . '.';
    return $msg;
}

function ti_weekly_plan_save(int $instructor_id, int $course_id, int $dow,
                              string $time_from, int $duration_min,
                              string $status = 'draft', string $notes = ''): int {
    $time_from    = substr(trim($time_from), 0, 5);
    $duration_min = max(15, min(480, $duration_min));
    $dow          = max(0, min(6, $dow));
    if (!array_key_exists($status, TI_WEEKLY_STATUS)) $status = 'draft';

    $existing = db_one(
        "SELECT id FROM k30_ti_weekly_plan WHERE course_id=? AND day_of_week=?",
        [$course_id, $dow]
    );
    if ($existing) {
        db_exec(
            "UPDATE k30_ti_weekly_plan SET instructor_id=?, time_from=?, duration_min=?,
             status=?, notes=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?",
            [$instructor_id, $time_from, $duration_min, $status, trim($notes), $existing['id']]
        );
        return (int)$existing['id'];
    }
    db_exec(
        "INSERT INTO k30_ti_weekly_plan
         (instructor_id, course_id, day_of_week, time_from, duration_min, status, notes)
         VALUES (?,?,?,?,?,?,?)",
        [$instructor_id, $course_id, $dow, $time_from, $duration_min, $status, trim($notes)]
    );
    return (int)db()->lastInsertId();
}

function ti_weekly_plan_delete(int $id, int $instructor_id): void {
    db_exec("DELETE FROM k30_ti_weekly_plan WHERE id=? AND instructor_id=?", [$id, $instructor_id]);
}

function ti_weekly_plan_set_status(int $id, int $instructor_id, string $status): void {
    if (!array_key_exists($status, TI_WEEKLY_STATUS)) return;
    db_exec("UPDATE k30_ti_weekly_plan SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND instructor_id=?",
            [$status, $id, $instructor_id]);
}

/**
 * Greedy auto-assign wszystkich niezaplanowanych kursów prowadzącego.
 * Respektuje dostępność (status='approved') i limit TI_WEEKLY_MAX_MIN na dzień.
 * Zwraca liczbę przypisanych kursów.
 */
function ti_weekly_autoassign(int $instructor_id): int {
    $avail = ti_instructor_availability($instructor_id, 'approved');
    if (!$avail) return 0;

    // Zbuduj mapę dostępności per dzień
    $avail_by_dow = [];
    foreach ($avail as $a) { $avail_by_dow[(int)$a['day_of_week']][] = $a; }

    // Istniejące sloty — sumy minut per dzień
    $existing = ti_weekly_plan_list($instructor_id);
    $day_used = [];  // dow => minutes used
    $assigned_courses = [];
    foreach ($existing as $e) {
        $dow = (int)$e['day_of_week'];
        $day_used[$dow] = ($day_used[$dow] ?? 0) + (int)$e['duration_min'];
        $assigned_courses[$e['course_id']][$dow] = true;
    }

    // Nieprzypisane kursy — pobierz aktywne bez pełnego pokrycia
    $courses = db_all(
        "SELECT c.id, c.name, c.duration_min FROM k30_ti_courses c
         WHERE c.instructor_id=? AND c.is_active=1 AND c.status!='cancelled'
         ORDER BY c.duration_min ASC, c.name ASC",
        [$instructor_id]
    );

    $assigned = 0;
    foreach ($courses as $c) {
        $cid     = (int)$c['id'];
        $dur     = (int)($c['duration_min'] ?: 90);

        // Sprawdź czy kurs ma już slot na każdy dzień, w którym jest dostępność
        // — jeśli nie, spróbuj przypisać do pierwszego wolnego
        $sorted_dows = array_keys($avail_by_dow);
        // Sort: Pn-Sb (1..6,0)
        usort($sorted_dows, fn($a, $b) => (($a + 6) % 7) - (($b + 6) % 7));

        foreach ($sorted_dows as $dow) {
            if (isset($assigned_courses[$cid][$dow])) continue;  // już zaplanowany ten dzień

            $used = $day_used[$dow] ?? 0;
            if ($used + $dur > TI_WEEKLY_MAX_MIN) continue;      // przekroczyłoby limit

            // Znajdź pierwszą wolną godzinę w dostępności tego dnia
            $windows = $avail_by_dow[$dow];
            usort($windows, fn($a, $b) => strcmp($a['time_from'], $b['time_from']));

            // Istniejące sloty w tym dniu (do sprawdzenia kolizji)
            $slots_that_day = array_filter($existing, fn($e) => (int)$e['day_of_week'] === $dow);

            foreach ($windows as $win) {
                $win_start = ti_hm2min($win['time_from']);
                $win_end   = ti_hm2min($win['time_to']);
                if ($win_end - $win_start < $dur) continue;  // okno za krótkie

                // Znajdź pierwszy wolny czas w oknie
                $candidate = $win_start;
                foreach ($slots_that_day as $s) {
                    $s_start = ti_hm2min($s['time_from']);
                    $s_end   = $s_start + (int)$s['duration_min'];
                    if ($candidate >= $s_start && $candidate < $s_end) {
                        $candidate = $s_end;
                    }
                }
                if ($candidate + $dur > $win_end) continue;

                $tf = sprintf('%02d:%02d', intdiv($candidate, 60), $candidate % 60);
                ti_weekly_plan_save($instructor_id, $cid, $dow, $tf, $dur, 'draft');
                $day_used[$dow] = ($day_used[$dow] ?? 0) + $dur;
                $assigned_courses[$cid][$dow] = true;
                // Dorzuć do $existing dla kolejnych iteracji kolizji
                $existing[] = ['day_of_week' => $dow, 'time_from' => $tf, 'duration_min' => $dur, 'course_id' => $cid];
                $slots_that_day[] = ['day_of_week' => $dow, 'time_from' => $tf, 'duration_min' => $dur, 'course_id' => $cid];
                $assigned++;
                break;  // jedno przypisanie per kurs per uruchomienie
            }
        }
    }
    return $assigned;
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


// ═══════════════════════════════════════════════════════════════════════════
//  ZAJĘTOŚĆ KONTA ZOOM vs TERMINY LEKCJI
//  Wszystkie spotkania SZO powstają na JEDNYM koncie hosta (ustawienie
//  zoom_user_id), a jeden host nie prowadzi dwóch spotkań jednocześnie —
//  nakładające się lekcje zdalne są więc niewykonalne technicznie.
//  Sprawdzamy dwa źródła zajętości:
//   1) lekcje w SZO korzystające z Zooma (k30_ti_sessions) — bo stałe linki
//      kursów to spotkania typu 3 (bez terminu) i API ich nie zna,
//   2) spotkania z ustalonym terminem na koncie hosta z API Zoom — łapie
//      także spotkania utworzone poza SZO (ZoomAPI::busy_slots()).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Czy lekcja odbywa się przez Zoom (a więc obciąża konto hosta).
 * Brak wybranej metody = lekcja dziedziczy stały link Zoom kursu, jeśli kurs go ma.
 */
function ti_lesson_uses_zoom(int $course_id, string $lesson_method, ?string $course_zoom_meeting_id = null): bool {
    if ($lesson_method === 'zdalna_zoom') return true;
    if ($lesson_method !== '')            return false;   // stacjonarna / zdalna_inne
    if ($course_zoom_meeting_id === null) {
        if (!$course_id) return false;
        try {
            $course_zoom_meeting_id = (string)(
                db_one("SELECT zoom_meeting_id FROM k30_ti_courses WHERE id=?", [$course_id])['zoom_meeting_id'] ?? ''
            );
        } catch (\Throwable $e) { return false; }
    }
    return trim((string)$course_zoom_meeting_id) !== '';
}

/**
 * Zajętość konta hosta z API Zoom — cache w obrębie requestu (seria lekcji
 * odpytuje Zoom raz, nie 52 razy). $refresh=true wymusza ponowne odpytanie,
 * np. po utworzeniu lub usunięciu spotkania w tym samym żądaniu.
 *
 * @return array{ok:bool, slots:array, error:string}
 */
function ti_zoom_api_busy(bool $refresh = false): array {
    static $cache = null;
    if ($refresh)            $cache = null;
    if ($cache !== null)     return $cache;

    require_once __DIR__ . '/zoom.php';
    if (!zoom_enabled()) {
        return $cache = ['ok' => false, 'slots' => [], 'error' => 'Integracja Zoom nie jest skonfigurowana.'];
    }
    try {
        $api = new ZoomAPI();
        return $cache = ['ok' => true, 'slots' => $api->busy_slots(), 'error' => ''];
    } catch (\Throwable $e) {
        try { (new ZoomAPI())->log('busy_check', '', 'error', $e->getMessage()); } catch (\Throwable $e2) {}
        return $cache = ['ok' => false, 'slots' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Czy termin lekcji zmieści się w zajętości konta Zoom.
 *
 * @param int    $skip_session_id  Lekcja edytowana (nie koliduje sama ze sobą).
 * @return array{ok:bool, checked:bool, api_ok:bool, reason:string, warning:string}
 *   ok=false      → termin zajęty (blokada),
 *   checked=false → nie było czego sprawdzać (Zoom wyłączony, lekcja nie przez
 *                   Zoom albo lekcja bez godzin — okna czasowego nie da się wyznaczyć),
 *   api_ok=false  → API Zoom nie odpowiedziało; sprawdzono tylko lekcje z SZO (warning).
 */
function ti_zoom_slot_check(
    int    $course_id,
    string $lesson_method,
    string $date,
    string $time_from,
    string $time_to,
    int    $skip_session_id = 0
): array {
    $res = ['ok' => true, 'checked' => false, 'api_ok' => true, 'reason' => '', 'warning' => ''];

    require_once __DIR__ . '/zoom.php';
    if (!zoom_enabled())                                         return $res;
    if (!ti_lesson_uses_zoom($course_id, $lesson_method))        return $res;

    $date = trim($date); $tf = trim($time_from); $tt = trim($time_to);
    if ($date === '' || $tf === '' || $tt === '')                return $res;
    if (ti_hm2min($tt) <= ti_hm2min($tf))                        return $res;
    $res['checked'] = true;

    // ── 1) Lekcje SZO na tym samym koncie hosta ────────────────────────────────
    try {
        $rows = db_all(
            "SELECT s.id, s.course_id, s.time_from, s.time_to, s.lesson_method,
                    c.name AS course_name, c.zoom_meeting_id
               FROM k30_ti_sessions s
               JOIN k30_ti_courses  c ON c.id = s.course_id
              WHERE s.lesson_date = ?
                AND s.status NOT IN ('cancelled','draft')
                AND s.time_from != '' AND s.time_to != ''
                AND s.time_from < ? AND s.time_to > ?"
            . ($skip_session_id ? " AND s.id != ?" : ''),
            $skip_session_id ? [$date, $tt, $tf, $skip_session_id] : [$date, $tt, $tf]
        );
    } catch (\Throwable $e) { $rows = []; }

    foreach ($rows as $r) {
        if (!ti_lesson_uses_zoom(
            (int)$r['course_id'], (string)($r['lesson_method'] ?? ''), (string)($r['zoom_meeting_id'] ?? '')
        )) continue;
        $res['ok']     = false;
        $res['reason'] = 'Konto Zoom jest w tym czasie zajęte — lekcja kursu „' . (string)$r['course_name'] . '" '
            . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5)
            . ' (' . date('d.m.Y', strtotime($date)) . '). Jeden host Zoom nie prowadzi dwóch spotkań jednocześnie.';
        return $res;
    }

    // ── 2) Spotkania z terminem na koncie hosta (API Zoom, także spoza SZO) ────
    $api = ti_zoom_api_busy();
    if (!$api['ok']) {
        $res['api_ok']  = false;
        $res['warning'] = 'Nie udało się sprawdzić zajętości w API Zoom (' . $api['error']
            . ') — weryfikacja objęła tylko lekcje zaplanowane w SZO.';
        return $res;
    }
    $win_from = strtotime($date . ' ' . $tf);
    $win_to   = strtotime($date . ' ' . $tt);
    foreach ($api['slots'] as $s) {
        $sf = strtotime((string)($s['start'] ?? ''));
        $st = strtotime((string)($s['end']   ?? ''));
        if (!$sf || !$st) continue;
        if ($sf < $win_to && $st > $win_from) {
            $res['ok']     = false;
            $res['reason'] = 'Konto Zoom jest w tym czasie zajęte spotkaniem „' . (string)$s['topic'] . '" ('
                . date('d.m.Y H:i', $sf) . '–' . date('H:i', $st) . ') zaplanowanym w Zoomie.';
            return $res;
        }
    }
    return $res;
}

/**
 * Zajętość Zoom dla wielu dat o tych samych godzinach (seria / zajęcia stałe).
 *
 * @param string[] $dates
 * @return array{ok:bool, checked:bool, api_ok:bool, conflicts:array<int,array{date:string,reason:string}>, warning:string}
 */
function ti_zoom_dates_check(int $course_id, string $lesson_method, array $dates, string $time_from, string $time_to): array {
    $out = ['ok' => true, 'checked' => false, 'api_ok' => true, 'conflicts' => [], 'warning' => ''];
    foreach ($dates as $d) {
        $d = trim((string)$d);
        if ($d === '') continue;
        $c = ti_zoom_slot_check($course_id, $lesson_method, $d, $time_from, $time_to);
        if ($c['checked'])  $out['checked'] = true;
        if (!$c['api_ok']) { $out['api_ok'] = false; $out['warning'] = $c['warning']; }
        if (!$c['ok'])     { $out['ok'] = false; $out['conflicts'][] = ['date' => $d, 'reason' => $c['reason']]; }
    }
    return $out;
}

/** Komunikat o kolizjach Zoom w serii terminów: pierwsze trzy daty + licznik. */
function ti_zoom_conflicts_msg(array $conflicts): string {
    $n = count($conflicts);
    if (!$n) return '';
    $dates = array_map(fn($c) => date('d.m.Y', strtotime((string)$c['date'])), array_slice($conflicts, 0, 3));
    return 'Kolizja z zajętością konta Zoom w ' . $n . ' ' . ($n === 1 ? 'terminie' : 'terminach') . ': '
        . implode(', ', $dates) . ($n > 3 ? ' (+' . ($n - 3) . ')' : '') . '. '
        . (string)($conflicts[0]['reason'] ?? '');
}

// ═══════════════════════════════════════════════════════════════════════════
//  OCENY — włączanie/wyłączanie per kurs i per osoba (e-dziennik)
// ═══════════════════════════════════════════════════════════════════════════

/** Czy w kursie w ogóle prowadzi się oceny? */
function k30_ti_course_grades_enabled(int $course_id): bool {
    $r = db_one("SELECT grades_enabled FROM k30_ti_courses WHERE id=?", [$course_id]);
    return $r === null ? true : (int)($r['grades_enabled'] ?? 1) === 1;
}

/** Czy kurs liczy frekwencję (obecność/nieobecność)? 0 = wyłączone dla kursu.
 *  Odporne na brak kolumny (przed migracją) — wtedy domyślnie liczy frekwencję. */
function k30_ti_course_tracks_attendance(int $course_id): bool {
    try {
        $r = db_one("SELECT track_attendance FROM k30_ti_courses WHERE id=?", [$course_id]);
    } catch (\Throwable $e) {
        return true; // kolumna jeszcze nie istnieje — nie blokuj
    }
    return $r === null ? true : (int)($r['track_attendance'] ?? 1) === 1;
}

// ── Alert niskiej frekwencji ──────────────────────────────────────────────────

/** Próg alertu niskiej frekwencji w % (ustawienie ti_low_attendance_pct, domyślnie 50). */
function k30_ti_low_attendance_threshold(): int {
    $v = (int)(db_one("SELECT value FROM settings WHERE key_='ti_low_attendance_pct'")['value'] ?? 0);
    return ($v > 0 && $v <= 100) ? $v : 50;
}

/** Czy alerty niskiej frekwencji są włączone (ti_low_attendance_enabled; domyślnie tak). */
function k30_ti_low_attendance_enabled(): bool {
    $r = db_one("SELECT value FROM settings WHERE key_='ti_low_attendance_enabled'");
    return $r === null ? true : (string)$r['value'] !== '0';
}

/** Frekwencja kursanta w kursie: [present, countable, pct]. Liczy tylko lekcje
 *  held/individual_change bez odwołanego udziału (praca własna wykluczona). */
function k30_ti_client_course_attendance(int $course_id, int $client_id): array {
    $r = db_one(
        "SELECT
            SUM(CASE WHEN COALESCE(a.cancelled,0)=0 THEN 1 ELSE 0 END) AS countable,
            SUM(CASE WHEN a.attended=1 AND COALESCE(a.cancelled,0)=0 THEN 1 ELSE 0 END) AS present
         FROM k30_ti_attendance a
         JOIN k30_ti_sessions s ON s.id=a.session_id
         WHERE s.course_id=? AND a.client_id=? AND s.status IN ('held','individual_change')",
        [$course_id, $client_id]
    );
    $countable = (int)($r['countable'] ?? 0);
    $present   = (int)($r['present'] ?? 0);
    $pct = $countable > 0 ? (int)round($present / $countable * 100) : 100;
    return ['present' => $present, 'countable' => $countable, 'pct' => $pct];
}

/**
 * Sprawdza frekwencję kursanta w kursie i — gdy spadła poniżej progu — wysyła
 * jednorazowy alert (e-mail/SMS do kursanta i opiekuna). Reset dedupa, gdy
 * frekwencja wróci powyżej progu. Wołane po zapisie obecności.
 */
function k30_ti_check_low_attendance(int $course_id, int $client_id): void {
    if (!k30_ti_low_attendance_enabled())            return;
    if (!k30_ti_course_tracks_attendance($course_id)) return;

    $st = k30_ti_client_course_attendance($course_id, $client_id);
    if ($st['countable'] < 3) return; // za mało lekcji, by liczyć frekwencję

    $threshold = k30_ti_low_attendance_threshold();
    $enr = db_one("SELECT id, COALESCE(low_att_alerted,0) AS alerted FROM k30_ti_enrollments WHERE course_id=? AND client_id=? AND status='active'", [$course_id, $client_id]);
    if (!$enr) return;
    $already = (int)$enr['alerted'] === 1;

    if ($st['pct'] < $threshold && !$already) {
        db()->prepare("UPDATE k30_ti_enrollments SET low_att_alerted=1 WHERE id=?")->execute([(int)$enr['id']]);
        k30_ti_notify_low_attendance($course_id, $client_id, $st['pct'], $threshold);
    } elseif ($st['pct'] >= $threshold && $already) {
        db()->prepare("UPDATE k30_ti_enrollments SET low_att_alerted=0 WHERE id=?")->execute([(int)$enr['id']]);
    }
}

/** Powiadom kursanta (i opiekuna małoletniego) o niskiej frekwencji — e-mail + SMS. */
function k30_ti_notify_low_attendance(int $course_id, int $client_id, int $pct, int $threshold): void {
    $row = db_one(
        "SELECT c.name AS course_name, cl.name AS client_name, cl.email, cl.phone,
                a.is_minor, a.guardian_email, a.guardian_name, a.guardian_phone,
                a.notify_phone2, a.notify_phone2_verified, a.notify_phone3, a.notify_phone3_verified
         FROM k30_ti_courses c
         JOIN k30_clients cl ON cl.id=?
         LEFT JOIN k30_ti_student_accounts a ON a.client_id=cl.id AND a.is_active=1
         WHERE c.id=? LIMIT 1",
        [$client_id, $course_id]
    );
    if (!$row) return;
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $crs  = (string)$row['course_name'];
    $stu  = (string)$row['client_name'];
    $isMinor = !empty($row['is_minor']);

    // E-mail
    $emails = [];
    $primary = trim((string)($row['email'] ?? ''));
    if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = $stu;
    $gem = trim((string)($row['guardian_email'] ?? ''));
    if ($isMinor && $gem !== '' && filter_var($gem, FILTER_VALIDATE_EMAIL)) $emails[$gem] = $row['guardian_name'] ?: $stu;
    if ($emails) {
        if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
        if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';
        $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
        $r = function_exists('email_tpl_render') ? email_tpl_render('ti_low_attendance', [
            'org'         => $org,
            'client_name' => htmlspecialchars($stu, ENT_QUOTES),
            'course_name' => htmlspecialchars($crs, ENT_QUOTES),
            'pct'         => (string)$pct,
            'threshold'   => (string)$threshold,
            'url'         => htmlspecialchars($url, ENT_QUOTES),
        ]) : ['enabled' => true, 'subject' => '', 'html' => ''];
        if (!isset($r['enabled']) || $r['enabled']) {
            $subject = !empty($r['subject']) ? $r['subject'] : "{$org}: niska frekwencja — {$crs} ({$pct}%)";
            $html    = !empty($r['html']) ? $r['html'] :
                "<p>Frekwencja {$stu} na kursie <strong>{$crs}</strong> wynosi <strong>{$pct}%</strong> — poniżej progu {$threshold}%.</p>"
              . "<p>Prosimy o regularną obecność. Szczegóły w panelu kursanta.</p>";
            foreach ($emails as $addr => $nm) {
                try { mail_queue_add($addr, (string)$nm, $subject, $html, '', 'ti_low_attendance', $course_id, '', false); }
                catch (\Throwable $e) {}
            }
        }
    }

    // SMS (opt-in lekcje + numery zweryfikowane; opiekun małoletniego)
    require_once __DIR__ . '/sms.php';
    if (function_exists('sms_channel_ready') && sms_channel_ready()) {
        $nums = k30_ti_sms_numbers($row);
        if ($isMinor) { $gp = trim((string)($row['guardian_phone'] ?? '')); if ($gp !== '' && !in_array($gp, $nums, true)) $nums[] = $gp; }
        $msg = "Niska frekwencja: {$crs} — {$pct}% (prog {$threshold}%). Prosimy o regularna obecnosc.";
        foreach ($nums as $n) { try { sms_send($n, $msg); } catch (\Throwable $e) {} }
    }
}

/** Czy osoba ma globalnie włączone oceny w TI? */
function k30_ti_client_grades_enabled(int $client_id): bool {
    $r = db_one("SELECT ti_grades_enabled FROM k30_clients WHERE id=?", [$client_id]);
    return $r === null ? true : (int)($r['ti_grades_enabled'] ?? 1) === 1;
}

/** Czy dla danej osoby w danym kursie wolno wystawiać/oglądać oceny? (kurs ∧ osoba) */
function k30_ti_grades_allowed(int $course_id, int $client_id): bool {
    return k30_ti_course_grades_enabled($course_id) && k30_ti_client_grades_enabled($client_id);
}

// ═══════════════════════════════════════════════════════════════════════════
//  KOMUNIKACJA — odbiorcy wysyłki e-mail/SMS (per grupa / prowadzący / dzień)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Zbiór kursów na podstawie trybu filtra.
 *  - 'grupa'      → $opts['course_ids'] (lista id)
 *  - 'prowadzacy' → $opts['instructor_id'] (kursy prowadzone przez tę osobę)
 *  - 'dzien'      → $opts['date'] (kursy mające lekcję w danym dniu)
 */
function k30_ti_comm_course_ids(string $mode, array $opts): array {
    if ($mode === 'grupa') {
        return array_values(array_unique(array_filter(array_map('intval', (array)($opts['course_ids'] ?? [])))));
    }
    if ($mode === 'prowadzacy') {
        $iid = (int)($opts['instructor_id'] ?? 0);
        if (!$iid) return [];
        return array_map(fn($r) => (int)$r['id'], db_all("SELECT id FROM k30_ti_courses WHERE instructor_id=?", [$iid]));
    }
    if ($mode === 'dzien') {
        $d = trim((string)($opts['date'] ?? ''));
        if ($d === '') return [];
        return array_map(fn($r) => (int)$r['course_id'], db_all("SELECT DISTINCT course_id FROM k30_ti_sessions WHERE lesson_date=?", [$d]));
    }
    return [];
}

/** Unikalni aktywni kursanci z danych kursów (id, nazwa, e-mail, telefon). */
function k30_ti_comm_recipients(array $course_ids): array {
    $course_ids = array_values(array_unique(array_filter(array_map('intval', $course_ids))));
    if (!$course_ids) return [];
    $ph = implode(',', array_fill(0, count($course_ids), '?'));
    return db_all(
        "SELECT cl.id AS client_id, cl.name, cl.email, cl.phone, 'kursant' AS role
         FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.status='active' AND e.course_id IN ($ph)
         GROUP BY cl.id
         ORDER BY cl.name COLLATE NOCASE",
        $course_ids
    );
}

/**
 * Rodzice / opiekunowie małoletnich kursantów z danych kursów (dane opiekuna
 * z konta kursanta: guardian_name/guardian_email/guardian_phone), do wysyłki
 * komunikatów obok samych kursantów. Pomija konta bez kontaktu opiekuna.
 */
function k30_ti_comm_guardian_recipients(array $course_ids): array {
    $course_ids = array_values(array_unique(array_filter(array_map('intval', $course_ids))));
    if (!$course_ids) return [];
    $ph = implode(',', array_fill(0, count($course_ids), '?'));
    return db_all(
        "SELECT a.id AS client_id,
                'Opiekun: ' || cl.name AS name,
                a.guardian_email AS email,
                a.guardian_phone AS phone,
                'opiekun' AS role
         FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         JOIN k30_ti_student_accounts a ON a.client_id=e.client_id AND a.is_active=1 AND a.is_minor=1
         WHERE e.status='active' AND e.course_id IN ($ph)
           AND (a.guardian_email!='' OR a.guardian_phone!='')
         GROUP BY a.id
         ORDER BY cl.name COLLATE NOCASE",
        $course_ids
    );
}

/** Lista osób prowadzących (instruktorów) TI — id + nazwa. */
function k30_ti_instructors(): array {
    return db_all(
        "SELECT u.id, u.name, u.email FROM users u
         WHERE u.id IN (SELECT instructor_id FROM k30_ti_courses WHERE instructor_id IS NOT NULL)
            OR u.id IN (SELECT user_id FROM k30_ti_instructor_accounts)
         ORDER BY u.name COLLATE NOCASE"
    );
}

/**
 * Zapisuje wygenerowany PDF raportu TI jako pismo EZD w skonfigurowanej teczce.
 * Jeśli EZD wyłączone lub ustawienie ti_report_ezd_teczka_id nie skonfigurowane — cicho pomija.
 * Wywołuj w try-catch; nigdy nie powinno przerwać pobierania pliku przez użytkownika.
 */
function ti_report_to_ezd(string $pdfData, string $filename, string $title, int $user_id, bool $signed = false): void {
    if (!function_exists('module_enabled') || !module_enabled('ezd_enabled')) return;
    $teczka_id = (int)((db_one("SELECT value FROM settings WHERE key_='ti_report_ezd_teczka_id'") ?: [])['value'] ?? 0);
    if (!$teczka_id) return;
    if (!function_exists('ezd_pismo_create')) {
        require_once __DIR__ . '/ezd.php';
    }
    // Sprawa roczna w teczce — "Raporty TI YYYY"; znajdź lub utwórz
    $rok = (int)date('Y');
    $sprawa_title = 'Raporty TI ' . $rok;
    $sprawa = db_one("SELECT id FROM ezd_sprawy WHERE teczka_id=? AND title=? AND status!='closed' LIMIT 1",
                     [$teczka_id, $sprawa_title]);
    $sprawa_id = $sprawa ? (int)$sprawa['id'] : ezd_sprawa_create([
        'teczka_id'   => $teczka_id,
        'title'       => $sprawa_title,
        'description' => 'Automatycznie generowane raporty z modułu Dydaktyka TI.',
        'status'      => 'active',
        'owner_id'    => $user_id,
    ], $user_id);
    // Pismo wychodzące — raport wygenerowany przez organizację
    $pid = ezd_pismo_create([
        'sprawa_id'    => $sprawa_id,
        'kierunek'     => 'wychodzace',
        'title'        => ($signed ? '[Podpisany] ' : '') . $title,
        'status'       => 'zakonczone',
        'owner_id'     => $user_id,
        'data_pisma'   => date('Y-m-d'),
        'data_wysylki' => date('Y-m-d'),
    ], $user_id);
    // Zapisz bajty PDF do pliku tymczasowego i dołącz jako załącznik
    $tmp = sys_get_temp_dir() . '/ti_rpt_' . uniqid('', true) . '.pdf';
    if (@file_put_contents($tmp, $pdfData) !== false) {
        try { ezd_attach_path($tmp, $filename, $sprawa_id, $pid, $user_id); } catch (\Throwable $e) {}
        @unlink($tmp);
    }
}
