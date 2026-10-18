<?php
/**
 * modules/application_documents/logic/applicationDocuments.php — wzory wniosków organizacji
 * składanych elektronicznie w Panelu współpracownika (panel/apply.php → user_applications)
 * oraz ich dokument do wydruku / PDF.
 *
 * Rejestr appDocsTemplates(): kod wzoru → typ wniosku (application_types.name), pola formularza
 * i treść dokumentu. appDocsEnsureTypes() dopisuje brakujące typy i pola (idempotentnie, wołane
 * z _applications_init()); istniejących typów nie nadpisuje — administrator może je zmieniać
 * w admin/application_types.php.
 */

/** Wspólne pola nagłówka wniosku (miejscowość, adresat, wnioskodawca). */
function appDocsHeaderFields(): array {
    return [
        ['miejscowosc',  'Miejscowość',                                  'text',     [], 'np. Kraków',                                   1],
        ['organ',        'Do: nazwa i adres organu lub instytucji',      'textarea', [], "Nazwa\nUlica i numer\nKod pocztowy, miejscowość", 1],
        ['wnioskodawca', 'Wnioskodawca: imię i nazwisko lub nazwa',      'text',     [], 'Imię i nazwisko lub nazwa wnioskodawcy',       1],
        ['adres',        'Adres zamieszkania lub siedziby',             'text',     [], 'Ulica, numer, kod pocztowy, miejscowość',       1],
        ['kontakt',      'Dane kontaktowe',                              'text',     [], 'Telefon lub e-mail',                           1],
    ];
}

/** Wspólne pola stopki wniosku (forma, sposób przekazania, kontakt, załączniki). */
function appDocsFooterFields(string $what): array {
    return [
        ['forma',        'Forma wydania ' . $what,                       'select',   ['papierowa', 'elektroniczna'], '',                 1],
        ['sposob',       'Sposób przekazania',                           'select',   ['odbiór osobisty', 'doręczenie pocztą', 'doręczenie elektroniczne'], '', 1],
        ['kontakt_pref', 'Preferowany sposób kontaktu w sprawie wniosku', 'select',  ['e-mail', 'telefon', 'Panel współpracownika (SZO)', 'poczta tradycyjna'], '', 1],
        ['zalaczniki',   'Załączniki',                                   'textarea', [], 'Wymagany załącznik — każdy w osobnym wierszu. Puste = brak.', 0],
    ];
}

/**
 * Rejestr wzorów. Klucz = application_types.name.
 * fields: [name, label, type, options, placeholder, required]; doc: elementy treści dokumentu.
 */
function appDocsTemplates(): array {
    return [
        'wniosek_zsw1' => [
            'code'        => 'ZSW-1',
            'label'       => 'ZSW-1 · Wniosek o wydanie dokumentu lub zaświadczenia',
            'icon'        => 'bi-file-earmark-text',
            'description' => 'Wniosek o wydanie dokumentu lub zaświadczenia (wzór ZSW-1), składany elektronicznie.',
            'sort'        => 15,
            'title'       => 'WNIOSEK O WYDANIE DOKUMENTU LUB ZAŚWIADCZENIA',
            'fields'      => array_merge(appDocsHeaderFields(), [
                ['dokument', 'Dokładna nazwa dokumentu lub zaświadczenia',        'text', [], 'np. zaświadczenie o odbyciu wolontariatu', 1],
                ['dotyczy',  'Kogo lub czego dotyczy',                            'text', [], 'Osoba, sprawa lub okres, którego dotyczy dokument', 1],
                ['cel',      'Cel uzyskania dokumentu',                           'text', [], 'Jeśli wymagany — np. do urzędu pracy', 0],
            ], appDocsFooterFields('dokumentu')),
            'request'     => 'Zwracam się z wnioskiem o wydanie {dokument} dotyczącego: {dotyczy}.',
            'purpose'     => 'Dokument jest mi potrzebny w celu: {cel}.',
            'asks'        => ['wydanie dokumentu w formie {forma_txt};', 'przekazanie go w sposób: {sposob}.'],
            'fee'         => 'Jeżeli wydanie dokumentu wiąże się z opłatą, proszę o wskazanie jej wysokości oraz sposobu uiszczenia, o ile informacja ta nie jest dostępna w formularzu lub na stronie organu.',
        ],
        'wniosek_zsw2' => [
            'code'        => 'ZSW-2',
            'label'       => 'ZSW-2 · Wniosek o wydanie duplikatu dokumentacji',
            'icon'        => 'bi-files',
            'description' => 'Wniosek o wydanie duplikatu dokumentacji (wzór ZSW-2), składany elektronicznie.',
            'sort'        => 16,
            'title'       => 'WNIOSEK O WYDANIE DUPLIKATU DOKUMENTACJI',
            'fields'      => array_merge(appDocsHeaderFields(), [
                ['dokument', 'Rodzaj i nazwa dokumentacji',                       'text', [], 'np. umowa wolontariacka i karta czasu pracy', 1],
                ['dotyczy',  'Kogo lub czego dotyczy',                            'text', [], 'Osoba, sprawa lub okres, którego dotyczy dokumentacja', 1],
                ['cel',      'Cel uzyskania dokumentacji',                        'text', [], 'Jeśli wymagany', 0],
            ], appDocsFooterFields('duplikatu')),
            'request'     => 'Zwracam się z wnioskiem o wydanie duplikatu dokumentacji: {dokument}, dotyczącej: {dotyczy}.',
            'purpose'     => 'Dokumentacja jest mi potrzebna w celu: {cel}.',
            'asks'        => ['wydanie duplikatu w formie {forma_txt}, jeśli dopuszczalna;', 'przekazanie go w sposób: {sposob}.'],
            'fee'         => 'Jeżeli wydanie duplikatu dokumentacji wiąże się z opłatą, proszę o wskazanie jej wysokości oraz sposobu uiszczenia, o ile informacja ta nie jest dostępna w formularzu lub na stronie podmiotu przechowującego dokumentację.',
        ],
        'wniosek_dok' => [
            'code'        => 'DOK',
            'label'       => 'DOK · Wniosek o kserokopię dokumentacji wolontariusza / zleceniobiorcy',
            'icon'        => 'bi-copy',
            'description' => 'Wniosek o sporządzenie kserokopii dokumentacji wolontariusza lub zleceniobiorcy (wzór DOK), składany elektronicznie.',
            'sort'        => 17,
            'title'       => 'WNIOSEK O SPORZĄDZENIE KSEROKOPII DOKUMENTACJI WOLONTARIUSZA / ZLECENIOBIORCY',
            'fields'      => array_merge(appDocsHeaderFields(), [
                ['osoba',    'Imię i nazwisko wolontariusza lub zleceniobiorcy', 'text',     [], 'Czyjej dokumentacji dotyczy wniosek', 1],
                ['podmiot',  'Nazwa podmiotu lub organizacji',                   'text',     [], 'U kogo był wolontariat / umowa zlecenia', 1],
                ['okres',    'Okres, którego dotyczy dokumentacja',              'text',     [], 'np. 01.2025 – 06.2026', 1],
                ['dokument', 'Dokumenty do skopiowania',                         'textarea', [], 'Rodzaje lub nazwy dokumentów — przy wniosku o całość dokumentacji wpisz to wprost', 1],
                ['cel',      'Cel uzyskania dokumentacji',                       'text',     [], 'Jeśli wymagany', 0],
                ['sposob',       'Sposób przekazania kserokopii',                'select',   ['odbiór osobisty', 'doręczenie na wskazany adres'], '', 1],
                ['kontakt_pref', 'Preferowany sposób kontaktu w sprawie wniosku', 'select',  ['e-mail', 'telefon', 'Panel współpracownika (SZO)', 'poczta tradycyjna'], '', 1],
                ['zalaczniki',   'Załączniki',                                   'textarea', [], 'Wymagany załącznik — każdy w osobnym wierszu. Puste = brak.', 0],
            ]),
            'request'     => 'Zwracam się z wnioskiem o sporządzenie kserokopii dokumentacji dotyczącej {osoba}, związanej z wolontariatem lub wykonywaniem umowy zlecenia u {podmiot} w okresie {okres}. Proszę o sporządzenie kserokopii następujących dokumentów: {dokument}.',
            'purpose'     => 'Dokumentacja jest mi potrzebna w celu: {cel}.',
            'asks'        => ['sporządzenie kserokopii w formie papierowej;', 'przekazanie kserokopii w sposób: {sposob}.'],
            'fee'         => 'Jeżeli sporządzenie kserokopii wiąże się z opłatą, proszę o wskazanie jej wysokości oraz sposobu uiszczenia przed realizacją wniosku.',
        ],
        // Wzory z sekcjami (treść jako 'blocks' — zob. appDocsBlocksHtml)
        'wniosek_upo1' => [
            'group'       => 'collab',   // moduł Zmiana danych współpracownika
            'code'        => 'UPO1',
            'label'       => 'UPO1 · Wniosek o wydanie upoważnienia',
            'icon'        => 'bi-person-check',
            'description' => 'Wniosek o wydanie upoważnienia do czynności lub informacji (wzór UPO1), składany elektronicznie.',
            'sort'        => 18,
            'title'       => 'WNIOSEK O WYDANIE UPOWAŻNIENIA',
            'titleField'  => 'upow_osoba',
            'fields'      => [
                ['organ',        'Do: nazwa i adres organu lub instytucji',             'textarea', [], '', 1],
                ['wnioskodawca', 'Imię i nazwisko / nazwa wnioskodawcy',                'text',     [], '', 1],
                ['identyfikator','Identyfikator lub stanowisko',                        'text',     [], 'Jeśli dotyczy', 0],
                ['kontakt',      'Dane kontaktowe',                                     'text',     [], 'Telefon lub e-mail', 1],
                ['upow_osoba',   'Upoważnienie dla: imię i nazwisko',                   'text',     [], 'Osoba, która ma otrzymać upoważnienie', 1],
                ['upow_ident',   'Identyfikator / dane kontaktowe osoby upoważnianej',  'text',     [], 'Jeśli wymagane', 0],
                ['zakres',       'Zakres czynności lub informacji objętych upoważnieniem', 'textarea', [], 'np. dostęp do dokumentacji projektu, odbiór korespondencji', 1],
                ['sprawa',       'Sprawa, system lub relacja, której dotyczy upoważnienie', 'text', [], 'np. system SZO, projekt „…”, umowa nr …', 1],
                ['okres_od',     'Wnioskowany okres obowiązywania: od',                 'date',     [], '', 1],
                ['okres_do',     'Wnioskowany okres obowiązywania: do',                 'date',     [], '', 0],
                ['uzasadnienie', 'Uzasadnienie / dodatkowe informacje',                 'textarea', [], '', 0],
                ['zalaczniki',   'Załączniki',                                          'textarea', [], 'Każdy w osobnym wierszu. Puste = brak.', 0],
            ],
            'blocks'      => [
                ['to'],
                ['h', 'Dane wnioskodawcy'],
                ['kv', 'Imię i nazwisko / nazwa', 'wnioskodawca'],
                ['kv', 'Identyfikator lub stanowisko', 'identyfikator'],
                ['kv', 'Dane kontaktowe', 'kontakt'],
                ['h', 'Treść wniosku'],
                ['p', 'Wnoszę o wydanie upoważnienia dla:'],
                ['kv', 'Imię i nazwisko', 'upow_osoba'],
                ['kv', 'Identyfikator / dane kontaktowe', 'upow_ident'],
                ['kv', 'Zakres czynności lub informacji objętych upoważnieniem', 'zakres'],
                ['kv', 'Sprawa, system lub relacja, której dotyczy upoważnienie', 'sprawa'],
                ['p', 'Wnioskowany okres obowiązywania: od {okres_od} do {okres_do_txt}.'],
                ['kv', 'Uzasadnienie / dodatkowe informacje', 'uzasadnienie'],
                ['p', 'Proszę o przekazanie informacji o sposobie i terminie wydania upoważnienia na wskazane dane kontaktowe.'],
                ['att'],
                ['sign', 'w miejsce podpisu wnioskodawcy'],
                ['decision', 'Adnotacje podmiotu / decyzja'],
            ],
        ],
        'wniosek_upo2' => [
            'group'       => 'collab',   // moduł Zmiana danych współpracownika
            'code'        => 'UPO2',
            'label'       => 'UPO2 · Upoważnienie osoby trzeciej do kontaktu',
            'icon'        => 'bi-people',
            'description' => 'Upoważnienie osoby trzeciej do kontaktu w Twojej sprawie (wzór UPO2), składane elektronicznie.',
            'sort'        => 19,
            'title'       => 'UPOWAŻNIENIE OSOBY TRZECIEJ DO KONTAKTU',
            'titleField'  => 'upow_osoba',
            'fields'      => [
                ['wnioskodawca', 'Osoba udzielająca upoważnienia: imię i nazwisko / nazwa', 'text', [], '', 1],
                ['identyfikator','Identyfikator lub dane sprawy',                       'text',     [], 'np. nr umowy, nr sprawy', 0],
                ['kontakt',      'Dane kontaktowe',                                     'text',     [], 'Telefon lub e-mail', 1],
                ['upow_osoba',   'Osoba upoważniona: imię i nazwisko',                  'text',     [], '', 1],
                ['upow_ident',   'Identyfikator / dane kontaktowe osoby upoważnionej',  'text',     [], 'Jeśli wymagane', 0],
                ['kontakt_z',    'Kontakt z (podmiot)',                                 'text',     [], 'Z kim osoba upoważniona może się kontaktować', 1],
                ['sprawa',       'W sprawie',                                           'text',     [], 'np. rozliczenia wolontariatu, zajęć TI', 1],
                ['zakres',       'Zakres kontaktu i informacji',                         'textarea', [], 'np. informacje o terminach zajęć, frekwencji, płatnościach', 1],
                ['okres_od',     'Okres obowiązywania: od',                             'date',     [], '', 1],
                ['okres_do',     'Okres obowiązywania: do',                             'date',     [], '', 0],
            ],
            'blocks'      => [
                ['h', 'Dane osoby udzielającej upoważnienia'],
                ['kv', 'Imię i nazwisko / nazwa', 'wnioskodawca'],
                ['kv', 'Identyfikator lub dane sprawy', 'identyfikator'],
                ['kv', 'Dane kontaktowe', 'kontakt'],
                ['h', 'Dane osoby upoważnionej'],
                ['kv', 'Imię i nazwisko', 'upow_osoba'],
                ['kv', 'Identyfikator / dane kontaktowe', 'upow_ident'],
                ['h', 'Zakres upoważnienia'],
                ['p', 'Upoważniam wskazaną wyżej osobę do kontaktowania się z {kontakt_z} w sprawie {sprawa}.'],
                ['p', 'Upoważnienie obejmuje następujący zakres kontaktu i informacji: {zakres}.'],
                ['p', 'Upoważnienie dotyczy wyłącznie wskazanego zakresu kontaktu i samo przez się nie obejmuje prawa do składania oświadczeń woli, zaciągania zobowiązań ani podejmowania decyzji w moim imieniu.'],
                ['p', 'Okres obowiązywania: od {okres_od} do {okres_do_txt}.'],
                ['sign', 'w miejsce podpisu osoby udzielającej upoważnienia'],
                ['line', 'Potwierdzenie przyjęcia przez osobę upoważnioną (jeśli wymagane)'],
            ],
        ],
        'wniosek_upo3' => [
            'group'       => 'collab',   // moduł Zmiana danych współpracownika
            'code' => 'UPO3', 'label' => 'UPO3 · Odwołanie upoważnienia', 'icon' => 'bi-person-x', 'sort' => 20,
            'description' => 'Odwołanie udzielonego upoważnienia i cofnięcie powiązanego dostępu (wzór UPO3).',
            'title' => 'ODWOŁANIE UPOWAŻNIENIA', 'titleField' => 'upow_osoba',
            'fields' => array_merge([appDocsOrganField()], appDocsPersonFields('Imię i nazwisko / nazwa osoby odwołującej', 'Identyfikator lub dane sprawy'), [
                ['upow_osoba',   'Imię i nazwisko osoby upoważnionej',            'text',     [], '', 1],
                ['upow_ozn',     'Data udzielenia / oznaczenie upoważnienia',      'text',     [], 'np. 01.03.2026, UPO2/15/2026', 1],
                ['zakres',       'Zakres, którego dotyczy odwołanie',              'textarea', [], 'Całość albo wskazana część upoważnienia', 1],
                ['sprawa',       'Sprawa lub system',                              'text',     [], '', 1],
                ['uwagi',        'Uwagi / żądany termin realizacji czynności technicznych', 'textarea', [], '', 0],
            ]),
            'blocks' => array_merge([['to']], appDocsPersonBlocks('Dane osoby odwołującej upoważnienie', 'Identyfikator lub dane sprawy'), [
                ['h', 'Oświadczenie'],
                ['p', 'Niniejszym odwołuję upoważnienie udzielone:'],
                ['kv', 'Imię i nazwisko osoby upoważnionej', 'upow_osoba'],
                ['kv', 'Data udzielenia / oznaczenie upoważnienia', 'upow_ozn'],
                ['kv', 'Zakres, którego dotyczy odwołanie', 'zakres'],
                ['kv', 'Sprawa lub system', 'sprawa'],
                ['p', 'Proszę o odnotowanie odwołania oraz — jeśli dotyczy — cofnięcie powiązanego dostępu w systemie. Proszę o potwierdzenie daty odnotowania odwołania.'],
                ['kv', 'Uwagi / żądany termin realizacji czynności technicznych', 'uwagi'],
                ['sign', 'w miejsce podpisu'],
                ['decision', 'Adnotacje podmiotu'],
            ]),
        ],
        'wniosek_szd' => [
            'group'       => 'collab',   // moduł Zmiana danych współpracownika
            'code' => 'SZD', 'label' => 'SZD · Wniosek o zmianę danych w systemie', 'icon' => 'bi-pencil-square', 'sort' => 21,
            'description' => 'Wniosek o zmianę danych w systemie — pola, dotychczasowe i nowe wartości (wzór SZD).',
            'title' => 'WNIOSEK O ZMIANĘ DANYCH W SYSTEMIE', 'titleField' => 'system',
            'fields' => array_merge([appDocsOrganField()], appDocsPersonFields('Imię i nazwisko / nazwa wnioskodawcy', 'Identyfikator użytkownika / numer sprawy'), [
                ['system',       'Nazwa systemu',                                  'text',     [], 'np. SZO, Microsoft 365, panel kursanta', 1],
                ['rodzaj',       'Rodzaj zmiany',                                  'select',   ['aktualizacja danych', 'korekta błędu', 'uzupełnienie danych', 'usunięcie danych', 'inna'], '', 1],
                ['zmiany',       'Zmiany: pole | dotychczasowa wartość | nowa wartość', 'textarea', [], "Każda zmiana w osobnym wierszu, np.\nNazwisko | Kowalska | Nowak\nTelefon | 600 000 000 | 601 111 111", 1],
                ['uzasadnienie', 'Uzasadnienie / podstawa zmiany',                 'textarea', [], '', 1],
                ['data_zmiany',  'Wnioskowana data wprowadzenia zmiany',           'date',     [], '', 0],
                ['zalaczniki',   'Załączniki potwierdzające zmianę (jeśli wymagane)', 'textarea', [], 'Każdy w osobnym wierszu. Puste = brak.', 0],
            ]),
            'blocks' => array_merge([['to']], appDocsPersonBlocks('Dane wnioskodawcy', 'Identyfikator użytkownika / numer sprawy'), [
                ['h', 'Wnioskowana zmiana'],
                ['kv', 'Nazwa systemu', 'system'],
                ['kv', 'Rodzaj zmiany', 'rodzaj'],
                ['table', 'zmiany', ['Pole / dana', 'Dotychczasowa wartość', 'Nowa wartość']],
                ['kv', 'Uzasadnienie / podstawa zmiany', 'uzasadnienie'],
                ['kv', 'Wnioskowana data wprowadzenia zmiany', 'data_zmiany'],
                ['att', 'Załączniki potwierdzające zmianę'],
                ['p', 'Proszę o potwierdzenie wprowadzenia zmiany albo wskazanie brakujących informacji.'],
                ['sign', 'w miejsce podpisu wnioskodawcy'],
                ['decision', 'Adnotacje realizującego'],
            ]),
        ],
        'wniosek_skk' => [
            'group'       => 'collab',   // moduł Zmiana danych współpracownika
            'code' => 'SKK', 'label' => 'SKK · Wniosek o zmianę kanału kontaktu', 'icon' => 'bi-telephone-forward', 'sort' => 22,
            'description' => 'Wniosek o zmianę kanału kontaktu — e-mail, telefon, inny kanał (wzór SKK).',
            'title' => 'WNIOSEK O ZMIANĘ KANAŁU KONTAKTU', 'titleField' => 'kanal_nowy',
            'fields' => array_merge([appDocsOrganField()], appDocsPersonFields('Imię i nazwisko / nazwa wnioskodawcy', 'Identyfikator użytkownika / numer sprawy'), [
                ['kanal_stary',  'Dotychczasowy kanał kontaktu',                   'select',   ['e-mail', 'telefon / SMS', 'Panel współpracownika (SZO)', 'poczta tradycyjna', 'inny'], '', 1],
                ['kanal_nowy',   'Wnioskowany kanał kontaktu',                     'select',   ['e-mail', 'telefon / SMS', 'Panel współpracownika (SZO)', 'poczta tradycyjna', 'inny'], '', 1],
                ['kanal_dane',   'Nowy adres e-mail / numer telefonu / identyfikator kanału', 'text', [], '', 1],
                ['zakres',       'Zakres spraw, którego dotyczy zmiana',           'textarea', [], 'Wszystkie sprawy albo wskazane', 1],
                ['data_zmiany',  'Wnioskowana data zmiany',                        'date',     [], '', 0],
                ['uwagi',        'Uwagi',                                          'textarea', [], '', 0],
            ]),
            'blocks' => array_merge([['to']], appDocsPersonBlocks('Dane wnioskodawcy', 'Identyfikator użytkownika / numer sprawy'), [
                ['h', 'Zmiana kanału kontaktu'],
                ['kv', 'Dotychczasowy kanał kontaktu', 'kanal_stary'],
                ['kv', 'Wnioskowany kanał kontaktu', 'kanal_nowy'],
                ['kv', 'Nowy adres e-mail / numer telefonu / identyfikator kanału', 'kanal_dane'],
                ['kv', 'Zakres spraw, którego dotyczy zmiana', 'zakres'],
                ['kv', 'Wnioskowana data zmiany', 'data_zmiany'],
                ['p', 'Proszę o potwierdzenie wprowadzenia zmiany oraz wskazanie, czy wymaga ona dodatkowej weryfikacji.'],
                ['kv', 'Uwagi', 'uwagi'],
                ['sign', 'w miejsce podpisu wnioskodawcy'],
                ['decision', 'Adnotacje realizującego'],
            ]),
        ],
        'wniosek_itsm' => [
            'code' => 'ITSM', 'label' => 'ITSM · Wniosek o aktywację licencji', 'icon' => 'bi-key', 'sort' => 23,
            'description' => 'Wniosek o aktywację licencji systemu lub oprogramowania (wzór ITSM).',
            'title' => 'WNIOSEK O AKTYWACJĘ LICENCJI', 'titleField' => 'system',
            'fields' => [
                appDocsOrganField(),
                ['wnioskodawca', 'Imię i nazwisko osoby, której dotyczy wniosek', 'text',     [], '', 1],
                ['identyfikator','Identyfikator konta / login służbowy',           'text',     [], 'np. imie.nazwisko@feer.org.pl', 1],
                ['jednostka',    'Jednostka / stanowisko',                         'text',     [], '', 0],
                ['kontakt',      'Dane kontaktowe',                                'text',     [], 'Telefon lub e-mail', 1],
                ['system',       'Nazwa systemu lub oprogramowania',               'text',     [], 'np. Microsoft 365, Canva Pro, Adobe Acrobat', 1],
                ['poziom',       'Rodzaj / poziom licencji',                       'text',     [], 'np. A3, Pro, Business Standard', 1],
                ['data_aktywacji','Wnioskowana data aktywacji',                   'date',     [], '', 0],
                ['okres',        'Okres obowiązywania, jeśli ograniczony',         'text',     [], 'np. do 30.06.2027', 0],
                ['uzasadnienie', 'Uzasadnienie biznesowe',                         'textarea', [], '', 1],
                ['zasob',        'Urządzenie / numer zasobu (jeśli wymagane)',     'text',     [], '', 0],
                ['akceptujacy',  'Osoba zatwierdzająca / akceptacja',              'text',     [], 'Przełożony lub właściciel budżetu', 0],
            ],
            'blocks' => [
                ['to'],
                ['h', 'Dane osoby, której dotyczy wniosek'],
                ['kv', 'Imię i nazwisko', 'wnioskodawca'],
                ['kv', 'Identyfikator konta / login służbowy', 'identyfikator'],
                ['kv', 'Jednostka / stanowisko', 'jednostka'],
                ['kv', 'Dane kontaktowe', 'kontakt'],
                ['h', 'Wnioskowane uprawnienie licencyjne'],
                ['kv', 'Nazwa systemu lub oprogramowania', 'system'],
                ['kv', 'Rodzaj / poziom licencji', 'poziom'],
                ['kv', 'Wnioskowana data aktywacji', 'data_aktywacji'],
                ['kv', 'Okres obowiązywania, jeśli ograniczony', 'okres'],
                ['kv', 'Uzasadnienie biznesowe', 'uzasadnienie'],
                ['kv', 'Urządzenie / numer zasobu', 'zasob'],
                ['kv', 'Osoba zatwierdzająca / akceptacja', 'akceptujacy'],
                ['p', 'Proszę o potwierdzenie aktywacji albo wskazanie brakujących danych lub wymaganej zgody.'],
                ['sign', 'wnioskodawca'],
                ['decision', 'Adnotacje zespołu IT'],
            ],
        ],
        'wniosek_dyd1' => [
            'group'       => 'collab',   // moduł Zmiana danych współpracownika
            'code' => 'DYD1', 'label' => 'DYD1 · Upoważnienie do wglądu w panel', 'icon' => 'bi-eye', 'sort' => 24,
            'description' => 'Upoważnienie innej osoby do wglądu w panel lub system — bez prawa zmian (wzór DYD1).',
            'title' => 'UPOWAŻNIENIE DO WGLĄDU W PANEL', 'titleField' => 'upow_osoba',
            'fields' => array_merge(appDocsPersonFields('Osoba udzielająca upoważnienia: imię i nazwisko / nazwa', 'Identyfikator użytkownika / numer sprawy'), [
                ['upow_osoba',   'Osoba upoważniona: imię i nazwisko',             'text',     [], '', 1],
                ['upow_ident',   'Identyfikator konta / dane kontaktowe osoby upoważnionej', 'text', [], '', 1],
                ['system',       'Nazwa panelu / systemu',                         'text',     [], 'np. panel kursanta, panel dydaktyka', 1],
                ['zakres',       'Zakres danych lub widoków do wglądu',            'textarea', [], 'np. plan zajęć, frekwencja, oceny', 1],
                ['zmiany_zatw',  'Prawo do wprowadzania zmian (jeśli odrębnie zatwierdzono)', 'select', ['nie — wyłącznie wgląd', 'tak — odrębnie zatwierdzone'], '', 1],
                ['okres_od',     'Okres obowiązywania: od',                        'date',     [], '', 1],
                ['okres_do',     'Okres obowiązywania: do',                        'date',     [], '', 0],
                ['uzasadnienie', 'Uzasadnienie / cel dostępu',                     'textarea', [], '', 1],
            ]),
            'blocks' => array_merge(appDocsPersonBlocks('Dane osoby udzielającej upoważnienia', 'Identyfikator użytkownika / numer sprawy'), [
                ['h', 'Dane osoby upoważnionej'],
                ['kv', 'Imię i nazwisko', 'upow_osoba'],
                ['kv', 'Identyfikator konta / dane kontaktowe', 'upow_ident'],
                ['h', 'Zakres dostępu'],
                ['kv', 'Nazwa panelu / systemu', 'system'],
                ['kv', 'Zakres danych lub widoków, do których ma być zapewniony wgląd', 'zakres'],
                ['p', 'Zakres upoważnienia obejmuje wyłącznie wgląd, bez prawa do wprowadzania zmian, chyba że odrębnie zatwierdzono: {zmiany_zatw}.'],
                ['p', 'Okres obowiązywania: od {okres_od} do {okres_do_txt}.'],
                ['kv', 'Uzasadnienie / cel dostępu', 'uzasadnienie'],
                ['p', 'Upoważnienie nie obejmuje udostępniania hasła ani danych uwierzytelniających innej osobie. Dostęp powinien zostać przypisany do indywidualnego konta osoby upoważnionej, jeśli system to umożliwia.'],
                ['sign', 'w miejsce podpisu osoby udzielającej upoważnienia'],
                ['decision', 'Akceptacja administratora / właściciela systemu (jeśli wymagana)'],
            ]),
        ],
    ];
}

/** Pole adresata wzorów z sekcjami (zablokowane — organizacja). */
function appDocsOrganField(): array {
    return ['organ', 'Do: nazwa i adres organu lub instytucji', 'textarea', [], '', 1];
}

/** Pola osoby składającej (imię i nazwisko zablokowane, identyfikator, kontakt). */
function appDocsPersonFields(string $nameLabel, string $identLabel): array {
    return [
        ['wnioskodawca',  $nameLabel,  'text', [], '', 1],
        ['identyfikator', $identLabel, 'text', [], 'Jeśli dotyczy', 0],
        ['kontakt',       'Dane kontaktowe', 'text', [], 'Telefon lub e-mail', 1],
    ];
}

/** Bloki sekcji osoby składającej. */
function appDocsPersonBlocks(string $heading, string $identLabel): array {
    return [['h', $heading], ['kv', 'Imię i nazwisko / nazwa', 'wnioskodawca'], ['kv', $identLabel, 'identyfikator'], ['kv', 'Dane kontaktowe', 'kontakt']];
}

/**
 * Wzór do potwierdzenia złożenia (UPZ): wzór urzędowy typu albo — dla typów bez wzoru — wzór ogólny
 * PIS z nazwą typu. UPZ wystawia się dla KAŻDEGO wniosku.
 */
function appDocsReceiptTpl(array $app): array {
    $tpl = appDocsTemplateFor($app['type_name'] ?? null);
    if ($tpl) return $tpl;
    $label = trim((string)($app['type_label'] ?? '')) ?: 'Pismo / wniosek';
    return ['code' => 'PIS', 'label' => $label, 'title' => mb_strtoupper($label), 'generic' => true];
}

/** Wzór dla nazwy typu wniosku albo null (typ bez dokumentu). */
function appDocsTemplateFor(?string $typeName): ?array {
    return $typeName ? (appDocsTemplates()[$typeName] ?? null) : null;
}

/** Dopisuje brakujące typy wniosków z rejestru (wraz z polami). Bez nadpisywania istniejących. */
function appDocsEnsureTypes(\PDO $pdo): void {
    $have = $pdo->query("SELECT name FROM application_types")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    foreach (appDocsTemplates() as $name => $t) {
        if (in_array($name, $have, true)) continue;
        $pdo->prepare("INSERT INTO application_types (name, label, icon, description, requires_contract, allow_attachment, is_active, sort_order)
                       VALUES (?,?,?,?,0,1,1,?)")
            ->execute([$name, $t['label'], $t['icon'], $t['description'], $t['sort']]);
        $typeId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO application_type_fields (type_id, name, label, field_type, options_json, placeholder, required, sort_order)
                              VALUES (?,?,?,?,?,?,?,?)");
        foreach ($t['fields'] as $i => [$fname, $label, $ftype, $opts, $ph, $req]) {
            $ins->execute([$typeId, $fname, $label, $ftype, json_encode($opts, JSON_UNESCAPED_UNICODE), $ph, $req, ($i + 1) * 10]);
        }
    }
}

/** Domyślna wartość pola formularza wzoru (dane zalogowanej osoby, adresat = organizacja). */
function appDocsPrefill(?string $typeName, string $field, array $user): string {
    if (!appDocsTemplateFor($typeName)) return '';
    switch ($field) {
        case 'wnioskodawca': return (string)($user['name'] ?? '');
        case 'kontakt':
            $u = db_one("SELECT email, phone_number FROM users WHERE id = ?", [(int)($user['id'] ?? 0)]) ?: [];
            return implode(', ', array_filter([trim((string)($u['phone_number'] ?? '')), trim((string)($u['email'] ?? $user['email'] ?? ''))]));
        case 'organ':
            $name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
            return trim($name . "\n" . org_setting('org_adres'));
        case 'podmiot':     return (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
        case 'osoba':       return (string)($user['name'] ?? '');
        case 'kontakt_z':   return (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
        case 'miejscowosc': return '';
    }
    return '';
}

/** Pola wzoru zablokowane do edycji (wyszarzone) — adresatem zawsze jest organizacja, wnioskodawcą zalogowana osoba. */
const APP_DOCS_LOCKED_FIELDS = ['organ', 'wnioskodawca'];

function appDocsFieldLocked(?string $typeName, string $field): bool {
    return appDocsTemplateFor($typeName) !== null && in_array($field, APP_DOCS_LOCKED_FIELDS, true);
}

/** Nadpisuje zablokowane pola wartościami z systemu (ochrona przed zmianą w żądaniu). */
function appDocsApplyLocked(?string $typeName, array $fields, array $user): array {
    foreach (APP_DOCS_LOCKED_FIELDS as $f) {
        if (appDocsFieldLocked($typeName, $f)) $fields[$f] = appDocsPrefill($typeName, $f, $user);
    }
    return $fields;
}

/** Domyślny tytuł wniosku z wzoru (gdy użytkownik go nie wpisał). */
function appDocsDefaultTitle(string $typeName, array $fields): string {
    $t = appDocsTemplateFor($typeName);
    if (!$t) return '';
    $what = trim((string)($fields[$t['titleField'] ?? 'dokument'] ?? ''));
    return $t['code'] . ($what !== '' ? ' — ' . mb_substr($what, 0, 120) : ' — ' . mb_strtolower($t['title']));
}

/** Wniosek z typem i wnioskodawcą albo null. */
function appDocsLoad(int $id): ?array {
    $row = db_one("SELECT a.*, t.name AS type_name, t.label AS type_label, u.name AS user_name, u.email AS user_email
                   FROM user_applications a
                   LEFT JOIN application_types t ON t.id = a.type_id
                   LEFT JOIN users u ON u.id = a.user_id
                   WHERE a.id = ?", [$id]);
    return $row ?: null;
}

/** Czy bieżący użytkownik może zobaczyć dokument: autor wniosku albo admin/redaktor. */
function appDocsCanView(array $app): bool {
    $u = current_user();
    if (!$u) return false;
    return (int)$app['user_id'] === (int)$u['id'] || in_array($u['role'] ?? '', ['admin', 'editor'], true);
}

/** Numer dokumentu wniosku, np. ZSW-1/42/2026. */
function appDocsNumber(array $app, array $tpl): string {
    return $tpl['code'] . '/' . (int)$app['id'] . '/' . date('Y', strtotime((string)$app['created_at']));
}

/** Adres dokumentu (HTML) lub PDF dla wniosku. */
function appDocsUrl(int $id, bool $pdf = false): string {
    return APP_URL . '/modules/application_documents/router.php/' . $id . ($pdf ? '/pdf' : '');
}

/** Treść dokumentu (HTML, bez <html>) — wspólna dla podglądu i PDF. */
function appDocsHtml(array $app, array $tpl): string {
    $f   = json_decode((string)($app['fields_json'] ?? '{}'), true) ?: [];
    // daty z pól typu date (RRRR-MM-DD) w dokumencie po polsku: DD.MM.RRRR
    $v   = function (string $k) use ($f) {
        $x = trim((string)($f[$k] ?? ''));
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $x, $m) ? "$m[3].$m[2].$m[1]" : $x;
    };
    $date = date('d.m.Y', strtotime((string)$app['created_at']));
    $calc = ['forma_txt' => $v('forma') === 'elektroniczna' ? 'elektronicznej' : ($v('forma') === 'papierowa' ? 'papierowej' : ''),
             'okres_do_txt' => $v('okres_do') !== '' ? $v('okres_do') : 'odwołania'];
    $fill = fn(string $s) => preg_replace_callback('/\{(\w+)\}/', function ($m) use ($v, $calc) {
        $val = $calc[$m[1]] ?? $v($m[1]);
        return '<b>' . nl2br(h($val !== '' ? $val : '—')) . '</b>';
    }, h($s));
    if (!empty($tpl['blocks'])) return appDocsBlocksHtml($app, $tpl, $v, $fill, $date);
    $att = array_values(array_filter(array_map('trim', preg_split('/\R/', $v('zalaczniki')))));
    if (!empty($app['plik'])) $att[] = 'plik dołączony do wniosku: ' . basename((string)$app['plik']);

    ob_start(); ?>
<div class="ad-place"><?= h($v('miejscowosc') ?: '…') ?>, <?= h($date) ?> r.</div>
<table class="ad-parties"><tr>
  <td><div class="ad-lbl">Wnioskodawca:</div>
    <div class="ad-val"><b><?= h($v('wnioskodawca')) ?></b><br><?= h($v('adres')) ?><br><?= h($v('kontakt')) ?></div></td>
  <td class="ad-to"><div class="ad-lbl">Do:</div><div class="ad-val"><b><?= nl2br(h($v('organ'))) ?></b></div></td>
</tr></table>
<h1 class="ad-title"><?= h($tpl['title']) ?></h1>
<div class="ad-no">nr <?= h(appDocsNumber($app, $tpl)) ?></div>
<p><?= $fill($tpl['request']) ?></p>
<?php if ($v('cel') !== ''): ?><p><?= $fill($tpl['purpose']) ?></p><?php endif; ?>
<p>Proszę o:</p>
<ul><?php foreach ($tpl['asks'] as $ask): ?><li><?= $fill($ask) ?></li><?php endforeach; ?></ul>
<p><?= h($tpl['fee']) ?></p>
<p>Proszę o kontakt w sprawie wniosku za pośrednictwem: <b><?= h($v('kontakt_pref') ?: '—') ?></b>.</p>
<p class="ad-lbl2">Załączniki:</p>
<ul><?php if ($att): foreach ($att as $a): ?><li><?= h($a) ?></li><?php endforeach; else: ?><li>brak</li><?php endif; ?></ul>
<div class="ad-sign">
  <div class="ad-sign-box">
    <div class="ad-lbl">Złożono elektronicznie</div>
    <div>w miejsce podpisu wnioskodawcy: <b><?= h($app['user_name'] ?: $v('wnioskodawca')) ?></b><?= $app['user_email'] ? ' (' . h($app['user_email']) . ')' : '' ?></div>
    <div>po zalogowaniu do SZO, <?= h(date('d.m.Y, H:i', strtotime((string)$app['created_at']))) ?></div>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}

/**
 * Treść wzoru z sekcjami (UPO1, UPO2): bloki 'to' (adresat), 'h' (sekcja), 'kv' (etykieta + pole),
 * 'p' (akapit z {polami}), 'att' (załączniki), 'sign' (złożenie elektroniczne), 'decision' (adnotacje
 * podmiotu — status i odpowiedź z SZO), 'line' (miejsce na podpis odręczny).
 */
function appDocsBlocksHtml(array $app, array $tpl, callable $v, callable $fill, string $date): string {
    $att = array_values(array_filter(array_map('trim', preg_split('/\R/', $v('zalaczniki')))));
    if (!empty($app['plik'])) $att[] = 'plik dołączony do wniosku: ' . basename((string)$app['plik']);
    $out = '<div class="ad-place">' . h($date) . ' r.</div>';
    $titleDone = false;
    $title = '<h1 class="ad-title">' . h($tpl['title']) . '</h1><div class="ad-no">nr ' . h(appDocsNumber($app, $tpl)) . '</div>';
    foreach ($tpl['blocks'] as $b) {
        switch ($b[0]) {
            case 'to':
                $out .= '<table class="ad-parties"><tr><td></td><td class="ad-to"><div class="ad-lbl">Do:</div><div class="ad-val"><b>'
                      . nl2br(h($v('organ'))) . '</b></div></td></tr></table>';
                break;
            case 'h':
                if (!$titleDone) { $out .= $title; $titleDone = true; }
                $out .= '<div class="ad-sec">' . h($b[1]) . '</div>';
                break;
            case 'kv':
                $val = $v($b[2]);
                $out .= '<table class="ad-kv"><tr><td class="ad-kv-l">' . h($b[1]) . ':</td><td class="ad-kv-v">'
                      . ($val !== '' ? nl2br(h($val)) : '<span class="ad-muted">—</span>') . '</td></tr></table>';
                break;
            case 'p':
                $out .= '<p>' . $fill($b[1]) . '</p>';
                break;
            case 'table':
                $rows = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$v($b[1])))));
                $out .= '<table class="ad-grid"><tr>' . implode('', array_map(fn($c) => '<th>' . h($c) . '</th>', $b[2])) . '</tr>';
                foreach ($rows ?: [''] as $row) {
                    $cells = array_map('trim', preg_split('/\s*[|;\t]\s*/', $row));
                    $cells = array_pad(array_slice($cells, 0, count($b[2])), count($b[2]), '');
                    $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . ($c !== '' ? h($c) : '&nbsp;') . '</td>', $cells)) . '</tr>';
                }
                $out .= '</table>';
                break;
            case 'att':
                $out .= '<p class="ad-lbl2">' . h($b[1] ?? 'Załączniki') . ':</p><ul>';
                $out .= $att ? implode('', array_map(fn($a) => '<li>' . h($a) . '</li>', $att)) : '<li>brak</li>';
                $out .= '</ul>';
                break;
            case 'sign':
                $out .= '<div class="ad-sign"><div class="ad-sign-box"><div class="ad-lbl">Złożono elektronicznie</div>'
                      . '<div>' . h($b[1]) . ': <b>' . h($app['user_name'] ?: $v('wnioskodawca')) . '</b>'
                      . (!empty($app['user_email']) ? ' (' . h($app['user_email']) . ')' : '') . '</div>'
                      . '<div>po zalogowaniu do SZO, ' . h(date('d.m.Y, H:i', strtotime((string)$app['created_at']))) . '</div>'
                      . ($v('podpis') !== '' ? '<div>' . h($v('podpis')) . '</div>' : '') . '</div></div>';
                break;
            case 'decision':
                $ans = trim((string)($app['odpowiedz'] ?? ''));
                $st  = str_replace('_', ' ', (string)($app['status'] ?? ''));
                $out .= '<div class="ad-box"><div class="ad-lbl">' . h($b[1]) . '</div>'
                      . ($ans !== '' || !in_array($app['status'] ?? '', ['', 'nowy'], true)
                          ? '<div>Status: <b>' . h($st) . '</b>' . (!empty($app['odpowiedz_at']) ? ' · ' . h(date('d.m.Y', strtotime((string)$app['odpowiedz_at']))) : '') . '</div>'
                            . ($ans !== '' ? '<div>' . nl2br(h($ans)) . '</div>' : '')
                          : '<div class="ad-blank">&nbsp;</div><div class="ad-blank">&nbsp;</div><div class="ad-blank">&nbsp;</div>')
                      . '</div>';
                break;
            case 'line':
                $out .= '<div class="ad-line"><div class="ad-line-l">' . h($b[1]) . ':</div><div class="ad-line-s">&nbsp;</div></div>';
                break;
        }
    }
    if (!$titleDone) $out = $title . $out;
    return $out;
}

/** Arkusz stylów dokumentu (podgląd i mPDF — tylko proste selektory). */
function appDocsCss(): string {
    return 'body{font-family:"DejaVu Sans","Segoe UI",Arial,sans-serif;font-size:10.5pt;color:#111;line-height:1.5}'
        . '.ad-place{text-align:right;margin-bottom:18pt}'
        . '.ad-parties{width:100%;border-collapse:collapse;margin-bottom:22pt}.ad-parties td{vertical-align:top;width:50%;padding:0}'
        . '.ad-to{padding-left:24pt}.ad-lbl{font-size:8pt;text-transform:uppercase;letter-spacing:1pt;color:#555;margin-bottom:3pt}'
        . '.ad-lbl2{margin-bottom:2pt}.ad-title{font-size:13pt;text-align:center;margin:0 0 2pt;letter-spacing:.5pt}'
        . '.ad-no{text-align:center;font-size:8.5pt;color:#555;margin-bottom:16pt}'
        . 'p{margin:0 0 8pt;text-align:justify}ul{margin:0 0 8pt 16pt;padding:0}li{margin-bottom:2pt}'
        . '.ad-sign{margin-top:30pt;text-align:right}.ad-sign-box{display:inline-block;text-align:left;border-left:3pt solid #0050ef;padding:4pt 0 4pt 8pt;font-size:9pt}'
        . '.ad-sec{font-size:8.5pt;font-weight:bold;text-transform:uppercase;letter-spacing:.8pt;border-bottom:1.2pt solid #111;padding-bottom:2pt;margin:14pt 0 6pt}'
        . '.ad-kv{width:100%;border-collapse:collapse;margin-bottom:3pt}.ad-kv td{vertical-align:top;padding:2pt 0}'
        . '.ad-kv-l{width:42%;color:#444;padding-right:8pt}.ad-kv-v{font-weight:bold}.ad-muted{color:#888;font-weight:normal}'
        . '.ad-box{margin-top:18pt;border:1pt solid #111;padding:6pt 8pt;font-size:9.5pt}.ad-blank{border-bottom:.6pt dotted #888;height:16pt}'
        . '.ad-grid{width:100%;border-collapse:collapse;margin:4pt 0 8pt}.ad-grid th,.ad-grid td{border:.8pt solid #111;padding:3pt 5pt;text-align:left;vertical-align:top;font-size:9.5pt}'
        . '.ad-grid th{background:#e6e6e6;font-size:8pt;text-transform:uppercase;letter-spacing:.4pt}'
        . '.ad-line{margin-top:22pt;font-size:9pt}.ad-line-s{border-bottom:.8pt solid #111;width:60%;height:18pt}';
}

/** PDF wniosku jako string albo null przy błędzie. */
function appDocsPdf(array $app, array $tpl): ?string {
    try {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 20, 'margin_right' => 18,
                                'margin_top' => 18, 'margin_bottom' => 18, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $mpdf->SetTitle($tpl['title'] . ' ' . appDocsNumber($app, $tpl));
        $mpdf->SetAuthor((string)($app['user_name'] ?? ''));
        $mpdf->SetHTMLFooter('<div style="font-size:7.5pt;color:#777;text-align:right">' . h(appDocsNumber($app, $tpl)) . ' · strona {PAGENO}/{nbpg}</div>');
        $mpdf->WriteHTML(appDocsCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(appDocsHtml($app, $tpl), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        error_log('[application_documents] ' . $e->getMessage());
        return null;
    }
}

// ── Złożenie elektroniczne: suma kontrolna, rejestracja w EZD, potwierdzenie ─────

/** Suma kontrolna SHA-256 treści wniosku (pola + metadane złożenia) — stała, niezależna od PDF. */
function appDocsHash(array $app): string {
    $fields = json_decode((string)($app['fields_json'] ?? '{}'), true) ?: [];
    ksort($fields);
    return hash('sha256', json_encode([
        'id' => (int)$app['id'], 'type' => (string)($app['type_name'] ?? ''), 'user' => (int)$app['user_id'],
        'title' => (string)$app['tytul'], 'fields' => $fields, 'created' => (string)$app['created_at'],
        'file' => (string)($app['plik'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Skrót do wyświetlenia: grupy po 8 znaków. */
function appDocsHashPretty(string $hash): string {
    return trim(chunk_split(strtoupper($hash), 8, ' '));
}

/**
 * Po złożeniu KAŻDEGO wniosku: numer (wzór albo PIS), suma kontrolna, IP, rejestracja przesyłki w EZD (RPW)
 * ze skanem = PDF wniosku (wzory ZSW/DOK) + potwierdzenie złożenia UPZ, oraz wystawienie UPZ
 * z wysyłką do składającego. Błąd EZD lub poczty nie cofa złożenia — trafia do logu.
 */
function appDocsAfterSubmit(int $appId): void {
    appDocsMigrateUpz();
    $app = appDocsLoad($appId);
    if (!$app) return;
    $tpl    = appDocsReceiptTpl($app);
    $number = appDocsNumber($app, $tpl);
    $hash   = appDocsHash($app);
    db()->prepare("UPDATE user_applications SET doc_number=?, doc_hash=?, submitted_ip=? WHERE id=?")
        ->execute([$number, $hash, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $appId]);

    if (function_exists('module_enabled') && module_enabled('ezd_enabled')) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/ezd.php';
            $f = json_decode((string)$app['fields_json'], true) ?: [];
            $rpw = ezd_rpw_create([
                'data_wplywu' => date('Y-m-d', strtotime((string)$app['created_at'])),
                'typ'         => 'inne',
                'nadawca'     => trim((string)($f['wnioskodawca'] ?? '')) ?: (string)$app['user_name'],
                'znak_obcy'   => $number,
                'opis'        => $tpl['code'] . ' — ' . (string)$app['tytul'],
                'uwagi'       => 'Złożono elektronicznie w Panelu współpracownika (' . ($app['user_email'] ?: 'konto #' . $app['user_id']) . '). '
                               . 'Suma kontrolna SHA-256: ' . $hash . '. W skanie: ' . (empty($tpl['generic']) ? 'wniosek i ' : '') . 'potwierdzenie złożenia UPZ.',
            ], (int)$app['user_id']);
            db()->prepare("UPDATE user_applications SET ezd_rpw_id=? WHERE id=?")->execute([(int)$rpw['id'], $appId]);

            $fresh = appDocsLoad($appId) ?? $app;
            $parts = [];
            if (empty($tpl['generic'])) $parts[] = appDocsPdf($fresh, $tpl);
            $parts[] = appDocsReceiptPdf($fresh, $tpl);
            $pdf = appDocsMergePdfs(array_values(array_filter($parts, fn($x) => $x !== null)));
            if ($pdf !== null) {
                $dir = UPLOAD_DIR . EZD_RPW_SUBDIR . (int)$rpw['id'] . '/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
                if (@file_put_contents($dir . $stored, $pdf) !== false) {
                    db()->prepare("UPDATE ezd_rpw SET scan_file=?, scan_name=?, scan_mime='application/pdf', scan_size=? WHERE id=?")
                        ->execute([$stored, str_replace('/', '_', $number) . '.pdf', strlen($pdf), (int)$rpw['id']]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[application_documents] EZD RPW dla wniosku #' . $appId . ': ' . $e->getMessage());
        }
    }

    try { appDocsIssueReceipt($appId); }
    catch (\Throwable $e) { error_log('[application_documents] UPZ dla wniosku #' . $appId . ': ' . $e->getMessage()); }
}

/** Kolumny UPZ w user_applications (samonaprawa). */
function appDocsMigrateUpz(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('_applications_init')) _applications_init();   // doc_number, doc_hash, ezd_rpw_id…
    $cols = array_column(db()->query("PRAGMA table_info(user_applications)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
    foreach (['upz_file' => 'TEXT', 'upz_issued_at' => 'DATETIME', 'upz_mailed_at' => 'DATETIME'] as $c => $def) {
        if ($cols && !in_array($c, $cols, true)) db()->exec("ALTER TABLE user_applications ADD COLUMN {$c} {$def}");
    }
}

/**
 * Wystawia potwierdzenie złożenia (UPZ) wniosku: PDF w UPLOAD_DIR/application_receipts/ i — jeśli jeszcze
 * nie wysłano — e-mail do składającego z PDF w załączniku, wysyłany od razu (bez kolejki). Bezpieczne
 * do wielokrotnego wywołania (workflow może je odpalić na etapie): drugi raz tylko odświeża PDF.
 * Zwraca ścieżkę PDF względem UPLOAD_DIR albo null.
 */
function appDocsIssueReceipt(int $appId, bool $mail = true): ?string {
    appDocsMigrateUpz();
    $app = appDocsLoad($appId);
    if (!$app) return null;
    $tpl = appDocsReceiptTpl($app);
    if (empty($app['doc_number']) || empty($app['doc_hash'])) {
        db()->prepare("UPDATE user_applications SET doc_number=COALESCE(doc_number, ?), doc_hash=COALESCE(doc_hash, ?) WHERE id=?")
            ->execute([appDocsNumber($app, $tpl), appDocsHash($app), $appId]);
        $app = appDocsLoad($appId);
    }
    $pdf = appDocsReceiptPdf($app, $tpl);
    if ($pdf === null) return null;
    $rel = 'application_receipts/UPZ_' . str_replace('/', '_', (string)$app['doc_number']) . '.pdf';
    if (!is_dir(UPLOAD_DIR . 'application_receipts')) @mkdir(UPLOAD_DIR . 'application_receipts', 0750, true);
    if (@file_put_contents(UPLOAD_DIR . $rel, $pdf) === false) return null;
    db()->prepare("UPDATE user_applications SET upz_file=?, upz_issued_at=COALESCE(upz_issued_at, ?) WHERE id=?")->execute([$rel, date('Y-m-d H:i:s'), $appId]);

    if ($mail && empty($app['upz_mailed_at']) && filter_var((string)$app['user_email'], FILTER_VALIDATE_EMAIL)) {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<p>Dzień dobry ' . $e($app['user_name']) . ',</p>'
              . '<p>potwierdzamy złożenie wniosku <strong>' . $e($app['tytul']) . '</strong> (nr ' . $e($app['doc_number']) . ', '
              . date('d.m.Y H:i', strtotime((string)$app['created_at'])) . ').</p>'
              . '<p>W załączniku potwierdzenie złożenia (UPZ) z sumą kontrolną treści wniosku. '
              . 'Status sprawy sprawdzisz w Panelu współpracownika: <a href="' . $e(APP_URL . '/panel/apply.php') . '">' . $e(APP_URL . '/panel/apply.php') . '</a>.</p>';
        $id = mail_queue_add((string)$app['user_email'], (string)$app['user_name'], 'Potwierdzenie złożenia wniosku ' . $app['doc_number'], $html, '',
            'application_upz', $appId, '', true,
            [['path' => $rel, 'name' => basename($rel), 'mime' => 'application/pdf', 'size' => strlen($pdf)]]);
        $st = $id ? db_one("SELECT status FROM mail_queue WHERE id = ?", [$id]) : null;
        // wysłany od razu albo czeka w kolejce na ponowienie — w obu przypadkach drugi raz nie dokładamy
        if ($id) db()->prepare("UPDATE user_applications SET upz_mailed_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $appId]);
        if (($st['status'] ?? '') !== 'sent') error_log('[application_documents] UPZ #' . $appId . ' — e-mail nie wyszedł od razu (kolejka ponowi).');
    }
    return $rel;
}

/** Łączy kilka PDF (stringi) w jeden — mPDF + FPDI. Jeden plik zwraca bez zmian. */
function appDocsMergePdfs(array $pdfs): ?string {
    if (!$pdfs) return null;
    if (count($pdfs) === 1) return $pdfs[0];
    try {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmp]);
        foreach ($pdfs as $pdf) {
            $n = $m->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));
            for ($i = 1; $i <= $n; $i++) {
                $tplId = $m->importPage($i);
                $size  = $m->getTemplateSize($tplId);
                $m->AddPageByArray(['orientation' => $size['orientation'] ?? 'P', 'sheet-size' => [$size['width'], $size['height']]]);
                $m->useTemplate($tplId);
            }
        }
        return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        error_log('[application_documents] scalanie PDF: ' . $e->getMessage());
        return $pdfs[0];
    }
}

/** Dane przesyłki RPW powiązanej z wnioskiem (numer do wyświetlenia) albo null. */
function appDocsRpw(array $app): ?array {
    if (empty($app['ezd_rpw_id'])) return null;
    try {
        $r = db_one("SELECT id, rpw_nr, rok, data_wplywu, status FROM ezd_rpw WHERE id = ?", [(int)$app['ezd_rpw_id']]);
        return $r ? $r + ['label' => 'RPW ' . $r['rpw_nr'] . '/' . $r['rok']] : null;
    } catch (\Throwable $e) { return null; }
}

/** Adres potwierdzenia złożenia (HTML) lub jego PDF. */
function appDocsReceiptUrl(int $id, bool $pdf = false): string {
    return APP_URL . '/modules/application_documents/router.php/' . $id . '/potwierdzenie' . ($pdf ? '/pdf' : '');
}

/**
 * Potwierdzenie złożenia wniosku — układ formularza urzędowego (jak CIT-8/UPO): kod wzoru w ramce,
 * sekcje A–D na szarym pasie, numerowane pola w ramkach. Tabele, żeby mPDF odwzorował układ.
 */
function appDocsReceiptHtml(array $app, array $tpl): string {
    $hash   = (string)($app['doc_hash'] ?: appDocsHash($app));
    $valid  = hash_equals($hash, appDocsHash($app));
    $rpw    = appDocsRpw($app);
    $f      = json_decode((string)($app['fields_json'] ?? '{}'), true) ?: [];
    $org    = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $n = 0;
    $cell = function (string $label, string $value, int $span = 1, bool $mono = false) use (&$n) {
        $n++;
        return '<td class="rc-c" colspan="' . $span . '"><div class="rc-l">' . $n . '. ' . h($label) . '</div>'
             . '<div class="rc-v' . ($mono ? ' rc-mono' : '') . '">' . ($value !== '' ? h($value) : '&nbsp;') . '</div></td>';
    };
    ob_start(); ?>
<table class="rc-top"><tr>
  <td class="rc-code"><div class="rc-code-k">UPZ</div><div class="rc-code-s">potwierdzenie<br>złożenia</div></td>
  <td class="rc-head"><div class="rc-h1">POTWIERDZENIE ZŁOŻENIA WNIOSKU DROGĄ ELEKTRONICZNĄ</div>
    <div class="rc-h2">wzór <?= h($tpl['code']) ?> · <?= h(mb_strtolower($tpl['title'])) ?></div></td>
  <td class="rc-stamp"><div class="rc-l">Status</div><div class="rc-ok"><?= $valid ? 'PRZYJĘTO' : 'NIEZGODNA SUMA' ?></div></td>
</tr></table>

<div class="rc-sec">A. DANE DOKUMENTU</div>
<table class="rc-t"><tr><?= $cell('Kod wzoru', $tpl['code']) ?><?= $cell('Numer wniosku', (string)($app['doc_number'] ?: appDocsNumber($app, $tpl)), 1, true) ?><?= $cell('Data i godzina złożenia', date('d.m.Y, H:i:s', strtotime((string)$app['created_at']))) ?></tr>
<tr><?= $cell('Tytuł wniosku', (string)$app['tytul'], 3) ?></tr></table>

<div class="rc-sec">B. DANE WNIOSKODAWCY</div>
<table class="rc-t"><tr><?= $cell('Imię i nazwisko lub nazwa', trim((string)($f['wnioskodawca'] ?? '')) ?: (string)$app['user_name'], 2) ?><?= $cell('Konto w SZO (login)', (string)($app['user_email'] ?? '')) ?></tr>
<tr><?= $cell('Adres zamieszkania lub siedziby', (string)($f['adres'] ?? ''), 2) ?><?= $cell('Adres IP przy złożeniu', (string)($app['submitted_ip'] ?? '')) ?></tr></table>

<div class="rc-sec">C. DANE ODBIORCY</div>
<table class="rc-t"><tr><?= $cell('Nazwa odbiorcy', $org, 2) ?><?= $cell('Numer w rejestrze przesyłek (EZD)', $rpw['label'] ?? 'oczekuje na rejestrację', 1, true) ?></tr>
<tr><?= $cell('Adresat wskazany we wniosku', preg_replace('/\s*\R\s*/', ', ', trim((string)($f['organ'] ?? ''))), 2) ?><?= $cell('Sposób złożenia', 'elektronicznie — Panel współpracownika') ?></tr></table>

<div class="rc-sec">D. SUMA KONTROLNA DOKUMENTU</div>
<table class="rc-t"><tr><?= $cell('Algorytm', 'SHA-256') ?><?= $cell('Wartość skrótu treści wniosku', appDocsHashPretty($hash), 2, true) ?></tr></table>

<p class="rc-note">Potwierdzenie wygenerowane automatycznie przez System Wspomagania Zarządzania Organizacją (SZO). Potwierdza wpływ wniosku
o podanym numerze i treści o wskazanej sumie kontrolnej. Wniosek złożono po uwierzytelnieniu w SZO — w miejsce podpisu własnoręcznego.</p>
<?php
    return (string)ob_get_clean();
}

/** Styl potwierdzenia (podgląd i mPDF). */
function appDocsReceiptCss(): string {
    return 'body{font-family:"DejaVu Sans","Segoe UI",Arial,sans-serif;font-size:9pt;color:#111}'
        . 'table{border-collapse:collapse;width:100%}'
        . '.rc-top td{border:1.2pt solid #111;vertical-align:middle;padding:5pt 7pt}'
        . '.rc-code{width:22%;text-align:center;background:#111;color:#fff}.rc-code-k{font-size:20pt;font-weight:bold;letter-spacing:2pt}.rc-code-s{font-size:7pt;text-transform:uppercase;letter-spacing:.6pt}'
        . '.rc-h1{font-size:11.5pt;font-weight:bold;letter-spacing:.3pt}.rc-h2{font-size:8pt;color:#444;margin-top:2pt}'
        . '.rc-stamp{width:18%;text-align:center}.rc-ok{font-size:11pt;font-weight:bold;color:#007a00;margin-top:2pt}'
        . '.rc-sec{background:#d9d9d9;border:1.2pt solid #111;border-top:0;font-weight:bold;font-size:8.5pt;padding:3pt 7pt;letter-spacing:.4pt;margin-top:8pt}'
        . '.rc-t{margin-top:0}.rc-c{border:1pt solid #111;border-top:0;vertical-align:top;padding:2pt 5pt 4pt;background:#fff}'
        . '.rc-l{font-size:6.8pt;color:#333}.rc-v{font-size:10pt;font-weight:bold;min-height:12pt;margin-top:2pt;word-wrap:break-word}'
        . '.rc-mono{font-family:"DejaVu Sans Mono",monospace;font-size:9pt}.rc-note{font-size:7.5pt;color:#444;margin-top:12pt;line-height:1.45}';
}

/** PDF potwierdzenia albo null. */
function appDocsReceiptPdf(array $app, array $tpl): ?string {
    try {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14,
                                'margin_top' => 14, 'margin_bottom' => 14, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $mpdf->SetTitle('Potwierdzenie złożenia ' . ($app['doc_number'] ?: appDocsNumber($app, $tpl)));
        $mpdf->WriteHTML(appDocsReceiptCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(appDocsReceiptHtml($app, $tpl), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        error_log('[application_documents] potwierdzenie: ' . $e->getMessage());
        return null;
    }
}
