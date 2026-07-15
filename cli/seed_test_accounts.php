<?php
/**
 * cli/seed_test_accounts.php — zakłada konta testowe do QA logowania panelu TI:
 * kursant.pel (pełnoletni), kursant.niep + rodzic.niep (małoletni + opiekun na tym
 * samym koncie), dydaktyk@<domena> (dydaktyk). Dodatkowo zakłada dedykowany
 * "Kurs testowy (TEST)" z instruktorem = konto dydaktyka i zapisuje oba testowe
 * konta kursantów na ten kurs — nie dotyka ŻADNYCH istniejących kursów/kont.
 *
 * Idempotentne: jeśli dane konto/kurs już istnieje, pomija je (nie nadpisuje hasła).
 *
 * Użycie:
 *   php cli/seed_test_accounts.php '<haslo>' [domena_email]
 *
 * Przykład:
 *   php cli/seed_test_accounts.php 'MojeHaslo123!' feer.test
 *
 * Hasło podajesz jako argument — NIE jest zapisane na stałe w tym pliku (żeby nie
 * trafiało do historii gita w plaintext). Domena e-mail dydaktyka domyślnie "feer.test"
 * (nierozwiązywalna, testowa — RFC 2606 — żeby żaden e-mail nie poszedł naprawdę).
 *
 * Po zakończeniu testów uruchom cli/cleanup_test_accounts.php, żeby usunąć te konta.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$password = $argv[1] ?? '';
$domain   = $argv[2] ?? 'feer.test';

if ($password === '') {
    fwrite(STDERR, "Użycie: php cli/seed_test_accounts.php '<haslo>' [domena_email]\n");
    exit(2);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Hasło musi mieć co najmniej 8 znaków.\n");
    exit(2);
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';

$hash     = password_hash($password, PASSWORD_DEFAULT);
$dydEmail = 'dydaktyk@' . $domain;

// ── kursant.pel — pełnoletni kursant, własny klient ─────────────────────────
$acc = db_one("SELECT id FROM k30_ti_student_accounts WHERE login='kursant.pel'");
if ($acc) {
    echo "POMINIĘTO kursant.pel — już istnieje (id={$acc['id']}).\n";
} else {
    $clientPel = db_insert('k30_clients', ['name' => 'Kursant Pełnoletni (TEST)', 'status' => 'enrolled']);
    db_insert('k30_ti_student_accounts', [
        'client_id' => $clientPel, 'login' => 'kursant.pel', 'password_hash' => $hash,
        'is_active' => 1, 'is_minor' => 0,
    ]);
    echo "OK kursant.pel (client_id=$clientPel)\n";
}

// ── kursant.niep + rodzic.niep — małoletni kursant + opiekun na tym samym koncie ──
$acc = db_one("SELECT id FROM k30_ti_student_accounts WHERE login='kursant.niep'");
if ($acc) {
    echo "POMINIĘTO kursant.niep/rodzic.niep — już istnieje (id={$acc['id']}).\n";
} else {
    $clientNiep = db_insert('k30_clients', ['name' => 'Kursant Niepełnoletni (TEST)', 'status' => 'enrolled']);
    db_insert('k30_ti_student_accounts', [
        'client_id' => $clientNiep, 'login' => 'kursant.niep', 'password_hash' => $hash,
        'is_active' => 1, 'is_minor' => 1, 'guardian_name' => 'Rodzic Testowy (TEST)',
        'parent_login' => 'rodzic.niep', 'parent_password_hash' => $hash,
    ]);
    echo "OK kursant.niep + rodzic.niep (client_id=$clientNiep)\n";
}

// ── dydaktyk — konto SZO (users) z uprawnieniem k30_consultant ──────────────
$dyd = db_one("SELECT id FROM users WHERE email=?", [$dydEmail]);
if ($dyd) {
    echo "POMINIĘTO $dydEmail — już istnieje (id={$dyd['id']}).\n";
    $dydId = (int)$dyd['id'];
} else {
    $dydId = db_insert('users', [
        'name' => 'Dydaktyk (TEST)', 'email' => $dydEmail, 'password' => $hash,
        'role' => 'viewer', 'is_active' => 1, 'k30_consultant' => 1,
    ]);
    echo "OK dydaktyk — login e-mailem: $dydEmail (id=$dydId)\n";
}

// ── Kurs testowy + zapis obu testowych kursantów ────────────────────────────
$course = db_one("SELECT id FROM k30_ti_courses WHERE name='Kurs testowy (TEST)'");
if ($course) {
    echo "POMINIĘTO kurs testowy — już istnieje (id={$course['id']}).\n";
    $courseId = (int)$course['id'];
} else {
    $courseId = db_insert('k30_ti_courses', [
        'name' => 'Kurs testowy (TEST)', 'description' => 'Kurs do testów logowania/paneli — konta testowe.',
        'instructor_id' => $dydId, 'day_of_week' => 1, 'time_from' => '16:00', 'time_to' => '17:00',
        'duration_min' => 60, 'is_active' => 1, 'status' => 'active',
    ]);
    echo "OK kurs testowy (id=$courseId, instructor_id=$dydId)\n";
}

foreach (['kursant.pel', 'kursant.niep'] as $login) {
    $sa = db_one("SELECT client_id FROM k30_ti_student_accounts WHERE login=?", [$login]);
    if (!$sa) continue;
    $enr = db_one("SELECT id FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$courseId, $sa['client_id']]);
    if ($enr) { echo "POMINIĘTO zapis $login — już zapisany.\n"; continue; }
    db_insert('k30_ti_enrollments', ['course_id' => $courseId, 'client_id' => (int)$sa['client_id'], 'hourly_rate' => 0, 'status' => 'active']);
    echo "OK zapis $login na kurs $courseId\n";
}

echo "\nGotowe. Loginy: kursant.pel, kursant.niep, rodzic.niep, $dydEmail — hasło: to, które podałeś.\n";
echo "Po testach uruchom: php cli/cleanup_test_accounts.php $domain\n";
