<?php
/**
 * includes/ti_participant_report.php — Dane do raportów „per uczestnik" (TI).
 *
 * Zestawienie dla kursanta w okresie (miesiąc / rok):
 *   - FREKWENCJA  — obecności/nieobecności na lekcjach we wszystkich jego kursach
 *                   (tylko kursy z track_attendance=1, statusy held/individual_change).
 *   - ROZLICZENIA — należności i wpłaty w okresie + bieżące saldo konta (nadpłata/niedopłata).
 *
 * Używane przez karty30/ti/participant_monthly.php i participant_annual.php (eksport PDF).
 */
require_once __DIR__ . '/ti_payments.php';

const TI_PR_MONTHS_PL = ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
                         'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];

/** Kwota w formacie „1 234,50". */
function ti_pr_zl($x): string { return number_format((float)$x, 2, ',', ' '); }

/**
 * Aktywni uczestnicy (kursanci) z aktywnym zapisem — opcjonalnie w jednym kursie.
 * @return array<int,array{id:int,name:string}> posortowane po nazwie
 */
function ti_pr_clients(int $course_id = 0): array {
    if ($course_id) {
        return db_all(
            "SELECT DISTINCT cl.id, cl.name FROM k30_ti_enrollments e
             JOIN k30_clients cl ON cl.id=e.client_id
             WHERE e.status='active' AND e.course_id=?
             ORDER BY cl.name COLLATE NOCASE", [$course_id]);
    }
    return db_all(
        "SELECT DISTINCT cl.id, cl.name FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.status='active'
         ORDER BY cl.name COLLATE NOCASE");
}

/**
 * Frekwencja uczestnika w okresie [from,to] (daty YYYY-MM-DD), per kurs.
 * Liczą się tylko kursy z track_attendance=1 i lekcje o statusie held/individual_change;
 * odwołane lekcje (cancelled) pokazujemy osobno i nie wliczamy do mianownika.
 *
 * @return array{courses:array<int,array>,held:int,present:int,absent:int,cancelled_lesson:int,pct:?int}
 */
function ti_pr_attendance(int $client_id, string $from, string $to, int $course_id = 0): array {
    $sql = "SELECT c.id AS course_id, c.name AS course_name,
                   %TRACK% s.id AS session_id, s.status,
                   a.attended, COALESCE(a.cancelled,0) AS att_cancelled
            FROM k30_ti_enrollments e
            JOIN k30_ti_courses c ON c.id=e.course_id
            JOIN k30_ti_sessions s ON s.course_id=c.id
            LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=e.client_id
            WHERE e.client_id=? AND e.status='active'
              AND s.lesson_date BETWEEN ? AND ?
              AND (s.status IS NULL OR s.status IN ('held','individual_change','cancelled'))";
    $params = [$client_id, $from, $to];
    if ($course_id) { $sql .= " AND c.id=?"; $params[] = $course_id; }
    $sql .= " ORDER BY c.name COLLATE NOCASE, s.lesson_date, s.time_from";

    $run = function (bool $withTrack) use ($sql, $params) {
        $q = str_replace('%TRACK%', $withTrack ? 'c.track_attendance AS track_attendance,' : '', $sql);
        return db_all($q, $params);
    };
    // Przed migracją kolumny track_attendance — fallback bez niej (domyślnie „liczy frekwencję").
    try { $rows = $run(true); } catch (\Throwable $e) { $rows = $run(false); }

    $courses = [];
    foreach ($rows as $r) {
        $track = array_key_exists('track_attendance', $r) ? (int)($r['track_attendance'] ?? 1) : 1;
        if ($track !== 1) continue; // kurs bez frekwencji — pomijamy
        $cid = (int)$r['course_id'];
        if (!isset($courses[$cid])) {
            $courses[$cid] = ['name' => $r['course_name'], 'held' => 0, 'present' => 0,
                              'absent' => 0, 'cancelled_part' => 0, 'cancelled_lesson' => 0, 'pct' => null];
        }
        if (($r['status'] ?? '') === 'cancelled') { $courses[$cid]['cancelled_lesson']++; continue; }
        $courses[$cid]['held']++;
        if (!empty($r['att_cancelled']))            { $courses[$cid]['cancelled_part']++; $courses[$cid]['absent']++; }
        elseif ((int)($r['attended'] ?? 0) === 1)   { $courses[$cid]['present']++; }
        else                                        { $courses[$cid]['absent']++; }
    }

    $tHeld = $tPres = $tAbs = $tCancL = 0;
    foreach ($courses as &$c) {
        $c['pct'] = $c['held'] > 0 ? (int)round($c['present'] / $c['held'] * 100) : null;
        $tHeld += $c['held']; $tPres += $c['present']; $tAbs += $c['absent']; $tCancL += $c['cancelled_lesson'];
    }
    unset($c);

    return ['courses' => $courses, 'held' => $tHeld, 'present' => $tPres, 'absent' => $tAbs,
            'cancelled_lesson' => $tCancL, 'pct' => $tHeld > 0 ? (int)round($tPres / $tHeld * 100) : null];
}

/**
 * Frekwencja CAŁEJ grupy (kursu) w okresie: per uczestnik + agregat grupy.
 * Frekwencja grupy = suma obecności ÷ suma lekcji z listą obecności (ważona liczbą lekcji).
 *
 * @return array{track:int,participants:array<int,array>,lessons_held:int,lessons_cancelled:int,
 *                held_total:int,present_total:int,avg_pct:?int}
 */
function ti_pr_course_attendance(int $course_id, string $from, string $to): array {
    $track = 1;
    try {
        $c = db_one("SELECT track_attendance FROM k30_ti_courses WHERE id=?", [$course_id]);
        if ($c) $track = (int)($c['track_attendance'] ?? 1);
    } catch (\Throwable $e) { $track = 1; } // przed migracją kolumny — domyślnie liczy frekwencję

    // Lekcje kursu w okresie (odbyte vs odwołane) — niezależnie od uczestników
    $sessions = db_all(
        "SELECT status FROM k30_ti_sessions
         WHERE course_id=? AND lesson_date BETWEEN ? AND ?
           AND (status IS NULL OR status IN ('held','individual_change','cancelled'))",
        [$course_id, $from, $to]);
    $lessons_held = $lessons_cancelled = 0;
    foreach ($sessions as $s) {
        if (($s['status'] ?? '') === 'cancelled') $lessons_cancelled++; else $lessons_held++;
    }

    $participants = []; $tHeld = $tPres = 0;
    foreach (ti_pr_clients($course_id) as $cl) {
        $cid = (int)$cl['id'];
        $a   = ti_pr_attendance($cid, $from, $to, $course_id);
        $c0  = $a['courses'][$course_id] ?? ['held' => 0, 'present' => 0, 'absent' => 0, 'cancelled_lesson' => 0, 'pct' => null];
        $participants[] = ['client_id' => $cid, 'name' => $cl['name'],
                           'held' => $c0['held'], 'present' => $c0['present'], 'absent' => $c0['absent'],
                           'cancelled_lesson' => $c0['cancelled_lesson'], 'pct' => $c0['pct']];
        $tHeld += $c0['held']; $tPres += $c0['present'];
    }
    return ['track' => $track, 'participants' => $participants,
            'lessons_held' => $lessons_held, 'lessons_cancelled' => $lessons_cancelled,
            'held_total' => $tHeld, 'present_total' => $tPres,
            'avg_pct' => $tHeld > 0 ? (int)round($tPres / $tHeld * 100) : null];
}

/**
 * Rozliczenia uczestnika w JEDNYM miesiącu.
 * @return array{charges:float,paid:float,payments:float}
 *   charges  — należności wystawione za ten miesiąc (amount + korekta),
 *   paid     — ile z tych należności już pokryto (alokacja FIFO),
 *   payments — wpłaty zaksięgowane w tym miesiącu (wg daty wpłaty).
 */
function ti_pr_billing_month(int $client_id, int $year, int $month): array {
    ti_payments_migrate();
    $ch = db_one(
        "SELECT COALESCE(SUM(amount + COALESCE(adjustment,0)),0) AS charges,
                COALESCE(SUM(COALESCE(paid_amount,0)),0)         AS paid
         FROM k30_ti_billing
         WHERE client_id=? AND year=? AND month=? AND status IN ('issued','paid')",
        [$client_id, $year, $month]);
    $pay = db_one(
        "SELECT COALESCE(SUM(amount),0) AS s FROM k30_ti_payments
         WHERE client_id=? AND strftime('%Y-%m', COALESCE(paid_at, created_at))=?",
        [$client_id, sprintf('%04d-%02d', $year, $month)]);
    return ['charges'   => round((float)$ch['charges'], 2),
            'paid'      => round((float)$ch['paid'], 2),
            'payments'  => round((float)$pay['s'], 2)];
}

/**
 * Rozliczenia uczestnika per miesiąc w całym roku.
 * @return array{months:array<int,array{charges:float,paid:float,payments:float}>,totals:array{charges:float,paid:float,payments:float}}
 */
function ti_pr_billing_year(int $client_id, int $year): array {
    ti_payments_migrate();
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[$m] = ['charges' => 0.0, 'paid' => 0.0, 'payments' => 0.0];

    foreach (db_all(
        "SELECT month,
                SUM(amount + COALESCE(adjustment,0)) AS charges,
                SUM(COALESCE(paid_amount,0))         AS paid
         FROM k30_ti_billing
         WHERE client_id=? AND year=? AND status IN ('issued','paid')
         GROUP BY month", [$client_id, $year]) as $r) {
        $m = (int)$r['month'];
        if ($m >= 1 && $m <= 12) { $months[$m]['charges'] = round((float)$r['charges'], 2); $months[$m]['paid'] = round((float)$r['paid'], 2); }
    }
    foreach (db_all(
        "SELECT CAST(strftime('%m', COALESCE(paid_at, created_at)) AS INTEGER) AS m, SUM(amount) AS s
         FROM k30_ti_payments
         WHERE client_id=? AND strftime('%Y', COALESCE(paid_at, created_at))=?
         GROUP BY m", [$client_id, (string)$year]) as $r) {
        $m = (int)$r['m'];
        if ($m >= 1 && $m <= 12) $months[$m]['payments'] = round((float)$r['s'], 2);
    }

    $tot = ['charges' => 0.0, 'paid' => 0.0, 'payments' => 0.0];
    foreach ($months as $mm) { $tot['charges'] += $mm['charges']; $tot['paid'] += $mm['paid']; $tot['payments'] += $mm['payments']; }
    return ['months' => $months, 'totals' => $tot];
}
