<?php
/**
 * karty30/ti/dydaktyk/planner_ajax.php — AJAX endpoint dla SZO Planner.
 *
 * POST akcje (pole `action`):
 *   block_save        — utwórz / zaktualizuj blok biblioteki
 *   block_delete      — usuń blok biblioteki
 *   schedule_create   — utwórz nowy harmonogram
 *   schedule_save     — zapisz stan dni harmonogramu (JSON payload)
 *   schedule_delete   — usuń harmonogram
 */
define('SKIP_CONSENT_CHECK', true);
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$me  = dyd_require();
$uid = (int)$me['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'POST required']);
    exit;
}

dyd_token_check();

$action = trim($_POST['action'] ?? '');

function planner_ok(array $data = [], string $msg = ''): never {
    echo json_encode(['ok' => true, 'msg' => $msg] + $data);
    exit;
}

function planner_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

switch ($action) {

    /* ── Bloki ─────────────────────────────────────────────────────── */

    case 'block_save': {
        $id    = (int)($_POST['block_id'] ?? 0) ?: null;
        $title = trim($_POST['title'] ?? '');
        if ($title === '') planner_err('Podaj nazwę bloku.');

        // Upewnij się, że blok należy do tego prowadzącego
        if ($id) {
            $existing = szo_block_get($id);
            if (!$existing || (int)$existing['instructor_id'] !== $uid) planner_err('Brak dostępu.', 403);
        }

        $new_id = szo_block_save([
            'instructor_id'   => $uid,
            'title'           => $title,
            'category'        => $_POST['category'] ?? 'workshop',
            'duration_min'    => $_POST['duration_min'] ?? 60,
            'difficulty'      => $_POST['difficulty'] ?? 2,
            'energy_impact'   => $_POST['energy_impact'] ?? 0,
            'min_break_after' => $_POST['min_break_after'] ?? 0,
            'notes'           => $_POST['notes'] ?? '',
            'locked'          => !empty($_POST['locked']),
            'tags'            => array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))),
        ], $id);

        $block = szo_block_get($new_id);
        planner_ok(['block' => $block], $id ? 'Blok zaktualizowany.' : 'Blok dodany do biblioteki.');
    }

    case 'block_delete': {
        $id = (int)($_POST['block_id'] ?? 0);
        $b  = $id ? szo_block_get($id) : null;
        if (!$b || (int)$b['instructor_id'] !== $uid) planner_err('Brak bloku.', 404);
        szo_block_delete($id, $uid);
        planner_ok([], 'Blok usunięty.');
    }

    /* ── Harmonogramy ──────────────────────────────────────────────── */

    case 'schedule_create': {
        $title     = trim($_POST['title'] ?? '');
        $num_days  = max(2, min(7, (int)($_POST['num_days'] ?? 3)));
        $course_id = ((int)($_POST['course_id'] ?? 0)) ?: null;
        if ($title === '') planner_err('Podaj tytuł harmonogramu.');
        $sid      = szo_schedule_create($uid, $title, $num_days, $course_id);
        $schedule = szo_schedule_get($sid, $uid);
        planner_ok(['schedule' => $schedule], 'Harmonogram utworzony.');
    }

    case 'schedule_save': {
        $sid  = (int)($_POST['schedule_id'] ?? 0);
        $raw  = $_POST['days_json'] ?? '[]';
        $days = json_decode($raw, true);
        if (!is_array($days)) planner_err('Nieprawidłowy format danych.');
        if (!szo_schedule_save_days($sid, $uid, $days)) planner_err('Brak harmonogramu.', 404);
        planner_ok([], 'Zapisano.');
    }

    case 'schedule_delete': {
        $sid = (int)($_POST['schedule_id'] ?? 0);
        if (!db_one("SELECT id FROM k30_szo_schedules WHERE id=? AND instructor_id=?", [$sid, $uid])) {
            planner_err('Brak harmonogramu.', 404);
        }
        szo_schedule_delete($sid, $uid);
        planner_ok([], 'Harmonogram usunięty.');
    }

    /* ── Solver (Python engine) ───────────────────────────────────────── */

    case 'schedule_solve': {
        $sid  = (int)($_POST['schedule_id'] ?? 0);
        $mode = in_array($_POST['mode'] ?? 'auto', ['auto', 'mpp'], true)
                ? ($_POST['mode'] ?? 'auto') : 'auto';

        $schedule = szo_schedule_get($sid, $uid);
        if (!$schedule) planner_err('Brak harmonogramu.', 404);

        $blocks = szo_blocks_list($uid);
        if (!$blocks) planner_err('Biblioteka bloków jest pusta — dodaj bloki przed Auto-Planem.');

        // ── Dostępność prowadzącego → okno dzienne ──────────────────
        // Uwzględnij dostępność prowadzącego z modułu TI.
        // Jeśli harmonogram powiązany z kursem, sprawdź instruktora kursu.
        $instr_id  = $uid;
        $course_id = (int)($schedule['course_id'] ?? 0);
        if ($course_id) {
            $course_instr = (int)(db_one(
                "SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id]
            )['instructor_id'] ?? 0);
            if ($course_instr) $instr_id = $course_instr;
        }

        $avail = ti_instructor_availability($instr_id);
        $start_min = 480;   // domyślnie 08:00
        $end_min   = 1020;  // domyślnie 17:00
        if ($avail) {
            $starts = array_map(fn($a) => ti_hm2min($a['time_from']), $avail);
            $ends   = array_map(fn($a) => ti_hm2min($a['time_to']),   $avail);
            $start_min = min($starts) ?: 480;
            $end_min   = max($ends)   ?: 1020;
        }
        $cfg_max  = (int)szo_setting('szo_max_daily_minutes', 480);
        $raw_max  = max(60, $end_min - $start_min - 60); // minus 60 min przerwa obiadowa
        $max_min  = min($raw_max, $cfg_max);              // nie przekraczaj ustawienia admina

        // ── Liczba zaplanowanych zajęć z grupą (ostatnie 4 tygodnie) ──
        $load_stats = ['sessions' => 0, 'sessions_per_week' => 0, 'committed_min' => 0];
        if ($course_id) {
            $load = db_one(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(duration_min), 0) AS total_min
                 FROM k30_ti_sessions
                 WHERE course_id=? AND status IN ('planned','held')
                   AND lesson_date >= date('now') AND lesson_date <= date('now','+28 days')",
                [$course_id]
            );
            $load_stats['sessions']         = (int)($load['cnt'] ?? 0);
            $load_stats['sessions_per_week']= (int)ceil(($load['cnt'] ?? 0) / 4);
            $load_stats['committed_min']    = (int)($load['total_min'] ?? 0);
        }

        // ── Payload dla Python engine ────────────────────────────────
        $idx_blocks = [];
        foreach ($blocks as $b) { $idx_blocks[$b['id']] = $b; }

        $payload_blocks = array_values(array_map(fn($b) => [
            'id'              => (int)$b['id'],
            'title'           => $b['title'],
            'category'        => $b['category'],
            'duration_min'    => (int)$b['duration_min'],
            'difficulty'      => (int)$b['difficulty'],
            'energy_impact'   => (int)$b['energy_impact'],
            'min_break_after' => (int)$b['min_break_after'],
            'tags'            => is_array($b['tags']) ? $b['tags'] : (json_decode($b['tags'] ?? '[]', true) ?: []),
            'locked'          => (bool)$b['locked'],
        ], $blocks));

        $payload_days = array_values(array_map(fn($d) => [
            'day_number'  => (int)$d['day_number'],
            'phase'       => $d['phase'] ?: 'foundation',
            'max_minutes' => $max_min,
        ], $schedule['days']));

        // MPP: rekonstruuj istniejące sloty z aktualnego block_order
        $existing_slots = [];
        if ($mode === 'mpp') {
            foreach ($schedule['days'] as $d) {
                $cursor = $start_min;
                foreach (($d['block_order'] ?? []) as $bid) {
                    $bid = (int)$bid;
                    $blk = $idx_blocks[$bid] ?? null;
                    if (!$blk) continue;
                    $dur = (int)$blk['duration_min'];
                    $existing_slots[] = [
                        'block_id'   => $bid,
                        'day_number' => (int)$d['day_number'],
                        'start_min'  => $cursor,
                        'locked'     => (bool)$blk['locked'],
                    ];
                    $cursor += $dur + (int)($blk['min_break_after'] ?? 0);
                }
            }
        }

        $payload = [
            'blocks'          => $payload_blocks,
            'days'            => $payload_days,
            'daily_settings'  => [
                'start_min'   => $start_min,
                'end_min'     => $end_min,
                'max_minutes' => $max_min,
                'lunch_start' => min($start_min + 210, (int)(($start_min + $end_min) / 2)),
                'lunch_dur'   => 60,
            ],
            'existing_slots'  => $existing_slots,
            'mode'            => $mode,
        ];

        // ── Wywołaj Python engine ────────────────────────────────────
        if (!defined('SZOPLANNER_ENGINE')) {
            planner_err('Silnik SZO nie jest skonfigurowany (stała SZOPLANNER_ENGINE).', 503);
        }
        $ctx = stream_context_create(['http' => [
            'method'          => 'POST',
            'header'          => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout'         => 30,
            'ignore_errors'   => true,
        ]]);
        $raw = @file_get_contents(SZOPLANNER_ENGINE . '/solve', false, $ctx);
        if ($raw === false) {
            planner_err('Silnik SZO niedostępny (' . SZOPLANNER_ENGINE . '). Sprawdź czy działa.', 503);
        }
        $result = json_decode($raw, true);
        if (!$result || !isset($result['slots'])) {
            $engine_err = $result['detail'] ?? $result['error'] ?? $result['message'] ?? 'Nieznany błąd silnika';
            planner_err('Błąd silnika: ' . $engine_err, 502);
        }

        // ── Zapisz wynik: block_order per dzień ─────────────────────
        $days_new = [];
        foreach ($schedule['days'] as $d) {
            $dn = (int)$d['day_number'];
            $day_slots = array_filter($result['slots'], fn($s) => (int)$s['day_number'] === $dn);
            usort($day_slots, fn($a, $b) => $a['start_min'] <=> $b['start_min']);
            $days_new[] = [
                'day_number'  => $dn,
                'phase'       => $d['phase'],
                'block_order' => array_values(array_map(fn($s) => (int)$s['block_id'], $day_slots)),
            ];
        }
        szo_schedule_save_days($sid, $uid, $days_new);
        $schedule_upd = szo_schedule_get($sid, $uid);

        planner_ok([
            'schedule'        => $schedule_upd,
            'score'           => $result['score']      ?? null,
            'violations'      => $result['violations'] ?? [],
            'placed'          => $result['placed']     ?? 0,
            'unplaced'        => $result['unplaced']   ?? 0,
            'mpp_moves'       => $result['mpp_moves']  ?? null,
            'avail_window'    => ['start_min' => $start_min, 'end_min' => $end_min, 'max_min' => $max_min],
            'load_stats'      => $load_stats,
        ], 'Auto-Plan wygenerowany przez silnik SZO.');
    }

    /* ── Wrzuć harmonogram do SZO jako szkice ─────────────────────────── */

    case 'schedule_push': {
        $sid       = (int)($_POST['schedule_id'] ?? 0);
        $course_id = (int)($_POST['course_id']   ?? 0);
        $dates_raw = $_POST['day_dates'] ?? '{}';          // JSON: {day_number: "YYYY-MM-DD", ...}
        $day_dates = json_decode($dates_raw, true) ?: [];

        $schedule = szo_schedule_get($sid, $uid);
        if (!$schedule) planner_err('Brak harmonogramu.', 404);
        if (!$course_id) planner_err('Podaj grupę, do której wrzucić zajęcia.');

        // Sprawdź dostęp do kursu
        if (!db_one("SELECT id FROM k30_ti_courses WHERE id=? AND status!='cancelled'", [$course_id])) {
            planner_err('Nie znaleziono grupy.', 404);
        }

        // Okno dostępności → godzina startu lekcji
        $instr_id  = $uid;
        $c_instr = (int)(db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id])['instructor_id'] ?? 0);
        if ($c_instr) $instr_id = $c_instr;

        $avail     = ti_instructor_availability($instr_id);
        $start_min = 480;
        if ($avail) {
            $starts = array_map(fn($a) => ti_hm2min($a['time_from']), $avail);
            $start_min = min($starts) ?: 480;
        }

        // Załaduj bloki
        $blocks = szo_blocks_list($uid);
        $idx_blocks = [];
        foreach ($blocks as $b) { $idx_blocks[$b['id']] = $b; }

        // Usuń istniejące szkice dla tego harmonogramu w tym kursie
        db_exec(
            "DELETE FROM k30_ti_sessions WHERE course_id=? AND status='draft'
             AND notes LIKE '%[szo_sid:" . ((int)$sid) . "]%'",
            [$course_id]
        );

        $created = 0;
        foreach ($schedule['days'] as $day) {
            $dn  = (int)$day['day_number'];
            $dt  = trim($day_dates[(string)$dn] ?? $day_dates[$dn] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) continue;

            $cursor = $start_min;
            foreach (($day['block_order'] ?? []) as $bid) {
                $bid = (int)$bid;
                $blk = $idx_blocks[$bid] ?? null;
                if (!$blk) continue;

                $dur  = (int)$blk['duration_min'];
                $from = sprintf('%02d:%02d', intdiv($cursor, 60), $cursor % 60);
                $to   = sprintf('%02d:%02d', intdiv($cursor + $dur, 60), ($cursor + $dur) % 60);

                db_exec(
                    "INSERT INTO k30_ti_sessions
                     (course_id, lesson_date, time_from, time_to, duration_min, status,
                      topic, block_type, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [
                        $course_id, $dt, $from, $to, $dur, 'draft',
                        $blk['title'],
                        $blk['category'] === 'theory'    ? 'theory'
                         : ($blk['category'] === 'workshop' ? 'practical' : 'theory'),
                        '[szo_sid:' . $sid . '] [blk:' . $bid . '] ' . ($blk['notes'] ?? ''),
                        $uid,
                    ]
                );
                $created++;

                $cursor += $dur + (int)($blk['min_break_after'] ?? 0);
                // Przerwa obiadowa (12:30) — pomiń lunch window 30 min
                if ($cursor <= 750 && $cursor + $dur > 750) $cursor = 810;
            }
        }

        planner_ok(['created' => $created], "Wrzucono {$created} zajęć jako Szkice do grupy.");
    }

    /* ── Tygodniowy plan cykliczny ───────────────────────────────────────────── */

    case 'weekly_slot_save': {
        $slot_id     = (int)($_POST['slot_id']     ?? 0);
        $course_id   = (int)($_POST['course_id']   ?? 0);
        $dow         = (int)($_POST['day_of_week'] ?? 0);
        $time_from   = substr(trim($_POST['time_from']   ?? '09:00'), 0, 5);
        $duration    = max(15, min(480, (int)($_POST['duration_min'] ?? 90)));
        $status      = in_array($_POST['status'] ?? '', ['draft','approved'], true)
                       ? $_POST['status'] : 'draft';
        $notes       = substr(trim($_POST['notes'] ?? ''), 0, 200);

        if (!$course_id) planner_err('Wybierz kurs.');
        if (!db_one("SELECT id FROM k30_ti_courses WHERE id=? AND instructor_id=?", [$course_id, $uid]))
            planner_err('Kurs nie należy do Ciebie.');

        // Sprawdź dzienny limit (nie przekraczaj TI_WEEKLY_MAX_MIN)
        $day_sum = (int)(db_one(
            "SELECT COALESCE(SUM(duration_min),0) AS s FROM k30_ti_weekly_plan
             WHERE instructor_id=? AND day_of_week=?" . ($slot_id ? " AND id!=?" : ""),
            $slot_id ? [$uid, $dow, $slot_id] : [$uid, $dow]
        )['s'] ?? 0);
        if ($day_sum + $duration > TI_WEEKLY_MAX_MIN)
            planner_err("Przekroczony dzienny limit ({$day_sum}+{$duration} > " . TI_WEEKLY_MAX_MIN . " min).");

        if ($slot_id) {
            // Edycja istniejącego
            if (!db_one("SELECT id FROM k30_ti_weekly_plan WHERE id=? AND instructor_id=?", [$slot_id, $uid]))
                planner_err('Slot nie istnieje.');
            db_exec(
                "UPDATE k30_ti_weekly_plan SET course_id=?, day_of_week=?, time_from=?,
                 duration_min=?, status=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$course_id, $dow, $time_from, $duration, $status, $notes, $slot_id]
            );
            planner_ok(['id' => $slot_id], 'Slot zaktualizowany.');
        }
        $id = ti_weekly_plan_save($uid, $course_id, $dow, $time_from, $duration, $status, $notes);
        planner_ok(['id' => $id], 'Slot zapisany.');
    }

    case 'weekly_slot_delete': {
        $slot_id = (int)($_POST['slot_id'] ?? 0);
        if (!$slot_id) planner_err('Brak ID slotu.');
        ti_weekly_plan_delete($slot_id, $uid);
        planner_ok([], 'Slot usunięty.');
    }

    case 'weekly_slot_status': {
        $slot_id = (int)($_POST['slot_id'] ?? 0);
        $status  = in_array($_POST['status'] ?? '', ['draft','approved'], true)
                   ? $_POST['status'] : 'draft';
        if (!$slot_id) planner_err('Brak ID slotu.');
        ti_weekly_plan_set_status($slot_id, $uid, $status);
        planner_ok([], 'Status zmieniony.');
    }

    /* Planner godzin → lekcje: jedyne miejsce, które zamienia wzorzec tygodnia
       na wpisy w k30_ti_sessions (patrz ti_weekly_plan_generate). */
    case 'weekly_generate': {
        $from       = trim((string)($_POST['date_from'] ?? ''));
        $to         = trim((string)($_POST['date_to']   ?? ''));
        $course_id  = (int)($_POST['course_id'] ?? 0);
        $only_appr  = ($_POST['only_approved'] ?? '1') !== '0';
        $skip_off   = ($_POST['skip_off_days'] ?? '1') !== '0';
        $status     = ($_POST['status'] ?? 'planned') === 'draft' ? 'draft' : 'planned';

        if ($course_id && !db_one("SELECT id FROM k30_ti_courses WHERE id=? AND instructor_id=?", [$course_id, $uid])) {
            planner_err('Kurs nie należy do Ciebie.', 403);
        }
        try {
            $r = ti_weekly_plan_generate($uid, $from, $to, [
                'only_approved' => $only_appr,
                'course_id'     => $course_id,
                'skip_off_days' => $skip_off,
                'status'        => $status,
            ]);
        } catch (\Throwable $e) {
            planner_err($e->getMessage());
        }
        if (!$r['slots']) {
            planner_err($only_appr
                ? 'Brak zatwierdzonych slotów w plannerze godzin — zatwierdź slajdy albo odznacz „tylko zatwierdzone”.'
                : 'Planner godzin jest pusty — dodaj najpierw slajdy tygodnia.');
        }
        planner_ok($r, ti_weekly_plan_generate_msg($r));
    }

    case 'avail_set_status': {
        $avail_id = (int)($_POST['avail_id'] ?? 0);
        $status   = in_array($_POST['status'] ?? '', ['draft','approved'], true)
                    ? $_POST['status'] : 'approved';
        if (!$avail_id) planner_err('Brak ID dostępności.');
        ti_avail_set_status($avail_id, $uid, $status);
        planner_ok([], 'Status dostępności zmieniony.');
    }

    default:
        planner_err('Nieznana akcja.');
}
