<?php
/**
 * includes/ti_leaves.php — Urlopy / nieobecności prowadzących TI.
 *
 * Modularny rejestr zakresów dat niedostępności kadry (urlop, chorobowe, inne),
 * z helperami do dashboardu (trwające / nadchodzące) oraz sprawdzania kolizji
 * przy planowaniu lekcji.
 */

/** Rodzaje nieobecności (kod => etykieta). */
const TI_LEAVE_TYPES = [
    'urlop'      => 'Urlop',
    'chorobowe'  => 'Chorobowe (L4)',
    'okolicznosc'=> 'Okolicznościowy',
    'inne'       => 'Inne',
];

function ti_leave_type_label(string $code): string {
    return TI_LEAVE_TYPES[$code] ?? $code;
}

function ti_leaves_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_leaves (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date_from     DATE    NOT NULL,
        date_to       DATE    NOT NULL,
        type          TEXT    NOT NULL DEFAULT 'urlop',
        note          TEXT    NOT NULL DEFAULT '',
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_leaves_instr ON k30_ti_instructor_leaves(instructor_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_leaves_dates ON k30_ti_instructor_leaves(date_from, date_to)");
    // Śledzenie powiadomień kursantów (dokładane bezpiecznie do istniejących baz)
    foreach ([
        "ALTER TABLE k30_ti_instructor_leaves ADD COLUMN notified_at   DATETIME",
        "ALTER TABLE k30_ti_instructor_leaves ADD COLUMN notified_hash TEXT NOT NULL DEFAULT ''",
    ] as $sql) { try { db()->exec($sql); } catch (\Throwable $e) {} }
}

/** Pełna lista urlopów (z nazwą prowadzącego). */
function ti_leaves_all(): array {
    ti_leaves_migrate();
    return db_all(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         ORDER BY l.date_from DESC, l.id DESC"
    );
}

function ti_leave_get(int $id): ?array {
    ti_leaves_migrate();
    return db_one("SELECT * FROM k30_ti_instructor_leaves WHERE id=?", [$id]) ?: null;
}

/** Urlopy trwające w danym dniu (domyślnie dziś). */
function ti_leaves_current(string $day = ''): array {
    ti_leaves_migrate();
    $day = $day !== '' ? $day : date('Y-m-d');
    return db_all(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         WHERE l.date_from <= ? AND l.date_to >= ?
         ORDER BY l.date_to ASC, instructor_name",
        [$day, $day]
    );
}

/** Urlopy nadchodzące w najbliższych N dniach (zaczynające się po dziś). */
function ti_leaves_upcoming(int $days = 30): array {
    ti_leaves_migrate();
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime("+{$days} days"));
    return db_all(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         WHERE l.date_from > ? AND l.date_from <= ?
         ORDER BY l.date_from ASC, instructor_name",
        [$today, $until]
    );
}

/**
 * Czy prowadzący jest nieobecny w danym dniu? Zwraca wiersz urlopu lub null.
 * Przydatne do ostrzeżeń przy planowaniu lekcji.
 */
function ti_instructor_on_leave(int $instructor_id, string $day): ?array {
    ti_leaves_migrate();
    if (!$instructor_id || $day === '') return null;
    return db_one(
        "SELECT * FROM k30_ti_instructor_leaves
         WHERE instructor_id=? AND date_from <= ? AND date_to >= ?
         ORDER BY date_to DESC LIMIT 1",
        [$instructor_id, $day, $day]
    ) ?: null;
}

/**
 * Urlopy prowadzących, którzy uczą danego kursanta (aktywne zapisy) —
 * trwające dziś + nadchodzące w ciągu N dni. Do bannera w panelu kursanta.
 * Zwraca też listę nazw kursów, na które wpływa nieobecność (course_names).
 */
function ti_leaves_for_client(int $client_id, int $days = 30): array {
    ti_leaves_migrate();
    if (!$client_id) return [];
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime("+{$days} days"));
    return db_all(
        "SELECT l.id, l.instructor_id, l.date_from, l.date_to, l.type, l.note,
                COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name,
                GROUP_CONCAT(DISTINCT c.name) AS course_names
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         JOIN k30_ti_courses c ON c.instructor_id=l.instructor_id
         JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=? AND e.status='active'
         WHERE l.date_to >= ? AND l.date_from <= ?
         GROUP BY l.id
         ORDER BY l.date_from ASC, instructor_name",
        [$client_id, $today, $until]
    );
}

/** Aktywni kursanci uczeni przez danego prowadzącego (adresaci powiadomień o urlopie). */
function ti_leave_affected_students(int $instructor_id): array {
    ti_leaves_migrate();
    if (!$instructor_id) return [];
    return db_all(
        "SELECT DISTINCT a.id, a.is_minor, a.guardian_email, cl.name, cl.email
         FROM k30_ti_courses c
         JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.status='active'
         JOIN k30_ti_student_accounts a ON a.client_id=e.client_id AND a.is_active=1
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE c.instructor_id=?",
        [$instructor_id]
    );
}

/**
 * Powiadom kursantów prowadzącego o zaplanowanym urlopie (e-mail do kolejki).
 * Idempotentne: nie wysyła ponownie, dopóki zakres/rodzaj urlopu się nie zmienił
 * (chyba że $force). Pomija urlopy już zakończone. Zwraca liczbę wysłanych maili.
 */
function ti_leaves_notify(int $leave_id, bool $force = false): int {
    ti_leaves_migrate();
    $l = db_one(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l JOIN users u ON u.id=l.instructor_id WHERE l.id=?",
        [$leave_id]
    );
    if (!$l) return 0;
    if ($l['date_to'] < date('Y-m-d')) return 0;                 // urlop już za nami
    $hash = md5($l['instructor_id'] . '|' . $l['date_from'] . '|' . $l['date_to'] . '|' . $l['type']);
    if (!$force && ($l['notified_hash'] ?? '') === $hash) return 0; // już powiadomiono o tym zakresie

    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    $url   = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php';
    $range = date('d.m.Y', strtotime($l['date_from']));
    if ($l['date_to'] !== $l['date_from']) $range .= ' – ' . date('d.m.Y', strtotime($l['date_to']));
    $typeLabel = ti_leave_type_label($l['type']);

    $sent = 0;
    foreach (ti_leave_affected_students((int)$l['instructor_id']) as $s) {
        // Adresaci: główny e-mail kursanta + e-mail opiekuna (gdy małoletni)
        $emails  = [];
        $primary = trim((string)($s['email'] ?? ''));
        if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = (string)$s['name'];
        $gemail  = trim((string)($s['guardian_email'] ?? ''));
        if (!empty($s['is_minor']) && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) $emails[$gemail] = (string)$s['name'];
        if (!$emails || !function_exists('mail_queue_add')) continue;

        $html = "<p>Dzień dobry,</p>"
              . "<p>Informujemy, że prowadzący <strong>" . htmlspecialchars((string)$l['instructor_name'], ENT_QUOTES) . "</strong> "
              . "ma zaplanowaną nieobecność (" . htmlspecialchars($typeLabel, ENT_QUOTES) . ") w terminie <strong>" . htmlspecialchars($range, ENT_QUOTES) . "</strong>.</p>"
              . "<p>W tym czasie zajęcia mogą zostać odwołane lub przełożone. Aktualny harmonogram i ewentualne zmiany znajdziesz w panelu kursanta.</p>"
              . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Otwórz panel kursanta</a></p>"
              . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";
        foreach ($emails as $addr => $nm) {
            try { mail_queue_add($addr, $nm, "{$org}: nieobecność prowadzącego ({$range})", $html, '', 'ti_leave', (int)$l['id'], '', false); $sent++; }
            catch (\Throwable $e) {}
        }
    }
    db()->prepare("UPDATE k30_ti_instructor_leaves SET notified_at=datetime('now'), notified_hash=? WHERE id=?")
       ->execute([$hash, $leave_id]);
    return $sent;
}
