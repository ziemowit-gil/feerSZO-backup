<?php
/**
 * cli/tiTestProtocols.php — zakłada grupę TESTOWĄ z 4 protokołami (4 ostatnie miesiące, każdy inny przypadek).
 *
 *   php cli/tiTestProtocols.php <e-mail prowadzącego albo id użytkownika>
 *
 * Miesiące (licząc od bieżącego, M):
 *   M-3  4 odbyte zajęcia (45, 90, 60, 45 min)  → protokół ZATWIERDZONY i podpisany (wpis testowy),
 *   M-2  3 odbyte zajęcia                        → protokół DO ZATWIERDZENIA (zaległy),
 *   M-1  2 zajęcia odwołane, 0 odbytych          → 0 GODZIN (informacja zamiast protokołu),
 *   M    2 odbyte + 2 zaplanowane                → BIEŻĄCY miesiąc (można zamknąć wcześniej).
 * Grupa ma nazwę „TEST — 4 protokoły …”, jest aktywna i przypisana do wskazanego prowadzącego.
 * Nic nie jest wysyłane (bez SMS i maili). Do usunięcia: zwykłe usuwanie kursu w panelu.
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit('Tylko z CLI.'); }
defined('APP_CLI') || define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_protocols.php';

$kto = trim((string)($argv[1] ?? ''));
if ($kto === '') { fwrite(STDERR, "Użycie: php cli/tiTestProtocols.php <e-mail prowadzącego albo id>\n"); cli_exit(1); }
karty30_migrate();
ti_protocols_migrate();
$u = ctype_digit($kto) ? db_one("SELECT id, name FROM users WHERE id = ?", [(int)$kto])
                       : db_one("SELECT id, name FROM users WHERE email = ?", [$kto]);
if (!$u) { fwrite(STDERR, "Nie ma użytkownika: {$kto}\n"); cli_exit(1); }
$uid = (int)$u['id'];

// Kurs: kopia ustawień istniejącej grupy (wymagane kolumny), z własną nazwą i prowadzącym.
$wzor = db_one("SELECT * FROM k30_ti_courses WHERE format = 'group' ORDER BY id DESC LIMIT 1")
     ?: db_one("SELECT * FROM k30_ti_courses ORDER BY id DESC LIMIT 1");
if (!$wzor) { fwrite(STDERR, "Brak jakiegokolwiek kursu do skopiowania ustawień.\n"); cli_exit(1); }
unset($wzor['id'], $wzor['closed_at'], $wzor['closed_by']);
// Puste pola pomijamy — niech baza wstawi wartości domyślne (db_insert zamienia '' w kolumnach *_id na NULL).
$wzor = array_filter($wzor, fn($v, $k) => $v !== null && !($v === '' && str_ends_with((string)$k, '_id')), ARRAY_FILTER_USE_BOTH);
$wzor['name']          = 'TEST — 4 protokoły (' . date('d.m H:i') . ')';
$wzor['display_name']  = $wzor['name'];
$wzor['group_code']    = 'TEST-PROT-' . date('His');
$wzor['instructor_id'] = $uid;
$wzor['is_active']     = 1;
$wzor['status']        = 'active';
$wzor['closed_name']   = '';
$wzor['created_at']    = date('Y-m-d H:i:s');
$wzor['created_by']    = $uid;
$cid = db_insert('k30_ti_courses', $wzor);

$sesja = function (string $data, string $od, string $do, string $status, string $temat) use ($cid, $uid): void {
    $min = (strtotime("2000-01-01 $do") - strtotime("2000-01-01 $od")) / 60;
    db_insert('k30_ti_sessions', [
        'course_id' => $cid, 'lesson_date' => $data, 'time_from' => $od, 'time_to' => $do, 'duration_min' => (int)$min,
        'status' => $status, 'topic' => $temat, 'notes' => '', 'instructor_notes' => '', 'created_by' => $uid,
        'created_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $status === 'cancelled' ? 'Test — zajęcia odwołane' : '',
    ]);
};
$ym = fn(int $back) => date('Y-m', strtotime(date('Y-m-01') . " -{$back} month"));
$d  = fn(string $ym, int $day) => $ym . '-' . sprintf('%02d', min($day, (int)date('t', strtotime($ym . '-01'))));

// M-3: 4 odbyte zajęcia, protokół zatwierdzony.
$m3 = $ym(3);
$sesja($d($m3, 3),  '16:00', '16:45', 'held', 'Test — 45 min');
$sesja($d($m3, 10), '16:00', '17:30', 'held', 'Test — 90 min');
$sesja($d($m3, 17), '16:00', '17:00', 'held', 'Test — 60 min');
$sesja($d($m3, 24), '16:00', '16:45', 'held', 'Test — 45 min');
// M-2: 3 odbyte zajęcia, protokół do zatwierdzenia.
$m2 = $ym(2);
$sesja($d($m2, 5),  '10:00', '11:30', 'held', 'Test — 90 min');
$sesja($d($m2, 12), '10:00', '10:50', 'held', 'Test — 50 min');
$sesja($d($m2, 19), '10:00', '11:00', 'held', 'Test — 60 min');
// M-1: tylko odwołane → 0 godzin.
$m1 = $ym(1);
$sesja($d($m1, 8),  '12:00', '13:30', 'cancelled', 'Test — odwołane');
$sesja($d($m1, 22), '12:00', '13:30', 'cancelled', 'Test — odwołane');
// M: bieżący — 2 odbyte (w przeszłości) i 2 zaplanowane (w przyszłości).
$m0 = $ym(0);
$sesja(date('Y-m-d', strtotime('-6 days')), '16:00', '17:30', 'held', 'Test — 90 min');
$sesja(date('Y-m-d', strtotime('-2 days')), '16:00', '16:45', 'held', 'Test — 45 min');
$sesja(date('Y-m-d', strtotime('+3 days')), '16:00', '17:30', 'scheduled', 'Test — zaplanowane');
$sesja(date('Y-m-d', strtotime('+10 days')), '16:00', '17:30', 'scheduled', 'Test — zaplanowane');

// 4 protokoły (wiersze miesięcy); M-3 zatwierdzony wpisem testowym (bez prawdziwego podpisu).
$prot = [];
foreach ([$m3, $m2, $m1, $m0] as $mies) $prot[$mies] = ti_protocol_get_or_create_for_month($cid, $mies);
db()->prepare("UPDATE k30_ti_protocols SET status = 'approved', approved_by = ?, approved_name = ?, approved_at = datetime('now'),
                      sign_subject = 'TEST — podpis testowy', sign_fingerprint = 'test', sign_at = datetime('now')
                WHERE id = ?")->execute([$uid, (string)$u['name'], (int)$prot[$m3]['id']]);

echo "Utworzono grupę #{$cid}: {$wzor['name']} (prowadzący: {$u['name']})\n";
foreach ($prot as $mies => $p) {
    $hl = ti_protocol_hours_list(ti_protocol_get((int)$p['id']) ?: $p);
    printf("  %s  protokół #%d  zajęć odbytych: %d  godz. rozliczeniowe: %s  faktycznie: %d min%s\n",
        $mies, (int)$p['id'], count($hl['rows']), ti_protocol_hours_fmt($hl['total_hours']), (int)$hl['total_min'],
        $mies === $m3 ? '  [zatwierdzony]' : '');
}
echo "Panel: karty30/ti/dydaktyk/protokoly_moje.php?course={$cid}&ym={$m2}\n";
