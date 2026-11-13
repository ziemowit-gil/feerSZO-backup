<?php
/**
 * modules/it_portal/lib/itPortalCloudSeed.php — usługa „Chmura plików” w Portalu Usług IT (/uslugi-it/uslugi/chmura-plikow)
 * z wprowadzeniem i krótkim artykułem o Nextcloud. Polecenie 2026-11-13: „Przygotuj też krótki artykuł o NextCloud + takie wprowadzenie”.
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

/** @return array{category:string, article:string} */
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

    $title = 'Nextcloud: pierwsze kroki w chmurze plików';
    $old = $catId ? db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = 'nextcloud'", [$catId]) : null;
    $rep['article'] = "chmura-plikow/nextcloud — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
    if ($dryRun) return $rep;
    itPortalArticleSave([
        'title' => $title, 'slug' => 'nextcloud', 'category_id' => $catId,
        'keywords' => 'nextcloud, chmura, chmura plików, pliki, dysk, onedrive, udostępnianie, link, synchronizacja, aplikacja, kosz, wersje, nc.feer.org.pl, iam, hasło',
        'blocks' => json_encode(itPortalCloudBlocks($app), JSON_UNESCAPED_UNICODE),
        'related' => $old ? (json_decode((string)$old['related'], true) ?: []) : [],
        'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : 1,
        'sort_order' => $old ? (int)$old['sort_order'] : 10,
    ], $old ? (int)$old['id'] : 0);
    return $rep;
}

/** Wprowadzenie na stronie usługi „Chmura plików”. */
function itPortalCloudIntro(string $app): string {
    $cloud = itPortalCloudUrl();
    $host = (string)(parse_url($cloud, PHP_URL_HOST) ?: $cloud);
    return '<p>Chmura plików Fundacji działa na programie Nextcloud pod adresem <a href="' . $cloud . '">' . $host . '</a>. '
         . 'Zastępuje OneDrive z Microsoft 365, który wyłączamy 20 listopada 2026 r. Trzymasz w niej pliki służbowe, udostępniasz je innym osobom '
         . 'i synchronizujesz z komputerem i telefonem.</p>'
         . '<p>Logujesz się loginem <code>imie.nazwisko</code> albo adresem e-mail i hasłem IAM. To hasło jest inne niż hasło do poczty. '
         . 'Zacznij od instrukcji poniżej. Jeśli czegoś w niej brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>';
}

/** Bloki artykułu. */
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
            $step('Zaloguj się w przeglądarce', '<p>Wejdź na <a href="' . $cloud . '">' . $host . '</a>. Login to <code>imie.nazwisko</code> albo Twój adres e-mail, hasło to hasło IAM.</p>'),
            $step('Zainstaluj aplikację', '<p>Pobierz aplikację Nextcloud na komputer (Windows, macOS, Linux) albo telefon (Android, iOS) ze strony '
                . '<a href="https://nextcloud.com/install/">nextcloud.com/install</a>. Przy pierwszym uruchomieniu wpisz adres <code>' . $host . '</code> i zaloguj się. '
                . 'Aplikacja na komputerze synchronizuje wybrane foldery tak jak OneDrive.</p>'),
            $step('Przenieś pliki z OneDrive', '<p>Przed 20 listopada 2026 r. przenieś do chmury pliki z OneDrive, których potrzebujesz. Jeśli administrator zlecił kopiowanie '
                . 'Twojego OneDrive, pliki pojawią się same w folderze „OneDrive”.</p>'),
        ]],
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
