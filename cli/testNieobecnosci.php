<?php
/**
 * cli/testNieobecnosci.php — hermetyczny test nieobecności prowadzących TI (godziny, grupy, odwoływanie,
 * nowe terminy, przywracanie, powiadomienia e-mail/SMS).
 *
 *   php cli/testNieobecnosci.php            # pełny test
 *   SZO_TEST_DB=/ścieżka/baza.db php …      # inna baza wzorcowa (domyślnie umowy.db)
 *
 * Działa na KOPII bazy w katalogu tymczasowym: ustawienia wysyłki są podmieniane na atrapy (cli/tests/fakegw.py,
 * 127.0.0.1), wszystkie dane testowe mają adresy @example.invalid. Po teście sprawdza, czy żaden e-mail ani SMS
 * nie poszedł poza adresy/numery testowe, i czy prawdziwa baza nie została zmieniona. Kod wyjścia 0 = wszystko OK.
 * Wewnętrznie ponownie uruchamia siebie w osobnych procesach (--child=…): DB_PATH i strony z exit() wymagają procesu.
 */
const HTTP_PORT = 18771, SMTP_PORT = 18772;
$self = __FILE__; $root = dirname(__DIR__);

// ───────────────────────── proces nadrzędny ─────────────────────────
if (!isset($argv[1]) || !str_starts_with($argv[1], '--child=')) {
    $src = getenv('SZO_TEST_DB') ?: $root . '/umowy.db';
    if (!is_file($src)) { fwrite(STDERR, "Brak bazy wzorcowej: $src\n"); exit(2); }
    $before = [filesize($src), filemtime($src)];
    $tmp = sys_get_temp_dir() . '/szo_nieob_' . getmypid(); mkdir($tmp, 0700, true);
    $db = $tmp . '/test.db'; copy($src, $db);
    $pdo = new PDO('sqlite:' . $db);
    // Neutralizacja wysyłki — OSOBNE polecenia (błąd jednego nie może pominąć reszty)
    foreach (["DELETE FROM settings WHERE key_ LIKE 'm365%'", "DELETE FROM settings WHERE key_ LIKE 'smtp%'", "DELETE FROM settings WHERE key_ LIKE 'sms%'"] as $q) $pdo->exec($q);
    $ins = $pdo->prepare("INSERT INTO settings(key_,value) VALUES (?,?)");
    foreach (['smtp_host' => '127.0.0.1', 'smtp_port' => (string)SMTP_PORT, 'smtp_encryption' => 'none', 'smtp_from_email' => 't@example.invalid',
              'sms_enabled' => '1', 'sms_provider' => 'httprequest', 'sms_http_method' => 'GET', 'sms_fallback_provider' => '',
              'sms_http_url' => 'http://127.0.0.1:' . HTTP_PORT . '/sms?phone={phone}&text={message}'] as $k => $v) $ins->execute([$k, $v]);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE key_ LIKE 'm365%' OR key_ LIKE 'smtp2%' OR key_ LIKE 'sms_sender%'")->fetchColumn();
    if ($left) { fwrite(STDERR, "Nie wyczyszczono ustawień wysyłki — przerywam.\n"); exit(2); }
    unset($pdo);

    $events = $tmp . '/events.jsonl';
    $gw = proc_open(['python3', __DIR__ . '/tests/fakegw.py', $events, (string)HTTP_PORT, (string)SMTP_PORT], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(800000);
    $fail = 0;
    $run = function (string $mode, string ...$extra) use ($self, $db, &$fail): void {
        $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($self) . ' --child=' . $mode . ' ' . escapeshellarg($db) . ' ' . implode(' ', array_map('escapeshellarg', $extra)) . ' 2>&1', $out, $rc);
        foreach ($out as $l) { if (str_starts_with($l, 'FAIL') || str_starts_with($l, 'PHP ') || str_contains($l, 'Warning:') || str_contains($l, 'Fatal')) { echo $l . "\n"; $fail++; } elseif (getenv('VERBOSE')) echo $l . "\n"; }
        echo str_pad("[$mode]", 22) . implode(' | ', array_filter($out, fn($l) => str_starts_with($l, 'PASS') || str_starts_with($l, 'mode='))) . "\n";
    };
    $run('logic');
    $run('seed');
    foreach (['post_badhours', 'post_save', 'get_conf', 'post_cancel', 'get_conf', 'get_delete', 'post_delete', 'get_rebook'] as $m) $run('page', $m);

    // Strażnik wycieków: tylko adresaci i numery testowe
    foreach (is_file($events) ? file($events, FILE_IGNORE_NEW_LINES) : [] as $l) {
        $o = json_decode($l, true);
        foreach ($o['mail']['to'] ?? [] as $rcpt) if (!str_contains($rcpt, 'example.invalid')) { echo "FAIL wyciek e-maila: $rcpt\n"; $fail++; }
        foreach ((array)($o['sms']['phone'] ?? []) as $ph) if (!preg_match('/^48?6\d{8}$/', (string)$ph) && !preg_match('/^6\d{8}$/', (string)$ph)) { echo "FAIL podejrzany numer SMS: $ph\n"; $fail++; }
    }
    $n_mail = $n_sms = 0; foreach (is_file($events) ? file($events) : [] as $l) { $o = json_decode($l, true); isset($o['mail']) ? $n_mail++ : $n_sms++; }
    echo "Atrapy: e-maile=$n_mail, SMS=$n_sms\n";
    proc_terminate($gw); proc_close($gw);
    array_map('unlink', glob($tmp . '/*')); rmdir($tmp);
    clearstatcache(); if ([filesize($src), filemtime($src)] !== $before) { echo "FAIL baza wzorcowa została zmieniona!\n"; $fail++; }
    echo $fail ? "\nWYNIK: BŁĘDY ($fail)\n" : "\nWYNIK: OK\n";
    exit($fail ? 1 : 0);
}

// ───────────────────────── procesy potomne ─────────────────────────
[$mode, $db] = [substr($argv[1], 8), $argv[2]];
define('DB_PATH', $db); define('SKIP_CONSENT_CHECK', true);
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/x.php'; $_SERVER['SCRIPT_NAME'] = '/x.php'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir($root);
require $root . '/config.php';
if (realpath(DB_PATH) !== realpath($db)) { echo "FAIL zła baza\n"; exit(1); }

function t(string $n, $got, $exp): void { global $ok, $bad; $p = $got === $exp; $p ? $ok++ : $bad++; echo ($p ? 'OK   ' : 'FAIL ') . $n . ($p ? '' : '  got=' . json_encode($got, JSON_UNESCAPED_UNICODE) . ' exp=' . json_encode($exp, JSON_UNESCAPED_UNICODE)) . "\n"; }
$ok = $bad = 0;

/** Dane testowe: prowadzący, 2 grupy z kursantami, lekcje w dniu +$off. Zwraca identyfikatory. */
function seed(string $tag, int $off): array {
    $uI = db_insert('users', ['name' => "Prow $tag", 'email' => "prow_$tag@example.invalid", 'role' => 'editor', 'is_active' => 1, 'k30_consultant' => 1]);
    $d = date('Y-m-d', strtotime("+$off day")); $o = ['instr' => $uI, 'd' => $d, 'courses' => [], 's' => []];
    foreach (['A' => '600100200', 'B' => '600300400'] as $g => $phone) {
        $cid = db_insert('k30_ti_courses', ['name' => "Grupa $g $tag", 'instructor_id' => $uI]);
        $cl = db_insert('k30_clients', ['name' => "Kursant $g $tag", 'email' => "k{$g}_$tag@example.invalid", 'phone' => $phone]);
        db_insert('k30_ti_student_accounts', ['client_id' => $cl, 'login' => "kt{$g}_$tag", 'password_hash' => 'x', 'is_active' => 1, 'notify_sms_lessons' => 1]);
        db_insert('k30_ti_enrollments', ['course_id' => $cid, 'client_id' => $cl, 'status' => 'active']);
        $o['courses'][$g] = $cid; $o['client'][$g] = $cl;
    }
    foreach ([['A', '08:00', '09:00'], ['A', '10:00', '11:00'], ['A', '14:00', '15:00'], ['B', '10:30', '11:30']] as [$g, $f, $to])
        $o['s'][] = db_insert('k30_ti_sessions', ['course_id' => $o['courses'][$g], 'lesson_date' => $d, 'time_from' => $f, 'time_to' => $to, 'duration_min' => 60, 'status' => 'planned']);
    return $o;
}

if ($mode === 'logic') {
    require $root . '/includes/db.php'; require $root . '/includes/functions.php'; require $root . '/includes/karty30.php';
    require $root . '/includes/ti_leaves.php'; require $root . '/modules/ti_scheduler/logic/schedulerWorkflow.php';
    karty30_migrate(); ti_leaves_migrate(); ti_sched_migrate();
    $m = db_insert('users', ['name' => 'Kierownik T', 'email' => 'kier@example.invalid', 'role' => 'editor', 'is_active' => 1, 'phone_number' => '600999888']);
    db()->exec("INSERT INTO k30_ti_panel_roles(user_id,role) VALUES ($m,'kierownik')");
    $X = seed('L', 3); [$uI, $d] = [$X['instr'], $X['d']]; [$a1, $a2, $a3, $b1] = $X['s']; [$cA, $cB] = [$X['courses']['A'], $X['courses']['B']];

    // — godziny nieobecności 09:30–10:45 (lekcja grupy B o 10:30 nakłada się, 08:00–09:00 i 14:00 nie)
    $lid = db_insert('k30_ti_instructor_leaves', ['instructor_id' => $uI, 'date_from' => $d, 'date_to' => $d, 'time_from' => '09:30', 'time_to' => '10:45', 'type' => 'chorobowe', 'created_by' => 1]);
    t('godziny: nakładanie 10:00-11:00', (bool)ti_instructor_on_leave($uI, $d, '10:00', '11:00'), true);
    t('godziny: inna pora 14:00-15:00', (bool)ti_instructor_on_leave($uI, $d, '14:00', '15:00'), false);
    t('godziny: styk 10:45-11:45', (bool)ti_instructor_on_leave($uI, $d, '10:45', '11:45'), false);
    t('godziny: styk z początkiem 08:30-09:30', (bool)ti_instructor_on_leave($uI, $d, '08:30', '09:30'), false);
    t('godziny: bez godzin ≠ cały dzień', (bool)ti_instructor_on_leave($uI, $d), false);
    $av = ti_instructor_available_at($uI, $d, '10:00', '11:00');
    t('dostępność: blokada z godzinami w komunikacie', [$av['ok'], str_contains($av['reason'], '09:30–10:45') && str_contains($av['reason'], 'Chorobowe')], [false, true]);
    t('konflikty godzinowe: 08:00 nie, 10:00 tak, 10:30 (grupa B) tak', array_map('intval', array_column(ti_leave_conflicts(ti_leave_get($lid)), 'id')), [$a2, $b1]);
    $sms = 0; $n = ti_leaves_notify($lid, false, true, $sms);
    t('zapowiedź: e-mail 2 (obie grupy), SMS 2', [$n, $sms], [2, 2]);
    t('zapowiedź idempotentna', ti_leaves_notify($lid, false, true, $sms), 0);
    $res = sc_open_leave_resolution($lid, 1);
    t('rozstrzyganie: 2 otwarte, idempotentne', [$res['items'], $res['open'], sc_open_leave_resolution($lid, 1)['request_id'] === $res['request_id']], [2, 2, true]);

    // — odwołanie z prośbą o kontakt, powiązane z nieobecnością
    $r = ti_absence_cancel_sessions([$a2, $b1], 'Test', 'Admin T', $uI, true, true, true, $lid);
    t('odwołanie: 2 lekcje, 2 e-maile, 2 SMS (zbiorczo per kurs)', [$r['cancelled'], $r['emails'], $r['sms']], [2, 2, 2]);
    t('rebook: 2 do ustalenia, 2 odwołane z nieobecności', [ti_rebook_open_count(), count(ti_leave_cancelled_sessions($lid))], [2, 2]);
    t('ponowne odwołanie pomijane', ti_absence_cancel_sessions([$a2], 'x', 'y')['skipped'], 1);

    // — ustalenie nowego terminu
    $rb = (int)db_one('SELECT id FROM k30_ti_rebook WHERE session_id=?', [$a2])['id'];
    t('rebook: skontaktowano', ti_rebook_set($rb, 'contacted', 'tel', 'Admin T'), true);
    t('rebook: termin w przeszłości odrzucony', ti_rebook_schedule($rb, date('Y-m-d', strtotime('-1 day')), '10:00', '11:00', 'A')['ok'], false);
    t('rebook: zły przedział odrzucony', ti_rebook_schedule($rb, date('Y-m-d', strtotime('+9 day')), '11:00', '10:00', 'A')['ok'], false);
    $d9 = date('Y-m-d', strtotime('+9 day'));
    $sch = ti_rebook_schedule($rb, $d9, '10:00', '11:00', 'Admin T');
    t('rebook: nowy termin ustalony', $sch['ok'], true);
    $s = db_one('SELECT status, lesson_date, rescheduled_from_date FROM k30_ti_sessions WHERE id=?', [$a2]);
    t('rebook: lekcja wróciła w nowym terminie', [$s['status'], $s['lesson_date'], $s['rescheduled_from_date']], ['planned', $d9, $d]);

    // — przywracanie: blokada przy trwającej nieobecności, potem po usunięciu
    $rs = ti_absence_restore_sessions([$b1], 'Admin T');
    t('przywracanie zablokowane (prowadzący nadal nieobecny)', [$rs['restored'], count($rs['blocked'])], [0, 1]);
    $w = ti_leave_withdraw_notify(ti_leave_get($lid), true);
    t('wycofanie nieobecności: e-mail 2 (obie grupy), SMS 0 (lekcje odwołane/przeniesione)', [$w['emails'], $w['sms']], [2, 0]);
    db()->prepare('DELETE FROM k30_ti_instructor_leaves WHERE id=?')->execute([$lid]);
    $rs = ti_absence_restore_sessions([$b1], 'Admin T');
    t('przywracanie po usunięciu: 1, e-mail 1, SMS 1', [$rs['restored'], $rs['emails'], $rs['sms']], [1, 1, 1]);
    t('przywracanie: status i rebook', [db_one('SELECT status FROM k30_ti_sessions WHERE id=?', [$b1])['status'], db_one('SELECT status FROM k30_ti_rebook WHERE session_id=?', [$b1])['status']], ['planned', 'restored']);

    // — zwykłe odwołanie (cause=other) bez prośby o kontakt
    $r = ti_cancel_sessions_notify([$a3], 'Awaria', 'Admin T', ['cause' => 'other']);
    t('odwołanie zwykłe: e-mail 1, SMS 1, rebook info', [$r['emails'], $r['sms'], db_one('SELECT status FROM k30_ti_rebook WHERE session_id=?', [$a3])['status']], [1, 1, 'info']);

    // — pilne SMS do kierownictwa
    $d1 = date('Y-m-d', strtotime('+1 day'));
    t('pilne: jutro → SMS', sc_notify_urgent($uI, $d1, $d1, '', '', 2), 1);
    t('pilne: za 3 dni → brak', sc_notify_urgent($uI, $d, $d, '', '', 2), 0);

    // — grupy
    $lg = db_insert('k30_ti_instructor_leaves', ['instructor_id' => $uI, 'date_from' => $d, 'date_to' => $d, 'course_ids' => (string)$cB, 'type' => 'urlop', 'created_by' => 1]);
    $LG = ti_leave_get($lg);
    t('grupy: sanityzacja (obce odrzucone, wszystkie = brak)', [ti_leave_sanitize_course_ids($uI, [$cA, 99999999]), ti_leave_sanitize_course_ids($uI, [$cA, $cB])], [(string)$cA, '']);
    t('grupy: konflikty tylko grupa B', array_unique(array_map('intval', array_column(ti_leave_conflicts($LG), 'course_id'))), [(int)$cB]);
    t('grupy: dostępność A wolna, B zablokowana', [ti_instructor_available_at($uI, $d, '10:30', '11:30', $cA)['ok'], ti_instructor_available_at($uI, $d, '10:30', '11:30', $cB)['ok']], [true, false]);
    $sms = 0; t('grupy: e-mail tylko do grupy B', ti_leaves_notify($lg, false, true, $sms), 1);
    t('grupy: baner kursanta A nie widzi, B widzi', [count(ti_leaves_for_client((int)$X['client']['A'], 30)), count(ti_leaves_for_client((int)$X['client']['B'], 30))], [0, 1]);
    $g = sc_request_unavailability($uI, $d, $d, 'inne', '', '', '', [$cA]);
    t('zgłoszenie z grupą A: zapis + pozycje tylko grupy A (jedyna planowana: 08:00)', [db_one('SELECT course_ids FROM k30_sc_requests WHERE id=?', [$g['id']])['course_ids'], (int)db_one('SELECT COUNT(*) n FROM k30_sc_request_items WHERE request_id=?', [$g['id']])['n']], [(string)$cA, 1]);

    // — zgłoszenie godzinowe: walidacja i kopiowanie do urlopu
    t('zgłoszenie: złe godziny odrzucone', [sc_request_unavailability($uI, $d, $d, 'inne', '', '12:00', '11:00')['ok'], sc_request_unavailability($uI, $d, $d, 'inne', '', '12:00', '')['ok']], [false, false]);
    $h = sc_request_unavailability($uI, $d, $d, 'inne', 'wizyta', '16:00', '18:00');
    sc_request_decide((int)$h['id'], 'approve', '', 1);
    $lv = db_one('SELECT time_from, time_to FROM k30_ti_instructor_leaves WHERE id=(SELECT leave_id FROM k30_sc_requests WHERE id=?)', [$h['id']]);
    t('zatwierdzenie kopiuje godziny do urlopu', [$lv['time_from'], $lv['time_to']], ['16:00', '18:00']);
    echo "PASS=$ok FAIL=$bad\n"; exit($bad ? 1 : 0);
}

if ($mode === 'seed') {
    require $root . '/includes/db.php'; require $root . '/includes/functions.php'; require $root . '/includes/karty30.php'; require $root . '/includes/ti_leaves.php';
    karty30_migrate(); ti_leaves_migrate();
    seed('P', 6); echo "PASS=0 FAIL=0 (seed)\n"; exit(0);
}

// ── strony panelu dydaktyka (smoke-render + POST) ──
$pmode = $argv[3] ?? 'get_conf';
chdir($root . '/karty30/ti/dydaktyk');
require 'auth.php';
require $root . '/includes/ti_leaves.php'; require $root . '/modules/ti_scheduler/logic/schedulerWorkflow.php';
$prof = dyd_profile_from_user(db_one('SELECT * FROM users WHERE id=1')); $prof['totp_bypass'] = 1; $prof['yk_ok'] = 1; dyd_login_user($prof);
$_SESSION['k30_dyd_ctx']['role'] = 'staff'; $_SESSION['k30_dyd_ctx']['mode'] = 'kursy';
$iid = (int)db_one("SELECT id FROM users WHERE email='prow_P@example.invalid'")['id'];
$d = date('Y-m-d', strtotime('+6 day')); $_SERVER['REQUEST_METHOD'] = 'GET'; $script = 'urlopy.php'; $expect = ''; $flashRe = null;
$last = db_one('SELECT id FROM k30_ti_instructor_leaves WHERE instructor_id=? ORDER BY id DESC LIMIT 1', [$iid]);
$gB = (int)(db_one("SELECT id FROM k30_ti_courses WHERE name='Grupa B P'")['id'] ?? 0);
switch ($pmode) {
    case 'post_badhours': $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['_op' => 'save_leave', 'leave_id' => 0, 'instructor_id' => $iid, 'date_from' => $d, 'date_to' => $d, 'time_from' => '11:00', 'time_to' => '09:00', 'type' => 'urlop']; $flashRe = '/poprawne godziny/'; break;
    case 'post_save':     $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['_op' => 'save_leave', 'leave_id' => 0, 'instructor_id' => $iid, 'date_from' => $d, 'date_to' => $d, 'time_from' => '09:00', 'time_to' => '11:00', 'type' => 'chorobowe', 'note' => 't', 'notify_sms' => '1']; $flashRe = '/kolidujące z nieobecnością: 2/u'; break;
    case 'get_conf':      $_GET['conflicts'] = (int)$last['id']; $expect = 'kolidujace'; break;
    case 'post_cancel':   $_SERVER['REQUEST_METHOD'] = 'POST';
        $sid = (int)db_one("SELECT s.id FROM k30_ti_sessions s WHERE s.course_id=? AND s.lesson_date=? AND s.time_from='10:00'", [(int)db_one("SELECT id FROM k30_ti_courses WHERE name='Grupa A P'")['id'], $d])['id'];
        $_POST = ['_op' => 'cancel_conflicts', 'leave_id' => (int)$last['id'], 'session_ids' => [$sid, 999999], 'reason' => '', 'notify_email' => '1', 'notify_sms' => '1', 'ask_contact' => '1']; $flashRe = '/Odwołano zajęć: 1/u'; break;
    case 'get_delete':    $_GET['delete'] = (int)$last['id']; $expect = 'usuwanie'; break;
    case 'post_delete':   $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['_op' => 'delete_leave', 'leave_id' => (int)$last['id'], 'notify_withdrawn' => '1', 'notify_sms' => '1', 'notify_email' => '1',
        'session_ids' => array_map('intval', array_column(ti_leave_cancelled_sessions((int)$last['id']), 'id'))]; $flashRe = '/Nieobecność usunięta/u'; break;
    case 'get_rebook':    $script = 'nowyTermin.php'; $expect = 'Nowe terminy po odwołaniu'; break;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') $_POST['_token'] = dyd_token();
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] = '/karty30/ti/dydaktyk/' . $script;
ob_start();
register_shutdown_function(function () use ($pmode, $expect, $flashRe) {
    $out = ob_get_clean(); $flash = json_encode($_SESSION['flash'] ?? null, JSON_UNESCAPED_UNICODE);
    $good = ($flashRe === null || preg_match($flashRe, (string)$flash)) && ($expect === '' || str_contains($out, $expect)) && !preg_match('/Warning|Fatal error|Notice|Deprecated/', $out);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && strlen($out) < 2000) $good = false;
    // Menu jest tylko boczne: strona ma menu boczne i nie ma górnego rzędu sekcji
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!str_contains($out, 'dyd-side-sec') || str_contains($out, 'class="dyd-topnav"'))) $good = false;
    echo ($good ? "mode=$pmode OK" : "FAIL mode=$pmode bytes=" . strlen($out) . " flash=$flash") . "\n";
});
include $root . '/karty30/ti/dydaktyk/' . $script;
