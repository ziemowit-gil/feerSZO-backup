<?php
/**
 * karty30/ti/dydaktyk/planistaAjax.php — AJAX planisty zajęć (kierownik).
 *
 * GET  action=feed&from=&to=         — lekcje + zasoby zakresu (JSON)
 * POST action=move                   — przesunięcie lekcji (data/godziny/trener/sala)
 * POST action=state                  — potwierdzenia: client|instructor (toggle)
 * POST action=check                  — sama walidacja terminu (bez zapisu)
 * Scenariusze „co jeśli": feed&scenario=ID, move+scenario=ID (zapis do nakładki), scn_create/scn_discard/scn_diff/scn_publish
 * Auto-dopasowanie: match (session_id), find_slots (course_id,dur,from,to), add_lesson
 * Zapis wymaga tokenu dyd_token (pole _token lub nagłówek X-CSRF-Token).
 */
define('SKIP_CONSENT_CHECK', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerFeed.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerScenarios.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerMatch.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerSeries.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$me  = dyd_require();
$uid = (int)$me['user_id'];
if (!dyd_is_staff()) { http_response_code(403); echo json_encode(['ok' => false, 'msg' => 'Brak uprawnień.']); exit; }
ti_sched_migrate();

function sc_out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'feed') {
    $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) sc_out(['ok' => false, 'msg' => 'Zły zakres dat.'], 400);
    if ((strtotime($to) - strtotime($from)) > 62 * 86400) sc_out(['ok' => false, 'msg' => 'Zakres za duży.'], 400);
    $scn = (int)($_GET['scenario'] ?? 0);
    if ($scn && !sc_scenario_open($scn)) $scn = 0;
    sc_out(['ok' => true, 'scenario' => $scn, 'scenarios' => sc_scenarios_list()] + sc_feed($from, $to, $scn));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sc_out(['ok' => false, 'msg' => 'POST required'], 405);
dyd_token_check();

if ($action === 'scn_create') {
    $id = sc_scenario_create((string)($_POST['title'] ?? ''), (string)($_POST['note'] ?? ''), $uid);
    sc_out(['ok' => true, 'id' => $id]);
}
if (in_array($action, ['scn_discard', 'scn_diff', 'scn_publish'], true)) {
    $scn = (int)($_POST['scenario'] ?? 0);
    if (!sc_scenario_open($scn)) sc_out(['ok' => false, 'msg' => 'Scenariusz nie jest otwarty.'], 404);
    if ($action === 'scn_discard') { sc_scenario_discard($scn); sc_out(['ok' => true]); }
    if ($action === 'scn_diff') sc_out(['ok' => true, 'diff' => sc_scenario_diff($scn)]);
    $r = sc_scenario_publish($scn, $uid, !empty($_POST['force']), !empty($_POST['notify']));
    sc_out($r, $r['ok'] || !empty($r['needs_ack']) ? 200 : 409);
}
if (in_array($action, ['series_preview', 'series_apply'], true)) {
    $o = ['course_id' => (int)($_POST['course_id'] ?? 0), 'profile_id' => (int)($_POST['profile_id'] ?? 0), 'total_hours' => (float)str_replace(',', '.', (string)($_POST['total_hours'] ?? 0)),
          'hour_min' => (int)($_POST['hour_min'] ?? 45), 'start_date' => (string)($_POST['start_date'] ?? ''), 'instructor_id' => (int)($_POST['instructor_id'] ?? 0), 'room_id' => (int)($_POST['room_id'] ?? 0)];
    if ($msg = ti_course_closed_guard(['course_id' => $o['course_id']])) sc_out(['ok' => false, 'msg' => $msg], 423);
    sc_out($action === 'series_preview' ? sc_series_preview($o) : sc_series_apply($o, $uid));
}
if (in_array($action, ['cycle_preview', 'cycle_apply'], true)) {
    $o = ['course_id' => (int)($_POST['course_id'] ?? 0), 'start_date' => (string)($_POST['start_date'] ?? ''), 'time_from' => (string)($_POST['time_from'] ?? ''),
          'hours' => (float)str_replace(',', '.', (string)($_POST['hours'] ?? 0)), 'hour_min' => (int)($_POST['hour_min'] ?? 45), 'interval' => (int)($_POST['interval'] ?? 1),
          'unit' => (string)($_POST['unit'] ?? 'week'), 'weekdays' => (array)($_POST['weekdays'] ?? []), 'end_mode' => (string)($_POST['end_mode'] ?? 'count'), 'end_value' => (string)($_POST['end_value'] ?? ''),
          'holiday_mode' => (string)($_POST['holiday_mode'] ?? 'skip'), 'instructor_id' => (int)($_POST['instructor_id'] ?? 0), 'room_id' => (int)($_POST['room_id'] ?? 0)];
    if ($msg = ti_course_closed_guard(['course_id' => $o['course_id']])) sc_out(['ok' => false, 'msg' => $msg], 423);
    sc_out($action === 'cycle_preview' ? sc_cycle_preview($o) : sc_cycle_apply($o, $uid));
}
if ($action === 'find_slots') {
    $cid = (int)($_POST['course_id'] ?? 0);
    if (!$cid) sc_out(['ok' => false, 'msg' => 'Wybierz grupę.'], 400);
    sc_out(['ok' => true, 'slots' => sc_find_slots($cid, (int)($_POST['dur'] ?? 90), (string)($_POST['from'] ?? date('Y-m-d')), (string)($_POST['to'] ?? date('Y-m-d', strtotime('+14 days'))), !empty($_POST['any']), !empty($_POST['weekends']))]);
}
if ($action === 'add_lesson') {
    $cid = (int)($_POST['course_id'] ?? 0); $date = (string)($_POST['date'] ?? ''); $tf = substr((string)($_POST['time_from'] ?? ''), 0, 5); $tt = substr((string)($_POST['time_to'] ?? ''), 0, 5);
    $iid = (int)($_POST['instructor_id'] ?? 0); $room = (int)($_POST['room_id'] ?? 0);
    if ($msg = ti_course_closed_guard(['course_id' => $cid])) sc_out(['ok' => false, 'msg' => $msg], 423);
    $c = db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$cid]);
    if (!$c) sc_out(['ok' => false, 'msg' => 'Nie znaleziono grupy.'], 404);
    $own = ($iid && $iid !== (int)$c['instructor_id']) ? $iid : 0;
    $res = sc_validate(['course_id' => $cid, 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt, 'instructor_id' => $own, 'room_id' => $room]);
    if ($res['hard']) sc_out(['ok' => false, 'blocked' => true, 'hard' => $res['hard'], 'soft' => $res['soft'], 'msg' => $res['hard'][0]['msg']], 409);
    if ($res['soft'] && empty($_POST['force'])) sc_out(['ok' => false, 'needs_ack' => true, 'hard' => [], 'soft' => $res['soft'], 'msg' => 'Termin wymaga potwierdzenia.'], 409);
    $dur = ti_hm2min($tt) - ti_hm2min($tf);
    $new = db_insert('k30_ti_sessions', ['course_id' => $cid, 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt, 'duration_min' => $dur,
        'status' => 'planned', 'instructor_id' => $own ?: null, 'room_id' => $room ?: null, 'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
    pl_audit('session', (int)$new, 'planista_add', [], ['date' => $date, 'from' => $tf, 'to' => $tt], $uid);
    sc_out(['ok' => true, 'id' => (int)$new, 'msg' => 'Dodano lekcję.']);
}

$sid = (int)($_POST['session_id'] ?? 0);
$s = db_one("SELECT s.*, c.instructor_id AS c_instr FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$sid]);
if (!$s) sc_out(['ok' => false, 'msg' => 'Nie znaleziono lekcji.'], 404);
if ($msg = ti_course_closed_guard(['session_id' => $sid])) sc_out(['ok' => false, 'msg' => $msg], 423);

if ($action === 'match') sc_out(['ok' => true, 'matches' => sc_match_session($sid)]);

if ($action === 'state') {
    $which = (string)($_POST['which'] ?? '');
    if (!in_array($which, ['client', 'instructor'], true)) sc_out(['ok' => false, 'msg' => 'Nieznany rodzaj potwierdzenia.'], 400);
    $col = $which === 'client' ? 'client' : 'instr';
    $cur = db_one("SELECT * FROM k30_sc_session_state WHERE session_id=?", [$sid]);
    $on  = empty($cur[$col . '_confirmed_at']);
    if (!$cur) db_exec("INSERT INTO k30_sc_session_state (session_id) VALUES (?)", [$sid]);
    db_exec("UPDATE k30_sc_session_state SET {$col}_confirmed_at=?, {$col}_confirmed_by=? WHERE session_id=?",
        [$on ? date('Y-m-d H:i:s') : null, $on ? $uid : null, $sid]);
    pl_audit('session', $sid, 'confirm_' . $which, [], ['on' => $on], $uid);
    sc_out(['ok' => true, 'on' => $on]);
}

if ($action === 'move' || $action === 'check') {
    if (!sc_session_movable((string)$s['status'])) sc_out(['ok' => false, 'msg' => 'Lekcja odbyta lub odwołana — nie można jej przesuwać.'], 409);

    $date = (string)($_POST['date'] ?? $s['lesson_date']);
    $tf   = substr((string)($_POST['time_from'] ?? $s['time_from']), 0, 5);
    $tt   = substr((string)($_POST['time_to'] ?? $s['time_to']), 0, 5);
    $iid_in = array_key_exists('instructor_id', $_POST) ? max(0, (int)$_POST['instructor_id']) : (int)($s['instructor_id'] ?? 0);
    // Zastępstwo zapisujemy tylko gdy trener różni się od prowadzącego kursu (inaczej dziedziczy).
    $own_instr = ($iid_in && $iid_in !== (int)$s['c_instr']) ? $iid_in : 0;
    $room = array_key_exists('room_id', $_POST) ? max(0, (int)$_POST['room_id']) : (int)($s['room_id'] ?? 0);

    $res = sc_validate(['course_id' => (int)$s['course_id'], 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt,
                        'instructor_id' => $own_instr, 'room_id' => $room, 'skip_id' => $sid]);
    $hard_fixed = array_values(array_filter($res['hard'], fn($h) => empty($h['overridable'])));
    $force = !empty($_POST['force']);

    if ($action === 'check') sc_out(['ok' => !$res['hard'], 'hard' => $res['hard'], 'soft' => $res['soft']]);
    $scn = (int)($_POST['scenario'] ?? 0);
    if ($scn && !sc_scenario_open($scn)) sc_out(['ok' => false, 'msg' => 'Scenariusz nie jest już otwarty.'], 404);

    if ($hard_fixed) sc_out(['ok' => false, 'blocked' => true, 'hard' => $res['hard'], 'soft' => $res['soft'], 'msg' => $hard_fixed[0]['msg']], 409);
    if (($res['hard'] || $res['soft']) && !$force) {
        sc_out(['ok' => false, 'needs_ack' => true, 'hard' => $res['hard'], 'soft' => $res['soft'],
                'msg' => 'Termin wymaga potwierdzenia.'], 409);
    }

    if ($scn) {   // zapis do nakładki scenariusza — plan oficjalny bez zmian
        sc_scenario_set_change($scn, $sid, $date, $tf, $tt, $iid_in ?: (int)$s['c_instr'], $room);
        sc_out(['ok' => true, 'msg' => 'Zapisano w scenariuszu.']);
    }

    $before = ['date' => $s['lesson_date'], 'from' => $s['time_from'], 'to' => $s['time_to'],
               'instructor_id' => $s['instructor_id'], 'room_id' => $s['room_id']];
    $old = null;
    if ($date !== (string)$s['lesson_date'] || $tf !== substr((string)$s['time_from'], 0, 5) || $tt !== substr((string)$s['time_to'], 0, 5)) {
        $old = k30_ti_do_reschedule($sid, $date, $tf, $tt);
    }
    db_exec("UPDATE k30_ti_sessions SET instructor_id=?, room_id=?, updated_at=datetime('now') WHERE id=?",
        [$own_instr ?: null, $room ?: null, $sid]);
    // Zmiana terminu cofa potwierdzenia — ustalenia dotyczyły starego terminu.
    if ($old) db_exec("DELETE FROM k30_sc_session_state WHERE session_id=?", [$sid]);
    pl_audit('session', $sid, 'planista_move', $before,
        ['date' => $date, 'from' => $tf, 'to' => $tt, 'instructor_id' => $own_instr ?: null, 'room_id' => $room ?: null, 'forced' => $force], $uid);
    if ($old && !empty($_POST['notify'])) { try { k30_ti_reschedule_notify_parties($sid, $old, false); } catch (\Throwable $e) {} }
    sc_out(['ok' => true, 'msg' => 'Zapisano.']);
}

sc_out(['ok' => false, 'msg' => 'Nieznana akcja.'], 400);
