<?php
/**
 * karty30/ti/dydaktyk/planistaAjax.php — AJAX planisty zajęć (kierownik).
 *
 * GET  action=feed&from=&to=         — lekcje + zasoby zakresu (JSON)
 * POST action=move                   — przesunięcie lekcji (data/godziny/trener/sala)
 * POST action=state                  — potwierdzenia: client|instructor (toggle)
 * POST action=check                  — sama walidacja terminu (bez zapisu)
 * Zapis wymaga tokenu dyd_token (pole _token lub nagłówek X-CSRF-Token).
 */
define('SKIP_CONSENT_CHECK', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerFeed.php';

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
    sc_out(['ok' => true] + sc_feed($from, $to));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sc_out(['ok' => false, 'msg' => 'POST required'], 405);
dyd_token_check();

$sid = (int)($_POST['session_id'] ?? 0);
$s = db_one("SELECT s.*, c.instructor_id AS c_instr FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$sid]);
if (!$s) sc_out(['ok' => false, 'msg' => 'Nie znaleziono lekcji.'], 404);
if ($msg = ti_course_closed_guard(['session_id' => $sid])) sc_out(['ok' => false, 'msg' => $msg], 423);

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

    if ($hard_fixed) sc_out(['ok' => false, 'blocked' => true, 'hard' => $res['hard'], 'soft' => $res['soft'], 'msg' => $hard_fixed[0]['msg']], 409);
    if (($res['hard'] || $res['soft']) && !$force) {
        sc_out(['ok' => false, 'needs_ack' => true, 'hard' => $res['hard'], 'soft' => $res['soft'],
                'msg' => 'Termin wymaga potwierdzenia.'], 409);
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
