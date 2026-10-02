<?php
/**
 * modules/ti_scheduler/logic/schedulerMatch.php — auto-dopasowanie zasobów.
 *
 * sc_match_session(): najlepsze pary (trener, sala) dla lekcji o ustalonym terminie.
 * sc_find_slots():    najlepsze terminy (data, godzina, trener, sala) dla grupy.
 * Wszystko przechodzi przez sc_validate() — propozycje nie mają twardych konfliktów.
 */
require_once __DIR__ . '/schedulerValidator.php';

/** Punktacja trenera dla grupy: im mniej, tym lepiej. */
function sc_instructor_score(int $iid, int $course_id, string $date, int $soft_n): int {
    $c = db_one("SELECT instructor_id, location FROM k30_ti_courses WHERE id=?", [$course_id]);
    $score = $soft_n * 1000;
    if ((int)($c['instructor_id'] ?? 0) === $iid) $score -= 500;                       // prowadzący grupy
    elseif (db_one("SELECT 1 FROM k30_ti_sessions WHERE course_id=? AND instructor_id=? LIMIT 1", [$course_id, $iid])) $score -= 200; // prowadził już tę grupę
    $mon = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $sun = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    return $score + intdiv(sc_instructor_minutes($iid, $mon, $sun), 6);               // obciążenie tygodnia (10 pkt/h)
}

/** Sale spełniające wymagania i wolne w terminie; najmniejsza wystarczająca pojemność = najlepsza. */
function sc_free_rooms(int $course_id, string $date, string $tf, string $tt, int $skip = 0, string $method = ''): array {
    if (in_array($method, ['zdalna_zoom', 'zdalna_inne'], true)) return [];
    $need = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id])['n'] ?? 0);
    $out = [];
    foreach (db_all("SELECT id, name, capacity, mode_support FROM k30_pl_rooms WHERE is_active=1 ORDER BY capacity") as $r) {
        if ($r['mode_support'] === 'remote') continue;
        if ($need > 0 && (int)$r['capacity'] > 0 && (int)$r['capacity'] < $need) continue;
        if (pl_room_availability((int)$r['id'], $date, $tf, $tt, $skip)) continue;
        $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'capacity' => (int)$r['capacity'], 'waste' => max(0, (int)$r['capacity'] - $need)];
    }
    usort($out, fn($a, $b) => $a['waste'] <=> $b['waste']);
    return $out;
}

/** @return list<array{instructor_id:int,instructor:string,room_id:int,room:string,score:int,soft:list<string>}> */
function sc_match_session(int $session_id, int $limit = 5): array {
    $s = db_one("SELECT s.*, c.instructor_id AS ci FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$session_id]);
    if (!$s) return [];
    $tf = substr((string)$s['time_from'], 0, 5); $tt = substr((string)$s['time_to'], 0, 5);
    $rooms = sc_free_rooms((int)$s['course_id'], $s['lesson_date'], $tf, $tt, $session_id, (string)$s['lesson_method']);
    $out = [];
    foreach (k30_get_consultants() as $u) {
        $iid = (int)$u['id'];
        $r = sc_validate(['course_id' => (int)$s['course_id'], 'lesson_date' => $s['lesson_date'], 'time_from' => $tf, 'time_to' => $tt,
                          'instructor_id' => $iid, 'room_id' => 0, 'lesson_method' => (string)$s['lesson_method'], 'skip_id' => $session_id]);
        if ($r['hard']) continue;
        $base = sc_instructor_score($iid, (int)$s['course_id'], $s['lesson_date'], count($r['soft']));
        $room = $rooms[0] ?? null;
        // Zostaw salę, jeśli obecna jest wolna i wystarczająca — mniej zamieszania niż zmiana
        foreach ($rooms as $rm) if ($rm['id'] === (int)$s['room_id']) { $room = $rm; break; }
        $out[] = ['instructor_id' => $iid, 'instructor' => (string)$u['display_name'], 'room_id' => $room['id'] ?? 0, 'room' => $room['name'] ?? '—',
                  'score' => $base, 'soft' => array_column($r['soft'], 'msg')];
    }
    usort($out, fn($a, $b) => $a['score'] <=> $b['score']);
    return array_slice($out, 0, $limit);
}

/**
 * Szukanie terminów dla grupy: skanuje dni i godziny (co 30 min, 8:00–20:00), pomija dni wolne i weekendy
 * wg flagi. Domyślnie tylko trener grupy; $any_instructor=true rozszerza na całą kadrę.
 */
function sc_find_slots(int $course_id, int $dur_min, string $from, string $to, bool $any_instructor = false, bool $weekends = false, int $limit = 10, string $method = ''): array {
    $dur_min = max(15, min(480, $dur_min));
    if ((strtotime($to) - strtotime($from)) > 31 * 86400) $to = date('Y-m-d', strtotime($from . ' +31 days'));
    $c = db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id]);
    if (!$c) return [];
    $cands = [];
    foreach (k30_get_consultants() as $u) if ($any_instructor || (int)$u['id'] === (int)$c['instructor_id']) $cands[(int)$u['id']] = (string)$u['display_name'];
    if (!$cands && (int)$c['instructor_id']) $cands[(int)$c['instructor_id']] = (string)(db_one("SELECT name FROM users WHERE id=?", [(int)$c['instructor_id']])['name'] ?? '');
    $out = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
        $date = date('Y-m-d', $d); $n = (int)date('N', $d);
        if (($n >= 6 && !$weekends) || ti_date_is_off($date) || ti_period_closed_for_date($date)) continue;
        foreach ($cands as $iid => $iname) {
            for ($m = 8 * 60; $m + $dur_min <= 20 * 60; $m += 30) {
                $tf = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); $e = $m + $dur_min; $tt = sprintf('%02d:%02d', intdiv($e, 60), $e % 60);
                $r = sc_validate(['course_id' => $course_id, 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt, 'instructor_id' => $iid, 'room_id' => 0, 'lesson_method' => $method]);
                if ($r['hard']) continue;
                $rooms = sc_free_rooms($course_id, $date, $tf, $tt, 0, $method);
                if ($method === 'stacjonarna' && !$rooms) continue;          // stacjonarnie tylko gdy jest wolna sala
                $soft = array_values(array_filter($r['soft'], fn($x) => !in_array($x['code'], ['ROOM_MISSING'], true)));
                $out[] = ['date' => $date, 'from' => $tf, 'to' => $tt, 'instructor_id' => $iid, 'instructor' => $iname,
                          'room_id' => $rooms[0]['id'] ?? 0, 'room' => $method !== '' && $method !== 'stacjonarna' ? '— online —' : ($rooms[0]['name'] ?? '—'), 'method' => $method,
                          'soft' => array_column($soft, 'msg'), 'rooms_free' => count($rooms),
                          'score' => sc_instructor_score($iid, $course_id, $date, count($soft)) + ((($rooms || $method !== '' && $method !== 'stacjonarna') ? 0 : 300)) + abs($m - 17 * 60) / 30];
            }
        }
    }
    usort($out, fn($a, $b) => $a['score'] <=> $b['score']);
    return array_slice($out, 0, $limit);
}
