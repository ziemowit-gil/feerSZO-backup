<?php
/**
 * modules/it_portal/lib/itPortalAnkieterSeed.php — usługa „Ankieter” w Portalu Usług IT (/uslugi-it/uslugi/ankieter).
 * Polecenie 2026-11-14: dokumentacja IT systemu ankiet i formularzy Ankieter (https://formularz.feer.org.pl, Laravel 13 na MyDevil),
 * z naciskiem na logowanie jednokrotne przez Logto, „w feerSZO pod /uslugi-it”.
 * 2026-11-15 (repozytorium ankieter, commit 10b2f19): instrukcja IT ze zrzutami (przycisk SSO „Logowanie jednokrotne”, stan Logto,
 * nowy objaw w problemach) i nowa instrukcja dla prowadzących ankiety (docs/uslugi-it/ankieter-uzytkownik.md).
 * Fakty (adresy, ścieżki, polecenia) bez zmian, forma wg zasad pomocy: bez tabel i myślników, nazwy przycisków w „cudzysłowie”,
 * polecenia w <pre><code>. Zrzuty: modules/it_portal/lib/img/ank-*.jpg (itPortalCloudShot kopiuje je do uploads/it_portal/).
 * 2026-11-15 (commit d7f7361): szczegółowa dokumentacja, Ankieter jako odpowiednik Microsoft Forms, MySQL na produkcji, role Respondent/Autor/
 * Administrator, nowe funkcje (udostępnianie, zaproszenia z edytorem, wydruk kodów, QR, dokończ później, mail po wysłaniu); 28 zrzutów.
 * Artykuły: „Dla wszystkich” (4 części instrukcji dla prowadzących) i „Dla IT” (7 artykułów, it_only = 1: administratorzy i rola IT).
 * Bezpieczny do ponownego uruchomienia; „Zobacz też” między artykułami Ankietera jest dopisywane, cudze powiązania zostają.
 * Używają: migracje '2026-11_it_portal_ankieter*', cli/it_portal_ankieter_seed.php.
 */

/** @return list<string> */
function itPortalAnkieterApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $f = 'https://formularz.feer.org.pl';
    $b = $app . '/uslugi-it/uslugi/ankieter/';
    $intro = itPortalSanitizeHtml('<p>Ankieter to system ankiet i formularzy Fundacji pod adresem <a href="' . $f . '">formularz.feer.org.pl</a>. '
        . 'Ankiety służą do badań, a formularze zbierają zgłoszenia, na przykład zapisy na szkolenia.</p>'
        . '<p>To nasz odpowiednik Microsoft Forms. Instrukcję dla prowadzących ankiety zacznij od artykułu '
        . '<a href="' . $b . 'instrukcja">Ankieter: pierwsze kroki i porównanie z Microsoft Forms</a>. Artykuły w grupie „Dla IT” są dla działu IT.</p>');

    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'ankieter'");
    if ($dryRun) {
        $rep[] = 'usługa ankieter: ' . ($cat ? 'aktualizacja wstępu' : 'nowa');
        foreach (itPortalAnkieterArticles($app, true) as $slug => [$title]) $rep[] = "ankieter/{$slug} — {$title}";
        return $rep;
    }
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Ankieter', 'slug' => 'ankieter', 'icon' => 'document', 'sort_order' => 27, 'is_active' => 1,
            'tile_group' => 'Pliki, dokumenty i zadania',
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }

    $ids = []; $i = 0;
    foreach (itPortalAnkieterArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'it_only' => $group === 'Dla IT' ? 1 : 0, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
        $rep[] = "ankieter/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    // „Zobacz też”: każdy artykuł Ankietera wskazuje pozostałe
    foreach ($ids as $id) {
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$id]);
        $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), array_values(array_diff($ids, [$id])))));
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $id]);
    }
    return $rep;
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty, grupa w menu].
 * Treść: repozytorium ankieter, commit d7f7361 (docs/uslugi-it/ankieter-uzytkownik.md i ankieter.md), 2026-11-15.
 * Tabele z plików źródłowych zamienione na listy (zasada pomocy: bez tabel). $noShots: bez kopiowania zrzutów (--dry-run).
 */
function itPortalAnkieterArticles(string $app, bool $noShots = false): array {
    if (!function_exists('itPortalCloudShot')) require_once __DIR__ . '/itPortalCloudSeed.php';
    $app = rtrim($app, '/');
    $f  = 'https://formularz.feer.org.pl';
    $b  = $app . '/uslugi-it/uslugi/ankieter/';
    $hd = $app . '/helpdesk/new.php';
    $it = '<a href="https://it.feer.org.pl">it.feer.org.pl</a>';
    $c   = static fn(string $s): string => '<code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code>';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $box = static fn(string $kind, string $h): array => ['type' => 'callout', 'kind' => $kind, 'html' => $h];
    $img = static fn(string $name, string $alt): array => $noShots ? [] : itPortalCloudShot('ank-' . $name, $alt);
    $ul  = static fn(array $items): string => '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    $a   = static fn(string $slug, string $title): string => '<a href="' . $b . $slug . '">' . $title . '</a>';
    $g1 = 'Dla wszystkich'; $g2 = 'Dla IT';

    $aStart  = $a('instrukcja', 'Ankieter: pierwsze kroki i porównanie z Microsoft Forms');
    $aKreator = $a('kreator-i-logika', 'Ankieter: kreator pytań, warunki i przejścia');
    $aUdost  = $a('udostepnianie-i-zaproszenia', 'Ankieter: udostępnianie, zaproszenia i współpraca');
    $aWyniki = $a('wyniki-i-rodo', 'Ankieter: wypełnianie, wyniki, RODO i częste pytania');
    $aSso    = $a('logowanie-sso-logto', 'Ankieter: logowanie jednokrotne (SSO) przez Logto');
    $aSerwer = $a('serwer-i-wdrozenie', 'Ankieter: serwer MyDevil, cron i wdrożenie');
    $aKopie  = $a('kopie-i-bezpieczenstwo', 'Ankieter: kopie zapasowe, przywracanie i bezpieczeństwo');

    return [
        // ═══ Dla wszystkich: docs/uslugi-it/ankieter-uzytkownik.md ═══

        // rozdziały 1 do 4
        'instrukcja' => ['Ankieter: pierwsze kroki i porównanie z Microsoft Forms',
            'ankieter, ankieta, ankiety, formularz, formularze, formularz.feer.org.pl, microsoft forms, forms, odpowiednik, zamiast forms, logowanie, role, '
            . 'respondent, autor, administrator, pulpit, lista ankiet, szablon, wzór, nps, ocena szkolenia, zapisy, formularz kontaktowy, duplikuj, szkic', array_merge(
            [$t('<p>Ankieter to system ankiet i formularzy Fundacji FEER pod adresem <a href="' . $f . '">' . $f . '</a>. '
                . 'To nasz odpowiednik Microsoft Forms: robi to samo, ale działa na serwerze Fundacji, nie wysyła danych do zewnętrznych firm, '
                . 'nie ma skryptów śledzących i jest dostępny dla osób z niepełnosprawnościami (WCAG 2.2 AA).</p>'
                . '<p>Instrukcja ma cztery części: ten artykuł, ' . $aKreator . ', ' . $aUdost . ' oraz ' . $aWyniki . '. '
                . 'Jeśli czegoś tu brakuje, napisz do IT (' . $it . ').</p>'),
             $t('<h2>1. Ankieter a Microsoft Forms</h2>'
                . '<p>Jeśli znasz Microsoft Forms, w Ankieterze odnajdziesz się od razu. Nazwy są trochę inne:</p>'
                . $ul([
                    'Formularz albo test w Forms to w Ankieterze ankieta (badanie opinii, wyniki na wykresach) albo formularz (zgłoszenia, na przykład zapisy, lista odpowiedzi).',
                    'Sekcja to sekcja, czyli jeden krok ankiety.',
                    'Rozgałęzienie („Przejdź do”) to przejścia po pytaniu i po sekcji oraz warunki wyświetlenia z grupami „i” albo „lub”.',
                    'Motyw to wygląd: tło, kolor i własne zdjęcie.',
                    'Ustawienie, kto może odpowiadać, to dostęp: każdy z linkiem, wspólne hasło, tylko zalogowani, tylko zaproszeni.',
                    'Kod QR to kod QR, plakat A4 oraz paski z osobistymi kodami do wydruku.',
                    '„Udostępnij do współpracy” to „Kto ma dostęp w panelu”: „może edytować” albo „widzi wyniki”.',
                    'Potwierdzenie e-mail to mail po wysłaniu z kopią odpowiedzi.',
                    '„Otwórz w Excelu” to eksport do XLSX, ODS, CSV i JSON z wyborem pól.',
                ])
                . '<p>Czego Microsoft Forms nie ma, a Ankieter tak: osobiste linki i kody działające raz, przypomnienia, które unieważniają stary link, '
                . '„dokończ później” z linkiem w mailu, klauzula RODO z zapisem zgód, zapisane kwerendy i pełna obsługa czytników ekranu.</p>'),
             $t('<h2>2. Logowanie i role</h2>'
                . '<p>Wejdź na <a href="' . $f . '/admin">' . $f . '/admin</a>.</p>'
                . $ul([
                    '„Logowanie jednokrotne” (przycisk na górze): e-mail w Fundacji i hasło logowania jednokrotnego, to samo co do SZO i chmury. Tak loguje się większość osób.',
                    '„Albo hasłem”: e-mail i hasło konta założonego ręcznie przez administratora Ankietera.',
                ]))],
            $img('01-logowanie.jpg', 'Strona logowania'),
            [$t('<p>Po zalogowaniu Ankieter sprawdza Twoją rolę:</p>'
                . $ul([
                    'Respondent tylko wypełnia ankiety „dla zalogowanych”. Nie widzi panelu.',
                    'Autor tworzy własne ankiety i formularze i udostępnia je innym autorom. Widzi tylko swoje i te, które ktoś mu udostępnił.',
                    'Administrator widzi wszystkie ankiety, zarządza kontami, logowaniem i pocztą.',
                ])
                . '<p>Rolę nadaje administrator albo przychodzi ona z logowania jednokrotnego (rola w systemie kont Fundacji). '
                . 'Jeśli po zalogowaniu widzisz komunikat o braku uprawnień, poproś IT o rolę „Autor”.</p>'),
             $t('<h2>3. Pulpit i lista ankiet</h2>'
                . '<p>Pulpit to strona startowa panelu. Na górze są kafelki do tworzenia, niżej ostatnio edytowane ankiety z wyszukiwarką, '
                . 'a na dole liczby i wykres odpowiedzi z ostatnich 14 dni.</p>')],
            $img('02-pulpit.jpg', 'Pulpit z szablonami i ostatnimi ankietami'),
            [$t('<p>W menu z lewej masz „Ankiety”, „Formularze” i „Wszystkie”. Lista pokazuje tytuł, adres, rodzaj, stan, liczbę odpowiedzi '
                . 'i datę ostatniej zmiany. Przy ankietach, które ktoś Ci udostępnił, jest dopisek „Właściciel: …”.</p>')],
            $img('03-lista.jpg', 'Lista ankiet i formularzy'),
            [$t('<p>Znaczki przy tytule (legenda jest nad listą):</p>'
                . $ul([
                    'pełna kropka: zbiera odpowiedzi; puste kółko: szkic; kwadrat: zakończona;',
                    'kłódka: wspólne hasło; ludzik: tylko zalogowani; koperta: tylko zaproszeni; kula ziemska: każdy z linkiem.',
                ])),
             $t('<h2>4. Nowa ankieta lub formularz</h2>'
                . '<p>Na pulpicie kliknij jeden z kafelków:</p>'
                . $ul([
                    '„Pusta ankieta” albo „Pusty formularz”: zaczynasz od zera.',
                    '„Szybkie NPS”: jedno pytanie „czy polecisz” (od 0 do 10) i jedno „dlaczego”.',
                    '„Ocena szkolenia”: oceny, siatka, tematy i gotowa logika warunkowa.',
                    '„Zapisy na wydarzenie”: dane uczestnika, termin, potrzeby, zgoda.',
                    '„Formularz kontaktowy”: imię, e-mail, temat, wiadomość.',
                    '„Wzory FEER”: ankiety, które ktoś z zespołu oznaczył jako wzór (zobacz „Treści i RODO” w artykule ' . $aUdost . ').',
                ])
                . '<p>Nowa ankieta jest szkicem. Nikt jej nie zobaczy, dopóki jej nie opublikujesz.</p>'
                . '<p>„Duplikuj” (na liście i w nagłówku ankiety) robi kopię z pytaniami, ustawieniami i kwerendami, ale bez odpowiedzi. '
                . 'Tak przygotujesz kolejną edycję tego samego szkolenia.</p>')],
        ), false, $g1],

        // rozdziały 5 i 6
        'kreator-i-logika' => ['Ankieter: kreator pytań, warunki i przejścia',
            'ankieter, kreator, sekcja, sekcje, pytanie, pytania, rodzaje pytań, krótki tekst, długi tekst, jednokrotny wybór, wielokrotny wybór, lista rozwijana, '
            . 'skala liniowa, nps, siatka, macierz, data, przesyłanie pliku, załącznik, blok informacyjny, wymagane, format, pesel, nip, wzorzec, '
            . 'wersja robocza, opublikuj, podgląd, logika, warunek, warunki, przejście, przejdź do, rozgałęzienie, po tej sekcji, zakończ ankietę', array_merge(
            [$t('<h2>5. Kreator: sekcje i pytania</h2><p>Zakładka „Kreator” to miejsce, gdzie układasz treść.</p>')],
            $img('04-kreator.jpg', 'Kreator ankiety z sekcjami i pytaniami'),
            [$t('<h3>Sekcje</h3>'
                . '<p>Sekcja to jeden krok, czyli jedna „strona” ankiety. Osoba wypełniająca widzi pasek „Krok 2 z 4”. Sekcja ma tytuł i opcjonalny opis. '
                . 'Kolejność sekcji i pytań zmieniasz strzałkami (działa też z klawiatury). „Dodaj sekcję” jest na dole kreatora.</p>'),
             $t('<h3>Rodzaje pytań</h3>'
                . $ul([
                    '„Krótki tekst”: imię, e-mail, telefon, PESEL, NIP, krótka odpowiedź.',
                    '„Długi tekst”: uwagi, opis, uzasadnienie.',
                    '„Jednokrotny wybór”: jedna odpowiedź z listy (przyciski).',
                    '„Wielokrotny wybór”: kilka odpowiedzi; można ustawić minimum i maksimum zaznaczeń.',
                    '„Lista rozwijana”: jedna odpowiedź z długiej listy.',
                    '„Skala liniowa”: na przykład od 1 do 5, z opisami końców.',
                    '„NPS (od 0 do 10)”: „jak bardzo prawdopodobne jest, że polecisz…”.',
                    '„Siatka jednokrotnego wyboru”: kilka wierszy ocenianych w tej samej skali.',
                    '„Siatka wielokrotnego wyboru”: jak wyżej, ale wiele kolumn w wierszu.',
                    '„Data”: kalendarz, zapis RRRR-MM-DD.',
                    '„Przesyłanie pliku”: załącznik, z limitem rozmiaru i typów (na przykład pdf, jpg).',
                    '„Blok informacyjny”: sam tekst bez odpowiedzi, na przykład wyjaśnienie przed pytaniami.',
                ])),
             $t('<h3>Ustawienia pytania</h3>'
                . '<p>Kliknij pytanie, żeby je rozwinąć. Ustawisz tam:</p>'
                . $ul([
                    'treść pytania i podpowiedź pod nią;',
                    '„Wymagane”: bez odpowiedzi nie da się przejść dalej;',
                    'format (krótki tekst): e-mail, telefon, PESEL, NIP albo własny wzorzec. Ankieter sprawdza sumy kontrolne PESEL i NIP, więc literówka nie przejdzie;',
                    'długość tekstu (od, do) i liczbę zaznaczeń przy wielokrotnym wyborze;',
                    'opcje, wiersze i kolumny dla pytań z wyborem i siatek;',
                    'logikę: krótkie podsumowanie („Pokazywane warunkowo · przejścia: 1”) i przycisk „Ustaw warunki i przejścia”, '
                    . 'który otwiera okno z warunkiem wyświetlenia i przejściami (rozdział 6 niżej).',
                ])
                . '<p>„Duplikuj” przy pytaniu robi jego kopię, „Usuń” je usuwa.</p>'),
             $t('<h3>Wersja robocza, publikacja i podgląd</h3>'
                . $ul([
                    '„Zapisz wersję roboczą” zapisuje zmiany, ale osoby wypełniające ich jeszcze nie widzą.',
                    '„Opublikuj” udostępnia nową wersję. Wcześniejsze odpowiedzi zostają w wynikach.',
                    'Nad kreatorem pojawia się lista problemów (na przykład puste pytanie albo warunek na nieistniejące pytanie). Dopóki są problemy, publikacja jest zablokowana.',
                    '„Podgląd” pokazuje ankietę tak, jak zobaczy ją respondent. Możesz klikać do woli, nic się nie zapisze.',
                ])
                . '<p>Tło, kolor oraz strony przed i po ankiecie ustawiasz w zakładce „Udostępnianie i ustawienia” (' . $aUdost . ').</p>'),
             $t('<h2>6. Logika: warunki i przejścia</h2>'
                . '<p>Logika pozwala pokazać każdemu tylko to, co go dotyczy. Są trzy narzędzia:</p><ol>'
                . '<li>„Warunek wyświetlenia” przy pytaniu: pytanie pojawia się tylko po określonych odpowiedziach.</li>'
                . '<li>„Po odpowiedzi przejdź do…” przy pytaniu: po danej odpowiedzi przeskocz do dalszego pytania, innej sekcji albo od razu zakończ ankietę.</li>'
                . '<li>„Po tej sekcji” pod sekcją: reguły przejścia po całej sekcji i to, co dzieje się „w pozostałych przypadkach”.</li></ol>'
                . '<p>Warunki zawsze dotyczą wcześniejszych pytań, a przejścia prowadzą tylko do przodu. Dzięki temu ankieta nie może się zapętlić. '
                . 'Odpowiedzi na pytania, których osoba nie widziała, nie są zapisywane.</p>'),
             $t('<h3>Warunek wyświetlenia</h3>'
                . '<p>Rozwiń pytanie i kliknij „Ustaw warunki i przejścia”. Otworzy się okno z dwiema częściami: „Warunek wyświetlenia (jeśli…)” '
                . 'i „Po odpowiedzi przejdź do…”. Okno zamkniesz przyciskiem „Gotowe”, krzyżykiem albo klawiszem Esc; zmiany zapisuje potem „Zapisz wersję roboczą”.</p>'
                . '<p>W części „Warunek wyświetlenia (jeśli…)” kliknij „Dodaj warunek”. Wybierz pytanie, rodzaj warunku i wartość. Kilka warunków łączysz trybem:</p>'
                . $ul(['„wszystkie poniższe (i)”: muszą być spełnione wszystkie;', '„dowolny z poniższych (lub)”: wystarczy jeden.'])
                . '<p>„Dodaj grupę (i / lub)” tworzy grupę z własnym trybem. Tak zbudujesz warunek w rodzaju '
                . '„(ocena najwyżej 6 i był na szkoleniu) lub data szkolenia przed 1 stycznia”.</p>')],
            $img('21-warunki-grupy.jpg', 'Warunek z grupą „lub”'),
            [$t('<p>Dostępne warunki:</p>'
                . $ul([
                    '„jest równe” albo „jest różne od”: odpowiedź jest (albo nie jest) dokładnie tą wartością. Przykład: „Czy byłeś?” jest równe „Nie”.',
                    '„zawiera” albo „nie zawiera”: przy wielokrotnym wyborze zaznaczono (albo nie) daną opcję; przy tekście: zawiera fragment. Przykład: tematy zawierają „Dostępność”.',
                    '„zaczyna się od”: tekst zaczyna się od podanego fragmentu, wielkość liter bez znaczenia. Przykład: kod pocztowy zaczyna się od „00”.',
                    '„jest większe” albo „mniejsze” (także „lub równe”): porównanie liczb, na przykład skali i NPS. Przykład: NPS jest mniejsze lub równe 6.',
                    '„zaznaczono co najmniej” albo „najwyżej”: liczba zaznaczonych odpowiedzi w wielokrotnym wyborze. Przykład: zaznaczono co najmniej 3.',
                    '„data jest przed” albo „po”: porównanie dat z pytania „Data”. Przykład: data szkolenia po 2026-12-01.',
                    '„ma odpowiedź” albo „nie ma odpowiedzi”: pytanie zostało (albo nie) wypełnione. Przykład: telefon ma odpowiedź.',
                ])
                . '<p>Warunek na pytanie bez odpowiedzi jest niespełniony (poza „nie ma odpowiedzi”).</p>'),
             $t('<h3>Po odpowiedzi przejdź do…</h3>'
                . '<p>W oknie „Ustaw warunki i przejścia”, w części „Po odpowiedzi przejdź do…”, kliknij „Dodaj przejście”. '
                . 'Ustaw warunek (domyślnie dotyczy tego pytania) i cel:</p>'
                . $ul([
                    '„Pytanie: …”: dalsze pytanie w tej samej albo innej sekcji. Pytania pomiędzy są pomijane;',
                    '„Sekcja: …”: początek dalszej sekcji;',
                    '„Zakończ ankietę (wyślij odpowiedzi)”: osoba od razu widzi przycisk wysyłki.',
                ]))],
            $img('22-przejscia-po-pytaniu.jpg', 'Przejście po pytaniu do końca ankiety'),
            [$t('<p>Przejść może być kilka. Ankieter sprawdza je po kolei i wykonuje pierwsze pasujące.</p>'
                . '<h3>Po tej sekcji</h3>'
                . '<p>Pod każdą sekcją jest ramka „Po tej sekcji” z regułami przejścia (takimi samymi jak wyżej, także z grupami) i polem '
                . '„W pozostałych przypadkach”: następna sekcja, wybrana sekcja, wybrane pytanie albo koniec ankiety.</p>'
                . '<h3>Przykłady</h3>'
                . $ul([
                    'Osoby, które nie były na szkoleniu, odpowiadają na inne pytania. Przy „Czy byłeś(-aś) na szkoleniu?” dodaj przejście: jest równe „Nie”, cel: sekcja „Brak udziału”.',
                    'Dopytaj krytyków. Przy pytaniu „Co poprawić?” ustaw warunek wyświetlenia: NPS jest mniejsze lub równe 6.',
                    'Zakończ ankietę osobom spoza grupy docelowej. Przy „Wiek” dodaj przejście: jest mniejsze niż 18, cel: „Zakończ ankietę”.',
                    'Pomiń pytania o nocleg. Przy „Czy potrzebujesz noclegu?” przejście: jest równe „Nie”, cel: pytanie „Uwagi” (pytania o nocleg zostaną pominięte).',
                ]))],
        ), false, $g1],

        // rozdziały 7 do 9
        'udostepnianie-i-zaproszenia' => ['Ankieter: udostępnianie, zaproszenia i współpraca',
            'ankieter, udostępnianie, ustawienia, link, kod qr, qr, plakat, plakat a4, pdf, adres ankiety, dostęp, każdy z linkiem, wspólne hasło, tylko zalogowani, '
            . 'tylko zaproszeni, anonimowe, limit, zakończ, dokończ później, wygląd, tło, kolor, zdjęcie, strona startowa, wstęp, podziękowanie, przekierowanie, '
            . 'rodo, klauzula, zgody, wzór, potwierdzenie, mail po wysłaniu, kopia odpowiedzi, powiadomienia, zaproszenia, przypomnienie, wydruk, kody dostępu, '
            . 'linki, współpraca, udostępnij, kto ma dostęp, widzi wyniki, może edytować, zabierz dostęp', array_merge(
            [$t('<h2>7. Udostępnianie i ustawienia</h2>'
                . '<p>Zakładka „Udostępnianie i ustawienia” zbiera wszystko poza treścią pytań. Po zmianach kliknij „Zapisz” na dole ramki „Ustawienia”.</p>'
                . '<h3>Link, kod QR i plakat</h3>'
                . '<p>Na górze jest link do ankiety z przyciskiem „Kopiuj” (oraz „Kopiuj link” w nagłówku każdej zakładki) i kod QR z tym samym adresem.</p>')],
            $img('13-qr-link.jpg', 'Link, kod QR i plakat'),
            [$t($ul([
                    '„Pobierz QR (SVG)”: plik do wstawienia w ulotkę albo prezentację. SVG skaluje się bez utraty jakości.',
                    '„Plakat A4 z kodem QR”: gotowa strona z tytułem, opisem, dużym kodem i adresem. Kliknij „Drukuj lub zapisz jako PDF” '
                    . 'i w oknie drukowania wybierz drukarkę albo „Zapisz jako PDF”.',
                ]))],
            $img('19-plakat-a4.jpg', 'Plakat A4 z kodem QR'),
            [$t('<h3>Podstawowe</h3>'
                . '<p>Tytuł, opis, adres (końcówka linku, na przykład ' . $c('ocena-szkolenia') . ': małe litery, cyfry i myślniki) oraz rodzaj: '
                . 'ankieta albo formularz. Zmiana adresu sprawia, że stary link przestaje działać.</p>')],
            $img('05-link.jpg', 'Link i podstawowe ustawienia'),
            [$t('<h3>Dostęp</h3>'
                . '<p>„Status”: szkic, zbiera odpowiedzi albo zakończona. „Kto może wypełnić”:</p>'
                . $ul([
                    '„Każdy z linkiem”: wystarczy link albo kod QR. Dobre do otwartych badań i zapisów.',
                    '„Wspólne hasło”: link i hasło, które podajesz uczestnikom. Dobre do ankiety dla grupy na sali.',
                    '„Tylko zalogowani”: logowanie kontem Fundacji, jedno wypełnienie na konto. Dobre do ankiet wewnętrznych dla zespołu.',
                    '„Tylko zaproszeni”: osobisty link albo kod, który działa raz (rozdział 8 niżej). Dobre do badań z listą uczestników.',
                ])
                . '<p>„Anonimowe odpowiedzi”: odpowiedź nie jest łączona z osobą, nawet przy logowaniu i zaproszeniach.</p>')],
            $img('06-dostep.jpg', 'Ustawienia dostępu i zbierania odpowiedzi'),
            [$t('<h3>Zbieranie</h3>'
                . $ul([
                    '„Limit”: po tylu odpowiedziach ankieta sama się zamknie (na przykład liczba miejsc na szkoleniu);',
                    '„Zakończ”: data i godzina zamknięcia;',
                    '„Można przerwać i wrócić później”: odpowiedzi zapisują się w przeglądarce, a pod pytaniami pojawia się przycisk „Dokończ później” '
                    . 'z prośbą o wysłanie linku (' . $aWyniki . ').',
                ])
                . '<h3>Wygląd</h3>'
                . $ul([
                    '„Tło”: fale, kropki, gładkie albo własne zdjęcie (JPG, PNG albo WEBP, do 4 MB, najlepiej poziome, od 1600 pikseli szerokości);',
                    '„Kolor”: niebieski, morski, fioletowy, bursztynowy, różany albo grafitowy. Zmienia pasek nad pytaniami i odcień tła.',
                ])
                . '<p>Pytania zawsze leżą na białej karcie, więc tło nie pogarsza czytelności.</p>'
                . '<h3>Strona startowa</h3>'
                . '<p>Zaznacz „Pokaż stronę startową przed pytaniami”. Osoba najpierw zobaczy tytuł, opis i tekst z pola „Wstęp”, '
                . 'a pytania dopiero po kliknięciu przycisku. Napis na przycisku ustawisz sam (domyślnie „Rozpocznij”).</p>')],
            $img('14-wyglad-start.jpg', 'Wygląd i strona startowa'),
            [$t('<h3>Treści i RODO, strona końcowa</h3>'
                . $ul([
                    '„Wstęp”: tekst nad pierwszym pytaniem albo na stronie startowej;',
                    '„Po wysłaniu”: „Pokaż podziękowanie” (własny nagłówek, treść i przycisk z linkiem, na przykład „Wróć na feer.org.pl”) '
                    . 'albo „Przekieruj na stronę” (osoba od razu trafia pod podany adres);',
                    '„Klauzula informacyjna RODO”: pojawia się nad przyciskiem wysyłki, do rozwinięcia. „Wstaw wzór” wstawia wzór Fundacji do uzupełnienia;',
                    '„Zgody”: pola do zaznaczenia, wymagane albo dobrowolne. Każda odpowiedź zapamiętuje, na co osoba się zgodziła i jaką treść klauzuli widziała;',
                    '„Udostępnij jako wzór”: ankieta pojawi się na pulpicie jako „Wzór FEER” dla całego zespołu.',
                ])
                . '<p>W polach tekstowych działa pogrubienie (' . $c('**tekst**') . ') i linki (' . $c('[opis](https://adres)') . ').</p>')],
            $img('07-tresci-rodo.jpg', 'Wstęp, podziękowanie, klauzula RODO i zgody'),
            [$t('<h3>Mail po wysłaniu</h3>'
                . '<p>Zaznacz „Wyślij potwierdzenie osobie, która wypełniła” i wybierz pytanie, w którym osoba wpisuje e-mail '
                . '(najlepiej „Krótki tekst” z formatem e-mail). Ustaw temat i treść; możesz użyć pól ' . $c('{tytul}') . ', ' . $c('{opis}') . ', ' . $c('{termin}') . '. '
                . '„Dołącz kopię odpowiedzi” dopisze do maila pytania, które osoba widziała, i jej odpowiedzi (bez załączników).</p>')],
            $img('15-mail-po-wyslaniu.jpg', 'Mail po wysłaniu'),
            [$t('<p>Jeśli osoba pominęła pytanie z adresem albo wpisała błędny adres, mail nie wychodzi, a odpowiedź i tak się zapisuje.</p>'
                . '<h3>Powiadomienia</h3>'
                . '<p>„E-mail o każdym nowym wypełnieniu”: na ten adres przyjdzie informacja o nowej odpowiedzi z linkiem do panelu. '
                . 'Powiadomienie nie zawiera treści odpowiedzi. Jeśli IT włączyło Nextcloud, możesz też zapisywać wyniki w chmurze jako plik XLSX.</p>'),
             $t('<h2>8. Zaproszenia: e-mail, wydruk, kody</h2>'
                . '<p>Ta część pojawia się, gdy w „Dostęp” wybierzesz „Tylko zaproszeni”. U góry są liczniki: linki i kody, wykorzystane, wysłane mailem '
                . 'i bez odpowiedzi. Pod nimi cztery przyciski: „Wyślij zaproszenia”, „Przypomnij”, „Wydruk z kodami QR (PDF)” i „Linki do rozesłania samemu”. '
                . 'Każdy otwiera osobne okno. Okno zamyka krzyżyk, „Anuluj” albo klawisz Esc; po zamknięciu wracasz do przycisku, od którego zaczęłaś(-eś). '
                . 'Jeśli w oknie był błąd (na przykład zły adres e-mail), po przeładowaniu strony okno otworzy się samo, z zaznaczonym polem.</p>'
                . '<h3>Zaproszenia e-mail z własną treścią</h3><ol>'
                . '<li>Kliknij „Wyślij zaproszenia” i wpisz adresy, jeden w wierszu.</li>'
                . '<li>W ramce „Treść wiadomości” popraw temat i treść. Pola ' . $c('{tytul}') . ', ' . $c('{opis}') . ' i ' . $c('{termin}') . ' zamienią się '
                . 'na tytuł, opis i datę zakończenia. Przycisk z osobistym linkiem dodaje się sam pod treścią.</li>'
                . '<li>Kliknij „Wyślij próbę do mnie”, żeby zobaczyć maila u siebie (przycisk w próbie prowadzi do podglądu).</li>'
                . '<li>Kliknij „Wyślij zaproszenia”.</li></ol>')],
            $img('16-zaproszenia-edytor.jpg', 'Edytor treści zaproszenia'),
            [$t('<p>„Zapisz treść” zapisuje tekst bez wysyłki, a „Przywróć domyślną” wraca do tekstu Ankietera. Osoby, które już dostały zaproszenie, są pomijane.</p>'
                . '<h3>Przypomnienia</h3>'
                . '<p>„Przypomnij osobom bez odpowiedzi” ma osobną treść (też z próbą). Każda osoba dostaje nowy link, a stary przestaje działać. '
                . 'To celowe: w bazie są tylko skróty linków, nie same linki.</p>'
                . '<h3>Zaproszenia do wydruku (PDF)</h3>'
                . '<p>Do zajęć stacjonarnych, gdy nie masz adresów e-mail. Wpisz liczbę i opcjonalnie grupę (na przykład „szkolenie 12.12”), kliknij „Przygotuj wydruk”. '
                . 'Dostaniesz paski do pocięcia, a na każdym:</p>'
                . $ul(['kod QR z osobistym linkiem,', 'nazwę i adres ankiety,', 'kod dostępu do wpisania, na przykład ' . $c('U57R-EU3R-H9') . ' (bez liter i cyfr, które łatwo pomylić).']))],
            $img('17-przypomnienie-wydruk.jpg', 'Zaproszenia do wydruku'),
            [$t('<p>Kliknij „Drukuj lub zapisz jako PDF”. Kody pokazują się tylko raz: wydrukuj albo zapisz PDF od razu. Każdy kod działa raz.</p>')],
            $img('20-wydruk-paski.jpg', 'Paski z kodami QR i kodami dostępu'),
            [$t('<h3>Linki do rozesłania samemu</h3>'
                . '<p>„Generuj” tworzy listę osobistych linków do wklejenia na przykład do innego systemu mailingowego. Lista też pokazuje się tylko raz.</p>'
                . '<h2>9. Współpraca z innymi autorami</h2>'
                . '<p>Na dole zakładki „Udostępnianie i ustawienia” jest ramka „Kto ma dostęp w panelu” z listą osób. Właściciel ankiety (albo administrator) '
                . 'klika „Udostępnij komuś”, w oknie wpisuje e-mail osoby z rolą „Autor” i wybiera uprawnienia:</p>'
                . $ul([
                    '„Widzi wyniki”: podsumowanie, odpowiedzi, kwerendy i eksport, bez zmian w treści;',
                    '„Może edytować”: także kreator, ustawienia i zaproszenia.',
                ]))],
            $img('18-kto-ma-dostep.jpg', 'Kto ma dostęp w panelu'),
            [$t('<p>Osoba z udostępnieniem nie może udostępnić ankiety dalej ani jej usunąć. „Zabierz dostęp” cofa udostępnienie od razu.</p>')],
        ), false, $g1],

        // rozdziały 10 do 13
        'wyniki-i-rodo' => ['Ankieter: wypełnianie, wyniki, RODO i częste pytania',
            'ankieter, respondent, osoba wypełniająca, strona startowa, kod dostępu, dokończ później, wyniki, podsumowanie, wykresy, szybki filtr, odpowiedzi, '
            . 'zgłoszenia, kwerendy, eksport, excel, xlsx, ods, csv, json, rodo, dane osobowe, usuwanie ankiety, zakończona, pytania, faq, test, punktacja, link nie działa', array_merge(
            [$t('<h2>10. Co widzi osoba wypełniająca</h2>'
                . '<p>Ankieta działa na każdym urządzeniu, także na telefonie, z klawiaturą i z czytnikiem ekranu.</p>'
                . '<p>Jeśli włączysz stronę startową, osoba najpierw widzi wstęp i przycisk.</p>')],
            $img('11-strona-startowa.jpg', 'Strona startowa'),
            [$t('<p>Pasek u góry pokazuje krok, a błędy pojawiają się przy polu i w podsumowaniu nad formularzem.</p>')],
            $img('08-ankieta-respondent.jpg', 'Ankieta oczami respondenta'),
            [$t($ul([
                    'Przy ankiecie „Tylko zaproszeni” otwartej bez linku pojawia się pole „Kod dostępu”. Wielkość liter i myślniki w kodzie nie mają znaczenia.',
                    '„Dokończ później”: osoba wpisuje e-mail, a Ankieter zapisuje dotychczasowe odpowiedzi (bez załączników) i wysyła link ważny 30 dni. '
                    . 'Po wysłaniu ankiety zapis jest usuwany.',
                ]))],
            $img('12-dokoncz-pozniej.jpg', 'Dokończ później'),
            [$t('<h2>11. Wyniki, odpowiedzi, kwerendy i eksport</h2>'
                . $ul([
                    '„Podsumowanie”: liczba odpowiedzi i wykres dla każdego pytania, pod wykresem „Pokaż jako tabelę”. '
                    . '„Szybki filtr” zawęża wyniki do osób, które w wybranym pytaniu odpowiedziały w określony sposób.',
                ]))],
            $img('09-podsumowanie.jpg', 'Podsumowanie wyników'),
            [$t($ul([
                    '„Odpowiedzi” (w formularzu: „Zgłoszenia”): lista pojedynczych wypełnień ze szczegółami, zgodami i załącznikami. Pojedynczą odpowiedź można usunąć.',
                    '„Kwerendy”: zapisane warunki, na przykład „krytycy (NPS od 0 do 6)” albo „uczestnicy z Warszawy”. Wybierasz je potem w podsumowaniu, odpowiedziach i eksporcie.',
                    '„Eksport”: zaznaczasz pola i format: Excel (XLSX), LibreOffice (ODS), CSV (UTF-8, średnik) albo JSON. '
                    . 'Wartości mogą być etykietami („Tak”) albo kodami („yes”), co ułatwia analizę statystyczną.',
                ]))],
            $img('10-eksport.jpg', 'Eksport wybranych pól'),
            [$t('<h2>12. Dane osobowe i RODO</h2>'
                . $ul([
                    'Pytaj tylko o to, czego naprawdę potrzebujesz. Do opinii o szkoleniu nie potrzeba imienia.',
                    'Jeśli zbierasz dane osobowe, wstaw klauzulę RODO („Wstaw wzór”) i dodaj potrzebne zgody.',
                    'W ankietach anonimowych nie dodawaj pytań o imię czy e-mail.',
                    'Po zakończeniu projektu usuń ankietę z odpowiedziami albo poproś IT o archiwizację.',
                ])),
             $box('warn', '<p>Pliki z eksportu z danymi osobowymi trzymaj tylko w miejscach Fundacji, na przykład w chmurze nc.feer.org.pl, '
                . 'nie na prywatnych dyskach i nie w załącznikach do maili poza Fundację.</p>'),
             $t('<p>Usuwanie ankiety: przycisk na samym dole otwiera okno z pytaniem i liczbą odpowiedzi, które znikną. '
                . 'Jeśli chcesz tylko przestać zbierać odpowiedzi, zmień status na „Zakończona”.</p>'
                . '<h2>13. Najczęstsze pytania</h2>'
                . '<h3>Zmieniłem pytanie po publikacji. Co z odpowiedziami?</h3><p>Zostają. Nowa wersja dotyczy tylko nowych odpowiedzi.</p>'
                . '<h3>Ktoś mówi, że link nie działa.</h3><p>Przy „Tylko zaproszeni” link działa raz. Jeśli osoba jeszcze nie odpowiedziała, '
                . 'wyślij przypomnienie albo wydrukuj nowy kod.</p>'
                . '<h3>Nie widzę ankiety kolegi.</h3><p>Autorzy widzą tylko swoje ankiety. Poproś właściciela o udostępnienie (rozdział 9 w artykule ' . $aUdost . ').</p>'
                . '<h3>Chcę ankietę bez logowania, ale tylko dla jednej grupy.</h3><p>Wybierz „Wspólne hasło” i podaj hasło na sali.</p>'
                . '<h3>Jak zrobić test z punktacją jak w Microsoft Forms?</h3><p>Ankieter nie liczy punktów. Wyniki pobierzesz w eksporcie i policzysz w arkuszu.</p>'
                . '<h3>Gdzie zgłosić błąd albo pomysł?</h3><p>' . $it . '.</p>')],
        ), false, $g1],

        // ═══ Dla IT: docs/uslugi-it/ankieter.md ═══

        // rozdziały 1 do 3
        'ankieter-podstawy' => ['Ankieter: najważniejsze informacje, funkcje i role',
            'ankieter, formularz.feer.org.pl, microsoft forms, mydevil, s89, serwer, katalog, laravel, mysql, sqlite, kolejka, dns, cloudflare, feer-dns, github, testy, '
            . 'funkcje, role, respondent, autor, administrator, is_admin, is_author, formpolicy, uprawnienia, form_shares, visibleTo, użytkownicy', array_merge(
            [$t('<p>Ankieter to system ankiet i formularzy Fundacji FEER pod adresem <a href="' . $f . '">' . $f . '</a>. '
                . 'Zastępuje Microsoft Forms: dane zostają na serwerze Fundacji, nie ma zewnętrznych skryptów, fontów ani trackerów, '
                . 'a interfejs respondenta i panel spełniają WCAG 2.2 AA.</p>'
                . '<p>Instrukcja dla osób prowadzących ankiety zaczyna się w artykule ' . $aStart . '.</p>'),
             $t('<h2>1. Najważniejsze informacje</h2>'
                . $ul([
                    'Adres: <a href="' . $f . '">' . $f . '</a> (panel: ' . $c('/admin') . ', logowanie: ' . $c('/logowanie') . ', ankiety: ' . $c('/f/<adres>') . ').',
                    'Serwer: MyDevil s89 (' . $c('s89.mydevil.net') . '), konto ' . $c('feer') . ', IP WWW ' . $c('212.91.26.67') . '.',
                    'Katalog: ' . $c('~/domains/formularz.feer.org.pl/app') . '; ' . $c('public_html') . ' to dowiązanie do ' . $c('app/public') . '.',
                    'Technologia: Laravel 13, PHP 8.4, Blade, Alpine.js i Tailwind CSS 4 (assety z Vite, bez CDN).',
                    'Baza danych: MySQL na MyDevil (dane dostępowe w ' . $c('app/.env') . '). Lokalnie i w testach SQLite w trybie WAL.',
                    'Kolejka: sterownik ' . $c('database') . ', przetwarzana z crona co minutę.',
                    'DNS: ' . $c('formularz.feer.org.pl A 212.91.26.67') . ' (Cloudflare, bez proxy), repozytorium ' . $c('feer-dns') . '.',
                    'Certyfikat: Let\'s Encrypt z panelu MyDevil.',
                    'Logowanie: SSO przez Logto (' . $c('logto.feer.org.pl') . '), awaryjnie hasło. Obsługiwane też Authentik, Microsoft 365, Google, Nextcloud, dowolny OIDC.',
                    'Kod: GitHub, prywatne repozytorium ' . $c('ankieter') . ', gałąź ' . $c('main') . '.',
                    'Testy: ' . $c('php artisan test') . ' (PHPUnit, 45 testów).',
                ])),
             $t('<h2>2. Funkcje i odpowiedniki w Microsoft Forms</h2>'
                . $ul([
                    'Rodzaje: ankieta (badanie, wykresy) i formularz (zgłoszenia, lista). W Forms: formularz albo test.',
                    'Pytania: 12 typów: krótki i długi tekst, jednokrotny i wielokrotny wybór, lista, skala, NPS, dwie siatki, data, plik, blok informacyjny. W Forms: podobne typy.',
                    'Walidacja: e-mail, telefon, PESEL i NIP z sumą kontrolną, własny wzorzec, długość, liczba zaznaczeń. W Forms: ograniczona.',
                    'Logika: warunki wyświetlenia z grupami i/lub, 17 operatorów, przejścia po pytaniu i po sekcji do pytania, sekcji albo końca. W Forms: rozgałęzienia.',
                    'Dostęp: publiczny, wspólne hasło, tylko zalogowani (SSO), tylko zaproszeni (link albo kod działa raz). W Forms: wszyscy albo organizacja.',
                    'Zaproszenia: e-mail z edytorem treści, przypomnienia z rotacją linków, paski do wydruku z QR i kodem, listy linków. W Forms: brak.',
                    'QR: kod SVG i plakat A4. W Forms: kod QR.',
                    'Wygląd: wzór tła, 6 kolorów, własne zdjęcie, strona startowa i końcowa z przyciskiem. W Forms: motywy.',
                    'Respondent: wersja robocza w przeglądarce, „dokończ później” z linkiem w mailu, potwierdzenie z kopią odpowiedzi. W Forms: potwierdzenie.',
                    'RODO: klauzula, zgody, zapis zgód i skrótu treści klauzuli przy każdej odpowiedzi. W Forms: brak.',
                    'Wyniki: wykresy z tabelami danych, szybki filtr, zapisane kwerendy, lista odpowiedzi. W Forms: podsumowanie.',
                    'Eksport: XLSX, ODS, CSV (UTF-8 z BOM), JSON; wybór pól, etykiety albo kody. W Forms: Excel.',
                    'Współpraca: role autor i administrator, udostępnianie „widzi wyniki” i „może edytować”. W Forms: współpraca.',
                    'Integracje: Nextcloud (WebDAV), SSO OIDC. W Forms: Microsoft 365.',
                ])),
             $t('<h2>3. Role i uprawnienia</h2>'
                . $ul([
                    'Respondent (' . $c('is_admin=0') . ', ' . $c('is_author=0') . ' w ' . $c('users') . '): brak panelu; wypełnia ankiety „tylko zalogowani”.',
                    'Autor (' . $c('is_author=1') . '): panel; własne ankiety oraz udostępnione mu; bez kont i ustawień.',
                    'Administrator (' . $c('is_admin=1') . '): wszystko, czyli wszystkie ankiety, użytkownicy, SSO, poczta.',
                ]))],
            $img('it-05-role.jpg', 'Lista kont z rolami'),
            [$t('<p>Rolę ustawia się w „Użytkownicy → Dodaj osobę / Edytuj”. Konta SSO dostają role automatycznie z ustawień '
                . '„Ustawienia → Logowanie SSO → Zasady” (' . $aSso . ').</p>')],
            $img('it-06-nowa-osoba.jpg', 'Formularz konta z wyborem roli'),
            [$t('<p>Uprawnienia do pojedynczej ankiety liczy ' . $c('App\Policies\FormPolicy') . ':</p>'
                . $ul([
                    $c('view') . ' (wyniki, odpowiedzi, eksport, kwerendy, podgląd, duplikat, QR, plakat): administrator, właściciel (' . $c('forms.owner_id') . ') albo osoba z wpisem w ' . $c('form_shares') . ';',
                    $c('update') . ' (kreator, ustawienia, zaproszenia, wydruk kodów, usuwanie odpowiedzi): administrator, właściciel albo udostępnienie ' . $c('edit') . ';',
                    $c('manage') . ' (udostępnianie, usunięcie ankiety): tylko administrator i właściciel.',
                ])
                . '<p>Listy w panelu filtruje zakres ' . $c('Form::visibleTo($user)') . '. Trasy są pogrupowane w ' . $c('routes/web.php') . ' według middleware '
                . $c('can:view,form') . ', ' . $c('can:update,form') . ' i ' . $c('can:manage,form') . '; konta i ustawienia mają ' . $c('can:admin') . '.</p>'
                . '<p>Zasady, które warto znać:</p>'
                . $ul([
                    'udostępnić można tylko osobie z dostępem do panelu (autor albo administrator);',
                    'osoba z udostępnieniem nie udostępnia dalej i nie usuwa ankiety;',
                    'ostatniego administratora nie da się usunąć ani zdegradować; nikt nie odbierze roli sam sobie;',
                    'rola z SSO jest tylko nadawana. Odebranie roli w Logto nie odbiera jej w Ankieterze, więc zrób to w „Użytkownicy”.',
                ]))],
        ), false, $g2],

        // rozdział 4
        'logowanie-sso-logto' => ['Ankieter: logowanie jednokrotne (SSO) przez Logto',
            'ankieter, sso, logowanie jednokrotne, logto, logto-admin, oidc, openid connect, client id, client secret, app id, app secret, redirect uri, adres powrotu, '
            . 'ankieter-admin, ankieter-autor, rola autora, sso_author_role, zasady, domeny, grupa administratorów, sso_subject, authentik, microsoft 365, google, nextcloud, pkce', array_merge(
            [$t('<p>Logto działa na MyDevil: konsola <a href="https://logto-admin.feer.org.pl">https://logto-admin.feer.org.pl</a>, '
                . 'punkt logowania <a href="https://logto.feer.org.pl">https://logto.feer.org.pl</a>. Konto ' . $c('administrator') . ' w konsoli to osobne konto '
                . 'zarządzające, niezwiązane z kontami pracowników.</p>'
                . '<h2>Aplikacja w Logto</h2><ol>'
                . '<li>Konsola, „Applications → Create application”, typ „Traditional web”, nazwa ' . $c('Ankieter') . '.</li>'
                . '<li>„Redirect URIs”: ' . $c('https://formularz.feer.org.pl/logowanie/sso/logto/powrot') . '</li>'
                . '<li>„Post sign-out redirect URIs”: ' . $c('https://formularz.feer.org.pl/logowanie') . '</li>'
                . '<li>„CORS allowed origins”: puste (logowanie po stronie serwera).</li>'
                . '<li>Z „Endpoints &amp; Credentials” skopiuj „Issuer endpoint” (' . $c('https://logto.feer.org.pl/oidc') . '), „App ID” i „App secret”.</li></ol>'
                . '<h2>Role w Logto</h2>'
                . $ul([
                    $c('ankieter-admin') . ' (typ User): administratorzy Ankietera;',
                    $c('ankieter-autor') . ' (typ User, do założenia): autorzy tworzący własne ankiety.',
                ])
                . '<p>Rolę przypisujesz w „User management → osoba → Roles”. Ankieter prosi o zakres ' . $c('roles') . ', więc role przychodzą w tokenie w polu ' . $c('roles') . '.</p>'
                . '<h2>Ustawienia w Ankieterze</h2>'
                . '<p>„Ustawienia → Logowanie SSO → Logto”: zaznacz „Pokazuj na stronie logowania”, wpisz napis na przycisku (obecnie „Logowanie jednokrotne”), '
                . 'adres wystawcy, Client ID i Client secret. „Zapisz”, potem „Sprawdź połączenie”: poprawny wynik to „Połączenie działa”.</p>')],
            $img('it-01-sso-logto.jpg', 'Karta Logto w ustawieniach SSO'),
            [$t('<h2>Zasady</h2>'
                . $ul([
                    '„Zakładaj konto przy pierwszym logowaniu”: obecnie włączone. Nowa osoba z SSO dostaje konto respondenta albo rolę z pól niżej.',
                    '„Dozwolone domeny e-mail”: obecnie ' . $c('feer.org.pl') . '. Konta spoza listy nie powstaną.',
                    '„Administratorzy (e-maile)”: obecnie puste. Adresy, które zawsze dostają administratora.',
                    '„Grupa administratorów”: obecnie puste. Nazwa grupy z claimu ' . $c('groups') . ' (Authentik, Nextcloud).',
                    '„Rola administratora (Logto)”: obecnie ' . $c('ankieter-admin') . '. Rola z claimu ' . $c('roles') . '.',
                    '„Rola autora (Logto)”: do ustawienia, na przykład ' . $c('ankieter-autor') . '. Rola albo grupa nadająca autora (' . $c('SSO_AUTHOR_ROLE') . ' w ' . $c('.env') . ').',
                ]))],
            $img('it-02-sso-zasady.jpg', 'Sekcja Zasady'),
            [$box('info', '<p>Stan na 15.11.2026: aplikacja „Ankieter” w Logto ma App ID ' . $c('p6gp60gispy4x3klkmj9v') . ', rola ' . $c('ankieter-admin') . ' istnieje, '
                . 'provider Logto jest włączony, a sekret jest zapisany w zaszyfrowanych ustawieniach.</p>'),
             $t('<h2>Jak to działa</h2>'
                . $ul([
                    'OpenID Connect, Authorization Code z PKCE; sprawdzane są ' . $c('state') . ', ' . $c('nonce') . ', ' . $c('iss') . ', ' . $c('aud') . ' i ' . $c('exp') . '. '
                    . 'Token pobierany jest po TLS bezpośrednio od wystawcy (OIDC Core 3.1.3.7), bez osobnej weryfikacji podpisu.',
                    'Sekret klienta jest szyfrowany kluczem ' . $c('APP_KEY') . ' i nie wraca do formularza ustawień.',
                    'Konto jest wiązane z identyfikatorem osoby w Logto (' . $c('users.sso_subject') . '). Istniejące konto z tym samym e-mailem jest łączone '
                    . 'tylko przy potwierdzonym adresie albo ograniczonych domenach.',
                    'Logowanie hasłem działa dalej jako droga awaryjna.',
                ])
                . '<h2>Inni dostawcy</h2>'
                . '<p>Ten sam ekran obsługuje Authentik (' . $c('https://<authentik>/application/o/<aplikacja>/') . '), Microsoft 365 '
                . '(' . $c('https://login.microsoftonline.com/<ID-dzierżawy>/v2.0') . '), Google, Nextcloud (aplikacja „OIDC Identity Provider”) i dowolnego dostawcę OIDC. '
                . 'Adres powrotu: ' . $c('https://formularz.feer.org.pl/logowanie/sso/<dostawca>/powrot') . '.</p>')],
        ), false, $g2],

        // rozdziały 5 i 9
        'poczta-i-nextcloud' => ['Ankieter: poczta, kolejka i Nextcloud',
            'ankieter, poczta, smtp, smtp.dpoczta.pl, mail, maile, testuj wysyłkę maili, kolejka, failed_jobs, surveyinvitation, responseconfirmation, resumelink, '
            . 'newresponsereceived, zaproszenia, przypomnienia, potwierdzenie, dokończ później, nextcloud, nc.feer.org.pl, xlsx, webdav, syncformtonextcloud, env', array_merge(
            [$t('<h2>Poczta i kolejka</h2>'
                . '<p>„Ustawienia → Poczta e-mail”: serwer SMTP, port, szyfrowanie, użytkownik, hasło (szyfrowane), nadawca. '
                . '„Testuj wysyłkę maili” wysyła wiadomość od razu, bez kolejki, więc błąd widać na ekranie.</p>')],
            $img('it-03-poczta.jpg', 'Ustawienia poczty'),
            [$t('<p>Rodzaje maili:</p>'
                . $ul([
                    'Zaproszenie i przypomnienie (' . $c('SurveyInvitation') . '): do zaproszonych, przez kolejkę; temat i treść z edytora ankiety, przycisk z osobistym linkiem.',
                    'Próba zaproszenia: do zalogowanej osoby, od razu; jak wyżej, link do podglądu, temat z „[PRÓBA]”.',
                    'Potwierdzenie (' . $c('ResponseConfirmation') . '): do osoby wypełniającej (adres z wybranego pytania), przez kolejkę; treść z ustawień, opcjonalnie kopia odpowiedzi.',
                    'Dokończ później (' . $c('ResumeLink') . '): do osoby wypełniającej, od razu; link z tokenem, ważny 30 dni.',
                    'Nowa odpowiedź (' . $c('NewResponseReceived') . '): na adres z ustawień ankiety, przez kolejkę; bez treści odpowiedzi, tylko link do panelu.',
                    'Test poczty: na wskazany adres, od razu; wiadomość testowa.',
                ])
                . '<p>Kolejkę przetwarza cron (' . $aSerwer . '). Jeśli zaproszenia nie wychodzą, a test poczty działa, sprawdź cron i tabelę ' . $c('failed_jobs') . '.</p>'),
             $t('<h2>Nextcloud</h2>'
                . '<p>Wyniki mogą trafiać do ' . $c('nc.feer.org.pl') . ' jako plik XLSX (' . $c('Ankieter/<adres-ankiety>/<adres-ankiety>.xlsx') . '). Konfiguracja w ' . $c('.env') . ':</p>'
                . $pre("NEXTCLOUD_ENABLED=true\nNEXTCLOUD_URL=https://nc.feer.org.pl\nNEXTCLOUD_USERNAME=<konto techniczne>\n"
                    . "NEXTCLOUD_APP_PASSWORD=<hasło aplikacji z Ustawienia → Bezpieczeństwo>\nNEXTCLOUD_FOLDER=Ankieter")
                . '<p>Zapis włącza się dla każdej ankiety osobno albo ręcznie przyciskiem „Wyślij do Nextcloud”. Zadanie ' . $c('SyncFormToNextcloud') . ' jest unikalne '
                . 'i opóźnione o minutę, więc seria odpowiedzi daje jeden zapis.</p>')],
        ), false, $g2],

        // rozdziały 6, 7, 8 i 15
        'model-danych-i-logika' => ['Ankieter: model danych, logika, kody dostępu i kod źródłowy',
            'ankieter, model danych, baza, tabele, forms, schema, form_versions, form_questions, form_shares, form_access_tokens, form_responses, form_answers, '
            . 'form_participations, form_drafts, form_queries, app_settings, logika, formflow, form-runner, computeflow, visible_if, jumps, operatory, schemavalidator, '
            . 'responserulesbuilder, kody dostępu, token, sha-256, qr, bacon-qr-code, plakat, wydruk, kod źródłowy, formpolicy, kontrolery, modal, testy, seeder', array_merge(
            [$t('<h2>Model danych</h2>'
                . '<p>Schemat ankiety (' . $c('forms.schema') . ', JSON) jest źródłem prawdy. Każda publikacja zapisuje migawkę w ' . $c('form_versions') . ' '
                . 'i odświeża projekcję pytań w ' . $c('form_questions') . '. Odpowiedź to JSON w ' . $c('form_responses.answers') . ' oraz wiersze analityczne '
                . 'w ' . $c('form_answers') . ' (szybkie filtry i kwerendy).</p>'
                . $ul([
                    $c('users') . ': konta; ' . $c('is_admin') . ', ' . $c('is_author') . ', ' . $c('sso_subject') . '.',
                    $c('forms') . ': ankieta: treść, ustawienia, wygląd (' . $c('theme_*') . ', ' . $c('background_image') . '), strony (' . $c('start_page') . ', ' . $c('thanks_*') . '), '
                    . 'RODO, treści maili (' . $c('invite_*') . ', ' . $c('remind_*') . ', ' . $c('confirm_*') . ').',
                    $c('form_versions') . ': opublikowane wersje schematu.',
                    $c('form_questions') . ': projekcja pytań do analiz.',
                    $c('form_shares') . ': udostępnienia: ' . $c('form_id') . ', ' . $c('user_id') . ', ' . $c('permission') . ' (' . $c('view') . ' albo ' . $c('edit') . ').',
                    $c('form_access_tokens') . ': linki i kody „tylko zaproszeni”: skrót SHA-256, grupa, e-mail, daty zaproszenia, przypomnienia i użycia.',
                    $c('form_responses') . ': odpowiedzi, zgody, skrót klauzuli, czas wypełniania.',
                    $c('form_answers') . ': odpowiedzi rozbite na wiersze (' . $c('value_key') . ', ' . $c('value_text') . ', ' . $c('value_num') . ').',
                    $c('form_participations') . ': blokada drugiego wypełnienia w trybie „tylko zalogowani”.',
                    $c('form_drafts') . ': „dokończ później”: skrót tokenu, e-mail, odpowiedzi, ' . $c('expires_at') . ' (30 dni).',
                    $c('form_queries') . ': zapisane kwerendy.',
                    $c('app_settings') . ': ustawienia z panelu (SSO, poczta), zaszyfrowane ' . $c('APP_KEY') . '.',
                    $c('jobs') . ', ' . $c('failed_jobs') . ', ' . $c('cache') . ', ' . $c('sessions') . ': infrastruktura Laravel.',
                ])
                . '<p>Pliki: załączniki respondentów w ' . $c('storage/app/private/forms/<id>/') . ', zdjęcia tła w ' . $c('storage/app/private/backgrounds/') . ' '
                . '(serwowane przez trasę ' . $c('/f/<adres>/tlo') . ', bez publicznego dowiązania).</p>'),
             $t('<h2>Logika ankiet</h2>'
                . '<p>Silnik jest zaimplementowany dwa razy, z tą samą semantyką: ' . $c('app/Support/Forms/FormFlow.php') . ' (serwer, źródło prawdy) '
                . 'i ' . $c('computeFlow()') . ' w ' . $c('resources/js/form-runner.js') . ' (przeglądarka). Zmieniaj je zawsze parami.</p>'
                . $ul([
                    'Warunek to drzewo ' . $c('{"all": [...]}') . ', ' . $c('{"any": [...]}') . ', ' . $c('{"not": {...}}') . ' albo liść ' . $c('{"q", "op", "value", "row"?}') . '.',
                    'Operatory: ' . $c('eq') . ', ' . $c('neq') . ', ' . $c('in') . ', ' . $c('not_in') . ', ' . $c('contains') . ', ' . $c('not_contains') . ', ' . $c('starts_with') . ', '
                    . $c('gt') . ', ' . $c('gte') . ', ' . $c('lt') . ', ' . $c('lte') . ', ' . $c('count_gte') . ', ' . $c('count_lte') . ', ' . $c('date_before') . ', '
                    . $c('date_after') . ', ' . $c('answered') . ', ' . $c('not_answered') . '.',
                    'Pytanie może mieć ' . $c('visible_if') . ' i ' . $c('jumps') . ' (' . $c('[{ "if": warunek, "goto": cel }]') . '). Sekcja ma ' . $c('jumps') . ' i ' . $c('next') . '.',
                    'Cel: klucz sekcji, ' . $c('q:<klucz pytania>') . ' albo ' . $c('submit') . '. Walidator (' . $c('SchemaValidator') . ') przepuszcza tylko skoki do przodu, '
                    . 'a silnik dodatkowo nie odwiedza sekcji drugi raz.',
                    'Odpowiedzi „efektywne” pochodzą tylko z widocznych pytań. Serwer liczy ścieżkę od nowa i odrzuca odpowiedzi na pytania ukryte albo pominięte; '
                    . 'walidacja (' . $c('ResponseRulesBuilder') . ') dotyczy tylko widocznych pytań.',
                ]))],
            $img('21-warunki-grupy.jpg', 'Warunek z grupą w kreatorze'),
            [$t('<h2>Zaproszenia, kody dostępu i QR</h2>'
                . $ul([
                    'Linki z zaproszeń: 40 znaków hex. Kody do wydruku: 10 znaków z alfabetu bez ' . $c('0/O') . ' i ' . $c('1/I/L') . ' (' . $c('FormAccessToken::CODE_ALPHABET') . '), '
                    . 'zapisywane jako ' . $c('ABCD-EFGH-JK') . '. Wielkość liter i myślniki są ignorowane.',
                    'W bazie jest tylko skrót SHA-256. Dlatego przypomnienie generuje nowy link, a wydruk i lista linków pokazują się raz.',
                    'Ankieta „tylko zaproszeni” otwarta bez tokenu pokazuje pole „Kod dostępu”; zły kod zwraca 403 z tym samym polem.',
                    'Kody QR generuje biblioteka ' . $c('bacon/bacon-qr-code') . ' (SVG, na serwerze). Trasy: ' . $c('/admin/ankiety/<adres>/qr.svg') . ', ' . $c('/plakat') . ' (A4) '
                    . 'i ' . $c('/wydruk') . ' (paski). PDF powstaje z okna drukowania przeglądarki (style ' . $c('@media print') . ' w ' . $c('app.css') . ').',
                ]))],
            $img('20-wydruk-paski.jpg', 'Paski do wydruku'),
            [$t('<h2>Praca na kodzie</h2>'
                . $ul([
                    $c('app/Support/Forms/') . ': logika (' . $c('FormFlow') . '), walidacja schematu i odpowiedzi, wyniki, kwerendy, szablony.',
                    $c('app/Policies/FormPolicy.php') . ': uprawnienia do ankiet.',
                    $c('app/Http/Controllers/PublicFormController.php') . ': strona respondenta, wysyłka, „dokończ później”, potwierdzenia.',
                    $c('app/Http/Controllers/Admin/') . ': panel: kreator, ustawienia, zaproszenia, wydruk, udostępnianie, wyniki, eksport.',
                    $c('resources/js/form-runner.js') . ': respondent: logika, walidacja na żywo, wersje robocze, fokus i komunikaty dla czytników.',
                    $c('resources/js/form-builder.js') . ': kreator, edytor warunków i przejść.',
                    $c('resources/views/components/modal.blade.php') . ': okna dialogowe: natywny ' . $c('<dialog>') . ' i ' . $c('showModal()') . ', ' . $c('aria-modal') . ', '
                    . $c('aria-labelledby') . '; obsługa w ' . $c('resources/js/app.js') . ' (' . $c('data-modal-open') . ', ' . $c('data-modal-close') . ', powrót fokusu, '
                    . 'otwieranie po błędzie walidacji).',
                    $c('tests/') . ': testy funkcjonalne i jednostkowe (logika, uprawnienia, zaproszenia, RODO, eksport, SSO).',
                ])
                . '<p>Lokalnie:</p>'
                . $pre("composer install\nnpm install\ncp .env.example .env\nphp artisan key:generate\nphp artisan migrate --seed\nphp artisan serve")
                . '<p>Dane testowych kont zapisuje seeder w ' . $c('storage/app/demo-credentials.txt') . '.</p>')],
        ), false, $g2],

        // rozdziały 10 i 11
        'serwer-i-wdrozenie' => ['Ankieter: serwer MyDevil, cron i wdrożenie',
            'ankieter, mydevil, s89, serwer, hosting, devil, php84, open_basedir, htaccess, addtype, ssl, lets encrypt, ssh, hasła awaryjne, ankieter-admin.txt, '
            . 'cron, kolejka, queue, artisan, migrate, wdrożenie, deploy, mydevil.sh, cofnięcie, rsync, composer', [
            $t('<h2>Hosting MyDevil</h2>'
                . $ul([
                    'Wersja PHP dla WWW: linia ' . $c('AddType application/x-httpd-php84 .php') . ' na początku ' . $c('app/public/.htaccess') . '. '
                    . 'Dopisuje ją skrypt wdrożenia; nie dodawaj jej do repozytorium.',
                    $c('open_basedir') . ' obejmuje cały katalog domeny:'
                    . $pre('devil www options formularz.feer.org.pl php_openbasedir /usr/home/feer/domains/formularz.feer.org.pl:/tmp'),
                    'SSL:' . $pre('devil ssl www add 212.91.26.67 le le formularz.feer.org.pl'),
                    'Konsola PHP: zawsze ' . $c('/usr/local/bin/php84') . ', na przykład ' . $c('php84 artisan migrate --force') . '.',
                    'SSH: MyDevil blokuje na kilka minut po wielu połączeniach w krótkim czasie. Łącz polecenia w jedno połączenie.',
                ])),
            $t('<h3>Pliki z hasłami na serwerze</h3>'
                . '<p>Hasła awaryjne leżą w plikach z uprawnieniami ' . $c('600') . ' w katalogu domowym konta ' . $c('feer') . ' '
                . '(na przykład ' . $c('~/ankieter-admin.txt') . ' dla lokalnego administratora). Odczyt: ' . $c("ssh mydevil 'cat ~/ankieter-admin.txt'") . '.</p>'),
            $box('warn', '<p>Nie wklejaj haseł do czatu ani zgłoszeń.</p>'),
            $t('<h3>Zadania cykliczne (cron)</h3>'
                . $pre('* * * * * cd ~/domains/formularz.feer.org.pl/app && /usr/local/bin/php84 artisan queue:work --stop-when-empty --max-time=50 >/dev/null 2>&1')
                . '<p>Wygasłe wersje „dokończ później” są usuwane przy zapisie kolejnej; nie wymagają osobnego zadania.</p>'),
            $t('<h2>Wdrożenie aktualizacji</h2>'
                . '<p>Z katalogu projektu na komputerze z dostępem SSH (host ' . $c('mydevil') . ' w ' . $c('~/.ssh/config') . '):</p><ol>'
                . '<li>Kopia bazy (' . $aKopie . '). Przy zmianach w ' . $c('database/migrations') . ' obowiązkowo.</li>'
                . '<li>' . $c('php artisan test') . ': wszystkie testy muszą przejść.</li>'
                . '<li>' . $c('git push') . ' do ' . $c('main') . '.</li>'
                . '<li>Wdrożenie:' . $pre('./deploy/mydevil.sh')
                . 'Skrypt kolejno: buduje assety (' . $c('npm run build') . '), wysyła kod przez rsync (bez ' . $c('.env') . ', bazy, logów, ' . $c('vendor') . ' '
                . 'i ' . $c('storage/app') . '), dopisuje linię PHP 8.4 do ' . $c('.htaccess') . ', uruchamia ' . $c('composer install --no-dev') . ', '
                . $c('artisan migrate --force') . ', ' . $c('artisan optimize') . ' i ' . $c('queue:restart') . '.</li>'
                . '<li>Sprawdź ' . $c('https://formularz.feer.org.pl/logowanie') . ' i jedną ankietę.</li></ol>'
                . '<p>Cofnięcie: ' . $c('git checkout <poprzedni commit>') . ', ponownie ' . $c('./deploy/mydevil.sh') . '. Jeśli wersja miała migracje, '
                . 'przywróć kopię bazy z punktu 1 (nie cofaj migracji na produkcji bez kopii).</p>'),
        ], false, $g2],

        // rozdziały 12 i 13
        'kopie-i-bezpieczenstwo' => ['Ankieter: kopie zapasowe, przywracanie i bezpieczeństwo',
            'ankieter, kopia zapasowa, backup, mysql, mysqldump, my.cnf, przywracanie, restore, gunzip, załączniki, storage, app_key, env, szyfrowanie, '
            . 'bezpieczeństwo, prywatność, csp, csrf, referrer-policy, limity prób, sha-256, anonimowe ankiety, dokończ później, rodo', [
            $t('<h2>Kopie zapasowe i przywracanie</h2>'
                . '<p>Produkcja (MySQL), na serwerze:</p>'
                . $pre("cd ~/domains/formularz.feer.org.pl/app\nmysqldump --defaults-extra-file=~/backup/.my.cnf --single-transaction <nazwa_bazy> | gzip > ~/backup/ankieter-\$(date +%F).sql.gz\n"
                    . "tar czf ~/backup/ankieter-pliki-\$(date +%F).tgz storage/app/private")
                . '<p>Plik ' . $c('~/backup/.my.cnf') . ' (uprawnienia ' . $c('600') . ') zawiera sekcję ' . $c('[client]') . ' z ' . $c('host') . ', ' . $c('user') . ' i ' . $c('password') . ' '
                . 'z ' . $c('.env') . '; usuń go po zrobieniu kopii. Ostrzeżenie mysqldump o braku uprawnienia ' . $c('PROCESS') . ' dotyczy tylko przestrzeni tabel i nie psuje kopii. '
                . 'Ostatnia kopia przed wdrożeniem: ' . $c('~/backup/ankieter-20261115-0039.sql.gz') . '.</p>'
                . '<p>Przywracanie:</p>'
                . $pre('gunzip -c ~/backup/<plik>.sql.gz | mysql --defaults-extra-file=~/backup/.my.cnf <nazwa_bazy>')),
            $box('warn', '<p>Bez pliku ' . $c('.env') . ', a dokładniej ' . $c('APP_KEY') . ', nie da się odczytać zaszyfrowanych ustawień (sekret SSO, hasło SMTP). '
                . 'Przechowuj kopię ' . $c('.env') . ' osobno, w bezpiecznym miejscu.</p>'),
            $t('<h2>Bezpieczeństwo i prywatność</h2>'
                . $ul([
                    'Brak zewnętrznych skryptów, fontów i trackerów. Nagłówki CSP (w produkcji), ' . $c('Referrer-Policy: no-referrer') . ', ochrona CSRF.',
                    'Limity prób: wyświetlenie i wysyłka ankiety, hasło do ankiety, logowanie, SSO, „dokończ później”, test poczty.',
                    'Tokeny zaproszeń i „dokończ później” tylko jako skrót SHA-256. Hasła do ankiet i kont są hashowane.',
                    'Ankiety anonimowe nie wiążą odpowiedzi z osobą, także przy zaproszeniach i logowaniu.',
                    'Załączniki i zdjęcia tła na prywatnym dysku. Załączniki widzi tylko osoba z uprawnieniem ' . $c('view') . ' do ankiety.',
                    'Powiadomienie o nowej odpowiedzi nie zawiera treści. Potwierdzenie z kopią odpowiedzi idzie tylko na adres podany przez osobę wypełniającą.',
                    '„Dokończ później” zapisuje odpowiedzi na serwerze do 30 dni; po wysłaniu ankiety wpis jest usuwany.',
                ])),
        ], false, $g2],

        // rozdział 14
        'rozwiazywanie-problemow' => ['Ankieter: rozwiązywanie problemów',
            'ankieter, problem, błąd, błąd 500, biała strona, laravel.log, open_basedir, kod php, addtype, nie przychodzą maile, cron, crontab, failed_jobs, '
            . '403, uprawnienia, kod z wydruku, logowanie hasłem, nie udało się połączyć z dostawcą logowania, sesja logowania wygasła, dokończ później, potwierdzenie', [
            $t('<h2>Biała strona albo błąd 500</h2>'
                . '<p>Sprawdź ' . $c('app/storage/logs/laravel.log') . ' oraz uprawnienia ' . $c('storage/') . ' i ' . $c('bootstrap/cache') . '.</p>'
                . '<h2>Komunikat open_basedir</h2>'
                . '<p>Sprawdź ustawienie ' . $c('php_openbasedir') . ' (' . $aSerwer . ').</p>'
                . '<h2>Strona pokazuje kod PHP</h2>'
                . '<p>Brakuje linii ' . $c('AddType … php84') . ' w ' . $c('public/.htaccess') . '. Uruchom wdrożenie ponownie.</p>'
                . '<h2>Nie przychodzą maile</h2>'
                . '<p>Użyj „Testuj wysyłkę maili”, sprawdź cron kolejki (' . $c('crontab -l') . ') i tabelę ' . $c('failed_jobs') . '.</p>'
                . '<h2>Autor nie widzi ankiety kolegi</h2>'
                . '<p>To zamierzone. Właściciel musi ją udostępnić (' . $c('form_shares') . ').</p>'
                . '<h2>403 na stronie ankiety w panelu</h2>'
                . '<p>Ta osoba nie ma uprawnienia ' . $c('view') . ', ' . $c('update') . ' albo ' . $c('manage') . ' do tej ankiety.</p>'
                . '<h2>Kod z wydruku nie działa</h2>'
                . '<p>Kod został już użyty albo to inna ankieta. Sprawdź licznik „Wykorzystane”.</p>'
                . '<h2>„Nie udało się połączyć z dostawcą logowania”</h2>'
                . '<p>Kliknij „Sprawdź połączenie” w ustawieniach SSO. Adres wystawcy musi być identyczny jak w Logto.</p>'
                . '<h2>„Sesja logowania wygasła” przy SSO</h2>'
                . '<p>Adres powrotu w Logto musi pasować dokładnie (https, bez ukośnika na końcu).</p>'
                . '<h2>Logowanie hasłem nie przechodzi</h2>'
                . '<p>Sprawdź, czy w adresie konta w „Użytkownicy” nie ma literówki. Konto SSO nie ma hasła lokalnego.</p>'
                . '<h2>Brak przycisku „Dokończ później”</h2>'
                . '<p>W ankiecie jest wyłączone „Można przerwać i wrócić później”.</p>'
                . '<h2>Potwierdzenie nie wychodzi</h2>'
                . '<p>Wybrane pytanie z e-mailem było pominięte albo puste, albo adres jest błędny.</p>'),
            $box('info', '<p>Nie pomogło? Zgłoś problem w <a href="' . $hd . '">Helpdesku IT</a>.</p>'),
        ], false, $g2],
    ];
}
