<?php
/**
 * modules/srs/logic/srs.php — System Rezerwacji Sal (SRS): logika modułu rezerwacji zasobów organizacji.
 *
 * Tabele:
 *   resource_categories       — kategorie zasobów
 *   resources                 — zasoby (sala, komputer, router, …)
 *   resource_field_defs       — dodatkowe pola definicji per zasób/kategoria
 *   resource_reservations     — wnioski rezerwacji
 *   resource_reservation_log  — historia zmian statusu rezerwacji
 *
 * Przepływ zatwierdzania (requires_approval=1):
 *   złożony → pending_admin → pending_dysponent → [odmowa|oczekuje_dokumenty|zgoda|rezerwacja]
 * Bez zatwierdzania:
 *   złożony → rezerwacja
 */

// ── Stałe statusów ────────────────────────────────────────────────────────────
const RES_STATUSES = [
    'zlozony'            => ['label' => 'Złożony',               'color' => '#6366f1', 'bg' => '#EEF2FF'],
    'pending_admin'      => ['label' => 'Oczekuje na admina',    'color' => '#f59e0b', 'bg' => '#FFFBEB'],
    'pending_dysponent'  => ['label' => 'U dysponenta',          'color' => '#0ea5e9', 'bg' => '#F0F9FF'],
    'oczekuje_dokumenty' => ['label' => 'Oczekuje na dokumenty', 'color' => '#8b5cf6', 'bg' => '#F5F3FF'],
    'zgoda'              => ['label' => 'Zaakceptowana',         'color' => '#22c55e', 'bg' => '#F0FDF4'],
    'rezerwacja'         => ['label' => 'Rezerwacja',            'color' => '#16a34a', 'bg' => '#DCFCE7'],
    'odmowa'             => ['label' => 'Odmowa',                'color' => '#ef4444', 'bg' => '#FEF2F2'],
    'anulowana'          => ['label' => 'Anulowana',             'color' => '#9ca3af', 'bg' => '#F9FAFB'],
];

// ── Migracja (idempotentna) ───────────────────────────────────────────────────
function resources_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS resource_categories (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        icon        TEXT    NOT NULL DEFAULT 'bi-box',
        color       TEXT    NOT NULL DEFAULT '#6366f1',
        sort_order  INTEGER NOT NULL DEFAULT 0,
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS resources (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id      INTEGER REFERENCES resource_categories(id) ON DELETE SET NULL,
        name             TEXT    NOT NULL,
        description      TEXT    NOT NULL DEFAULT '',
        location         TEXT    NOT NULL DEFAULT '',
        capacity         INTEGER,
        requires_approval INTEGER NOT NULL DEFAULT 0,
        dysponent_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        is_active        INTEGER NOT NULL DEFAULT 1,
        sort_order       INTEGER NOT NULL DEFAULT 0,
        created_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS resource_field_defs (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        resource_id  INTEGER REFERENCES resources(id) ON DELETE CASCADE,
        category_id  INTEGER REFERENCES resource_categories(id) ON DELETE CASCADE,
        label        TEXT    NOT NULL,
        field_type   TEXT    NOT NULL DEFAULT 'text',
        options      TEXT    NOT NULL DEFAULT '',
        is_required  INTEGER NOT NULL DEFAULT 0,
        sort_order   INTEGER NOT NULL DEFAULT 0,
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS resource_reservations (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        resource_id      INTEGER NOT NULL REFERENCES resources(id) ON DELETE CASCADE,
        user_id          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date_from        DATE    NOT NULL,
        date_to          DATE    NOT NULL,
        time_from        TEXT    NOT NULL DEFAULT '',
        time_to          TEXT    NOT NULL DEFAULT '',
        purpose          TEXT    NOT NULL DEFAULT '',
        extra_fields     TEXT    NOT NULL DEFAULT '{}',
        status           TEXT    NOT NULL DEFAULT 'zlozony',
        admin_note       TEXT    NOT NULL DEFAULT '',
        dysponent_note   TEXT    NOT NULL DEFAULT '',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS resource_reservation_log (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        reservation_id INTEGER NOT NULL REFERENCES resource_reservations(id) ON DELETE CASCADE,
        user_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        old_status     TEXT    NOT NULL DEFAULT '',
        new_status     TEXT    NOT NULL DEFAULT '',
        note           TEXT    NOT NULL DEFAULT '',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_res_reserv_resource ON resource_reservations(resource_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_res_reserv_user    ON resource_reservations(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_res_reserv_status  ON resource_reservations(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_res_reserv_dates   ON resource_reservations(date_from,date_to)");

    // Godziny dostępności zasobu per dzień tygodnia
    // day_of_week: 1=Pn, 2=Wt, 3=Śr, 4=Czw, 5=Pt, 6=Sob, 0=Nd
    $pdo->exec("CREATE TABLE IF NOT EXISTS resource_availability (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        resource_id INTEGER NOT NULL REFERENCES resources(id) ON DELETE CASCADE,
        day_of_week INTEGER NOT NULL,
        time_open   TEXT    NOT NULL DEFAULT '08:00',
        time_close  TEXT    NOT NULL DEFAULT '16:00',
        is_closed   INTEGER NOT NULL DEFAULT 0,
        UNIQUE(resource_id, day_of_week)
    )");

    // Idempotentne migracje na istniejących tabelach
    foreach ([
        "ALTER TABLE resources ADD COLUMN k30_enabled INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE resources ADD COLUMN operator_label TEXT NOT NULL DEFAULT ''",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // Domyślne kategorie (jeśli puste)
    $count = (int)$pdo->query("SELECT COUNT(*) FROM resource_categories")->fetchColumn();
    if ($count === 0) {
        $ins = $pdo->prepare("INSERT INTO resource_categories (name, icon, color, sort_order) VALUES (?,?,?,?)");
        $ins->execute(['Sala',     'bi-door-open-fill',    '#6366f1', 1]);
        $ins->execute(['Komputer', 'bi-pc-display',        '#0ea5e9', 2]);
        $ins->execute(['Router',   'bi-router-fill',       '#f59e0b', 3]);
        $ins->execute(['Inne',     'bi-box-seam-fill',     '#6b7280', 4]);
    }
}

// Nazwy dni tygodnia (1=Pn…6=Sob, 0=Nd)
const RES_DAYS = [1=>'Poniedziałek',2=>'Wtorek',3=>'Środa',4=>'Czwartek',5=>'Piątek',6=>'Sobota',0=>'Niedziela'];

// ── Kategorie ─────────────────────────────────────────────────────────────────
function res_categories(bool $active_only = true): array {
    $w = $active_only ? 'WHERE is_active=1' : '';
    return db_all("SELECT * FROM resource_categories $w ORDER BY sort_order, name");
}

// ── Zasoby ────────────────────────────────────────────────────────────────────
function res_list(int $cat_id = 0, bool $active_only = true): array {
    $where = $active_only ? ['r.is_active=1'] : [];
    $params = [];
    if ($cat_id) { $where[] = 'r.category_id=?'; $params[] = $cat_id; }
    $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return db_all(
        "SELECT r.*, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color,
                u.name AS dysponent_name
         FROM resources r
         LEFT JOIN resource_categories c ON c.id=r.category_id
         LEFT JOIN users u ON u.id=r.dysponent_user_id
         $sql_where ORDER BY r.sort_order, r.name",
        $params
    );
}

function res_get(int $id): ?array {
    return db_one(
        "SELECT r.*, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color,
                u.name AS dysponent_name, u.email AS dysponent_email
         FROM resources r
         LEFT JOIN resource_categories c ON c.id=r.category_id
         LEFT JOIN users u ON u.id=r.dysponent_user_id
         WHERE r.id=?", [$id]
    ) ?: null;
}

function res_save(array $data, ?int $id = null): int {
    $fields = ['category_id','name','description','location','operator_label','capacity',
               'requires_approval','dysponent_user_id','is_active','k30_enabled','sort_order'];
    $data['updated_at'] = date('Y-m-d H:i:s');
    if ($id) {
        $set = []; $params = [];
        foreach (array_merge($fields, ['updated_at']) as $f) {
            if (array_key_exists($f, $data)) { $set[] = "$f=?"; $params[] = $data[$f]; }
        }
        $params[] = $id;
        db()->prepare("UPDATE resources SET " . implode(',', $set) . " WHERE id=?")->execute($params);
        return $id;
    }
    $data['created_by'] = (int)(current_user()['id'] ?? 0);
    $data['created_at'] = date('Y-m-d H:i:s');
    return db_insert('resources', array_intersect_key($data, array_flip(
        array_merge($fields, ['created_by','created_at','updated_at'])
    )));
}

function res_delete(int $id): void {
    db()->prepare("DELETE FROM resources WHERE id=?")->execute([$id]);
}

// ── Godziny dostępności ───────────────────────────────────────────────────────

/**
 * Zwraca godziny dostępności zasobu — tablica indeksowana dniem tygodnia.
 * Brakujące dni = brak ograniczeń (otwarty przez cały dzień).
 */
function res_availability(int $resource_id): array {
    $rows = db_all("SELECT * FROM resource_availability WHERE resource_id=?", [$resource_id]);
    $map  = [];
    foreach ($rows as $r) $map[(int)$r['day_of_week']] = $r;
    return $map;
}

/** Zapisuje/aktualizuje godziny dla jednego dnia. */
function res_availability_save(int $resource_id, int $day, string $open, string $close, bool $closed): void {
    try {
        db()->prepare(
            "INSERT INTO resource_availability (resource_id, day_of_week, time_open, time_close, is_closed)
             VALUES (?,?,?,?,?)
             ON CONFLICT(resource_id, day_of_week) DO UPDATE
             SET time_open=excluded.time_open, time_close=excluded.time_close, is_closed=excluded.is_closed"
        )->execute([$resource_id, $day, $open, $close, $closed ? 1 : 0]);
    } catch (\Throwable $e) {
        // Fallback starszy SQLite
        $ex = db_one("SELECT id FROM resource_availability WHERE resource_id=? AND day_of_week=?", [$resource_id, $day]);
        if ($ex) {
            db()->prepare("UPDATE resource_availability SET time_open=?,time_close=?,is_closed=? WHERE resource_id=? AND day_of_week=?")
               ->execute([$open, $close, $closed?1:0, $resource_id, $day]);
        } else {
            db_insert('resource_availability', ['resource_id'=>$resource_id,'day_of_week'=>$day,'time_open'=>$open,'time_close'=>$close,'is_closed'=>$closed?1:0]);
        }
    }
}

/**
 * Sprawdza czy zasób jest dostępny w danym dniu i godzinie.
 * Jeśli brak rekordu availability → bez ograniczeń → dostępny.
 */
function res_is_available_at(int $resource_id, string $date, string $time_from = '', string $time_to = ''): bool {
    $dow = (int)date('N', strtotime($date)); // 1=Pn…7=Nd, mapujemy 7→0
    if ($dow === 7) $dow = 0;
    $avail = res_availability($resource_id);
    if (!isset($avail[$dow])) return true; // brak reguły = otwarty
    $a = $avail[$dow];
    if ($a['is_closed']) return false;
    if ($time_from && $time_to) {
        if ($time_from < $a['time_open'] || $time_to > $a['time_close']) return false;
    }
    return true;
}

/**
 * Zasoby dostępne dla Dydaktyka 3 — aktywne, z flagą k30_enabled=1.
 * Opcjonalnie filtrowane po dacie/godzinach.
 */
function res_k30_available(string $date = '', string $time_from = '', string $time_to = ''): array {
    $rows = db_all(
        "SELECT r.*, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color
         FROM resources r
         LEFT JOIN resource_categories c ON c.id=r.category_id
         WHERE r.is_active=1 AND r.k30_enabled=1
         ORDER BY r.sort_order, r.name"
    );
    if (!$date) return $rows;
    return array_values(array_filter($rows, fn($r) => res_is_available_at((int)$r['id'], $date, $time_from, $time_to)));
}

// ── Pola dodatkowe zasobu ─────────────────────────────────────────────────────
function res_field_defs(int $resource_id): array {
    $r = res_get($resource_id);
    // Pola przypisane do konkretnego zasobu LUB do jego kategorii
    $cat_id = (int)($r['category_id'] ?? 0);
    $params = [$resource_id];
    $cat_cond = $cat_id ? "OR (category_id=? AND resource_id IS NULL)" : "";
    if ($cat_id) $params[] = $cat_id;
    return db_all(
        "SELECT * FROM resource_field_defs
         WHERE is_active=1 AND (resource_id=? $cat_cond)
         ORDER BY sort_order, id",
        $params
    );
}

// ── Rezerwacje ────────────────────────────────────────────────────────────────
function res_reserve(array $data): int {
    $resource = res_get((int)$data['resource_id']);
    $needs_approval = $resource && $resource['requires_approval'];

    $status = $needs_approval ? 'pending_admin' : 'rezerwacja';
    $id = db_insert('resource_reservations', [
        'resource_id'  => (int)$data['resource_id'],
        'user_id'      => (int)(current_user()['id'] ?? 0),
        'date_from'    => $data['date_from'],
        'date_to'      => $data['date_to'],
        'time_from'    => $data['time_from'] ?? '',
        'time_to'      => $data['time_to']   ?? '',
        'purpose'      => trim($data['purpose'] ?? ''),
        'extra_fields' => json_encode($data['extra_fields'] ?? [], JSON_UNESCAPED_UNICODE),
        'status'       => $status,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    res_log($id, 0, '', $status, 'Wniosek złożony');
    res_notify_event($id, $status);
    return $id;
}

function res_reservation_get(int $id): ?array {
    return db_one(
        "SELECT rr.*, r.name AS res_name, r.requires_approval, r.dysponent_user_id,
                r.category_id, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color,
                u.name AS user_name, u.email AS user_email,
                d.name AS dysponent_name, d.email AS dysponent_email
         FROM resource_reservations rr
         JOIN resources r ON r.id=rr.resource_id
         LEFT JOIN resource_categories c ON c.id=r.category_id
         JOIN users u ON u.id=rr.user_id
         LEFT JOIN users d ON d.id=r.dysponent_user_id
         WHERE rr.id=?", [$id]
    ) ?: null;
}

function res_reservations_for_user(int $user_id): array {
    return db_all(
        "SELECT rr.*, r.name AS res_name, c.icon AS cat_icon, c.color AS cat_color
         FROM resource_reservations rr
         JOIN resources r ON r.id=rr.resource_id
         LEFT JOIN resource_categories c ON c.id=r.category_id
         WHERE rr.user_id=?
         ORDER BY rr.created_at DESC",
        [$user_id]
    );
}

function res_reservations_pending_admin(): array {
    return db_all(
        "SELECT rr.*, r.name AS res_name, c.icon AS cat_icon, c.color AS cat_color,
                u.name AS user_name, u.email AS user_email
         FROM resource_reservations rr
         JOIN resources r ON r.id=rr.resource_id
         LEFT JOIN resource_categories c ON c.id=r.category_id
         JOIN users u ON u.id=rr.user_id
         WHERE rr.status IN ('zlozony','pending_admin')
         ORDER BY rr.created_at ASC"
    );
}

function res_reservations_pending_dysponent(int $dysponent_user_id): array {
    return db_all(
        "SELECT rr.*, r.name AS res_name, c.icon AS cat_icon, c.color AS cat_color,
                u.name AS user_name, u.email AS user_email
         FROM resource_reservations rr
         JOIN resources r ON r.id=rr.resource_id
         LEFT JOIN resource_categories c ON c.id=r.category_id
         JOIN users u ON u.id=rr.user_id
         WHERE rr.status='pending_dysponent' AND r.dysponent_user_id=?
         ORDER BY rr.created_at ASC",
        [$dysponent_user_id]
    );
}

function res_all_reservations(string $status = '', string $date_from = '', string $date_to = ''): array {
    $where = []; $params = [];
    if ($status) { $where[] = 'rr.status=?'; $params[] = $status; }
    if ($date_from) { $where[] = 'rr.date_from>=?'; $params[] = $date_from; }
    if ($date_to)   { $where[] = 'rr.date_to<=?';   $params[] = $date_to; }
    $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return db_all(
        "SELECT rr.*, r.name AS res_name, c.icon AS cat_icon, c.color AS cat_color,
                u.name AS user_name
         FROM resource_reservations rr
         JOIN resources r ON r.id=rr.resource_id
         LEFT JOIN resource_categories c ON c.id=r.category_id
         JOIN users u ON u.id=rr.user_id
         $sql_where ORDER BY rr.date_from DESC, rr.created_at DESC",
        $params
    );
}

function res_change_status(int $reservation_id, string $new_status, string $note = ''): void {
    $old = db_one("SELECT status FROM resource_reservations WHERE id=?", [$reservation_id]);
    $old_status = $old['status'] ?? '';
    db()->prepare(
        "UPDATE resource_reservations SET status=?, updated_at=datetime('now') WHERE id=?"
    )->execute([$new_status, $reservation_id]);
    res_log($reservation_id, (int)(current_user()['id'] ?? 0), $old_status, $new_status, $note);
    res_notify_event($reservation_id, $new_status, $note);
}

function res_log(int $reservation_id, int $user_id, string $old, string $new, string $note): void {
    db_insert('resource_reservation_log', [
        'reservation_id' => $reservation_id,
        'user_id'        => $user_id ?: null,
        'old_status'     => $old,
        'new_status'     => $new,
        'note'           => $note,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

function res_log_for(int $reservation_id): array {
    return db_all(
        "SELECT l.*, u.name AS user_name FROM resource_reservation_log l
         LEFT JOIN users u ON u.id=l.user_id
         WHERE l.reservation_id=? ORDER BY l.created_at ASC",
        [$reservation_id]
    );
}

// Konflikty dat dla zasobu
function res_conflicts(int $resource_id, string $date_from, string $date_to, int $exclude_id = 0): array {
    $params = [$resource_id, $date_to, $date_from];
    $excl = $exclude_id ? "AND rr.id!=?" : '';
    if ($exclude_id) $params[] = $exclude_id;
    $conflicts = db_all(
        "SELECT rr.*, u.name AS user_name FROM resource_reservations rr
         JOIN users u ON u.id=rr.user_id
         WHERE rr.resource_id=? AND rr.status NOT IN ('odmowa','anulowana')
         AND rr.date_from<=? AND rr.date_to>=? $excl",
        $params
    );

    // Kolizja z terminami Dydaktyki 3 (k30_schedules) na tym samym zasobie —
    // zasób współdzielony (k30_enabled=1) ma dwa niezależne rejestry rezerwacji,
    // więc SRS musi widzieć też zajętości z drugiej strony (i odwrotnie —
    // patrz karty30/schedules/add.php). Precyzja dnia (bez godzin), tak jak
    // reszta res_conflicts() — rezerwacja SRS blokuje cały zakres dat.
    if (function_exists('db_all') && db_one("SELECT name FROM sqlite_master WHERE type='table' AND name='k30_schedules'")) {
        $k30_rows = db_all(
            "SELECT s.id, DATE(s.start_time) AS lesson_date, TIME(s.start_time) AS t_start,
                    TIME(s.start_time, '+' || s.duration_minutes || ' minutes') AS t_end,
                    c.name AS user_name
             FROM k30_schedules s
             LEFT JOIN k30_clients c ON c.id = s.client_id
             WHERE s.resource_id=? AND s.status NOT IN ('cancelled','rejected')
             AND DATE(s.start_time) BETWEEN ? AND ?",
            [$resource_id, $date_from, $date_to]
        );
        foreach ($k30_rows as $k) {
            $conflicts[] = [
                'id'         => $k['id'],
                'date_from'  => $k['lesson_date'],
                'date_to'    => $k['lesson_date'],
                'time_from'  => $k['t_start'],
                'time_to'    => $k['t_end'],
                'user_name'  => ($k['user_name'] ?? '?') . ' (Dydaktyka 3)',
                'source'     => 'dydaktyka3',
            ];
        }
    }

    return $conflicts;
}

/**
 * Znacznik statusu — pigułka w języku wizualnym modułu „Tożsamość".
 *
 * Kształt jest w inline-style (a nie w klasie `.res-status-badge`), bo funkcja
 * bywa wołana ze stron, które tej klasy nie definiują (np. sekcja rezerwacji
 * w panelu wolontariusza) — wcześniej znacznik renderował się tam jako goły tekst.
 */
function res_status_badge(string $status): string {
    $s = RES_STATUSES[$status] ?? ['label' => $status, 'color' => '#4b5563', 'bg' => '#f3f4f6'];
    return '<span class="res-status-badge" style="display:inline-flex;align-items:center;gap:.3rem;'
        . 'padding:.2rem .6rem;border-radius:999px;font-size:.74rem;font-weight:600;white-space:nowrap;'
        . 'background:' . h($s['bg']) . ';color:' . h($s['color']) . ';border:1px solid ' . h($s['color']) . '55">'
        . h($s['label']) . '</span>';
}

/** Czy użytkownik jest dysponentem choćby jednego zasobu (dostęp do panelu decyzji). */
function res_is_dysponent(int $user_id): bool {
    if ($user_id <= 0) return false;
    return (bool)db_one("SELECT 1 AS x FROM resources WHERE dysponent_user_id=? LIMIT 1", [$user_id]);
}

/** Liczba rezerwacji czekających na decyzję danego użytkownika (admin i/lub dysponent). */
function res_pending_count_for(int $user_id, bool $is_admin): int {
    $n = 0;
    if ($is_admin) $n += count(res_reservations_pending_admin());
    $n += count(res_reservations_pending_dysponent($user_id));
    return $n;
}

// ── Powiadomienia ─────────────────────────────────────────────────────────────

/**
 * Wysyła powiadomienie systemowe + email do wskazanego użytkownika.
 *
 * @param int    $to_user_id  ID odbiorcy
 * @param string $title       Tytuł powiadomienia
 * @param string $body_text   Treść (HTML lub tekst — do maila i systemu)
 * @param string $url         URL przycisku w mailu + link w powiadomieniu
 * @param int    $res_id      ID rezerwacji (do linka)
 */
function res_notify(int $to_user_id, string $title, string $body_text, string $url = '', int $res_id = 0): void {
    // ── Powiadomienie w systemie ──────────────────────────────────────────────
    try {
        require_once dirname(__DIR__, 3) . '/includes/notifications.php';
        notif_migrate();
        notif_create($to_user_id, 'reservation', $title, $body_text, $url);
    } catch (\Throwable $e) {
        error_log('[res_notify] notif_create failed: ' . $e->getMessage());
    }

    // ── Email ─────────────────────────────────────────────────────────────────
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $recipient = db_one("SELECT name, email FROM users WHERE id=?", [$to_user_id]);
        if (!$recipient || !$recipient['email']) return;

        $org      = defined('ORG_NAME') ? htmlspecialchars(ORG_NAME, ENT_QUOTES) : 'System';
        $rname    = htmlspecialchars($recipient['name'], ENT_QUOTES);
        $body_esc = nl2br(htmlspecialchars($body_text, ENT_QUOTES));
        $btn      = $url
            ? "<p style='margin:18px 0 0'><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'
                  style='display:inline-block;background:#6366f1;color:#fff;padding:11px 24px;
                         border-radius:7px;text-decoration:none;font-weight:700;font-size:14px'>
                  Przejdź do rezerwacji →</a></p>"
            : '';

        // Stopka globalna (ustawienia org) lub domyślna
        $footer_html = '';
        try {
            $gf = org_setting('crm_email_footer');
            if ($gf) $footer_html = '<hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0">' . $gf;
        } catch (\Throwable $e) {}

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pl"><head><meta charset="UTF-8"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;padding:32px 16px;margin:0">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)">
  <!-- Nagłówek -->
  <div style="background:#4f46e5;padding:22px 30px;color:#fff">
    <div style="font-size:18px;font-weight:700">{$org}</div>
    <div style="font-size:12px;color:rgba(255,255,255,.65);margin-top:2px">Rezerwacje zasobów</div>
  </div>
  <!-- Treść -->
  <div style="padding:26px 30px;font-size:14px;color:#374151;line-height:1.6">
    <p style="margin:0 0 12px">Cześć <strong>{$rname}</strong>,</p>
    <div style="background:#f5f3ff;border-left:4px solid #6366f1;padding:14px 16px;border-radius:0 8px 8px 0;margin:16px 0;color:#1e1b4b">
      {$body_esc}
    </div>
    {$btn}
  </div>
  <!-- Podpis / stopka -->
  <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 30px;font-size:11px;color:#94a3b8">
    Wiadomość automatyczna z systemu <strong>{$org}</strong> — nie odpowiadaj na tę wiadomość.
    {$footer_html}
  </div>
</div>
</body></html>
HTML;

        mail_queue_add(
            $recipient['email'],
            $recipient['name'],
            $title,
            $html,
            strip_tags($body_text),
            'reservation',
            $res_id ?: null
        );
        mail_queue_process(1);
    } catch (\Throwable $e) {
        error_log('[res_notify] mail failed: ' . $e->getMessage());
    }
}

/**
 * Powiadomienia dla konkretnych zdarzeń w cyklu życia rezerwacji.
 * Wywoływane po res_change_status() i res_reserve().
 */
function res_notify_event(int $reservation_id, string $new_status, string $note = ''): void {
    $r = res_reservation_get($reservation_id);
    if (!$r) return;

    $org      = defined('ORG_NAME') ? ORG_NAME : 'System';
    $res_name = $r['res_name'];
    $date_str = $r['date_from'] . ($r['date_to'] !== $r['date_from'] ? ' – ' . $r['date_to'] : '');
    $url      = (defined('APP_URL') ? APP_URL : '') . '/modules/srs/view.php?id=' . $reservation_id;
    $status   = RES_STATUSES[$new_status] ?? ['label' => $new_status];

    switch ($new_status) {

        // Złożono nowy wniosek → powiadom adminów
        case 'pending_admin':
            $admins = db_all("SELECT id FROM users WHERE role IN ('admin','editor') AND is_active=1");
            foreach ($admins as $a) {
                res_notify(
                    (int)$a['id'],
                    "Nowy wniosek rezerwacji: {$res_name}",
                    "Użytkownik <strong>{$r['user_name']}</strong> złożył wniosek rezerwacji zasobu <strong>{$res_name}</strong> w terminie {$date_str}.\n\nCel: {$r['purpose']}",
                    $url,
                    $reservation_id
                );
            }
            break;

        // Przekazano do dysponenta → powiadom dysponenta
        case 'pending_dysponent':
            if ($r['dysponent_user_id']) {
                res_notify(
                    (int)$r['dysponent_user_id'],
                    "Wniosek do zatwierdzenia: {$res_name}",
                    "Administrator przekazał do Ciebie wniosek rezerwacji zasobu <strong>{$res_name}</strong>.\n\nTermin: {$date_str}\nWnioskodawca: {$r['user_name']}\nCel: {$r['purpose']}" . ($note ? "\n\nNota admina: {$note}" : ''),
                    $url,
                    $reservation_id
                );
            }
            break;

        // Decyzja dysponenta / admina → powiadom wnioskodawcę
        case 'odmowa':
        case 'oczekuje_dokumenty':
        case 'zgoda':
        case 'rezerwacja':
        case 'anulowana':
            $status_label = $status['label'];
            $note_part    = $note ? "\n\nUwagi: {$note}" : '';
            res_notify(
                (int)$r['user_id'],
                "Aktualizacja rezerwacji: {$res_name} — {$status_label}",
                "Status Twojej rezerwacji zasobu <strong>{$res_name}</strong> (termin: {$date_str}) zmienił się na: <strong>{$status_label}</strong>.{$note_part}",
                $url,
                $reservation_id
            );
            break;
    }
}
