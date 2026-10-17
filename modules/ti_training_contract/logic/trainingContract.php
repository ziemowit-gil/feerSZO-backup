<?php
/**
 * modules/ti_training_contract/logic/trainingContract.php — treść i DOCX „Umowy o świadczenie usług szkoleniowych”
 * (nr TI/UMSZ/NNN/RRRR) między Fundacją a Kursantem/Kursantką, z Załącznikami nr 1–3 (program, klauzula RODO,
 * wzór odstąpienia). Zapis, numeracja i Rejestr Umów (RU): trainingContractStore.php.
 *
 * Szablon treści (paragraphs()/annexes()) — znaczniki rozwijane w render():
 *   {pole}            wartość pola formularza (puste → linia do ręcznego uzupełnienia),
 *   {K} {K_gen} {K_dat} {K_acc} {K_ins}   „Kursantka / Kursant” w przypadkach: mian., dop., cel., bier., narz.,
 *   [żeńska|męska]    forma zależna od pola „plec”.
 * Puste pola są dozwolone — wydruk ma wtedy linie, jak papierowy wzór.
 */
class TiTrainingContract
{
    /** Źródła finansowania (wpływają na § 1 ust. 2 i § 2 ust. 2). */
    public const FUNDING = [
        'publiczne' => 'Ze środków publicznych (np. PFRON) z dopłatą uczestnika lub bez',
        'wlasne'    => 'W całości ze środków własnych uczestnika',
    ];

    /** Odmiana „Kursantka / Kursant”. */
    private const NOUN = [
        'K'     => ['Kursantka', 'Kursant'],
        'K_gen' => ['Kursantki', 'Kursanta'],
        'K_dat' => ['Kursantce', 'Kursantowi'],
        'K_acc' => ['Kursantkę', 'Kursanta'],
        'K_ins' => ['Kursantką', 'Kursantem'],
    ];

    /**
     * Pola formularza: klucz => [etykieta, wartość domyślna, sekcja, typ]. Typ: text|textarea|date.
     * Kolejność = kolejność w formularzu.
     */
    public static function fields(): array
    {
        return [
            // Strony
            'data'           => ['Data zawarcia', date('Y-m-d'), 'strony', 'date'],
            'miejsce'        => ['Miejsce zawarcia', 'Krakowie', 'strony', 'text'],
            'imie_nazwisko'  => ['Imię i nazwisko', '', 'strony', 'text'],
            'imie_narz'      => ['Imię i nazwisko w narzędniku („z kim?”) — puste = automatycznie', '', 'strony', 'text'],
            'adres'          => ['Adres zamieszkania', '', 'strony', 'text'],
            'pesel'          => ['PESEL', '', 'strony', 'text'],
            'email'          => ['E-mail', '', 'strony', 'text'],
            'telefon'        => ['Telefon', '', 'strony', 'text'],
            'regon'          => ['REGON Fundacji', '', 'fundacja', 'text'],
            'reprezentant'   => ['Reprezentant Fundacji (narzędnik)', 'Ziemowita Gila – Prezesa Zarządu', 'fundacja', 'text'],
            'fund_email'     => ['E-mail Fundacji', 'kontakt@feer.pl', 'fundacja', 'text'],
            'fund_tel'       => ['Telefon Fundacji', '578 599 703', 'fundacja', 'text'],
            'panel'          => ['Adres Panelu Kursanta', 'kursant.feer.org.pl', 'fundacja', 'text'],
            'email_wsparcie' => ['Adres do zgłaszania problemów z logowaniem (§ 4)', 'kontakt@feer.pl', 'fundacja', 'text'],
            'regulamin_od'   => ['Regulamin rekrutacji i uczestnictwa obowiązuje od', '15.01.2023 r.', 'fundacja', 'text'],
            // § 1 i Załącznik nr 1
            'temat'          => ['Tematyka szkolenia', '', 'szkolenie', 'text'],
            'zrodlo_publ'    => ['Źródło finansowania publicznego (dopełniacz: „z …”)', 'dofinansowania PFRON w ramach programu „Aktywny samorząd”', 'szkolenie', 'text'],
            'wymiar'         => ['Łączny wymiar (np. 30 godzin dydaktycznych)', '', 'szkolenie', 'text'],
            'link_wyprzedzenie' => ['Link do spotkania najpóźniej (np. 24 godziny) przed zajęciami', '24 godziny', 'szkolenie', 'text'],
            'wymagania_tech' => ['Wymagania techniczne', 'komputer z dostępem do internetu, mikrofon, słuchawki lub głośniki oraz zainstalowany czytnik ekranu NVDA', 'szkolenie', 'textarea'],
            'min_uczestnikow' => ['Minimalna liczba uczestników (zajęcia grupowe)', 'nie dotyczy — zajęcia indywidualne', 'szkolenie', 'text'],
            'prowadzacy'     => ['Prowadzący / prowadząca', '', 'szkolenie', 'text'],
            'materialy'      => ['Materiały szkoleniowe w postaci', 'materiałów elektronicznych dostępnych dla czytnika ekranu', 'szkolenie', 'text'],
            'materialy_udost' => ['Materiały udostępnione (sposób)', 'w Panelu Kursanta', 'szkolenie', 'text'],
            'cel'            => ['Cel kształcenia (Zał. 1)', '', 'szkolenie', 'textarea'],
            'zakres'         => ['Zakres tematyczny i kolejność modułów (Zał. 1)', '', 'szkolenie', 'textarea'],
            'asynchr'        => ['Elementy asynchroniczne (Zał. 1)', 'nie występują', 'szkolenie', 'text'],
            'terminy'        => ['Terminy i godziny (Zał. 1)', 'zgodnie z harmonogramem w Panelu Kursanta', 'szkolenie', 'textarea'],
            'platforma_uwagi' => ['Platforma — uwagi (Zał. 1)', '', 'szkolenie', 'text'],
            'liczba_uczestnikow' => ['Liczba uczestników, jeżeli szkolenie grupowe (Zał. 1)', 'nie dotyczy', 'szkolenie', 'text'],
            'warunki_ukonczenia' => ['Warunki ukończenia i zaliczenia (§ 6, Zał. 1)', 'obecność na co najmniej 80% zajęć; test końcowy nie jest wymagany', 'szkolenie', 'textarea'],
            'dokument'       => ['Dokument wydawany po szkoleniu', 'zaświadczenie o ukończeniu Szkolenia', 'szkolenie', 'text'],
            'dokument_termin' => ['Termin wydania dokumentu', '14 dni od zakończenia Szkolenia', 'szkolenie', 'text'],
            // § 2
            'wynagrodzenie'  => ['Łączne wynagrodzenie (zł)', '', 'platnosci', 'text'],
            'kwota_platnik'  => ['Kwota pokrywana przez płatnika publicznego (zł)', '', 'platnosci', 'text'],
            'platnik'        => ['Płatnik publiczny (np. Miejski Ośrodek Pomocy Społecznej w Krakowie)', '', 'platnosci', 'text'],
            'kwota_kursant'  => ['Kwota pokrywana przez uczestnika (zł)', '', 'platnosci', 'text'],
            'rachunek'       => ['Nr rachunku bankowego Fundacji', '', 'platnosci', 'text'],
            'faktura_na'     => ['Faktura / rachunek wystawiany na', '', 'platnosci', 'text'],
            // § 5
            'zwrot_nadplaty' => ['Zwrot nadpłaty po rezygnacji w terminie', '14 dni', 'zmiany', 'text'],
            'zmiana_wyprzedzenie' => ['Propozycja zmiany harmonogramu z wyprzedzeniem', '3 dni roboczych', 'zmiany', 'text'],
            'termin_zastepczy' => ['Termin zastępczy w ciągu', '14 dni', 'zmiany', 'text'],
            'uzgodnienie_do' => ['Brak uzgodnienia nowego terminu do', '30 dni od odwołanych zajęć', 'zmiany', 'text'],
            'zwrot_czesci'   => ['Zwrot opłaty za niewykonaną część w ciągu', '14 dni', 'zmiany', 'text'],
            'nieobecnosc_zasady' => ['Zasady odrabiania nieobecności', 'zajęcia odwołane z zachowaniem terminu zgłoszenia odrabia się w terminie uzgodnionym przez Strony; zajęcia nieodbyte bez zgłoszenia nie są odrabiane i nie powodują dodatkowej opłaty', 'zmiany', 'textarea'],
            'reklamacje'     => ['Sposób i termin rozpatrywania reklamacji', 'Fundacja rozpatruje reklamację w terminie 14 dni od jej otrzymania i odpowiada w formie, w jakiej ją złożono', 'zmiany', 'textarea'],
            // Załącznik nr 2 — RODO
            'rodo_pfron'     => ['RODO: cel i podstawa dla rozliczenia dofinansowania', 'art. 6 ust. 1 lit. c RODO w związku z przepisami o rehabilitacji zawodowej i społecznej oraz dokumentami programu „Aktywny samorząd”', 'rodo', 'textarea'],
            'rodo_art9'      => ['RODO: dane o zdrowiu / niepełnosprawności (art. 9)', 'informacja o rodzaju niepełnosprawności w zakresie niezbędnym do dostosowania zajęć i rozliczenia dofinansowania — art. 9 ust. 2 lit. a RODO (wyraźna zgoda) albo lit. b/g, jeżeli wynika to z przepisów', 'rodo', 'textarea'],
            'rodo_odbiorcy'  => ['RODO: rzeczywiste kategorie odbiorców', 'biuro rachunkowe, dostawcy usług IT i hostingu, operator SMS, Microsoft (poczta), Zoom Video Communications, WhatsApp (Meta), instytucja rozliczająca dofinansowanie', 'rodo', 'textarea'],
            'rodo_transfer'  => ['RODO: transfer do państw trzecich', 'Stany Zjednoczone — na podstawie decyzji Komisji Europejskiej stwierdzającej odpowiedni stopień ochrony (EU-US Data Privacy Framework) albo standardowych klauzul umownych', 'rodo', 'textarea'],
            'rodo_okresy'    => ['RODO: okresy przechowywania', 'dokumentacja księgowa — 5 lat od końca roku podatkowego; dokumentacja dofinansowania — przez okres wskazany w umowie o dofinansowanie; pozostałe dane — do upływu terminu przedawnienia roszczeń', 'rodo', 'textarea'],
            'rodo_wymagane'  => ['RODO: dane wymagane do rozliczenia', 'imię i nazwisko, PESEL, adres oraz potwierdzenie udziału w zajęciach', 'rodo', 'textarea'],
        ];
    }

    /** Sekcje formularza. */
    public const SECTIONS = [
        'strony'    => 'Kursant / Kursantka',
        'szkolenie' => 'Szkolenie (§ 1, § 6, Załącznik nr 1)',
        'platnosci' => 'Wynagrodzenie (§ 2)',
        'zmiany'    => 'Rezygnacja, zmiany terminów, reklamacje (§ 5)',
        'rodo'      => 'Klauzula informacyjna (Załącznik nr 2)',
        'fundacja'  => 'Dane Fundacji',
    ];

    /** Dane domyślne nowej umowy. */
    public static function defaults(): array
    {
        $d = ['plec' => 'K', 'finansowanie' => 'publiczne', 'client_id' => 0];
        foreach (self::fields() as $k => $f) $d[$k] = $f[1];
        return $d;
    }

    /** Dane przykładowe — Pani (jak we wzorze) albo Pan. */
    public static function sample(string $plec = 'K'): array
    {
        $K = $plec !== 'M';
        return array_merge(self::defaults(), [
            'plec' => $K ? 'K' : 'M',
            'imie_nazwisko' => $K ? 'Anna Testowa' : 'Jan Testowy',
            'adres' => $K ? 'ul. Przykładowa 1/2, 30-001 Kraków' : 'ul. Próbna 5, 31-002 Kraków',
            'pesel' => $K ? '90010112349' : '85020354321',
            'email' => $K ? 'anna.testowa@example.com' : 'jan.testowy@example.com',
            'telefon' => '535 231 923',
            'temat' => 'Obsługa czytnika ekranu NVDA – poziom podstawowy',
            'wymiar' => '30 godzin dydaktycznych',
            'prowadzacy' => 'Jan Kowalski',
            'cel' => 'Samodzielna obsługa komputera z systemem Windows przy użyciu czytnika ekranu NVDA.',
            'zakres' => '1) instalacja i konfiguracja NVDA; 2) nawigacja po systemie; 3) praca z dokumentami; 4) przeglądanie stron internetowych; 5) poczta elektroniczna.',
            'wynagrodzenie' => '3000,00', 'kwota_platnik' => '2700,00', 'kwota_kursant' => '300,00',
            'platnik' => 'Miejski Ośrodek Pomocy Społecznej w Krakowie',
            'rachunek' => '00 0000 0000 0000 0000 0000 0000',
            'faktura_na' => $K ? 'Kursantkę' : 'Kursanta',
        ]);
    }

    /** Oczyszcza dane z formularza. Zwraca [dane, błędy]. */
    public static function normalize(array $in): array
    {
        $d = self::defaults();
        foreach (self::fields() as $k => $_) if (isset($in[$k])) $d[$k] = trim(str_replace("\r", '', (string)$in[$k]));
        $d['plec'] = ($in['plec'] ?? 'K') === 'M' ? 'M' : 'K';
        $d['finansowanie'] = isset(self::FUNDING[$in['finansowanie'] ?? '']) ? $in['finansowanie'] : 'publiczne';
        $d['client_id'] = max(0, (int)($in['client_id'] ?? 0));
        $d['pesel'] = preg_replace('/\s+/', '', $d['pesel']);
        $err = [];
        if ($d['pesel'] !== '' && !self::peselOk($d['pesel'])) $err[] = 'PESEL ma nieprawidłową sumę kontrolną albo długość.';
        if ($d['data'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['data'])) $err[] = 'Data zawarcia ma zły format.';
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Adres e-mail jest nieprawidłowy.';
        return [$d, $err];
    }

    public static function peselOk(string $p): bool
    {
        if (!preg_match('/^\d{11}$/', $p)) return false;
        $w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3]; $s = 0;
        for ($i = 0; $i < 10; $i++) $s += $w[$i] * (int)$p[$i];
        return (10 - $s % 10) % 10 === (int)$p[10];
    }

    /**
     * Imię i nazwisko w narzędniku („zawarta z Anną Testową”) — prosta heurystyka dla typowych polskich form;
     * nietypowe nazwiska poprawia się ręcznie w polu „imie_narz”.
     */
    public static function instrumental(string $name, string $plec): string
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim($name)) as $w) {
            if ($w === '') continue;
            $parts = [];
            foreach (explode('-', $w) as $p) $parts[] = self::instrumentalWord($p, $plec === 'K');
            $out[] = implode('-', $parts);
        }
        return implode(' ', $out);
    }

    private static function instrumentalWord(string $w, bool $female): string
    {
        $l = mb_strtolower($w);
        $end = fn(string $s) => str_ends_with($l, $s);
        $cut = fn(int $n) => mb_substr($w, 0, mb_strlen($w) - $n);
        if ($female) {
            if ($end('a')) return $cut(1) . 'ą';                       // Anna → Anną, Kowalska → Kowalską, Testowa → Testową
            return $w;                                                // nazwiska nieodmienne (Nowak, Kowalczyk)
        }
        if ($end('ski') || $end('cki') || $end('dzki')) return $w . 'm'; // Kowalski → Kowalskim
        if ($end('y') || $end('i')) return $w . 'm';                    // Testowy → Testowym
        if ($end('a')) return $cut(1) . 'ą';                            // Kuba → Kubą, Zawisza → Zawiszą
        if ($end('ek')) return $cut(2) . 'kiem';                       // Marek → Markiem, Hajek → Hajkiem
        if ($end('k') || $end('g')) return $w . 'iem';                 // Nowak → Nowakiem
        if ($end('ec')) return $cut(2) . 'cem';                        // Stefaniec → Stefańcem (uproszczenie)
        if ($end('o')) return $cut(1) . 'em';                          // Fredro → Fredrem
        return $w . 'em';                                              // Jan → Janem, Piotr → Piotrem
    }

    /** Rozwija znaczniki szablonu. */
    public static function render(string $tpl, array $d): string
    {
        $fem = ($d['plec'] ?? 'K') !== 'M';
        $tpl = preg_replace_callback('/\[([^\[\]|]*)\|([^\[\]|]*)\]/u', fn($m) => $fem ? $m[1] : $m[2], $tpl);
        return preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function ($m) use ($d, $fem) {
            $k = $m[1];
            if (isset(self::NOUN[$k])) return self::NOUN[$k][$fem ? 0 : 1];
            if ($k === 'data_pl') return ($d['data'] ?? '') !== '' ? date('d.m.Y', strtotime($d['data'])) : self::blank(12);
            if ($k === 'nr') return ($d['nr'] ?? '') !== '' ? $d['nr'] : 'TI/UMSZ/XXX/' . date('Y');
            if ($k === 'imie_narz') {
                $v = trim((string)($d['imie_narz'] ?? ''));
                if ($v === '' && trim((string)($d['imie_nazwisko'] ?? '')) !== '') $v = self::instrumental($d['imie_nazwisko'], $d['plec'] ?? 'K');
                return $v !== '' ? $v : self::blank(28);
            }
            $v = trim((string)($d[$k] ?? ''));
            return $v !== '' ? $v : self::blank(in_array($k, ['pesel', 'telefon', 'regon'], true) ? 14 : 24);
        }, $tpl);
    }

    private static function blank(int $n): string
    {
        return str_repeat('_', $n);
    }

    /** Nagłówek (strony umowy) — lista akapitów. */
    public static function preamble(): array
    {
        return [
            'Zawarta dnia {data_pl} r. w {miejsce}',
            'pomiędzy:',
            'Fundacją Edukacji Empatii Rozwoju „FEER” z siedzibą w Krakowie przy ul. Zamkniętej 10/1.5, 30-554 Kraków, wpisaną do rejestru stowarzyszeń Krajowego Rejestru Sądowego pod numerem 0000779281, NIP 7343570539, REGON {regon}, reprezentowaną przez {reprezentant}, kontakt: {fund_email}, {fund_tel}, zwaną dalej „Fundacją”,',
            'a',
            '{imie_narz}, zamieszkał[ą|ym] pod adresem: {adres}, PESEL {pesel}, e-mail: {email}, telefon: {telefon}, zwan[ą|ym] dalej „{K_ins}”,',
            'łącznie zwanymi dalej „Stronami”.',
        ];
    }

    /** Paragrafy umowy: [[tytuł paragrafu, [ustęp, …]], …]. */
    public static function paragraphs(array $d): array
    {
        $publ = ($d['finansowanie'] ?? 'publiczne') === 'publiczne';
        return [
            ['Przedmiot i organizacja szkolenia', [
                '{K} zleca Fundacji, a Fundacja zobowiązuje się przeprowadzić szkolenie o tematyce: „{temat}”, zwane dalej „Szkoleniem”.',
                $publ
                    ? 'Szkolenie jest realizowane przez Fundację w ramach odpłatnej działalności pożytku publicznego. Jest finansowane ze środków publicznych, w tym z {zrodlo_publ}, na zasadach i w zakresie wynikających z decyzji o przyznaniu dofinansowania oraz dokumentów dotyczących jego rozliczenia.'
                    : 'Szkolenie jest realizowane przez Fundację w ramach odpłatnej działalności pożytku publicznego i jest finansowane ze środków własnych {K_gen}.',
                'Szkolenie odbędzie się zdalnie (online), w formie zajęć synchronicznych na żywo, w terminach wskazanych w Załączniku nr 1. Łączny wymiar wynosi {wymiar}. Sposób realizacji treści asynchronicznych, jeżeli będą przewidziane, wymaga opisania w Załączniku nr 1.',
                'Szczegółowy program, cele i zakres tematyczny oraz wymiar godzin określa Załącznik nr 1, stanowiący integralną część Umowy. Harmonogram zajęć Fundacja udostępnia {K_dat} w systemie Panel Kursanta pod adresem {panel}, po zalogowaniu się na indywidualne konto. Zmiany harmonogramu wymagają uzgodnienia przez Strony w formie dokumentowej.',
                'Fundacja utworzy dla {K_gen} indywidualne konto w panelu pod adresem {panel}. Po podpisaniu Umowy nazwę użytkownika do konta prześle na adres e-mail wskazany przez {K_acc}, a tymczasowe hasło do pierwszego logowania — w wiadomości SMS na wskazany przez [nią|niego] numer telefonu. Zajęcia online będą prowadzone za pośrednictwem platformy Zoom albo WhatsApp; wybór platformy dla danego spotkania Strony uzgodnią. Link lub inne dane umożliwiające dołączenie do spotkania Fundacja udostępni w panelu {K_gen} nie później niż {link_wyprzedzenie} przed rozpoczęciem zajęć. Zasady pierwszego logowania, zmiany hasła i odzyskiwania dostępu określa § 4 ust. 2 Umowy. Dane dostępowe są przeznaczone do osobistego użytku {K_gen} i nie powinny być udostępniane osobom nieuprawnionym.',
                'Fundacja poinformuje {K_acc} przed Szkoleniem, czy dane spotkanie odbędzie się przez Zoom czy WhatsApp, oraz o wymaganym sprzęcie, oprogramowaniu i sposobie połączenia. Wymagania techniczne obejmują w szczególności {wymagania_tech}. Fundacja sprawdzi przed zajęciami możliwość udziału z użyciem czytnika ekranu NVDA; jeżeli uzgodniona platforma utrudnia taki udział, Strony uzgodnią dostępne rozwiązanie alternatywne.',
                'Udział w Szkoleniu może być potwierdzany przez logowanie na platformie, potwierdzenie obecności przez {K_acc} lub inny uzgodniony sposób. Fundacja może dokumentować udział w zakresie niezbędnym do potwierdzenia wykonania Szkolenia i jego rozliczenia, z uwzględnieniem zasad właściwych dla dofinansowania.',
                'W razie przerwy w połączeniu Strony podejmą niezwłocznie próbę wznowienia zajęć. Jeżeli przyczyna leżąca po stronie Fundacji lub używanej przez nią platformy uniemożliwi przeprowadzenie istotnej części Szkolenia, Fundacja uzgodni z {K_ins} termin uzupełniający albo inny sposób realizacji tej części bez dodatkowego wynagrodzenia. W przypadku problemu po stronie {K_gen} Strony uzgodnią sposób dalszego postępowania, uwzględniając możliwość wznowienia zajęć lub wyznaczenia terminu uzupełniającego.',
                'Żadna ze Stron nie może nagrywać ani utrwalać Szkolenia bez uprzedniej zgody drugiej Strony wyrażonej w formie dokumentowej. Jeżeli nagrywanie uzgodniono, Fundacja przed jego rozpoczęciem poinformuje {K_acc} o celu, zakresie, zasadach przechowywania i udostępniania nagrania oraz o właściwych zasadach przetwarzania danych osobowych.',
                'Szkolenie lub jego wyodrębnione części mogą być prowadzone grupowo albo indywidualnie, zgodnie z Załącznikiem nr 1. Dla części prowadzonych grupowo w Załączniku nr 1 należy przed zawarciem Umowy określić minimalną liczbę uczestników: {min_uczestnikow}. Uprawnienie Fundacji do odwołania zajęć z powodu spadku liczby uczestników poniżej tego minimum określa § 5 Umowy i dotyczy wyłącznie części wyraźnie oznaczonych w Załączniku nr 1 jako grupowe. Nie dotyczy zajęć indywidualnych. Zmiana zajęć grupowych na indywidualne wymaga uzgodnienia przez Strony oraz zgodności z warunkami dofinansowania.',
                'Szkolenie poprowadzi {prowadzacy}. Fundacja może zastąpić osobę prowadzącą osobą o równorzędnych kwalifikacjach, informując o tym {K_acc} przed zajęciami.',
                'Fundacja zapewni materiały szkoleniowe w postaci {materialy} oraz udostępni je {materialy_udost}. Inne świadczenia, jeżeli są objęte Umową, należy wymienić w Załączniku nr 1.',
                'Fundacja będzie współdziałać przy potwierdzaniu udziału, wykonania i rozliczenia Szkolenia oraz — jeżeli wymagają tego dokumenty dotyczące dofinansowania — przy monitoringu i ewaluacji. Szczegółowe wymagania wynikają z decyzji o dofinansowaniu i dokumentów właściwych dla tej sprawy; Umowa nie przesądza o nieznanych lub niewskazanych warunkach programu.',
                'Fundacja, jako Organizator, zobowiązuje się realizować Szkolenie zgodnie z Umową i Załącznikiem nr 1, z należytą starannością właściwą dla profesjonalnego organizatora. W szczególności Fundacja zapewni osobę prowadzącą o uzgodnionych kwalifikacjach, przekaże {K_dat} uzgodnione materiały w dostępnej formie, zapewni link, dostęp do panelu i narzędzia online w zakresie pozostającym pod jej kontrolą oraz udzieli informacji potrzebnych do udziału w zajęciach. Fundacja będzie współdziałać z {K_ins} przy usuwaniu problemów technicznych, niezwłocznie poinformuje [ją|go] o przeszkodach mogących wpłynąć na termin lub sposób realizacji i zaproponuje rozwiązanie zastępcze. Fundacja będzie prowadzić dokumentację obecności, realizacji i rozliczenia w zakresie wymaganym Umową oraz dokumentami właściwymi dla dofinansowania, a po spełnieniu uzgodnionych warunków wyda dokument potwierdzający udział lub ukończenie Szkolenia. Obowiązki dokumentacyjne nie stanowią zgody {K_gen} na nagrywanie zajęć ani na wykorzystywanie [jej|jego] wizerunku do promocji; takie działania wymagają odrębnej, właściwej podstawy i — gdy jest wymagana — osobnej zgody.',
            ]],
            ['Wynagrodzenie i finansowanie', [
                'Łączne wynagrodzenie za Szkolenie wynosi {wynagrodzenie} zł. Fundacja oświadcza, że na dzień zawarcia Umowy nie jest zarejestrowanym czynnym podatnikiem VAT. Sposób rozliczenia podatku VAT i udokumentowania wynagrodzenia będzie zgodny z obowiązującym statusem podatkowym Fundacji oraz przepisami prawa.',
                $publ
                    ? 'Wynagrodzenie zostanie pokryte w następujący sposób: {kwota_platnik} zł przez {platnik} oraz {kwota_kursant} zł przez {K_acc}. Sposób finansowania powinien odpowiadać decyzji o dofinansowaniu i dokumentom właściwym dla tej sprawy.'
                    : 'Wynagrodzenie zostanie pokryte w całości przez {K_acc}.',
                'Fundacja wystawia rozliczenie za szkolenie miesięcznie. Zapłata wynagrodzenia należnego od {K_gen} następuje przelewem na rachunek bankowy Fundacji wskazany w rozliczeniu, nr {rachunek}. Termin płatności upływa 5. dnia miesiąca następującego po miesiącu wystawienia rozliczenia. Faktura lub inny właściwy dokument księgowy zostanie wystawiony na {faktura_na}.',
                'Samo nieprzyznanie, obniżenie albo opóźnienie wypłaty dofinansowania nie zwiększa automatycznie kwoty należnej od {K_gen}. Zmiana wysokości [jej|jego] odpłatności lub zasad finansowania wymaga uprzedniego uzgodnienia przez Strony na piśmie, w tym w postaci dokumentowej.',
                'Jeżeli płatnikiem ma być podmiot niebędący Stroną Umowy, jego obowiązek zapłaty powinien wynikać z odrębnego dokumentu. Samo wskazanie takiego podmiotu jako płatnika nie nakłada na niego obowiązków umownych ani nie zmienia kwoty zobowiązania {K_gen}.',
            ]],
            ['Materiały szkoleniowe i prawa autorskie', [
                'Materiały przekazane {K_dat} w związku ze Szkoleniem, w tym materiały udostępnione w panelu {K_gen}, mogą stanowić utwory chronione prawem autorskim. Fundacja udziela {K_dat}, w zakresie praw przysługujących Fundacji albo licencji uprawniających ją do udzielenia dalszego upoważnienia, niewyłącznej licencji na korzystanie z tych materiałów na własny, niekomercyjny użytek związany z udziałem w Szkoleniu.',
                'Licencja obejmuje korzystanie na terytorium całego świata, przez czas nieoznaczony od dnia udostępnienia danego materiału, na następujących polach eksploatacji: utrwalanie i zwielokrotnianie materiałów techniką cyfrową lub drukarską w zakresie niezbędnym do osobistej nauki oraz odczytywanie, wyświetlanie i korzystanie z nich za pośrednictwem panelu {K_gen} lub na urządzeniu {K_gen}.',
                'Wynagrodzenie za licencję, o której mowa w niniejszym paragrafie, jest uwzględnione w wynagrodzeniu określonym w § 2. Licencja nie uprawnia do rozpowszechniania materiałów, udostępniania ich innym osobom, publikowania, odsprzedaży, komercyjnego wykorzystania ani modyfikowania, z wyjątkiem zakresu, na jaki Fundacja udzieli odrębnej zgody lub zezwalają przepisy prawa.',
                'Umowa nie przenosi autorskich praw majątkowych ani osobistych. Prawa do elementów materiałów należących do osób trzecich podlegają warunkom licencji lub innym upoważnieniom udzielonym przez uprawnione podmioty; Fundacja poinformuje {K_acc} o ograniczeniach istotnych dla dozwolonego korzystania z takich elementów.',
            ]],
            ['Panel {K_gen}', [
                'Fundacja udostępni {K_dat} panel internetowy pod adresem {panel}, dostępny po zalogowaniu się na indywidualne konto {K_gen}, w celu udostępniania harmonogramu zajęć i informacji organizacyjnych, materiałów związanych ze Szkoleniem oraz kontaktu w sprawach dotyczących jego realizacji.',
                'Po podpisaniu Umowy Fundacja prześle nazwę użytkownika na adres e-mail {K_gen} wskazany w Umowie, a tymczasowe, jednorazowe hasło do pierwszego logowania — SMS-em na wskazany w Umowie numer telefonu. Hasło tymczasowe jest ważne przez 24 godziny od wysłania i wygasa po pierwszym użyciu; przy pierwszym logowaniu {K} ustawi własne hasło zgodne z wymaganiami panelu. {K} zobowiązuje się używać hasła unikalnego, nieużywanego do innych kont, zachować je w poufności i nie zapisywać go w miejscu dostępnym dla osób nieuprawnionych. Jeżeli hasło wygaśnie, zostanie utracone lub logowanie nie będzie możliwe, {K} zgłosi problem Fundacji pod adresem {email_wsparcie}. Po weryfikacji tożsamości {K_gen} za pośrednictwem wcześniej wskazanych danych kontaktowych Fundacja unieważni dotychczasowe hasło i prześle nowe hasło tymczasowe SMS-em na numer telefonu wskazany w Umowie; jeśli {K} [utraciła|utracił] dostęp do tego numeru, Fundacja uzgodni odrębny sposób bezpiecznej weryfikacji. Hasło nie będzie przesyłane pocztą elektroniczną. W razie podejrzenia ujawnienia hasła lub nieuprawnionego dostępu {K} niezwłocznie powiadomi Fundację, która podejmie działania w celu zablokowania lub zresetowania dostępu.',
                'Konto jest przeznaczone do osobistego użytku {K_gen}. {K} zobowiązuje się nie udostępniać danych logowania innym osobom, chronić je przed dostępem osób nieuprawnionych i niezwłocznie poinformować Fundację o podejrzeniu ich ujawnienia lub nieuprawnionego użycia.',
                '{K} korzysta z panelu zgodnie z jego przeznaczeniem, umową i przepisami prawa. Zabronione jest podejmowanie działań zakłócających działanie panelu, omijanie zabezpieczeń lub uzyskiwanie dostępu do danych innych użytkowników.',
                'Fundacja może czasowo ograniczyć dostęp do panelu z przyczyn technicznych, konserwacyjnych lub bezpieczeństwa. W miarę możliwości poinformuje o planowanej przerwie z wyprzedzeniem, a o nieplanowanej przerwie – niezwłocznie po uzyskaniu informacji. Po upływie okresu dostępu Fundacja może dezaktywować konto; materiały, do których licencja została udzielona na podstawie § 3, pozostają objęte warunkami tej licencji, niezależnie od dostępności panelu.',
                'Informacje o przetwarzaniu danych osobowych związanym z prowadzeniem panelu Fundacja przekazuje {K_dat} odrębnie, w klauzuli informacyjnej.',
            ]],
            ['Rezygnacja, odstąpienie i odwołanie Szkolenia', [
                '{K} może złożyć oświadczenie o rezygnacji lub wypowiedzeniu Umowy Fundacji w formie dokumentowej, w szczególności na adres e-mail Fundacji albo przez panel {K_gen}. W zakresie, w jakim do Umowy stosuje się art. 750 Kodeksu cywilnego, rozliczenie następuje z uwzględnieniem art. 746 Kodeksu cywilnego: obejmuje wynagrodzenie odpowiadające czynnościom wykonanym do chwili zakończenia Umowy oraz uzasadnione wydatki poniesione w celu jej należytego wykonania; nadpłata podlega zwrotowi w terminie {zwrot_nadplaty}. Nie wyłącza się z góry uprawnienia do wypowiedzenia z ważnych powodów.',
                'Ustalony harmonogram może zostać zmieniony za uzgodnieniem Stron w formie dokumentowej. Fundacja przekaże propozycję zmiany {K_dat} przez Panel Kursanta, e-mail lub SMS z wyprzedzeniem co najmniej {zmiana_wyprzedzenie}; w nagłym, nieprzewidywalnym przypadku poinformuje [ją|go] niezwłocznie po powzięciu informacji o przeszkodzie. Jeżeli Fundacja nie może przeprowadzić zajęć, niezwłocznie zawiadomi {K_acc} i zaproponuje termin zastępczy w ciągu {termin_zastepczy}. Zajęcia grupowe mogą zostać odwołane z powodu spadku liczby uczestników poniżej minimum wskazanego w Załączniku nr 1 przed zawarciem Umowy; zasada ta nie dotyczy zajęć indywidualnych. Zajęcia indywidualne Fundacja może odwołać lub przełożyć wyłącznie z ważnej przyczyny uniemożliwiającej ich przeprowadzenie. Zmiana formy zajęć lub ceny wymaga odrębnego uzgodnienia przez Strony. Jeżeli {K} nie zaakceptuje terminu zastępczego albo Strony nie uzgodnią nowego terminu do {uzgodnienie_do}, {K} może zrezygnować z niewykonanej części Szkolenia, a Fundacja zwróci właściwemu płatnikowi opłatę przypadającą na tę część w ciągu {zwrot_czesci}. Odwołanie lub zmiana terminu przez Fundację nie powoduje dodatkowej opłaty ani automatycznego zwiększenia wynagrodzenia {K_gen}.',
                'Jeżeli {K} nie może uczestniczyć w zajęciach, zgłasza nieobecność Fundacji przez Panel Kursanta, e-mail lub SMS co najmniej 3 dni robocze przed zajęciami. Gdy przyczyna jest nagła i nieprzewidywalna, informuje Fundację niezwłocznie po jej zaistnieniu, w miarę możliwości nie później niż 6 godzin przed zajęciami, a jeżeli nie jest to możliwe — niezwłocznie po ustaniu przeszkody. Na uzasadnione żądanie Fundacji {K} uprawdopodobni przyczynę nieobecności, przekazując tylko informacje niezbędne do jej oceny. Nieobecność nie powoduje sama przez się dodatkowej opłaty ani uznania zajęć za wykonane. Zasady odrobienia nieobecności, liczby dopuszczalnych zmian terminu i rozliczenia zajęć nieodbytych z przyczyn leżących po stronie {K_gen} określa się następująco: {nieobecnosc_zasady}. Ewentualna opłata musi być określona w Umowie kwotowo albo w sposób pozwalający ją obliczyć przed zawarciem Umowy oraz być zgodna z warunkami dofinansowania. Odwołanie pojedynczych zajęć nie oznacza wypowiedzenia całej Umowy i nie ogranicza ustawowych praw Stron.',
                'Jeżeli Umowa została zawarta na odległość lub poza lokalem przedsiębiorstwa, {K_dat} [będącej konsumentką|będącemu konsumentem] przysługuje ustawowe prawo odstąpienia na warunkach i w terminie wynikających z ustawy o prawach konsumenta, co do zasady w terminie 14 dni; w przypadkach przewidzianych ustawą termin może być dłuższy. Umowa nie wyłącza ani nie ogranicza ustawowych praw [konsumentki|konsumenta].',
                'Rozpoczęcie świadczenia przed upływem terminu do odstąpienia następuje wyłącznie na wyraźne żądanie {K_gen} złożone na trwałym nośniku, odrębnie od samego zawarcia Umowy. Wzór takiego żądania i potwierdzenia zapoznania się ze skutkami stanowi Załącznik nr 3; oświadczenie jest dobrowolne i nie może być z góry zaznaczone ani uznane za złożone przez sam podpis pod Umową. Utrata prawa odstąpienia po pełnym wykonaniu usługi może nastąpić wyłącznie po spełnieniu ustawowych warunków, w tym uprzednim poinformowaniu {K_gen}, uzyskaniu [jej|jego] wyraźnej zgody oraz potwierdzenia przyjęcia do wiadomości skutków. W razie odstąpienia po zgłoszeniu żądania wcześniejszego rozpoczęcia ewentualne rozliczenie za część wykonaną następuje wyłącznie na warunkach i w wysokości przewidzianych prawem.',
                'Jeżeli Umowa jest zawierana z [konsumentką|konsumentem] na odległość lub poza lokalem przedsiębiorstwa, Fundacja przed związaniem {K_gen} Umową przekazuje [jej|mu] na trwałym nośniku wymagane prawem informacje, w tym dotyczące głównych cech Szkolenia, łącznej ceny i sposobu jej obliczenia, płatności, terminu i sposobu realizacji, reklamacji, prawa odstąpienia oraz wzoru formularza odstąpienia. Fundacja przekaże potwierdzenie zawarcia Umowy wraz z jej załącznikami na trwałym nośniku w terminie przewidzianym prawem, najpóźniej przed rozpoczęciem świadczenia usługi. Reklamacje dotyczące realizacji Umowy {K} może składać na adres e-mail Fundacji lub listownie na jej adres wskazane w Umowie; szczegółowy sposób i termin ich rozpatrywania Fundacja poda {K_dat} przed zawarciem Umowy: {reklamacje}. Postanowienie nie ogranicza ustawowych praw {K_gen}.',
            ]],
            ['Ukończenie Szkolenia i dokument potwierdzający', [
                'Warunki ukończenia Szkolenia określa Załącznik nr 1, w tym wymagany poziom obecności oraz ewentualny test, zadanie lub inna forma sprawdzenia wiedzy: {warunki_ukonczenia}. Jeżeli określony warunek nie jest wymagany, wskazuje się to wyraźnie.',
                'Po spełnieniu warunków ukończenia Fundacja wyda {K_dat} {dokument} w terminie {dokument_termin}. Dokument nie stanowi świadectwa ani kwalifikacji zawodowej uznawanej na podstawie przepisów, chyba że wyraźnie wskazano właściwą podstawę prawną i spełniono jej warunki.',
            ]],
            ['Ochrona danych osobowych', [
                'Fundacja przekazuje {K_dat} informacje wymagane przez art. 13 rozporządzenia (UE) 2016/679 (RODO) w odrębnej klauzuli informacyjnej stanowiącej Załącznik nr 2 albo doręczonej na trwałym nośniku najpóźniej przy pozyskiwaniu danych. Klauzula zawiera w szczególności cele i podstawy przetwarzania, odbiorców danych, okres przechowywania lub kryteria jego ustalenia, prawa osoby, której dane dotyczą, oraz informację o skardze do organu nadzorczego.',
                'Dane związane z udziałem w Szkoleniu i rozliczeniem dofinansowania mogą być przekazywane podmiotom uprawnionym lub zaangażowanym w realizację i rozliczenie dofinansowania w zakresie wynikającym z właściwych dokumentów i przepisów. Szczegóły wskazuje klauzula informacyjna; Umowa sama nie przesądza o nieznanych odbiorcach ani okresach przechowywania.',
                'Jeżeli w odrębnym wariancie Umowy podmiot instytucjonalny będzie Zamawiającym i będzie przekazywać dane swoich pracowników lub innych uczestników, Strony określą role w przetwarzaniu danych i zawrą umowę powierzenia, jeżeli będzie ona wymagana ze względu na rzeczywisty sposób przetwarzania.',
            ]],
            ['Postanowienia końcowe', [
                'W sprawach nieuregulowanych Umową zastosowanie ma prawo polskie. Spory rozpoznaje sąd właściwy według przepisów powszechnie obowiązujących; Umowa nie ustanawia właściwości wyłącznej sądu na niekorzyść {K_gen} [będącej konsumentką|będącego konsumentem].',
                'Zmiany Umowy wymagają uzgodnienia przez Strony co najmniej w formie dokumentowej, chyba że przepisy prawa wymagają formy szczególnej. Harmonogram i szczegóły organizacyjne mogą być uzgadniane w tej samej formie.',
                'Regulamin rekrutacji i uczestnictwa w Szkoleniach FEER obowiązujący od {regulamin_od} został udostępniony {K_dat} przed zawarciem Umowy i stosuje się go uzupełniająco wyłącznie w zakresie zasad organizacyjnych dotyczących zmiany harmonogramu, odwołania lub przełożenia zajęć, niezwłocznego informowania o niemożności ich przeprowadzenia oraz zgłaszania i usprawiedliwiania nieobecności — z uwzględnieniem szczegółowych zasad określonych w § 5 Umowy. W razie rozbieżności pierwszeństwo mają postanowienia Umowy, w szczególności dotyczące odwołania zajęć grupowych i indywidualnych, zwrotu opłaty oraz braku automatycznej dodatkowej opłaty za nieobecność. Pozostałe postanowienia Regulaminu, w tym dotyczące opłat i cennika, limitu bezpłatnych godzin, minimalnej frekwencji, wykluczenia z udziału oraz wykorzystania wizerunku, nie mają zastosowania do Umowy, chyba że Strony wyraźnie uzgodnią je odrębnie na piśmie. Jeżeli do rozliczenia szkolenia wymagane są dodatkowe dokumenty dofinansowania, stosuje się dokumenty właściwe dla przyznanego dofinansowania po ich udostępnieniu {K_dat} przed zawarciem Umowy.',
                'Dane kontaktowe Stron do spraw związanych z realizacją Umowy obejmują adresy e-mail i numery telefonów wskazane przy oznaczeniu Stron. Strony mogą korzystać z tych danych do bieżącej komunikacji, uzgadniania harmonogramu i przekazywania informacji organizacyjnych oraz — w zakresie dopuszczalnym prawem — do składania oświadczeń w formie dokumentowej. Każda ze Stron niezwłocznie poinformuje drugą Stronę w formie dokumentowej o zmianie swoich danych kontaktowych. Postanowienie nie zastępuje formy szczególnej wymaganej przepisami dla danego oświadczenia.',
                'Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron, albo zawarto w postaci elektronicznej. Każda ze Stron otrzymuje egzemplarz Umowy wraz z uzgodnionymi załącznikami.',
            ]],
        ];
    }

    /**
     * Załączniki: [[nagłówek, podtytuł, [blok, …]], …]. Blok: ['p' => tekst] | ['n' => [punkty numerowane]] |
     * ['box' => tekst] (pole wyboru ☐) | ['h' => śródtytuł].
     */
    public static function annexes(): array
    {
        return [
            ['Załącznik nr 1', 'Program i harmonogram Szkolenia', [
                ['p' => 'Nazwa Szkolenia: „{temat}”.'],
                ['p' => 'Cel kształcenia: {cel}'],
                ['p' => 'Zakres tematyczny i kolejność modułów: {zakres}'],
                ['p' => 'Forma: zajęcia online synchroniczne na żywo; elementy asynchroniczne, jeżeli występują: {asynchr}'],
                ['p' => 'Terminy i godziny: {terminy}'],
                ['p' => 'Wymiar: {wymiar}'],
                ['p' => 'Platforma i sposób dostępu: Zoom albo WhatsApp, według wyboru uzgodnionego przez Strony dla danego spotkania. Link lub inne dane dołączenia zostaną udostępnione po zalogowaniu na indywidualne konto {K_gen} w panelu {panel}; {platforma_uwagi}'],
                ['p' => 'Trener/Trenerka: {prowadzacy}'],
                ['p' => 'Liczba uczestników, jeżeli szkolenie jest grupowe: {liczba_uczestnikow}'],
                ['p' => 'Materiały zapewniane przez Fundację: {materialy}'],
                ['p' => 'Warunki ukończenia i zaliczenia: {warunki_ukonczenia}'],
                ['p' => 'Dokument wydawany po szkoleniu: {dokument}'],
                ['p' => 'Załącznik stanowi integralną część Umowy i podlega uzgodnieniu przez obie Strony.'],
            ]],
            ['Załącznik nr 2', "KLAUZULA INFORMACYJNA\nUmowa o przeprowadzenie szkolenia", [
                ['p' => 'Zgodnie z art. 13 rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679 z dnia 27 kwietnia 2016 r. w sprawie ochrony osób fizycznych w związku z przetwarzaniem danych osobowych i w sprawie swobodnego przepływu takich danych oraz uchylenia dyrektywy 95/46/WE (ogólne rozporządzenie o ochronie danych), dalej „RODO”, informujemy, że:'],
                ['n' => [
                    'Administratorem danych osobowych {K_gen} jest Fundacja Edukacji Empatii Rozwoju „FEER” z siedzibą w Krakowie przy ul. Zamkniętej 10/1.5, 30-554 Kraków, wpisana do rejestru stowarzyszeń Krajowego Rejestru Sądowego pod numerem 0000779281, NIP 7343570539. Z Administratorem można skontaktować się listownie pod wskazanym adresem, telefonicznie pod numerem {fund_tel} lub pocztą elektroniczną: {fund_email}.',
                    'Dane osobowe będą przetwarzane w celu obsługi zgłoszenia i kontaktu z {K_ins}, zawarcia i wykonania Umowy, organizacji Szkolenia, prowadzenia indywidualnego konta w Panelu Kursanta, udostępnienia harmonogramu i materiałów oraz dokumentowania udziału — w zakresie niezbędnym do podjęcia działań przed zawarciem Umowy lub jej wykonania, na podstawie art. 6 ust. 1 lit. b RODO.',
                    'Dane będą również przetwarzane w celu wykonania obowiązków prawnych związanych z dokumentacją księgową i podatkową — na podstawie art. 6 ust. 1 lit. c RODO, a w celu ustalenia, dochodzenia lub obrony roszczeń — na podstawie art. 6 ust. 1 lit. f RODO, w ramach prawnie uzasadnionego interesu Administratora polegającego na ochronie jego praw.',
                    'W zakresie dokumentowania lub rozliczania dofinansowania PFRON dane będą przetwarzane w celu i na podstawie wynikających z właściwych przepisów oraz dokumentów finansowania: {rodo_pfron}.',
                    'Jeżeli Fundacja przetwarza dane dotyczące niepełnosprawności lub zdrowia {K_gen}, cel, zakres tych danych i odrębna przesłanka dopuszczalności ich przetwarzania z art. 9 ust. 2 RODO: {rodo_art9}. Niniejsza klauzula informacyjna sama w sobie nie stanowi zgody na przetwarzanie takich danych.',
                    'Dane mogą być udostępniane podmiotom uprawnionym na podstawie przepisów prawa, instytucjom finansującym lub kontrolującym dofinansowanie — w zakresie właściwych przepisów i dokumentów finansowania — oraz podmiotom świadczącym na rzecz Fundacji usługi księgowe, informatyczne, hostingowe, poczty elektronicznej i SMS, obsługi Panelu Kursanta oraz platformy Zoom lub WhatsApp, w zakresie niezbędnym do realizacji wskazanych celów. Rzeczywiste kategorie odbiorców: {rodo_odbiorcy}.',
                    'Dane nie będą przekazywane do państwa trzeciego ani organizacji międzynarodowej, chyba że korzystanie z dostawcy Panelu Kursanta, poczty, SMS lub platformy zajęć wiąże się z takim przekazaniem. W razie transferu — państwo docelowe, podstawa transferu i właściwe zabezpieczenia: {rodo_transfer}.',
                    'Dane będą przechowywane przez okres niezbędny do obsługi zgłoszenia i wykonania Umowy, a następnie przez okres wymagany przepisami dotyczącymi dokumentacji księgowej i dofinansowania oraz przez okres przedawnienia roszczeń — zależnie od rodzaju danych i celu ich przetwarzania. Szczegółowe okresy lub kryteria ich ustalenia: {rodo_okresy}.',
                    '{K_dat} przysługuje, na warunkach określonych w RODO, prawo dostępu do danych, ich sprostowania, usunięcia lub ograniczenia przetwarzania, prawo do przenoszenia danych w przypadkach przewidzianych prawem oraz prawo wniesienia sprzeciwu wobec przetwarzania opartego na prawnie uzasadnionym interesie. Jeżeli przetwarzanie odbywa się na podstawie zgody, {K} może ją wycofać w dowolnym momencie; nie wpływa to na zgodność z prawem przetwarzania dokonanego przed jej wycofaniem. W celu skorzystania z praw można skontaktować się z Administratorem na wskazane wyżej dane kontaktowe.',
                    '{K} ma prawo wnieść skargę do Prezesa Urzędu Ochrony Danych Osobowych, jeżeli uzna, że przetwarzanie [jej|jego] danych osobowych narusza przepisy RODO.',
                    'Podanie danych niezbędnych do zawarcia i wykonania Umowy jest dobrowolne, jednak ich niepodanie może uniemożliwić zawarcie lub wykonanie Umowy. Podanie danych wymaganych do rozliczenia dofinansowania może być konieczne, jeżeli wynika to z właściwych przepisów lub dokumentów finansowania: {rodo_wymagane}.',
                    'Wobec danych {K_gen} nie będą podejmowane decyzje w sposób zautomatyzowany, w tym nie będą one podlegały profilowaniu.',
                ]],
            ]],
            ['Załącznik nr 3', 'WZÓR OŚWIADCZENIA O ODSTĄPIENIU I ŻĄDANIA WCZEŚNIEJSZEGO ROZPOCZĘCIA USŁUGI', [
                ['h' => 'A. Oświadczenie o odstąpieniu od Umowy'],
                ['p' => 'Adresat: Fundacja Edukacji Empatii Rozwoju „FEER”, ul. Zamknięta 10/1.5, 30-554 Kraków, e-mail: {fund_email}.'],
                ['p' => 'Niniejszym informuję o odstąpieniu od umowy o świadczenie usług szkoleniowych nr {nr} zawartej dnia {data_pl}, dotyczącej szkolenia „{temat}”.'],
                ['p' => 'Imię i nazwisko {K_gen}: {imie_nazwisko}.'],
                ['p' => 'Adres {K_gen}: {adres}.'],
                ['p' => 'Data złożenia oświadczenia: ________________.'],
                ['p' => 'Podpis {K_gen} (wyłącznie w przypadku oświadczenia składanego w postaci papierowej): ________________.'],
                ['h' => 'B. Dobrowolne żądanie wcześniejszego rozpoczęcia usługi'],
                ['p' => 'Wypełniać wyłącznie wtedy, gdy {K} chce, aby Fundacja rozpoczęła świadczenie Szkolenia przed upływem terminu do odstąpienia od Umowy. Niewypełnienie tej części nie wpływa na zawarcie Umowy ani na ustawowe prawo odstąpienia.'],
                ['box' => 'Żądam rozpoczęcia świadczenia usługi szkoleniowej przed upływem terminu do odstąpienia od Umowy.'],
                ['box' => 'Przyjmuję do wiadomości, że jeżeli usługa zostanie w pełni wykonana za moją uprzednią, wyraźną zgodą i po poinformowaniu mnie o skutkach, mogę utracić prawo odstąpienia od Umowy na warunkach określonych w ustawie.'],
                ['p' => 'Przyjmuję do wiadomości, że jeżeli odstąpię od Umowy po zgłoszeniu żądania wcześniejszego rozpoczęcia, mogę być [zobowiązana|zobowiązany] do zapłaty za część usługi wykonaną do chwili odstąpienia wyłącznie w zakresie i na warunkach przewidzianych prawem.'],
                ['p' => 'Data: ________________.'],
                ['p' => 'Podpis {K_gen}: ________________.'],
            ]],
        ];
    }

    /** Buduje DOCX i zwraca jego zawartość (bajty). $d — dane umowy z polami 'nr' (numer TI/UMSZ, puste = XXX). */
    public static function docx(array $d): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $er = error_reporting(error_reporting() & ~E_DEPRECATED);   // PHPWord sypie E_DEPRECATED na nowszym PHP — nie może trafić przed nagłówki
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);  // „&”, „<” w danych nie mogą zepsuć XML-a
        $r = fn(string $t) => self::render($t, $d);

        $w = new \PhpOffice\PhpWord\PhpWord();
        $w->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
        $w->setDefaultFontName('Arial');
        $w->setDefaultFontSize(10);
        $J = \PhpOffice\PhpWord\SimpleType\Jc::class;
        $just = ['alignment' => $J::BOTH, 'spaceAfter' => 100, 'lineHeight' => 1.2];
        $num  = $just + ['indentation' => ['left' => 400, 'hanging' => 400]];
        $ctr  = ['alignment' => $J::CENTER, 'spaceAfter' => 80, 'keepNext' => true];
        $sec  = ['marginLeft' => 1250, 'marginRight' => 1250, 'marginTop' => 1100, 'marginBottom' => 1100];
        $s = $w->addSection($sec);
        $s->addFooter()->addPreserveText('{PAGE} / {NUMPAGES}', ['size' => 8], ['alignment' => $J::CENTER]);

        $s->addText('UMOWA O ŚWIADCZENIE USŁUG SZKOLENIOWYCH', ['bold' => true, 'size' => 12], $ctr);
        $s->addText($r('Nr {nr}'), ['bold' => true], $ctr);
        $s->addTextBreak(1);
        foreach (self::preamble() as $p) $s->addText($r($p), [], $just);

        foreach (self::paragraphs($d) as $i => [$title, $items]) {
            $s->addTextBreak(1);
            $s->addText('§ ' . ($i + 1), ['bold' => true], $ctr);
            $s->addText($r($title), ['bold' => true], $ctr);
            foreach ($items as $n => $t) $s->addText(($n + 1) . ".\t" . $r($t), [], $num + ['tabs' => [new \PhpOffice\PhpWord\Style\Tab('left', 400)]]);
        }

        $s->addTextBreak(3);
        $t = $s->addTable(['alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER]);
        $t->addRow();
        foreach ([['Fundacja', ''], [$r('{K}'), (string)($d['imie_nazwisko'] ?? '')]] as [$who, $name]) {
            $c = $t->addCell(4500);
            $c->addText('____________________________', [], $ctr);
            $c->addText($who, ['size' => 9], $ctr);
            if ($name !== '') $c->addText($name, ['size' => 9], $ctr);
        }

        foreach (self::annexes() as [$head, $sub, $blocks]) {
            $s->addPageBreak();
            $s->addText($head, ['bold' => true], ['alignment' => $J::RIGHT, 'spaceAfter' => 120]);
            foreach (explode("\n", $sub) as $line) $s->addText($line, ['bold' => true], $ctr);
            $s->addText($r('do umowy nr {nr} z dnia {data_pl}'), ['size' => 9, 'italic' => true], $ctr);
            $s->addTextBreak(1);
            foreach ($blocks as $b) {
                if (isset($b['h'])) $s->addText($b['h'], ['bold' => true], $just + ['keepNext' => true]);
                elseif (isset($b['box'])) $s->addText("☐\t" . $r($b['box']), [], $num + ['tabs' => [new \PhpOffice\PhpWord\Style\Tab('left', 400)]]);
                elseif (isset($b['n'])) foreach ($b['n'] as $n => $tx) $s->addText(($n + 1) . ".\t" . $r($tx), [], $num + ['tabs' => [new \PhpOffice\PhpWord\Style\Tab('left', 400)]]);
                else $s->addText($r($b['p']), [], $just);
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'umsz');
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($tmp);
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        error_reporting($er);
        return $bytes;
    }

    /** Nazwa pliku do pobrania. */
    public static function filename(array $d): string
    {
        $slug = fn(string $s) => trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: '')), '_');
        $nr = ($d['nr'] ?? '') !== '' ? $slug($d['nr']) : 'projekt';
        $who = $slug((string)($d['imie_nazwisko'] ?? ''));
        return 'umowa_szkoleniowa_' . $nr . ($who !== '' ? '_' . $who : '') . '.docx';
    }
}
