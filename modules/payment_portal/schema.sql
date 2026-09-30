-- modules/payment_portal/schema.sql — portal płatności SZO pod adresem /platnosci.
-- Wykonywany przez pp_migrate() (idempotentnie: IF NOT EXISTS).
--
-- participant_id = k30_clients.id (uczestnik/klient SZO). Płatność Przelewy24 idzie
-- przez istniejącą integrację includes/p24.php (p24_payments, api/p24_webhook.php,
-- obowiązkowe transaction/verify) — source_type 'payment_portal', source_id = id
-- transakcji portalu. Kwoty REAL z 2 miejscami, liczone na groszach; kwota koszyka
-- jest zawsze liczona na serwerze z bazy (nigdy z formularza).

CREATE TABLE IF NOT EXISTS payment_portal_users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id  INTEGER NOT NULL UNIQUE REFERENCES k30_clients(id) ON DELETE CASCADE,
    individual_nrb  TEXT UNIQUE,                    -- 26 cyfr (bez PL), suma kontrolna IBAN
    email           TEXT UNIQUE,
    access_hash     TEXT UNIQUE,                    -- SHA-256 tokenu z linku (sam token nie jest przechowywany)
    token_created_at DATETIME,
    is_active       INTEGER NOT NULL DEFAULT 1,
    last_login_at   DATETIME,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payable_items (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id  INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
    title           TEXT    NOT NULL,
    reference_type  TEXT    NOT NULL DEFAULT 'manual'
                    CHECK (reference_type IN ('invoice','card_application','workshop','manual','ti_billing')),
    reference_id    INTEGER,
    amount          REAL    NOT NULL CHECK (amount > 0),
    status          TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','processing','paid','cancelled')),
    due_date        DATE,
    paid_at         DATETIME,
    created_by      TEXT    NOT NULL DEFAULT '',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_payable_items_participant ON payable_items(participant_id, status);
CREATE INDEX IF NOT EXISTS idx_payable_items_ref         ON payable_items(reference_type, reference_id);
-- jedna otwarta pozycja na to samo źródło (np. rozliczenie TI, faktura)
CREATE UNIQUE INDEX IF NOT EXISTS uq_payable_items_open_ref ON payable_items(reference_type, reference_id)
    WHERE reference_id IS NOT NULL AND status IN ('pending','processing');

CREATE TABLE IF NOT EXISTS portal_transactions (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id    INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
    transaction_uuid  TEXT    NOT NULL UNIQUE,
    total_amount      REAL    NOT NULL CHECK (total_amount > 0),
    payment_method    TEXT    NOT NULL CHECK (payment_method IN ('individual_nrb','p24')),
    status            TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('success','pending','failed')),
    transfer_title    TEXT    NOT NULL DEFAULT '',   -- tytuł przelewu (NRB) / opis (P24)
    p24_payment_id    INTEGER,                       -- p24_payments.id
    gateway_response  TEXT,                          -- JSON: dane P24 / potwierdzenie NRB
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at      DATETIME
);
CREATE INDEX IF NOT EXISTS idx_portal_tx_participant ON portal_transactions(participant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_portal_tx_status      ON portal_transactions(status, payment_method);

CREATE TABLE IF NOT EXISTS portal_transaction_items (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    portal_transaction_id  INTEGER NOT NULL REFERENCES portal_transactions(id) ON DELETE CASCADE,
    payable_item_id        INTEGER NOT NULL REFERENCES payable_items(id) ON DELETE CASCADE,
    amount                 REAL    NOT NULL CHECK (amount > 0)
);
CREATE INDEX IF NOT EXISTS idx_portal_tx_items_tx   ON portal_transaction_items(portal_transaction_id);
CREATE INDEX IF NOT EXISTS idx_portal_tx_items_item ON portal_transaction_items(payable_item_id);

-- Dziennik audytu — wspólny (modules/audit_logs); akcje payments.*
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    action      TEXT NOT NULL,
    details     TEXT,
    ip_address  TEXT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
