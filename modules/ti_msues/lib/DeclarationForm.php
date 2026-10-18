<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/DeclarationForm.php — treść i pola „Oświadczenia o kwalifikacjach i kompetencjach do prowadzenia
 * szkoleń lub konsultacji” (wzór Fundacji, wersja zmieniona 2026-10-18; symbol druku OKK-1).
 *
 * Jedno źródło treści dla formularza, PDF (układ formularza urzędowego jak CIT-8) i DOCX. Punkty 1–8 dosłownie wg wzoru;
 * nazwa podmiotu z ustawień organizacji (org_name, dopełniacz — org_name_genitive). Pola z podpowiedziami wzoru.
 * Część „Oświadczenia dodatkowe” (licencja na materiały, klauzula RODO) jest opcjonalna — drukowana tylko, gdy zaznaczona.
 */
require_once __DIR__ . '/MsuesSzoData.php';

final class DeclarationForm
{
    public const SYMBOL  = 'OKK-1';
    public const VERSION = '2';

    public const PODSTAWY = [
        'wolontariat' => 'wolontariuszka/wolontariusz',
        'zlecenie'    => 'zleceniobiorczyni/zleceniobiorca',
        'inna'        => 'inna podstawa współpracy',
    ];

    /** pole => [etykieta w formularzu, podpowiedź wzoru, typ (text|textarea|date|select|bool), maks. długość] */
    public const FIELDS = [
        'miejscowosc'          => ['Miejscowość', 'miejscowość', 'text', 80],
        'data'                 => ['Data', 'data', 'date', 10],
        'imie_nazwisko'        => ['Imię i nazwisko', 'imię i nazwisko', 'text', 160],
        'dane_identyfikacyjne' => ['Adres lub dane identyfikacyjne', 'adres lub dane identyfikacyjne, jeśli wymagane', 'text', 255],
        'podstawa'             => ['Prowadzę działania jako', '', 'select', 16],
        'podstawa_inna'        => ['Inna podstawa współpracy', 'podstawa współpracy', 'text', 160],
        'podmiot'              => ['Na rzecz', 'nazwa podmiotu', 'text', 255],
        'zakres'               => ['Pkt 2 — zakres powierzonych działań', 'tematyka i zakres szkoleń lub konsultacji', 'textarea', 3000],
        'wyksztalcenie'        => ['Pkt 3 — wykształcenie', 'wykształcenie (kierunek, uczelnia/szkoła, rok)', 'textarea', 2000],
        'przygotowanie'        => ['Pkt 3 — kursy, szkolenia, przygotowanie pedagogiczne i merytoryczne', 'kursy, szkolenia, przygotowanie pedagogiczne/merytoryczne', 'textarea', 3000],
        'doswiadczenie'        => ['Pkt 3 — doświadczenie zgodne z zakresem usługi', 'doświadczenie (okres, miejsce, liczba godzin/uczestników)', 'textarea', 3000],
        'uprawnienia'          => ['Pkt 4 — wymagane uprawnienia', 'nazwa uprawnienia lub wpisz: nie dotyczy', 'textarea', 1000],
        'dok_zalaczone'        => ['Pkt 8 — dokumenty przedłożone wraz z oświadczeniem', 'wykaz załączonych dokumentów lub wpisz: brak', 'textarea', 2000],
        'dok_na_zadanie'       => ['Pkt 8 — dokumenty, które zostaną przedłożone na żądanie', 'wykaz dokumentów lub wpisz: nie dotyczy', 'textarea', 2000],
        'zgoda_materialy'      => ['Udzielam Fundacji niewyłącznej licencji na korzystanie z przygotowanych przeze mnie materiałów szkoleniowych w zakresie realizowanych usług i oświadczam, że nie naruszają one praw osób trzecich', '', 'bool', 1],
        'zgoda_rodo'           => ['Zapoznałam/zapoznałem się z klauzulą informacyjną o przetwarzaniu danych osobowych i przyjmuję do wiadomości, że niniejsze oświadczenie i dokumenty mogą zostać udostępnione do audytu prowadzonego przez Wojewódzki Urząd Pracy w Krakowie (Centrum Zapewniania Jakości Kształcenia)', '', 'bool', 1],
    ];

    public static function defaults(): array
    {
        $d = [];
        foreach (self::FIELDS as $k => [, , $type]) $d[$k] = $type === 'bool' ? 0 : '';
        $d['podstawa'] = 'zlecenie';
        $d['data'] = date('Y-m-d');
        $d['miejscowosc'] = MsuesSzoData::orgCity();
        $d['podmiot'] = MsuesSzoData::orgName();
        return $d;
    }

    /** @return array{0: array, 1: list<string>} [dane, błędy] */
    public static function normalize(array $in): array
    {
        $d = self::defaults();
        $err = [];
        foreach (self::FIELDS as $k => [$label, , $type, $max]) {
            if ($type === 'bool') { $d[$k] = !empty($in[$k]) ? 1 : 0; continue; }
            $v = trim(str_replace("\r\n", "\n", (string)($in[$k] ?? '')));
            $d[$k] = mb_substr($v, 0, $max);
        }
        if (!isset(self::PODSTAWY[$d['podstawa']])) $d['podstawa'] = 'inna';
        if ($d['podstawa'] !== 'inna') $d['podstawa_inna'] = '';
        if ($d['data'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['data'])) { $err[] = 'Nieprawidłowa data.'; $d['data'] = ''; }
        return [$d, $err];
    }

    /** Pola wymagane do wysłania do weryfikacji. */
    public static function missingForSubmit(array $d): array
    {
        $m = [];
        foreach (['miejscowosc', 'data', 'imie_nazwisko', 'zakres', 'wyksztalcenie', 'doswiadczenie', 'uprawnienia', 'dok_zalaczone', 'dok_na_zadanie'] as $k) {
            if (trim((string)($d[$k] ?? '')) === '') $m[] = self::FIELDS[$k][0];
        }
        if (($d['podstawa'] ?? '') === 'inna' && trim((string)($d['podstawa_inna'] ?? '')) === '') $m[] = self::FIELDS['podstawa_inna'][0];
        return $m;
    }

    /** Wstępne wypełnienie z SZO: konto, umowa z Rejestru, usługa (grupa TI), kwalifikacje planisty, dokumenty MSUES. */
    public static function prefill(int $trainerId, ?int $courseId, array $documents = []): array
    {
        $d = self::defaults();
        $u = MsuesSzoData::user($trainerId);
        $d['imie_nazwisko'] = (string)($u['name'] ?? '');
        $contracts = MsuesSzoData::contracts($trainerId);
        $c = $contracts[0] ?? null;
        if ($c) {
            $d['podstawa'] = $c['podstawa'];
            if ($c['podstawa'] === 'inna') $d['podstawa_inna'] = $c['label'] . ($c['numer_umowy'] !== '' ? ' nr ' . $c['numer_umowy'] : '');
            $d['dane_identyfikacyjne'] = $c['adres'];
            $upr = array_filter([$c['uprawnienia'] !== '' ? 'uprawnienia nr ' . $c['uprawnienia'] : '', $c['dyplom'] !== '' ? 'dyplom nr ' . $c['dyplom'] : '']);
            if ($upr) $d['uprawnienia'] = implode('; ', $upr);
        }
        $course = $courseId ? MsuesSzoData::course($courseId) : null;
        if ($course) {
            $d['zakres'] = trim('Prowadzenie zajęć w ramach usługi „' . MsuesSzoData::courseLabel($course) . '”'
                . (trim((string)($course['subject_name'] ?? '')) !== '' ? ' (' . $course['subject_name'] . ')' : '') . '.'
                . (trim((string)($course['description'] ?? '')) !== '' ? "\n" . mb_substr(strip_tags((string)$course['description']), 0, 1200) : ''));
        } elseif ($c && $c['przedmiot'] !== '') {
            $d['zakres'] = $c['przedmiot'];
        }
        $q = MsuesSzoData::qualifications($trainerId);
        if ($q) $d['przygotowanie'] = 'Kwalifikacje potwierdzone w ewidencji kadry: ' . implode('; ', $q) . '.';
        $kinds = MsuesChecklist::documentKinds();
        $list = array_map(fn($x) => ($kinds[$x['kind']] ?? $x['kind']) . ($x['title'] !== '' ? ': ' . $x['title'] : ''), $documents);
        $d['dok_zalaczone'] = $list ? implode("\n", $list) : 'brak';
        $d['dok_na_zadanie'] = 'nie dotyczy';
        return $d;
    }

    /**
     * Treść wzoru: lista punktów. Każdy punkt to tekst albo [tekst, pola] — pola wpisywane po tekście (pkt 2, 3, 4, 8).
     * @return list<array{0:string,1:list<string>}>
     */
    public static function points(): array
    {
        $gen = MsuesSzoData::orgNameGenitive();
        return [
            ['Posiadam wiedzę, przygotowanie, umiejętności i doświadczenie odpowiednie do prowadzenia wskazanych niżej szkoleń lub konsultacji, w zakresie opisanym w niniejszym oświadczeniu.', []],
            ['Zakres powierzonych mi działań obejmuje:', ['zakres']],
            ['Moje kwalifikacje, przygotowanie i doświadczenie związane z tym zakresem przedstawiają się następująco:', ['wyksztalcenie', 'przygotowanie', 'doswiadczenie']],
            ['Posiadam wymagane przepisami prawa uprawnienia, jeżeli są one wymagane do prowadzenia wskazanych przeze mnie szkoleń lub konsultacji. W szczególności:', ['uprawnienia']],
            ['Będę prowadzić działania wyłącznie w granicach posiadanych kwalifikacji i kompetencji, rzetelnie oraz z uwzględnieniem charakteru i potrzeb uczestników. W przypadku zagadnienia wykraczającego poza mój zakres kompetencji poinformuję o tym podmiot zlecający lub uczestnika i nie będę przedstawiać się jako osoba posiadająca kwalifikacje, których nie mam.', []],
            ['Informacje podane w niniejszym oświadczeniu są zgodne z prawdą. Zobowiązuję się niezwłocznie poinformować podmiot zlecający o zmianie okoliczności mających znaczenie dla posiadanych przeze mnie uprawnień lub możliwości prowadzenia wskazanych działań.', []],
            ['W związku z działaniami ' . $gen . ' zmierzającymi do uzyskania znaku jakości MSUES, opartego na Małopolskich Standardach Usług Edukacyjno-Szkoleniowych (MSUES), zobowiązuję się — w zakresie powierzonych mi zadań i po przekazaniu właściwych wymagań — stosować dotyczące tych zadań zasady jakości oraz współpracować przy ocenie i doskonaleniu usług. Na uzasadnione żądanie Fundacji przekażę, w zakresie niezbędnym i z zachowaniem zasad poufności oraz ochrony danych, informacje i dokumenty dotyczące moich kwalifikacji, doświadczenia i realizowanych usług, potrzebne do przygotowania do audytu lub innych działań związanych z ubieganiem się o znak jakości MSUES. Rozumiem, że znak jakości MSUES przyznaje Województwo Małopolskie reprezentowane przez Wojewódzki Urząd Pracy w Krakowie w ramach Centrum Zapewniania Jakości Kształcenia, a niniejsze oświadczenie nie potwierdza uzyskania tego znaku przez Fundację. W związku z planowanym ubieganiem się przez Fundację o wpis do Bazy Usług Rozwojowych (BUR), zobowiązuję się współpracować w zakresie dokumentowania kwalifikacji i usług, o ile informacje te są wymagane i dotyczą powierzonych mi zadań; samo uzyskanie znaku MSUES nie jest w niniejszym oświadczeniu przedstawiane jako równoznaczne z dokonaniem wpisu do BUR.', []],
            ['Na potwierdzenie podanych informacji przedkładam dokumenty wymienione poniżej albo zobowiązuję się przedłożyć je podmiotowi zlecającemu na jego uzasadnione żądanie, o ile nimi dysponuję i ich przedłożenie jest dopuszczalne:', ['dok_zalaczone', 'dok_na_zadanie']],
        ];
    }

    public const SUBLABELS = [
        'zakres' => 'Tematyka i zakres szkoleń lub konsultacji', 'uprawnienia' => 'Uprawnienia wymagane przepisami prawa',
        'wyksztalcenie' => 'Wykształcenie', 'przygotowanie' => 'Kursy, szkolenia, przygotowanie pedagogiczne i merytoryczne',
        'doswiadczenie' => 'Doświadczenie', 'dok_zalaczone' => 'Dokumenty przedłożone wraz z oświadczeniem',
        'dok_na_zadanie' => 'Dokumenty, które zostaną przedłożone na żądanie',
    ];

    public const CLOSING = 'Oświadczam, że mam świadomość, iż niniejsze oświadczenie nie zastępuje dokumentu potwierdzającego formalne uprawnienie, jeżeli przepisy wymagają jego uzyskania lub okazania.';

    public static function title(): string
    {
        return 'OŚWIADCZENIE O KWALIFIKACJACH I KOMPETENCJACH DO PROWADZENIA SZKOLEŃ LUB KONSULTACJI';
    }

    public static function filename(array $decl, string $ext): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($decl['data']['imie_nazwisko'] ?? 'prowadzacy')) ?: 'prowadzacy');
        return 'Oswiadczenie_MSUES_' . trim($name, '_') . '_v' . (int)$decl['version'] . '.' . $ext;
    }

    public static function dateLong(string $ymd): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return $ymd;
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
}
