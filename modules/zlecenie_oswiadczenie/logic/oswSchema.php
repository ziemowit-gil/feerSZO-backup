<?php
/**
 * modules/zlecenie_oswiadczenie/logic/oswSchema.php — tabele modułu „Oświadczenia zleceniobiorcy”.
 *
 *   zlec_osw       — wypełnienie kreatora (pakiet): odpowiedzi (JSON), umowa (0 = jeszcze bez umowy — po złożeniu powstaje
 *                    projekt umowy), link dla zleceniobiorcy (token), krok kreatora, złożenie;
 *   zlec_osw_docs  — dokumenty pakietu: 'zus' (oświadczenie dla celów podatkowych i ZUS, oryginalny wzór DOCX) i 'zlb'
 *                    (oświadczenie o numerze rachunku bankowego, symbol ZLB), każdy z własnym statusem i skanem.
 */

function oswMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('osw_migrate', __FILE__)) return;
    db()->exec("CREATE TABLE IF NOT EXISTS zlec_osw (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id    INTEGER NOT NULL DEFAULT 0,
        token          TEXT,
        token_expires  DATETIME,
        invite_name    TEXT,
        invite_email   TEXT,
        answers        TEXT NOT NULL DEFAULT '{}',
        step           INTEGER NOT NULL DEFAULT 1,
        status         TEXT NOT NULL DEFAULT 'roboczy',
        submitted_at   DATETIME,
        submitted_ip   TEXT,
        draft_created  INTEGER NOT NULL DEFAULT 0,
        is_current     INTEGER NOT NULL DEFAULT 1,
        created_by     INTEGER,
        created_at     DATETIME DEFAULT (datetime('now','localtime')),
        updated_at     DATETIME
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_zlec_osw_token ON zlec_osw(token) WHERE token IS NOT NULL");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_zlec_osw_contract ON zlec_osw(contract_id, is_current)");
    db()->exec("CREATE TABLE IF NOT EXISTS zlec_osw_docs (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        osw_id        INTEGER NOT NULL,
        kind          TEXT NOT NULL,
        numer         TEXT,
        status        TEXT NOT NULL DEFAULT 'wypelnione',
        printed_at    DATETIME,
        print_count   INTEGER NOT NULL DEFAULT 0,
        scan_path     TEXT,
        scan_name     TEXT,
        scan_at       DATETIME,
        scan_ip       TEXT,
        verified_by   INTEGER,
        verified_name TEXT,
        verified_at   DATETIME,
        note          TEXT,
        extra_doc_id  INTEGER,
        created_at    DATETIME DEFAULT (datetime('now','localtime')),
        updated_at    DATETIME,
        UNIQUE(osw_id, kind)
    )");
    // Publiczny kreator: źródło pakietu i powiązanie z kontem oświadczeń (po potwierdzeniu z e-maila)
    foreach (['source' => "TEXT NOT NULL DEFAULT 'invite'", 'account_id' => 'INTEGER', 'account_confirmed_at' => 'DATETIME',
              'account_mail_at' => 'DATETIME'] as $col => $def) {
        try { db()->exec("ALTER TABLE zlec_osw ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
    }
    // Zweryfikowany skan → pismo wewnętrzne w koszulce EZD umowy
    foreach (['ezd_sprawa_id' => 'INTEGER', 'ezd_pismo_id' => 'INTEGER', 'ezd_at' => 'DATETIME', 'ezd_error' => 'TEXT'] as $col => $def) {
        try { db()->exec("ALTER TABLE zlec_osw_docs ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
    }
    db()->exec("CREATE TABLE IF NOT EXISTS zlec_osw_accounts (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        email             TEXT NOT NULL UNIQUE,
        first_name        TEXT,
        last_name         TEXT,
        phone             TEXT,
        pesel             TEXT,
        birth_date        DATE,
        address           TEXT,
        password_hash     TEXT,
        token_hash        TEXT,
        token_purpose     TEXT,
        token_osw_id      INTEGER,
        token_expires_at  DATETIME,
        email_verified_at DATETIME,
        failed_logins     INTEGER NOT NULL DEFAULT 0,
        locked_until      DATETIME,
        last_login_at     DATETIME,
        created_at        DATETIME DEFAULT (datetime('now','localtime')),
        updated_at        DATETIME
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS zlec_osw_hits (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_hash TEXT NOT NULL,
        action  TEXT NOT NULL,
        at      DATETIME NOT NULL
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_zlec_osw_hits ON zlec_osw_hits(action, ip_hash, at)");
}
