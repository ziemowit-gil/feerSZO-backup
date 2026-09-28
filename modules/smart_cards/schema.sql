-- ============================================================================
-- schema.sql — Hologramy, Karty dostępu (NFC) i wspólny dziennik audytu
-- SQLite 3.38+ (indeksy częściowe, JSON1). Uruchom z PRAGMA foreign_keys=ON.
--
-- Plik referencyjny do ręcznej instalacji / przeglądu. W aplikacji schemat
-- zakładają i naprawiają same moduły przy pierwszym użyciu:
--   audit_logs_migrate()  modules/audit_logs/logic/audit_logs.php
--   holograms_migrate()   modules/holograms/logic/holograms.php
--   smart_cards_migrate() modules/smart_cards/logic/smart_cards.php
-- Wygenerowano z tych migracji — przy zmianie schematu odśwież ten plik.
-- ============================================================================

PRAGMA foreign_keys = ON;
BEGIN TRANSACTION;

-- ── audit_logs ──
CREATE TABLE IF NOT EXISTS audit_logs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER,
        action      TEXT NOT NULL,
        details     TEXT,
        ip_address  TEXT,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action  ON audit_logs(action);
CREATE INDEX IF NOT EXISTS idx_audit_logs_user    ON audit_logs(user_id);

-- ── holograms ──
CREATE TABLE IF NOT EXISTS holograms (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        holo_number  TEXT NOT NULL UNIQUE,
        batch_number TEXT,
        status       TEXT NOT NULL DEFAULT 'available'
                     CHECK (status IN ('available','issued','damaged','returned','lost')),
        assigned_to  TEXT,
        issued_at    DATETIME,
        issued_by    TEXT,
        notes        TEXT,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    , contract_type TEXT, contract_id INTEGER);
CREATE INDEX IF NOT EXISTS idx_holograms_status ON holograms(status);
CREATE INDEX IF NOT EXISTS idx_holograms_batch  ON holograms(batch_number);
CREATE INDEX IF NOT EXISTS idx_holograms_contract ON holograms(contract_type, contract_id);
CREATE INDEX IF NOT EXISTS idx_holograms_assigned ON holograms(assigned_to);

-- ── holograms_log ──
CREATE TABLE IF NOT EXISTS holograms_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        hologram_id  INTEGER NOT NULL REFERENCES holograms(id) ON DELETE CASCADE,
        action       TEXT NOT NULL,
        from_status  TEXT,
        to_status    TEXT,
        details      TEXT,
        user_id      INTEGER,
        user_name    TEXT NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    );
CREATE INDEX IF NOT EXISTS idx_holograms_log_h ON holograms_log(hologram_id);

-- ── card_templates ──
CREATE TABLE IF NOT EXISTS card_templates (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        design_config TEXT NOT NULL DEFAULT '{}',
        is_default    INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME
    );
CREATE UNIQUE INDEX IF NOT EXISTS uq_card_templates_default ON card_templates(is_default) WHERE is_default = 1;

-- ── access_zones ──
CREATE TABLE IF NOT EXISTS access_zones (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        zone_name      TEXT NOT NULL UNIQUE COLLATE NOCASE,
        description    TEXT,
        security_level INTEGER NOT NULL DEFAULT 1 CHECK (security_level BETWEEN 1 AND 5),
        is_active      INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    );

-- ── smart_cards ──
CREATE TABLE IF NOT EXISTS smart_cards (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        card_uid        TEXT NOT NULL UNIQUE,
        template_id     INTEGER REFERENCES card_templates(id) ON DELETE RESTRICT,
        user_id         INTEGER NOT NULL,
        status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','blocked','expired','lost')),
        expires_at      DATETIME NOT NULL,
        application_id  INTEGER,
        issued_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        issued_by       INTEGER,
        status_reason   TEXT,
        status_changed_at DATETIME,
        suspended_zones TEXT
    , uid_source TEXT NOT NULL DEFAULT 'generated', programmed_at DATETIME, programmed_by INTEGER, nfc_token_hash TEXT);
CREATE INDEX IF NOT EXISTS idx_smart_cards_user    ON smart_cards(user_id);
CREATE INDEX IF NOT EXISTS idx_smart_cards_status  ON smart_cards(status, expires_at);
CREATE INDEX IF NOT EXISTS idx_smart_cards_tpl     ON smart_cards(template_id);
CREATE INDEX IF NOT EXISTS idx_smart_cards_token ON smart_cards(nfc_token_hash);

-- ── card_applications ──
CREATE TABLE IF NOT EXISTS card_applications (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id       INTEGER NOT NULL,
        reason        TEXT NOT NULL,
        status        TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by    INTEGER,
        decided_by    INTEGER,
        decided_at    DATETIME,
        decision_note TEXT,
        card_id       INTEGER REFERENCES smart_cards(id) ON DELETE SET NULL
    );
CREATE INDEX IF NOT EXISTS idx_card_apps_status ON card_applications(status, created_at);
CREATE INDEX IF NOT EXISTS idx_card_apps_user   ON card_applications(user_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_card_apps_pending ON card_applications(user_id) WHERE status = 'pending';

-- ── card_zone_permissions ──
CREATE TABLE IF NOT EXISTS card_zone_permissions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        card_id     INTEGER REFERENCES smart_cards(id) ON DELETE CASCADE,
        template_id INTEGER REFERENCES card_templates(id) ON DELETE CASCADE,
        zone_id     INTEGER NOT NULL REFERENCES access_zones(id) ON DELETE CASCADE,
        granted_by  INTEGER,
        granted_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        CHECK ((card_id IS NULL) <> (template_id IS NULL))
    );
CREATE UNIQUE INDEX IF NOT EXISTS uq_czp_card ON card_zone_permissions(card_id, zone_id) WHERE card_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_czp_tpl  ON card_zone_permissions(template_id, zone_id) WHERE template_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_czp_zone ON card_zone_permissions(zone_id);

-- Wzór domyślny — bez niego nie da się zatwierdzić pierwszego wniosku
INSERT INTO card_templates (name, design_config, is_default)
SELECT 'Standardowa', '{"bg_from":"#1e3a8a","bg_to":"#0f172a","angle":135,"text":"#ffffff","accent":"#38bdf8","chip":"gold","pattern":"waves","label":"KARTA DOSTĘPU"}', 1
WHERE NOT EXISTS (SELECT 1 FROM card_templates);

COMMIT;
