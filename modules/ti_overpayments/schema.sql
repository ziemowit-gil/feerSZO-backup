-- modules/ti_overpayments/schema.sql — podmoduł obsługi nadpłat z kont wirtualnych
-- uczestników TI. Wykonywany przez ti_op_migrate() (idempotentnie: IF NOT EXISTS).
--
-- Konto wirtualne uczestnika = istniejąca księga: k30_ti_payments (wpłaty) +
-- k30_ti_billing (należności, z FVAT). Saldo i nadpłata są z niej WYLICZANE
-- (ti_client_allocation). Poniższe tabele NIE są drugim źródłem prawdy:
--   • virtual_account_balances — migawka salda po każdej operacji (raporty, lista);
--   • overpayment_transactions — rejestr nadpłat i ich rozliczeń (ścieżka audytu);
--     każda dyspozycja (zwrot / zaliczenie / przeksięgowanie) ma odpowiadający
--     wpis w k30_ti_payments zapisany w TEJ SAMEJ transakcji (ledger_payment_ids).
-- Kwoty: REAL zaokrąglane do 2 miejsc; arytmetyka w PHP na groszach (int).
-- participant_id = k30_clients.id; fvat_id / settled_against_fvat_id = invoices.id
-- (faktura produkcyjna), a billing_id / settled_against_billing_id = k30_ti_billing.id
-- (rozliczenie, z którego FVAT powstała — także gdy faktura jest tylko skanem/numerem).

CREATE TABLE IF NOT EXISTS virtual_account_balances (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id      INTEGER NOT NULL UNIQUE REFERENCES k30_clients(id) ON DELETE CASCADE,
    balance             REAL    NOT NULL DEFAULT 0,     -- wpłaty − należności (ujemne = zaległość)
    overpayment_amount  REAL    NOT NULL DEFAULT 0,     -- nadpłata wg księgi (grupowa + ogólna)
    registered_amount   REAL    NOT NULL DEFAULT 0,     -- nadpłata zarejestrowana, status available
    debt_amount         REAL    NOT NULL DEFAULT 0,     -- niedopłata wg księgi
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS overpayment_transactions (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id              INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
    course_id                   INTEGER NOT NULL DEFAULT 0,   -- „koszyk” nadpłaty: grupa (>0) albo konto ogólne (0)
    fvat_id                     INTEGER,                      -- faktura źródłowa (invoices.id), jeśli dotyczy
    billing_id                  INTEGER,                      -- rozliczenie źródłowe (k30_ti_billing.id)
    amount                      REAL    NOT NULL,             -- kwota tego wpisu (> 0)
    source_type                 TEXT    NOT NULL DEFAULT 'overpayment_from_fvat', -- overpayment_from_fvat | manual_adjustment
    status                      TEXT    NOT NULL DEFAULT 'available',  -- available | refunded | settled | transferred
    parent_id                   INTEGER REFERENCES overpayment_transactions(id) ON DELETE SET NULL, -- wpis, z którego wydzielono część
    settled_against_fvat_id     INTEGER,                      -- FVAT, na którą zaliczono (invoices.id)
    settled_against_billing_id  INTEGER,                      -- rozliczenie, na które zaliczono
    target_course_id            INTEGER,                      -- zaliczenie na poczet przyszłych należności grupy
    target_participant_id       INTEGER,                      -- przeksięgowanie na konto innego uczestnika
    refund_account              TEXT    NOT NULL DEFAULT '',  -- rachunek do zwrotu
    refund_title                TEXT    NOT NULL DEFAULT '',  -- tytuł przelewu zwrotnego
    ledger_payment_ids          TEXT    NOT NULL DEFAULT '',  -- JSON: id wpisów w k30_ti_payments tej dyspozycji
    notes                       TEXT,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by                  TEXT    NOT NULL DEFAULT '',
    disposed_at                 DATETIME,                     -- kiedy rozliczono (status ≠ available)
    disposed_by                 TEXT    NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_op_tx_participant ON overpayment_transactions(participant_id, status);
CREATE INDEX IF NOT EXISTS idx_op_tx_status      ON overpayment_transactions(status);
CREATE INDEX IF NOT EXISTS idx_op_tx_parent      ON overpayment_transactions(parent_id);

-- Dziennik audytu — wspólny dla modułów (modules/audit_logs), tu dla kompletności.
-- Akcje: overpayments.detected / .registered / .refunded / .settled / .transferred / .auto_settled
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    action      TEXT NOT NULL,
    details     TEXT,
    ip_address  TEXT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
