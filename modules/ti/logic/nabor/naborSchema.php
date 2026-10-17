<?php
/**
 * modules/ti/logic/nabor/naborSchema.php — TI › Nabór do grup: schemat.
 *
 * Grupy to istniejące grupy TI (k30_ti_courses, limit = max_students, zajęte = aktywne zapisy k30_ti_enrollments).
 * Tabele naboru:
 *   ti_nabor_courses     — które grupy są w publicznym formularzu + opis dla rodziców (kategoria, wiek, harmonogram, opłata),
 *   ti_nabor_submissions — zgłoszenia (dane uczestnika i opiekuna, powiązania: EZD, CRM, k30_clients po przyjęciu),
 *   ti_nabor_consents    — rejestr zgód RODO z adresem IP (audyt),
 *   ti_nabor_history     — dziennik obiegu (zmiany statusów z operatorem i notatką).
 * Kody SMS do weryfikacji numeru opiekuna: funkcjonalność modules/weryfikacja_sms (cel 'ti_nabor').
 */
require_once dirname(__DIR__, 4) . '/includes/db.php';
require_once dirname(__DIR__, 4) . '/includes/karty30.php';
require_once dirname(__DIR__, 4) . '/modules/selfrepairDB/logic/selfRepairDB.php';

function naborMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    karty30_migrate();
    if (szo_schema_current('ti_nabor_migrate', __FILE__)) return;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS ti_nabor_courses (
        course_id   INTEGER PRIMARY KEY REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        is_open     INTEGER NOT NULL DEFAULT 1,              -- widoczna w formularzu publicznym
        public_name TEXT    NOT NULL DEFAULT '',             -- nazwa dla rodziców ('' = display_name / name grupy)
        category    TEXT    NOT NULL DEFAULT '',
        age_group   TEXT    NOT NULL DEFAULT '',
        schedule    TEXT    NOT NULL DEFAULT '',             -- opis terminów ('' = dzień i godziny z grupy)
        price_info  TEXT    NOT NULL DEFAULT '',
        sort_order  INTEGER NOT NULL DEFAULT 0,
        updated_at  DATETIME NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ti_nabor_submissions (
        id                              INTEGER PRIMARY KEY AUTOINCREMENT,
        registration_number             TEXT    NOT NULL UNIQUE,     -- REK/2026/10/0042
        course_id                       INTEGER REFERENCES k30_ti_courses(id) ON DELETE SET NULL,  -- NULL = termin do ustalenia
        client_id                       INTEGER REFERENCES k30_clients(id) ON DELETE SET NULL,      -- uczestnik TI po przyjęciu
        ezd_case_id                     INTEGER,                     -- ezd_sprawy.id (koszulka)
        crm_contact_id                  INTEGER,
        status                          TEXT    NOT NULL DEFAULT 'unconfirmed',
        is_custom_schedule              INTEGER NOT NULL DEFAULT 0,
        preferred_days                  TEXT    NOT NULL DEFAULT '[]',
        preferred_time_slots            TEXT    NOT NULL DEFAULT '[]',
        notes_availability              TEXT    NOT NULL DEFAULT '',
        participant_name                TEXT    NOT NULL,
        participant_birth_date_or_pesel TEXT    NOT NULL DEFAULT '',
        participant_school_class        TEXT    NOT NULL DEFAULT '',
        participant_medical_notes       TEXT    NOT NULL DEFAULT '',
        guardian_name                   TEXT    NOT NULL,
        guardian_phone                  TEXT    NOT NULL,
        guardian_email                  TEXT    NOT NULL,
        guardian_street                 TEXT    NOT NULL DEFAULT '',
        guardian_postal_code            TEXT    NOT NULL DEFAULT '',
        guardian_city                   TEXT    NOT NULL DEFAULT '',
        pickup_type                     TEXT    NOT NULL DEFAULT 'guardians_only',  -- independent | guardians_only | authorized_persons
        authorized_persons_details      TEXT    NOT NULL DEFAULT '',
        payment_method                  TEXT    NOT NULL DEFAULT '',  -- deklaracja: transfer | cash | exempt ('' przy terminie do ustalenia)
        phone_verified_at               DATETIME,                     -- numer opiekuna potwierdzony kodem SMS
        email_token_hash                TEXT,                         -- sha256 tokenu z linku potwierdzającego
        email_confirmed_at              DATETIME,
        ip_address                      TEXT    NOT NULL DEFAULT '',
        created_at                      DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at                      DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $have = array_column($pdo->query("PRAGMA table_info(ti_nabor_submissions)")->fetchAll(), 'name');
    // Zgłoszenie testowe z panelu: bez SMS/e-maili, EZD, CRM i zapisu do grupy
    if (!in_array('is_test', $have, true)) $pdo->exec("ALTER TABLE ti_nabor_submissions ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_nabor_sub_status ON ti_nabor_submissions(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_nabor_sub_course ON ti_nabor_submissions(course_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_nabor_sub_token ON ti_nabor_submissions(email_token_hash)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ti_nabor_consents (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id     INTEGER NOT NULL REFERENCES ti_nabor_submissions(id) ON DELETE CASCADE,
        ip_address        TEXT    NOT NULL DEFAULT '',
        user_agent        TEXT    NOT NULL DEFAULT '',
        terms_accepted    INTEGER NOT NULL DEFAULT 0,
        gdpr_accepted     INTEGER NOT NULL DEFAULT 0,
        image_consent     INTEGER NOT NULL DEFAULT 0,
        marketing_consent INTEGER NOT NULL DEFAULT 0,
        created_at        DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_nabor_cons_sub ON ti_nabor_consents(submission_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ti_nabor_history (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id INTEGER NOT NULL REFERENCES ti_nabor_submissions(id) ON DELETE CASCADE,
        user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,   -- NULL = system / opiekun
        user_name     TEXT    NOT NULL DEFAULT '',
        from_status   TEXT    NOT NULL DEFAULT '',
        to_status     TEXT    NOT NULL,
        comment       TEXT    NOT NULL DEFAULT '',
        created_at    DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_nabor_hist_sub ON ti_nabor_history(submission_id)");
}
