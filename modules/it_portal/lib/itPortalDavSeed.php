<?php
/**
 * modules/it_portal/lib/itPortalDavSeed.php — artykuł „Kalendarz i kontakty z chmury w poczcie, telefonie i programie” w usłudze
 * „Chmura plików” (/uslugi-it/uslugi/chmura-plikow/kontakty-i-kalendarz). Polecenie 2026-11-14: „Książka CardDAV, CalDAV, WebDAV
 * Nextcloud + Roundcube”, potem „zrób jakąś integrację”.
 *
 * Fakty sprawdzone 14.11.2026: w Nextcloud (nc.feer.org.pl, NC 35.0.3) zainstalowano aplikacje Kalendarz 6.6.5 i Kontakty 8.10.0;
 * /.well-known/caldav i /.well-known/carddav prowadzą do /remote.php/dav/. Webmail dPoczty (Roundcube z dodatkami dostawcy) ma
 * w Kalendarzu menu przy „Kalendarze” z pozycją „Dodaj kalendarz CalDAV” (pola „Adres URL CalDAV”, „Nazwa użytkownika”, „Hasło”,
 * przycisk „Znajdź kalendarze”, „Zapisz”); kontaktów CardDAV nie obsługuje (brak takiej opcji w Kontaktach i Preferencjach).
 * Integracja w SZO: /kalendarz-kontakty (modules/cloudDav) tworzy hasło aplikacji przez Login Flow v2 i podaje gotowe dane.
 * Decyzja użytkownika: kalendarz w chmurze i kalendarz w dPoczcie opisujemy oba, bez zalecenia.
 * Uruchamia migracja '2026-11_it_portal_cloud_dav_article'. Bezpieczne do ponownego uruchomienia (ten sam adres = nadpisanie treści).
 */

/** @return array{category:string, saved:string} */
function itPortalDavApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    require_once __DIR__ . '/itPortalCloudSeed.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'chmura-plikow'");
    if (!$cat) return ['category' => 'brak usługi „Chmura plików”', 'saved' => ''];
    $catId = (int)$cat['id'];
    $slug = 'kontakty-i-kalendarz';
    [$title, $keywords, $blocks] = itPortalDavArticle($app);
    $old = db_one("SELECT id, sort_order, related, is_published, is_pinned FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep = ['category' => (string)$cat['name'], 'saved' => ($old ? 'nadpisany' : 'nowy') . ": chmura-plikow/{$slug} — {$title}"];
    if ($dryRun) return $rep;

    $find = static fn(string $c, string $a) => db_one("SELECT a.id, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$c, $a]);
    $relPairs = [['chmura-plikow', 'ustawienia-osobiste'], ['chmura-plikow', 'aplikacja-telefon'], ['moja-poczta', 'kalendarz']];
    $rel = [];
    foreach ($relPairs as [$c, $a]) if ($r = $find($c, $a)) $rel[] = (int)$r['id'];
    $id = itPortalArticleSave([
        'title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : 0,
        'sort_order' => $old ? (int)$old['sort_order'] : 45, 'menu_group' => 'Kalendarz i kontakty',
        'related' => array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), $rel))),
    ], $old ? (int)$old['id'] : 0);
    // „Zobacz też” z powrotem: kalendarz w dPoczcie i ustawienia osobiste (hasła aplikacji)
    foreach ([['moja-poczta', 'kalendarz'], ['chmura-plikow', 'ustawienia-osobiste']] as [$c, $a]) {
        $row = $find($c, $a);
        if (!$row) continue;
        $ids = itPortalIds($row['related']);
        if (!in_array($id, $ids, true)) { $ids[] = $id; db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($ids), (int)$row['id']]); }
    }
    return $rep;
}

/** @return array{0:string,1:string,2:array} tytuł, słowa kluczowe, bloki */
function itPortalDavArticle(string $app): array {
    $cloud = itPortalCloudUrl();
    $host  = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    $dav   = $cloud . '/remote.php/dav';
    $szo   = $app . '/kalendarz-kontakty';
    $hd    = $app . '/helpdesk/new.php';
    $base  = $app . '/uslugi-it/uslugi/chmura-plikow/';
    $step  = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    $blocks = [
        ['type' => 'text', 'html' => '<p>W chmurze plików są aplikacje Kalendarz i Kontakty. Otworzysz je z menu aplikacji na górnym pasku chmury. '
            . 'Ten sam kalendarz i te same kontakty możesz mieć też w poczcie dPoczta, w telefonie i w programie na komputerze. '
            . 'Zmiana zrobiona w jednym miejscu pojawi się w pozostałych.</p>'
            . '<p>Kalendarz w poczcie dPoczta to osobny kalendarz. Wydarzenia z poczty nie trafiają same do chmury, a wydarzenia z chmury do poczty. '
            . 'Możesz korzystać z obu. Jeśli chcesz widzieć kalendarz z chmury także w poczcie, podłącz go tak, jak opisuje ten artykuł.</p>'],

        ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Najprościej połączysz urządzenie na stronie <a href="' . $szo . '">Kalendarz i kontakty z chmury</a> w SZO. '
            . 'Wybierasz, gdzie chcesz mieć kalendarz, potwierdzasz dostęp w chmurze, a strona pokazuje gotowe dane do wklejenia. '
            . 'Dla iPhone\'a, iPada i Maca daje profil, który ustawia kalendarz i kontakty sam.</p>'],

        ['type' => 'wizard', 'title' => 'Połączenie przez SZO', 'intro' => '', 'steps' => [
            $step('Otwórz stronę w SZO', '<p>Zaloguj się do SZO i wejdź na stronę <a href="' . $szo . '">Kalendarz i kontakty z chmury</a>. '
                . 'Prowadzi do niej też odnośnik w Systemie Tożsamości, w części Logowanie jednokrotne, przy Twoim koncie w chmurze.</p>'),
            $step('Wybierz urządzenie', '<p>Zaznacz, gdzie chcesz mieć kalendarz i kontakty: w poczcie dPoczta, na iPhonie, iPadzie albo Macu, '
                . 'w telefonie z Androidem albo w programie na komputerze. Kliknij „Połącz z chmurą”.</p>'),
            $step('Udziel dostępu', '<p>W nowej karcie otworzy się chmura. Zaloguj się, jeśli trzeba, i kliknij „Udziel dostępu”. Potem wróć do karty z SZO. '
                . 'Strona sama zauważy, że gotowe.</p>'),
            $step('Przepisz dane', '<p>SZO pokaże adres, nazwę użytkownika i hasło aplikacji, a pod nimi instrukcję dla wybranego urządzenia. '
                . 'Przycisk „Kopiuj” przenosi każde pole do schowka. Hasło widać na stronie przez pół godziny, a SZO go nie zapisuje.</p>'),
        ]],

        ['type' => 'text', 'html' => '<h2>Kalendarz i kontakty z Microsoft 365</h2>'
            . '<p>Microsoft 365 wyłączamy 20 listopada 2026 r. Kalendarze i kontakty z Outlooka przeniesiemy do chmury, a zrobi to administrator. '
            . 'W chmurze pojawi się kalendarz „Kalendarz z Outlooka”, pozostałe kalendarze jako „Outlook:” z dawną nazwą, i książka adresowa „Kontakty z Outlooka”.</p>'
            . '<p>Uczestników spotkań zobaczysz w opisie wydarzenia, bo przy przenoszeniu nie wysyłamy nikomu zaproszeń. Kalendarzy, które udostępnili Ci inni, '
            . 'nie kopiujemy, bo przeniesie je ich właściciel. Jeśli nie masz jeszcze konta w chmurze, kopia poczeka i trafi do chmury, gdy je założysz.</p>'
            . '<p>Stan przenoszenia zobaczysz na stronie <a href="' . $szo . '">Kalendarz i kontakty z chmury</a>. Jest tam też przycisk, którym przeniesiesz wszystko jeszcze raz. '
            . 'Te same wydarzenia i kontakty zostaną wtedy nadpisane, więc nic się nie zdubluje.</p>'],

        ['type' => 'text', 'html' => '<h2>Hasło aplikacji</h2>'
            . '<p>Telefon ani program nie dostaje Twojego hasła do logowania jednokrotnego. Dostaje własne hasło aplikacji, a każde urządzenie powinno mieć osobne. '
            . 'Gdy zgubisz telefon, usuniesz tylko jego hasło, a pozostałe urządzenia będą działać dalej.</p>'
            . '<p>Hasła aplikacji widzisz w chmurze w ustawieniach osobistych, w części Bezpieczeństwo, na liście urządzeń i sesji. '
            . 'Urządzenia połączone przez SZO mają w nazwie dopisek SZO. Przy każdym jest menu, którym usuniesz jego hasło.</p>'
            . '<p>Hasło aplikacji utworzysz też sam, bez SZO. W tej samej części Bezpieczeństwo wpisz nazwę urządzenia, na przykład „Telefon”, '
            . 'i utwórz nowe hasło aplikacji. Chmura pokaże nazwę logowania i hasło tylko raz, więc od razu wpisz je w urządzenie. '
            . 'Więcej o tej części ustawień jest w artykule <a href="' . $base . 'ustawienia-osobiste">Ustawienia osobiste w chmurze</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Poczta dPoczta</h2>'
            . '<p>Kalendarz z chmury dodasz w kalendarzu na <a href="https://dpoczta.pl">dpoczta.pl</a>:</p><ol>'
            . '<li>Otwórz Kalendarz ikoną kalendarza na prawym pasku poczty.</li>'
            . '<li>Kliknij ikonę z trzema kreskami obok nagłówka „Kalendarze” i wybierz „Dodaj kalendarz CalDAV”.</li>'
            . '<li>Wpisz „Adres URL CalDAV”, „Nazwę użytkownika” i „Hasło” ze strony w SZO.</li>'
            . '<li>Kliknij „Znajdź kalendarze”, zaznacz kalendarze, które chcesz widzieć, i kliknij „Zapisz”.</li></ol>'
            . '<p>Adres URL CalDAV wygląda tak: <code>' . $dav . '/calendars/login/</code>. W miejsce słowa login wpisz swój login w chmurze, na przykład jan.kowalski. '
            . 'Jeśli poczta nic nie znajdzie, wpisz adres jednego kalendarza. Adresy wszystkich Twoich kalendarzy pokazuje strona w SZO.</p>'
            . '<p>Kontaktów z chmury poczta dPoczta nie umie pobierać. Kontakty z chmury podłączysz w telefonie i w programie na komputerze. '
            . 'O kalendarzu samej poczty przeczytasz w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/kalendarz">Kalendarz: spotkania, udostępnianie, telefon</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>iPhone, iPad i Mac</h2>'
            . '<p>Na stronie w SZO wybierz iPhone, iPad albo Mac. Po potwierdzeniu dostępu kliknij „Pobierz profil”. Na iPhonie i iPadzie zrób to w przeglądarce Safari na tym urządzeniu. '
            . 'Potem otwórz Ustawienia, stuknij „Pobrano profil” na górze i zainstaluj go. Telefon uprzedzi, że profil nie jest podpisany. Przy takich profilach to normalne. '
            . 'Na Macu otwórz pobrany plik, a potem w Ustawieniach systemowych wyszukaj „Profile” i zainstaluj go stamtąd.</p>'
            . '<p>Konto możesz też dodać ręcznie. W Ustawieniach wybierz Kalendarz, potem Konta, „Dodaj konto”, „Inne” i „Dodaj konto CalDAV”. '
            . 'W nowszych wersjach systemu Kalendarz jest w Ustawieniach w części Aplikacje. Jako serwer wpisz <code>' . $host . '</code>, '
            . 'a potem nazwę użytkownika i hasło aplikacji. Kontakty dodasz tak samo, w części Kontakty, jako konto CardDAV. '
            . 'Jeśli telefon nie znajdzie serwera, wpisz zamiast niego pełny adres <code>' . $dav . '/principals/users/login/</code> ze swoim loginem.</p>'],

        ['type' => 'text', 'html' => '<h2>Telefon z Androidem</h2>'
            . '<p>Android potrzebuje aplikacji DAVx5. W F-Droid jest bezpłatna, w Sklepie Play płatna.</p><ol>'
            . '<li>W DAVx5 stuknij przycisk dodawania konta i wybierz logowanie adresem URL i nazwą użytkownika.</li>'
            . '<li>Jako adres wpisz <code>' . $dav . '</code>, a potem nazwę użytkownika i hasło aplikacji.</li>'
            . '<li>Utwórz konto i zaznacz książki adresowe i kalendarze, które chcesz mieć w telefonie. Pojawią się w aplikacjach Kontakty i Kalendarz.</li></ol>'
            . '<p>Jeśli DAVx5 proponuje logowanie przez Nextcloud, możesz wybrać je zamiast tego. Wpisujesz wtedy tylko adres chmury, logujesz się w przeglądarce '
            . 'i klikasz „Udziel dostępu”, a DAVx5 sam dostaje hasło aplikacji.</p>'],
        ['type' => 'buttons', 'title' => 'Pobierz DAVx5', 'items' => [
            ['label' => 'Pobierz z F-Droid', 'url' => 'https://f-droid.org/packages/at.bitfire.davdroid/', 'note' => 'bezpłatnie'],
            ['label' => 'Pobierz z Google Play', 'url' => 'https://play.google.com/store/apps/details?id=at.bitfire.davdroid', 'note' => 'płatnie'],
        ]],

        ['type' => 'text', 'html' => '<h2>Thunderbird i inne programy</h2>'
            . '<p>W Thunderbirdzie dodaj nowy kalendarz, wybierz kalendarz w sieci i wpisz nazwę użytkownika i adres <code>' . $dav . '</code>. '
            . 'Program sam znajdzie Twoje kalendarze. Kontakty dodasz w Książce adresowej jako książkę adresową CardDAV, z tą samą nazwą użytkownika i tym samym adresem. '
            . 'Gdy program zapyta o hasło, podaj hasło aplikacji.</p>'
            . '<p>Inne programy, które obsługują CalDAV i CardDAV, łączysz tak samo. Gdy program chce adresu konkretnego kalendarza albo książki adresowej, '
            . 'weź go ze strony w SZO. Domyślny kalendarz ma adres <code>' . $dav . '/calendars/login/personal/</code>, '
            . 'a domyślna książka adresowa <code>' . $dav . '/addressbooks/users/login/contacts/</code>.</p>'
            . '<p>Outlook na Windows sam nie łączy się z takim kalendarzem i potrzebuje do tego dodatku. Jeśli z niego korzystasz, '
            . '<a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie wpisuj w telefonie ani w programie hasła do logowania jednokrotnego. Używaj haseł aplikacji. '
            . 'Gdy zgubisz telefon albo przestaniesz używać programu, usuń jego hasło aplikacji w chmurze, w ustawieniach osobistych, w części Bezpieczeństwo.</p>'],
        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Kalendarz albo kontakty się nie łączą? <a href="' . $hd . '">Napisz do Helpdesku IT</a> '
            . 'i podaj, jakie urządzenie łączysz i jaki komunikat się pojawił. Nie wysyłaj nam hasła.</p>'],
    ];
    return ['Kalendarz i kontakty z chmury w poczcie, telefonie i programie',
        'kalendarz, kontakty, książka adresowa, caldav, carddav, synchronizacja, dpoczta, poczta, webmail, roundcube, dodaj kalendarz caldav, '
        . 'telefon, android, davx5, iphone, ipad, mac, profil, mobileconfig, thunderbird, outlook, hasło aplikacji, urządzenie, udziel dostępu, '
        . 'kalendarz-kontakty, nextcloud, ' . $host,
        itPortalCloudPilot($blocks)];
}
