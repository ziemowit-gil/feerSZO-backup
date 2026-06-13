<?php
/**
 * Setup — jednorazowy skrypt tworzący całą strukturę bazy danych.
 *
 * Zastępuje install.php + wszystkie migrate_*.php.
 * Bezpieczny do wielokrotnego uruchamiania — używa wyłącznie
 * CREATE TABLE IF NOT EXISTS i ALTER TABLE (ignoruje błędy "column exists").
 *
 * Użycie:
 *   php setup/setup.php              ← CLI
 *   http://twoja-domena/setup/setup.php ← przeglądarka (usuń po użyciu!)
 */

define('SETUP_RUNNING', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$isCli = php_sapi_name() === 'cli';

// ── Helpers ───────────────────────────────────────────────────────────────────

$results = [];

function run(string $label, string $sql): void {
    global $results, $isCli;
    try {
        db()->exec($sql);
        $results[] = ['ok' => true, 'label' => $label];
        if ($isCli) echo "  ✓ $label\n";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        // SQLite: "duplicate column name" — nie jest błędem przy re-run
        $is_dup = str_contains($msg, 'duplicate column') || str_contains($msg, 'already exists');
        $results[] = ['ok' => $is_dup, 'label' => $label, 'note' => $is_dup ? 'już istnieje' : $msg];
        if ($isCli) echo ($is_dup ? "  · $label (już istnieje)\n" : "  ✗ $label — $msg\n");
    }
}

function section(string $title): void {
    global $isCli;
    if ($isCli) echo "\n── $title ──\n";
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. UŻYTKOWNICY
// ══════════════════════════════════════════════════════════════════════════════
section('Użytkownicy');

run('users', "CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password        VARCHAR(255),
    microsoft_id    VARCHAR(255),
    role            VARCHAR(50)  DEFAULT 'viewer',
    is_active       INTEGER      DEFAULT 1,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    totp_secret     TEXT,
    totp_confirmed  INTEGER      DEFAULT 0,
    twofa_method    TEXT         DEFAULT '',
    twofa_phone     TEXT,
    totp_backup_codes TEXT,
    login_code      TEXT         DEFAULT NULL
)");

// Kolumny 2FA — dla upgrade ze starej bazy
foreach ([
    "ALTER TABLE users ADD COLUMN totp_secret TEXT",
    "ALTER TABLE users ADD COLUMN totp_confirmed INTEGER DEFAULT 0",
    "ALTER TABLE users ADD COLUMN twofa_method TEXT DEFAULT ''",
    "ALTER TABLE users ADD COLUMN twofa_phone TEXT",
    "ALTER TABLE users ADD COLUMN totp_backup_codes TEXT",
    "ALTER TABLE users ADD COLUMN login_code TEXT DEFAULT NULL",
] as $sql) {
    run('users — kolumna 2FA/login_code', $sql);
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. UMOWY
// ══════════════════════════════════════════════════════════════════════════════
section('Umowy — Zlecenie');

run('umowy_zlecenie', "CREATE TABLE IF NOT EXISTS umowy_zlecenie (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    numer_umowy              VARCHAR(100) NOT NULL,
    status                   VARCHAR(50)  DEFAULT 'projekt',
    imie_nazwisko            VARCHAR(255),
    pesel                    VARCHAR(11),
    adres                    TEXT,
    seria_nr_dowodu          VARCHAR(50),
    urzad_skarbowy           VARCHAR(255),
    rachunek_bankowy         VARCHAR(50),
    przedmiot_zlecenia       TEXT,
    data_zawarcia            DATE,
    data_rozpoczecia         DATE,
    data_zakonczenia         DATE,
    wynagrodzenie_brutto     DECIMAL(12,2),
    stawka_kwota             DECIMAL(10,2),
    typ_stawki               VARCHAR(20),
    liczba_godzin_planowana  DECIMAL(8,2),
    sposob_rozliczenia       VARCHAR(20),
    termin_platnosci         VARCHAR(100),
    zus_skladki              INTEGER      DEFAULT 0,
    tytul_ubezpieczenia      VARCHAR(255),
    zwolnienie_wiek          INTEGER      DEFAULT 0,
    zaliczka_podatek         DECIMAL(10,2),
    kup                      VARCHAR(10),
    numer_projektu           VARCHAR(255),
    opiekun                  VARCHAR(255),
    wymagany_rachunek        INTEGER      DEFAULT 0,
    data_zl_rachunku         DATE,
    forma_podpisania         VARCHAR(20),
    platforma_el             VARCHAR(100),
    id_dokumentu_el          VARCHAR(255),
    plik_potwierdzenia       VARCHAR(500),
    plik_umowy               VARCHAR(500),
    uwagi                    TEXT,
    nr_roboczy               VARCHAR(100),
    nr_system                VARCHAR(100),
    nr_rejestru              VARCHAR(100),
    m365_konto               INTEGER      DEFAULT 0,
    m365_login               VARCHAR(255),
    m365_user_id             VARCHAR(255),
    m365_konto_aktywne       INTEGER      DEFAULT 0,
    m365_data_utworzenia     DATETIME,
    m365_licencja_przypisana INTEGER      DEFAULT 0,
    email                    VARCHAR(255),
    epodpis_dostawca         VARCHAR(100),
    epodpis_nr_certyfikatu   VARCHAR(255),
    epodpis_data_waznosci    DATE,
    created_by               INTEGER,
    created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

section('Umowy — Usługi');

run('umowy_uslugi', "CREATE TABLE IF NOT EXISTS umowy_uslugi (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    numer_umowy              VARCHAR(100) NOT NULL,
    status                   VARCHAR(50)  DEFAULT 'projekt',
    nazwa_wykonawcy          VARCHAR(255),
    nip_pesel                VARCHAR(20),
    adres                    TEXT,
    rachunek_lub_faktura     VARCHAR(255),
    przedmiot_uslugi         TEXT,
    zakres_uslug             TEXT,
    data_zawarcia            DATE,
    data_rozpoczecia         DATE,
    data_zakonczenia         DATE,
    czas_nieokreslony        INTEGER      DEFAULT 0,
    okres_wypowiedzenia      VARCHAR(100),
    wartosc_netto            DECIMAL(12,2),
    wartosc_brutto           DECIMAL(12,2),
    stawka_vat               VARCHAR(10),
    waluta                   VARCHAR(10)  DEFAULT 'PLN',
    harmonogram_platnosci    VARCHAR(50),
    termin_platnosci_dni     INTEGER,
    numer_projektu           VARCHAR(255),
    wymagana_faktura         INTEGER      DEFAULT 1,
    opiekun                  VARCHAR(255),
    wymagany_protokol        INTEGER      DEFAULT 0,
    data_odbioru             DATE,
    forma_podpisania         VARCHAR(20),
    platforma_el             VARCHAR(100),
    id_dokumentu_el          VARCHAR(255),
    plik_potwierdzenia       VARCHAR(500),
    plik_umowy               VARCHAR(500),
    zalaczniki               VARCHAR(1000),
    uwagi                    TEXT,
    nr_roboczy               VARCHAR(100),
    nr_system                VARCHAR(100),
    nr_rejestru              VARCHAR(100),
    email                    VARCHAR(255),
    epodpis_dostawca         VARCHAR(100),
    epodpis_nr_certyfikatu   VARCHAR(255),
    epodpis_data_waznosci    DATE,
    created_by               INTEGER,
    created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

section('Umowy — Wolontariat');

run('umowy_wolontariat', "CREATE TABLE IF NOT EXISTS umowy_wolontariat (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    numer_umowy              VARCHAR(100) NOT NULL,
    status                   VARCHAR(50)  DEFAULT 'projekt',
    imie_nazwisko            VARCHAR(255),
    pesel                    VARCHAR(11),
    adres                    TEXT,
    telefon                  VARCHAR(20),
    email                    VARCHAR(255),
    data_urodzenia           DATE,
    niepelnoletni            INTEGER      DEFAULT 0,
    zgoda_opiekuna           VARCHAR(500),
    przedmiot_porozumienia   TEXT,
    miejsce_wolontariatu     VARCHAR(255),
    data_zawarcia            DATE,
    data_rozpoczecia         DATE,
    data_zakonczenia         DATE,
    bezterminowa             INTEGER      DEFAULT 0,
    godzin_tygodniowo        DECIMAL(5,2),
    godzin_przepracowanych   DECIMAL(8,2) DEFAULT 0,
    ubezpieczenie_nnw        INTEGER      DEFAULT 0,
    numer_polisy_nnw         VARCHAR(100),
    ubezpieczenie_oc         INTEGER      DEFAULT 0,
    szkolenie_bhp            INTEGER      DEFAULT 0,
    data_szkolenia_bhp       DATE,
    zwrot_kosztow            INTEGER      DEFAULT 0,
    zwrot_kosztow_opis       TEXT,
    opiekun                  VARCHAR(255),
    projekt_program          VARCHAR(255),
    forma_podpisania         VARCHAR(20),
    platforma_el             VARCHAR(100),
    id_dokumentu_el          VARCHAR(255),
    plik_potwierdzenia       VARCHAR(500),
    plik_umowy               VARCHAR(500),
    uwagi                    TEXT,
    nr_roboczy               VARCHAR(100),
    nr_system                VARCHAR(100),
    nr_rejestru              VARCHAR(100),
    m365_konto               INTEGER      DEFAULT 0,
    m365_login               VARCHAR(255),
    m365_user_id             VARCHAR(255),
    m365_konto_aktywne       INTEGER      DEFAULT 0,
    m365_data_utworzenia     DATETIME,
    m365_licencja_przypisana INTEGER      DEFAULT 0,
    epodpis_dostawca         VARCHAR(100),
    epodpis_nr_certyfikatu   VARCHAR(255),
    epodpis_data_waznosci    DATE,
    adres_odbiorca           TEXT,
    adres_linia1             TEXT,
    adres_linia2             TEXT,
    adres_kod_pocztowy       TEXT,
    adres_miasto             TEXT,
    adres_kraj               TEXT         DEFAULT 'PL',
    webngo_id                INT,
    z_webngo                 INTEGER      DEFAULT 0,
    webngo_numer_umowy       TEXT,
    rodzic_imie_nazwisko     TEXT,
    rodzic_email             TEXT,
    rodzic_telefon           TEXT,
    created_by               INTEGER,
    created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

section('Umowy — Dzieło');

run('umowy_dzielo', "CREATE TABLE IF NOT EXISTS umowy_dzielo (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    numer_umowy              VARCHAR(100) NOT NULL,
    status                   VARCHAR(50)  DEFAULT 'projekt',
    imie_nazwisko            VARCHAR(255),
    pesel                    VARCHAR(11),
    adres                    TEXT,
    urzad_skarbowy           VARCHAR(255),
    rachunek_bankowy         VARCHAR(50),
    opis_dziela              TEXT,
    termin_oddania           DATE,
    data_zawarcia            DATE,
    wynagrodzenie_brutto     DECIMAL(12,2),
    kup50                    INTEGER      DEFAULT 0,
    zaliczka_podatek         DECIMAL(10,2),
    prawa_autorskie          INTEGER      DEFAULT 0,
    zakres_praw              TEXT,
    wymagany_protokol        INTEGER      DEFAULT 0,
    data_odbioru             DATE,
    dzielo_przyjete          INTEGER      DEFAULT 0,
    data_zl_rachunku         DATE,
    numer_projektu           VARCHAR(255),
    opiekun                  VARCHAR(255),
    forma_podpisania         VARCHAR(20),
    platforma_el             VARCHAR(100),
    id_dokumentu_el          VARCHAR(255),
    plik_potwierdzenia       VARCHAR(500),
    plik_umowy               VARCHAR(500),
    uwagi                    TEXT,
    nr_roboczy               VARCHAR(100),
    nr_system                VARCHAR(100),
    nr_rejestru              VARCHAR(100),
    m365_konto               INTEGER      DEFAULT 0,
    m365_login               VARCHAR(255),
    m365_user_id             VARCHAR(255),
    m365_konto_aktywne       INTEGER      DEFAULT 0,
    m365_data_utworzenia     DATETIME,
    m365_licencja_przypisana INTEGER      DEFAULT 0,
    email                    VARCHAR(255),
    epodpis_dostawca         VARCHAR(100),
    epodpis_nr_certyfikatu   VARCHAR(255),
    epodpis_data_waznosci    DATE,
    created_by               INTEGER,
    created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

section('Umowy — Praca');

run('umowy_praca', "CREATE TABLE IF NOT EXISTS umowy_praca (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    numer_umowy              VARCHAR(100) NOT NULL,
    status                   VARCHAR(50)  DEFAULT 'obowiązująca',
    imie_nazwisko            VARCHAR(255),
    pesel                    VARCHAR(11),
    adres                    TEXT,
    seria_nr_dowodu          VARCHAR(50),
    urzad_skarbowy           VARCHAR(255),
    rachunek_bankowy         VARCHAR(50),
    email_login              VARCHAR(255),
    stanowisko               VARCHAR(255),
    dzial_projekt            VARCHAR(255),
    wymiar_etatu             VARCHAR(20),
    rodzaj_umowy             VARCHAR(50),
    data_zawarcia            DATE,
    data_rozpoczecia         DATE,
    data_zakonczenia         DATE,
    wynagrodzenie_brutto     DECIMAL(12,2),
    skladniki_wynagrodzenia  TEXT,
    urlop_wymiar             INTEGER,
    urlop_zalegly            INTEGER      DEFAULT 0,
    okres_wypowiedzenia      VARCHAR(100),
    ppk                      INTEGER      DEFAULT 0,
    pit2                     INTEGER      DEFAULT 0,
    badania_data_waznosci    DATE,
    bhp_data_waznosci        DATE,
    klauzula_rodo            INTEGER      DEFAULT 0,
    opiekun_przelozony       VARCHAR(255),
    forma_podpisania         VARCHAR(20),
    platforma_el             VARCHAR(100),
    id_dokumentu_el          VARCHAR(255),
    plik_potwierdzenia       VARCHAR(500),
    plik_umowy               VARCHAR(500),
    aneksy                   TEXT,
    uwagi                    TEXT,
    nr_roboczy               VARCHAR(100),
    nr_system                VARCHAR(100),
    nr_rejestru              VARCHAR(100),
    email                    VARCHAR(255),
    epodpis_dostawca         VARCHAR(100),
    epodpis_nr_certyfikatu   VARCHAR(255),
    epodpis_data_waznosci    DATE,
    created_by               INTEGER,
    created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

section('Umowy — Inne');

run('umowy_inne', "CREATE TABLE IF NOT EXISTS umowy_inne (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    numer_umowy              VARCHAR(100) NOT NULL,
    status                   VARCHAR(50)  DEFAULT 'projekt',
    typ_umowy                VARCHAR(255),
    strona_umowy             VARCHAR(255),
    pesel_nip_krs            VARCHAR(50),
    adres                    TEXT,
    przedmiot_umowy          TEXT,
    data_zawarcia            DATE,
    data_rozpoczecia         DATE,
    data_zakonczenia         DATE,
    czas_nieokreslony        INTEGER      DEFAULT 0,
    okres_wypowiedzenia      VARCHAR(100),
    wartosc_umowy            DECIMAL(12,2),
    waluta                   VARCHAR(10)  DEFAULT 'PLN',
    warunki_finansowe        TEXT,
    numer_projektu           VARCHAR(255),
    opiekun                  VARCHAR(255),
    dzialania_cykliczne      INTEGER      DEFAULT 0,
    dzialania_opis           TEXT,
    data_przegladu           DATE,
    forma_podpisania         VARCHAR(20),
    platforma_el             VARCHAR(100),
    id_dokumentu_el          VARCHAR(255),
    plik_potwierdzenia       VARCHAR(500),
    plik_umowy               VARCHAR(500),
    zalaczniki               VARCHAR(1000),
    uwagi                    TEXT,
    nr_roboczy               VARCHAR(100),
    nr_system                VARCHAR(100),
    nr_rejestru              VARCHAR(100),
    email                    VARCHAR(255),
    epodpis_dostawca         VARCHAR(100),
    epodpis_nr_certyfikatu   VARCHAR(255),
    epodpis_data_waznosci    DATE,
    created_by               INTEGER,
    created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════════════════════
// 3. USTAWIENIA
// ══════════════════════════════════════════════════════════════════════════════
section('Ustawienia');

run('settings', "CREATE TABLE IF NOT EXISTS settings (
    key_  VARCHAR(100) PRIMARY KEY,
    value TEXT
)");

// ══════════════════════════════════════════════════════════════════════════════
// 4. OBIEG DOKUMENTÓW
// ══════════════════════════════════════════════════════════════════════════════
section('Obieg dokumentów');

run('contract_approvals', "CREATE TABLE IF NOT EXISTS contract_approvals (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type VARCHAR(50)  NOT NULL,
    contract_id   INTEGER      NOT NULL,
    requested_by  INTEGER      NOT NULL,
    requested_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    token         VARCHAR(64)  UNIQUE NOT NULL,
    token_expires DATETIME     NOT NULL,
    status        VARCHAR(20)  DEFAULT 'oczekuje',
    decided_by    INTEGER,
    decided_at    DATETIME,
    decision_note TEXT,
    email_sent    INTEGER      DEFAULT 0,
    via_email     INTEGER      DEFAULT 0
)");

run('idx_approvals_contract', "CREATE INDEX IF NOT EXISTS idx_approvals_contract ON contract_approvals(contract_type, contract_id)");
run('idx_approvals_token',    "CREATE INDEX IF NOT EXISTS idx_approvals_token    ON contract_approvals(token)");

run('contract_audit_log', "CREATE TABLE IF NOT EXISTS contract_audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type VARCHAR(50),
    contract_id   INTEGER,
    user_id       INTEGER,
    user_snapshot VARCHAR(255),
    action        VARCHAR(50)  NOT NULL,
    note          TEXT,
    ip_address    VARCHAR(45),
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

run('idx_audit_contract', "CREATE INDEX IF NOT EXISTS idx_audit_contract ON contract_audit_log(contract_type, contract_id)");

run('contract_amendments', "CREATE TABLE IF NOT EXISTS contract_amendments (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type  VARCHAR(50)  NOT NULL,
    contract_id    INTEGER      NOT NULL,
    numer_aneksu   INTEGER      NOT NULL DEFAULT 1,
    requested_by   INTEGER,
    requested_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    opis_zmian     TEXT         NOT NULL,
    plik_aneksu    VARCHAR(500),
    status         VARCHAR(20)  DEFAULT 'oczekuje',
    token          VARCHAR(64)  UNIQUE,
    token_expires  DATETIME,
    decided_by     INTEGER,
    decided_at     DATETIME,
    decision_note  TEXT,
    via_email      INTEGER      DEFAULT 0,
    email_sent     INTEGER      DEFAULT 0,
    proposed_changes TEXT,
    applied_at     DATETIME
)");

run('idx_amendments_contract', "CREATE INDEX IF NOT EXISTS idx_amendments_contract ON contract_amendments(contract_type, contract_id)");
run('idx_amendments_token',    "CREATE INDEX IF NOT EXISTS idx_amendments_token    ON contract_amendments(token)");

run('contract_edit_requests', "CREATE TABLE IF NOT EXISTS contract_edit_requests (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type VARCHAR(50)  NOT NULL,
    contract_id   INTEGER      NOT NULL,
    requested_by  INTEGER,
    requested_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    opis_zmian    TEXT         NOT NULL,
    status        VARCHAR(20)  DEFAULT 'oczekuje',
    token         VARCHAR(64)  UNIQUE,
    token_expires DATETIME,
    decided_by    INTEGER,
    decided_at    DATETIME,
    decision_note TEXT,
    via_email     INTEGER      DEFAULT 0,
    email_sent    INTEGER      DEFAULT 0
)");

run('idx_editreq_contract', "CREATE INDEX IF NOT EXISTS idx_editreq_contract ON contract_edit_requests(contract_type, contract_id)");
run('idx_editreq_token',    "CREATE INDEX IF NOT EXISTS idx_editreq_token    ON contract_edit_requests(token)");

run('contract_termination_requests', "CREATE TABLE IF NOT EXISTS contract_termination_requests (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type  TEXT    NOT NULL,
    contract_id    INTEGER NOT NULL,
    requested_by   INTEGER,
    requester_name TEXT    NOT NULL,
    powod          TEXT    NOT NULL,
    proposed_date  TEXT,
    status         TEXT    NOT NULL DEFAULT 'oczekuje',
    decided_by     INTEGER,
    decided_at     DATETIME,
    decision_note  TEXT,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════════════════════
// 5. ZAŚWIADCZENIA
// ══════════════════════════════════════════════════════════════════════════════
section('Zaświadczenia');

run('certificate_requests', "CREATE TABLE IF NOT EXISTS certificate_requests (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type       TEXT    NOT NULL,
    contract_id         INTEGER NOT NULL,
    requested_by        INTEGER,
    requester_name      TEXT    NOT NULL,
    requester_email     TEXT    NOT NULL,
    cel                 TEXT    NOT NULL,
    status              TEXT    NOT NULL DEFAULT 'oczekuje',
    issued_by           INTEGER,
    issued_at           DATETIME,
    rejection_note      TEXT,
    certificate_content TEXT,
    certificate_file    TEXT,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════════════════════
// 6. PISMA
// ══════════════════════════════════════════════════════════════════════════════
section('Pisma i dokumenty');

run('contract_letters', "CREATE TABLE IF NOT EXISTS contract_letters (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type  TEXT    NOT NULL,
    contract_id    INTEGER NOT NULL,
    kierunek       TEXT    NOT NULL DEFAULT 'wychodzące',
    typ_pisma      TEXT    NOT NULL DEFAULT 'inne',
    tytul          TEXT    NOT NULL,
    tresc          TEXT,
    plik           TEXT,
    data_pisma     TEXT,
    nadawca        TEXT,
    odbiorca       TEXT,
    odbiorca_email TEXT,
    uwagi          TEXT,
    email_sent     INTEGER DEFAULT 0,
    postivo_id     TEXT,
    postivo_status TEXT,
    created_by     INTEGER,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════════════════════
// 7. WIADOMOŚCI
// ══════════════════════════════════════════════════════════════════════════════
section('Wiadomości');

run('messages', "CREATE TABLE IF NOT EXISTS messages (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    context_type   TEXT    NOT NULL DEFAULT 'contract',
    context_id     INTEGER NOT NULL,
    contract_type  TEXT    NOT NULL DEFAULT '',
    sender_type    TEXT    NOT NULL DEFAULT 'admin',
    sender_id      INTEGER,
    sender_name    TEXT    NOT NULL DEFAULT '',
    body           TEXT    NOT NULL DEFAULT '',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_read        INTEGER NOT NULL DEFAULT 0,
    recipient_type TEXT    NOT NULL DEFAULT 'admin',
    subject        TEXT    NOT NULL DEFAULT '',
    type_id        INTEGER DEFAULT NULL
)");

run('idx_messages_ctx', "CREATE INDEX IF NOT EXISTS idx_messages_ctx ON messages(context_type, context_id)");

run('message_types', "CREATE TABLE IF NOT EXISTS message_types (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    description   TEXT    NOT NULL DEFAULT '',
    available_for TEXT    NOT NULL DEFAULT 'both',
    is_active     INTEGER NOT NULL DEFAULT 1,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════════════════════
// 8. ONBOARDING
// ══════════════════════════════════════════════════════════════════════════════
section('Onboarding');

run('onboarding_volunteers', "CREATE TABLE IF NOT EXISTS onboarding_volunteers (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    session_token        TEXT    UNIQUE,
    status               TEXT    NOT NULL DEFAULT 'new',
    imie_nazwisko        TEXT    NOT NULL DEFAULT '',
    pesel                TEXT    NOT NULL DEFAULT '',
    data_urodzenia       TEXT    NOT NULL DEFAULT '',
    adres                TEXT    NOT NULL DEFAULT '',
    telefon              TEXT    NOT NULL DEFAULT '',
    email                TEXT    NOT NULL DEFAULT '',
    phone_verified       INTEGER NOT NULL DEFAULT 0,
    phone_code           TEXT,
    phone_code_expires   TEXT,
    email_verified       INTEGER NOT NULL DEFAULT 0,
    email_token          TEXT,
    email_token_expires  TEXT,
    klauzula_accepted    INTEGER NOT NULL DEFAULT 0,
    klauzula_version     TEXT    NOT NULL DEFAULT '',
    admin_note           TEXT    NOT NULL DEFAULT '',
    ip_address           TEXT    NOT NULL DEFAULT '',
    user_agent           TEXT    NOT NULL DEFAULT '',
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP
)");

run('onboarding_messages', "CREATE TABLE IF NOT EXISTS onboarding_messages (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    volunteer_id INTEGER NOT NULL,
    subject      TEXT    NOT NULL DEFAULT '',
    body         TEXT    NOT NULL DEFAULT '',
    sent_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_by      INTEGER
)");

// ══════════════════════════════════════════════════════════════════════════════
// 9. SMS
// ══════════════════════════════════════════════════════════════════════════════
section('SMS');

run('sms_login_tokens', "CREATE TABLE IF NOT EXISTS sms_login_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    phone      TEXT    NOT NULL,
    code       TEXT    NOT NULL,
    user_id    INTEGER NOT NULL,
    expires_at TEXT    NOT NULL,
    used_at    TEXT,
    created_at TEXT    DEFAULT (datetime('now'))
)");

// ══════════════════════════════════════════════════════════════════════════════
// 10. MICROSOFT 365
// ══════════════════════════════════════════════════════════════════════════════
section('Microsoft 365');

run('m365_standalone_accounts', "CREATE TABLE IF NOT EXISTS m365_standalone_accounts (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    imie_nazwisko             VARCHAR(255) NOT NULL,
    email                     VARCHAR(255),
    opis                      TEXT,
    m365_login                VARCHAR(255),
    m365_user_id              VARCHAR(255),
    m365_konto_aktywne        INTEGER      DEFAULT 0,
    m365_licencja_przypisana  INTEGER      DEFAULT 0,
    m365_data_utworzenia      DATETIME,
    linked_user_id            INTEGER,
    created_by                INTEGER,
    created_at                DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════════════════════
// 11. OPIEKUNOWIE UMÓW
// ══════════════════════════════════════════════════════════════════════════════
section('Opiekunowie umów');

run('contract_supervisors', "CREATE TABLE IF NOT EXISTS contract_supervisors (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type TEXT    NOT NULL,
    contract_id   INTEGER NOT NULL,
    user_id       INTEGER NOT NULL,
    user_name     TEXT    NOT NULL DEFAULT '',
    user_email    TEXT    NOT NULL DEFAULT '',
    assigned_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(contract_type, contract_id)
)");

// ══════════════════════════════════════════════════════════════════════════════
// 12. ZADANIA (KANBAN)
// ══════════════════════════════════════════════════════════════════════════════
section('Zadania — Kanban');

run('task_workspaces', "CREATE TABLE IF NOT EXISTS task_workspaces (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    slug        TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT,
    color       TEXT    NOT NULL DEFAULT '#2563eb',
    icon        TEXT    NOT NULL DEFAULT 'bi-kanban',
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_by  INTEGER NOT NULL,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

run('task_lists', "CREATE TABLE IF NOT EXISTS task_lists (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    name         TEXT    NOT NULL,
    position     REAL    NOT NULL DEFAULT 0,
    color        TEXT,
    is_done_state INTEGER NOT NULL DEFAULT 0,
    wip_limit    INTEGER,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE
)");

run('task_tags', "CREATE TABLE IF NOT EXISTS task_tags (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER,
    name         TEXT    NOT NULL,
    color        TEXT    NOT NULL DEFAULT '#64748b',
    text_color   TEXT    NOT NULL DEFAULT '#ffffff',
    is_active    INTEGER NOT NULL DEFAULT 1,
    created_by   INTEGER NOT NULL,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

run('tasks', "CREATE TABLE IF NOT EXISTS tasks (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id    INTEGER NOT NULL,
    list_id         INTEGER NOT NULL,
    title           TEXT    NOT NULL,
    description     TEXT,
    position        REAL    NOT NULL DEFAULT 0,
    priority        INTEGER NOT NULL DEFAULT 2,
    start_date      TEXT,
    due_date        TEXT,
    estimated_hours REAL,
    created_by      INTEGER NOT NULL,
    completed_at    TEXT,
    deleted_at      TEXT,
    contract_type   TEXT    DEFAULT NULL,
    contract_id     INTEGER DEFAULT NULL,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (workspace_id) REFERENCES task_workspaces(id) ON DELETE CASCADE,
    FOREIGN KEY (list_id)      REFERENCES task_lists(id)      ON DELETE RESTRICT,
    FOREIGN KEY (created_by)   REFERENCES users(id)           ON DELETE RESTRICT
)");

// tasks v6 — dla upgrade
run('tasks — contract_type',  "ALTER TABLE tasks ADD COLUMN contract_type TEXT DEFAULT NULL");
run('tasks — contract_id',    "ALTER TABLE tasks ADD COLUMN contract_id INTEGER DEFAULT NULL");

run('idx_tasks_list',     "CREATE INDEX IF NOT EXISTS idx_tasks_list     ON tasks(list_id, position)");
run('idx_tasks_due',      "CREATE INDEX IF NOT EXISTS idx_tasks_due      ON tasks(due_date, completed_at, deleted_at)");
run('idx_tasks_ws',       "CREATE INDEX IF NOT EXISTS idx_tasks_ws       ON tasks(workspace_id, deleted_at)");
run('idx_tasks_contract', "CREATE INDEX IF NOT EXISTS idx_tasks_contract ON tasks(contract_type, contract_id)");

run('task_task_tags', "CREATE TABLE IF NOT EXISTS task_task_tags (
    task_id     INTEGER NOT NULL,
    tag_id      INTEGER NOT NULL,
    assigned_by INTEGER NOT NULL,
    assigned_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (task_id, tag_id)
)");

run('task_assignments', "CREATE TABLE IF NOT EXISTS task_assignments (
    task_id     INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    assigned_by INTEGER NOT NULL,
    assigned_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (task_id, user_id)
)");

run('task_comments', "CREATE TABLE IF NOT EXISTS task_comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER NOT NULL,
    author_id  INTEGER NOT NULL,
    body       TEXT    NOT NULL,
    is_edited  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

run('task_history', "CREATE TABLE IF NOT EXISTS task_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id     INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    event_type  TEXT    NOT NULL,
    from_value  TEXT,
    to_value    TEXT,
    metadata    TEXT,
    occurred_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

run('task_list_time', "CREATE TABLE IF NOT EXISTS task_list_time (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id          INTEGER NOT NULL,
    list_id          INTEGER NOT NULL,
    list_name        TEXT    NOT NULL,
    entered_at       TEXT    NOT NULL,
    exited_at        TEXT,
    duration_seconds INTEGER
)");

run('task_workspace_members', "CREATE TABLE IF NOT EXISTS task_workspace_members (
    workspace_id INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    role         TEXT    NOT NULL DEFAULT 'editor',
    added_by     INTEGER NOT NULL,
    added_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (workspace_id, user_id)
)");

run('task_files', "CREATE TABLE IF NOT EXISTS task_files (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id       INTEGER NOT NULL,
    original_name TEXT    NOT NULL,
    stored_name   TEXT    NOT NULL,
    file_size     INTEGER NOT NULL DEFAULT 0,
    mime_type     TEXT,
    uploaded_by   INTEGER NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

run('task_subtasks', "CREATE TABLE IF NOT EXISTS task_subtasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id      INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    is_done      INTEGER NOT NULL DEFAULT 0,
    position     REAL    NOT NULL DEFAULT 0,
    created_by   INTEGER NOT NULL,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    completed_at TEXT
)");

run('task_notification_prefs', "CREATE TABLE IF NOT EXISTS task_notification_prefs (
    user_id          INTEGER PRIMARY KEY,
    notify_assigned  INTEGER NOT NULL DEFAULT 1,
    notify_mentioned INTEGER NOT NULL DEFAULT 1,
    notify_comment   INTEGER NOT NULL DEFAULT 0,
    notify_due_1day  INTEGER NOT NULL DEFAULT 1,
    notify_due_today INTEGER NOT NULL DEFAULT 1,
    notify_sms       INTEGER NOT NULL DEFAULT 0,
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

run('task_notification_log', "CREATE TABLE IF NOT EXISTS task_notification_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    event_type TEXT    NOT NULL,
    ref_id     INTEGER NOT NULL,
    sent_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

// ══════════════════════════════════════════════════════════════════════════════
// 13. DOMYŚLNE SETTINGS
// ══════════════════════════════════════════════════════════════════════════════
section('Domyślne ustawienia');

$defaults = [
    'sms_enabled'          => '0',
    'sms_provider'         => 'smsapi',
    'sms_sender_name'      => 'INFO',
    'wa_enabled'           => '0',
    'tasks_enabled'        => '1',
    'onboarding_enabled'   => '0',
    'onboarding_title'     => 'Kwestionariusz wolontariusza',
    'onboarding_intro'     => '',
    'm365_enabled'         => '0',
    'ceidg_enabled'        => '0',
    'postivo_enabled'      => '0',
    'msg_notify_email'     => '0',
];

foreach ($defaults as $k => $v) {
    try {
        $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
        if (!$exists) {
            db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$k, $v]);
            $results[] = ['ok' => true, 'label' => "setting: $k = $v"];
            if ($isCli) echo "  ✓ setting: $k = $v\n";
        }
    } catch (\Throwable $e) {
        $results[] = ['ok' => false, 'label' => "setting: $k", 'note' => $e->getMessage()];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 14. KONTO ADMINISTRATORA
// ══════════════════════════════════════════════════════════════════════════════
section('Konto admina');

$admin_email = $isCli ? ($argv[1] ?? 'admin@local') : ($_GET['email'] ?? 'admin@local');
$admin_pass  = $isCli ? ($argv[2] ?? 'changeme')    : ($_GET['pass']  ?? 'changeme');
$admin_name  = 'Administrator';

try {
    $exists = db_one("SELECT id FROM users WHERE email=?", [$admin_email]);
    $hash   = password_hash($admin_pass, PASSWORD_BCRYPT);
    if ($exists) {
        db()->prepare("UPDATE users SET password=?, role='admin', is_active=1 WHERE email=?")
             ->execute([$hash, $admin_email]);
        $results[] = ['ok' => true, 'label' => "admin — zaktualizowano: $admin_email"];
        if ($isCli) echo "  · admin — zaktualizowano: $admin_email\n";
    } else {
        db()->prepare("INSERT INTO users (name,email,password,role,is_active) VALUES (?,?,?,'admin',1)")
             ->execute([$admin_name, $admin_email, $hash]);
        $results[] = ['ok' => true, 'label' => "admin — utworzono: $admin_email"];
        if ($isCli) echo "  ✓ admin — utworzono: $admin_email / $admin_pass\n";
    }
} catch (\Throwable $e) {
    $results[] = ['ok' => false, 'label' => 'admin — błąd', 'note' => $e->getMessage()];
}

// ══════════════════════════════════════════════════════════════════════════════
// PODSUMOWANIE CLI
// ══════════════════════════════════════════════════════════════════════════════
if ($isCli) {
    $ok  = count(array_filter($results, fn($r) => $r['ok']));
    $err = count(array_filter($results, fn($r) => !$r['ok']));
    echo "\n══════════════════════════════════\n";
    echo "Gotowe: $ok OK  |  $err błędów\n";
    echo "Konto admin: $admin_email / $admin_pass\n";
    echo "══════════════════════════════════\n";
    exit(0);
}

// ══════════════════════════════════════════════════════════════════════════════
// WYNIK W PRZEGLĄDARCE
// ══════════════════════════════════════════════════════════════════════════════
$ok_count  = count(array_filter($results, fn($r) => $r['ok']));
$err_count = count(array_filter($results, fn($r) => !$r['ok']));
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Setup — Rejestr Umów</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:720px">

  <h3 class="mb-1"><i class="bi bi-database-check text-primary"></i> Setup — Rejestr Umów</h3>
  <p class="text-muted small mb-4">Struktura bazy danych + konto admina</p>

  <?php if ($err_count === 0): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-2"></i>
    <strong>Wszystko gotowe!</strong> <?= $ok_count ?> operacji zakończonych pomyślnie.
  </div>
  <?php else: ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <?= $ok_count ?> OK · <?= $err_count ?> błędów (szczegóły poniżej)
  </div>
  <?php endif; ?>

  <!-- Konto admina -->
  <div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-person-lock"></i> Dane konta admina</div>
  <div class="card-body">
    <table class="table table-sm mb-0">
      <tr><td class="text-muted">E-mail</td><td><code><?= htmlspecialchars($admin_email) ?></code></td></tr>
      <tr><td class="text-muted">Hasło</td><td><code><?= htmlspecialchars($admin_pass) ?></code></td></tr>
      <tr><td class="text-muted">Rola</td><td><span class="badge bg-danger">admin</span></td></tr>
    </table>
  </div>
  </div>

  <!-- Log operacji -->
  <div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
    <span><i class="bi bi-list-check"></i> Log operacji</span>
    <button class="btn btn-sm btn-outline-secondary" type="button"
            data-bs-toggle="collapse" data-bs-target="#logBody">
      Pokaż / ukryj
    </button>
  </div>
  <div class="collapse" id="logBody">
  <div class="card-body p-0">
    <ul class="list-group list-group-flush small">
      <?php foreach ($results as $r): ?>
      <li class="list-group-item py-1 px-3 d-flex align-items-center gap-2">
        <?php if ($r['ok']): ?>
        <i class="bi bi-check-circle text-success flex-shrink-0"></i>
        <?php else: ?>
        <i class="bi bi-exclamation-circle text-danger flex-shrink-0"></i>
        <?php endif; ?>
        <span><?= htmlspecialchars($r['label']) ?></span>
        <?php if (!empty($r['note'])): ?>
        <span class="text-muted ms-auto"><?= htmlspecialchars($r['note']) ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  </div>
  </div>

  <div class="d-flex gap-2">
    <a href="<?= defined('APP_URL') ? APP_URL : '..' ?>/auth/login.php" class="btn btn-primary">
      <i class="bi bi-box-arrow-in-right"></i> Zaloguj się
    </a>
    <div class="alert alert-danger py-2 px-3 mb-0 small d-flex align-items-center gap-2">
      <i class="bi bi-shield-exclamation"></i>
      <strong>Usuń plik setup/setup.php</strong> po zakończeniu konfiguracji!
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
