<?php
/**
 * modules/ti_plan_public/logic/planPublicPortal.php — Portal planu zajęć TI (wzór: plan lekcji online szkoły).
 *
 * Trzy rodzaje stron publicznych (bez logowania), wszystkie w planPubliczny.php:
 *   - portal /plan/            — katalog „Grupy” i „Prowadzący” (TYLKO wpisy z flagą in_directory),
 *   - plan grupy ?g=<token>    — dotychczasowy tajny link grupy (k30_ti_plan_public_links),
 *   - plan prowadzącego ?p=<token> — tajny link prowadzącego (k30_ti_plan_public_instr).
 *
 * Widok = konkretny TYDZIEŃ z datami (TI planuje terminami, nie stałym planem szkolnym):
 * wiersze to godziny zajęć w danym tygodniu, kolumny dni; karta lekcji jak we wzorze.
 *
 * Prywatność: prowadzący zawsze inicjałami; na planie prowadzącego z katalogu nazwy grup
 * spoza katalogu są ukryte („grupa”), bo mogą zawierać nazwiska uczestników.
 */
require_once __DIR__ . '/planPublicManager.php';

/* ───────────────────────── Linki prowadzących ───────────────────────── */

function tiPlanPublicInstrGet(int $userId): ?array {
    tiPlanPublicMigrate();
    return db_one("SELECT * FROM k30_ti_plan_public_instr WHERE user_id=?", [$userId]) ?: null;
}

/** [user_id => wiersz linku] */
function tiPlanPublicInstrMap(): array {
    tiPlanPublicMigrate();
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_plan_public_instr") as $r) $out[(int)$r['user_id']] = $r;
    return $out;
}

function tiPlanPublicInstrEnable(int $userId, int $by): array {
    tiPlanPublicMigrate();
    if (tiPlanPublicInstrGet($userId)) {
        db()->prepare("UPDATE k30_ti_plan_public_instr SET is_enabled=1, updated_at=datetime('now') WHERE user_id=?")->execute([$userId]);
    } else {
        db()->prepare("INSERT INTO k30_ti_plan_public_instr(user_id, token, is_enabled, created_by) VALUES(?,?,1,?)")
            ->execute([$userId, tiPlanPublicToken(), $by]);
    }
    return tiPlanPublicInstrGet($userId);
}

function tiPlanPublicInstrDisable(int $userId): void {
    tiPlanPublicMigrate();
    db()->prepare("UPDATE k30_ti_plan_public_instr SET is_enabled=0, updated_at=datetime('now') WHERE user_id=?")->execute([$userId]);
}

function tiPlanPublicInstrRegenerate(int $userId, int $by): array {
    tiPlanPublicMigrate();
    if (!tiPlanPublicInstrGet($userId)) return tiPlanPublicInstrEnable($userId, $by);
    db()->prepare("UPDATE k30_ti_plan_public_instr SET token=?, views=0, last_view_at=NULL, updated_at=datetime('now') WHERE user_id=?")
        ->execute([tiPlanPublicToken(), $userId]);
    return tiPlanPublicInstrGet($userId);
}

function tiPlanPublicInstrUpdateOptions(int $userId, array $in): void {
    tiPlanPublicMigrate();
    $weeks = (int)($in['weeks'] ?? 12);
    if (!in_array($weeks, TI_PLAN_PUBLIC_WEEKS, true)) $weeks = 12;
    db()->prepare("UPDATE k30_ti_plan_public_instr SET weeks=?, in_directory=?, note=?, updated_at=datetime('now') WHERE user_id=?")
        ->execute([$weeks, !empty($in['in_directory']) ? 1 : 0, mb_substr(trim((string)($in['note'] ?? '')), 0, 500), $userId]);
}

/** Włączony link prowadzącego po tokenie (z nazwą użytkownika). */
function tiPlanPublicInstrFindByToken(string $token): ?array {
    tiPlanPublicMigrate();
    if (!preg_match('/^[a-z0-9]{24}$/', $token)) return null;
    return db_one("SELECT l.*, u.name AS user_name FROM k30_ti_plan_public_instr l JOIN users u ON u.id=l.user_id
                    WHERE l.token=? AND l.is_enabled=1", [$token]) ?: null;
}

function tiPlanPublicInstrTouch(int $linkId): void {
    try {
        db()->prepare("UPDATE k30_ti_plan_public_instr SET views=views+1, last_view_at=datetime('now') WHERE id=?")->execute([$linkId]);
    } catch (\Throwable $e) {}
}

function tiPlanPublicInstrUrl(array $row): string {
    return rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/dydaktyk/planPubliczny.php?p=' . rawurlencode((string)$row['token']);
}

function tiPlanPublicInstrShortUrl(array $row): ?string {
    $u = tiPlanPublicShortUrl($row);
    return $u === null ? null : str_replace('/plan/', '/plan/p/', $u);
}

/** Adres portalu (katalogu) — krótki na subdomenie ti.*, inaczej techniczny. */
function tiPlanPublicPortalUrl(): string {
    $short = tiPlanPublicShortUrl(['token' => '']);
    return $short !== null ? $short : rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/dydaktyk/planPubliczny.php';
}

/** Prowadzący aktywnych grup (domyślni + zastępstwa w terminach od dziś) — do zarządzania linkami. */
function tiPlanPublicInstructors(): array {
    return db_all(
        "SELECT u.id, u.name, COUNT(DISTINCT x.course_id) AS n_courses
           FROM (SELECT c.instructor_id AS uid, c.id AS course_id FROM k30_ti_courses c
                  WHERE c.instructor_id IS NOT NULL AND c.status NOT IN ('archived','cancelled')
                 UNION
                 SELECT s.instructor_id, s.course_id FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
                  WHERE s.instructor_id IS NOT NULL AND s.lesson_date >= date('now') AND c.status NOT IN ('archived','cancelled')) x
           JOIN users u ON u.id = x.uid
          GROUP BY u.id, u.name
          ORDER BY u.name COLLATE NOCASE");
}

/* ───────────────────────── Katalog portalu ───────────────────────── */

/** Grupy w katalogu: link włączony + in_directory + grupa aktywna. */
function tiPlanPublicDirectoryGroups(): array {
    tiPlanPublicMigrate();
    $rows = db_all(
        "SELECT l.token, c.id AS course_id, c.name, u.name AS instr_name, st.name AS subject_name,
                (SELECT MIN(s.lesson_date) FROM k30_ti_sessions s
                  WHERE s.course_id=c.id AND s.lesson_date >= date('now') AND s.draft_id IS NULL
                    AND s.status NOT IN ('draft','reserved','cancelled')) AS next_date
           FROM k30_ti_plan_public_links l
           JOIN k30_ti_courses c ON c.id = l.course_id
           LEFT JOIN users u ON u.id = c.instructor_id
           LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
          WHERE l.is_enabled=1 AND l.in_directory=1 AND c.status NOT IN ('archived','cancelled')
          ORDER BY c.name COLLATE NOCASE");
    foreach ($rows as &$r) $r['instr'] = tiPlanPublicInitials((string)$r['instr_name']);
    return $rows;
}

/** Prowadzący w katalogu: link włączony + in_directory; z nazwami ich grup z katalogu. */
function tiPlanPublicDirectoryInstructors(): array {
    tiPlanPublicMigrate();
    $rows = db_all(
        "SELECT l.token, l.user_id, u.name FROM k30_ti_plan_public_instr l JOIN users u ON u.id=l.user_id
          WHERE l.is_enabled=1 AND l.in_directory=1 ORDER BY u.name COLLATE NOCASE");
    $dirCourses = [];
    foreach (tiPlanPublicDirectoryGroups() as $g) $dirCourses[(int)$g['course_id']] = $g['name'];
    foreach ($rows as &$r) {
        $r['initials'] = tiPlanPublicInitials((string)$r['name']);
        $ids = array_map('intval', array_column(db_all(
            "SELECT DISTINCT c.id FROM k30_ti_courses c
               LEFT JOIN k30_ti_sessions s ON s.course_id=c.id AND s.lesson_date >= date('now')
              WHERE c.status NOT IN ('archived','cancelled') AND (c.instructor_id=? OR s.instructor_id=?)",
            [(int)$r['user_id'], (int)$r['user_id']]), 'id'));
        $r['groups'] = array_values(array_intersect_key($dirCourses, array_flip($ids)));
        unset($r['name'], $r['user_id']);   // pełne nazwisko nie wychodzi poza serwer
    }
    return $rows;
}

/** Zakładka „Sale” w portalu (zajętość i rezerwacje sal) — przełącznik w planLinki.php. */
function tiPlanPublicRoomsEnabled(): bool {
    return org_setting('ti_plan_public_rooms') === '1';
}

/** [course_id => token] grup z katalogu — do linkowania kart lekcji z planów sal i prowadzących. */
function tiPlanPublicDirectoryTokens(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (tiPlanPublicDirectoryGroups() as $g) $map[(int)$g['course_id']] = (string)$g['token'];
    }
    return $map;
}

/** [user_id => token] prowadzących z katalogu — do linkowania z planów grup i sal. */
function tiPlanPublicDirectoryInstrTokens(): array {
    tiPlanPublicMigrate();
    $out = [];
    foreach (db_all("SELECT user_id, token FROM k30_ti_plan_public_instr WHERE is_enabled=1 AND in_directory=1") as $r) $out[(int)$r['user_id']] = (string)$r['token'];
    return $out;
}

/** Sale (aktywne) z liczbą terminów w najbliższych $weeks tygodniach; bez sal bez zajęć. */
function tiPlanPublicDirectoryRooms(int $weeks = 8): array {
    ti_planner_ext_migrate();
    $rows = db_all(
        "SELECT r.id, r.name, r.short_label, r.location, r.capacity, b.name AS building,
                (SELECT COUNT(*) FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
                  WHERE s.room_id=r.id AND s.lesson_date BETWEEN date('now') AND date('now', ?)
                    AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved','cancelled')
                    AND c.status NOT IN ('archived','cancelled')) AS n_lessons
           FROM k30_pl_rooms r LEFT JOIN k30_pl_buildings b ON b.id = r.building_id
          WHERE r.is_active=1
          ORDER BY COALESCE(b.name, r.location) COLLATE NOCASE, r.name COLLATE NOCASE",
        ['+' . (7 * max(1, $weeks)) . ' days']);
    return array_values(array_filter($rows, fn($r) => (int)$r['n_lessons'] > 0));
}

/** Jedna aktywna sala (do strony planu sali) albo null. */
function tiPlanPublicRoom(int $id): ?array {
    ti_planner_ext_migrate();
    return db_one("SELECT r.*, b.name AS building, b.address AS building_address FROM k30_pl_rooms r
                     LEFT JOIN k30_pl_buildings b ON b.id = r.building_id WHERE r.id=? AND r.is_active=1", [$id]) ?: null;
}

/* ───────────────────────── Terminy i siatka tygodnia ───────────────────────── */

/**
 * Terminy (bez wersji roboczych i rezerwacji) w zakresie dat. $filter: ['courses' => [id…]]
 * (grupa), ['instructor' => uid] (domyślny grupy lub zastępstwo w terminie) albo ['room' => id].
 * Zwraca znormalizowane wpisy gotowe do widoku publicznego.
 */
function tiPlanPublicLessons(array $filter, string $from, string $to): array {
    ti_planner_ext_migrate();
    k30_ti_reschedule_migrate();
    $where = ''; $args = [];
    if (isset($filter['instructor'])) {
        $where = "COALESCE(s.instructor_id, c.instructor_id) = CAST(? AS INTEGER) AND c.status NOT IN ('archived','cancelled')";
        $args[] = (int)$filter['instructor'];
    } elseif (isset($filter['room'])) {
        $where = "s.room_id = ? AND c.status NOT IN ('archived','cancelled')";
        $args[] = (int)$filter['room'];
    } else {
        $ids = array_values(array_filter(array_map('intval', (array)($filter['courses'] ?? []))));
        if (!$ids) return [];
        $where = 's.course_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = $ids;
    }
    $args[] = $from; $args[] = $to;
    $rows = db_all(
        "SELECT s.id, s.course_id, s.room_id, s.room_reservation_status, s.lesson_date, s.time_from, s.time_to, s.status, s.lesson_method, s.date_flag, s.meeting_url,
                s.rescheduled_from_date, s.rescheduled_from_time_from,
                c.name AS course_name, c.default_meeting_url, c.location AS course_location, c.is_online AS course_online,
                st.name AS subject_name, u.name AS instr_name, COALESCE(s.instructor_id, c.instructor_id) AS instr_id,
                r.name AS room_name, r.location AS room_location, r.short_label AS room_short_label
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
           LEFT JOIN users u ON u.id = COALESCE(s.instructor_id, c.instructor_id)
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
          WHERE $where AND s.lesson_date BETWEEN ? AND ?
            AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved')
          ORDER BY s.lesson_date, s.time_from, c.name",
        $args);
    $methods = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'online', 'zdalna_inne' => 'online'];
    $out = [];
    foreach ($rows as $r) {
        $method = $methods[(string)$r['lesson_method']] ?? ((string)$r['lesson_method'] === '' ? ((int)$r['course_online'] === 1 ? 'online' : 'stacjonarnie') : '');
        $room = ti_room_label($r['room_name'] !== null ? ['name' => $r['room_name'], 'location' => $r['room_location'], 'short_label' => $r['room_short_label']] : null, (string)($r['course_location'] ?? ''));
        $tf = substr((string)$r['time_from'], 0, 5);
        $tt = substr((string)$r['time_to'], 0, 5);
        $moved = trim((string)($r['rescheduled_from_date'] ?? '')) !== ''
            ? date('d.m', strtotime((string)$r['rescheduled_from_date'])) . ($r['rescheduled_from_time_from'] ? ' ' . substr((string)$r['rescheduled_from_time_from'], 0, 5) : '')
            : '';
        $out[] = [
            'date'      => (string)$r['lesson_date'],
            'dow'       => (int)date('N', strtotime((string)$r['lesson_date'])),
            'tf'        => $tf,
            'tt'        => $tt,
            'time'      => $tf !== '' ? $tf . '–' . $tt : '',
            'course_id' => (int)$r['course_id'],
            'course'    => (string)$r['course_name'],
            'subject'   => trim((string)$r['subject_name']) !== '' ? (string)$r['subject_name'] : (string)$r['course_name'],
            'instr'     => tiPlanPublicInitials((string)($r['instr_name'] ?? '')),
            'instr_id'  => (int)$r['instr_id'],
            'method'    => $method,
            'room'      => $room !== '—' && $method !== 'online' ? $room : '',
            'url'       => $method === 'online' ? (trim((string)$r['meeting_url']) ?: trim((string)$r['default_meeting_url'])) : '',
            'cancelled' => (string)$r['status'] === 'cancelled',
            'flag'      => (string)($r['date_flag'] ?? ''),
            'moved'     => $moved,   // przeniesione z „dd.mm hh:mm”
            'room_id'   => $method !== 'online' ? (int)$r['room_id'] : 0,
            // rezerwacja sali u operatora przestrzeni (k30_ti_sessions.room_reservation_status)
            'room_ok'   => (string)($r['room_reservation_status'] ?? '') === 'potwierdzone',
        ];
    }
    return $out;
}

/** Poniedziałek tygodnia zawierającego datę (Y-m-d). */
function tiPlanPublicMonday(string $date): string {
    $t = strtotime($date);
    return date('Y-m-d', strtotime('-' . ((int)date('N', $t) - 1) . ' days', $t));
}

/**
 * Siatka jednego tygodnia: dni (Pn–Pt zawsze, Sb/Nd gdy są zajęcia), wiersze = przedziały godzin
 * występujące w tym tygodniu, komórka = lista terminów (kilka grup/prowadzących naraz).
 */
function tiPlanPublicWeekGrid(array $lessons, string $monday): array {
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $week = array_values(array_filter($lessons, fn($l) => $l['date'] >= $monday && $l['date'] <= $sunday));
    $short = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'Sb', 7 => 'Nd'];
    $ids   = [1 => 'pn', 2 => 'wt', 3 => 'sr', 4 => 'cz', 5 => 'pt', 6 => 'sb', 7 => 'nd'];
    $days = [];
    for ($d = 1; $d <= 7; $d++) {
        $n = count(array_filter($week, fn($l) => $l['dow'] === $d && !$l['cancelled']));
        $any = count(array_filter($week, fn($l) => $l['dow'] === $d));
        if ($d >= 6 && !$any) continue;
        $date = date('Y-m-d', strtotime($monday . ' +' . ($d - 1) . ' days'));
        $days[$d] = ['dow' => $d, 'id' => $ids[$d], 'short' => $short[$d], 'full' => TI_DAYS_PL_FULL[$d],
                     'date' => $date, 'count' => $n, 'today' => $date === date('Y-m-d')];
    }
    $slots = []; $cells = [];
    foreach ($week as $l) {
        $k = $l['time'] !== '' ? $l['time'] : '—';
        $slots[$k] = ['key' => $k, 'tf' => $l['tf'], 'tt' => $l['tt']];
        $cells[$k][$l['dow']][] = $l;
    }
    uasort($slots, fn($a, $b) => [$a['tf'], $a['tt']] <=> [$b['tf'], $b['tt']]);
    return ['monday' => $monday, 'sunday' => $sunday, 'days' => $days, 'slots' => array_values($slots), 'cells' => $cells, 'lessons' => $week];
}

/** Najbliższy nieodwołany termin (od teraz) albo null. */
function tiPlanPublicNext(array $lessons): ?array {
    $today = date('Y-m-d'); $now = date('H:i');
    foreach ($lessons as $l) {
        if ($l['cancelled']) continue;
        if ($l['date'] > $today || ($l['date'] === $today && $l['tt'] >= $now)) return $l;
    }
    return null;
}

/** Plik .ics z nieodwołanymi terminami. */
function tiPlanPublicIcs(array $lessons, string $calName, string $uidSeed): string {
    $esc = fn(string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\\;', '\\,', '\\n'], $s);
    $host = parse_url((string)(defined('APP_URL') ? APP_URL : ''), PHP_URL_HOST) ?: 'feer.org.pl';
    $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FEER//Plan zajec TI//PL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $esc($calName), 'X-WR-TIMEZONE:Europe/Warsaw',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Warsaw',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'END:VTIMEZONE'];
    foreach ($lessons as $l) {
        if ($l['cancelled'] || !preg_match('/^(\d{2}):(\d{2})$/', $l['tf'], $a) || !preg_match('/^(\d{2}):(\d{2})$/', $l['tt'], $b)) continue;
        $day = str_replace('-', '', $l['date']);
        $out[] = 'BEGIN:VEVENT';
        $out[] = 'UID:' . sha1($uidSeed . '|' . $l['course_id'] . '|' . $l['date'] . '|' . $l['time']) . '@' . $host;
        $out[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $out[] = "DTSTART;TZID=Europe/Warsaw:{$day}T{$a[1]}{$a[2]}00";
        $out[] = "DTEND;TZID=Europe/Warsaw:{$day}T{$b[1]}{$b[2]}00";
        $out[] = 'SUMMARY:' . $esc($l['subject']);
        $out[] = 'LOCATION:' . $esc($l['method'] === 'online' ? ($l['url'] ?: 'online') : $l['room']);
        if ($l['url'] !== '') $out[] = 'URL:' . $l['url'];
        $out[] = 'DESCRIPTION:' . $esc('Forma: ' . ($l['method'] ?: '—') . ($l['url'] ? '. Link do zajęć: ' . $l['url'] : '') . ($l['instr'] ? '. Prowadzący: ' . $l['instr'] : '') . '. Plan może ulec zmianie.');
        $out[] = 'END:VEVENT';
    }
    $out[] = 'END:VCALENDAR';
    return implode("\r\n", $out) . "\r\n";
}
