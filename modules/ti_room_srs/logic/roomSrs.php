<?php
/**
 * modules/ti_room_srs/logic/roomSrs.php — połączenie rezerwacji sal TI (k30_pl_rooms / k30_ti_sessions) z SRS
 * (modules/srs: resources / resource_reservations) i wnioski/formularze rezerwacji sal operatorów.
 *
 * Wzory operatorów (TI_ROOM_SRS_TEMPLATES) — oryginały wypełniane bez zmiany układu:
 *   reymonta — „FORMULARZ REZERWACJI SALI”, Centrum Obywatelskie Reymonta 20 (DOCX, RoomFormDocx);
 *   c10      — „WYNAJEM SAL W CENTRUM OBYWATELSKIM CENTRUM C 10 — WNIOSEK” (PDF, RoomFormC10Pdf).
 *
 * Tabele:
 *   ti_room_srs_rooms   — sala TI → wzór operatora + opcja „Sala” we wzorze + zasób SRS;
 *   ti_room_srs_forms   — formularz: grupa + sala + okres (course_id > 0, terminy = lekcje) albo rezerwacja przestrzeni
 *                         bez grupy (course_id = 0, data i godziny w form_data); dane BEZ PESEL-u i adresu zamieszkania
 *                         (wpisywane ręcznie, tylko do pobieranego pliku); powiązana rezerwacja SRS;
 *   ti_room_srs_bookers — uprawnienie „Może rezerwować” (kierownik panelu / administrator SRS ma je zawsze).
 *
 * Dwa wejścia z tą samą logiką: panel dydaktyka (source = 'dyd') i SZO (source = 'szo'); źródło widać na listach
 * formularzy i na karcie rezerwacji SRS (extra_fields.source).
 *
 * Akceptacja: formularz osoby z „Może rezerwować” czeka na kierownika (approval_status = do_akceptacji, e-mail do
 * kierowników); dopiero zatwierdzony można pobrać, a rezerwacja SRS idzie przy zatwierdzeniu (e-mail do autora).
 * Formularze kierownika są zatwierdzone od razu.
 *
 * Status rezerwacji SRS wraca do lekcji: zgoda/rezerwacja → „potwierdzone”, odmowa/anulowana → „do_rezerwacji”
 * (tiRoomSrsSyncFromSrs, wołane z res_change_status()).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_planner_ext.php';
require_once dirname(__DIR__, 3) . '/includes/ti_room_reports.php';
require_once dirname(__DIR__, 2) . '/srs/logic/srs.php';
require_once dirname(__DIR__) . '/lib/RoomFormDocx.php';
require_once dirname(__DIR__) . '/lib/RoomFormC10Pdf.php';

/** Wzory operatorów: etykieta, format pliku, opcje pola „Sala”. */
const TI_ROOM_SRS_TEMPLATES = [
    'reymonta' => [
        'label' => 'Centrum Obywatelskie — ul. Reymonta 20', 'format' => 'docx',
        'rooms' => ['1W040' => 'Szkoleniowo – konferencyjna 1W040', '1W014' => 'Sala konferencyjna „akwarium” 1W014', '1W038' => 'Sala mała 1W038'],
    ],
    'c10' => [
        'label' => 'Centrum Obywatelskie Centrum C10 — os. Centrum C 10', 'format' => 'pdf',
        'rooms' => ['2' => '2 (sala coworkingowa)', '6' => '6 (sala szkoleniowa)'],
    ],
];

/** Rodzaj podmiotu we wniosku C10. */
const TI_ROOM_SRS_ENTITY_TYPES = ['ngo' => 'organizacja pozarządowa', 'grupa' => 'grupa nieformalna', 'mieszkaniec' => 'mieszkanka / mieszkaniec Krakowa'];

/** Skąd złożono formularz/rezerwację. */
const TI_ROOM_SRS_SOURCES = ['dyd' => 'Panel dydaktyka', 'szo' => 'SZO'];

function tiRoomSrsSourceBadge(?string $source): string
{
    $s = $source === 'szo' ? 'szo' : 'dyd';
    return '<span class="badge ' . ($s === 'szo' ? 'text-bg-dark' : 'text-bg-info') . '" title="Złożone z: ' . h(TI_ROOM_SRS_SOURCES[$s]) . '">'
         . ($s === 'szo' ? 'SZO' : 'DYD') . '</span>';
}

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
    $cols = array_column(db_all("PRAGMA table_info(ti_room_srs_rooms)"), 'name');
    if (!in_array('template', $cols, true)) db()->exec("ALTER TABLE ti_room_srs_rooms ADD COLUMN template TEXT NOT NULL DEFAULT ''");
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
    $fcols = array_column(db_all("PRAGMA table_info(ti_room_srs_forms)"), 'name');
    if (!in_array('source', $fcols, true)) db()->exec("ALTER TABLE ti_room_srs_forms ADD COLUMN source TEXT NOT NULL DEFAULT 'dyd'");
    // Akceptacja kierownika przed wydrukiem (formularze sprzed akceptacji — zatwierdzone)
    foreach (['approval_status' => "TEXT NOT NULL DEFAULT 'zatwierdzony'", 'approved_by' => 'INTEGER', 'approved_at' => 'TEXT', 'notified_at' => 'TEXT'] as $c => $def) {
        if (!in_array($c, $fcols, true)) db()->exec("ALTER TABLE ti_room_srs_forms ADD COLUMN $c $def");
    }
    // Klucz grupa+sala+okres tylko dla formularzy grup — rezerwacji przestrzeni bez grupy (course_id=0) może być wiele.
    db()->exec("DROP INDEX IF EXISTS ux_ti_room_srs_forms_key");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_ti_room_srs_forms_course ON ti_room_srs_forms(course_id, room_id, date_from, date_to) WHERE course_id > 0");
    db()->exec("CREATE INDEX IF NOT EXISTS ix_ti_room_srs_forms_res ON ti_room_srs_forms(srs_reservation_id)");
    db()->exec("CREATE TABLE IF NOT EXISTS ti_room_srs_bookers (
        user_id    INTEGER PRIMARY KEY,
        granted_by INTEGER,
        created_at TEXT
    )");
}

/* ── Uprawnienie „Może rezerwować” ────────────────────────────────────────── */

/** Kierownik (dyd_is_staff) zawsze; pozostali — z nadanym uprawnieniem w Zespole. */
function tiRoomSrsCanBook(int $userId, bool $isStaff): bool
{
    if ($isStaff) return true;
    if ($userId <= 0) return false;
    tiRoomSrsMigrate();
    return (bool)db_one("SELECT 1 FROM ti_room_srs_bookers WHERE user_id = ?", [$userId]);
}

/** @return int[] user_id z uprawnieniem. */
function tiRoomSrsBookerIds(): array
{
    tiRoomSrsMigrate();
    return array_map('intval', array_column(db_all("SELECT user_id FROM ti_room_srs_bookers"), 'user_id'));
}

function tiRoomSrsSetBooker(int $userId, bool $on, int $grantedBy): void
{
    tiRoomSrsMigrate();
    if ($on) {
        db_exec("INSERT OR IGNORE INTO ti_room_srs_bookers (user_id, granted_by, created_at) VALUES (?,?,datetime('now'))", [$userId, $grantedBy ?: null]);
    } else {
        db_exec("DELETE FROM ti_room_srs_bookers WHERE user_id = ?", [$userId]);
    }
}

/* ── Sale TI ↔ wzory operatorów ↔ zasoby SRS ──────────────────────────────── */

/** Wzór i opcja sali odgadnięte z nazwy/skrótu/nazwy operatora sali TI. @return array{0:string,1:string} */
function tiRoomSrsGuessFormRoom(array $room): array
{
    $hay = mb_strtolower(implode(' ', [$room['operator_label'] ?? '', $room['short_label'] ?? '', $room['name'] ?? '',
                                       $room['location'] ?? '', $room['building_name'] ?? '', $room['building_address'] ?? '']));
    if (preg_match('/1w0?(\d{2})\b/u', $hay, $m) && isset(TI_ROOM_SRS_TEMPLATES['reymonta']['rooms']['1W0' . $m[1]])) {
        return ['reymonta', '1W0' . $m[1]];
    }
    if (preg_match('/\bc\s?10\b/u', $hay)) {
        if (str_contains($hay, 'cowork')) return ['c10', '2'];
        if (str_contains($hay, 'szkol')) return ['c10', '6'];
        return ['c10', ''];
    }
    if (str_contains($hay, 'reymonta')) return ['reymonta', ''];
    return ['', ''];
}

/** Sale TI (aktywne) z wzorem, opcją wzoru (zapisaną albo odgadniętą) i zasobem SRS. */
function tiRoomSrsRooms(): array
{
    tiRoomSrsMigrate();
    $rows = db_all(
        "SELECT r.*, b.name AS building_name, b.address AS building_address,
                m.resource_id, m.form_room AS saved_form_room, m.template AS saved_template,
                res.name AS resource_name, res.is_active AS resource_active
         FROM k30_pl_rooms r
         LEFT JOIN k30_pl_buildings b ON b.id = r.building_id
         LEFT JOIN ti_room_srs_rooms m ON m.room_id = r.id
         LEFT JOIN resources res ON res.id = m.resource_id
         WHERE r.is_active = 1
         ORDER BY r.name"
    );
    foreach ($rows as &$r) {
        if ($r['saved_template'] !== null && $r['saved_template'] !== '') {
            $r['template'] = (string)$r['saved_template'];
            $r['form_room'] = (string)$r['saved_form_room'];
        } elseif ($r['saved_form_room'] !== null && $r['saved_form_room'] !== '') {
            // zapis sprzed wielu wzorów — opcje 1W0xx to wzór Reymonta 20
            $r['template'] = 'reymonta';
            $r['form_room'] = (string)$r['saved_form_room'];
        } else {
            [$r['template'], $r['form_room']] = tiRoomSrsGuessFormRoom($r);
        }
        if (!isset(TI_ROOM_SRS_TEMPLATES[$r['template']])) { $r['template'] = ''; $r['form_room'] = ''; }
        elseif (!isset(TI_ROOM_SRS_TEMPLATES[$r['template']]['rooms'][$r['form_room']])) $r['form_room'] = '';
    }
    unset($r);
    return $rows;
}

function tiRoomSrsRoom(int $roomId): ?array
{
    foreach (tiRoomSrsRooms() as $r) if ((int)$r['id'] === $roomId) return $r;
    return null;
}

/** Sale z przypisanym wzorem operatora — do rezerwacji przestrzeni. */
function tiRoomSrsBookableRooms(): array
{
    return array_values(array_filter(tiRoomSrsRooms(), fn($r) => $r['template'] !== ''));
}

function tiRoomSrsSaveRoom(int $roomId, ?int $resourceId, string $template, string $formRoom): void
{
    tiRoomSrsMigrate();
    $template = isset(TI_ROOM_SRS_TEMPLATES[$template]) ? $template : '';
    $formRoom = $template !== '' && isset(TI_ROOM_SRS_TEMPLATES[$template]['rooms'][$formRoom]) ? $formRoom : '';
    db_exec(
        "INSERT INTO ti_room_srs_rooms (room_id, resource_id, template, form_room, updated_at) VALUES (?,?,?,?,datetime('now'))
         ON CONFLICT(room_id) DO UPDATE SET resource_id=excluded.resource_id, template=excluded.template,
                                            form_room=excluded.form_room, updated_at=excluded.updated_at",
        // pusty wzór zapisujemy jako '-', żeby „spoza wzoru” nie było nadpisywane podpowiedzią z nazwy
        [$roomId, $resourceId ?: null, $template !== '' ? $template : '-', $formRoom]
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
    $tpl = TI_ROOM_SRS_TEMPLATES[$room['template']] ?? null;
    $name = $tpl && $room['form_room'] !== '' ? $tpl['rooms'][$room['form_room']] . ' — ' . explode(' — ', $tpl['label'])[0] : (string)$room['name'];
    // Bez res_save(): ta bierze autora z current_user(), które w sesji panelu dydaktyka jest puste (FK do users).
    $resId = db_insert('resources', [
        'category_id'       => $cat ? (int)$cat['id'] : null,
        'name'              => $name,
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
    tiRoomSrsSaveRoom($roomId, $resId, (string)$room['template'], (string)$room['form_room']);
    return $resId;
}

/* ── Grupy z salą w okresie ───────────────────────────────────────────────── */

/**
 * Pary grupa + sala w okresie [from, to] z terminami (bez odwołanych). $instructorId > 0 — tylko grupy, które ta osoba
 * prowadzi (lekcja albo grupa), dla prowadzącego z uprawnieniem „Może rezerwować”.
 */
function tiRoomSrsCandidates(string $from, string $to, int $instructorId = 0): array
{
    tiRoomSrsMigrate();
    $out = [];
    foreach (_ti_room_reservation_rows($from, $to) as $r) {
        if ($instructorId && (int)($r['instructor_id'] ?: $r['course_instructor_id']) !== $instructorId
            && !k30_ti_instructor_owns_course($instructorId, (int)$r['course_id'])) continue;
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

/** „termin” w liczbie: 1 termin, 2–4 terminy, 5+ terminów (12–14 terminów). */
function tiRoomSrsTermWord(int $n): string
{
    if ($n === 1) return 'termin';
    return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 'terminy' : 'terminów';
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
    return tiRoomSrsPerson((int)array_key_first($cnt));
}

function tiRoomSrsPerson(int $userId): ?array
{
    return db_one("SELECT id, name, first_name, last_name, email, phone_number FROM users WHERE id = ?", [$userId]);
}

/** Wspólne pola: organizacja z ustawień i osoba odpowiedzialna (PESEL/adres zawsze puste). */
function tiRoomSrsBaseData(?array $person): array
{
    $org = ti_org_contact_info();
    $first = trim((string)($person['first_name'] ?? ''));
    $last  = trim((string)($person['last_name'] ?? ''));
    if ($person && ($first === '' || $last === '')) {
        $parts = preg_split('/\s+/u', trim((string)$person['name']));
        $last = $last !== '' ? $last : (string)array_pop($parts);
        $first = $first !== '' ? $first : implode(' ', $parts);
    }
    $krs = trim((string)org_setting('org_krs'));
    return [
        'room' => '', 'terms' => '',
        'orgName' => $org['name'], 'orgAddress' => $org['address'], 'orgPhone' => $org['phone'], 'orgEmail' => $org['email'],
        'orgKrs' => $krs !== '' ? 'KRS ' . $krs : '', 'entityType' => 'ngo', 'newsletter' => '',
        'purpose' => 'wydarzenie', 'topic' => '', 'participants' => '', 'minors' => 'nie', 'statement' => 'nie_dotyczy',
        'firstName' => $first, 'lastName' => $last, 'address' => '', 'pesel' => '',
        'phone' => (string)($person['phone_number'] ?? ''), 'email' => (string)($person['email'] ?? ''), 'equipment' => '',
    ];
}

/** Nakłada zapisane dane formularza (bez terminów, PESEL-u i adresu). */
function tiRoomSrsMergeSaved(array $data, ?array $form, array $keepFresh = ['terms']): array
{
    if (!$form) return $data;
    $saved = json_decode((string)$form['form_data'], true) ?: [];
    foreach (array_merge($keepFresh, ['pesel', 'address']) as $k) unset($saved[$k]);
    return array_merge($data, array_intersect_key($saved, $data));
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
    $pp = tiRoomSrsParticipants($courseId, $sessions ? (string)$sessions[0]['lesson_date'] : $from);
    $instr = tiRoomSrsInstructor($sessions);

    $name = trim((string)($course['display_name'] ?: $course['name']));
    $desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$course['description'])));
    if (mb_strlen($desc) > 600) $desc = mb_substr($desc, 0, 597) . '…';

    $data = array_merge(tiRoomSrsBaseData($instr), [
        'room'         => (string)$room['form_room'],
        'terms'        => implode("\n", tiRoomSrsTermLines($sessions)),
        'topic'        => 'Zajęcia grupy „' . $name . '”' . ($desc !== '' ? ' — ' . $desc : ''),
        'participants' => (string)$pp['count'],
        'minors'       => $pp['minors'] > 0 ? 'tak' : 'nie',
        'statement'    => $pp['minors'] > 0 ? 'tak' : 'nie_dotyczy',
    ]);
    $form = tiRoomSrsFormFind($courseId, $roomId, $from, $to);
    return [
        'data' => tiRoomSrsMergeSaved($data, $form),
        'meta' => ['course' => $course, 'room' => $room, 'sessions' => $sessions, 'participants' => $pp, 'instructor' => $instr, 'form' => $form],
    ];
}

/**
 * Rezerwacja przestrzeni bez grupy (np. konsultacje, spotkanie): sala, dzień, godziny. Osoba odpowiedzialna = $userId.
 * $formId > 0 — edycja zapisanej rezerwacji (sala, dzień i godziny z zapisu, chyba że podano nowe).
 */
function tiRoomSrsAdhocDefaults(int $roomId, string $date, string $tf, string $tt, int $userId, int $formId = 0): array
{
    tiRoomSrsMigrate();
    $form = $formId ? tiRoomSrsFormGet($formId) : null;
    if ($form && (int)$form['course_id'] !== 0) $form = null;
    $saved = $form ? (json_decode((string)$form['form_data'], true) ?: []) : [];
    if ($form) {
        $roomId = $roomId ?: (int)$form['room_id'];
        $date = $date !== '' ? $date : (string)$form['date_from'];
        $tf = $tf !== '' ? $tf : (string)($saved['timeFrom'] ?? '');
        $tt = $tt !== '' ? $tt : (string)($saved['timeTo'] ?? '');
    }
    $room = tiRoomSrsRoom($roomId);
    $data = array_merge(tiRoomSrsBaseData($form ? tiRoomSrsPerson((int)$form['created_by']) : tiRoomSrsPerson($userId)), [
        'room'  => (string)($room['form_room'] ?? ''),
        'terms' => $date !== '' && $tf !== '' && $tt !== ''
            ? TI_DAYS_PL_FULL[(int)date('N', strtotime($date))] . ', ' . date('d.m.Y', strtotime($date)) . ', ' . $tf . '–' . $tt : '',
        'purpose' => 'wydarzenie',
    ]);
    $data = tiRoomSrsMergeSaved($data, $form, ['terms', 'room']);
    if ($form && $roomId === (int)$form['room_id'] && (string)($saved['room'] ?? '') !== '') $data['room'] = (string)$saved['room'];
    $conflicts = [];
    if ($room && $date !== '' && $tf !== '' && $tt !== '') {
        foreach (pl_room_availability($roomId, $date, $tf, $tt) as $c) {
            $cn = db_one("SELECT name FROM k30_ti_courses WHERE id = ?", [(int)$c['course_id']]);
            $conflicts[] = 'zajęcia TI „' . ($cn['name'] ?? '?') . '” ' . substr((string)$c['time_from'], 0, 5) . '–' . substr((string)$c['time_to'], 0, 5);
        }
    }
    return ['data' => $data, 'meta' => ['room' => $room, 'form' => $form, 'date' => $date, 'timeFrom' => $tf, 'timeTo' => $tt, 'conflicts' => $conflicts]];
}

/** Dane z POST-u formularza (przycięte, opcje z dozwolonych wartości wzoru sali). */
function tiRoomSrsDataFromPost(array $p, string $template): array
{
    $s = fn($k, $max = 500) => mb_substr(trim((string)($p[$k] ?? '')), 0, $max);
    $opt = fn($k, array $allowed) => in_array($p[$k] ?? '', $allowed, true) ? (string)$p[$k] : '';
    return [
        'room'         => $opt('room', array_map('strval', array_keys(TI_ROOM_SRS_TEMPLATES[$template]['rooms'] ?? []))),
        'terms'        => $s('terms', 4000),
        'orgName'      => $s('orgName'),
        'orgAddress'   => $s('orgAddress'),
        'orgPhone'     => $s('orgPhone', 60),
        'orgEmail'     => $s('orgEmail', 200),
        'orgKrs'       => $s('orgKrs', 100),
        'entityType'   => $opt('entityType', array_keys(TI_ROOM_SRS_ENTITY_TYPES)),
        'newsletter'   => !empty($p['newsletter']) ? '1' : '',
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

/**
 * Plik formularza dla wzoru sali. @return array{bin:string, mime:string, ext:string}
 */
function tiRoomSrsRender(string $template, array $data): array
{
    $data['terms'] = preg_split('/\R/u', (string)$data['terms']);
    if ($template === 'c10') {
        return ['bin' => RoomFormC10Pdf::render($data), 'mime' => 'application/pdf', 'ext' => 'pdf'];
    }
    if ($template === 'reymonta') {
        return ['bin' => RoomFormDocx::render($data), 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ext' => 'docx'];
    }
    throw new RuntimeException('Sala nie ma przypisanego wzoru formularza operatora — ustaw go w przypisaniu sal.');
}

/* ── Zapisane formularze i rezerwacje SRS ─────────────────────────────────── */

function tiRoomSrsFormFind(int $courseId, int $roomId, string $from, string $to): ?array
{
    return db_one("SELECT * FROM ti_room_srs_forms WHERE course_id=? AND room_id=? AND date_from=? AND date_to=? AND course_id > 0",
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

/** Rezerwacje przestrzeni bez grupy: wszystkie (kierownik) albo własne, od $from. */
function tiRoomSrsAdhocList(int $userId, bool $all, string $from): array
{
    tiRoomSrsMigrate();
    return db_all(
        "SELECT f.*, r.name AS room_name, r.short_label AS room_short_label, u.name AS user_name, rr.status AS srs_status
         FROM ti_room_srs_forms f
         JOIN k30_pl_rooms r ON r.id = f.room_id
         LEFT JOIN users u ON u.id = f.created_by
         LEFT JOIN resource_reservations rr ON rr.id = f.srs_reservation_id
         WHERE f.course_id = 0 AND f.date_to >= ?" . ($all ? '' : ' AND f.created_by = ?') . "
         ORDER BY f.date_from, f.id",
        $all ? [$from] : [$from, $userId]
    );
}

/**
 * Zapisuje formularz grupy (bez PESEL-u i adresu zamieszkania). $approved — zapis kierownika (zatwierdzony od razu);
 * zapis osoby z uprawnieniem „Może rezerwować” czeka na akceptację kierownika (bez pliku i bez rezerwacji SRS).
 * Rezerwacja w SRS ($withSrs) idzie dopiero dla zatwierdzonego formularza. Zwraca id formularza.
 */
function tiRoomSrsSave(int $courseId, int $roomId, string $from, string $to, array $data, int $userId, bool $withSrs,
                       string $source = 'dyd', bool $approved = true): int
{
    tiRoomSrsMigrate();
    $sessions = tiRoomSrsSessions($courseId, $roomId, $from, $to);
    if (!$sessions) throw new RuntimeException('Grupa nie ma terminów w tej sali w wybranym okresie.');
    $ids = array_map(fn($s) => (int)$s['id'], $sessions);
    $json = tiRoomSrsStoredJson($data + ['_srs' => $withSrs ? 1 : 0]);

    $form = tiRoomSrsFormFind($courseId, $roomId, $from, $to);
    $prev = $form;
    if ($form) {
        db_exec("UPDATE ti_room_srs_forms SET session_ids=?, form_data=?, source=?, updated_at=datetime('now') WHERE id=?",
            [json_encode($ids), $json, $source, (int)$form['id']]);
        $formId = (int)$form['id'];
    } else {
        $formId = db_insert('ti_room_srs_forms', [
            'course_id' => $courseId, 'room_id' => $roomId, 'date_from' => $from, 'date_to' => $to,
            'session_ids' => json_encode($ids), 'form_data' => $json, 'created_by' => $userId ?: null, 'source' => $source,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    tiRoomSrsAfterSave($formId, $userId, $approved, $prev);
    return $formId;
}

/** Zapisuje rezerwację przestrzeni bez grupy (nową albo $formId); akceptacja i SRS jak w tiRoomSrsSave(). */
function tiRoomSrsSaveAdhoc(int $formId, int $roomId, string $date, string $tf, string $tt, array $data, int $userId, bool $withSrs,
                            string $source = 'dyd', bool $approved = true): int
{
    tiRoomSrsMigrate();
    if (!tiRoomSrsRoom($roomId)) throw new RuntimeException('Wybierz salę.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt) || $tf >= $tt) {
        throw new RuntimeException('Podaj dzień i godziny (od wcześniejszej do późniejszej).');
    }
    $json = tiRoomSrsStoredJson($data + ['timeFrom' => $tf, 'timeTo' => $tt, '_srs' => $withSrs ? 1 : 0]);
    $form = $formId ? tiRoomSrsFormGet($formId) : null;
    $prev = $form && (int)$form['course_id'] === 0 ? $form : null;
    if ($form && (int)$form['course_id'] === 0) {
        db_exec("UPDATE ti_room_srs_forms SET room_id=?, date_from=?, date_to=?, form_data=?, source=?, updated_at=datetime('now') WHERE id=?",
            [$roomId, $date, $date, $json, $source, $formId]);
    } else {
        $formId = db_insert('ti_room_srs_forms', [
            'course_id' => 0, 'room_id' => $roomId, 'date_from' => $date, 'date_to' => $date, 'session_ids' => '[]',
            'form_data' => $json, 'created_by' => $userId ?: null, 'source' => $source,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    tiRoomSrsAfterSave($formId, $userId, $approved, $prev);
    return $formId;
}

/**
 * Po zapisie: kierownik — zatwierdza od razu (i składa rezerwację SRS, jeśli zaznaczono); pozostali — formularz wraca
 * do „czeka na akceptację” (każda zmiana danych wymaga ponownego zatwierdzenia) i kierownicy dostają e-mail;
 * zatwierdzony formularz zapisany bez zmian zostaje zatwierdzony (ponowne pobranie).
 */
function tiRoomSrsAfterSave(int $formId, int $userId, bool $approved, ?array $prev): void
{
    if ($approved) {
        tiRoomSrsApprove($formId, $userId, false);
        return;
    }
    $was = tiRoomSrsFormGet($formId);
    // Zatwierdzony formularz pobierany ponownie bez zmian — zostaje zatwierdzony (PESEL/adres nie są zapisywane)
    if ($prev && tiRoomSrsIsApproved($prev) && (string)$prev['form_data'] === (string)$was['form_data']
        && (string)$prev['room_id'] === (string)$was['room_id'] && (string)$prev['date_from'] === (string)$was['date_from']) return;
    db_exec("UPDATE ti_room_srs_forms SET approval_status='do_akceptacji', approved_by=NULL, approved_at=NULL WHERE id=?", [$formId]);
    if (($was['approval_status'] ?? '') !== 'do_akceptacji' || empty($was['notified_at'])) {
        tiRoomSrsNotifyPending($formId);
        db_exec("UPDATE ti_room_srs_forms SET notified_at=datetime('now') WHERE id=?", [$formId]);
    }
}

/** Formularz zatwierdzony przez kierownika — wolno pobrać plik. */
function tiRoomSrsIsApproved(?array $form): bool
{
    return $form !== null && ($form['approval_status'] ?? 'zatwierdzony') === 'zatwierdzony';
}

/**
 * Akceptacja kierownika: status „zatwierdzony”, rezerwacja SRS (gdy autor ją zaznaczył), e-mail do autora
 * ($notify — gdy zatwierdza ktoś inny niż autor).
 */
function tiRoomSrsApprove(int $formId, int $approverId, bool $notify = true): void
{
    $form = tiRoomSrsFormGet($formId);
    if (!$form) throw new RuntimeException('Nie ma takiego formularza.');
    db_exec("UPDATE ti_room_srs_forms SET approval_status='zatwierdzony', approved_by=?, approved_at=datetime('now') WHERE id=?",
        [$approverId ?: null, $formId]);
    $data = json_decode((string)$form['form_data'], true) ?: [];
    if (!empty($data['_srs'])) tiRoomSrsSubmitToSrs($formId, (int)($form['created_by'] ?: $approverId));
    if ($notify && (int)$form['created_by'] && (int)$form['created_by'] !== $approverId) tiRoomSrsNotifyApproved($formId, $approverId);
}

/** Składa/aktualizuje rezerwację SRS z zapisanego formularza (grupa: terminy lekcji, przestrzeń: dzień i godziny). */
function tiRoomSrsSubmitToSrs(int $formId, int $userId): ?int
{
    $form = tiRoomSrsFormGet($formId);
    if (!$form) return null;
    $data = json_decode((string)$form['form_data'], true) ?: [];
    $source = (string)($form['source'] ?? 'dyd');
    if ((int)$form['course_id'] > 0) {
        $sessions = tiRoomSrsSessions((int)$form['course_id'], (int)$form['room_id'], (string)$form['date_from'], (string)$form['date_to']);
        if (!$sessions) return null;
        $course = db_one("SELECT name, display_name FROM k30_ti_courses WHERE id = ?", [(int)$form['course_id']]);
        $purpose = 'TI — zajęcia grupy „' . trim((string)($course['display_name'] ?: $course['name'])) . '” ('
                 . count($sessions) . ' ' . tiRoomSrsTermWord(count($sessions)) . ').' . "\n" . implode("\n", tiRoomSrsTermLines($sessions));
        return tiRoomSrsUpsertReservation($formId, (string)$form['date_from'], (string)$form['date_to'],
            min(array_map(fn($s) => substr((string)$s['time_from'], 0, 5), $sessions)),
            max(array_map(fn($s) => substr((string)$s['time_to'], 0, 5), $sessions)), $purpose, $userId, $source);
    }
    $purpose = 'Rezerwacja przestrzeni (' . TI_ROOM_SRS_SOURCES[$source === 'szo' ? 'szo' : 'dyd'] . '): '
             . (($data['topic'] ?? '') !== '' ? $data['topic'] : 'spotkanie') . "\n" . ($data['terms'] ?? '');
    return tiRoomSrsUpsertReservation($formId, (string)$form['date_from'], (string)$form['date_to'],
        (string)($data['timeFrom'] ?? ''), (string)($data['timeTo'] ?? ''), $purpose, $userId, $source);
}

/** Formularze czekające na akceptację kierownika. */
function tiRoomSrsPendingList(): array
{
    tiRoomSrsMigrate();
    return db_all(
        "SELECT f.*, r.name AS room_name, r.short_label AS room_short_label, u.name AS user_name,
                c.name AS course_name, c.display_name AS course_display
         FROM ti_room_srs_forms f
         JOIN k30_pl_rooms r ON r.id = f.room_id
         LEFT JOIN users u ON u.id = f.created_by
         LEFT JOIN k30_ti_courses c ON c.id = f.course_id
         WHERE f.approval_status = 'do_akceptacji'
         ORDER BY f.updated_at"
    );
}

/* ── Powiadomienia e-mail ─────────────────────────────────────────────────── */

/** Krótki opis formularza do e-maila: grupa albo temat, sala, termin. */
function tiRoomSrsFormSummary(array $form): array
{
    $data = json_decode((string)$form['form_data'], true) ?: [];
    $room = tiRoomSrsRoom((int)$form['room_id']);
    $what = (int)$form['course_id'] > 0
        ? 'grupa „' . (string)(db_one("SELECT COALESCE(NULLIF(display_name,''), name) n FROM k30_ti_courses WHERE id=?", [(int)$form['course_id']])['n'] ?? '?') . '”'
        : 'rezerwacja przestrzeni' . (($data['topic'] ?? '') !== '' ? ' — ' . $data['topic'] : '');
    return [
        'what'  => $what,
        'room'  => (string)($room['short_label'] ?? '') ?: (string)($room['name'] ?? ''),
        'terms' => (string)($data['terms'] ?? ''),
        'who'   => (string)(db_one("SELECT name FROM users WHERE id=?", [(int)$form['created_by']])['name'] ?? ''),
        'src'   => TI_ROOM_SRS_SOURCES[($form['source'] ?? 'dyd') === 'szo' ? 'szo' : 'dyd'],
    ];
}

function tiRoomSrsPanelLink(string $route, array $query = []): string
{
    require_once dirname(__DIR__, 2) . '/ti_panel_urls/lib/tiPanelUrl.php';
    return tiPanelUrl('dydaktyk', 'saleFormularz.php?' . http_build_query(['_route' => $route] + $query));
}

/** E-mail do kierowników i zastępców panelu (bez kierownika — też administratorzy): formularz czeka na akceptację. */
function tiRoomSrsNotifyPending(int $formId): void
{
    $form = tiRoomSrsFormGet($formId);
    if (!$form || !function_exists('mail_queue_add')) return;
    $sum = tiRoomSrsFormSummary($form);
    $link = tiRoomSrsPanelLink('/');
    $rows = db_all(
        "SELECT u.id, u.name, u.email, pr.role FROM k30_ti_panel_roles pr JOIN users u ON u.id = pr.user_id
         WHERE pr.role IN ('kierownik','zastepca') AND u.is_active = 1 AND COALESCE(u.email,'') <> ''"
    );
    // Bez kierownika w rolach panelu — administratorzy (jak w Kontroli Lekcji i Wpisów)
    if (!array_filter($rows, fn($r) => $r['role'] === 'kierownik')) {
        $rows = array_merge($rows, db_all("SELECT id, name, email, 'admin' AS role FROM users WHERE role = 'admin' AND is_active = 1 AND COALESCE(email,'') <> ''"));
    }
    $subject = 'Rezerwacja sali czeka na akceptację — ' . $sum['room'];
    $text = $sum['who'] . ' przygotował(a) formularz rezerwacji sali (' . $sum['src'] . ").\n\n"
          . 'Co: ' . $sum['what'] . "\nSala: " . $sum['room'] . "\nTermin:\n" . $sum['terms'] . "\n\n"
          . "Formularz można wydrukować dopiero po akceptacji kierownika.\nZatwierdź: " . $link . "\n";
    $html = '<p><strong>' . h($sum['who']) . '</strong> przygotował(a) formularz rezerwacji sali (' . h($sum['src']) . ').</p>'
          . '<p>Co: ' . h($sum['what']) . '<br>Sala: ' . h($sum['room']) . '<br>Termin:<br>' . nl2br(h($sum['terms'])) . '</p>'
          . '<p>Formularz można wydrukować dopiero po akceptacji kierownika.</p>'
          . '<p><a href="' . h($link) . '">Otwórz formularze rezerwacji sal i zatwierdź</a></p>';
    $sent = [];
    foreach ($rows as $r) {
        $email = strtolower(trim((string)$r['email']));
        // bez autora, duplikatów i adresów technicznych (konto serwisowe/ratunkowe @local)
        if ((int)$r['id'] === (int)$form['created_by'] || isset($sent[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $sent[$email] = true;
        try { mail_queue_add((string)$r['email'], (string)$r['name'], $subject, $html, $text, 'ti_room_srs_pending', $formId); }
        catch (Throwable $e) { error_log('ti_room_srs mail: ' . $e->getMessage()); }
    }
}

/** E-mail do autora: kierownik zatwierdził formularz — można pobrać i wydrukować. */
function tiRoomSrsNotifyApproved(int $formId, int $approverId): void
{
    $form = tiRoomSrsFormGet($formId);
    $author = $form ? db_one("SELECT name, email FROM users WHERE id=? AND email != ''", [(int)$form['created_by']]) : null;
    if (!$author || !function_exists('mail_queue_add')) return;
    $sum = tiRoomSrsFormSummary($form);
    $approver = (string)(db_one("SELECT name FROM users WHERE id=?", [$approverId])['name'] ?? 'Kierownik');
    $link = (int)$form['course_id'] > 0
        ? tiRoomSrsPanelLink('/formularz', ['course' => (int)$form['course_id'], 'room' => (int)$form['room_id'], 'from' => $form['date_from'], 'to' => $form['date_to']])
        : tiRoomSrsPanelLink('/nowa', ['id' => $formId]);
    $subject = 'Rezerwacja sali zatwierdzona — ' . $sum['room'];
    $text = $approver . ' zatwierdził(a) formularz rezerwacji sali.' . "\n\nCo: " . $sum['what'] . "\nSala: " . $sum['room']
          . "\nTermin:\n" . $sum['terms'] . "\n\nMożesz pobrać i wydrukować formularz (PESEL i adres wpisujesz przy pobieraniu): " . $link . "\n";
    $html = '<p><strong>' . h($approver) . '</strong> zatwierdził(a) formularz rezerwacji sali.</p>'
          . '<p>Co: ' . h($sum['what']) . '<br>Sala: ' . h($sum['room']) . '<br>Termin:<br>' . nl2br(h($sum['terms'])) . '</p>'
          . '<p><a href="' . h($link) . '">Pobierz i wydrukuj formularz</a> (PESEL i adres wpisujesz przy pobieraniu).</p>';
    try { mail_queue_add((string)$author['email'], (string)$author['name'], $subject, $html, $text, 'ti_room_srs_approved', $formId); }
    catch (Throwable $e) { error_log('ti_room_srs mail: ' . $e->getMessage()); }
}

/** form_data bez PESEL-u i adresu zamieszkania. */
function tiRoomSrsStoredJson(array $data): string
{
    unset($data['pesel'], $data['address']);
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE);
}

/**
 * Rezerwacja SRS dla formularza: nowa (status wg requires_approval zasobu, wpis w historii, powiadomienia SRS)
 * albo aktualizacja terminów istniejącej, jeśli nie jest odrzucona/anulowana. null — sala bez aktywnego zasobu SRS.
 */
function tiRoomSrsUpsertReservation(int $formId, string $from, string $to, string $tf, string $tt, string $purpose, int $userId, string $source = 'dyd'): ?int
{
    $form = tiRoomSrsFormGet($formId);
    $room = $form ? tiRoomSrsRoom((int)$form['room_id']) : null;
    $resourceId = (int)($room['resource_id'] ?? 0);
    if (!$form || !$resourceId || !(int)($room['resource_active'] ?? 0)) return null;
    $data = json_decode((string)$form['form_data'], true) ?: [];
    $purpose .= "\nLiczba uczestników: " . (($data['participants'] ?? '') !== '' ? $data['participants'] : '?')
              . "\nZłożone z: " . TI_ROOM_SRS_SOURCES[$source === 'szo' ? 'szo' : 'dyd'] . ' (Formularze rezerwacji sal).';

    $existing = (int)$form['srs_reservation_id'] ? db_one("SELECT id, status FROM resource_reservations WHERE id = ?", [(int)$form['srs_reservation_id']]) : null;
    if ($existing && !in_array($existing['status'], ['odmowa', 'anulowana'], true)) {
        db_exec("UPDATE resource_reservations SET resource_id=?, date_from=?, date_to=?, time_from=?, time_to=?, purpose=?, extra_fields=?, updated_at=datetime('now') WHERE id=?",
            [$resourceId, $from, $to, $tf, $tt, $purpose, json_encode(['ti_room_srs_form_id' => $formId, 'source' => $source]), (int)$existing['id']]);
        res_log((int)$existing['id'], $userId, (string)$existing['status'], (string)$existing['status'], 'Zaktualizowano z formularza rezerwacji sali (TI)');
        tiRoomSrsSyncFromSrs((int)$existing['id'], (string)$existing['status']);
        return (int)$existing['id'];
    }

    $resource = res_get($resourceId);
    $status = !empty($resource['requires_approval']) ? 'pending_admin' : 'rezerwacja';
    $resId = db_insert('resource_reservations', [
        'resource_id' => $resourceId, 'user_id' => $userId,
        'date_from' => $from, 'date_to' => $to, 'time_from' => $tf, 'time_to' => $tt,
        'purpose' => $purpose, 'extra_fields' => json_encode(['ti_room_srs_form_id' => $formId, 'source' => $source]),
        'status' => $status, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    db_exec("UPDATE ti_room_srs_forms SET srs_reservation_id=? WHERE id=?", [$resId, $formId]);
    res_log($resId, $userId, '', $status, 'Wniosek z formularza rezerwacji sali (TI)');
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
