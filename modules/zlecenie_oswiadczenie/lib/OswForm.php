<?php
declare(strict_types=1);

/**
 * modules/zlecenie_oswiadczenie/lib/OswForm.php — kroki kreatora, pola, walidacja i reguły oświadczeń zleceniobiorcy.
 *
 * Pola odwzorowują wzór „Oświadczenie dla celów podatkowych i ubezpieczenia ZUS” 1:1 (dane osobowe, PESEL, kod zawodu,
 * adres zamieszkania dla celów podatkowych, urząd skarbowy, pkt 1–5, dobrowolne ubezpieczenia) oraz oświadczenie
 * o numerze rachunku bankowego (ZLB). Reguły ZUS w kreatorze są podpowiedzią — ostatecznie ustala je Fundacja.
 */
final class OswForm
{
    public const STEPS = [
        1 => ['dane',       'Dane osobowe'],
        2 => ['adres',      'Adres i urząd skarbowy'],
        3 => ['zus',        'Ubezpieczenia — Twoja sytuacja'],
        4 => ['dobrowolne', 'Ubezpieczenia dobrowolne'],
        5 => ['zlb',        'Rachunek bankowy'],
        6 => ['podsumowanie', 'Sprawdź i złóż'],
    ];

    public const WOJEWODZTWA = ['dolnośląskie', 'kujawsko-pomorskie', 'lubelskie', 'lubuskie', 'łódzkie', 'małopolskie',
        'mazowieckie', 'opolskie', 'podkarpackie', 'podlaskie', 'pomorskie', 'śląskie', 'świętokrzyskie',
        'warmińsko-mazurskie', 'wielkopolskie', 'zachodniopomorskie'];

    /** Banki wg numeru rozliczeniowego (cyfry 3–6 NRB) — tylko podpowiedź nazwy, pole można zmienić. */
    public const BANKI = [
        '1010' => 'Narodowy Bank Polski', '1020' => 'PKO Bank Polski', '1030' => 'Bank Handlowy w Warszawie (Citi Handlowy)',
        '1050' => 'ING Bank Śląski', '1060' => 'Bank BPH', '1090' => 'Santander Bank Polska', '1130' => 'Bank Gospodarstwa Krajowego',
        '1140' => 'mBank', '1160' => 'Bank Millennium', '1240' => 'Bank Pekao', '1320' => 'Bank Pocztowy',
        '1540' => 'Bank Ochrony Środowiska', '1610' => 'SGB-Bank', '1680' => 'Plus Bank', '1870' => 'Nest Bank',
        '1930' => 'Bank Polskiej Spółdzielczości', '1940' => 'Credit Agricole Bank Polska', '2030' => 'BNP Paribas Bank Polska',
        '2120' => 'Santander Consumer Bank', '2490' => 'Alior Bank', '2770' => 'VeloBank', '2910' => 'Aion Bank',
    ];

    /** Pola tekstowe kroku → [etykieta, wymagane] (checkboxy i radio obsługiwane osobno w normalize()). */
    public static function textFields(int $step): array
    {
        return match ($step) {
            1 => ['nazwisko_imiona' => 'Nazwisko i imiona', 'data_urodzenia' => 'Data urodzenia', 'obywatelstwo' => 'Obywatelstwo',
                  'pesel' => 'PESEL', 'dokument' => 'Rodzaj i numer dokumentu tożsamości', 'plec' => 'Formy w oświadczeniu',
                  'kod_zawodu' => 'Kod zawodu'],
            2 => ['adr_kraj' => 'Kraj', 'adr_miejscowosc' => 'Miejscowość', 'adr_kod' => 'Kod pocztowy', 'adr_ulica' => 'Ulica',
                  'adr_nr_domu' => 'Nr domu', 'adr_nr_mieszkania' => 'Nr mieszkania', 'adr_wojewodztwo' => 'Województwo',
                  'adr_powiat' => 'Powiat', 'adr_gmina' => 'Gmina', 'urzad_skarbowy' => 'Urząd skarbowy'],
            3 => ['zatrudniony' => 'Zatrudnienie', 'wynagrodzenie_min' => 'Wynagrodzenie', 'inny_tytul_opis' => 'Tytuł ubezpieczenia'],
            5 => ['nrb' => 'Numer rachunku', 'bank_nazwa' => 'Bank prowadzący rachunek', 'posiadacz' => 'Imię i nazwisko posiadacza rachunku',
                  'zlb_miejscowosc' => 'Miejscowość'],
            default => [],
        };
    }

    public static function checkFields(int $step): array
    {
        return match ($step) {
            1 => ['brak_pesel'],
            3 => ['inny_tytul', 'emeryt', 'student', 'bez_tytulu'],
            4 => ['dobr_emerytalne', 'dobr_chorobowe'],
            5 => ['zlb_potwierdzam'],
            6 => ['oswiadczam'],
            default => [],
        };
    }

    /** Odpowiedzi kroku z formularza (przycięte, bez nadmiarowych odstępów). */
    public static function normalize(int $step, array $post): array
    {
        $out = [];
        foreach (array_keys(self::textFields($step)) as $k) {
            $out[$k] = trim(preg_replace('/\s+/u', ' ', (string)($post[$k] ?? '')) ?? '');
        }
        foreach (self::checkFields($step) as $k) $out[$k] = !empty($post[$k]) ? 1 : 0;
        if (isset($out['pesel']))   $out['pesel'] = preg_replace('/\D/', '', $out['pesel']);
        if (isset($out['nrb']))     $out['nrb'] = strtoupper(preg_replace('/[\s-]/', '', $out['nrb']));
        if (isset($out['adr_kod'])) $out['adr_kod'] = preg_replace('/^(\d{2})(\d{3})$/', '$1-$2', $out['adr_kod']);
        if (isset($out['kod_zawodu'])) $out['kod_zawodu'] = preg_replace('/\s/', '', $out['kod_zawodu']);
        if (!empty($out['brak_pesel'])) $out['pesel'] = '';
        if ($step === 3 && ($out['zatrudniony'] ?? '') !== 'tak') $out['wynagrodzenie_min'] = '';
        if ($step === 3 && empty($out['inny_tytul'])) $out['inny_tytul_opis'] = '';
        return $out;
    }

    /** Walidacja kroku. $a — wszystkie odpowiedzi (z bieżącym krokiem). Zwraca [pole => komunikat]. */
    public static function validate(int $step, array $a): array
    {
        $e = [];
        $req = function (string $k, string $msg) use (&$e, $a): void { if (trim((string)($a[$k] ?? '')) === '') $e[$k] = $msg; };
        switch ($step) {
            case 1:
                $req('nazwisko_imiona', 'Wpisz nazwisko i imiona.');
                if (!isset($e['nazwisko_imiona']) && !preg_match('/^\p{L}[\p{L}\'’ .-]*\p{L}$/u', (string)$a['nazwisko_imiona'])) $e['nazwisko_imiona'] = 'Nazwisko i imiona mogą zawierać tylko litery, spacje, łącznik i apostrof.';
                elseif (!isset($e['nazwisko_imiona']) && !str_contains((string)$a['nazwisko_imiona'], ' ')) $e['nazwisko_imiona'] = 'Wpisz nazwisko i co najmniej jedno imię.';
                $dob = (string)($a['data_urodzenia'] ?? '');
                if (!self::dateOk($dob)) $e['data_urodzenia'] = 'Wpisz prawidłową datę urodzenia.';
                elseif (($age = self::age($dob)) < 13 || $age > 100) $e['data_urodzenia'] = 'Sprawdź datę urodzenia — wiek musi mieścić się w przedziale 13–100 lat.';
                $req('obywatelstwo', 'Wpisz obywatelstwo, np. polskie.');
                if (empty($a['brak_pesel'])) {
                    $p = (string)($a['pesel'] ?? '');
                    if (strlen($p) !== 11) $e['pesel'] = 'PESEL ma 11 cyfr.';
                    elseif (!pesel_valid($p)) $e['pesel'] = 'Ten numer PESEL ma błędną cyfrę kontrolną — sprawdź, czy nie ma literówki.';
                    elseif (!isset($e['data_urodzenia']) && pesel_to_birthdate($p) !== $dob) $e['pesel'] = 'Data urodzenia zapisana w PESEL (' . self::pl((string)pesel_to_birthdate($p)) . ') różni się od wpisanej daty urodzenia.';
                } else {
                    $req('dokument', 'Podaj rodzaj i numer dokumentu tożsamości (np. paszport AB1234567).');
                }
                if (!in_array($a['plec'] ?? '', ['k', 'm', 'obie'], true)) $e['plec'] = 'Wybierz formy, w jakich oświadczenie ma być wypełnione.';
                if (($a['kod_zawodu'] ?? '') !== '' && !preg_match('/^\d{6}$/', (string)$a['kod_zawodu'])) $e['kod_zawodu'] = 'Kod zawodu ma 6 cyfr (klasyfikacja zawodów i specjalności). Jeśli go nie znasz, zostaw puste.';
                break;
            case 2:
                $req('adr_kraj', 'Wpisz kraj.');
                $req('adr_miejscowosc', 'Wpisz miejscowość.');
                $pl = self::isPoland((string)($a['adr_kraj'] ?? ''));
                if ($pl && !preg_match('/^\d{2}-\d{3}$/', (string)($a['adr_kod'] ?? ''))) $e['adr_kod'] = 'Kod pocztowy wpisz w formacie 00-000.';
                elseif (!$pl) $req('adr_kod', 'Wpisz kod pocztowy.');
                $req('adr_nr_domu', 'Wpisz numer domu.');
                if ($pl && !in_array($a['adr_wojewodztwo'] ?? '', self::WOJEWODZTWA, true)) $e['adr_wojewodztwo'] = 'Wybierz województwo z listy.';
                elseif (!$pl) $req('adr_wojewodztwo', 'Wpisz region (województwo).');
                $req('adr_powiat', 'Wpisz powiat.');
                $req('adr_gmina', 'Wpisz gminę.');
                $req('urzad_skarbowy', 'Wpisz urząd skarbowy właściwy dla miejsca zamieszkania.');
                break;
            case 3:
                if (!in_array($a['zatrudniony'] ?? '', ['tak', 'nie'], true)) $e['zatrudniony'] = 'Zaznacz, czy jesteś jednocześnie zatrudniona/zatrudniony.';
                if (($a['zatrudniony'] ?? '') === 'tak' && !in_array($a['wynagrodzenie_min'] ?? '', ['co_najmniej', 'mniej'], true)) $e['wynagrodzenie_min'] = 'Zaznacz wysokość wynagrodzenia brutto z tamtej umowy.';
                if (!empty($a['inny_tytul']) && trim((string)($a['inny_tytul_opis'] ?? '')) === '') $e['inny_tytul_opis'] = 'Podaj tytuł ubezpieczenia, np. działalność gospodarcza, KRUS.';
                if (!empty($a['student'])) {
                    $dob = (string)($a['data_urodzenia'] ?? '');
                    if (self::dateOk($dob) && self::age($dob) >= 26) $e['student'] = 'Punkt 4 dotyczy osób, które nie ukończyły 26 lat — według daty urodzenia masz już ukończone 26 lat.';
                }
                if (!empty($a['bez_tytulu']) && (($a['zatrudniony'] ?? '') === 'tak' || !empty($a['inny_tytul']) || !empty($a['emeryt']) || !empty($a['student']))) {
                    $e['bez_tytulu'] = 'Punkt 5 („nie pracuję… i nie jestem objęta/objęty ubezpieczeniem z żadnego tytułu”) wyklucza punkty 1 („jestem”), 2, 3 i 4.';
                }
                if (($a['zatrudniony'] ?? '') === 'nie' && empty($a['inny_tytul']) && empty($a['emeryt']) && empty($a['student']) && empty($a['bez_tytulu'])) {
                    $e['bez_tytulu'] = 'Nie zaznaczono żadnej sytuacji z punktów 2–5. Jeśli nie pracujesz i nie masz innego ubezpieczenia, zaznacz punkt 5.';
                }
                break;
            case 4:
                if (self::studentExempt($a) && (!empty($a['dobr_emerytalne']) || !empty($a['dobr_chorobowe']))) $e['dobr_emerytalne'] = 'Uczeń lub student do 26 lat nie podlega ubezpieczeniom społecznym z tej umowy — nie można wybrać ubezpieczeń dobrowolnych.';
                if (!empty($a['dobr_chorobowe']) && !self::mandatory($a) && empty($a['dobr_emerytalne']) && !self::studentExempt($a)) $e['dobr_chorobowe'] = 'Dobrowolne ubezpieczenie chorobowe jest możliwe tylko razem z ubezpieczeniem emerytalnym i rentowym z tej umowy — zaznacz też pierwszą opcję albo odznacz chorobowe.';
                break;
            case 5:
                $n = (string)($a['nrb'] ?? '');
                if ($n === '') $e['nrb'] = 'Wpisz numer rachunku.';
                elseif (!self::ibanOk($n)) $e['nrb'] = ctype_digit($n) && strlen($n) !== 26 ? 'Numer rachunku w Polsce ma 26 cyfr (wpisano ' . strlen($n) . ').' : 'Numer rachunku ma błędną sumę kontrolną — sprawdź, czy nie ma literówki.';
                $req('bank_nazwa', 'Wpisz nazwę banku prowadzącego rachunek.');
                $req('posiadacz', 'Wpisz imię i nazwisko posiadacza rachunku.');
                $req('zlb_miejscowosc', 'Wpisz miejscowość złożenia oświadczenia.');
                if (empty($a['zlb_potwierdzam'])) $e['zlb_potwierdzam'] = 'Potwierdź treść oświadczenia o rachunku bankowym.';
                break;
            case 6:
                if (empty($a['oswiadczam'])) $e['oswiadczam'] = 'Potwierdź, że dane są zgodne z prawdą.';
                break;
        }
        return $e;
    }

    // ── Reguły ────────────────────────────────────────────────────────────────

    /** Uczeń / student do 26 lat — z tej umowy brak ubezpieczeń społecznych. */
    public static function studentExempt(array $a): bool
    {
        return !empty($a['student']) && self::dateOk((string)($a['data_urodzenia'] ?? '')) && self::age((string)$a['data_urodzenia']) < 26;
    }

    /** Ubezpieczenie emerytalne i rentowe z tej umowy obowiązkowe (podpowiedź). */
    public static function mandatory(array $a): bool
    {
        if (self::studentExempt($a)) return false;
        $covered = (($a['zatrudniony'] ?? '') === 'tak' && ($a['wynagrodzenie_min'] ?? '') === 'co_najmniej') || !empty($a['inny_tytul']);
        return !$covered;
    }

    /** Opis sytuacji ubezpieczeniowej (podpowiedź w kreatorze i notatka w projekcie umowy). */
    public static function zusHint(array $a): string
    {
        if (self::studentExempt($a)) return 'Jako uczeń lub student do 26 lat nie podlegasz z tej umowy ubezpieczeniom społecznym ani zdrowotnemu.';
        if (self::mandatory($a)) return 'Z tej umowy ubezpieczenie emerytalne i rentowe jest obowiązkowe (opłaca je Fundacja i Ty). Możesz dodatkowo wybrać dobrowolne ubezpieczenie chorobowe.';
        return 'Masz już ubezpieczenie z innego tytułu (co najmniej minimalne wynagrodzenie albo inny tytuł) — z tej umowy obowiązkowe jest tylko ubezpieczenie zdrowotne. Ubezpieczenie emerytalne i rentowe możesz wybrać dobrowolnie, a chorobowe — tylko razem z nim.';
    }

    public static function dateOk(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $dd] = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y) && $d <= date('Y-m-d');
    }

    public static function age(string $dob, ?string $on = null): int
    {
        return (int)date_diff(new DateTime($dob), new DateTime($on ?? date('Y-m-d')))->y;
    }

    public static function isPoland(string $kraj): bool
    {
        return in_array(mb_strtolower(trim($kraj)), ['polska', 'pl', 'poland', 'rzeczpospolita polska'], true);
    }

    /** NRB (26 cyfr, PL) albo IBAN z kodem kraju — suma kontrolna mod 97. */
    public static function ibanOk(string $n): bool
    {
        $n = strtoupper(preg_replace('/[\s-]/', '', $n));
        if (ctype_digit($n)) $n = 'PL' . $n;
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $n)) return false;
        if (str_starts_with($n, 'PL') && strlen($n) !== 28) return false;
        $r = substr($n, 4) . substr($n, 0, 4);
        $num = '';
        foreach (str_split($r) as $c) $num .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
        $mod = 0;
        foreach (str_split($num, 7) as $chunk) $mod = (int)(($mod . $chunk) % 97);
        return $mod === 1;
    }

    /** Numer w grupach (NRB: 2 + 6×4 cyfry; IBAN: PL + …). */
    public static function nrbFormat(string $n): string
    {
        $n = strtoupper(preg_replace('/[\s-]/', '', $n));
        if (ctype_digit($n) && strlen($n) === 26) return substr($n, 0, 2) . ' ' . trim(chunk_split(substr($n, 2), 4, ' '));
        return trim(chunk_split($n, 4, ' '));
    }

    public static function bankName(string $n): string
    {
        $d = preg_replace('/\D/', '', preg_replace('/^PL/i', '', $n));
        return strlen($d) === 26 ? (self::BANKI[substr($d, 2, 4)] ?? '') : '';
    }

    /** Domyślne formy gramatyczne z PESEL (przedostatnia cyfra: parzysta — kobieta). */
    public static function plecFromPesel(string $p): string
    {
        return strlen($p) === 11 && ctype_digit($p) ? ((int)$p[9] % 2 === 0 ? 'k' : 'm') : '';
    }

    /** „Kowalska Anna Maria” → „Anna Maria Kowalska” (wzór ZUS pyta o „Nazwisko i imiona”, ZLB o „imię i nazwisko”). */
    public static function nameFirst(string $n): string
    {
        $p = preg_split('/\s+/u', trim($n)) ?: [];
        return count($p) > 1 ? implode(' ', array_slice($p, 1)) . ' ' . $p[0] : trim($n);
    }

    public static function pl(string $ymd): string
    {
        return $ymd !== '' && strtotime($ymd) ? date('d.m.Y', (int)strtotime($ymd)) : '';
    }

    /** Adres jednym wierszem (do projektu umowy i podsumowania). */
    public static function addressLine(array $a): string
    {
        $street = trim(($a['adr_ulica'] ?? '') !== '' ? 'ul. ' . $a['adr_ulica'] . ' ' . ($a['adr_nr_domu'] ?? '') : ($a['adr_miejscowosc'] ?? '') . ' ' . ($a['adr_nr_domu'] ?? ''));
        if (($a['adr_nr_mieszkania'] ?? '') !== '') $street .= '/' . $a['adr_nr_mieszkania'];
        $kraj = self::isPoland((string)($a['adr_kraj'] ?? '')) ? '' : ', ' . $a['adr_kraj'];
        return trim($street . ', ' . ($a['adr_kod'] ?? '') . ' ' . ($a['adr_miejscowosc'] ?? '') . $kraj, ' ,');
    }

    /** Wiersze podsumowania: [krok => [[etykieta, wartość]]]. */
    public static function summary(array $a): array
    {
        $yn = fn($k) => !empty($a[$k]) ? 'tak' : 'nie';
        $plec = ['k' => 'żeńskie (np. „zatrudniona”)', 'm' => 'męskie (np. „zatrudniony”)', 'obie' => 'bez skreślania (obie formy)'][$a['plec'] ?? ''] ?? '';
        return [
            1 => [['Nazwisko i imiona', $a['nazwisko_imiona'] ?? ''], ['Data urodzenia', self::pl((string)($a['data_urodzenia'] ?? ''))],
                  ['Obywatelstwo', $a['obywatelstwo'] ?? ''],
                  !empty($a['brak_pesel']) ? ['PESEL', 'nie posiadam — ' . ($a['dokument'] ?? '')] : ['PESEL', $a['pesel'] ?? ''],
                  ['Kod zawodu', ($a['kod_zawodu'] ?? '') ?: '— (uzupełni Fundacja)'], ['Formy w oświadczeniu', $plec]],
            2 => [['Adres zamieszkania dla celów podatkowych', self::addressLine($a)],
                  ['Województwo / powiat / gmina', trim(($a['adr_wojewodztwo'] ?? '') . ' / ' . ($a['adr_powiat'] ?? '') . ' / ' . ($a['adr_gmina'] ?? ''))],
                  ['Urząd skarbowy', $a['urzad_skarbowy'] ?? '']],
            3 => [['1. Jednocześnie zatrudniona/ny (umowa o pracę, zlecenie lub równorzędna)', ($a['zatrudniony'] ?? '') === 'tak'
                      ? 'jestem — wynagrodzenie ' . (($a['wynagrodzenie_min'] ?? '') === 'co_najmniej' ? 'co najmniej minimalne' : 'mniej niż minimalne') : 'nie jestem'],
                  ['2. Ubezpieczona/ny z innych tytułów', !empty($a['inny_tytul']) ? 'tak — ' . ($a['inny_tytul_opis'] ?? '') : 'nie'],
                  ['3. Emeryt lub rencista', $yn('emeryt')], ['4. Uczeń lub student do 26 lat', $yn('student')],
                  ['5. Nie pracuję i nie mam ubezpieczenia z żadnego tytułu', $yn('bez_tytulu')]],
            4 => [['Dobrowolne ubezpieczenie emerytalne i rentowe', $yn('dobr_emerytalne')], ['Dobrowolne ubezpieczenie chorobowe', $yn('dobr_chorobowe')]],
            5 => [['Numer rachunku', self::nrbFormat((string)($a['nrb'] ?? ''))], ['Bank', $a['bank_nazwa'] ?? ''],
                  ['Posiadacz rachunku', $a['posiadacz'] ?? ''], ['Miejscowość', $a['zlb_miejscowosc'] ?? '']],
        ];
    }
}
