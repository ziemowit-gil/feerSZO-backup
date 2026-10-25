<?php
/**
 * modules/ti_scheduler/logic/schedulerValidator.php — walidacja terminu lekcji.
 *
 * sc_validate() to jeden punkt wejścia dla planisty (przeciąganie, edycja):
 *   hard — blokują zapis (nakładanie trener/sala/grupa/Zoom, urlop, brak
 *          kwalifikacji, zamknięty okres, przekroczony limit „twardy"),
 *   soft — ostrzeżenia do potwierdzenia (limity godzin, ciąg zajęć, dzień wolny,
 *          pojemność sali).
 * Wpis hard z 'overridable'=>true kierownik może świadomie zatwierdzić.
 */
require_once __DIR__ . '/schedulerSchema.php';

const SC_CHAIN_GAP_MIN = 15;   // przerwa krótsza lub równa tej wartości = zajęcia „pod rząd"

/** Minuty trwania wyrażenia SQL dla lekcji (duration_min albo z godzin). */
const SC_DUR_SQL = "COALESCE(NULLIF(s.duration_min,0),
    CAST((strftime('%s','2000-01-01 '||s.time_to)-strftime('%s','2000-01-01 '||s.time_from))/60 AS INTEGER))";

/**
 * Kurs hybrydowy: aktywny uczestnik ma osobną stawkę online (hourly_rate_online > 0) albo grupa
 * ma już lekcje i stacjonarne, i zdalne. W takim kursie każda lekcja musi mieć jawną metodę,
 * bo od niej zależy stawka (patrz ti_session_is_online).
 */
function ti_course_is_hybrid(int $course_id): bool {
    static $c = [];
    if (!$course_id) return false;
    if (!isset($c[$course_id])) {
        $r = db_one("SELECT
            (SELECT COUNT(*) FROM k30_ti_courses WHERE id=? AND class_type='hybrid') AS typed,
            (SELECT COUNT(*) FROM k30_ti_enrollments WHERE course_id=? AND status='active' AND COALESCE(hourly_rate_online,0)>0.005) AS online_rate,
            (SELECT COUNT(DISTINCT CASE WHEN lesson_method='stacjonarna' THEN 's' WHEN lesson_method IN ('zdalna_zoom','zdalna_inne') THEN 'r' END)
               FROM k30_ti_sessions WHERE course_id=? AND status!='cancelled' AND lesson_method!='') AS kinds", [$course_id, $course_id, $course_id]);
        $c[$course_id] = (int)($r['typed'] ?? 0) > 0 || (int)($r['online_rate'] ?? 0) > 0 || (int)($r['kinds'] ?? 0) >= 2;
    }
    return $c[$course_id];
}

function sc_effective_instructor(int $course_id, int $instructor_id = 0): int {
    return $instructor_id > 0 ? $instructor_id : ti_course_instructor_id($course_id);
}

function sc_limits(int $instructor_id): ?array {
    return db_one("SELECT * FROM k30_sc_instructor_limits WHERE instructor_id=?", [$instructor_id]) ?: null;
}

/** Suma minut zajęć trenera w zakresie dat (bez odwołanych, bez grup archiwalnych, bez $skip_id). */
function sc_instructor_minutes(int $iid, string $from, string $to, int $skip_id = 0): int {
    $r = db_one(
        "SELECT COALESCE(SUM(" . SC_DUR_SQL . "),0) AS m
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
          WHERE COALESCE(s.instructor_id,c.instructor_id)=CAST(? AS INTEGER) AND s.draft_id IS NULL
            AND s.status NOT IN ('cancelled') AND s.lesson_date BETWEEN ? AND ? AND COALESCE(c.status,'active')!='archived'" . ($skip_id ? " AND s.id!=?" : ''),
        $skip_id ? [$iid, $from, $to, $skip_id] : [$iid, $from, $to]
    );
    return (int)($r['m'] ?? 0);
}

/** Lekcje trenera kolidujące z oknem czasowym (także zastępstwa — liczy efektywnego prowadzącego). */
function sc_instructor_overlaps(int $iid, string $date, string $from, string $to, int $skip_id = 0): array {
    if (!$iid) return [];
    return db_all(
        "SELECT s.id, s.course_id, s.time_from, s.time_to, c.name AS course_name
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
          WHERE COALESCE(s.instructor_id,c.instructor_id)=CAST(? AS INTEGER) AND s.lesson_date=? AND s.draft_id IS NULL
            AND s.status NOT IN ('cancelled') AND s.time_from<? AND s.time_to>? AND COALESCE(c.status,'active')!='archived'" . ($skip_id ? " AND s.id!=?" : ''),
        $skip_id ? [$iid, $date, $to, $from, $skip_id] : [$iid, $date, $to, $from]
    );
}

/** Czy trener ma kwalifikacje wymagane dla kursu? @return list<string> brakujące wymagania (nazwy). */
function sc_missing_qualifications(int $iid, int $course_id, string $date): array {
    $c = db_one("SELECT subject_type_id, tech_path_id FROM k30_ti_courses WHERE id=?", [$course_id]);
    if (!$c || !$iid) return [];
    $missing = [];
    foreach (['subject' => (int)($c['subject_type_id'] ?? 0), 'path' => (int)($c['tech_path_id'] ?? 0)] as $kind => $ref) {
        if ($ref <= 0) continue;
        if (!db_one("SELECT 1 FROM k30_sc_requirements WHERE ref_kind=? AND ref_id=?", [$kind, $ref])) continue;
        $q = db_one("SELECT 1 FROM k30_sc_qualifications WHERE instructor_id=? AND ref_kind=? AND ref_id=?
                        AND (valid_to='' OR valid_to>=?)", [$iid, $kind, $ref, $date]);
        if (!$q) $missing[] = sc_ref_label($kind, $ref);
    }
    return $missing;
}

function sc_ref_label(string $kind, int $ref): string {
    if ($kind === 'subject') {
        $r = db_one("SELECT name FROM k30_ti_subject_types WHERE id=?", [$ref]);
        return 'rodzaj zajęć „' . ($r['name'] ?? ('#' . $ref)) . '”';
    }
    $r = db_one("SELECT name FROM k30_pl_tech_paths WHERE id=?", [$ref]);
    return 'ścieżka „' . ($r['name'] ?? ('#' . $ref)) . '”';
}

/**
 * Walidacja proponowanego terminu.
 * $p: course_id, lesson_date, time_from, time_to, [instructor_id (0=z kursu)], [room_id],
 *     [lesson_method], [skip_id].
 * @return array{hard:list<array>, soft:list<array>}
 */
function sc_validate(array $p): array {
    $hard = []; $soft = [];
    $add_h = function (string $code, string $msg, bool $ov = false) use (&$hard) { $hard[] = ['code' => $code, 'msg' => $msg, 'overridable' => $ov]; };
    $add_s = function (string $code, string $msg) use (&$soft) { $soft[] = ['code' => $code, 'msg' => $msg]; };

    $course = (int)($p['course_id'] ?? 0);
    $date   = (string)($p['lesson_date'] ?? '');
    $tf     = substr((string)($p['time_from'] ?? ''), 0, 5);
    $tt     = substr((string)($p['time_to'] ?? ''), 0, 5);
    $skip   = (int)($p['skip_id'] ?? 0);
    $room   = (int)($p['room_id'] ?? 0);
    if (!$course || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt)) {
        return ['hard' => [['code' => 'INPUT', 'msg' => 'Nieprawidłowa data lub godziny.', 'overridable' => false]], 'soft' => []];
    }
    $dur = ti_hm2min($tt) - ti_hm2min($tf);
    if ($dur <= 0) {
        return ['hard' => [['code' => 'INPUT', 'msg' => 'Godzina zakończenia musi być późniejsza niż rozpoczęcia.', 'overridable' => false]], 'soft' => []];
    }
    $iid = sc_effective_instructor($course, (int)($p['instructor_id'] ?? 0));

    // Okres zamknięty / dzień wolny
    if ($pc = ti_period_closed_for_date($date)) $add_h('PERIOD_CLOSED', ti_period_closed_msg($pc));
    if (ti_date_is_off($date)) $add_s('HOLIDAY', 'Termin wypada w dzień wolny / przerwę.');

    // Trener: urlop i okna dostępności
    if ($iid) {
        $av = ti_instructor_available_at($iid, $date, $tf, $tt);
        if (!$av['ok']) {
            $is_leave = function_exists('ti_instructor_on_leave') && ti_instructor_on_leave($iid, $date, $tf, $tt);
            $add_h($is_leave ? 'LEAVE' : 'AVAILABILITY', $av['reason'], !$is_leave);
        }
        // Nakładanie z innymi lekcjami trenera
        if ($ov = sc_instructor_overlaps($iid, $date, $tf, $tt, $skip)) {
            $o = $ov[0];
            $add_h('CONFLICT_INSTRUCTOR', 'Trener ma w tym czasie inne zajęcia: ' . $o['course_name'] . ' (' . substr($o['time_from'], 0, 5) . '–' . substr($o['time_to'], 0, 5) . ').');
        }
        // Kwalifikacje
        if ($miss = sc_missing_qualifications($iid, $course, $date)) {
            $add_h('QUALIFICATION', 'Trener nie ma wymaganych uprawnień: ' . implode(', ', $miss) . '.');
        }
        // Limity godzin
        if ($lim = sc_limits($iid)) {
            $viol = sc_check_limits($iid, $lim, $date, $tf, $tt, $dur, $skip);
            foreach ($viol as $v) {
                if (!empty($lim['hard'])) $add_h('LIMIT_' . $v['code'], $v['msg'], true); else $add_s('LIMIT_' . $v['code'], $v['msg']);
            }
        }
    }

    // Grupa nie może mieć dwóch zajęć naraz
    $cc = db_all(
        "SELECT id,time_from,time_to FROM k30_ti_sessions
          WHERE course_id=? AND lesson_date=? AND draft_id IS NULL AND status NOT IN ('cancelled')
            AND time_from<? AND time_to>?" . ($skip ? " AND id!=?" : ''),
        $skip ? [$course, $date, $tt, $tf, $skip] : [$course, $date, $tt, $tf]
    );
    if ($cc) $add_h('CONFLICT_COURSE', 'Grupa ma już zajęcia w tym czasie (' . substr($cc[0]['time_from'], 0, 5) . '–' . substr($cc[0]['time_to'], 0, 5) . ').');

    // Sala
    if ($room) {
        $rc = pl_room_availability($room, $date, $tf, $tt, $skip);
        if ($rc) {
            $n = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$rc[0]['course_id']])['name'] ?? '';
            $add_h('CONFLICT_ROOM', 'Sala jest zajęta w tym czasie' . ($n !== '' ? ' (' . $n . ')' : '') . '.');
        }
        $r = db_one("SELECT capacity, is_active FROM k30_pl_rooms WHERE id=?", [$room]);
        if ($r && empty($r['is_active'])) $add_s('ROOM_INACTIVE', 'Sala jest oznaczona jako nieaktywna.');
        $need = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course])['n'] ?? 0);
        if ($r && $need > 0 && (int)$r['capacity'] > 0 && $need > (int)$r['capacity']) {
            $add_s('ROOM_CAPACITY', "Sala mieści {$r['capacity']} osób, a w grupie jest $need.");
        }
    }

    // Zoom (jeden host = jedno spotkanie)
    $lm = (string)($p['lesson_method'] ?? '');
    if ($skip && $lm === '') $lm = (string)(db_one("SELECT lesson_method FROM k30_ti_sessions WHERE id=?", [$skip])['lesson_method'] ?? '');
    // Metoda i sala (kurs hybrydowy: od metody zależy stawka)
    if (ti_course_is_hybrid($course)) {
        if ($lm === '') $add_s('METHOD_MISSING', 'Kurs hybrydowy — lekcja nie ma metody (online / stacjonarna), więc stawka zależy od ustawienia grupy.');
        elseif ($lm === 'stacjonarna' && !$room) $add_s('ROOM_MISSING', 'Lekcja stacjonarna bez przypisanej sali.');
    }
    if ($room && in_array($lm, ['zdalna_zoom', 'zdalna_inne'], true)) $add_s('ROOM_ON_ONLINE', 'Lekcja online ma przypisaną salę — zwolnij ją, jeśli nie jest potrzebna.');
    $zc = ti_zoom_slot_check($course, $lm, $date, $tf, $tt, $skip);
    if (!$zc['ok']) $add_h('CONFLICT_ZOOM', $zc['reason']);

    return ['hard' => $hard, 'soft' => $soft];
}

/** Porównanie z limitami trenera → lista przekroczeń [code,msg]. */
function sc_check_limits(int $iid, array $lim, string $date, string $tf, string $tt, int $dur, int $skip): array {
    $out = [];
    $fmt = fn(int $m) => sprintf('%d h %02d min', intdiv($m, 60), $m % 60);
    $ts  = strtotime($date);
    $day_cap = (int)$lim['max_day_min'];
    if ($day_cap > 0) {
        $m = sc_instructor_minutes($iid, $date, $date, $skip) + $dur;
        if ($m > $day_cap) $out[] = ['code' => 'DAY', 'msg' => 'Limit dzienny trenera przekroczony: ' . $fmt($m) . ' (limit ' . $fmt($day_cap) . ').'];
    }
    $wk_cap = (int)$lim['max_week_min'];
    if ($wk_cap > 0) {
        $mon = date('Y-m-d', strtotime('monday this week', $ts));
        $sun = date('Y-m-d', strtotime('sunday this week', $ts));
        $m = sc_instructor_minutes($iid, $mon, $sun, $skip) + $dur;
        if ($m > $wk_cap) $out[] = ['code' => 'WEEK', 'msg' => 'Limit tygodniowy trenera przekroczony: ' . $fmt($m) . ' (limit ' . $fmt($wk_cap) . ').'];
    }
    $mo_cap = (int)$lim['max_month_min'];
    if ($mo_cap > 0) {
        $m = sc_instructor_minutes($iid, date('Y-m-01', $ts), date('Y-m-t', $ts), $skip) + $dur;
        if ($m > $mo_cap) $out[] = ['code' => 'MONTH', 'msg' => 'Limit miesięczny trenera przekroczony: ' . $fmt($m) . ' (limit ' . $fmt($mo_cap) . ').'];
    }
    $ch_cap = (int)$lim['max_consecutive_min'];
    if ($ch_cap > 0) {
        $rows = db_all(
            "SELECT s.time_from, s.time_to FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
              WHERE COALESCE(s.instructor_id,c.instructor_id)=CAST(? AS INTEGER) AND s.lesson_date=? AND s.draft_id IS NULL
                AND s.status NOT IN ('cancelled') AND COALESCE(c.status,'active')!='archived'" . ($skip ? " AND s.id!=?" : '') . " ORDER BY s.time_from",
            $skip ? [$iid, $date, $skip] : [$iid, $date]
        );
        $iv = array_map(fn($r) => [ti_hm2min($r['time_from']), ti_hm2min($r['time_to'])], $rows);
        $iv[] = [ti_hm2min($tf), ti_hm2min($tt)];
        usort($iv, fn($a, $b) => $a[0] <=> $b[0]);
        $chain = []; $cur = null;
        foreach ($iv as $x) {
            if ($cur && $x[0] - $cur[1] <= SC_CHAIN_GAP_MIN) { $cur[1] = max($cur[1], $x[1]); $cur[2] += $x[1] - $x[0]; }
            else { if ($cur) $chain[] = $cur; $cur = [$x[0], $x[1], $x[1] - $x[0]]; }
        }
        if ($cur) $chain[] = $cur;
        $nf = ti_hm2min($tf);
        foreach ($chain as $c) {
            if ($nf >= $c[0] && $nf < $c[1] + 1 && $c[2] > $ch_cap) {
                $out[] = ['code' => 'CHAIN', 'msg' => 'Za długi ciąg zajęć bez przerwy: ' . $fmt($c[2]) . ' (limit ' . $fmt($ch_cap) . ').'];
                break;
            }
        }
    }
    return $out;
}
