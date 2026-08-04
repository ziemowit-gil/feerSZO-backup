<?php
/**
 * cli/cleanup_test_accounts.php — usuwa konta i dane testowe założone przez
 * cli/seed_test_accounts.php. Dopasowuje WYŁĄCZNIE po dokładnych nazwach/loginach
 * użytych przy zakładaniu — nie rusza żadnych innych danych.
 *
 * Usuwa:
 *   SZO: admin@<domena>, editor@<domena>, viewer@<domena>
 *   TI:  kursant.pel, kursant.niep/rodzic.niep, dydaktyk@<domena>,
 *        "Kurs testowy (TEST)" + zapisy
 *
 * Użycie:
 *   php cli/cleanup_test_accounts.php [domena_email]
 *
 * Przykład (domyślna domena "feer.test", jak w seed_test_accounts.php):
 *   php cli/cleanup_test_accounts.php
 *   php cli/cleanup_test_accounts.php feer.test
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$domain = $argv[1] ?? 'feer.test';
$dydEmail = 'dydaktyk@' . $domain;

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';

$removed = 0;

// ── Konta SZO (logowanie lokalne) ────────────────────────────────────────
foreach ([
    ['email' => 'admin@'  . $domain, 'name' => 'Admin Testowy (TEST)'],
    ['email' => 'editor@' . $domain, 'name' => 'Editor Testowy (TEST)'],
    ['email' => 'viewer@' . $domain, 'name' => 'Viewer Testowy (TEST)'],
] as $a) {
    $u = db_one("SELECT id FROM users WHERE email=? AND name=?", [$a['email'], $a['name']]);
    if (!$u) { echo "{$a['email']} — nie znaleziono (już usunięty?).\n"; continue; }
    db()->prepare("DELETE FROM users WHERE id=?")->execute([$u['id']]);
    echo "USUNIĘTO {$a['email']} (id={$u['id']}).\n";
    $removed++;
}

// ── Kurs testowy + zapisy ────────────────────────────────────────────────
$course = db_one("SELECT id FROM k30_ti_courses WHERE name='Kurs testowy (TEST)'");
if ($course) {
    db()->prepare("DELETE FROM k30_ti_enrollments WHERE course_id=?")->execute([$course['id']]);
    db()->prepare("DELETE FROM k30_ti_courses WHERE id=?")->execute([$course['id']]);
    echo "USUNIĘTO kurs testowy (id={$course['id']}) + zapisy.\n";
    $removed++;
} else {
    echo "Kurs testowy — nie znaleziono (już usunięty?).\n";
}

// ── Konta kursantów + ich klienci ────────────────────────────────────────
foreach ([
    ['login' => 'kursant.pel',  'client' => 'Kursant Pełnoletni (TEST)'],
    ['login' => 'kursant.niep', 'client' => 'Kursant Niepełnoletni (TEST)'],
] as $t) {
    $acc = db_one("SELECT id, client_id FROM k30_ti_student_accounts WHERE login=?", [$t['login']]);
    if (!$acc) { echo "{$t['login']} — nie znaleziono (już usunięty?).\n"; continue; }
    db()->prepare("DELETE FROM k30_ti_student_accounts WHERE id=?")->execute([$acc['id']]);
    // Usuń klienta tylko jeśli to nasz testowy (dopasowanie po nazwie — nie kasuj cudzych danych).
    $cl = db_one("SELECT id FROM k30_clients WHERE id=? AND name=?", [$acc['client_id'], $t['client']]);
    if ($cl) db()->prepare("DELETE FROM k30_clients WHERE id=?")->execute([$cl['id']]);
    echo "USUNIĘTO {$t['login']} (konto + klient „{$t['client']}\").\n";
    $removed++;
}

// ── Konto dydaktyka ──────────────────────────────────────────────────────
$dyd = db_one("SELECT id FROM users WHERE email=? AND name='Dydaktyk (TEST)'", [$dydEmail]);
if ($dyd) {
    db()->prepare("DELETE FROM users WHERE id=?")->execute([$dyd['id']]);
    echo "USUNIĘTO $dydEmail (id={$dyd['id']}).\n";
    $removed++;
} else {
    echo "$dydEmail — nie znaleziono (już usunięty lub nazwa zmieniona ręcznie — nie kasuję).\n";
}

echo "\nGotowe. Usunięto $removed element(ów) testowych.\n";
