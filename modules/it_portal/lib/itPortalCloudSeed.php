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
    foreach (itPortalCloudArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $sort]) {
        $old = $catId ? db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep['article'][] = "chmura-plikow/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
        if ($dryRun) continue;
        itPortalArticleSave([
            'title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
            'related' => $old ? (json_decode((string)$old['related'], true) ?: []) : [],
            'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : ($pinned ? 1 : 0),
            'sort_order' => $old ? (int)$old['sort_order'] : $sort,
        ], $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/** Wprowadzenie na stronie usługi „Chmura plików”. */
function itPortalCloudIntro(string $app): string {
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    return '<p>Chmura plików Fundacji działa na programie Nextcloud pod adresem <a href="' . $cloud . '">' . $host . '</a>. '
         . 'Zastępuje OneDrive z Microsoft 365, który wyłączamy 20 listopada 2026 r. Trzymasz w niej pliki służbowe, udostępniasz je innym osobom '
         . 'i synchronizujesz z komputerem i telefonem.</p>'
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
            'nextcloud, chmura, chmura plików, pliki, dysk, onedrive, udostępnianie, link, synchronizacja, aplikacja, kosz, wersje, nc.feer.org.pl, iam, hasło',
            itPortalCloudBlocks($app), true, 10],
        'instalacja-aplikacji' => ['Instalacja aplikacji Nextcloud na komputerze i telefonie',
            'nextcloud, aplikacja, klient, instalacja, konfiguracja, synchronizacja, windows, macos, linux, android, iphone, ios, pliki wirtualne, folder, zasobnik, agh',
            itPortalCloudClientsBlocks($app), false, 20],
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
            . 'Aplikacja w telefonie daje dostęp do plików w podróży. Zanim zaczniesz, załóż konto w chmurze i sprawdź, czy logujesz się na <a href="' . $cloud . '">' . $host . '</a> w przeglądarce.</p>'],
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
