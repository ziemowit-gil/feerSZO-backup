<?php
/**
 * modules/ppk/tests/ppkCalculatorTest.php — testy jednostkowe obliczeń PPK (bez bazy). Uruchom: php modules/ppk/tests/ppkCalculatorTest.php
 * Kod wyjścia 0 = wszystko przeszło.
 */
require_once dirname(__DIR__) . '/lib/PpkCalculator.php';

$fails = 0; $n = 0;
function check(string $name, mixed $got, mixed $expected): void
{
    global $fails, $n; $n++;
    $ok = $got === $expected;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : '  got=' . json_encode($got) . ' expected=' . json_encode($expected)) . "\n";
    if (!$ok) $fails++;
}
$v = static fn(string $from, string $status, PpkRates $r = new PpkRates()): array => ['effective_from' => $from, 'status' => $status, 'rates' => $r];
$P = PpkCalculator::STATUS_PARTICIPANT; $R = PpkCalculator::STATUS_RESIGNED; $X = PpkCalculator::STATUS_EXEMPT;

// ── wpłaty podstawowe (2% + 1,5%) i przychód PIT pracodawcy
$c = PpkCalculator::calculate(500000, new PpkRates());   // 5000,00 zł
check('pracownik 2% od 5000 zł = 100,00', $c['employee_total'], 10000);
check('pracodawca 1,5% od 5000 zł = 75,00', $c['employer_total'], 7500);
check('przychód PIT = składka pracodawcy', $c['employer_taxable_income'], 7500);
check('do instytucji = pracownik + pracodawca', $c['total_to_fund'], 17500);

// ── zaokrąglanie do grosza (połówka w górę)
check('2% od 1234,56 = 24,69', PpkCalculator::contribution(123456, 200), 2469);
check('1,5% od 1234,56 = 18,52 (18,5184 → w górę)', PpkCalculator::contribution(123456, 150), 1852);
check('połówka grosza w górę: 1% od 0,50 zł', PpkCalculator::contribution(50, 100), 1);
check('tuż pod połówką w dół: 1% od 0,49 zł', PpkCalculator::contribution(49, 100), 0);
check('podstawa 0 → 0', PpkCalculator::contribution(0, 200), 0);
check('podstawa ujemna → 0 (korekta nie generuje ujemnej wpłaty)', PpkCalculator::contribution(-1000, 200), 0);

// ── stawki dodatkowe
$c = PpkCalculator::calculate(500000, new PpkRates(200, 200, 150, 250));
check('dodatkowa pracownika 2%', $c['employee_add'], 10000);
check('razem pracownik 4%', $c['employee_total'], 20000);
check('dodatkowa pracodawcy 2,5%', $c['employer_add'], 12500);
check('razem pracodawca 4%', $c['employer_total'], 20000);
check('przychód PIT obejmuje także dodatkową pracodawcy', $c['employer_taxable_income'], 20000);
$c = PpkCalculator::calculate(500000, new PpkRates(50, 0, 150, 0));
check('obniżona wpłata pracownika 0,5%', $c['employee_total'], 2500);

// ── limity stawek
check('stawki domyślne poprawne', (new PpkRates())->errors(), []);
check('pracownik podstawowa 0,4% — za nisko', count((new PpkRates(40))->errors()), 1);
check('pracownik podstawowa 2,01% — za wysoko', count((new PpkRates(201))->errors()), 1);
check('pracownik dodatkowa 2,01% — za wysoko', count((new PpkRates(200, 201))->errors()), 1);
check('pracodawca podstawowa ≠ 1,5%', count((new PpkRates(200, 0, 100))->errors()), 1);
check('pracodawca dodatkowa 2,51% — za wysoko', count((new PpkRates(200, 0, 150, 251))->errors()), 1);
check('granice dozwolone (0,5 / 2 / 2 / 2,5)', (new PpkRates(50, 200, 150, 250))->errors(), []);
check('kilka naruszeń naraz', count((new PpkRates(10, 999, 1, 999))->errors()), 4);
check('percentToBp 1,5', PpkRates::percentToBp('1,5'), 150);
check('percentToBp 0.5', PpkRates::percentToBp('0.5'), 50);
check('percentToBp 2%', PpkRates::percentToBp('2%'), 200);
check('percentToBp tekst', PpkRates::percentToBp('abc'), null);
check('percentToBp 3 miejsca', PpkRates::percentToBp('1.555'), null);
check('bpToPercent 150 → 1,5', PpkRates::bpToPercent(150), '1,5');
check('bpToPercent 200 → 2', PpkRates::bpToPercent(200), '2');
check('bpToPercent 0 → 0', PpkRates::bpToPercent(0), '0');

// ── rezygnacja: wypłaty od daty złożenia deklaracji bez wpłat, wcześniejsze z wpłatami (ten sam miesiąc)
$hist = [$v('2026-01-01', $P), $v('2026-03-15', $R)];
$m = PpkCalculator::calculateMonth($hist, [['date' => '2026-03-10', 'base_gr' => 300000], ['date' => '2026-03-20', 'base_gr' => 300000]]);
check('rezygnacja w trakcie miesiąca: wypłata przed — są wpłaty', $m['payments'][0]['applied'], true);
check('rezygnacja w trakcie miesiąca: wypłata po — brak wpłat', $m['payments'][1]['employee_total'] + $m['payments'][1]['employer_total'], 0);
check('rezygnacja: suma miesiąca = tylko wypłata sprzed', $m['employee_total'], 6000);
check('rezygnacja: przychód PIT pracodawcy tylko za wypłatę sprzed', $m['employer_taxable_income'], 4500);
check('rezygnacja w dniu wypłaty — brak wpłat (od daty złożenia włącznie)', PpkCalculator::calculatePayment($hist, '2026-03-15', 100000)['applied'], false);
check('dzień przed rezygnacją — są wpłaty', PpkCalculator::calculatePayment($hist, '2026-03-14', 100000)['applied'], true);
check('rezygnacja złożona przed naliczeniem listy', PpkCalculator::calculatePayment([$v('2026-03-01', $P), $v('2026-03-31', $R)], '2026-03-31', 100000)['employee_total'], 0);

// ── zmiana stawki w trakcie miesiąca
$hist = [$v('2026-01-01', $P, new PpkRates(200, 0, 150, 0)), $v('2026-03-16', $P, new PpkRates(100, 100, 150, 100))];
$m = PpkCalculator::calculateMonth($hist, [['date' => '2026-03-10', 'base_gr' => 400000], ['date' => '2026-03-25', 'base_gr' => 400000]]);
check('zmiana stawki: wypłata przed po starych stawkach (2%)', $m['payments'][0]['employee_total'], 8000);
check('zmiana stawki: wypłata po — nowe (1% + 1%)', $m['payments'][1]['employee_total'], 8000);
check('zmiana stawki: nowa dodatkowa pracodawcy 1%', $m['payments'][1]['employer_add'], 4000);
check('zmiana stawki: stara wypłata bez dodatkowej pracodawcy', $m['payments'][0]['employer_add'], 0);
check('zmiana stawki: suma przychodu PIT', $m['employer_taxable_income'], 6000 + 10000);

// ── brak wersji / osoba niepodlegająca / data przed pierwszą wersją
check('brak historii → brak wpłat', PpkCalculator::calculatePayment([], '2026-03-10', 100000)['applied'], false);
check('wypłata przed pierwszą wersją → brak wpłat', PpkCalculator::calculatePayment([$v('2026-05-01', $P)], '2026-04-30', 100000)['total_to_fund'], 0);
check('niepodlegający → brak wpłat', PpkCalculator::calculatePayment([$v('2026-01-01', $X)], '2026-03-10', 100000)['applied'], false);
check('kolejność wersji w tablicy nie ma znaczenia', PpkCalculator::versionOn([$v('2026-03-15', $R), $v('2026-01-01', $P)], '2026-04-01')['status'], $R);

// ── ponowny autozapis co 4 lata (2023, 2027, 2031…)
check('rezygnacja 2024 → autozapis 2027-03-01', PpkCalculator::nextAutoEnrollment('2024-05-10'), '2027-03-01');
check('rezygnacja przed autozapisem 2023 → 2023-03-01', PpkCalculator::nextAutoEnrollment('2023-02-01'), '2023-03-01');
check('rezygnacja dokładnie w dniu autozapisu → następny cykl', PpkCalculator::nextAutoEnrollment('2023-03-01'), '2027-03-01');
check('rezygnacja tuż po autozapisie 2027 → 2031', PpkCalculator::nextAutoEnrollment('2027-03-02'), '2031-03-01');
check('inny miesiąc autozapisu (kwiecień)', PpkCalculator::nextAutoEnrollment('2024-05-10', 4), '2027-04-01');
$rez = [$v('2024-05-10', $R)];
check('autozapis jeszcze nie nadszedł', PpkCalculator::autoEnrollmentDue($rez, '2027-02-28'), null);
check('autozapis w dniu terminu', PpkCalculator::autoEnrollmentDue($rez, '2027-03-01'), '2027-03-01');
check('autozapis po terminie (zaległy) zwraca datę terminu', PpkCalculator::autoEnrollmentDue($rez, '2027-06-15'), '2027-03-01');
check('uczestnik nie jest zapisywany ponownie', PpkCalculator::autoEnrollmentDue([$v('2024-01-01', $P)], '2031-03-01'), null);
check('niepodlegający nie jest zapisywany ponownie', PpkCalculator::autoEnrollmentDue([$v('2024-01-01', $X)], '2031-03-01'), null);
check('po autozapisie wpłaty wracają', PpkCalculator::calculatePayment([$v('2024-05-10', $R), $v('2027-03-01', $P)], '2027-03-31', 100000)['applied'], true);
check('między rezygnacją a autozapisem brak wpłat', PpkCalculator::calculatePayment([$v('2024-05-10', $R), $v('2027-03-01', $P)], '2026-12-31', 100000)['applied'], false);

// ── kwoty
check('toGrosze 1234,56', PpkCalculator::toGrosze('1234,56'), 123456);
check('toGrosze 1 234,5', PpkCalculator::toGrosze('1 234,5'), 123450);
check('toGrosze 5000', PpkCalculator::toGrosze('5000'), 500000);
check('toGrosze tekst', PpkCalculator::toGrosze('abc'), null);
check('toGrosze ujemna', PpkCalculator::toGrosze('-5'), null);
check('formatGr', PpkCalculator::formatGr(123456), '1 234,56');

echo "\n" . ($fails ? "BŁĘDY: $fails z $n\n" : "WSZYSTKO OK ($n)\n");
exit($fails ? 1 : 0);
