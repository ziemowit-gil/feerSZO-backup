<?php
/**
 * modules/ti_scheduler/logic/schedulerSeries.php — automatyczne rozkładanie ciągów zajęć.
 *
 * Z profilu struktury czasu (ciągły / weekendowy / wieczorowy) i wymiaru godzin układa lekcje
 * dzień po dniu: pomija dni wolne, święta ustawowe, urlopy i zamknięte okresy, wstawia przerwy
 * (krótkie i obiadową) oraz pilnuje maksymalnej liczby godzin pod rząd i dziennego limitu trenera.
 * Dni, w których któryś blok ma twardy konflikt, są pomijane, a wymiar godzin uzupełniany dalej.
 */
require_once __DIR__ . '/schedulerValidator.php';

/** Data Wielkanocy (algorytm Gaussa). */
function sc_easter(int $year): \DateTimeImmutable {
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/** Święta ustawowe w Polsce (data => nazwa). Wigilia od 2025 r. */
function sc_pl_holidays(int $year): array {
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];
    $e = sc_easter($year);
    $h = ["$year-01-01" => 'Nowy Rok', "$year-01-06" => 'Trzech Króli', $e->format('Y-m-d') => 'Wielkanoc',
          $e->modify('+1 day')->format('Y-m-d') => 'Poniedziałek Wielkanocny', "$year-05-01" => 'Święto Pracy', "$year-05-03" => 'Święto Konstytucji 3 Maja',
          $e->modify('+49 days')->format('Y-m-d') => 'Zielone Świątki', $e->modify('+60 days')->format('Y-m-d') => 'Boże Ciało',
          "$year-08-15" => 'Wniebowzięcie NMP', "$year-11-01" => 'Wszystkich Świętych', "$year-11-11" => 'Święto Niepodległości',
          "$year-12-25" => 'Boże Narodzenie', "$year-12-26" => 'Drugi dzień Świąt'];
    if ($year >= 2025) $h["$year-12-24"] = 'Wigilia';
    ksort($h);
    return $cache[$year] = $h;
}

/** Powód wyłączenia dnia ('' = dzień roboczy dla zajęć). */
function sc_day_off_reason(string $date): string {
    $h = sc_pl_holidays((int)substr($date, 0, 4));
    if (isset($h[$date])) return 'święto: ' . $h[$date];
    if (ti_date_is_off($date)) return 'dzień wolny / przerwa (kalendarz TI)';
    if ($pc = ti_period_closed_for_date($date)) return 'okres zamknięty';
    return '';
}

function sc_profiles(bool $active_only = true): array {
    ti_sched_migrate();
    return db_all("SELECT * FROM k30_sc_day_profiles" . ($active_only ? " WHERE is_active=1" : "") . " ORDER BY id");
}

function sc_hm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }

/**
 * Układa dzień według profilu. @return list<array{from:string,to:string}> bloki zajęć.
 * $want_min — ile minut jeszcze potrzeba, $day_cap — dzienny limit (0 = brak).
 */
function sc_layout_day(array $p, int $want_min, int $day_cap = 0): array {
    $cur = ti_hm2min($p['start_time']); $end = ti_hm2min($p['end_time']);
    $unit = max(15, (int)$p['unit_min']); $brk = max(0, (int)$p['break_min']);
    $maxc = max($unit, (int)$p['max_consecutive_min']);
    $lf = $p['lunch_from'] !== '' ? ti_hm2min($p['lunch_from']) : 0; $lm = (int)$p['lunch_min']; $lunch_done = !($lf && $lm);
    $used = 0; $out = [];
    while ($cur < $end && $want_min - $used >= $unit) {
        if (!$lunch_done && $cur >= $lf) { $cur += $lm; $lunch_done = true; continue; }
        $room = $end - $cur;
        if (!$lunch_done) $room = min($room, $lf - $cur);       // blok nie wchodzi w przerwę obiadową
        $len = min($maxc, $room, $want_min - $used, $day_cap ? $day_cap - $used : PHP_INT_MAX);
        $len = intdiv($len, $unit) * $unit;                      // pełne godziny lekcyjne
        if ($len < $unit) {
            if (!$lunch_done && $cur < $lf) { $cur = $lf; continue; }   // zostało mniej niż jednostka przed obiadem
            break;
        }
        $out[] = ['from' => sc_hm($cur), 'to' => sc_hm($cur + $len)];
        $used += $len; $cur += $len + $brk;
    }
    return $out;
}

/**
 * Podgląd ciągu. $o: course_id, profile_id, total_hours, hour_min (45|60), start_date, instructor_id, room_id.
 * @return array{ok:bool,msg?:string,sessions:list<array>,skipped:list<array>,total_min:int,placed_min:int,end_date:string}
 */
function sc_series_preview(array $o): array {
    ti_sched_migrate();
    $course = (int)($o['course_id'] ?? 0);
    $p = db_one("SELECT * FROM k30_sc_day_profiles WHERE id=?", [(int)($o['profile_id'] ?? 0)]);
    $start = (string)($o['start_date'] ?? '');
    $hour_min = in_array((int)($o['hour_min'] ?? 45), [45, 60], true) ? (int)$o['hour_min'] : 45;
    $total = (int)round((float)($o['total_hours'] ?? 0) * $hour_min);
    $fail = fn(string $m) => ['ok' => false, 'msg' => $m, 'sessions' => [], 'skipped' => [], 'total_min' => 0, 'placed_min' => 0, 'end_date' => ''];
    if (!$course || !$p || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return $fail('Wybierz grupę, profil i datę rozpoczęcia.');
    if ($total <= 0 || $total > 6000 * 60) return $fail('Podaj wymiar godzin.');
    $iid = sc_effective_instructor($course, (int)($o['instructor_id'] ?? 0));
    $room = (int)($o['room_id'] ?? 0);
    $days = array_map('intval', explode(',', (string)$p['days']));
    $every = max(1, (int)$p['every_weeks']);
    $cap = $iid ? (int)(sc_limits($iid)['max_day_min'] ?? 0) : 0;

    $sessions = []; $skipped = []; $placed = 0;
    $d = new \DateTimeImmutable($start); $w0 = (int)$d->modify('monday this week')->format('U');
    for ($n = 0; $n < 800 && $placed < $total; $n++, $d = $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        if (!in_array((int)$d->format('N'), $days, true)) continue;
        if ($every > 1 && ((int)round(((int)$d->modify('monday this week')->format('U') - $w0) / 604800) % $every) !== 0) continue;
        if ($why = sc_day_off_reason($date)) { $skipped[] = ['date' => $date, 'reason' => $why]; continue; }
        if ($iid && function_exists('ti_instructor_on_leave') && ti_instructor_on_leave($iid, $date)) { $skipped[] = ['date' => $date, 'reason' => 'nieobecność trenera']; continue; }
        $blocks = sc_layout_day($p, $total - $placed, $cap);
        if (!$blocks) continue;
        $bad = null; $soft = [];
        foreach ($blocks as $b) {
            $r = sc_validate(['course_id' => $course, 'lesson_date' => $date, 'time_from' => $b['from'], 'time_to' => $b['to'], 'instructor_id' => (int)($o['instructor_id'] ?? 0), 'room_id' => $room]);
            foreach ($r['hard'] as $h) { $bad = $h['msg']; break 2; }
            foreach ($r['soft'] as $sft) $soft[$sft['msg']] = true;
        }
        if ($bad !== null) { $skipped[] = ['date' => $date, 'reason' => $bad]; continue; }
        foreach ($blocks as $b) { $sessions[] = ['date' => $date, 'from' => $b['from'], 'to' => $b['to'], 'min' => ti_hm2min($b['to']) - ti_hm2min($b['from']), 'warn' => array_keys($soft)]; $placed += ti_hm2min($b['to']) - ti_hm2min($b['from']); }
    }
    return ['ok' => true, 'sessions' => $sessions, 'skipped' => $skipped, 'total_min' => $total, 'placed_min' => $placed,
            'end_date' => $sessions ? end($sessions)['date'] : '', 'hour_min' => $hour_min];
}

/** Zapis ciągu jako lekcje „zaplanowane" — powtórna walidacja każdego bloku tuż przed zapisem. */
function sc_series_apply(array $o, int $by): array {
    $pv = sc_series_preview($o);
    if (!$pv['ok']) return $pv + ['created' => 0];
    $course = (int)$o['course_id']; $iid_in = (int)($o['instructor_id'] ?? 0); $room = (int)($o['room_id'] ?? 0);
    $c = db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course]);
    $own = ($iid_in && $iid_in !== (int)($c['instructor_id'] ?? 0)) ? $iid_in : 0;
    $pdo = db(); $pdo->beginTransaction(); $n = 0;
    try {
        foreach ($pv['sessions'] as $s) {
            db_insert('k30_ti_sessions', ['course_id' => $course, 'lesson_date' => $s['date'], 'time_from' => $s['from'], 'time_to' => $s['to'], 'duration_min' => $s['min'],
                'status' => 'planned', 'instructor_id' => $own ?: null, 'room_id' => $room ?: null, 'notes' => 'Wygenerowano w planiście (ciąg zajęć)', 'created_by' => $by, 'created_at' => date('Y-m-d H:i:s')]);
            $n++;
        }
        pl_audit('course', $course, 'planista_series', [], ['profile' => (int)$o['profile_id'], 'from' => $o['start_date'], 'lessons' => $n, 'end' => $pv['end_date']], $by);
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Błąd zapisu: ' . $e->getMessage(), 'created' => 0]; }
    return ['ok' => true, 'created' => $n, 'msg' => "Dodano lekcji: $n (do {$pv['end_date']})."];
}

/**
 * Cykl „X godzin co Y": stały termin powtarzany co N dni / tygodni / miesięcy.
 * $o: course_id, start_date, time_from (HH:MM), hours (czas jednego spotkania), hour_min (45|60),
 *     interval (N), unit (day|week|month), weekdays[] (ISO 1–7, tylko unit=week; domyślnie dzień startu),
 *     end_mode (count|date|hours), end_value, holiday_mode (skip|shift), instructor_id, room_id.
 * @return array{ok:bool,msg?:string,sessions:list<array>,skipped:list<array>,total_min:int,placed_min:int,end_date:string}
 */
function sc_cycle_preview(array $o): array {
    ti_sched_migrate();
    $fail = fn(string $m) => ['ok' => false, 'msg' => $m, 'sessions' => [], 'skipped' => [], 'total_min' => 0, 'placed_min' => 0, 'end_date' => ''];
    $course = (int)($o['course_id'] ?? 0); $start = (string)($o['start_date'] ?? ''); $tf = substr((string)($o['time_from'] ?? ''), 0, 5);
    $hour_min = in_array((int)($o['hour_min'] ?? 45), [45, 60], true) ? (int)$o['hour_min'] : 45;
    $dur = (int)round((float)($o['hours'] ?? 0) * $hour_min);
    $n = max(1, (int)($o['interval'] ?? 1)); $unit = in_array($o['unit'] ?? '', ['day', 'week', 'month'], true) ? $o['unit'] : 'week';
    $mode = in_array($o['end_mode'] ?? '', ['count', 'date', 'hours'], true) ? $o['end_mode'] : 'count';
    $endv = (string)($o['end_value'] ?? '');
    if (!$course || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $tf)) return $fail('Wybierz grupę, datę i godzinę rozpoczęcia.');
    if ($dur < 15 || $dur > 600) return $fail('Podaj czas jednego spotkania (od 15 minut do 10 godzin).');
    if (ti_hm2min($tf) + $dur > 24 * 60) return $fail('Spotkanie wychodzi poza dobę — zmień godzinę lub czas.');
    $limit_count = $mode === 'count' ? max(1, min(400, (int)$endv)) : 400;
    $limit_min = $mode === 'hours' ? (int)round((float)str_replace(',', '.', $endv) * $hour_min) : PHP_INT_MAX;
    $limit_date = $mode === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endv) ? $endv : '2099-12-31';
    if ($mode === 'date' && $limit_date === '2099-12-31') return $fail('Podaj datę zakończenia.');
    if ($mode === 'hours' && $limit_min <= 0) return $fail('Podaj łączny wymiar godzin.');
    $shift = ($o['holiday_mode'] ?? 'skip') === 'shift';
    $iid_in = (int)($o['instructor_id'] ?? 0); $room = (int)($o['room_id'] ?? 0);
    $iid = sc_effective_instructor($course, $iid_in);
    $tt = sc_hm(ti_hm2min($tf) + $dur);

    // kandydaci na daty (rosnąco)
    $s0 = new \DateTimeImmutable($start);
    $cands = (function () use ($s0, $n, $unit, $o) {
        if ($unit === 'day') { for ($k = 0; $k < 1200; $k++) yield $s0->modify('+' . ($k * $n) . ' days'); }
        elseif ($unit === 'month') {
            for ($k = 0; $k < 120; $k++) {
                $m = $s0->modify('first day of this month')->modify('+' . ($k * $n) . ' months');
                $day = min((int)$s0->format('j'), (int)$m->format('t'));
                yield $m->setDate((int)$m->format('Y'), (int)$m->format('n'), $day);
            }
        } else {
            $wd = array_values(array_unique(array_filter(array_map('intval', (array)($o['weekdays'] ?? [])), fn($x) => $x >= 1 && $x <= 7)));
            if (!$wd) $wd = [(int)$s0->format('N')];
            sort($wd);
            $mon = $s0->modify('monday this week');
            for ($w = 0; $w < 400; $w++) {
                $ws = $mon->modify('+' . ($w * $n) . ' weeks');
                foreach ($wd as $d) { $day = $ws->modify('+' . ($d - 1) . ' days'); if ($day >= $s0) yield $day; }
            }
        }
    })();

    $sessions = []; $skipped = []; $placed = 0; $seen = [];
    foreach ($cands as $d) {
        $date = $d->format('Y-m-d');
        if ($date > $limit_date || count($sessions) >= $limit_count || $placed >= $limit_min) break;
        if ($date > date('Y-m-d', strtotime($start . ' +3 years'))) break;
        $shifted = '';
        if ($why = sc_day_off_reason($date)) {
            if (!$shift) { $skipped[] = ['date' => $date, 'reason' => $why]; continue; }
            $orig = $date;
            for ($i = 0; $i < 10 && (sc_day_off_reason($date) !== '' || (int)(new \DateTimeImmutable($date))->format('N') >= 6); $i++) $date = date('Y-m-d', strtotime($date . ' +1 day'));
            if (sc_day_off_reason($date) !== '' || $date > $limit_date) { $skipped[] = ['date' => $orig, 'reason' => $why]; continue; }
            $shifted = $orig . ' → ' . $date;
        }
        if (isset($seen[$date])) continue; $seen[$date] = true;
        if ($iid && function_exists('ti_instructor_on_leave') && ti_instructor_on_leave($iid, $date, $tf, $tt, $course)) { $skipped[] = ['date' => $date, 'reason' => 'nieobecność trenera']; continue; }
        $r = sc_validate(['course_id' => $course, 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt, 'instructor_id' => $iid_in, 'room_id' => $room]);
        if ($r['hard']) { $skipped[] = ['date' => $date, 'reason' => $r['hard'][0]['msg']]; continue; }
        $sessions[] = ['date' => $date, 'from' => $tf, 'to' => $tt, 'min' => $dur, 'warn' => array_column($r['soft'], 'msg'), 'shifted' => $shifted];
        $placed += $dur;
    }
    return ['ok' => true, 'sessions' => $sessions, 'skipped' => $skipped, 'total_min' => $mode === 'hours' ? $limit_min : ($mode === 'count' ? $limit_count * $dur : 0),
            'placed_min' => $placed, 'end_date' => $sessions ? end($sessions)['date'] : '', 'hour_min' => $hour_min];
}

/** Zapis lekcji z gotowego podglądu (wspólne dla generatora profilowego i cyklu). */
function sc_series_commit(int $course, int $iid_in, int $room, array $sessions, array $meta, int $by): array {
    if (!$sessions) return ['ok' => false, 'msg' => 'Brak lekcji do zapisu.', 'created' => 0];
    $c = db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course]);
    $own = ($iid_in && $iid_in !== (int)($c['instructor_id'] ?? 0)) ? $iid_in : 0;
    $pdo = db(); $pdo->beginTransaction(); $n = 0;
    try {
        foreach ($sessions as $s) {
            db_insert('k30_ti_sessions', ['course_id' => $course, 'lesson_date' => $s['date'], 'time_from' => $s['from'], 'time_to' => $s['to'], 'duration_min' => $s['min'],
                'status' => 'planned', 'instructor_id' => $own ?: null, 'room_id' => $room ?: null, 'notes' => 'Wygenerowano w planiście (cykl zajęć)', 'created_by' => $by, 'created_at' => date('Y-m-d H:i:s')]);
            $n++;
        }
        pl_audit('course', $course, 'planista_cycle', [], $meta + ['lessons' => $n], $by);
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Błąd zapisu: ' . $e->getMessage(), 'created' => 0]; }
    return ['ok' => true, 'created' => $n, 'msg' => "Dodano lekcji: $n (do " . end($sessions)['date'] . ').'];
}

function sc_cycle_apply(array $o, int $by): array {
    $pv = sc_cycle_preview($o);
    if (!$pv['ok']) return $pv + ['created' => 0];
    return sc_series_commit((int)$o['course_id'], (int)($o['instructor_id'] ?? 0), (int)($o['room_id'] ?? 0), $pv['sessions'],
        ['unit' => $o['unit'] ?? '', 'interval' => $o['interval'] ?? 1, 'from' => $o['start_date']], $by);
}
