<?php
/**
 * includes/ti_periods.php — Okresy nauczania TI (kwartały, rok szkolny, wakacje).
 *
 * Okresy są GLOBALNE dla modułu TI (jeden wspólny kalendarz akademicki).
 * Przynależność lekcji do okresu wynika z jej daty (lesson_date ∈ [date_from,date_to]).
 * Służą do: kopiowania/przenoszenia zajęć między okresami (narzędzie masowe)
 * oraz do informowania kursanta i dydaktyka o trwających wakacjach.
 *
 * Osobne od k30_ti_holidays (dni wolne / przerwy) — te nie wpływają na baner wakacyjny.
 */

if (!defined('TI_PERIOD_TYPES')) {
    define('TI_PERIOD_TYPES', [
        'quarter'     => ['label' => 'Okres 3-miesięczny (kwartał)', 'short' => 'Kwartał',     'icon' => 'bi-calendar3',       'color' => '#2563eb', 'bg' => '#eff6ff'],
        'school_year' => ['label' => 'Rok szkolny (10-miesięczny)',  'short' => 'Rok szkolny', 'icon' => 'bi-mortarboard',     'color' => '#7c3aed', 'bg' => '#f5f3ff'],
        'vacation'    => ['label' => 'Okres wakacyjny',              'short' => 'Wakacje',     'icon' => 'bi-sun',             'color' => '#d97706', 'bg' => '#fffbeb'],
    ]);
}

/** Tworzy tabelę okresów (samonaprawa schematu — wołane z każdego punktu wejścia). */
function ti_periods_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_periods (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            type        TEXT    NOT NULL DEFAULT 'quarter',
            date_from   TEXT    NOT NULL,
            date_to     TEXT    NOT NULL,
            note        TEXT    NOT NULL DEFAULT '',
            created_by  INTEGER,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_periods_dates ON k30_ti_periods(date_from, date_to)");
    } catch (\Throwable $e) {}
}

/** Etykieta typu okresu. */
function ti_period_type_label(string $type, bool $short = false): string {
    $t = TI_PERIOD_TYPES[$type] ?? null;
    if (!$t) return $type;
    return $short ? $t['short'] : $t['label'];
}

/** Meta typu (icon/color/bg/label). */
function ti_period_type_meta(string $type): array {
    return TI_PERIOD_TYPES[$type] ?? ['label' => $type, 'short' => $type, 'icon' => 'bi-calendar', 'color' => '#6b7280', 'bg' => '#f9fafb'];
}

/** Wszystkie okresy (opcjonalnie filtr typu), posortowane od najnowszych. */
function ti_periods_all(?string $type = null): array {
    ti_periods_migrate();
    if ($type !== null && $type !== '') {
        return db_all("SELECT * FROM k30_ti_periods WHERE type=? ORDER BY date_from DESC, id DESC", [$type]);
    }
    return db_all("SELECT * FROM k30_ti_periods ORDER BY date_from DESC, id DESC");
}

/** Pojedynczy okres. */
function ti_period_get(int $id): ?array {
    ti_periods_migrate();
    return db_one("SELECT * FROM k30_ti_periods WHERE id=?", [$id]) ?: null;
}

/** Liczba dni w okresie (włącznie z krańcami). */
function ti_period_days(array $period): int {
    $a = new DateTime($period['date_from']);
    $b = new DateTime($period['date_to']);
    return (int)$a->diff($b)->days + 1;
}

/**
 * Aktualnie trwający okres wakacyjny (type=vacation, date_from ≤ dziś ≤ date_to).
 * Zwraca wiersz okresu + wyliczone pole 'resume_date' (dzień wznowienia = dzień po zakończeniu),
 * albo null gdy dziś nie ma wakacji.
 */
function ti_current_vacation(?string $today = null): ?array {
    ti_periods_migrate();
    $today = $today ?: date('Y-m-d');
    $row = db_one(
        "SELECT * FROM k30_ti_periods
         WHERE type='vacation' AND date_from <= ? AND date_to >= ?
         ORDER BY date_to DESC LIMIT 1",
        [$today, $today]
    );
    if (!$row) return null;
    $row['resume_date'] = date('Y-m-d', strtotime($row['date_to'] . ' +1 day'));
    return $row;
}

/**
 * Lekcje należące do okresu (po dacie), opcjonalnie zawężone do wybranych kursów.
 * @param int[] $course_ids  pusty => wszystkie kursy
 */
function ti_period_sessions(array $period, array $course_ids = []): array {
    ti_periods_migrate();
    $params = [$period['date_from'], $period['date_to']];
    $sql = "SELECT s.*, c.name AS course_name, c.instructor_id
              FROM k30_ti_sessions s
              JOIN k30_ti_courses c ON c.id = s.course_id
             WHERE s.lesson_date >= ? AND s.lesson_date <= ?
               AND c.status != 'cancelled'";
    $course_ids = array_values(array_filter(array_map('intval', $course_ids)));
    if ($course_ids) {
        $sql .= " AND s.course_id IN (" . implode(',', array_fill(0, count($course_ids), '?')) . ")";
        $params = array_merge($params, $course_ids);
    }
    $sql .= " ORDER BY s.course_id, s.lesson_date, s.time_from";
    return db_all($sql, $params);
}

/** Czy dana data wpada w dzień wolny / przerwę (k30_ti_holidays)? */
function ti_date_is_off(string $date): bool {
    try {
        $row = db_one("SELECT 1 FROM k30_ti_holidays WHERE date_from <= ? AND date_to >= ? LIMIT 1", [$date, $date]);
        return (bool)$row;
    } catch (\Throwable $e) { return false; }
}

/**
 * Mapuje datę lekcji z okresu źródłowego na docelowy przez przesunięcie o offset
 * (target.date_from − source.date_from). Gdy $skip_off=true i data trafia na
 * dzień wolny/przerwę — przesuwa na najbliższy kolejny dzień roboczy (max 31 dni).
 */
function ti_period_map_date(string $lesson_date, array $src, array $dst, bool $skip_off = false): string {
    $offset = (new DateTime($src['date_from']))->diff(new DateTime($dst['date_from']));
    $signed = ($dst['date_from'] >= $src['date_from']) ? 1 : -1;
    $d = new DateTime($lesson_date);
    $days = (int)$offset->days * $signed;
    $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
    $out = $d->format('Y-m-d');
    if ($skip_off) {
        $guard = 0;
        while (ti_date_is_off($out) && $guard < 31) {
            $d->modify('+1 day');
            $out = $d->format('Y-m-d');
            $guard++;
        }
    }
    return $out;
}
