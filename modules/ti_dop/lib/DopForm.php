<?php
declare(strict_types=1);

/**
 * modules/ti_dop/lib/DopForm.php — wnioski rozliczeniowe uczestnika TI (poza licencjami), związane z dokumentami kursanta:
 *
 *   DOP1 — Zwrot nadpłaty: wniosek (dane, kwota, przyczyna, IBAN, właściciel rachunku, oświadczenie, załączniki) →
 *          weryfikacja i decyzja działu finansowego (saldo nadpłaty na dzień, zgodność rachunku) → realizacja przelewu
 *          (data, nr transakcji) = zwrot w rejestrze nadpłat (ti_op_refund, wpis ujemny w księdze uczestnika).
 *   DOP2 — Uruchomienie płatności z subkonta na podstawie przedpłaty: wnioskodawca / subkonto / beneficjent, dane przedpłaty,
 *          dyspozycja (kwota, tytuł, odbiorca, rachunek, termin), załączniki → 1. weryfikacja środków (saldo subkonta)
 *          → 2. zatwierdzenie kierownika → 3. realizacja przelewu (wpis ujemny w księdze uczestnika, source_type dop2_payout).
 * Numer wniosku: DOP1/{6 cyfr}/{rok}, DOP2/{6 cyfr}/{rok} (licznik osobny dla każdego wzoru).
 * Wersja papierowa (podpis odręczny, skan) albo cyfrowa (potwierdzenie przez link e-mail + SMS — imię i nazwisko, czas, IP).
 */
enum DopForm: string
{
    case Dop1 = 'DOP1';
    case Dop2 = 'DOP2';

    public function title(): string
    {
        return match ($this) {
            self::Dop1 => 'Zwrot nadpłaty',
            self::Dop2 => 'Uruchomienie płatności z subkonta (na podstawie przedpłaty)',
        };
    }

    public function color(): string
    {
        return $this === self::Dop1 ? '#00aba9' : '#6a00ff';
    }

    /** Kroki obsługi po złożeniu: kod => [nazwa, czy wymaga roli kierownika]. */
    public function steps(): array
    {
        return match ($this) {
            self::Dop1 => ['verify' => ['Weryfikacja i decyzja działu finansowo-księgowego', true], 'execute' => ['Realizacja przelewu', false]],
            self::Dop2 => ['verify' => ['Weryfikacja dostępności środków na subkoncie', false], 'approve' => ['Zatwierdzenie dyrektora / kierownika', true], 'execute' => ['Realizacja płatności (dział finansowy)', false]],
        };
    }

    public function statement(): string
    {
        return $this === self::Dop1
            ? 'Zwracam się z prośbą o zwrot powstałej nadpłaty na moim koncie rozrachunkowym.'
            : 'Wnoszę o uruchomienie i przekazanie środków z wyżej wymienionego subkonta.';
    }

    /** Pola wniosku: kod => [etykieta, typ, wymagane, opcje|null, sekcja]. */
    public function fields(): array
    {
        return match ($this) {
            self::Dop1 => [
                'nazwa'       => ['Imię i nazwisko / Nazwa podmiotu', 'text', true, null, 'I'],
                'nip_pesel'   => ['NIP / PESEL', 'text', false, null, 'I'],
                'nr_klienta'  => ['Numer umowy / Klienta', 'text', false, null, 'I'],
                'adres'       => ['Adres zamieszkania / Siedziby', 'text', true, null, 'I'],
                'kontakt'     => ['Telefon / E-mail do kontaktu', 'text', true, null, 'I'],
                'kwota'       => ['Kwota do zwrotu (PLN)', 'money', true, null, 'II'],
                'przyczyna'   => ['Przyczyna powstania nadpłaty', 'select', true, ['podwojna' => 'Podwójna / błędna wpłata', 'korekta' => 'Korekta faktury / rozliczenie umowy', 'inne' => 'Inne'], 'II'],
                'przyczyna_inna' => ['Inna przyczyna (jaka?)', 'text', false, null, 'II'],
                'iban'        => ['Dane do zwrotu — numer rachunku (IBAN)', 'iban', true, null, 'II'],
                'wlasciciel'  => ['Właściciel rachunku', 'text', true, null, 'II'],
                'oswiadczenie' => ['Oświadczam, że jestem właścicielem wskazanego wyżej rachunku bankowego / posiadam upoważnienie do odbioru środków', 'check', true, null, 'II'],
                'zal_przelew' => ['Potwierdzenie dokonania przelewu / wpłaty', 'check', false, null, 'III'],
                'zal_faktura' => ['Kopia faktury korygującej / rozliczenia', 'check', false, null, 'III'],
                'zal_upowaznienie' => ['Upoważnienie do odbioru środków (jeśli konto należy do innej osoby / podmiotu)', 'check', false, null, 'III'],
            ],
            self::Dop2 => [
                'wnioskodawca' => ['Imię i nazwisko / Dział', 'text', true, null, 'I'],
                'subkonto'    => ['Numer subkonta źródłowego / ID projektu', 'text', true, null, 'I'],
                'beneficjent' => ['Nazwa Beneficjenta / Klienta subkonta', 'text', true, null, 'I'],
                'przedplata_kwota' => ['Kwota wniesionej przedpłaty (PLN)', 'money', true, null, 'II'],
                'przedplata_data'  => ['Data zaksięgowania przedpłaty', 'date', true, null, 'II'],
                'przedplata_dok'   => ['Dokument potwierdzający przedpłatę (np. proforma / zlecenie / umowa nr)', 'text', true, null, 'II'],
                'kwota'       => ['Kwota do uruchomienia (PLN)', 'money', true, null, 'III'],
                'tytul'       => ['Tytuł płatności / Cel przeznaczenia', 'text', true, null, 'III'],
                'odbiorca'    => ['Nazwa odbiorcy', 'text', true, null, 'III'],
                'iban'        => ['Nr konta bankowego / subkonta docelowego', 'iban', true, null, 'III'],
                'termin'      => ['Wymagany termin realizacji', 'date', true, null, 'III'],
                'zal_faktura' => ['Kopia faktury proforma / rachunku / umowy stanowiącej podstawę wydatku', 'check', false, null, 'IV'],
                'zal_wplyw'   => ['Potwierdzenie wpływu przedpłaty na subkonto', 'check', false, null, 'IV'],
                'zal_zgoda'   => ['Zgoda / upoważnienie do dysponowania subkontem', 'check', false, null, 'IV'],
            ],
        };
    }

    /** Nazwy sekcji wniosku (numeracja rzymska jak na wzorze). */
    public function sections(): array
    {
        return match ($this) {
            self::Dop1 => ['I' => 'Dane Wnioskodawcy / Klienta', 'II' => 'Treść Wniosku i Dane do Przelewu', 'III' => 'Załączniki', 'IV' => 'Podpis Wnioskodawcy', 'V' => 'Weryfikacja Działu Finansowo-Księgowego'],
            self::Dop2 => ['I' => 'Dane Wnioskodawcy / Weryfikatora', 'II' => 'Dane Przedpłaty (Podstawa Uruchomienia)', 'III' => 'Dyspozycja Płatności / Przekazania Środków', 'IV' => 'Załączone Dokumenty', 'V' => 'Podpis Osoby Składającej Wniosek', 'VI' => 'Ścieżka Akceptacji i Realizacji'],
        };
    }
}

enum DopStatus: string
{
    case Signing   = 'signing';    // czeka na potwierdzenie wnioskodawcy (link) albo skan podpisanego wniosku
    case Verify    = 'verify';     // czeka na weryfikację (DOP1: i decyzję)
    case Approve   = 'approve';    // DOP2: czeka na zatwierdzenie kierownika
    case Execute   = 'execute';    // zatwierdzony — czeka na realizację przelewu
    case Done      = 'done';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Signing   => 'Czeka na podpis wnioskodawcy',
            self::Verify    => 'Weryfikacja',
            self::Approve   => 'Czeka na zatwierdzenie kierownika',
            self::Execute   => 'Do realizacji przelewu',
            self::Done      => 'Zrealizowany',
            self::Rejected  => 'Odrzucony',
            self::Cancelled => 'Wycofany',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Signing => '#fa6800', self::Verify => '#f0a30a', self::Approve => '#1ba1e2', self::Execute => '#0050ef',
            self::Done => '#008a00', self::Rejected => '#a20025', self::Cancelled => '#647687',
        };
    }
}

/** Kwota słownie po polsku (złote i grosze) — do pól „słownie” na wzorach. */
function dopKwotaSlownie(float $kwota): string
{
    $j = ['', 'jeden', 'dwa', 'trzy', 'cztery', 'pięć', 'sześć', 'siedem', 'osiem', 'dziewięć', 'dziesięć', 'jedenaście', 'dwanaście', 'trzynaście', 'czternaście', 'piętnaście', 'szesnaście', 'siedemnaście', 'osiemnaście', 'dziewiętnaście'];
    $d = ['', '', 'dwadzieścia', 'trzydzieści', 'czterdzieści', 'pięćdziesiąt', 'sześćdziesiąt', 'siedemdziesiąt', 'osiemdziesiąt', 'dziewięćdziesiąt'];
    $s = ['', 'sto', 'dwieście', 'trzysta', 'czterysta', 'pięćset', 'sześćset', 'siedemset', 'osiemset', 'dziewięćset'];
    $forms = [['', '', ''], ['tysiąc', 'tysiące', 'tysięcy'], ['milion', 'miliony', 'milionów']];
    $form = function (int $n, array $f): string {
        if ($n === 1) return $f[0];
        $l = $n % 10; $t = $n % 100;
        return ($l >= 2 && $l <= 4 && ($t < 12 || $t > 14)) ? $f[1] : $f[2];
    };
    $tri = function (int $n) use ($j, $d, $s): string {
        $w = [];
        if ($n >= 100) { $w[] = $s[intdiv($n, 100)]; $n %= 100; }
        if ($n >= 20) { $w[] = $d[intdiv($n, 10)]; $n %= 10; }
        if ($n > 0) $w[] = $j[$n];
        return implode(' ', $w);
    };
    $zl = (int)floor(round($kwota, 2));
    $gr = (int)round(($kwota - $zl) * 100);
    if ($zl === 0) $words = 'zero';
    else {
        $parts = []; $i = 0; $n = $zl;
        while ($n > 0 && $i < 3) {
            $t = $n % 1000;
            if ($t > 0) $parts[] = ($i > 0 && $t === 1 ? '' : $tri($t)) . ($i > 0 ? ' ' . $form($t, $forms[$i]) : '');
            $n = intdiv($n, 1000); $i++;
        }
        $words = trim(implode(' ', array_reverse(array_map('trim', $parts))));
    }
    $zlF = $zl === 1 ? 'złoty' : ((($zl % 10) >= 2 && ($zl % 10) <= 4 && ($zl % 100 < 12 || $zl % 100 > 14)) ? 'złote' : 'złotych');
    return $words . ' ' . $zlF . ' ' . str_pad((string)$gr, 2, '0', STR_PAD_LEFT) . '/100';
}
