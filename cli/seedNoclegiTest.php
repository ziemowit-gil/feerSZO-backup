<?php
/**
 * cli/seedNoclegiTest.php — dane testowe modułu Noclegi (modules/noclegi): 3 konta, umowa z „Dop. noclegi”,
 * budżet z limitami, obiekt z pokojami (w tym dostępny), zgłoszenia we wszystkich statusach.
 *
 * Użycie:
 *   php cli/seedNoclegiTest.php create '<haslo>' [domena]   # idempotentne; hasła istniejących kont nie nadpisuje
 *   php cli/seedNoclegiTest.php status [domena]
 *   php cli/seedNoclegiTest.php delete [domena]             # kasuje WYŁĄCZNIE rekordy oznaczone (TEST) / TEST-NOCL
 *
 * Konta (domena domyślnie feer.test — RFC 2606, żaden e-mail nie wyjdzie): noclegi.uzytkownik@, noclegi.koordynator@,
 * noclegi.logistyk@. Żadnych wysyłek: nadawca modułu jest podstawiony atrapą, e-maile nie trafiają nawet do kolejki.
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }
$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) { fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n"); cli_exit(2); }
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/user_delete.php';
require_once $base . '/modules/noclegi/logic/noclegi.php';

$GLOBALS['NOCL_MAILER'] = fn() => true;   // bez wysyłek
const NCT_BUDGET = 'TEST-NOCL';
const NCT_FAC    = 'Pensjonat Testowy (TEST)';
$cmd = $argv[1] ?? '';
$usage = "Użycie:\n  php cli/seedNoclegiTest.php create '<haslo>' [domena]\n  php cli/seedNoclegiTest.php status [domena]\n  php cli/seedNoclegiTest.php delete [domena]\n";

$accounts = fn(string $dom) => [
    'uzytkownik'  => ['Noclegi Użytkownik (TEST)',  "noclegi.uzytkownik@$dom",  'viewer'],
    'koordynator' => ['Noclegi Koordynator (TEST)', "noclegi.koordynator@$dom", 'editor'],
    'logistyk'    => ['Noclegi Logistyk (TEST)',    "noclegi.logistyk@$dom",    'editor'],
];

switch ($cmd) {
    case 'create':
        $pw = $argv[2] ?? ''; $dom = $argv[3] ?? 'feer.test';
        if (strlen($pw) < 8) { fwrite(STDERR, "Podaj hasło (min. 8 znaków).\n" . $usage); cli_exit(2); }
        noclMigrate(); noclDictSeedRun();
        $ids = [];
        foreach ($accounts($dom) as $k => [$name, $email, $role]) {
            $u = db_one("SELECT id, name FROM users WHERE email = ?", [$email]);
            if ($u && $u['name'] !== $name) { fwrite(STDERR, "Konto $email istnieje, ale nie jest testowe — przerywam.\n"); cli_exit(1); }
            $ids[$k] = $u ? (int)$u['id'] : db_insert('users', ['name' => $name, 'email' => $email, 'password' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'is_active' => 1]);
        }
        // Umowa wolontariacka ze znacznikiem „Dop. noclegi” (bramka modułu)
        if (!db_one("SELECT 1 FROM umowy_wolontariat WHERE numer_umowy = 'TEST/NOCL/1'")) {
            db_insert('umowy_wolontariat', ['numer_umowy' => 'TEST/NOCL/1', 'status' => 'podpisana', 'imie_nazwisko' => 'Noclegi Użytkownik (TEST)',
                'email' => "noclegi.uzytkownik@$dom", 'data_rozpoczecia' => date('Y-m-d', strtotime('-30 days')), 'data_zakonczenia' => date('Y-m-d', strtotime('+365 days')),
                'dop_noclegi' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        }
        // Budżet, obiekt, pokoje
        $b = db_one("SELECT id FROM nocl_budgets WHERE code = ?", [NCT_BUDGET]);
        $bid = $b ? (int)$b['id'] : noclBudgetSave(['code' => NCT_BUDGET, 'name' => 'Projekt testowy (TEST)', 'limit_total' => '2000', 'limit_per_night' => '200', 'valid_from' => '', 'valid_to' => '', 'note' => 'Dane testowe', 'is_active' => 1]);
        noclRoleAssign($ids['koordynator'], 'koordynator', $bid, 0);
        noclRoleAssign($ids['logistyk'], 'logistyk', 0, 0);
        $f = db_one("SELECT id FROM nocl_facilities WHERE name = ?", [NCT_FAC]);
        if ($f) { $fid = (int)$f['id']; }
        else {
            [$fd] = noclFacilityValidate(['name' => NCT_FAC, 'type' => 'pensjonat', 'city' => 'Kraków', 'address' => 'ul. Testowa 1', 'phone' => '+48 12 000 00 00', 'checkin_from' => '15:00', 'checkin_to' => '21:00',
                'checkout_until' => '10:00', 'accessible' => 1, 'accessibility_info' => 'Winda, podjazd od podwórka.', 'access_info' => "Wejście od podwórka, skrytka na klucz przy furtce.", 'rules' => 'Cisza nocna 22:00–6:00.', 'is_active' => 1]);
            $fid = noclFacilitySave($fd);
            foreach ([['101', 'dwuosobowy', '120', '4821', 'Skrytka 3: 9911', 1], ['102', 'jednoosobowy', '150', '1357', 'Skrytka 4: 2468', 0], ['103', 'trzyosobowy', '90', '8642', 'Skrytka 5: 1122', 0]] as [$n, $t, $p, $c, $l, $acc]) {
                [$rd] = noclRoomValidate(['name' => $n, 'room_type' => $t, 'price_per_night' => $p, 'door_code' => $c, 'lockbox' => $l, 'accessible' => $acc, 'is_active' => 1]);
                noclRoomSave($fid, $rd);
            }
        }
        $rooms = array_column(noclRoomList($fid), 'id', 'name');
        // Zgłoszenia w każdym statusie (tylko raz)
        if (!db_one("SELECT 1 FROM nocl_requests WHERE purpose LIKE '[TEST]%'")) {
            $d = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
            $mk = fn(string $who, string $role, string $from, string $to, string $purpose, ?int $uid, array $x = []) => noclRequestCreate(
                noclRequestValidate(['person_name' => $who, 'person_role' => $role, 'person_email' => '', 'date_from' => $from, 'date_to' => $to, 'purpose' => '[TEST] ' . $purpose, 'budget_id' => $bid] + $x)[0],
                $uid, $ids['logistyk'], $uid ? 'form' : 'logistyk');
            $L = $ids['logistyk']; $K = $ids['koordynator'];
            $mk('Noclegi Użytkownik (TEST)', 'wolontariusz', $d(20), $d(22), 'Wniosek czekający', $ids['uzytkownik']);
            $q2 = $mk('Noclegi Użytkownik (TEST)', 'wolontariusz', $d(30), $d(33), 'W trakcie, pokój bez akceptacji kosztu', $ids['uzytkownik'], ['room_type_pref' => 'dwuosobowy']);
            noclTransition($q2, 'w_trakcie', $K); noclAssignRoom($q2, (int)$rooms['101'], $L);
            $q3 = $mk('Noclegi Użytkownik (TEST)', 'wolontariusz', $d(1), $d(3), 'Potwierdzone — kod widoczny', $ids['uzytkownik']);
            noclTransition($q3, 'w_trakcie', $K); noclAssignRoom($q3, (int)$rooms['103'], $L); noclApproveBudget($q3, $K); noclTransition($q3, 'potwierdzone', $L);
            $q4 = $mk('Dorota Wolontariuszka (TEST)', 'wolontariusz', $d(1), $d(2), 'Osoba bez konta, pokój dostępny', null, ['needs_accessible' => 1, 'accessibility_notes' => 'Porusza się na wózku.']);
            noclTransition($q4, 'w_trakcie', $L); noclAssignRoom($q4, (int)$rooms['101'], $L); noclApproveBudget($q4, $K); noclTransition($q4, 'potwierdzone', $L);
            $q5 = $mk('Noclegi Użytkownik (TEST)', 'wolontariusz', $d(-10), $d(-8), 'Zrealizowane', $ids['uzytkownik']);
            noclTransition($q5, 'w_trakcie', $K); noclAssignRoom($q5, (int)$rooms['102'], $L); noclApproveBudget($q5, $K); noclTransition($q5, 'potwierdzone', $L);
            db()->prepare("UPDATE nocl_requests SET status = 'zrealizowane' WHERE id = ?")->execute([$q5]);
            $q6 = $mk('Noclegi Użytkownik (TEST)', 'wolontariusz', $d(40), $d(41), 'Anulowane', $ids['uzytkownik']);
            noclTransition($q6, 'anulowane', $ids['uzytkownik']);
            [$inv] = noclInvoiceValidate(['number' => 'TEST/FV/1', 'category' => 'nocleg', 'amount' => '180', 'issue_date' => $d(-8), 'request_id' => $q5]);
            noclInvoiceCreate($inv, $L);
        }
        echo "OK dane testowe Noclegi gotowe (konta @$dom, budżet " . NCT_BUDGET . ", obiekt " . NCT_FAC . ").\n";
        echo "Logowanie: noclegi.uzytkownik@$dom / noclegi.koordynator@$dom / noclegi.logistyk@$dom (hasło z argumentu).\n";
        echo "Usunięcie: php cli/seedNoclegiTest.php delete\n";
        cli_exit(0);

    case 'status':
        $dom = $argv[2] ?? 'feer.test';
        noclMigrate();
        foreach ($accounts($dom) as $k => [$name, $email]) { $u = db_one("SELECT id FROM users WHERE email = ? AND name = ?", [$email, $name]); echo str_pad($email, 40) . ($u ? 'id=' . $u['id'] : 'brak') . "\n"; }
        echo 'Zgłoszeń testowych: ' . (int)db_one("SELECT COUNT(*) c FROM nocl_requests WHERE purpose LIKE '[TEST]%'")['c'] . "\n";
        echo 'Obiekt: ' . (db_one("SELECT id FROM nocl_facilities WHERE name = ?", [NCT_FAC]) ? 'jest' : 'brak') . ', budżet: ' . (db_one("SELECT id FROM nocl_budgets WHERE code = ?", [NCT_BUDGET]) ? 'jest' : 'brak') . "\n";
        cli_exit(0);

    case 'delete':
        $dom = $argv[2] ?? 'feer.test';
        noclMigrate();
        $reqs = array_column(db_all("SELECT id FROM nocl_requests WHERE purpose LIKE '[TEST]%'"), 'id');
        foreach ($reqs as $id) {
            db()->prepare("DELETE FROM nocl_invoices WHERE request_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM nocl_log WHERE request_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM nocl_requests WHERE id = ?")->execute([$id]);
        }
        $b = db_one("SELECT id FROM nocl_budgets WHERE code = ?", [NCT_BUDGET]);
        if ($b) { db()->prepare("DELETE FROM nocl_user_roles WHERE budget_id = ?")->execute([$b['id']]); db()->prepare("DELETE FROM nocl_budgets WHERE id = ?")->execute([$b['id']]); }
        $f = db_one("SELECT id FROM nocl_facilities WHERE name = ?", [NCT_FAC]);
        if ($f) { db()->prepare("DELETE FROM nocl_rooms WHERE facility_id = ?")->execute([$f['id']]); db()->prepare("DELETE FROM nocl_facilities WHERE id = ?")->execute([$f['id']]); }
        db()->prepare("DELETE FROM umowy_wolontariat WHERE numer_umowy = 'TEST/NOCL/1'")->execute();
        foreach ($accounts($dom) as [$name, $email]) {
            $u = db_one("SELECT id FROM users WHERE email = ? AND name = ?", [$email, $name]);
            if (!$u) continue;
            db()->prepare("DELETE FROM nocl_user_roles WHERE user_id = ?")->execute([$u['id']]);
            $res = user_delete_execute((int)$u['id'], 0, 'Seeder noclegi — sprzątanie po testach');
            echo ($res['ok'] ? 'USUNIĘTO ' : 'BŁĄD ') . $email . ': ' . $res['msg'] . "\n";
        }
        echo 'Usunięto zgłoszeń: ' . count($reqs) . ".\n";
        cli_exit(0);

    default:
        fwrite(STDERR, $usage); cli_exit(2);
}
