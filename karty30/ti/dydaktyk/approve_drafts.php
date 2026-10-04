<?php
/**
 * karty30/ti/dydaktyk/approve_drafts.php — zatwierdzanie roboczych lekcji z podglądem maila.
 *   GET  ?course=ID&ids=1,2,3  → JSON: temat, adresaci, załączniki, ostrzeżenia, HTML maila (do iframe srcdoc)
 *   POST course, ids[], send_mail=1|0, _token → zatwierdza (draft→planned) + opcjonalna wysyłka, redirect do Lekcji
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
    $ids  = (array)($_POST['ids'] ?? []);
    $send = !empty($_POST['send_mail']);
    $res  = ti_appr_approve($cid, $ids, $send, $uid);
    if ($res['approved'] > 0) {
        flash_set('success', 'Zatwierdzono lekcje: ' . $res['approved'] . '.' . ($send ? ' Wysłano e-maili: ' . $res['mails'] . '.' : ' E-mail nie został wysłany.'));
    } else {
        flash_set('danger', 'Nie zatwierdzono żadnej lekcji — wybrane nie są już wersjami roboczymi.');
    }
    header('Location: index.php?course=' . $cid . '&tab=lekcje'); exit;
}

header('Content-Type: application/json; charset=utf-8');
$ids      = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
$sessions = ti_appr_sessions($cid, $ids);
if (!$sessions) { echo json_encode(['error' => 'Brak wybranych wersji roboczych.'], JSON_UNESCAPED_UNICODE); exit; }
$rec = ti_appr_recipients($cid);
$mat = ti_appr_materials(array_column($sessions, 'id'));
$mail = ti_appr_compose($course, $sessions, $mat, $rec ? (explode(' ', trim($rec[0]['name']))[0] ?: $rec[0]['name']) : '{imię}');
echo json_encode([
    'count'      => count($sessions),
    'subject'    => $mail['subject'],
    'html'       => $mail['html'],
    'recipients' => array_map(fn($r) => $r['name'] . ' <' . $r['email'] . '>' . ($r['kind'] === 'opiekun' ? ' (opiekun)' : ''), $rec),
    'files'      => array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size'], 'exists' => $f['exists']], $mat['files']),
    'links'      => $mat['links'],
    'warnings'   => ti_appr_warnings($cid, $sessions, $rec, $mat),
], JSON_UNESCAPED_UNICODE);
