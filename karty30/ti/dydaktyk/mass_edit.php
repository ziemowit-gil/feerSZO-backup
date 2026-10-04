<?php
/**
 * karty30/ti/dydaktyk/mass_edit.php — masowa zmiana parametrów terminów (typ terminu, forma, link, sala,
 * godziny, przesunięcie dat, temat, notatki, zadanie domowe, pewność terminu, prowadzący).
 *   GET  course + filtry (from,to,only_method,only_status) + chg_<pole>/val_<pole> → JSON podglądu
 *   POST te same pola + ids[] + send_mail + _token → zapis
 * Dostęp: prowadzący kursu lub kierownik; kursy hybrydowe — tylko kierownik; zamknięte okresy blokują.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_lesson_approval/logic/approval.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
$cid = (int)($_REQUEST['course'] ?? 0);
if (!dyd_owns_course($uid, $cid)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }
$course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$cid]);
if (!$course) { http_response_code(404); exit('Nie znaleziono kursu.'); }
$staff = dyd_is_staff();
$ch = ti_mm_parse_changes($_REQUEST, $staff);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    try {
        $r = ti_mm_apply($cid, (array)($_POST['ids'] ?? []), $ch, !empty($_POST['send_mail']), $staff);
        flash_set('success', 'Zmieniono lekcje: ' . $r['changed'] . ' (' . ti_mm_describe($ch) . ').' . (!empty($_POST['send_mail']) ? ' Wysłano e-maili: ' . $r['mails'] . '.' : ''));
    } catch (\Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: index.php?course=' . $cid . '&tab=lekcje'); exit;
}

header('Content-Type: application/json; charset=utf-8');
$sessions = ti_mm_candidates($cid, $_GET);
$chk = ti_mm_check($cid, $sessions, $ch, $staff);
$out = [
    'summary'  => $sessions ? 'Zmiana dotyczy ' . count($sessions) . ' lekcji: ' . ti_mm_describe($ch) . '.' : 'Żadna lekcja nie pasuje do kryteriów.',
    'items'    => $chk['items'], 'warnings' => $chk['warnings'], 'blockers' => $chk['blockers'],
    'ids'      => array_map(fn($s) => (int)$s['id'], $sessions),
];
[$mail_sessions, $kind] = $sessions && !$chk['blockers'] ? ti_mm_mail_preview_sessions($sessions, $ch) : [[], ''];
if ($mail_sessions) {
    $rec = ti_appr_recipients($cid);
    $mat = $kind === 'approve' ? ti_appr_materials(array_column($mail_sessions, 'id')) : ['files' => [], 'links' => []];
    $mat['sched'] = ti_appr_schedule_names($course);
    $m = ti_appr_compose($course, $mail_sessions, $mat, $rec ? (explode(' ', trim($rec[0]['name']))[0] ?: $rec[0]['name']) : '{imię}', $kind);
    $files = array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size'], 'exists' => $f['exists']], $mat['files']);
    foreach ($mat['sched'] as $n) $files[] = ['name' => $n, 'size' => 0, 'exists' => true];
    $out += ['subject' => $m['subject'], 'html' => $m['html'], 'files' => $files, 'links' => $mat['links'],
             'recipients' => array_map(fn($r) => $r['name'] . ' <' . $r['email'] . '>' . ($r['kind'] === 'opiekun' ? ' (opiekun)' : ''), $rec)];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
