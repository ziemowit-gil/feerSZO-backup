<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseOperationDefs.php — definicje rejestru operacji na licencjach (cykl życia zasobów cyfrowych).
 *
 * Wnioski:  AM11 — licencja finansowana przez Fundację FEER (weryfikacja budżetu, provisioning),
 *           AM12 — zwrot kosztów / refaktura licencji (weryfikacja dowodu zakupu),
 *           AM13 — przypisanie licencji finansowanej zewnętrznie (grant / partner / własna).
 * Operacje na JUŻ PRZYPISANYCH licencjach (kody GEN):
 *           AM20 — zmiana płatnika / budżetu (BEZ akceptacji: wskazuje się doliczenie do zajęć z kwotą albo rachunek i kwotę wpłaty),
 *           AM21 — zmiana planu / upgrade / downgrade / add-on, AM22 — zawieszenie / reasygnacja,
 *           AM23 — cofnięcie / deprovisioning (polityka danych), AM24 — przedłużenie / odnowienie.
 * Numer sprawy: 4000-{symbol}/734350539/{6 cyfr}{2 losowe litery}/{rok}/{SubID = nr kursu/nr kursanta}.
 * Pola formularzy (wniosek i weryfikacja) są danymi — ten sam opis rysuje formularz w panelu i druk w układzie CIT.
 */
require_once __DIR__ . '/ldSchema.php';

const LD_OP_PREFIX = '4000';
const LD_OP_ORG_ID = '734350539';

enum OperationForm: string
{
    case Am11 = 'AM11';
    case Am12 = 'AM12';
    case Am13 = 'AM13';
    case Am20 = 'AM20';
    case Am21 = 'AM21';
    case Am22 = 'AM22';
    case Am23 = 'AM23';
    case Am24 = 'AM24';

    /** Kod operacji w rekordzie JSON (AM20-GEN … AM24-GEN; wnioski AM11–13 bez sufiksu). */
    public function actionType(): string
    {
        return $this->lifecycle() ? $this->value . '-GEN' : $this->value;
    }

    public function lifecycle(): bool
    {
        return in_array($this, [self::Am20, self::Am21, self::Am22, self::Am23, self::Am24], true);
    }

    /** Operacja dotyczy konkretnej, już przypisanej licencji. */
    public function needsResource(): bool
    {
        return $this->lifecycle();
    }

    /** AM20 nie wymaga akceptacji — wykonuje się od razu po złożeniu (stempel składającego). */
    public function needsApproval(): bool
    {
        return $this !== self::Am20;
    }

    public function title(): string
    {
        return match ($this) {
            self::Am11 => 'Wniosek o licencję finansowaną przez Fundację FEER',
            self::Am12 => 'Wniosek o zwrot kosztów lub refakturę licencji',
            self::Am13 => 'Wniosek o przypisanie licencji finansowanej zewnętrznie',
            self::Am20 => 'Wniosek o zmianę sposobu płatności za posiadaną licencję',
            self::Am21 => 'Modyfikacja planu licencji (upgrade / downgrade / add-on)',
            self::Am22 => 'Zawieszenie lub reasygnacja licencji',
            self::Am23 => 'Cofnięcie licencji (deprovisioning)',
            self::Am24 => 'Przedłużenie / odnowienie licencji',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Am11 => 'Licencja z budżetu FEER',
            self::Am12 => 'Zwrot kosztów / refaktura',
            self::Am13 => 'Licencja finansowana zewnętrznie',
            self::Am20 => 'Zmiana płatnika',
            self::Am21 => 'Zmiana planu / SKU',
            self::Am22 => 'Zawieszenie / reasygnacja',
            self::Am23 => 'Cofnięcie licencji',
            self::Am24 => 'Przedłużenie',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Am11, self::Am12, self::Am13 => '#0050ef',
            self::Am20 => '#008a00',
            self::Am21 => '#1ba1e2',
            self::Am22 => '#f0a30a',
            self::Am23 => '#a20025',
            self::Am24 => '#6a00ff',
        };
    }

    /** Oświadczenie wnioskodawcy drukowane pod polami wniosku (treść wzoru). */
    public function statement(): string
    {
        return match ($this) {
            self::Am11 => 'Proszę o przyznanie licencji wskazanej powyżej na potrzeby kursu lub działalności dydaktycznej. Przyjmuję do wiadomości, że realizacja zakupu lub przypisanie licencji następuje po weryfikacji budżetu i zatwierdzeniu wniosku.',
            self::Am12 => 'Wnoszę o rozliczenie wskazanego kosztu licencji. Oświadczam, że załączone dokumenty dotyczą opisanego zakupu; w przypadku wniosku o zwrot wskazuję koszt rzeczywiście poniesiony, a w przypadku refaktury — podmiot, którego dotyczy wnioskowane rozliczenie.',
            self::Am13 => 'Proszę o weryfikację możliwości przypisania lub aktywacji wskazanej licencji. Przyjmuję do wiadomości, że aktywacja w tenancie ośrodka może nastąpić wyłącznie po potwierdzeniu uprawnienia, zgodności licencji z wnioskowanym sposobem użycia oraz możliwości technicznych i zasad placówki.',
            self::Am20 => 'Wnoszę o zmianę wyłącznie płatnika, źródła finansowania lub sposobu rozliczenia wskazanej, już posiadanej licencji. Zmiana płatności sama przez się nie przenosi licencji ani praw z niej wynikających, nie zmienia licencjobiorcy, użytkownika końcowego ani właściciela lub administratora konta/tenantu, i nie upoważnia nowego płatnika do korzystania z licencji, dysponowania kontem ani udzielania dalszych uprawnień. Pozostałe warunki licencji pozostają bez zmian. Każda odrębna zmiana tych podmiotów lub uprawnień wymaga osobnej podstawy i wymaganych zgód, a także zgodności z umową oraz warunkami dostawcy.',
            self::Am21 => 'Wnoszę o zmianę poziomu wskazanej licencji w opisanym zakresie. Przyjmuję do wiadomości, że zmiana zależy od dostępności planu u dostawcy oraz finansowania.',
            self::Am22 => 'Wnoszę o czasowe odebranie lub przekazanie wskazanej licencji. Przyjmuję do wiadomości, że w okresie zawieszenia dostęp do usług może być ograniczony zgodnie z warunkami dostawcy i wskazanymi wytycznymi dotyczącymi danych.',
            self::Am23 => 'Wnoszę o całkowite wycofanie wskazanej licencji. Przyjmuję do wiadomości, że po wycofaniu dostęp do produktu, konta i zapisanych danych może zostać utracony; dane zostaną potraktowane zgodnie ze wskazaną polityką.',
            self::Am24 => 'Wnoszę o przedłużenie okresu ważności wskazanej licencji na kolejny cykl dydaktyczny lub projektowy.',
        };
    }

    /** Skutek decyzji drukowany w sekcji weryfikacji (AM20 — tekst wzoru). */
    public function effect(): string
    {
        return $this === self::Am20
            ? 'Zatwierdzenie zmienia wyłącznie sposób płatności i nie stanowi przeniesienia licencji ani praw do konta; nie zmienia licencjobiorcy, użytkownika końcowego ani właściciela/administratora konta, chyba że odrębna decyzja wyraźnie stanowi inaczej i zmiana jest dopuszczalna zgodnie z umową oraz warunkami dostawcy.'
            : '';
    }

    /**
     * Pola wniosku: kod => [etykieta, typ, wymagane, opcje|null]. Typy: text, textarea, date, money, select, check, account.
     * Wartości domyślne podstawia LicenseOperationService::defaults().
     */
    public function requestFields(): array
    {
        $src = ['FEER' => 'Fundacja FEER', 'Grant' => 'Grant', 'Własna' => 'Płatność własna', 'Partner' => 'Partner'];
        return match ($this) {
            self::Am11 => [
                'wnioskodawca' => ['Wnioskodawca', 'text', true, null], 'rola' => ['Rola i jednostka', 'text', false, null],
                'cel' => ['Kurs lub cel dydaktyczny', 'text', true, null], 'produkt' => ['Wnioskowany produkt, plan lub SKU', 'text', true, null],
                'okres_od' => ['Okres licencji — od', 'date', true, null], 'okres_do' => ['Okres licencji — do', 'date', true, null],
                'kwota' => ['Szacowany koszt (zł)', 'money', true, null], 'rozliczenie' => ['Sposób rozliczenia', 'textarea', false, null],
            ],
            self::Am12 => [
                'wnioskodawca' => ['Wnioskodawca / osoba, która poniosła koszt', 'text', true, null], 'cel' => ['Kurs lub cel zakupu', 'text', true, null],
                'licencja' => ['Zakupiona licencja', 'text', true, null], 'data_zakupu' => ['Data zakupu', 'date', true, null],
                'kwota' => ['Kwota zakupu (zł)', 'money', true, null], 'sprzedawca' => ['Sprzedawca / dostawca', 'text', true, null],
                'sposob' => ['Wnioskowany sposób rozliczenia', 'select', true, ['zwrot' => 'zwrot kosztu wnioskodawcy', 'refaktura' => 'refaktura na wskazany podmiot']],
                'dane' => ['Dane do rozliczenia', 'textarea', true, null],
                'dowod' => ['Załączony dowód zakupu', 'select', true, ['faktura' => 'faktura', 'rachunek' => 'rachunek', 'potwierdzenie' => 'potwierdzenie płatności', 'inny' => 'inny']],
                'dowod_inny' => ['Inny dowód (jaki)', 'text', false, null],
            ],
            self::Am13 => [
                'wnioskodawca' => ['Wnioskodawca', 'text', true, null], 'uzytkownik' => ['Kursant lub użytkownik', 'text', true, null],
                'cel' => ['Kurs lub cel dydaktyczny', 'text', true, null],
                'zrodlo' => ['Źródło finansowania', 'select', true, ['grant' => 'grant', 'partner' => 'partner', 'wlasna' => 'własna licencja', 'inne' => 'inne']],
                'zrodlo_inne' => ['Inne źródło (jakie)', 'text', false, null], 'licencja' => ['Licencja lub uprawnienie', 'text', true, null],
                'dowod' => ['Dowód uprawnienia, kodu lub finansowania', 'textarea', true, null],
                'aktywacja' => ['Wnioskowany sposób aktywacji', 'select', true, ['tenant' => 'konto w tenancie ośrodka', 'poza' => 'aktywacja poza tenantem', 'inny' => 'inny']],
                'aktywacja_inny' => ['Inny sposób aktywacji', 'text', false, null],
            ],
            self::Am20 => [
                'wnioskodawca' => ['Wnioskodawca', 'text', true, null],
                'licencjobiorca' => ['Licencjobiorca wskazany w umowie lub u dostawcy', 'text', true, null],
                'uzytkownik' => ['Użytkownik końcowy licencji', 'text', true, null],
                'wlasciciel' => ['Właściciel lub administrator konta/tenantu', 'text', true, null],
                'produkt' => ['Produkt i licencja', 'text', true, null], 'identyfikator' => ['Identyfikator licencji, subskrypcji lub konta', 'text', false, null],
                'okres' => ['Okres licencji / następny termin płatności', 'text', false, null],
                'obecny_platnik' => ['Obecny płatnik', 'text', true, null], 'nowy_platnik' => ['Wnioskowany nowy płatnik', 'text', true, null],
                'nowe_zrodlo' => ['Nowe źródło finansowania', 'select', true, $src], 'pozycja' => ['Pozycja budżetowa / projekt', 'text', false, null],
                'data_zmiany' => ['Wnioskowana data wejścia zmiany w życie', 'date', true, null],
                'tryb' => ['Sposób płatności', 'select', true, ['zajecia' => 'doliczenie do opłaty za zajęcia', 'rachunek' => 'wpłata na rachunek']],
                'kwota' => ['Kwota (zł) — o ile wzrośnie opłata za zajęcia albo kwota do wpłaty', 'money', true, null],
                'rachunek' => ['Numer rachunku do wpłat', 'account', false, null],
                'uzasadnienie' => ['Uzasadnienie zmiany', 'textarea', true, null],
                'dokumenty' => ['Dokumenty potwierdzające licencję i rozliczenie', 'textarea', false, null],
                'potwierdzenie' => ['Potwierdzam treść oświadczenia (zmiana płatności nie przenosi licencji ani praw do konta)', 'check', true, null],
            ],
            self::Am21 => [
                'rodzaj' => ['Rodzaj zmiany', 'select', true, ['upgrade' => 'upgrade (wyższy plan)', 'downgrade' => 'downgrade (niższy plan)', 'addon' => 'add-on / dodatkowe stanowisko', 'cykl' => 'zmiana cyklu (miesięczny ↔ roczny)']],
                'nowy_produkt' => ['Nowy produkt, plan lub SKU', 'text', true, null], 'nowe_zrodlo' => ['Źródło finansowania zmiany', 'select', false, $src],
                'pozycja' => ['Pozycja budżetowa / projekt', 'text', false, null], 'uzasadnienie' => ['Uzasadnienie', 'textarea', true, null],
            ],
            self::Am22 => [
                'rodzaj' => ['Rodzaj', 'select', true, ['zawieszenie' => 'zawieszenie (tymczasowe odebranie)', 'reasygnacja' => 'reasygnacja (przekazanie innej osobie)']],
                'do_dnia' => ['Zawieszenie do dnia', 'date', false, null], 'nowy_uzytkownik' => ['Nowy użytkownik (przy reasygnacji)', 'text', false, null],
                'polityka' => ['Dane i zasoby użytkownika', 'select', true, AssetPolicy::options()],
                'wytyczne' => ['Wytyczne: zachowanie dostępu do projektów i plików', 'textarea', true, null], 'uzasadnienie' => ['Uzasadnienie (np. przerwa w kursie, urlop, zmiana grupy)', 'textarea', true, null],
            ],
            self::Am23 => [
                'polityka' => ['Dane i zasoby użytkownika', 'select', true, AssetPolicy::options()],
                'procedura' => ['Procedura archiwizacji lub przeniesienia własności zasobów', 'textarea', true, null],
                'data_wycofania' => ['Data wycofania', 'date', true, null], 'uzasadnienie' => ['Uzasadnienie', 'textarea', true, null],
            ],
            self::Am24 => [
                'do_dnia' => ['Przedłużenie do dnia', 'date', true, null], 'nowe_zrodlo' => ['Źródło finansowania', 'select', false, $src],
                'pozycja' => ['Pozycja budżetowa / projekt', 'text', false, null], 'uzasadnienie' => ['Uzasadnienie (kolejny cykl dydaktyczny / projekt)', 'textarea', true, null],
            ],
        };
    }

    /** Pola weryfikacji i decyzji (wypełnia zatwierdzający); AM20 — brak (bez akceptacji). */
    public function decisionFields(): array
    {
        return match ($this) {
            self::Am11 => ['weryfikacja_budzetu' => ['Weryfikacja budżetu', 'textarea', true, null], 'warunki' => ['Uzasadnienie lub warunki przyznania', 'textarea', false, null],
                           'kwota_pozycja' => ['Kwota zatwierdzona i pozycja budżetowa', 'text', false, null], 'odpowiedzialny' => ['Provisioning licencji — osoba odpowiedzialna', 'text', false, null]],
            self::Am12 => ['wynik_dowodu' => ['Wynik weryfikacji dowodu zakupu', 'textarea', true, null], 'forma_kwota' => ['Forma i kwota zatwierdzonego rozliczenia', 'text', false, null],
                           'warunki' => ['Uzasadnienie lub warunki decyzji', 'textarea', false, null], 'przekazanie' => ['Przekazanie do wypłaty lub refakturowania', 'text', false, null]],
            self::Am13 => ['wynik_zrodla' => ['Wynik weryfikacji źródła, uprawnienia lub kodu', 'textarea', true, null], 'wynik_aktywacji' => ['Wynik weryfikacji sposobu aktywacji', 'textarea', true, null],
                           'warunki' => ['Zakres i warunki aktywacji / uzasadnienie odmowy', 'textarea', false, null], 'odpowiedzialny' => ['Aktywacja — osoba odpowiedzialna', 'text', false, null]],
            self::Am20 => [],
            default    => ['vendor_check' => ['Weryfikacja dostępności miejsc / licencji u dostawcy', 'textarea', false, null],
                           'warunki' => ['Uwagi i warunki realizacji', 'textarea', false, null], 'odpowiedzialny' => ['Osoba lub jednostka realizująca', 'text', false, null]],
        };
    }
}

/** Polityka danych i zasobów użytkownika przy zawieszeniu / cofnięciu (pole data_and_assets_policy rekordu JSON). */
enum AssetPolicy: string
{
    case Transfer = 'TRANSFER_OWNERSHIP';
    case Archive  = 'ARCHIVE';
    case Delete   = 'DELETE';
    case Keep     = 'KEEP_AS_IS';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'przeniesienie własności zasobów',
            self::Archive  => 'archiwizacja',
            self::Delete   => 'usunięcie',
            self::Keep     => 'bez zmian',
        };
    }

    public static function options(): array
    {
        $o = [];
        foreach (self::cases() as $c) $o[$c->value] = $c->label();
        return $o;
    }
}

/** Stan operacji w SZO; status() i approval() dają wartości rekordu JSON. */
enum OperationStatus: string
{
    case Pending   = 'pending';     // złożona, czeka na decyzję
    case Approved  = 'approved';    // zatwierdzona, czeka na wykonanie (zadanie IT)
    case Executed  = 'executed';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Czeka na decyzję',
            self::Approved  => 'Zatwierdzona — w realizacji',
            self::Executed  => 'Wykonana',
            self::Rejected  => 'Odmowa',
            self::Cancelled => 'Wycofana',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending   => '#f0a30a',
            self::Approved  => '#1ba1e2',
            self::Executed  => '#008a00',
            self::Rejected  => '#a20025',
            self::Cancelled => '#647687',
        };
    }

    public function jsonStatus(): string
    {
        return match ($this) {
            self::Pending, self::Approved => 'PENDING_EXECUTION',
            self::Executed  => 'EXECUTED',
            self::Rejected  => 'REJECTED',
            self::Cancelled => 'CANCELLED',
        };
    }

    public function approval(): string
    {
        return match ($this) {
            self::Pending   => 'PENDING',
            self::Approved, self::Executed => 'APPROVED',
            self::Rejected  => 'REJECTED',
            self::Cancelled => 'CANCELLED',
        };
    }
}

/** Numer sprawy operacji: 4000-AM20/734350539/000123XK/2026/{nr kursu}/{nr kursanta}. */
function ldOpNumber(OperationForm $form, int $seq, string $letters, string $year, string $subId): string
{
    return LD_OP_PREFIX . '-' . $form->value . '/' . LD_OP_ORG_ID . '/' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT) . $letters . '/' . $year . '/' . $subId;
}

function ldRandomLetters(): string
{
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // bez I i O (mylą się z 1 i 0)
    return $a[random_int(0, strlen($a) - 1)] . $a[random_int(0, strlen($a) - 1)];
}
