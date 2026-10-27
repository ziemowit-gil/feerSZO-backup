<?php
/**
 * modules/ppk/lib/PpkCalculator.php — obliczenia wpłat do PPK (Pracownicze Plany Kapitałowe). Czysta logika: bez bazy, bez sesji.
 *
 * Kwoty w GROSZACH (int), stawki w PUNKTACH BAZOWYCH = setnych częściach procenta (2% = 200, 1,5% = 150) — żadnych liczb zmiennoprzecinkowych
 * w rachunku. Każdy składnik zaokrąglamy do pełnego grosza (połówka w górę).
 *
 * Podstawa (BaseGr) to wynagrodzenie będące podstawą wymiaru składek emerytalnej i rentowej, BEZ ograniczenia rocznego 30-krotności —
 * podstawę podaje wywołujący (SZO nie ma jeszcze silnika list płac; ta klasa jest gotowa do wpięcia w niego).
 *
 * Model obowiązywania: historia wersji [effective_from, status, stawki]. Do wypłaty (daty naliczenia $asOf) stosujemy wersję obowiązującą
 * w tym dniu — dlatego zmiana stawki czy rezygnacja w trakcie miesiąca dotyczy tylko wypłat z datą od zmiany, a wcześniejsze wypłaty
 * tego samego miesiąca liczą się po starych zasadach. Miesiąc = suma wypłat, każda liczona osobno.
 *
 * Założenia do weryfikacji z prawnikiem/kadrami (zostały tu jawnie, bo wynikają z interpretacji, nie z kodu):
 *  - rezygnacja działa od daty złożenia deklaracji włącznie (wypłaty z datą ≥ daty rezygnacji nie mają wpłat);
 *  - ponowny autozapis co 4 lata od 2023 (2023, 2027, 2031…), w miesiącu z ustawienia (domyślnie marzec — jak w zleceniu), pierwszego dnia miesiąca;
 *  - składka pracodawcy (podstawowa + dodatkowa) jest przychodem pracownika ze stosunku pracy (PIT), nie wchodzi do podstawy ZUS;
 *    sam podatek (zaliczka) należy do silnika płac — tu zwracamy przychód.
 */

final class PpkRates
{
    public const EMPLOYEE_BASE_MIN_BP = 50;    // 0,5% — obniżona wpłata podstawowa pracownika (dla niskich wynagrodzeń)
    public const EMPLOYEE_BASE_MAX_BP = 200;   // 2%
    public const EMPLOYEE_ADD_MAX_BP  = 200;   // dodatkowa pracownika: do 2%
    public const EMPLOYER_BASE_BP     = 150;   // 1,5% — ustawowa, stała
    public const EMPLOYER_ADD_MAX_BP  = 250;   // dodatkowa pracodawcy: do 2,5%

    public function __construct(
        public readonly int $employeeBaseBp = self::EMPLOYEE_BASE_MAX_BP,
        public readonly int $employeeAddBp = 0,
        public readonly int $employerBaseBp = self::EMPLOYER_BASE_BP,
        public readonly int $employerAddBp = 0,
    ) {}

    /** @return list<string> komunikaty o przekroczeniu limitów; pusta lista = stawki poprawne */
    public function errors(): array
    {
        $e = [];
        if ($this->employeeBaseBp < self::EMPLOYEE_BASE_MIN_BP || $this->employeeBaseBp > self::EMPLOYEE_BASE_MAX_BP) {
            $e[] = 'Wpłata podstawowa pracownika musi mieścić się w przedziale 0,5–2%.';
        }
        if ($this->employeeAddBp < 0 || $this->employeeAddBp > self::EMPLOYEE_ADD_MAX_BP) {
            $e[] = 'Wpłata dodatkowa pracownika: od 0 do 2%.';
        }
        if ($this->employerBaseBp !== self::EMPLOYER_BASE_BP) {
            $e[] = 'Wpłata podstawowa pracodawcy wynosi ustawowo 1,5%.';
        }
        if ($this->employerAddBp < 0 || $this->employerAddBp > self::EMPLOYER_ADD_MAX_BP) {
            $e[] = 'Wpłata dodatkowa pracodawcy: od 0 do 2,5%.';
        }
        return $e;
    }

    /** @return array{employee_base_bp:int,employee_add_bp:int,employer_base_bp:int,employer_add_bp:int} */
    public function toArray(): array
    {
        return ['employee_base_bp' => $this->employeeBaseBp, 'employee_add_bp' => $this->employeeAddBp,
                'employer_base_bp' => $this->employerBaseBp, 'employer_add_bp' => $this->employerAddBp];
    }

    public static function fromArray(array $a): self
    {
        return new self((int)($a['employee_base_bp'] ?? self::EMPLOYEE_BASE_MAX_BP), (int)($a['employee_add_bp'] ?? 0),
                        (int)($a['employer_base_bp'] ?? self::EMPLOYER_BASE_BP), (int)($a['employer_add_bp'] ?? 0));
    }

    /** „1,5” / „0.5” / „2” → 150 / 50 / 200; null gdy nie liczba albo więcej niż 2 miejsca po przecinku. */
    public static function percentToBp(string $s): ?int
    {
        $s = trim(str_replace([',', '%', ' '], ['.', '', ''], $s));
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s)) return null;
        return (int)round((float)$s * 100);
    }

    public static function bpToPercent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',') ?: '0';
    }
}

final class PpkCalculator
{
    public const STATUS_PARTICIPANT = 'uczestnik';
    public const STATUS_RESIGNED    = 'rezygnacja';
    public const STATUS_EXEMPT      = 'niepodlegajacy';
    public const STATUSES = [self::STATUS_PARTICIPANT, self::STATUS_RESIGNED, self::STATUS_EXEMPT];

    /** Pierwszy rok cyklu ponownego autozapisu; kolejne co 4 lata. */
    public const AUTO_ENROLL_ANCHOR_YEAR = 2023;
    public const AUTO_ENROLL_EVERY_YEARS = 4;

    /** Wpłata = podstawa × stawka, zaokrąglona do grosza (połówka w górę), w arytmetyce całkowitej. */
    public static function contribution(int $baseGr, int $rateBp): int
    {
        if ($baseGr <= 0 || $rateBp <= 0) return 0;
        return intdiv($baseGr * $rateBp * 2 + 10000, 20000);
    }

    /**
     * Wpłaty od jednej podstawy po podanych stawkach.
     * @return array{employee_base:int,employee_add:int,employee_total:int,employer_base:int,employer_add:int,employer_total:int,employer_taxable_income:int,total_to_fund:int}
     */
    public static function calculate(int $baseGr, PpkRates $r): array
    {
        $eb = self::contribution($baseGr, $r->employeeBaseBp);
        $ea = self::contribution($baseGr, $r->employeeAddBp);
        $rb = self::contribution($baseGr, $r->employerBaseBp);
        $ra = self::contribution($baseGr, $r->employerAddBp);
        return [
            'employee_base' => $eb, 'employee_add' => $ea, 'employee_total' => $eb + $ea,
            'employer_base' => $rb, 'employer_add' => $ra, 'employer_total' => $rb + $ra,
            'employer_taxable_income' => $rb + $ra,     // przychód pracownika (PIT); poza podstawą ZUS
            'total_to_fund' => $eb + $ea + $rb + $ra,   // kwota przekazywana do instytucji finansowej
        ];
    }

    /**
     * Wersja obowiązująca w danym dniu: ostatnia o effective_from ≤ $date.
     * @param list<array{effective_from:string,status:string,rates:PpkRates}> $versions dowolna kolejność
     */
    public static function versionOn(array $versions, string $date): ?array
    {
        $best = null;
        foreach ($versions as $v) {
            if ($v['effective_from'] <= $date && ($best === null || $v['effective_from'] > $best['effective_from'])) $best = $v;
        }
        return $best;
    }

    /**
     * Wpłaty od jednej wypłaty z datą naliczenia $asOf. applied=false: brak wersji, rezygnacja albo osoba niepodlegająca — wpłaty zerowe.
     * @return array<string,mixed> wynik calculate() + applied, status, version_from
     */
    public static function calculatePayment(array $versions, string $asOf, int $baseGr): array
    {
        $v = self::versionOn($versions, $asOf);
        $applied = $v !== null && $v['status'] === self::STATUS_PARTICIPANT;
        $res = self::calculate($applied ? $baseGr : 0, $applied ? $v['rates'] : new PpkRates());
        return $res + ['applied' => $applied, 'status' => $v['status'] ?? null, 'version_from' => $v['effective_from'] ?? null];
    }

    /**
     * Wpłaty za miesiąc = suma wypłat, każda liczona osobno wg wersji obowiązującej w jej dniu.
     * @param list<array{date:string,base_gr:int}> $payments
     * @return array<string,mixed> sumy składników + payments (wynik per wypłata)
     */
    public static function calculateMonth(array $versions, array $payments): array
    {
        $sum = ['employee_base' => 0, 'employee_add' => 0, 'employee_total' => 0, 'employer_base' => 0, 'employer_add' => 0,
                'employer_total' => 0, 'employer_taxable_income' => 0, 'total_to_fund' => 0];
        $rows = [];
        foreach ($payments as $p) {
            $r = self::calculatePayment($versions, (string)$p['date'], (int)$p['base_gr']);
            foreach ($sum as $k => $_) $sum[$k] += $r[$k];
            $rows[] = ['date' => $p['date'], 'base_gr' => (int)$p['base_gr']] + $r;
        }
        return $sum + ['payments' => $rows];
    }

    /**
     * Najbliższa data ponownego autozapisu STRICTE po dacie rezygnacji: pierwszy dzień $month w roku z cyklu (2023, 2027, 2031…).
     */
    public static function nextAutoEnrollment(string $resignationDate, int $month = 3): string
    {
        $month = max(1, min(12, $month));
        $y = self::AUTO_ENROLL_ANCHOR_YEAR;
        while (true) {
            $d = sprintf('%04d-%02d-01', $y, $month);
            if ($d > $resignationDate) return $d;
            $y += self::AUTO_ENROLL_EVERY_YEARS;
        }
    }

    /**
     * Data, od której należy ponownie zapisać pracownika do PPK, jeśli już nadeszła: tylko gdy bieżąca wersja to REZYGNACJA
     * (osoby niepodlegające i uczestnicy nie są zapisywane ponownie).
     */
    public static function autoEnrollmentDue(array $versions, string $today, int $month = 3): ?string
    {
        $v = self::versionOn($versions, $today);
        if ($v === null || $v['status'] !== self::STATUS_RESIGNED) return null;
        $due = self::nextAutoEnrollment($v['effective_from'], $month);
        return $due <= $today ? $due : null;
    }

    /** „1234,56” / „1234.56” / „1 234,56” → 123456; null, gdy to nie kwota. */
    public static function toGrosze(string $amount): ?int
    {
        $s = str_replace([' ', "\u{00A0}"], '', trim($amount));
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
        [$zl, $gr] = array_pad(explode('.', $s, 2), 2, '0');
        return (int)$zl * 100 + (int)str_pad($gr, 2, '0');
    }

    public static function formatGr(int $gr): string
    {
        return number_format($gr / 100, 2, ',', ' ');
    }
}
