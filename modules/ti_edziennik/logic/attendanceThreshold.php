<?php
/**
 * modules/ti_edziennik/logic/attendanceThreshold.php — próg frekwencji grupy TI i ostrzeżenia dla kadry.
 *
 * Wcześniej był tylko globalny próg (ustawienie ti_low_attendance_pct, domyślnie 50%) i jednorazowy alert
 * do kursanta/opiekuna (k30_ti_check_low_attendance w includes/karty30.php). Tu dochodzi:
 *   - własny próg grupy k30_ti_courses.min_attendance_pct (NULL = globalny), np. 80% dla grup PFRON;
 *   - strefa ostrzeżenia: do TI_ATT_WARN_MARGIN punktów nad progiem („blisko progu”);
 *   - poziom kursanta zapamiętany w k30_ti_enrollments.att_level ('', 'ok', 'warn', 'low'); każde wejście
 *     w 'warn' / 'low' zapisuje zdarzenie w k30_ti_attendance_alerts — czyta je dzwonek prowadzącego
 *     i kierownika (modules/ti_dyd_notifications);
 *   - oznaczenia w dzienniku (zakładki Nieobecności i Oceny) — tiAttCourseOverview().
 * Poniżej 3 lekcji do policzenia frekwencji nie oceniamy (jak w alercie dla kursanta).
 */

const TI_ATT_WARN_MARGIN = 10;
const TI_ATT_MIN_LESSONS = 3;

function tiAttMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('ti_att_threshold', __FILE__)) return;
    foreach ([
        "ALTER TABLE k30_ti_courses     ADD COLUMN min_attendance_pct INTEGER",
        "ALTER TABLE k30_ti_enrollments ADD COLUMN att_level TEXT NOT NULL DEFAULT ''",
    ] as $sql) { try { db()->exec($sql); } catch (\Throwable $e) {} }
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_attendance_alerts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id  INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        client_id  INTEGER NOT NULL REFERENCES k30_clients(id)    ON DELETE CASCADE,
        level      TEXT    NOT NULL CHECK (level IN ('warn','low')),
        pct        INTEGER NOT NULL,
        threshold  INTEGER NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_att_alerts_course ON k30_ti_attendance_alerts(course_id, created_at)");
}

/** Własny próg grupy albo null (= globalny). */
function tiAttCourseThreshold(int $courseId): ?int
{
    tiAttMigrate();
    $v = db_one("SELECT min_attendance_pct FROM k30_ti_courses WHERE id=?", [$courseId])['min_attendance_pct'] ?? null;
    return ($v !== null && (int)$v > 0 && (int)$v <= 100) ? (int)$v : null;
}

/** Obowiązujący próg grupy w %. */
function tiAttThreshold(int $courseId): int
{
    return tiAttCourseThreshold($courseId) ?? k30_ti_low_attendance_threshold();
}

/** Ustawia próg grupy; null / 0 = wróć do globalnego. Przelicza poziomy kursantów (bez wysyłki do nich). */
function tiAttSetThreshold(int $courseId, ?int $pct): void
{
    tiAttMigrate();
    $pct = ($pct !== null && $pct > 0 && $pct <= 100) ? $pct : null;
    db()->prepare("UPDATE k30_ti_courses SET min_attendance_pct=? WHERE id=?")->execute([$pct, $courseId]);
    foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$courseId]) as $r) {
        tiAttCheck($courseId, (int)$r['client_id']);
    }
}

/** Poziom: '' (za mało lekcji), 'ok', 'warn' (blisko progu), 'low' (poniżej progu). */
function tiAttLevel(int $pct, int $countable, int $threshold): string
{
    if ($countable < TI_ATT_MIN_LESSONS) return '';
    if ($pct < $threshold) return 'low';
    if ($pct < min(100, $threshold + TI_ATT_WARN_MARGIN)) return 'warn';
    return 'ok';
}

/**
 * Przelicza poziom kursanta i — przy wejściu w 'warn' lub 'low' — zapisuje zdarzenie dla kadry.
 * Wołane z k30_ti_check_low_attendance() po każdym zapisie obecności. Zwraca nowy poziom.
 */
function tiAttCheck(int $courseId, int $clientId): string
{
    tiAttMigrate();
    if (!k30_ti_course_tracks_attendance($courseId)) return '';
    $enr = db_one("SELECT id, att_level FROM k30_ti_enrollments WHERE course_id=? AND client_id=? AND status='active'", [$courseId, $clientId]);
    if (!$enr) return '';
    $st  = k30_ti_client_course_attendance($courseId, $clientId);
    $thr = tiAttThreshold($courseId);
    $lvl = tiAttLevel($st['pct'], $st['countable'], $thr);
    if ($lvl === (string)$enr['att_level']) return $lvl;
    db()->prepare("UPDATE k30_ti_enrollments SET att_level=? WHERE id=?")->execute([$lvl, (int)$enr['id']]);
    if ($lvl === 'warn' || $lvl === 'low') {
        db_insert('k30_ti_attendance_alerts', ['course_id' => $courseId, 'client_id' => $clientId, 'level' => $lvl,
                                               'pct' => $st['pct'], 'threshold' => $thr, 'created_at' => date('Y-m-d H:i:s')]);
    }
    return $lvl;
}

/** Frekwencja aktywnych kursantów grupy: [client_id => ['pct','present','countable','level']]. */
function tiAttCourseOverview(int $courseId): array
{
    if (!k30_ti_course_tracks_attendance($courseId)) return [];
    $thr = tiAttThreshold($courseId);
    $out = [];
    foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$courseId]) as $r) {
        $st = k30_ti_client_course_attendance($courseId, (int)$r['client_id']);
        $out[(int)$r['client_id']] = $st + ['level' => tiAttLevel($st['pct'], $st['countable'], $thr)];
    }
    return $out;
}

/** Plakietka poziomu do dziennika (HTML) — '' gdy wszystko w porządku. */
function tiAttBadge(array $st, int $threshold): string
{
    $lvl = $st['level'] ?? '';
    if ($lvl !== 'warn' && $lvl !== 'low') return '';
    $txt = $lvl === 'low' ? 'poniżej progu' : 'blisko progu';
    $cls = $lvl === 'low' ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle' : 'bg-warning-subtle text-warning-emphasis border-warning-subtle';
    return '<span class="badge border ' . $cls . ' ms-1" title="Frekwencja ' . (int)$st['pct'] . '%, próg grupy ' . $threshold . '%">'
         . '<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>frekw. ' . (int)$st['pct'] . '% — ' . $txt . '</span>';
}

/** Zdarzenia dla dzwonka: grupy prowadzącego $uid albo wszystkie (kierownik). */
function tiAttAlertsFor(int $uid, bool $all, string $since, int $limit = 15): array
{
    tiAttMigrate();
    $where = $all ? '' : ' AND c.instructor_id = :uid';
    try {
        return db_all("SELECT al.created_at AS ts, al.course_id, al.level, al.pct, al.threshold, cl.name AS who,
                              COALESCE(NULLIF(c.display_name, ''), c.name) AS course
                         FROM k30_ti_attendance_alerts al JOIN k30_ti_courses c ON c.id = al.course_id
                         LEFT JOIN k30_clients cl ON cl.id = al.client_id
                        WHERE al.created_at >= :since{$where}
                        ORDER BY al.created_at DESC LIMIT {$limit}", $all ? ['since' => $since] : ['since' => $since, 'uid' => $uid]);
    } catch (\Throwable $e) { return []; }
}
