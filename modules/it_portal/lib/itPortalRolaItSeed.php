<?php
/**
 * modules/it_portal/lib/itPortalRolaItSeed.php — artykuł „Rola IT: kto czyta artykuły dla działu IT” w usłudze „Koordynatorzy IT”
 * (/uslugi-it/uslugi/koordynatorzy-it/rola-it). Polecenie 2026-11-14: „dodaj artykuł o roli IT do pomocy”, po wprowadzeniu
 * artykułów „tylko dla IT” (itp_articles.it_only) i roli IT przy koncie (users.it_staff, checkbox „IT” w Zarządzaniu użytkownikami).
 * Artykuł jest otwarty dla wszystkich (it_only = 0), bo prowadzi do niego komunikat przy zablokowanym artykule (public/usluga.php).
 * Prosty język wg zasad pomocy: bez tabel i myślników. Uruchamia migracja '2026-11_it_portal_rola_it_article'.
 */

const ITP_ROLA_IT_SLUG = 'rola-it';

/** @return array{category:string, saved:string} */
function itPortalRolaItApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'koordynatorzy-it'") ?: db_one("SELECT id, name FROM itp_categories WHERE slug = 'helpdesk'");
    if (!$cat) return ['category' => 'brak usługi „Koordynatorzy IT” i „Helpdesk”', 'saved' => ''];
    $catId = (int)$cat['id'];
    [$title, $keywords, $blocks] = itPortalRolaItArticle($app);
    $old = db_one("SELECT id, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, ITP_ROLA_IT_SLUG]);
    $rep = ['category' => (string)$cat['name'], 'saved' => ($old ? 'nadpisany' : 'nowy') . ': ' . ITP_ROLA_IT_SLUG . " — {$title}"];
    if ($dryRun) return $rep;

    $rel = [];
    foreach ([['ankieter', 'ankieter-podstawy'], ['helpdesk', 'jak-zglosic']] as [$cs, $as]) {
        $r = db_one("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cs, $as]);
        if ($r) $rel[] = (int)$r['id'];
    }
    $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_articles WHERE category_id = ?", [$catId])['s'] ?? 10);
    itPortalArticleSave(['title' => $title, 'slug' => ITP_ROLA_IT_SLUG, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0, 'sort_order' => $sort, 'it_only' => 0,
        'related' => array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), $rel)))], $old ? (int)$old['id'] : 0);
    return $rep;
}

/** @return array{0:string,1:string,2:array} tytuł, słowa kluczowe, bloki */
function itPortalRolaItArticle(string $app): array {
    $users = $app . '/tozsamosc/uzytkownicy.php';
    $panel = $app . '/modules/it_portal/index.php';
    $hd    = $app . '/helpdesk/new.php';
    $ank   = $app . '/uslugi-it/uslugi/ankieter';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return ['Rola IT: kto czyta artykuły dla działu IT',
        'rola it, dział it, tylko dla it, artykuł dla działu it, nie widzę treści, brak dostępu, komunikat, zablokowany artykuł, '
        . 'zarządzanie użytkownikami, uprawnienia, checkbox it, it_staff, administrator, pomoc it, dokumentacja techniczna',
        [
            ['type' => 'text', 'html' => '<p>Część artykułów w Pomocy IT jest przeznaczona dla działu IT. Opisują na przykład ustawienia serwera '
                . 'i logowania jednokrotnego w <a href="' . $ank . '">Ankieterze</a>. Treść takiego artykułu widzą administratorzy SZO '
                . 'i osoby, którym nadano rolę IT. Pozostali zobaczą tytuł i komunikat „Artykuł dla działu IT”.</p>'],

            ['type' => 'text', 'html' => '<h2>Widzisz komunikat zamiast treści</h2>'
                . '<p>Jeśli nie jesteś zalogowany(-a) do SZO, kliknij w komunikacie „zaloguj się do SZO”. Po zalogowaniu wrócisz do tego samego artykułu. '
                . 'Gdy masz rolę IT, zobaczysz już jego treść.</p>'
                . '<p>Jeśli jesteś zalogowany(-a), a komunikat mówi, że Twoje konto nie ma roli IT, to tak właśnie jest. '
                . 'Pracujesz w dziale IT albo potrzebujesz tych informacji do pracy? Napisz do <a href="' . $hd . '">Helpdesku IT</a>, '
                . 'a administrator nada Ci rolę albo pomoże w sprawie, której szukasz.</p>'
                . '<p>Bez roli IT takie artykuły nie pokazują się też w wyszukiwarce Pomocy IT, w „Popularnych instrukcjach”, '
                . 'w Czacie Pomocy IT ani w podpowiedziach przy zgłoszeniu w Helpdesku.</p>'],

            ['type' => 'wizard', 'title' => 'Jak nadać rolę IT (dla administratorów)', 'intro' => '<p>Rolę IT nadaje administrator SZO.</p>', 'steps' => [
                $step('Otwórz listę użytkowników', '<p>Wejdź w <a href="' . $users . '">Tożsamość, Zarządzanie użytkownikami</a>.</p>'),
                $step('Znajdź osobę', '<p>Wpisz imię, nazwisko albo adres e-mail w polu wyszukiwania i kliknij „Filtruj”.</p>'),
                $step('Zaznacz „IT”', '<p>Obok listy z rolą konta jest pole wyboru „IT”. Zaznacz je. Zmiana zapisze się od razu i zobaczysz potwierdzenie na górze strony.</p>'),
                $step('Gotowe', '<p>Osoba nie musi logować się ponownie. Wystarczy, że odświeży stronę artykułu.</p>'),
            ]],

            ['type' => 'text', 'html' => '<p>Żeby odebrać rolę, odznacz pole „IT” w tym samym miejscu. Administrator widzi artykuły dla działu IT zawsze, '
                . 'więc jemu roli nadawać nie trzeba. Każda zmiana zapisuje się w historii konta.</p>'],

            ['type' => 'text', 'html' => '<h2>Jak oznaczyć artykuł jako przeznaczony dla IT</h2>'
                . '<p>Otwórz <a href="' . $panel . '">panel Portalu Usług IT</a> (w menu SZO: Obsługa, Portal Usług IT) i wybierz artykuł do edycji. '
                . 'Zaznacz „Tylko dla IT” i zapisz. Od tej chwili treść artykułu zobaczą tylko administratorzy i osoby z rolą IT.</p>'
                . '<p>Tytuł artykułu dalej widać w menu usługi. Dzięki temu każdy wie, że taki artykuł istnieje i do kogo się zwrócić.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Rola IT otwiera tylko artykuły w Pomocy IT. Nie daje dostępu do innych modułów SZO, '
                . 'do serwerów ani do haseł. Te uprawnienia nadaje się osobno.</p>'],
        ]];
}
