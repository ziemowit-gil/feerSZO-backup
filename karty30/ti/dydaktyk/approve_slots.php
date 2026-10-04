<?php
/**
 * karty30/ti/dydaktyk/approve_slots.php — zatwierdzanie szkiców z planera cyklicznego (sloty tygodniowe).
 *   GET  ?ids=1,2 → JSON podglądu (mail, załączniki z harmonogramem, ostrzeżenia)
 *   POST ids[], send_mail, _token → status 'approved' + opcjonalny mail do kursantów kursu
 * Dostęp: właściciel slotów (prowadzący) lub kierownik.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_lesson_approval/logic/approval.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
$staff = dyd_is_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $send = !empty($_POST['send_mail']);
    $r = ti_slot_approve((array)($_POST['ids'] ?? []), $send, $uid, $staff);
    flash_set($r['approved'] ? 'success' : 'danger', $r['approved']
        ? 'Zatwierdzono sloty planu cyklicznego: ' . $r['approved'] . '.' . ($send ? ' Wysłano e-maili: ' . $r['mails'] . '.' : ' E-mail nie został wysłany.')
        : 'Nie zatwierdzono żadnego slotu — wybrane nie są już szkicami.');
    header('Location: index.php?tab=cykliczne'); exit;
}

header('Content-Type: application/json; charset=utf-8');
$slots = ti_slot_get(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))), $uid, $staff);
if (!$slots) { echo json_encode(['error' => 'Brak wybranych szkiców.'], JSON_UNESCAPED_UNICODE); exit; }
$first = $slots[0];
$course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [(int)$first['course_id']]) ?: ['name' => ''];
$of_course = array_values(array_filter($slots, fn($s) => (int)$s['course_id'] === (int)$first['course_id']));
$rec = ti_appr_recipients((int)$first['course_id']);
$names = ti_appr_schedule_names($course);
$mail = ti_slot_compose($course, $of_course, $names, $rec ? (explode(' ', trim($rec[0]['name']))[0] ?: $rec[0]['name']) : '{imię}');
$warn = [];
$courses = array_unique(array_map(fn($s) => (string)$s['course_name'], $slots));
if (count($courses) > 1) $warn[] = 'Zatwierdzasz sloty z ' . count($courses) . ' kursów — poniżej podgląd maila dla kursu „' . $first['course_name'] . '”; pozostałe kursy dostaną analogiczne wiadomości.';
foreach ($slots as $sl) {
    if (!empty($sl['is_online']) && trim((string)$sl['default_meeting_url']) === '') $warn[] = 'Kurs „' . $sl['course_name'] . '” jest online, ale nie ma domyślnego linku do spotkania.';
    $n = (int)db_one("SELECT COUNT(*) AS n FROM k30_ti_weekly_plan WHERE instructor_id=? AND day_of_week=? AND status='approved' AND id<>?
                      AND time_from < ? AND time(time_from, '+' || duration_min || ' minutes') > ?", [(int)$sl['instructor_id'], (int)$sl['day_of_week'], (int)$sl['id'], date('H:i', strtotime('2000-01-01 ' . substr($sl['time_from'], 0, 5) . ' +' . (int)$sl['duration_min'] . ' minutes')), substr($sl['time_from'], 0, 5)])['n'];
    if ($n) $warn[] = 'Slot ' . (K30_TI_DAYS[(int)$sl['day_of_week']] ?? '') . ' ' . ti_slot_range($sl) . ' nakłada się na już zatwierdzony slot prowadzącego.';
}
if (!$rec) $warn[] = 'Kurs „' . $first['course_name'] . '” nie ma kursantów z poprawnym adresem e-mail — mail nie zostanie wysłany.';
echo json_encode([
    'summary'    => 'Zatwierdzasz ' . count($slots) . ' slot(ów) tygodniowego planu cyklicznego. Zatwierdzone sloty są podstawą generowania lekcji.',
    'items'      => array_map(fn($s) => $s['course_name'] . ' — ' . (K30_TI_DAYS[(int)$s['day_of_week']] ?? '') . ' ' . ti_slot_range($s), $slots),
    'ids'        => array_map(fn($s) => (int)$s['id'], $slots), 'blockers' => [], 'warnings' => array_values(array_unique($warn)),
    'subject'    => $mail['subject'], 'html' => $mail['html'],
    'recipients' => array_map(fn($r) => $r['name'] . ' <' . $r['email'] . '>' . ($r['kind'] === 'opiekun' ? ' (opiekun)' : ''), $rec),
    'files'      => array_map(fn($n) => ['name' => $n, 'size' => 0, 'exists' => true], $names), 'links' => [],
], JSON_UNESCAPED_UNICODE);
