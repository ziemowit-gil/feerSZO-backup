<?php
/**
 * modules/ti_edziennik/logic/courseResults.php — wynik kursu w e-dzienniku TI (ocena końcowa + zaliczenie).
 *
 * Oceny okresowe żyją w protokołach (k30_ti_protocol_entries, po zatwierdzeniu). Brakowało formalnego wyniku
 * CAŁEGO kursu: „zaliczył / nie zaliczył / nie ukończył” z oceną końcową — tu k30_ti_course_results
 * (jeden wiersz na kursanta w grupie). Prowadzący wystawia go w zakładce Oceny; system podpowiada ocenę
 * (ostatnia z zatwierdzonego protokołu, inaczej zaokrąglona średnia ważona) i wynik (frekwencja poniżej progu
 * albo ocena 1 → „nie zaliczył”). Wynik trafia do dziennika PDF (modules/ti_pdf/logic/dziennik.php).
 * Zapis blokuje zamknięcie grupy (ti_course_closed_guard w POST panelu).
 */

const TI_COURSE_RESULTS = [
    'passed'     => 'zaliczył(a)',
    'failed'     => 'nie zaliczył(a)',
    'incomplete' => 'nie ukończył(a)',
];

function tiCourseResultsMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('ti_course_results', __FILE__)) return;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_course_results (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id    INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
        client_id    INTEGER NOT NULL REFERENCES k30_clients(id)    ON DELETE CASCADE,
        result       TEXT    NOT NULL CHECK (result IN ('passed','failed','incomplete')),
        final_grade  TEXT    NOT NULL DEFAULT '',
        note         TEXT    NOT NULL DEFAULT '',
        decided_by   INTEGER,
        decided_name TEXT    NOT NULL DEFAULT '',
        decided_at   DATETIME,
        UNIQUE(course_id, client_id)
    )");
}

/** Wyniki grupy: [client_id => wiersz]. */
function tiCourseResults(int $courseId): array
{
    tiCourseResultsMigrate();
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_course_results WHERE course_id=?", [$courseId]) as $r) $out[(int)$r['client_id']] = $r;
    return $out;
}

/** Ostatnia ocena z zatwierdzonego protokołu grupy: [client_id => value_text]. */
function tiCourseProtocolFinals(int $courseId): array
{
    $out = [];
    try {
        foreach (db_all("SELECT e.client_id, e.value_text FROM k30_ti_protocol_entries e JOIN k30_ti_protocols p ON p.id = e.protocol_id
                          WHERE p.course_id = ? AND p.status = 'approved' AND e.value_text != '' ORDER BY p.approved_at", [$courseId]) as $r) {
            $out[(int)$r['client_id']] = (string)$r['value_text'];
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** Próg frekwencji grupy (%): własny grupy albo globalny (attendanceThreshold.php). */
function tiCourseResultsThreshold(int $courseId): int
{
    require_once __DIR__ . '/attendanceThreshold.php';
    return tiAttThreshold($courseId);
}

/**
 * Podpowiedź systemu dla kursanta: ['grade' => '4', 'result' => 'passed'|'failed'|null, 'why' => '…'].
 * $grades — oceny cząstkowe kursanta w grupie; $protocolFinal — ocena z ostatniego zatwierdzonego protokołu.
 */
function tiCourseResultSuggest(int $courseId, int $clientId, array $grades, ?string $protocolFinal): array
{
    $avg   = k30_ti_grades_average($grades);
    $grade = $protocolFinal !== null && $protocolFinal !== '' ? $protocolFinal
           : ($avg !== null ? (string)max(1, min(6, (int)round($avg))) : '');
    $num   = $grade !== '' ? k30_ti_grade_parse_num($grade) : null;
    $why   = [];
    $result = null;
    if ($num !== null) {
        $result = $num < 2 ? 'failed' : 'passed';
        $why[]  = $protocolFinal ? 'ocena z protokołu' : 'średnia ważona ' . number_format((float)$avg, 2, ',', '');
    }
    if (k30_ti_course_tracks_attendance($courseId)) {
        $att = k30_ti_client_course_attendance($courseId, $clientId);
        $thr = tiCourseResultsThreshold($courseId);
        if ($att['countable'] > 0) {
            $why[] = 'frekwencja ' . $att['pct'] . '% (próg ' . $thr . '%)';
            if ($att['pct'] < $thr) $result = 'failed';
            elseif ($result === null) $result = 'passed';
        }
    }
    return ['grade' => $grade, 'result' => $result, 'why' => implode(', ', $why)];
}

/** Zapis wyniku jednego kursanta; $result '' = usuń wpis. Zwraca true, gdy coś zmieniono. */
function tiCourseResultSave(int $courseId, int $clientId, string $result, string $grade, string $note, int $by, string $byName): bool
{
    tiCourseResultsMigrate();
    $grade = mb_substr(trim($grade), 0, 10);
    $note  = mb_substr(trim($note), 0, 300);
    $cur   = db_one("SELECT result, final_grade, note FROM k30_ti_course_results WHERE course_id=? AND client_id=?", [$courseId, $clientId]);
    if ($result === '' || !isset(TI_COURSE_RESULTS[$result])) {
        if (!$cur) return false;
        db()->prepare("DELETE FROM k30_ti_course_results WHERE course_id=? AND client_id=?")->execute([$courseId, $clientId]);
        return true;
    }
    if ($cur && $cur['result'] === $result && $cur['final_grade'] === $grade && $cur['note'] === $note) return false;
    db()->prepare("INSERT INTO k30_ti_course_results (course_id, client_id, result, final_grade, note, decided_by, decided_name, decided_at)
                   VALUES (?,?,?,?,?,?,?,datetime('now'))
                   ON CONFLICT(course_id, client_id) DO UPDATE SET result=excluded.result, final_grade=excluded.final_grade,
                       note=excluded.note, decided_by=excluded.decided_by, decided_name=excluded.decided_name, decided_at=excluded.decided_at")
        ->execute([$courseId, $clientId, $result, $grade, $note, $by ?: null, $byName]);
    return true;
}
