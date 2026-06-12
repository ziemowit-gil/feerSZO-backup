<?php
/**
 * includes/crm.php — CRM Module Core
 *
 * Zawiera:
 *  - crm_migrate()       — idempotentna migracja tabel (lazy, $done guard)
 *  - class CrmManager    — statyczne metody biznesowe (SRP)
 *  - class SyncService   — delta-sync: System Główny → CRM
 *
 * Wymaga: db.php, auth.php, functions.php
 */

// ── Stałe relacji ──────────────────────────────────────────────────────────────
const CRM_RELATION_TYPES = [
    'powiązany'    => 'Powiązany',
    'przełożony'   => 'Przełożony',
    'podwładny'    => 'Podwładny',
    'partner'      => 'Partner biznesowy',
    'sponsor'      => 'Sponsor / Darczyńca',
    'beneficjent'  => 'Beneficjent',
    'współpracuje' => 'Współpracuje',
];

/** Wbudowane domyślne statusy — używane jako seed i fallback. */
const CRM_DEFAULT_STATUSES = [
    'prospect'   => ['label' => 'Prospect',   'color' => '#0176D3', 'sort_order' => 1],
    'aktywny'    => ['label' => 'Aktywny',    'color' => '#2E844A', 'sort_order' => 2],
    'partner'    => ['label' => 'Partner',    'color' => '#7F2B8B', 'sort_order' => 3],
    'darczyńca'  => ['label' => 'Darczyńca',  'color' => '#FE9339', 'sort_order' => 4],
    'klient'     => ['label' => 'Klient',     'color' => '#032D60', 'sort_order' => 5],
    'nieaktywny' => ['label' => 'Nieaktywny', 'color' => '#939393', 'sort_order' => 6],
];

/**
 * Zwraca aktywne statusy CRM z bazy (lub wbudowane jeśli tabela pusta).
 * Zamiennik dla stałej CRM_STATUSES.
 */
function crm_statuses(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = crm_db()->query(
            "SELECT slug, label, color FROM crm_statuses WHERE is_active=1 ORDER BY sort_order, id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows) {
            $cache = [];
            foreach ($rows as $r) {
                $cache[$r['slug']] = ['label' => $r['label'], 'color' => $r['color']];
            }
            return $cache;
        }
    } catch (\Throwable $e) {}
    // Fallback do wbudowanych
    $cache = array_map(fn($v) => ['label'=>$v['label'],'color'=>$v['color']], CRM_DEFAULT_STATUSES);
    return $cache;
}

const CRM_CHANNELS = [
    'email'   => ['label' => 'E-mail',    'icon' => 'bi-envelope-fill'],
    'sms'     => ['label' => 'SMS',       'icon' => 'bi-phone-fill'],
    'telefon' => ['label' => 'Telefon',   'icon' => 'bi-telephone-fill'],
    'osobisty'=> ['label' => 'Osobisty',  'icon' => 'bi-person-fill'],
];

// ─────────────────────────────────────────────────────────────────────────────
// OSOBNA BAZA CRM — opcjonalna konfiguracja
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Zwraca połączenie PDO dla tabel CRM.
 * Domyślnie = main db(). Gdy skonfigurowano osobną bazę w settings, zwraca osobny singleton.
 */
function crm_db(): \PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $type = crm_setting('crm_db_type');
        if (!$type || $type === 'main') {
            $pdo = db();
            return $pdo;
        }
        if ($type === 'sqlite') {
            $path = crm_setting('crm_db_path');
            if (!$path) { $pdo = db(); return $pdo; }
            // Ścieżka względna → względem katalogu głównego
            if (!str_starts_with($path, '/')) $path = dirname(__DIR__) . '/' . $path;
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
            return $pdo;
        }
        if ($type === 'mysql') {
            $host   = crm_setting('crm_db_host') ?: '127.0.0.1';
            $port   = crm_setting('crm_db_port') ?: '3306';
            $name   = crm_setting('crm_db_name');
            $user   = crm_setting('crm_db_user');
            $pass   = crm_setting('crm_db_pass');
            $dsn    = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return $pdo;
        }
    } catch (\Throwable $e) {
        error_log('[crm_db] Błąd połączenia z bazą CRM: ' . $e->getMessage() . ' — fallback na główną bazę');
    }

    $pdo = db();
    return $pdo;
}

/** Odczytaj ustawienie CRM z tabeli settings w GŁÓWNEJ bazie. */
function crm_setting(string $key): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    } catch (\Throwable $e) {
        $cache[$key] = '';
    }
    return $cache[$key];
}

/** Zapisz ustawienie CRM w GŁÓWNEJ bazie. */
function crm_setting_save(string $key, string $value): void {
    if (DB_TYPE === 'sqlite') {
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?,?)")->execute([$key, $value]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")->execute([$key,$value,$value]);
    }
}

/** Sprawdź połączenie z bazą CRM i zwróć diagnostykę. */
function crm_db_status(): array {
    try {
        $pdo   = crm_db();
        $type  = crm_setting('crm_db_type') ?: 'main';
        $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'crm_%'")->fetchAll() ?: [], 'name');
        // MySQL fallback
        if (empty($tables)) {
            try {
                $tables = array_column($pdo->query("SHOW TABLES LIKE 'crm_%'")->fetchAll(\PDO::FETCH_NUM) ?: [], 0);
            } catch (\Throwable $e) {}
        }
        return ['ok' => true, 'type' => $type, 'tables' => count($tables), 'msg' => 'Połączono'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'type' => 'error', 'tables' => 0, 'msg' => $e->getMessage()];
    }
}

// Wrappers CRM DB — używaj zamiast db_one/db_all gdy operujesz na tabelach crm_*
function crm_one(string $sql, array $p = []): ?array {
    $st = crm_db()->prepare($sql); $st->execute($p);
    $r  = $st->fetch(); return $r ?: null;
}
function crm_all(string $sql, array $p = []): array {
    $st = crm_db()->prepare($sql); $st->execute($p);
    return $st->fetchAll() ?: [];
}
function crm_insert(string $table, array $data): int {
    $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
    $vals = implode(',', array_fill(0, count($data), '?'));
    crm_db()->prepare("INSERT INTO `$table` ($cols) VALUES ($vals)")->execute(array_values($data));
    return (int)crm_db()->lastInsertId();
}
function crm_update(string $table, array $data, int $id): void {
    $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
    crm_db()->prepare("UPDATE `$table` SET $set WHERE id=?")->execute([...array_values($data), $id]);
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRACJA — uruchamiana raz na żądanie HTTP
// ─────────────────────────────────────────────────────────────────────────────

function crm_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = crm_db();

    // Kontakty CRM
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contacts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        type         TEXT    NOT NULL DEFAULT 'osoba',
        status       TEXT    NOT NULL DEFAULT 'prospect',
        imie_nazwisko TEXT   NOT NULL,
        email        TEXT,
        telefon      TEXT,
        adres        TEXT,
        nip          TEXT,
        krs          TEXT,
        stanowisko   TEXT,
        organizacja  TEXT,
        notatka      TEXT,
        avatar_initials TEXT,
        person_id    INTEGER REFERENCES persons(id) ON DELETE SET NULL,
        source       TEXT    NOT NULL DEFAULT 'manual',
        synced_at    DATETIME,
        crm_active   INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_contacts_email  ON crm_contacts(email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_contacts_status ON crm_contacts(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_contacts_upd    ON crm_contacts(updated_at)");

    // Dodatkowe kolumny (dla istniejących baz — idempotentne ALTER TABLE)
    $extra_cols = [
        "ALTER TABLE crm_contacts ADD COLUMN imie            TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN nazwisko        TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN pesel           TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN data_urodzenia  DATE",
        "ALTER TABLE crm_contacts ADD COLUMN regon           TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN branza          TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN strona_www      TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN osoba_kontaktowa TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN forma_prawna    TEXT",
        // Terytorium
        "ALTER TABLE crm_contacts ADD COLUMN wojewodztwo     TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN powiat          TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN gmina           TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN teryt_kod       TEXT",
    ];
    foreach ($extra_cols as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }

    // Tagi
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_tags (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id  INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        tag         TEXT    NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(contact_id, tag)
    )");

    // Notatki
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_notes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id  INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        body        TEXT    NOT NULL,
        is_pinned   INTEGER NOT NULL DEFAULT 0,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Relacje (wiele-do-wielu; kierunek: a → b; symetria wg potrzeby)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_relations (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_a_id  INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        contact_b_id  INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        relation_type TEXT    NOT NULL DEFAULT 'powiązany',
        notes         TEXT,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(contact_a_id, contact_b_id, relation_type),
        CHECK(contact_a_id != contact_b_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_rel_a ON crm_relations(contact_a_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_rel_b ON crm_relations(contact_b_id)");

    // Historia komunikacji
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_communications (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id    INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        channel       TEXT    NOT NULL DEFAULT 'email',
        direction     TEXT    NOT NULL DEFAULT 'out',
        template_name TEXT,
        subject       TEXT,
        body          TEXT    NOT NULL,
        status        TEXT    NOT NULL DEFAULT 'wysłana',
        sent_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        sent_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_contact ON crm_communications(contact_id)");

    // Szablony wiadomości
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_templates (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL UNIQUE,
        channel     TEXT    NOT NULL DEFAULT 'email',
        subject     TEXT,
        body        TEXT    NOT NULL,
        variables   TEXT,
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Log synchronizacji
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_sync_log (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        synced_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        records_updated INTEGER NOT NULL DEFAULT 0,
        source          TEXT DEFAULT 'heartbeat',
        ip              TEXT,
        details         TEXT
    )");

    // Powiązanie kontaktów CRM z działaniami systemu głównego
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_action_links (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        action_id  INTEGER NOT NULL REFERENCES actions(id)      ON DELETE CASCADE,
        contact_id INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        rola       TEXT NOT NULL DEFAULT 'uczestnik',
        nota       TEXT,
        added_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(action_id, contact_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_al_action  ON crm_action_links(action_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_al_contact ON crm_action_links(contact_id)");

    // Grupy kontaktów
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_groups (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL UNIQUE,
        description TEXT,
        color       TEXT    NOT NULL DEFAULT '#2E844A',
        icon        TEXT    NOT NULL DEFAULT 'bi-collection-fill',
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Przynależność do grup (wiele-do-wielu)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_group_members (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id   INTEGER NOT NULL REFERENCES crm_groups(id)   ON DELETE CASCADE,
        contact_id INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        added_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(group_id, contact_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_grp_m_grp ON crm_group_members(group_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_grp_m_con ON crm_group_members(contact_id)");

    // Podgrupy i połączenia grup
    foreach ([
        "ALTER TABLE crm_groups ADD COLUMN parent_id INTEGER REFERENCES crm_groups(id) ON DELETE SET NULL",
        "ALTER TABLE crm_groups ADD COLUMN auto_source TEXT DEFAULT NULL",
        "ALTER TABLE crm_groups ADD COLUMN sort_order INTEGER DEFAULT 0",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }

    // Dostęp do grupy per użytkownik
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_group_users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id   INTEGER NOT NULL REFERENCES crm_groups(id) ON DELETE CASCADE,
        user_id    INTEGER NOT NULL REFERENCES users(id)      ON DELETE CASCADE,
        can_write  INTEGER NOT NULL DEFAULT 0,
        can_delete INTEGER NOT NULL DEFAULT 0,
        granted_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(group_id, user_id)
    )");

    // Tagi grup
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_group_tags (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL REFERENCES crm_groups(id) ON DELETE CASCADE,
        tag      TEXT    NOT NULL,
        UNIQUE(group_id, tag)
    )");

    // Połączenia grup (dziedziczenie członków)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_group_links (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_group_id INTEGER NOT NULL REFERENCES crm_groups(id) ON DELETE CASCADE,
        child_group_id  INTEGER NOT NULL REFERENCES crm_groups(id) ON DELETE CASCADE,
        UNIQUE(parent_group_id, child_group_id),
        CHECK(parent_group_id != child_group_id)
    )");

    // Log wysyłek masowych
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_mass_sends (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id     INTEGER REFERENCES crm_groups(id) ON DELETE SET NULL,
        channel      TEXT    NOT NULL DEFAULT 'email',
        subject      TEXT,
        body         TEXT    NOT NULL,
        template_name TEXT,
        recipients   INTEGER NOT NULL DEFAULT 0,
        sent_ok      INTEGER NOT NULL DEFAULT 0,
        sent_fail    INTEGER NOT NULL DEFAULT 0,
        status       TEXT    NOT NULL DEFAULT 'pending',
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        finished_at  DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ms_group ON crm_mass_sends(group_id)");

    // Sprawy CRM
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_cases (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id  INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        title       TEXT    NOT NULL,
        description TEXT,
        status      TEXT    NOT NULL DEFAULT 'open',
        priority    TEXT    NOT NULL DEFAULT 'medium',
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        closed_at   DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_cases_contact ON crm_cases(contact_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_cases_status  ON crm_cases(status)");

    // Notatki do spraw
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_case_notes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        case_id     INTEGER NOT NULL REFERENCES crm_cases(id) ON DELETE CASCADE,
        body        TEXT    NOT NULL,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_case_notes_case ON crm_case_notes(case_id)");

    // Pliki do spraw
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_case_files (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        case_id       INTEGER NOT NULL REFERENCES crm_cases(id) ON DELETE CASCADE,
        original_name TEXT    NOT NULL,
        stored_path   TEXT    NOT NULL,
        display_name  TEXT,
        description   TEXT,
        file_size     INTEGER,
        mime_type     TEXT,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_case_files_case ON crm_case_files(case_id)");

    // Planowane działania na kontakcie
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_activities (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id   INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        type         TEXT    NOT NULL DEFAULT 'task',
        title        TEXT    NOT NULL,
        description  TEXT,
        scheduled_at DATETIME,
        duration_min INTEGER,
        status       TEXT    NOT NULL DEFAULT 'planned',
        outcome      TEXT,
        assigned_to  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_act_contact ON crm_activities(contact_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_act_status  ON crm_activities(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_act_sched   ON crm_activities(scheduled_at)");

    // Zdarzenia kalendarza CRM
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_events (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id   INTEGER REFERENCES crm_contacts(id) ON DELETE SET NULL,
        title        TEXT    NOT NULL,
        description  TEXT,
        event_date   DATE    NOT NULL,
        event_time   TIME,
        event_end_date DATE,
        event_end_time TIME,
        all_day      INTEGER NOT NULL DEFAULT 1,
        event_type   TEXT    NOT NULL DEFAULT 'task',
        color        TEXT    NOT NULL DEFAULT '#2E844A',
        status       TEXT    NOT NULL DEFAULT 'pending',
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_events_date    ON crm_events(event_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_events_contact ON crm_events(contact_id)");

    // ── Dodatkowe pola kontaktów ───────────────────────────────────────────────
    // Statusy CRM (edytowalne przez admina)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_statuses (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        slug        TEXT    NOT NULL UNIQUE,
        label       TEXT    NOT NULL,
        color       TEXT    NOT NULL DEFAULT '#6B7280',
        sort_order  INTEGER NOT NULL DEFAULT 0,
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Seed domyślnych statusów (tylko raz)
    $has_statuses = (int)($pdo->query("SELECT COUNT(*) FROM crm_statuses")->fetchColumn() ?? 0);
    if (!$has_statuses) {
        $ins_st = $pdo->prepare("INSERT INTO crm_statuses (slug,label,color,sort_order) VALUES (?,?,?,?)");
        foreach (CRM_DEFAULT_STATUSES as $slug => $s) {
            try { $ins_st->execute([$slug, $s['label'], $s['color'], $s['sort_order']]); } catch (\Throwable $e) {}
        }
    }

    // Grupy pól (sekcje formularza kontaktu)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_field_groups (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        label       TEXT    NOT NULL,
        icon        TEXT    NOT NULL DEFAULT 'bi-card-list',
        applies_to  TEXT    NOT NULL DEFAULT 'both',
        sort_order  INTEGER NOT NULL DEFAULT 0,
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Pola kontaktów (custom)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_field_defs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id    INTEGER REFERENCES crm_field_groups(id) ON DELETE SET NULL,
        label       TEXT    NOT NULL,
        field_type  TEXT    NOT NULL DEFAULT 'text',
        options     TEXT    NOT NULL DEFAULT '',
        applies_to  TEXT    NOT NULL DEFAULT 'both',
        sort_order  INTEGER NOT NULL DEFAULT 0,
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Dodaj group_id do starych tabel (idempotentne)
    try { $pdo->exec("ALTER TABLE crm_contact_field_defs ADD COLUMN group_id INTEGER REFERENCES crm_field_groups(id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_field_values (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id   INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        field_def_id INTEGER NOT NULL REFERENCES crm_contact_field_defs(id) ON DELETE CASCADE,
        value        TEXT    NOT NULL DEFAULT '',
        UNIQUE(contact_id, field_def_id)
    )");

    // Ustawienia modułu w tabeli settings (idempotentne)
    $defaults = [
        'crm_enabled'    => '1',
        'crm_sync_token' => bin2hex(random_bytes(24)),
    ];
    foreach ($defaults as $key => $val) {
        try {
            $exists = db_one("SELECT id FROM settings WHERE key_=?", [$key]);
            if (!$exists) {
                $pdo->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")
                    ->execute([$key, $val]);
            }
        } catch (\Throwable $e) {}
    }

    // Uprawnienia dla roli 'crm' w systemie RBAC (addytywne)
    try {
        $admin  = db_one("SELECT id FROM roles WHERE name='admin'");
        $editor = db_one("SELECT id FROM roles WHERE name='editor'");
        $viewer = db_one("SELECT id FROM roles WHERE name='viewer'");
        $ins = $pdo->prepare(
            "INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete)
             VALUES (?,?,?,?,?)"
        );
        if ($admin)  $ins->execute([$admin['id'],  'crm', 1, 1, 1]);
        if ($editor) $ins->execute([$editor['id'], 'crm', 1, 1, 0]);
        if ($viewer) $ins->execute([$viewer['id'], 'crm', 1, 0, 0]);
    } catch (\Throwable $e) {}

    // Kolumna powiązania z EZD (idempotentna)
    try {
        $pdo->exec("ALTER TABLE crm_cases ADD COLUMN ezd_sprawa_id INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL");
    } catch (\Throwable $e) {}

    // Rozszerzone pola pisma (idempotentne)
    foreach ([
        "ALTER TABLE contract_letters ADD COLUMN sygnatura TEXT",
        "ALTER TABLE contract_letters ADD COLUMN miejsce TEXT",
        "ALTER TABLE contract_letters ADD COLUMN sposob_doreczenia TEXT DEFAULT 'email'",
        "ALTER TABLE contract_letters ADD COLUMN pilnosc TEXT DEFAULT 'zwykłe'",
        "ALTER TABLE contract_letters ADD COLUMN termin_odpowiedzi TEXT",
        "ALTER TABLE contract_letters ADD COLUMN kopia_do TEXT",
        "ALTER TABLE contract_letters ADD COLUMN podpisujacy_id INTEGER REFERENCES users(id) ON DELETE SET NULL",
        "ALTER TABLE contract_letters ADD COLUMN podstawa_prawna TEXT",
        "ALTER TABLE contract_letters ADD COLUMN nr_nadania TEXT",
        "ALTER TABLE contract_letters ADD COLUMN adres_edoreczenia TEXT",
        "ALTER TABLE contract_letters ADD COLUMN edoreczenia_ref TEXT",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Uprawnienia grup — funkcje globalne
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Czy bieżący użytkownik ma pełny dostęp do wszystkich kontaktów CRM
 * (admin lub rola bez ograniczeń grupowych).
 */
function crm_access_unrestricted(): bool {
    if (is_admin()) return true;
    $u = current_user();
    if (!$u) return false;
    // Użytkownicy z can_write('crm') na poziomie roli systemowej (editor itp.)
    // mają pełny dostęp, chyba że są crm_only i nie są adminem
    if (!is_crm_only()) return true;
    return false;
}

/**
 * Zwraca ID grup, do których bieżący użytkownik ma dostęp (czytanie).
 * Dla adminów i ról z pełnym dostępem zwraca null (= brak filtrowania).
 * Dla użytkowników z ograniczeniami grupowymi zwraca tablicę ID grup.
 */
function crm_accessible_group_ids(): ?array {
    if (crm_access_unrestricted()) return null;
    $u = current_user();
    if (!$u) return [];
    static $cache = [];
    $uid = (int)$u['id'];
    if (array_key_exists($uid, $cache)) return $cache[$uid];

    // Pobierz grupy z dostępem przypisanym bezpośrednio
    $direct = db_all(
        "SELECT DISTINCT gu.group_id FROM crm_group_users gu WHERE gu.user_id=?",
        [$uid]
    );
    $ids = array_map(fn($r) => (int)$r['group_id'], $direct);

    return $cache[$uid] = $ids;
}

/**
 * Sprawdza, czy bieżący użytkownik ma dostęp do konkretnego kontaktu.
 * Admin / pełny dostęp: zawsze true.
 * Ograniczony: true jeśli kontakt należy do co najmniej jednej dostępnej grupy.
 */
function crm_can_access_contact(int $contact_id): bool {
    $group_ids = crm_accessible_group_ids();
    if ($group_ids === null) return true;
    if (empty($group_ids)) return false;
    $placeholders = implode(',', array_fill(0, count($group_ids), '?'));
    $row = db_one(
        "SELECT 1 FROM crm_group_members WHERE contact_id=? AND group_id IN ($placeholders) LIMIT 1",
        array_merge([$contact_id], $group_ids)
    );
    return (bool)$row;
}

/**
 * Sprawdza uprawnienie zapisu/usuwania w danej grupie dla bieżącego użytkownika.
 */
function crm_group_can(int $group_id, string $perm = 'write'): bool {
    if (crm_access_unrestricted()) return true;
    $u = current_user();
    if (!$u) return false;
    $col = $perm === 'delete' ? 'can_delete' : 'can_write';
    $row = db_one(
        "SELECT $col FROM crm_group_users WHERE group_id=? AND user_id=?",
        [$group_id, (int)$u['id']]
    );
    return !empty($row[$col]);
}

// ─────────────────────────────────────────────────────────────────────────────
// KLASA CrmManager — logika biznesowa (Single Responsibility)
// ─────────────────────────────────────────────────────────────────────────────

class CrmManager
{
    // ── Kontakty ──────────────────────────────────────────────────────────────

    /**
     * Zwraca stronę kontaktów z filtrami.
     *
     * @param array  $filters  ['q', 'status', 'type', 'tag']
     * @param int    $page     Numer strony (1-based)
     * @param int    $per_page Rekordów na stronę
     * @return array ['rows' => array, 'total' => int]
     */
    public static function getContacts(array $filters = [], int $page = 1, int $per_page = 25): array
    {
        $where = ['c.crm_active = 1'];
        $params = [];

        // Ograniczenie grupowe — widoczne tylko kontakty z dostępnych grup
        $accessible = crm_accessible_group_ids();
        if ($accessible !== null) {
            if (empty($accessible)) {
                return ['rows' => [], 'total' => 0];
            }
            $ph = implode(',', array_fill(0, count($accessible), '?'));
            $where[]  = "EXISTS (SELECT 1 FROM crm_group_members gm WHERE gm.contact_id=c.id AND gm.group_id IN ($ph))";
            $params   = array_merge($params, $accessible);
        }

        if (!empty($filters['q'])) {
            $where[]    = "(c.imie_nazwisko LIKE ? OR c.email LIKE ? OR c.organizacja LIKE ? OR c.telefon LIKE ?)";
            $q          = '%' . $filters['q'] . '%';
            $params     = array_merge($params, [$q, $q, $q, $q]);
        }
        if (!empty($filters['status'])) {
            $where[]  = "c.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[]  = "c.type = ?";
            $params[] = $filters['type'];
        }
        if (!empty($filters['tag'])) {
            $where[]  = "EXISTS (SELECT 1 FROM crm_tags t WHERE t.contact_id=c.id AND t.tag=?)";
            $params[] = $filters['tag'];
        }
        if (!empty($filters['group'])) {
            $where[]  = "EXISTS (SELECT 1 FROM crm_group_members gm WHERE gm.contact_id=c.id AND gm.group_id=?)";
            $params[] = (int)$filters['group'];
        }
        if (!empty($filters['wojewodztwo'])) {
            $where[]  = "c.wojewodztwo = ?";
            $params[] = $filters['wojewodztwo'];
        }
        if (!empty($filters['powiat'])) {
            $where[]  = "c.powiat LIKE ?";
            $params[] = '%' . $filters['powiat'] . '%';
        }
        if (!empty($filters['gmina'])) {
            $where[]  = "c.gmina LIKE ?";
            $params[] = '%' . $filters['gmina'] . '%';
        }
        if (!empty($filters['action_id'])) {
            $where[]  = "EXISTS (SELECT 1 FROM crm_action_links al WHERE al.contact_id=c.id AND al.action_id=?)";
            $params[] = (int)$filters['action_id'];
        }

        $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)(db_one(
            "SELECT COUNT(*) AS cnt FROM crm_contacts c {$sql_where}",
            $params
        )['cnt'] ?? 0);

        $offset = ($page - 1) * $per_page;
        $rows = db_all(
            "SELECT c.*,
                    (SELECT GROUP_CONCAT(t.tag, ',') FROM crm_tags t WHERE t.contact_id=c.id) AS tags_csv,
                    (SELECT MAX(cc.sent_at) FROM crm_communications cc WHERE cc.contact_id=c.id) AS last_comm_at
             FROM crm_contacts c
             {$sql_where}
             ORDER BY c.updated_at DESC
             LIMIT {$per_page} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Pojedynczy kontakt z pełnymi danymi. */
    public static function getContact(int $id): ?array
    {
        $contact = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [$id]);
        if (!$contact) return null;
        if (!crm_can_access_contact($id)) return null;

        $contact['tags']           = db_all("SELECT * FROM crm_tags WHERE contact_id=? ORDER BY tag", [$id]);
        $contact['notes']          = db_all(
            "SELECT n.*, u.name AS author_name
             FROM crm_notes n LEFT JOIN users u ON u.id=n.created_by
             WHERE n.contact_id=? ORDER BY n.is_pinned DESC, n.created_at DESC",
            [$id]
        );
        $contact['communications'] = db_all(
            "SELECT cc.*, u.name AS sender_name
             FROM crm_communications cc LEFT JOIN users u ON u.id=cc.sent_by
             WHERE cc.contact_id=? ORDER BY cc.sent_at DESC LIMIT 50",
            [$id]
        );
        $contact['relations']      = self::getRelations($id);
        $contact['groups']         = self::getContactGroups($id);

        return $contact;
    }

    /**
     * Tworzy nowy kontakt. Zwraca ID.
     * @param array $data Wymagany klucz 'imie_nazwisko'.
     */
    public static function createContact(array $data): int
    {
        $data['avatar_initials'] = self::makeInitials($data['imie_nazwisko'] ?? '');
        $data['created_at']      = date('Y-m-d H:i:s');
        $data['updated_at']      = date('Y-m-d H:i:s');

        $allowed = [
            'type','status','imie_nazwisko','email','telefon','adres',
            'nip','krs','stanowisko','organizacja','notatka',
            'avatar_initials','person_id','source','created_by',
            'created_at','updated_at',
            // pola osób fizycznych
            'imie','nazwisko','pesel','data_urodzenia',
            // pola firm / organizacji
            'regon','branza','strona_www','osoba_kontaktowa','forma_prawna',
            // lokalizacja administracyjna
            'wojewodztwo','powiat','gmina','teryt_kod',
            // strukturalny adres
            'addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        return db_insert('crm_contacts', $data);
    }

    /** Aktualizuje kontakt (automatycznie updated_at przez db_update). */
    public static function updateContact(int $id, array $data): void
    {
        if (isset($data['imie_nazwisko'])) {
            $data['avatar_initials'] = self::makeInitials($data['imie_nazwisko']);
        }
        $allowed = [
            'type','status','imie_nazwisko','email','telefon','adres',
            'nip','krs','stanowisko','organizacja','notatka',
            'avatar_initials','person_id','source',
            // pola osób fizycznych
            'imie','nazwisko','pesel','data_urodzenia',
            // pola firm / organizacji
            'regon','branza','strona_www','osoba_kontaktowa','forma_prawna',
            // lokalizacja administracyjna
            'wojewodztwo','powiat','gmina','teryt_kod',
            // strukturalny adres
            'addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        db_update('crm_contacts', $data, $id);
    }

    /** Soft-delete: crm_active = 0. */
    public static function deleteContact(int $id): void
    {
        db()->prepare("UPDATE crm_contacts SET crm_active=0, updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $id]);
    }

    // ── Tagi ──────────────────────────────────────────────────────────────────

    public static function addTag(int $contact_id, string $tag): void
    {
        $tag = mb_strtolower(trim($tag));
        if ($tag === '') return;
        try {
            db()->prepare("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)")
                ->execute([$contact_id, $tag]);
        } catch (\Throwable $e) {}
        db()->prepare("UPDATE crm_contacts SET updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $contact_id]);
    }

    public static function removeTag(int $contact_id, string $tag): void
    {
        db()->prepare("DELETE FROM crm_tags WHERE contact_id=? AND tag=?")
            ->execute([$contact_id, $tag]);
    }

    // ── Notatki ───────────────────────────────────────────────────────────────

    /** Dodaje notatkę, zwraca jej ID. */
    public static function addNote(int $contact_id, string $body, int $user_id, bool $pinned = false): int
    {
        $id = db_insert('crm_notes', [
            'contact_id' => $contact_id,
            'body'       => trim($body),
            'is_pinned'  => $pinned ? 1 : 0,
            'created_by' => $user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        db()->prepare("UPDATE crm_contacts SET updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $contact_id]);
        return $id;
    }

    public static function deleteNote(int $note_id): void
    {
        db()->prepare("DELETE FROM crm_notes WHERE id=?")->execute([$note_id]);
    }

    // ── Relacje ───────────────────────────────────────────────────────────────

    /**
     * Dodaje relację a→b.
     * Automatycznie tworzy relację odwrotną b→a z odwrotnym typem
     * (jeśli typ ma swoją odwrotność, np. przełożony↔podwładny).
     */
    public static function addRelation(int $a, int $b, string $type, ?string $notes = null): void
    {
        $user_id = (int)(current_user()['id'] ?? 0);
        try {
            db()->prepare(
                "INSERT OR IGNORE INTO crm_relations
                 (contact_a_id, contact_b_id, relation_type, notes, created_by, created_at)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$a, $b, $type, $notes, $user_id, date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}

        // Automatyczna relacja odwrotna
        $inverse = self::inverseRelationType($type);
        if ($inverse !== $type) {
            try {
                db()->prepare(
                    "INSERT OR IGNORE INTO crm_relations
                     (contact_a_id, contact_b_id, relation_type, notes, created_by, created_at)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$b, $a, $inverse, $notes, $user_id, date('Y-m-d H:i:s')]);
            } catch (\Throwable $e) {}
        }
    }

    public static function removeRelation(int $relation_id): void
    {
        db()->prepare("DELETE FROM crm_relations WHERE id=?")->execute([$relation_id]);
    }

    /** Zwraca wszystkie relacje danego kontaktu (w obu kierunkach). */
    public static function getRelations(int $contact_id): array
    {
        return db_all(
            "SELECT r.*,
                    cb.imie_nazwisko AS other_name,
                    cb.status        AS other_status,
                    cb.type          AS other_type,
                    cb.organizacja   AS other_org,
                    cb.avatar_initials AS other_initials
             FROM crm_relations r
             JOIN crm_contacts cb ON cb.id = r.contact_b_id
             WHERE r.contact_a_id = ? AND cb.crm_active = 1
             ORDER BY r.relation_type, cb.imie_nazwisko",
            [$contact_id]
        );
    }

    // ── Komunikacja ───────────────────────────────────────────────────────────

    /**
     * Loguje wysłaną wiadomość i opcjonalnie wysyła SMS/email.
     *
     * @param bool $do_send true = faktycznie wyślij przez bramkę
     * @return int ID logu komunikacji
     */
    public static function sendAndLog(
        int    $contact_id,
        string $channel,
        string $body,
        string $subject       = '',
        string $template_name = '',
        bool   $do_send       = true,
        array  $attachments   = []
    ): int {
        $user    = current_user();
        $user_id = (int)($user['id'] ?? 0);
        $status  = 'zaplanowana';

        if ($do_send) {
            $contact   = db_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]);
            $user_sig  = db_one("SELECT crm_email_signature, crm_sms_signature FROM users WHERE id=?", [$user_id]);
            try {
                if ($channel === 'sms' && ($contact['telefon'] ?? '')) {
                    require_once __DIR__ . '/sms.php';
                    $sms_body = $body;
                    $sms_sig  = trim($user_sig['crm_sms_signature'] ?? '');
                    if ($sms_sig !== '') {
                        $sms_body = rtrim($sms_body) . "\n" . $sms_sig;
                    }
                    sms_send($contact['telefon'], $sms_body);
                    $status = 'wysłana';
                } elseif ($channel === 'email' && ($contact['email'] ?? '')) {
                    require_once __DIR__ . '/mail_queue.php';
                    $to_name = $contact['imie_nazwisko'] ?? '';
                    // Wyślij HTML jeśli treść zawiera tagi, inaczej zawiń w prosty HTML
                    $is_html = strip_tags($body) !== $body;
                    $html_body = $is_html ? $body : nl2br(htmlspecialchars($body));
                    // Dołącz podpis użytkownika (jeśli ustawiony)
                    $user_email_sig = trim($user_sig['crm_email_signature'] ?? '');
                    if ($user_email_sig !== '') {
                        $html_body .= "\n<hr style=\"border:none;border-top:1px solid #e5e7eb;margin:1rem 0\">\n" . $user_email_sig;
                    }
                    // Dołącz globalną stopkę CRM admina (jeśli ustawiona)
                    $crm_footer = trim(org_setting('crm_email_footer'));
                    if ($crm_footer !== '') {
                        $html_body .= "\n<hr>\n" . $crm_footer;
                    }
                    mail_queue_add($contact['email'], $to_name, $subject ?: 'Wiadomość', $html_body, $body, 'crm', $contact_id, '', false, $attachments);
                    // Wyślij natychmiast (nie czekaj na cron) — przez M365/SMTP/mail()
                    mail_queue_process(1);
                    $status = 'wysłana';
                }
            } catch (\Throwable $e) {
                $status = 'błąd: ' . mb_substr($e->getMessage(), 0, 120);
            }
        }

        $id = db_insert('crm_communications', [
            'contact_id'    => $contact_id,
            'channel'       => $channel,
            'direction'     => 'out',
            'template_name' => $template_name ?: null,
            'subject'       => $subject ?: null,
            'body'          => $body,
            'status'        => $status,
            'sent_by'       => $user_id,
            'sent_at'       => date('Y-m-d H:i:s'),
        ]);

        db()->prepare("UPDATE crm_contacts SET updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $contact_id]);

        return $id;
    }

    // ── Szablony ──────────────────────────────────────────────────────────────

    /** Podstawia zmienne szablonu ({imie}, {email}, …) danymi kontaktu. */
    public static function renderTemplate(string $tpl, array $contact): string
    {
        $vars = [
            '{imie}'          => explode(' ', $contact['imie_nazwisko'] ?? '')[0] ?? '',
            '{imie_nazwisko}' => $contact['imie_nazwisko'] ?? '',
            '{email}'         => $contact['email'] ?? '',
            '{telefon}'       => $contact['telefon'] ?? '',
            '{organizacja}'   => $contact['organizacja'] ?? '',
            '{stanowisko}'    => $contact['stanowisko'] ?? '',
            '{wojewodztwo}'   => $contact['wojewodztwo'] ?? '',
            '{powiat}'        => $contact['powiat'] ?? '',
            '{gmina}'         => $contact['gmina'] ?? '',
            '{data}'          => date('d.m.Y'),
        ];
        return str_replace(array_keys($vars), array_values($vars), $tpl);
    }

    // ── Statystyki ────────────────────────────────────────────────────────────

    public static function getStats(): array
    {
        $total   = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1")['c'] ?? 0);
        $by_status = db_all(
            "SELECT status, COUNT(*) AS cnt FROM crm_contacts WHERE crm_active=1 GROUP BY status ORDER BY cnt DESC"
        );
        $new_this_month = (int)(db_one(
            "SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND created_at >= DATE('now','start of month')"
        )['c'] ?? 0);
        $last_comm = db_one(
            "SELECT MAX(sent_at) AS ts FROM crm_communications"
        );

        return compact('total', 'by_status', 'new_this_month', 'last_comm');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function makeInitials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        $ini   = '';
        foreach ($words as $w) $ini .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
        return mb_substr($ini, 0, 2, 'UTF-8') ?: '?';
    }

    private static function inverseRelationType(string $type): string
    {
        $map = [
            'przełożony'   => 'podwładny',
            'podwładny'    => 'przełożony',
            'sponsor'      => 'beneficjent',
            'beneficjent'  => 'sponsor',
        ];
        return $map[$type] ?? $type;
    }

    // ── Zarządzanie tagami ────────────────────────────────────────────────────

    /**
     * Zwraca wszystkie tagi z liczbą aktywnych kontaktów.
     * Posortowane malejąco po popularności.
     */
    public static function getAllTags(): array
    {
        return db_all(
            "SELECT t.tag, COUNT(DISTINCT t.contact_id) AS cnt
             FROM crm_tags t
             JOIN crm_contacts c ON c.id = t.contact_id AND c.crm_active = 1
             GROUP BY t.tag
             ORDER BY cnt DESC, t.tag ASC"
        );
    }

    /**
     * Zmienia nazwę tagu we wszystkich kontaktach.
     * Obsługuje konflikty (kontakt ma już nowy tag → usuwa stary).
     * Zwraca liczbę zaktualizowanych wierszy.
     */
    public static function renameTag(string $old_tag, string $new_tag): int
    {
        $old = mb_strtolower(trim($old_tag));
        $new = mb_strtolower(trim($new_tag));
        if ($old === '' || $new === '' || $old === $new) return 0;

        // Usuń duplikaty — kontakty, które mają już $new
        db()->prepare(
            "DELETE FROM crm_tags
             WHERE tag = ? AND contact_id IN (SELECT contact_id FROM crm_tags WHERE tag = ?)"
        )->execute([$old, $new]);

        $stmt = db()->prepare("UPDATE crm_tags SET tag = ? WHERE tag = ?");
        $stmt->execute([$new, $old]);
        return $stmt->rowCount();
    }

    /**
     * Usuwa tag ze wszystkich kontaktów.
     * Zwraca liczbę usuniętych wierszy.
     */
    public static function deleteTag(string $tag): int
    {
        $tag = mb_strtolower(trim($tag));
        if ($tag === '') return 0;
        $stmt = db()->prepare("DELETE FROM crm_tags WHERE tag = ?");
        $stmt->execute([$tag]);
        return $stmt->rowCount();
    }

    // ── Grupy kontaktów ───────────────────────────────────────────────────────

    /** Zwraca wszystkie grupy z liczbą członków, posortowane alfabetycznie.
     *  Dla użytkowników z ograniczonym dostępem — tylko ich grupy. */
    public static function getGroups(): array
    {
        $accessible = crm_accessible_group_ids();
        $extra_where = '';
        $params = [];
        if ($accessible !== null) {
            if (empty($accessible)) return [];
            $ph = implode(',', array_fill(0, count($accessible), '?'));
            $extra_where = "WHERE g.id IN ($ph)";
            $params = $accessible;
        }
        return db_all(
            "SELECT g.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM crm_group_members gm WHERE gm.group_id = g.id) AS member_count
             FROM crm_groups g
             LEFT JOIN users u ON u.id = g.created_by
             $extra_where
             ORDER BY g.name ASC",
            $params
        );
    }

    /** Zwraca jedną grupę z pełną listą członków. Null jeśli nie istnieje. */
    public static function getGroup(int $id): ?array
    {
        $group = db_one("SELECT * FROM crm_groups WHERE id=?", [$id]);
        if (!$group) return null;

        $group['members'] = db_all(
            "SELECT c.id, c.type, c.imie_nazwisko, c.email, c.telefon,
                    c.organizacja, c.stanowisko, c.status,
                    c.avatar_initials, gm.added_at, u.name AS added_by_name
             FROM crm_group_members gm
             JOIN crm_contacts c ON c.id = gm.contact_id AND c.crm_active = 1
             LEFT JOIN users u ON u.id = gm.added_by
             WHERE gm.group_id = ?
             ORDER BY c.imie_nazwisko ASC",
            [$id]
        );

        return $group;
    }

    /** Tworzy nową grupę. Zwraca ID. */
    public static function createGroup(array $data): int
    {
        $user_id = (int)(current_user()['id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        return db_insert('crm_groups', [
            'name'        => trim($data['name']),
            'description' => trim($data['description'] ?? '') ?: null,
            'color'       => $data['color'] ?? '#2E844A',
            'icon'        => $data['icon'] ?? 'bi-collection-fill',
            'created_by'  => $user_id,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /** Aktualizuje metadane grupy. */
    public static function updateGroup(int $id, array $data): void
    {
        $allowed = ['name', 'description', 'color', 'icon'];
        $data = array_intersect_key($data, array_flip($allowed));
        if (isset($data['name']))        $data['name']        = trim($data['name']);
        if (isset($data['description'])) $data['description'] = trim($data['description']) ?: null;
        $data['updated_at'] = date('Y-m-d H:i:s');
        db_update('crm_groups', $data, $id);
    }

    /**
     * Usuwa grupę i wszystkie jej przynależności (kontakty nie są dotykane).
     */
    public static function deleteGroup(int $id): void
    {
        db()->prepare("DELETE FROM crm_groups WHERE id=?")->execute([$id]);
    }

    /** Dodaje kontakt do grupy (INSERT OR IGNORE). Opcjonalny $added_by nadpisuje bieżącego usera. */
    public static function addToGroup(int $group_id, int $contact_id, int $added_by = 0): void
    {
        $user_id = $added_by ?: (int)(current_user()['id'] ?? 0);
        try {
            db()->prepare(
                "INSERT OR IGNORE INTO crm_group_members
                 (group_id, contact_id, added_by, added_at) VALUES (?,?,?,?)"
            )->execute([$group_id, $contact_id, $user_id, date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
    }

    /** Usuwa kontakt z grupy. */
    public static function removeFromGroup(int $group_id, int $contact_id): void
    {
        db()->prepare(
            "DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?"
        )->execute([$group_id, $contact_id]);
    }

    // ── Dostęp użytkowników do grup ───────────────────────────────────────────

    /** Zwraca listę użytkowników z dostępem do grupy. */
    public static function getGroupUsers(int $group_id): array
    {
        return db_all(
            "SELECT gu.*, u.name AS user_name, u.email AS user_email, r.display_name AS role_name
             FROM crm_group_users gu
             JOIN users u ON u.id = gu.user_id
             LEFT JOIN roles r ON r.name = u.role
             WHERE gu.group_id = ?
             ORDER BY u.name ASC",
            [$group_id]
        );
    }

    /** Ustawia/aktualizuje dostęp użytkownika do grupy. */
    public static function setGroupUser(int $group_id, int $user_id, int $can_write, int $can_delete): void
    {
        $grantor = (int)(current_user()['id'] ?? 0);
        try {
            db()->prepare(
                "INSERT INTO crm_group_users (group_id, user_id, can_write, can_delete, granted_by, granted_at)
                 VALUES (?,?,?,?,?,?)
                 ON CONFLICT(group_id, user_id) DO UPDATE SET can_write=excluded.can_write, can_delete=excluded.can_delete, granted_by=excluded.granted_by, granted_at=excluded.granted_at"
            )->execute([$group_id, $user_id, $can_write, $can_delete, $grantor, date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            $ex = db_one("SELECT id FROM crm_group_users WHERE group_id=? AND user_id=?", [$group_id, $user_id]);
            if ($ex) {
                db()->prepare("UPDATE crm_group_users SET can_write=?,can_delete=?,granted_by=?,granted_at=? WHERE group_id=? AND user_id=?")
                    ->execute([$can_write, $can_delete, $grantor, date('Y-m-d H:i:s'), $group_id, $user_id]);
            } else {
                db_insert('crm_group_users', ['group_id'=>$group_id,'user_id'=>$user_id,'can_write'=>$can_write,'can_delete'=>$can_delete,'granted_by'=>$grantor,'granted_at'=>date('Y-m-d H:i:s')]);
            }
        }
    }

    /** Usuwa dostęp użytkownika do grupy. */
    public static function removeGroupUser(int $group_id, int $user_id): void
    {
        db()->prepare("DELETE FROM crm_group_users WHERE group_id=? AND user_id=?")
            ->execute([$group_id, $user_id]);
    }

    /** Zwraca grupy, do których należy kontakt. */
    public static function getContactGroups(int $contact_id): array
    {
        return db_all(
            "SELECT g.* FROM crm_groups g
             JOIN crm_group_members gm ON gm.group_id = g.id
             WHERE gm.contact_id = ?
             ORDER BY g.name ASC",
            [$contact_id]
        );
    }

    /** Zwraca kontakty NIE należące jeszcze do danej grupy (do selektu dodawania). */
    public static function getContactsNotInGroup(int $group_id): array
    {
        return db_all(
            "SELECT id, imie_nazwisko, organizacja, type FROM crm_contacts
             WHERE crm_active = 1
               AND id NOT IN (SELECT contact_id FROM crm_group_members WHERE group_id = ?)
             ORDER BY imie_nazwisko ASC",
            [$group_id]
        );
    }

    // ── Wolontariusze systemu głównego ────────────────────────────────────────

    /**
     * Umowy wolontariatu z systemu głównego pasujące do e-maila kontaktu.
     * Bezpieczne — try/catch na wypadek braku tabeli.
     */
    public static function getContactVolunteerContracts(string $email): array
    {
        if ($email === '') return [];
        try {
            return db_all(
                "SELECT w.id, w.numer_umowy, w.imie_nazwisko, w.status,
                        w.data_od, w.data_do, w.stanowisko,
                        w.action_id, w.grant_id, w.miejsce_wolontariatu
                 FROM umowy_wolontariat w
                 WHERE LOWER(w.email) = LOWER(?)
                 ORDER BY w.data_od DESC, w.id DESC",
                [$email]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Zgłoszenia rekrutacyjne z systemu głównego pasujące do e-maila kontaktu.
     * JOIN z volunteer_offers po tytuł ogłoszenia.
     */
    public static function getContactRecruitments(string $email): array
    {
        if ($email === '') return [];
        try {
            return db_all(
                "SELECT a.id, a.status, a.created_at, a.admin_notes,
                        a.avail_from, a.avail_to,
                        o.id AS offer_id, o.title AS offer_title, o.status AS offer_status
                 FROM volunteer_applications a
                 JOIN volunteer_offers o ON o.id = a.volunteer_offer_id
                 WHERE LOWER(a.candidate_email) = LOWER(?)
                 ORDER BY a.created_at DESC",
                [$email]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Powiązania z Działaniami systemu głównego ─────────────────────────────

    /**
     * Powiązuje kontakt CRM z działaniem (INSERT OR IGNORE).
     *
     * @param string $rola  np. 'uczestnik', 'prelegent', 'wolontariusz', 'koordynator'
     */
    public static function linkToAction(
        int    $contact_id,
        int    $action_id,
        string $rola = 'uczestnik',
        string $nota = ''
    ): void {
        $user_id = (int)(current_user()['id'] ?? 0);
        try {
            db()->prepare(
                "INSERT OR IGNORE INTO crm_action_links
                 (action_id, contact_id, rola, nota, added_by, added_at)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$action_id, $contact_id, $rola, $nota ?: null, $user_id, date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
    }

    /** Usuwa powiązanie kontaktu z działaniem. */
    public static function unlinkFromAction(int $contact_id, int $action_id): void
    {
        db()->prepare(
            "DELETE FROM crm_action_links WHERE contact_id=? AND action_id=?"
        )->execute([$contact_id, $action_id]);
    }

    /**
     * Zwraca działania, do których jest przypisany kontakt.
     * Dane działania pobierane bezpośrednio z tabeli `actions` (system główny).
     */
    public static function getContactActions(int $contact_id): array
    {
        return db_all(
            "SELECT a.id, a.nazwa, a.typ, a.status, a.data_od, a.data_do,
                    a.lokalizacja, a.forma, a.wlasne_dzialanie,
                    cal.rola, cal.nota, cal.added_at,
                    u.name AS koordynator_name
             FROM crm_action_links cal
             JOIN actions a ON a.id = cal.action_id
             LEFT JOIN users u ON u.id = a.koordynator_id
             WHERE cal.contact_id = ?
             ORDER BY a.data_od DESC, a.id DESC",
            [$contact_id]
        );
    }

    /**
     * Zwraca kontakty CRM przypisane do działania.
     * Używane w actions/view.php.
     */
    public static function getActionContacts(int $action_id): array
    {
        return db_all(
            "SELECT c.id, c.type, c.imie_nazwisko, c.email, c.telefon,
                    c.organizacja, c.stanowisko, c.status, c.avatar_initials,
                    cal.rola, cal.nota, cal.added_at,
                    u.name AS added_by_name
             FROM crm_action_links cal
             JOIN crm_contacts c ON c.id = cal.contact_id AND c.crm_active = 1
             LEFT JOIN users u ON u.id = cal.added_by
             WHERE cal.action_id = ?
             ORDER BY c.imie_nazwisko ASC",
            [$action_id]
        );
    }

    /**
     * Zwraca kontakty CRM, które NIE są jeszcze przypisane do działania.
     * Do selektu w formularzu dodawania uczestnika.
     */
    public static function getContactsNotInAction(int $action_id): array
    {
        return db_all(
            "SELECT id, imie_nazwisko, organizacja, type, status FROM crm_contacts
             WHERE crm_active = 1
               AND id NOT IN (SELECT contact_id FROM crm_action_links WHERE action_id = ?)
             ORDER BY imie_nazwisko ASC",
            [$action_id]
        );
    }

    /**
     * Automatycznie dodaje wolontariusza do CRM po zapisaniu umowy wolontariackiej.
     * Tworzy lub aktualizuje kontakt CRM, przypisuje do grupy Wolontariusze
     * i do podgrupy działania jeśli action_id jest podany.
     */
    public static function autoAddVolunteer(array $row, int $created_by): void {
        $email = trim($row['email'] ?? '');
        $name  = trim($row['imie_nazwisko'] ?? '');
        if (!$name) return;

        // 1. Znajdź lub utwórz kontakt CRM
        $contact_id = null;
        if ($email) {
            $existing = db_one("SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1", [$email]);
            if ($existing) $contact_id = (int)$existing['id'];
        }
        if (!$contact_id) {
            $existing = db_one("SELECT id FROM crm_contacts WHERE imie_nazwisko=? AND crm_active=1 ORDER BY id DESC LIMIT 1", [$name]);
            if ($existing) $contact_id = (int)$existing['id'];
        }
        if (!$contact_id) {
            $contact_payload = [
                'type'         => 'osoba',
                'imie_nazwisko'=> $name,
                'email'        => $email ?: null,
                'telefon'      => $row['telefon'] ?? null,
                'status'       => 'aktywny',
                'source'       => 'wolontariat_auto',
                'created_by'   => $created_by,
            ];
            // Oddzielne imię/nazwisko jeśli dostarczone
            if (!empty($row['imie']))    $contact_payload['imie']    = $row['imie'];
            if (!empty($row['nazwisko'])) $contact_payload['nazwisko'] = $row['nazwisko'];
            // Dodatkowe pola z kartoteki
            foreach (['adres','stanowisko','organizacja','pesel','data_urodzenia','wojewodztwo'] as $f) {
                if (!empty($row[$f])) $contact_payload[$f] = $row[$f];
            }
            $contact_id = self::createContact($contact_payload);
        } else {
            // Zaktualizuj dane jeśli są nowe
            $upd = [];
            if ($email) $upd['email'] = $email;
            if ($row['telefon'] ?? '') $upd['telefon'] = $row['telefon'];
            if (!empty($row['imie']))    $upd['imie']    = $row['imie'];
            if (!empty($row['nazwisko'])) $upd['nazwisko'] = $row['nazwisko'];
            foreach (['adres','stanowisko','organizacja','pesel','data_urodzenia','wojewodztwo'] as $f) {
                if (!empty($row[$f])) $upd[$f] = $row[$f];
            }
            if ($upd) self::updateContact($contact_id, $upd);
        }

        // 2. Dodaj tag "wolontariusz"
        try {
            db()->prepare("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)")
                ->execute([$contact_id, 'wolontariusz']);
        } catch (\Throwable $e) {}

        // 3. Grupa główna "Wolontariusze" — znajdź lub utwórz
        $vol_group = db_one("SELECT id FROM crm_groups WHERE auto_source='wolontariusze'");
        if (!$vol_group) {
            $vol_group_id = db_insert('crm_groups', [
                'name'        => 'Wolontariusze',
                'description' => 'Wolontariusze — dodawani automatycznie z umów wolontariackich',
                'color'       => '#2E844A',
                'icon'        => 'bi-heart-fill',
                'auto_source' => 'wolontariusze',
                'sort_order'  => 0,
                'created_by'  => $created_by,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        } else {
            $vol_group_id = (int)$vol_group['id'];
        }
        self::addToGroup($vol_group_id, $contact_id, $created_by);

        // 4. Podgrupa wg działania (action_id)
        $action_id = (int)($row['action_id'] ?? 0);
        if ($action_id) {
            $action = db_one("SELECT id, nazwa FROM actions WHERE id=?", [$action_id]);
            if ($action) {
                $action_name    = trim($action['nazwa']);
                $action_source  = 'action_' . $action_id;
                $action_group   = db_one("SELECT id FROM crm_groups WHERE auto_source=?", [$action_source]);
                if (!$action_group) {
                    $action_group_id = db_insert('crm_groups', [
                        'name'        => 'Wolontariusze: ' . $action_name,
                        'description' => 'Wolontariusze działania: ' . $action_name,
                        'color'       => '#0176D3',
                        'icon'        => 'bi-lightning-charge-fill',
                        'auto_source' => $action_source,
                        'parent_id'   => $vol_group_id,
                        'sort_order'  => $action_id,
                        'created_by'  => $created_by,
                        'created_at'  => date('Y-m-d H:i:s'),
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $action_group_id = (int)$action_group['id'];
                }
                self::addToGroup($action_group_id, $contact_id, $created_by);
                // Tag z nazwą działania
                try {
                    $tag = mb_strtolower(preg_replace('/\s+/', '-', trim($action_name)));
                    db()->prepare("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)")
                        ->execute([$contact_id, $tag]);
                } catch (\Throwable $e) {}
            }
        }

        // 5. Tag z projektem/programem
        $projekt = trim($row['projekt_program'] ?? '');
        if ($projekt) {
            try {
                $tag = mb_strtolower(preg_replace('/\s+/', '-', $projekt));
                db()->prepare("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)")
                    ->execute([$contact_id, $tag]);
            } catch (\Throwable $e) {}
        }
    }

    // ── Dodatkowe pola (definicje) ─────────────────────────────────────────

    public static function getFieldDefs(string $applies_to = '', bool $active_only = true): array {
        $where = $active_only ? "WHERE is_active=1" : "WHERE 1=1";
        $params = [];
        if ($applies_to) {
            $where .= " AND (applies_to='both' OR applies_to=?)";
            $params[] = $applies_to;
        }
        return db_all("SELECT * FROM crm_contact_field_defs $where ORDER BY sort_order, id", $params);
    }

    public static function getFieldDef(int $id): ?array {
        return db_one("SELECT * FROM crm_contact_field_defs WHERE id=?", [$id]) ?: null;
    }

    public static function saveFieldDef(array $data, ?int $id = null): int {
        $fields = [
            'label'      => trim($data['label'] ?? ''),
            'field_type' => in_array($data['field_type'] ?? '', ['text','number','date','select','url','email','textarea','checkbox'], true)
                              ? $data['field_type'] : 'text',
            'options'    => trim($data['options'] ?? ''),
            'applies_to' => in_array($data['applies_to'] ?? '', ['osoba','organizacja','both'], true)
                              ? $data['applies_to'] : 'both',
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'is_active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            'group_id'   => isset($data['group_id']) && $data['group_id'] ? (int)$data['group_id'] : null,
        ];
        if ($id) {
            db()->prepare(
                "UPDATE crm_contact_field_defs SET label=?,field_type=?,options=?,applies_to=?,sort_order=?,is_active=?,group_id=? WHERE id=?"
            )->execute([...array_values($fields), $id]);
            return $id;
        }
        return db_insert('crm_contact_field_defs', $fields);
    }

    public static function deleteFieldDef(int $id): void {
        db()->prepare("DELETE FROM crm_contact_field_defs WHERE id=?")->execute([$id]);
    }

    // ── Dodatkowe pola (wartości) ──────────────────────────────────────────

    public static function getFieldValues(int $contact_id): array {
        $rows = db_all(
            "SELECT fv.field_def_id, fv.value FROM crm_contact_field_values fv WHERE fv.contact_id=?",
            [$contact_id]
        );
        $out = [];
        foreach ($rows as $r) $out[(int)$r['field_def_id']] = $r['value'];
        return $out;
    }

    public static function saveFieldValues(int $contact_id, array $values): void {
        // $values = [field_def_id => value, ...]
        foreach ($values as $def_id => $val) {
            $def_id = (int)$def_id;
            $val    = (string)$val;
            try {
                db()->prepare(
                    "INSERT INTO crm_contact_field_values (contact_id, field_def_id, value) VALUES (?,?,?)
                     ON CONFLICT(contact_id, field_def_id) DO UPDATE SET value=excluded.value"
                )->execute([$contact_id, $def_id, $val]);
            } catch (\Throwable $e) {
                // Fallback dla starszego SQLite bez ON CONFLICT na UNIQUE
                $ex = db_one("SELECT id FROM crm_contact_field_values WHERE contact_id=? AND field_def_id=?", [$contact_id, $def_id]);
                if ($ex) {
                    db()->prepare("UPDATE crm_contact_field_values SET value=? WHERE contact_id=? AND field_def_id=?")
                        ->execute([$val, $contact_id, $def_id]);
                } else {
                    db_insert('crm_contact_field_values', ['contact_id' => $contact_id, 'field_def_id' => $def_id, 'value' => $val]);
                }
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// KLASA SyncService — delta synchronizacja System Główny → CRM
// ─────────────────────────────────────────────────────────────────────────────

class SyncService
{
    /**
     * Weryfikuje token synchronizacji z nagłówka lub POST.
     */
    public static function verifyToken(string $token): bool
    {
        $stored = org_setting('crm_sync_token');
        if ($stored === '' || $token === '') return false;
        return hash_equals($stored, $token);
    }

    /**
     * Zwraca kontakty zmienione od podanego timestampa (delta sync).
     *
     * @param int $since Unix timestamp ostatniej synchronizacji (0 = wszystkie)
     * @return array ['contacts' => array, 'server_ts' => int]
     */
    public static function getDelta(int $since = 0): array
    {
        $since_dt = $since > 0 ? date('Y-m-d H:i:s', $since) : '1970-01-01 00:00:00';

        $contacts = db_all(
            "SELECT c.id, c.type, c.status, c.imie_nazwisko, c.email,
                    c.telefon, c.organizacja, c.stanowisko, c.source,
                    c.crm_active, c.updated_at,
                    (SELECT GROUP_CONCAT(t.tag,',') FROM crm_tags t WHERE t.contact_id=c.id) AS tags
             FROM crm_contacts c
             WHERE c.updated_at > ?
             ORDER BY c.updated_at DESC",
            [$since_dt]
        );

        return [
            'contacts'  => $contacts,
            'server_ts' => time(),
            'count'     => count($contacts),
        ];
    }

    /**
     * Importuje/aktualizuje kontakt z systemu głównego.
     * Dopasowanie: po email lub person_id.
     *
     * @return int ID kontaktu (nowy lub istniejący)
     */
    public static function upsertFromMain(array $data): int
    {
        $existing = null;

        // Dopasowanie po person_id
        if (!empty($data['person_id'])) {
            $existing = db_one(
                "SELECT id FROM crm_contacts WHERE person_id=? AND crm_active=1",
                [(int)$data['person_id']]
            );
        }
        // Dopasowanie po email
        if (!$existing && !empty($data['email'])) {
            $existing = db_one(
                "SELECT id FROM crm_contacts WHERE email=? AND crm_active=1 LIMIT 1",
                [$data['email']]
            );
        }

        $payload = [
            'imie_nazwisko' => $data['imie_nazwisko'] ?? 'Nieznany',
            'email'         => $data['email'] ?? null,
            'telefon'       => $data['telefon'] ?? null,
            'organizacja'   => $data['organizacja'] ?? null,
            'stanowisko'    => $data['stanowisko'] ?? null,
            'person_id'     => $data['person_id'] ?? null,
            'source'        => 'sync',
            'synced_at'     => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            CrmManager::updateContact((int)$existing['id'], $payload);
            return (int)$existing['id'];
        }

        $payload['status'] = $data['status'] ?? 'aktywny';
        $payload['type']   = $data['type']   ?? 'osoba';
        return CrmManager::createContact($payload);
    }

    /** Loguje wynik synchronizacji. */
    public static function logSync(int $records, string $source = 'heartbeat', string $ip = ''): void
    {
        try {
            db_insert('crm_sync_log', [
                'records_updated' => $records,
                'source'          => $source,
                'ip'              => $ip,
                'synced_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}
    }

    // ── Synchronizacja grupy "Wolontariusze" ──────────────────────────────────

    /**
     * Statusy umów wolontariatu uznawane za aktywne (wolontariusz jest w grupie).
     */
    private static function _activeVolStatuses(): array {
        return ['projekt', 'podpisana', 'w realizacji', 'obowiązująca'];
    }

    /** Statusy uznawane za zakończone (przechodzą do "Byli wolontariusze"). */
    private static function _inactiveVolStatuses(): array {
        return ['zakończona', 'rozwiązana', 'anulowana'];
    }

    /**
     * Znajdź lub utwórz grupę "Byli wolontariusze" (auto_source='byli_wolontariusze').
     * Tworzy ją jako podgrupę grupy "Wolontariusze" ($vol_gid).
     */
    private static function _ensureFormerGroup(string $now, int $vol_gid): int {
        $g = db_one("SELECT id FROM crm_groups WHERE auto_source='byli_wolontariusze'");
        if ($g) return (int)$g['id'];
        return (int)db_insert('crm_groups', [
            'name'        => 'Byli wolontariusze',
            'description' => 'Wolontariusze z zakończonymi umowami — zarządzani automatycznie',
            'color'       => '#64748b',
            'icon'        => 'bi-archive-fill',
            'auto_source' => 'byli_wolontariusze',
            'parent_id'   => $vol_gid ?: null,
            'sort_order'  => 99,
            'created_by'  => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /**
     * Synchronizuje przynależność jednego wolontariusza (po e-mailu) do grupy
     * "Wolontariusze" na podstawie aktualnego statusu jego umowy.
     *
     * Wywoływany zaraz po zapisaniu umowy (edit/view).
     */
    public static function syncVolunteerGroupMember(string $email, string $contract_status): void {
        $email = trim($email);
        if (!$email) return;
        if (!module_enabled('crm_enabled')) return;

        $group = db_one("SELECT id FROM crm_groups WHERE auto_source='wolontariusze'");
        if (!$group) return;
        $gid = (int)$group['id'];

        $contact = db_one(
            "SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1 LIMIT 1",
            [$email]
        );
        if (!$contact) return;
        $cid  = (int)$contact['id'];
        $now  = date('Y-m-d H:i:s');
        $fgid = self::_ensureFormerGroup($now, $gid);

        $active    = self::_activeVolStatuses();
        $is_active = in_array($contract_status, $active, true);

        if ($is_active) {
            // Dodaj do "Wolontariusze"
            try {
                db()->prepare(
                    "INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at) VALUES (?,?,0,?)"
                )->execute([$gid, $cid, $now]);
            } catch (\Throwable $e) {}
            // Usuń z "Byli wolontariusze" (wrócił do aktywności)
            db()->prepare("DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?")
                ->execute([$fgid, $cid]);
        } else {
            // Sprawdź czy ma INNĄ aktywną umowę — jeśli tak, zostaje w grupie "Wolontariusze"
            $ph    = implode(',', array_fill(0, count($active), '?'));
            $other = db_one(
                "SELECT id FROM umowy_wolontariat WHERE LOWER(email)=LOWER(?) AND status IN ({$ph}) LIMIT 1",
                array_merge([$email], $active)
            );
            if (!$other) {
                // Usuń z "Wolontariusze"
                db()->prepare("DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?")
                    ->execute([$gid, $cid]);
                // Przenieś do "Byli wolontariusze"
                try {
                    db()->prepare(
                        "INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at) VALUES (?,?,0,?)"
                    )->execute([$fgid, $cid, $now]);
                } catch (\Throwable $e) {}
            }
        }
    }

    /**
     * Pełna synchronizacja grup wolontariuszy:
     *  - dodaje/usuwa w "Wolontariusze" wg aktywnych umów
     *  - przenosi do/z "Byli wolontariusze" tych bez aktywnej umowy
     *
     * Wywoływana przez CRON raz dziennie.
     *
     * @return array{added:int, removed:int, former_added:int, former_removed:int}
     */
    public static function syncVolunteerGroup(): array {
        $added = $removed = $former_added = $former_removed = 0;
        if (!module_enabled('crm_enabled')) return compact('added', 'removed', 'former_added', 'former_removed');

        $active = self::_activeVolStatuses();
        $ph     = implode(',', array_fill(0, count($active), '?'));
        $now    = date('Y-m-d H:i:s');

        // Znajdź lub stwórz grupę "Wolontariusze"
        $group = db_one("SELECT id FROM crm_groups WHERE auto_source='wolontariusze'");
        if (!$group) {
            $gid = (int)db_insert('crm_groups', [
                'name'        => 'Wolontariusze',
                'description' => 'Wolontariusze — dodawani automatycznie z umow wolontariackich',
                'color'       => '#2E844A',
                'icon'        => 'bi-heart-fill',
                'auto_source' => 'wolontariusze',
                'sort_order'  => 0,
                'created_by'  => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        } else {
            $gid = (int)$group['id'];
        }

        // Znajdź lub stwórz grupę "Byli wolontariusze"
        $fgid = self::_ensureFormerGroup($now, $gid);

        // 1. Dodaj brakujących z aktywnymi umowami do "Wolontariusze"
        //    i usuń ich z "Byli wolontariusze" (jeśli wrócili)
        $active_emails = db_all(
            "SELECT DISTINCT LOWER(TRIM(email)) AS email
             FROM umowy_wolontariat
             WHERE status IN ({$ph}) AND email IS NOT NULL AND TRIM(email) != ''",
            $active
        );
        foreach ($active_emails as $row) {
            $contact = db_one(
                "SELECT id FROM crm_contacts WHERE LOWER(email)=? AND crm_active=1 LIMIT 1",
                [$row['email']]
            );
            if (!$contact) continue;
            $cid = (int)$contact['id'];
            try {
                $stmt = db()->prepare(
                    "INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at) VALUES (?,?,0,?)"
                );
                $stmt->execute([$gid, $cid, $now]);
                if ($stmt->rowCount() > 0) $added++;
            } catch (\Throwable $e) {}
            // Usuń z "Byli" jeśli tam był
            db()->prepare("DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?")
                ->execute([$fgid, $cid]);
        }

        // 2. Usuń z "Wolontariusze" tych bez aktywnej umowy, przenieś do "Byli"
        $members = db_all(
            "SELECT gm.contact_id, LOWER(TRIM(c.email)) AS email
             FROM crm_group_members gm
             JOIN crm_contacts c ON c.id = gm.contact_id AND c.crm_active = 1
             WHERE gm.group_id = ?",
            [$gid]
        );
        foreach ($members as $m) {
            if (!$m['email']) continue;
            $has_active = db_one(
                "SELECT id FROM umowy_wolontariat WHERE LOWER(TRIM(email))=? AND status IN ({$ph}) LIMIT 1",
                array_merge([$m['email']], $active)
            );
            if (!$has_active) {
                db()->prepare("DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?")
                    ->execute([$gid, (int)$m['contact_id']]);
                $removed++;
                // Przenieś do "Byli wolontariusze"
                try {
                    $stmt2 = db()->prepare(
                        "INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at) VALUES (?,?,0,?)"
                    );
                    $stmt2->execute([$fgid, (int)$m['contact_id'], $now]);
                    if ($stmt2->rowCount() > 0) $former_added++;
                } catch (\Throwable $e) {}
            }
        }

        // 3. Wyczyść "Byli" z tych, którzy mają aktywne umowy (niespójność)
        $former_members = db_all(
            "SELECT gm.contact_id, LOWER(TRIM(c.email)) AS email
             FROM crm_group_members gm
             JOIN crm_contacts c ON c.id = gm.contact_id AND c.crm_active = 1
             WHERE gm.group_id = ?",
            [$fgid]
        );
        foreach ($former_members as $m) {
            if (!$m['email']) continue;
            $has_active = db_one(
                "SELECT id FROM umowy_wolontariat WHERE LOWER(TRIM(email))=? AND status IN ({$ph}) LIMIT 1",
                array_merge([$m['email']], $active)
            );
            if ($has_active) {
                db()->prepare("DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?")
                    ->execute([$fgid, (int)$m['contact_id']]);
                $former_removed++;
            }
        }

        return compact('added', 'removed', 'former_added', 'former_removed');
    }
}
