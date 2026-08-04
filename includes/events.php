<?php
/**
 * includes/events.php — Moduł Wydarzeń: funkcje pomocnicze
 *
 * Samonaprawa schematu: tabele modułu istniały dotąd tylko w
 * cli/migrations/migrate_events.php (osobny skrypt CLI, nie wpięty w żaden
 * automatyczny mechanizm) — moduł dało się włączyć (events_enabled) bez
 * uruchomienia migracji, co dawało "no such table: ev_registrations" przy
 * pierwszym użyciu (np. panel/index.php → "Moje wydarzenia"). Wzorzec jak
 * includes/zlecenie_schema.php: CREATE TABLE / ALTER TABLE w try/catch,
 * uruchamiane raz przy pierwszym require tego pliku.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $exec = function (string $sql) use ($pdo) {
        try { $pdo->exec($sql); } catch (\Throwable $e) { /* istnieje / niekrytyczne — ignorujemy */ }
    };

    $exec("CREATE TABLE IF NOT EXISTS ev_events (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        slug            TEXT    NOT NULL UNIQUE,
        title           TEXT    NOT NULL,
        description     TEXT,
        type            TEXT    NOT NULL DEFAULT 'stationary',
        status          TEXT    NOT NULL DEFAULT 'draft',
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
    )");

    $exec("CREATE TABLE IF NOT EXISTS ev_registrations (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id        INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
        crm_contact_id  INTEGER REFERENCES crm_contacts(id) ON DELETE SET NULL,
        first_name      TEXT    NOT NULL,
        last_name       TEXT    NOT NULL,
        email           TEXT    NOT NULL,
        phone           TEXT,
        ticket_code     TEXT    NOT NULL UNIQUE,
        status          TEXT    NOT NULL DEFAULT 'confirmed',
        checked_in_at   DATETIME,
        checked_in_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        reg_data        TEXT,
        source          TEXT    NOT NULL DEFAULT 'form',
        notes           TEXT,
        created_at      DATETIME DEFAULT (datetime('now','localtime')),
        updated_at      DATETIME DEFAULT (datetime('now','localtime'))
    )");

    $exec("CREATE TABLE IF NOT EXISTS ev_roles (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id    INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        role        TEXT    NOT NULL DEFAULT 'volunteer',
        added_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        added_at    DATETIME DEFAULT (datetime('now','localtime')),
        UNIQUE(event_id, user_id)
    )");

    $exec("CREATE TABLE IF NOT EXISTS ev_form_fields (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id    INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
        field_key   TEXT    NOT NULL,
        label       TEXT    NOT NULL,
        type        TEXT    NOT NULL DEFAULT 'text',
        options     TEXT,
        placeholder TEXT,
        is_required INTEGER NOT NULL DEFAULT 0,
        position    INTEGER NOT NULL DEFAULT 0,
        UNIQUE(event_id, field_key)
    )");

    $exec("CREATE TABLE IF NOT EXISTS ev_checkin_tokens (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id    INTEGER NOT NULL REFERENCES ev_events(id) ON DELETE CASCADE,
        token       TEXT    NOT NULL UNIQUE,
        expires_at  DATETIME,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT (datetime('now','localtime'))
    )");

    // Kolumny dodane po pierwszym wdrożeniu
    $exec("ALTER TABLE ev_events ADD COLUMN rodo_clause       TEXT");
    $exec("ALTER TABLE ev_events ADD COLUMN notify_new_reg    INTEGER NOT NULL DEFAULT 1");
    $exec("ALTER TABLE ev_events ADD COLUMN notify_email      TEXT");
    $exec("ALTER TABLE ev_events ADD COLUMN crm_auto_sync     INTEGER NOT NULL DEFAULT 1");
    $exec("ALTER TABLE ev_events ADD COLUMN reg_always_open   INTEGER NOT NULL DEFAULT 0");

    // Indeksy
    $exec("CREATE INDEX IF NOT EXISTS idx_ev_reg_event  ON ev_registrations(event_id)");
    $exec("CREATE INDEX IF NOT EXISTS idx_ev_reg_email  ON ev_registrations(email)");
    $exec("CREATE INDEX IF NOT EXISTS idx_ev_reg_ticket ON ev_registrations(ticket_code)");
    $exec("CREATE INDEX IF NOT EXISTS idx_ev_roles_user ON ev_roles(user_id)");
    $exec("CREATE INDEX IF NOT EXISTS idx_ev_fields_ev  ON ev_form_fields(event_id, position)");
})();

// ── Generowanie unikalnego kodu biletu ────────────────────────────────────

function ev_ticket_code(): string {
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    } while (db_one("SELECT id FROM ev_registrations WHERE ticket_code=?", [$code]));
    return $code;
}

// ── Role i dostęp ─────────────────────────────────────────────────────────

/**
 * Zwraca rolę usera w kontekście danego wydarzenia.
 * System-admin → 'admin' zawsze.
 * Szuka też check-in tokenów w sesji.
 */
function ev_role(int $event_id, ?int $user_id = null): ?string {
    if (!$user_id) {
        $u = current_user();
        $user_id = (int)($u['id'] ?? 0);
    }
    if (!$user_id) {
        // Token check-in (bez logowania)
        auth_start();
        $tok = $_SESSION['ev_checkin_token'] ?? null;
        if ($tok) {
            $t = db_one(
                "SELECT event_id FROM ev_checkin_tokens
                 WHERE token=? AND event_id=? AND (expires_at IS NULL OR expires_at > datetime('now','localtime'))",
                [$tok, $event_id]
            );
            if ($t) return 'checkin';
        }
        return null;
    }

    // System admin → pełny dostęp
    $user = db_one("SELECT role FROM users WHERE id=?", [$user_id]);
    if (($user['role'] ?? '') === 'admin') return 'admin';

    $m = db_one(
        "SELECT role FROM ev_roles WHERE event_id=? AND user_id=?",
        [$event_id, $user_id]
    );
    if ($m) return $m['role'];

    // Twórca wydarzenia → automatycznie admin
    $ev = db_one("SELECT created_by FROM ev_events WHERE id=?", [$event_id]);
    if ($ev && (int)$ev['created_by'] === $user_id) {
        try {
            db()->prepare(
                "INSERT OR IGNORE INTO ev_roles (event_id, user_id, role, added_by, added_at)
                 VALUES (?,?,'admin',?,datetime('now','localtime'))"
            )->execute([$event_id, $user_id, $user_id]);
        } catch (\Throwable $e) {}
        return 'admin';
    }

    return null;
}

function ev_require_role(int $event_id, array $roles = ['admin']): void {
    require_login();
    $role = ev_role($event_id);
    if (!$role || !in_array($role, $roles, true)) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Brak dostępu.']);
            exit;
        }
        flash_set('error', 'Brak dostępu do tego wydarzenia.');
        header('Location: ' . APP_URL . '/events/index.php'); exit;
    }
}

function ev_can(int $event_id, string ...$roles): bool {
    $uid  = (int)(current_user()['id'] ?? 0);
    $role = ev_role($event_id, $uid);
    return $role !== null && in_array($role, $roles, true);
}

// ── Stany i etykiety ───────────────────────────────────────────────────────

const EV_STATUS = [
    'draft'     => ['label' => 'Szkic',      'class' => 'secondary'],
    'published' => ['label' => 'Aktywne',    'class' => 'success'],
    'cancelled' => ['label' => 'Odwołane',   'class' => 'danger'],
    'archived'  => ['label' => 'Archiwum',   'class' => 'dark'],
];

const EV_TYPE = [
    'webinar'    => ['label' => 'Webinar',     'icon' => 'bi-camera-video'],
    'stationary' => ['label' => 'Stacjonarne', 'icon' => 'bi-geo-alt'],
];

const EV_REG_STATUS = [
    'confirmed' => ['label' => 'Potwierdzona', 'class' => 'success'],
    'cancelled' => ['label' => 'Anulowana',    'class' => 'danger'],
    'waitlist'  => ['label' => 'Lista oczek.', 'class' => 'warning'],
];

function ev_status_badge(string $status): string {
    $d = EV_STATUS[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $d['class'] . '">' . h($d['label']) . '</span>';
}

// ── Liczniki ───────────────────────────────────────────────────────────────

function ev_reg_count(int $event_id): int {
    return (int)(db_one(
        "SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND status='confirmed'",
        [$event_id]
    )['n'] ?? 0);
}

function ev_checked_in_count(int $event_id): int {
    return (int)(db_one(
        "SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND checked_in_at IS NOT NULL",
        [$event_id]
    )['n'] ?? 0);
}

// ── Dostępne dla usera wydarzenia ─────────────────────────────────────────

function ev_user_events(?int $user_id = null): array {
    if (!$user_id) $user_id = (int)(current_user()['id'] ?? 0);
    $u = db_one("SELECT role FROM users WHERE id=?", [$user_id]);
    if (($u['role'] ?? '') === 'admin') {
        return db_all(
            "SELECT e.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM ev_registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS reg_count,
                    (SELECT COUNT(*) FROM ev_registrations r WHERE r.event_id=e.id AND r.checked_in_at IS NOT NULL) AS checkin_count
             FROM ev_events e LEFT JOIN users u ON u.id=e.created_by
             ORDER BY e.start_at DESC"
        );
    }
    return db_all(
        "SELECT e.*, u.name AS creator_name, er.role AS my_role,
                (SELECT COUNT(*) FROM ev_registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS reg_count,
                (SELECT COUNT(*) FROM ev_registrations r WHERE r.event_id=e.id AND r.checked_in_at IS NOT NULL) AS checkin_count
         FROM ev_roles er
         JOIN ev_events e ON e.id = er.event_id
         LEFT JOIN users u ON u.id = e.created_by
         WHERE er.user_id = ?
         ORDER BY e.start_at DESC",
        [$user_id]
    );
}

// ── CRM sync ───────────────────────────────────────────────────────────────

/**
 * Synchronizuje uczestnika rejestracji z CRM.
 * 1. Szuka kontaktu po emailu
 * 2. Tworzy jeśli nie istnieje
 * 3. Dodaje do grupy CRM powiązanej z wydarzeniem
 * Zwraca crm_contact_id lub null przy błędzie.
 */
function ev_crm_sync(array $reg, int $event_id): ?int {
    try {
        $email = trim($reg['email'] ?? '');
        if (!$email) return null;

        $uid = (int)(db_one("SELECT created_by FROM ev_events WHERE id=?", [$event_id])['created_by'] ?? 1);

        // Szukaj istniejącego kontaktu
        $contact = db_one(
            "SELECT id FROM crm_contacts WHERE email=? AND crm_active=1 LIMIT 1",
            [$email]
        );

        $contact_id = null;

        if ($contact) {
            $contact_id = (int)$contact['id'];
        } else {
            // Utwórz nowy kontakt CRM
            $contact_id = db_insert('crm_contacts', [
                'type'           => 'osoba',
                'status'         => 'prospect',
                'imie_nazwisko'  => trim(($reg['first_name'] ?? '') . ' ' . ($reg['last_name'] ?? '')),
                'imie'           => $reg['first_name'] ?? '',
                'nazwisko'       => $reg['last_name']  ?? '',
                'email'          => $email,
                'telefon'        => $reg['phone'] ?? null,
                'source'         => 'event',
                'crm_active'     => 1,
                'created_by'     => $uid,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
            if (!function_exists('crm_automation_fire')) require_once __DIR__ . '/crm_automation.php';
            crm_automation_fire('contact_created', $contact_id);
        }

        // Dodaj do grupy CRM powiązanej z wydarzeniem
        $ev = db_one("SELECT crm_group_id, title FROM ev_events WHERE id=?", [$event_id]);
        if ($ev) {
            $group_id = $ev['crm_group_id'];

            // Utwórz grupę jeśli nie istnieje
            if (!$group_id) {
                $group_id = db_insert('crm_groups', [
                    'name'       => 'Wydarzenie: ' . $ev['title'],
                    'description'=> 'Auto-utworzona przez moduł wydarzeń',
                    'color'      => '#7c3aed',
                    'icon'       => 'bi-calendar-event',
                    'auto_source'=> 'event_' . $event_id,
                    'created_by' => $uid,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                db()->prepare("UPDATE ev_events SET crm_group_id=? WHERE id=?")
                    ->execute([$group_id, $event_id]);
            }

            try {
                db()->prepare(
                    "INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at)
                     VALUES (?,?,?,datetime('now','localtime'))"
                )->execute([$group_id, $contact_id, $uid]);
            } catch (\Throwable $e) {}

            // Tag na kontakcie
            try {
                $ev_full = db_one("SELECT slug FROM ev_events WHERE id=?", [$event_id]);
                db()->prepare(
                    "INSERT OR IGNORE INTO crm_tags (contact_id, tag, created_at)
                     VALUES (?, ?, datetime('now','localtime'))"
                )->execute([$contact_id, 'event:' . ($ev_full['slug'] ?? $event_id)]);
            } catch (\Throwable $e) {}
        }

        return $contact_id;

    } catch (\Throwable $e) {
        error_log('[ev_crm_sync] ' . $e->getMessage());
        return null;
    }
}

// ── Power Automate webhook ─────────────────────────────────────────────────

function ev_pa_notify(int $event_id, array $reg_data): void {
    try {
        $ev = db_one("SELECT pa_webhook_url, title FROM ev_events WHERE id=?", [$event_id]);
        if (!$ev || !$ev['pa_webhook_url']) return;

        $payload = json_encode([
            'event_id'    => $event_id,
            'event_title' => $ev['title'],
            'ticket_code' => $reg_data['ticket_code'] ?? '',
            'first_name'  => $reg_data['first_name']  ?? '',
            'last_name'   => $reg_data['last_name']   ?? '',
            'email'       => $reg_data['email']       ?? '',
            'phone'       => $reg_data['phone']       ?? '',
            'registered_at'=> date('c'),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($ev['pa_webhook_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        error_log('[ev_pa_notify] ' . $e->getMessage());
    }
}

// ── CSRF helper dla API ────────────────────────────────────────────────────

function ev_api_error(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ev_api_ok(mixed $data = null): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function ev_csrf_check(): void {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $body['_csrf'] ?? $_POST['_csrf'] ?? '';
    auth_start();
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        ev_api_error('Nieprawidłowy token CSRF.', 403);
    }
}

function ev_parse_json(): array {
    $b = json_decode(file_get_contents('php://input'), true);
    return is_array($b) ? $b : [];
}

// ── API / embed — serializacja ─────────────────────────────────────────────

function ev_register_url(string $slug): string {
    return rtrim(APP_URL, '/') . '/events/public/register.php?slug=' . urlencode($slug);
}

function ev_spots_left(array $ev, ?int $reg_count = null): ?int {
    if (empty($ev['capacity'])) return null;
    if ($reg_count === null) $reg_count = ev_reg_count((int)$ev['id']);
    return max(0, (int)$ev['capacity'] - $reg_count);
}

/**
 * Publiczna (bezpieczna) reprezentacja wydarzenia — do feedu / osadzania.
 * Nigdy nie zawiera meeting_url ani danych uczestników.
 */
function ev_public_event(array $r): array {
    $type_info = EV_TYPE[$r['type']] ?? ['label' => $r['type'], 'icon' => 'bi-calendar'];
    $reg_count = ev_reg_count((int)$r['id']);
    return [
        'id'          => (int)$r['id'],
        'slug'        => $r['slug'],
        'title'       => $r['title'],
        'description' => $r['description'] ? mb_substr(trim(strip_tags($r['description'])), 0, 300) : null,
        'type'        => $r['type'],
        'type_label'  => $type_info['label'],
        'start_at'    => $r['start_at'],
        'end_at'      => $r['end_at'],
        'venue'       => $r['type'] === 'stationary' ? ($r['venue'] ?: null) : null,
        'cover_image' => $r['cover_image']
            ? (preg_match('#^https?://#', $r['cover_image']) ? $r['cover_image'] : rtrim(APP_URL, '/') . '/' . ltrim($r['cover_image'], '/'))
            : null,
        'capacity'    => $r['capacity'] !== null && $r['capacity'] !== '' ? (int)$r['capacity'] : null,
        'spots_left'  => ev_spots_left($r, $reg_count),
        'register_url'=> ev_register_url($r['slug']),
    ];
}

/** Pełna reprezentacja wydarzenia dla API wewnętrznego (Bearer, events:read/write). */
function ev_api_event(array $r): array {
    $type_info   = EV_TYPE[$r['type']] ?? ['label' => $r['type'], 'icon' => 'bi-calendar'];
    $status_info = EV_STATUS[$r['status']] ?? ['label' => $r['status'], 'class' => 'secondary'];
    $reg_count   = ev_reg_count((int)$r['id']);
    return [
        'id'           => (int)$r['id'],
        'slug'         => $r['slug'],
        'title'        => $r['title'],
        'description'  => $r['description'],
        'type'         => $r['type'],
        'type_label'   => $type_info['label'],
        'status'       => $r['status'],
        'status_label' => $status_info['label'],
        'venue'        => $r['venue'],
        'address'      => $r['address'],
        'meeting_url'  => $r['meeting_url'],
        'start_at'     => $r['start_at'],
        'end_at'       => $r['end_at'],
        'capacity'     => $r['capacity'] !== null && $r['capacity'] !== '' ? (int)$r['capacity'] : null,
        'reg_count'    => $reg_count,
        'spots_left'   => ev_spots_left($r, $reg_count),
        'is_public'    => (bool)$r['is_public'],
        'reg_open_at'     => $r['reg_open_at'],
        'reg_close_at'    => $r['reg_close_at'],
        'reg_always_open' => (bool)($r['reg_always_open'] ?? false),
        'cover_image'  => $r['cover_image'],
        'register_url' => ev_register_url($r['slug']),
        'created_at'   => $r['created_at'],
        'updated_at'   => $r['updated_at'],
    ];
}

/** Rejestracja — reprezentacja dla API wewnętrznego. */
function ev_api_registration(array $r): array {
    return [
        'id'            => (int)$r['id'],
        'event_id'      => (int)$r['event_id'],
        'first_name'    => $r['first_name'],
        'last_name'     => $r['last_name'],
        'email'         => $r['email'],
        'phone'         => $r['phone'],
        'ticket_code'   => $r['ticket_code'],
        'status'        => $r['status'],
        'checked_in_at' => $r['checked_in_at'],
        'reg_data'      => $r['reg_data'] ? json_decode($r['reg_data'], true) : null,
        'source'        => $r['source'],
        'notes'         => $r['notes'],
        'created_at'    => $r['created_at'],
        'updated_at'    => $r['updated_at'],
    ];
}
