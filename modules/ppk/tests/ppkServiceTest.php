<?php
/**
 * modules/ppk/tests/ppkServiceTest.php — test integracyjny PpkService na KOPII bazy.
 * Użycie: php modules/ppk/tests/ppkServiceTest.php /ścieżka/do/kopii.db   (bez argumentu odmawia — nie dotyka prawdziwej bazy).
 */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_file($argv[1])) { fwrite(STDERR, "Podaj ścieżkę do KOPII bazy jako argument.\n"); exit(2); }
define('DB_PATH', $argv[1]);
$root = dirname(__DIR__, 3);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/PpkService.php';

$fails = 0;
function ok(string $n, bool $c, string $extra = ''): void { global $fails; echo ($c ? 'PASS ' : 'FAIL ') . $n . ($c ? '' : "  [$extra]") . "\n"; if (!$c) $fails++; }
function throws(string $n, callable $f, string $contains = ''): void {
    try { $f(); ok($n, false, 'brak wyjątku'); }
    catch (InvalidArgumentException $e) { ok($n, $contains === '' || str_contains($e->getMessage(), $contains), $e->getMessage()); }
}

PpkService::migrate();
db()->prepare("INSERT INTO umowy_praca (numer_umowy, status, imie_nazwisko, pesel, stanowisko, wynagrodzenie_brutto, ppk, addr_street, addr_house, addr_postal, addr_city)
               VALUES ('TEST-PPK/1', 'obowiązująca', 'Jan Testowy', '90010112345', 'Specjalista', '5000,00', 0, 'Długa', '5', '00-001', 'Warszawa')")->execute();
$cid = (int)db()->lastInsertId();
$flag = static fn(): int => (int)db_one("SELECT ppk FROM umowy_praca WHERE id=?", [$cid])['ppk'];

// ── historia ustawień
PpkService::addVersion($cid, '2026-01-01', PpkCalculator::STATUS_PARTICIPANT, new PpkRates(), 'reczna', null, '', 1);
ok('uczestnik → flaga ppk=1 w umowie', $flag() === 1);
throws('stawka poza limitem odrzucona', fn() => PpkService::addVersion($cid, '2026-02-01', 'uczestnik', new PpkRates(200, 300), 'reczna', null, '', 1), '2%');
throws('zła data', fn() => PpkService::addVersion($cid, '2026-13-40', 'uczestnik', new PpkRates(), 'reczna', null, '', 1), 'datę');
throws('zły status', fn() => PpkService::addVersion($cid, '2026-02-01', 'xyz', null, 'reczna', null, '', 1), 'status');
throws('nieistniejąca umowa', fn() => PpkService::addVersion(999999, '2026-02-01', 'uczestnik', null, 'reczna', null, '', 1), 'umowy');
PpkService::addVersion($cid, '2026-01-01', 'uczestnik', new PpkRates(100, 100, 150, 100), 'reczna', null, 'poprawka', 1);
ok('ta sama data zastępuje wersję', count(PpkService::versions($cid)) === 1 && (int)PpkService::versions($cid)[0]['employee_add_bp'] === 100);
PpkService::addVersion($cid, '2026-01-01', 'uczestnik', new PpkRates(), 'reczna', null, '', 1);

// ── PPKR: numeracja, złożenie, skutek w ustawieniach
$r1 = PpkService::createDeclaration($cid, 'PPKR', null, 1);
$r2 = PpkService::createDeclaration($cid, 'PPKR', null, 1);
$n1 = PpkService::declaration($r1)['number']; $n2 = PpkService::declaration($r2)['number'];
ok('numer PPKR/NNNN/RRRR', (bool)preg_match('#^PPKR/\d{4}/' . date('Y') . '$#', $n1), $n1);
ok('numery rosną', (int)substr($n2, 5, 4) === (int)substr($n1, 5, 4) + 1, "$n1 $n2");
ok('snapshot bez PESEL', !str_contains(PpkService::declaration($r1)['snapshot'], '90010112345'));
ok('snapshot z adresem', str_contains(PpkService::declaration($r1)['snapshot'], 'Warszawa'));
throws('elektroniczna bez potwierdzenia', fn() => PpkService::registerSubmission($r1, '2026-03-15', 'elektroniczna', '', null, 1), 'potwierdzenie');
throws('data w przyszłości', fn() => PpkService::registerSubmission($r1, date('Y-m-d', strtotime('+3 days')), 'papierowa', '', null, 1), 'przyszłości');
throws('zła forma', fn() => PpkService::registerSubmission($r1, '2026-03-15', 'ustna', '', null, 1), 'formę');
PpkService::registerSubmission($r1, '2026-03-15', 'elektroniczna', 'msg-id 4711', null, 1);
ok('PPKR złożona', PpkService::declaration($r1)['status'] === 'zlozona' && PpkService::declaration($r1)['form'] === 'elektroniczna');
ok('rezygnacja dopisana jako wersja', PpkService::current($cid, '2026-03-15')['status'] === 'rezygnacja');
ok('przed rezygnacją nadal uczestnik', PpkService::current($cid, '2026-03-14')['status'] === 'uczestnik');
ok('flaga ppk=0 po rezygnacji', $flag() === 0);
ok('wersja wskazuje deklarację', (int)PpkService::current($cid, '2026-03-15')['declaration_id'] === $r1);
throws('ponowne złożenie tego samego druku', fn() => PpkService::registerSubmission($r1, '2026-03-16', 'papierowa', '', null, 1), 'już');
PpkService::cancelDeclaration($r2, 1);
ok('szkic anulowany', PpkService::declaration($r2)['status'] === 'anulowana');
throws('złożonego nie anulujemy', fn() => PpkService::cancelDeclaration($r1, 1), 'tylko druk');
throws('anulowanego nie składamy', fn() => PpkService::registerSubmission($r2, '2026-03-16', 'papierowa', '', null, 1), 'już');

// ── podgląd wpłat po rezygnacji w trakcie miesiąca
$m = PpkService::previewMonth($cid, '2026-03', [['date' => '2026-03-10', 'base_gr' => 500000], ['date' => '2026-03-20', 'base_gr' => 500000]]);
ok('podgląd: wypłata przed rezygnacją z wpłatami', $m['payments'][0]['employee_total'] === 10000);
ok('podgląd: wypłata po rezygnacji bez wpłat', $m['payments'][1]['total_to_fund'] === 0);
$m = PpkService::previewMonth($cid, '2026-02');
ok('podgląd domyślny: brutto z umowy (5000 zł) → pracownik 100 zł', $m['employee_total'] === 10000 && $m['employer_taxable_income'] === 7500, json_encode($m['employee_total']));

// ── PPKP: przystąpienie ze stawkami z druku
throws('PPKP ze stawką poza limitem', fn() => PpkService::createDeclaration($cid, 'PPKP', new PpkRates(200, 250), 1), '2%');
$p1 = PpkService::createDeclaration($cid, 'PPKP', new PpkRates(100, 50, 150, 100), 1);
PpkService::registerSubmission($p1, '2026-05-04', 'papierowa', '', '2026-06-01', 1);
$cur = PpkService::current($cid, '2026-06-01');
ok('PPKP: uczestnik od daty obowiązywania', $cur['status'] === 'uczestnik' && $cur['effective_from'] === '2026-06-01');
ok('PPKP: stawki z druku', (int)$cur['employee_base_bp'] === 100 && (int)$cur['employee_add_bp'] === 50 && (int)$cur['employer_add_bp'] === 100);
ok('PPKP: między złożeniem a obowiązywaniem nadal rezygnacja', PpkService::current($cid, '2026-05-20')['status'] === 'rezygnacja');
ok('flaga ppk=1 po przystąpieniu (od dziś)', $flag() === 1);
ok('PPKP numerowane osobno', str_starts_with(PpkService::declaration($p1)['number'], 'PPKP/0001/'));

// ── autozapis
db()->prepare("INSERT INTO umowy_praca (numer_umowy, status, imie_nazwisko, wynagrodzenie_brutto, ppk) VALUES ('TEST-PPK/2','obowiązująca','Anna Rezygnująca','4000,00',0)")->execute();
$c2 = (int)db()->lastInsertId();
PpkService::addVersion($c2, '2024-05-10', 'rezygnacja', null, 'reczna', null, '', 1);
ok('autozapis przed terminem: nic', PpkService::autoEnroll($c2, '2027-02-28') === null);
$vid = PpkService::autoEnroll($c2, '2027-03-05');
ok('autozapis po terminie: nowa wersja', $vid !== null && PpkService::current($c2, '2027-03-01')['status'] === 'uczestnik' && PpkService::current($c2, '2027-03-01')['source'] === 'autozapis');
ok('autozapis: data terminu, nie dzień uruchomienia', PpkService::current($c2, '2027-03-05')['effective_from'] === '2027-03-01');
ok('autozapis: stawki domyślne 2% / 1,5%', (int)PpkService::current($c2, '2027-03-05')['employee_base_bp'] === 200 && (int)PpkService::current($c2, '2027-03-05')['employer_base_bp'] === 150);
ok('autozapis idempotentny', PpkService::autoEnroll($c2, '2027-03-06') === null);
db()->prepare("INSERT INTO umowy_praca (numer_umowy, status, imie_nazwisko) VALUES ('TEST-PPK/3','rozwiązana','Ktoś Zwolniony')")->execute();
$c3 = (int)db()->lastInsertId();
PpkService::addVersion($c3, '2024-05-10', 'rezygnacja', null, 'reczna', null, '', 1);
ok('autozapis pomija umowy nieobowiązujące', PpkService::autoEnroll($c3, '2027-03-05') === null);
db()->prepare("INSERT INTO umowy_praca (numer_umowy, status, imie_nazwisko) VALUES ('TEST-PPK/4','obowiązująca','Nie Podlega')")->execute();
$c4 = (int)db()->lastInsertId();
PpkService::addVersion($c4, '2024-05-10', 'niepodlegajacy', null, 'reczna', null, '', 1);
ok('niepodlegający nie jest zapisywany ponownie', PpkService::autoEnroll($c4, '2031-03-05') === null);
ok('autoEnrollAll: kolejna umowa do zapisu', PpkService::autoEnrollAll('2027-03-05') === 0);
PpkService::addVersion($c4, '2024-06-01', 'rezygnacja', null, 'reczna', null, '', 1);
ok('autoEnrollAll zapisuje należne', PpkService::autoEnrollAll('2027-03-05') === 1);

// ── audyt bez PESEL
$leak = db_one("SELECT COUNT(*) n FROM audit_logs WHERE action LIKE 'ppk.%' AND details LIKE '%90010112345%'");
ok('audyt PPK bez PESEL', (int)$leak['n'] === 0);
ok('audyt PPK zapisuje zdarzenia', (int)db_one("SELECT COUNT(*) n FROM audit_logs WHERE action LIKE 'ppk.%'")['n'] > 5);

echo $fails ? "\nBŁĘDY: $fails\n" : "\nWSZYSTKO OK\n";
exit($fails ? 1 : 0);
