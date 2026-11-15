<?php
/**
 * modules/it_portal/lib/itPortalCmsSeed.php — usługa „Strona feer.org.pl (CMS)” w Portalu Usług IT (/uslugi-it/uslugi/strona-cms).
 * Polecenie 2026-11-15: „Do pomocy IT /uslugi-it zrób pomoc do tego … ze zrzutami ekranu” — po zmianach w CMS (repo feer-web):
 * poprawiator dostępności treści (commit 6744bc5d), formularze przez zwykły SMTP zamiast Microsoft Graph i zadanie antyspamowe
 * w formularzach publicznych (commit 53f35d92).
 * Forma wg zasad pomocy: bez tabel i myślników, nazwy przycisków w „cudzysłowie”, polecenia w <pre><code>.
 * Zrzuty: modules/it_portal/lib/img/cms-*.jpg (lokalna kopia CMS w stylu Metro, dane przykładowe; itPortalCloudShot kopiuje je do uploads/it_portal/).
 * 2026-11-15 (polecenie: „Poprawiator to teraz Autonaprawa i w pomocy zrób listę funkcji już opisanych, ale opisz też podstawy CMS-a”):
 * artykuł „poprawiator” → „autonaprawa” (ten sam rekord, nowy adres), trzy artykuły „Podstawy CMS” (logowanie, pulpit i menu; dodawanie
 * i edycja treści; pliki, historia wersji i kosz) ze zrzutami cms-1x-*.jpg oraz „Spis opisanych funkcji CMS” budowany z listy artykułów.
 * Artykuły: „Podstawy” (3), „Dla redakcji” (Autonaprawa, zadanie antyspamowe, spis), „Dla IT” (poczta formularzy przez SMTP, it_only = 1).
 * Bezpieczny do ponownego uruchomienia. Używają: migracje '2026-11_it_portal_cms_docs' i '2026-11_it_portal_cms_basics', cli/it_portal_cms_seed.php.
 */

/** @return list<string> */
function itPortalCmsApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $b = $app . '/uslugi-it/uslugi/strona-cms/';
    $intro = itPortalSanitizeHtml('<p>Strona <a href="https://feer.org.pl">feer.org.pl</a> ma panel (CMS), w którym redakcja dodaje aktualności, strony i działania. '
        . 'Jeśli dopiero zaczynasz, przeczytaj po kolei trzy artykuły z grupy „Podstawy”. Pełną listę instrukcji znajdziesz w '
        . '<a href="' . $b . 'spis-funkcji">Spisie opisanych funkcji CMS</a>. Artykuły w grupie „Dla IT” są dla działu IT.</p>');

    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'strona-cms'");
    if ($dryRun) {
        $rep[] = 'usługa strona-cms: ' . ($cat ? 'aktualizacja wstępu' : 'nowa');
        foreach (itPortalCmsArticles($app, true) as $slug => [$title]) $rep[] = "strona-cms/{$slug} — {$title}";
        return $rep;
    }
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Strona feer.org.pl (CMS)', 'slug' => 'strona-cms', 'icon' => 'globe', 'sort_order' => 28, 'is_active' => 1,
            'tile_group' => 'Pliki, dokumenty i zadania',
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }

    // „Poprawiator” zmienił nazwę na „Autonaprawa”: ten sam artykuł dostaje nowy adres (powiązania i statystyki zostają).
    if (!db_one("SELECT 1 FROM itp_articles WHERE category_id = ? AND slug = 'autonaprawa'", [$catId])) {
        db_exec("UPDATE itp_articles SET slug = 'autonaprawa' WHERE category_id = ? AND slug = 'poprawiator'", [$catId]);
    }

    $ids = []; $i = 0;
    foreach (itPortalCmsArticles($app) as $slug => [$title, $keywords, $blocks, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => in_array($slug, ['podstawy-pulpit-i-menu', 'spis-funkcji'], true) ? 1 : 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'it_only' => $group === 'Dla IT' ? 1 : 0,
            'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
        $rep[] = "strona-cms/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    // „Zobacz też”: artykuły usługi wskazują się nawzajem i artykuł o logowaniu do CMS
    $login = db_one("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = 'moj-login' AND a.slug = 'logowanie-do-cms'");
    $all = $login ? array_merge($ids, [(int)$login['id']]) : $ids;
    foreach ($all as $id) {
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$id]);
        $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), array_values(array_diff($all, [$id])))));
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $id]);
    }
    return $rep;
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, grupa w menu]. $noShots: bez kopiowania zrzutów (--dry-run).
 */
function itPortalCmsArticles(string $app, bool $noShots = false): array {
    if (!function_exists('itPortalCloudShot')) require_once __DIR__ . '/itPortalCloudSeed.php';
    $app = rtrim($app, '/');
    $hd  = $app . '/helpdesk/new.php';
    $cms = 'https://feer.org.pl/admin';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $box = static fn(string $kind, string $h): array => ['type' => 'callout', 'kind' => $kind, 'html' => $h];
    $img = static fn(string $name, string $alt): array => $noShots ? [] : itPortalCloudShot('cms-' . $name, $alt);
    $ul  = static fn(array $items): string => '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    $step = static fn(string $title, string $h): array => ['title' => $title, 'html' => $h, 'images' => []];
    $g0 = 'Podstawy'; $g1 = 'Dla redakcji'; $g2 = 'Dla IT';
    $b   = $app . '/uslugi-it/uslugi/strona-cms/';
    $login = $app . '/uslugi-it/uslugi/moj-login/logowanie-do-cms';
    $a   = static fn(string $slug, string $title): string => '<a href="' . $b . $slug . '">' . $title . '</a>';

    $articles = [
        // ═══ Podstawy: dla każdej osoby z redakcji, w kolejności czytania ═══
        'podstawy-pulpit-i-menu' => ['Podstawy CMS: logowanie, pulpit i menu',
            'podstawy, cms, panel strony, wecms, feer.org.pl/admin, logowanie, pulpit, menu, kafle, start, treści, działalność, komunikacja, '
            . 'dokumenty i bip, ustawienia, system, skróty, ulubione, szukaj, ctrl k, zadania, kalendarz, zobacz stronę, nowa osoba, pierwsze kroki', array_merge(
            [$t('<p>Panel strony feer.org.pl (CMS, nazywa się weCMS) jest pod adresem <a href="' . $cms . '">feer.org.pl/admin</a>. '
                . 'Logujesz się kontem Fundacji, tak samo jak do SZO i chmury: opisuje to artykuł '
                . '<a href="' . $login . '">Logowanie do panelu strony feer.org.pl (CMS)</a>. To, co widzisz w panelu, zależy od Twojej roli: '
                . 'redaktor widzi treści, administrator także ustawienia i użytkowników.</p>'
                . '<h2>Pulpit</h2><p>Po zalogowaniu otwiera się „Pulpit”. Znajdziesz na nim:</p>'
                . $ul([
                    '„Na dziś”: sprawy, które czekają na Twoją decyzję, na przykład treści do zatwierdzenia albo nowe zgłoszenia. Gdy nic nie czeka, widzisz „Wszystko załatwione”.',
                    '„Utwórz”: niebieskie kafle, którymi od razu zaczynasz nową aktualność, stronę, działanie, wydarzenie, szkolenie, kampanię newslettera, dokument BIP albo ogłoszenie wolontariackie.',
                    '„Do dokończenia”: Twoje szkice, czyli treści zapisane, ale jeszcze nieopublikowane. „Dokończ” otwiera je w edytorze.',
                    '„Ostatnia aktywność”: ostatnio zmienione treści.',
                    '„Podgląd strony”: otwiera stronę feer.org.pl w nowej karcie.',
                ]))],
            $img('10-pulpit.jpg', 'Pulpit panelu: powitanie, „Na dziś” z komunikatem „Wszystko załatwione”, niebieskie kafle „Utwórz” i lista „Do dokończenia”'),
            [$t('<h2>Menu</h2><p>Menu jest na ciemnym pasku u góry. Kliknięcie grupy rozwija panel z kaflami, a Esc albo kliknięcie obok go zamyka. '
                . 'Kafel bieżącej strony ma pełny kolor. Grupy:</p>'
                . $ul([
                    '„Start”: pulpit i treści czekające na zatwierdzenie.',
                    '„Treści”: strony i menu nawigacji, aktualności z kategoriami i tagami, podcasty, FAQ, elementy strony głównej (slidery, galeria, szybkie akcje, partnerzy), pliki (Multimedia, Kosz) i kontrola jakości (Przegląd treści, Dostępność treści, Jakość tekstów).',
                    '„Działalność”: działania, szkolenia i wydarzenia, oferta, materiały edukacyjne, zapisy uczestników, sklep, wolontariat i ogłoszenia o pracę.',
                    '„Komunikacja”: wiadomości i zgłoszenia z formularzy, kontakt, formularze i ankiety, newsletter, landing pages, zbiórki i bannery.',
                    '„Dokumenty i BIP”: Biuletyn Informacji Publicznej i sprawozdania.',
                    '„Ustawienia” i „System”: wygląd i działanie strony, użytkownicy, kopie zapasowe. Zwykle tylko dla administratorów.',
                ]))],
            $img('11-menu.jpg', 'Rozwinięta grupa „Treści” w górnym menu: kafle Strony, Menu nawigacji, Aktualności, Podcasty, FAQ, Multimedia, Kosz, Dostępność treści i inne'),
            [$t('<h2>Szybkie przejścia</h2>' . $ul([
                    '„Szukaj…” (albo Ctrl i K) przeszukuje naraz strony z ich treścią, aktualności, pliki, osoby i ustawienia. Strzałkami wybierasz wynik, Enter go otwiera.',
                    '„Skróty” po prawej: wyszukiwarka w menu (klawisz /), Twoje ulubione pozycje i ostatnio otwarte ekrany.',
                    '„Zobacz stronę” otwiera stronę publiczną, „Zadania” i „Kalendarz” pokazują Twoje sprawy i terminy, dzwonek to powiadomienia.',
                    'Ikona z ludzikiem otwiera ułatwienia dostępu panelu: większy tekst, kontrast, odstępy. Ustawienia panelu nie zmieniają wyglądu strony publicznej.',
                    'Litera z Twoim inicjałem w prawym górnym rogu to menu konta: profil i hasło, powiadomienia, wylogowanie. W profilu wybierzesz też wygląd panelu.',
                ])),
             $box('tip', '<p>Zacznij od ' . $a('podstawy-tresci', 'dodawania i edycji treści') . ', a potem przeczytaj o ' . $a('podstawy-pliki-i-wersje', 'plikach, historii wersji i koszu') . '.</p>')],
            ), $g0],

        'podstawy-tresci' => ['Podstawy CMS: dodawanie i edycja treści',
            'aktualność, nowa aktualność, dodaj aktualność, strona, nowa strona, działanie, edytor blokowy, blok, bloki, ukośnik, dodaj blok, '
            . 'nagłówek, akapit, lista, obraz, publikacja, opublikuj, szkic, wersja robocza, podgląd, lead, zdjęcie główne, kategoria, '
            . 'edytuj na stronie, pasek panelu, edytuj, zapisz, zdejmij z publikacji, treść archiwalna', array_merge(
            [$t('<p>Aktualności, strony i działania edytujesz w <strong>edytorze blokowym</strong>. Treść składa się z bloków: akapit, nagłówek, lista, '
                . 'obraz, tabela, przycisk, plik do pobrania, film i inne. Wszystkie trzy rodzaje treści działają tak samo, różnią się polami w panelu po prawej.</p>'
                . '<h2>Lista treści</h2><p>W menu „Treści” wybierz „Aktualności” (albo „Strony”, a dla działań „Działalność”, „Działania”). '
                . 'Lista ma wyszukiwarkę i filtry stanu, kategorii i sortowania. Przy każdej pozycji widać stan: „Opublikowana” z datą albo „Szkic”. '
                . 'Przyciski w wierszu: „Edytuj”, oko (podgląd), kopia (duplikat) i kosz.</p>')],
            $img('12-aktualnosci.jpg', 'Lista aktualności: przycisk „Dodaj aktualność”, wyszukiwarka i filtry, trzy wpisy ze stanem „Opublikowana” albo „Szkic” i przyciskami „Edytuj”, podgląd, duplikat, kosz'),
            [['type' => 'wizard', 'title' => 'Jak dodać aktualność', 'intro' => '', 'steps' => [
                $step('Zacznij nową', '<p>Kliknij „Dodaj aktualność” na liście albo kafel „Aktualność” w „Utwórz” na pulpicie. Otworzy się edytor blokowy z nowym szkicem.</p>'),
                $step('Wpisz tytuł i treść', '<p>Kliknij w tytuł „Nowa aktualność” i wpisz własny. Pod spodem pisz treść. Nowy blok dodasz przyciskiem „+” '
                    . 'albo wpisując „/” na początku pustego wiersza; pojawi się lista bloków do wyboru, na przykład „Nagłówek” albo „Lista”. '
                    . 'Śródtytuły rób blokiem „Nagłówek”, a nie pogrubieniem; czytnik ekranu pozwala wtedy do nich przeskakiwać.</p>'),
                $step('Uzupełnij panel po prawej', '<p>Na karcie „Aktualność”: „Lead (krótki opis)”, który widać na liście aktualności, „Zdjęcie główne” '
                    . '(„Wybierz z biblioteki”) i „Kategoria”. Na karcie „Blok” są ustawienia zaznaczonego bloku, na przykład opis obrazu.</p>'),
                $step('Zapisz szkic i obejrzyj', '<p>„Zapisz” zapisuje zmiany. Dopóki pole „Opublikowana” jest puste, treść jest szkicem i widać ją tylko w panelu. '
                    . '„Podgląd wersji roboczej” pokazuje, jak będzie wyglądać na stronie.</p>'),
                $step('Opublikuj', '<p>Zaznacz „Opublikowana” w części „Publikacja” i kliknij „Zapisz”. Treść pojawi się na stronie. '
                    . 'Jeśli ma zniknąć w określonym dniu (na przykład po końcu naboru), wpisz datę w „Zdejmij z publikacji”.</p>'),
            ]]],
            $img('13-edytor.jpg', 'Edytor blokowy aktualności: tytuł, akapit, nagłówek „Terminy” i lista; u góry przyciski „Dodaj blok”, cofnij, ponów, widok listy, historia wersji, „Dostępność: OK”, „Podgląd wersji roboczej” i „Zapisz”; po prawej panel z „Publikacją”, leadem, zdjęciem głównym i kategorią'),
            [$t('<h2>Przyciski edytora</h2>' . $ul([
                    'Strzałka w lewo: powrót do listy.',
                    '„+”: dodaj blok. Cofnij i ponów działają też z klawiatury (Ctrl i Z, Ctrl i Y).',
                    '„Widok listy”: wszystkie bloki w kolejności, żeby szybko znaleźć ten, którego szukasz. Blok przeniesiesz strzałkami „Przenieś w górę” i „Przenieś w dół” na jego pasku albo przeciągając za uchwyt.',
                    '„Historia wersji”: poprzednie zapisy tej treści (więcej w ' . $a('podstawy-pliki-i-wersje', 'artykule o historii wersji') . ').',
                    '„Dodaj z szablonu”: gotowy układ treści (szablon) wstawiony jednym kliknięciem.',
                    '„Importuj z pliku (DOCX, TXT)”: wczytuje tekst z dokumentu Word albo pliku tekstowego, z nagłówkami i listami.',
                    '„Dostępność: OK” albo liczba uwag: sprawdzenie treści na bieżąco; kliknięcie pokazuje, co poprawić.',
                    '„Wersja robocza” w panelu po prawej: zmiany w opublikowanej treści, które nie są jeszcze widoczne na stronie; opublikujesz je później.',
                    'Ikona klawiatury w prawym górnym rogu: lista skrótów klawiszowych.',
                ])
                . '<h2>Edycja prosto ze strony</h2><p>Gdy jesteś zalogowany(-a) do panelu i otworzysz feer.org.pl, na samej górze strony jest ten sam ciemny pasek co w panelu. '
                . 'Przycisk „Edytuj” pokazuje, co możesz zmienić na oglądanej stronie:</p>'
                . $ul([
                    '„Edytuj na stronie”: poprawiasz tekst bezpośrednio na stronie, bez przechodzenia do panelu.',
                    '„Edytor blokowy”: otwiera tę treść w edytorze blokowym.',
                    '„Edytuj w panelu”: otwiera pełny formularz w panelu.',
                    'Administrator ma tam też „Edytuj nagłówek” i „Edytuj stopkę” całej witryny.',
                ]))],
            $img('16-edytuj-na-stronie.jpg', 'Strona publiczna z paskiem panelu: rozwinięte okienko „Edytuj” z pozycjami „Edytuj na stronie”, „Edytor blokowy”, „Edytuj w panelu”, „Edytuj nagłówek” i „Edytuj stopkę”'),
            [$box('info', '<p>Po zapisie edytor sam sprawdza dostępność treści. Typowe problemy, na przykład pogrubienia zamiast śródtytułów, poprawi za Ciebie '
                . $a('autonaprawa', 'Autonaprawa') . '.</p>')],
            ), $g0],

        'podstawy-pliki-i-wersje' => ['Podstawy CMS: pliki, historia wersji i kosz',
            'multimedia, pliki, biblioteka, wgraj pliki, zdjęcia, obrazy, dokumenty, pdf, foldery, opis alternatywny, alt, dostępność dokumentów, '
            . 'historia zmian, historia wersji, przywróć, pokaż różnice, cofnij zmiany, kosz, usunięte, przywróć z kosza', array_merge(
            [$t('<h2>Pliki (Multimedia)</h2><p>Zdjęcia, dokumenty PDF i inne pliki trzymasz w bibliotece: „Treści”, „Multimedia”. '
                . 'Z tej samej biblioteki wybierasz pliki w edytorze („Wybierz z biblioteki”).</p>'
                . $ul([
                    '„Wgraj pliki” dodaje pliki; możesz też przeciągnąć je na okno.',
                    'Po lewej są foldery (nowy folder wpiszesz w polu „Nowy folder…”) i filtr rodzaju: obrazy, dokumenty, inne.',
                    'Każdemu zdjęciu wpisz opis alternatywny, czyli jedno zdanie, co na nim widać. Bez opisu osoba niewidoma nie wie, co przedstawia.',
                    '„Narzędzia”: „Opisy alternatywne” pokazuje zdjęcia bez opisu, „Dostępność dokumentów” sprawdza pliki PDF i Word, „Porządki” znajduje nieużywane pliki.',
                ]))],
            $img('15-multimedia.jpg', 'Biblioteka plików: przycisk „Wgraj pliki”, wyszukiwarka, po lewej foldery, rodzaje plików i narzędzia „Porządki”, „Dostępność dokumentów”, „Opisy alternatywne”'),
            [$t('<h2>Historia wersji</h2><p>Każdy zapis strony, aktualności albo działania tworzy wersję. Panel przechowuje 30 ostatnich wersji każdej treści. '
                . 'Historię otworzysz w edytorze przyciskiem „Historia wersji” (ikona zegara) albo w formularzu treści.</p>'
                . $ul([
                    'Na liście widać datę, osobę i liczbę zmienionych pól. Ostatnia pozycja to „wersja bieżąca”.',
                    '„Pokaż różnice” pokazuje, co zmieniłoby przywrócenie tej wersji.',
                    '„Przywróć” wraca do tej wersji. Obecna treść nie ginie: przywrócenie samo tworzy nową wersję, więc możesz je też cofnąć.',
                ]))],
            $img('14-historia.jpg', 'Historia zmian strony „O Fundacji”: trzy wersje z datą i autorem, przyciski „Pokaż różnice” i „Przywróć”, ostatnia oznaczona jako wersja bieżąca'),
            [$t('<h2>Kosz</h2><p>Usunięta strona, aktualność, działanie albo dokument BIP nie znika od razu: trafia do kosza („Treści”, „Kosz”). '
                . 'Stamtąd przywrócisz ją jednym przyciskiem albo usuniesz na zawsze. W edytorze usuwa przycisk „Do kosza” w panelu po prawej.</p>'),
             $box('warn', '<p>Usunięcie z kosza jest ostateczne, nie ma go w historii wersji. Jeśli nie wiesz, czy treść jest jeszcze potrzebna, '
                . 'zostaw ją w koszu albo zdejmij z publikacji.</p>')],
            ), $g0],

        'autonaprawa' => ['Autonaprawa: automatyczne poprawki dostępności treści',
            'autonaprawa, poprawiator, dostępność treści, dostępność, wcag, czytnik ekranu, nagłówki, h2, h3, pogrubienie, śródtytuł, lista, myślniki, '
            . 'tabela, nagłówek tabeli, osadzenie, film, youtube, popraw automatycznie, cms, panel strony, feer.org.pl, redakcja', array_merge(
            [$t('<p>W panelu strony feer.org.pl jest raport <strong>Dostępność treści</strong> (menu „Treści”, grupa „Kontrola jakości”). '
                . 'Pokazuje strony, aktualności i działania, w których coś utrudnia czytanie osobom korzystającym z czytnika ekranu: '
                . 'źle ułożone nagłówki, pogrubienia zamiast śródtytułów, listy pisane myślnikami, tabele bez nagłówków, filmy bez tytułu.</p>'
                . '<p><strong>Autonaprawa</strong> poprawia te rzeczy za Ciebie, jednym przyciskiem.</p>')],
            $img('01-autonaprawa.jpg', 'Raport Dostępność treści: liczba błędów i uwag, ramka Autonaprawa z przyciskiem „Popraw automatycznie 3 treści” i lista treści z przyciskami „Popraw automatycznie” i „Edytuj”'),
            [['type' => 'wizard', 'title' => 'Jak użyć Autonaprawy', 'intro' => '', 'steps' => [
                $step('Otwórz raport', '<p>Zaloguj się do <a href="' . $cms . '">panelu strony</a> i wybierz „Treści”, potem „Dostępność treści”.</p>'),
                $step('Zobacz, co zostanie poprawione', '<p>Przy każdej treści jest zdanie <strong>„Autonaprawa zrobi: …”</strong>, na przykład „pogrubienia zamienione na śródtytuły (2), myślniki zamienione na listy (1)”.</p>'),
                $step('Popraw jedną treść albo wszystkie', '<p>Kliknij „Popraw automatycznie” przy wybranej treści albo przycisk w ramce „Autonaprawa”, który poprawi wszystkie treści z widocznej listy. '
                    . 'Jeśli wybierzesz filtr, na przykład „Pogrubienie zamiast nagłówka”, Autonaprawa zrobi tylko tę jedną rzecz.</p>'),
                $step('Popraw resztę ręcznie', '<p>Po poprawkach na górze pojawi się podsumowanie, a na liście zostaną tylko uwagi, których nie da się poprawić automatycznie. '
                    . 'Kliknij „Edytuj” i popraw je w edytorze.</p>'),
            ]]],
            $img('02-po-poprawce.jpg', 'Raport po poprawkach: komunikat „Poprawiono automatycznie 3 treści” i dwie pozostałe uwagi o tekstach linków'),
            [$t('<h2>Co Autonaprawa zmienia</h2>' . $ul([
                    'Usuwa puste nagłówki.',
                    'Układa poziomy nagłówków: treść zaczyna się od „Nagłówka 2”, kolejne poziomy idą po kolei, bez przeskoków, a „Nagłówek 1” w treści zmienia na „Nagłówek 2” (nagłówkiem 1 jest tytuł strony).',
                    'Krótki pogrubiony akapit, który wygląda jak śródtytuł, zamienia na prawdziwy nagłówek. Pod nagłówkiem 2 staje się nagłówkiem 3.',
                    'Kilka akapitów zaczynających się od myślnika albo numeru zamienia na listę punktowaną albo numerowaną.',
                    'W tabeli z co najmniej dwoma wierszami oznacza pierwszy wiersz jako nagłówek kolumn.',
                    'Osadzonemu filmowi albo mapie nadaje tytuł, na przykład „Film YouTube” albo „Mapa Google”.',
                ])
                . '<h2>Czego nie zmienia</h2>'
                . '<p>Tekstów linków (na przykład „kliknij tutaj” albo długi adres zamiast opisu) i opisów obrazów. Tu trzeba wiedzieć, co jest celem linku i co pokazuje obraz, '
                . 'więc decyduje redaktor. Opisy obrazów z biblioteki poprawisz w „Multimedia”, w audycie opisów alternatywnych.</p>'),
             $box('info', '<p><strong>Zmianę da się cofnąć.</strong> Każda poprawiona treść dostaje nową wersję. Jeśli wynik Ci się nie podoba, '
                . 'otwórz treść do edycji, wejdź w historię wersji i przywróć poprzednią. Treści z edytora blokowego Autonaprawa poprawia w blokach, więc bloki zostają.</p>'),
             $box('tip', '<p>Pogrubiony akapit nie zawsze jest śródtytułem. Po poprawkach zajrzyj na stronę i sprawdź, czy nagłówki mają sens. '
                . 'Jeśli coś ma zostać zwykłym pogrubieniem, przywróć poprzednią wersję albo zmień styl akapitu w edytorze.</p>')],
            ), $g1],

        'zadanie-antyspamowe' => ['Zadanie antyspamowe w formularzach strony',
            'antyspam, spam, zadanie antyspamowe, ile wynosi, captcha, formularz, formularz kontaktowy, zgłoszenie bariery, umów spotkanie, '
            . 'dołącz do federacji, współpraca, nie mogę wysłać, błędna odpowiedź, feer.org.pl, strona', array_merge(
            [$t('<p>Formularze na stronie feer.org.pl chronią się przed automatycznym spamem. Na dole formularza jest krótkie pytanie, '
                . 'na przykład <strong>„Zadanie antyspamowe: Ile wynosi 3 + 7?”</strong>. Odpowiedź wpisuje się cyfrą (10) albo słownie (dziesięć).</p>'
                . '<p>To zwykły tekst, a nie obrazek z literami, więc działa z czytnikiem ekranu i na telefonie. Oprócz pytania formularz ma też ukryte zabezpieczenia: '
                . 'odrzuca wysyłkę w ułamku sekundy po otwarciu strony, treść z dużą liczbą linków i dwa takie same zgłoszenia pod rząd.</p>'
                . '<p>Zadanie jest w formularzu kontaktowym, w zgłoszeniu bariery w deklaracji dostępności, w zapisie na spotkanie i „Umów spotkanie”, '
                . 'w „Dołącz do federacji”, w formularzu współpracy, w formularzach z kreatora „Formularze” i w zapisach na szkolenia.</p>')],
            $img('04-antyspam.jpg', 'Formularz „Umów spotkanie” z polem „Zadanie antyspamowe: Ile wynosi 2 + 5?” i czerwonym komunikatem o nieprawidłowej odpowiedzi'),
            [$t('<h2>Gdy ktoś pisze, że nie może wysłać formularza</h2>' . $ul([
                    'Komunikat „Odpowiedź na zadanie antyspamowe jest nieprawidłowa” oznacza zły wynik. Pod spodem jest nowe pytanie: trzeba wpisać nowy wynik, a reszta formularza zostaje wypełniona.',
                    'Komunikat o zbyt wielu odnośnikach: zgłoszenie miało więcej niż dwa adresy internetowe. Trzeba usunąć część adresów.',
                    'Komunikat „To zgłoszenie zostało już wysłane”: ta sama treść poszła drugi raz w ciągu 10 minut. Pierwsza wysyłka doszła.',
                    'Strona otwarta dłużej niż dobę: trzeba ją odświeżyć i wypełnić formularz jeszcze raz.',
                ])),
             $box('info', '<p>Jeśli ktoś mimo to nie może wysłać zgłoszenia, poproś go o kontakt mailem albo telefonicznie, a sprawę zgłoś w <a href="' . $hd . '">Helpdesku IT</a> '
                . '(adres strony z formularzem i godzina). Zablokowane zgłoszenia IT widzi w logu serwera.</p>')],
            ), $g1],

        'poczta-smtp' => ['Poczta z formularzy strony przez SMTP',
            'smtp, poczta, formularze, powiadomienia, mail, e-mail, microsoft graph, graph, msgraph, wysyłka, tryb wysyłki, własny serwer smtp, '
            . 'starttls, port 587, 465, wiadomość testowa, mail_timeout, cms, feer.org.pl, ustawienia poczta, nie przychodzą maile', array_merge(
            [$t('<p>Od 15 listopada 2026 powiadomienia z formularzy strony feer.org.pl nie idą osobno przez Microsoft Graph. '
                . 'Wychodzą tym samym trybem co cała poczta strony, ustawianym w panelu: <strong>„Ustawienia”, „Poczta”, karta „Sposób wysyłki”</strong>. '
                . 'Docelowo jest to zwykły serwer SMTP. Przełącznik „Formularze: powiadomienia przez Graph” i wybór Graph w ustawieniach pojedynczego formularza zostały usunięte; '
                . 'w edycji formularza widać tylko, którym trybem idą powiadomienia.</p>')],
            $img('03-poczta-smtp.jpg', 'Ustawienia, Poczta, karta „Sposób wysyłki”: tryb „Własny serwer SMTP”, adres i nazwa nadawcy, host, port 587, użytkownik, hasło, szyfrowanie TLS (STARTTLS) i sekcja „Wyślij wiadomość testową”'),
            [['type' => 'wizard', 'title' => 'Jak ustawić SMTP', 'intro' => '', 'steps' => [
                $step('Otwórz ustawienia poczty', '<p>W <a href="' . $cms . '">panelu strony</a> wybierz „Ustawienia”, „Poczta” i kartę „Sposób wysyłki”.</p>'),
                $step('Wybierz tryb', '<p>W polu „Tryb wysyłki” wybierz <strong>„Własny serwer SMTP”</strong>. Wpisz „Adres nadawcy” (skrzynka, z której wychodzi poczta) i „Nazwę nadawcy”.</p>'),
                $step('Wpisz dane serwera', '<p>Host, port, użytkownik i hasło skrzynki. Zwykle port 587 z szyfrowaniem „TLS (STARTTLS)” albo port 465 z „SSL”. '
                    . 'Hasło jest zapisane w bazie w postaci zaszyfrowanej; puste pole przy kolejnym zapisie zostawia stare hasło.</p>'),
                $step('Zapisz i sprawdź', '<p>Kliknij „Zapisz zmiany”, a potem w sekcji „Wyślij wiadomość testową” wpisz swój adres i kliknij „Wyślij test”. '
                    . 'Na koniec wyślij próbne zgłoszenie z dowolnego formularza na stronie.</p>'),
            ]]],
            [$t('<h2>Dobrze wiedzieć</h2>' . $ul([
                    'Dopóki „Tryb wysyłki” to „Microsoft 365 (Graph API)”, cała poczta strony, razem z formularzami, idzie dalej przez Graph. Formularze nie mają już osobnego ustawienia.',
                    'Karta „Autokonfigurator” podpowiada host i port po wpisaniu adresu skrzynki. Przy skrzynce Microsoft 365 SMTP działa tylko, gdy skrzynka ma włączone uwierzytelnianie SMTP (SMTP AUTH).',
                    'Połączenie z serwerem SMTP ma limit 15 sekund. Gdy serwer nie odpowiada, zgłoszenie i tak zapisuje się w panelu, a błąd trafia do logu. Limit zmienia zmienna MAIL_TIMEOUT w pliku .env.',
                    'Zamiast ustawień w panelu można użyć pliku .env (tryb „Dziedzicz z serwera (.env)”):',
                ])
                . $pre("MAIL_MAILER=smtp\nMAIL_HOST=smtp.example.org\nMAIL_PORT=587\nMAIL_USERNAME=formularze@feer.org.pl\nMAIL_PASSWORD=…\nMAIL_FROM_ADDRESS=formularze@feer.org.pl\nMAIL_TIMEOUT=15")
                . '<h2>Wdrożenie zmiany</h2><p>Na serwerze strony (h68) w katalogu aplikacji:</p>'
                . $pre('./deploy.sh')
                . '<p>Bez migracji i bez budowania. Potem ustaw SMTP w panelu jak wyżej.</p>'
                . '<h2>Maile nie przychodzą</h2>' . $ul([
                    'Sprawdź „Wyślij test”. Komunikat błędu mówi, co jest nie tak (zły host, port, hasło albo blokada po stronie serwera pocztowego).',
                    'Sprawdź log aplikacji, wpisy „Formularz: nie udało się wysłać powiadomienia o zgłoszeniu”. Są w nich nazwa formularza, numer zgłoszenia i użyty mailer.',
                    'Zgłoszenia nie giną: są w panelu w „Formularze”, w zgłoszeniach danego formularza.',
                ])),
             $box('info', '<p>Antyspam formularzy (zadanie tekstowe i ukryte zabezpieczenia) opisuje artykuł „Zadanie antyspamowe w formularzach strony”. '
                . 'Po stronie kodu to klasa App\\Support\\SpamGuard i fragment widoku partials/spam-protection w repozytorium feer-web.</p>')],
            ), $g2],
    ];

    // ═══ Spis opisanych funkcji: budowany z listy artykułów, więc nowy artykuł pojawia się w nim sam ═══
    $sum = [
        'podstawy-pulpit-i-menu' => 'jak wejść do panelu, co jest na pulpicie, jak działa menu, wyszukiwarka, skróty i menu konta',
        'podstawy-tresci' => 'lista treści, dodawanie aktualności krok po kroku, edytor blokowy, szkic, podgląd, publikacja i edycja prosto ze strony',
        'podstawy-pliki-i-wersje' => 'biblioteka plików i opisy alternatywne, historia wersji z przywracaniem, kosz',
        'autonaprawa' => 'raport „Dostępność treści” i automatyczne poprawki nagłówków, list, tabel i osadzeń',
        'zadanie-antyspamowe' => 'pytanie „Ile wynosi…” w formularzach strony i co zrobić, gdy ktoś nie może wysłać formularza',
        'poczta-smtp' => 'ustawienie wysyłki poczty z formularzy przez serwer SMTP, wiadomość testowa i szukanie błędów (dla IT)',
    ];
    $groups = [];
    foreach ($articles as $slug => $art) {
        $groups[$art[3]][] = '<a href="' . $b . $slug . '">' . $art[0] . '</a>' . (isset($sum[$slug]) ? ': ' . $sum[$slug] . '.' : '');
    }
    $groups[$g0] = array_merge(['<a href="' . $login . '">Logowanie do panelu strony feer.org.pl (CMS)</a> (usługa „Mój login”): logowanie jednokrotne kontem Fundacji.'], $groups[$g0] ?? []);
    $html = '<p>Wszystkie instrukcje do panelu strony feer.org.pl (CMS), które są już w Pomocy IT. Jeśli szukasz czegoś, czego tu nie ma, '
        . 'napisz w <a href="' . $hd . '">Helpdesku IT</a>; dopiszemy instrukcję.</p>';
    foreach ([$g0 => 'Podstawy (czytaj po kolei)', $g1 => 'Funkcje dla redakcji', $g2 => 'Dla działu IT'] as $g => $label) {
        if (!empty($groups[$g])) $html .= '<h2>' . $label . '</h2>' . $ul($groups[$g]);
    }
    $articles['spis-funkcji'] = ['Spis opisanych funkcji CMS',
        'spis, lista, funkcje, instrukcje, co jest opisane, cms, panel strony, feer.org.pl, pomoc, dokumentacja, autonaprawa, podstawy',
        [$t($html)], $g1];

    return $articles;
}
