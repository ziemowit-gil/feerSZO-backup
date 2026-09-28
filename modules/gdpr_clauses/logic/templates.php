<?php
/**
 * modules/gdpr_clauses/logic/templates.php — wbudowane szablony klauzul RODO.
 *
 * Punkt wyjścia do nowej klauzuli („Nowa z szablonu" w edit.php). Szablon
 * kopiuje się do zwykłej klauzuli — późniejsze zmiany tutaj NIE wpływają
 * na klauzule już utworzone. Dane administratora zawsze przez zmienne
 * globalne; to, co różni się między klauzulami (cel, okres przechowywania,
 * odbiorcy), przez zmienne lokalne — tak, żeby treść była wspólna, a
 * szczegóły edytowalne w polach, nie w środku tekstu.
 *
 * Treści są wzorami do weryfikacji przez IOD, nie gotową opinią prawną.
 */

function gdpr_clauses_templates(): array {
    $admin = "## Administrator danych\n"
        . "Administratorem Pani/Pana danych osobowych jest **{{company_name}}** z siedzibą: {{address}}, NIP {{nip}}, KRS {{krs}}. Kontakt z administratorem: {{contact_email}}.\n\n"
        . "## Inspektor Ochrony Danych\n"
        . "Administrator nie wyznaczył Inspektora Ochrony Danych. W sprawach dotyczących przetwarzania danych osobowych można kontaktować się bezpośrednio z administratorem: {{contact_email}}.";
    $rights = "## Przysługujące prawa\n"
        . "Ma Pani/Pan prawo dostępu do swoich danych, ich sprostowania, usunięcia lub ograniczenia przetwarzania, prawo do przenoszenia danych oraz prawo wniesienia sprzeciwu wobec przetwarzania. Jeżeli przetwarzanie odbywa się na podstawie zgody — prawo do jej cofnięcia w dowolnym momencie bez wpływu na zgodność z prawem przetwarzania dokonanego przed cofnięciem.\n\n"
        . "Przysługuje Pani/Panu prawo wniesienia skargi do Prezesa Urzędu Ochrony Danych Osobowych (ul. Stawki 2, 00-193 Warszawa).";
    $intro = "Zgodnie z art. 13 ust. 1 i 2 Rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679 z dnia 27 kwietnia 2016 r. (RODO) informujemy, że:";
    $common = fn(string $purposes, string $extra = '') => $intro . "\n\n" . $admin . "\n\n"
        . "## Cele i podstawy przetwarzania\n" . $purposes . "\n\n"
        . "## Okres przechowywania\nDane będą przechowywane {{okres_przechowywania}}.\n\n"
        . "## Odbiorcy danych\n{{odbiorcy}}\n\n"
        . $rights . "\n\n"
        . "## Informacja o wymogu podania danych\n{{wymog_podania}}"
        . ($extra !== '' ? "\n\n" . $extra : '')
        . "\n\nDane nie będą wykorzystywane do zautomatyzowanego podejmowania decyzji, w tym profilowania.";
    $odbiorcy = 'Odbiorcami danych mogą być podmioty świadczące na rzecz administratora usługi IT, hostingowe i pocztowe — wyłącznie na podstawie umów powierzenia przetwarzania — oraz organy uprawnione na podstawie przepisów prawa.';

    return [
        'rekrutacja' => [
            'tytul' => 'Klauzula informacyjna — rekrutacja',
            'content' => gdpr_clauses_example_recruitment(),
            'local_vars' => [],
        ],
        'wolontariat' => [
            'tytul' => 'Klauzula informacyjna — wolontariat',
            'content' => $common(
                "- zawarcie i wykonanie porozumienia wolontariackiego — art. 6 ust. 1 lit. b RODO,\n"
                . "- wypełnienie obowiązków prawnych, w tym z ustawy o działalności pożytku publicznego i o wolontariacie oraz ustawy o przeciwdziałaniu zagrożeniom przestępczością na tle seksualnym — art. 6 ust. 1 lit. c RODO,\n"
                . "- ustalenie, dochodzenie lub obrona roszczeń — art. 6 ust. 1 lit. f RODO."
            ),
            'local_vars' => [
                'okres_przechowywania' => 'przez okres trwania porozumienia, a następnie przez okres przedawnienia roszczeń (6 lat) lub dłużej, jeśli wymagają tego przepisy o archiwizacji',
                'odbiorcy' => $odbiorcy,
                'wymog_podania' => 'Podanie danych jest warunkiem zawarcia porozumienia wolontariackiego; w zakresie wymaganym przepisami jest obowiązkowe.',
            ],
        ],
        'newsletter' => [
            'tytul' => 'Klauzula informacyjna — newsletter',
            'content' => $common(
                "- wysyłka newslettera z informacjami o działalności administratora — na podstawie zgody (art. 6 ust. 1 lit. a RODO) oraz art. 10 ustawy o świadczeniu usług drogą elektroniczną,\n"
                . "- prowadzenie ewidencji udzielonych i cofniętych zgód — art. 6 ust. 1 lit. c i f RODO."
            ),
            'local_vars' => [
                'okres_przechowywania' => 'do czasu cofnięcia zgody (wypisania się z newslettera), a po tym czasie przez okres przedawnienia ewentualnych roszczeń w zakresie niezbędnym do wykazania udzielenia zgody',
                'odbiorcy' => 'Odbiorcami danych mogą być dostawcy systemów do wysyłki poczty elektronicznej i usług IT, wyłącznie na podstawie umów powierzenia przetwarzania.',
                'wymog_podania' => 'Podanie adresu e-mail jest dobrowolne, ale niezbędne do otrzymywania newslettera. Z subskrypcji można zrezygnować w każdej chwili, klikając link w stopce wiadomości.',
            ],
        ],
        'wydarzenia' => [
            'tytul' => 'Klauzula informacyjna — zapisy na wydarzenia',
            'content' => $common(
                "- rejestracja i organizacja udziału w wydarzeniu, kontakt w sprawach organizacyjnych — art. 6 ust. 1 lit. b RODO,\n"
                . "- rozliczenie wydarzenia, w tym wobec grantodawców — art. 6 ust. 1 lit. c i f RODO,\n"
                . "- utrwalanie i publikacja wizerunku — wyłącznie na podstawie odrębnej zgody (art. 6 ust. 1 lit. a RODO)."
            ),
            'local_vars' => [
                'okres_przechowywania' => 'przez czas niezbędny do organizacji i rozliczenia wydarzenia, a w przypadku projektów dofinansowanych — przez okres wymagany umową o dofinansowanie',
                'odbiorcy' => $odbiorcy . ' W przypadku projektów dofinansowanych — także grantodawcy i instytucje kontrolujące.',
                'wymog_podania' => 'Podanie danych jest dobrowolne, ale niezbędne do zapisania się na wydarzenie.',
            ],
        ],
        'darowizny' => [
            'tytul' => 'Klauzula informacyjna — darowizny',
            'content' => $common(
                "- przyjęcie i obsługa darowizny, wystawienie potwierdzenia — art. 6 ust. 1 lit. b RODO,\n"
                . "- wypełnienie obowiązków podatkowych i rachunkowych — art. 6 ust. 1 lit. c RODO,\n"
                . "- podziękowanie za wsparcie i informowanie o efektach — art. 6 ust. 1 lit. f RODO (prawnie uzasadniony interes administratora)."
            ),
            'local_vars' => [
                'okres_przechowywania' => 'przez 5 lat licząc od końca roku kalendarzowego, w którym przekazano darowiznę (przepisy podatkowe i o rachunkowości)',
                'odbiorcy' => $odbiorcy . ' Także operatorzy płatności oraz biuro rachunkowe.',
                'wymog_podania' => 'Podanie danych jest dobrowolne; bez nich nie jest możliwe wystawienie imiennego potwierdzenia darowizny.',
            ],
        ],
        'kontakt' => [
            'tytul' => 'Klauzula informacyjna — formularz kontaktowy i korespondencja',
            'content' => $common(
                "- odpowiedź na zapytanie i prowadzenie korespondencji — art. 6 ust. 1 lit. f RODO (prawnie uzasadniony interes administratora polegający na komunikacji z osobami, które się z nim kontaktują),\n"
                . "- jeżeli zapytanie dotyczy zawarcia umowy — art. 6 ust. 1 lit. b RODO."
            ),
            'local_vars' => [
                'okres_przechowywania' => 'przez czas niezbędny do załatwienia sprawy, a następnie przez okres przedawnienia ewentualnych roszczeń',
                'odbiorcy' => $odbiorcy,
                'wymog_podania' => 'Podanie danych jest dobrowolne, ale niezbędne do udzielenia odpowiedzi.',
            ],
        ],
        'monitoring' => [
            'tytul' => 'Klauzula informacyjna — monitoring wizyjny',
            'content' => $common(
                "- zapewnienie bezpieczeństwa osób i ochrona mienia na terenie objętym monitoringiem — art. 6 ust. 1 lit. f RODO (prawnie uzasadniony interes administratora)."
                , "## Zakres monitoringu\nMonitoringiem objęty jest: {{obszar_monitoringu}}. Obszar ten jest oznaczony tablicami informacyjnymi."
            ),
            'local_vars' => [
                'okres_przechowywania' => 'nie dłużej niż 3 miesiące od dnia nagrania, chyba że nagranie stanowi dowód w postępowaniu — wtedy do jego prawomocnego zakończenia',
                'odbiorcy' => 'Odbiorcami danych mogą być podmioty obsługujące system monitoringu na podstawie umowy powierzenia oraz organy uprawnione (np. Policja, sąd).',
                'wymog_podania' => 'Wejście na teren objęty monitoringiem oznacza, że wizerunek zostanie zarejestrowany.',
                'obszar_monitoringu' => 'wejście do budynku i korytarze',
            ],
        ],
    ];
}
