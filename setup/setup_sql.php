<?php
/**
 * Czysty SQL setup — wywoływany przez saas/master.php przy tworzeniu tenanta.
 * Przyjmuje $pdo jako argument zamiast globalnego db().
 * Użycie: setup_tenant_db(PDO $pdo)
 */

function setup_tenant_db(PDO $pdo): void {
    $sqls = [

    // ── Użytkownicy ──────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE, password VARCHAR(255),
        microsoft_id VARCHAR(255), role VARCHAR(50) DEFAULT 'viewer',
        is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        totp_secret TEXT, totp_confirmed INTEGER DEFAULT 0,
        twofa_method TEXT DEFAULT '', twofa_phone TEXT,
        totp_backup_codes TEXT, login_code TEXT DEFAULT NULL
    )",

    // ── Umowy ─────────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS umowy_zlecenie (
        id INTEGER PRIMARY KEY AUTOINCREMENT, numer_umowy VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'projekt', imie_nazwisko VARCHAR(255),
        pesel VARCHAR(11), adres TEXT, seria_nr_dowodu VARCHAR(50),
        urzad_skarbowy VARCHAR(255), rachunek_bankowy VARCHAR(50),
        przedmiot_zlecenia TEXT, data_zawarcia DATE, data_rozpoczecia DATE,
        data_zakonczenia DATE, wynagrodzenie_brutto DECIMAL(12,2),
        stawka_kwota DECIMAL(10,2), typ_stawki VARCHAR(20),
        liczba_godzin_planowana DECIMAL(8,2), sposob_rozliczenia VARCHAR(20),
        termin_platnosci VARCHAR(100), zus_skladki INTEGER DEFAULT 0,
        tytul_ubezpieczenia VARCHAR(255), zwolnienie_wiek INTEGER DEFAULT 0,
        zaliczka_podatek DECIMAL(10,2), kup VARCHAR(10),
        numer_projektu VARCHAR(255), opiekun VARCHAR(255),
        wymagany_rachunek INTEGER DEFAULT 0, data_zl_rachunku DATE,
        forma_podpisania VARCHAR(20), platforma_el VARCHAR(100),
        id_dokumentu_el VARCHAR(255), plik_potwierdzenia VARCHAR(500),
        plik_umowy VARCHAR(500), uwagi TEXT, nr_roboczy VARCHAR(100),
        nr_system VARCHAR(100), nr_rejestru VARCHAR(100),
        m365_konto INTEGER DEFAULT 0, m365_login VARCHAR(255),
        m365_user_id VARCHAR(255), m365_konto_aktywne INTEGER DEFAULT 0,
        m365_data_utworzenia DATETIME, m365_licencja_przypisana INTEGER DEFAULT 0,
        email VARCHAR(255), epodpis_dostawca VARCHAR(100),
        epodpis_nr_certyfikatu VARCHAR(255), epodpis_data_waznosci DATE,
        created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS umowy_uslugi (
        id INTEGER PRIMARY KEY AUTOINCREMENT, numer_umowy VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'projekt', nazwa_wykonawcy VARCHAR(255),
        nip_pesel VARCHAR(20), adres TEXT, rachunek_lub_faktura VARCHAR(255),
        przedmiot_uslugi TEXT, zakres_uslug TEXT, data_zawarcia DATE,
        data_rozpoczecia DATE, data_zakonczenia DATE,
        czas_nieokreslony INTEGER DEFAULT 0, okres_wypowiedzenia VARCHAR(100),
        wartosc_netto DECIMAL(12,2), wartosc_brutto DECIMAL(12,2),
        stawka_vat VARCHAR(10), waluta VARCHAR(10) DEFAULT 'PLN',
        harmonogram_platnosci VARCHAR(50), termin_platnosci_dni INTEGER,
        numer_projektu VARCHAR(255), wymagana_faktura INTEGER DEFAULT 1,
        opiekun VARCHAR(255), wymagany_protokol INTEGER DEFAULT 0,
        data_odbioru DATE, forma_podpisania VARCHAR(20),
        platforma_el VARCHAR(100), id_dokumentu_el VARCHAR(255),
        plik_potwierdzenia VARCHAR(500), plik_umowy VARCHAR(500),
        zalaczniki VARCHAR(1000), uwagi TEXT, nr_roboczy VARCHAR(100),
        nr_system VARCHAR(100), nr_rejestru VARCHAR(100),
        email VARCHAR(255), epodpis_dostawca VARCHAR(100),
        epodpis_nr_certyfikatu VARCHAR(255), epodpis_data_waznosci DATE,
        created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS umowy_wolontariat (
        id INTEGER PRIMARY KEY AUTOINCREMENT, numer_umowy VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'projekt', imie_nazwisko VARCHAR(255),
        pesel VARCHAR(11), adres TEXT, telefon VARCHAR(20), email VARCHAR(255),
        data_urodzenia DATE, niepelnoletni INTEGER DEFAULT 0,
        zgoda_opiekuna VARCHAR(500), przedmiot_porozumienia TEXT,
        miejsce_wolontariatu VARCHAR(255), data_zawarcia DATE,
        data_rozpoczecia DATE, data_zakonczenia DATE,
        bezterminowa INTEGER DEFAULT 0, godzin_tygodniowo DECIMAL(5,2),
        godzin_przepracowanych DECIMAL(8,2) DEFAULT 0,
        ubezpieczenie_nnw INTEGER DEFAULT 0, numer_polisy_nnw VARCHAR(100),
        ubezpieczenie_oc INTEGER DEFAULT 0, szkolenie_bhp INTEGER DEFAULT 0,
        data_szkolenia_bhp DATE, zwrot_kosztow INTEGER DEFAULT 0,
        zwrot_kosztow_opis TEXT, opiekun VARCHAR(255),
        projekt_program VARCHAR(255), forma_podpisania VARCHAR(20),
        platforma_el VARCHAR(100), id_dokumentu_el VARCHAR(255),
        plik_potwierdzenia VARCHAR(500), plik_umowy VARCHAR(500),
        uwagi TEXT, nr_roboczy VARCHAR(100), nr_system VARCHAR(100),
        nr_rejestru VARCHAR(100), m365_konto INTEGER DEFAULT 0,
        m365_login VARCHAR(255), m365_user_id VARCHAR(255),
        m365_konto_aktywne INTEGER DEFAULT 0, m365_data_utworzenia DATETIME,
        m365_licencja_przypisana INTEGER DEFAULT 0,
        epodpis_dostawca VARCHAR(100), epodpis_nr_certyfikatu VARCHAR(255),
        epodpis_data_waznosci DATE, adres_odbiorca TEXT,
        adres_linia1 TEXT, adres_linia2 TEXT, adres_kod_pocztowy TEXT,
        adres_miasto TEXT, adres_kraj TEXT DEFAULT 'PL',
        webngo_id INT, z_webngo INTEGER DEFAULT 0, webngo_numer_umowy TEXT,
        rodzic_imie_nazwisko TEXT, rodzic_email TEXT, rodzic_telefon TEXT,
        created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS umowy_dzielo (
        id INTEGER PRIMARY KEY AUTOINCREMENT, numer_umowy VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'projekt', imie_nazwisko VARCHAR(255),
        pesel VARCHAR(11), adres TEXT, urzad_skarbowy VARCHAR(255),
        rachunek_bankowy VARCHAR(50), opis_dziela TEXT, termin_oddania DATE,
        data_zawarcia DATE, wynagrodzenie_brutto DECIMAL(12,2),
        kup50 INTEGER DEFAULT 0, zaliczka_podatek DECIMAL(10,2),
        prawa_autorskie INTEGER DEFAULT 0, zakres_praw TEXT,
        wymagany_protokol INTEGER DEFAULT 0, data_odbioru DATE,
        dzielo_przyjete INTEGER DEFAULT 0, data_zl_rachunku DATE,
        numer_projektu VARCHAR(255), opiekun VARCHAR(255),
        forma_podpisania VARCHAR(20), platforma_el VARCHAR(100),
        id_dokumentu_el VARCHAR(255), plik_potwierdzenia VARCHAR(500),
        plik_umowy VARCHAR(500), uwagi TEXT, nr_roboczy VARCHAR(100),
        nr_system VARCHAR(100), nr_rejestru VARCHAR(100),
        m365_konto INTEGER DEFAULT 0, m365_login VARCHAR(255),
        m365_user_id VARCHAR(255), m365_konto_aktywne INTEGER DEFAULT 0,
        m365_data_utworzenia DATETIME, m365_licencja_przypisana INTEGER DEFAULT 0,
        email VARCHAR(255), epodpis_dostawca VARCHAR(100),
        epodpis_nr_certyfikatu VARCHAR(255), epodpis_data_waznosci DATE,
        created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS umowy_praca (
        id INTEGER PRIMARY KEY AUTOINCREMENT, numer_umowy VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'obowiązująca', imie_nazwisko VARCHAR(255),
        pesel VARCHAR(11), adres TEXT, seria_nr_dowodu VARCHAR(50),
        urzad_skarbowy VARCHAR(255), rachunek_bankowy VARCHAR(50),
        email_login VARCHAR(255), stanowisko VARCHAR(255),
        dzial_projekt VARCHAR(255), wymiar_etatu VARCHAR(20),
        rodzaj_umowy VARCHAR(50), data_zawarcia DATE, data_rozpoczecia DATE,
        data_zakonczenia DATE, wynagrodzenie_brutto DECIMAL(12,2),
        skladniki_wynagrodzenia TEXT, urlop_wymiar INTEGER,
        urlop_zalegly INTEGER DEFAULT 0, okres_wypowiedzenia VARCHAR(100),
        ppk INTEGER DEFAULT 0, pit2 INTEGER DEFAULT 0,
        badania_data_waznosci DATE, bhp_data_waznosci DATE,
        klauzula_rodo INTEGER DEFAULT 0, opiekun_przelozony VARCHAR(255),
        forma_podpisania VARCHAR(20), platforma_el VARCHAR(100),
        id_dokumentu_el VARCHAR(255), plik_potwierdzenia VARCHAR(500),
        plik_umowy VARCHAR(500), aneksy TEXT, uwagi TEXT,
        nr_roboczy VARCHAR(100), nr_system VARCHAR(100), nr_rejestru VARCHAR(100),
        email VARCHAR(255), epodpis_dostawca VARCHAR(100),
        epodpis_nr_certyfikatu VARCHAR(255), epodpis_data_waznosci DATE,
        created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS umowy_inne (
        id INTEGER PRIMARY KEY AUTOINCREMENT, numer_umowy VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'projekt', typ_umowy VARCHAR(255),
        strona_umowy VARCHAR(255), pesel_nip_krs VARCHAR(50), adres TEXT,
        przedmiot_umowy TEXT, data_zawarcia DATE, data_rozpoczecia DATE,
        data_zakonczenia DATE, czas_nieokreslony INTEGER DEFAULT 0,
        okres_wypowiedzenia VARCHAR(100), wartosc_umowy DECIMAL(12,2),
        waluta VARCHAR(10) DEFAULT 'PLN', warunki_finansowe TEXT,
        numer_projektu VARCHAR(255), opiekun VARCHAR(255),
        dzialania_cykliczne INTEGER DEFAULT 0, dzialania_opis TEXT,
        data_przegladu DATE, forma_podpisania VARCHAR(20),
        platforma_el VARCHAR(100), id_dokumentu_el VARCHAR(255),
        plik_potwierdzenia VARCHAR(500), plik_umowy VARCHAR(500),
        zalaczniki VARCHAR(1000), uwagi TEXT, nr_roboczy VARCHAR(100),
        nr_system VARCHAR(100), nr_rejestru VARCHAR(100),
        email VARCHAR(255), epodpis_dostawca VARCHAR(100),
        epodpis_nr_certyfikatu VARCHAR(255), epodpis_data_waznosci DATE,
        created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Settings ──────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS settings (key_ VARCHAR(100) PRIMARY KEY, value TEXT)",

    // ── Rejestr migracji schematu (audyt: co i kiedy zastosowano) ──────────────
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        mig_key    VARCHAR(190) PRIMARY KEY,
        status     VARCHAR(20)  NOT NULL DEFAULT 'ok',
        detail     TEXT,
        applied_at DATETIME
    )",

    // ── Obieg dokumentów ──────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS contract_approvals (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type VARCHAR(50) NOT NULL,
        contract_id INTEGER NOT NULL, requested_by INTEGER NOT NULL,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        token VARCHAR(64) UNIQUE NOT NULL, token_expires DATETIME NOT NULL,
        status VARCHAR(20) DEFAULT 'oczekuje', decided_by INTEGER,
        decided_at DATETIME, decision_note TEXT,
        email_sent INTEGER DEFAULT 0, via_email INTEGER DEFAULT 0
    )",
    "CREATE INDEX IF NOT EXISTS idx_approvals_contract ON contract_approvals(contract_type,contract_id)",
    "CREATE INDEX IF NOT EXISTS idx_approvals_token ON contract_approvals(token)",
    "CREATE TABLE IF NOT EXISTS contract_audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type VARCHAR(50),
        contract_id INTEGER, user_id INTEGER, user_snapshot VARCHAR(255),
        action VARCHAR(50) NOT NULL, note TEXT, ip_address VARCHAR(45),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_audit_contract ON contract_audit_log(contract_type,contract_id)",
    "CREATE TABLE IF NOT EXISTS contract_amendments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type VARCHAR(50) NOT NULL,
        contract_id INTEGER NOT NULL, numer_aneksu INTEGER NOT NULL DEFAULT 1,
        requested_by INTEGER, requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        opis_zmian TEXT NOT NULL, plik_aneksu VARCHAR(500),
        status VARCHAR(20) DEFAULT 'oczekuje', token VARCHAR(64) UNIQUE,
        token_expires DATETIME, decided_by INTEGER, decided_at DATETIME,
        decision_note TEXT, via_email INTEGER DEFAULT 0,
        email_sent INTEGER DEFAULT 0, proposed_changes TEXT, applied_at DATETIME
    )",
    "CREATE INDEX IF NOT EXISTS idx_amendments_contract ON contract_amendments(contract_type,contract_id)",
    "CREATE TABLE IF NOT EXISTS contract_edit_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type VARCHAR(50) NOT NULL,
        contract_id INTEGER NOT NULL, requested_by INTEGER,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        opis_zmian TEXT NOT NULL, status VARCHAR(20) DEFAULT 'oczekuje',
        token VARCHAR(64) UNIQUE, token_expires DATETIME,
        decided_by INTEGER, decided_at DATETIME, decision_note TEXT,
        via_email INTEGER DEFAULT 0, email_sent INTEGER DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS contract_termination_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type TEXT NOT NULL,
        contract_id INTEGER NOT NULL, requested_by INTEGER,
        requester_name TEXT NOT NULL, powod TEXT NOT NULL,
        proposed_date TEXT, status TEXT NOT NULL DEFAULT 'oczekuje',
        decided_by INTEGER, decided_at DATETIME, decision_note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS certificate_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type TEXT NOT NULL,
        contract_id INTEGER NOT NULL, requested_by INTEGER,
        requester_name TEXT NOT NULL, requester_email TEXT NOT NULL,
        cel TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'oczekuje',
        issued_by INTEGER, issued_at DATETIME, rejection_note TEXT,
        certificate_content TEXT, certificate_file TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS contract_letters (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type TEXT NOT NULL,
        contract_id INTEGER NOT NULL, kierunek TEXT NOT NULL DEFAULT 'wychodzące',
        typ_pisma TEXT NOT NULL DEFAULT 'inne', tytul TEXT NOT NULL,
        tresc TEXT, plik TEXT, data_pisma TEXT, nadawca TEXT,
        odbiorca TEXT, odbiorca_email TEXT, uwagi TEXT,
        email_sent INTEGER DEFAULT 0, postivo_id TEXT, postivo_status TEXT,
        created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS contract_supervisors (
        id INTEGER PRIMARY KEY AUTOINCREMENT, contract_type TEXT NOT NULL,
        contract_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        user_name TEXT NOT NULL DEFAULT '', user_email TEXT NOT NULL DEFAULT '',
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(contract_type, contract_id)
    )",

    // ── Wiadomości ────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        context_type TEXT NOT NULL DEFAULT 'contract',
        context_id INTEGER NOT NULL, contract_type TEXT NOT NULL DEFAULT '',
        sender_type TEXT NOT NULL DEFAULT 'admin', sender_id INTEGER,
        sender_name TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_read INTEGER NOT NULL DEFAULT 0,
        recipient_type TEXT NOT NULL DEFAULT 'admin',
        subject TEXT NOT NULL DEFAULT '', type_id INTEGER DEFAULT NULL
    )",
    "CREATE INDEX IF NOT EXISTS idx_messages_ctx ON messages(context_type,context_id)",
    "CREATE TABLE IF NOT EXISTS message_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '', available_for TEXT NOT NULL DEFAULT 'both',
        is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Onboarding ───────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS onboarding_volunteers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, session_token TEXT UNIQUE,
        status TEXT NOT NULL DEFAULT 'new', imie_nazwisko TEXT NOT NULL DEFAULT '',
        pesel TEXT NOT NULL DEFAULT '', data_urodzenia TEXT NOT NULL DEFAULT '',
        adres TEXT NOT NULL DEFAULT '', telefon TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '', phone_verified INTEGER NOT NULL DEFAULT 0,
        phone_code TEXT, phone_code_expires TEXT,
        email_verified INTEGER NOT NULL DEFAULT 0, email_token TEXT,
        email_token_expires TEXT, klauzula_accepted INTEGER NOT NULL DEFAULT 0,
        klauzula_version TEXT NOT NULL DEFAULT '',
        admin_note TEXT NOT NULL DEFAULT '', ip_address TEXT NOT NULL DEFAULT '',
        user_agent TEXT NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS onboarding_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL,
        subject TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '',
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, sent_by INTEGER
    )",

    // ── SMS ───────────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS sms_login_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL,
        code TEXT NOT NULL, user_id INTEGER NOT NULL,
        expires_at TEXT NOT NULL, used_at TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    )",

    // ── M365 standalone ───────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS m365_standalone_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, imie_nazwisko VARCHAR(255) NOT NULL,
        email VARCHAR(255), opis TEXT, m365_login VARCHAR(255),
        m365_user_id VARCHAR(255), m365_konto_aktywne INTEGER DEFAULT 0,
        m365_licencja_przypisana INTEGER DEFAULT 0, m365_data_utworzenia DATETIME,
        linked_user_id INTEGER, created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Zadania ───────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS task_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL, description TEXT,
        color TEXT NOT NULL DEFAULT '#2563eb', icon TEXT NOT NULL DEFAULT 'bi-kanban',
        is_active INTEGER NOT NULL DEFAULT 1, created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",
    "CREATE TABLE IF NOT EXISTS task_lists (
        id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL,
        name TEXT NOT NULL, position REAL NOT NULL DEFAULT 0, color TEXT,
        is_done_state INTEGER NOT NULL DEFAULT 0, wip_limit INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS task_tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER,
        name TEXT NOT NULL, color TEXT NOT NULL DEFAULT '#64748b',
        text_color TEXT NOT NULL DEFAULT '#ffffff', is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",
    "CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL,
        list_id INTEGER NOT NULL, title TEXT NOT NULL, description TEXT,
        position REAL NOT NULL DEFAULT 0, priority INTEGER NOT NULL DEFAULT 2,
        start_date TEXT, due_date TEXT, estimated_hours REAL,
        created_by INTEGER NOT NULL, completed_at TEXT, deleted_at TEXT,
        contract_type TEXT DEFAULT NULL, contract_id INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE,
        FOREIGN KEY (list_id) REFERENCES task_lists(id) ON DELETE RESTRICT,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
    )",
    "CREATE INDEX IF NOT EXISTS idx_tasks_list     ON tasks(list_id,position)",
    "CREATE INDEX IF NOT EXISTS idx_tasks_due       ON tasks(due_date,completed_at,deleted_at)",
    "CREATE INDEX IF NOT EXISTS idx_tasks_ws        ON tasks(workspace_id,deleted_at)",
    "CREATE INDEX IF NOT EXISTS idx_tasks_contract  ON tasks(contract_type,contract_id)",
    "CREATE TABLE IF NOT EXISTS task_task_tags (
        task_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,
        assigned_by INTEGER NOT NULL,
        assigned_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (task_id, tag_id)
    )",
    "CREATE TABLE IF NOT EXISTS task_assignments (
        task_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        assigned_by INTEGER NOT NULL,
        assigned_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (task_id, user_id)
    )",
    "CREATE TABLE IF NOT EXISTS task_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
        author_id INTEGER NOT NULL, body TEXT NOT NULL,
        is_edited INTEGER NOT NULL DEFAULT 0, deleted_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",
    "CREATE TABLE IF NOT EXISTS task_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL, event_type TEXT NOT NULL,
        from_value TEXT, to_value TEXT, metadata TEXT,
        occurred_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",
    "CREATE TABLE IF NOT EXISTS task_time_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL, started_at TEXT NOT NULL,
        ended_at TEXT, duration_seconds INTEGER, note TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",
    "CREATE INDEX IF NOT EXISTS idx_tlog_task ON task_time_logs(task_id)",
    "CREATE INDEX IF NOT EXISTS idx_tlog_user ON task_time_logs(user_id)",
    "CREATE TABLE IF NOT EXISTS task_list_time (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
        list_id INTEGER NOT NULL, list_name TEXT NOT NULL,
        entered_at TEXT NOT NULL, exited_at TEXT, duration_seconds INTEGER
    )",
    "CREATE TABLE IF NOT EXISTS task_workspace_members (
        workspace_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT 'editor', added_by INTEGER NOT NULL,
        added_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (workspace_id, user_id)
    )",
    "CREATE TABLE IF NOT EXISTS task_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
        original_name TEXT NOT NULL, stored_name TEXT NOT NULL,
        file_size INTEGER NOT NULL DEFAULT 0, mime_type TEXT,
        uploaded_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",
    "CREATE TABLE IF NOT EXISTS task_subtasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
        title TEXT NOT NULL, is_done INTEGER NOT NULL DEFAULT 0,
        position REAL NOT NULL DEFAULT 0, created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        completed_at TEXT
    )",
    "CREATE TABLE IF NOT EXISTS task_notification_prefs (
        user_id INTEGER PRIMARY KEY, notify_assigned INTEGER NOT NULL DEFAULT 1,
        notify_mentioned INTEGER NOT NULL DEFAULT 1,
        notify_comment INTEGER NOT NULL DEFAULT 0,
        notify_due_1day INTEGER NOT NULL DEFAULT 1,
        notify_due_today INTEGER NOT NULL DEFAULT 1,
        notify_sms INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",
    "CREATE TABLE IF NOT EXISTS task_notification_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        event_type TEXT NOT NULL, ref_id INTEGER NOT NULL,
        sent_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )",

    // ── Procedury ─────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS procedures (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT    NOT NULL,
        content    TEXT    NOT NULL DEFAULT '',
        category   TEXT    NOT NULL DEFAULT '',
        status     TEXT    NOT NULL DEFAULT 'active',
        owner_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        version    INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME
    )",
    "CREATE TABLE IF NOT EXISTS procedure_versions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        procedure_id INTEGER NOT NULL REFERENCES procedures(id) ON DELETE CASCADE,
        version      INTEGER NOT NULL,
        title        TEXT    NOT NULL,
        content      TEXT    NOT NULL DEFAULT '',
        category     TEXT    NOT NULL DEFAULT '',
        owner_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        changed_by   INTEGER NOT NULL,
        change_note  TEXT    NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS procedure_relations (
        procedure_id INTEGER NOT NULL,
        related_id   INTEGER NOT NULL,
        PRIMARY KEY (procedure_id, related_id)
    )",
    "CREATE TABLE IF NOT EXISTS procedure_attachments (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        procedure_id  INTEGER NOT NULL REFERENCES procedures(id) ON DELETE CASCADE,
        filename      TEXT    NOT NULL,
        original_name TEXT    NOT NULL,
        mime_type     TEXT    NOT NULL DEFAULT '',
        file_size     INTEGER NOT NULL DEFAULT 0,
        uploaded_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── EZD / Kancelaria ──────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS ezd_jrwa (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        symbol      TEXT    NOT NULL UNIQUE,
        title       TEXT    NOT NULL,
        kat_arch    TEXT    NOT NULL DEFAULT 'B10',
        description TEXT    NOT NULL DEFAULT '',
        parent_id   INTEGER REFERENCES ezd_jrwa(id) ON DELETE SET NULL,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS ezd_teczki (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        jrwa_id     INTEGER REFERENCES ezd_jrwa(id) ON DELETE SET NULL,
        symbol      TEXT    NOT NULL,
        title       TEXT    NOT NULL,
        rok         INTEGER NOT NULL,
        status      TEXT    NOT NULL DEFAULT 'open',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        closed_at   DATETIME
    )",
    "CREATE TABLE IF NOT EXISTS ezd_sprawy (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        teczka_id   INTEGER NOT NULL REFERENCES ezd_teczki(id) ON DELETE RESTRICT,
        znak_sprawy TEXT    NOT NULL UNIQUE,
        numer       INTEGER NOT NULL,
        title       TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'open',
        priority    TEXT    NOT NULL DEFAULT 'normal',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        deadline    DATE,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        closed_at   DATETIME
    )",
    "CREATE TABLE IF NOT EXISTS ezd_pisma (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id   INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        sygnatura   TEXT    NOT NULL,
        kierunek    TEXT    NOT NULL DEFAULT 'przychodzace',
        title       TEXT    NOT NULL,
        tresc       TEXT    NOT NULL DEFAULT '',
        nadawca     TEXT    NOT NULL DEFAULT '',
        odbiorca    TEXT    NOT NULL DEFAULT '',
        data_pisma  DATE,
        data_wplywu DATE,
        data_wysylki DATE,
        status      TEXT    NOT NULL DEFAULT 'nowe',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS ezd_umowy (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id       INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        sygnatura       TEXT    NOT NULL,
        title           TEXT    NOT NULL,
        typ             TEXT    NOT NULL DEFAULT 'umowa',
        strona          TEXT    NOT NULL DEFAULT '',
        wartosc         REAL,
        waluta          TEXT    NOT NULL DEFAULT 'PLN',
        data_zawarcia   DATE,
        data_od         DATE,
        data_do         DATE,
        warunki_platnosci TEXT  NOT NULL DEFAULT '',
        status          TEXT    NOT NULL DEFAULT 'projekt',
        owner_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        ref_type        TEXT,
        ref_id          INTEGER
    )",
    "CREATE TABLE IF NOT EXISTS ezd_dekretacje (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        pismo_id        INTEGER REFERENCES ezd_pisma(id)  ON DELETE CASCADE,
        umowa_id        INTEGER REFERENCES ezd_umowy(id)  ON DELETE CASCADE,
        zlecajacy_id    INTEGER NOT NULL REFERENCES users(id),
        wykonawca_id    INTEGER NOT NULL REFERENCES users(id),
        dyspozycja      TEXT    NOT NULL DEFAULT 'do_zalat',
        tresc           TEXT    NOT NULL DEFAULT '',
        deadline        DATE,
        status          TEXT    NOT NULL DEFAULT 'oczekuje',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at    DATETIME
    )",
    "CREATE TABLE IF NOT EXISTS ezd_zalaczniki (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        pismo_id        INTEGER REFERENCES ezd_pisma(id)  ON DELETE CASCADE,
        umowa_id        INTEGER REFERENCES ezd_umowy(id)  ON DELETE CASCADE,
        filename        TEXT    NOT NULL,
        original_name   TEXT    NOT NULL,
        mime_type       TEXT    NOT NULL DEFAULT '',
        file_size       INTEGER NOT NULL DEFAULT 0,
        wersja          INTEGER NOT NULL DEFAULT 1,
        prev_id         INTEGER REFERENCES ezd_zalaczniki(id) ON DELETE SET NULL,
        uploaded_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS ezd_log (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        teczka_id       INTEGER REFERENCES ezd_teczki(id)  ON DELETE SET NULL,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id)  ON DELETE SET NULL,
        pismo_id        INTEGER REFERENCES ezd_pisma(id)   ON DELETE SET NULL,
        umowa_id        INTEGER REFERENCES ezd_umowy(id)   ON DELETE SET NULL,
        user_id         INTEGER NOT NULL,
        action          TEXT    NOT NULL,
        details         TEXT    NOT NULL DEFAULT '',
        ip              TEXT    NOT NULL DEFAULT '',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Struktura Organizacyjna ───────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS org_units (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id          INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
        supervisor_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
        supervisor_user_id INTEGER REFERENCES users(id)     ON DELETE SET NULL,
        code               TEXT    NOT NULL UNIQUE,
        name               TEXT    NOT NULL,
        short_name         TEXT    NOT NULL DEFAULT '',
        description        TEXT    NOT NULL DEFAULT '',
        phone              TEXT    NOT NULL DEFAULT '',
        email              TEXT    NOT NULL DEFAULT '',
        location           TEXT    NOT NULL DEFAULT '',
        status             TEXT    NOT NULL DEFAULT 'active',
        sort_order         INTEGER NOT NULL DEFAULT 0,
        created_by         INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS org_positions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        name         TEXT    NOT NULL,
        code         TEXT    NOT NULL DEFAULT '',
        is_head_role INTEGER NOT NULL DEFAULT 0,
        sort_order   INTEGER NOT NULL DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS org_members (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        unit_id       INTEGER NOT NULL REFERENCES org_units(id) ON DELETE CASCADE,
        user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        position_id   INTEGER REFERENCES org_positions(id) ON DELETE SET NULL,
        position_name TEXT    NOT NULL DEFAULT '',
        is_head       INTEGER NOT NULL DEFAULT 0,
        is_primary    INTEGER NOT NULL DEFAULT 0,
        email_service TEXT    NOT NULL DEFAULT '',
        phone_direct  TEXT    NOT NULL DEFAULT '',
        phone_mobile  TEXT    NOT NULL DEFAULT '',
        availability  TEXT    NOT NULL DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'active',
        substitute_id INTEGER REFERENCES org_members(id) ON DELETE SET NULL,
        valid_from    DATE,
        valid_to      DATE,
        sort_order    INTEGER NOT NULL DEFAULT 0,
        notes         TEXT    NOT NULL DEFAULT '',
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_org_members_unit ON org_members(unit_id)",
    "CREATE INDEX IF NOT EXISTS idx_org_members_user ON org_members(user_id)",
    "CREATE TABLE IF NOT EXISTS org_history (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT    NOT NULL,
        entity_id   INTEGER NOT NULL,
        action      TEXT    NOT NULL,
        old_data    TEXT    NOT NULL DEFAULT '',
        new_data    TEXT    NOT NULL DEFAULT '',
        changed_by  INTEGER NOT NULL REFERENCES users(id),
        changed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        note        TEXT    NOT NULL DEFAULT ''
    )",
    "CREATE INDEX IF NOT EXISTS idx_org_history_ent ON org_history(entity_type,entity_id)",

    // ── Kolejka e-mail ────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS mail_queue (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        to_email     TEXT    NOT NULL,
        to_name      TEXT    NOT NULL DEFAULT '',
        subject      TEXT    NOT NULL,
        body_html    TEXT    NOT NULL DEFAULT '',
        body_text    TEXT    NOT NULL DEFAULT '',
        context_type TEXT    NOT NULL DEFAULT '',
        context_id   INTEGER,
        status       TEXT    NOT NULL DEFAULT 'pending',
        retry_count  INTEGER NOT NULL DEFAULT 0,
        last_error   TEXT    NOT NULL DEFAULT '',
        scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        sent_at      DATETIME,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_mq_status ON mail_queue(status,scheduled_at)",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists') &&
                !str_contains($e->getMessage(), 'duplicate column')) {
                throw $e;
            }
        }
    }

    // Domyślne settings
    $defaults = [
        'sms_enabled' => '0', 'sms_provider' => 'smsapi', 'sms_sender_name' => 'INFO',
        'wa_enabled' => '0', 'tasks_enabled' => '1', 'onboarding_enabled' => '0',
        'onboarding_title' => 'Kwestionariusz wolontariusza', 'm365_enabled' => '0',
        'ceidg_enabled' => '0', 'postivo_enabled' => '0', 'msg_notify_email' => '0',
        'ezd_enabled' => '1', 'org_enabled' => '0',
        'crm_enabled' => '0',
    ];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO settings (key_,value) VALUES (?,?)");
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);
}

/**
 * Migracje schematu dla istniejących tenantów.
 * Bezpieczne do wielokrotnego uruchamiania — ignoruje błędy "already exists".
 * Wzorzec: gdy dodajesz nową kolumnę, dopisz tu ALTER TABLE.
 * Nowe tabele obsługuje setup_tenant_db() przez CREATE TABLE IF NOT EXISTS.
 *
 * @return array Lista wyników: [status, opis] — status: 'ok'|'skip'|'err'
 */
function migrate_tenant_db(PDO $pdo): array {
    $results = [];

    // ── Rejestr migracji: gwarantuj tabelę, wczytaj już zastosowane klucze ─────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            mig_key    VARCHAR(190) PRIMARY KEY,
            status     VARCHAR(20)  NOT NULL DEFAULT 'ok',
            detail     TEXT,
            applied_at DATETIME
        )");
    } catch (\Throwable $e) { /* best-effort — migracje działają też bez rejestru */ }

    $applied = [];
    try {
        foreach ($pdo->query("SELECT mig_key FROM schema_migrations WHERE status='ok'")
                     ->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $applied[$k] = true;
        }
    } catch (\Throwable $e) { /* brak rejestru — potraktuj wszystko jako niezastosowane */ }

    // Zapis stanu pojedynczej migracji (idempotentny na SQLite i MySQL).
    $record = function(string $key, string $status, string $detail) use ($pdo): void {
        $now = date('Y-m-d H:i:s');
        try {
            $sel = $pdo->prepare("SELECT 1 FROM schema_migrations WHERE mig_key=?");
            $sel->execute([$key]);
            if ($sel->fetchColumn()) {
                $pdo->prepare("UPDATE schema_migrations SET status=?, detail=?, applied_at=? WHERE mig_key=?")
                    ->execute([$status, $detail, $now, $key]);
            } else {
                $pdo->prepare("INSERT INTO schema_migrations (mig_key,status,detail,applied_at) VALUES (?,?,?,?)")
                    ->execute([$key, $status, $detail, $now]);
            }
        } catch (\Throwable $e) { /* rejestr jest pomocniczy, nie blokuje migracji */ }
    };

    $run = function(string $label, string $sql) use ($pdo, &$results, &$applied, $record): void {
        // Już zarejestrowane jako wykonane — pomiń ALTER (szybciej + audyt).
        if (!empty($applied[$label])) {
            $results[] = ['skip', $label . ' (zarejestrowane)'];
            return;
        }
        try {
            $pdo->exec($sql);
            $results[] = ['ok', $label];
            $record($label, 'ok', '');
            $applied[$label] = true;
        } catch (\PDOException $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'duplicate column') || str_contains($m, 'already exists')) {
                $results[] = ['skip', $label];
                $record($label, 'ok', 'istniało w bazie');
                $applied[$label] = true;
            } else {
                $results[] = ['err', $label . ' — ' . $m];
                $record($label, 'err', $m);
            }
        }
    };

    // ── Nowe tabele / indeksy (bezpieczne przez IF NOT EXISTS) ─────────────────
    setup_tenant_db($pdo);

    // ── Schema v2: tasks — powiązanie z umowami ────────────────────────────────
    $run('tasks.contract_type', "ALTER TABLE tasks ADD COLUMN contract_type TEXT DEFAULT NULL");
    $run('tasks.contract_id',   "ALTER TABLE tasks ADD COLUMN contract_id INTEGER DEFAULT NULL");
    $run('idx_tasks_contract',  "CREATE INDEX IF NOT EXISTS idx_tasks_contract ON tasks(contract_type,contract_id)");

    // ── Schema v3: users — 2FA i kod dostępu ──────────────────────────────────
    $run('users.twofa_method',       "ALTER TABLE users ADD COLUMN twofa_method TEXT DEFAULT ''");
    $run('users.twofa_phone',        "ALTER TABLE users ADD COLUMN twofa_phone TEXT");
    $run('users.totp_backup_codes',  "ALTER TABLE users ADD COLUMN totp_backup_codes TEXT");
    $run('users.login_code',         "ALTER TABLE users ADD COLUMN login_code TEXT DEFAULT NULL");

    // ── Schema v4: umowy_wolontariat — adres i dane dodatkowe ─────────────────
    $run('umowy_wolontariat.adres',             "ALTER TABLE umowy_wolontariat ADD COLUMN adres TEXT");
    $run('umowy_wolontariat.seria_nr_dowodu',   "ALTER TABLE umowy_wolontariat ADD COLUMN seria_nr_dowodu VARCHAR(50)");
    $run('umowy_wolontariat.urzad_skarbowy',    "ALTER TABLE umowy_wolontariat ADD COLUMN urzad_skarbowy VARCHAR(255)");
    $run('umowy_wolontariat.rachunek_bankowy',  "ALTER TABLE umowy_wolontariat ADD COLUMN rachunek_bankowy VARCHAR(50)");
    $run('umowy_wolontariat.email',             "ALTER TABLE umowy_wolontariat ADD COLUMN email VARCHAR(255)");
    $run('umowy_wolontariat.epodpis_dostawca',  "ALTER TABLE umowy_wolontariat ADD COLUMN epodpis_dostawca VARCHAR(100)");
    $run('umowy_wolontariat.epodpis_nr_certyfikatu', "ALTER TABLE umowy_wolontariat ADD COLUMN epodpis_nr_certyfikatu VARCHAR(255)");
    $run('umowy_wolontariat.epodpis_data_waznosci',  "ALTER TABLE umowy_wolontariat ADD COLUMN epodpis_data_waznosci DATE");

    // ── Schema v5: m365 na umowach ─────────────────────────────────────────────
    foreach (['umowy_zlecenie','umowy_dzielo','umowy_wolontariat'] as $t) {
        $run("$t.m365_konto",                "ALTER TABLE $t ADD COLUMN m365_konto INTEGER DEFAULT 0");
        $run("$t.m365_login",                "ALTER TABLE $t ADD COLUMN m365_login VARCHAR(255)");
        $run("$t.m365_user_id",              "ALTER TABLE $t ADD COLUMN m365_user_id VARCHAR(255)");
        $run("$t.m365_konto_aktywne",        "ALTER TABLE $t ADD COLUMN m365_konto_aktywne INTEGER DEFAULT 0");
        $run("$t.m365_data_utworzenia",      "ALTER TABLE $t ADD COLUMN m365_data_utworzenia DATETIME");
        $run("$t.m365_licencja_przypisana",  "ALTER TABLE $t ADD COLUMN m365_licencja_przypisana INTEGER DEFAULT 0");
    }

    // ── Schema v6: org_members — sort_order ───────────────────────────────────
    $run('org_members.sort_order', "ALTER TABLE org_members ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");

    // ── Schema v6: ezd_dekretacje — unit_id ───────────────────────────────────
    $run('ezd_dekretacje.unit_id', "ALTER TABLE ezd_dekretacje ADD COLUMN unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL");

    // ── Schema v7: org_units — nadzorowanie ───────────────────────────────────
    $run('org_units.supervisor_unit_id', "ALTER TABLE org_units ADD COLUMN supervisor_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL");
    $run('org_units.supervisor_user_id', "ALTER TABLE org_units ADD COLUMN supervisor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL");

    // ── Schema v8: osoba podpisująca ze strony fundacji ───────────────────────
    foreach (['umowy_wolontariat','umowy_zlecenie','umowy_dzielo','umowy_uslugi','umowy_praca','umowy_inne'] as $t) {
        $run("$t.podpisujacy_fundacja", "ALTER TABLE $t ADD COLUMN podpisujacy_fundacja VARCHAR(255)");
        $run("$t.podpisujacy_stanowisko", "ALTER TABLE $t ADD COLUMN podpisujacy_stanowisko VARCHAR(255)");
    }

    // ── Schema v8: org_representatives ────────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS org_representatives (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            title      TEXT NOT NULL DEFAULT '',
            is_active  INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
        )");
        $results[] = ['ok', 'org_representatives'];
    } catch (\Throwable $e) { $results[] = ['skip', 'org_representatives']; }

    // ── Schema v9: access_level na umowach (akcje masowe) ─────────────────────
    foreach (['umowy_wolontariat','umowy_zlecenie','umowy_dzielo','umowy_uslugi','umowy_praca','umowy_inne'] as $t) {
        $run("$t.access_level", "ALTER TABLE $t ADD COLUMN access_level TEXT NOT NULL DEFAULT 'full'");
    }

    // ── Nowe domyślne settings ─────────────────────────────────────────────────
    $new_settings = [
        'wa_enabled' => '0', 'tasks_enabled' => '1',
        'ceidg_enabled' => '0', 'postivo_enabled' => '0',
        'msg_notify_email' => '0', 'm365_enabled' => '0',
        'org_enabled' => '0',
    ];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO settings (key_,value) VALUES (?,?)");
    foreach ($new_settings as $k => $v) {
        try { $ins->execute([$k, $v]); $results[] = ['ok', "settings.$k"]; }
        catch (\Throwable $e) { $results[] = ['skip', "settings.$k"]; }
    }

    return $results;
}
