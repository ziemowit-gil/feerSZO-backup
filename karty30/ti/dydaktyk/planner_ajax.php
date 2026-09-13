<?php
/**
 * karty30/ti/dydaktyk/planner_ajax.php — AJAX endpoint dla tygodniowego planu
 * cyklicznego (zakładka "Plan cykliczny" / _tab_cykliczne.php).
 *
 * POST akcje (pole `action`):
 *   weekly_slot_save    — utwórz / zaktualizuj slot tygodniowego wzorca
 *   weekly_slot_delete  — usuń slot
 *   weekly_slot_status  — zmień status slotu (draft/approved)
 *   weekly_generate     — wygeneruj zajęcia z wzorca tygodnia w zakresie dat
 *   avail_set_status    — zmień status wpisu dostępności
 *
 * Uwaga: to NIE jest już endpoint narzędzia "SZO Planner" (bloki/harmonogramy/
 * solver) — ten moduł został usunięty razem z includes/ti_planner.php.
 */
define('SKIP_CONSENT_CHECK', true);
require_once __DIR__ . '/auth.php';

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
