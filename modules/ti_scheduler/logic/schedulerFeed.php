<?php
/**
 * modules/ti_scheduler/logic/schedulerFeed.php — dane kalendarza/Gantta planisty.
 *
 * sc_feed() zwraca lekcje z zakresu dat razem z efektywnym trenerem, salą,
 * statusem „kolorystycznym" (sc_display_status) oraz wykrytymi nakładaniami
 * (trener / sala / grupa), a także zasoby (trenerzy, sale, grupy) i okna
 * dostępności + urlopy trenerów do podświetlenia na osi czasu.
 */
require_once __DIR__ . '/schedulerValidator.php';

/** Statusy wyświetlane (pochodne od k30_ti_sessions.status + k30_sc_session_state). */
const SC_STATUS_STYLES = [
    'tentative'            => ['label' => 'Wstępne',                   'color' => '#B45309', 'bg' => '#FEF3C7'],
    'confirmed_client'     => ['label' => 'Potwierdzone z klientem',   'color' => '#1D4ED8', 'bg' => '#DBEAFE'],
    'confirmed_instructor' => ['label' => 'Potwierdzone z trenerem',   'color' => '#6D28D9', 'bg' => '#EDE9FE'],
    'confirmed_both'       => ['label' => 'Potwierdzone (klient+trener)', 'color' => '#047857', 'bg' => '#D1FAE5'],
    'cancelled'            => ['label' => 'Odwołane',                  'color' => '#B91C1C', 'bg' => '#FEE2E2'],
    'done'                 => ['label' => 'Zrealizowane',              'color' => '#374151', 'bg' => '#E5E7EB'],
    'draft'                => ['label' => 'Wersja robocza',            'color' => '#6B7280', 'bg' => '#F3F4F6'],
    'reserved'             => ['label' => 'Rezerwacja',                'color' => '#0369A1', 'bg' => '#E0F2FE'],
];

function sc_display_status(string $status, bool $client_ok, bool $instr_ok): string {
    if ($status === 'cancelled') return 'cancelled';
    if (in_array($status, K30_TI_HELD_STATUSES, true)) return 'done';
    if ($status === 'draft') return 'draft';
    if ($status === 'reserved') return 'reserved';
    if ($client_ok && $instr_ok) return 'confirmed_both';
    if ($client_ok) return 'confirmed_client';
    if ($instr_ok) return 'confirmed_instructor';
    return 'tentative';
}

/** Czy lekcję można jeszcze przesuwać (nieodbyta, nieodwołana). */
function sc_session_movable(string $status): bool {
    return !in_array($status, array_merge(K30_TI_HELD_STATUSES, ['cancelled']), true);
}

/** @return array{sessions:list<array>, instructors:list<array>, rooms:list<array>, courses:list<array>, avail:array, leaves:array, holidays:list<string>} */
function sc_feed(string $from, string $to, int $scenario = 0): array {
    ti_sched_migrate();
    $changes = [];
    if ($scenario) foreach (db_all("SELECT * FROM k30_sc_scenario_changes WHERE scenario_id=?", [$scenario]) as $c) $changes[(int)$c['session_id']] = $c;
    $rows = db_all(
        "SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to, s.status, s.topic, s.lesson_method,
                s.instructor_id AS s_instr, c.instructor_id AS c_instr, s.room_id, s.series_id, s.rescheduled_from_date, s.rate_override,
                c.name AS course_name, c.group_code, c.status AS course_status, u.name AS instr_name, r.name AS room_name,
                st.client_confirmed_at, st.instr_confirmed_at
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id=s.course_id
           LEFT JOIN users u ON u.id=COALESCE(s.instructor_id,c.instructor_id)
           LEFT JOIN k30_pl_rooms r ON r.id=s.room_id
           LEFT JOIN k30_sc_session_state st ON st.session_id=s.id
          WHERE (s.lesson_date BETWEEN ? AND ?
                 OR s.id IN (SELECT session_id FROM k30_sc_scenario_changes WHERE scenario_id=? AND new_date BETWEEN ? AND ?))
            AND s.draft_id IS NULL
            AND COALESCE(c.closed_at,'')='' AND COALESCE(c.status,'active')!='cancelled'
          ORDER BY s.lesson_date, s.time_from",
        [$from, $to, $scenario, $from, $to]
    );
    $sessions = [];
    foreach ($rows as $r) {
        $iid = (int)($r['s_instr'] ?: $r['c_instr']);
        $ds  = sc_display_status((string)$r['status'], !empty($r['client_confirmed_at']), !empty($r['instr_confirmed_at']));
        $sessions[] = [
            'id' => (int)$r['id'], 'course_id' => (int)$r['course_id'],
            'course' => (string)$r['course_name'], 'code' => (string)$r['group_code'],
            'date' => (string)$r['lesson_date'], 'from' => substr((string)$r['time_from'], 0, 5), 'to' => substr((string)$r['time_to'], 0, 5),
            'instructor_id' => $iid, 'instructor' => (string)($r['instr_name'] ?? ''),
            'own_instr' => $r['s_instr'] ? (int)$r['s_instr'] : 0,
            'room_id' => (int)$r['room_id'], 'room' => (string)($r['room_name'] ?? ''),
            'status' => (string)$r['status'], 'ds' => $ds, 'movable' => sc_session_movable((string)$r['status']),
            'topic' => (string)($r['topic'] ?? ''), 'method' => (string)($r['lesson_method'] ?? ''),
            'moved' => !empty($r['rescheduled_from_date']), 'series' => (int)$r['series_id'],
            'client_ok' => !empty($r['client_confirmed_at']), 'instr_ok' => !empty($r['instr_confirmed_at']), 'rate' => ($r['rate_override'] !== null && (float)$r['rate_override'] > 0.005) ? (float)$r['rate_override'] : 0,
            'archived' => ($r['course_status'] ?? '') === 'archived',   // grupa archiwalna — widoczna, bez kolizji
            'conflicts' => [], 'changed' => false,
        ];
        if (isset($changes[(int)$r['id']])) {
            $c = $changes[(int)$r['id']]; $k = count($sessions) - 1;
            $sessions[$k]['orig'] = ['date' => $sessions[$k]['date'], 'from' => $sessions[$k]['from'], 'to' => $sessions[$k]['to'],
                                     'instructor_id' => $sessions[$k]['instructor_id'], 'instructor' => $sessions[$k]['instructor'], 'room_id' => $sessions[$k]['room_id'], 'room' => $sessions[$k]['room']];
            $sessions[$k]['date'] = (string)$c['new_date']; $sessions[$k]['from'] = (string)$c['new_from']; $sessions[$k]['to'] = (string)$c['new_to'];
            $sessions[$k]['instructor_id'] = (int)$c['new_instructor_id']; $sessions[$k]['room_id'] = (int)$c['new_room_id'];
            $sessions[$k]['instructor'] = $c['new_instructor_id'] ? (string)(db_one("SELECT COALESCE(NULLIF(TRIM(first_name||' '||last_name),''), name) AS n FROM users WHERE id=?", [(int)$c['new_instructor_id']])['n'] ?? '') : '';
            $sessions[$k]['room'] = $c['new_room_id'] ? (string)(db_one("SELECT name FROM k30_pl_rooms WHERE id=?", [(int)$c['new_room_id']])['name'] ?? '') : '';
            $sessions[$k]['changed'] = true;
        }
    }
    $sessions = array_values(array_filter($sessions, fn($x) => $x['date'] >= $from && $x['date'] <= $to));
    // Nakładania w obrębie pobranego zakresu (trener / sala / grupa)
    $groups = [];
    foreach ($sessions as $i => $s) {
        if ($s['status'] === 'cancelled' || $s['archived']) continue;
        if ($s['instructor_id']) $groups['i' . $s['instructor_id'] . '|' . $s['date']][] = $i;
        if ($s['room_id'])       $groups['r' . $s['room_id'] . '|' . $s['date']][] = $i;
        $groups['c' . $s['course_id'] . '|' . $s['date']][] = $i;
    }
    $label = ['i' => 'instructor', 'r' => 'room', 'c' => 'course'];
    foreach ($groups as $key => $idx) {
        if (count($idx) < 2) continue;
        foreach ($idx as $a) foreach ($idx as $b) {
            if ($a >= $b) continue;
            if ($sessions[$a]['from'] < $sessions[$b]['to'] && $sessions[$b]['from'] < $sessions[$a]['to']) {
                foreach ([$a, $b] as $x) $sessions[$x]['conflicts'][$label[$key[0]]] = true;
            }
        }
    }
    foreach ($sessions as &$s) $s['conflicts'] = array_keys($s['conflicts']);
    unset($s);

    // Zasoby
    $instr = [];
    foreach (db_all(
        "SELECT DISTINCT u.id, u.name FROM users u
          WHERE u.id IN (SELECT instructor_id FROM k30_ti_courses WHERE instructor_id IS NOT NULL
                          AND COALESCE(closed_at,'')='' AND COALESCE(status,'active')!='cancelled')
             OR u.id IN (SELECT instructor_id FROM k30_ti_sessions WHERE instructor_id IS NOT NULL AND lesson_date BETWEEN ? AND ?)
             OR (u.k30_consultant=1 AND u.is_active=1)
          ORDER BY u.name", [$from, $to]) as $u) $instr[] = ['id' => (int)$u['id'], 'name' => (string)$u['name']];
    $rooms = [];
    foreach (db_all("SELECT id, name, capacity FROM k30_pl_rooms WHERE is_active=1 ORDER BY name") as $r) $rooms[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'capacity' => (int)$r['capacity']];
    $courses = [];
    foreach (db_all("SELECT id, name, group_code, instructor_id FROM k30_ti_courses
                      WHERE COALESCE(closed_at,'')='' AND COALESCE(status,'active')!='cancelled' AND is_active=1 ORDER BY name") as $c) {
        $courses[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'code' => (string)$c['group_code'], 'instructor_id' => (int)$c['instructor_id']];
    }

    // Dostępność (zatwierdzone okna) i urlopy trenerów
    $avail = []; $leaves = [];
    foreach ($instr as $u) {
        $w = [];
        foreach (ti_instructor_availability($u['id'], 'approved') as $a) {
            $w[] = ['dow' => (int)$a['day_of_week'], 'from' => substr((string)$a['time_from'], 0, 5), 'to' => substr((string)$a['time_to'], 0, 5),
                    'vf' => (string)($a['valid_from'] ?? ''), 'vt' => (string)($a['valid_to'] ?? '')];
        }
        if ($w) $avail[$u['id']] = $w;
    }
    ti_leaves_migrate();
    foreach (db_all("SELECT instructor_id, date_from, date_to, time_from, time_to, type FROM k30_ti_instructor_leaves WHERE date_to>=? AND date_from<=?", [$from, $to]) as $l) {
        $leaves[(int)$l['instructor_id']][] = ['from' => (string)$l['date_from'], 'to' => (string)$l['date_to'], 'type' => (string)$l['type'],
            'tf' => substr((string)($l['time_from'] ?? ''), 0, 5), 'tt' => substr((string)($l['time_to'] ?? ''), 0, 5)];
    }
    $hol = [];
    try { foreach (db_all("SELECT date_from, date_to, name FROM k30_ti_holidays WHERE date_to>=? AND date_from<=?", [$from, $to]) as $h) $hol[] = ['from' => (string)$h['date_from'], 'to' => (string)$h['date_to'], 'name' => (string)$h['name']]; } catch (\Throwable $e) {}

    return ['sessions' => $sessions, 'instructors' => $instr, 'rooms' => $rooms, 'courses' => $courses,
            'avail' => $avail, 'leaves' => $leaves, 'holidays' => $hol];
}
