<?php
/**
 * modules/ti_scheduler/logic/schedulerReports.php — raporty operacyjne i menedżerskie planisty.
 *
 *  sc_report_attendance($ym) — planowana frekwencja grup (lekcje × zapisani × historyczna frekwencja),
 *  sc_report_rooms($ym)      — wykorzystanie sal (godziny zajęte / dostępne, uczestnicy / pojemność),
 *  sc_report_payouts($ym)    — prognoza wynagrodzeń trenerów (odbyte + zaplanowane) z rozbiciem na netto.
 * $ym = 'YYYY-MM'. Wszystkie raporty liczą tylko plan oficjalny (bez scenariuszy).
 */
require_once __DIR__ . '/schedulerSeries.php';

const SC_OPEN_FROM = 8 * 60;    // okno „dostępności" sali w dni robocze
const SC_OPEN_TO   = 20 * 60;

function sc_ym(string $ym): string { return preg_match('/^\d{4}-\d{2}$/', $ym) ? $ym : date('Y-m'); }

/** Minuty trwania lekcji w SQL (kolumna duration_min albo różnica godzin). */
const SC_REP_DUR = "COALESCE(NULLIF(s.duration_min,0), CAST((strftime('%s','2000-01-01 '||s.time_to)-strftime('%s','2000-01-01 '||s.time_from))/60 AS INTEGER))";

/** @return list<array> wiersz na grupę */
function sc_report_attendance(string $ym): array {
    ti_sched_migrate(); $ym = sc_ym($ym);
    $out = [];
    foreach (db_all(
        "SELECT c.id, c.name, c.group_code, COALESCE(u.name,'—') AS iname,
                SUM(CASE WHEN s.status IN ('planned','reserved') THEN 1 ELSE 0 END) AS planned,
                SUM(CASE WHEN s.status IN ('held','individual_change','remote_material') THEN 1 ELSE 0 END) AS held,
                SUM(CASE WHEN s.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS enrolled,
                COALESCE(c.max_students,0) AS max_students
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id LEFT JOIN users u ON u.id=c.instructor_id
          WHERE strftime('%Y-%m', s.lesson_date)=? AND s.draft_id IS NULL AND s.status!='draft'
          GROUP BY c.id ORDER BY c.name", [$ym]) as $r) {
        // historyczna frekwencja z ostatnich 90 dni odbytych lekcji tej grupy
        $h = db_one("SELECT SUM(CASE WHEN a.attended=1 THEN 1 ELSE 0 END) AS yes, COUNT(*) AS n
                       FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
                      WHERE s.course_id=? AND s.status IN ('held','individual_change') AND s.lesson_date>=date('now','-90 days')
                        AND COALESCE(a.cancelled,0)=0", [(int)$r['id']]);
        $rate = (int)($h['n'] ?? 0) >= 5 ? round((int)$h['yes'] / (int)$h['n'] * 100, 1) : null;
        $slots = (int)$r['planned'] * (int)$r['enrolled'];
        $out[] = ['course_id' => (int)$r['id'], 'course' => (string)$r['name'], 'code' => (string)$r['group_code'], 'instructor' => (string)$r['iname'],
            'planned' => (int)$r['planned'], 'held' => (int)$r['held'], 'cancelled' => (int)$r['cancelled'], 'enrolled' => (int)$r['enrolled'], 'max' => (int)$r['max_students'],
            'slots' => $slots, 'rate' => $rate, 'expected' => $rate === null ? $slots : (int)round($slots * $rate / 100),
            'fill' => (int)$r['max_students'] > 0 ? round((int)$r['enrolled'] / (int)$r['max_students'] * 100) : null];
    }
    return $out;
}

/** Dni robocze miesiąca (Pn–Pt bez świąt i dni wolnych). */
function sc_working_days(string $ym): int {
    $n = 0; $d = new \DateTimeImmutable($ym . '-01'); $last = (int)$d->format('t');
    for ($i = 1; $i <= $last; $i++) {
        $day = $d->setDate((int)$d->format('Y'), (int)$d->format('m'), $i);
        if ((int)$day->format('N') >= 6) continue;
        if (sc_day_off_reason($day->format('Y-m-d')) !== '') continue;
        $n++;
    }
    return $n;
}

function sc_report_rooms(string $ym): array {
    ti_sched_migrate(); $ym = sc_ym($ym);
    $avail = sc_working_days($ym) * (SC_OPEN_TO - SC_OPEN_FROM);
    $out = [];
    foreach (db_all(
        "SELECT r.id, r.name, r.capacity, COALESCE(b.name,'') AS building,
                COALESCE(SUM(" . SC_REP_DUR . "),0) AS used_min, COUNT(s.id) AS n,
                COALESCE(SUM((SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=s.course_id AND e.status='active')),0) AS people
           FROM k30_pl_rooms r LEFT JOIN k30_pl_buildings b ON b.id=r.building_id
           LEFT JOIN k30_ti_sessions s ON s.room_id=r.id AND strftime('%Y-%m', s.lesson_date)=? AND s.status NOT IN ('cancelled','draft') AND s.draft_id IS NULL
          WHERE r.is_active=1 GROUP BY r.id ORDER BY b.name, r.name", [$ym]) as $r) {
        $used = (int)$r['used_min'];
        $out[] = ['room_id' => (int)$r['id'], 'room' => (string)$r['name'], 'building' => (string)$r['building'], 'capacity' => (int)$r['capacity'],
            'lessons' => (int)$r['n'], 'used_min' => $used, 'avail_min' => $avail, 'util' => $avail ? round($used / $avail * 100, 1) : 0.0,
            'avg_people' => (int)$r['n'] ? round((int)$r['people'] / (int)$r['n'], 1) : 0.0,
            'seat_fill' => ((int)$r['n'] && (int)$r['capacity']) ? round((int)$r['people'] / (int)$r['n'] / (int)$r['capacity'] * 100) : null];
    }
    return $out;
}

/** Lekcje stacjonarne bez przypisanej sali w miesiącu (do wskaźnika w raporcie sal). */
function sc_rooms_missing_count(string $ym): int {
    return (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions s WHERE strftime('%Y-%m', s.lesson_date)=? AND s.status IN ('planned','reserved')
                            AND COALESCE(s.room_id,0)=0 AND COALESCE(s.lesson_method,'') IN ('','stacjonarna') AND s.draft_id IS NULL", [sc_ym($ym)])['n'] ?? 0);
}

/** Prognoza wynagrodzeń: odbyte (stan faktyczny) + zaplanowane. Stawka za lekcję = c.lesson_payout_bb. */
function sc_report_payouts(string $ym): array {
    ti_sched_migrate(); $ym = sc_ym($ym);
    $rows = db_all(
        "SELECT COALESCE(s.instructor_id,c.instructor_id) AS iid, COALESCE(u.name,'(brak prowadzącego)') AS iname,
                COALESCE(u.ti_payout_form, CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS form,
                COALESCE(s.self_prep_remote,0) AS spr, c.lesson_payout_bb AS bb,
                CASE WHEN s.status IN ('held','individual_change','remote_material') THEN 'held' ELSE 'planned' END AS kind,
                " . SC_REP_DUR . " AS dur
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
           LEFT JOIN users u ON u.id=COALESCE(s.instructor_id,c.instructor_id)
          WHERE strftime('%Y-%m', s.lesson_date)=? AND s.draft_id IS NULL AND c.lesson_payout_bb>0
            AND s.status IN ('held','individual_change','remote_material','planned','reserved')
          ORDER BY iname", [$ym]);
    $by = [];
    foreach ($rows as $r) {
        $iid = (int)$r['iid'];
        $by[$iid] ??= ['instructor_id' => $iid, 'name' => (string)$r['iname'], 'form' => (string)$r['form'], 'held_n' => 0, 'planned_n' => 0, 'held_min' => 0, 'planned_min' => 0,
            'held_bb' => 0.0, 'planned_bb' => 0.0, 'held_netto' => 0.0, 'planned_netto' => 0.0];
        $exempt = in_array($r['form'], ['student', 'b2b'], true) || (bool)$r['spr'];
        $b = k30_ti_payout_breakdown((float)$r['bb'], $exempt);
        $k = $r['kind'];
        $by[$iid][$k . '_n']++; $by[$iid][$k . '_min'] += (int)$r['dur'];
        $by[$iid][$k . '_bb'] += $b['brutto_brutto']; $by[$iid][$k . '_netto'] += $b['netto'];
    }
    foreach ($by as &$x) {
        $x['total_bb'] = round($x['held_bb'] + $x['planned_bb'], 2); $x['total_netto'] = round($x['held_netto'] + $x['planned_netto'], 2);
        $x['total_min'] = $x['held_min'] + $x['planned_min'];
        $lim = $x['instructor_id'] ? sc_limits($x['instructor_id']) : null;
        $x['limit_min'] = (int)($lim['max_month_min'] ?? 0);
        $x['over_limit'] = $x['limit_min'] > 0 && $x['total_min'] > $x['limit_min'];
    }
    unset($x);
    return array_values($by);
}

/** Zbiorcze wskaźniki do paska nad raportami. */
function sc_report_totals(array $pay): array {
    $t = ['held_bb' => 0.0, 'planned_bb' => 0.0, 'total_bb' => 0.0, 'total_netto' => 0.0, 'lessons' => 0, 'hours' => 0.0];
    foreach ($pay as $p) { foreach (['held_bb', 'planned_bb', 'total_bb', 'total_netto'] as $k) $t[$k] += $p[$k]; $t['lessons'] += $p['held_n'] + $p['planned_n']; $t['hours'] += $p['total_min'] / 60; }
    return $t;
}

/**
 * Cykle grup: wzorce zajęć (dzień tygodnia + godziny) z lekcji od dziś, z godzinami i typowym odstępem.
 * @return list<array>
 */
function sc_report_cycles(): array {
    ti_sched_migrate();
    $today = date('Y-m-d');
    $rows = db_all(
        "SELECT c.id, c.name, c.group_code, COALESCE(u.name,'—') AS iname, s.lesson_date, s.time_from, s.time_to, " . SC_REP_DUR . " AS dur, s.status
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id LEFT JOIN users u ON u.id=COALESCE(s.instructor_id,c.instructor_id)
          WHERE s.lesson_date>=? AND s.status IN ('planned','reserved') AND s.draft_id IS NULL
            AND COALESCE(c.closed_at,'')='' AND COALESCE(c.status,'active')!='cancelled'
          ORDER BY c.name, s.lesson_date, s.time_from", [$today]);
    $DN = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sb', 7 => 'Nd'];
    $by = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $by[$id] ??= ['course_id' => $id, 'course' => (string)$r['name'], 'code' => (string)$r['group_code'], 'instructor' => (string)$r['iname'], 'dates' => [], 'pat' => [], 'min' => 0, 'weeks' => []];
        $by[$id]['dates'][] = $r['lesson_date']; $by[$id]['min'] += (int)$r['dur'];
        $k = $DN[(int)date('N', strtotime($r['lesson_date']))] . ' ' . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5);
        $by[$id]['pat'][$k] = ($by[$id]['pat'][$k] ?? 0) + 1;
        $by[$id]['weeks'][date('o-W', strtotime($r['lesson_date']))] = true;
    }
    $out = [];
    foreach ($by as $g) {
        $d = array_values(array_unique($g['dates'])); sort($d); $gaps = [];
        for ($i = 1; $i < count($d); $i++) $gaps[] = (int)round((strtotime($d[$i]) - strtotime($d[$i - 1])) / 86400);
        sort($gaps); $med = $gaps ? $gaps[intdiv(count($gaps), 2)] : 0;
        arsort($g['pat']);
        $nw = max(1, count($g['weeks']));
        $out[] = ['course_id' => $g['course_id'], 'course' => $g['course'], 'code' => $g['code'], 'instructor' => $g['instructor'],
            'lessons' => count($g['dates']), 'min' => $g['min'], 'first' => $d[0], 'last' => end($d), 'gap_days' => $med,
            'per_week_min' => (int)round($g['min'] / $nw), 'weeks' => $nw,
            'pattern' => implode('; ', array_map(fn($k, $v) => "$k (×$v)", array_keys($g['pat']), $g['pat']))];
    }
    return $out;
}

/** Godziny trenerów w kolejnych tygodniach (odbyte + zaplanowane). @return array{weeks:list<string>,rows:list<array>} */
function sc_report_week_load(int $weeks = 8): array {
    ti_sched_migrate();
    $weeks = max(2, min(26, $weeks));
    $mon = date('Y-m-d', strtotime('monday this week'));
    $ws = []; for ($i = 0; $i < $weeks; $i++) $ws[] = date('Y-m-d', strtotime("$mon +$i weeks"));
    $to = date('Y-m-d', strtotime(end($ws) . ' +6 days'));
    $by = [];
    foreach (db_all(
        "SELECT COALESCE(s.instructor_id,c.instructor_id) AS iid, COALESCE(u.name,'(brak)') AS iname, s.lesson_date, " . SC_REP_DUR . " AS dur
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id LEFT JOIN users u ON u.id=COALESCE(s.instructor_id,c.instructor_id)
          WHERE s.lesson_date BETWEEN ? AND ? AND s.status IN ('planned','reserved','held','individual_change','remote_material') AND s.draft_id IS NULL", [$mon, $to]) as $r) {
        $iid = (int)$r['iid'];
        $w = date('Y-m-d', strtotime('monday this week', strtotime($r['lesson_date'])));
        $by[$iid] ??= ['instructor_id' => $iid, 'name' => (string)$r['iname'], 'cells' => array_fill_keys($ws, 0), 'total' => 0];
        if (isset($by[$iid]['cells'][$w])) { $by[$iid]['cells'][$w] += (int)$r['dur']; $by[$iid]['total'] += (int)$r['dur']; }
    }
    foreach ($by as &$x) { $lim = $x['instructor_id'] ? sc_limits($x['instructor_id']) : null; $x['week_limit'] = (int)($lim['max_week_min'] ?? 0); }
    unset($x);
    usort($by, fn($a, $b) => strcmp($a['name'], $b['name']));
    return ['weeks' => $ws, 'rows' => array_values($by)];
}
