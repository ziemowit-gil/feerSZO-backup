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
