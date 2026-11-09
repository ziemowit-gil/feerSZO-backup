<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/Ant1Form.php — ANT1: wniosek o nadanie uprawnień w ramach dostępu do subskrypcji
 * Claude for Nonprofit (Anthropic Claude, w tym Claude Code) dla uczestnika szkoleń (pełnoletniego / niepełnoletniego) albo dydaktyka.
 *
 * Wzór papierowy: lib/wzor/druk-ANT1.docx (dane osoby, oświadczenie o zryczałtowanej opłacie 25 zł za dostęp do Claude, klauzula
 * informacyjna RODO, oświadczenie kierownika). Tu: teksty wzoru słowo w słowo (bez zmian redakcyjnych) + wartości domyślne.
 * Obieg (rejestr operacji, LicenseOperationService): biuro wypełnia dane → osoba potwierdza wniosek, oświadczenie o opłacie
 * i RODO przez link (e-mail + SMS; stempel: imię i nazwisko, czas, IP) → kierownik składa oświadczenie kierownika (decyzja cyfrowa)
 * → zadanie dla Helpdesku IT (nadanie dostępu; numer zgłoszenia trafia do oświadczenia kierownika) → PDF w układzie AM do osoby.
 * Potwierdzenie przez link zapisuje też akceptację regulaminu TI „claude_oplata” (includes/ti_terms.php), gdy osoba ma konto uczestnika.
 */
final class Ant1Form
{
    public const SYMBOL = 'ANT1';
    public const PRODUCT = 'Claude for Nonprofit (Anthropic Claude, w tym Claude Code)';
    public const EMAIL_DOMAIN = 'edu.feer.org.pl';
    public const FEE = '8 USD miesięcznie';   // od 2026-11-09 (wcześniej 25 zł ryczałtem) — kwota i zasady jak w modules/claude_billing

    public const TYPES = [
        'pelnoletni'  => 'pełnoletniego kursanta / uczestnika szkoleń',
        'maloletni'   => 'niepełnoletniego kursanta / uczestnika szkoleń',
        'dydaktyk'    => 'dydaktyka / trenera',
    ];

    /** „jest uczestnikiem / słuchaczem / kursantem (niepotrzebne skreślić)” — oświadczenie kierownika. */
    public const PERSON_STATUS = ['uczestnik' => 'uczestnikiem', 'sluchacz' => 'słuchaczem', 'kursant' => 'kursantem', 'prowadzacy' => 'prowadzącym (dydaktyk / trener)'];

    public const TEXT_REQUEST = 'Zwracam się z prośbą o nadanie dostępu do Anthropic Claude, w tym także Anthropic Claude Code, w ramach szkolenia / kursu, który jest przeze mnie prowadzony / w którym uczestniczę — na potrzeby dydaktyczne.';

    /** OŚWIADCZENIE o opłacie: wstęp, punkty 1–4. Opłata jest za SAM DOSTĘP do narzędzia Claude (od 2026-11-09: 8 USD miesięcznie, wcześniej 25 zł ryczałtem). */
    public const FEE_INTRO = 'Ja, niżej podpisany/a, wyrażam zgodę na comiesięczną opłatę za dostęp do narzędzia Claude (licencja Claude Team w programie Claude for Nonprofit) udostępnionego mi przez Fundację.';
    public const FEE_LEAD  = 'Oświadczam, że zostałem/am poinformowany/a, że:';
    public const FEE_POINTS = [
        'Opłata wynosi 8 USD miesięcznie — tyle, ile Fundacja płaci za jedno miejsce w Claude Team w cenie dla organizacji non-profit (cena regularna: 25 USD). Kwotę przelicza się na złote po ostatnim kursie sprzedaży dewiz PKO Banku Polskiego z dnia wystawienia faktury.',
        'Fakturę dostaję w dniu włączenia dostępu, a potem co miesiąc w dniu włączenia dostępu, nie później niż 8. dnia miesiąca. Na zapłatę mam 5 dni (Przelewy24, przelew na rachunek Fundacji albo szybki przelew z faktury). Jeśli nie zapłacę w ciągu 7 dni po terminie, dostęp zostanie wstrzymany do czasu wpłaty.',
        'Zniżka dla organizacji non-profit nie obejmuje używania API Claude — dotyczy wyłącznie planów Team i Enterprise.',
        'Mogę zrezygnować w każdej chwili w panelu „Moje licencje” — wtedy nie dostanę kolejnych faktur. Jeśli dostawca zmieni cenę licencji, Fundacja poprosi mnie o ponowne zaakceptowanie tego oświadczenia.',
    ];

    /** KLAUZULA INFORMACYJNA (RODO) — akapity wzoru. */
    public const RODO = [
        'Zgodnie z rozporządzeniem Parlamentu Europejskiego i Rady (UE) 2016/679 (RODO) informujemy, że:',
        'Administratorem Pani/Pana danych osobowych jest Fundacja Edukacji Empatii Rozwoju „FEER” z siedzibą w Krakowie przy ul. Zamkniętej 10/1.5 (30-554 Kraków), KRS 0000779281, NIP 7343570539.',
        'Zakres danych obejmuje: imię, nazwisko, adres e-mail, nazwę organizacji, treść zadań i ćwiczeń wykonywanych w narzędziu oraz dane techniczne dotyczące korzystania z konta, jeśli narzędzie je rejestruje.',
        'Dane są przetwarzane w celu umożliwienia udziału w zajęciach i szkoleniu, realizacji współpracy z organizacją partnerską oraz rozliczenia opłaty. Podstawą prawną jest art. 6 ust. 1 lit. b RODO (wykonanie umowy o udział w szkoleniu) oraz art. 6 ust. 1 lit. f RODO (prawnie uzasadniony interes Administratora w prowadzeniu zajęć).',
        'Dostawca narzędzia, Anthropic, PBC, przetwarza dane w imieniu FEER na podstawie warunków umowy zawierającej umowę powierzenia przetwarzania danych. Dane mogą być przekazywane poza Europejski Obszar Gospodarczy na podstawie standardowych klauzul umownych zatwierdzonych przez Komisję Europejską.',
        'Wyniki ćwiczeń mogą być udostępniane organizacji partnerskiej wyłącznie w zakresie niezbędnym do realizacji celu szkolenia.',
        'Dane będą przechowywane przez czas trwania licencji oraz 2 lata od zakończenia udziału. Konto zostaje dezaktywowane po zakończeniu udziału.',
        'Przysługuje Pani/Panu prawo dostępu do swoich danych, ich sprostowania, usunięcia, ograniczenia przetwarzania oraz wniesienia sprzeciwu wobec przetwarzania opartego na prawnie uzasadnionym interesie Administratora. Kontakt w sprawach danych: kontakt@feer.pl. Przysługuje też prawo wniesienia skargi do Prezesa Urzędu Ochrony Danych Osobowych (ul. Stawki 2, 00-193 Warszawa).',
        'Podanie danych jest dobrowolne, ale niezbędne do udziału w zajęciach. Dane nie podlegają zautomatyzowanemu podejmowaniu decyzji ani profilowaniu.',
    ];
    /** Oświadczenie osoby pod klauzulą RODO (potwierdzane przez link). */
    public const RODO_DECL = 'Nie wprowadzam do narzędzia danych szczególnej kategorii (np. o zdrowiu, pochodzeniu, poglądach) ani danych osób trzecich, chyba że są w pełni zanonimizowane i jest to konieczne do celu ćwiczenia.';

    public const KIEROWNIK_FOOT = 'Osobie tej przydzielono imienne konto licencji narzędzia Claude (Anthropic, PBC) na czas udziału w zajęciach lub szkoleniu.';
    public const KIEROWNIK_DECL = 'Oświadczam, że osoba została poinformowana o zasadach przetwarzania danych osobowych określonych w klauzuli informacyjnej RODO oraz o wysokości opłaty: 8 USD miesięcznie po kursie PKO BP, płatnej co miesiąc.';

    /** Pola wniosku (biuro wypełnia; zgody potwierdza osoba przez link — pola check pominięte w formularzu). */
    public static function requestFields(): array
    {
        return [
            'rodzaj'      => ['Wniosek dotyczy', 'select', true, self::TYPES],
            'imie'        => ['Imię', 'text', true, null],
            'nazwisko'    => ['Nazwisko', 'text', true, null],
            'email_edu'   => ['Adres e-mail w domenie ' . self::EMAIL_DOMAIN, 'text', true, null],
            'nr_kursu'    => ['Nr kursu', 'text', false, null],
            'nazwa_grupy' => ['Nazwa grupy', 'text', false, null],
            'uwagi'       => ['Uwagi do wniosku (cel dydaktyczny, zakres)', 'textarea', false, null],
            'oplata_zgoda' => ['Oświadczenie o opłacie ryczałtowej 25 zł (potwierdza osoba przez link)', 'check', false, null],
            'rodo_zgoda'   => ['Zapoznanie z klauzulą RODO i oświadczenie o danych (potwierdza osoba przez link)', 'check', false, null],
        ];
    }

    /** Pola oświadczenia kierownika (decyzja cyfrowa). */
    public static function decisionFields(): array
    {
        return [
            'kierownik'    => ['Imię i nazwisko kierownika', 'text', true, null],
            'stanowisko'   => ['Stanowisko', 'text', true, null],
            'status_osoby' => ['Osoba jest', 'select', true, self::PERSON_STATUS],
            'zajecia'      => ['Nazwa zajęć / szkolenia', 'text', true, null],
            'partner'      => ['Organizacja partnerska (współpraca)', 'text', false, null],
            'okres_od'     => ['Okres uczestnictwa — od', 'date', true, null],
            'okres_do'     => ['Okres uczestnictwa — do', 'date', true, null],
            'helpdesk'     => ['Numer zgłoszenia w systemie HELPDESK', 'text', false, null],
            'warunki'      => ['Uwagi / uzasadnienie odmowy', 'textarea', false, null],
        ];
    }

    /** Domyślne wartości wniosku z konta (uczestnik / pracownik). */
    public static function defaults(array $acc): array
    {
        $parts = preg_split('/\s+/', trim((string)$acc['name'])) ?: [];
        $imie = (string)array_shift($parts); $nazwisko = implode(' ', $parts);
        $rodzaj = 'pelnoletni';
        if ($acc['kind'] === 'user') $rodzaj = 'dydaktyk';
        elseif (!empty($acc['student_account_id'])) {
            try { if (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE id = ? AND is_minor = 1", [(int)$acc['student_account_id']])) $rodzaj = 'maloletni'; } catch (\Throwable $e) {}
        }
        $login = (string)$acc['login'];
        $email = str_ends_with(strtolower($login), '@' . self::EMAIL_DOMAIN) ? $login : (str_ends_with(strtolower((string)$acc['email']), '@' . self::EMAIL_DOMAIN) ? (string)$acc['email'] : ($acc['kind'] === 'user' ? (string)$acc['email'] : ''));
        if ($email === '' && $acc['kind'] === 'client' && (int)($acc['id'] ?? 0) > 0) {   // login „l” + 8 cyfr z panelu /licencje (modules/license_login)
            require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';
            if ($own = LicenseLogin::get((int)$acc['id'])) $email = $own . '@' . self::EMAIL_DOMAIN;
        }
        return ['rodzaj' => $rodzaj, 'imie' => $imie, 'nazwisko' => $nazwisko, 'email_edu' => $email,
                'nr_kursu' => $acc['course_id'] ? (string)$acc['course_id'] : '', 'nazwa_grupy' => (string)$acc['course_name'], 'uwagi' => ''];
    }

    /** Domyślne wartości oświadczenia kierownika (z wniosku, konta i zapisu na kurs). */
    public static function decisionDefaults(array $op, array $actor): array
    {
        $r = $op['request'];
        $d = ['kierownik' => (string)($actor['name'] ?? ''), 'stanowisko' => 'Kierownik Technologii Informacyjnych', 'status_osoby' => ($r['rodzaj'] ?? '') === 'dydaktyk' ? 'prowadzacy' : 'uczestnik',
              'zajecia' => (string)($r['nazwa_grupy'] ?? $op['course_name']), 'partner' => '', 'okres_od' => '', 'okres_do' => '', 'helpdesk' => (string)($op['helpdesk_number'] ?? '')];
        try {
            if ($op['account_kind'] === 'client' && !empty($op['course_id'])) {
                $e = db_one("SELECT start_date, end_date FROM k30_ti_enrollments WHERE client_id = ? AND course_id = ? ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id DESC LIMIT 1", [(int)$op['account_id'], (int)$op['course_id']]);
                if ($e) { $d['okres_od'] = substr((string)$e['start_date'], 0, 10); $d['okres_do'] = substr((string)($e['end_date'] ?? ''), 0, 10); }
            }
        } catch (\Throwable $e) {}
        if ($d['okres_od'] === '') $d['okres_od'] = date('Y-m-d');
        return $d;
    }

    /** Treść oświadczenia kierownika z wartościami (puste → nawiasy wzoru). */
    public static function kierownikStatement(array $op, array $d): string
    {
        $r = $op['request'];
        $v = fn(string $k, string $ph) => trim((string)($d[$k] ?? '')) !== '' ? trim((string)$d[$k]) : '[' . $ph . ']';
        $status = self::PERSON_STATUS[(string)($d['status_osoby'] ?? '')] ?? 'uczestnikiem / słuchaczem / kursantem';
        $dt = fn(string $k) => trim((string)($d[$k] ?? '')) !== '' ? ldDate((string)$d[$k]) : '[data]';
        return 'Ja, niżej podpisany/a, ' . $v('kierownik', 'imię i nazwisko kierownika') . ', pełniący/a funkcję ' . $v('stanowisko', 'stanowisko')
            . ' w Fundacji Edukacji Empatii Rozwoju „FEER” (ul. Zamkniętej 10/1.5, 30-554 Kraków), oświadczam, że: Osoba: ' . trim((string)($r['imie'] ?? '') . ' ' . (string)($r['nazwisko'] ?? ''))
            . ', adres e-mail: ' . (string)($r['email_edu'] ?? '') . ', jest ' . $status . ' zajęć lub szkolenia: ' . $v('zajecia', 'nazwa zajęć / szkolenia')
            . ', prowadzonego przez Fundację' . (trim((string)($d['partner'] ?? '')) !== '' ? ' w ramach współpracy z ' . trim((string)$d['partner']) : '') . '. Okres uczestnictwa: od ' . $dt('okres_od') . ' do ' . $dt('okres_do')
            . '. Numer zgłoszenia w systemie HELPDESK: ' . (trim((string)($d['helpdesk'] ?? '')) !== '' ? trim((string)$d['helpdesk']) : (trim((string)($op['helpdesk_number'] ?? '')) !== '' ? (string)$op['helpdesk_number'] : '[numer zgłoszenia]')) . '. '
            . self::KIEROWNIK_FOOT;
    }

    /** Czy adres e-mail nadaje się do wniosku (domena edu.feer.org.pl; dla pracowników także feer.org.pl). */
    public static function emailOk(string $email, string $kind): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        return str_ends_with($email, '@' . self::EMAIL_DOMAIN) || ($kind === 'user' && str_ends_with($email, '@feer.org.pl'));
    }
}
