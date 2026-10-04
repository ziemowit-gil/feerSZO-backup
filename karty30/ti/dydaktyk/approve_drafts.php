<?php
/**
 * karty30/ti/dydaktyk/approve_drafts.php — decyzje o roboczych wersjach lekcji (draft).
 *   GET  ?course=ID&ids=1,2,3 → JSON podglądu (mail, załączniki z harmonogramem, ostrzeżenia)
 *   POST op=approve (domyślnie): course, ids[], send_mail, _token → draft→planned + opcjonalny mail
 *   POST op=delete : course, ids[] (puste = wszystkie robocze kursu), _token → usuwa wybrane robocze
 * Dostęp: prowadzący kursu lub kierownik.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_lesson_approval/logic/approval.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
$cid = (int)($_REQUEST['course'] ?? 0);
if (!dyd_owns_course($uid, $cid)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }
$course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$cid]);
if (!$course) { http_response_code(404); exit('Nie znaleziono kursu.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $ids = (array)($_POST['ids'] ?? []);
    if (($_POST['op'] ?? 'approve') === 'delete') {
        $sel = $ids ? ti_appr_sessions($cid, $ids) : ti_appr_drafts($cid);
        foreach ($sel as $s) db_exec("DELETE FROM k30_ti_sessions WHERE id=? AND status='draft'", [(int)$s['id']]);
        flash_set('success', 'Usunięto wersje robocze: ' . count($sel) . '.');
    } else {
        $send = !empty($_POST['send_mail']);
        $res  = ti_appr_approve($cid, $ids, $send, $uid);
        flash_set($res['approved'] > 0 ? 'success' : 'danger', $res['approved'] > 0
            ? 'Zatwierdzono lekcje: ' . $res['approved'] . '.' . ($send ? ' Wysłano e-maili: ' . $res['mails'] . '.' : ' E-mail nie został wysłany.')
            : 'Nie zatwierdzono żadnej lekcji — wybrane nie są już wersjami roboczymi.');
    }
    header('Location: index.php?course=' . $cid . '&tab=lekcje'); exit;
}

header('Content-Type: application/json; charset=utf-8');
$ids      = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
$sessions = ti_appr_sessions($cid, $ids);
if (!$sessions) { echo json_encode(['error' => 'Brak wybranych wersji roboczych.'], JSON_UNESCAPED_UNICODE); exit; }
$rec = ti_appr_recipients($cid);
$mat = ti_appr_materials(array_column($sessions, 'id'));
$warn = ti_appr_warnings($cid, $sessions, $rec, $mat);
$mat['sched'] = ti_appr_schedule_names($course);
$mail = ti_appr_compose($course, $sessions, $mat, $rec ? (explode(' ', trim($rec[0]['name']))[0] ?: $rec[0]['name']) : '{imię}');
$files = array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size'], 'exists' => $f['exists']], $mat['files']);
foreach ($mat['sched'] as $n) $files[] = ['name' => $n, 'size' => 0, 'exists' => true];
echo json_encode([
    'summary'    => 'Zatwierdzasz ' . count($sessions) . ' lekcji. Staną się widoczne dla kursantów i zaczną się liczyć do frekwencji i rozliczeń.',
    'items'      => array_map(fn($s) => ($s['lesson_date'] !== '' ? date('d.m.Y', strtotime($s['lesson_date'])) . ' ' . substr($s['time_from'], 0, 5) : 'bez terminu') . ' — ' . ($s['topic'] !== '' ? $s['topic'] : 'bez tematu'), $sessions),
    'ids'        => array_map(fn($s) => (int)$s['id'], $sessions),
    'blockers'   => [],
    'subject'    => $mail['subject'], 'html' => $mail['html'],
    'recipients' => array_map(fn($r) => $r['name'] . ' <' . $r['email'] . '>' . ($r['kind'] === 'opiekun' ? ' (opiekun)' : ''), $rec),
    'files'      => $files, 'links' => $mat['links'], 'warnings' => array_merge(ti_appr_blockers($sessions), $warn),
], JSON_UNESCAPED_UNICODE);
