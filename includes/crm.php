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

// ── Typy kontaktów ────────────────────────────────────────────────────────────
const CRM_CONTACT_TYPES = [
    'osoba'       => ['label' => 'Osoba fizyczna', 'short' => 'Osoba',      'icon' => 'bi-person-fill', 'color' => '#0176D3', 'org_like' => false],
    'organizacja' => ['label' => 'Organizacja',    'short' => 'Org.',        'icon' => 'bi-building',    'color' => '#032D60', 'org_like' => true],
    'kontrahent'  => ['label' => 'Kontrahent',     'short' => 'Kontr.',      'icon' => 'bi-briefcase',   'color' => '#E07B39', 'org_like' => true],
    'partner'     => ['label' => 'Partner',        'short' => 'Partner',     'icon' => 'bi-handshake',   'color' => '#2E844A', 'org_like' => true],
];

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

/**
 * Status, przy którym podmiot może świadczyć usługi na rzecz organizacji.
 *
 * REGUŁA: kategoria „świadczy usługi na rzecz FEER" i przypisane rodzaje usług
 * są dostępne WYŁĄCZNIE dla kontaktów o statusie „partner" — decyduje status
 * (cykl życia relacji), NIE typ kontaktu. W CRM „partner" występuje w obu
 * słownikach; tu chodzi o crm_contacts.status.
 *
 * Dane raz wpisane nie są kasowane przy zmianie statusu — kontakt, który przestał
 * być partnerem, pokazuje swoje usługi tylko do odczytu (patrz _cv_services_html()).
 */
const CRM_SERVICES_STATUS = 'partner';

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

/** Definicje pól standardowych (systemowych) kontaktu CRM — dla UI uprawnień. */
const CRM_SYSTEM_FIELDS = [
    'email'            => ['label' => 'E-mail',              'group' => 'Dane kontaktowe',  'applies_to' => 'both'],
    'telefon'          => ['label' => 'Telefon',             'group' => 'Dane kontaktowe',  'applies_to' => 'both'],
    'adres'            => ['label' => 'Adres',               'group' => 'Dane kontaktowe',  'applies_to' => 'both'],
    'stanowisko'       => ['label' => 'Stanowisko / Rola',   'group' => 'Dane kontaktowe',  'applies_to' => 'osoba'],
    'organizacja'      => ['label' => 'Firma / Organizacja', 'group' => 'Dane kontaktowe',  'applies_to' => 'osoba'],
    'strona_www'       => ['label' => 'Strona WWW',          'group' => 'Dane kontaktowe',  'applies_to' => 'both'],
    'notatka'          => ['label' => 'Notatka',             'group' => 'Dane kontaktowe',  'applies_to' => 'both'],
    'pesel'            => ['label' => 'PESEL',               'group' => 'Dane osobowe',     'applies_to' => 'osoba'],
    'data_urodzenia'   => ['label' => 'Data urodzenia',      'group' => 'Dane osobowe',     'applies_to' => 'osoba'],
    'nip'              => ['label' => 'NIP',                 'group' => 'Dane rejestrowe',  'applies_to' => 'both'],
    'krs'              => ['label' => 'KRS',                 'group' => 'Dane rejestrowe',  'applies_to' => 'organizacja'],
    'regon'            => ['label' => 'REGON',               'group' => 'Dane rejestrowe',  'applies_to' => 'organizacja'],
    'branza'           => ['label' => 'Branża',              'group' => 'Dane rejestrowe',  'applies_to' => 'organizacja'],
    'forma_prawna'     => ['label' => 'Forma prawna',        'group' => 'Dane rejestrowe',  'applies_to' => 'organizacja'],
    'osoba_kontaktowa' => ['label' => 'Osoba kontaktowa',    'group' => 'Dane rejestrowe',  'applies_to' => 'organizacja'],
    'status'           => ['label' => 'Status kontaktu',     'group' => 'Systemowe',        'applies_to' => 'both'],
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

// ── Katalog rodzajów usług (otwarty) ─────────────────────────────────────────

/** Pozycje katalogu rodzajów usług. */
function crm_service_types(bool $only_active = true): array {
    try {
        return crm_all(
            "SELECT * FROM crm_service_types" . ($only_active ? " WHERE is_active=1" : "")
            . " ORDER BY sort_order, nazwa"
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Zwraca id rodzaju usługi o podanej nazwie, tworząc go, gdy jeszcze nie istnieje.
 * Dzięki temu katalog jest otwarty — nową pozycję można dopisać wprost z karty
 * kontaktu, bez wchodzenia w ustawienia. Dopasowanie bez rozróżniania wielkości liter.
 */
function crm_service_type_find_or_create(string $nazwa, ?int $user_id = null): int {
    $nazwa = trim($nazwa);
    if ($nazwa === '') return 0;
    try {
        $row = crm_one("SELECT id FROM crm_service_types WHERE LOWER(nazwa)=LOWER(?)", [$nazwa]);
        if ($row) return (int)$row['id'];
        $max = crm_one("SELECT MAX(sort_order) AS m FROM crm_service_types");
        return crm_insert('crm_service_types', [
            'nazwa'      => mb_substr($nazwa, 0, 120),
            'is_active'  => 1,
            'sort_order' => (int)($max['m'] ?? 0) + 1,
            'created_by' => $user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Ilu kontaktów dotyczy dany rodzaj usługi — do ustawień katalogu. */
function crm_service_type_usage(int $type_id): int {
    try {
        return (int)(crm_one(
            "SELECT COUNT(*) AS c FROM crm_contact_services WHERE service_type_id=?", [$type_id]
        )['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Czy kontakt może mieć usługi na rzecz organizacji (status = partner).
 *
 * @param array|string|null $contact Rekord kontaktu albo sam status.
 */
function crm_services_allowed(array|string|null $contact): bool
{
    $status = is_array($contact) ? (string)($contact['status'] ?? '') : (string)$contact;
    return $status === CRM_SERVICES_STATUS;
}

/** Czy kontakt ma już jakiekolwiek dane o usługach (flaga albo przypisane rodzaje). */
function crm_services_has_data(array $contact): bool
{
    return !empty($contact['swiadczy_uslugi']) || !empty($contact['services']);
}

/** Etykieta statusu wymaganego dla usług — do komunikatów w interfejsie. */
function crm_services_status_label(): string
{
    $st = crm_statuses();
    return (string)($st[CRM_SERVICES_STATUS]['label'] ?? 'Partner');
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

// Współdzielenie spraw — lista osób, którym sprawę udostępniono.
function crm_case_shares(int $case_id): array {
    return db_all(
        "SELECT s.*, u.name AS user_name, u.first_name, u.last_name
         FROM crm_case_shares s
         JOIN users u ON u.id=s.user_id
         WHERE s.case_id=? ORDER BY u.name",
        [$case_id]
    );
}

// Czy bieżący użytkownik może edytować daną sprawę.
// Model addytywny: globalny zapis CRM / admin / twórca, ALBO udział z prawem zapisu
// (udostępnienie „edycja" podnosi do edycji nawet użytkownika z samym odczytem CRM).
function crm_case_can_edit(array $case): bool {
    if (is_admin() || can_write('crm')) return true;
    $uid = (int)(current_user()['id'] ?? 0);
    if (!$uid) return false;
    if ((int)($case['created_by'] ?? 0) === $uid) return true;
    $share = db_one(
        "SELECT can_write FROM crm_case_shares WHERE case_id=? AND user_id=?",
        [(int)($case['id'] ?? 0), $uid]
    );
    return $share && (int)$share['can_write'] === 1;
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
        // Strukturalny adres (używany przez createContact/updateContact i formularze) — v1.9
        "ALTER TABLE crm_contacts ADD COLUMN addr_street     TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN addr_house      TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN addr_flat       TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN addr_postal     TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN addr_city       TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN addr_country    TEXT",
        // Wypisanie z wysyłek mailowych (kampanie + automatyzacje) — globalne, nie per-kampania
        "ALTER TABLE crm_contacts ADD COLUMN email_opt_out    INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE crm_contacts ADD COLUMN email_opt_out_at DATETIME",
        // Kategoria „świadczy usługi na rzecz FEER" — rodzaje usług w crm_contact_services
        "ALTER TABLE crm_contacts ADD COLUMN swiadczy_uslugi INTEGER NOT NULL DEFAULT 0",
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

    // Osoby kontaktowe podmiotu — wiele osób do jednej firmy/organizacji.
    // Zastępuje pojedyncze pole crm_contacts.osoba_kontaktowa, które jest teraz
    // utrzymywane jako zdenormalizowana nazwa osoby głównej (dla list, eksportu i API).
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_persons (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id    INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        imie_nazwisko TEXT    NOT NULL,
        stanowisko    TEXT,
        email         TEXT,
        telefon       TEXT,
        notatka       TEXT,
        is_primary    INTEGER NOT NULL DEFAULT 0,
        sort_order    INTEGER NOT NULL DEFAULT 0,
        linked_contact_id INTEGER REFERENCES crm_contacts(id) ON DELETE SET NULL,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_cperson_contact ON crm_contact_persons(contact_id)");

    // Otwarty katalog rodzajów usług świadczonych na rzecz organizacji.
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_service_types (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        nazwa      TEXT    NOT NULL UNIQUE,
        opis       TEXT,
        is_active  INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Przypisanie rodzajów usług do kontaktu (kategoria „świadczy usługi na rzecz FEER").
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_services (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id      INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        service_type_id INTEGER NOT NULL REFERENCES crm_service_types(id) ON DELETE CASCADE,
        uwagi           TEXT,
        od_kiedy        DATE,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(contact_id, service_type_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_cservices_contact ON crm_contact_services(contact_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_cservices_type    ON crm_contact_services(service_type_id)");

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
    // Identyfikator zewnętrzny (np. ID wiadomości Graph) — deduplikacja przychodzących. v1.9
    try { $pdo->exec("ALTER TABLE crm_communications ADD COLUMN external_id TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_extid ON crm_communications(external_id)"); } catch (\Throwable $e) {}

    // Szablony wiadomości
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_templates (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL UNIQUE,
        channel     TEXT    NOT NULL DEFAULT 'email',
        subject     TEXT,
        body        TEXT    NOT NULL,
        variables   TEXT,
        is_active   INTEGER NOT NULL DEFAULT 1,
        is_locked   INTEGER NOT NULL DEFAULT 0,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Szablon zastrzeżony (is_locked=1) — edytować/usuwać może tylko administrator.
    try { $pdo->exec("ALTER TABLE crm_templates ADD COLUMN is_locked INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // Źródło szablonu — 'manual' (edycja tekstowa) lub 'mosaico' (edytor drag&drop).
    try { $pdo->exec("ALTER TABLE crm_templates ADD COLUMN source TEXT NOT NULL DEFAULT 'manual'"); } catch (\Throwable $e) {}

    // Log synchronizacji
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_sync_log (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        synced_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        records_updated INTEGER NOT NULL DEFAULT 0,
        source          TEXT DEFAULT 'heartbeat',
        ip              TEXT,
        details         TEXT
    )");

    // Kampanie mailowe
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_campaigns (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        name               TEXT    NOT NULL,
        template_id        INTEGER REFERENCES crm_templates(id) ON DELETE SET NULL,
        subject            TEXT    NOT NULL DEFAULT '',
        segment_type       TEXT    NOT NULL DEFAULT 'tags',
        segment_config     TEXT    NOT NULL DEFAULT '{}',
        status             TEXT    NOT NULL DEFAULT 'draft',
        scheduled_at       DATETIME,
        sent_at            DATETIME,
        created_by         INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        recipients_count   INTEGER NOT NULL DEFAULT 0,
        sent_count         INTEGER NOT NULL DEFAULT 0,
        failed_count       INTEGER NOT NULL DEFAULT 0,
        opened_count       INTEGER NOT NULL DEFAULT 0,
        clicked_count      INTEGER NOT NULL DEFAULT 0,
        unsubscribed_count INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_campaigns_status ON crm_campaigns(status, scheduled_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_campaign_recipients (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id      INTEGER NOT NULL REFERENCES crm_campaigns(id) ON DELETE CASCADE,
        contact_id       INTEGER NOT NULL REFERENCES crm_contacts(id)  ON DELETE CASCADE,
        mail_queue_id    INTEGER,
        tracking_token   TEXT    NOT NULL,
        status           TEXT    NOT NULL DEFAULT 'queued',
        sent_at          DATETIME,
        opened_at        DATETIME,
        first_clicked_at DATETIME,
        click_count      INTEGER NOT NULL DEFAULT 0,
        unsubscribed_at  DATETIME,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(campaign_id, contact_id)
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_camp_rcpt_token ON crm_campaign_recipients(tracking_token)");

    // Automatyzacje — reguły „zdarzenie → akcja"
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_automations (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        name           TEXT    NOT NULL,
        trigger_event  TEXT    NOT NULL,
        trigger_config TEXT    NOT NULL DEFAULT '{}',
        action_type    TEXT    NOT NULL,
        action_config  TEXT    NOT NULL DEFAULT '{}',
        is_active      INTEGER NOT NULL DEFAULT 1,
        run_count      INTEGER NOT NULL DEFAULT 0,
        last_run_at    DATETIME,
        created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_automations_event ON crm_automations(trigger_event, is_active)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_automation_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        automation_id INTEGER NOT NULL REFERENCES crm_automations(id) ON DELETE CASCADE,
        contact_id   INTEGER REFERENCES crm_contacts(id) ON DELETE CASCADE,
        event        TEXT    NOT NULL,
        status       TEXT    NOT NULL,
        detail       TEXT    NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_automation_log ON crm_automation_log(automation_id, contact_id, created_at)");

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
    try { $pdo->exec("ALTER TABLE crm_mass_sends ADD COLUMN failed_ids TEXT"); } catch (\Throwable $e) {}

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

    // Współdzielenie spraw (per-użytkownik; addytywne — nie ogranicza widoczności)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_case_shares (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        case_id    INTEGER NOT NULL REFERENCES crm_cases(id) ON DELETE CASCADE,
        user_id    INTEGER NOT NULL REFERENCES users(id)     ON DELETE CASCADE,
        can_write  INTEGER NOT NULL DEFAULT 0,
        shared_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        shared_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(case_id, user_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_case_shares_case ON crm_case_shares(case_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_case_shares_user ON crm_case_shares(user_id)");

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

    // Uczestnicy wydarzeń (grupa osób przypisana do zdarzenia)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_event_participants (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id   INTEGER NOT NULL REFERENCES crm_events(id) ON DELETE CASCADE,
        user_id    INTEGER NOT NULL REFERENCES users(id)      ON DELETE CASCADE,
        added_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(event_id, user_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_evt_part_event ON crm_event_participants(event_id)");

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
    // Uprawnienia per-pole: lista ról (JSON), puste = wszyscy
    try { $pdo->exec("ALTER TABLE crm_contact_field_defs ADD COLUMN visible_roles TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE crm_contact_field_defs ADD COLUMN edit_roles TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // Uprawnienia per-grupa: widoczność/edycja grupy pól
    try { $pdo->exec("ALTER TABLE crm_field_groups ADD COLUMN visible_roles TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE crm_field_groups ADD COLUMN edit_roles TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // Uprawnienia do pól standardowych (systemowych)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_system_field_perms (
        field_key     TEXT PRIMARY KEY,
        visible_roles TEXT NOT NULL DEFAULT '',
        edit_roles    TEXT NOT NULL DEFAULT ''
    )");

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

    // Kolumny numeracji i powiązania z umową (idempotentne)
    foreach ([
        "ALTER TABLE crm_cases ADD COLUMN case_number TEXT",
        "ALTER TABLE crm_cases ADD COLUMN contract_type TEXT",
        "ALTER TABLE crm_cases ADD COLUMN contract_id INTEGER",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_cases_case_number ON crm_cases(case_number) WHERE case_number IS NOT NULL");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_cases_contract ON crm_cases(contract_type, contract_id) WHERE contract_type IS NOT NULL AND contract_id IS NOT NULL");
    } catch (\Throwable $e) {}

    // Katalog rodzajów usług — seed startowy tylko przy pustej tabeli.
    // Katalog jest otwarty: pozycje można dowolnie dodawać, zmieniać i usuwać
    // w Ustawieniach CRM → Rodzaje usług, a także dopisywać wprost z karty kontaktu.
    try {
        $has_types = (int)($pdo->query("SELECT COUNT(*) AS c FROM crm_service_types")->fetch()['c'] ?? 0);
        if ($has_types === 0) {
            $ins = $pdo->prepare("INSERT OR IGNORE INTO crm_service_types (nazwa, sort_order) VALUES (?,?)");
            foreach ([
                'Usługi księgowe', 'Obsługa prawna', 'Usługi IT', 'Szkolenia i warsztaty',
                'Tłumaczenia', 'Transport', 'Catering', 'Usługi remontowo-budowlane',
                'Marketing i promocja', 'Fotografia / wideo', 'Wsparcie psychologiczne',
                'Najem / udostępnianie lokalu',
            ] as $i => $nazwa) {
                $ins->execute([$nazwa, $i]);
            }
        }
    } catch (\Throwable $e) {}

    // Jednorazowe przeniesienie starego pola osoba_kontaktowa do crm_contact_persons.
    // Kolumna zostaje jako zdenormalizowana nazwa osoby głównej (list, eksport, API),
    // ale edycja odbywa się już wyłącznie przez tabelę osób kontaktowych.
    try {
        $migrated = db_one("SELECT value FROM settings WHERE key_='crm_contact_persons_migrated'");
        if (!$migrated) {
            $rows = $pdo->query(
                "SELECT id, osoba_kontaktowa FROM crm_contacts
                 WHERE osoba_kontaktowa IS NOT NULL AND TRIM(osoba_kontaktowa) != ''"
            )->fetchAll();
            $ins = $pdo->prepare(
                "INSERT INTO crm_contact_persons (contact_id, imie_nazwisko, is_primary, sort_order, created_at)
                 VALUES (?,?,1,0,?)"
            );
            $chk = $pdo->prepare("SELECT COUNT(*) AS c FROM crm_contact_persons WHERE contact_id=?");
            foreach ($rows as $r) {
                $chk->execute([(int)$r['id']]);
                if ((int)($chk->fetch()['c'] ?? 0) > 0) continue;
                $ins->execute([(int)$r['id'], trim($r['osoba_kontaktowa']), date('Y-m-d H:i:s')]);
            }
            $pdo->prepare("INSERT INTO settings (key_, value) VALUES ('crm_contact_persons_migrated', ?)")
                ->execute([date('Y-m-d H:i:s')]);
        }
    } catch (\Throwable $e) {}

    // Schemat pism (dane rejestrowe + Postivo) — jedno źródło prawdy.
    // Wcześniej lista kolumn pisma była tu duplikowana i rozjeżdżała się z kodem.
    require_once __DIR__ . '/letters_schema.php';
}

// ─────────────────────────────────────────────────────────────────────────────
// Numeracja spraw CRM
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Zwraca następny numer sprawy w formacie CASE{RRRR}/{NNN}, np. CASE2026/001.
 * Numer jest wyznaczany na podstawie istniejących wpisów w crm_cases.
 */
function crm_next_case_number(int $year = 0): string {
    if ($year <= 0) $year = (int)date('Y');
    $prefix = 'CASE' . $year . '/';
    $row = db_one(
        "SELECT case_number FROM crm_cases WHERE case_number LIKE ? ORDER BY case_number DESC LIMIT 1",
        [$prefix . '%']
    );
    $next = 1;
    if ($row) {
        $parts = explode('/', $row['case_number']);
        $next = (int)end($parts) + 1;
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
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
// UPRAWNIENIA PER-POLE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Zwraca listę wszystkich ról dostępnych w systemie dla selecta uprawnień pól.
 * Format: ['admin' => 'Administrator', ...]
 */
function crm_all_roles(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = db_all("SELECT name, display_name FROM roles WHERE is_active IS NULL OR is_active != 0 ORDER BY sort_order, id");
        $cache = [];
        foreach ($rows as $r) $cache[$r['name']] = $r['display_name'];
    } catch (\Throwable $e) {
        $cache = ['admin' => 'Administrator', 'editor' => 'Redaktor', 'viewer' => 'Podgląd', 'crm_user' => 'Użytkownik CRM'];
    }
    return $cache;
}

/**
 * Sprawdza czy bieżący użytkownik może WIDZIEĆ pole (visible_roles).
 * Puste visible_roles = wszyscy mają dostęp.
 * Jeśli pole ma group_id — sprawdza też widoczność grupy.
 */
function crm_field_visible(array $fd): bool {
    if (is_admin()) return true;
    // Sprawdź grupę (jeśli pole należy do grupy)
    $group_id = (int)($fd['group_id'] ?? 0);
    if ($group_id && !_crm_group_perm_visible($group_id)) return false;
    $roles_json = $fd['visible_roles'] ?? '';
    if ($roles_json === '' || $roles_json === '[]') return true;
    $allowed = json_decode($roles_json, true);
    if (!is_array($allowed) || empty($allowed)) return true;
    $user = current_user();
    if (!$user) return false;
    $user_role = $user['role'] ?? '';
    return in_array($user_role, $allowed, true);
}

/**
 * Sprawdza czy bieżący użytkownik może EDYTOWAĆ pole (edit_roles).
 * Puste edit_roles = takie same prawa jak visible_roles (kto widzi, ten edytuje).
 */
function crm_field_editable(array $fd): bool {
    if (!crm_field_visible($fd)) return false;
    $roles_json = $fd['edit_roles'] ?? '';
    if ($roles_json === '' || $roles_json === '[]') return true;
    if (is_admin()) return true;
    $allowed = json_decode($roles_json, true);
    if (!is_array($allowed) || empty($allowed)) return true;
    $user = current_user();
    if (!$user) return false;
    return in_array($user['role'] ?? '', $allowed, true);
}

/** Ładuje uprawnienia pola standardowego z cache (jeden SELECT na żądanie). */
function _crm_sys_perms(): array {
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    try {
        foreach (crm_all("SELECT field_key, visible_roles, edit_roles FROM crm_system_field_perms") as $r) {
            $p[$r['field_key']] = $r;
        }
    } catch (\Throwable $e) {}
    return $p;
}

/** Zwraca widoczność grupy pól z cache. */
function _crm_group_perm_visible(int $group_id): bool {
    static $cache = [];
    if (isset($cache[$group_id])) return $cache[$group_id];
    if (is_admin()) return $cache[$group_id] = true;
    try {
        $grp = crm_one("SELECT visible_roles FROM crm_field_groups WHERE id=?", [$group_id]);
    } catch (\Throwable $e) {
        return $cache[$group_id] = true;
    }
    if (!$grp) return $cache[$group_id] = true;
    $roles_json = $grp['visible_roles'] ?? '';
    if ($roles_json === '' || $roles_json === '[]') return $cache[$group_id] = true;
    $allowed = json_decode($roles_json, true);
    if (!is_array($allowed) || empty($allowed)) return $cache[$group_id] = true;
    $user = current_user();
    if (!$user) return $cache[$group_id] = false;
    return $cache[$group_id] = in_array($user['role'] ?? '', $allowed, true);
}

/** Zwraca edytowalność grupy pól z cache. */
function _crm_group_perm_editable(int $group_id): bool {
    if (!_crm_group_perm_visible($group_id)) return false;
    if (is_admin()) return true;
    static $cache = [];
    if (isset($cache[$group_id])) return $cache[$group_id];
    try {
        $grp = crm_one("SELECT edit_roles FROM crm_field_groups WHERE id=?", [$group_id]);
    } catch (\Throwable $e) {
        return $cache[$group_id] = true;
    }
    if (!$grp) return $cache[$group_id] = true;
    $roles_json = $grp['edit_roles'] ?? '';
    if ($roles_json === '' || $roles_json === '[]') return $cache[$group_id] = true;
    $allowed = json_decode($roles_json, true);
    if (!is_array($allowed) || empty($allowed)) return $cache[$group_id] = true;
    $user = current_user();
    if (!$user) return $cache[$group_id] = false;
    return $cache[$group_id] = in_array($user['role'] ?? '', $allowed, true);
}

/**
 * Sprawdza widoczność pola STANDARDOWEGO (systemowego).
 * Puste = brak ograniczenia = wszyscy widzą.
 */
function crm_sys_field_visible(string $field_key): bool {
    if (is_admin()) return true;
    $fd = _crm_sys_perms()[$field_key] ?? null;
    if (!$fd) return true;
    return crm_field_visible($fd);
}

/**
 * Sprawdza edytowalność pola STANDARDOWEGO (systemowego).
 */
function crm_sys_field_editable(string $field_key): bool {
    if (!crm_sys_field_visible($field_key)) return false;
    if (is_admin()) return true;
    $fd = _crm_sys_perms()[$field_key] ?? null;
    if (!$fd) return true;
    return crm_field_editable($fd);
}

// ─────────────────────────────────────────────────────────────────────────────
// KLASA CrmManager — logika biznesowa (Single Responsibility)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Usuwa z danych klucze o wartości NULL/'' dla kolumn NOT NULL, które mają
 * wartość domyślną — wtedy zadziała DEFAULT zamiast wysypać INSERT/UPDATE.
 * Formularze często wysyłają `?: null` dla pól nieobowiązkowych (np. crm_contacts.source
 * = NOT NULL DEFAULT 'manual'), co bez tego kończyło się błędem 500.
 */
function crm_strip_null_notnull(array $data, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cols = [];
        try {
            if (DB_TYPE === 'sqlite') {
                foreach (crm_db()->query("PRAGMA table_info(`$table`)")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    if ((int)($c['notnull'] ?? 0) === 1 && $c['dflt_value'] !== null) $cols[] = $c['name'];
                }
            } else {
                foreach (crm_db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    if (($c['Null'] ?? '') === 'NO' && $c['Default'] !== null) $cols[] = $c['Field'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[crm_strip_null_notnull] ' . $e->getMessage());
        }
        $cache[$table] = $cols;
    }
    foreach ($cache[$table] as $col) {
        if (array_key_exists($col, $data) && ($data[$col] === null || $data[$col] === '')) {
            unset($data[$col]);
        }
    }
    return $data;
}

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
        // Wyszukiwanie „we wszystkich polach" — szeroki LIKE po polach identyfikacyjnych i opisowych
        if (!empty($filters['q_all'])) {
            $cols = ['c.imie_nazwisko', 'c.email', 'c.organizacja', 'c.telefon',
                     'c.nip', 'c.krs', 'c.regon', 'c.pesel', 'c.branza', 'c.adres',
                     'c.stanowisko', 'c.osoba_kontaktowa', 'c.strona_www', 'c.notatka',
                     'c.wojewodztwo', 'c.powiat', 'c.gmina'];
            $where[]   = '(' . implode(' OR ', array_map(fn($col) => "$col LIKE ?", $cols)) . ')';
            $qa        = '%' . $filters['q_all'] . '%';
            $params    = array_merge($params, array_fill(0, count($cols), $qa));
        }
        if (!empty($filters['source'])) {
            $where[]  = "c.source = ?";
            $params[] = $filters['source'];
        }
        if (!empty($filters['branza'])) {
            $where[]  = "c.branza LIKE ?";
            $params[] = '%' . $filters['branza'] . '%';
        }
        // Zakres daty dodania
        if (!empty($filters['created_from'])) {
            $where[]  = "c.created_at >= ?";
            $params[] = substr($filters['created_from'], 0, 10) . ' 00:00:00';
        }
        if (!empty($filters['created_to'])) {
            $where[]  = "c.created_at <= ?";
            $params[] = substr($filters['created_to'], 0, 10) . ' 23:59:59';
        }
        // Zakres daty ostatniego kontaktu (korelowany podzapytanie)
        if (!empty($filters['last_from'])) {
            $where[]  = "(SELECT MAX(cc.sent_at) FROM crm_communications cc WHERE cc.contact_id=c.id) >= ?";
            $params[] = substr($filters['last_from'], 0, 10) . ' 00:00:00';
        }
        if (!empty($filters['last_to'])) {
            $where[]  = "(SELECT MAX(cc.sent_at) FROM crm_communications cc WHERE cc.contact_id=c.id) <= ?";
            $params[] = substr($filters['last_to'], 0, 10) . ' 23:59:59';
        }
        // „Bez kontaktu od X dni" — brak komunikacji lub ostatnia starsza niż X dni
        if (!empty($filters['stale_days'])) {
            $where[]  = "(SELECT MAX(cc.sent_at) FROM crm_communications cc WHERE cc.contact_id=c.id) IS NULL
                         OR (SELECT MAX(cc.sent_at) FROM crm_communications cc WHERE cc.contact_id=c.id) < datetime('now', ?)";
            $params[] = '-' . (int)$filters['stale_days'] . ' days';
        }
        // Obecność e-maila / telefonu
        if (!empty($filters['has_email'])) {
            $where[] = "(c.email IS NOT NULL AND c.email != '')";
        }
        if (!empty($filters['has_phone'])) {
            $where[] = "(c.telefon IS NOT NULL AND c.telefon != '')";
        }
        if (!empty($filters['status'])) {
            $where[]  = "c.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[]  = "c.type = ?";
            $params[] = $filters['type'];
        }
        // Kategoria „świadczy usługi na rzecz FEER" — sama flaga lub konkretny rodzaj usługi
        if (!empty($filters['uslugi'])) {
            if ($filters['uslugi'] === 'any') {
                $where[] = "(c.swiadczy_uslugi = 1
                             OR EXISTS (SELECT 1 FROM crm_contact_services cs WHERE cs.contact_id=c.id))";
            } else {
                $where[]  = "EXISTS (SELECT 1 FROM crm_contact_services cs
                                     WHERE cs.contact_id=c.id AND cs.service_type_id=?)";
                $params[] = (int)$filters['uslugi'];
            }
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
        $contact['persons']        = self::getContactPersons($id);
        $contact['services']       = self::getContactServices($id);

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
            // kategoria „świadczy usługi na rzecz FEER"
            'swiadczy_uslugi',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        // Reguła „usługi tylko dla partnera" obowiązuje też tu — createContact()
        // przyjmuje swiadczy_uslugi wprost, więc bez tego import i REST API
        // mogłyby oznaczyć podmiot o dowolnym statusie.
        if (!empty($data['swiadczy_uslugi']) && !crm_services_allowed($data['status'] ?? '')) {
            $data['swiadczy_uslugi'] = 0;
        }
        $data = crm_strip_null_notnull($data, 'crm_contacts');
        $id = db_insert('crm_contacts', $data);
        require_once __DIR__ . '/crm_automation.php';
        crm_automation_fire('contact_created', $id);
        // Książka adresowa Outlooka — tylko gdy administrator włączył automatyczny zapis.
        // Błąd Graph nie może przerwać dodawania kontaktu, dlatego łapiemy wszystko.
        try {
            require_once __DIR__ . '/crm_office.php';
            if (crm_office_auto_push()) crm_office_push_contact($id);
        } catch (\Throwable $e) {
            error_log('[crm_office_auto_push] ' . $e->getMessage());
        }
        return $id;
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
            // kategoria „świadczy usługi na rzecz FEER"
            'swiadczy_uslugi',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        // Jak w createContact(). Aktualizacja nie musi zawierać statusu —
        // wtedy rozstrzyga status już zapisany w bazie.
        if (!empty($data['swiadczy_uslugi'])) {
            $st = $data['status'] ?? (crm_one("SELECT status FROM crm_contacts WHERE id=?", [$id])['status'] ?? '');
            if (!crm_services_allowed((string)$st)) $data['swiadczy_uslugi'] = 0;
        }
        $data = crm_strip_null_notnull($data, 'crm_contacts');
        db_update('crm_contacts', $data, $id);
    }

    /** Soft-delete: crm_active = 0. */
    public static function deleteContact(int $id): void
    {
        db()->prepare("UPDATE crm_contacts SET crm_active=0, updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $id]);
    }

    // ── Osoby kontaktowe podmiotu ─────────────────────────────────────────────
    // Jedna firma/organizacja może mieć wiele osób kontaktowych. Pierwsza (lub
    // oznaczona jako główna) jest kopiowana do crm_contacts.osoba_kontaktowa,
    // dzięki czemu listy, eksport, import i REST API działają bez zmian.

    public static function getContactPersons(int $contact_id): array
    {
        try {
            return crm_all(
                "SELECT p.*, c.imie_nazwisko AS linked_name, c.avatar_initials AS linked_initials
                 FROM crm_contact_persons p
                 LEFT JOIN crm_contacts c ON c.id = p.linked_contact_id
                 WHERE p.contact_id = ?
                 ORDER BY p.is_primary DESC, p.sort_order, p.id",
                [$contact_id]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getContactPerson(int $person_id): ?array
    {
        try {
            return crm_one("SELECT * FROM crm_contact_persons WHERE id=?", [$person_id]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Dodaje osobę kontaktową. Pierwsza dodana zostaje automatycznie główną. */
    public static function addContactPerson(int $contact_id, array $data, ?int $user_id = null): int
    {
        $name = trim((string)($data['imie_nazwisko'] ?? ''));
        if ($name === '') return 0;

        $existing = self::getContactPersons($contact_id);
        $primary  = !empty($data['is_primary']) || !$existing;
        $now      = date('Y-m-d H:i:s');

        $pid = crm_insert('crm_contact_persons', [
            'contact_id'    => $contact_id,
            'imie_nazwisko' => $name,
            'stanowisko'    => trim((string)($data['stanowisko'] ?? '')) ?: null,
            'email'         => trim((string)($data['email']      ?? '')) ?: null,
            'telefon'       => trim((string)($data['telefon']    ?? '')) ?: null,
            'notatka'       => trim((string)($data['notatka']    ?? '')) ?: null,
            'is_primary'    => $primary ? 1 : 0,
            'sort_order'    => count($existing),
            'linked_contact_id' => !empty($data['linked_contact_id']) ? (int)$data['linked_contact_id'] : null,
            'created_by'    => $user_id,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        if ($primary) self::setPrimaryContactPerson($contact_id, $pid);
        else          self::syncPrimaryPersonName($contact_id);
        return $pid;
    }

    public static function updateContactPerson(int $person_id, array $data): void
    {
        $person = self::getContactPerson($person_id);
        if (!$person) return;
        $fields = [];
        foreach (['imie_nazwisko', 'stanowisko', 'email', 'telefon', 'notatka'] as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = trim((string)$data[$f]);
            $fields[$f] = ($f === 'imie_nazwisko') ? $v : ($v ?: null);
        }
        if (isset($fields['imie_nazwisko']) && $fields['imie_nazwisko'] === '') unset($fields['imie_nazwisko']);
        if (!$fields) return;
        $fields['updated_at'] = date('Y-m-d H:i:s');
        crm_update('crm_contact_persons', $fields, $person_id);
        self::syncPrimaryPersonName((int)$person['contact_id']);
    }

    public static function deleteContactPerson(int $person_id): void
    {
        $person = self::getContactPerson($person_id);
        if (!$person) return;
        $contact_id = (int)$person['contact_id'];
        crm_db()->prepare("DELETE FROM crm_contact_persons WHERE id=?")->execute([$person_id]);

        // Gdy usunięto osobę główną — awansuj następną z listy.
        if (!empty($person['is_primary'])) {
            $next = self::getContactPersons($contact_id);
            if ($next) { self::setPrimaryContactPerson($contact_id, (int)$next[0]['id']); return; }
        }
        self::syncPrimaryPersonName($contact_id);
    }

    /** Ustawia jedną osobę jako główną (pozostałe tracą flagę). */
    public static function setPrimaryContactPerson(int $contact_id, int $person_id): void
    {
        try {
            crm_db()->prepare("UPDATE crm_contact_persons SET is_primary=0 WHERE contact_id=?")
                ->execute([$contact_id]);
            crm_db()->prepare("UPDATE crm_contact_persons SET is_primary=1 WHERE id=? AND contact_id=?")
                ->execute([$person_id, $contact_id]);
        } catch (\Throwable $e) {}
        self::syncPrimaryPersonName($contact_id);
    }

    /** Przepisuje nazwę osoby głównej do crm_contacts.osoba_kontaktowa. */
    public static function syncPrimaryPersonName(int $contact_id): void
    {
        $persons = self::getContactPersons($contact_id);
        $name    = $persons ? (string)$persons[0]['imie_nazwisko'] : null;
        try {
            crm_db()->prepare("UPDATE crm_contacts SET osoba_kontaktowa=?, updated_at=? WHERE id=?")
                ->execute([$name, date('Y-m-d H:i:s'), $contact_id]);
        } catch (\Throwable $e) {}
    }

    // ── Kategoria „świadczy usługi na rzecz FEER" ──────────────────────────────

    public static function getContactServices(int $contact_id): array
    {
        try {
            return crm_all(
                "SELECT cs.*, st.nazwa, st.is_active AS type_active
                 FROM crm_contact_services cs
                 JOIN crm_service_types st ON st.id = cs.service_type_id
                 WHERE cs.contact_id = ?
                 ORDER BY st.sort_order, st.nazwa",
                [$contact_id]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Dopina rodzaj usługi do kontaktu i włącza kategorię.
     * Kategoria bez wskazanego rodzaju też jest dopuszczalna — flagę ustawia
     * setProvidesServices(), tu tylko pilnujemy spójności w drugą stronę.
     */
    public static function addContactService(int $contact_id, int $service_type_id,
                                             ?string $uwagi = null, ?int $user_id = null): void
    {
        if ($service_type_id <= 0) return;
        // Reguła „usługi tylko dla partnera" — pilnowana także tutaj, nie tylko
        // w formularzach, żeby żadna ścieżka zapisu (import, API, akcja z karty)
        // nie dopisała usług podmiotowi o innym statusie.
        if (!crm_services_allowed(crm_one("SELECT status FROM crm_contacts WHERE id=?", [$contact_id]))) return;
        try {
            crm_db()->prepare(
                "INSERT OR IGNORE INTO crm_contact_services
                    (contact_id, service_type_id, uwagi, created_by, created_at)
                 VALUES (?,?,?,?,?)"
            )->execute([$contact_id, $service_type_id, ($uwagi !== null && trim($uwagi) !== '') ? trim($uwagi) : null,
                        $user_id, date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) { return; }
        self::setProvidesServices($contact_id, true);
    }

    public static function removeContactService(int $link_id): void
    {
        try {
            crm_db()->prepare("DELETE FROM crm_contact_services WHERE id=?")->execute([$link_id]);
        } catch (\Throwable $e) {}
    }

    /** Włącza/wyłącza kategorię. Wyłączenie NIE kasuje historii przypisanych usług. */
    public static function setProvidesServices(int $contact_id, bool $on): void
    {
        // Włączyć kategorię można tylko partnerowi; wyłączyć — zawsze, żeby dało
        // się posprzątać po kontakcie, który partnerem być przestał.
        if ($on && !crm_services_allowed(crm_one("SELECT status FROM crm_contacts WHERE id=?", [$contact_id]))) return;
        try {
            crm_db()->prepare("UPDATE crm_contacts SET swiadczy_uslugi=?, updated_at=? WHERE id=?")
                ->execute([$on ? 1 : 0, date('Y-m-d H:i:s'), $contact_id]);
        } catch (\Throwable $e) {}
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
        require_once __DIR__ . '/crm_automation.php';
        crm_automation_fire('tag_added', $contact_id, ['tag' => $tag]);
    }

    public static function removeTag(int $contact_id, string $tag): void
    {
        db()->prepare("DELETE FROM crm_tags WHERE contact_id=? AND tag=?")
            ->execute([$contact_id, $tag]);
    }

    // ── Notatki ───────────────────────────────────────────────────────────────

    /** Dodaje notatkę, zwraca jej ID. */
    public static function addNote(int $contact_id, string $body, ?int $user_id, bool $pinned = false): int
    {
        $id = db_insert('crm_notes', [
            'contact_id' => $contact_id,
            'body'       => trim($body),
            'is_pinned'  => $pinned ? 1 : 0,
            // NULL gdy brak prawidłowego użytkownika (np. zapis przez API key) — chroni FK users(id)
            'created_by' => ($user_id && $user_id > 0) ? $user_id : null,
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
        array  $attachments   = [],
        string $from_email    = ''   // skrzynka nadawcy (np. konto M365 usera); '' = systemowy
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
                    mail_queue_add($contact['email'], $to_name, $subject ?: 'Wiadomość', $html_body, $body, 'crm', $contact_id, '', false, $attachments, $from_email);
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

    /**
     * Podstawia zmienne szablonu danymi kontaktu (odbiorcy) oraz nadawcy
     * (zalogowanego użytkownika CRM). Zmienne nadawcy: {nadawca_imie},
     * {nadawca_nazwisko}, {nadawca_imie_nazwisko}, {nadawca_email}, {nadawca_telefon}.
     *
     * @param array|null $user Dane nadawcy; domyślnie bieżący użytkownik.
     */
    public static function renderTemplate(string $tpl, array $contact, ?array $user = null): string
    {
        $snd = self::senderData($user);
        $vars = [
            // Odbiorca (kontakt)
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
            // Nadawca (zalogowany użytkownik)
            '{nadawca_imie}'          => $snd['imie'],
            '{nadawca_nazwisko}'      => $snd['nazwisko'],
            '{nadawca_imie_nazwisko}' => $snd['imie_nazwisko'],
            '{nadawca_email}'         => $snd['email'],
            '{nadawca_telefon}'       => $snd['telefon'],
        ];
        return str_replace(array_keys($vars), array_values($vars), $tpl);
    }

    /** Dane nadawcy (zalogowanego użytkownika) do zmiennych {nadawca_*}. */
    public static function senderData(?array $user = null): array
    {
        static $cache = [];
        if ($user === null && function_exists('current_user')) $user = current_user() ?: [];
        $user = $user ?: [];

        // Sesja przechowuje tylko id/name/email/role — first_name/last_name/phone_number
        // dociągamy z bazy (z cache per użytkownik, bezpieczne dla pętli wysyłki masowej).
        $uid = (int)($user['id'] ?? 0);
        if ($uid && (!array_key_exists('first_name', $user) || !array_key_exists('phone_number', $user))) {
            if (!array_key_exists($uid, $cache)) {
                try {
                    $cache[$uid] = db_one(
                        "SELECT first_name, last_name, email, phone_number, name FROM users WHERE id=?",
                        [$uid]
                    ) ?: [];
                } catch (\Throwable $e) { $cache[$uid] = []; }
            }
            $user = array_merge($cache[$uid], $user); // wartości jawnie przekazane mają priorytet
        }

        $fn = trim($user['first_name'] ?? '');
        $ln = trim($user['last_name'] ?? '');
        $full = trim($fn . ' ' . $ln);
        if ($full === '') $full = trim($user['name'] ?? '');
        return [
            'imie'          => $fn,
            'nazwisko'      => $ln,
            'imie_nazwisko' => $full,
            'email'         => trim($user['email'] ?? ''),
            'telefon'       => trim($user['phone_number'] ?? ''),
        ];
    }

    /**
     * Lista braków w danych nadawcy (puste pola) — do monitu o uzupełnienie.
     * Zwraca etykiety pól, np. ['Imię', 'Numer telefonu'].
     */
    public static function senderMissing(?array $user = null): array
    {
        $s = self::senderData($user);
        $labels = [
            'imie'     => 'Imię',
            'nazwisko' => 'Nazwisko',
            'email'    => 'Adres e-mail',
            'telefon'  => 'Numer telefonu',
        ];
        $missing = [];
        foreach ($labels as $k => $label) {
            if (($s[$k] ?? '') === '') $missing[] = $label;
        }
        return $missing;
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
     * Dane osobowe z umów (główny system) pasujące do kontaktu — do importu na kartę.
     * Dopasowanie po person_id, a w razie jego braku po e-mailu (umowy wolontariatu).
     * Dla każdego pola podstawowego bierze pierwszą niepustą wartość z najświeższej
     * umowy i zapisuje jej źródło (nr umowy + typ). Bezpieczne: try/catch per tabela.
     *
     * @return array<string,array{value:string,source:string}>  pole => {wartość, źródło}
     */
    public static function getContractDataForContact(array $contact): array
    {
        $person_id = (int)($contact['person_id'] ?? 0);
        $email     = trim((string)($contact['email'] ?? ''));
        if (!$person_id && $email === '') return [];

        // tabela => etykieta typu; telefon/email/data_urodzenia tylko w wolontariacie
        $tables = [
            'umowy_zlecenie'    => 'zlecenie',
            'umowy_wolontariat' => 'wolontariat',
            'umowy_dzielo'      => 'dzieło',
            'umowy_praca'       => 'praca',
        ];

        $rows = [];
        foreach ($tables as $tbl => $typ) {
            $extra = ($tbl === 'umowy_wolontariat')
                ? ', telefon, email, data_urodzenia'
                : ", NULL AS telefon, NULL AS email, NULL AS data_urodzenia";
            try {
                if ($person_id) {
                    $found = db_all(
                        "SELECT numer_umowy, data_zawarcia, imie_nazwisko, pesel, adres{$extra}
                         FROM {$tbl} WHERE person_id = ?
                         ORDER BY data_zawarcia DESC, id DESC",
                        [$person_id]
                    );
                } elseif ($tbl === 'umowy_wolontariat') {
                    $found = db_all(
                        "SELECT numer_umowy, data_zawarcia, imie_nazwisko, pesel, adres{$extra}
                         FROM {$tbl} WHERE LOWER(email) = LOWER(?)
                         ORDER BY data_zawarcia DESC, id DESC",
                        [$email]
                    );
                } else {
                    $found = [];
                }
            } catch (\Throwable $e) {
                $found = [];
            }
            foreach ($found as $r) {
                $r['_typ'] = $typ;
                $rows[]    = $r;
            }
        }
        if (!$rows) return [];

        // globalne sortowanie po dacie zawarcia (malejąco) — najświeższa umowa wygrywa
        usort($rows, fn($a, $b) => strcmp(
            (string)($b['data_zawarcia'] ?? ''),
            (string)($a['data_zawarcia'] ?? '')
        ));

        $out = [];
        foreach (['imie_nazwisko', 'pesel', 'adres', 'telefon', 'email', 'data_urodzenia'] as $f) {
            foreach ($rows as $r) {
                $v = trim((string)($r[$f] ?? ''));
                if ($v !== '') {
                    $out[$f] = [
                        'value'  => $v,
                        'source' => trim((string)($r['numer_umowy'] ?? '')) . ' (' . $r['_typ'] . ')',
                    ];
                    break;
                }
            }
        }
        return $out;
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

    // ── Automatyczne sprawy CRM z umów ───────────────────────────────────────

    /**
     * Znajdź lub utwórz kontakt CRM na podstawie danych z wiersza umowy.
     */
    private static function _contractContact(array $row, string $type, int $user_id): ?int {
        $email = trim($row['email'] ?? '');
        $name  = trim($row['imie_nazwisko'] ?? $row['nazwa_wykonawcy'] ?? $row['strona_umowy'] ?? '');
        if (!$email && !$name) return null;

        $contact_id = null;
        if ($email) {
            $ex = db_one("SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1 LIMIT 1", [$email]);
            if ($ex) $contact_id = (int)$ex['id'];
        }
        if (!$contact_id && $name) {
            $ex = db_one("SELECT id FROM crm_contacts WHERE imie_nazwisko=? AND crm_active=1 ORDER BY id DESC LIMIT 1", [$name]);
            if ($ex) $contact_id = (int)$ex['id'];
        }
        if (!$contact_id) {
            $payload = [
                'type'          => 'osoba',
                'imie_nazwisko' => $name ?: $email,
                'email'         => $email ?: null,
                'status'        => 'aktywny',
                'source'        => $type . '_auto',
                'created_by'    => $user_id,
            ];
            foreach (['telefon', 'adres', 'pesel', 'data_urodzenia'] as $f) {
                if (!empty($row[$f])) $payload[$f] = $row[$f];
            }
            $contact_id = self::createContact($payload);
        }
        return $contact_id;
    }

    private static function _contractTypeLabel(string $type): string {
        return [
            'wolontariat' => 'Porozumienie wolontariackie',
            'zlecenie'    => 'Umowa zlecenie',
            'dzielo'      => 'Umowa o dzieło',
            'praca'       => 'Umowa o pracę',
            'uslugi'      => 'Umowa usługi',
            'inne'        => 'Umowa',
        ][$type] ?? 'Umowa';
    }

    /**
     * Tworzy sprawę CRM po zapisaniu nowej umowy.
     * Wywołać po db_insert() w kontrakcie; zawsze w try/catch.
     */
    public static function autoCreateContractCase(
        string $type,
        int    $contract_id,
        string $numer,
        array  $row,
        int    $user_id
    ): void {
        if (!module_enabled('crm_enabled')) return;

        // Deduplicacja — nie twórz jeśli sprawa dla tej umowy już istnieje
        if ($contract_id > 0) {
            $exists = db_one(
                "SELECT id FROM crm_cases WHERE contract_type=? AND contract_id=?",
                [$type, $contract_id]
            );
            if ($exists) return;
        }

        $contact_id = self::_contractContact($row, $type, $user_id);
        if (!$contact_id) return;

        $label = self::_contractTypeLabel($type);
        $title = $numer ? "{$label}: {$numer}" : $label;

        $parts = [];
        foreach (['przedmiot_zlecenia', 'przedmiot_porozumienia', 'przedmiot_uslugi', 'opis_dziela', 'przedmiot_umowy'] as $f) {
            if (!empty($row[$f])) { $parts[] = mb_substr($row[$f], 0, 200); break; }
        }
        if (!empty($row['data_zawarcia'])) $parts[] = 'Data zawarcia: ' . $row['data_zawarcia'];
        if (!empty($row['opiekun']))       $parts[] = 'Opiekun: ' . $row['opiekun'];

        $case_number = crm_next_case_number();

        $case_id = db_insert('crm_cases', [
            'contact_id'    => $contact_id,
            'title'         => $title,
            'description'   => $parts ? implode("\n", $parts) : null,
            'status'        => 'open',
            'priority'      => 'medium',
            'created_by'    => $user_id,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
            'case_number'   => $case_number,
            'contract_type' => $type,
            'contract_id'   => $contract_id > 0 ? $contract_id : null,
        ]);
        require_once __DIR__ . '/crm_automation.php';
        crm_automation_fire('case_created', $contact_id, ['case_id' => $case_id]);
    }

    /**
     * Tworzy sprawę CRM po złożeniu wniosku o aneks.
     * Ładuje dane umowy z bazy. Wywołać w submit_amendment(); zawsze w try/catch.
     */
    public static function autoCreateAmendmentCase(
        string $type,
        int    $contract_id,
        int    $nr,
        string $numer,
        string $opis,
        int    $user_id,
        int    $amendment_id = 0
    ): void {
        if (!module_enabled('crm_enabled')) return;

        // Deduplicacja — nie twórz jeśli sprawa dla tego aneksu już istnieje
        if ($amendment_id > 0) {
            $exists = db_one(
                "SELECT id FROM crm_cases WHERE contract_type='amendment' AND contract_id=?",
                [$amendment_id]
            );
            if ($exists) return;
        }

        $tbl_map = [
            'wolontariat' => 'umowy_wolontariat',
            'zlecenie'    => 'umowy_zlecenie',
            'dzielo'      => 'umowy_dzielo',
            'praca'       => 'umowy_praca',
            'uslugi'      => 'umowy_uslugi',
            'powierzenie' => 'umowy_powierzenie',
            'inne'        => 'umowy_inne',
        ];
        $tbl = $tbl_map[$type] ?? null;
        if (!$tbl) return;
        $contract = db_one("SELECT * FROM {$tbl} WHERE id=?", [$contract_id]);
        if (!$contract) return;

        $contact_id = self::_contractContact($contract, $type, $user_id);
        if (!$contact_id) return;

        $label = self::_contractTypeLabel($type);
        $title = "Aneks #{$nr} — {$label}: {$numer}";
        $short = mb_strlen($opis) > 200 ? mb_substr($opis, 0, 197) . '…' : $opis;

        $case_number = crm_next_case_number();

        $case_id = db_insert('crm_cases', [
            'contact_id'    => $contact_id,
            'title'         => $title,
            'description'   => $short ?: null,
            'status'        => 'open',
            'priority'      => 'medium',
            'created_by'    => $user_id,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
            'case_number'   => $case_number,
            'contract_type' => 'amendment',
            'contract_id'   => $amendment_id > 0 ? $amendment_id : null,
        ]);
        require_once __DIR__ . '/crm_automation.php';
        crm_automation_fire('case_created', $contact_id, ['case_id' => $case_id]);
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
        // Normalizuj visible_roles i edit_roles → JSON lub ''
        $norm_roles = function(mixed $v): string {
            if (is_array($v)) {
                $v = array_values(array_filter($v, fn($r) => is_string($r) && $r !== ''));
                return $v ? json_encode($v, JSON_UNESCAPED_UNICODE) : '';
            }
            if (is_string($v) && $v !== '') return $v; // już JSON
            return '';
        };
        $fields = [
            'label'        => trim($data['label'] ?? ''),
            'field_type'   => in_array($data['field_type'] ?? '', ['text','number','date','select','url','email','textarea','checkbox'], true)
                                ? $data['field_type'] : 'text',
            'options'      => trim($data['options'] ?? ''),
            'applies_to'   => in_array($data['applies_to'] ?? '', ['osoba','organizacja','both'], true)
                                ? $data['applies_to'] : 'both',
            'sort_order'   => (int)($data['sort_order'] ?? 0),
            'is_active'    => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            'group_id'     => isset($data['group_id']) && $data['group_id'] ? (int)$data['group_id'] : null,
            'visible_roles'=> $norm_roles($data['visible_roles'] ?? ''),
            'edit_roles'   => $norm_roles($data['edit_roles'] ?? ''),
        ];
        if ($id) {
            db()->prepare(
                "UPDATE crm_contact_field_defs SET label=?,field_type=?,options=?,applies_to=?,sort_order=?,is_active=?,group_id=?,visible_roles=?,edit_roles=? WHERE id=?"
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
