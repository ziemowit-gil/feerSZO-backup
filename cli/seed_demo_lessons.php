<?php
/**
 * cli/seed_demo_lessons.php — fikcyjna grupa i zajęcia dla konta testowego kursanta (np. test2),
 * żeby sprawdzić widoki panelu (najbliższe zajęcia, licznik, kalendarz, frekwencja).
 *
 * Tworzy:
 *   • grupę „Pracownia komputerowa (DEMO)”, kod DEMO-1, bez faktur i bez rozliczeń (no_invoice=1, wup_exclude=1, stawka 0),
 *   • zapis kursanta do grupy,
 *   • 8 zajęć: 4 minione (3 z obecnością, 1 nieobecność), dziś (za ok. 2 godz.), jutro, za 7 i za 14 dni.
 * Nic nie wysyła (bez SMS/e-maili) — wpisy idą bezpośrednio do bazy.
 * Wszystko jest oznaczone znacznikiem [DEMO-SEED] w opisie grupy, więc łatwo to usunąć.
 *
 * Użycie:
 *   php cli/seed_demo_lessons.php                 # dla konta test2
 *   php cli/seed_demo_lessons.php --login=test2 --dry-run
 *   php cli/seed_demo_lessons.php --login=test2 --reset    # usuń i utwórz od nowa (świeże daty)
 *   php cli/seed_demo_lessons.php --login=test2 --remove   # usuń dane demo tego konta
 *   php cli/seed_demo_lessons.php --login=test2 --instructor=jan@feer.org.pl   # przypisz prowadzącego (także do istniejącej grupy demo)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko z CLI.\n"); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/karty30.php';
karty30_migrate();

const DEMO_MARKER = '[DEMO-SEED]';

$opt = ['login' => 'test2', 'dry-run' => false, 'reset' => false, 'remove' => false, 'instructor' => ''];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--login=(.+)$/', $a, $m)) $opt['login'] = trim($m[1]);
    elseif (preg_match('/^--instructor=(.+)$/', $a, $m)) $opt['instructor'] = trim($m[1]);
    elseif (in_array($a, ['--dry-run', '--reset', '--remove'], true)) $opt[substr($a, 2)] = true;
    else { fwrite(STDERR, "Nieznana opcja: $a\n"); exit(2); }
}

$acc = db_one("SELECT id, client_id, login FROM k30_ti_student_accounts WHERE login = ? OR (login_alias != '' AND login_alias = ?)",
              [$opt['login'], $opt['login']]);
if (!$acc) { fwrite(STDERR, "Nie ma konta kursanta o loginie „{$opt['login']}”.\n"); exit(1); }
$client_id = (int)$acc['client_id'];
echo "Konto: {$acc['login']} (konto #{$acc['id']}, klient #{$client_id})\n";

/** Grupy demo tego klienta (rozpoznawane po znaczniku w opisie i zapisie klienta). */
$demo_courses = function () use ($client_id): array {
    return db_all(
        "SELECT c.id, c.name FROM k30_ti_courses c
           JOIN k30_ti_enrollments e ON e.course_id = c.id AND e.client_id = ?
          WHERE c.description LIKE ?",
        [$client_id, '%' . DEMO_MARKER . '%']);
};

$remove = function (bool $dry) use ($demo_courses): int {
    $n = 0;
    foreach ($demo_courses() as $c) {
        echo ($dry ? '[dry-run] ' : '') . "Usuwam grupę demo #{$c['id']} „{$c['name']}”\n";
        if (!$dry) {
            $pdo = db();
            $pdo->prepare("DELETE FROM k30_ti_attendance WHERE session_id IN (SELECT id FROM k30_ti_sessions WHERE course_id = ?)")->execute([$c['id']]);
            $pdo->prepare("DELETE FROM k30_ti_sessions WHERE course_id = ?")->execute([$c['id']]);
            $pdo->prepare("DELETE FROM k30_ti_enrollments WHERE course_id = ?")->execute([$c['id']]);
            $pdo->prepare("DELETE FROM k30_ti_courses WHERE id = ?")->execute([$c['id']]);
        }
        $n++;
    }
    return $n;
};

if ($opt['remove']) {
    $n = $remove($opt['dry-run']);
    echo $n ? "Gotowe.\n" : "Brak danych demo dla tego konta.\n";
    exit(0);
}

// Prowadzący (opcjonalnie): konto SZO po e-mailu
$instructor_id = null;
if ($opt['instructor'] !== '') {
    $u = db_one("SELECT id, name FROM users WHERE LOWER(email) = LOWER(?) AND is_active = 1", [$opt['instructor']]);
    if (!$u) { fwrite(STDERR, "Nie ma aktywnego konta prowadzącego o e-mailu „{$opt['instructor']}”.\n"); exit(1); }
    $instructor_id = (int)$u['id'];
    echo "Prowadzący: {$u['name']} (#{$instructor_id})\n";
}

if ($demo_courses()) {
    if (!$opt['reset'] && $instructor_id !== null) {
        foreach ($demo_courses() as $c) {
            echo ($opt['dry-run'] ? '[dry-run] ' : '') . "Przypisuję prowadzącego do grupy demo #{$c['id']}\n";
            if (!$opt['dry-run']) {
                db()->prepare("UPDATE k30_ti_courses SET instructor_id = ? WHERE id = ?")->execute([$instructor_id, $c['id']]);
                db()->prepare("UPDATE k30_ti_sessions SET instructor_id = ? WHERE course_id = ?")->execute([$instructor_id, $c['id']]);
            }
        }
        echo "Gotowe.\n";
        exit(0);
    }
    if (!$opt['reset']) { echo "Dane demo już istnieją — nic nie robię. Użyj --reset, żeby odświeżyć daty, --instructor=…, żeby przypisać prowadzącego, albo --remove.\n"; exit(0); }
    $remove($opt['dry-run']);
}

// ── Plan zajęć (daty względem dziś, strefa z config.php) ─────────────────────
$today = date('Y-m-d');
$soon  = date('H:i', strtotime('+2 hours'));
if ($soon < date('H:i')) $soon = '23:30';   // tuż przed północą nie przeskakujemy na jutro
$soon_to = date('H:i', strtotime($today . ' ' . $soon) + 90 * 60);
if ($soon_to < $soon) $soon_to = '23:59';

$plan = [
    [date('Y-m-d', strtotime('-28 days')), '16:00', '17:30', 'Wprowadzenie do pracy z czytnikiem ekranu', 1],
    [date('Y-m-d', strtotime('-21 days')), '16:00', '17:30', 'Skróty klawiszowe w systemie Windows',      1],
    [date('Y-m-d', strtotime('-14 days')), '16:00', '17:30', 'Poczta e-mail — Outlook z klawiatury',       0],
    [date('Y-m-d', strtotime('-7 days')),  '16:00', '17:30', 'Edytor tekstu — formatowanie dokumentu',     1],
    [$today,                                $soon,   $soon_to, 'Arkusz kalkulacyjny — pierwsze formuły',  null],
    [date('Y-m-d', strtotime('+1 day')),   '16:00', '17:30', 'Przeglądarka internetowa — bezpieczeństwo',  null],
    [date('Y-m-d', strtotime('+7 days')),  '16:00', '17:30', 'Wideorozmowy — Zoom i Teams',                null],
    [date('Y-m-d', strtotime('+14 days')), '16:00', '17:30', 'Powtórka i ćwiczenia',                        null],
];

echo ($opt['dry-run'] ? '[dry-run] ' : '') . "Tworzę grupę „Pracownia komputerowa (DEMO)” (DEMO-1) i " . count($plan) . " zajęć:\n";
foreach ($plan as [$d, $f, $t, $topic, $att]) {
    echo "  • {$d} {$f}–{$t}  {$topic}" . ($att === null ? '' : ($att ? '  [obecny]' : '  [nieobecny]')) . "\n";
}
if ($opt['dry-run']) { echo "[dry-run] Nic nie zapisano.\n"; exit(0); }

$pdo = db();
$pdo->beginTransaction();
try {
    $course_id = db_insert('k30_ti_courses', [
        'name'         => 'Pracownia komputerowa (DEMO)',
        'instructor_id' => $instructor_id,
        'display_name' => 'Pracownia komputerowa (DEMO)',
        'group_code'   => 'DEMO-1',
        'description'  => DEMO_MARKER . ' Dane fikcyjne do testów widoku panelu kursanta. Usuń: php cli/seed_demo_lessons.php --remove',
        'location'     => 'Online',
        'is_active'    => 1,
        'status'       => 'active',
        'class_type'   => 'group',
        'time_from'    => '16:00',
        'time_to'      => '17:30',
        'duration_min' => 90,
        'no_invoice'   => 1,
        'wup_exclude'  => 1,
        'billing_amount' => 0,
    ]);
    db_insert('k30_ti_enrollments', [
        'course_id'   => $course_id,
        'client_id'   => $client_id,
        'hourly_rate' => 0,
        'start_date'  => date('Y-m-d', strtotime('-30 days')),
        'status'      => 'active',
        'notes'       => DEMO_MARKER,
    ]);
    foreach ($plan as [$d, $f, $t, $topic, $att]) {
        $mins = (int)round((strtotime("$d $t") - strtotime("$d $f")) / 60);
        $sid = db_insert('k30_ti_sessions', [
            'course_id'    => $course_id,
            'lesson_date'  => $d,
            'time_from'    => $f,
            'time_to'      => $t,
            'duration_min' => $mins > 0 ? $mins : 90,
            'status'       => $d < $today ? 'held' : 'planned',
            'topic'        => $topic,
            'notes'        => DEMO_MARKER,
            'instructor_id' => $instructor_id,
        ]);
        if ($att !== null) {
            db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => $client_id, 'attended' => $att]);
        }
    }
    $pdo->commit();
    echo "Gotowe: grupa #$course_id. Odśwież panel kursanta.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Błąd: ' . $e->getMessage() . "\n");
    exit(1);
}
