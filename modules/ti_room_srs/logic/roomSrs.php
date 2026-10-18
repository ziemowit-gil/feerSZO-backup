<?php
/**
 * modules/ti_room_srs/logic/roomSrs.php — połączenie rezerwacji sal TI (k30_pl_rooms / k30_ti_sessions) z SRS
 * (modules/srs: resources / resource_reservations) i formularz rezerwacji sali operatora (RoomFormDocx).
 *
 * Tabele:
 *   ti_room_srs_rooms  — sala TI → zasób SRS + opcja „Sala” we wzorze operatora (1W040 / 1W014 / 1W038);
 *   ti_room_srs_forms  — wygenerowany formularz: grupa + sala + okres, terminy (session_ids), dane formularza
 *                        BEZ PESEL-u i adresu zamieszkania (wpisywane ręcznie, tylko do pobieranego pliku),
 *                        powiązana rezerwacja SRS.
 *
 * Jeden formularz = jedna grupa w jednej sali w wybranym okresie (wszystkie terminy w jednym dokumencie).
 * Status rezerwacji SRS wraca do lekcji: zgoda/rezerwacja → „potwierdzone”, odmowa/anulowana → „do_rezerwacji”
 * (tiRoomSrsSyncFromSrs, wołane z res_change_status()).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_planner_ext.php';
require_once dirname(__DIR__, 3) . '/includes/ti_room_reports.php';
require_once dirname(__DIR__, 2) . '/srs/logic/srs.php';
require_once dirname(__DIR__) . '/lib/RoomFormDocx.php';

/** Opcje pola „Sala” we wzorze operatora (Centrum Obywatelskie, Reymonta 20). */
const TI_ROOM_SRS_FORM_ROOMS = [
    '1W040' => 'Szkoleniowo – konferencyjna 1W040',
    '1W014' => 'Sala konferencyjna „akwarium” 1W014',
    '1W038' => 'Sala mała 1W038',
];

/** Statusy SRS, które oznaczają salę zarezerwowaną u operatora. */
const TI_ROOM_SRS_CONFIRMED = ['zgoda', 'rezerwacja'];

function tiRoomSrsMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    karty30_migrate();
    ti_planner_ext_migrate();
    resources_migrate();
    db()->exec("CREATE TABLE IF NOT EXISTS ti_room_srs_rooms (
        room_id     INTEGER PRIMARY KEY,
        resource_id INTEGER,
        form_room   TEXT NOT NULL DEFAULT '',
        updated_at  TEXT
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS ti_room_srs_forms (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id          INTEGER NOT NULL,
        room_id            INTEGER NOT NULL,
        date_from          TEXT NOT NULL,
        date_to            TEXT NOT NULL,
        session_ids        TEXT NOT NULL DEFAULT '[]',
        form_data          TEXT NOT NULL DEFAULT '{}',
        srs_reservation_id INTEGER,
        created_by         INTEGER,
        created_at         TEXT,
        updated_at         TEXT
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_ti_room_srs_forms_key ON ti_room_srs_forms(course_id, room_id, date_from, date_to)");
    db()->exec("CREATE INDEX IF NOT EXISTS ix_ti_room_srs_forms_res ON ti_room_srs_forms(srs_reservation_id)");
}

/* ── Sale TI ↔ zasoby SRS ─────────────────────────────────────────────────── */

/** Kod sali we wzorze odgadnięty z nazwy/skrótu/nazwy operatora sali TI („…1W40” → 1W040). */
function tiRoomSrsGuessFormRoom(array $room): string
{
    $hay = implode(' ', [$room['operator_label'] ?? '', $room['short_label'] ?? '', $room['name'] ?? '']);
    if (preg_match('/1W0?(\d{2})\b/i', $hay, $m)) {
        $code = '1W0' . $m[1];
        if (isset(TI_ROOM_SRS_FORM_ROOMS[$code])) return $code;
    }
    return '';
}

/** Sale TI (aktywne) z przypisanym zasobem SRS i opcją wzoru (zapisaną albo odgadniętą). */
function tiRoomSrsRooms(): array
{
    tiRoomSrsMigrate();
    $rows = db_all(
        "SELECT r.*, b.name AS building_name, b.address AS building_address,
                m.resource_id, m.form_room AS saved_form_room, res.name AS resource_name, res.is_active AS resource_active
         FROM k30_pl_rooms r
         LEFT JOIN k30_pl_buildings b ON b.id = r.building_id
         LEFT JOIN ti_room_srs_rooms m ON m.room_id = r.id
         LEFT JOIN resources res ON res.id = m.resource_id
         WHERE r.is_active = 1
         ORDER BY r.name"
    );
    foreach ($rows as &$r) {
        $r['form_room'] = $r['saved_form_room'] !== null ? (string)$r['saved_form_room'] : tiRoomSrsGuessFormRoom($r);
    }
    unset($r);
    return $rows;
}

function tiRoomSrsRoom(int $roomId): ?array
{
    foreach (tiRoomSrsRooms() as $r) if ((int)$r['id'] === $roomId) return $r;
    return null;
}

function tiRoomSrsSaveRoom(int $roomId, ?int $resourceId, string $formRoom): void
{
    tiRoomSrsMigrate();
    $formRoom = isset(TI_ROOM_SRS_FORM_ROOMS[$formRoom]) ? $formRoom : '';
    db_exec(
        "INSERT INTO ti_room_srs_rooms (room_id, resource_id, form_room, updated_at) VALUES (?,?,?,datetime('now'))
         ON CONFLICT(room_id) DO UPDATE SET resource_id=excluded.resource_id, form_room=excluded.form_room, updated_at=excluded.updated_at",
        [$roomId, $resourceId ?: null, $formRoom]
    );
}

/** Zasoby SRS do wyboru (aktywne, sale na górze). */
function tiRoomSrsResources(): array
{
    tiRoomSrsMigrate();
    return db_all(
        "SELECT r.id, r.name, r.location, r.requires_approval, c.name AS cat_name
         FROM resources r LEFT JOIN resource_categories c ON c.id = r.category_id
         WHERE r.is_active = 1
         ORDER BY CASE WHEN c.name = 'Sala' THEN 0 ELSE 1 END, r.sort_order, r.name"
    );
}

/** Tworzy w SRS zasób odpowiadający sali TI (kategoria „Sala”, z zatwierdzaniem, dostępny dla Dydaktyka 3) i go przypisuje. */
function tiRoomSrsCreateResource(int $roomId, int $userId): int
{
    $room = tiRoomSrsRoom($roomId);
    if (!$room) throw new RuntimeException('Nie ma takiej sali.');
    $cat = db_one("SELECT id FROM resource_categories WHERE name = 'Sala' ORDER BY id LIMIT 1");
    // Bez res_save(): ta bierze autora z current_user(), które w sesji panelu dydaktyka jest puste (FK do users).
    $resId = db_insert('resources', [
        'category_id'       => $cat ? (int)$cat['id'] : null,
        'name'              => $room['form_room'] !== '' ? TI_ROOM_SRS_FORM_ROOMS[$room['form_room']] : (string)$room['name'],
        'description'       => 'Sala TI: ' . $room['name'],
        'location'          => trim(($room['building_name'] ?? '') . ', ' . ($room['building_address'] ?? ''), ', ') ?: (string)$room['location'],
        'operator_label'    => (string)($room['operator_label'] ?? ''),
        'capacity'          => (int)$room['capacity'] ?: null,
        'requires_approval' => 1,
        'is_active'         => 1,
        'k30_enabled'       => 1,
        'sort_order'        => 0,
        'created_by'        => $userId ?: null,
        'created_at'        => date('Y-m-d H:i:s'),
        'updated_at'        => date('Y-m-d H:i:s'),
    ]);
    tiRoomSrsSaveRoom($roomId, $resId, (string)$room['form_room']);
    return $resId;
}

/* ── Grupy z salą w okresie ───────────────────────────────────────────────── */

/**
 * Pary grupa + sala w okresie [from, to] z terminami (bez odwołanych), do wygenerowania formularza.
 * @return array<int, array{course_id:int, room_id:int, course_name:string, room_label:string, date_from:string,
 *   date_to:string, count:int, pending:int, session_ids:int[], sessions:array, form:?array}>
 */
function tiRoomSrsCandidates(string $from, string $to): array
{
    tiRoomSrsMigrate();
    $out = [];
    foreach (_ti_room_reservation_rows($from, $to) as $r) {
        $k = $r['course_id'] . '|' . $r['room_id'];
        $out[$k] ??= [
            'course_id' => (int)$r['course_id'], 'room_id' => (int)$r['room_id'],
            'course_name' => (string)$r['course_name'], 'room_label' => (string)$r['room_label'],
            'date_from' => (string)$r['lesson_date'], 'date_to' => (string)$r['lesson_date'],
            'count' => 0, 'pending' => 0, 'session_ids' => [], 'sessions' => [], 'form' => null,
        ];
        $g = &$out[$k];
        $g['date_from'] = min($g['date_from'], (string)$r['lesson_date']);
        $g['date_to']   = max($g['date_to'], (string)$r['lesson_date']);
        $g['count']++;
        if ($r['room_reservation_status'] !== 'potwierdzone') $g['pending']++;
        $g['session_ids'][] = (int)$r['id'];
        $g['sessions'][] = $r;
        unset($g);
    }
    foreach ($out as &$g) $g['form'] = tiRoomSrsFormFind($g['course_id'], $g['room_id'], $g['date_from'], $g['date_to']);
    unset($g);
    uasort($out, fn($a, $b) => [$a['room_label'], $a['course_name']] <=> [$b['room_label'], $b['course_name']]);
    return array_values($out);
}

/** Terminy grupy w sali w okresie (lekcje nieodwołane). */
function tiRoomSrsSessions(int $courseId, int $roomId, string $from, string $to): array
{
    return array_values(array_filter(_ti_room_reservation_rows($from, $to),
        fn($r) => (int)$r['course_id'] === $courseId && (int)$r['room_id'] === $roomId));
}

/**
 * Linie pola „Termin rezerwacji wraz ze wskazaniem godzin”: jedna linia na dzień tygodnia + godziny,
 * z wszystkimi datami (operator dostaje konkretne dni, nie „co tydzień”).
 */
function tiRoomSrsTermLines(array $sessions): array
{
    $slots = [];
    foreach ($sessions as $s) {
        $dow = (int)date('N', strtotime((string)$s['lesson_date']));
        $tf = substr((string)$s['time_from'], 0, 5);
        $tt = substr((string)$s['time_to'], 0, 5);
        $slots[$dow . '|' . $tf . '|' . $tt]['head'] = TI_DAYS_PL_FULL[$dow] . ' ' . $tf . '–' . $tt;
        $slots[$dow . '|' . $tf . '|' . $tt]['dates'][] = (string)$s['lesson_date'];
        $slots[$dow . '|' . $tf . '|' . $tt]['sort'] = [$dow, $tf];
    }
    uasort($slots, fn($a, $b) => $a['sort'] <=> $b['sort']);
    $lines = [];
    foreach ($slots as $sl) {
        $dates = array_unique($sl['dates']);
        sort($dates);
        $lines[] = $sl['head'] . ': ' . implode(', ', array_map(fn($d) => date('d.m.Y', strtotime($d)), $dates))
                 . ' (' . count($dates) . ' ' . tiRoomSrsTermWord(count($dates)) . ')';
    }
    return $lines;
}

/** „termin” w liczbie: 1 termin, 2–4 terminy, 5+ terminów (12–14 terminów). */
function tiRoomSrsTermWord(int $n): string
{
    if ($n === 1) return 'termin';
    return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 'terminy' : 'terminów';
}

/* ── Uczestnicy i małoletni ───────────────────────────────────────────────── */

/** Data urodzenia z PESEL (Y-m-d) albo '' przy niepoprawnym numerze. */
function tiRoomSrsPeselBirth(string $pesel): string
{
    if (!preg_match('/^\d{11}$/', $pesel)) return '';
    $y = (int)substr($pesel, 0, 2); $m = (int)substr($pesel, 2, 2); $d = (int)substr($pesel, 4, 2);
    $century = [0 => 1900, 20 => 2000, 40 => 2100, 60 => 2200, 80 => 1800][intdiv($m, 20) * 20] ?? 1900;
    $m %= 20;
    return checkdate($m, $d, $century + $y) ? sprintf('%04d-%02d-%02d', $century + $y, $m, $d) : '';
}

function tiRoomSrsPeselValid(string $pesel): bool
{
    if (!preg_match('/^\d{11}$/', $pesel)) return false;
    $w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) $sum += $w[$i] * (int)$pesel[$i];
    return (10 - $sum % 10) % 10 === (int)$pesel[10];
}

/**
 * Aktywni uczestnicy grupy: liczba i liczba małoletnich na dzień pierwszego terminu
 * (data urodzenia z karty klienta, a gdy jej brak — z PESEL). Brak daty = nieznany wiek.
 * @return array{count:int, minors:int, unknown:int}
 */
function tiRoomSrsParticipants(int $courseId, string $onDate): array
{
    $rows = db_all(
        "SELECT cl.date_of_birth, cl.pesel FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id = e.client_id
         WHERE e.course_id = ? AND e.status = 'active'", [$courseId]
    );
    $adultFrom = date('Y-m-d', strtotime($onDate . ' -18 years'));
    $minors = 0; $unknown = 0;
    foreach ($rows as $r) {
        $birth = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$r['date_of_birth']) ? substr((string)$r['date_of_birth'], 0, 10)
               : tiRoomSrsPeselBirth((string)$r['pesel']);
        if ($birth === '') { $unknown++; continue; }
        if ($birth > $adultFrom) $minors++;
    }
    return ['count' => count($rows), 'minors' => $minors, 'unknown' => $unknown];
}

/* ── Dane formularza ──────────────────────────────────────────────────────── */

/** Prowadzący odpowiedzialny za salę: najczęstszy prowadzący terminów (lekcja, a gdy brak — grupa). */
function tiRoomSrsInstructor(array $sessions): ?array
{
    $cnt = [];
    foreach ($sessions as $s) {
        $id = (int)($s['instructor_id'] ?: $s['course_instructor_id']);
        if ($id) $cnt[$id] = ($cnt[$id] ?? 0) + 1;
    }
    if (!$cnt) return null;
    arsort($cnt);
    return db_one("SELECT id, name, first_name, last_name, email, phone_number FROM users WHERE id = ?", [array_key_first($cnt)]);
}

/**
 * Domyślne dane formularza dla grupy w sali w okresie — wszystko, co system wie. PESEL i adres zamieszkania
 * zawsze puste (wpisywane ręcznie przy generowaniu). Dla zapisanego formularza nadpisuje zapisanymi wartościami.
 */
function tiRoomSrsDefaults(int $courseId, int $roomId, string $from, string $to): array
{
    tiRoomSrsMigrate();
    $course = db_one("SELECT * FROM k30_ti_courses WHERE id = ?", [$courseId]);
    $room = tiRoomSrsRoom($roomId);
    if (!$course || !$room) throw new RuntimeException('Nie ma takiej grupy albo sali.');
    $sessions = tiRoomSrsSessions($courseId, $roomId, $from, $to);
    $org = ti_org_contact_info();
    $pp = tiRoomSrsParticipants($courseId, $sessions ? (string)$sessions[0]['lesson_date'] : $from);
    $instr = tiRoomSrsInstructor($sessions);

    $first = trim((string)($instr['first_name'] ?? ''));
    $last  = trim((string)($instr['last_name'] ?? ''));
    if ($instr && ($first === '' || $last === '')) {
        $parts = preg_split('/\s+/u', trim((string)$instr['name']));
        $last = $last !== '' ? $last : (string)array_pop($parts);
        $first = $first !== '' ? $first : implode(' ', $parts);
    }

    $name = trim((string)($course['display_name'] ?: $course['name']));
    $desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$course['description'])));
    if (mb_strlen($desc) > 600) $desc = mb_substr($desc, 0, 597) . '…';

    $data = [
        'room'         => (string)$room['form_room'],
        'terms'        => implode("\n", tiRoomSrsTermLines($sessions)),
        'orgName'      => $org['name'],
        'orgAddress'   => $org['address'],
        'orgPhone'     => $org['phone'],
        'orgEmail'     => $org['email'],
        'purpose'      => 'wydarzenie',
        'topic'        => 'Zajęcia grupy „' . $name . '”' . ($desc !== '' ? ' — ' . $desc : ''),
        'participants' => (string)$pp['count'],
        'minors'       => $pp['minors'] > 0 ? 'tak' : 'nie',
        'statement'    => $pp['minors'] > 0 ? 'tak' : 'nie_dotyczy',
        'firstName'    => $first,
        'lastName'     => $last,
        'address'      => '',
        'pesel'        => '',
        'phone'        => (string)($instr['phone_number'] ?? ''),
        'email'        => (string)($instr['email'] ?? ''),
        'equipment'    => '',
    ];
    $meta = [
        'course' => $course, 'room' => $room, 'sessions' => $sessions, 'participants' => $pp, 'instructor' => $instr,
        'form' => tiRoomSrsFormFind($courseId, $roomId, $from, $to),
    ];
    if ($meta['form']) {
        $saved = json_decode((string)$meta['form']['form_data'], true) ?: [];
        // Terminy zawsze świeże (lekcje mogły się zmienić); reszta — jak zapisano.
        unset($saved['terms'], $saved['pesel'], $saved['address']);
        $data = array_merge($data, array_intersect_key($saved, $data));
    }
    return ['data' => $data, 'meta' => $meta];
}

/** Dane z POST-u formularza (przycięte, opcje z dozwolonych wartości). */
function tiRoomSrsDataFromPost(array $p): array
{
    $s = fn($k, $max = 500) => mb_substr(trim((string)($p[$k] ?? '')), 0, $max);
    $opt = fn($k, array $allowed) => in_array($p[$k] ?? '', $allowed, true) ? (string)$p[$k] : '';
    return [
        'room'         => $opt('room', array_keys(TI_ROOM_SRS_FORM_ROOMS)),
        'terms'        => $s('terms', 4000),
        'orgName'      => $s('orgName'),
        'orgAddress'   => $s('orgAddress'),
        'orgPhone'     => $s('orgPhone', 60),
        'orgEmail'     => $s('orgEmail', 200),
        'purpose'      => $opt('purpose', ['coworking', 'wydarzenie']),
        'topic'        => $s('topic', 2000),
        'participants' => $s('participants', 20),
        'minors'       => $opt('minors', ['tak', 'nie']),
        'statement'    => $opt('statement', ['tak', 'nie', 'nie_dotyczy']),
        'firstName'    => $s('firstName', 100),
        'lastName'     => $s('lastName', 100),
        'address'      => $s('address', 300),
        'pesel'        => preg_replace('/\D/', '', $s('pesel', 20)),
        'phone'        => $s('phone', 60),
        'email'        => $s('email', 200),
        'equipment'    => $s('equipment', 1000),
    ];
}

/** Dane w kształcie RoomFormDocx::render() (terminy jako linie). */
function tiRoomSrsDocxData(array $data): array
{
    $data['terms'] = preg_split('/\R/u', (string)$data['terms']);
    return $data;
}

/* ── Zapisane formularze i rezerwacje SRS ─────────────────────────────────── */

function tiRoomSrsFormFind(int $courseId, int $roomId, string $from, string $to): ?array
{
    return db_one("SELECT * FROM ti_room_srs_forms WHERE course_id=? AND room_id=? AND date_from=? AND date_to=?",
        [$courseId, $roomId, $from, $to]);
}

function tiRoomSrsFormGet(int $id): ?array
{
    tiRoomSrsMigrate();
    return db_one("SELECT * FROM ti_room_srs_forms WHERE id = ?", [$id]);
}

function tiRoomSrsFormByReservation(int $reservationId): ?array
{
    tiRoomSrsMigrate();
    return db_one("SELECT * FROM ti_room_srs_forms WHERE srs_reservation_id = ? ORDER BY id DESC LIMIT 1", [$reservationId]);
}

/**
 * Zapisuje formularz (bez PESEL-u i adresu zamieszkania) i — gdy $withSrs i sala ma zasób SRS — zakłada albo
 * aktualizuje rezerwację w SRS. Zwraca id formularza.
 */
function tiRoomSrsSave(int $courseId, int $roomId, string $from, string $to, array $data, int $userId, bool $withSrs): int
{
    tiRoomSrsMigrate();
    $sessions = tiRoomSrsSessions($courseId, $roomId, $from, $to);
    if (!$sessions) throw new RuntimeException('Grupa nie ma terminów w tej sali w wybranym okresie.');
    $ids = array_map(fn($s) => (int)$s['id'], $sessions);
    $stored = $data;
    unset($stored['pesel'], $stored['address']);
    $json = json_encode($stored, JSON_UNESCAPED_UNICODE);

    $form = tiRoomSrsFormFind($courseId, $roomId, $from, $to);
    if ($form) {
        db_exec("UPDATE ti_room_srs_forms SET session_ids=?, form_data=?, updated_at=datetime('now') WHERE id=?",
            [json_encode($ids), $json, (int)$form['id']]);
        $formId = (int)$form['id'];
    } else {
        $formId = db_insert('ti_room_srs_forms', [
            'course_id' => $courseId, 'room_id' => $roomId, 'date_from' => $from, 'date_to' => $to,
            'session_ids' => json_encode($ids), 'form_data' => $json, 'created_by' => $userId ?: null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    if ($withSrs) tiRoomSrsUpsertReservation($formId, $sessions, $userId);
    return $formId;
}

/**
 * Rezerwacja SRS dla formularza: nowa (status wg requires_approval zasobu, wpis w historii, powiadomienia SRS)
 * albo aktualizacja terminów istniejącej, jeśli nie jest odrzucona/anulowana.
 */
function tiRoomSrsUpsertReservation(int $formId, array $sessions, int $userId): ?int
{
    $form = tiRoomSrsFormGet($formId);
    $room = $form ? tiRoomSrsRoom((int)$form['room_id']) : null;
    $resourceId = (int)($room['resource_id'] ?? 0);
    if (!$form || !$resourceId || !(int)($room['resource_active'] ?? 0)) return null;

    $course = db_one("SELECT name, display_name FROM k30_ti_courses WHERE id = ?", [(int)$form['course_id']]);
    $data = json_decode((string)$form['form_data'], true) ?: [];
    $tf = min(array_map(fn($s) => substr((string)$s['time_from'], 0, 5), $sessions));
    $tt = max(array_map(fn($s) => substr((string)$s['time_to'], 0, 5), $sessions));
    $purpose = 'TI — zajęcia grupy „' . trim((string)($course['display_name'] ?: $course['name'])) . '” ('
             . count($sessions) . ' term.).' . "\n" . implode("\n", tiRoomSrsTermLines($sessions))
             . "\nLiczba uczestników: " . ($data['participants'] ?? '?')
             . "\nFormularz rezerwacji sali: Panel dydaktyka › Formularze rezerwacji sal.";

    $existing = (int)$form['srs_reservation_id'] ? db_one("SELECT id, status FROM resource_reservations WHERE id = ?", [(int)$form['srs_reservation_id']]) : null;
    if ($existing && !in_array($existing['status'], ['odmowa', 'anulowana'], true)) {
        db_exec("UPDATE resource_reservations SET resource_id=?, date_from=?, date_to=?, time_from=?, time_to=?, purpose=?, updated_at=datetime('now') WHERE id=?",
            [$resourceId, $form['date_from'], $form['date_to'], $tf, $tt, $purpose, (int)$existing['id']]);
        res_log((int)$existing['id'], $userId, (string)$existing['status'], (string)$existing['status'], 'Zaktualizowano terminy z formularza TI');
        tiRoomSrsSyncFromSrs((int)$existing['id'], (string)$existing['status']);
        return (int)$existing['id'];
    }

    $resource = res_get($resourceId);
    $status = !empty($resource['requires_approval']) ? 'pending_admin' : 'rezerwacja';
    $resId = db_insert('resource_reservations', [
        'resource_id' => $resourceId, 'user_id' => $userId,
        'date_from' => $form['date_from'], 'date_to' => $form['date_to'], 'time_from' => $tf, 'time_to' => $tt,
        'purpose' => $purpose, 'extra_fields' => json_encode(['ti_room_srs_form_id' => $formId]),
        'status' => $status, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    db_exec("UPDATE ti_room_srs_forms SET srs_reservation_id=? WHERE id=?", [$resId, $formId]);
    res_log($resId, $userId, '', $status, 'Wniosek z formularza rezerwacji sali TI');
    try { res_notify_event($resId, $status); } catch (Throwable $e) { error_log('ti_room_srs notify: ' . $e->getMessage()); }
    tiRoomSrsSyncFromSrs($resId, $status);
    return $resId;
}

/**
 * Status rezerwacji SRS → status rezerwacji sali w lekcjach formularza. Wołane z res_change_status().
 * Zgoda/rezerwacja potwierdza, odmowa/anulowanie cofa do „do rezerwacji”, statusy w toku niczego nie zmieniają.
 */
function tiRoomSrsSyncFromSrs(int $reservationId, string $status): void
{
    tiRoomSrsMigrate();
    $form = tiRoomSrsFormByReservation($reservationId);
    if (!$form) return;
    if (in_array($status, TI_ROOM_SRS_CONFIRMED, true)) $to = 'potwierdzone';
    elseif (in_array($status, ['odmowa', 'anulowana'], true)) $to = 'do_rezerwacji';
    else return;
    foreach (json_decode((string)$form['session_ids'], true) ?: [] as $sid) pl_room_reservation_set((int)$sid, $to);
}
