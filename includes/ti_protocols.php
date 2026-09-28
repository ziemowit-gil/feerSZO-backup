<?php
/**
 * includes/ti_protocols.php — Protokoły zajęć kursu za okres (panel prowadzącego).
 *
 * Protokół zamyka jeden kurs za jeden okres nauczania i obejmuje CAŁE rozliczenie
 * zajęć, nie tylko oceny: oceny końcowe uczestników, ewidencję godzin prowadzącego,
 * naliczenie wypłaty oraz dwa podpisy elektroniczne (prowadzący i za organizatora).
 * Zatwierdzenie ocen zamyka wpisy ze śladem (kto, kiedy).
 * Po zatwierdzeniu prowadzący nie może już zmieniać ocen — odblokować może
 * pracownik D3 / administrator, z podaniem powodu (też zapisywanym).
 *
 * ROZDZIAŁ OD E-DZIENNIKA: oceny cząstkowe zostają w k30_ti_grades (kategorie,
 * wagi, średnia ważona). Ocena z protokołu jest oceną końcową i trzyma się we
 * własnej tabeli, żeby NIE wchodziła do średniej ważonej dziennika i żeby
 * zatwierdzenie mogło ją zablokować niezależnie od wpisów bieżących.
 * Skala jest ta sama co w dzienniku (1–6 z +/-), więc obie liczby są
 * porównywalne — protokół pokazuje średnią z dziennika obok oceny końcowej.
 *
 * Osobne od [[project_ti_blackout]] (wyłączenie dziennika blokuje też protokoły)
 * i od k30_ti_tests (wyniki testów).
 */

require_once __DIR__ . '/karty30.php';    // k30_ti_grade_parse_num(), k30_ti_course_grades()

/** Dopuszczalne wpisy poza skalą liczbową (nieklasyfikowany, zwolniony itp.). */
const TI_PROTOCOL_SPECIAL = ['np' => 'nieklasyfikowany', 'nb' => 'nieobecny', 'zw' => 'zwolniony', 'bz' => 'brak zaliczenia'];

if (!defined('TI_PROTOCOL_STATUSES')) {
    define('TI_PROTOCOL_STATUSES', [
        'open'     => ['label' => 'niezatwierdzony', 'badge' => 'secondary'],
        'approved' => ['label' => 'zatwierdzony',    'badge' => 'success'],
    ]);
}

/**
 * Zakres dat protokołu (period_name/date_from/date_to): okres nauczania albo —
 * dla protokołu miesięcznego (year_month, bez okresu) — pierwszy i ostatni
 * dzień tego miesiąca. Bez tego ewidencja godzin, wypłata i średnie z
 * dziennika protokołu miesięcznego obejmowały całe życie kursu.
 */
const TI_PROTOCOL_RANGE_COLS = "COALESCE(per.name, CASE WHEN p.year_month != '' THEN 'miesiąc ' || p.year_month END) AS period_name,
       COALESCE(per.date_from, CASE WHEN p.year_month != '' THEN p.year_month || '-01' END) AS date_from,
       COALESCE(per.date_to,   CASE WHEN p.year_month != '' THEN date(p.year_month || '-01', '+1 month', '-1 day') END) AS date_to";

/** Samonaprawa schematu — wołana z każdego punktu wejścia. */
function ti_protocols_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_protocols (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
            period_id     INTEGER REFERENCES k30_ti_periods(id) ON DELETE SET NULL,
            title         TEXT    NOT NULL DEFAULT '',
            status        TEXT    NOT NULL DEFAULT 'open',
            note          TEXT    NOT NULL DEFAULT '',
            approved_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            approved_name TEXT    NOT NULL DEFAULT '',
            approved_at   TEXT,
            unlocked_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            unlocked_name TEXT    NOT NULL DEFAULT '',
            unlocked_at   TEXT,
            unlock_reason TEXT    NOT NULL DEFAULT '',
            created_by    INTEGER,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_protocol_entries (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            protocol_id INTEGER NOT NULL REFERENCES k30_ti_protocols(id) ON DELETE CASCADE,
            client_id   INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
            value_text  TEXT    NOT NULL DEFAULT '',
            value_num   REAL,
            note        TEXT    NOT NULL DEFAULT '',
            updated_by  INTEGER,
            updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_prot_entry
                    ON k30_ti_protocol_entries(protocol_id, client_id)");
    } catch (\Throwable $e) {}

    // Elektroniczne podpisy: prowadzącego (ewidencja godzin i wypłata)
    // oraz za organizatora (kontrasygnata kierownika / pracownika D3)
    foreach ([
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_by   INTEGER",
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_name TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_at   TEXT",
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_ip   TEXT NOT NULL DEFAULT ''",
        // Ewidencję godzin potwierdza zwykle sam prowadzący — gdy w jego imieniu
        // zrobi to kierownik/zastępca (np. prowadzący zapomniał/nie ma dostępu),
        // znacznik odróżnia to od jego własnego podpisu na wydruku i w panelu.
        // Nie wpływa na naliczenie wypłaty — ta liczy się zawsze wg instructor_id
        // kursu/lekcji, niezależnie kto kliknął potwierdzenie.
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_on_behalf INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_by     INTEGER",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_name   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_at     TEXT",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_ip     TEXT NOT NULL DEFAULT ''",
        // Tryb miesięczny (obok istniejącego trybu "okres nauczania" — period_id):
        // protokół bez period_id, kluczowany (course_id, year_month) "RRRR-MM".
        // Stare protokoły per-okres zostają nietknięte; nowe zamykanie jest per-miesiąc.
        "ALTER TABLE k30_ti_protocols ADD COLUMN year_month TEXT NOT NULL DEFAULT ''",
        // Stała kopia danych z chwili zatwierdzenia (ewidencja godzin, wypłata,
        // uczestnicy, średnie z dziennika) — zatwierdzony protokół pokazuje ją
        // zamiast liczenia na żywo, więc późniejsze zmiany lekcji go nie ruszają.
        "ALTER TABLE k30_ti_protocols ADD COLUMN snapshot_json TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_protocols ADD COLUMN snapshot_at   TEXT",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
    try {
        db()->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_prot_course_month
             ON k30_ti_protocols(course_id, year_month) WHERE year_month != ''"
        );
    } catch (\Throwable $e) {}
    // Indeks "jeden protokół na kurs+okres" pierwotnie obejmował też protokoły
    // miesięczne (period_id NULL → 0), więc drugi miesiąc tego samego kursu
    // padał na UNIQUE. Zawężamy go do protokołów per-okres (year_month='').
    try {
        $idx = (string)(db_one("SELECT sql FROM sqlite_master WHERE type='index' AND name='idx_ti_prot_course_period'")['sql'] ?? '');
        if ($idx !== '' && stripos($idx, 'year_month') === false) {
            db()->exec("DROP INDEX idx_ti_prot_course_period");
            $idx = '';
        }
        if ($idx === '') {
            db()->exec(
                "CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_prot_course_period
                 ON k30_ti_protocols(course_id, COALESCE(period_id, 0)) WHERE year_month = ''"
            );
        }
    } catch (\Throwable $e) {}
}

/**
 * Protokół miesięczny kursu — pobiera istniejący albo zakłada nowy (status
 * 'open'). $year_month w formacie "RRRR-MM". Osobny tor od protokołów
 * per-okres (period_id) — patrz nagłówek pliku.
 */
function ti_protocol_get_or_create_for_month(int $course_id, string $year_month): array {
    ti_protocols_migrate();
    $row = db_one("SELECT * FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$course_id, $year_month]);
    if ($row) return $row;
    ti_protocol_require_course_open($course_id);
    db()->prepare(
        "INSERT INTO k30_ti_protocols (course_id, year_month, title, status) VALUES (?,?,?,'open')"
    )->execute([$course_id, $year_month, 'Protokół ' . $year_month]);
    return db_one("SELECT * FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$course_id, $year_month]);
}

/**
 * Miesiące (kurs + "RRRR-MM") które prowadzący powinien zamknąć: każdy
 * miesiąc, w którym kurs miał choć jedną ODBYTĄ lekcję (same zaplanowane /
 * rezerwacje nie wymagają protokołu — najpierw trzeba uzupełnić obecność), a protokół
 * miesięczny nie jest jeszcze zatwierdzony. Miesiąc bieżący liczy się jako
 * "w toku" (można zamknąć wcześniej, ale nie jest jeszcze zaległy);
 * wcześniejsze niezamknięte miesiące są "zaległe".
 */
function ti_protocol_pending_months_for_instructor(int $instructor_uid): array {
    ti_protocols_migrate();
    $courses = k30_ti_instructor_courses($instructor_uid, false);
    if (!$courses) return [];
    $cur_ym = date('Y-m');
    $out = [];
    foreach ($courses as $c) {
        $cid = (int)$c['id'];
        if (ti_course_closed($cid)) continue;   // zamknięta grupa — nic do zamykania
        $months = db_all(
            "SELECT DISTINCT strftime('%Y-%m', lesson_date) AS ym
               FROM k30_ti_sessions
              WHERE course_id=? AND status IN ('held','individual_change','remote_material')
                AND lesson_date <= date('now','localtime')
              ORDER BY ym",
            [$cid]
        );
        foreach ($months as $m) {
            $ym = (string)$m['ym'];
            if ($ym === '' || $ym > $cur_ym) continue;
            $prot = db_one("SELECT id, status FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$cid, $ym]);
            if ($prot && (string)$prot['status'] === 'approved') continue;
            $out[] = [
                'course_id'   => $cid,
                'course_name' => (string)$c['name'],
                'year_month'  => $ym,
                'protocol_id' => $prot['id'] ?? null,
                'is_current'  => $ym === $cur_ym,
                'can_approve' => ti_protocol_can_approve($instructor_uid, $cid),
                'is_overdue'  => $ym < $cur_ym,
            ];
        }
    }
    usort($out, fn($a, $b) => $a['year_month'] <=> $b['year_month']);
    return $out;
}

/**
 * Czy użytkownik może ZATWIERDZIĆ protokół kursu: tylko główny prowadzący
 * (k30_ti_courses.instructor_id) albo kierownik/pracownik D3 ($is_staff).
 * Współprowadzący może protokół oglądać i wypełniać, ale nie zatwierdza.
 */
function ti_protocol_can_approve(int $uid, int $course_id, bool $is_staff = false): bool {
    if ($is_staff) return true;
    if ($uid <= 0 || $course_id <= 0) return false;
    return (int)(db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id])['instructor_id'] ?? 0) === $uid;
}

const TI_PROTOCOL_APPROVE_DENIED = 'Protokół zatwierdza główny prowadzący kursu albo kierownik — współprowadzący nie może go zatwierdzić.';

/** Zatwierdzone protokoły MIESIĘCZNE własnych kursów prowadzącego (najnowsze najpierw). */
function ti_protocol_closed_months_for_instructor(int $instructor_uid): array {
    ti_protocols_migrate();
    $ids = array_map(fn($c) => (int)$c['id'], k30_ti_instructor_courses($instructor_uid, false));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return db_all(
        "SELECT p.id AS protocol_id, p.course_id, c.name AS course_name, p.year_month,
                p.approved_name, p.approved_at
           FROM k30_ti_protocols p JOIN k30_ti_courses c ON c.id = p.course_id
          WHERE p.course_id IN ($ph) AND p.status = 'approved' AND p.year_month != ''
          ORDER BY p.year_month DESC, c.name",
        $ids
    );
}

/**
 * Lista miesięcy per kurs prowadzącego (ostatnie $months_back miesięcy do
 * bieżącego włącznie, nie wcześniej niż pierwsza lekcja kursu) ze stanem
 * protokołu miesięcznego:
 *   approved — zatwierdzony; overdue — miniony z odbytymi zajęciami, niezatwierdzony;
 *   current  — bieżący (można zamknąć wcześniej, np. 25., gdy nie ma już zajęć);
 *   empty    — miniony bez odbytych zajęć (protokół niewymagany).
 * planned_left — zaplanowane lekcje w miesiącu od dziś (ostrzeżenie przy
 * wcześniejszym zamknięciu bieżącego miesiąca).
 */
function ti_protocol_months_for_instructor(int $instructor_uid, int $months_back = 12): array {
    ti_protocols_migrate();
    $courses = k30_ti_instructor_courses($instructor_uid, false);
    $cur_ym  = date('Y-m');
    $min_ym  = date('Y-m', strtotime(date('Y-m-01') . ' -' . max(0, $months_back - 1) . ' month'));
    $today   = date('Y-m-d');
    $out = [];
    foreach ($courses as $c) {
        $cid   = (int)$c['id'];
        $first = (string)(db_one("SELECT MIN(strftime('%Y-%m', lesson_date)) AS m FROM k30_ti_sessions
                                   WHERE course_id=? AND status NOT IN ('cancelled','draft')", [$cid])['m'] ?? '');
        if ($first === '' || $first > $cur_ym) continue;
        $stats = [];
        foreach (db_all(
            "SELECT strftime('%Y-%m', lesson_date) AS ym,
                    SUM(CASE WHEN status IN ('held','individual_change','remote_material') THEN 1 ELSE 0 END) AS held,
                    SUM(CASE WHEN status IN ('planned','reserved') AND lesson_date >= ? THEN 1 ELSE 0 END) AS planned_left,
                    SUM(CASE WHEN status IN ('planned','reserved') AND lesson_date < ? THEN 1 ELSE 0 END) AS planned_past
               FROM k30_ti_sessions WHERE course_id=? AND status NOT IN ('cancelled','draft')
              GROUP BY ym", [$today, $today, $cid]) as $r) {
            $stats[(string)$r['ym']] = $r;
        }
        $prots = [];
        foreach (db_all("SELECT id, year_month, status, approved_name, approved_at FROM k30_ti_protocols
                          WHERE course_id=? AND year_month != ''", [$cid]) as $p) {
            $prots[(string)$p['year_month']] = $p;
        }
        $can = ti_protocol_can_approve($instructor_uid, $cid);
        for ($ym = max($first, $min_ym); $ym <= $cur_ym; $ym = date('Y-m', strtotime($ym . '-01 +1 month'))) {
            $st   = $stats[$ym] ?? ['held' => 0, 'planned_left' => 0, 'planned_past' => 0];
            $p    = $prots[$ym] ?? null;
            $held = (int)$st['held'];
            $state = ($p && $p['status'] === 'approved') ? 'approved'
                   : ($ym === $cur_ym ? 'current' : ($held > 0 ? 'overdue' : 'empty'));
            $out[] = [
                'course_id'     => $cid,
                'course_name'   => (string)$c['name'],
                'year_month'    => $ym,
                'state'         => $state,
                'protocol_id'   => $p ? (int)$p['id'] : null,
                'lessons_held'  => $held,
                'planned_left'  => (int)$st['planned_left'],
                'planned_past'  => (int)$st['planned_past'],
                'approved_name' => (string)($p['approved_name'] ?? ''),
                'approved_at'   => $p['approved_at'] ?? null,
                'can_approve'   => $can,
            ];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['year_month'], $a['year_month']) ?: strcasecmp($a['course_name'], $b['course_name']));
    return $out;
}

/** Podsumowanie miesiąca dla kreatora: liczba lekcji odbytych i średnia frekwencja (%). */
function ti_protocol_month_summary(int $course_id, string $year_month): array {
    $sessions = db_all(
        "SELECT id, status FROM k30_ti_sessions
          WHERE course_id=? AND strftime('%Y-%m', lesson_date)=? AND status NOT IN ('cancelled','draft')",
        [$course_id, $year_month]
    );
    $held = array_values(array_filter($sessions, fn($s) => in_array($s['status'], K30_TI_HELD_STATUSES, true)));
    // Frekwencja tylko z zajęć ze sprawdzaną obecnością — materiał zdalny oznacza
    // wszystkich jako obecnych i sztucznie by ją zawyżał.
    $session_ids = array_column(array_filter($held, fn($s) => in_array($s['status'], K30_TI_ATTENDANCE_STATUSES, true)), 'id');
    $present = 0; $total = 0;
    if ($session_ids) {
        $ph  = implode(',', array_fill(0, count($session_ids), '?'));
        $row = db_one(
            "SELECT SUM(CASE WHEN attended=1 THEN 1 ELSE 0 END) AS present, COUNT(*) AS total
               FROM k30_ti_attendance WHERE session_id IN ($ph) AND COALESCE(cancelled,0)=0",
            $session_ids
        );
        $present = (int)($row['present'] ?? 0);
        $total   = (int)($row['total'] ?? 0);
    }
    $planned_left = (int)(db_one(
        "SELECT COUNT(*) AS n FROM k30_ti_sessions
          WHERE course_id=? AND strftime('%Y-%m', lesson_date)=? AND status IN ('planned','reserved') AND lesson_date >= ?",
        [$course_id, $year_month, date('Y-m-d')]
    )['n'] ?? 0);
    return [
        'planned_left'  => $planned_left,
        'lessons_total' => count($sessions),
        'lessons_held'  => count($held),
        'attendance_pct'=> $total > 0 ? round($present * 100 / $total) : null,
    ];
}

/** Etykieta stanu protokołu. */
function ti_protocol_status_label(string $status): string {
    return TI_PROTOCOL_STATUSES[$status]['label'] ?? $status;
}

/**
 * Bramka: protokoły za okres muszą być otwarte przez administrację.
 * Protokoły bez wskazanego okresu (z czasów, gdy było to możliwe) przepuszczamy,
 * żeby dały się domknąć.
 *
 * @throws RuntimeException gdy administracja zamknęła protokoły za ten okres.
 */
function ti_protocol_require_period_open(array $prot): void {
    $pid = (int)($prot['period_id'] ?? 0);
    if (!$pid) return;
    require_once __DIR__ . '/ti_periods.php';
    if (!ti_period_protocols_open($pid)) {
        throw new \RuntimeException(ti_period_protocols_closed_msg(ti_period_get($pid)));
    }
}

/** Zamknięta grupa („Zamknij i archiwizuj") blokuje każdy zapis protokołu. */
function ti_protocol_require_course_open(int $course_id): void {
    if ($c = ti_course_closed($course_id)) throw new \RuntimeException(ti_course_closed_msg($c));
}

/** Czy protokół jest zamknięty do edycji. */
function ti_protocol_is_locked(array $protocol): bool {
    return (string)($protocol['status'] ?? 'open') === 'approved';
}

/**
 * Sprawdza wpis oceny. Zwraca ['ok'=>bool, 'text'=>string, 'num'=>?float, 'msg'=>string].
 * Puste = wyczyszczenie wpisu (ok, text='').
 */
function ti_protocol_parse_value(string $raw): array {
    $t = trim($raw);
    if ($t === '') return ['ok' => true, 'text' => '', 'num' => null, 'msg' => ''];

    $low = mb_strtolower($t, 'UTF-8');
    if (isset(TI_PROTOCOL_SPECIAL[$low])) {
        return ['ok' => true, 'text' => $low, 'num' => null, 'msg' => ''];
    }
    $t = str_replace(' ', '', $t);
    if (preg_match('/^[1-6][+\-]?$/', $t)) {
        return ['ok' => true, 'text' => $t, 'num' => k30_ti_grade_parse_num($t), 'msg' => ''];
    }
    return [
        'ok' => false, 'text' => '', 'num' => null,
        'msg' => 'Niedozwolony wpis „' . $raw . '”. Dozwolone: 1–6 (można z + lub -) albo '
               . implode(', ', array_keys(TI_PROTOCOL_SPECIAL)) . '.',
    ];
}

/** Protokoły kursu (najnowsze pierwsze) z nazwą okresu. */
function ti_protocols_for_course(int $course_id): array {
    ti_protocols_migrate();
    return db_all(
        "SELECT p.*, " . TI_PROTOCOL_RANGE_COLS . "
           FROM k30_ti_protocols p
           LEFT JOIN k30_ti_periods per ON per.id = p.period_id
          WHERE p.course_id = ?
          ORDER BY COALESCE(per.date_from, NULLIF(p.year_month,'') || '-01', p.created_at) DESC, p.id DESC",
        [$course_id]
    );
}

/**
 * Zaległe protokoły — niezatwierdzone (status='open'), których okres nauczania
 * już się skończył (per.date_to < dziś). Widok kierownika/administratora do
 * zbiorczego zamykania protokołów, na które prowadzący nie zdążył.
 */
function ti_protocols_overdue(): array {
    ti_protocols_migrate();
    ti_course_close_migrate();
    return db_all(
        "SELECT p.*, " . TI_PROTOCOL_RANGE_COLS . ",
                c.name AS course_name, c.instructor_id,
                u.name AS instructor_name,
                CAST(julianday('now') - julianday(per.date_to) AS INTEGER) AS days_overdue
           FROM k30_ti_protocols p
           JOIN k30_ti_periods per ON per.id = p.period_id
           JOIN k30_ti_courses c   ON c.id   = p.course_id
           LEFT JOIN users u       ON u.id   = c.instructor_id
          WHERE p.status = 'open' AND per.date_to < date('now')
            AND (c.closed_at IS NULL OR c.closed_at = '')
          ORDER BY per.date_to ASC, c.name ASC"
    );
}

/**
 * Zaległe protokoły MIESIĘCZNE (widok kierownika): kurs × zakończony miesiąc
 * z odbytą lekcją, bez zatwierdzonego protokołu miesięcznego. protocol_id jest
 * null, gdy prowadzący nawet nie otworzył protokołu (zakładany przy zatwierdzeniu).
 *
 * Liczymy od najwcześniejszego miesiąca, dla którego w systemie w ogóle istnieje
 * protokół miesięczny (start trybu miesięcznego) — wcześniejsza historia była
 * rozliczana protokołami per okres. Miesiąc objęty w całości zatwierdzonym
 * protokołem za okres też nie jest zaległy.
 */
function ti_protocols_overdue_months(): array {
    ti_protocols_migrate();
    ti_course_close_migrate();
    $start = (string)(db_one("SELECT MIN(year_month) AS m FROM k30_ti_protocols WHERE year_month != ''")['m'] ?? '');
    if ($start === '') return [];
    return db_all(
        "SELECT x.course_id, x.course_name, x.instructor_name, x.year_month, p.id AS protocol_id,
                x.year_month || '-01' AS date_from,
                date(x.year_month || '-01', '+1 month', '-1 day') AS date_to,
                CAST(julianday('now','localtime') - julianday(date(x.year_month || '-01', '+1 month', '-1 day')) AS INTEGER) AS days_overdue
           FROM (SELECT s.course_id, c.name AS course_name, COALESCE(u.name,'') AS instructor_name,
                        strftime('%Y-%m', s.lesson_date) AS year_month
                   FROM k30_ti_sessions s
                   JOIN k30_ti_courses c ON c.id = s.course_id
                   LEFT JOIN users u     ON u.id = c.instructor_id
                  WHERE s.status IN ('held','individual_change','remote_material')
                    AND (c.closed_at IS NULL OR c.closed_at = '')
                  GROUP BY s.course_id, strftime('%Y-%m', s.lesson_date)) x
           LEFT JOIN k30_ti_protocols p ON p.course_id = x.course_id AND p.year_month = x.year_month
          WHERE x.year_month >= ? AND x.year_month < strftime('%Y-%m', 'now', 'localtime')
            AND (p.id IS NULL OR p.status = 'open')
            AND NOT EXISTS (
                SELECT 1 FROM k30_ti_protocols pp JOIN k30_ti_periods per ON per.id = pp.period_id
                 WHERE pp.course_id = x.course_id AND pp.status = 'approved'
                   AND per.date_from <= x.year_month || '-01'
                   AND per.date_to   >= date(x.year_month || '-01', '+1 month', '-1 day'))
          ORDER BY x.year_month, x.course_name",
        [$start]
    );
}

function ti_protocol_get(int $id): ?array {
    ti_protocols_migrate();
    if (!$id) return null;
    return db_one(
        "SELECT p.*, " . TI_PROTOCOL_RANGE_COLS . ", c.name AS course_name
           FROM k30_ti_protocols p
           LEFT JOIN k30_ti_periods per ON per.id = p.period_id
           LEFT JOIN k30_ti_courses c   ON c.id   = p.course_id
          WHERE p.id = ?",
        [$id]
    );
}

/**
 * Tworzy protokół dla kursu i okresu albo zwraca istniejący (jeden na parę).
 * @return int id protokołu
 */
function ti_protocol_ensure(int $course_id, int $period_id, ?int $by = null, string $title = ''): int {
    ti_protocols_migrate();
    require_once __DIR__ . '/ti_periods.php';
    if (!$course_id) throw new \RuntimeException('Brak kursu.');
    ti_protocol_require_course_open($course_id);

    // Protokół zajęć dotyczy zawsze okresu, a okres do rozliczenia otwiera
    // administracja — bez tego prowadzący nie zakłada protokołu.
    if (!$period_id) {
        throw new \RuntimeException('Wskaż okres nauczania — protokół zajęć zawsze dotyczy okresu.');
    }
    if (!ti_period_protocols_open($period_id)) {
        throw new \RuntimeException(ti_period_protocols_closed_msg(ti_period_get($period_id)));
    }

    // CAST konieczny: PDO wiąże parametry jako TEKST, a COALESCE(...) jest
    // wyrażeniem bez affinity kolumny — bez rzutowania '1' != 1 i SQLite
    // wpuściłby duplikat wprost na unikalny indeks.
    $existing = db_one(
        "SELECT id FROM k30_ti_protocols WHERE course_id=? AND COALESCE(period_id,0)=CAST(? AS INTEGER) AND year_month=''",
        [$course_id, $period_id]
    );
    if ($existing) return (int)$existing['id'];

    if ($title === '') {
        $per   = $period_id ? db_one("SELECT name FROM k30_ti_periods WHERE id=?", [$period_id]) : null;
        $title = $per
            ? 'Protokół zajęć za okres: ' . (string)$per['name']
            : 'Protokół zajęć bez wskazanego okresu';
    }
    try {
        return db_insert('k30_ti_protocols', [
            'course_id'  => $course_id,
            'period_id'  => $period_id ?: null,
            'title'      => $title,
            'status'     => 'open',
            'created_by' => $by,
        ]);
    } catch (\PDOException $e) {
        // Podwójny klik / dwie karty na tym samym kursie+okresie: SELECT wyżej
        // nie jest atomowy względem tego INSERT-a, więc przy wyścigu druga
        // próba trafia na unique index — dogrywamy SELECT zamiast wywalać 500.
        if (str_contains($e->getMessage(), 'idx_ti_prot_course_period')) {
            $existing = db_one(
                "SELECT id FROM k30_ti_protocols WHERE course_id=? AND COALESCE(period_id,0)=CAST(? AS INTEGER) AND year_month=''",
                [$course_id, $period_id]
            );
            if ($existing) return (int)$existing['id'];
        }
        throw $e;
    }
}

/** Uczestnicy kursu do protokołu (aktywni zapisani, alfabetycznie). */
function ti_protocol_participants(int $course_id): array {
    ti_protocols_migrate();
    return db_all(
        "SELECT e.client_id, cl.name, cl.email, cl.phone
           FROM k30_ti_enrollments e
           JOIN k30_clients cl ON cl.id = e.client_id
          WHERE e.course_id = ? AND e.status = 'active'
          ORDER BY cl.name COLLATE NOCASE",
        [$course_id]
    );
}

/** Wpisy protokołu jako mapa client_id → wiersz. */
function ti_protocol_entries(int $protocol_id): array {
    ti_protocols_migrate();
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_protocol_entries WHERE protocol_id=?", [$protocol_id]) as $r) {
        $out[(int)$r['client_id']] = $r;
    }
    return $out;
}

/**
 * Zbiorczy zapis ocen protokołu.
 *
 * @param array $values  client_id => ocena (tekst; puste = wyczyść wpis)
 * @param array $notes   client_id => uwaga
 * @return array{saved:int, cleared:int, errors:string[]}
 * @throws RuntimeException gdy protokół jest zatwierdzony (zamknięty).
 */
function ti_protocol_save_entries(int $protocol_id, array $values, array $notes = [], ?int $by = null): array {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot) throw new \RuntimeException('Protokół nie istnieje.');
    ti_protocol_require_course_open((int)$prot['course_id']);
    if (ti_protocol_is_locked($prot)) {
        throw new \RuntimeException('Protokół jest zatwierdzony — ocen nie można już zmieniać.');
    }
    ti_protocol_require_period_open($prot);

    $allowed = array_map(fn($p) => (int)$p['client_id'], ti_protocol_participants((int)$prot['course_id']));
    $existing = ti_protocol_entries($protocol_id);
    $saved = 0; $cleared = 0; $errors = [];

    foreach ($values as $cid => $raw) {
        $cid = (int)$cid;
        if (!in_array($cid, $allowed, true)) continue;   // nie uczestnik tego kursu

        $p = ti_protocol_parse_value((string)$raw);
        if (!$p['ok']) { $errors[] = $p['msg']; continue; }

        $note = trim((string)($notes[$cid] ?? ''));

        if ($p['text'] === '' && $note === '') {
            if (isset($existing[$cid])) {
                db_exec("DELETE FROM k30_ti_protocol_entries WHERE id=?", [(int)$existing[$cid]['id']]);
                $cleared++;
            }
            continue;
        }
        if (isset($existing[$cid])) {
            db()->prepare(
                "UPDATE k30_ti_protocol_entries
                    SET value_text=?, value_num=?, note=?, updated_by=?, updated_at=datetime('now')
                  WHERE id=?"
            )->execute([$p['text'], $p['num'], $note, $by, (int)$existing[$cid]['id']]);
        } else {
            db_insert('k30_ti_protocol_entries', [
                'protocol_id' => $protocol_id,
                'client_id'   => $cid,
                'value_text'  => $p['text'],
                'value_num'   => $p['num'],
                'note'        => $note,
                'updated_by'  => $by,
            ]);
        }
        $saved++;
    }
    db_exec("UPDATE k30_ti_protocols SET updated_at=datetime('now') WHERE id=?", [$protocol_id]);
    return ['saved' => $saved, 'cleared' => $cleared, 'errors' => $errors];
}

/**
 * Zatwierdza protokół (ślad: kto, kiedy) i zamyka go do edycji.
 *
 * Protokół BEZ OCEN też można zatwierdzić — bywa, że w okresie nikomu oceny nie
 * postawiono (kurs bez oceniania, same zajęcia praktyczne, brak uczestników),
 * a okres i tak trzeba rozliczyć. Taki protokół jest wystawiony jako pusty:
 * wydruk zawiera adnotację, że nie wystawiono żadnej oceny (patrz ti_protocol_pdf).
 *
 * @throws RuntimeException gdy protokół nie istnieje albo jest już zatwierdzony.
 */
function ti_protocol_approve(int $protocol_id, ?int $by, string $by_name): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot) throw new \RuntimeException('Protokół nie istnieje.');
    ti_protocol_require_course_open((int)$prot['course_id']);
    if (ti_protocol_is_locked($prot)) throw new \RuntimeException('Protokół jest już zatwierdzony.');
    ti_protocol_require_period_open($prot);

    db()->prepare(
        "UPDATE k30_ti_protocols
            SET status='approved', approved_by=?, approved_name=?, approved_at=datetime('now'),
                updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, $protocol_id]);

    ti_protocol_snapshot_save($prot);
}

/**
 * Zapisuje stałą kopię danych protokołu (z chwili zatwierdzenia). Liczone na
 * żywo z lekcji/zapisów/dziennika — po zapisie zatwierdzony protokół czyta już
 * tylko kopię (ti_protocol_hours_and_payout / _participants_for / _averages_for).
 */
function ti_protocol_snapshot_save(array $prot): void {
    $snap = [
        'v'            => 1,
        'hours'        => ti_protocol_hours_and_payout_live($prot),
        'participants' => array_map(fn($p) => ['client_id' => (int)$p['client_id'], 'name' => (string)$p['name']],
                                    ti_protocol_participants((int)$prot['course_id'])),
        'averages'     => ti_protocol_diary_averages((int)$prot['course_id'], $prot['date_from'] ?? null, $prot['date_to'] ?? null),
    ];
    db()->prepare("UPDATE k30_ti_protocols SET snapshot_json=?, snapshot_at=datetime('now') WHERE id=?")
        ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), (int)$prot['id']]);
}

/** Kopia danych zatwierdzonego protokołu albo null (protokół otwarty / zatwierdzony przed wprowadzeniem kopii). */
function ti_protocol_snapshot(array $prot): ?array {
    if (!ti_protocol_is_locked($prot) || trim((string)($prot['snapshot_json'] ?? '')) === '') return null;
    $s = json_decode((string)$prot['snapshot_json'], true);
    return is_array($s) ? $s : null;
}

/** Uczestnicy protokołu: z kopii zatwierdzenia albo na żywo (aktywni zapisani). */
function ti_protocol_participants_for(array $prot): array {
    $snap = ti_protocol_snapshot($prot);
    return $snap ? (array)($snap['participants'] ?? []) : ti_protocol_participants((int)$prot['course_id']);
}

/** Średnie z dziennika: z kopii zatwierdzenia albo na żywo za zakres protokołu. */
function ti_protocol_averages_for(array $prot): array {
    $snap = ti_protocol_snapshot($prot);
    if ($snap) {
        $out = [];
        foreach ((array)($snap['averages'] ?? []) as $cid => $v) $out[(int)$cid] = (float)$v;
        return $out;
    }
    return ti_protocol_diary_averages((int)$prot['course_id'], $prot['date_from'] ?? null, $prot['date_to'] ?? null);
}

/**
 * Czy dane na żywo rozjechały się z kopią zatwierdzonego protokołu (ktoś
 * zmienił/usunął lekcję po zatwierdzeniu) — do ostrzeżenia w panelu.
 */
function ti_protocol_snapshot_drift(array $prot): bool {
    $snap = ti_protocol_snapshot($prot);
    if (!$snap) return false;
    $a = (array)($snap['hours'] ?? []);
    $b = ti_protocol_hours_and_payout_live($prot);
    return (int)($a['lessons'] ?? 0) !== (int)$b['lessons']
        || (int)($a['total_min'] ?? 0) !== (int)$b['total_min']
        || abs((float)($a['payout']['brutto_brutto'] ?? 0) - (float)($b['payout']['brutto_brutto'] ?? 0)) > 0.004;
}

/**
 * Odblokowuje zatwierdzony protokół (tylko pracownik D3 / admin — sprawdzane
 * po stronie wywołującej). Ślad odblokowania z powodem zostaje w protokole.
 */
function ti_protocol_unlock(int $protocol_id, ?int $by, string $by_name, string $reason): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot) throw new \RuntimeException('Protokół nie istnieje.');
    ti_protocol_require_course_open((int)$prot['course_id']);
    if (!ti_protocol_is_locked($prot)) throw new \RuntimeException('Ten protokół nie jest zatwierdzony.');
    $reason = trim($reason);
    if ($reason === '') throw new \RuntimeException('Podaj powód odblokowania protokołu.');

    // Protokół z zamkniętego okresu jest domknięty razem z nim — najpierw okres
    if (!empty($prot['period_id'])) {
        require_once __DIR__ . '/ti_periods.php';
        $per = ti_period_get((int)$prot['period_id']);
        if ($per && ti_period_is_closed($per)) {
            throw new \RuntimeException(
                'Okres „' . (string)$per['name'] . '” jest zamknięty — aby poprawić protokół, '
                . 'administrator musi najpierw otworzyć okres ponownie.'
            );
        }
    }

    db()->prepare(
        "UPDATE k30_ti_protocols
            SET status='open', unlocked_by=?, unlocked_name=?, unlocked_at=datetime('now'),
                unlock_reason=?, snapshot_json='', snapshot_at=NULL, updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, $reason, $protocol_id]);

    // Otwarty protokół to zmienione dane — potwierdzenie ewidencji przestaje
    // odpowiadać stanowi, więc trzeba je złożyć ponownie.
    ti_protocol_hours_ack_clear($protocol_id);
}

/** Czy prowadzący potwierdził ewidencję godzin i naliczenie wypłaty. */
function ti_protocol_hours_acked(array $prot): bool {
    return !empty($prot['hours_ack_at']);
}

/**
 * Potwierdzenie ewidencji godzin i naliczenia wypłaty przez prowadzącego —
 * elektroniczny odpowiednik podpisu na wydruku. Zapisuje kto, kiedy i z jakiego
 * adresu IP; potwierdzenie jest jednorazowe (do wycofania przez odblokowanie
 * protokołu, tak samo jak zatwierdzenie ocen).
 *
 * Kierownik/administrator może uzupełnić to potwierdzenie w imieniu prowadzącego
 * (np. gdy ten zapomniał albo nie ma dostępu) — zaznaczając w panelu jawny
 * checkbox "w zastępstwie" ($on_behalf). Zapisujemy to jako "uzupełnienie
 * w zastępstwie" (hours_ack_on_behalf), żeby wydruk/panel nie sugerował
 * fałszywie podpisu samego prowadzącego. Nie zmienia to, komu liczy się
 * wypłata — ta zawsze wynika z instructor_id kursu/lekcji, nie z tego kto
 * kliknął potwierdzenie.
 *
 * @throws RuntimeException gdy protokół nie istnieje albo już potwierdzony.
 */
function ti_protocol_hours_ack(int $protocol_id, ?int $by, string $by_name, string $ip = '', bool $on_behalf = false): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot)                            throw new \RuntimeException('Protokół nie istnieje.');
    ti_protocol_require_course_open((int)$prot['course_id']);
    if (ti_protocol_hours_acked($prot))    throw new \RuntimeException('Ewidencja godzin jest już potwierdzona.');
    // Potwierdza się dokument zamknięty — dopiero zatwierdzenie utrwala ewidencję
    // (snapshot_json); potwierdzenie otwartego protokołu dotyczyłoby danych,
    // które mogą się jeszcze zmienić.
    if (!ti_protocol_is_locked($prot))     throw new \RuntimeException('Najpierw zatwierdź protokół — potwierdza się ewidencję zamkniętego dokumentu.');

    $ip = trim($ip) !== '' ? trim($ip) : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    db()->prepare(
        "UPDATE k30_ti_protocols
            SET hours_ack_by=?, hours_ack_name=?, hours_ack_at=datetime('now'), hours_ack_ip=?,
                hours_ack_on_behalf=?, updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, substr($ip, 0, 64), $on_behalf ? 1 : 0, $protocol_id]);
}

/**
 * Etykieta do wyświetlenia przy potwierdzeniu ewidencji godzin — zwykły podpis
 * prowadzącego albo "Uzupełnienie w/z [imię] ([rola])", gdy zrobił to kierownik
 * lub zastępca w jego imieniu (patrz hours_ack_on_behalf w ti_protocol_hours_ack()).
 */
function ti_protocol_hours_ack_label(array $prot): string {
    $name = (string)($prot['hours_ack_name'] ?? '');
    if ($name === '' || empty($prot['hours_ack_on_behalf'])) return $name;

    $role = function_exists('ti_panel_role') ? ti_panel_role((int)($prot['hours_ack_by'] ?? 0)) : '';
    $role_label = K30_TI_PANEL_ROLES[$role] ?? 'kierownik';
    return 'Uzupełnienie w/z ' . $name . ' (' . $role_label . ')';
}

/** Czy protokół jest podpisany za organizatora. */
function ti_protocol_org_acked(array $prot): bool {
    return !empty($prot['org_ack_at']);
}

/**
 * Podpis za organizatora — elektroniczna kontrasygnata kierownika / pracownika D3
 * (uprawnienie sprawdza wywołujący). Podpisujemy dokument gotowy, więc wymagany
 * jest zatwierdzony protokół; brak potwierdzenia prowadzącego nie blokuje podpisu,
 * ale panel pokazuje ten stan wprost, żeby nikt nie kontrasygnował w ciemno.
 *
 * @throws RuntimeException gdy protokół nie istnieje, nie jest zatwierdzony
 *                          albo jest już podpisany.
 */
function ti_protocol_org_ack(int $protocol_id, ?int $by, string $by_name, string $ip = ''): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot)                          throw new \RuntimeException('Protokół nie istnieje.');
    ti_protocol_require_course_open((int)$prot['course_id']);
    if (!ti_protocol_is_locked($prot))   throw new \RuntimeException('Najpierw zatwierdź protokół — podpisuje się dokument zamknięty.');
    if (ti_protocol_org_acked($prot))    throw new \RuntimeException('Protokół jest już podpisany za organizatora.');

    $ip = trim($ip) !== '' ? trim($ip) : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    db()->prepare(
        "UPDATE k30_ti_protocols
            SET org_ack_by=?, org_ack_name=?, org_ack_at=datetime('now'), org_ack_ip=?,
                updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, substr($ip, 0, 64), $protocol_id]);
}

/**
 * Wycofuje oba podpisy (wołane przy odblokowaniu protokołu) — po korekcie danych
 * ani ewidencja, ani kontrasygnata nie odpowiadają już stanowi dokumentu.
 */
function ti_protocol_hours_ack_clear(int $protocol_id): void {
    ti_protocols_migrate();
    db_exec(
        "UPDATE k30_ti_protocols
            SET hours_ack_by=NULL, hours_ack_name='', hours_ack_at=NULL, hours_ack_ip='',
                org_ack_by=NULL,   org_ack_name='',   org_ack_at=NULL,   org_ack_ip='',
                updated_at=datetime('now')
          WHERE id=?",
        [$protocol_id]
    );
}

/**
 * Wypełnienie protokołu: ilu uczestników ma ocenę.
 * @return array{total:int, filled:int, pct:int}
 */
function ti_protocol_stats(int $protocol_id, int $course_id): array {
    ti_protocols_migrate();
    $total = count(ti_protocol_participants($course_id));
    $filled = (int)(db_one(
        "SELECT COUNT(*) AS n FROM k30_ti_protocol_entries
          WHERE protocol_id=? AND value_text != ''",
        [$protocol_id]
    )['n'] ?? 0);
    if ($filled > $total) $filled = $total;   // uczestnik wypisany po wpisie oceny
    return [
        'total'  => $total,
        'filled' => $filled,
        'pct'    => $total > 0 ? (int)round($filled * 100 / $total) : 0,
    ];
}

/** Czy w protokole nie ma ani jednej oceny (protokół pusty). */
function ti_protocol_is_empty(array $stats): bool {
    return (int)$stats['filled'] === 0;
}

/** Adnotacja na wydruk pustego protokołu. */
function ti_protocol_empty_note(array $stats): string {
    return $stats['total'] === 0
        ? 'ADNOTACJA: W okresie objętym protokołem do zajęć nie był zapisany żaden uczestnik — protokół pozostaje pusty.'
        : 'ADNOTACJA: W okresie objętym protokołem nie wystawiono żadnej oceny. Protokół zostaje wystawiony jako pusty dla '
          . $stats['total'] . ' ' . ($stats['total'] === 1 ? 'uczestnika' : 'uczestników') . '.';
}

/** Krótki opis wypełnienia, np. „częściowo wypełniony (3 z 8)”. */
function ti_protocol_fill_text(array $stats): string {
    if ($stats['total'] === 0)                 return 'brak uczestników';
    if ($stats['filled'] === 0)                return 'pusty (0 z ' . $stats['total'] . ')';
    if ($stats['filled'] < $stats['total'])    return 'częściowo wypełniony (' . $stats['filled'] . ' z ' . $stats['total'] . ')';
    return 'wypełniony (' . $stats['total'] . ' z ' . $stats['total'] . ')';
}

/**
 * Średnia ważona z e-dziennika per uczestnik (do kolumny pomocniczej w protokole).
 *
 * Liczona TYLKO z ocen wystawionych w okresie protokołu ($from/$to — daty
 * okresu nauczania), a nie od dnia utworzenia grupy: k30_ti_course_grades()
 * zwraca całą historię ocen kursu (e-dziennik pokazuje ją w całości celowo),
 * więc bez tego ograniczenia protokół za np. wrzesień pokazywałby średnią
 * wliczającą też oceny z czerwca czy lipca. Ocena bez powiązanej lekcji
 * (session_id NULL) liczy się po dacie wystawienia (graded_at).
 *
 * @return array<int, float> client_id => średnia
 */
function ti_protocol_diary_averages(int $course_id, ?string $from = null, ?string $to = null): array {
    $by_client = [];
    foreach (k30_ti_course_grades($course_id) as $g) {
        if ($from !== null && $to !== null) {
            $gdate = substr((string)($g['session_date'] ?? '') ?: (string)($g['graded_at'] ?? ''), 0, 10);
            if ($gdate === '' || $gdate < $from || $gdate > $to) continue;
        }
        $by_client[(int)$g['client_id']][] = $g;
    }
    $out = [];
    foreach ($by_client as $cid => $grades) {
        $avg = k30_ti_grades_average($grades);
        if ($avg !== null) $out[$cid] = $avg;
    }
    return $out;
}

/**
 * Ewidencja godzin prowadzącego i naliczenie wypłaty za okres protokołu.
 *
 * Liczy tak samo, jak zakładka „Wypłaty” i k30_ti_payouts_by_instructor():
 * stawka za zajęcia jest na kursie (lesson_payout_bb), liczą się zajęcia
 * odbyte (held / individual_change / remote_material), a praca własna
 * (self_prep_remote) i formy student/B2B są bezskładkowe.
 *
 * @return array{from:string, to:string, rows:array, lessons:int, total_min:int,
 *               bb:float, payout:array, instructor:string, form:string, has_rate:bool}
 */
function ti_protocol_hours_and_payout(array $prot): array {
    $snap = ti_protocol_snapshot($prot);
    if ($snap && is_array($snap['hours'] ?? null)) return $snap['hours'] + ['frozen' => true];
    return ti_protocol_hours_and_payout_live($prot);
}

/** Ewidencja i wypłata liczone na żywo z lekcji (bez kopii zatwierdzenia). */
function ti_protocol_hours_and_payout_live(array $prot): array {
    $course_id = (int)$prot['course_id'];

    // Zakres: okres protokołu, a bez okresu — całe życie kursu
    $from = (string)($prot['date_from'] ?? '');
    $to   = (string)($prot['date_to']   ?? '');
    if ($from === '' || $to === '') {
        $r    = db_one("SELECT MIN(lesson_date) AS f, MAX(lesson_date) AS t FROM k30_ti_sessions WHERE course_id=?", [$course_id]);
        $from = (string)($r['f'] ?? date('Y-m-d'));
        $to   = (string)($r['t'] ?? date('Y-m-d'));
    }

    $c = db_one(
        "SELECT c.lesson_payout_bb, c.instructor_id, COALESCE(u.name,'') AS iname,
                COALESCE(u.ti_payout_form, CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS payout_form
           FROM k30_ti_courses c
           LEFT JOIN users u ON u.id = c.instructor_id
          WHERE c.id=?",
        [$course_id]
    ) ?: [];
    $bb          = (float)($c['lesson_payout_bb'] ?? 0);
    $form        = (string)($c['payout_form'] ?? 'zlecenie');
    $main_iid    = (int)($c['instructor_id'] ?? 0);

    // Zastępstwo (s.instructor_id ≠ prowadzący kursu) płacone jest ZASTĘPCY, wg jego
    // formy rozliczenia — tak samo jak k30_ti_payouts_by_instructor() (zakładka Wypłaty).
    // Ewidencja godzin kursu obejmuje wszystkie zajęcia, ale naliczenie wypłaty
    // prowadzącego tylko jego własne; zastępstwa idą osobnym zestawieniem.
    $sessions = db_all(
        "SELECT s.*, COALESCE(s.instructor_id, c.instructor_id) AS eff_iid, COALESCE(u.name,'') AS eff_name,
                COALESCE(u.ti_payout_form, CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS eff_form
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN users u ON u.id = COALESCE(s.instructor_id, c.instructor_id)
          WHERE s.course_id=? AND s.lesson_date BETWEEN ? AND ?
            AND s.status IN ('held','individual_change','remote_material')
          ORDER BY s.lesson_date, s.time_from",
        [$course_id, $from, $to]
    );

    $rows = []; $total_min = 0; $acc = _k30_ti_payout_zero(); $subs = [];
    foreach ($sessions as $s) {
        $min = (int)($s['duration_min'] ?? 0);
        if ($min <= 0 && $s['time_from'] && $s['time_to']) {
            $min = max(0, ti_hm2min((string)$s['time_to']) - ti_hm2min((string)$s['time_from']));
        }
        $total_min += $min;

        $eff_iid = (int)($s['eff_iid'] ?? 0);
        $is_sub  = $eff_iid !== $main_iid;
        $b = null;
        if ($bb > 0) {
            $exempt = in_array((string)$s['eff_form'], ['student', 'b2b'], true) || !empty($s['self_prep_remote']);
            $b = k30_ti_payout_breakdown($bb, $exempt);
            if ($is_sub) {
                if (!isset($subs[$eff_iid])) {
                    $subs[$eff_iid] = _k30_ti_payout_zero() + ['name' => (string)$s['eff_name'], 'form' => (string)$s['eff_form']];
                }
                _k30_ti_payout_accumulate($subs[$eff_iid], $b);
            } else {
                _k30_ti_payout_accumulate($acc, $b);
            }
        }
        $rows[] = [
            'date'    => (string)$s['lesson_date'],
            'from'    => substr((string)$s['time_from'], 0, 5),
            'to'      => substr((string)$s['time_to'], 0, 5),
            'min'     => $min,
            'topic'   => (string)($s['topic'] ?? ''),
            'status'  => (string)$s['status'],
            'own'     => !empty($s['self_prep_remote']),
            'sub'     => $is_sub ? (string)$s['eff_name'] : '',
            'bb'      => $b ? (float)$b['brutto_brutto'] : 0.0,
            'netto'   => $b ? (float)$b['netto'] : 0.0,
        ];
    }

    return [
        'from' => $from, 'to' => $to, 'rows' => $rows,
        'lessons' => count($rows), 'total_min' => $total_min,
        'bb' => $bb, 'payout' => $acc, 'subs' => array_values($subs),
        'instructor' => (string)($c['iname'] ?? ''), 'form' => $form,
        'has_rate' => $bb > 0,
    ];
}

/**
 * Godziny do ewidencji/listy: każda rozpoczęta godzina = pełna, PER LEKCJA
 * (45 min → 1, 90 min → 2) — ta sama zasada co rozliczenie kursanta
 * (k30_ti_calculate_billing: ceil(duration_min/60)).
 */
function ti_protocol_hours_ceil(int $min): int {
    return $min > 0 ? (int)ceil($min / 60) : 0;
}

/** Suma godzin (każda rozpoczęta = pełna) z wierszy ewidencji. */
function ti_protocol_rows_hours(array $rows): int {
    $t = 0;
    foreach ($rows as $r) $t += ti_protocol_hours_ceil((int)($r['min'] ?? 0));
    return $t;
}

/** Minuty → „12 h 30 min” (na wydruk ewidencji). */
function ti_protocol_hm(int $min): string {
    if ($min <= 0) return '0 h';
    $h = intdiv($min, 60); $m = $min % 60;
    return ($h ? $h . ' h' : '') . ($h && $m ? ' ' : '') . ($m ? $m . ' min' : '');
}

/** Kwota w formacie polskim, np. „1 234,50 zł”. */
function ti_protocol_money(float $v): string {
    return number_format($v, 2, ',', ' ') . ' zł';
}

/** Etykieta statusu zajęć na ewidencji. */
function ti_protocol_status_lesson(string $status, bool $own): string {
    if ($own) return 'praca własna (bez składek)';
    return [
        'held'              => 'odbyte',
        'individual_change' => 'zmiana indywidualna',
        // Status remote_material NIE zwalnia ze składek — zwalnia tylko flaga
        // self_prep_remote ($own), tak samo jak w zakładce Wypłaty.
        'remote_material'   => 'materiał zdalny',
    ][$status] ?? $status;
}

/**
 * Treść protokołu jako HTML do wydruku (wydzielona z ti_protocol_pdf, żeby dało
 * się ją sprawdzić bez generowania PDF — wzorzec jak ti_syllabus_print_html).
 * Protokół bez ocen dostaje wyraźną adnotację o braku ocen.
 */
function ti_protocol_print_html(array $prot): string {
    $course_id = (int)$prot['course_id'];
    $parts     = ti_protocol_participants_for($prot);
    $entries   = ti_protocol_entries((int)$prot['id']);
    $avgs      = ti_protocol_averages_for($prot);
    $stats     = ti_protocol_stats((int)$prot['id'], $course_id);
    $h         = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $rows = '';
    $i = 0;
    foreach ($parts as $p) {
        $cid = (int)$p['client_id'];
        $e   = $entries[$cid] ?? null;
        $i++;
        $rows .= '<tr>'
            . '<td>' . $i . '.</td>'
            . '<td>' . $h($p['name']) . '</td>'
            . '<td class="c">' . $h(($e['value_text'] ?? '') !== '' ? $e['value_text'] : '—') . '</td>'
            . '<td class="c">' . (isset($avgs[$cid]) ? number_format($avgs[$cid], 2, ',', '') : '—') . '</td>'
            . '<td>' . $h($e['note'] ?? '') . '</td>'
            . '</tr>';
    }

    // ── 2. Ewidencja godzin i 3. Wypłata ─────────────────────────────────────
    $hp = ti_protocol_hours_and_payout($prot);

    $ev = '<h2>2. Ewidencja godzin prowadzącego</h2>';
    $ev .= '<p class="sub">Prowadzący: <strong>' . $h($hp['instructor'] !== '' ? $hp['instructor'] : '—')
        . '</strong> · zakres: ' . $h(date('d.m.Y', strtotime($hp['from'])))
        . '–' . $h(date('d.m.Y', strtotime($hp['to']))) . '</p>';
    $ev .= '<p class="sub">Liczba godzin: każda rozpoczęta godzina zajęć liczona jako pełna (w nawiasie faktyczny czas).</p>';
    if (!$hp['rows']) {
        $ev .= '<p class="empty">W tym zakresie nie ma zajęć odbytych — ewidencja jest pusta.</p>';
    } else {
        $ev .= '<table class="items"><thead><tr>'
            . '<th style="width:6%">#</th><th style="width:14%">Data</th><th style="width:16%">Godziny</th>'
            . '<th style="width:14%">Liczba godzin</th><th>Temat</th><th style="width:18%">Rodzaj</th>'
            . ($hp['has_rate'] ? '<th style="width:16%">Wypłata brutto-brutto</th>' : '')
            . '</tr></thead><tbody>';
        $i = 0;
        foreach ($hp['rows'] as $r) {
            $i++;
            $ev .= '<tr>'
                . '<td>' . $i . '.</td>'
                . '<td>' . $h(date('d.m.Y', strtotime($r['date']))) . '</td>'
                . '<td>' . $h($r['from'] !== '' ? $r['from'] . '–' . $r['to'] : '—') . '</td>'
                . '<td>' . ti_protocol_hours_ceil((int)$r['min'])
                    . ' <span style="font-size:8pt;color:#555">(' . $h(ti_protocol_hm((int)$r['min'])) . ')</span></td>'
                . '<td>' . $h($r['topic']) . '</td>'
                . '<td>' . $h(ti_protocol_status_lesson((string)$r['status'], (bool)$r['own']))
                    . (($r['sub'] ?? '') !== '' ? '<br><em>zastępstwo: ' . $h($r['sub']) . '</em>' : '') . '</td>'
                . ($hp['has_rate'] ? '<td class="r">' . $h(ti_protocol_money((float)$r['bb'])) . '</td>' : '')
                . '</tr>';
        }
        $ev .= '<tr class="sum"><td colspan="3">Razem</td>'
            . '<td>' . ti_protocol_rows_hours($hp['rows']) . ' h</td>'
            . '<td colspan="2">' . (int)$hp['lessons'] . ' ' . ($hp['lessons'] === 1 ? 'zajęcie' : 'zajęć') . '</td>'
            . ($hp['has_rate'] ? '<td class="r">' . $h(ti_protocol_money((float)$hp['payout']['brutto_brutto'])) . '</td>' : '')
            . '</tr>';
        $ev .= '</tbody></table>';
    }

    $pay = '<h2>3. Naliczenie wypłaty</h2>';
    if (!$hp['has_rate']) {
        $pay .= '<p class="empty">Dla tych zajęć nie ustawiono stawki za zajęcie (lesson_payout_bb = 0), '
              . 'więc wypłata nie jest naliczana. Ewidencja godzin powyżej pozostaje wiążąca.</p>';
    } else {
        $P = $hp['payout'];
        $pay .= '<table class="head"><tbody>'
            . '<tr><th>Stawka za zajęcie (brutto-brutto)</th><td>' . $h(ti_protocol_money((float)$hp['bb'])) . '</td></tr>'
            . '<tr><th>Zajęcia rozliczone</th><td>' . (int)$P['lessons'] . '</td></tr>'
            . '<tr><th>Suma brutto-brutto (koszt)</th><td>' . $h(ti_protocol_money((float)$P['brutto_brutto'])) . '</td></tr>'
            . '<tr><th>ZUS pracodawcy</th><td>' . $h(ti_protocol_money((float)$P['zus_employer'])) . '</td></tr>'
            . '<tr><th>Brutto (wynagrodzenie)</th><td>' . $h(ti_protocol_money((float)$P['brutto'])) . '</td></tr>'
            . '<tr><th>Składki potrącone</th><td>' . $h(ti_protocol_money((float)$P['skladki'])) . '</td></tr>'
            . '<tr><th>Zaliczka PIT</th><td>' . $h(ti_protocol_money((float)$P['pit'])) . '</td></tr>'
            . '<tr><th>Do wypłaty netto</th><td><strong>' . $h(ti_protocol_money((float)$P['netto'])) . '</strong></td></tr>'
            . '</tbody></table>';
        $pay .= '<p class="sub">Forma rozliczenia: ' . $h($hp['form'])
              . '. Zajęcia oznaczone jako „praca własna (bez składek)” liczone są bezskładkowo; '
              . 'materiał zdalny — wg formy rozliczenia prowadzącego.</p>';
        if (!empty($hp['subs'])) {
            $pay .= '<p class="sub"><strong>Zastępstwa</strong> — wypłata należy się zastępcy (wg jego formy rozliczenia) '
                  . 'i nie wchodzi do naliczenia prowadzącego powyżej:</p>'
                  . '<table class="items"><thead><tr><th>Zastępca</th><th style="width:14%">Forma</th>'
                  . '<th style="width:12%">Zajęcia</th><th style="width:20%">Brutto-brutto</th><th style="width:20%">Netto</th>'
                  . '</tr></thead><tbody>';
            foreach ($hp['subs'] as $sb) {
                $pay .= '<tr><td>' . $h($sb['name'] !== '' ? $sb['name'] : '—') . '</td>'
                      . '<td>' . $h($sb['form']) . '</td>'
                      . '<td class="c">' . (int)$sb['lessons'] . '</td>'
                      . '<td class="r">' . $h(ti_protocol_money((float)$sb['brutto_brutto'])) . '</td>'
                      . '<td class="r">' . $h(ti_protocol_money((float)$sb['netto'])) . '</td></tr>';
            }
            $pay .= '</tbody></table>';
        }
    }

    $acked = ti_protocol_hours_acked($prot);
    $sign_instructor = $acked
        ? 'Potwierdzone elektronicznie w panelu:<br><strong>' . $h(ti_protocol_hours_ack_label($prot) ?: '—') . '</strong><br>'
          . $h(date('d.m.Y H:i', strtotime((string)$prot['hours_ack_at'])))
          . ($prot['hours_ack_ip'] !== '' ? '<br>IP ' . $h($prot['hours_ack_ip']) : '')
        : '.............................................<br>data i podpis prowadzącego';

    $org_acked = ti_protocol_org_acked($prot);
    $sign_org  = $org_acked
        ? 'Podpisane elektronicznie za organizatora:<br><strong>' . $h($prot['org_ack_name'] ?: '—') . '</strong><br>'
          . $h(date('d.m.Y H:i', strtotime((string)$prot['org_ack_at'])))
          . ($prot['org_ack_ip'] !== '' ? '<br>IP ' . $h($prot['org_ack_ip']) : '')
        : '.............................................<br>za organizatora';

    $statement = '<div class="stmt">'
        . '<p class="stmt-h">Oświadczenie prowadzącego</p>'
        . '<p>Potwierdzam, że ewidencja godzin oraz naliczenie wypłaty w tym protokole '
        . 'są zgodne ze stanem faktycznym — zajęcia w wykazanych terminach odbyły się '
        . 'w podanym wymiarze, a wykazane kwoty nie budzą moich zastrzeżeń.</p>'
        . ($acked ? '' : '<p class="empty">Oświadczenie niepotwierdzone — wymaga podpisu prowadzącego.</p>')
        . '<table class="signs"><tbody><tr>'
        . '<td>' . $sign_instructor . '</td>'
        . '<td>' . $sign_org . '</td>'
        . '</tr></tbody></table></div>';

    $empty_note = ti_protocol_is_empty($stats)
        ? '<p class="empty-note">' . $h(ti_protocol_empty_note($stats)) . '</p>'
        : '';

    $trace = ti_protocol_is_locked($prot)
        ? 'Zatwierdził: ' . $h($prot['approved_name'] ?: '—')
          . ', ' . $h($prot['approved_at'] ? date('d.m.Y H:i', strtotime((string)$prot['approved_at'])) : '—')
        : 'Protokół niezatwierdzony — wydruk roboczy.';
    if (!empty($prot['snapshot_at']) && ti_protocol_snapshot($prot)) {
        $trace .= '<br>Dane ewidencji, wypłaty i uczestników utrwalone przy zatwierdzeniu: '
            . $h(date('d.m.Y H:i', strtotime((string)$prot['snapshot_at']))) . '.';
    }
    if (!empty($prot['unlocked_at'])) {
        $trace .= '<br>Odblokowany: ' . $h($prot['unlocked_name'] ?: '—') . ', '
            . $h(date('d.m.Y H:i', strtotime((string)$prot['unlocked_at'])))
            . ' — powód: ' . $h($prot['unlock_reason']);
    }

    return '<h1>Protokół zajęć kursu za ' . (!empty($prot['year_month']) ? 'miesiąc' : 'okres') . '</h1>'
        . '<table class="head"><tbody>'
        . '<tr><th>Zajęcia</th><td>' . $h($prot['course_name'] ?? '') . '</td></tr>'
        . '<tr><th>Protokół</th><td>' . $h($prot['title']) . '</td></tr>'
        . '<tr><th>Okres</th><td>' . $h($prot['period_name'] ?: 'nie wskazano') . '</td></tr>'
        . '<tr><th>Stan</th><td>' . $h(ti_protocol_status_label((string)$prot['status']))
            . ' — ' . $h(ti_protocol_fill_text($stats))
            . (ti_protocol_is_empty($stats) ? ' — <strong>brak ocen</strong>' : '') . '</td></tr>'
        . '<tr><th>Wydruk</th><td>' . date('d.m.Y H:i') . '</td></tr>'
        . '</tbody></table>'
        . '<h2>1. Oceny końcowe</h2>'
        . '<table class="items"><thead><tr>'
        . '<th style="width:6%">#</th><th>Uczestnik</th><th style="width:14%">Ocena końcowa</th>'
        . '<th style="width:14%">Średnia z dziennika</th><th style="width:26%">Uwagi</th>'
        . '</tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak uczestników.</td></tr>') . '</tbody></table>'
        . $empty_note
        . $ev
        . $pay
        . '<p class="trace">' . $trace . '</p>'
        . $statement;
}

/** Protokół jako bajty PDF (mPDF, dejavuserif) albo null przy błędzie. */
function ti_protocol_pdf(array $prot): ?string {
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 18, 'margin_right' => 16, 'margin_top' => 16, 'margin_bottom' => 16,
            'default_font' => 'dejavuserif', 'tempDir' => $mpdf_tmp,
        ]);
        $mpdf->SetTitle('Protokół zajęć kursu — ' . (string)($prot['course_name'] ?? '')
            . ((string)($prot['period_name'] ?? '') !== '' ? ', ' . (string)$prot['period_name'] : ''));
        $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
        $mpdf->WriteHTML(
            'body { font-family:"DejaVu Serif",serif; font-size:10pt; color:#000; }
             h1 { font-size:14pt; margin:0 0 3mm; }
             table { border-collapse:collapse; width:100%; }
             table.head th { text-align:left; width:30%; background:#f4f6f8; }
             table.head th, table.head td { border:.2mm solid #ccc; padding:1.3mm 2mm; font-size:9.5pt; }
             table.items { margin-top:5mm; }
             table.items th { background:#eef1f4; border:.2mm solid #999; padding:1.3mm 2mm; font-size:9pt; text-align:left; }
             table.items td { border:.2mm solid #999; padding:1.3mm 2mm; font-size:9.5pt; }
             td.c { text-align:center; }
             p.empty-note { margin-top:5mm; padding:2.5mm 3mm; border:.3mm solid #333;
                            background:#f2f2f2; font-size:9.5pt; font-weight:bold; }
             p.trace { margin-top:5mm; font-size:9pt; color:#333; }
             h2 { font-size:11.5pt; margin:6mm 0 2mm; border-bottom:.3mm solid #999; padding-bottom:1mm; }
             p.sub { font-size:9pt; color:#333; margin:0 0 2mm; }
             td.r { text-align:right; }
             tr.sum td { background:#f2f2f2; font-weight:bold; }
             div.stmt { margin-top:6mm; border:.3mm solid #333; padding:3mm; }
             p.stmt-h { font-weight:bold; margin:0 0 1.5mm; font-size:10pt; }
             div.stmt p { font-size:9.5pt; margin:0 0 2mm; }
             table.signs td { width:50%; padding-top:12mm; font-size:9pt; text-align:center; border:none; }',
            \Mpdf\HTMLParserMode::HEADER_CSS
        );
        $mpdf->WriteHTML(ti_protocol_print_html($prot), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Protokół "roboczy" dla kursu i miesiąca bez zakładania wiersza w bazie —
 * do podglądu/wydruku listy godzin przed zatwierdzeniem (get-or-create
 * zostawiamy samemu zatwierdzeniu).
 */
function ti_protocol_month_stub(int $course_id, string $year_month): array {
    $existing = db_one("SELECT id FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$course_id, $year_month]);
    if ($existing) return ti_protocol_get((int)$existing['id']) ?: [];
    $c = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id]);
    return [
        'id' => 0, 'course_id' => $course_id, 'course_name' => (string)($c['name'] ?? ''),
        'year_month' => $year_month, 'status' => 'open', 'title' => 'Protokół ' . $year_month,
        'period_name' => 'miesiąc ' . $year_month,
        'date_from' => $year_month . '-01', 'date_to' => date('Y-m-t', strtotime($year_month . '-01')),
    ];
}

/**
 * Lista godzin do sprawdzenia przed podpisem: jeden wiersz na zajęcia —
 * data i liczba godzin (każda rozpoczęta godzina = pełna: 45 min → 1, 90 min → 2). Z kopii zatwierdzenia,
 * gdy protokół jest zatwierdzony, inaczej na żywo.
 *
 * @return array{rows: array<int, array{date:string, from:string, to:string, min:int, hours:float, sub:string}>, total_min:int, total_hours:float}
 */
function ti_protocol_hours_list(array $prot): array {
    $hp   = ti_protocol_hours_and_payout($prot);
    $rows = [];
    foreach ((array)$hp['rows'] as $r) {
        $min = (int)$r['min'];
        $rows[] = [
            'date' => (string)$r['date'], 'from' => (string)$r['from'], 'to' => (string)$r['to'],
            'min' => $min, 'hours' => (float)ti_protocol_hours_ceil($min), 'sub' => (string)($r['sub'] ?? ''),
        ];
    }
    return ['rows' => $rows, 'total_min' => (int)$hp['total_min'], 'total_hours' => (float)ti_protocol_rows_hours($rows),
            'instructor' => (string)($hp['instructor'] ?? '')];
}

/** Liczba godzin po polsku: 1,5 / 2 / 0,75. */
function ti_protocol_hours_fmt(float $h): string {
    return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',');
}

/** HTML listy godzin (data — liczba godzin) — do okna sprawdzenia i PDF. */
function ti_protocol_hours_list_html(array $prot): string {
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $hl = ti_protocol_hours_list($prot);
    $out = '<h1>Lista godzin zajęć</h1>'
         . '<table class="head"><tbody>'
         . '<tr><th>Zajęcia</th><td>' . $h($prot['course_name'] ?? '') . '</td></tr>'
         . '<tr><th>Okres</th><td>' . $h($prot['period_name'] ?? '') . '</td></tr>'
         . '<tr><th>Prowadzący</th><td>' . $h($hl['instructor'] !== '' ? $hl['instructor'] : '—') . '</td></tr>'
         . '<tr><th>Wydruk</th><td>' . date('d.m.Y H:i') . '</td></tr>'
         . '</tbody></table>';
    if (!$hl['rows']) return $out . '<p class="empty-note">W tym okresie nie ma zajęć odbytych.</p>';
    $out .= '<table class="items"><thead><tr><th style="width:8%">#</th><th>Data</th><th style="width:30%" class="r">Liczba godzin</th></tr></thead><tbody>';
    $i = 0;
    foreach ($hl['rows'] as $r) {
        $i++;
        $out .= '<tr><td>' . $i . '.</td><td>' . $h(date('d.m.Y', strtotime($r['date'])))
              . ($r['sub'] !== '' ? ' <em>(zastępstwo: ' . $h($r['sub']) . ')</em>' : '') . '</td>'
              . '<td class="r">' . $h(ti_protocol_hours_fmt($r['hours'])) . '</td></tr>';
    }
    $out .= '<tr class="sum"><td colspan="2">Razem (' . count($hl['rows']) . ' zaj.)</td><td class="r">'
          . $h(ti_protocol_hours_fmt($hl['total_hours'])) . '</td></tr></tbody></table>'
          . '<p style="font-size:8.5pt;color:#444">Każda rozpoczęta godzina zajęć liczona jako pełna.</p>'
          . '<p class="trace">Sprawdziłem/am listę godzin: ............................................. (data i podpis)</p>';
    return $out;
}

/**
 * Okno modalne (Bootstrap) „Sprawdź listę godzin”: tabela data — liczba godzin,
 * wydruk PDF i obowiązkowe zaznaczenie „sprawdziłem/am” przed przyciskiem
 * Akceptuję, który wysyła formularz $form_id (albo sam jest jego submitem).
 * $submit_attrs — atrybuty przycisku (np. name/value dla _op wspólnego formularza).
 */
function ti_protocol_hours_check_modal(string $modal_id, array $prot, string $pdf_url, string $form_id, string $accept_label, string $submit_attrs = ''): string {
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $hl = ti_protocol_hours_list($prot);
    $rows = '';
    foreach ($hl['rows'] as $i => $r) {
        $rows .= '<tr><td class="text-body-secondary">' . ($i + 1) . '.</td><td>' . $h(date('d.m.Y', strtotime($r['date'])))
               . ($r['sub'] !== '' ? ' <span class="small text-body-secondary">(zastępstwo: ' . $h($r['sub']) . ')</span>' : '')
               . '</td><td class="text-end">' . $h(ti_protocol_hours_fmt($r['hours'])) . '</td></tr>';
    }
    $cb = $modal_id . '_ok';
    return '<div class="modal fade" id="' . $h($modal_id) . '" tabindex="-1" aria-labelledby="' . $h($modal_id) . '_t" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-scrollable modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="' . $h($modal_id) . '_t"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Sprawdź listę godzin</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>'
        . '<div class="modal-body">'
        . '<p class="small mb-2"><strong>' . $h($prot['course_name'] ?? '') . '</strong> — ' . $h($prot['period_name'] ?? '') . '</p>'
        . ($hl['rows']
            ? '<table class="table table-sm align-middle mb-2"><caption class="visually-hidden">Lista godzin zajęć</caption>'
              . '<thead><tr><th scope="col" style="width:3rem">#</th><th scope="col">Data</th><th scope="col" class="text-end">Liczba godzin</th></tr></thead>'
              . '<tbody>' . $rows . '</tbody><tfoot><tr class="fw-semibold"><td colspan="2">Razem (' . count($hl['rows']) . ' zaj.)</td>'
              . '<td class="text-end">' . $h(ti_protocol_hours_fmt($hl['total_hours'])) . '</td></tr></tfoot></table>'
            : '<p class="text-body-secondary">W tym okresie nie ma zajęć odbytych.</p>')
        . '<p class="small text-body-secondary mb-2">Każda rozpoczęta godzina zajęć liczona jako pełna (45 min = 1 h, 90 min = 2 h).</p>'
        . '<a href="' . $h($pdf_url) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mb-3">'
        . '<i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj listę godzin (PDF)</a>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . $h($cb) . '" '
        . 'onchange="document.getElementById(\'' . $h($modal_id) . '_btn\').disabled=!this.checked">'
        . '<label class="form-check-label small" for="' . $h($cb) . '">Sprawdziłem/am listę godzin — daty i liczba godzin są zgodne ze stanem faktycznym.</label></div>'
        . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>'
        . '<button type="submit" class="btn btn-success" id="' . $h($modal_id) . '_btn" form="' . $h($form_id) . '" disabled ' . $submit_attrs . '>'
        . '<i class="bi bi-check2-circle me-1" aria-hidden="true"></i>' . $h($accept_label) . '</button></div>'
        . '</div></div></div>';
}

/** Lista godzin jako PDF (mPDF) albo null przy błędzie. */
function ti_protocol_hours_list_pdf(array $prot): ?string {
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 18, 'margin_right' => 16, 'margin_top' => 16, 'margin_bottom' => 16,
            'default_font' => 'dejavuserif', 'tempDir' => $mpdf_tmp,
        ]);
        $mpdf->SetTitle('Lista godzin — ' . (string)($prot['course_name'] ?? '') . ', ' . (string)($prot['period_name'] ?? ''));
        $mpdf->WriteHTML(
            'body { font-family:"DejaVu Serif",serif; font-size:10pt; color:#000; }
             h1 { font-size:14pt; margin:0 0 3mm; }
             table { border-collapse:collapse; width:100%; }
             table.head th { text-align:left; width:30%; background:#f4f6f8; }
             table.head th, table.head td { border:.2mm solid #ccc; padding:1.3mm 2mm; font-size:9.5pt; }
             table.items { margin-top:5mm; }
             table.items th { background:#eef1f4; border:.2mm solid #999; padding:1.3mm 2mm; font-size:9pt; text-align:left; }
             table.items td { border:.2mm solid #999; padding:1.3mm 2mm; font-size:10pt; }
             .r { text-align:right; }
             tr.sum td { background:#f2f2f2; font-weight:bold; }
             p.empty-note { margin-top:5mm; font-weight:bold; }
             p.trace { margin-top:10mm; font-size:9.5pt; }',
            \Mpdf\HTMLParserMode::HEADER_CSS
        );
        $mpdf->WriteHTML(ti_protocol_hours_list_html($prot), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        return null;
    }
}

/** Nazwa pliku listy godzin, np. lista_godzin_Grupa_A_2026-09.pdf */
function ti_protocol_hours_list_filename(array $prot): string {
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($prot['course_name'] ?? 'kurs')) ?: 'kurs');
    $when = (string)($prot['year_month'] ?? '') !== '' ? (string)$prot['year_month'] : (string)($prot['date_from'] ?? date('Y-m-d'));
    return 'lista_godzin_' . trim($slug, '_') . '_' . $when . '.pdf';
}

/**
 * Oceny końcowe kursanta z ZATWIERDZONYCH protokołów — dla panelu kursanta
 * i opiekuna. Protokół w toku nie jest deklaracją, więc go nie pokazujemy:
 * ocena pojawia się dopiero po zatwierdzeniu.
 *
 * @return array<int, array{course_name:string, period:string, value:string, note:string, approved_at:string, approved_name:string}>
 */
function ti_protocol_final_grades_for_client(int $client_id): array {
    ti_protocols_migrate();
    if (!$client_id) return [];
    try {
        return db_all(
            "SELECT c.id AS course_id, c.name AS course_name,
                    COALESCE(per.name, '') AS period,
                    e.value_text AS value, e.note,
                    p.approved_at, p.approved_name
               FROM k30_ti_protocol_entries e
               JOIN k30_ti_protocols p   ON p.id = e.protocol_id
               JOIN k30_ti_courses   c   ON c.id = p.course_id
               LEFT JOIN k30_ti_periods per ON per.id = p.period_id
              WHERE e.client_id = ? AND p.status = 'approved' AND e.value_text != ''
              ORDER BY COALESCE(per.date_from, p.approved_at) DESC, c.name COLLATE NOCASE",
            [$client_id]
        );
    } catch (\Throwable $e) { return []; }
}

/** Nazwa pliku PDF protokołu. */
function ti_protocol_pdf_filename(array $prot): string {
    $base = 'protokol-' . (string)($prot['course_name'] ?? 'zajecia') . '-' . (string)($prot['period_name'] ?? 'okres');
    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base);
    return trim((string)$base, '-') . '.pdf';
}

/**
 * Wywołuje karty30/ti/dydaktyk/api_protocols.php przez HTTP, przekazując
 * ciasteczko BIEŻĄCEJ sesji panelu (dyd_start() już ją otworzył) — działa
 * "w imieniu" zalogowanego prowadzącego. Przygotowanie pod przyszłe
 * wydzielenie panelu dydaktyka jako osobnej aplikacji: wywołujący (patrz
 * protokoly_moje.php) próbuje NAJPIERW przez API, a dopiero gdy się nie
 * uda (sieć, timeout, błąd) — woła bezpośrednio odpowiednią funkcję z tego
 * pliku (ta sama baza). Zwraca null przy jakimkolwiek niepowodzeniu, żeby
 * wywołujący mógł spaść na fallback bez rzucania wyjątku.
 */
function ti_protocols_api_call(string $action, array $params = [], string $method = 'GET'): ?array {
    if (!defined('APP_URL') || !function_exists('curl_init')) return null;
    $url = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/api_protocols.php?action=' . urlencode($action);
    if ($method === 'GET' && $params) $url .= '&' . http_build_query($params);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_COOKIE         => session_name() . '=' . session_id(),
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = json_encode($params, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) return null;
    $json = json_decode((string)$body, true);
    return is_array($json) && !isset($json['error']) ? $json : null;
}
