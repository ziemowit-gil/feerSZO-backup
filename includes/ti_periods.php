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

    // Zamykanie okresu (kolumny mogą już istnieć — bezpieczny ALTER)
    foreach ([
        "ALTER TABLE k30_ti_periods ADD COLUMN closed_at     TEXT",
        "ALTER TABLE k30_ti_periods ADD COLUMN closed_by     INTEGER",
        "ALTER TABLE k30_ti_periods ADD COLUMN closed_name   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_periods ADD COLUMN close_note    TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_periods ADD COLUMN reopened_at   TEXT",
        "ALTER TABLE k30_ti_periods ADD COLUMN reopened_by   INTEGER",
        "ALTER TABLE k30_ti_periods ADD COLUMN reopened_name TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_periods ADD COLUMN reopen_reason TEXT NOT NULL DEFAULT ''",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
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

// ═══════════════════════════════════════════════════════════════════════════
//  ZAMYKANIE OKRESU — warunkiem są zatwierdzone protokoły zajęć kursów
//  Zamknięty okres to koniec rozliczenia dydaktycznego: nie da się w nim
//  ustawiać ani przesuwać zajęć, a protokołów nie można odblokować, dopóki
//  okres nie zostanie otwarty ponownie (admin, z powodem).
//  Patrz [[project_ti_protocols]].
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Gotowość okresu do zamknięcia: które kursy miały w nim zajęcia i czy każdy
 * ma zatwierdzony protokół.
 *
 * @return array{ready:bool, courses:array<int,array{course_id:int,name:string,sessions:int,protocol_id:int,status:string}>, missing:int}
 */
function ti_period_close_readiness(int $period_id): array {
    ti_periods_migrate();
    require_once __DIR__ . '/ti_protocols.php';
    ti_protocols_migrate();

    $per = ti_period_get($period_id);
    if (!$per) return ['ready' => false, 'courses' => [], 'missing' => 0];

    // Kursy z zajęciami w okresie (odwołane i szkice się nie liczą)
    $rows = db_all(
        "SELECT s.course_id, c.name, COUNT(*) AS n
           FROM k30_ti_sessions s
           JOIN k30_ti_courses  c ON c.id = s.course_id
          WHERE s.lesson_date BETWEEN ? AND ?
            AND s.status NOT IN ('cancelled','draft')
          GROUP BY s.course_id, c.name
          ORDER BY c.name COLLATE NOCASE",
        [$per['date_from'], $per['date_to']]
    );

    $courses = []; $missing = 0;
    foreach ($rows as $r) {
        $cid  = (int)$r['course_id'];
        $prot = db_one(
            "SELECT id, status FROM k30_ti_protocols
              WHERE course_id=? AND COALESCE(period_id,0)=CAST(? AS INTEGER)",
            [$cid, $period_id]
        );
        $status = $prot ? (string)$prot['status'] : 'none';
        if ($status !== 'approved') $missing++;
        $courses[] = [
            'course_id'   => $cid,
            'name'        => (string)$r['name'],
            'sessions'    => (int)$r['n'],
            'protocol_id' => (int)($prot['id'] ?? 0),
            'status'      => $status,
        ];
    }
    return ['ready' => $missing === 0, 'courses' => $courses, 'missing' => $missing];
}

/** Czy okres jest zamknięty. */
function ti_period_is_closed(array $period): bool {
    return !empty($period['closed_at']);
}

/**
 * Zamyka okres. Wymaga zatwierdzonych protokołów dla wszystkich kursów,
 * które miały w nim zajęcia.
 *
 * @throws RuntimeException gdy okres nie istnieje, jest już zamknięty
 *                          albo brakuje zatwierdzonych protokołów.
 */
function ti_period_close(int $period_id, ?int $by, string $by_name, string $note = ''): void {
    ti_periods_migrate();
    $per = ti_period_get($period_id);
    if (!$per)                    throw new \RuntimeException('Okres nie istnieje.');
    if (ti_period_is_closed($per)) throw new \RuntimeException('Ten okres jest już zamknięty.');

    $r = ti_period_close_readiness($period_id);
    if (!$r['ready']) {
        $names = [];
        foreach ($r['courses'] as $c) {
            if ($c['status'] !== 'approved') $names[] = $c['name'];
        }
        throw new \RuntimeException(
            'Nie można zamknąć okresu: ' . $r['missing'] . ' '
            . ($r['missing'] === 1 ? 'kurs nie ma' : 'kursów nie ma')
            . ' zatwierdzonego protokołu zajęć za ten okres (' . implode(', ', array_slice($names, 0, 5))
            . (count($names) > 5 ? ' i inne' : '') . ').'
        );
    }
    db()->prepare(
        "UPDATE k30_ti_periods
            SET closed_at=datetime('now'), closed_by=?, closed_name=?, close_note=?, updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, trim($note), $period_id]);
}

/** Otwiera zamknięty okres ponownie (admin). Powód wymagany i zapisywany. */
function ti_period_reopen(int $period_id, ?int $by, string $by_name, string $reason): void {
    ti_periods_migrate();
    $per = ti_period_get($period_id);
    if (!$per)                      throw new \RuntimeException('Okres nie istnieje.');
    if (!ti_period_is_closed($per)) throw new \RuntimeException('Ten okres nie jest zamknięty.');
    $reason = trim($reason);
    if ($reason === '')             throw new \RuntimeException('Podaj powód ponownego otwarcia okresu.');

    db()->prepare(
        "UPDATE k30_ti_periods
            SET closed_at=NULL, reopened_by=?, reopened_name=?, reopened_at=datetime('now'),
                reopen_reason=?, updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, $reason, $period_id]);
}

/**
 * Zamknięty okres obejmujący datę (albo null) — bramka dla ustawiania zajęć.
 * Cache w obrębie requestu: seria lekcji pyta o wiele dat.
 */
function ti_period_closed_for_date(string $date): ?array {
    static $cache = [];
    $date = trim($date);
    if ($date === '') return null;
    if (array_key_exists($date, $cache)) return $cache[$date];
    ti_periods_migrate();
    try {
        $row = db_one(
            "SELECT * FROM k30_ti_periods
              WHERE closed_at IS NOT NULL AND date_from <= ? AND date_to >= ?
              ORDER BY date_from DESC LIMIT 1",
            [$date, $date]
        );
    } catch (\Throwable $e) { $row = null; }
    return $cache[$date] = $row;
}

/** Komunikat bramki zamkniętego okresu. */
function ti_period_closed_msg(array $period): string {
    return 'Okres „' . (string)$period['name'] . '" jest zamknięty ('
        . date('d.m.Y', strtotime((string)$period['date_from'])) . '–'
        . date('d.m.Y', strtotime((string)$period['date_to']))
        . ') — zajęć w nim nie można już dodawać ani przesuwać. '
        . 'Otworzyć okres ponownie może administrator.';
}
