<?php
/**
 * includes/ti_rekrutacja.php — Rekrutacja TI: zapisy na terminy za żetony.
 *
 * Nowy model od roku 2026/2027: kursant wybiera prowadzącego, prowadzący
 * samodzielnie wystawia terminy (sloty), rezerwacja pobiera żetony z puli
 * (k30_pl_token_pool_wallets — pule kierownika są źródłem prawdy o saldzie,
 * NIE stary portfel ogólny k30_pl_token_wallets).
 *
 * Tabele:
 *   k30_rk_rounds         — tury zapisów (zasady: pula, limit, okno zwrotu)
 *   k30_rk_slots          — terminy wystawiane przez prowadzących
 *   k30_rk_bookings       — rezerwacje kursantów (kto, co, za ile żetonów)
 *   k30_rk_access_tokens  — dostęp po tokenie z URL (selektor + hash sekretu)
 *   k30_rk_notifications  — idempotencja wysyłek (unikat round+client+kind)
 *
 * Nazwa „rekrutacja” w includes/rekrutacja.php jest zajęta przez nabór
 * wolontariuszy — stąd prefiks ti_/rk_ i osobny plik.
 */

declare(strict_types=1);

class RkException extends \RuntimeException {}

/* ══════════════════════════════════════════════════════════════════════════
   MIGRACJA
   ══════════════════════════════════════════════════════════════════════════ */

function ti_rk_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_rk_rounds (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        name            TEXT    NOT NULL,
        period_id       INTEGER REFERENCES k30_ti_periods(id),
        pool_id         INTEGER REFERENCES k30_pl_token_pools(id),
        status          TEXT    NOT NULL DEFAULT 'draft',
        opens_at        DATETIME NOT NULL,
        closes_at       DATETIME,
        announce_at     DATETIME,
        announced_at    DATETIME,
        max_per_client  INTEGER NOT NULL DEFAULT 0,
        refund_hours    INTEGER NOT NULL DEFAULT 24,
        late_refund_pct INTEGER NOT NULL DEFAULT 0,
        audience_json   TEXT    NOT NULL DEFAULT '{}',
        rules_html      TEXT    NOT NULL DEFAULT '',
        created_by      INTEGER REFERENCES users(id),
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_rk_slots (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        round_id       INTEGER NOT NULL REFERENCES k30_rk_rounds(id) ON DELETE CASCADE,
        instructor_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        course_id      INTEGER REFERENCES k30_ti_courses(id),
        subject_label  TEXT    NOT NULL DEFAULT '',
        mode           TEXT    NOT NULL DEFAULT 'online',
        room_id        INTEGER REFERENCES k30_pl_rooms(id),
        starts_at      DATETIME NOT NULL,
        ends_at        DATETIME NOT NULL,
        capacity       INTEGER NOT NULL DEFAULT 1,
        seats_taken    INTEGER NOT NULL DEFAULT 0,
        token_cost     INTEGER NOT NULL DEFAULT 1,
        status         TEXT    NOT NULL DEFAULT 'draft',
        session_id     INTEGER REFERENCES k30_ti_sessions(id),
        notes          TEXT    NOT NULL DEFAULT '',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        CHECK (capacity >= 1),
        CHECK (seats_taken >= 0 AND seats_taken <= capacity)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rk_slot_instr ON k30_rk_slots(instructor_id, starts_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rk_slot_round ON k30_rk_slots(round_id, status, starts_at)");
    // Jeden prowadzący nie wystawia dwóch żywych slotów o tym samym starcie
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_rk_slot_uniq
                ON k30_rk_slots(instructor_id, starts_at)
                WHERE status IN ('draft','open','locked')");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_rk_bookings (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        slot_id         INTEGER NOT NULL REFERENCES k30_rk_slots(id) ON DELETE CASCADE,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id)  ON DELETE CASCADE,
        wallet_id       INTEGER NOT NULL REFERENCES k30_pl_token_pool_wallets(id),
        tokens_spent    INTEGER NOT NULL DEFAULT 0,
        tokens_refunded INTEGER NOT NULL DEFAULT 0,
        status          TEXT    NOT NULL DEFAULT 'confirmed',
        source          TEXT    NOT NULL DEFAULT 'panel',
        access_token_id INTEGER REFERENCES k30_rk_access_tokens(id),
        booked_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        cancelled_at    DATETIME,
        cancel_reason   TEXT    NOT NULL DEFAULT ''
    )");
    // Klucz antywyścigowy: jeden kursant = jedno żywe miejsce na slocie.
    // v2 dodaje status pending_parent (rezerwacja małoletniego czekająca
    // na zatwierdzenie rodzica też zajmuje miejsce).
    $pdo->exec("DROP INDEX IF EXISTS idx_rk_book_uniq");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_rk_book_uniq_v2
                ON k30_rk_bookings(slot_id, client_id)
                WHERE status IN ('confirmed','pending_parent','attended','no_show')");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rk_book_client ON k30_rk_bookings(client_id, status)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_rk_access_tokens (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        selector     TEXT    NOT NULL UNIQUE,
        token_hash   TEXT    NOT NULL,
        client_id    INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        round_id     INTEGER REFERENCES k30_rk_rounds(id) ON DELETE CASCADE,
        scope        TEXT    NOT NULL DEFAULT 'booking',
        expires_at   DATETIME NOT NULL,
        used_count   INTEGER NOT NULL DEFAULT 0,
        last_used_at DATETIME,
        last_ip      TEXT    NOT NULL DEFAULT '',
        revoked_at   DATETIME,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Token zatwierdzenia rodzica wskazuje konkretną rezerwację
    $cols = array_column(db_all("PRAGMA table_info(k30_rk_access_tokens)"), 'name');
    if ($cols && !in_array('booking_id', $cols, true)) {
        try { $pdo->exec("ALTER TABLE k30_rk_access_tokens ADD COLUMN booking_id INTEGER"); } catch (\Throwable) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_rk_notifications (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        round_id   INTEGER NOT NULL REFERENCES k30_rk_rounds(id) ON DELETE CASCADE,
        client_id  INTEGER NOT NULL,
        kind       TEXT    NOT NULL,
        mail_id    INTEGER,
        sent_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(round_id, client_id, kind)
    )");

    // Prowadzący do wyboru per grupa (kurs) w turze — ustawia kierownik.
    // Brak wpisów dla grup kursanta = kursant widzi wszystkich prowadzących.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_rk_round_course_instructors (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        round_id      INTEGER NOT NULL REFERENCES k30_rk_rounds(id)   ON DELETE CASCADE,
        course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id)  ON DELETE CASCADE,
        instructor_id INTEGER NOT NULL REFERENCES users(id)           ON DELETE CASCADE,
        UNIQUE(round_id, course_id, instructor_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rk_rci_round ON k30_rk_round_course_instructors(round_id, course_id)");

    // Rate-limit prób tokenu (zgadywanie linków)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_rk_rate (
        bucket     TEXT NOT NULL,
        ip         TEXT NOT NULL,
        hits       INTEGER NOT NULL DEFAULT 0,
        window_at  DATETIME NOT NULL,
        PRIMARY KEY (bucket, ip)
    )");

    // ── Bezpieczny ALTER na księdze pul (held + odnośnik księgowy) ─────────
    $cols = array_column(db_all("PRAGMA table_info(k30_pl_token_pool_wallets)"), 'name');
    if ($cols && !in_array('held', $cols, true)) {
        try { $pdo->exec("ALTER TABLE k30_pl_token_pool_wallets ADD COLUMN held INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable) {}
    }
    $cols = array_column(db_all("PRAGMA table_info(k30_pl_token_pool_txns)"), 'name');
    if ($cols) {
        foreach (['ref_type' => "TEXT NOT NULL DEFAULT ''", 'ref_id' => "INTEGER NOT NULL DEFAULT 0"] as $c => $def) {
            if (!in_array($c, $cols, true)) {
                try { $pdo->exec("ALTER TABLE k30_pl_token_pool_txns ADD COLUMN $c $def"); } catch (\Throwable) {}
            }
        }
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   TRANSAKCJE — BEGIN IMMEDIATE (SQLite bierze blokadę zapisu na wejściu,
   więc busy_timeout czeka na starcie, a nie wybucha SQLITE_BUSY w środku)
   ══════════════════════════════════════════════════════════════════════════ */

function rk_tx(callable $fn): mixed {
    $pdo = db();
    if ($pdo->inTransaction()) return $fn($pdo);

    $native = defined('DB_TYPE') && DB_TYPE !== 'sqlite';
    $native ? $pdo->beginTransaction() : $pdo->exec('BEGIN IMMEDIATE');
    try {
        $out = $fn($pdo);
        $native ? $pdo->commit() : $pdo->exec('COMMIT');
        return $out;
    } catch (\Throwable $e) {
        try { $native ? $pdo->rollBack() : $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
        throw $e;
    }
}

/** Jak db_exec(), ale zwraca liczbę zmienionych wierszy — do UPDATE warunkowych. */
function rk_affect(string $sql, array $params = []): int {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/* ══════════════════════════════════════════════════════════════════════════
   ŻETONY — jedyne miejsce liczące saldo pul. Wzór: granted − spent − held.
   ══════════════════════════════════════════════════════════════════════════ */

/** Salda kursanta we wszystkich aktywnych pulach (także zerowe — do widoku). */
function rk_client_pools(int $client_id): array {
    return db_all(
        "SELECT p.id AS pool_id, p.name, p.color, p.description, p.valid_from, p.valid_to,
                w.id AS wallet_id,
                COALESCE(w.granted,0) AS granted,
                COALESCE(w.spent,0)   AS spent,
                COALESCE(w.held,0)    AS held,
                COALESCE(w.granted,0) - COALESCE(w.spent,0) - COALESCE(w.held,0) AS available
           FROM k30_pl_token_pools p
           LEFT JOIN k30_pl_token_pool_wallets w ON w.pool_id = p.id AND w.client_id = ?
          WHERE p.is_active = 1
          ORDER BY (p.valid_to IS NULL), p.valid_to ASC, p.id DESC",
        [$client_id]
    );
}

/** Suma dostępnych żetonów kursanta (wszystkie ważne dziś pule). */
function rk_client_available(int $client_id): int {
    $r = db_one(
        "SELECT COALESCE(SUM(w.granted - w.spent - w.held),0) AS avail
           FROM k30_pl_token_pool_wallets w
           JOIN k30_pl_token_pools p ON p.id = w.pool_id
          WHERE w.client_id = ? AND p.is_active = 1
            AND (p.valid_from IS NULL OR p.valid_from <= date('now'))
            AND (p.valid_to   IS NULL OR p.valid_to   >= date('now'))",
        [$client_id]
    );
    return max(0, (int)($r['avail'] ?? 0));
}

/** Historia transakcji kursanta ze wszystkich pul (do widoku „Żetony”). */
function rk_client_txns(int $client_id, int $limit = 50): array {
    return db_all(
        "SELECT t.*, p.name AS pool_name, p.color AS pool_color
           FROM k30_pl_token_pool_txns t
           JOIN k30_pl_token_pool_wallets w ON w.id = t.wallet_id
           JOIN k30_pl_token_pools p ON p.id = w.pool_id
          WHERE w.client_id = ?
          ORDER BY t.created_at DESC, t.id DESC
          LIMIT ?",
        [$client_id, $limit]
    );
}

/**
 * Portfel do obciążenia: pula ważna w DNIU ZAJĘĆ, z wystarczającym saldem,
 * wygasająca najwcześniej (FIFO). Tura może narzucić konkretną pulę.
 * Rezerwacja nigdy nie skleja żetonów z dwóch pul (zwroty stają się
 * niejednoznaczne) — brak jednej puli z pełną kwotą = INSUFFICIENT_TOKENS.
 */
function rk_wallet_pick(int $client_id, int $forced_pool_id, int $cost, string $lesson_at): array {
    $day    = substr($lesson_at, 0, 10);
    // CAST: (granted−spent−held) to wyrażenie bez afiniczności — parametr PDO
    // wszedłby jako TEXT i w SQLite INTEGER >= TEXT jest zawsze fałszem.
    $where  = ["w.client_id = ?", "p.is_active = 1",
               "(p.valid_from IS NULL OR p.valid_from <= ?)",
               "(p.valid_to   IS NULL OR p.valid_to   >= ?)",
               "(w.granted - w.spent - w.held) >= CAST(? AS INTEGER)"];
    $params = [$client_id, $day, $day, $cost];

    if ($forced_pool_id > 0) { $where[] = "w.pool_id = ?"; $params[] = $forced_pool_id; }

    $w = db_one(
        "SELECT w.*, p.name AS pool_name, p.valid_to
           FROM k30_pl_token_pool_wallets w
           JOIN k30_pl_token_pools p ON p.id = w.pool_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY (p.valid_to IS NULL), p.valid_to ASC, w.id ASC
          LIMIT 1",
        $params
    );
    if (!$w) throw new RkException('INSUFFICIENT_TOKENS');
    return $w;
}

/* ══════════════════════════════════════════════════════════════════════════
   USTAWIENIA MODUŁU — zależność godziny ↔ żetony ↔ złotówki, wszystko
   edytowane przez kierownika (zakładka Ustawienia w rekrutacja.php).
   ══════════════════════════════════════════════════════════════════════════ */

/** Ustawienie modułu z org_settings (string; '' = brak). */
function rk_setting(string $key, string $default = ''): string {
    $v = function_exists('org_setting') ? org_setting($key) : '';
    return $v !== '' ? $v : $default;
}

/** Ile minut zajęć odpowiada 1 żetonowi (0 = auto-wycena wyłączona). */
function rk_token_minutes(): int {
    return max(0, (int)rk_setting('rk_token_minutes', '60'));
}

/** Wartość 1 żetonu w złotych do podsumowań rozliczeniowych (0 = nie pokazuj). */
function rk_token_pln(): float {
    return max(0.0, (float)str_replace(',', '.', rk_setting('rk_token_pln', '0')));
}

/** Ile godzin rodzic ma na zatwierdzenie rezerwacji małoletniego. */
function rk_parent_confirm_hours(): int {
    return max(1, (int)rk_setting('rk_parent_confirm_hours', '48'));
}

/** Automatyczny koszt terminu z czasu trwania: ceil(minuty / minuty-na-żeton). */
function rk_auto_cost(string $starts_at, string $ends_at): int {
    $per = rk_token_minutes();
    if ($per <= 0) return 1;
    $min = (strtotime($ends_at) - strtotime($starts_at)) / 60;
    return max(1, (int)ceil($min / $per));
}

/* ══════════════════════════════════════════════════════════════════════════
   PROWADZĄCY DO WYBORU PER GRUPA — przypisania kierownika w turze.
   Reguła: jeśli którakolwiek grupa kursanta ma wpisy w turze, kursant widzi
   wyłącznie prowadzących przypisanych jego grupom; brak wpisów = wszyscy.
   ══════════════════════════════════════════════════════════════════════════ */

/** Mapa przypisań tury: [course_id => [instructor_id, …]]. */
function rk_group_map(int $round_id): array {
    $map = [];
    foreach (db_all("SELECT course_id, instructor_id FROM k30_rk_round_course_instructors
                      WHERE round_id=? ORDER BY course_id", [$round_id]) as $r) {
        $map[(int)$r['course_id']][] = (int)$r['instructor_id'];
    }
    return $map;
}

/** Ustawia prowadzących dla grupy w turze (pusta lista = zdjęcie ograniczenia). */
function rk_group_set(int $round_id, int $course_id, array $instructor_ids): void {
    rk_tx(function () use ($round_id, $course_id, $instructor_ids) {
        db_exec("DELETE FROM k30_rk_round_course_instructors WHERE round_id=? AND course_id=?",
                [$round_id, $course_id]);
        foreach (array_unique(array_filter(array_map('intval', $instructor_ids))) as $iid) {
            db_exec("INSERT OR IGNORE INTO k30_rk_round_course_instructors
                        (round_id, course_id, instructor_id) VALUES (?,?,?)",
                    [$round_id, $course_id, $iid]);
        }
    });
}

/**
 * Prowadzący dozwoleni dla kursanta w turze.
 * null = bez ograniczeń (żadna z jego grup nie ma przypisań).
 */
function rk_allowed_instructors(int $round_id, int $client_id): ?array {
    $rows = db_all(
        "SELECT DISTINCT gi.instructor_id
           FROM k30_rk_round_course_instructors gi
           JOIN k30_ti_enrollments e ON e.course_id = gi.course_id
          WHERE gi.round_id = ? AND e.client_id = ? AND e.status = 'active'",
        [$round_id, $client_id]);
    if (!$rows) return null;
    return array_map(fn($r) => (int)$r['instructor_id'], $rows);
}

/* ══════════════════════════════════════════════════════════════════════════
   TURY
   ══════════════════════════════════════════════════════════════════════════ */

function rk_rounds_list(): array {
    return db_all(
        "SELECT r.*, p.name AS pool_name,
                (SELECT COUNT(*) FROM k30_rk_slots s WHERE s.round_id=r.id) AS n_slots,
                (SELECT COUNT(*) FROM k30_rk_bookings b JOIN k30_rk_slots s ON s.id=b.slot_id
                  WHERE s.round_id=r.id AND b.status IN ('confirmed','pending_parent')) AS n_bookings
           FROM k30_rk_rounds r
           LEFT JOIN k30_pl_token_pools p ON p.id = r.pool_id
          ORDER BY r.opens_at DESC, r.id DESC", []
    );
}

function rk_round_get(int $id): ?array {
    return db_one("SELECT * FROM k30_rk_rounds WHERE id=?", [$id]) ?: null;
}

function rk_round_save(array $d, ?int $id = null): int {
    $fields = [
        'name'            => substr(trim($d['name'] ?? ''), 0, 160),
        'period_id'       => !empty($d['period_id']) ? (int)$d['period_id'] : null,
        'pool_id'         => !empty($d['pool_id'])   ? (int)$d['pool_id']   : null,
        'opens_at'        => ($d['opens_at'] ?? '') ?: date('Y-m-d H:i:s'),
        'closes_at'       => ($d['closes_at'] ?? '') ?: null,
        'announce_at'     => ($d['announce_at'] ?? '') ?: null,
        'max_per_client'  => max(0, (int)($d['max_per_client'] ?? 0)),
        'refund_hours'    => max(0, (int)($d['refund_hours'] ?? 24)),
        'late_refund_pct' => min(100, max(0, (int)($d['late_refund_pct'] ?? 0))),
        'audience_json'   => $d['audience_json'] ?? '{}',
        'rules_html'      => $d['rules_html'] ?? '',
    ];
    if ($id) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_rk_rounds SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $fields['status']     = 'draft';
    $fields['created_by'] = !empty($d['created_by']) ? (int)$d['created_by'] : null;
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_rk_rounds ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

/** Tury widoczne dla kursanta: otwarte, albo zaplanowane z ogłoszonym startem. */
function rk_rounds_for_client(int $client_id): array {
    return db_all(
        "SELECT r.*, p.name AS pool_name,
                (SELECT COUNT(*) FROM k30_rk_slots s
                  WHERE s.round_id=r.id AND s.status='open'
                    AND s.seats_taken < s.capacity
                    AND s.starts_at > datetime('now')) AS n_free,
                (SELECT COUNT(*) FROM k30_rk_bookings b JOIN k30_rk_slots s ON s.id=b.slot_id
                  WHERE s.round_id=r.id AND b.client_id=?
                    AND b.status IN ('confirmed','pending_parent','attended','no_show')) AS n_mine
           FROM k30_rk_rounds r
           LEFT JOIN k30_pl_token_pools p ON p.id = r.pool_id
          WHERE r.status IN ('open','scheduled')
          ORDER BY r.status='open' DESC, r.opens_at ASC",
        [$client_id]
    );
}

/* ══════════════════════════════════════════════════════════════════════════
   SLOTY
   ══════════════════════════════════════════════════════════════════════════ */

function rk_slot_get(int $id): ?array {
    return db_one(
        "SELECT s.*, u.name AS instructor_name, r.name AS round_name
           FROM k30_rk_slots s
           JOIN users u ON u.id = s.instructor_id
           JOIN k30_rk_rounds r ON r.id = s.round_id
          WHERE s.id=?", [$id]) ?: null;
}

function rk_slot_save(array $d, ?int $id = null): int {
    $fields = [
        'round_id'      => (int)($d['round_id'] ?? 0),
        'instructor_id' => (int)($d['instructor_id'] ?? 0),
        'course_id'     => !empty($d['course_id']) ? (int)$d['course_id'] : null,
        'subject_label' => substr(trim($d['subject_label'] ?? ''), 0, 160),
        'mode'          => in_array($d['mode'] ?? '', ['online','onsite','hybrid'], true) ? $d['mode'] : 'online',
        'room_id'       => !empty($d['room_id']) ? (int)$d['room_id'] : null,
        'starts_at'     => $d['starts_at'] ?? '',
        'ends_at'       => $d['ends_at'] ?? '',
        'capacity'      => max(1, (int)($d['capacity'] ?? 1)),
        // Puste pole kosztu = auto-wycena z czasu trwania (1 żeton = rk_token_minutes)
        'token_cost'    => (($d['token_cost'] ?? '') === '' || $d['token_cost'] === 'auto')
                              ? rk_auto_cost((string)($d['starts_at'] ?? ''), (string)($d['ends_at'] ?? ''))
                              : max(0, (int)$d['token_cost']),
        'notes'         => substr($d['notes'] ?? '', 0, 500),
    ];
    if (!$fields['round_id'] || !$fields['instructor_id']) throw new RkException('SLOT_INVALID');
    if (!$fields['starts_at'] || !$fields['ends_at'] || $fields['ends_at'] <= $fields['starts_at']) {
        throw new RkException('SLOT_INVALID_TIME');
    }
    // Urlop prowadzącego przykrywający termin — slot nie ma prawa powstać
    $leave = db_one(
        "SELECT 1 FROM k30_ti_instructor_leaves
          WHERE instructor_id=? AND status='approved'
            AND date(?) BETWEEN date_from AND date_to",
        [$fields['instructor_id'], $fields['starts_at']]);
    if ($leave) throw new RkException('SLOT_ON_LEAVE');

    if ($id) {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        db_exec("UPDATE k30_rk_slots SET $sets WHERE id=?", [...array_values($fields), $id]);
        return $id;
    }
    $cols = implode(',', array_keys($fields));
    $phs  = implode(',', array_fill(0, count($fields), '?'));
    db_exec("INSERT INTO k30_rk_slots ($cols) VALUES ($phs)", array_values($fields));
    return (int)db()->lastInsertId();
}

/**
 * Prowadzący mający sloty w turze + licznik wolnych miejsc.
 * $client_id > 0 zawęża listę do prowadzących dozwolonych dla grup kursanta
 * (przypisania kierownika — rk_allowed_instructors).
 */
function rk_instructors_for_round(int $round_id, int $client_id = 0): array {
    $extra  = '';
    $params = [$round_id];
    if ($client_id > 0) {
        $allowed = rk_allowed_instructors($round_id, $client_id);
        if ($allowed !== null) {
            if (!$allowed) return [];
            $extra  = ' AND u.id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            $params = array_merge($params, $allowed);
        }
    }
    return db_all(
        "SELECT u.id, u.name, u.email,
                COUNT(s.id) AS slots_total,
                SUM(CASE WHEN s.status='open' AND s.seats_taken < s.capacity
                          AND s.starts_at > datetime('now') THEN 1 ELSE 0 END) AS slots_free,
                MIN(CASE WHEN s.status='open' AND s.seats_taken < s.capacity
                          AND s.starts_at > datetime('now') THEN s.starts_at END) AS next_free_at,
                MIN(s.token_cost) AS min_cost
           FROM k30_rk_slots s
           JOIN users u ON u.id = s.instructor_id
          WHERE s.round_id = ? AND s.status IN ('open','locked') AND u.is_active = 1$extra
          GROUP BY u.id
          ORDER BY slots_free DESC, u.name COLLATE NOCASE",
        $params
    );
}

/** Wolne, przyszłe terminy wybranego prowadzącego w turze. */
function rk_slots_for_instructor(int $round_id, int $instructor_id, array $f = []): array {
    $where  = ["s.round_id = ?", "s.instructor_id = ?", "s.status = 'open'",
               "s.starts_at > datetime('now')"];
    $params = [$round_id, $instructor_id];

    $where[] = "NOT EXISTS (SELECT 1 FROM k30_ti_instructor_leaves l
                             WHERE l.instructor_id = s.instructor_id AND l.status='approved'
                               AND date(s.starts_at) BETWEEN l.date_from AND l.date_to)";

    if (!empty($f['only_free']))   $where[] = "s.seats_taken < s.capacity";
    if (!empty($f['mode']))      { $where[] = "s.mode = ?";              $params[] = $f['mode']; }
    if (!empty($f['date_from'])) { $where[] = "date(s.starts_at) >= ?"; $params[] = $f['date_from']; }
    if (!empty($f['date_to']))   { $where[] = "date(s.starts_at) <= ?"; $params[] = $f['date_to']; }
    if (isset($f['max_cost']))   { $where[] = "s.token_cost <= ?";      $params[] = (int)$f['max_cost']; }

    return db_all(
        "SELECT s.*, (s.capacity - s.seats_taken) AS seats_free, r.name AS room_name
           FROM k30_rk_slots s
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY s.starts_at",
        $params
    );
}

/** Sloty prowadzącego do jego własnego panelu (wszystkie statusy). */
function rk_slots_of_instructor(int $instructor_id, int $limit = 200): array {
    return db_all(
        "SELECT s.*, r.name AS round_name, r.status AS round_status,
                (s.capacity - s.seats_taken) AS seats_free
           FROM k30_rk_slots s
           JOIN k30_rk_rounds r ON r.id = s.round_id
          WHERE s.instructor_id = ?
          ORDER BY s.starts_at DESC
          LIMIT ?",
        [$instructor_id, $limit]
    );
}

/* ══════════════════════════════════════════════════════════════════════════
   REZERWACJA — sedno. Zasada: nie „sprawdź, potem zapisz”, tylko zapis
   warunkowy + kontrola rowCount. Wyjątek cofa całość przez rk_tx().
   ══════════════════════════════════════════════════════════════════════════ */

function rk_book(int $slot_id, int $client_id, string $source = 'panel', ?int $token_id = null): array {
    return rk_tx(function () use ($slot_id, $client_id, $source, $token_id) {

        /* 1. Slot + tura — migawka wewnątrz transakcji */
        $slot = db_one(
            "SELECT s.*, r.opens_at, r.closes_at, r.pool_id, r.max_per_client, r.status AS round_status
               FROM k30_rk_slots s
               JOIN k30_rk_rounds r ON r.id = s.round_id
              WHERE s.id = ?", [$slot_id]);

        if (!$slot)                                  throw new RkException('SLOT_NOT_FOUND');
        if ($slot['status'] !== 'open')              throw new RkException('SLOT_CLOSED');
        if ($slot['round_status'] !== 'open')        throw new RkException('ROUND_CLOSED');
        if (strtotime((string)$slot['opens_at']) > time()) throw new RkException('ROUND_NOT_STARTED');
        if ($slot['closes_at'] && strtotime((string)$slot['closes_at']) < time())
                                                     throw new RkException('ROUND_ENDED');
        if (strtotime((string)$slot['starts_at']) <= time()) throw new RkException('SLOT_IN_PAST');

        /* 1a. Przypisania kierownika: prowadzący musi być dozwolony dla grup kursanta */
        $allowed = rk_allowed_instructors((int)$slot['round_id'], $client_id);
        if ($allowed !== null && !in_array((int)$slot['instructor_id'], $allowed, true)) {
            throw new RkException('INSTRUCTOR_NOT_ALLOWED');
        }

        /* 1b. Małoletni: rezerwację musi zatwierdzić rodzic (mail + SMS po transakcji) */
        $guardian = rk_guardian_for_client($client_id);
        $pending  = false;
        if ($guardian['is_minor']) {
            if ($guardian['email'] === '') throw new RkException('GUARDIAN_MISSING');
            $pending = true;
        }

        /* 2. Limit rezerwacji w turze */
        if ((int)$slot['max_per_client'] > 0) {
            $n = (int)(db_one(
                "SELECT COUNT(*) n FROM k30_rk_bookings b
                   JOIN k30_rk_slots s2 ON s2.id = b.slot_id
                  WHERE b.client_id = ? AND s2.round_id = ?
                    AND b.status IN ('confirmed','pending_parent','attended','no_show')",
                [$client_id, $slot['round_id']])['n'] ?? 0);
            if ($n >= (int)$slot['max_per_client']) throw new RkException('ROUND_LIMIT_REACHED');
        }

        /* 3. Dubel na tym samym slocie → ALREADY_BOOKED (czytelniej niż kolizja) */
        $dup = db_one(
            "SELECT 1 FROM k30_rk_bookings
              WHERE slot_id = ? AND client_id = ?
                AND status IN ('confirmed','pending_parent','attended','no_show')",
            [$slot_id, $client_id]);
        if ($dup) throw new RkException('ALREADY_BOOKED');

        /* 3a. Kolizja z innym własnym terminem o tej samej porze */
        $clash = db_one(
            "SELECT 1 FROM k30_rk_bookings b
               JOIN k30_rk_slots s2 ON s2.id = b.slot_id
              WHERE b.client_id = ? AND b.status IN ('confirmed','pending_parent') AND s2.id != ?
                AND s2.starts_at < ? AND s2.ends_at > ?",
            [$client_id, $slot_id, $slot['ends_at'], $slot['starts_at']]);
        if ($clash) throw new RkException('TIME_CLASH');

        $cost   = (int)$slot['token_cost'];
        $wallet = $cost > 0
            ? rk_wallet_pick($client_id, (int)($slot['pool_id'] ?? 0), $cost, (string)$slot['starts_at'])
            : rk_wallet_free($client_id, (int)($slot['pool_id'] ?? 0));

        /* 4. Zajęcie miejsca — warunek w WHERE, nie w PHP */
        $ok = rk_affect(
            "UPDATE k30_rk_slots SET seats_taken = seats_taken + 1
              WHERE id = ? AND status = 'open' AND seats_taken < capacity",
            [$slot_id]);
        if ($ok !== 1) throw new RkException('SLOT_FULL');

        /* 5. Pobranie żetonów — saldo liczone w tym samym WHERE */
        if ($cost > 0) {
            $ok = rk_affect(
                "UPDATE k30_pl_token_pool_wallets
                    SET spent = spent + ?, updated_at = datetime('now')
                  WHERE id = ? AND (granted - spent - held) >= CAST(? AS INTEGER)",
                [$cost, $wallet['id'], $cost]);
            if ($ok !== 1) throw new RkException('INSUFFICIENT_TOKENS');
        }

        /* 6. Rezerwacja — unikat łapie dubla z drugiej karty */
        try {
            db_exec(
                "INSERT INTO k30_rk_bookings
                    (slot_id, client_id, wallet_id, tokens_spent, status, source, access_token_id)
                 VALUES (?,?,?,?, ?, ?, ?)",
                [$slot_id, $client_id, $wallet['id'], $cost,
                 $pending ? 'pending_parent' : 'confirmed', $source, $token_id]);
        } catch (\PDOException) {
            throw new RkException('ALREADY_BOOKED');   // rollback cofa kroki 4 i 5
        }
        $booking_id = (int)db()->lastInsertId();

        /* 7. Księga — wpis z odnośnikiem do rezerwacji */
        if ($cost > 0) {
            db_exec(
                "INSERT INTO k30_pl_token_pool_txns
                    (wallet_id, amount, direction, reason, ref_type, ref_id)
                 VALUES (?,?, 'debit', 'rezerwacja', 'rk_booking', ?)",
                [$wallet['id'], $cost, $booking_id]);
        }

        /* 8. Materializacja do dziennika TI — dopiero po zatwierdzeniu rodzica,
              gdy rezerwacja czeka (pending_parent nie siedzi w dzienniku) */
        if (!$pending) rk_materialize_session($slot_id, $client_id);

        return ['booking_id' => $booking_id, 'tokens_spent' => $cost,
                'status'     => $pending ? 'pending_parent' : 'confirmed'];
    });
}

/** Opiekun małoletniego kursanta z konta k30_ti_student_accounts. */
function rk_guardian_for_client(int $client_id): array {
    $a = db_one(
        "SELECT is_minor, guardian_name, guardian_email, guardian_phone
           FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$client_id]);
    return [
        'is_minor' => !empty($a['is_minor']),
        'name'     => trim((string)($a['guardian_name'] ?? '')),
        'email'    => trim((string)($a['guardian_email'] ?? '')),
        'phone'    => trim((string)($a['guardian_phone'] ?? '')),
    ];
}

/** Portfel „techniczny” dla slotów darmowych (token_cost=0) — żeby FK trzymał. */
function rk_wallet_free(int $client_id, int $pool_id): array {
    if ($pool_id <= 0) {
        $p = db_one("SELECT id FROM k30_pl_token_pools WHERE is_active=1 ORDER BY id LIMIT 1");
        if (!$p) {
            db_exec("INSERT INTO k30_pl_token_pools (name, description) VALUES ('Ogólna','utworzona automatycznie')");
            $pool_id = (int)db()->lastInsertId();
        } else {
            $pool_id = (int)$p['id'];
        }
    }
    db_exec("INSERT OR IGNORE INTO k30_pl_token_pool_wallets (pool_id, client_id) VALUES (?,?)",
            [$pool_id, $client_id]);
    return db_one("SELECT * FROM k30_pl_token_pool_wallets WHERE pool_id=? AND client_id=?",
            [$pool_id, $client_id]);
}

/**
 * Slot staje się lekcją w k30_ti_sessions przy pierwszej rezerwacji — frekwencja,
 * e-dziennik i wypłata prowadzącego liczą się z istniejących mechanizmów TI.
 * k30_ti_attendance to tu WYŁĄCZNIE dziennik; zajętość liczy slots.seats_taken.
 */
function rk_materialize_session(int $slot_id, int $client_id): int {
    $s = db_one("SELECT * FROM k30_rk_slots WHERE id = ?", [$slot_id]);
    $session_id = (int)($s['session_id'] ?? 0);

    if (!$session_id) {
        $course_id = (int)($s['course_id'] ?? 0) ?: rk_placeholder_course((int)$s['instructor_id']);
        db_exec(
            "INSERT INTO k30_ti_sessions
                (course_id, lesson_date, time_from, time_to, duration_min, status, notes, created_by)
             VALUES (?,?,?,?,?, 'planned', ?, ?)",
            [$course_id, substr((string)$s['starts_at'],0,10), substr((string)$s['starts_at'],11,5),
             substr((string)$s['ends_at'],11,5),
             max(15, (int)round((strtotime((string)$s['ends_at']) - strtotime((string)$s['starts_at'])) / 60)),
             'Rekrutacja: termin #'.$slot_id, (int)$s['instructor_id']]);
        $session_id = (int)db()->lastInsertId();
        db_exec("UPDATE k30_rk_slots SET session_id = ? WHERE id = ?", [$session_id, $slot_id]);
    }

    db_exec("INSERT OR IGNORE INTO k30_ti_attendance (session_id, client_id, attended)
             VALUES (?,?,0)", [$session_id, $client_id]);
    return $session_id;
}

/** Kurs-wydmuszka „Konsultacje — {prowadzący}” dla slotów bez kursu. */
function rk_placeholder_course(int $instructor_id): int {
    $c = db_one(
        "SELECT id FROM k30_ti_courses
          WHERE instructor_id=? AND name LIKE 'Konsultacje —%' LIMIT 1", [$instructor_id]);
    if ($c) return (int)$c['id'];
    $u = db_one("SELECT name FROM users WHERE id=?", [$instructor_id]);
    db_exec("INSERT INTO k30_ti_courses (name, description, instructor_id, is_active)
             VALUES (?,?,?,1)",
            ['Konsultacje — ' . (string)($u['name'] ?? ('#'.$instructor_id)),
             'Terminy indywidualne z modułu Rekrutacja TI.', $instructor_id]);
    return (int)db()->lastInsertId();
}

/* ══════════════════════════════════════════════════════════════════════════
   ANULOWANIE I ZWROT
   ══════════════════════════════════════════════════════════════════════════ */

function rk_cancel(int $booking_id, int $client_id, string $by = 'student', string $reason = ''): array {
    return rk_tx(function () use ($booking_id, $client_id, $by, $reason) {

        $b = db_one(
            "SELECT b.*, s.starts_at, s.id AS slot_id, s.session_id,
                    r.refund_hours, r.late_refund_pct
               FROM k30_rk_bookings b
               JOIN k30_rk_slots  s ON s.id = b.slot_id
               JOIN k30_rk_rounds r ON r.id = s.round_id
              WHERE b.id = ?" . ($by === 'student' ? " AND b.client_id = ?" : ""),
            $by === 'student' ? [$booking_id, $client_id] : [$booking_id]);

        if (!$b) throw new RkException('BOOKING_NOT_FOUND');
        if (!in_array($b['status'], ['confirmed','pending_parent'], true)) {
            throw new RkException('BOOKING_NOT_ACTIVE');
        }

        $paid  = (int)$b['tokens_spent'] - (int)$b['tokens_refunded'];
        $hours = (strtotime((string)$b['starts_at']) - time()) / 3600;

        // Pełny zwrot: odwołanie ośrodka, decyzja/wygaśnięcie u rodzica,
        // rezygnacja z rezerwacji jeszcze niezatwierdzonej, rezygnacja w oknie.
        if ($by !== 'student' || $b['status'] === 'pending_parent' || $hours >= (int)$b['refund_hours']) {
            $refund = $paid;
        } else {
            $refund = (int)floor($paid * (int)$b['late_refund_pct'] / 100);
        }

        $new_status = match ($by) {
            'staff'  => 'cancelled_staff',
            'parent' => 'cancelled_parent',
            default  => 'cancelled_student',
        };

        /* Zamknięcie rezerwacji — warunkowo: dwa kliki nie zwrócą dwa razy */
        $ok = rk_affect(
            "UPDATE k30_rk_bookings
                SET status = ?, cancelled_at = datetime('now'),
                    tokens_refunded = tokens_refunded + ?, cancel_reason = ?
              WHERE id = ? AND status IN ('confirmed','pending_parent')",
            [$new_status, $refund, substr($reason, 0, 300), $booking_id]);
        if ($ok !== 1) throw new RkException('BOOKING_NOT_ACTIVE');

        db_exec("UPDATE k30_rk_slots SET seats_taken = MAX(0, seats_taken - 1) WHERE id = ?",
                [$b['slot_id']]);

        if ($refund > 0) {
            [$target_id, $tag] = rk_refund_target((int)$b['wallet_id']);
            db_exec("UPDATE k30_pl_token_pool_wallets
                        SET spent = MAX(0, spent - ?), updated_at = datetime('now')
                      WHERE id = ?", [$refund, $target_id]);
            db_exec("INSERT INTO k30_pl_token_pool_txns
                        (wallet_id, amount, direction, reason, ref_type, ref_id)
                     VALUES (?,?, 'credit', ?, 'rk_booking', ?)",
                    [$target_id, $refund, $tag, $booking_id]);
        }

        /* Wypis z dziennika, jeśli slot był zmaterializowany */
        if (!empty($b['session_id'])) {
            db_exec("DELETE FROM k30_ti_attendance WHERE client_id = ? AND session_id = ?",
                    [$b['client_id'], (int)$b['session_id']]);
        }

        return ['refunded' => $refund, 'forfeited' => $paid - $refund];
    });
}

/**
 * Pula docelowa zwrotu. Wygasła pula = zwrot pozorny, więc kierujemy do puli
 * zastępczej (org_setting rk_refund_fallback_pool). Bez zastępczej — zwrot do
 * źródłowej z reason=refund_expired_pool, żeby kierownik to zobaczył na liście.
 */
function rk_refund_target(int $wallet_id): array {
    $w = db_one(
        "SELECT w.*, p.valid_to, p.is_active
           FROM k30_pl_token_pool_wallets w
           JOIN k30_pl_token_pools p ON p.id = w.pool_id
          WHERE w.id = ?", [$wallet_id]);
    if (!$w) throw new RkException('WALLET_NOT_FOUND');

    $expired = !(int)$w['is_active']
        || ($w['valid_to'] && $w['valid_to'] < date('Y-m-d'));
    if (!$expired) return [$wallet_id, 'zwrot'];

    $fb = (int)(function_exists('org_setting') ? org_setting('rk_refund_fallback_pool') : 0);
    if ($fb > 0 && db_one("SELECT 1 FROM k30_pl_token_pools WHERE id=? AND is_active=1", [$fb])) {
        db_exec("INSERT OR IGNORE INTO k30_pl_token_pool_wallets (pool_id, client_id) VALUES (?,?)",
                [$fb, (int)$w['client_id']]);
        $t = db_one("SELECT id FROM k30_pl_token_pool_wallets WHERE pool_id=? AND client_id=?",
                [$fb, (int)$w['client_id']]);
        return [(int)$t['id'], 'zwrot_pula_zastepcza'];
    }
    return [$wallet_id, 'refund_expired_pool'];
}

/** Odwołanie całego slotu przez prowadzącego/kierownika — zwroty 100%. */
function rk_slot_cancel(int $slot_id, int $instructor_id, string $reason = ''): int {
    return rk_tx(function () use ($slot_id, $instructor_id, $reason) {
        $ok = rk_affect(
            "UPDATE k30_rk_slots SET status='cancelled'
              WHERE id=? AND instructor_id=? AND status IN ('draft','open','locked')",
            [$slot_id, $instructor_id]);
        if ($ok !== 1) throw new RkException('SLOT_NOT_FOUND');

        $n = 0;
        foreach (db_all("SELECT id, client_id FROM k30_rk_bookings
                          WHERE slot_id=? AND status IN ('confirmed','pending_parent')", [$slot_id]) as $b) {
            rk_cancel((int)$b['id'], (int)$b['client_id'], 'staff', $reason ?: 'odwołanie terminu');
            $n++;
        }
        // Odwołaj też zmaterializowaną lekcję, jeśli nikt inny na niej nie siedzi
        $s = db_one("SELECT session_id FROM k30_rk_slots WHERE id=?", [$slot_id]);
        if (!empty($s['session_id'])) {
            $left = db_one("SELECT COUNT(*) n FROM k30_ti_attendance WHERE session_id=?",
                    [(int)$s['session_id']]);
            if ((int)($left['n'] ?? 0) === 0) {
                db_exec("UPDATE k30_ti_sessions SET status='cancelled' WHERE id=?", [(int)$s['session_id']]);
            }
        }
        return $n;
    });
}

/** Rezerwacje kursanta do widoku „Moje zapisy”. */
function rk_bookings_for_client(int $client_id, int $limit = 100): array {
    return db_all(
        "SELECT b.*, s.starts_at, s.ends_at, s.mode, s.subject_label, s.token_cost,
                s.status AS slot_status,
                u.name AS instructor_name, r.name AS round_name, r.refund_hours,
                rm.name AS room_name
           FROM k30_rk_bookings b
           JOIN k30_rk_slots s ON s.id = b.slot_id
           JOIN users u ON u.id = s.instructor_id
           JOIN k30_rk_rounds r ON r.id = s.round_id
           LEFT JOIN k30_pl_rooms rm ON rm.id = s.room_id
          WHERE b.client_id = ?
          ORDER BY s.starts_at DESC
          LIMIT ?",
        [$client_id, $limit]
    );
}

/* ══════════════════════════════════════════════════════════════════════════
   DOSTĘP PO TOKENIE Z URL — selektor (jawny, indeks) + sekret (hash, stały czas)
   ══════════════════════════════════════════════════════════════════════════ */

function rk_token_issue(int $client_id, int $round_id, int $days = 30, string $scope = 'booking'): string {
    $selector = bin2hex(random_bytes(6));    // 12 znaków
    $secret   = bin2hex(random_bytes(24));   // 48 znaków, 192 bity
    db_exec("INSERT INTO k30_rk_access_tokens
                (selector, token_hash, client_id, round_id, scope, expires_at)
             VALUES (?,?,?,?,?,?)",
        [$selector, hash('sha256', $secret), $client_id, $round_id ?: null, $scope,
         date('Y-m-d H:i:s', strtotime("+$days days"))]);
    return $selector . '.' . $secret;
}

function rk_token_resolve(string $raw): ?array {
    if (!preg_match('/^([a-f0-9]{12})\.([a-f0-9]{48})$/', $raw, $m)) return null;
    $row = db_one("SELECT * FROM k30_rk_access_tokens WHERE selector = ?", [$m[1]]);
    if (!$row || $row['revoked_at'])                             return null;
    if (!hash_equals((string)$row['token_hash'], hash('sha256', $m[2]))) return null;
    if (strtotime((string)$row['expires_at']) < time())          return null;

    db_exec("UPDATE k30_rk_access_tokens
                SET used_count = used_count + 1, last_used_at = datetime('now'), last_ip = ?
              WHERE id = ?", [substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), $row['id']]);
    return $row;
}

/**
 * Sesja tokenowa strony t.php. Token przyjmowany RAZ, wymieniany na krótką
 * sesję i zdejmowany z URL (303) — nie jeździ w Refererze ani w historii.
 */
function rk_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('k30_rk');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function rk_session_open(array $token_row): void {
    rk_session_start();
    session_regenerate_id(true);
    $_SESSION['rk_client_id'] = (int)$token_row['client_id'];
    $_SESSION['rk_round_id']  = (int)($token_row['round_id'] ?? 0);
    $_SESSION['rk_token_id']  = (int)$token_row['id'];
    $_SESSION['rk_scope']     = (string)$token_row['scope'];
    $_SESSION['rk_expires']   = time() + 3600;
    $_SESSION['rk_csrf']      = bin2hex(random_bytes(16));
}

/** Kontekst sesji tokenowej albo null (brak/wygasła). */
function rk_session_context(): ?array {
    rk_session_start();
    if (empty($_SESSION['rk_client_id']) || ($_SESSION['rk_expires'] ?? 0) < time()) return null;
    $_SESSION['rk_expires'] = time() + 3600;   // aktywność odświeża sesję
    return [
        'client_id' => (int)$_SESSION['rk_client_id'],
        'round_id'  => (int)($_SESSION['rk_round_id'] ?? 0),
        'token_id'  => (int)($_SESSION['rk_token_id'] ?? 0),
        'scope'     => (string)($_SESSION['rk_scope'] ?? 'booking'),
    ];
}

function rk_session_csrf(): string {
    rk_session_start();
    if (empty($_SESSION['rk_csrf'])) $_SESSION['rk_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['rk_csrf'];
}

/** Prosty rate-limit: 10 prób / 10 min na IP i kubełek. */
function rk_rate_ok(string $bucket, string $ip, int $max = 10, int $window_min = 10): bool {
    $ip  = substr($ip ?: '?', 0, 45);
    $row = db_one("SELECT * FROM k30_rk_rate WHERE bucket=? AND ip=?", [$bucket, $ip]);
    $now = time();
    if (!$row || strtotime((string)$row['window_at']) < $now - $window_min * 60) {
        db_exec("INSERT INTO k30_rk_rate (bucket, ip, hits, window_at) VALUES (?,?,1,datetime('now'))
                 ON CONFLICT(bucket, ip) DO UPDATE SET hits=1, window_at=datetime('now')",
                [$bucket, $ip]);
        return true;
    }
    if ((int)$row['hits'] >= $max) return false;
    db_exec("UPDATE k30_rk_rate SET hits = hits + 1 WHERE bucket=? AND ip=?", [$bucket, $ip]);
    return true;
}

/* ══════════════════════════════════════════════════════════════════════════
   POWIADOMIENIA — zapowiedź startu zapisów (idempotentna per odbiorca)
   ══════════════════════════════════════════════════════════════════════════ */

/** Odbiorcy tury: grupy + kursanci prowadzących + posiadacze żetonów puli. */
function rk_round_audience(int $round_id): array {
    $r = rk_round_get($round_id);
    if (!$r) return [];
    $a   = json_decode((string)$r['audience_json'] ?: '{}', true) ?: [];
    $out = [];

    if (!empty($a['courses']) && function_exists('k30_ti_comm_recipients')) {
        $out = array_merge($out, k30_ti_comm_recipients(array_map('intval', (array)$a['courses'])));
    }
    if (!empty($a['instructors'])) {
        $ids = array_map('intval', (array)$a['instructors']);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $out = array_merge($out, db_all(
            "SELECT DISTINCT cl.id AS client_id, cl.name, cl.email
               FROM k30_ti_enrollments e
               JOIN k30_ti_courses  c ON c.id = e.course_id
               JOIN k30_clients    cl ON cl.id = e.client_id
              WHERE e.status='active' AND c.instructor_id IN ($ph)", $ids));
    }
    if (!empty($r['pool_id'])) {
        $out = array_merge($out, db_all(
            "SELECT cl.id AS client_id, cl.name, cl.email
               FROM k30_pl_token_pool_wallets w
               JOIN k30_clients cl ON cl.id = w.client_id
              WHERE w.pool_id = ? AND (w.granted - w.spent - w.held) > 0", [(int)$r['pool_id']]));
    }

    // Dedup po client_id i adresie (rodzeństwo z jednego adresu rodzica — jedna wiadomość)
    $seen = [];
    return array_values(array_filter($out, function ($c) use (&$seen) {
        $k = (int)$c['client_id'] . '|' . mb_strtolower(trim((string)($c['email'] ?? '')));
        if (isset($seen[$k])) return false;
        return $seen[$k] = true;
    }));
}

/**
 * Wysyłka zapowiedzi. Znacznik w k30_rk_notifications powstaje PRZED mailem —
 * kolizja unikatu znaczy „już wysłane”, więc zazębione crony nie dublują.
 */
function rk_round_announce(int $round_id, string $kind = 'open'): int {
    if (!function_exists('mail_queue_add')) require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('email_tpl_render')) require_once __DIR__ . '/email_templates.php';

    $r = rk_round_get($round_id);
    if (!$r) return 0;

    $sent = 0;
    foreach (rk_round_audience($round_id) as $c) {
        if (trim((string)($c['email'] ?? '')) === '') continue;

        try {
            db_exec("INSERT INTO k30_rk_notifications (round_id, client_id, kind) VALUES (?,?,?)",
                    [$round_id, (int)$c['client_id'], $kind]);
        } catch (\PDOException) { continue; }

        $days = 30;
        if (!empty($r['closes_at'])) {
            $days = max(7, (int)ceil((strtotime((string)$r['closes_at']) - time()) / 86400));
        }
        $link = rtrim(APP_URL, '/') . '/karty30/ti/rekrutacja/t.php?t='
              . rk_token_issue((int)$c['client_id'], $round_id, $days);

        $tpl = email_tpl_render('rk_round_open', [
            'name'       => (string)$c['name'],
            'round'      => (string)$r['name'],
            'opens_at'   => rk_fmt_dt((string)$r['opens_at']),
            'closes_at'  => $r['closes_at'] ? rk_fmt_dt((string)$r['closes_at']) : 'do wyczerpania miejsc',
            'balance'    => (string)rk_client_available((int)$c['client_id']),
            'limit'      => (int)$r['max_per_client'] ? (string)(int)$r['max_per_client'] : 'bez limitu',
            'refund_h'   => (string)(int)$r['refund_hours'],
            'rules_html' => (string)$r['rules_html'],
            'link'       => $link,
            'org'        => defined('ORG_NAME') ? ORG_NAME : '',
        ]);
        if (!$tpl['enabled'] || $tpl['subject'] === '') break;  // szablon wyłączony/brak = stop

        $mail_id = mail_queue_add((string)$c['email'], (string)$c['name'],
                                  $tpl['subject'], $tpl['html'], '', 'rk_round', $round_id);
        db_exec("UPDATE k30_rk_notifications SET mail_id = ?
                  WHERE round_id = ? AND client_id = ? AND kind = ?",
                [$mail_id, $round_id, (int)$c['client_id'], $kind]);
        $sent++;
    }

    if ($kind === 'open') {
        db_exec("UPDATE k30_rk_rounds SET announced_at = datetime('now') WHERE id = ?", [$round_id]);
    }
    return $sent;
}

/* ══════════════════════════════════════════════════════════════════════════
   ZATWIERDZENIE RODZICA — rezerwacja małoletniego czeka (pending_parent),
   rodzic dostaje e-mail z linkiem (token) + SMS. Zatwierdza albo odrzuca;
   po rk_parent_confirm_hours bez decyzji cron wygasza z pełnym zwrotem.
   ══════════════════════════════════════════════════════════════════════════ */

/** Token zatwierdzenia dla rodzica (scope parent_confirm, wskazuje rezerwację). */
function rk_parent_token_issue(int $booking_id, int $client_id, int $days = 7): string {
    $selector = bin2hex(random_bytes(6));
    $secret   = bin2hex(random_bytes(24));
    db_exec("INSERT INTO k30_rk_access_tokens
                (selector, token_hash, client_id, booking_id, scope, expires_at)
             VALUES (?,?,?,?, 'parent_confirm', ?)",
        [$selector, hash('sha256', $secret), $client_id, $booking_id,
         date('Y-m-d H:i:s', strtotime("+$days days"))]);
    return $selector . '.' . $secret;
}

/**
 * Wysyłka prośby o zatwierdzenie do rodzica (e-mail + SMS). Wołać PO
 * zamknięciu transakcji rezerwacji. Idempotentna: drugi raz nic nie wysyła,
 * jeśli rezerwacja ma już żywy token parent_confirm.
 */
function rk_parent_request_send(int $booking_id): bool {
    if (!function_exists('mail_queue_add')) require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('email_tpl_render')) require_once __DIR__ . '/email_templates.php';

    $b = db_one(
        "SELECT b.*, s.starts_at, s.ends_at, s.subject_label, s.mode,
                u.name AS instructor_name, cl.name AS client_name
           FROM k30_rk_bookings b
           JOIN k30_rk_slots s ON s.id = b.slot_id
           JOIN users u ON u.id = s.instructor_id
           JOIN k30_clients cl ON cl.id = b.client_id
          WHERE b.id = ? AND b.status = 'pending_parent'", [$booking_id]);
    if (!$b) return false;

    $g = rk_guardian_for_client((int)$b['client_id']);
    if ($g['email'] === '') return false;

    $has = db_one("SELECT 1 FROM k30_rk_access_tokens
                    WHERE booking_id=? AND scope='parent_confirm'
                      AND revoked_at IS NULL AND expires_at > datetime('now')", [$booking_id]);
    if ($has) return true;   // prośba już wysłana

    $tok  = rk_parent_token_issue($booking_id, (int)$b['client_id'],
                                  max(2, (int)ceil(rk_parent_confirm_hours() / 24) + 1));
    $link = rtrim(APP_URL, '/') . '/karty30/ti/rekrutacja/potwierdz.php?t=' . $tok;

    $tpl = email_tpl_render('rk_parent_confirm', [
        'guardian'   => $g['name'] !== '' ? $g['name'] : 'Szanowni Państwo',
        'student'    => (string)$b['client_name'],
        'when'       => rk_fmt_dt((string)$b['starts_at']) . '–' . substr((string)$b['ends_at'], 11, 5),
        'instructor' => (string)$b['instructor_name'],
        'subject'    => (string)($b['subject_label'] ?: 'konsultacja'),
        'tokens'     => (string)(int)$b['tokens_spent'],
        'hours'      => (string)rk_parent_confirm_hours(),
        'link'       => $link,
        'org'        => defined('ORG_NAME') ? ORG_NAME : '',
    ]);
    if ($tpl['enabled'] && $tpl['subject'] !== '') {
        mail_queue_add($g['email'], $g['name'], $tpl['subject'], $tpl['html'],
                       '', 'rk_parent_confirm', $booking_id);
    }

    // SMS informacyjny — decyzja i tak zapada w mailu
    if ($g['phone'] !== '' && function_exists('sms_is_enabled') && sms_is_enabled()) {
        try {
            sms_send($g['phone'],
                'Kursant ' . $b['client_name'] . ' zapisal sie na zajecia '
                . date('d.m H:i', strtotime((string)$b['starts_at']))
                . '. Prosimy zatwierdzic rezerwacje - link wyslalismy e-mailem na adres '
                . $g['email'] . '. ' . (defined('ORG_NAME') ? ORG_NAME : ''));
        } catch (\Throwable) { /* SMS nie blokuje przepływu */ }
    }
    return true;
}

/** Token rodzica → rezerwacja z kontekstem (albo null). */
function rk_parent_resolve(string $raw): ?array {
    $t = rk_token_resolve($raw);
    if (!$t || $t['scope'] !== 'parent_confirm' || empty($t['booking_id'])) return null;
    $b = db_one(
        "SELECT b.*, s.starts_at, s.ends_at, s.subject_label, s.mode,
                u.name AS instructor_name, cl.name AS client_name
           FROM k30_rk_bookings b
           JOIN k30_rk_slots s ON s.id = b.slot_id
           JOIN users u ON u.id = s.instructor_id
           JOIN k30_clients cl ON cl.id = b.client_id
          WHERE b.id = ?", [(int)$t['booking_id']]);
    return $b ? ['token' => $t, 'booking' => $b] : null;
}

/** Zatwierdzenie przez rodzica: pending_parent → confirmed + wpis do dziennika. */
function rk_parent_confirm(int $booking_id): void {
    rk_tx(function () use ($booking_id) {
        $ok = rk_affect("UPDATE k30_rk_bookings SET status='confirmed'
                          WHERE id=? AND status='pending_parent'", [$booking_id]);
        if ($ok !== 1) throw new RkException('BOOKING_NOT_ACTIVE');
        $b = db_one("SELECT slot_id, client_id FROM k30_rk_bookings WHERE id=?", [$booking_id]);
        rk_materialize_session((int)$b['slot_id'], (int)$b['client_id']);
        db_exec("UPDATE k30_rk_access_tokens SET revoked_at=datetime('now')
                  WHERE booking_id=? AND scope='parent_confirm'", [$booking_id]);
    });
}

/** Odrzucenie przez rodzica: pełny zwrot żetonów, miejsce wraca do puli. */
function rk_parent_reject(int $booking_id, string $reason = ''): array {
    $b = db_one("SELECT client_id FROM k30_rk_bookings WHERE id=?", [$booking_id]);
    if (!$b) throw new RkException('BOOKING_NOT_FOUND');
    $out = rk_cancel($booking_id, (int)$b['client_id'], 'parent', $reason ?: 'odrzucone przez rodzica');
    db_exec("UPDATE k30_rk_access_tokens SET revoked_at=datetime('now')
              WHERE booking_id=? AND scope='parent_confirm'", [$booking_id]);
    return $out;
}

/** Wygaszenie rezerwacji bez decyzji rodzica (cron). Zwraca liczbę wygaszonych. */
function rk_parent_expire_stale(): int {
    $h = rk_parent_confirm_hours();
    $n = 0;
    foreach (db_all(
        "SELECT id, client_id FROM k30_rk_bookings
          WHERE status='pending_parent'
            AND booked_at < datetime('now', ?)", ["-$h hours"]) as $b) {
        try {
            rk_cancel((int)$b['id'], (int)$b['client_id'], 'parent', 'brak zatwierdzenia w terminie');
            $n++;
        } catch (RkException) { /* wyścig z decyzją rodzica — pomijamy */ }
    }
    return $n;
}

/* ══════════════════════════════════════════════════════════════════════════
   GENERATOR SLOTÓW Z DOSTĘPNOŚCI — kierownik jednym ruchem wrzuca terminy
   prowadzących na podstawie ich okien tygodniowych (k30_ti_instructor_availability).
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Tworzy otwarte sloty w zakresie dat z zatwierdzonych okien dostępności.
 * Okna cięte na odcinki $duration_min; koszt auto z rk_token_minutes, chyba że
 * podany. Duplikaty (ten sam start) i dni urlopu pomijane bez błędu.
 * Zwraca ['created' => n, 'skipped' => n].
 */
function rk_slots_generate(int $round_id, array $instructor_ids, string $date_from, string $date_to,
                           int $duration_min = 60, int $capacity = 1, ?int $token_cost = null,
                           string $mode = 'online'): array {
    $instructor_ids = array_unique(array_filter(array_map('intval', $instructor_ids)));
    $duration_min   = max(15, min(480, $duration_min));
    $t_from = strtotime($date_from . ' 00:00:00');
    $t_to   = strtotime($date_to   . ' 00:00:00');
    if (!$instructor_ids || !$t_from || !$t_to || $t_to < $t_from) return ['created' => 0, 'skipped' => 0];
    $t_to = min($t_to, strtotime('+120 days'));   // bezpiecznik zakresu

    $created = 0; $skipped = 0;
    foreach ($instructor_ids as $iid) {
        $windows = db_all(
            "SELECT day_of_week, time_from, time_to FROM k30_ti_instructor_availability
              WHERE instructor_id=? AND is_active=1 AND status='approved'", [$iid]);
        if (!$windows) continue;
        $by_dow = [];
        foreach ($windows as $w) $by_dow[(int)$w['day_of_week']][] = $w;

        for ($day = $t_from; $day <= $t_to; $day += 86400) {
            if ($day < strtotime('today')) continue;
            $dow = (int)date('w', $day);
            foreach ($by_dow[$dow] ?? [] as $w) {
                $win_from = strtotime(date('Y-m-d ', $day) . $w['time_from']);
                $win_to   = strtotime(date('Y-m-d ', $day) . $w['time_to']);
                if (!$win_from || !$win_to) continue;
                for ($s = $win_from; $s + $duration_min * 60 <= $win_to; $s += $duration_min * 60) {
                    if ($s <= time()) { continue; }
                    $starts = date('Y-m-d H:i:s', $s);
                    $ends   = date('Y-m-d H:i:s', $s + $duration_min * 60);
                    try {
                        $sid = rk_slot_save([
                            'round_id'      => $round_id,
                            'instructor_id' => $iid,
                            'starts_at'     => $starts,
                            'ends_at'       => $ends,
                            'capacity'      => $capacity,
                            'token_cost'    => $token_cost !== null ? (string)$token_cost : '',
                            'mode'          => $mode,
                        ]);
                        db_exec("UPDATE k30_rk_slots SET status='open' WHERE id=?", [$sid]);
                        $created++;
                    } catch (RkException) { $skipped++;        // urlop / walidacja
                    } catch (\PDOException) { $skipped++; }    // duplikat startu
                }
            }
        }
    }
    return ['created' => $created, 'skipped' => $skipped];
}

/* ══════════════════════════════════════════════════════════════════════════
   POMOCNICZE
   ══════════════════════════════════════════════════════════════════════════ */

function rk_fmt_dt(string $dt): string {
    $t = strtotime($dt);
    return $t ? date('d.m.Y H:i', $t) : $dt;
}

/** Komunikat dla kursanta z kodu RkException. */
function rk_error_message(string $code): string {
    return match ($code) {
        'SLOT_FULL'           => 'Ten termin właśnie zajął ktoś inny. Wybierz inny — żetony nie zostały pobrane.',
        'ALREADY_BOOKED'      => 'Jesteś już zapisany(-a) na ten termin.',
        'INSUFFICIENT_TOKENS' => 'Za mało żetonów w jednej puli, by opłacić ten termin.',
        'TIME_CLASH'          => 'O tej porze masz już inny zarezerwowany termin.',
        'ROUND_NOT_STARTED'   => 'Zapisy w tej turze jeszcze się nie rozpoczęły.',
        'ROUND_ENDED', 'ROUND_CLOSED' => 'Zapisy w tej turze zostały zamknięte.',
        'ROUND_LIMIT_REACHED' => 'Osiągnięto limit rezerwacji w tej turze. Zwolnij termin, by wybrać inny.',
        'SLOT_CLOSED'         => 'Ten termin nie przyjmuje już zapisów.',
        'SLOT_IN_PAST'        => 'Ten termin już się rozpoczął.',
        'SLOT_NOT_FOUND'      => 'Nie znaleziono terminu.',
        'BOOKING_NOT_FOUND'   => 'Nie znaleziono rezerwacji.',
        'BOOKING_NOT_ACTIVE'  => 'Ta rezerwacja nie jest już aktywna.',
        'SLOT_ON_LEAVE'       => 'Prowadzący ma urlop w tym dniu — termin nie może powstać.',
        'INSTRUCTOR_NOT_ALLOWED' => 'Ten prowadzący nie jest dostępny dla Twojej grupy w tej turze.',
        'GUARDIAN_MISSING'    => 'Rezerwacja osoby małoletniej wymaga zatwierdzenia rodzica, a na koncie brak adresu e-mail opiekuna — skontaktuj się z sekretariatem.',
        'SLOT_INVALID_TIME'   => 'Nieprawidłowy zakres godzin terminu.',
        default               => 'Operacja nie powiodła się (' . $code . ').',
    };
}
