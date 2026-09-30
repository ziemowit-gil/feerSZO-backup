-- modules/ti_pricing/schema.sql — Moduł elastycznych cenników i rabatów zależnych od typu zajęć (TI).
-- Wykonywany przez ti_pricing_migrate() (idempotentnie: IF NOT EXISTS).
--
-- Integracja z TI: cena z silnika to STAWKA GODZINOWA (zł/h) zapisu kursanta
-- (k30_ti_enrollments.hourly_rate / hourly_rate_online). Grupa wskazuje typ zajęć
-- (ti_pricing_course_types: typ stacjonarny + opcjonalnie typ dla lekcji online).
-- Rozliczenia, faktury i historia pobrań liczą się dalej ze stawek zapisu — cennik
-- je ustala („Zastosuj do zapisu”), nie zastępuje księgi rozliczeń.
-- participant_id = k30_clients.id.

CREATE TABLE IF NOT EXISTS lesson_types (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL,
    slug         TEXT    NOT NULL UNIQUE,
    base_price   REAL    NOT NULL DEFAULT 0 CHECK (base_price >= 0),   -- zł za godzinę lekcji
    mode         TEXT    NOT NULL DEFAULT 'dowolna',                   -- stacjonarna | online | dowolna (informacyjnie)
    description  TEXT,
    is_active    INTEGER NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_lesson_types_active ON lesson_types(is_active);

CREATE TABLE IF NOT EXISTS discount_rules (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    name                   TEXT    NOT NULL,
    discount_type          TEXT    NOT NULL CHECK (discount_type IN ('percentage','fixed')),
    discount_value         REAL    NOT NULL CHECK (discount_value >= 0),
    target_lesson_type_id  INTEGER REFERENCES lesson_types(id) ON DELETE CASCADE,   -- NULL = wszystkie typy
    condition_type         TEXT    NOT NULL DEFAULT 'none'
                           CHECK (condition_type IN ('none','participant_status','early_bird','bundle_quantity','virtual_account_overpayment')),
    condition_value        TEXT,        -- statusy po przecinku | dni przed startem | min. liczba grup | min. nadpłata zł
    date_from              DATETIME,
    date_to                DATETIME,
    priority               INTEGER NOT NULL DEFAULT 100,   -- rosnąco: niższa liczba = wcześniej
    stackable              INTEGER NOT NULL DEFAULT 1,     -- 0 = nie łączy się z innymi (stosowana sama albo wcale)
    is_active              INTEGER NOT NULL DEFAULT 1,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_discount_rules_active ON discount_rules(is_active, priority);
CREATE INDEX IF NOT EXISTS idx_discount_rules_type   ON discount_rules(target_lesson_type_id);

CREATE TABLE IF NOT EXISTS calculated_prices_log (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id          INTEGER,
    lesson_type_id          INTEGER,
    course_id               INTEGER,
    mode                    TEXT    NOT NULL DEFAULT 'stacjonarna',   -- stacjonarna | online (która stawka zapisu)
    base_price              REAL    NOT NULL DEFAULT 0,
    applied_discounts_json  TEXT    NOT NULL DEFAULT '[]',           -- kolejne kroki: reguła, przed, po, powód
    context_json            TEXT    NOT NULL DEFAULT '{}',           -- profil: statusy, liczba grup, nadpłata, data startu
    final_price             REAL    NOT NULL DEFAULT 0,
    source                  TEXT    NOT NULL DEFAULT 'applied',      -- applied | override
    note                    TEXT    NOT NULL DEFAULT '',
    created_by              TEXT    NOT NULL DEFAULT '',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_calc_prices_participant ON calculated_prices_log(participant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_calc_prices_course      ON calculated_prices_log(course_id);

-- Typ zajęć grupy TI (stacjonarny + opcjonalny dla lekcji online)
CREATE TABLE IF NOT EXISTS ti_pricing_course_types (
    course_id              INTEGER PRIMARY KEY REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
    lesson_type_id         INTEGER REFERENCES lesson_types(id) ON DELETE SET NULL,
    online_lesson_type_id  INTEGER REFERENCES lesson_types(id) ON DELETE SET NULL,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Profil cenowy uczestnika (status: student, ngo, wolontariusz…; „kontynuacja” wyliczana automatycznie)
CREATE TABLE IF NOT EXISTS ti_pricing_participant_status (
    participant_id  INTEGER PRIMARY KEY REFERENCES k30_clients(id) ON DELETE CASCADE,
    statuses        TEXT    NOT NULL DEFAULT '',   -- po przecinku
    updated_by      TEXT    NOT NULL DEFAULT '',
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Dziennik audytu — wspólny (modules/audit_logs); akcje pricing.*
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    action      TEXT NOT NULL,
    details     TEXT,
    ip_address  TEXT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
