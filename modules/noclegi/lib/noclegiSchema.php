<?php
/**
 * modules/noclegi/lib/noclegiSchema.php — samonaprawa schematu modułu Noclegi (tabele nocl_*).
 * Wywołanie: noclMigrate() — idempotentne, za bramką szo_schema_current.
 */
/**
 * Znacznik „Dop. noclegi” na umowie (wolontariat, zlecenie): tylko osoba z aktywną umową z tym znacznikiem może sama
 * zgłaszać noclegi (noclEligible). Wywoływane z formularzy umów i z noclMigrate().
 */
function noclContractFlagMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['umowy_wolontariat', 'umowy_zlecenie'] as $t) {
        try {
            $has = false;
            foreach (db_all("PRAGMA table_info($t)") as $c) if ($c['name'] === 'dop_noclegi') $has = true;
            if (!$has) db()->exec("ALTER TABLE $t ADD COLUMN dop_noclegi INTEGER NOT NULL DEFAULT 0");
        } catch (\Throwable $e) { error_log('noclegi: dop_noclegi ' . $t . ' — ' . $e->getMessage()); }
    }
}

function noclMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('noclegi_migrate', __FILE__)) return;
    noclContractFlagMigrate();
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS nocl_user_roles (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        role       TEXT    NOT NULL,
        budget_id  INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT,
        UNIQUE(user_id, role, budget_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS nocl_facilities (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        type          TEXT NOT NULL DEFAULT 'hotel',
        city          TEXT NOT NULL DEFAULT '',
        address       TEXT NOT NULL DEFAULT '',
        phone         TEXT NOT NULL DEFAULT '',
        email         TEXT NOT NULL DEFAULT '',
        map_url       TEXT NOT NULL DEFAULT '',
        checkin_from  TEXT NOT NULL DEFAULT '15:00',
        checkin_to    TEXT NOT NULL DEFAULT '22:00',
        checkout_until TEXT NOT NULL DEFAULT '11:00',
        accessible    INTEGER NOT NULL DEFAULT 0,
        accessibility_info TEXT NOT NULL DEFAULT '',
        access_info   TEXT NOT NULL DEFAULT '',
        rules         TEXT NOT NULL DEFAULT '',
        notes         TEXT NOT NULL DEFAULT '',
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_at    TEXT,
        updated_at    TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS nocl_rooms (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        facility_id    INTEGER NOT NULL REFERENCES nocl_facilities(id) ON DELETE CASCADE,
        name           TEXT NOT NULL,
        room_type      TEXT NOT NULL DEFAULT 'dwuosobowy',
        beds           INTEGER NOT NULL DEFAULT 2,
        accessible     INTEGER NOT NULL DEFAULT 0,
        price_per_night TEXT NOT NULL DEFAULT '0',
        door_code      TEXT NOT NULL DEFAULT '',
        lockbox        TEXT NOT NULL DEFAULT '',
        notes          TEXT NOT NULL DEFAULT '',
        is_active      INTEGER NOT NULL DEFAULT 1,
        created_at     TEXT,
        updated_at     TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nocl_rooms_fac ON nocl_rooms(facility_id)");
    $db->exec("CREATE TABLE IF NOT EXISTS nocl_budgets (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        code            TEXT NOT NULL,
        name            TEXT NOT NULL,
        limit_total     TEXT NOT NULL DEFAULT '0',
        limit_per_night TEXT NOT NULL DEFAULT '0',
        valid_from      TEXT NOT NULL DEFAULT '',
        valid_to        TEXT NOT NULL DEFAULT '',
        note            TEXT NOT NULL DEFAULT '',
        is_active       INTEGER NOT NULL DEFAULT 1,
        created_at      TEXT,
        updated_at      TEXT,
        UNIQUE(code)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS nocl_requests (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id          INTEGER,
        person_name      TEXT NOT NULL,
        person_email     TEXT NOT NULL DEFAULT '',
        person_phone     TEXT NOT NULL DEFAULT '',
        person_role      TEXT NOT NULL DEFAULT 'dydaktyk',
        date_from        TEXT NOT NULL,
        date_to          TEXT NOT NULL,
        city             TEXT NOT NULL DEFAULT '',
        purpose          TEXT NOT NULL DEFAULT '',
        course_id        INTEGER,
        source           TEXT NOT NULL DEFAULT 'form',
        ti_key           TEXT NOT NULL DEFAULT '',
        room_type_pref   TEXT NOT NULL DEFAULT '',
        preferences      TEXT NOT NULL DEFAULT '',
        roommate_pref    TEXT NOT NULL DEFAULT '',
        needs_accessible INTEGER NOT NULL DEFAULT 0,
        accessibility_notes TEXT NOT NULL DEFAULT '',
        budget_id        INTEGER,
        status           TEXT NOT NULL DEFAULT 'wniosek',
        facility_id      INTEGER,
        room_id          INTEGER,
        cost_total       TEXT NOT NULL DEFAULT '0',
        budget_approved  INTEGER NOT NULL DEFAULT 0,
        budget_approved_by INTEGER,
        budget_approved_at TEXT,
        coord_note       TEXT NOT NULL DEFAULT '',
        logistics_note   TEXT NOT NULL DEFAULT '',
        confirm_mail_at  TEXT,
        reminder_at      TEXT,
        created_by       INTEGER,
        created_at       TEXT,
        updated_at       TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nocl_req_status ON nocl_requests(status, date_from)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nocl_req_user ON nocl_requests(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nocl_req_room ON nocl_requests(room_id, date_from)");
    $db->exec("CREATE TABLE IF NOT EXISTS nocl_invoices (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        budget_id   INTEGER,
        request_id  INTEGER,
        facility_id INTEGER,
        number      TEXT NOT NULL DEFAULT '',
        category    TEXT NOT NULL DEFAULT 'nocleg',
        issue_date  TEXT NOT NULL DEFAULT '',
        due_date    TEXT NOT NULL DEFAULT '',
        amount      TEXT NOT NULL DEFAULT '0',
        status      TEXT NOT NULL DEFAULT 'oczekuje',
        note        TEXT NOT NULL DEFAULT '',
        file_name   TEXT NOT NULL DEFAULT '',
        file_path   TEXT NOT NULL DEFAULT '',
        approved_by INTEGER,
        approved_at TEXT,
        created_by  INTEGER,
        created_at  TEXT,
        updated_at  TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS nocl_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER,
        entity     TEXT NOT NULL DEFAULT 'request',
        action     TEXT NOT NULL,
        detail     TEXT NOT NULL DEFAULT '',
        user_id    INTEGER,
        user_name  TEXT NOT NULL DEFAULT '',
        created_at TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nocl_log_req ON nocl_log(request_id)");
}
