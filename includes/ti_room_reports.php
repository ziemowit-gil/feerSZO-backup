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

/**
 * Dane organizacji do nagłówka wydruków TI (plan_librus*.php) — te same klucze
 * org_setting() co reszta systemu (invoice_pdf.php, certificates.php…).
 */
function ti_org_contact_info(): array {
    return [
        'name'    => (string)(org_setting('org_name') ?: (defined('APP_ORG') ? APP_ORG : (defined('ORG_NAME') ? ORG_NAME : ''))),
        'address' => (string)org_setting('org_adres'),
        'phone'   => (string)org_setting('org_telefon'),
        'email'   => (string)org_setting('org_email'),
        'www'     => (string)org_setting('org_www'),
    ];
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
        "SELECT c.*, u.name AS instructor_name, u.phone_number AS instructor_phone, u.email AS instructor_email,
                st.name AS subject_name, st.abbreviation AS subject_abbr
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
        "SELECT s.lesson_date, s.time_from, s.time_to, s.instructor_id, s.room_id, s.date_flag,
                s.rescheduled_from_date, s.rescheduled_from_time_from, s.rescheduled_from_time_to,
                u.name AS instr_name, u.phone_number AS instr_phone, u.email AS instr_email,
                r.name AS room_name, r.location AS room_location
         FROM k30_ti_sessions s
         LEFT JOIN users u ON u.id = s.instructor_id
         LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
         WHERE s.course_id = ? AND s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
         ORDER BY s.lesson_date, s.time_from",
        [$course_id, $from, $to]
    );

    $subject = $course['subject_abbr'] ?: ($course['subject_name'] ?: $course['name']);
    // Kontakty prowadzących faktycznie występujących w oknie wydruku (domyślny
    // + zastępstwa) — do sekcji „Kontakty do prowadzących" w plan_librus.php.
    $instructors_seen = [];
    if (trim((string)$course['instructor_name']) !== '') {
        $instructors_seen[$course['instructor_name']] = ['name' => $course['instructor_name'], 'phone' => (string)($course['instructor_phone'] ?? ''), 'email' => (string)($course['instructor_email'] ?? '')];
    }

    // Grupuj po (dow, time_from, time_to) — biorąc najczęściej występującego
    // prowadzącego/salę w tym slocie (zwykle jednorodne, ale zastępstwa się zdarzają).
    // Lekcja przeniesioną na inny termin (rescheduled_from_*) grupujemy wg jej
    // PIERWOTNEGO slotu, żeby siatka nadal pokazywała normalny plan tygodnia —
    // sam wyjątek trafia osobno do $exceptions (adnotacja pod siatką w wydruku).
    $slots = [];
    $exceptions = [];
    foreach ($rows as $r) {
        $has_orig = trim((string)($r['rescheduled_from_date'] ?? '')) !== '';
        $group_date = $has_orig ? (string)$r['rescheduled_from_date']      : (string)$r['lesson_date'];
        $group_tf   = $has_orig ? (string)$r['rescheduled_from_time_from'] : (string)$r['time_from'];
        $group_tt   = $has_orig ? (string)$r['rescheduled_from_time_to']   : (string)$r['time_to'];

        $dow = (int)date('N', strtotime($group_date));
        $tf  = substr($group_tf, 0, 5);
        $tt  = substr($group_tt, 0, 5);
        $tk  = $tf . '–' . $tt;
        $slots[$tk][$dow]['count']       = ($slots[$tk][$dow]['count'] ?? 0) + 1;
        $_instr_name = $r['instr_name'] ?: $course['instructor_name'];
        $slots[$tk][$dow]['instructors'][] = $_instr_name;
        if (trim((string)$_instr_name) !== '' && !isset($instructors_seen[$_instr_name])) {
            $instructors_seen[$_instr_name] = [
                'name'  => $_instr_name,
                'phone' => (string)($r['instr_name'] ? ($r['instr_phone'] ?? '') : ($course['instructor_phone'] ?? '')),
                'email' => (string)($r['instr_name'] ? ($r['instr_email'] ?? '') : ($course['instructor_email'] ?? '')),
            ];
        }
        $room_lbl = ti_room_label($r['room_id'] ? ['name' => $r['room_name'], 'location' => $r['room_location']] : null, $course['location']);
        $slots[$tk][$dow]['rooms'][] = $room_lbl;
        $slots[$tk][$dow]['dates'][] = $group_date;
        $slots[$tk][$dow]['flags'][] = (string)($r['date_flag'] ?? '');

        if ($has_orig) {
            $exceptions[] = [
                'from_label' => date('d.m.Y', strtotime($group_date)) . ' (' . TI_DAYS_PL_FULL[$dow] . '), ' . $tf . '–' . $tt,
                'to_label'   => date('d.m.Y', strtotime((string)$r['lesson_date'])) . ' (' . (TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$r['lesson_date']))] ?? '') . '), '
                                . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5),
                'room'       => $room_lbl,
            ];
        }
    }

    $grid = [];
    foreach ($slots as $tk => $days) {
        foreach ($days as $dow => $d) {
            $instr_counts = array_count_values(array_filter($d['instructors']));
            $room_counts  = array_count_values(array_filter($d['rooms']));
            arsort($instr_counts); arsort($room_counts);
            sort($d['dates']);
            // Adnotacja niepewności terminu (K30_TI_DATE_FLAGS) — komórka grupuje
            // wiele konkretnych dat, więc pokazujemy najpilniejszą: możliwa zmiana
            // ma pierwszeństwo przed samą niepewnością terminu.
            $cell_flag = in_array('change_possible', $d['flags'], true) ? 'change_possible'
                       : (in_array('tentative', $d['flags'], true) ? 'tentative' : '');
            $grid[$tk][$dow] = [
                'subject'    => $subject,
                'instructor' => (string)array_key_first($instr_counts) ?: '—',
                'room'       => (string)array_key_first($room_counts) ?: '—',
                'n_dates'    => count($d['dates']),
                'date_flag'  => $cell_flag,
                // Zakres dat, w którym ten konkretny slot faktycznie występuje —
                // istotne, gdy grupa ma WIĘCEJ NIŻ JEDEN slot w oknie wydruku:
                // to znak, że harmonogram grupy zmienił się w trakcie (np. od
                // 1.09 do 30.10 piątek 18:00, a od 1.11 inny dzień/godzina) —
                // patrz $multi_slot niżej i adnotacja "obowiązuje" w plan_librus.php.
                'valid_from' => $d['dates'][0],
                'valid_to'   => $d['dates'][count($d['dates']) - 1],
            ];
        }
    }
    $time_slots = array_keys($grid);
    usort($time_slots, fn($a, $b) => substr($a, 0, 5) <=> substr($b, 0, 5));
    usort($exceptions, fn($a, $b) => $a['from_label'] <=> $b['from_label']);

    // Czy grupa ma w tym oknie więcej niż jeden (dzień, godzina)? Jeśli tak,
    // wydruk pokazuje przy każdej komórce zakres dat, w którym ten slot
    // faktycznie obowiązuje — inaczej dwa następujące po sobie okresy z różnym
    // terminem wyglądałyby jak dwa równoległe, cotygodniowe spotkania naraz.
    $multi_slot = array_sum(array_map('count', $grid)) > 1;

    return [
        'course'      => $course,
        'from'        => $from,
        'to'          => $to,
        'time_slots'  => $time_slots,
        'grid'        => $grid,
        'multi_slot'  => $multi_slot,
        'exceptions'  => $exceptions,
        'instructors' => array_values($instructors_seen),
    ];
}

/**
 * Siatka tygodniowa (dzień × godzina) ZAGREGOWANA ze wszystkich aktywnych
 * zapisów kursanta — osobisty „plan lekcji" w konwencji Librusa, analogiczny
 * do ti_librus_grid() dla jednej grupy, ale scalający wszystkie jego kursy.
 * Komórka to LISTA wpisów (zwykle jeden) — dwa różne kursy w tym samym slocie
 * (rzadka kolizja terminów) trafiają osobno zamiast się zlewać w jeden wpis.
 * Wpis z linkiem (Zoom/inne) — tylko gdy zajęcia zdalne i link faktycznie ustawiony.
 */
function ti_librus_grid_client(int $client_id, int $weeks = 8): array {
    $client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]);
    if (!$client) return ['client' => null, 'time_slots' => [], 'grid' => [], 'exceptions' => []];

    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime("+{$weeks} weeks"));
    $rows = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, s.room_id, s.date_flag,
                s.lesson_method, s.meeting_url,
                s.rescheduled_from_date, s.rescheduled_from_time_from, s.rescheduled_from_time_to,
                s.course_id, c.name AS course_name, c.location AS course_location, c.default_meeting_url,
                st.abbreviation AS subject_abbr, st.name AS subject_name,
                COALESCE(iu.name, cu.name) AS instr_name,
                COALESCE(iu.phone_number, cu.phone_number) AS instr_phone,
                COALESCE(iu.email, cu.email) AS instr_email,
                r.name AS room_name, r.location AS room_location
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN users iu ON iu.id = s.instructor_id
           LEFT JOIN users cu ON cu.id = c.instructor_id
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
           LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
          WHERE s.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
            AND s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
          ORDER BY s.lesson_date, s.time_from",
        [$client_id, $from, $to]
    );

    // Grupuj po (dow, time_from, time_to, course_id) — jak ti_librus_grid(), ale
    // klucz obejmuje kurs, więc kolizja dwóch kursów w tym samym slocie zostaje
    // dwoma osobnymi wpisami komórki zamiast się zlać w jeden.
    $cells = [];
    $slot_keys_per_course = [];
    $exceptions = [];
    $instructors_seen = [];
    foreach ($rows as $r) {
        $has_orig = trim((string)($r['rescheduled_from_date'] ?? '')) !== '';
        $group_date = $has_orig ? (string)$r['rescheduled_from_date']      : (string)$r['lesson_date'];
        $group_tf   = $has_orig ? (string)$r['rescheduled_from_time_from'] : (string)$r['time_from'];
        $group_tt   = $has_orig ? (string)$r['rescheduled_from_time_to']   : (string)$r['time_to'];

        $dow = (int)date('N', strtotime($group_date));
        $tf  = substr($group_tf, 0, 5);
        $tt  = substr($group_tt, 0, 5);
        $tk  = $tf . '–' . $tt;
        $ck  = (int)$r['course_id'];
        $slot_keys_per_course[$ck][$tk . '|' . $dow] = true;

        $room_lbl = ti_room_label($r['room_id'] ? ['name' => $r['room_name'], 'location' => $r['room_location']] : null, $r['course_location']);
        $cells[$tk][$dow][$ck]['subject']    = $r['subject_abbr'] ?: ($r['subject_name'] ?: $r['course_name']);
        $cells[$tk][$dow][$ck]['instructor'] = $r['instr_name'] ?: '—';
        if (trim((string)$r['instr_name']) !== '' && !isset($instructors_seen[$r['instr_name']])) {
            $instructors_seen[$r['instr_name']] = [
                'name' => $r['instr_name'], 'phone' => (string)($r['instr_phone'] ?? ''), 'email' => (string)($r['instr_email'] ?? ''),
            ];
        }
        $cells[$tk][$dow][$ck]['rooms'][]    = $room_lbl;
        $cells[$tk][$dow][$ck]['dates'][]    = $group_date;
        $cells[$tk][$dow][$ck]['flags'][]    = (string)($r['date_flag'] ?? '');
        if (in_array($r['lesson_method'], ['zdalna_zoom', 'zdalna_inne'], true)) {
            $link = trim((string)($r['meeting_url'] ?: $r['default_meeting_url']));
            if ($link !== '') $cells[$tk][$dow][$ck]['link'] = $link;
        }

        if ($has_orig) {
            $exceptions[] = [
                'from_label' => date('d.m.Y', strtotime($group_date)) . ' (' . TI_DAYS_PL_FULL[$dow] . '), ' . $tf . '–' . $tt,
                'to_label'   => date('d.m.Y', strtotime((string)$r['lesson_date'])) . ' (' . (TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$r['lesson_date']))] ?? '') . '), '
                                . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5),
                'course'     => (string)$r['course_name'],
            ];
        }
    }

    $grid = [];
    foreach ($cells as $tk => $days) {
        foreach ($days as $dow => $courses_in_slot) {
            $items = [];
            foreach ($courses_in_slot as $ck => $c) {
                $room_counts = array_count_values(array_filter($c['rooms']));
                arsort($room_counts);
                sort($c['dates']);
                $cell_flag = in_array('change_possible', $c['flags'], true) ? 'change_possible'
                           : (in_array('tentative', $c['flags'], true) ? 'tentative' : '');
                $items[] = [
                    'subject'    => $c['subject'],
                    'instructor' => $c['instructor'],
                    'room'       => (string)array_key_first($room_counts) ?: '—',
                    'valid_from' => $c['dates'][0],
                    'valid_to'   => $c['dates'][count($c['dates']) - 1],
                    // Ten konkretny kurs zmienił dzień/godzinę w oknie wydruku —
                    // pokaż zakres dat, w którym TA komórka obowiązuje (jak przy grupie).
                    'multi_slot' => count($slot_keys_per_course[$ck] ?? []) > 1,
                    'date_flag'  => $cell_flag,
                    'link'       => $c['link'] ?? '',
                ];
            }
            $grid[$tk][$dow] = $items;
        }
    }
    $time_slots = array_keys($grid);
    usort($time_slots, fn($a, $b) => substr($a, 0, 5) <=> substr($b, 0, 5));
    usort($exceptions, fn($a, $b) => $a['from_label'] <=> $b['from_label']);

    return [
        'client'      => $client,
        'from'        => $from,
        'to'          => $to,
        'time_slots'  => $time_slots,
        'grid'        => $grid,
        'exceptions'  => $exceptions,
        'instructors' => array_values($instructors_seen),
    ];
}

/**
 * Siatka tygodniowa (dzień × godzina) ZBIORCZA dla CAŁEJ instytucji — wszystkie
 * aktywne kursy naraz, nie jednego klienta ani jednej grupy. Ten sam kształt
 * komórki co ti_librus_grid_client() (LISTA wpisów — tu kolizje w tym samym
 * slocie są NORMĄ, nie wyjątkiem, bo różne grupy zwykle mają zajęcia
 * równolegle). „Subject" to pełna nazwa kursu (nie skrót przedmiotu) — w
 * przeglądzie całej instytucji liczy się KTÓRA grupa, nie tylko jaki przedmiot.
 */
function ti_librus_grid_institution(int $weeks = 8): array {
    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime("+{$weeks} weeks"));
    $rows = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, s.room_id, s.date_flag,
                s.lesson_method, s.meeting_url,
                s.rescheduled_from_date, s.rescheduled_from_time_from, s.rescheduled_from_time_to,
                s.course_id, c.name AS course_name, c.location AS course_location, c.default_meeting_url,
                COALESCE(iu.name, cu.name) AS instr_name,
                COALESCE(iu.phone_number, cu.phone_number) AS instr_phone,
                COALESCE(iu.email, cu.email) AS instr_email,
                r.name AS room_name, r.location AS room_location
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN users iu ON iu.id = s.instructor_id
           LEFT JOIN users cu ON cu.id = c.instructor_id
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
          WHERE c.is_active = 1 AND c.status NOT IN ('cancelled', 'archived')
            AND s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
          ORDER BY s.lesson_date, s.time_from",
        [$from, $to]
    );

    $cells = [];
    $slot_keys_per_course = [];
    $exceptions = [];
    $instructors_seen = [];
    foreach ($rows as $r) {
        $has_orig = trim((string)($r['rescheduled_from_date'] ?? '')) !== '';
        $group_date = $has_orig ? (string)$r['rescheduled_from_date']      : (string)$r['lesson_date'];
        $group_tf   = $has_orig ? (string)$r['rescheduled_from_time_from'] : (string)$r['time_from'];
        $group_tt   = $has_orig ? (string)$r['rescheduled_from_time_to']   : (string)$r['time_to'];

        $dow = (int)date('N', strtotime($group_date));
        $tf  = substr($group_tf, 0, 5);
        $tt  = substr($group_tt, 0, 5);
        $tk  = $tf . '–' . $tt;
        $ck  = (int)$r['course_id'];
        $slot_keys_per_course[$ck][$tk . '|' . $dow] = true;

        $room_lbl = ti_room_label($r['room_id'] ? ['name' => $r['room_name'], 'location' => $r['room_location']] : null, $r['course_location']);
        $cells[$tk][$dow][$ck]['subject']    = (string)$r['course_name'];
        $cells[$tk][$dow][$ck]['instructor'] = $r['instr_name'] ?: '—';
        if (trim((string)$r['instr_name']) !== '' && !isset($instructors_seen[$r['instr_name']])) {
            $instructors_seen[$r['instr_name']] = [
                'name' => $r['instr_name'], 'phone' => (string)($r['instr_phone'] ?? ''), 'email' => (string)($r['instr_email'] ?? ''),
            ];
        }
        $cells[$tk][$dow][$ck]['rooms'][]    = $room_lbl;
        $cells[$tk][$dow][$ck]['dates'][]    = $group_date;
        $cells[$tk][$dow][$ck]['flags'][]    = (string)($r['date_flag'] ?? '');
        if (in_array($r['lesson_method'], ['zdalna_zoom', 'zdalna_inne'], true)) {
            $link = trim((string)($r['meeting_url'] ?: $r['default_meeting_url']));
            if ($link !== '') $cells[$tk][$dow][$ck]['link'] = $link;
        }

        if ($has_orig) {
            $exceptions[] = [
                'from_label' => date('d.m.Y', strtotime($group_date)) . ' (' . TI_DAYS_PL_FULL[$dow] . '), ' . $tf . '–' . $tt,
                'to_label'   => date('d.m.Y', strtotime((string)$r['lesson_date'])) . ' (' . (TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$r['lesson_date']))] ?? '') . '), '
                                . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5),
                'course'     => (string)$r['course_name'],
            ];
        }
    }

    $grid = [];
    foreach ($cells as $tk => $days) {
        foreach ($days as $dow => $courses_in_slot) {
            $items = [];
            foreach ($courses_in_slot as $ck => $c) {
                $room_counts = array_count_values(array_filter($c['rooms']));
                arsort($room_counts);
                sort($c['dates']);
                $cell_flag = in_array('change_possible', $c['flags'], true) ? 'change_possible'
                           : (in_array('tentative', $c['flags'], true) ? 'tentative' : '');
                $items[] = [
                    'subject'    => $c['subject'],
                    'instructor' => $c['instructor'],
                    'room'       => (string)array_key_first($room_counts) ?: '—',
                    'valid_from' => $c['dates'][0],
                    'valid_to'   => $c['dates'][count($c['dates']) - 1],
                    'multi_slot' => count($slot_keys_per_course[$ck] ?? []) > 1,
                    'date_flag'  => $cell_flag,
                    'link'       => $c['link'] ?? '',
                ];
            }
            // W przeglądzie całej instytucji jedna komórka regularnie zbiera kilka
            // równoległych grup — sortuj wg przedmiotu, żeby wydruk był czytelny.
            usort($items, fn($a, $b) => strcmp($a['subject'], $b['subject']));
            $grid[$tk][$dow] = $items;
        }
    }
    $time_slots = array_keys($grid);
    usort($time_slots, fn($a, $b) => substr($a, 0, 5) <=> substr($b, 0, 5));
    usort($exceptions, fn($a, $b) => $a['from_label'] <=> $b['from_label']);

    return [
        'from'        => $from,
        'to'          => $to,
        'time_slots'  => $time_slots,
        'grid'        => $grid,
        'exceptions'  => $exceptions,
        'instructors' => array_values($instructors_seen),
    ];
}

/**
 * Siatka tygodniowa (dzień × godzina) ZAGREGOWANA ze wszystkich kursów, które
 * dany prowadzący faktycznie prowadzi w oknie wydruku — własne kursy ORAZ
 * zastępstwa (s.instructor_id nadpisuje c.instructor_id per lekcja, jak
 * wszędzie indziej w module). Komórka to LISTA wpisów jak w siatce kursanta —
 * kolizja dwóch WŁASNYCH kursów w tym samym slocie (rzadka, ale realna przy
 * kilku grupach) trafia osobno.
 */
function ti_librus_grid_instructor(int $instructor_id, int $weeks = 8): array {
    $instructor = db_one("SELECT id, name, phone_number, email FROM users WHERE id=?", [$instructor_id]);
    if (!$instructor) return ['instructor' => null, 'time_slots' => [], 'grid' => [], 'exceptions' => [], 'instructors' => []];

    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime("+{$weeks} weeks"));
    $rows = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, s.room_id, s.date_flag,
                s.lesson_method, s.meeting_url,
                s.rescheduled_from_date, s.rescheduled_from_time_from, s.rescheduled_from_time_to,
                s.course_id, c.name AS course_name, c.location AS course_location, c.default_meeting_url,
                st.abbreviation AS subject_abbr, st.name AS subject_name,
                r.name AS room_name, r.location AS room_location
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
           LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
          WHERE COALESCE(s.instructor_id, c.instructor_id) = ?
            AND s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
          ORDER BY s.lesson_date, s.time_from",
        [$instructor_id, $from, $to]
    );

    $cells = [];
    $slot_keys_per_course = [];
    $exceptions = [];
    foreach ($rows as $r) {
        $has_orig = trim((string)($r['rescheduled_from_date'] ?? '')) !== '';
        $group_date = $has_orig ? (string)$r['rescheduled_from_date']      : (string)$r['lesson_date'];
        $group_tf   = $has_orig ? (string)$r['rescheduled_from_time_from'] : (string)$r['time_from'];
        $group_tt   = $has_orig ? (string)$r['rescheduled_from_time_to']   : (string)$r['time_to'];

        $dow = (int)date('N', strtotime($group_date));
        $tf  = substr($group_tf, 0, 5);
        $tt  = substr($group_tt, 0, 5);
        $tk  = $tf . '–' . $tt;
        $ck  = (int)$r['course_id'];
        $slot_keys_per_course[$ck][$tk . '|' . $dow] = true;

        $room_lbl = ti_room_label($r['room_id'] ? ['name' => $r['room_name'], 'location' => $r['room_location']] : null, $r['course_location']);
        $cells[$tk][$dow][$ck]['subject']    = $r['subject_abbr'] ?: ($r['subject_name'] ?: $r['course_name']);
        $cells[$tk][$dow][$ck]['instructor'] = (string)$instructor['name'];
        $cells[$tk][$dow][$ck]['rooms'][]    = $room_lbl;
        $cells[$tk][$dow][$ck]['dates'][]    = $group_date;
        $cells[$tk][$dow][$ck]['flags'][]    = (string)($r['date_flag'] ?? '');
        if (in_array($r['lesson_method'], ['zdalna_zoom', 'zdalna_inne'], true)) {
            $link = trim((string)($r['meeting_url'] ?: $r['default_meeting_url']));
            if ($link !== '') $cells[$tk][$dow][$ck]['link'] = $link;
        }

        if ($has_orig) {
            $exceptions[] = [
                'from_label' => date('d.m.Y', strtotime($group_date)) . ' (' . TI_DAYS_PL_FULL[$dow] . '), ' . $tf . '–' . $tt,
                'to_label'   => date('d.m.Y', strtotime((string)$r['lesson_date'])) . ' (' . (TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$r['lesson_date']))] ?? '') . '), '
                                . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5),
                'course'     => (string)$r['course_name'],
            ];
        }
    }

    $grid = [];
    foreach ($cells as $tk => $days) {
        foreach ($days as $dow => $courses_in_slot) {
            $items = [];
            foreach ($courses_in_slot as $ck => $c) {
                $room_counts = array_count_values(array_filter($c['rooms']));
                arsort($room_counts);
                sort($c['dates']);
                $cell_flag = in_array('change_possible', $c['flags'], true) ? 'change_possible'
                           : (in_array('tentative', $c['flags'], true) ? 'tentative' : '');
                $items[] = [
                    'subject'    => $c['subject'],
                    'instructor' => $c['instructor'],
                    'room'       => (string)array_key_first($room_counts) ?: '—',
                    'valid_from' => $c['dates'][0],
                    'valid_to'   => $c['dates'][count($c['dates']) - 1],
                    'multi_slot' => count($slot_keys_per_course[$ck] ?? []) > 1,
                    'date_flag'  => $cell_flag,
                    'link'       => $c['link'] ?? '',
                ];
            }
            $grid[$tk][$dow] = $items;
        }
    }
    $time_slots = array_keys($grid);
    usort($time_slots, fn($a, $b) => substr($a, 0, 5) <=> substr($b, 0, 5));
    usort($exceptions, fn($a, $b) => $a['from_label'] <=> $b['from_label']);

    return [
        'instructor'  => $instructor,
        'from'        => $from,
        'to'          => $to,
        'time_slots'  => $time_slots,
        'grid'        => $grid,
        'exceptions'  => $exceptions,
        'instructors' => [[
            'name'  => (string)$instructor['name'],
            'phone' => (string)($instructor['phone_number'] ?? ''),
            'email' => (string)($instructor['email'] ?? ''),
        ]],
    ];
}

/**
 * PDF (bajty) siatki dzień×godzina — wspólne dla ti_librus_grid() (grupa,
 * komórka = jeden wpis) i ti_librus_grid_client() (kursant, komórka = LISTA
 * wpisów). Normalizuje oba kształty do listy wpisów na komórkę.
 *
 * @param array $L       wynik ti_librus_grid()/ti_librus_grid_client()
 * @param array $dow_lbl [dzień_tygodnia(1-7) => etykieta]
 * @param array $opts    ['title','subtitle','footer']
 */
function ti_librus_grid_pdf(array $L, array $dow_lbl, array $opts = []): string {
    require_once __DIR__ . '/fpdf/fpdf.php';
    $pl = fn($s) => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', (string)$s) ?: (string)$s;

    $pdf = new \FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 20;

    $pdf->SetFillColor(30, 41, 59);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell($W, 9, $pl((string)($opts['title'] ?? 'Plan zajęć')), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 8);
    if (!empty($opts['subtitle'])) $pdf->Cell($W, 5, $pl((string)$opts['subtitle']), 0, 1);
    if (!empty($opts['org']) && is_array($opts['org'])) {
        $org = $opts['org'];
        $orgLine = implode(' · ', array_filter([$org['name'] ?? '', $org['address'] ?? '', $org['phone'] ?? '', $org['email'] ?? '']));
        if ($orgLine !== '') {
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell($W, 4.5, $pl($orgLine), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
        }
    }
    $pdf->Ln(2);

    $timeColW = 22;
    $dayColW  = ($W - $timeColW) / count($dow_lbl);
    $lineH    = 4;

    $drawHead = function () use ($pdf, $pl, $timeColW, $dayColW, $dow_lbl) {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetTextColor(51, 65, 85);
        $pdf->Cell($timeColW, 7, $pl('Godzina'), 1, 0, 'C', true);
        foreach ($dow_lbl as $lbl) { $pdf->Cell($dayColW, 7, $pl($lbl), 1, 0, 'C', true); }
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
    };
    $drawHead();

    // Wpis komórki -> linie tekstu (etykieta, pogrubienie, kolor RGB)
    $cellLines = function (array $item): array {
        $lines = [];
        $lines[] = ['t' => (string)$item['subject'], 'b' => true, 'c' => [17, 17, 17]];
        if (trim((string)($item['instructor'] ?? '')) !== '' && $item['instructor'] !== '—') {
            $lines[] = ['t' => (string)$item['instructor'], 'b' => false, 'c' => [51, 65, 85]];
        }
        if (trim((string)($item['room'] ?? '')) !== '' && $item['room'] !== '—') {
            $lines[] = ['t' => (string)$item['room'], 'b' => false, 'c' => [15, 118, 110]];
        }
        if (!empty($item['multi_slot']) && !empty($item['valid_from']) && !empty($item['valid_to'])) {
            $lines[] = ['t' => 'obow.: ' . date('d.m.y', strtotime((string)$item['valid_from'])) . '-' . date('d.m.y', strtotime((string)$item['valid_to'])),
                        'b' => false, 'c' => [180, 83, 9]];
        }
        if (($item['date_flag'] ?? '') === 'change_possible') {
            $lines[] = ['t' => 'MOZLIWA ZMIANA TERMINU', 'b' => true, 'c' => [7, 89, 133]];
        } elseif (($item['date_flag'] ?? '') === 'tentative') {
            $lines[] = ['t' => 'TERMIN NIEPEWNY', 'b' => true, 'c' => [146, 64, 14]];
        }
        return $lines;
    };

    foreach ($L['time_slots'] as $tk) {
        $colLines = []; $maxLines = 2;
        foreach (array_keys($dow_lbl) as $d) {
            $raw = $L['grid'][$tk][$d] ?? [];
            $items = (is_array($raw) && array_key_exists('subject', $raw)) ? [$raw] : (array)$raw;
            $lines = [];
            foreach ($items as $idx => $it) {
                if ($idx > 0) $lines[] = ['t' => '— — —', 'b' => false, 'c' => [203, 213, 225]];
                foreach ($cellLines($it) as $ln) $lines[] = $ln;
            }
            if (!$lines) $lines[] = ['t' => '—', 'b' => false, 'c' => [203, 213, 225]];
            $colLines[$d] = $lines;
            $maxLines = max($maxLines, count($lines));
        }
        $rowH = max($lineH * 2, $maxLines * $lineH) + 1;

        if ($pdf->GetY() + $rowH > $pdf->GetPageHeight() - 14) {
            $pdf->AddPage();
            $drawHead();
        }

        $x0 = $pdf->GetX(); $y0 = $pdf->GetY();
        $pdf->Rect($x0, $y0, $timeColW, $rowH);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetXY($x0, $y0 + ($rowH - $lineH) / 2);
        $pdf->Cell($timeColW, $lineH, $pl($tk), 0, 0, 'C');

        $x = $x0 + $timeColW;
        foreach (array_keys($dow_lbl) as $d) {
            $pdf->Rect($x, $y0, $dayColW, $rowH);
            $ly = $y0 + 1;
            foreach ($colLines[$d] as $ln) {
                $pdf->SetXY($x + 1, $ly);
                $pdf->SetFont('Helvetica', $ln['b'] ? 'B' : '', 7);
                $pdf->SetTextColor($ln['c'][0], $ln['c'][1], $ln['c'][2]);
                $pdf->Cell($dayColW - 2, $lineH, $pl(mb_strimwidth($ln['t'], 0, 42, '…')), 0, 0, 'L');
                $ly += $lineH;
            }
            $x += $dayColW;
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x0, $y0 + $rowH);
    }

    // Uwagi zbierane z tych samych danych, co wiersze siatki (multi_slot/flagi
    // per komórka) + wyjątki (przeniesione terminy) — jedna lista, jak w HTML.
    $hasMultiSlot = !empty($L['multi_slot']);
    $hasFlags = false;
    foreach ($L['grid'] ?? [] as $_row) {
        foreach ($_row as $_raw) {
            $_items = (is_array($_raw) && array_key_exists('subject', $_raw)) ? [$_raw] : (array)$_raw;
            foreach ($_items as $_it) {
                if (!empty($_it['multi_slot'])) $hasMultiSlot = true;
                if (($_it['date_flag'] ?? '') !== '') $hasFlags = true;
            }
        }
    }
    $notes = [];
    if ($hasMultiSlot) $notes[] = 'Harmonogram zmienia się w wybranym okresie — przy takim terminie podano zakres dat, w którym obowiązuje.';
    if ($hasFlags) $notes[] = 'Etykiety TERMIN NIEPEWNY / MOZLIWA ZMIANA TERMINU dotyczą co najmniej jednej daty w danym slocie.';
    foreach ($L['exceptions'] ?? [] as $ex) {
        $extra = (string)($ex['room'] ?? $ex['course'] ?? '');
        $notes[] = 'Zmiana terminu: ' . $ex['from_label'] . ' -> ' . $ex['to_label'] . ($extra !== '' && $extra !== '—' ? ', ' . $extra : '');
    }

    if (!empty($L['instructors']) || $notes) {
        $pdf->Ln(3);
        if ($pdf->GetY() > $pdf->GetPageHeight() - 25) { $pdf->AddPage(); }
        $colW = ($W - 10) / 2;
        $y0 = $pdf->GetY();

        // Lewa kolumna: kontakty do prowadzących
        $pdf->SetXY(10, $y0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($colW, 6, $pl('Kontakty do prowadzących'), 0, 1);
        $pdf->SetX(10);
        if (!$L['instructors']) {
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell($colW, 5, $pl('Brak przypisanych prowadzących.'), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
        } else {
            foreach ($L['instructors'] as $ins) {
                $pdf->SetX(10);
                $pdf->SetFont('Helvetica', 'B', 7.5);
                $pdf->Cell($colW, 4.5, $pl($ins['name']), 0, 1);
                $contact = implode(' · ', array_filter([$ins['phone'] ?? '', $ins['email'] ?? '']));
                $pdf->SetX(10);
                $pdf->SetFont('Helvetica', '', 7);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->Cell($colW, 4.5, $pl($contact !== '' ? $contact : 'brak danych kontaktowych'), 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }
        }
        $yLeftEnd = $pdf->GetY();

        // Prawa kolumna: uwagi
        $pdf->SetXY(10 + $colW + 10, $y0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($colW, 6, $pl('Uwagi'), 0, 1);
        if (!$notes) {
            $pdf->SetX(10 + $colW + 10);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell($colW, 5, $pl('Brak uwag do wybranego okresu.'), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
        } else {
            $pdf->SetFont('Helvetica', '', 7.5);
            foreach ($notes as $note) {
                $pdf->SetX(10 + $colW + 10);
                $pdf->MultiCell($colW, 4.5, $pl('• ' . $note), 0, 'L');
            }
        }
        $yRightEnd = $pdf->GetY();
        $pdf->SetY(max($yLeftEnd, $yRightEnd));
    }

    if (!empty($opts['footer'])) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 12) { $pdf->AddPage(); }
        $pdf->SetY($pdf->GetPageHeight() - 12);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(130, 130, 130);
        $pdf->Cell($W, 4, $pl((string)$opts['footer']), 0, 0, 'L');
    }

    return $pdf->Output('S');
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
