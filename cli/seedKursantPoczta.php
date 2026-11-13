<?php
/**
 * cli/seedKursantPoczta.php — testowy kursant do sprawdzania „Poczty kursanta” (@stud.feer.org.pl) na prawdziwych kontaktach.
 *
 * Tworzy (idempotentnie):
 *   • kursanta „Kursant Poczty (TEST)” z podanym e-mailem i telefonem — NIE wirtualnego, więc e-maile i SMS-y naprawdę wychodzą;
 *   • konto w panelu kursanta: login kursant.poczta, hasło z --haslo (bez wymuszonej zmiany);
 *   • grupę „Poczta kursanta (TEST)” (TEST-POCZTA) z aktywnym zapisem — kursant trafia do XLSX „Loginy poczty kursanta”;
 *   • opcjonalnie wpis poczty kursanta (--poczta-login, --poczta-haslo) przez PocztaStud::save (zgłoszenie MIG-ST, gdy uruchamia
 *     zalogowany administrator z Admin › Seedery);
 *   • opcjonalnie --wyslij: PocztaStud::sendLoginData — e-mail tekstowy z loginem i SMS z hasłem startowym na podane kontakty.
 *
 * Użycie:
 *   php cli/seedKursantPoczta.php status
 *   php cli/seedKursantPoczta.php create --haslo='<hasło panelu>' [--email=…] [--telefon=…] [--poczta-login=k.poczty] [--poczta-haslo='<hasło startowe>'] [--wyslij] [--kadra=<e-mail konta admin/editor>]
 *
 * --kadra — z terminala (bez sesji) wysyłka i zgłoszenie MIG-ST idą na konto wskazanej osoby z kadry: SMS z hasłem wychodzi wtedy
 * także w ciszy nocnej (ręczna wysyłka kadry), a zgłaszającym w Helpdesku jest ta osoba. Bez --kadra w CLI: bez zgłoszenia,
 * a w nocy bez SMS-a z hasłem. Z Admin › Seedery osobą jest zalogowany administrator.
 *   php cli/seedKursantPoczta.php delete
 *
 * create przy istniejącym kursancie aktualizuje e-mail i telefon; hasło panelu zmienia tylko podane --haslo
 * (nowe konto bez --haslo dostaje losowe, wypisane raz). delete kasuje WYŁĄCZNIE kursanta o tej nazwie i loginie,
 * jego zapis, wpis poczty i grupę testową (gdy nikt inny w niej nie jest). Skrzynki u dostawcy poczty nie rusza.
 * Hasła podajesz w argumentach — nie trafiają do repozytorium ani do dziennika.
 */

require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    cli_exit(2);
}
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/modules/ti_poczta_stud/logic/PocztaStud.php';

const SKP_NAME   = 'Kursant Poczty (TEST)';
const SKP_LOGIN  = 'kursant.poczta';
const SKP_COURSE = 'Poczta kursanta (TEST)';
const SKP_GROUP  = 'TEST-POCZTA';

$usage = "Użycie:\n"
       . "  php cli/seedKursantPoczta.php status\n"
       . "  php cli/seedKursantPoczta.php create --haslo='<hasło panelu>' [--email=…] [--telefon=…] [--poczta-login=…] [--poczta-haslo='…'] [--wyslij] [--kadra=<e-mail>]\n"
       . "  php cli/seedKursantPoczta.php delete\n";

// Własny parser: getopt() przerywa na pierwszej nie-opcji (tu: komendzie), więc „create --haslo=…” by nie zadziałało.
$cmd = '';
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
    elseif ($cmd === '') $cmd = $a;
}
$val = fn(string $k, string $def = ''): string => is_string($opt[$k] ?? null) ? trim((string)$opt[$k]) : $def;

karty30_migrate();
PocztaStud::migrate();

$find = function (): ?array {
    return db_one("SELECT a.id AS account_id, a.client_id, c.name, c.email, c.phone
                     FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id = a.client_id
                    WHERE a.login = ? AND c.name = ?", [SKP_LOGIN, SKP_NAME]);
};
$status = function () use ($find): void {
    $k = $find();
    if (!$k) { echo "Brak testowego kursanta („" . SKP_NAME . "”, login " . SKP_LOGIN . ").\n"; return; }
    echo "Kursant: " . SKP_NAME . " (nr {$k['client_id']}), login panelu: " . SKP_LOGIN . "\n";
    echo "E-mail: " . ($k['email'] ?: '—') . ", telefon: " . ($k['phone'] ?: '—') . "\n";
    $c = db_one("SELECT id FROM k30_ti_courses WHERE name = ?", [SKP_COURSE]);
    $e = $c ? db_one("SELECT status FROM k30_ti_enrollments WHERE course_id = ? AND client_id = ?", [(int)$c['id'], (int)$k['client_id']]) : null;
    echo "Grupa " . SKP_GROUP . ": " . ($e ? 'zapis ' . $e['status'] : 'brak zapisu') . "\n";
    $m = PocztaStud::get((int)$k['client_id']);
    echo "Poczta kursanta: " . ($m ? $m['login'] . ' — ' . PocztaStud::staffStatus($m) . (($t = PocztaStud::ticketNumber($m)) !== '' ? " Zgłoszenie: {$t}." : '') : 'brak wpisu') . "\n";
};

switch ($cmd) {
    case 'status':
        $status();
        break;

    case 'create':
        $email = $val('email', 'ziemowit.gil@gmail.com');
        $phone = $val('telefon', '535 231 923');
        $pass  = $val('haslo');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Nieprawidłowy e-mail: {$email}\n"); cli_exit(2); }
        if (strlen((string)preg_replace('/\D/', '', $phone)) < 9) { fwrite(STDERR, "Nieprawidłowy telefon: {$phone}\n"); cli_exit(2); }
        if ($pass !== '' && mb_strlen($pass) < 8) { fwrite(STDERR, "Hasło panelu musi mieć co najmniej 8 znaków.\n"); cli_exit(2); }
        $k = $find();
        if ($k) {
            db_exec("UPDATE k30_clients SET email = ?, phone = ? WHERE id = ?", [$email, $phone, (int)$k['client_id']]);
            if ($pass !== '') db_exec("UPDATE k30_ti_student_accounts SET password_hash = ?, must_change_password = 0, is_active = 1 WHERE id = ?",
                                      [password_hash($pass, PASSWORD_DEFAULT), (int)$k['account_id']]);
            echo "Kursant już był (nr {$k['client_id']}) — zaktualizowano e-mail i telefon" . ($pass !== '' ? ' oraz hasło panelu' : '') . ".\n";
            $cid = (int)$k['client_id'];
        } else {
            if (db_one("SELECT id FROM k30_ti_student_accounts WHERE login = ?", [SKP_LOGIN])) {
                fwrite(STDERR, "Login " . SKP_LOGIN . " ma już inny kursant — nie ruszam go.\n"); cli_exit(2);
            }
            $shown = '';
            if ($pass === '') { $pass = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Kq'), '='); $shown = $pass; }
            $cid = db_insert('k30_clients', ['name' => SKP_NAME, 'email' => $email, 'phone' => $phone, 'status' => 'enrolled']);
            db_insert('k30_ti_student_accounts', ['client_id' => $cid, 'login' => SKP_LOGIN, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'is_active' => 1, 'must_change_password' => 0]);
            echo "Utworzono kursanta „" . SKP_NAME . "” (nr {$cid}), login panelu: " . SKP_LOGIN . ".\n";
            if ($shown !== '') echo "Hasło panelu (losowe, pokazujemy je tylko teraz): {$shown}\n";
            // Konto bez last_login: pierwsze logowanie idzie kodem SMS (ti_student_needs_reauth), potem kursant ustawia własne hasło.
            echo "Logowanie kursanta: " . rtrim((string)APP_URL, '/') . "/karty30/ti/login.php — login " . SKP_LOGIN
               . ", dowolne hasło; pierwsze logowanie kodem SMS na {$phone}, potem własne hasło.\n";
        }

        // Grupa testowa i aktywny zapis — kursant jest „aktualny” (XLSX loginów)
        $c = db_one("SELECT id FROM k30_ti_courses WHERE name = ?", [SKP_COURSE]);
        $courseId = $c ? (int)$c['id'] : db_insert('k30_ti_courses', ['name' => SKP_COURSE, 'group_code' => SKP_GROUP,
            'description' => 'Grupa do testów poczty kursanta — seeder cli/seedKursantPoczta.php.', 'is_active' => 1, 'status' => 'active']);
        if (!db_one("SELECT id FROM k30_ti_enrollments WHERE course_id = ? AND client_id = ?", [$courseId, $cid])) {
            db_insert('k30_ti_enrollments', ['course_id' => $courseId, 'client_id' => $cid, 'hourly_rate' => 0, 'status' => 'active']);
            echo "Zapisano do grupy " . SKP_GROUP . ".\n";
        }

        // Poczta kursanta (opcjonalnie) i wysyłka danych
        $u = function_exists('current_user') ? current_user() : null;
        $by = 'Seeder testowego kursanta' . ($u ? ' (' . ($u['name'] ?? '') . ')' : '');
        $uid = (int)($u['id'] ?? 0);
        if (($kEmail = $val('kadra')) !== '') {
            $ku = db_one("SELECT id, name, role FROM users WHERE LOWER(email) = LOWER(?) AND is_active = 1", [$kEmail]);
            if (!$ku || !in_array((string)$ku['role'], ['admin', 'editor'], true)) {
                fwrite(STDERR, "--kadra: brak aktywnego konta admin/editor o adresie {$kEmail}.\n"); cli_exit(2);
            }
            $uid = (int)$ku['id'];
            $by  = 'Seeder testowego kursanta (' . $ku['name'] . ')';
        }
        $mLogin = $val('poczta-login');
        $mPass  = $val('poczta-haslo');
        try {
            if ($mLogin !== '') echo PocztaStud::save($cid, $mLogin, $mPass, $by, $uid) . "\n";
            if (!empty($opt['wyslij'])) {
                if (!PocztaStud::get($cid)) { fwrite(STDERR, "Do wysyłki potrzebny jest wpis poczty: podaj --poczta-login.\n"); cli_exit(2); }
                echo PocztaStud::sendLoginData($cid, $by, $uid, $mPass) . "\n";
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Poczta: ' . $e->getMessage() . "\n");
            cli_exit(1);
        }
        echo "\n";
        $status();
        break;

    case 'delete':
        $k = $find();
        if (!$k) { echo "Nic do usunięcia — brak testowego kursanta.\n"; break; }
        $cid = (int)$k['client_id'];
        if (PocztaStud::get($cid)) echo PocztaStud::remove($cid, 'Seeder testowego kursanta') . "\n";
        db_exec("DELETE FROM k30_ti_enrollments WHERE client_id = ?", [$cid]);
        db_exec("DELETE FROM k30_ti_student_accounts WHERE id = ?", [(int)$k['account_id']]);
        db_exec("DELETE FROM k30_clients WHERE id = ? AND name = ?", [$cid, SKP_NAME]);
        echo "Usunięto kursanta „" . SKP_NAME . "” (nr {$cid}) i jego konto panelu.\n";
        $c = db_one("SELECT id FROM k30_ti_courses WHERE name = ?", [SKP_COURSE]);
        if ($c && !db_one("SELECT id FROM k30_ti_enrollments WHERE course_id = ? LIMIT 1", [(int)$c['id']])) {
            db_exec("DELETE FROM k30_ti_courses WHERE id = ?", [(int)$c['id']]);
            echo "Usunięto pustą grupę " . SKP_GROUP . ".\n";
        }
        break;

    default:
        fwrite(STDERR, $usage);
        cli_exit(2);
}
