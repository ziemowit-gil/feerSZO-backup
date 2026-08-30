<?php
/**
 * includes/ti_room_reports.php — Zestawienia lokalizacji/sal dla zajęć stacjonarnych TI.
 *
 * Trzy widoki oparte na istniejącej infrastrukturze sal (k30_pl_rooms,
 * k30_ti_sessions.room_id — patrz includes/ti_planner_ext.php):
 *   - ti_librus_grid()            → siatka dzień×godzina dla jednej grupy (plan_librus.php)
 *   - ti_group_location_summary()  → tabela Grupa|Dzień i Godziny|Lokalizacja (harmonogram_lokalizacje.php)
 *   - ti_room_reservation_report() → wykaz sal do rezerwacji, pogrupowany dniami (sale_rezerwacje.php)
 *
 * Wymaga: includes/karty30.php + includes/ti_planner_ext.php już załadowane
 * i zmigrowane (karty30_migrate() + ti_planner_ext_migrate()).
 */

declare(strict_types=1);

const TI_DAYS_PL_FULL = [1=>'Poniedziałek',2=>'Wtorek',3=>'Środa',4=>'Czwartek',5=>'Piątek',6=>'Sobota',7=>'Niedziela'];

/**
 * Zakres dat dla wykazu sal do rezerwacji — wspólny dla widoku HTML (sale_rezerwacje.php)
 * i eksportu PDF (sale_rezerwacje_pdf.php), żeby logika „tydzień/miesiąc/3 miesiące"
 * i nawigacja prev/next nie rozjechały się między nimi.
 *
 * @param string $w      data kotwicząca zakres (Y-m-d)
 * @param string $range  'week' | 'month' | 'quarter'
 * @return array{from:string,to:string,prev:string,next:string,label:string}
 */
function ti_room_reservation_range(string $w, string $range): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $w)) $w = date('Y-m-d');
    $months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                  7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];

    if ($range === 'month') {
        $from  = date('Y-m-01', strtotime($w));
        $to    = date('Y-m-t',  strtotime($w));
        $prev  = date('Y-m-01', strtotime($from . ' -1 month'));
        $next  = date('Y-m-01', strtotime($from . ' +1 month'));
        $label = $months_pl[(int)date('n', strtotime($from))] . ' ' . date('Y', strtotime($from));
    } elseif ($range === 'quarter') {
        $from  = date('Y-m-01', strtotime($w));
        $to    = date('Y-m-t',  strtotime($from . ' +2 months'));
        $prev  = date('Y-m-01', strtotime($from . ' -3 months'));
        $next  = date('Y-m-01', strtotime($from . ' +3 months'));
        $label = date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)) . ' (3 mies.)';
    } else {
        $from  = date('Y-m-d', strtotime('monday this week', strtotime($w)));
        $to    = date('Y-m-d', strtotime($from . ' +6 days'));
        $prev  = date('Y-m-d', strtotime($from . ' -7 days'));
        $next  = date('Y-m-d', strtotime($from . ' +7 days'));
        $label = date('d.m', strtotime($from)) . '–' . date('d.m.Y', strtotime($to));
    }
    return ['from' => $from, 'to' => $to, 'prev' => $prev, 'next' => $next, 'label' => $label];
}

/** Etykieta lokalizacji dla wyświetlenia: nazwa sali + jej lokalizacja, albo wolny tekst z kursu. */
function ti_room_label(?array $room, ?string $course_location = null): string {
    if ($room) {
        $lbl = (string)$room['name'];
        if (trim((string)$room['location']) !== '') $lbl .= ' (' . trim((string)$room['location']) . ')';
        return $lbl;
    }
    return trim((string)$course_location) !== '' ? trim((string)$course_location) : '—';
}

/**
 * Siatka tygodniowa (dzień × godzina) dla jednej grupy — odpowiednik „Planu
 * lekcji" z Librusa: wiersze to godziny zajęć tej grupy, kolumny to dni
 * tygodnia, komórka pokazuje przedmiot/grupę, prowadzącego i salę.
 */
function ti_librus_grid(int $course_id, int $weeks = 8): array {
    $course = db_one(
        "SELECT c.*, u.name AS instructor_name, st.name AS subject_name, st.abbreviation AS subject_abbr
         FROM k30_ti_courses c
         LEFT JOIN users u ON u.id = c.instructor_id
         LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
         WHERE c.id = ?",
        [$course_id]
    );
    if (!$course) return ['course' => null, 'time_slots' => [], 'grid' => []];

    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime("+{$weeks} weeks"));
    $rows = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, s.instructor_id, s.room_id,
                u.name AS instr_name, r.name AS room_name, r.location AS room_location
         FROM k30_ti_sessions s
         LEFT JOIN users u ON u.id = s.instructor_id
         LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
         WHERE s.course_id = ? AND s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
         ORDER BY s.lesson_date, s.time_from",
        [$course_id, $from, $to]
    );

    $subject = $course['subject_abbr'] ?: ($course['subject_name'] ?: $course['name']);

    // Grupuj po (dow, time_from, time_to) — biorąc najczęściej występującego
    // prowadzącego/salę w tym slocie (zwykle jednorodne, ale zastępstwa się zdarzają).
    $slots = [];
    foreach ($rows as $r) {
        $dow = (int)date('N', strtotime((string)$r['lesson_date']));
        $tf  = substr((string)$r['time_from'], 0, 5);
        $tt  = substr((string)$r['time_to'], 0, 5);
        $tk  = $tf . '–' . $tt;
        $slots[$tk][$dow]['count']       = ($slots[$tk][$dow]['count'] ?? 0) + 1;
        $slots[$tk][$dow]['instructors'][] = $r['instr_name'] ?: $course['instructor_name'];
        $room_lbl = ti_room_label($r['room_id'] ? ['name' => $r['room_name'], 'location' => $r['room_location']] : null, $course['location']);
        $slots[$tk][$dow]['rooms'][] = $room_lbl;
        $slots[$tk][$dow]['dates'][] = (string)$r['lesson_date'];
    }

    $grid = [];
    foreach ($slots as $tk => $days) {
        foreach ($days as $dow => $d) {
            $instr_counts = array_count_values(array_filter($d['instructors']));
            $room_counts  = array_count_values(array_filter($d['rooms']));
            arsort($instr_counts); arsort($room_counts);
            $grid[$tk][$dow] = [
                'subject'    => $subject,
                'instructor' => (string)array_key_first($instr_counts) ?: '—',
                'room'       => (string)array_key_first($room_counts) ?: '—',
                'n_dates'    => count($d['dates']),
            ];
        }
    }
    $time_slots = array_keys($grid);
    usort($time_slots, fn($a, $b) => substr($a, 0, 5) <=> substr($b, 0, 5));

    return [
        'course'     => $course,
        'from'       => $from,
        'to'         => $to,
        'time_slots' => $time_slots,
        'grid'       => $grid,
    ];
}

/**
 * Tabela podsumowująca: Grupa | Dzień i Godziny | Lokalizacja — jeden wiersz
 * na każdy odrębny (dzień tygodnia, godzina) wzorzec spotkań grupy stacjonarnej,
 * wyprowadzony z faktycznie zaplanowanych/odbytych terminów (a nie z pól
 * opisowych kursu, które mogą być nieaktualne).
 */
function ti_group_location_summary(int $weeks = 8): array {
    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime("+{$weeks} weeks"));

    $rows = db_all(
        "SELECT s.course_id, s.lesson_date, s.time_from, s.time_to, s.room_id,
                c.name AS course_name, c.location AS course_location, c.group_code,
                r.name AS room_name, r.location AS room_location
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id = s.course_id
         LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
         WHERE s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
           AND (s.lesson_method = 'stacjonarna' OR s.lesson_method = '' OR s.lesson_method IS NULL)
         ORDER BY c.name COLLATE NOCASE, s.lesson_date, s.time_from",
        [$from, $to]
    );

    $groups = [];
    foreach ($rows as $r) {
        $dow = (int)date('N', strtotime((string)$r['lesson_date']));
        $tf  = substr((string)$r['time_from'], 0, 5);
        $tt  = substr((string)$r['time_to'], 0, 5);
        $room_lbl = ti_room_label($r['room_id'] ? ['name' => $r['room_name'], 'location' => $r['room_location']] : null, $r['course_location']);
        $tk = $r['course_id'] . '|' . $dow . '|' . $tf . '|' . $tt . '|' . $room_lbl;
        if (!isset($groups[$tk])) {
            $groups[$tk] = [
                'course_id'   => (int)$r['course_id'],
                'course_name' => (string)$r['course_name'],
                'group_code'  => (string)($r['group_code'] ?? ''),
                'dow'         => $dow,
                'day_label'   => TI_DAYS_PL_FULL[$dow] ?? '',
                'time_from'   => $tf,
                'time_to'     => $tt,
                'location'    => $room_lbl,
                'n_dates'     => 0,
            ];
        }
        $groups[$tk]['n_dates']++;
    }
    uasort($groups, fn($a, $b) => [$a['course_name'], $a['dow'], $a['time_from']] <=> [$b['course_name'], $b['dow'], $b['time_from']]);
    return array_values($groups);
}

/**
 * Wykaz sal do rezerwacji — wszystkie terminy z przypisaną salą w zakresie dat,
 * pogrupowane dniami; do zgłoszenia zapotrzebowania koordynatorowi logistycznemu.
 */
function ti_room_reservation_report(string $from, string $to): array {
    $rows = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.room_id, s.room_reservation_status,
                s.instructor_id, c.name AS course_name, c.instructor_id AS course_instructor_id,
                u.name AS instr_name, cu.name AS course_instr_name,
                r.name AS room_name, r.location AS room_location
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id = s.course_id
         JOIN k30_pl_rooms r ON r.id = s.room_id
         LEFT JOIN users u  ON u.id = s.instructor_id
         LEFT JOIN users cu ON cu.id = c.instructor_id
         WHERE s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
         ORDER BY s.lesson_date, r.name, s.time_from",
        [$from, $to]
    );

    $by_day = [];
    foreach ($rows as $r) {
        $r['instructor_label'] = (string)($r['instr_name'] ?: $r['course_instr_name'] ?: '—');
        $r['room_label']       = ti_room_label(['name' => $r['room_name'], 'location' => $r['room_location']]);
        $by_day[(string)$r['lesson_date']][] = $r;
    }
    return $by_day;
}
