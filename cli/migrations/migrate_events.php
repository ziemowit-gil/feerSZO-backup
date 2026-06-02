#!/usr/bin/env php
<?php
/**
 * Migracja: Moduł Wydarzeń
 * php cli/migrations/migrate_events.php
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';

function ev_exec(string $sql, string $label): void {
    try { db()->exec($sql); echo "  ✓ $label\n"; }
    catch (\Throwable $e) { echo "  · $label — " . $e->getMessage() . "\n"; }
}

echo "\n=== Migracja: Moduł Wydarzeń ===\n\n";

ev_exec("CREATE TABLE IF NOT EXISTS ev_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    slug            TEXT    NOT NULL UNIQUE,
    title           TEXT    NOT NULL,
    description     TEXT,
    type            TEXT    NOT NULL DEFAULT 'stationary', -- webinar | stationary
    status          TEXT    NOT NULL DEFAULT 'draft',      -- draft | published | cancelled | archived
    venue           TEXT,
    address         TEXT,
    meeting_url     TEXT,
    start_at        DATETIME NOT NULL,
    end_at          DATETIME,
    capacity        INTEGER,
    is_public       INTEGER NOT NULL DEFAULT 1,
    reg_open_at     DATETIME,
    reg_close_at    DATETIME,
    cover_image     TEXT,
    crm_group_id    INTEGER REFERENCES crm_groups(id) ON DELETE SET NULL,
    pa_webhook_url  TEXT,
    metadata        TEXT,
    created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      DATETIME DEFAULT (datetime('now','localtime')),
    updated_at      DATETIME DEFAULT (datetime('now','localtime'))
)", 'ev_events');

ev_exec("CREATE TABLE IF NOT EXISTS ev_registrations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id        INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
    crm_contact_id  INTEGER REFERENCES crm_contacts(id) ON DELETE SET NULL,
    first_name      TEXT    NOT NULL,
    last_name       TEXT    NOT NULL,
    email           TEXT    NOT NULL,
    phone           TEXT,
    ticket_code     TEXT    NOT NULL UNIQUE,
    status          TEXT    NOT NULL DEFAULT 'confirmed', -- confirmed | cancelled | waitlist
    checked_in_at   DATETIME,
    checked_in_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reg_data        TEXT,  -- JSON: odpowiedzi na dodatkowe pola
    source          TEXT    NOT NULL DEFAULT 'form', -- form | admin | import | api
    notes           TEXT,
    created_at      DATETIME DEFAULT (datetime('now','localtime')),
    updated_at      DATETIME DEFAULT (datetime('now','localtime'))
)", 'ev_registrations');

ev_exec("CREATE TABLE IF NOT EXISTS ev_roles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role        TEXT    NOT NULL DEFAULT 'volunteer', -- admin | volunteer | checkin
    added_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    added_at    DATETIME DEFAULT (datetime('now','localtime')),
    UNIQUE(event_id, user_id)
)", 'ev_roles');

ev_exec("CREATE TABLE IF NOT EXISTS ev_form_fields (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
    field_key   TEXT    NOT NULL,
    label       TEXT    NOT NULL,
    type        TEXT    NOT NULL DEFAULT 'text', -- text|email|tel|select|checkbox|textarea|number
    options     TEXT,   -- JSON array dla select/checkbox
    placeholder TEXT,
    is_required INTEGER NOT NULL DEFAULT 0,
    position    INTEGER NOT NULL DEFAULT 0,
    UNIQUE(event_id, field_key)
)", 'ev_form_fields');

ev_exec("CREATE TABLE IF NOT EXISTS ev_checkin_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
    token       TEXT    NOT NULL UNIQUE,
    expires_at  DATETIME,
    created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at  DATETIME DEFAULT (datetime('now','localtime'))
)", 'ev_checkin_tokens');

// Kolumny dodane po pierwszym wdrożeniu
ev_exec("ALTER TABLE ev_events ADD COLUMN rodo_clause      TEXT", 'ev_events.rodo_clause');
ev_exec("ALTER TABLE ev_events ADD COLUMN notify_new_reg   INTEGER NOT NULL DEFAULT 1", 'ev_events.notify_new_reg');
ev_exec("ALTER TABLE ev_events ADD COLUMN notify_email     TEXT", 'ev_events.notify_email');
ev_exec("ALTER TABLE ev_events ADD COLUMN crm_auto_sync    INTEGER NOT NULL DEFAULT 1", 'ev_events.crm_auto_sync');

// Indeksy
ev_exec("CREATE INDEX IF NOT EXISTS idx_ev_reg_event   ON ev_registrations(event_id)", 'idx event');
ev_exec("CREATE INDEX IF NOT EXISTS idx_ev_reg_email   ON ev_registrations(email)",    'idx email');
ev_exec("CREATE INDEX IF NOT EXISTS idx_ev_reg_ticket  ON ev_registrations(ticket_code)", 'idx ticket');
ev_exec("CREATE INDEX IF NOT EXISTS idx_ev_roles_user  ON ev_roles(user_id)",          'idx roles');
ev_exec("CREATE INDEX IF NOT EXISTS idx_ev_fields_ev   ON ev_form_fields(event_id, position)", 'idx fields');

echo "\n✓ Migracja zakończona.\n\n";
