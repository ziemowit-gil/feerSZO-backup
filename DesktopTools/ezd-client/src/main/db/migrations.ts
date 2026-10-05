/** Kolejne migracje — indeks + 1 = PRAGMA user_version. Nigdy nie edytuj wykonanych. */
export const MIGRATIONS: string[] = [
  `
  CREATE TABLE customer (
    id TEXT PRIMARY KEY, kind TEXT NOT NULL CHECK(kind IN ('b2b','b2c')),
    name TEXT NOT NULL, nip TEXT, email TEXT, phone TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')));
  CREATE TABLE document (
    id TEXT PRIMARY KEY,
    kind TEXT NOT NULL CHECK(kind IN ('incoming','outgoing','internal')),
    reg_no TEXT UNIQUE, title TEXT NOT NULL,
    stage TEXT NOT NULL DEFAULT 'registered',
    jrwa TEXT, customer_id TEXT REFERENCES customer(id),
    version INTEGER NOT NULL DEFAULT 1, ocr_text TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')));
  CREATE TABLE document_version (
    document_id TEXT NOT NULL REFERENCES document(id), version INTEGER NOT NULL,
    sha256 TEXT NOT NULL, file_name TEXT NOT NULL, stored_path TEXT NOT NULL,
    at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY(document_id, version));
  CREATE TABLE reg_counter (year INTEGER NOT NULL, kind TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY(year, kind));
  CREATE TABLE audit (
    id TEXT PRIMARY KEY, entity TEXT NOT NULL, entity_id TEXT NOT NULL,
    actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT,
    at TEXT NOT NULL DEFAULT (datetime('now')));
  CREATE INDEX audit_entity ON audit(entity_id, at);
  CREATE TABLE deal (
    id TEXT PRIMARY KEY, customer_id TEXT NOT NULL REFERENCES customer(id),
    title TEXT NOT NULL, stage TEXT NOT NULL DEFAULT 'lead',
    value_pln INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT (datetime('now')));
  CREATE TABLE note (
    id TEXT PRIMARY KEY, customer_id TEXT NOT NULL REFERENCES customer(id),
    body TEXT NOT NULL, at TEXT NOT NULL DEFAULT (datetime('now')));
  CREATE TABLE mail_message (
    id TEXT PRIMARY KEY, customer_id TEXT REFERENCES customer(id), uid INTEGER,
    from_addr TEXT, subject TEXT, at TEXT);
  CREATE TABLE notification (
    id TEXT PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL, link TEXT,
    read INTEGER NOT NULL DEFAULT 0, at TEXT NOT NULL DEFAULT (datetime('now')));
  CREATE TABLE outbox (
    id TEXT PRIMARY KEY, op TEXT NOT NULL, payload TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')), attempts INTEGER NOT NULL DEFAULT 0);
  CREATE TABLE setting (key TEXT PRIMARY KEY, value TEXT NOT NULL);
  `,
  `
  CREATE VIRTUAL TABLE document_fts USING fts5(title, ocr_text, content='document', content_rowid='rowid', tokenize='unicode61 remove_diacritics 2');
  CREATE TRIGGER document_ai AFTER INSERT ON document BEGIN
    INSERT INTO document_fts(rowid, title, ocr_text) VALUES (new.rowid, new.title, coalesce(new.ocr_text,''));
  END;
  CREATE TRIGGER document_au AFTER UPDATE ON document BEGIN
    INSERT INTO document_fts(document_fts, rowid, title, ocr_text) VALUES ('delete', old.rowid, old.title, coalesce(old.ocr_text,''));
    INSERT INTO document_fts(rowid, title, ocr_text) VALUES (new.rowid, new.title, coalesce(new.ocr_text,''));
  END;
  `,
  `
  CREATE TABLE scan (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, file_path TEXT NOT NULL,
    pages INTEGER NOT NULL DEFAULT 1, size INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','uploading','uploaded','error')),
    error TEXT, server_ref TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')), uploaded_at TEXT);
  `,
  `
  ALTER TABLE scan ADD COLUMN target TEXT;
  CREATE TABLE remote_map (kind TEXT NOT NULL, local_id TEXT NOT NULL, remote_id TEXT NOT NULL, PRIMARY KEY(kind, local_id));
  DELETE FROM outbox WHERE op NOT IN ('crm.customer.upsert','crm.note');
  `
];
