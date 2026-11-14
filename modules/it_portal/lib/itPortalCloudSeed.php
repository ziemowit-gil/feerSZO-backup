<?php
/**
 * modules/it_portal/lib/itPortalCloudSeed.php — usługa „Chmura plików” w Portalu Usług IT (/uslugi-it/uslugi/chmura-plikow)
 * z wprowadzeniem i krótkim artykułem o Nextcloud. Polecenie 2026-11-13: „Przygotuj też krótki artykuł o NextCloud + takie wprowadzenie”.
 * Artykuł „instalacja-aplikacji” (polecenie 2026-11-13: instalacja klientów Nextcloud na przykładzie instrukcji CRI AGH, link do niej
 * jako przydatny materiał z dopiskiem, by wpisać adres chmury Fundacji zamiast AGH) wgrywa migracja '2026-11_it_portal_cloud_clients'.
 * Logowanie przez IAM (2026-11-13, „zaktualizuj, że już obsługuje IAM”): Nextcloud loguje przez logowanie jednokrotne (user_oidc,
 * login z customData.nc_uid w Logto) — przycisk na stronie chmury i strona logowania IAM, także w aplikacjach. Ramka w obu
 * artykułach mówi już, że chmura obsługuje IAM (wcześniej: „Docelowo chmurę obsłuży IAM”). Migracja '2026-11_it_portal_cloud_iam'.
 *
 * Fakty zgodne z kodem na 2026-11-13: adres chmury z SsoConfig::cloudUrl() (produkcja https://nc.feer.org.pl), login
 * imie.nazwisko albo adres e-mail, hasło IAM (inne niż do poczty), konto zakładane po zalogowaniu do SZO (/sso/start)
 * albo w Systemie Tożsamości (#sso), kopia OneDrive w folderze „OneDrive” (SsoAccounts::cloudMail, CloudFilesCopy),
 * zmiana hasła na /nowehaslo. Limitu miejsca celowo nie podajemy (ustawienie sso_cloud_quota może się zmieniać).
 *
 * Artykuł „grupy” (polecenie 2026-11-14 „zrób też o tym artykuł”): grupy w chmurze zakładane z SZO (modules/sso_logto/logic/
 * CloudTeamsSync.php: zespoły i obszary Zadań, Działania, jednostki organizacyjne; co 2 min) i udostępnianie im plików. Wgrywa go
 * sam (itPortalCloudArticleApply) migracja '2026-11_it_portal_cloud_groups', bez ruszania wstępu i pozostałych artykułów.
 * Od 2026-11-14 („w pomocy IT o grupach osobno dla userów, a osobno dla koordynatorów”): „grupy” to wersja dla użytkowników (pracownicy
 * i wolontariusze), a koordynatorzy mają usługę „Koordynatorzy IT” (tylko odbiorcy IT) z artykułem „grupy-w-chmurze”
 * (itPortalCloudCoordApply). Migracja '2026-11_it_portal_cloud_groups_split'.
 *
 * Usługę tworzy, gdy jej nie ma; gdy jest — nadpisuje tylko wstęp. Artykuł: ten sam adres = nadpisanie treści, przypięcie,
 * publikacja i powiązane zostają. Bezpieczne do ponownego uruchomienia. Używa migracja '2026-11_it_portal_cloud'
 * i cli/it_portal_cloud_seed.php.
 */

/** Adres chmury: z ustawień logowania jednokrotnego, domyślnie produkcyjny. */
function itPortalCloudUrl(): string {
    $url = 'https://nc.feer.org.pl';
    try {
        require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
        if (SsoConfig::cloudUrl() !== '') $url = rtrim(SsoConfig::cloudUrl(), '/');
    } catch (\Throwable $e) {}
    return $url;
}

/** @return array{category:string, article:list<string>} */
function itPortalCloudApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $intro = itPortalSanitizeHtml(itPortalCloudIntro($app));
    $rep = ['category' => '', 'article' => ''];

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'chmura-plikow'");
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = 'wstęp nadpisany';
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona';
        $catId = 0;
        if (!$dryRun) {
            $aud = array_column(itPortalAudiences(), 'id', 'name');
            $catId = itPortalCategorySave(['name' => 'Chmura plików', 'slug' => 'chmura-plikow', 'icon' => 'cloud', 'sort_order' => 25, 'is_active' => 1,
                'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
        }
    }

    $rep['article'] = [];
    foreach (itPortalCloudArticles($app) as $slug => $def) $rep['article'][] = itPortalCloudArticleSave($catId, $slug, $def, $dryRun);
    return $rep;
}

/**
 * Zapis jednego artykułu usługi: ten sam adres = nadpisanie treści; przypięcie, publikacja, kolejność i „Zobacz też” zostają.
 * $def = [tytuł, słowa kluczowe, bloki, przypięty, kolejność]. Zwraca opis do raportu.
 */
function itPortalCloudArticleSave(int $catId, string $slug, array $def, bool $dryRun = false, string $catSlug = 'chmura-plikow'): string {
    [$title, $keywords, $blocks, $pinned, $sort] = $def;
    $old = $catId ? db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
    if (!$dryRun) itPortalArticleSave([
        'title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        'related' => $old ? (json_decode((string)$old['related'], true) ?: []) : [],
        'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : ($pinned ? 1 : 0),
        'sort_order' => $old ? (int)$old['sort_order'] : $sort,
    ], $old ? (int)$old['id'] : 0);
    return "{$catSlug}/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

/** Tylko jeden artykuł usługi (wstęp i pozostałe artykuły bez zmian). Bez usługi zakłada ją razem ze wszystkimi artykułami. */
function itPortalCloudArticleApply(string $app, string $slug, bool $dryRun = false): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $all = itPortalCloudArticles($app);
    if (!isset($all[$slug])) return 'nie ma artykułu ' . $slug;
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'chmura-plikow'");
    if (!$cat) {
        if (!$dryRun) itPortalCloudApply($app);
        return 'usługa „Chmura plików” utworzona razem z artykułami';
    }
    return itPortalCloudArticleSave((int)$cat['id'], $slug, $all[$slug], $dryRun);
}

/** Wprowadzenie na stronie usługi „Chmura plików”. */
function itPortalCloudIntro(string $app): string {
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    return '<p>Chmura plików Fundacji działa na programie Nextcloud pod adresem <a href="' . $cloud . '">' . $host . '</a>. '
         . 'Zastępuje OneDrive z Microsoft 365, który wyłączamy 20 listopada 2026 r. Trzymasz w niej pliki służbowe, udostępniasz je innym osobom '
         . 'i synchronizujesz z komputerem i telefonem.</p>'
         . '<p>Obecna chmura to instalacja wdrożeniowa. Instalację produkcyjną uruchomimy na początku grudnia 2026 r.</p>'
         . itPortalCloudSlowNote() . itPortalCloudAddresses()
         . '<p>Logujesz się logowaniem jednokrotnym (IAM), tak jak do SZO: adresem e-mail i hasłem IAM. To hasło jest inne niż hasło do poczty. '
         . 'Zacznij od instrukcji poniżej. Jeśli czegoś w niej brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>';
}

/**
 * Artykuły usługi: slug => [tytuł, słowa kluczowe, bloki, przypięty, kolejność].
 * @return array<string, array{0:string,1:string,2:array,3:bool,4:int}>
 */
function itPortalCloudArticles(string $app): array {
    return [
        'nextcloud' => ['Nextcloud: pierwsze kroki w chmurze plików',
            'nextcloud, chmura, chmura plików, czytnik ekranu, nvda, jaws, voiceover, talkback, dostępność, niewidomy, słabowidzący, pliki, dysk, onedrive, udostępnianie, link, synchronizacja, aplikacja, kosz, wersje, nc.feer.org.pl, chmura.feer.org.pl, galaxy.feer.org.pl, adres chmury, wolno, wolna, wolno działa, długo się ładuje, iam, hasło',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudBlocks($app), $app)), true, 10],
        'instalacja-aplikacji' => ['Instalacja aplikacji Nextcloud na komputerze i telefonie',
            'nextcloud, aplikacja, klient, instalacja, czytnik ekranu, nvda, jaws, voiceover, talkback, dostępność, niewidomy, konfiguracja, synchronizacja, windows, macos, linux, android, iphone, ios, pliki wirtualne, folder, zasobnik, agh',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudClientsBlocks($app), $app, true)), false, 20],
        'menu-aplikacji' => ['Menu aplikacji i poruszanie się po chmurze',
            'menu aplikacji, launcher, aplikacje, kropki, dziewięć kropek, pulpit, pliki, biuro, zdjęcia, zespoły, aktywność, sklep aplikacji, text document, górny pasek, nawigacja, menu, menu konta, profil, status, wygląd i dostępność, motyw, wysoki kontrast, ciemny motyw, czcionka dla dysleksji, ustawienia osobiste, wyloguj, skróty klawiszowe, klawiatura, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudNavBlocks($app), $app)), false, 13],
        'pulpit-roboczy' => ['Pulpit z oknami (Desktop Workspace)',
            'desktop workspace, desktop, pulpit roboczy, pulpit z oknami, okna, pasek zadań, przypinanie, ikony na pulpicie, folder pulpitu, menedżer plików, dekoracje okien, reset, ustawienia pulpitu, eksperymentalne, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudWorkspaceBlocks($app), $app)), false, 18],
        'aktywnosc' => ['Aktywność: historia zmian w chmurze',
            'aktywność, historia, historia zmian, kto zmienił, kto usunął, kto udostępnił, zmiany w plikach, przez ciebie, przez innych, skupienie, ulubione, bezpieczeństwo, udostępniony plik, kalendarz, zadania, komentarze, kontakty, mapa cieplna, dowolna osoba, dowolny czas, hasło zostało zresetowane przez administratora, contact birthdays, urodziny, ustawienia aktywności, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudActivityBlocks($app), $app)), false, 15],
        'ustawienia-osobiste' => ['Ustawienia osobiste w chmurze: profil, język, bezpieczeństwo',
            'ustawienia, ustawienia osobiste, personal, profil, profile & contact, zdjęcie profilowe, awatar, pełna nazwa, zaimki, lokalizacja, telefon, widoczność, kto widzi, kłódka, language & locale, język, format daty, bezpieczeństwo, urządzenia, sesje, hasło aplikacji, powiadomienia, wygląd i dostępność, udostępnianie, dostępność, nieobecność, godziny pracy, przepływ, prywatność, migracja danych, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudSettingsBlocks($app), $app)), false, 16],
        'powiadomienia' => ['Powiadomienia w chmurze plików',
            'powiadomienia, powiadomienie, dzwonek, czerwona kropka, udostępnienie, udostępnił, wzmianka, komentarz, e-mail z chmury, wiadomości z chmury, za dużo maili, podsumowanie, aktywność, nie przeszkadzać, status, powiadomienia push, telefon, komputer, przeglądarka, odrzuć, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudNotifyBlocks($app), $app)), false, 14],
        'aplikacja-komputer' => ['Aplikacja Nextcloud na komputerze: jak z niej korzystać',
            'aplikacja, komputer, laptop, windows, macos, mac, linux, nextcloud, klient, synchronizacja, folder, eksplorator, finder, zasobnik, pasek menu, ikona, ikony, pliki wirtualne, zwolnij miejsce, zawsze na tym urządzeniu, konflikt, conflicted copy, wstrzymaj, ustawienia, udostępnij, kopiuj link, usuwanie, czytnik ekranu, nvda, jaws, voiceover',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudDesktopBlocks($app), $app)), false, 21],
        'aplikacja-telefon' => ['Aplikacja Nextcloud na telefonie: jak z niej korzystać',
            'aplikacja, telefon, komórka, smartfon, android, iphone, ios, nextcloud, aplikacja mobilna, offline, dostępne offline, automatyczne wysyłanie, zdjęcia, aparat, skanowanie, skan dokumentu, udostępnianie, blokada, kod, odcisk palca, face id, zgubiony telefon, aplikacja pliki, talkback, voiceover, czytnik ekranu, wylogowanie',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudPhoneBlocks($app), $app, true)), false, 22],
        'menu-plikow' => ['Co jest co w menu bocznym aplikacji Pliki',
            'menu, menu boczne, pliki, wszystkie pliki, pliki osobiste, ostatnie, ulubione, udostępnienia, foldery zespołów, etykiety, drzewo folderów, usunięte pliki, kosz, miejsce, limit, ustawienia plików, webdav, wyszukiwanie, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudMenuBlocks($app), $app)), false, 15],
        'logowanie' => ['Logowanie do chmury plików',
            'logowanie, zaloguj, zaloguj się, ekran logowania, strona logowania, login, nazwa użytkownika, hasło, iam, logowanie jednokrotne, logto, logto.feer.org.pl, nc.feer.org.pl, zapamiętaj mnie, zapomniałeś hasła, nie pamiętam hasła, utwórz konto, nazwa użytkownika jest nieprawidłowa, urządzenie, klucz dostępu, wyloguj, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudLoginBlocks($app), $app)), false, 11],
        'pulpit' => ['Ekran po zalogowaniu: Pulpit chmury plików',
            'ekran po zalogowaniu, po zalogowaniu, pulpit, strona startowa, start, dashboard, polecane pliki, zespoły, ostatnia aktywność, aktywność, powiadomienia, dzwonek, kontakty, status, awatar, menu aplikacji, wyszukiwarka, dostosuj, widżety, konto techniczne, szo, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudDashboardBlocks($app), $app)), false, 12],
        'lista-plikow' => ['Lista plików: przyciski, ikony i udostępnianie',
            'lista plików, pliki, ikony, google drive, kopia konta, dawne pliki, przeniesione pliki, przyciski, nowy, prześlij, nowy folder, filtr, widok, siatka, kafelki, sortowanie, zaznaczanie, trzy kropki, akcje, readme, readme.md, opis folderu, onedrive, documents, photos, szablony, udostępnij, udostępnianie, link, link publiczny, link wewnętrzny, udostępnianie zewnętrzne, inni z dostępem, aktywność, panel boczny, nextcloud',
            itPortalCloudPilot(itPortalCloudA11y(itPortalCloudListBlocks($app), $app)), false, 17],
        'grupy' => ['Grupy w chmurze: wspólne pliki zespołu, obszaru, działania i jednostki',
            'grupy, grupa, udostępnianie, udostępnij, folder wspólny, zespół, zespoły, obszar roboczy, obszar, działanie, działania, jednostka, jednostka organizacyjna, umowa, zadania, nextcloud, chmura, nie widzę folderu, brak dostępu',
            itPortalCloudPilot(itPortalCloudGroupsBlocks($app)), false, 30],
    ];
}

/**
 * Przyciski pobierania aplikacji Nextcloud (polecenie 2026-11-14: „dodaj też przyciski i linki do pobrania aplikacji”). Tylko oficjalne źródła:
 * strona nextcloud.com/install (komputer), Sklep Play i F-Droid (Android), App Store (iPhone, iPad).
 */
function itPortalCloudDownloadButtons(bool $desktop = true, bool $phone = true): array {
    $items = [];
    if ($desktop) {
        $items[] = ['label' => 'Pobierz na komputer', 'url' => 'https://nextcloud.com/install/#install-clients', 'note' => 'Windows, macOS, Linux (strona Nextcloud)'];
    }
    if ($phone) {
        $items[] = ['label' => 'Pobierz z Google Play', 'url' => 'https://play.google.com/store/apps/details?id=com.nextcloud.client', 'note' => 'Telefon i tablet z Androidem'];
        $items[] = ['label' => 'Pobierz z App Store', 'url' => 'https://apps.apple.com/app/nextcloud/id1125420102', 'note' => 'iPhone i iPad'];
        $items[] = ['label' => 'Pobierz z F-Droid', 'url' => 'https://f-droid.org/packages/com.nextcloud.client/', 'note' => 'Android bez usług Google'];
    }
    return [['type' => 'buttons', 'title' => 'Pobierz aplikację Nextcloud', 'items' => $items]];
}

/** Uwaga o wolnym działaniu (polecenie 2026-11-14: „NC może wolno działać, bo to serwer przedwdrożeniowy”). */
function itPortalCloudSlowNote(): string {
    return '<p>Chmura działa teraz na serwerze przedwdrożeniowym, więc może być wolna. Strony mogą się dłużej ładować, a duże pliki dłużej wysyłać. '
         . 'Jeśli coś się nie wczyta, poczekaj chwilę i odśwież stronę. Gdy błąd się powtarza, napisz do Helpdesku IT.</p>';
}

/**
 * Adresy chmury (polecenie 2026-11-14: „chmura będzie dostępna docelowo pod adresami nc.feer.org.pl, chmura.feer.org.pl i galaxy.feer.org.pl”).
 */
function itPortalCloudAddresses(): string {
    return '<p>Docelowo chmura będzie dostępna pod trzema adresami: nc.feer.org.pl, chmura.feer.org.pl i galaxy.feer.org.pl. '
         . 'Wszystkie prowadzą do tej samej chmury, tego samego konta i tych samych plików, więc wybierz ten, który łatwiej Ci zapamiętać. '
         . 'Na razie używaj adresu nc.feer.org.pl.</p>';
}

/**
 * Ramka na początku artykułów o chmurze: instalacja wdrożeniowa, produkcyjna od początku grudnia 2026 r. (polecenie 2026-11-14:
 * „dopisz w Nextcloud pomoc, że to instalacja wdrożeniowa, produkcyjna zostanie uruchomiona na początku grudnia”).
 */
function itPortalCloudPilot(array $blocks): array {
    return array_merge([['type' => 'callout', 'kind' => 'important',
        'html' => '<p>Obecna chmura plików to instalacja wdrożeniowa. Instalację produkcyjną uruchomimy na początku grudnia 2026 r.</p>'
            . itPortalCloudSlowNote() . itPortalCloudAddresses()]], $blocks);
}

/**
 * Bloki artykułu „Co jest co w menu bocznym aplikacji Pliki” (polecenie 2026-11-14 ze zrzutem ekranu Nextcloud 35: „wyjaśnij to menu
 * boczne i zrób pomoc, co jest co”). Kolejność jak w menu. Bez liczb zależnych od ustawień (limit miejsca, czas trzymania w koszu).
 */
function itPortalCloudMenuBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $groups = $app . '/uslugi-it/uslugi/chmura-plikow/grupy';
    return [
        ['type' => 'text', 'html' => '<p>Po lewej stronie aplikacji Pliki jest menu. Każda pozycja to inny widok Twoich plików, '
            . 'więc nic się nie przenosi ani nie kopiuje, gdy przełączasz się między nimi. Menu chowasz i pokazujesz przyciskiem nad listą plików.</p>'],

        ['type' => 'text', 'html' => '<h2>Pozycje menu od góry</h2>'
            . '<p>Szukaj tutaj. Pole na górze menu szuka plików i folderów po nazwie w aplikacji Pliki. '
            . 'Wyszukiwarka w górnym pasku („Aplikacje, pliki, wiadomości i nie tylko”) szuka w całej chmurze. Otworzysz ją też skrótem Ctrl+K, a na Macu Cmd+K.</p>'
            . '<p>Wszystkie pliki. Wszystko, do czego masz dostęp: Twoje pliki i foldery oraz to, co udostępnili Ci inni.</p>'
            . '<p>Pliki osobiste. Tylko Twoje własne pliki, bez tych, które ktoś Ci udostępnił.</p>'
            . '<p>Ostatnie. Pliki zmienione ostatnio, najnowsze na górze. Przydaje się, gdy nie pamiętasz, gdzie zapisałeś dokument.</p>'
            . '<p>Ulubione. Pliki i foldery oznaczone gwiazdką. Gwiazdkę dodasz w menu przy pliku, wybierając „Dodaj do ulubionych”.</p>'
            . '<p>Udostępnienia. Po rozwinięciu strzałką zobaczysz osobno oczekujące udostępnienia, prośby o plik, to, co Ty udostępniłeś innym, '
            . 'udostępnienia przez link, to, co inni udostępnili Tobie, i udostępnienia, które usunięto.</p>'
            . '<p>Foldery zespołów. Wspólne foldery, które administrator chmury zakłada dla całej grupy. Pliki w takim folderze należą do zespołu, '
            . 'a nie do jednej osoby, więc zostają, gdy ktoś odejdzie. Jeśli widzisz napis „Brak Folderów zespołów”, Twoja grupa nie ma jeszcze takiego folderu.</p>'
            . '<p>Etykiety. Słowa, którymi oznaczasz pliki, na przykład „faktura” albo „do podpisu”. Po kliknięciu etykiety widzisz wszystkie pliki nią oznaczone, '
            . 'z różnych folderów naraz.</p>'
            . '<p>Drzewo folderów. Twoje foldery jako rozwijane drzewo. Pozwala przejść do głębszego folderu bez klikania po kolei w każdy poziom.</p>'],
        ...itPortalCloudShot('nc-foldery-zespolow.jpg', 'Menu boczne aplikacji Pliki z zaznaczoną pozycją Foldery zespołów; po prawej napis Brak Folderów zespołów',
            'Menu boczne. Tu wybrano Foldery zespołów na koncie, które nie ma jeszcze żadnego.'),

        ['type' => 'text', 'html' => '<h2>Na dole menu</h2>'
            . '<p>Usunięte pliki. Kosz. Usunięty plik albo folder leży tu jeszcze przez jakiś czas i możesz go przywrócić. Później chmura usuwa go na stałe.</p>'
            . '<p>Wykorzystane miejsce. Ile miejsca zajmują Twoje pliki i ile masz go łącznie. Pliki w folderach zespołów i pliki udostępnione Ci przez innych '
            . 'nie zabierają Twojego miejsca. Gdy zaczyna go brakować, opróżnij kosz albo napisz do Helpdesku.</p>'
            . '<p>Ustawienia Plików. Wygląd listy, na przykład pokazywanie plików ukrytych. Jest tam też adres WebDAV, którego potrzebują niektóre programy do łączenia się z chmurą.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Pliki, które udostępniono Twojej grupie z SZO, znajdziesz w „Wszystkich plikach” i w „Udostępnieniach”. '
            . 'Jak działają grupy, opisuje artykuł <a href="' . $groups . '">Grupy w chmurze</a>. Gdy czegoś w menu brakuje albo działa inaczej, '
            . '<a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
    ];
}

/**
 * Ramka o czytniku ekranu wstawiana po pierwszym bloku artykułów o chmurze (polecenie 2026-11-14: „dopisz, że z NC najlepiej z czytnikiem
 * ekranu korzystać z aplikacji”). W artykule o instalacji aplikacji ($inClients) bez odsyłacza do samego siebie.
 */
function itPortalCloudA11y(array $blocks, string $app, bool $inClients = false): array {
    $apps = $app . '/uslugi-it/uslugi/chmura-plikow/instalacja-aplikacji';
    $html = '<p>Jeśli korzystasz z czytnika ekranu, na przykład NVDA, JAWS, VoiceOver albo TalkBack, najwygodniej pracować z chmurą przez aplikację Nextcloud '
        . 'na komputerze albo telefonie, a nie przez stronę w przeglądarce. Na komputerze pliki z chmury są wtedy zwykłym folderem w Eksploratorze plików '
        . 'albo w Finderze, więc obsługujesz je tak samo jak inne pliki, skrótami klawiszowymi, które już znasz. Strona w przeglądarce też działa z czytnikiem, '
        . 'ale ma dużo przycisków i paneli, które otwierają się z boku, więc poruszanie się po niej trwa dłużej.</p>'
        . ($inClients ? '' : '<p>Jak zainstalować aplikację, opisuje artykuł <a href="' . $apps . '">Instalacja aplikacji Nextcloud na komputerze i telefonie</a>.</p>');
    $note = ['type' => 'callout', 'kind' => 'tip', 'html' => $html];
    return array_merge(array_slice($blocks, 0, 1), [$note], array_slice($blocks, 1));
}

/**
 * Zrzut ekranu z repozytorium (modules/it_portal/lib/img/{nazwa}) kopiowany do uploads/it_portal/ pod nazwą z sumy SHA-1,
 * bo uploads/ nie jest w gicie. Zwraca blok obrazka albo [] gdy pliku brak (artykuł zapisze się wtedy bez zrzutu).
 */
function itPortalCloudShot(string $name, string $alt, string $caption = ''): array {
    require_once dirname(__DIR__) . '/logic/itPortalBlocks.php';
    $src = __DIR__ . '/img/' . $name;
    if (!is_file($src)) return [];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'jpeg' ? 'jpg' : strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $file = substr(sha1_file($src), 0, 20) . '.' . $ext;
    $dir = itPortalImageDir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_file($dir . $file) && !@copy($src, $dir . $file)) return [];
    return [['type' => 'image', 'file' => $file, 'alt' => $alt, 'caption' => $caption]];
}

/**
 * Bloki artykułu „Logowanie do chmury plików” (polecenie 2026-11-14: „opisz też ekran logowania”; potem: „nie ma okienka IAM od razu,
 * przynajmniej na razie, ale będzie docelowo”, więc najpierw strona Nextcloud, potem IAM). Zrzuty z nc.feer.org.pl po wylogowaniu:
 * strona logowania Nextcloud (formularz + „Login with logowanie jednokrotne”) i strona IAM logto.feer.org.pl. Hasła zmienia się tylko na /nowehaslo.
 */
function itPortalCloudLoginBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $nh = $app . '/nowehaslo';
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    return [
        ['type' => 'text', 'html' => '<p>Do chmury plików wchodzisz przez stronę <a href="' . $cloud . '">' . $host . '</a>. '
            . 'Logujesz się kontem IAM, czyli tym samym adresem e-mail i hasłem co przy logowaniu jednokrotnym do SZO. Hasło IAM jest inne niż hasło do poczty.</p>'
            . '<p>Na razie logowanie ma dwa kroki. Najpierw widzisz stronę logowania Nextcloud, a z niej przechodzisz na stronę logowania IAM. '
            . 'Docelowo strona IAM będzie otwierać się od razu po wejściu na ' . $host . ', bez pierwszego kroku.</p>'],

        ['type' => 'text', 'html' => '<h2>Krok 1: strona logowania Nextcloud</h2>'
            . '<p>Po wejściu na ' . $host . ' zobaczysz niebieską stronę z logo Nextcloud i napisem „Zaloguj się do Nextcloud”. '
            . 'Kliknij na dole przycisk „Login with logowanie jednokrotne”. Przeniesie Cię na stronę logowania IAM. '
            . 'Jeśli jesteś już zalogowany do SZO, często od razu trafisz do chmury, bez wpisywania hasła.</p>'
            . '<p>Możesz też wpisać adres e-mail i hasło IAM w pola na tej stronie i kliknąć „Zaloguj”. Zaznaczone „Zapamiętaj mnie” sprawia, '
            . 'że przeglądarka nie wyloguje Cię po zamknięciu. Odznacz to pole na wspólnym albo cudzym komputerze.</p>'
            . '<p>„Zaloguj się za pomocą urządzenia” działa tylko wtedy, gdy wcześniej dodałeś w ustawieniach osobistych chmury klucz dostępu, '
            . 'na przykład odcisk palca w laptopie albo klucz sprzętowy. Jeśli tego nie robiłeś, pomiń ten przycisk.</p>'],
        ...itPortalCloudShot('nc-logowanie-nextcloud.jpg', 'Strona logowania Nextcloud: pola Nazwa konta lub e-mail i Hasło, pole Zapamiętaj mnie, przycisk Zaloguj, linki Zaloguj się za pomocą urządzenia i Zapomniałeś hasła, przycisk Login with logowanie jednokrotne',
            'Strona logowania Nextcloud. Przycisk logowania jednokrotnego jest na samym dole.'),

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie zmieniaj hasła linkiem „Zapomniałeś hasła?” na stronie Nextcloud. Zmieniłbyś je tylko w chmurze '
            . 'i przestałoby pasować do SZO. Hasło IAM zmienisz albo odzyskasz na stronie <a href="' . $nh . '">Nowe hasło</a>, potwierdzając zmianę kodem.</p>'],

        ['type' => 'text', 'html' => '<h2>Krok 2: strona logowania IAM</h2>'
            . '<p>Druga strona jest biała, z logo Fundacji i napisem „Zaloguj się do swojego konta”. Jej adres zaczyna się od logto.feer.org.pl. '
            . 'Na dole jest dopisek „Powered by Logto”, to nazwa programu, który obsługuje logowanie.</p>'
            . '<p>W pole „Nazwa użytkownika” wpisz pełny adres e-mail, ten sam co do SZO. W pole „Hasło” wpisz hasło IAM i kliknij „Zaloguj się”. '
            . 'Jeśli zobaczysz komunikat, że nazwa użytkownika jest nieprawidłowa, sprawdź, czy wpisałeś cały adres razem z częścią po znaku @.</p>'
            . '<p>Linku „Utwórz konto” nie używaj. Konto w chmurze zakłada SZO i tylko takie konto ma Twoje grupy i wspólne foldery. '
            . 'Jeśli nie masz jeszcze konta, zaproponuje je panel SZO przy logowaniu.</p>'],
        ...itPortalCloudShot('nc-logowanie-iam.jpg', 'Strona logowania IAM: logo FEER, napis Zaloguj się do swojego konta, pola Nazwa użytkownika i Hasło, przycisk Zaloguj się, link Utwórz konto, na dole Powered by Logto',
            'Strona logowania IAM pod adresem logto.feer.org.pl.'),

        ['type' => 'text', 'html' => '<h2>Wylogowanie</h2>'
            . '<p>Kliknij kółko ze swoimi inicjałami w prawym górnym rogu i wybierz „Wyloguj się” na dole menu. Zrób to zawsze na komputerze, '
            . 'z którego korzysta ktoś jeszcze.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Czym konto IAM różni się od poczty, opisuje artykuł '
            . '<a href="' . $app . '/uslugi-it/uslugi/migracja/iam-i-poczta">Czym jest IAM, a czym poczta</a>. Co zobaczysz po zalogowaniu, opisuje artykuł '
            . '<a href="' . $app . '/uslugi-it/uslugi/chmura-plikow/pulpit">Ekran po zalogowaniu</a>. Gdy hasło nie działa mimo zmiany, '
            . '<a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
    ];
}

/**
 * Bloki artykułu „Ekran po zalogowaniu: Pulpit chmury plików” (polecenie 2026-11-14 ze zrzutem Pulpitu Nextcloud: „opisz to i wypchnij na pomoc IT”,
 * potem „ekran po zalogowaniu i że niektóre elementy mogą się różnić”).
 * Górny pasek, trzy widżety (Polecane pliki, Zespoły, Ostatnia aktywność), wpisy konta technicznego SZO, przycisk „Dostosuj”.
 */
function itPortalCloudDashboardBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'text', 'html' => '<p>Po zalogowaniu do chmury widzisz Pulpit. To strona startowa: wita Cię po imieniu i zbiera w jednym miejscu '
            . 'pliki, nad którymi ostatnio pracowano, Twoje zespoły i to, co się ostatnio zmieniło. Na Pulpit wrócisz w każdej chwili, klikając logo w lewym górnym rogu.</p>'
            . '<p>Czasem po zalogowaniu otwiera się od razu aplikacja Pliki z listą folderów. To też jest w porządku. Na Pulpit przejdziesz wtedy, klikając logo.</p>'],
        ...itPortalCloudShot('nc-pulpit.jpg', 'Pulpit chmury plików: górny pasek z wyszukiwarką, napis Witaj i karta Polecane pliki z listą przykładowych plików',
            'Pulpit nowego konta. Jest na nim tylko karta Polecane pliki. Na Twoim koncie kart może być więcej.'),

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Twój ekran może wyglądać trochę inaczej niż w tym opisie. Inne będą nazwy plików i folderów, '
            . 'wpisy w aktywności i liczba kart, bo zależą od Twojego konta, grup i tego, co sam ustawisz. Część ikon i napisów może się też zmienić '
            . 'po aktualizacji chmury, zwłaszcza po uruchomieniu instalacji produkcyjnej.</p>'],

        ['type' => 'text', 'html' => '<h2>Górny pasek</h2>'
            . '<p>Logo Nextcloud po lewej przenosi na Pulpit.</p>'
            . '<p>Ikona z kropkami obok logo otwiera menu aplikacji. Wybierzesz tam Pliki, Kontakty, Kalendarz i inne aplikacje chmury.</p>'
            . '<p>Napis obok ikony z kropkami pokazuje, w której aplikacji jesteś. Na stronie startowej to „Pulpit”.</p>'
            . '<p>Pole „Aplikacje, pliki, wiadomości i nie tylko” na środku to wyszukiwarka całej chmury. Otworzysz ją też skrótem Ctrl+K, a na Macu Cmd+K.</p>'
            . '<p>Dzwonek po prawej to powiadomienia, na przykład o nowym udostępnieniu. Czerwona kropka przy dzwonku oznacza, że masz nieprzeczytane.</p>'
            . '<p>Ikona z sylwetką na karcie to szybkie wyszukiwanie osób z chmury. Wpisz imię albo nazwisko, a zobaczysz adres e-mail i możliwość wysłania wiadomości.</p>'
            . '<p>Kółko z Twoimi inicjałami to menu konta. Ustawisz tam status (zielony znaczek oznacza, że jesteś dostępny), otworzysz ustawienia osobiste i wylogujesz się.</p>'],

        ['type' => 'text', 'html' => '<h2>Polecane pliki</h2>'
            . '<p>Pierwsza karta pokazuje pliki i foldery, które ostatnio się zmieniały, razem z czasem ostatniej zmiany. '
            . 'Kliknij nazwę, a otworzysz plik albo przejdziesz do folderu. Lista sama się zmienia, więc nie musisz niczego tu ustawiać. '
            . 'Pełną listę znajdziesz w aplikacji Pliki w pozycji „Ostatnie”. Na nowym koncie są tu przykładowe pliki, które chmura dodaje sama, '
            . 'a gdy nic się jeszcze nie zmieniało, zobaczysz napis „Nie ma jeszcze rekomendacji”.</p>'],

        ['type' => 'text', 'html' => '<h2>Zespoły</h2>'
            . '<p>Druga karta pokazuje zespoły, do których należysz. Zespół to lista osób, którą zakłada sam użytkownik chmury, '
            . 'żeby szybciej udostępniać pliki kilku osobom naraz. Napis „Nie znaleziono żadnych zespołów” jest w porządku i nie oznacza, że czegoś brakuje.</p>'
            . '<p>Zespoły to coś innego niż grupy z SZO. Grupy zakłada system na podstawie umów, obszarów i działań i to dzięki nim widzisz wspólne foldery. '
            . 'Opisuje je artykuł <a href="' . $base . 'grupy">Grupy w chmurze</a>. Własny zespół załóż przyciskiem „Utwórz swój pierwszy zespół” tylko wtedy, '
            . 'gdy często współpracujesz z kilkoma osobami spoza swojej grupy.</p>'],

        ['type' => 'text', 'html' => '<h2>Ostatnia aktywność</h2>'
            . '<p>Trzecia karta to skrót tego, co ostatnio działo się z Twoimi plikami i kontem: kto coś udostępnił, zmienił albo dodał. '
            . 'Przycisk „Więcej aktywności” na dole otwiera pełną listę. Na nowym koncie tej karty może jeszcze nie być.</p>'
            . '<p>Wpisy „@SZO (konto techniczne) dodał…” i „Zostałeś dodany jako członek…” są normalne. SZO samo dopisuje Cię do grup i udostępnia foldery, '
            . 'gdy podpiszesz umowę albo dołączysz do obszaru lub działania. Robi to przez swoje konto techniczne, dlatego to ono widnieje jako autor.</p>'],

        ['type' => 'text', 'html' => '<h2>Jak zmienić wygląd Pulpitu</h2>'
            . '<p>Przewiń Pulpit na sam dół i kliknij przycisk „Dostosuj” z ikoną ołówka. Zaznaczysz tam, które karty mają być widoczne, i zmienisz tło. '
            . 'Kolejność kart zmienisz, przeciągając je myszą.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Do samych plików przejdziesz przez menu aplikacji albo klikając plik w „Polecanych plikach”. '
            . 'Co oznaczają pozycje menu w aplikacji Pliki, wyjaśnia artykuł <a href="' . $base . 'menu-plikow">Co jest co w menu bocznym aplikacji Pliki</a>. '
            . 'Gdy coś na Pulpicie wygląda inaczej niż tutaj, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
    ];
}

/**
 * Bloki artykułu „Lista plików: przyciski, ikony i udostępnianie” (polecenie 2026-11-14 ze zrzutami aplikacji Pliki i panelu udostępniania:
 * „funkcje listy plików i ikony”). Folder „OneDrive” = kopia z Microsoft 365 robiona przez SZO (modules/sso_logto/logic/CloudFilesCopy.php).
 */
function itPortalCloudListBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'text', 'html' => '<p>Ten artykuł opisuje główną część aplikacji Pliki, czyli listę plików po prawej od menu bocznego, '
            . 'a także panel, który otwiera się przy udostępnianiu. Pozycje samego menu bocznego wyjaśnia artykuł '
            . '<a href="' . $base . 'menu-plikow">Co jest co w menu bocznym aplikacji Pliki</a>.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Twój ekran może wyglądać trochę inaczej. Nazwy plików i folderów, liczba kolumn '
            . 'i część przycisków zależą od Twojego konta, szerokości okna i wersji chmury.</p>'],

        ['type' => 'text', 'html' => '<h2>Pasek nad listą</h2>'
            . '<p>Ikona prostokąta z paskiem po lewej chowa i pokazuje menu boczne. Przydaje się na małym ekranie.</p>'
            . '<p>Niebieski przycisk „Nowy” dodaje coś do folderu, w którym jesteś. „Wyślij pliki” i „Wyślij katalogi” przesyłają pliki albo cały folder z komputera. '
            . '„Nowy katalog” zakłada folder. „Utwórz prośbę o plik” daje link, przez który ktoś wgra Ci pliki, nie widząc reszty folderu. '
            . '„Text document” tworzy pusty plik tekstowy. Pliki z komputera możesz też po prostu przeciągnąć myszą na listę.</p>'
            . '<p>Pozycje w menu „Nowy” zależą od tego, jakie aplikacje działają w chmurze. Później może tam dojść na przykład tworzenie dokumentów i arkuszy.</p>'
            . '<p>Obok jest ścieżka, czyli nazwa folderu, w którym jesteś, i folderów nad nim. Kliknij nazwę wyższego folderu, żeby do niego wrócić. '
            . 'Strzałka przy nazwie otwiera działania dla bieżącego folderu.</p>'
            . '<p>Ikona z trzema poziomymi kreskami po prawej to filtry. Pokażesz nimi na przykład tylko dokumenty, tylko zdjęcia '
            . 'albo pliki zmienione w ostatnim tygodniu.</p>'
            . '<p>Ikona z czterema kwadratami przełącza widok między listą a kafelkami z miniaturami. Kafelki są wygodne przy zdjęciach.</p>'],
        ...itPortalCloudShot('nc-nowy.jpg', 'Rozwinięte menu przycisku Nowy: Wyślij pliki, Wyślij katalogi, Nowy katalog, Utwórz prośbę o plik, Text document',
            'Menu przycisku Nowy.'),
        ...itPortalCloudShot('nc-lista-plikow.jpg', 'Aplikacja Pliki: menu boczne, przycisk Nowy, opis folderu Welcome to Nextcloud, ostatnie pliki i lista folderów Documents, Photos, Templates z ikonami udostępniania i trzema kropkami',
            'Wszystkie pliki na nowym koncie: opis folderu, ostatnio zmieniane pliki i lista z kolumnami Nazwa, Rozmiar i Zmodyfikowano.'),

        ['type' => 'text', 'html' => '<h2>Opis folderu nad listą</h2>'
            . '<p>Nad listą może być widoczny tekst, na przykład „Welcome to Nextcloud!”. To zawartość pliku Readme.md z tego folderu. '
            . 'Służy do opisania, co jest w folderze i jak z niego korzystać. Kliknij w tekst, żeby go zmienić. '
            . 'Po wpisaniu ukośnika „/” dodasz nagłówek, listę, tabelę albo obrazek.</p>'
            . '<p>Angielski tekst powitalny to przykład, który chmura wkłada do każdego nowego konta. Możesz go zmienić '
            . 'albo usunąć plik Readme.md z listy. Wtedy opis zniknie.</p>'
            . '<p>Pod opisem chmura pokazuje czasem kilka ostatnio zmienianych folderów i plików z tego miejsca, żeby szybciej do nich wrócić.</p>'],

        ['type' => 'text', 'html' => '<h2>Kolumny i zaznaczanie</h2>'
            . '<p>Kliknij nagłówek kolumny Nazwa, Rozmiar albo Zmodyfikowano, żeby posortować listę. Drugie kliknięcie odwraca kolejność. '
            . 'Strzałka przy nagłówku pokazuje, według czego lista jest teraz ułożona. W wąskim oknie nagłówki są skrócone, na przykład „Roz…”.</p>'
            . '<p>Kwadracik na początku wiersza zaznacza plik. Gdy zaznaczysz kilka, nad listą pojawią się przyciski dla wszystkich naraz: '
            . 'pobranie, przeniesienie lub skopiowanie i usunięcie. Kwadracik w nagłówku zaznacza wszystko w folderze.</p>'],

        ['type' => 'text', 'html' => '<h2>Ikony w wierszu</h2>'
            . '<p>Niebieski folder to folder. Plik ma ikonę swojego rodzaju albo miniaturę, na przykład zdjęcia. '
            . 'Kliknij nazwę, żeby otworzyć folder albo plik. Zdjęcia, filmy i PDF-y otworzą się w podglądzie, a pliki tekstowe od razu w edytorze. '
            . 'Inne pliki pobierzesz i otworzysz w programie na komputerze.</p>'
            . '<p>Ikona osoby z plusem to udostępnianie. Otwiera po prawej panel, w którym dajesz komuś dostęp. Gdy plik jest już udostępniony, '
            . 'zamiast niej widać awatar osoby albo napis „Udostępnione”.</p>'
            . '<p>Trzy kropki otwierają pozostałe działania: pobieranie, dodanie do ulubionych, zmianę nazwy, przeniesienie lub skopiowanie, '
            . 'szczegóły i usunięcie. To samo menu otworzysz prawym przyciskiem myszy na wierszu.</p>'],

        ['type' => 'text', 'html' => '<h2>Foldery, które są na nowym koncie</h2>'
            . '<p>Foldery Documents i Photos, folder Szablony (na części kont Templates) oraz przykładowe pliki, takie jak Nextcloud.png, Nextcloud Manual.pdf '
            . 'czy Nextcloud intro.mp4, chmura zakłada sama w każdym nowym koncie. Folder szablonów jest potrzebny, gdy chcesz tworzyć dokumenty z własnych wzorów. '
            . 'Resztę możesz zostawić, przemianować albo usunąć.</p>'
            . '<p>Jeśli na liście masz folder „OneDrive”, „Google Drive” albo „Kopia konta”, są w nim Twoje dane przeniesione z zewnętrznych serwerów, '
            . 'z których Fundacja kiedyś korzystała. Foldery zachowują układ i daty zmian z dawnego miejsca. Pracuj na tych plikach normalnie, '
            . 'przenoś je, gdzie chcesz, albo usuń te, których już nie potrzebujesz.</p>'
            . '<p>Folder OneDrive to kopia Twoich plików z OneDrive w Microsoft 365. SZO przenosi je w tle po założeniu konta w chmurze. '
            . 'Przy dużej ilości plików kopiowanie trwa nawet kilka godzin, więc folder może się jeszcze zapełniać.</p>'
            . '<p>Foldery, które ktoś Ci udostępnił, na przykład wspólne foldery grupy, też są na tej liście. '
            . 'Skąd się biorą, opisuje artykuł <a href="' . $base . 'grupy">Grupy w chmurze</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Panel udostępniania</h2>'
            . '<p>Na górze panelu jest nazwa pliku lub folderu, jego rozmiar, czas ostatniej zmiany i właściciel. Trzy kropki obok otwierają te same działania co w wierszu, '
            . 'a krzyżyk zamyka panel. Poniżej są zakładki. Udostępnianie służy do dawania dostępu, a Aktywność pokazuje, kto i kiedy coś zmienił. '
            . 'Przy plikach bywają też zakładki Wersje i Komentarze.</p>'
            . '<p>Udostępnianie wewnętrzne to dostęp dla osób z chmury Fundacji. Wpisz w pole imię, nazwisko, nazwę grupy albo zespołu i wybierz z listy. '
            . 'Przy każdej osobie ustawisz potem, czy może tylko oglądać, czy także zmieniać.</p>'
            . '<p>Inni z dostępem pokazuje osoby, które widzą ten element dzięki folderowi wyżej albo grupie, choć nie udostępniłeś go im tutaj bezpośrednio.</p>'
            . '<p>Link wewnętrzny kopiujesz ikoną po prawej. Zadziała tylko u osób, które już mają dostęp, więc nadaje się do wysłania koledze w wiadomości. '
            . 'Nikomu nowemu nie daje dostępu.</p>'
            . '<p>Udostępnienia zewnętrzne to dostęp dla osób spoza chmury Fundacji. W pole wpisujesz adres e-mail, a odbiorca dostanie wiadomość z linkiem. '
            . 'Identyfikator z innej chmury Nextcloud wpisujesz tylko wtedy, gdy odbiorca Ci go poda.</p>'
            . '<p>Utwórz link publiczny (plus po prawej) tworzy link, który otworzy każdy, kto go dostanie. Przy linku ustawisz hasło, datę wygaśnięcia '
            . 'i to, czy odbiorca może tylko pobierać, czy także wgrywać i zmieniać pliki.</p>'
            . '<p>Dodatkowe udostępnienia pokazują dostęp nadany w inny sposób, na przykład przez inne aplikacje chmury. Często ta część jest pusta.</p>'
            . '<p>Ikony z literą „i” przy nagłówkach pokazują krótką podpowiedź po najechaniu myszą.</p>'],
        ...itPortalCloudShot('nc-udostepnianie.jpg', 'Panel udostępniania folderu Documents po prawej: zakładki Udostępnianie i Aktywność, pola Udostępnianie wewnętrzne i Udostępnienia zewnętrzne, Link wewnętrzny i Utwórz link publiczny',
            'Panel udostępniania otwiera się po kliknięciu ikony osoby z plusem przy folderze.'),

        ['type' => 'text', 'html' => '<h2>Gdzie zobaczysz, co jest udostępnione</h2>'
            . '<p>W menu bocznym kliknij „Udostępnienia” i rozwiń strzałką. Oczekujące udostępnienia to te, które ktoś Ci wysłał, a Ty ich jeszcze nie przyjąłeś. '
            . 'Prośba o plik pozwala poprosić kogoś o wgranie plików do Twojego folderu przez link. Udostępnione innym, Udostępnione linkiem i Udostępnione Tobie '
            . 'to listy według tego, kto komu dał dostęp. Usunięte udostępnienia pokazują dostęp, z którego zrezygnowałeś. Możesz go tam przywrócić.</p>'],
        ...itPortalCloudShot('nc-udostepnienia-menu.jpg', 'Rozwinięte menu Udostępnienia: Oczekujące udostępnienia, Prośba o plik, Udostępnione innym, Udostępnione linkiem, Udostępnione Tobie, Usunięte udostępnienia; po prawej napis Brak udostępnień',
            'Konto bez udostępnień. Gdy coś udostępnisz albo ktoś udostępni coś Tobie, pojawi się tutaj.'),

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Pliki z danymi osobowymi udostępniaj wewnętrznie, osobom, które ich potrzebują. '
            . 'Jeśli musisz wysłać je poza Fundację, użyj linku publicznego z hasłem i datą wygaśnięcia, a hasło przekaż inną drogą, na przykład SMS-em.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Gdy czegoś nie możesz udostępnić albo nie widzisz przycisku z tego opisu, '
            . '<a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
    ];
}

/**
 * Bloki artykułu „Menu aplikacji i poruszanie się po chmurze” (polecenie 2026-11-14 ze zrzutem rozwiniętego menu aplikacji: „opisz launcher
 * i menu nawigacyjne”). Menu aplikacji: Pulpit, Pliki, Biuro, Zdjęcia, Zespoły, Aktywność, Sklep aplikacji, niżej skrót Text document.
 * Menu konta zwykłego użytkownika bez pozycji administratora.
 */
function itPortalCloudNavBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'text', 'html' => '<p>Chmura składa się z kilku aplikacji, na przykład Plików, Zdjęć i Aktywności. Przełączasz się między nimi menu aplikacji, '
            . 'a w każdej aplikacji poruszasz się menu po lewej stronie. Górny pasek z wyszukiwarką, powiadomieniami i menu konta jest wszędzie taki sam.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Lista aplikacji w Twoim menu może być inna niż w tym opisie. Zależy od Twojego konta '
            . 'i od tego, które aplikacje włączymy w chmurze. Po uruchomieniu instalacji produkcyjnej może ich przybyć.</p>'],

        ['type' => 'text', 'html' => '<h2>Menu aplikacji</h2>'
            . '<p>Menu aplikacji otwierasz ikoną z dziewięcioma kropkami w lewym górnym rogu, obok logo. Rozwinie się okienko z kafelkami. '
            . 'Kliknij kafelek, żeby otworzyć aplikację. Nazwa aplikacji, w której jesteś, jest pogrubiona i widać ją też obok ikony z kropkami.</p>'
            . '<p>Pulpit to strona startowa z kartami polecanych plików i ostatniej aktywności. Opisuje ją artykuł <a href="' . $base . 'pulpit">Ekran po zalogowaniu</a>.</p>'
            . '<p>Pliki to wszystkie Twoje pliki i foldery oraz te, które Ci udostępniono. Opisują je artykuły <a href="' . $base . 'menu-plikow">Co jest co w menu bocznym aplikacji Pliki</a> '
            . 'i <a href="' . $base . 'lista-plikow">Lista plików</a>.</p>'
            . '<p>Biuro służy do pracy z dokumentami biurowymi w przeglądarce.</p>'
            . '<p>Zdjęcia pokazują wszystkie zdjęcia i filmy z Twoich folderów w jednym miejscu, ułożone według daty. Zdjęć nie trzeba nigdzie przenosić, aplikacja sama je znajduje.</p>'
            . '<p>Zespoły to lista zespołów, do których należysz, i miejsce na założenie własnego zespołu. Zespół to coś innego niż grupa z SZO, '
            . 'co wyjaśnia artykuł <a href="' . $base . 'grupy">Grupy w chmurze</a>.</p>'
            . '<p>Aktywność to pełna historia zmian w Twoich plikach: kto i kiedy dodał, zmienił, usunął albo udostępnił plik. '
            . 'Po lewej przefiltrujesz ją, na przykład tylko do udostępnień albo tylko do Twoich zmian. Opisuje ją artykuł <a href="' . $base . 'aktywnosc">Aktywność</a>.</p>'
            . '<p>Sklep aplikacji z ikoną plusa prowadzi do zewnętrznej strony z dodatkami do Nextcloud. Dodatki włącza administrator chmury, '
            . 'więc nie musisz z tej pozycji korzystać. Jeśli brakuje Ci jakiejś aplikacji, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'
            . '<p>Pod kreską jest skrót „Text document”. Tworzy od razu nowy plik tekstowy, w którym możesz pisać notatki.</p>'
            . '<p>Menu zamkniesz klawiszem Escape albo klikając w dowolne miejsce poza nim.</p>'],

        ['type' => 'text', 'html' => '<h2>Górny pasek</h2>'
            . '<p>Logo po lewej zawsze przenosi na Pulpit. Pole „Aplikacje, pliki, wiadomości i nie tylko” na środku szuka w całej chmurze: w nazwach aplikacji, plików i folderów, '
            . 'w kontaktach i w treści niektórych plików. Otworzysz je też skrótem Ctrl+K, a na Macu Cmd+K.</p>'
            . '<p>Po prawej są dzwonek z powiadomieniami, ikona kontaktów do szybkiego wyszukania osoby z chmury i kółko z Twoimi inicjałami. '
            . 'Powiadomienia opisuje artykuł <a href="' . $base . 'powiadomienia">Powiadomienia w chmurze plików</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Menu konta</h2>'
            . '<p>Kliknij kółko z inicjałami w prawym górnym rogu. Na górze menu jest Twoje imię i nazwisko oraz „Zobacz profil”, czyli strona, '
            . 'którą widzą inni użytkownicy chmury. Niżej są te pozycje:</p><ul>'
            . '<li>„Ustaw status”: dostępny, zaraz wracam, nie przeszkadzać albo niewidoczny, z krótkim opisem i czasem wygaśnięcia.</li>'
            . '<li>„Wygląd i dostępność”: motyw jasny albo ciemny, motyw o wysokim kontraście, czcionka łatwiejsza do czytania dla osób z dysleksją i tło strony.</li>'
            . '<li>„Ustawienia osobiste”: dane konta, język, powiadomienia i bezpieczeństwo, w tym lista urządzeń połączonych z Twoim kontem. '
            . 'Opisuje je artykuł <a href="' . $base . 'ustawienia-osobiste">Ustawienia osobiste w chmurze</a>.</li>'
            . '<li>„Pomoc”: podręcznik Nextcloud.</li>'
            . '<li>„Wyloguj się”: kończy pracę w chmurze w tej przeglądarce.</li></ul>'
            . '<p>Hasła nie zmieniaj w ustawieniach osobistych chmury. Hasło IAM zmienisz na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>.</p>'],

        ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Jeśli źle widzisz albo męczy Cię jasny ekran, w „Wyglądzie i dostępności” włącz motyw o wysokim kontraście '
            . 'albo ciemny. Ustawienie działa od razu i zostaje na Twoim koncie, także na innym komputerze.</p>'],

        ['type' => 'text', 'html' => '<h2>Menu po lewej w aplikacjach</h2>'
            . '<p>Prawie każda aplikacja ma po lewej własne menu, na przykład Pliki mają „Wszystkie pliki”, „Ostatnie” i „Ulubione”. '
            . 'Na dole tego menu zwykle są ustawienia danej aplikacji. Menu chowasz i pokazujesz ikoną prostokąta z paskiem nad treścią. '
            . 'Na wąskim ekranie, na przykład na telefonie, menu jest schowane od początku.</p>'],

        ['type' => 'text', 'html' => '<h2>Obsługa z klawiatury</h2>'
            . '<p>Na początku każdej strony chmury jest ukryty link „Przejdź do głównej treści”, który pokazuje się po pierwszym naciśnięciu Tab. '
            . 'Klawiszem Tab przechodzisz między przyciskami, Enter albo spacja otwiera menu i pozycje, a Escape zamyka otwarte menu i okna. '
            . 'Aplikacja Pliki ma własne skróty, na przykład Delete usuwa zaznaczony plik, a F2 zmienia jego nazwę. Ich listę pokazuje znak zapytania na klawiaturze.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie widzisz w menu aplikacji, o której mówią koledzy? Może być włączona tylko dla części osób. '
            . '<a href="' . $hd . '">Napisz do Helpdesku IT</a>, a sprawdzimy.</p>'],
    ];
}

/**
 * Bloki artykułu „Pulpit z oknami (Desktop Workspace)” (polecenie 2026-11-14 z opisem dodatku Desktop Workspace ze sklepu Nextcloud: „opisz też to”).
 * Opis według dokumentacji dodatku; nazwy w chmurze mogą być po angielsku. Ustawienia użytkownika: Ustawienia osobiste → Pulpit.
 */
function itPortalCloudWorkspaceBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'text', 'html' => '<p>Desktop Workspace to dodatek, który zamienia chmurę w przeglądarce w coś podobnego do pulpitu komputera. '
            . 'Aplikacje chmury, na przykład Pliki czy Aktywność, otwierają się w oknach, które możesz przesuwać, zmieniać ich rozmiar i trzymać kilka obok siebie. '
            . 'Na dole jest pasek zadań, a na pulpicie ikony.</p>'
            . '<p>Z pulpitu z oknami nie musisz korzystać. Zwykły widok chmury, opisany w pozostałych artykułach, działa tak samo jak wcześniej.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Dodatek jest nowy i część jego funkcji jest oznaczona jako eksperymentalna. Nazwy w nim mogą być po angielsku, '
            . 'a wygląd może się zmieniać po aktualizacjach. Jeśli czegoś nie ma tam, gdzie opisujemy, poszukaj w menu aplikacji albo w ustawieniach osobistych w części „Pulpit”.</p>'],

        ['type' => 'text', 'html' => '<h2>Okna i pasek zadań</h2>'
            . '<p>Każda otwarta aplikacja ma własne okno z paskiem tytułu i przyciskami do zmniejszenia, powiększenia i zamknięcia. Okno przesuniesz, chwytając pasek tytułu myszą, '
            . 'a rozmiar zmienisz, ciągnąc za brzeg albo róg.</p>'
            . '<p>Pasek zadań na dole pokazuje otwarte okna. Kliknij okno na pasku, żeby je wysunąć na wierzch albo schować. Aplikacje, z których korzystasz najczęściej, '
            . 'przypniesz do paska, żeby otwierać je jednym kliknięciem.</p>'
            . '<p>Chmura zapamiętuje układ okien, menu aplikacji i ikon. Po ponownym wejściu zobaczysz pulpit taki, jaki zostawiłeś.</p>'],

        ['type' => 'text', 'html' => '<h2>Ikony i folder pulpitu</h2>'
            . '<p>Na pulpicie możesz ułożyć ikony aplikacji. Jeśli włączysz folder pulpitu, na pulpicie pokażą się też pliki z wybranego folderu w chmurze, '
            . 'tak jak pliki na pulpicie komputera. Folder wskazujesz w ustawieniach pulpitu. Pliki nadal leżą w chmurze, pulpit tylko je pokazuje.</p>'],

        ['type' => 'text', 'html' => '<h2>Ustawienia pulpitu</h2>'
            . '<p>Kliknij swoje inicjały, wybierz „Ustawienia osobiste”, a po lewej „Pulpit”. Ustawisz tam ikony na pulpicie, folder pulpitu, wygląd ramek okien '
            . 'i funkcje eksperymentalne, na przykład menedżer plików w oknie. Jest tam też przycisk, który przywraca pulpit do ustawień początkowych. '
            . 'Użyj go, gdy okna się pogubią albo pulpit przestanie się dobrze wyświetlać. Twoje pliki zostaną bez zmian.</p>'
            . '<p>Funkcje eksperymentalne włączaj tylko wtedy, gdy chcesz je wypróbować. Mogą działać niestabilnie.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Jeśli korzystasz z czytnika ekranu albo tylko z klawiatury, pulpit z oknami może być trudny w obsłudze. '
            . 'Zostań przy zwykłym widoku chmury albo pracuj w aplikacji Nextcloud na komputerze, opisanej w artykule '
            . '<a href="' . $base . 'aplikacja-komputer">Aplikacja Nextcloud na komputerze</a>.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Coś na pulpicie nie działa, a przywrócenie ustawień początkowych nie pomaga? '
            . '<a href="' . $hd . '">Napisz do Helpdesku IT</a> i opisz, co się dzieje.</p>'],
    ];
}

/**
 * Bloki artykułu „Aktywność: historia zmian w chmurze” (polecenie 2026-11-14 ze zrzutami nc.feer.org.pl/apps/activity: „opisz to”).
 * Filtry po lewej jak na zrzucie, wyszukiwanie po nazwie pliku, „Dowolna osoba”, „Dowolny czas”; wpis o haśle zresetowanym przez administratora
 * pojawia się, gdy SZO ustawia hasło w chmurze (/nowehaslo, zakładanie konta).
 */
function itPortalCloudActivityBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $nh = $app . '/nowehaslo';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'text', 'html' => '<p>Aplikacja Aktywność to pełna historia tego, co działo się w Twojej chmurze: kto dodał, zmienił, usunął albo udostępnił plik, '
            . 'kto skomentował plik i co zmieniło się na Twoim koncie. Otworzysz ją z menu aplikacji, ikoną błyskawicy. '
            . 'Krótki skrót tej listy jest też na Pulpicie w karcie „Ostatnia aktywność”.</p>'],

        ['type' => 'text', 'html' => '<h2>Lista zdarzeń</h2>'
            . '<p>Zdarzenia są ułożone od najnowszych i podzielone na dni: Dzisiaj, Wczoraj i starsze daty. Przy każdym zdarzeniu jest ikona rodzaju zmiany, '
            . 'opis i czas po prawej, na przykład „27 minut temu”. Zielony plus oznacza dodanie pliku, strzałki w kółko oznaczają zmianę, a ikona kalendarza wydarzenie w kalendarzu.</p>'
            . '<p>Pogrubione nazwy plików są linkami. Kliknij nazwę albo miniaturę pod opisem, żeby otworzyć plik. Gdy naraz zmieniło się kilka plików, '
            . 'chmura łączy je w jedno zdarzenie z listą nazw i miniaturami.</p>'],

        ['type' => 'text', 'html' => '<h2>Wyszukiwanie i filtry nad listą</h2>'
            . '<p>Pole „Wyszukaj według nazwy pliku” pokazuje tylko zdarzenia dotyczące plików o pasującej nazwie. Przydaje się, gdy chcesz sprawdzić, kto ostatnio zmienił konkretny dokument.</p>'
            . '<p>„Dowolna osoba” zawęża listę do zmian jednej osoby. „Dowolny czas” zawęża ją do wybranego okresu, na przykład ostatniego tygodnia. '
            . 'Nie każdy widok ma oba filtry.</p>'],

        ['type' => 'text', 'html' => '<h2>Widoki w menu po lewej</h2>'
            . '<p>Wszystkie aktywności. Wszystko naraz.</p>'
            . '<p>Skupienie. Wybrane, ważniejsze zdarzenia, bez drobnych zmian.</p>'
            . '<p>Przez Ciebie i Przez innych. Tylko Twoje działania albo tylko działania innych osób w plikach, do których masz dostęp.</p>'
            . '<p>Zespoły. Zdarzenia w zespołach, do których należysz.</p>'
            . '<p>Ulubione. Zmiany w plikach i folderach oznaczonych gwiazdką.</p>'
            . '<p>Zmiany w plikach. Dodawanie, zmiana, przenoszenie i usuwanie plików.</p>'
            . '<p>Bezpieczeństwo. Zdarzenia na Twoim koncie, na przykład zmiana hasła albo logowanie z nowego urządzenia.</p>'
            . '<p>Udostępniony plik. Udostępnienia, które dostałeś i które dałeś innym.</p>'
            . '<p>Kalendarz, Zadania i Kontakty. Zmiany w tych aplikacjach, jeśli z nich korzystasz.</p>'
            . '<p>Komentarze. Komentarze do plików, także te, w których ktoś Cię oznaczył.</p>'
            . '<p>Mapa cieplna. Wykres, który pokazuje, w które dni było najwięcej zmian.</p>'
            . '<p>Gdy w wybranym widoku nic się nie działo, zobaczysz napis „Brak aktywności”. To nie błąd.</p>'],

        ['type' => 'text', 'html' => '<h2>Wpisy, które mogą zaskoczyć</h2>'
            . '<p>„Twoje hasło zostało zresetowane przez administratora” pojawia się, gdy hasło w chmurze ustawia SZO, na przykład po zmianie hasła na stronie '
            . '<a href="' . $nh . '">Nowe hasło</a> albo przy zakładaniu konta. Jeśli w tym czasie niczego nie zmieniałeś, od razu zmień hasło IAM i '
            . '<a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'
            . '<p>Wpisy o wydarzeniach w kalendarzu „Contact birthdays” dotyczą kalendarza urodzin, który chmura sama tworzy z dat urodzenia w Twoich kontaktach. '
            . 'Nie musisz nic z nimi robić.</p>'
            . '<p>Wpisy „Utworzyłeś” z plikami takimi jak Readme.md, Nextcloud Manual.pdf czy Nextcloud intro.mp4 z dnia założenia konta to przykładowe pliki, '
            . 'które chmura dodaje do każdego nowego konta.</p>'
            . '<p>Wpisy od „SZO (konto techniczne)” oznaczają, że SZO dodało Cię do grupy albo udostępniło Ci folder. Więcej w artykule <a href="' . $base . 'grupy">Grupy w chmurze</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Ustawienia aktywności</h2>'
            . '<p>Na dole menu po lewej jest „Ustawienia aktywności”. Wybierzesz tam, które zdarzenia mają trafiać na listę i o których chmura ma Cię zawiadamiać e-mailem. '
            . 'To te same ustawienia co w części „Powiadomienia” ustawień osobistych, opisane w artykule <a href="' . $base . 'powiadomienia">Powiadomienia w chmurze plików</a>.</p>'],

        ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Ktoś zmienił albo usunął ważny plik? W Aktywności sprawdzisz, kto i kiedy to zrobił. '
            . 'Usunięty plik przywrócisz z „Usuniętych plików” w aplikacji Pliki, a starszą wersję pliku z zakładki Wersje w panelu szczegółów pliku.</p>'],
    ];
}

/**
 * Bloki artykułu „Ustawienia osobiste w chmurze: profil, język, bezpieczeństwo” (polecenie 2026-11-14 ze zrzutem Ustawień → Personal → Profile & contact).
 * Pozycje menu jak na zrzucie; część nazw jest jeszcze po angielsku. Zrzut użytkownika nie trafił do artykułu (dane osobowe).
 */
function itPortalCloudSettingsBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $nh = $app . '/nowehaslo';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'text', 'html' => '<p>Ustawienia osobiste otworzysz, klikając kółko ze swoimi inicjałami w prawym górnym rogu i wybierając „Ustawienia osobiste”. '
            . 'Po lewej jest lista części ustawień, a po prawej ustawienia wybranej części. Zmiany zapisują się same, bez osobnego przycisku.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Część nazw w ustawieniach jest na razie po angielsku, na przykład „Personal”, „Profile &amp; contact” i „Language &amp; locale”. '
            . 'To brak tłumaczenia w programie. Po aktualizacji nazwy mogą zmienić się na polskie.</p>'],

        ['type' => 'text', 'html' => '<h2>Profil i kontakt (Profile &amp; contact)</h2>'
            . '<p>Na górze jest Twoje zdjęcie albo inicjały i imię z nazwiskiem. Przełącznik „Nextcloud profile” włącza stronę profilu, którą widzą inni użytkownicy chmury, '
            . 'a „View full profile” pokazuje, jak ta strona wygląda.</p>'
            . '<p>„Wyślij zdjęcie profilowe” wgrywa zdjęcie z komputera, a „Wybierz z plików Nextcloud” bierze je z chmury. Zdjęcie musi być w formacie PNG albo JPG. '
            . 'Zdjęcie pomaga współpracownikom rozpoznać, kto udostępnił plik, ale nie musisz go dodawać.</p>'
            . '<p>Niżej są pola z danymi o Tobie: pełna nazwa, zaimki, lokalizacja, a po przewinięciu między innymi e-mail, telefon, organizacja, rola i krótki opis. Wszystkie poza imieniem i nazwiskiem są dobrowolne.</p>'
            . '<p>Ikona po prawej stronie każdego pola mówi, kto widzi tę informację. Kłódka oznacza, że widzisz ją tylko Ty. Ikona z kilkoma osobami oznacza, '
            . 'że widzą ją inni użytkownicy chmury Fundacji. Kliknij ikonę, żeby to zmienić. Prywatny numer telefonu i adres zostaw z kłódką.</p>'
            . '<p>Imię i nazwisko oraz adres e-mail chmura dostaje z SZO. Jeśli są błędne, nie poprawiaj ich tutaj, bo przy kolejnym logowaniu mogą wrócić do dawnej wersji. '
            . 'Popraw je w SZO albo <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Język i region (Language &amp; locale)</h2>'
            . '<p>Wybierzesz tu język chmury oraz format daty i godziny i pierwszy dzień tygodnia. Dla polskiego ustaw język polski i region Polska. '
            . 'Zmiana języka działa po odświeżeniu strony.</p>'],

        ['type' => 'text', 'html' => '<h2>Bezpieczeństwo</h2>'
            . '<p>Najważniejsza jest tu lista urządzeń i sesji, czyli przeglądarek i aplikacji, które są zalogowane na Twoje konto. Przy każdym urządzeniu jest menu, '
            . 'którym je wylogujesz. Przejrzyj listę od czasu do czasu, a zgubiony telefon albo cudzy komputer od razu usuń.</p>'
            . '<p>Możesz tu też utworzyć hasło aplikacji, czyli osobne hasło dla programu, który łączy się z chmurą bez logowania jednokrotnego, na przykład przez WebDAV. '
            . 'Hasło aplikacji widzisz tylko raz, więc od razu wpisz je w program. Gdy przestaniesz z niego korzystać, usuń to hasło z listy.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie zmieniaj tu swojego hasła, nawet jeśli zobaczysz takie pole. Hasło zmienione w chmurze przestanie pasować do SZO. '
            . 'Hasło IAM zmienisz na stronie <a href="' . $nh . '">Nowe hasło</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Pozostałe części</h2>'
            . '<p>Powiadomienia. Wybierasz, o czym chmura ma Cię zawiadamiać e-mailem i jak często. Szczegóły są w artykule <a href="' . $base . 'powiadomienia">Powiadomienia w chmurze plików</a>.</p>'
            . '<p>Wygląd i dostępność. Motyw jasny, ciemny albo o wysokim kontraście, czcionka dla osób z dysleksją i tło. Opisuje to artykuł '
            . '<a href="' . $base . 'menu-aplikacji">Menu aplikacji i poruszanie się po chmurze</a>.</p>'
            . '<p>Udostępnianie. Ustawisz, czy przyjmować udostępnienia od innych automatycznie, czy najpierw je akceptować. '
            . 'Jest tu też Twój identyfikator do udostępniania między różnymi chmurami Nextcloud. Podaj go tylko osobie spoza Fundacji, która ma własną chmurę i chce Ci coś udostępnić.</p>'
            . '<p>Dostępność. Godziny pracy i informacja o nieobecności, na przykład w czasie urlopu. Inni zobaczą ją przy Twoim nazwisku.</p>'
            . '<p>Przepływ. Reguły, które same coś robią z plikami, na przykład oznaczają je etykietą. Na co dzień nie są potrzebne.</p>'
            . '<p>Prywatność. Informacja o tym, gdzie są przechowywane Twoje dane i kto ma do nich dostęp.</p>'
            . '<p>Pulpit. Ustawienia pulpitu z oknami, jeśli z niego korzystasz. Opisuje je artykuł <a href="' . $base . 'pulpit-roboczy">Pulpit z oknami (Desktop Workspace)</a>.</p>'
            . '<p>Migracja danych. Eksport całego konta do jednego pliku albo wczytanie takiego pliku. Nie korzystaj z tego bez porozumienia z Helpdeskiem IT. '
            . 'Przeniesienie plików między instalacjami chmury Fundacji zaplanujemy sami.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie wiesz, czy coś zmieniać? Zostaw ustawienia domyślne i <a href="' . $hd . '">zapytaj w Helpdesku IT</a>.</p>'],
    ];
}

/**
 * Bloki artykułu „Powiadomienia w chmurze plików” (polecenie 2026-11-14: „opisz powiadomienia NC”). Dzwonek w górnym pasku, rodzaje powiadomień,
 * ustawienia powiadomień e-mail (Ustawienia osobiste → Powiadomienia), status „Nie przeszkadzać”, powiadomienia w aplikacjach.
 */
function itPortalCloudNotifyBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'text', 'html' => '<p>Chmura daje znać, gdy dzieje się coś, co Cię dotyczy: ktoś udostępnił Ci plik albo folder, wspomniał o Tobie w komentarzu '
            . 'albo zmienił coś w udostępnionych plikach. Powiadomienia widzisz w chmurze pod dzwonkiem, a część z nich także w wiadomościach e-mail i w aplikacjach.</p>'],

        ['type' => 'text', 'html' => '<h2>Dzwonek w górnym pasku</h2>'
            . '<p>Dzwonek jest w prawym górnym rogu, obok ikony kontaktów i Twoich inicjałów. Czerwona kropka przy nim oznacza, że masz nieprzeczytane powiadomienia. '
            . 'Kliknij dzwonek, a otworzy się lista, najnowsze na górze.</p>'
            . '<p>Kliknij powiadomienie, żeby przejść do pliku albo folderu, którego dotyczy. Niektóre powiadomienia mają własne przyciski, na przykład „Akceptuj” '
            . 'i „Odrzuć” przy udostępnieniu, które trzeba przyjąć. Takie udostępnienie zobaczysz też w menu bocznym aplikacji Pliki w „Oczekujących udostępnieniach”.</p>'
            . '<p>Krzyżyk przy powiadomieniu usuwa je z listy. Przycisk na dole listy usuwa wszystkie naraz. Usunięcie powiadomienia nie zmienia niczego w plikach.</p>'],

        ['type' => 'text', 'html' => '<h2>Co zobaczysz w powiadomieniach</h2>'
            . '<p>Najczęściej będą to nowe udostępnienia od osób z Fundacji i od SZO. Konto techniczne SZO dodaje Cię do grup i udostępnia foldery, '
            . 'gdy podpiszesz umowę albo dołączysz do obszaru lub działania, więc powiadomienia z napisem „SZO (konto techniczne)” są normalne. '
            . 'Więcej o tym piszemy w artykule <a href="' . $base . 'grupy">Grupy w chmurze</a>.</p>'
            . '<p>Dostaniesz też powiadomienie, gdy ktoś oznaczy Cię w komentarzu do pliku, wpisując @ i Twoje imię, gdy zbliża się koniec ważności udostępnionego linku '
            . 'albo gdy brakuje Ci miejsca. Pełną historię zmian w plikach, także tych bez powiadomienia, pokazuje aplikacja Aktywność w menu aplikacji.</p>'],

        ['type' => 'text', 'html' => '<h2>Powiadomienia e-mail i ich ustawienia</h2>'
            . '<p>Część powiadomień chmura wysyła też e-mailem na adres z Twojego konta. Jeśli dostajesz ich za dużo albo za mało, zmień to sam. '
            . 'Kliknij kółko ze swoimi inicjałami, wybierz „Ustawienia osobiste”, a potem w menu po lewej „Powiadomienia”.</p>'
            . '<p>Zobaczysz listę zdarzeń, na przykład udostępnienie pliku, zmianę albo usunięcie pliku i komentarz. Przy każdym zaznaczasz, czy chcesz o nim wiadomość e-mail, '
            . 'czy wystarczy powiadomienie w chmurze i aplikacjach. Niżej ustawisz, jak często chmura ma wysyłać e-mailowe podsumowanie, na przykład raz na godzinę albo raz dziennie.</p>'],

        ['type' => 'text', 'html' => '<h2>Gdy nie chcesz, żeby Ci przeszkadzano</h2>'
            . '<p>Kliknij swoje inicjały, wybierz „Ustaw status” i zaznacz „Nie przeszkadzać”. Chmura przestanie pokazywać wyskakujące powiadomienia, '
            . 'ale nadal będzie je zbierać pod dzwonkiem. Po powrocie zmień status z powrotem na „Dostępny”. Status możesz też ustawić tak, żeby sam się wyłączył po jakimś czasie.</p>'],

        ['type' => 'text', 'html' => '<h2>Powiadomienia w aplikacjach</h2>'
            . '<p>Aplikacja Nextcloud na komputerze pokazuje te same powiadomienia w swoim oknie i jako dymki systemowe. Aplikacja na telefonie może wysyłać powiadomienia na ekran telefonu. '
            . 'Gdy ich nie dostajesz, sprawdź w ustawieniach telefonu, czy aplikacja Nextcloud ma zgodę na powiadomienia. Na Androidzie bez usług Google, '
            . 'na przykład z aplikacją z F-Droid, powiadomienia na ekran mogą nie działać. Opisują to artykuły '
            . '<a href="' . $base . 'aplikacja-komputer">Aplikacja Nextcloud na komputerze</a> i <a href="' . $base . 'aplikacja-telefon">Aplikacja Nextcloud na telefonie</a>.</p>'
            . '<p>Przeglądarka może zapytać, czy pozwolić stronie chmury pokazywać powiadomienia. Zgódź się na swoim komputerze, a na wspólnym komputerze odmów.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Uważaj na fałszywe wiadomości, które udają powiadomienia z chmury. Prawdziwe powiadomienie prowadzi zawsze na adres chmury Fundacji. '
            . 'Gdy wiadomość prosi o hasło na innej stronie, nie klikaj w nią, tylko wejdź do chmury sam, wpisując jej adres, i sprawdź dzwonek.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie dostajesz powiadomień, choć ktoś mówi, że coś Ci udostępnił? Sprawdź „Oczekujące udostępnienia” w aplikacji Pliki. '
            . 'Jeśli tam też nic nie ma, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
    ];
}

/**
 * Bloki artykułu „Aplikacja Nextcloud na komputerze: jak z niej korzystać” (polecenie 2026-11-14: „opisz też aplikację Nextcloud na komputerze”).
 * Bez zrzutów; Windows i macOS różnią się wyglądem, więc opis podaje obie drogi i ramkę o różnicach.
 */
function itPortalCloudDesktopBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    return [
        ['type' => 'text', 'html' => '<p>Aplikacja Nextcloud na komputerze łączy chmurę z folderem na dysku. Pliki z chmury widzisz w Eksploratorze plików na Windows '
            . 'albo w Finderze na Macu i pracujesz na nich jak na zwykłych plikach. Każdą zmianę aplikacja sama wysyła do chmury, a zmiany innych osób pobiera na komputer.</p>'
            . '<p>Jak zainstalować aplikację i połączyć ją z chmurą, opisuje artykuł <a href="' . $base . 'instalacja-aplikacji">Instalacja aplikacji Nextcloud na komputerze i telefonie</a>. '
            . 'Przy logowaniu na razie najpierw zobaczysz stronę logowania Nextcloud. Kliknij na niej „Login with logowanie jednokrotne” i zaloguj się na stronie IAM. '
            . 'Docelowo strona IAM będzie otwierać się od razu.</p>'],
        ...itPortalCloudDownloadButtons(true, false),

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Aplikacja wygląda trochę inaczej na Windows, macOS i Linuksie, a nazwy pozycji mogą się zmieniać po aktualizacji. '
            . 'Jeśli czegoś nie widzisz tam, gdzie opisujemy, poszukaj w menu pod prawym przyciskiem myszy albo w ustawieniach aplikacji.</p>'],

        ['type' => 'text', 'html' => '<h2>Ikona aplikacji</h2>'
            . '<p>Działająca aplikacja ma ikonę chmurki. Na Windows jest w zasobniku obok zegara. Jeśli jej nie widać, kliknij strzałkę w górę obok zegara, bo Windows chowa część ikon. '
            . 'Na Macu ikona jest na pasku menu u góry ekranu.</p>'
            . '<p>Znaczek na ikonie mówi, co się dzieje. Zielony znaczek z ptaszkiem oznacza, że wszystko jest zsynchronizowane. Niebieskie strzałki oznaczają, że pliki właśnie się wysyłają albo pobierają. '
            . 'Żółty albo pomarańczowy wykrzyknik to ostrzeżenie, na przykład o pliku, którego nie da się wysłać. Czerwony znak oznacza błąd, na przykład brak połączenia albo potrzebę ponownego zalogowania. '
            . 'Znak pauzy oznacza, że synchronizacja jest wstrzymana.</p>'],

        ['type' => 'text', 'html' => '<h2>Okno aplikacji</h2>'
            . '<p>Kliknij ikonę chmurki, a otworzy się małe okno. Na górze jest Twoje konto i przyciski: otwarcie folderu z plikami na komputerze '
            . 'i otwarcie chmury w przeglądarce. Niżej jest lista ostatnich zmian, czyli pliki, które aplikacja niedawno wysłała albo pobrała, '
            . 'oraz powiadomienia, na przykład o tym, że ktoś Ci coś udostępnił. Ostrzeżenia i błędy też pojawiają się na tej liście, z opisem, którego pliku dotyczą.</p>'
            . '<p>Menu przy nazwie konta albo przycisk z trzema kropkami otwiera ustawienia, wstrzymanie synchronizacji i wylogowanie.</p>'],

        ['type' => 'text', 'html' => '<h2>Folder Nextcloud na dysku</h2>'
            . '<p>Na Windows folder Nextcloud jest w lewym panelu Eksploratora plików. Na Macu znajdziesz go w Finderze na pasku bocznym, w części „Lokalizacje”. '
            . 'Wszystko, co tam zapiszesz, skopiujesz albo przeciągniesz, trafi do chmury. Program, w którym pracujesz, na przykład Word, zapisuje plik prosto do tego folderu.</p>'
            . '<p>Przy plikach i folderach są małe znaczki. Zielony ptaszek oznacza, że plik jest na komputerze i w chmurze w tej samej wersji. '
            . 'Chmurka oznacza, że plik jest tylko w chmurze i pobierze się, gdy go otworzysz. Niebieskie strzałki oznaczają, że plik się właśnie synchronizuje. '
            . 'Czerwony znak oznacza problem z tym plikiem. Znaczek z osobami pokazuje, że plik albo folder jest udostępniony.</p>'],

        ['type' => 'text', 'html' => '<h2>Pliki wirtualne i miejsce na dysku</h2>'
            . '<p>Jeśli przy instalacji wybrałeś pliki wirtualne, na komputerze widzisz wszystkie pliki z chmury, ale zajmują miejsce dopiero po otwarciu. '
            . 'Kliknij plik albo folder prawym przyciskiem myszy. „Zawsze przechowuj na tym urządzeniu” pobierze go na stałe, żeby był dostępny bez internetu. '
            . '„Zwolnij miejsce” usunie kopię z dysku, a plik zostanie w chmurze. Na Macu te polecenia mogą nazywać się „Pobierz teraz” i „Usuń pobraną kopię”.</p>'],

        ['type' => 'text', 'html' => '<h2>Udostępnianie z komputera</h2>'
            . '<p>Kliknij plik albo folder w folderze Nextcloud prawym przyciskiem myszy i wybierz pozycję Nextcloud. Na Windows 11 może być ukryta pod „Pokaż więcej opcji”. '
            . 'Znajdziesz tam udostępnianie, skopiowanie linku wewnętrznego, otwarcie pliku w przeglądarce i historię zmian. '
            . 'Udostępnianie działa tak samo jak na stronie, co opisuje artykuł <a href="' . $base . 'lista-plikow">Lista plików: przyciski, ikony i udostępnianie</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Ustawienia</h2>'
            . '<p>W ustawieniach konta wybierzesz, które foldery z chmury mają być na tym komputerze, dodasz kolejny folder do synchronizacji albo wstrzymasz synchronizację, '
            . 'na przykład na czas pracy przez słabe łącze. W ustawieniach ogólnych włączysz albo wyłączysz uruchamianie aplikacji razem z komputerem i powiadomienia. '
            . 'W ustawieniach sieci ograniczysz prędkość wysyłania i pobierania.</p>'
            . '<p>Zostaw uruchamianie razem z komputerem włączone. Gdy aplikacja nie działa, zmiany zostają tylko na Twoim dysku i nikt inny ich nie widzi.</p>'],

        ['type' => 'text', 'html' => '<h2>Konflikty i pliki, które się nie wysyłają</h2>'
            . '<p>Gdy dwie osoby zmienią ten sam plik w tym samym czasie, aplikacja zachowa obie wersje. Obok oryginału pojawi się plik z dopiskiem „conflicted copy” i datą. '
            . 'Otwórz oba, przenieś potrzebne zmiany do jednego pliku i usuń drugi.</p>'
            . '<p>Aplikacja pomija pliki tymczasowe, na przykład te, których nazwa zaczyna się od ~$, tworzone przez Worda w czasie pracy. '
            . 'Nie wyśle też pliku z nazwą, której chmura nie przyjmuje, na przykład ze znakami \\ : * ? " &lt; &gt; |. Ostrzeżenie zobaczysz w oknie aplikacji. Zmień wtedy nazwę pliku.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Plik usunięty z folderu Nextcloud znika też z chmury i u osób, którym go udostępniłeś. Odzyskasz go z kosza na stronie '
            . '<a href="' . $cloud . '">' . $host . '</a> w „Usuniętych plikach”. Gdy usuwasz naraz dużo plików, aplikacja zapyta, czy na pewno. Przeczytaj to pytanie uważnie.</p>'],

        ['type' => 'text', 'html' => '<h2>Odłączenie komputera</h2>'
            . '<p>Jeśli oddajesz komputer albo korzysta z niego ktoś jeszcze, otwórz ustawienia konta w aplikacji i usuń konto. Odłączasz tylko ten komputer. '
            . 'Konto i pliki w chmurze zostają. Pliki na dysku mogą zostać w folderze Nextcloud, więc usuń je ręcznie, jeśli nie mają tam zostać.</p>'
            . '<p>Po zmianie hasła IAM aplikacja poprosi o ponowne zalogowanie. To normalne.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Ikona ciągle pokazuje błąd albo synchronizacja stoi od dawna? Sprawdź, czy masz najnowszą wersję aplikacji ze strony Nextcloud. '
            . 'Jeśli to nie pomoże, <a href="' . $hd . '">napisz do Helpdesku IT</a>, podaj system komputera i przepisz komunikat z okna aplikacji.</p>'],
    ];
}

/**
 * Bloki artykułu „Aplikacja Nextcloud na telefonie: jak z niej korzystać” (polecenie 2026-11-14: „opisz też aplikację Nextcloud na telefonie”).
 * Bez zrzutów; nazwy w aplikacjach na Androida i iPhone'a różnią się, więc opis podaje obie drogi i ramkę o różnicach.
 */
function itPortalCloudPhoneBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $nh = $app . '/nowehaslo';
    $base = $app . '/uslugi-it/uslugi/chmura-plikow/';
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    return [
        ['type' => 'text', 'html' => '<p>Aplikacja Nextcloud na telefonie pokazuje te same pliki i foldery co strona ' . $host . '. Przydaje się w podróży, '
            . 'do zrobienia zdjęcia albo skanu i wysłania go od razu do właściwego folderu. Jak ją zainstalować i się zalogować, opisuje artykuł '
            . '<a href="' . $base . 'instalacja-aplikacji">Instalacja aplikacji Nextcloud na komputerze i telefonie</a>.</p>'
            . '<p>Przy logowaniu w aplikacji na razie najpierw pokaże się strona logowania Nextcloud. Stuknij na niej „Login with logowanie jednokrotne”, '
            . 'a potem zaloguj się na stronie IAM adresem e-mail i hasłem IAM. Docelowo strona IAM będzie otwierać się od razu.</p>'
            . '<p>Telefon nie trzyma kopii całej chmury. Pliki pobierają się dopiero wtedy, gdy je otworzysz, więc aplikacja nie zapcha pamięci.</p>'],
        ...itPortalCloudDownloadButtons(false, true),

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Aplikacje na Androida i na iPhone’a wyglądają trochę inaczej, a nazwy przycisków mogą się zmieniać po aktualizacji. '
            . 'Jeśli czegoś nie widzisz tam, gdzie opisujemy, poszukaj w menu z trzema kropkami albo w ustawieniach aplikacji.</p>'],

        ['type' => 'text', 'html' => '<h2>Ekran główny</h2>'
            . '<p>Po otwarciu aplikacji widzisz listę plików i folderów, tak jak w „Wszystkich plikach” na stronie. Stuknij folder, żeby go otworzyć. '
            . 'Strzałka albo przycisk wstecz telefonu wraca o poziom wyżej.</p>'
            . '<p>Na dole ekranu albo w menu są skróty do innych widoków: ulubionych, zdjęć i filmów, plików udostępnionych, ostatnio zmienionych i plików offline. '
            . 'Na Androidzie menu otworzysz przyciskiem z trzema kreskami w lewym górnym rogu. Na iPhonie część widoków jest na dolnym pasku, a reszta pod przyciskiem „Więcej”.</p>'
            . '<p>Lupa u góry szuka plików po nazwie w całej chmurze. Przeciągnięcie listy w dół odświeża ją, gdy ktoś właśnie dodał plik.</p>'],

        ['type' => 'text', 'html' => '<h2>Dodawanie plików</h2>'
            . '<p>Przycisk z plusem dodaje coś do folderu, który masz otwarty. Wybierzesz tam przesłanie pliku albo zdjęcia z telefonu, zrobienie zdjęcia aparatem, '
            . 'zeskanowanie dokumentu i założenie nowego folderu. Skan zapisuje się jako PDF albo obraz, więc nadaje się do faktur i podpisanych pism.</p>'
            . '<p>Plik możesz też wysłać do chmury z innej aplikacji, na przykład z poczty albo galerii. Stuknij „Udostępnij”, wybierz Nextcloud i wskaż folder.</p>'
            . '<p>Postęp wysyłania pokazuje lista przesyłanych plików. Przy słabym zasięgu wysyłanie poczeka i dokończy się samo.</p>'],

        ['type' => 'text', 'html' => '<h2>Działania na pliku</h2>'
            . '<p>Stuknij plik, żeby go otworzyć. Zdjęcia, PDF-y i pliki tekstowe zobaczysz w aplikacji. Dokumenty, arkusze i prezentacje otworzą się w programie zainstalowanym w telefonie. '
            . 'Jeśli zmieniasz plik w innym programie, sprawdź potem w aplikacji Nextcloud, czy nowa wersja wróciła do chmury.</p>'
            . '<p>Trzy kropki przy pliku, albo dłuższe przytrzymanie palca, otwierają resztę działań: udostępnianie, dodanie do ulubionych, pobranie, '
            . 'zmianę nazwy, przeniesienie lub skopiowanie i usunięcie. Usunięty plik trafia do kosza w chmurze, tak jak na stronie.</p>'],

        ['type' => 'text', 'html' => '<h2>Pliki bez internetu</h2>'
            . '<p>Plik albo cały folder, którego potrzebujesz bez zasięgu, oznacz w menu z trzema kropkami jako „Dostępne offline”. '
            . 'Aplikacja pobierze go i będzie aktualizować, gdy telefon złapie internet. Nie oznaczaj tak dużych folderów, bo zajmą dużo miejsca w telefonie.</p>'],

        ['type' => 'text', 'html' => '<h2>Udostępnianie</h2>'
            . '<p>Wybierz „Udostępnij” w menu pliku. Zobaczysz te same możliwości co w panelu udostępniania na stronie: wyszukasz osobę albo grupę z Fundacji, '
            . 'wyślesz zaproszenie na adres e-mail albo utworzysz link publiczny. Przy linku ustaw hasło i datę wygaśnięcia. Szczegóły opisuje artykuł '
            . '<a href="' . $base . 'lista-plikow">Lista plików: przyciski, ikony i udostępnianie</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Automatyczne wysyłanie zdjęć</h2>'
            . '<p>W ustawieniach aplikacji jest automatyczne wysyłanie zdjęć i filmów z telefonu do chmury. Domyślnie jest wyłączone. Włącz je tylko na telefonie służbowym '
            . 'albo tylko dla folderu ze zdjęciami służbowymi. Prywatnych zdjęć nie wysyłaj do chmury Fundacji. Przy włączaniu warto zaznaczyć wysyłanie tylko przez Wi-Fi.</p>'],

        ['type' => 'text', 'html' => '<h2>Pliki chmury w innych aplikacjach</h2>'
            . '<p>Na iPhonie chmura pojawia się w aplikacji Pliki w części „Przeglądaj” jako Nextcloud. Jeśli jej tam nie ma, stuknij trzy kropki u góry, wybierz „Edytuj” i włącz Nextcloud. '
            . 'Na Androidzie chmurę zobaczysz w oknie wyboru pliku, na przykład przy dodawaniu załącznika do wiadomości. Dzięki temu dołączysz plik z chmury bez pobierania go wcześniej.</p>'],

        ['type' => 'text', 'html' => '<h2>Zabezpieczenie aplikacji</h2>'
            . '<p>Aplikacja pamięta logowanie, więc każdy, kto weźmie Twój odblokowany telefon, zobaczy pliki Fundacji. W ustawieniach aplikacji włącz blokadę kodem '
            . 'albo odciskiem palca czy Face ID. Aplikacja zapyta o nie przy każdym otwarciu.</p>'
            . '<p>Aby odłączyć telefon od chmury, w ustawieniach aplikacji usuń konto albo wyloguj się. Usuwasz tylko połączenie w telefonie. '
            . 'Konto i pliki w chmurze zostają.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Zgubiłeś telefon albo ktoś go ukradł? Od razu zmień hasło IAM na stronie <a href="' . $nh . '">Nowe hasło</a>. '
            . 'Potem na stronie ' . $host . ' kliknij swoje inicjały, wybierz ustawienia osobiste, a w części o bezpieczeństwie usuń ten telefon z listy urządzeń. '
            . 'Daj nam też znać w <a href="' . $hd . '">Helpdesku IT</a>.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Aplikacja się nie loguje albo pliki się nie wysyłają? Sprawdź, czy masz najnowszą wersję ze Sklepu Play albo App Store. '
            . 'Jeśli to nie pomoże, <a href="' . $hd . '">napisz do Helpdesku IT</a> i podaj model telefonu.</p>'],
    ];
}

/**
 * Artykuł w usłudze „Koordynatorzy IT”: „Ustawienia chmury dla Koordynatorów IT: limity folderów zespołów i pulpit z oknami” (polecenie 2026-11-14
 * ze zrzutem Ustawienia → Administration → Zespoły → Default quotas: „też dla IT”, „chodzi o Koordynatorów IT”). Koordynatorzy mają w chmurze
 * dostęp do części ustawień administracyjnych.
 */
function itPortalCloudItAdminApply(string $app, bool $dryRun = false): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'koordynatorzy-it'");
    if (!$cat) { if (!$dryRun) itPortalCloudCoordApply($app); $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'koordynatorzy-it'"); }
    $def = ['Ustawienia chmury dla Koordynatorów IT: limity folderów zespołów i pulpit z oknami',
        'koordynator it, koordynatorzy it, administracja, administrator, ustawienia administracyjne, administration, zespoły, foldery zespołów, team folders, default quotas, domyślny limit, limit, quota, quota overrides, nadpisanie limitu, grupa, desktop workspace, pulpit, dekoracje, menedżer plików, wiele okien, reset pulpitu, nextcloud',
        itPortalCloudItAdminBlocks($app), false, 20];
    return itPortalCloudArticleSave($cat ? (int)$cat['id'] : 0, 'administracja-chmury', $def, $dryRun, 'koordynatorzy-it');
}

function itPortalCloudItAdminBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $coord = $app . '/uslugi-it/uslugi/koordynatorzy-it/grupy-w-chmurze';
    $users = $app . '/uslugi-it/uslugi/chmura-plikow/';
    return [
        ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Ten artykuł jest dla Koordynatorów IT. Jako koordynator masz w chmurze dostęp do części ustawień administracyjnych, '
            . 'między innymi do zespołów i ich folderów. Jeśli po zalogowaniu nie widzisz tych ustawień, <a href="' . $hd . '">napisz do Helpdesku IT</a>. '
            . 'Skład grup z SZO ustawiasz w SZO, co opisuje artykuł <a href="' . $coord . '">Zarządzanie grupami w chmurze</a>.</p>'],

        ['type' => 'text', 'html' => '<p>Ustawienia otworzysz, klikając swoje inicjały i wybierając „Ustawienia administracyjne”. W menu po lewej, pod ustawieniami osobistymi, '
            . 'jest część „Administration” z działami, do których masz dostęp. Część nazw jest po angielsku, bo dodatki nie mają jeszcze tłumaczeń.</p>'],

        ['type' => 'text', 'html' => '<h2>Zespoły: domyślne limity folderów zespołów</h2>'
            . '<p>W części „Administration” wybierz „Zespoły”. Na górze są dwie zakładki: „Foldery zespołów” z listą folderów i ich ustawieniami oraz „Default quotas” z limitami miejsca.</p>'
            . '<p>W zakładce „Default quotas” pole „Domyślny limit” ustala, ile miejsca dostaje każdy folder zespołu założony automatycznie. Wybierz wartość, na przykład 5 GB, '
            . 'i kliknij „Zapisz” obok pola. Wartość 0 oznacza brak limitu. W prawym górnym rogu pojawi się potwierdzenie „Changed default team folder quotas”. '
            . 'Zmiana dotyczy folderów zakładanych od tej chwili. Limit istniejącego folderu zmienisz w zakładce „Foldery zespołów”.</p>'
            . '<p>Część „Quota overrides” pozwala dać innym grupom albo zespołom inny limit domyślny. Wybierz grupę albo zespół z listy „Select a team or group”, kliknij „Dodaj”, '
            . 'ustaw limit w tabeli i kliknij „Zapisz” pod tabelą. Gdy osoba zakładająca folder należy do kilku grup z własnymi limitami, nowy folder dostaje najwyższy z nich.</p>'
            . '<p>Dodatek działa tylko wtedy, gdy w chmurze jest włączona aplikacja Team Folders (foldery zespołów).</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Zanim zmniejszysz limit, sprawdź, ile miejsca zajmują już foldery. Gdy folder przekroczy limit, '
            . 'nikt nie wgra do niego nowych plików, ale nic z niego nie zniknie. Większe zmiany limitów uzgodnij z zespołem IT.</p>'],

        ['type' => 'text', 'html' => '<h2>Desktop Workspace: pulpit z oknami</h2>'
            . '<p>Dodatek Desktop Workspace włącza i wyłącza administrator chmury. Jeśli masz dostęp do jego ustawień, znajdziesz je w części „Administration” pod nazwą dodatku. Ustawisz tam:</p><ul>'
            . '<li>wygląd ramek okien (dekoracje),</li>'
            . '<li>eksperymentalny menedżer plików,</li>'
            . '<li>czy jedna aplikacja może mieć kilka okien naraz,</li>'
            . '<li>reset pulpitu wybranemu użytkownikowi, gdy jego układ okien się popsuł.</li></ul>'
            . '<p>Użytkownicy ustawiają swój pulpit sami w ustawieniach osobistych, w części „Pulpit”. Ich instrukcja to artykuł '
            . '<a href="' . $users . 'pulpit-roboczy">Pulpit z oknami (Desktop Workspace)</a>.</p>'
            . '<p>Funkcje eksperymentalne włączaj najpierw dla siebie i sprawdzaj na koncie testowym. Pamiętaj, że pulpit z oknami jest trudny w obsłudze czytnikiem ekranu, '
            . 'więc zwykły widok chmury musi zostać dostępny.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Jeśli widzisz ustawienia „OpenID Connect” albo „Uprawnienia administracyjne”, nie zmieniaj ich bez uzgodnienia z zespołem IT. '
            . 'Od pierwszego zależy logowanie jednokrotne przez IAM, a od drugiego, kto może zarządzać chmurą. Zgłoś potrzebę zmiany w <a href="' . $hd . '">Helpdesku IT</a>.</p>'],
    ];
}

/**
 * Ściąga dla Koordynatorów IT w artykule „Zarządzanie grupami w chmurze” (polecenie 2026-11-14: „dopisz koordynatorom”, wybór: ściąga w ich artykule).
 * Linki do instrukcji dla użytkowników i najczęstsze pytania z odpowiedzią i artykułem do wysłania.
 */
function itPortalCloudCoordCheatSheet(string $app): array {
    $b = $app . '/uslugi-it/uslugi/chmura-plikow/';
    $a = static fn(string $slug, string $t): string => '<a href="' . $b . $slug . '">' . $t . '</a>';
    $hd = $app . '/helpdesk/new.php';
    return [
        ['type' => 'text', 'html' => '<h2>Ściąga: instrukcje dla użytkowników</h2>'
            . '<p>Gdy ktoś z Twojej grupy pyta o chmurę, wyślij mu link do właściwej instrukcji:</p><ul>'
            . '<li>' . $a('nextcloud', 'Nextcloud: pierwsze kroki w chmurze plików') . '</li>'
            . '<li>' . $a('logowanie', 'Logowanie do chmury plików') . '</li>'
            . '<li>' . $a('pulpit', 'Ekran po zalogowaniu: Pulpit') . ' i ' . $a('menu-aplikacji', 'Menu aplikacji i poruszanie się po chmurze') . '</li>'
            . '<li>' . $a('menu-plikow', 'Co jest co w menu bocznym aplikacji Pliki') . ' i ' . $a('lista-plikow', 'Lista plików: przyciski, ikony i udostępnianie') . '</li>'
            . '<li>' . $a('grupy', 'Grupy w chmurze') . '</li>'
            . '<li>' . $a('powiadomienia', 'Powiadomienia') . ', ' . $a('aktywnosc', 'Aktywność') . ' i ' . $a('ustawienia-osobiste', 'Ustawienia osobiste') . '</li>'
            . '<li>' . $a('instalacja-aplikacji', 'Instalacja aplikacji') . ', ' . $a('aplikacja-komputer', 'Aplikacja na komputerze') . ' i ' . $a('aplikacja-telefon', 'Aplikacja na telefonie') . '</li>'
            . '</ul>'],

        ['type' => 'text', 'html' => '<h2>Ściąga: najczęstsze pytania</h2>'
            . '<p>„Nie mogę się zalogować.” Na razie logowanie ma dwa kroki: strona Nextcloud, przycisk „Login with logowanie jednokrotne”, potem strona IAM. '
            . 'W polu „Nazwa użytkownika” trzeba wpisać pełny adres e-mail. Zapomniane hasło zmienia się na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>, '
            . 'nigdy linkiem „Zapomniałeś hasła?” w chmurze. Wyślij: ' . $a('logowanie', 'Logowanie do chmury plików') . '.</p>'
            . '<p>„Nie widzę folderu grupy.” Sprawdź u siebie okno „Problemy z przypisaniami” i przypisanie tej osoby w SZO. Po poprawce grupa zmieni się w ciągu 2 minut. '
            . 'Folder pojawi się w „Wszystkich plikach”, a czasem najpierw w „Oczekujących udostępnieniach”. Wyślij: ' . $a('grupy', 'Grupy w chmurze') . '.</p>'
            . '<p>„Osoba nie ma konta w chmurze.” Załóż je przyciskiem „Załóż konto” w oknie „Problemy z przypisaniami”. Login i hasło pójdą SMS-em.</p>'
            . '<p>„Gdzie są moje pliki z OneDrive?” W folderze OneDrive na liście plików. SZO kopiuje je w tle, przy dużej ilości nawet kilka godzin. '
            . 'Foldery „Google Drive” i „Kopia konta” to dane z dawnych serwerów. Wyślij: ' . $a('lista-plikow', 'Lista plików') . '.</p>'
            . '<p>„Chmura działa wolno.” Tak, to serwer przedwdrożeniowy. Trzeba chwilę poczekać i odświeżyć stronę. Instalację produkcyjną uruchomimy na początku grudnia 2026 r.</p>'
            . '<p>„W Aktywności jest wpis, że administrator zresetował mi hasło.” To normalne po zmianie hasła na stronie Nowe hasło albo po założeniu konta, bo hasło w chmurze ustawia SZO. '
            . 'Jeśli osoba niczego nie zmieniała, niech od razu zmieni hasło i zgłosi to w Helpdesku. Wyślij: ' . $a('aktywnosc', 'Aktywność') . '.</p>'
            . '<p>„Korzystam z czytnika ekranu.” Poleć aplikację Nextcloud na komputerze albo telefonie. Wyślij: ' . $a('aplikacja-komputer', 'Aplikacja na komputerze') . '.</p>'
            . '<p>„Ktoś usunął albo zmienił plik.” W Aktywności widać, kto i kiedy to zrobił. Plik wraca z „Usuniętych plików”, a starsza wersja z zakładki Wersje.</p>'
            . '<p>Limity miejsca folderów zespołów i ustawienia pulpitu z oknami opisuje artykuł '
            . '<a href="' . $app . '/uslugi-it/uslugi/koordynatorzy-it/administracja-chmury">Ustawienia chmury dla Koordynatorów IT</a>.</p>'
            . '<p>Z czymś, czego nie ma w ściądze, odeślij osobę do <a href="' . $hd . '">Helpdesku IT</a> albo zgłoś to sam.</p>'],
    ];
}

/** Bloki artykułu „Grupy w chmurze” dla użytkowników: pracowników i wolontariuszy (grupy z SZO: modules/sso_logto/logic/CloudTeamsSync.php). */
function itPortalCloudGroupsBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        ['type' => 'text', 'html' => '<p>W chmurze plików są gotowe grupy osób z SZO. Gdy ktoś udostępni plik albo folder Twojej grupie, '
            . 'zobaczysz go u siebie bez proszenia o dostęp. Sam też możesz udostępnić folder całej grupie naraz.</p>'],

        ['type' => 'text', 'html' => '<h2>Do jakich grup należysz</h2>'
            . '<p>Jako pracownik jesteś w grupie swojej jednostki organizacyjnej, w grupach zespołów i obszarów roboczych, do których należysz w Zadaniach, '
            . 'oraz w grupach działań, które koordynujesz.</p>'
            . '<p>Jako wolontariusz jesteś w grupie działania, do którego przypisana jest Twoja umowa, i w grupie jednostki wpisanej w umowie, '
            . 'na przykład „Jednostka: Wolontariusze”.</p>'
            . '<p>W chmurze grupa zespołu ma nazwę zespołu. Pozostałe grupy zaczynają się od słowa „Obszar:”, „Działanie:” albo „Jednostka:”.</p>'],

        ['type' => 'text', 'html' => '<h2>Kiedy dostęp się zmienia</h2>'
            . '<p>SZO sprawdza skład grup co 2 minuty. Kiedy dostaniesz konto w chmurze, nową umowę albo miejsce w zespole, trafisz do grupy sam. '
            . 'Kiedy umowa się skończy albo odejdziesz z zespołu, wypadniesz z grupy i znikną Ci pliki udostępnione tej grupie. '
            . 'Pliki udostępnione Tobie osobiście zostają.</p>'],

        ['type' => 'wizard', 'title' => 'Jak udostępnić folder grupie', 'intro' => '<p>Tak samo udostępnisz pojedynczy plik.</p>', 'steps' => [
            $step('Otwórz chmurę', '<p>Wejdź na <a href="' . $cloud . '">' . $host . '</a> i zaloguj się logowaniem jednokrotnym.</p>'),
            $step('Wybierz folder', '<p>Przy folderze kliknij ikonę udostępniania. Z prawej strony otworzy się panel z polem wyszukiwania.</p>'),
            $step('Wpisz nazwę grupy', '<p>Wpisz początek nazwy, na przykład „Obszar”, „Działanie”, „Jednostka” albo nazwę zespołu, i wybierz grupę z listy.</p>'),
            $step('Ustaw uprawnienia', '<p>Zdecyduj, czy grupa może tylko czytać pliki, czy także je zmieniać i dodawać nowe.</p>'),
        ]],

        ['type' => 'text', 'html' => '<h2>Nie ma Cię w grupie</h2>'
            . '<p>Najpierw sprawdź, czy masz konto w chmurze. Bez niego nie trafisz do żadnej grupy. Konto założysz po zalogowaniu do SZO, '
            . 'gdzie zobaczysz prośbę o jego założenie, albo w Systemie Tożsamości. Po kilku minutach od założenia konta będziesz w swoich grupach.</p>'
            . '<p>Jeśli konto masz, a grupy brakuje, w SZO coś jest nie tak z przypisaniem: zespołem albo obszarem w Zadaniach, działaniem albo jednostką w umowie. '
            . 'Wolontariusz może zapytać koordynatora działania. Przypisania poprawiają Koordynatorzy IT, więc w razie wątpliwości '
            . '<a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie dopisuj nikogo do tych grup ręcznie w chmurze. Przy następnym sprawdzeniu SZO ustawi skład grupy z powrotem '
            . 'według przypisań w SZO. Osobie spoza grupy udostępnij plik osobno, po jej nazwie.</p>'],
    ];
}

/**
 * Usługa „Koordynatorzy IT” (tylko odbiorcy IT) z artykułem „Zarządzanie grupami w chmurze” (polecenie 2026-11-14: pomoc o grupach
 * „osobno dla userów, a osobno dla koordynatorów”). Usługę zakłada, gdy jej nie ma; artykuł nadpisuje. Zwraca opis do raportu.
 */
function itPortalCloudCoordApply(string $app, bool $dryRun = false): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'koordynatorzy-it'");
    $catId = $cat ? (int)$cat['id'] : 0;
    if (!$catId && !$dryRun) {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $intro = '<p>Instrukcje dla Koordynatorów IT, czyli osób, które w SZO zarządzają grupami w chmurze plików i kontami w chmurze dla członków grup.</p>';
        $catId = itPortalCategorySave(['name' => 'Koordynatorzy IT', 'slug' => 'koordynatorzy-it', 'icon' => 'people', 'sort_order' => 90, 'is_active' => 1,
            'audiences' => array_values(array_filter([(int)($aud['IT'] ?? 0)])), 'intro_html' => $intro]);
    }
    $def = ['Zarządzanie grupami w chmurze',
        'koordynator it, koordynatorzy it, grupy, grupy w chmurze, synchronizacja, przypisania, problemy z przypisaniami, ignoruj, ostrzeżenia, załóż konto, konto bez umowy, jednostka, działanie, nextcloud',
        itPortalCloudCoordBlocks($app), false, 10];
    return ($catId ? '' : 'usługa „Koordynatorzy IT” do utworzenia; ') . itPortalCloudArticleSave($catId, 'grupy-w-chmurze', $def, $dryRun, 'koordynatorzy-it');
}

/** Bloki artykułu „Zarządzanie grupami w chmurze” dla Koordynatorów IT (strona /ssoadmin/grupy, modules/sso_logto). */
function itPortalCloudCoordBlocks(string $app): array {
    $page = $app . '/ssoadmin/grupy';
    $users = $app . '/uslugi-it/uslugi/chmura-plikow/grupy';
    return [
        ['type' => 'text', 'html' => '<p>Grupami w chmurze zarządzasz na stronie <a href="' . $page . '">Grupy w chmurze</a> (w menu SZO pod tą samą nazwą). '
            . 'Widzą ją osoby z rolą „Koordynator IT”, osoby z uprawnieniem „Grupy w chmurze” i administratorzy. Rolę albo uprawnienie nadaje administrator na koncie osoby w SZO.</p>'],

        ['type' => 'text', 'html' => '<h2>Co SZO robi samo</h2>'
            . '<p>Co 2 minuty SZO ustawia w chmurze skład czterech rodzajów grup: zespołów i obszarów roboczych z Zadań, działań oraz jednostek organizacyjnych. '
            . 'Do grupy trafia osoba, która ma konto w chmurze i aktywne przypisanie w SZO: członkostwo w zespole albo obszarze, umowę z działaniem albo jednostką, '
            . 'koordynację działania albo jednostkę na koncie SZO.</p>'
            . '<p>Grupa powstaje, gdy ma do niej trafić pierwsza osoba. Grupa wyłączonego, zakończonego albo usuniętego zespołu, obszaru, działania czy jednostki zostaje, '
            . 'ale bez członków. SZO rusza tylko grupy, których identyfikator zaczyna się od <code>szo-</code>. Pozostałe grupy w chmurze zostają bez zmian.</p>'
            . '<p>Przycisk „Podgląd” pokazuje, co by się zmieniło, a „Synchronizuj grupy” robi to od razu, bez czekania na kolejny przebieg.</p>'],

        ['type' => 'text', 'html' => '<h2>Problemy z przypisaniami</h2>'
            . '<p>Gdy ktoś nie trafia do grupy przez brak w SZO, strona sama otwiera okno „Problemy z przypisaniami”. Otworzysz je też przyciskiem w sekcji grup. '
            . 'Przy każdej pozycji jest podpowiedź i przycisk, który prowadzi do poprawki.</p>'
            . '<ul>'
            . '<li>Osoba bez konta w chmurze: „Załóż konto” zakłada konto w chmurze i w logowaniu jednokrotnym. Login i hasło idą SMS-em, a hasło widać też raz na ekranie. '
            . 'Gdy osoba nie ma aktywnej umowy, konto powstaje z danych jej konta SZO i dodatkowo zakłada się zgłoszenie w Helpdesku, żeby uzupełnić umowę.</li>'
            . '<li>Umowa bez jednostki organizacyjnej: podpowiedź proponuje jednostkę z konta SZO tej osoby albo najczęstszą w umowach tego typu. '
            . '„Przypisz jednostkę” otwiera edycję umowy.</li>'
            . '<li>Umowa wolontariacka bez działania: podpowiedź proponuje działanie najczęstsze w umowach tej samej jednostki. „Przypisz działanie” otwiera edycję umowy.</li>'
            . '<li>Działanie bez koordynatora: „Wskaż koordynatora” otwiera kartę działania.</li>'
            . '</ul>'
            . '<p>Po poprawce w SZO grupy zmienią się same w ciągu 2 minut.</p>'],

        ['type' => 'text', 'html' => '<h2>Ignorowanie ostrzeżeń</h2>'
            . '<p>Ostrzeżenie, które Cię nie dotyczy, ukryjesz przyciskiem „Ignoruj”. „Ignoruj cały rodzaj” ukrywa wszystkie ostrzeżenia danego rodzaju, '
            . 'na przykład wszystkie umowy bez jednostki. Zignorowane widać na dole okna i stamtąd przywrócisz je przyciskiem „Przywróć”.</p>'
            . '<p>Lista zignorowanych jest wspólna dla wszystkich koordynatorów. SZO zapisuje w dzienniku, kto i kiedy coś zignorował albo przywrócił.</p>'],

        ...itPortalCloudCoordCheatSheet($app),

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie zmieniaj składu grup <code>szo-</code> ręcznie w chmurze, bo SZO przywróci go przy następnym przebiegu. '
            . 'Nie usuwaj też tych grup: razem z grupą znikają jej udostępnienia. Użytkownikom wysyłaj artykuł <a href="' . $users . '">Grupy w chmurze</a>.</p>'],
    ];
}

/** Bloki artykułu „Nextcloud: pierwsze kroki”. */
function itPortalCloudBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    $doc = 'https://docs.nextcloud.com/server/latest/user_manual/pl/';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];

    return [
        ['type' => 'text', 'html' => '<p>Nextcloud to program do pracy grupowej i przechowywania plików. W Fundacji służy jako chmura plików zamiast OneDrive. W chmurze możesz:</p><ul>'
            . '<li>trzymać pliki i foldery w jednym miejscu i otwierać je z komputera, telefonu i przeglądarki;</li>'
            . '<li>udostępniać pliki osobom z Fundacji albo linkiem, także osobom spoza Fundacji;</li>'
            . '<li>pracować w zespole na wspólnych folderach;</li>'
            . '<li>wrócić do starszej wersji pliku i odzyskać usunięty plik z kosza.</li></ul>'],
        ['type' => 'wizard', 'title' => 'Pierwsze kroki', 'intro' => '', 'steps' => [
            $step('Załóż konto', '<p>Po zalogowaniu do SZO panel sam zaproponuje konto IAM i konto w chmurze. Ustawiasz jedno hasło do obu. '
                . 'Konto założysz też w Systemie Tożsamości, w sekcji <a href="' . $app . '/tozsamosc/index.php#sso">Logowanie jednokrotne</a>.</p>'),
            $step('Zaloguj się w przeglądarce', '<p>Wejdź na <a href="' . $cloud . '">' . $host . '</a> i wybierz logowanie jednokrotne. Na stronie logowania IAM podaj adres e-mail i hasło IAM.</p>'),
            $step('Zainstaluj aplikację', '<p>Pobierz aplikację Nextcloud na komputer (Windows, macOS, Linux) albo telefon (Android, iOS) ze strony '
                . '<a href="https://nextcloud.com/install/">nextcloud.com/install</a>. Przy pierwszym uruchomieniu wpisz adres <code>' . $host . '</code> i zaloguj się. '
                . 'Aplikacja na komputerze synchronizuje wybrane foldery tak jak OneDrive. Krok po kroku opisuje to instrukcja '
                . '<a href="' . $app . '/uslugi-it/uslugi/chmura-plikow/instalacja-aplikacji">Instalacja aplikacji Nextcloud na komputerze i telefonie</a>.</p>'),
            $step('Przenieś pliki z OneDrive', '<p>Przed 20 listopada 2026 r. przenieś do chmury pliki z OneDrive, których potrzebujesz. Jeśli administrator zlecił kopiowanie '
                . 'Twojego OneDrive, pliki pojawią się same w folderze „OneDrive”.</p>'),
        ]],
        ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Chmura obsługuje już logowanie jednokrotne (IAM).</strong> Do Nextcloud logujesz się tak jak do SZO: '
            . 'przycisk logowania jednokrotnego przenosi Cię na stronę logowania IAM. Aplikacje na komputerze i telefonie otwierają tę samą stronę. '
            . 'Hasło jest jedno dla IAM i chmury, a do poczty masz osobne.</p>'],
        ['type' => 'text', 'html' => '<h2>Udostępnianie</h2>'
            . '<p>Przy pliku albo folderze kliknij ikonę udostępniania. Wpisz imię lub adres osoby z Fundacji albo utwórz link. '
            . 'Przy linku możesz ustawić hasło, datę wygaśnięcia i to, czy odbiorca może tylko oglądać, czy także zmieniać pliki.</p>'],
        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Pliki z danymi osobowymi udostępniaj tylko osobom, które ich potrzebują. Link dla osób spoza Fundacji zabezpiecz hasłem '
            . 'i datą wygaśnięcia. Plików służbowych nie przenoś na prywatne dyski (Google Drive, Dropbox, prywatny OneDrive).</p>'],
        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Hasło do chmury zmienisz na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>, razem z hasłem IAM. '
            . 'Coś nie działa? <a href="' . $hd . '">Napisz do Helpdesku</a>.</p>'],
        ['type' => 'text', 'html' => '<h2>Pomoc producenta</h2>'
            . '<p>Więcej szczegółów, ze zrzutami ekranu, znajdziesz w podręczniku użytkownika Nextcloud. Strony otwierają się w serwisie producenta.</p><ul>'
            . '<li><a href="' . $doc . '">Podręcznik użytkownika Nextcloud</a></li>'
            . '<li><a href="' . $doc . 'files/sharing.html">Udostępnianie plików</a></li>'
            . '<li><a href="' . $doc . 'files/version_control.html">Wersje plików</a></li>'
            . '<li><a href="' . $doc . 'files/deleted_file_management.html">Usunięte pliki i kosz</a></li>'
            . '<li><a href="https://nextcloud.com/install/">Aplikacje Nextcloud na komputer i telefon</a></li></ul>'],
    ];
}

/** Bloki artykułu „Instalacja aplikacji Nextcloud” (układ wzorowany na instrukcji CRI AGH, polecenie 2026-11-13). */
function itPortalCloudClientsBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];

    return [
        ['type' => 'text', 'html' => '<p>Aplikacja Nextcloud na komputerze trzyma kopię plików z chmury w zwykłym folderze i sama synchronizuje zmiany w obie strony, tak jak OneDrive. '
            . 'Aplikacja w telefonie daje dostęp do plików w podróży. Zanim zaczniesz, załóż konto w chmurze i sprawdź, czy logujesz się na <a href="' . $cloud . '">' . $host . '</a> w przeglądarce.</p>'
            . '<p>Pobieraj aplikację tylko z linków poniżej, czyli ze strony Nextcloud albo z oficjalnego sklepu. Wydawcą jest Nextcloud GmbH.</p>'],
        ...itPortalCloudDownloadButtons(),
        ['type' => 'wizard', 'title' => 'Komputer: instalacja (Windows, macOS, Linux)', 'intro' => '', 'steps' => [
            $step('Pobierz instalator', '<p>Wejdź na <a href="https://nextcloud.com/install/#install-clients">nextcloud.com/install</a>, w części dla komputerów (<span lang="en">Desktop</span>) wybierz swój system i pobierz plik.</p>'),
            $step('Zainstaluj', '<p>Uruchom pobrany plik i przejdź przez kolejne ekrany instalatora, zostawiając ustawienia domyślne. Na komputerze służbowym instalacja może wymagać uprawnień administratora. '
                . 'Jeśli ich nie masz, <a href="' . $hd . '">napisz do Helpdesku</a>.</p>'),
            $step('Uruchom aplikację', '<p>Po instalacji aplikacja zwykle uruchamia się sama. Jeśli nie widzisz jej okna, poszukaj ikony chmurki w zasobniku systemowym obok zegara (Windows) albo na pasku menu u góry ekranu (macOS).</p>'),
        ]],
        ['type' => 'wizard', 'title' => 'Komputer: połączenie z chmurą Fundacji', 'intro' => '', 'steps' => [
            $step('Wpisz adres serwera', '<p>Kliknij „Zaloguj się” i jako adres serwera wpisz <code>' . $cloud . '</code>.</p>'),
            $step('Zaloguj się w przeglądarce', '<p>Aplikacja otworzy przeglądarkę. Wybierz logowanie jednokrotne i na stronie logowania IAM podaj adres e-mail i hasło IAM (to samo co do chmury, inne niż do poczty).</p>'),
            $step('Udziel dostępu', '<p>Sprawdź, czy na ekranie jest Twoje imię i nazwisko, i kliknij „Udziel dostępu”. Wróć do aplikacji.</p>'),
            $step('Wybierz folder i sposób synchronizacji', '<p>Zostaw folder proponowany przez aplikację albo wskaż inny. Potem wybierz, co ma trafiać na komputer:</p><ul>'
                . '<li><strong>Pliki wirtualne</strong>: widzisz wszystkie pliki, ale pobierają się dopiero przy otwarciu. Oszczędza miejsce na dysku. Polecamy na Windows i macOS.</li>'
                . '<li><strong>Synchronizuj wszystko</strong>: cała chmura pobiera się na komputer.</li>'
                . '<li><strong>Wybierz, co synchronizować</strong>: tylko zaznaczone foldery.</li></ul><p>Kliknij „Połącz”. Pierwsza synchronizacja może potrwać.</p>'),
        ]],
        ['type' => 'wizard', 'title' => 'Telefon (Android, iPhone)', 'intro' => '', 'steps' => [
            $step('Zainstaluj aplikację', '<p>W Sklepie Play albo App Store wyszukaj „Nextcloud” (wydawca Nextcloud GmbH) i zainstaluj aplikację.</p>'),
            $step('Zaloguj się', '<p>Kliknij „Zaloguj się” i wpisz adres <code>' . $cloud . '</code>. Wybierz logowanie jednokrotne, zaloguj się adresem e-mail i hasłem IAM i udziel dostępu.</p>'),
            $step('Zdecyduj o zdjęciach', '<p>Aplikacja może sama wysyłać zdjęcia z telefonu do chmury. Włącz to tylko dla zdjęć służbowych. Prywatnych zdjęć nie wysyłaj do chmury Fundacji.</p>'),
        ]],
        ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Chmura obsługuje już logowanie jednokrotne (IAM).</strong> Do Nextcloud logujesz się tak jak do SZO: '
            . 'przycisk logowania jednokrotnego przenosi Cię na stronę logowania IAM. Aplikacje na komputerze i telefonie otwierają tę samą stronę. '
            . 'Hasło jest jedno dla IAM i chmury, a do poczty masz osobne.</p>'],
        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Pliku usuniętego z folderu synchronizowanego na komputerze ubywa też w chmurze. Odzyskasz go z kosza na <a href="' . $cloud . '">' . $host . '</a>. '
            . 'Po zmianie hasła IAM aplikacje poproszą o ponowne zalogowanie.</p>'],
        ['type' => 'text', 'html' => '<h2>Przydatne materiały</h2><ul>'
            . '<li><a href="https://cri.agh.edu.pl/pomoc-it/instrukcje/instalacja-i-konfiguracja-aplikacji-nextcloud">Instalacja i konfiguracja aplikacji Nextcloud</a>: instrukcja ze zrzutami ekranu przygotowana przez Akademię Górniczo-Hutniczą (AGH). '
            . 'Kroki są te same, ale zamiast adresu AGH (<code>dysk.agh.edu.pl</code>) wpisz adres chmury Fundacji: <code>' . $cloud . '</code>, a przy logowaniu wybierz logowanie jednokrotne i podaj adres e-mail i hasło IAM.</li>'
            . '<li><a href="https://nextcloud.com/install/#install-clients">Aplikacje Nextcloud do pobrania</a></li>'
            . '<li><a href="https://docs.nextcloud.com/server/latest/user_manual/pl/">Podręcznik użytkownika Nextcloud</a></li></ul>'],
        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Aplikacja nie łączy się albo synchronizacja stoi? <a href="' . $hd . '">Napisz do Helpdesku</a>.</p>'],
    ];
}
