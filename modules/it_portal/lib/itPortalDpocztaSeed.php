<?php
/**
 * modules/it_portal/lib/itPortalDpocztaSeed.php — instrukcje webmaila dPoczta.pl dla usługi „Moja poczta” w Portalu Usług IT.
 * Na podstawie pomocy dostawcy (dhosting.pl/pomoc, kategoria „Webmail dpoczta.pl”, stan 2026-11-13), przepisane prostym językiem.
 * Zasady logowania wg polecenia 2026-11-13: login imie.nazwisko@feer.org.pl; konta sprzed 13.11.2026 (migracja z Microsoft 365)
 * dostały hasło SMS-em po migracji, konta założone później dostają je SMS-em po podpisaniu umowy.
 * Używa migracja '2026-11_it_portal_dpoczta_articles' (modules/selfrepairDB/logic/migrations.php): dodaje tylko brakujące artykuły.
 * Artykuł „transfernow-wetransfer” i rozbudowane „duze-zalaczniki” (polecenie 2026-11-13: „opisz też artykuł dot. TransferNow
 * i WeTransfer w kontekście dużych załączników”) wgrywa migracja '2026-11_it_portal_transfer_article' przez $only.
 * Limity planów bezpłatnych wg stron dostawców, stan 2026-11-13: TransferNow 5 GB na przesyłkę, 7 dni, hasło bez opłat;
 * WeTransfer 10 przesyłek albo 3 GB w ciągu 30 dni, 3 dni, hasło tylko w planie płatnym.
 */

/**
 * Zasiew: wstęp „Moja poczta”, artykuły dPoczta.pl (ten sam adres = nadpisanie), ukrycie dawnych artykułów o poczcie
 * w Office 365 (is_published = 0, bez kasowania). Bezpieczne do ponownego uruchomienia.
 * $only (lista adresów artykułów): wgrywa tylko te artykuły, bez nadpisywania wstępu i bez ukrywania (dla późniejszych migracji);
 * kolejność (sort_order) taka sama jak przy pełnym zasiewie.
 * @return array{category:string, hidden:list<string>, created:list<string>, updated:list<string>}
 */
function itPortalDpocztaApply(string $app, bool $dryRun = false, ?array $only = null): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'hidden' => [], 'created' => [], 'updated' => []];
    $intro = itPortalSanitizeHtml(itPortalDpocztaIntro($app));
    $articles = itPortalDpocztaArticles($app);

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moja-poczta'");
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = $only !== null ? 'bez zmian' : 'wstęp nadpisany';
        if (!$dryRun && $only === null) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona';
        if ($dryRun) { $catId = 0; }
        else {
            $aud = array_column(itPortalAudiences(), 'id', 'name');
            $catId = itPortalCategorySave(['name' => 'Moja poczta', 'slug' => 'moja-poczta', 'icon' => 'mail', 'sort_order' => 20, 'is_active' => 1,
                'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
        }
    }

    // dawne instrukcje poczty w Office 365: w „Mojej poczcie” każdy artykuł o Office/M365/Outlooku/Exchange,
    // w innych usługach tylko taki, którego tytuł mówi o poczcie, skrzynce lub Outlooku
    $o365 = static fn(string $s): bool => (bool)preg_match('/office\s*365|microsoft\s*365|\bm365\b|outlook|exchange/iu', $s);
    foreach ($only !== null ? [] : db_all("SELECT a.id, a.category_id, a.slug, a.title, a.keywords, a.body_plain, c.slug AS cslug
                       FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE a.is_published = 1") as $a) {
        $own = (int)$a['category_id'] === $catId;
        if ($own && isset($articles[$a['slug']])) continue;
        $text = $a['title'] . ' ' . $a['keywords'] . ' ' . $a['body_plain'];
        $hit = $own ? $o365($text) : ($o365($text) && preg_match('/poczt|outlook|skrzynk|e-?mail/iu', (string)$a['title']));
        if (!$hit) continue;
        $rep['hidden'][] = "#{$a['id']} {$a['cslug']}/{$a['slug']} — {$a['title']}";
        if (!$dryRun) db_exec("UPDATE itp_articles SET is_published = 0, updated_at = datetime('now') WHERE id = ?", [$a['id']]);
    }

    $i = 0;
    foreach ($articles as $slug => [$title, $keywords, $blocks, $pinned]) {
        $sort = (++$i) * 10;
        if ($only !== null && !in_array($slug, $only, true)) continue;
        $old = $catId ? db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep[$old ? 'updated' : 'created'][] = "moja-poczta/{$slug} — {$title}";
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => $sort,
            'related' => $old ? itPortalIds($old['related'] ?? '[]') : []],   // 2026-11-14: nie kasuj „Zobacz też” przy ponownym zasiewie
            $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/**
 * Pożegnanie z pocztą Outlook w Microsoft 365 (polecenie 2026-11-14: „dodaj informację, że z Outlookiem można się pożegnać”, potem „da się połączyć nowym hasłem na stronie
 * office.com, jak czegoś brakuje w sensie maila”; bez odpowiedzi
 * na doprecyzowanie, więc chodzi o pocztę w Microsoft 365, a program Outlook nadal działa z dPocztą przez IMAP).
 */
function itPortalDpocztaOutlookBye(): string {
    return '<p>Z pocztą w Outlooku z Microsoft 365, czyli ze skrzynką na outlook.office.com i w aplikacji Outlook połączonej z kontem Microsoft, możesz się pożegnać. '
         . 'Twoja skrzynka jest teraz w dPoczcie i tam przychodzą nowe wiadomości. Starego konta Microsoft w Outlooku nie musisz już sprawdzać na co dzień.</p>'
         . '<p>Jeśli brakuje Ci jakiejś starej wiadomości, wejdź na <a href="https://www.office.com">office.com</a> i zaloguj się swoim adresem i nowym hasłem. '
         . 'Znajdziesz tam dawną skrzynkę w Outlooku i możesz z niej przekazać potrzebną wiadomość na nowy adres. Dopóki konto Microsoft działa, zrób to od razu, gdy zauważysz brak.</p>'
         . '<p>Jeśli lubisz program Outlook na komputerze, możesz go zostawić i podłączyć w nim nową skrzynkę jako konto IMAP, '
         . 'jak opisuje instrukcja „Poczta w telefonie, Outlooku i Thunderbirdzie”. Starą skrzynkę Microsoft usuń wtedy z listy kont w programie.</p>';
}

/**
 * Trzy osobne usługi portalu: „Zoom”, „Jitsi Meet”, „Fathom” (polecenie 2026-11-14: „to osobna kategoria… to mają być osobne kategorie”, wybór:
 * trzy osobne usługi). Artykuły były wcześniej w „Mojej poczcie” jako zoom / jitsi-meet / fathom; te wiersze są przenoszone (to samo id). @return list<string>
 */
function itPortalMeetingServicesApply(string $app): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $hdl = '<a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>';
    $svc = [
        'zoom' => ['Zoom', 'monitor', 21, 'zoom',
            '<p>Zoom to główne narzędzie do spotkań online w Fundacji. Mamy dwie licencje. Spotkania edukacyjne prowadzimy z technicznego konta edukacja@feer.org.pl. '
            . 'Spotkania szkoleniowe zakładają się same, a inne zamawiasz 2 do 3 dni wcześniej.</p>'],
        'jitsi-meet' => ['Jitsi Meet', 'chat', 23, 'jitsi-meet',
            '<p>Jitsi Meet to darmowe spotkania wideo w przeglądarce, bez konta i bez instalowania programu. Przydaje się do szybkich, doraźnych rozmów.</p>'],
        'fathom' => ['Fathom', 'document', 24, 'fathom',
            '<p>Fathom nagrywa spotkania online i przygotowuje z nich notatki. W Fundacji jest podłączony do technicznego konta edukacja@feer.org.pl, z którego prowadzimy spotkania edukacyjne.</p>'],
    ];
    $arts = itPortalMeetingArticles($app);
    $aud = array_column(itPortalAudiences(), 'id', 'name');
    $audIds = array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0]));
    $rep = [];
    foreach ($svc as $catSlug => [$name, $icon, $sort, $oldSlug, $introP]) {
        $intro = itPortalSanitizeHtml($introP . '<p>Jeśli czegoś brakuje w instrukcji, ' . $hdl . '.</p>');
        $cat = db_one("SELECT id FROM itp_categories WHERE slug = ?", [$catSlug]);
        if ($cat) {
            $catId = (int)$cat['id'];
            db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
        } else {
            $catId = itPortalCategorySave(['name' => $name, 'slug' => $catSlug, 'icon' => $icon, 'sort_order' => $sort, 'is_active' => 1, 'audiences' => $audIds, 'intro_html' => $intro]);
        }
        foreach ($arts[$catSlug] as $slug => [$title, $keywords, $blocks, $pinned]) {
            $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
            if (!$old) {
                $old = db_one("SELECT a.id, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = 'moja-poczta' AND a.slug = ?", [$oldSlug]);
                if ($old) db_exec("UPDATE itp_articles SET category_id = ?, menu_group = '' WHERE id = ?", [$catId, (int)$old['id']]);
            }
            itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
                'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
                'sort_order' => 10, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
            $rep[] = "{$catSlug}/{$slug} — {$title}";
        }
    }
    return $rep;
}

/** Artykuły trzech usług spotkań: [usługa => [slug => [tytuł, słowa kluczowe, bloki, przypięty]]]. */
function itPortalMeetingArticles(string $app): array {
    $app = rtrim($app, '/');
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'zoom' => [
        'zamawianie-spotkan' => ['Spotkanie na Zoomie: jak je zamówić', 'zoom, spotkanie zoom, licencja zoom, zamówić spotkanie, rezerwacja zoom, spotkanie online, wideokonferencja, spotkanie szkoleniowe, jarek@feer.org.pl, helpdesk', [
            ['type' => 'text', 'html' => '<p>Spotkania online w Fundacji prowadzimy przede wszystkim na Zoomie. Mamy dwie licencje Zoom, więc w tym samym czasie mogą trwać najwyżej dwa spotkania z naszych kont.</p>'],
            ['type' => 'text', 'html' => '<h2>Spotkania szkoleniowe</h2>'
                . '<p>Spotkania edukacyjne prowadzimy z technicznego konta <code>edukacja@feer.org.pl</code>. Spotkania dla grup szkoleniowych zakładają się automatycznie, gdy w systemie dodawana jest grupa. Mają stały link, ten sam na wszystkie zajęcia grupy. '
                . 'Spotkanie może też założyć dydaktyk w swoim panelu, w panelu kursanta. Do takich spotkań nie trzeba niczego zamawiać.</p>'],
            ['type' => 'text', 'html' => '<h2>Pozostałe spotkania</h2>'
                . '<p>Jeśli potrzebujesz spotkania na Zoomie, na przykład z partnerem albo zespołem, napisz 2 do 3 dni wcześniej na adres '
                . '<a href="mailto:jarek@feer.org.pl">jarek@feer.org.pl</a> albo <a href="' . $hd . '">zgłoś to w Helpdesku IT</a>. Podaj:</p><ul>'
                . '<li>datę i godzinę rozpoczęcia oraz przewidywany czas trwania,</li>'
                . '<li>temat spotkania,</li>'
                . '<li>przybliżoną liczbę uczestników,</li>'
                . '<li>czy spotkanie ma być nagrywane albo mieć notatki z Fathom.</li></ul>'
                . '<p>W odpowiedzi dostaniesz link do spotkania. Wyślij go uczestnikom albo wpisz w polu „Lokalizacja” spotkania w kalendarzu dPoczty.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Potrzebujesz porozmawiać od razu, bez zamawiania? Skorzystaj z <a href="' . $app . '/uslugi-it/uslugi/jitsi-meet/szybkie-spotkanie">Jitsi Meet</a>. '
                . 'Nagrywanie i notatki ze spotkań opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/fathom/notatki-ze-spotkan">Fathom: notatki ze spotkań online</a>.</p>'],
        ], false],

        ],
        'jitsi-meet' => [
        'szybkie-spotkanie' => ['Szybkie spotkanie online: Jitsi Meet', 'spotkanie online, wideorozmowa, rozmowa wideo, zoom, licencja zoom, jitsi, jitsi meet, meet.jit.si, teams, zoom, link do spotkania, szybkie spotkanie', [
            ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Spotkania w Fundacji prowadzimy przede wszystkim na Zoomie, na który mamy dwie licencje. '
                . 'Spotkania szkoleniowe zakładają się na Zoomie same przy dodaniu grupy i mają stały link, a dydaktyk może je też założyć w swoim panelu. '
                . 'Inne spotkanie na Zoomie zamów 2 do 3 dni wcześniej, jak opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/zoom/zamawianie-spotkan">Spotkanie na Zoomie</a>. '
                . 'Jitsi Meet to szybkie rozwiązanie na doraźną rozmowę, gdy Zoom jest zajęty albo nie ma czasu na umawianie.</p>'],
            ['type' => 'text', 'html' => '<p>Gdy potrzebujesz szybko porozmawiać z kimś przez wideo, możesz skorzystać z <a href="https://meet.jit.si/">Jitsi Meet</a>. '
                . 'To darmowa usługa do spotkań online. Działa w przeglądarce, bez instalowania programu, a uczestnicy nie potrzebują żadnego konta. Wystarczy im link.</p>'],
            ['type' => 'wizard', 'title' => 'Spotkanie w trzech krokach', 'intro' => '', 'steps' => [
                $step('Załóż pokój', '<p>Wejdź na <a href="https://meet.jit.si/">meet.jit.si</a>, wpisz nazwę spotkania i kliknij „Rozpocznij spotkanie”. '
                    . 'Wybierz nazwę trudną do odgadnięcia, na przykład z kilku słów i cyfr, a nie „FEER”. Strona może poprosić osobę zakładającą spotkanie o zalogowanie się, na przykład kontem Google.</p>'),
                $step('Zezwól na kamerę i mikrofon', '<p>Przeglądarka zapyta o dostęp do kamery i mikrofonu. Zgódź się. Przed wejściem sprawdzisz obraz i dźwięk.</p>'),
                $step('Wyślij link', '<p>Skopiuj adres strony spotkania z paska przeglądarki albo przyciskiem zaproszenia i wyślij go uczestnikom. Link możesz też wpisać w polu „Lokalizacja” spotkania w kalendarzu dPoczty.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Przydatne przyciski</h2>'
                . '<p>Na dole okna spotkania wyłączysz mikrofon i kamerę, udostępnisz ekran, otworzysz czat i podniesiesz rękę. W ustawieniach bezpieczeństwa możesz ustawić hasło do spotkania '
                . 'albo poczekalnię, w której zatwierdzasz każdego wchodzącego. Na telefonie wygodniej jest w aplikacji Jitsi Meet ze sklepu.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Jitsi Meet to publiczna usługa zewnętrzna. Nadaje się do szybkich, zwykłych rozmów. Przy rozmowach o danych osobowych albo sprawach poufnych '
                . 'ustaw hasło do spotkania i nie nagrywaj go. Jeśli potrzebujesz stałego narzędzia do takich spotkań, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], false],

        ],
        'fathom' => [
        'notatki-ze-spotkan' => ['Fathom: notatki ze spotkań online', 'fathom, spotkania szkoleniowe, grupa, stały link, dydaktyk, notatki ze spotkań, nagrywanie spotkań, transkrypcja, podsumowanie, ai, sztuczna inteligencja, zoom, edukacja@feer.org.pl, notetaker', [
            ['type' => 'text', 'html' => '<p><a href="https://www.fathom.ai/">Fathom</a> to narzędzie, które dołącza do spotkania online, nagrywa je i przygotowuje notatki: '
                . 'zapis rozmowy, krótkie podsumowanie i listę ustaleń. Dzięki temu nikt nie musi notować w trakcie spotkania.</p>'
                . '<p>W Fundacji Fathom jest domyślnie podłączony do konta <code>edukacja@feer.org.pl</code>. To konto techniczne do prowadzenia spotkań o charakterze edukacyjnym. '
                . 'Fathom działa na spotkaniach prowadzonych z tego konta, a notatki trafiają do tego konta.</p>'
                . '<p>Spotkania szkoleniowe na Zoomie zakładają się automatycznie, gdy w systemie dodawana jest grupa szkoleniowa, i mają stały link, ten sam na wszystkie zajęcia grupy. '
                . 'Spotkanie może też założyć dydaktyk w swoim panelu, w panelu kursanta.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Gdy Fathom nagrywa spotkanie, na początku powiedz o tym wszystkim uczestnikom. Nagranie i zapis rozmowy to dane osobowe uczestników. '
                . 'Jeśli ktoś się nie zgadza albo rozmawiacie o sprawach poufnych, na przykład o zdrowiu czy sprawach kadrowych, wyłącz nagrywanie dla tego spotkania.</p>'],
            ['type' => 'text', 'html' => '<p>Notatki z Fathom sprawdź, zanim je komuś wyślesz. Automatyczny zapis bywa niedokładny, zwłaszcza przy nazwiskach i liczbach. '
                . 'Spotkanie na Zoomie z notatkami zamówisz tak, jak opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/zoom/zamawianie-spotkan">Spotkanie na Zoomie</a>. '
                . 'Więcej informacji o Fathom, w tym o tym, kto ma dostęp do notatek, opublikujemy wkrótce.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Szybkie spotkania bez umawiania opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/jitsi-meet/szybkie-spotkanie">Szybkie spotkanie online: Jitsi Meet</a>. '
                . 'Z pytaniami <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], false],

        ],
    ];
}

/**
 * Grupy w rozwijanym menu „Mojej poczty” i kolejność (polecenie 2026-11-14: „też zrób kategorie”). Artykuły spoza seeda (np. gosposia) też tu są.
 * @return array<string, array{0:string,1:int}>
 */
function itPortalDpocztaMenuGroups(): array {
    $g1 = 'Pierwsze kroki'; $g2 = 'Hasło i bezpieczeństwo'; $g3 = 'Stopka i pisanie wiadomości'; $g4 = 'Porządek w skrzynce';
    $g5 = 'Telefon, programy, kontakty i kalendarz'; $g6 = 'Załączniki i duże pliki';
    return [
        'adres-i-haslo' => [$g1, 8], 'konta-funkcyjne' => [$g1, 15], 'dpoczta-funkcje' => [$g1, 9], 'logowanie-dpoczta' => [$g1, 10], 'dpoczta-co-jest-co' => [$g1, 11], 'dpoczta-a-outlook' => [$g1, 12], 'ustawienia-poczty' => [$g1, 13], 'preferencje' => [$g1, 14],
        'zmiana-hasla-poczty' => [$g2, 20], 'blad-authenticate-plain' => [$g2, 21], 'weryfikacja-dwuetapowa' => [$g2, 22], 'szyfrowanie-pgp' => [$g2, 23],
        'wiadomosci' => [$g3, 29], 'generator-stopki' => [$g3, 30], 'podpis-w-poczcie' => [$g3, 31], 'autoresponder' => [$g3, 32], 'szablony-odpowiedzi' => [$g3, 33],
        'filtry-i-przekierowanie' => [$g4, 40], 'spam-i-zaufani-nadawcy' => [$g4, 41], 'powiadomienia-o-poczcie' => [$g4, 42], 'gosposia' => [$g4, 43], 'problemy-z-odbiorem' => [$g4, 44],
        'poczta-w-telefonie' => [$g5, 50], 'kontakty' => [$g5, 51], 'kontakty-import-eksport' => [$g5, 52], 'kalendarz' => [$g5, 54], 'folder-wyslane' => [$g5, 53],
        'duze-zalaczniki' => [$g6, 60], 'transfernow-wetransfer' => [$g6, 61],
    ];
}

/** Ustawia grupy i kolejność artykułów „Mojej poczty” (po itPortalDpocztaApply, które nadaje kolejność według seeda). */
function itPortalDpocztaMenuApply(): void {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    foreach (itPortalDpocztaMenuGroups() as $slug => [$group, $sort]) {
        db_exec("UPDATE itp_articles SET menu_group = ?, sort_order = ? WHERE slug = ? AND category_id = (SELECT id FROM itp_categories WHERE slug = 'moja-poczta')",
                [$group, $sort, $slug]);
    }
}

/** Zrzut dPoczty z lib/img jako blok obrazka (zrzuty ekranu od użytkownika z 2026-11-14, bez danych osobowych). */
function itPortalDpocztaShot(string $name, string $alt, string $caption = ''): array {
    if (!function_exists('itPortalCloudShot')) require_once __DIR__ . '/itPortalCloudSeed.php';
    return itPortalCloudShot($name, $alt, $caption);
}

/**
 * Wstęp strony usługi „Moja poczta” (polecenie 2026-11-14: „przygotuj stronę wstępu + ogólnie informacje dPoczta i dhosting”).
 */
function itPortalDpocztaIntro(string $app): string {
    $b = rtrim($app, '/') . '/uslugi-it/uslugi/moja-poczta/';
    return '<p>Poczta służbowa Fundacji działa u firmy dhosting.pl, polskiej firmy hostingowej, która utrzymuje nasz serwer pocztowy. '
         . 'Twoja skrzynka jest na tym serwerze, a dostać się do niej możesz na kilka sposobów: w przeglądarce, w telefonie albo w programie pocztowym na komputerze.</p>'
         . '<p>W przeglądarce otwierasz pocztę na stronie <a href="https://dpoczta.pl">dpoczta.pl</a>. dPoczta to program pocztowy, który udostępnia dhosting.pl. '
         . 'Jest prostszy od Outlooka i ma mniej opcji, więc łatwiej się w nim odnaleźć. Masz w nim pocztę, kontakty i kalendarz. '
         . 'Logujesz się pełnym adresem <code>imie.nazwisko@feer.org.pl</code> i hasłem do skrzynki.</p>'
         . '<h2>Od czego zacząć</h2><ul>'
         . '<li><a href="' . $b . 'adres-i-haslo">Jaki jest mój adres e-mail i hasło?</a></li>'
         . '<li><a href="' . $b . 'logowanie-dpoczta">Jak zalogować się do poczty</a></li>'
         . '<li><a href="' . $b . 'dpoczta-funkcje">dPoczta: spis funkcji</a>, czyli wszystkie instrukcje w jednym miejscu</li>'
         . '<li><a href="' . $b . 'dpoczta-co-jest-co">Co jest co na ekranie i w ustawieniach</a></li>'
         . '<li><a href="' . $b . 'dpoczta-a-outlook">Najważniejsze różnice względem Outlooka</a></li></ul>'
         . itPortalDpocztaOutlookBye()
         . '<p>Zachęcamy, żeby od razu włączyć weryfikację dwuetapową. Chroni skrzynkę, nawet gdy ktoś pozna Twoje hasło. '
         . 'Jak to zrobić, opisuje instrukcja <a href="' . $b . 'weryfikacja-dwuetapowa">Weryfikacja dwuetapowa (2FA) w poczcie</a>.</p>'
         . '<p>Hasło do poczty zmieniasz tylko w SZO, na stronie <a href="' . rtrim($app, '/') . '/nowehaslo">Nowe hasło</a>, nigdy w ustawieniach dPoczty. '
         . 'Jeśli czegoś brakuje w instrukcjach, <a href="' . rtrim($app, '/') . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>';
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty na Start].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalDpocztaArticles(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $gear = 'Kliknij ikonę koła zębatego po prawej stronie (ustawienia).';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    $cloud = 'https://nc.feer.org.pl';
    try {
        require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
        if (SsoConfig::cloudUrl() !== '') $cloud = SsoConfig::cloudUrl();
    } catch (\Throwable $e) {}
    $transfer = $app . '/uslugi-it/uslugi/moja-poczta/transfernow-wetransfer';
    $kb = 'https://dhosting.pl/pomoc/baza-wiedzy/';   // baza wiedzy dostawcy poczty
    $base = $app . '/uslugi-it/uslugi/moja-poczta/';

    return [
        'logowanie-dpoczta' => ['Jak zalogować się do poczty', 'logowanie, login, hasło, sms, dpoczta, webmail, migracja, nowa poczta, imie.nazwisko, witamy w dpoczta.pl, nazwa użytkownika, nie pamiętasz hasła, sesja została zakończona', [
            ['type' => 'text', 'html' => '<p>Pocztę w przeglądarce otwierasz na stronie <a href="https://dpoczta.pl">dpoczta.pl</a>. Działa na komputerze i w telefonie, bez instalowania czegokolwiek.</p>'],
            ['type' => 'wizard', 'title' => 'Logowanie krok po kroku', 'intro' => '', 'steps' => [
                ['images' => array_map(static fn(array $b): array => ['file' => $b['file'], 'alt' => $b['alt'], 'caption' => $b['caption']],
                    itPortalDpocztaShot('dp-logowanie.jpg', 'Strona logowania dPoczta.pl: logo dhosting.pl, napis Witamy w dPoczta.pl, pola Nazwa użytkownika i Hasło, przycisk Zaloguj, link Nie pamiętasz hasła',
                        'Strona logowania dPoczta.pl.'))]
                    + $step('Otwórz stronę poczty', '<p>Wejdź na <a href="https://dpoczta.pl">dpoczta.pl</a>. Zobaczysz białą stronę z napisem „Witamy w dPoczta.pl” i dwoma polami. '
                    . 'Napis „Sesja została zakończona poprawnie” pojawia się tylko po wylogowaniu.</p>'),
                $step('Wpisz login', '<p>Loginem jest pełny adres służbowy: <code>imie.nazwisko@feer.org.pl</code>, małymi literami i bez polskich znaków (np. <code>jan.kowalski@feer.org.pl</code>).</p>'),
                $step('Wpisz hasło z SMS-a', '<p>Hasło do skrzynki dostałeś SMS-em:</p><ul><li>jeśli konto pocztowe istniało przed 13.11.2026, SMS przyszedł po przeniesieniu poczty z Microsoft 365;</li><li>jeśli konto założyliśmy później, SMS przychodzi po podpisaniu umowy.</li></ul>'),
                $step('Zmień hasło na własne', '<p>Po pierwszym logowaniu ustaw własne hasło w SZO, na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. Nie zmieniaj go w ustawieniach dpoczta.pl. Opis jest w instrukcji „Jak zmienić hasło do poczty”.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasło do poczty jest osobne. To inne hasło niż do Panelu współpracownika (SZO), do Microsoft 365 i do logowania jednokrotnego.</p>'
                . '<p>Pod przyciskiem „Zaloguj” jest link „Nie pamiętasz hasła?” Nie korzystaj z niego. Zapomniane hasło do poczty zmienisz na stronie '
                . '<a href="' . $app . '/nowehaslo">Nowe hasło</a> w SZO, bo tylko tam hasło zapisuje się we wszystkich miejscach.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie masz SMS-a albo hasło nie działa? <a href="' . $hd . '">Zgłoś to w Helpdesku</a>, nadamy nowe hasło.</p>'],
        ], true],

        'adres-i-haslo' => ['Jaki jest mój adres e-mail i hasło?', 'adres e-mail, mój adres, login, hasło, jakie mam hasło, konto osobiste, konto imienne, konto funkcyjne, kontakt@feer.org.pl, konto techniczne, pełnomocnictwo, imie.nazwisko, sms, pierwsze logowanie', [
            ['type' => 'text', 'html' => '<h2>Rodzaje kont pocztowych</h2>'
                . '<p>W Fundacji są trzy rodzaje kont pocztowych.</p><ul>'
                . '<li>Konto osobiste, czyli imienne. Jest przypisane do Ciebie. Logujesz się nim i z niego prowadzisz swoją korespondencję.</li>'
                . '<li>Konto funkcyjne. Jest przypisane do grupy osób albo do jednostki organizacyjnej, na przykład <code>kontakt@feer.org.pl</code> należy do Zarządu. '
                . 'Dostęp do niego dostajesz na podstawie pełnomocnictwa nadanego przez administratora serwera. Logujesz się nadal swoim imiennym adresem i swoim hasłem. '
                . 'Więcej w artykule <a href="' . $base . 'konta-funkcyjne">Konta funkcyjne w dPoczcie</a>.</li>'
                . '<li>Konto techniczne. Służy do obsługi systemów IT, na przykład do wysyłania powiadomień. Nie loguj się na takie konta i nie pisz z nich.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Jaki jest mój login i adres e-mail?</h2>'
                . '<p>Loginy w Fundacji tworzymy według jednego wzoru. Adres imienny ma postać <code>imie.nazwisko@feer.org.pl</code>, małymi literami i bez polskich znaków. '
                . 'Jeśli dwie osoby mają to samo imię i nazwisko, druga dostaje adres z cyfrą, na przykład <code>imie.nazwisko1@feer.org.pl</code>.</p>'
                . '<p>Ten sam pełny adres wpisujesz jako login przy logowaniu do poczty.</p>'],
            ['type' => 'text', 'html' => '<h2>Jakie jest moje hasło?</h2>'
                . '<p>Hasło do poczty nadaje administrator po utworzeniu Twojej umowy. Dostajesz je SMS-em na numer telefonu, który podałeś Fundacji.</p>'
                . '<p>Po pierwszym logowaniu zmień je na własne. Robisz to w SZO, na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. '
                . 'Hasło do poczty jest inne niż hasło do logowania jednokrotnego i chmury plików.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie dostałeś SMS-a, zmieniłeś numer telefonu albo potrzebujesz dostępu do konta funkcyjnego? <a href="' . $hd . '">Napisz do Helpdesku IT</a>. '
                . 'Jak się zalogować, opisuje instrukcja <a href="' . $base . 'logowanie-dpoczta">Jak zalogować się do poczty</a>.</p>'],
        ], true],

        'konta-funkcyjne' => ['Konta funkcyjne w dPoczcie (np. kontakt@feer.org.pl)', 'konto funkcyjne, skrzynka funkcyjna, skrzynka wspólna, kontakt@feer.org.pl, pełnomocnictwo, dostęp do skrzynki, wysyłanie jako, tożsamość, adres działu, zarząd', [
            ['type' => 'text', 'html' => '<p>Konto funkcyjne to skrzynka przypisana do grupy osób albo do jednostki organizacyjnej, a nie do jednej osoby. '
                . 'Przykładem jest <code>kontakt@feer.org.pl</code>, które należy do Zarządu. Dzięki temu wiadomości na ten adres widzi kilka osób, a korespondencja zostaje w Fundacji, gdy ktoś odchodzi.</p>'],
            ['type' => 'text', 'html' => '<h2>Jak dostać dostęp</h2>'
                . '<p>Dostęp do konta funkcyjnego nadaje administrator serwera pocztowego na podstawie pełnomocnictwa. Poproś o niego swojego przełożonego albo napisz do '
                . '<a href="' . $hd . '">Helpdesku IT</a>, podając adres konta funkcyjnego i powód. Hasła do konta funkcyjnego nie dostajesz i go nie potrzebujesz.</p>'],
            ['type' => 'text', 'html' => '<h2>Jak z niego korzystać</h2>'
                . '<p>Logujesz się na <a href="https://dpoczta.pl">dpoczta.pl</a> jak zwykle, swoim imiennym adresem i swoim hasłem. Po nadaniu dostępu wiadomości konta funkcyjnego zobaczysz po zalogowaniu, '
                . 'zwykle jako osobne foldery na liście po lewej. Jeśli ich nie widzisz, w ustawieniach otwórz „Foldery” i sprawdź, czy przełączniki przy tych folderach są włączone.</p>'
                . '<p>Żeby odpowiadać z adresu funkcyjnego, dodaj go jako tożsamość. W ustawieniach wybierz „Tożsamości”, kliknij ołówek „Utwórz”, w polu „E-Mail” wpisz adres funkcyjny, '
                . 'a w „Nazwie wyświetlanej” nazwę, na przykład „Fundacja FEER, kontakt”. Wklej stopkę i kliknij „Zapisz”. Przy pisaniu wiadomości wybierzesz ten adres w polu „Od”.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Z adresu funkcyjnego piszesz w imieniu Fundacji albo działu. Odpowiadaj tylko na sprawy, którymi się zajmujesz, i nie przenoś wiadomości konta funkcyjnego do swoich prywatnych folderów. '
                . 'Gdy przestajesz zajmować się tymi sprawami, daj znać w Helpdesku, żeby odebrać Ci dostęp.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Konta techniczne, które obsługują systemy IT, to coś innego niż konta funkcyjne. Nie loguj się na nie i nie pisz z nich. '
                . 'Rodzaje kont opisuje artykuł <a href="' . $base . 'adres-i-haslo">Jaki jest mój adres e-mail i hasło?</a>.</p>'],
        ], false],

        'dpoczta-funkcje' => ['dPoczta: spis funkcji', 'dpoczta, spis funkcji, funkcje, co potrafi, lista, mapa, wszystkie instrukcje, poczta, spis treści', [
            ['type' => 'text', 'html' => '<p>Tu znajdziesz wszystko, co potrafi dPoczta, w jednym miejscu. Przy każdej funkcji jest jedno zdanie, do czego służy, i link do instrukcji krok po kroku.</p>'],
            ['type' => 'text', 'html' => '<h2>Na start</h2><ul>'
                . '<li><a href="' . $base . 'adres-i-haslo">Adres e-mail i hasło</a>: rodzaje kont, wzór adresu i skąd wziąć hasło.</li>'
                . '<li><a href="' . $base . 'konta-funkcyjne">Konta funkcyjne</a>: wspólne skrzynki, na przykład kontakt@feer.org.pl.</li>'
                . '<li><a href="' . $base . 'logowanie-dpoczta">Logowanie</a>: pełny adres e-mail i hasło do poczty, na stronie dpoczta.pl.</li>'
                . '<li><a href="' . $base . 'dpoczta-co-jest-co">Co jest co na ekranie</a>: foldery, lista wiadomości, przyciski i ustawienia.</li>'
                . '<li><a href="' . $base . 'dpoczta-a-outlook">Różnice względem Outlooka</a>: gdzie znaleźć to, co znasz z Microsoft 365.</li>'
                . '<li><a href="' . $base . 'ustawienia-poczty">Ważne ustawienia</a>: język, odświeżanie, wiadomości HTML, autozapis i automatyczna stopka.</li>'
                . '<li><a href="' . $base . 'preferencje">Wszystkie Preferencje</a>: każdy dział ustawień po kolei, z tym, co polecamy.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Pisanie wiadomości</h2><ul>'
                . '<li><a href="' . $base . 'wiadomosci">Wiadomości</a>: czytanie, pisanie, odpowiadanie, załączniki i porządkowanie.</li>'
                . '<li><a href="' . $base . 'generator-stopki">Generator stopki</a>: obowiązkowa stopka Fundacji z Twoimi danymi.</li>'
                . '<li><a href="' . $base . 'podpis-w-poczcie">Podpis (Tożsamości)</a>: wklejenie stopki, nazwa nadawcy i dodatkowe adresy.</li>'
                . '<li><a href="' . $base . 'szablony-odpowiedzi">Odpowiedzi</a>: gotowe teksty do wstawiania w wiadomości.</li>'
                . '<li><a href="' . $base . 'autoresponder">Autoresponder</a>: automatyczna odpowiedź na czas urlopu.</li>'
                . '<li><a href="' . $base . 'duze-zalaczniki">Duże załączniki</a>: kiedy wysłać link zamiast pliku.</li>'
                . '<li><a href="' . $base . 'transfernow-wetransfer">TransferNow i WeTransfer</a>: bardzo duże pliki dla osób spoza Fundacji.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Porządek w skrzynce</h2><ul>'
                . '<li><a href="' . $base . 'filtry-i-przekierowanie">Filtry</a>: automatyczne przenoszenie, kopiowanie i przekazywanie wiadomości.</li>'
                . '<li><a href="' . $base . 'spam-i-zaufani-nadawcy">CleanBox</a>: filtr spamu, zaufani i zablokowani nadawcy.</li>'
                . '<li><a href="' . $base . 'powiadomienia-o-poczcie">Powiadomienia</a>: dźwięk i powiadomienie o nowej wiadomości.</li>'
                . '<li><a href="' . $base . 'problemy-z-odbiorem">Gdy wiadomości nie dochodzą</a>: lista rzeczy do sprawdzenia.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Hasło i bezpieczeństwo</h2><ul>'
                . '<li><a href="' . $base . 'zmiana-hasla-poczty">Zmiana hasła</a>: tylko w SZO, na stronie Nowe hasło.</li>'
                . '<li><a href="' . $base . 'weryfikacja-dwuetapowa">Weryfikacja dwuetapowa</a>: dodatkowy kod przy logowaniu. Zachęcamy do włączenia.</li>'
                . '<li><a href="' . $base . 'szyfrowanie-pgp">Szyfrowanie PGP</a>: szyfrowane wiadomości, dla Zarządu.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Kontakty, kalendarz i inne urządzenia</h2><ul>'
                . '<li><a href="' . $base . 'kontakty">Kontakty</a>: książki adresowe, grupy i nowe kontakty.</li>'
                . '<li><a href="' . $base . 'kalendarz">Kalendarz</a>: spotkania, zaproszenia, udostępnianie i synchronizacja z telefonem. Zastępuje kalendarz z Outlooka.</li>'
                . '<li><a href="' . $app . '/uslugi-it/uslugi/zoom/zamawianie-spotkan">Spotkanie na Zoomie</a>: jak zamówić spotkanie, 2 do 3 dni wcześniej.</li>'
                . '<li><a href="' . $app . '/uslugi-it/uslugi/jitsi-meet/szybkie-spotkanie">Jitsi Meet</a>: szybkie, doraźne spotkanie wideo przez link. Zwykłe spotkania prowadzimy na Zoomie.</li>'
                . '<li><a href="' . $app . '/uslugi-it/uslugi/fathom/notatki-ze-spotkan">Fathom</a>: nagrywanie i notatki ze spotkań, podłączony do konta edukacja@feer.org.pl.</li>'
                . '<li><a href="' . $base . 'poczta-w-telefonie">Poczta w telefonie i programach</a>: dane serwera do Outlooka, Thunderbirda i telefonu.</li>'
                . '<li><a href="' . $base . 'folder-wyslane">Folder Wysłane</a>: żeby wysłane z programu były widoczne wszędzie.</li></ul>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Z „Zadań” w dPoczcie nie korzystamy, zadania są w SZO w module <a href="' . $app . '/tasks/">Zadania</a>. '
                . 'Import i eksport kontaktów jest tylko dla Koordynatorów IT. Brakuje Ci czegoś na tej liście? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
        ], true],

        // 2026-11-14: hasło do poczty TYLKO przez SZO (/nowehaslo) — zmiana w dpoczta.pl nie synchronizuje się z SZO
        'dpoczta-co-jest-co' => ['dPoczta.pl: co jest co na ekranie i w ustawieniach', 'dpoczta, co jest co, ekran, foldery, odebrane, kopie robocze, wysłane, spam, kosz, archiwum, utwórz, szukaj, wątki, pasek, kontakty, kalendarz, zadania, ustawienia, preferencje, tożsamości, odpowiedzi, cloud view, przypomnienie hasła, klucze pgp, filtry, hasło, pomoc, autoresponder, uwierzytelnianie dwuetapowe, cleanbox, tryb ciemny, wyloguj, miejsce w skrzynce', [
            ['type' => 'text', 'html' => '<p>dPoczta.pl to program do czytania poczty w przeglądarce, który udostępnia dostawca naszego serwera pocztowego, firma dhosting.pl. '
                . 'Twoja skrzynka jest na serwerze dostawcy, a dPoczta jest tylko jednym ze sposobów, żeby do niej zajrzeć. Tę samą skrzynkę możesz otworzyć w telefonie albo w programie na komputerze.</p>'
                . '<p>dPoczta jest prostsza od Outlooka. Ma mniej przycisków i opcji, więc łatwiej się w niej odnaleźć, a do codziennej pracy z pocztą wystarcza.</p>'],
            ['type' => 'text', 'html' => '<p>Po zalogowaniu na <a href="https://dpoczta.pl">dpoczta.pl</a> ekran ma trzy kolumny: po lewej foldery, w środku listę wiadomości, '
                . 'a po prawej treść otwartej wiadomości. Na samym prawym brzegu jest wąski pasek z przejściem do innych części poczty.</p>'],
            ...itPortalDpocztaShot('dp-skrzynka.jpg', 'Główny ekran dPoczty: po lewej przycisk Utwórz i foldery z liczbą nieprzeczytanych wiadomości, w środku zamazana lista wiadomości z polem Szukaj, po prawej pusty podgląd, na prawym brzegu pasek z ikonami', 'Główny ekran poczty. Lista wiadomości jest celowo zamazana.'),
            ['type' => 'text', 'html' => '<h2>Lewa kolumna: foldery</h2>'
                . '<p>Na górze jest Twój adres e-mail, a obok trzy kropki z menu konta. Duży przycisk „Utwórz” otwiera nową wiadomość.</p>'
                . '<p>Niżej są foldery: Odebrane, Kopie robocze (zaczęte, niewysłane wiadomości), Wysłane, Spam, Kosz i Archiwum, a pod nimi Twoje własne foldery. '
                . 'Liczba przy folderze to liczba nieprzeczytanych wiadomości. Foldery dodasz, zmienisz i usuniesz w ustawieniach, w części „Foldery”.</p>'
                . '<p>Na dole kolumny jest pasek z procentem zajętego miejsca w skrzynce. Gdy zbliża się do pełna, opróżnij Kosz i Spam albo przenieś stare załączniki do chmury.</p>'],
            ['type' => 'text', 'html' => '<h2>Środek: lista wiadomości</h2>'
                . '<p>Na górze jest pole „Szukaj…”. Ikona koperty i strzałka obok otwierają opcje, na przykład szukanie tylko w nadawcy albo w temacie. '
                . 'Nad listą są też przyciski do zaznaczania wiadomości, ustawień listy i odświeżania.</p>'
                . '<p>Każda wiadomość ma nadawcę, temat i datę. Pogrubiony temat oznacza wiadomość nieprzeczytaną. Strzałka po lewej stronie wiadomości rozwija wątek, '
                . 'czyli całą rozmowę z odpowiedziami. Na dole są strzałki do przechodzenia między stronami listy i informacja, ile jest wątków.</p>'],
            ['type' => 'text', 'html' => '<h2>Prawa kolumna: wiadomość</h2>'
                . '<p>Nad otwartą wiadomością są przyciski: Odpowiedz, Odpowiedz wszystkim, Przekaż, Usuń, Archiwizuj, Oznacz jako spam (ikona płomienia), '
                . 'Etykiety (ikona metki) i trzy kropki z pozostałymi działaniami: drukowaniem, pobraniem, pokazaniem źródła, przeniesieniem, kopiowaniem, '
                . 'otwarciem w nowym oknie i utworzeniem filtra.</p>'
                . '<p>Źródło wiadomości to jej pełny zapis z nagłówkami, po których widać, skąd naprawdę przyszła. Gdy zgłaszasz podejrzaną wiadomość do Helpdesku, '
                . 'kliknij trzy kropki, potem „Pobierz…” i „Źródło wiadomości (eml)”, a pobrany plik dołącz do zgłoszenia. '
                . 'Opis dostawcy: <a href="' . $kb . 'gdzie-znalezc-zrodlo-wiadomosci-w-dpoczta-pl/">gdzie znaleźć źródło wiadomości w dPoczcie</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Pasek po prawej</h2>'
                . '<p>Ikony od góry: Poczta (koperta), Kontakty, Kalendarz, Zadania, Ustawienia (koło zębate) i inne aplikacje dostawcy (kratka). '
                . 'Na dole są księżyc, który włącza tryb ciemny, i czerwony przycisk wyłączania, którym się wylogujesz.</p>'
                . '<p>Z „Zadań” w dPoczcie nie korzystamy. Zadania prowadzimy w SZO, w module <a href="' . $app . '/tasks/">Zadania</a>, gdzie widzą je cały zespół i obszar.</p>'],
            ['type' => 'text', 'html' => '<h2>Ustawienia</h2>'
                . '<p>Po kliknięciu koła zębatego po lewej jest lista części ustawień:</p><ul>'
                . '<li>Preferencje: wygląd, język, strefa czasowa, sposób wyświetlania i pisania wiadomości.</li>'
                . '<li>Foldery: lista wszystkich folderów z przełącznikami. Wyłączony przełącznik chowa folder z lewej kolumny, ale nie usuwa wiadomości. '
                . 'Przyciski na górze: ołówek tworzy nowy folder, kosz usuwa zaznaczony, a gumka opróżnia go z wiadomości. Na dole jest pasek zajętego miejsca.</li>'
                . '<li>Tożsamości: nazwa nadawcy i podpis, czyli stopka. Opisuje to artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/podpis-w-poczcie">Jak ustawić podpis (stopkę) w poczcie</a>.</li>'
                . '<li>Odpowiedzi: gotowe teksty do wstawiania w wiadomości. Na początku lista jest pusta, a nowy tekst dodasz ikoną ołówka na górze. Opisuje to artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/szablony-odpowiedzi">Szablony odpowiedzi</a>.</li>'
                . '<li>Cloud View: ustawienia dodatku dostawcy poczty. Nie musisz ich zmieniać.</li>'
                . '<li>Klucze PGP: szyfrowanie i podpisywanie wiadomości, opisane w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/szyfrowanie-pgp">Szyfrowanie PGP</a>. Korzysta z nich Zarząd, pozostałe osoby nie muszą tu nic zmieniać.</li>'
                . '<li>Filtry: automatyczne przenoszenie i przekazywanie wiadomości, opisane w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/filtry-i-przekierowanie">Filtry i przekierowanie poczty</a>.</li>'
                . '<li>Pomoc: „Centrum pomocy” dostawcy z rozwijanymi działami: Preferencje, Tożsamości, Uwierzytelnianie dwuetapowe (2FA), Kalendarz i Dodatkowe informacje.</li>'
                . '<li>Autoresponder: odpowiedź na czas urlopu, opisana w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/autoresponder">Autoodpowiedź na czas nieobecności</a>.</li>'
                . '<li>Uwierzytelnianie dwuetapowe: dodatkowy kod przy logowaniu. Zachęcamy, żeby je włączyć. Opisuje to artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/weryfikacja-dwuetapowa">Weryfikacja dwuetapowa w poczcie</a>.</li>'
                . '<li>CleanBox: filtr antyspamowy, opisany w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/spam-i-zaufani-nadawcy">Spam, zablokowani i zaufani nadawcy</a>.</li></ul>'],
            ...itPortalDpocztaShot('dp-ustawienia-menu.jpg', 'Lista części ustawień dPoczty: Preferencje, Foldery, Tożsamości, Odpowiedzi, Cloud View, Przypomnienie hasła, Klucze PGP, Filtry, Hasło, Pomoc, Autoresponder, Uwierzytelnianie dwuetapowe, CleanBox', 'Ustawienia: lista części po lewej.'),
            ...itPortalDpocztaShot('dp-foldery.jpg', 'Ustawienia dPoczty, Foldery: lista folderów z przełącznikami, na górze ikony ołówka, kosza i gumki, na dole pasek zajętego miejsca', 'Ustawienia, „Foldery”.'),
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Z części „Hasło” i „Przypomnienie hasła” w ustawieniach poczty nie korzystamy. Formularz „Aktualne hasło, Nowe hasło, Potwierdź hasło” zmieniłby hasło tylko w poczcie i nie trafi ono do SZO. '
                . 'Hasło do poczty zmieniasz tylko na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>, jak opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/zmiana-hasla-poczty">Jak zmienić hasło do poczty</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie wiesz, do czego służy jakiś przycisk? Najedź na niego myszą, a pokaże się podpowiedź. Z pytaniami <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], true],

        'dpoczta-a-outlook' => ['dPoczta a Outlook: najważniejsze różnice', 'dpoczta, outlook, różnice, microsoft 365, porównanie, gdzie jest, reguły, filtry, podpis, tożsamości, automatyczne odpowiedzi, autoresponder, kategorie, etykiety, kalendarz, kontakty, onedrive, załączniki, spam, hasło', [
            ['type' => 'text', 'html' => '<p>Jeśli do tej pory pracowałeś w Outlooku z Microsoft 365, w dPoczcie większość rzeczy znajdziesz łatwo, ale część ma inne nazwy albo działa trochę inaczej. Poniżej najważniejsze różnice.</p>'],
            ['type' => 'text', 'html' => '<h2>Ogólnie</h2>'
                . '<p>Outlook to rozbudowany program Microsoftu, który był połączony z OneDrive, Teams i resztą Microsoft 365. '
                . 'dPoczta to prostszy program w przeglądarce od dostawcy naszego serwera pocztowego. Ma mniej przycisków, nie wymaga instalacji i działa w każdej przeglądarce, także w telefonie.</p>'
                . '<p>Twoja skrzynka jest teraz na serwerze dhosting, a nie w Microsoft 365. Program Outlook na komputerze możesz nadal podłączyć do nowej skrzynki jako konto IMAP, '
                . 'ale nie będzie już działał z kontem Microsoft dla poczty Fundacji.</p>'],
            ['type' => 'text', 'html' => '<h2>Te same rzeczy pod innymi nazwami</h2><ul>'
                . '<li>Reguły z Outlooka to w dPoczcie „Filtry”.</li>'
                . '<li>Podpisy to „Tożsamości”, w części „Podpis”.</li>'
                . '<li>Automatyczne odpowiedzi to „Autoresponder”.</li>'
                . '<li>Kategorie to „Etykiety”, ikona metki nad wiadomością.</li>'
                . '<li>Moje szablony albo szybkie części to „Odpowiedzi”.</li>'
                . '<li>Zadania z Outlooka albo Microsoft To Do prowadzimy teraz w SZO, w module <a href="' . $app . '/tasks/">Zadania</a>. Z „Zadań” w dPoczcie nie korzystamy.</li>'
                . '<li>Elementy wysłane to folder „Wysłane”, a Wersje robocze to „Kopie robocze”.</li>'
                . '<li>Wiadomości-śmieci to folder „Spam”, a ustawienia filtra to „CleanBox”.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Co działa inaczej</h2>'
                . '<p>Filtr spamu jest łagodniejszy niż w Outlooku, więc do Odebranych może trafić więcej reklam. Pierwsza wiadomość od nowego nadawcy może przyjść z opóźnieniem przez filtr GreyList.</p>'
                . '<p>Wyszukiwarka szuka domyślnie w bieżącym folderze. Żeby przeszukać całą skrzynkę, wybierz to w opcjach obok pola „Szukaj…”.</p>'
                . '<p>Kontakty to Twoje osobiste kontakty i adresy zbierane automatycznie. Nie ma wspólnej listy adresowej całej organizacji z Microsoft 365.</p>'
                . '<p>Kalendarz w dPoczcie zastępuje kalendarz z Outlooka, ale stare wydarzenia nie przenoszą się same. Spotkania z zaproszeniami działają, ale wolne terminy innych osób sprawdzisz tylko wtedy, gdy udostępnią Ci swój kalendarz.</p>'
                . '<p>Duże pliki nie trafiają już do OneDrive. Zamiast dużego załącznika wyślij link z chmury Fundacji.</p>'
                . '<p>Hasło do poczty jest osobne i zmieniasz je w SZO na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>, a nie w ustawieniach poczty. '
                . 'Weryfikację dwuetapową włączasz w samej dPoczcie.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Po kolei, co jest na ekranie dPoczty, opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/dpoczta-co-jest-co">dPoczta.pl: co jest co</a>. '
                . 'Brakuje Ci czegoś, co było w Outlooku? <a href="' . $hd . '">Napisz do Helpdesku IT</a>, sprawdzimy, jak to zrobić w nowej poczcie.</p>'],
        ], false],

        'preferencje' => ['Preferencje dPoczty: wszystkie działy po kolei', 'preferencje, ustawienia, interfejs użytkownika, widok skrzynki pocztowej, wyświetlanie wiadomości, tworzenie wiadomości, książka adresowa, foldery specjalne, szyfrowanie, ustawienia serwera, pasek boczny, ustawienia wyglądu, kalendarz, powiadomienia pulpitu, zdalne zasoby, obrazki, rozmiar czcionki, opróżnij kosz', [
            ['type' => 'text', 'html' => '<p>Preferencje otworzysz kołem zębatym na prawym pasku i pozycją „Preferencje” na górze listy. W środkowej kolumnie są działy, a po prawej ich ustawienia. '
                . 'Po każdej zmianie kliknij „Zapisz” na dole. Poniżej opisujemy każdy dział i podajemy, co polecamy. Najważniejsze ustawienia zebraliśmy też w artykule '
                . '<a href="' . $base . 'ustawienia-poczty">Ważne i przydatne ustawienia poczty</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Interfejs użytkownika</h2>'
                . '<p>Język, strefa czasowa, format czasu i daty, „Ładne daty” (napisy Dzisiaj i Wczoraj), przejście do następnej wiadomości po usunięciu i to, jak często poczta sprawdza nowe wiadomości. '
                . 'Polecamy język polski, automatyczną strefę czasową i odświeżanie co 1 minutę. W „Opcjach przeglądarki” jest link, który ustawia dPocztę jako program do otwierania adresów e-mail klikniętych na stronach.</p>'],
            ['type' => 'text', 'html' => '<h2>Widok skrzynki pocztowej</h2>'
                . '<p>„Oznacz wiadomości jako przeczytane” ustala, czy wiadomość staje się przeczytana od razu po otwarciu, czy po kilku sekundach. „Rozwijaj wątki” decyduje, czy rozmowy z odpowiedziami są od razu rozwinięte. '
                . '„Liczba wierszy na stronie” to ile wiadomości widać na liście naraz.</p>'
                . '<p>W części „Nowa wiadomość” włącz „Sprawdzaj czy nadeszły nowe wiadomości we wszystkich folderach”, jeśli filtry przenoszą Ci pocztę do innych folderów. '
                . '„Powiadomienia Pulpitu” pokażą dymek o nowej wiadomości na ekranie komputera. Włącz je tylko na swoim komputerze. Opisuje to artykuł '
                . '<a href="' . $base . 'powiadomienia-o-poczcie">Powiadomienia o nowej poczcie</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Wyświetlanie wiadomości</h2>'
                . '<p>„Domyślny HTML” zostaw włączony, żeby wiadomości wyglądały tak, jak przygotował je nadawca. „Zezwól na zdalne zasoby (obrazki, style)” zostaw na „nigdy”. '
                . 'Obrazki z obcych serwerów służą często do śledzenia i oszustw. Pojedynczej zaufanej wiadomości pozwolisz na nie przyciskiem nad treścią.</p>'
                . '<p>„Na żądanie potwierdzenia odbioru” ustawione na „pytaj mnie” sprawia, że sam decydujesz, czy nadawca dostanie potwierdzenie. '
                . '„Domyślnego zestawu znaków” w opcjach zaawansowanych nie zmieniaj.</p>'],
            ['type' => 'text', 'html' => '<h2>Tworzenie wiadomości</h2>'
                . '<p>Wiadomości HTML, autozapis, cytowanie i stopka. Szczegółowo opisuje je artykuł <a href="' . $base . 'ustawienia-poczty">Ważne i przydatne ustawienia poczty</a>. '
                . 'W opcjach zaawansowanych zostaw „Stosuj nazwy załączników zgodne z” na „RFC 2047/2231 (MS Outlook)”. Dzięki temu polskie znaki w nazwach załączników dobrze widzą też odbiorcy z Outlookiem.</p>'],
            ['type' => 'text', 'html' => '<h2>Książka adresowa</h2>'
                . '<p>Która książka jest domyślna, jak wyświetlać i sortować kontakty oraz gdzie poczta zapisuje adresy osób, do których piszesz („Zebrani adresaci”), i zaufanych nadawców. '
                . 'Zostaw ustawienia domyślne. „Używaj Zdjęć z Gravatar” zostaw wyłączone, bo wysyła adresy e-mail do zewnętrznej usługi. Więcej w artykule <a href="' . $base . 'kontakty">Kontakty w dPoczcie</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Foldery specjalne</h2>'
                . '<p>Tu poczta wie, który folder jest Kopiami roboczymi, Wysłanymi, Spamem, Koszem i Archiwum. Nie zmieniaj tych przypisań, bo programy na komputerze i telefon przestałyby trafiać do właściwych folderów. '
                . 'W części „Archiwum” możesz ustawić, żeby archiwizowane wiadomości same dzieliły się na podfoldery, na przykład według roku.</p>'],
            ['type' => 'text', 'html' => '<h2>Szyfrowanie</h2>'
                . '<p>Ustawienia PGP: czy podpisywać i szyfrować wiadomości domyślnie i jak długo pamiętać hasło do klucza. Z PGP korzysta Zarząd, pozostali nie muszą tu nic zmieniać. '
                . 'Opisuje to artykuł <a href="' . $base . 'szyfrowanie-pgp">Szyfrowanie PGP dla Zarządu</a>. Część „Opcje Mailvelope” dotyczy dodatku do przeglądarki i nie jest potrzebna.</p>'],
            ['type' => 'text', 'html' => '<h2>Ustawienia serwera</h2>'
                . '<p>Co dzieje się przy usuwaniu i archiwizowaniu wiadomości. „Przy wylogowaniu opróżnij Kosz” zostaw na „nigdy”, żeby móc odzyskać przypadkiem usunięte wiadomości. '
                . '„Kasuj wiadomości bezpośrednio w folderze Spam” zostaw wyłączone. Pozostałe opcje możesz zostawić bez zmian.</p>'],
            ['type' => 'text', 'html' => '<h2>Pasek boczny, Ustawienia wyglądu i Kalendarz</h2>'
                . '<p>„Pasek boczny” pozwala pokazać przy poczcie agendę kalendarza, czyli listę najbliższych spotkań. '
                . '„Ustawienia wyglądu” zmieniają ikony, rozmiar czcionki i grubość liter. Jeśli źle widzisz drobny tekst, ustaw większą czcionkę albo włącz „Grubą czcionkę”. '
                . 'Dział „Kalendarz” ustala domyślny widok i godziny pracy w kalendarzu, opisanym w artykule <a href="' . $base . 'kalendarz">Kalendarz</a>.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Nie wiesz, czy coś zmieniać? Zostaw ustawienie domyślne. Żadna opcja w Preferencjach nie usuwa wiadomości od razu, '
                . 'a każdą zmianę cofniesz w tym samym miejscu. Z pytaniami <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], false],

        'ustawienia-poczty' => ['Ważne i przydatne ustawienia poczty', 'ustawienia, preferencje, interfejs użytkownika, język, strefa czasowa, format daty, odświeżanie, nowe wiadomości, mailto, tworzenie wiadomości, html, automatyczny zapis, kopia robocza, potwierdzenie odbioru, dsn, odpowiedz wszystkim, przekazywanie, czcionka, podpis, stopka, automatycznie wstaw podpis, separator, cytowanie', [
            ['type' => 'text', 'html' => '<p>W ustawieniach poczty, w części „Preferencje”, jest kilka ustawień, które warto sprawdzić od razu po przejściu na dPocztę. '
                . 'Otworzysz je kołem zębatym na prawym pasku, a potem wybierzesz „Preferencje”. Środkowa kolumna pokazuje działy, a prawa ich ustawienia. '
                . 'Po każdej zmianie kliknij „Zapisz” na dole.</p>'],
            ['type' => 'text', 'html' => '<h2>Interfejs użytkownika</h2>'
                . '<p>„Język” ustaw na Polish (Polski), a „Strefę czasową” zostaw na wyborze automatycznym. „Format czasu” i „Format daty” wybierz tak, jak lubisz, na przykład 07:30 i 24.7.2026. '
                . '„Ładne daty” pokazują przy wiadomościach napisy „Dzisiaj” i „Wczoraj” zamiast samej daty.</p>'
                . '<p>„Po usunięciu elementu listy wyświetl następny” otwiera kolejną wiadomość, gdy usuniesz bieżącą. Przyspiesza porządki w skrzynce. '
                . '„Odświeżanie” ustala, jak często poczta sprawdza nowe wiadomości. Co 1 minutę to dobre ustawienie.</p>'
                . '<p>Link „Zainstaluj obsługę linków mailto:” sprawia, że kliknięcie adresu e-mail na dowolnej stronie otworzy nową wiadomość w dPoczcie, a nie w programie na komputerze. '
                . 'Przeglądarka zapyta o zgodę. Zgódź się na swoim komputerze.</p>'],
            ...itPortalDpocztaShot('dp-interfejs.jpg', 'Preferencje dPoczty, Interfejs użytkownika: Język Polish, Strefa czasowa automatyczna, Format czasu, Format daty, Ładne daty, Po usunięciu wyświetl następny, Odświeżanie co 1 minutę, Opcje przeglądarki', 'Preferencje, dział „Interfejs użytkownika”.'),
            ['type' => 'text', 'html' => '<h2>Tworzenie wiadomości</h2>'
                . '<p>To najważniejszy dział, bo decyduje o stopce i o tym, czy nic nie zginie w trakcie pisania.</p>'],
            ...itPortalDpocztaShot('dp-tworzenie-wiadomosci.jpg', 'Ustawienia dPoczty, Preferencje, Tworzenie wiadomości: Opcje główne i Opcje podpisów z przełącznikami i listami wyboru',
                'Preferencje, dział „Tworzenie wiadomości” z polecanymi ustawieniami.'),
            ['type' => 'text', 'html' => '<p>„Twórz wiadomości HTML” ustaw na „zawsze”. Tylko wtedy stopka z generatora wygląda tak jak w podglądzie, z pogrubieniami i linkami.</p>'
                . '<p>„Automatycznie zapisuj tworzoną wiadomość” ustaw na „co 1 minut(y)”. Pisana wiadomość zapisuje się wtedy w Kopiach roboczych, więc nie przepadnie, gdy zamkniesz kartę albo stracisz internet.</p>'
                . '<p>„Zawsze żądaj potwierdzenia odbioru” i „Zawsze żądaj statusu dostarczenia (DSN)” zostaw wyłączone. Potwierdzenia odbioru irytują odbiorców, '
                . 'a gdy naprawdę potrzebujesz go przy jednej wiadomości, zaznaczysz je przy jej pisaniu.</p>'
                . '<p>„Podczas odpowiadania rozpocznij wiadomość” ustaw na „powyżej cytowanej treści”. Odbiorca od razu widzi Twoją odpowiedź, a pod nią poprzednią wiadomość. '
                . '„Przekazywanie wiadomości” ustawione na „w treści” przekazuje wiadomość jako tekst. Wybierz „jako załącznik”, gdy odbiorca ma dostać oryginał, na przykład do zgłoszenia spamu.</p>'
                . '<p>„Czcionka wiadomości HTML” to krój i wielkość liter, którymi piszesz. Wybierz czytelny krój, na przykład Verdana albo Arial, w rozmiarze 10 albo 12 pt.</p>'],
            ['type' => 'text', 'html' => '<h2>Opcje podpisów</h2>'
                . '<p>„Automatycznie wstaw podpis” ustaw na „zawsze”. Stopka dopisze się wtedy sama do każdej nowej wiadomości, odpowiedzi i przekazania.</p>'
                . '<p>„Umieść podpis poniżej cytowanej wiadomości” zostaw wyłączone, żeby stopka była zaraz pod Twoją odpowiedzią, a nie na samym dole długiej rozmowy.</p>'
                . '<p>„Podczas odpowiedzi usuń podpis z cytowanej treści” i „Wymuś standardowy separator w podpisie” zostaw włączone. '
                . 'Pierwsze nie powiela stopek w długich wątkach, a drugie dodaje przed stopką krótką kreskę, dzięki której programy pocztowe rozpoznają podpis.</p>'
                . '<p>Samą stopkę wklejasz w „Tożsamościach”, jak opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/podpis-w-poczcie">Jak ustawić podpis (stopkę) w poczcie</a>.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Pozostałe działy, na przykład „Widok skrzynki pocztowej”, „Wyświetlanie wiadomości” czy „Ustawienia wyglądu”, możesz zostawić bez zmian. '
                . 'Jeśli coś przestanie Ci się podobać, wróć do Preferencji i zmień to z powrotem. Nic tu nie usuwa wiadomości.</p>'],
        ], false],

        'zmiana-hasla-poczty' => ['Jak zmienić hasło do poczty', 'hasło, zmiana hasła, nowe hasło, nowehaslo, szo, dpoczta, wymagania hasła, synchronizacja', [
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p><strong>Ważne: hasło do poczty zmieniaj wyłącznie w SZO, na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>.</strong> ' . 'Nie zmieniaj go w ustawieniach dpoczta.pl. Hasło zmienione w dpoczta.pl nie trafi do SZO, więc systemy się rozjadą: ' . 'kopia poczty, Gosposia i inne usługi przestaną działać, a przy kolejnej zmianie hasło może wrócić do starego.</p>'],
            ['type' => 'wizard', 'title' => 'Zmiana hasła w SZO', 'intro' => '', 'steps' => [
                $step('Otwórz stronę Nowe hasło', '<p>Wejdź na <a href="' . $app . '/nowehaslo">Nowe hasło</a> w SZO. W panelu współpracownika kliknij kafel „Zmiana haseł”. '
                    . 'Wejdziesz tam też bez logowania: podaj prywatny e-mail albo PESEL z umowy oraz adres służbowy.</p>'),
                $step('Wybierz hasło do poczty', '<p>Zaznacz „Poczta”. Jeśli jesteś zalogowany do SZO, kod nie jest potrzebny. '
                    . 'Bez logowania kliknij „Wyślij kod”: przyjdzie SMS-em, a gdy nie mamy Twojego numeru, e-mailem na prywatny adres.</p>'),
                $step('Ustaw nowe hasło', '<p>Wpisz nowe hasło dwa razy (bez logowania także kod) i kliknij „Zmień hasło”. Możesz też wybrać hasło losowe, które przyjdzie SMS-em.</p>'),
                $step('Wyloguj się z poczty i zaloguj nowym hasłem', '<p>Jeśli masz otwartą pocztę na dpoczta.pl, wyloguj się z niej i zaloguj nowym hasłem. '
                    . 'Inaczej poczta pokaże błąd „AUTHENTICATE PLAIN: Authentication failed”.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nowe hasło musi mieć co najmniej 12 znaków, w tym małą literę, wielką literę i cyfrę.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Masz pocztę też w telefonie albo w Outlooku? Po zmianie hasła wpisz nowe także tam, inaczej program przestanie pobierać wiadomości.</p>'],
        ], false],

        // 2026-11-14: błąd w dpoczta.pl po zmianie hasła w SZO bez wylogowania się z poczty
        'blad-authenticate-plain' => ['Błąd „AUTHENTICATE PLAIN: Authentication failed” po zmianie hasła',
            'authenticate plain, authentication failed, błąd serwera, błąd logowania, lista jest pusta, folder jest pusty, pusta skrzynka, zniknęły wiadomości, '
            . 'po zmianie hasła, dpoczta, wyloguj, nowe hasło, nowehaslo', [
            ['type' => 'text', 'html' => '<p>Po zmianie hasła do poczty w SZO otwarta karta dpoczta.pl może pokazać czerwony komunikat '
                . '<strong>„Błąd serwera: AUTHENTICATE PLAIN: Authentication failed”</strong>, a lista wiadomości robi się pusta.</p>'
                . '<p>Twoje wiadomości są bezpieczne. Poczta próbuje się łączyć starym hasłem, bo nie wylogowałeś się po zmianie.</p>'],
            ['type' => 'wizard', 'title' => 'Jak to naprawić', 'intro' => '', 'steps' => [
                $step('Wyloguj się z poczty', '<p>Na dpoczta.pl kliknij ikonę wyłączania (wyloguj) w prawym dolnym rogu albo zamknij kartę z pocztą.</p>'),
                $step('Zaloguj się nowym hasłem', '<p>Otwórz <a href="https://dpoczta.pl">dpoczta.pl</a> i zaloguj się adresem <code>imie.nazwisko@feer.org.pl</code> i <strong>nowym</strong> hasłem.</p>'),
                $step('Popraw hasło w telefonie i programie', '<p>Jeśli masz pocztę w telefonie albo w Outlooku, tam też wpisz nowe hasło. Inaczej program będzie pokazywał błąd logowania.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Błąd nie znika po wylogowaniu i zalogowaniu nowym hasłem? Sprawdź, czy wpisujesz nowe hasło, a nie startowe z SMS-a. '
                . 'Gdy dalej nie działa, napisz do <a href="' . $hd . '">Helpdesku IT</a>. Hasło zmieniaj tylko w SZO, na stronie '
                . '<a href="' . $app . '/nowehaslo">Nowe hasło</a>, nie w ustawieniach dpoczta.pl.</p>'],
        ], false],

        'poczta-w-telefonie' => ['Poczta w telefonie, Outlooku i Thunderbirdzie', 'imap, smtp, pop3, telefon, outlook, microsoft 365, pożegnanie z outlookiem, stare konto, office.com, brakująca wiadomość, stara poczta, thunderbird, apple mail, konfiguracja, serwer, port', [
            ['type' => 'callout', 'kind' => 'important', 'html' => itPortalDpocztaOutlookBye()],
            ['type' => 'text', 'html' => '<p>Pocztę możesz dodać do aplikacji w telefonie albo do programu na komputerze. Wybierz konto typu IMAP i wpisz dane z tabeli.</p>'
                . '<table><thead><tr><th>Ustawienie</th><th>Wartość</th></tr></thead><tbody>'
                . '<tr><td>Nazwa użytkownika</td><td><code>imie.nazwisko@feer.org.pl</code></td></tr>'
                . '<tr><td>Hasło</td><td>hasło do skrzynki pocztowej</td></tr>'
                . '<tr><td>Poczta przychodząca (IMAP)</td><td><code>imap.dpoczta.pl</code>, port 993, SSL/TLS</td></tr>'
                . '<tr><td>Poczta wychodząca (SMTP)</td><td><code>smtp.dpoczta.pl</code>, port 587, STARTTLS</td></tr>'
                . '<tr><td>Serwer wychodzący wymaga logowania</td><td>tak, ten sam login i hasło</td></tr>'
                . '</tbody></table>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Wybieraj IMAP. Wiadomości zostają wtedy na serwerze i widzisz je tak samo w telefonie, na komputerze i w przeglądarce.</p>'],
            ['type' => 'text', 'html' => '<p>POP3 (<code>pop3.dpoczta.pl</code>, port 995, SSL/TLS) pobiera wiadomości na jedno urządzenie i zwykle usuwa je z serwera. Jeśli to urządzenie się zepsuje, wiadomości przepadną.</p>'],
            ['type' => 'text', 'html' => '<h2>Instrukcje dostawcy ze zrzutami</h2>'
                . '<p>Dostawca poczty ma instrukcje krok po kroku dla popularnych programów. Wpisuj w nich dane z tabeli powyżej:</p><ul>'
                . '<li><a href="' . $kb . 'jak-skonfigurowac-poczte-w-outlook-365/">Outlook z Microsoft 365</a></li>'
                . '<li><a href="' . $kb . 'konfiguracja-poczty-na-ios-11-iphone/">iPhone</a></li>'
                . '<li><a href="https://dhosting.pl/pomoc/kategoria/poczta/konfiguracja-programow-pocztowych/">wszystkie instrukcje konfiguracji programów pocztowych</a></li></ul>'
                . '<p>Po dodaniu skrzynki sprawdź, gdzie program zapisuje wysłane wiadomości, jak opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/folder-wyslane">Folder „Wysłane”</a>.</p>'],
        ], true],

        'weryfikacja-dwuetapowa' => ['Weryfikacja dwuetapowa (2FA) w poczcie', '2fa, weryfikacja dwuetapowa, uwierzytelnianie dwuetapowe, uwierzytelnienie, bezpieczne konto, włamanie, authenticator, kod, bezpieczeństwo, kody zapasowe', [
            ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Zachęcamy wszystkich do włączenia weryfikacji dwuetapowej. Zajmuje to pięć minut, a skrzynka służbowa jest wtedy bezpieczna, '
                . 'nawet jeśli ktoś wyłudzi albo podejrzy Twoje hasło. Większość włamań do poczty zaczyna się właśnie od wykradzionego hasła.</p>'],
            ['type' => 'text', 'html' => '<p>Weryfikacja dwuetapowa chroni skrzynkę, nawet gdy ktoś pozna Twoje hasło. Przy logowaniu oprócz hasła podajesz kod z aplikacji w telefonie.</p>'],
            ['type' => 'wizard', 'title' => 'Włączanie 2FA', 'intro' => '<p>Najpierw zainstaluj w telefonie aplikację z kodami, np. Google Authenticator albo Microsoft Authenticator.</p>', 'steps' => [
                $step('Otwórz ustawienia', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' W lewej kolumnie wybierz „Uwierzytelnianie dwuetapowe”.</p>'),
                $step('Wybierz metodę i potwierdź hasłem', '<p>Zobaczysz dwie metody: „Aplikacja mobilna”, która generuje kody w telefonie, i „Email”, która wysyła kody na inny adres e-mail. '
                    . 'Polecamy aplikację mobilną. Kliknij „Włącz” przy wybranej metodzie. W okienku „Potwierdź hasło” wpisz hasło do skrzynki i kliknij „Kontynuuj”.</p>'),
                $step('Zeskanuj kod QR', '<p>Otwórz aplikację w telefonie i zeskanuj kod QR z ekranu. Wpisz kod, który pokaże aplikacja, i kliknij „Zakończ”.</p><p>Zamiast aplikacji możesz podać inny adres e-mail (nie ten służbowy). Kod przyjdzie tam i jest ważny 5 minut.</p>'),
                $step('Zapisz kody zapasowe', '<p>Kliknij „Pokaż kody zapasowe” i zapisz je albo wydrukuj. Przydadzą się, gdy zgubisz telefon. Zużyte kody wymienisz przyciskiem „Wygeneruj nowe kody zapasowe”.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-2fa.jpg', 'Ustawienia dPoczty, Uwierzytelnianie dwuetapowe: metody Aplikacja mobilna i Email, każda z przyciskiem Włącz', 'Ustawienia, „Uwierzytelnianie dwuetapowe”: wybór metody.'),
        ], false],

        'szyfrowanie-pgp' => ['Szyfrowanie PGP dla Zarządu: klucze i jak je wygenerować', 'pgp, gpg, openpgp, zarząd, szyfrowanie, zaszyfrowana wiadomość, wyślij klucz publiczny, podpis cyfrowy, klucz publiczny, klucz prywatny, generowanie klucza, klucze pgp, enigma, rsa, ecc, import klucza, eksport klucza, serwer kluczy', [
            ['type' => 'text', 'html' => '<p>PGP to sposób szyfrowania wiadomości e-mail od nadawcy do odbiorcy. Zaszyfrowaną wiadomość przeczyta tylko odbiorca, nawet jeśli ktoś przechwyci ją po drodze albo zajrzy na serwer. '
                . 'PGP pozwala też podpisać wiadomość cyfrowo, żeby odbiorca wiedział, że naprawdę pochodzi od Ciebie i nikt jej nie zmienił.</p>'
                . '<p>Każda osoba ma parę kluczy. Klucz publiczny dajesz innym: służy im do szyfrowania wiadomości do Ciebie i do sprawdzania Twojego podpisu. '
                . 'Klucz prywatny zostaje u Ciebie i chroni go hasło. Służy do odczytywania wiadomości i do podpisywania.</p>'],
            ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Szyfrowanie PGP w Fundacji jest przeznaczone dla Zarządu. Jeśli nie jesteś członkiem Zarządu, nie generuj kluczy PGP. '
                . 'Gdy ktoś prosi Cię o szyfrowaną korespondencję, <a href="' . $hd . '">napisz do Helpdesku IT</a>, a pomożemy wybrać inny sposób.</p>'
                . '<p>Szyfrowanie działa tylko wtedy, gdy obie strony mają klucze. Dane osobowe dla osób spoza Fundacji łatwiej wysłać linkiem z chmury zabezpieczonym hasłem.</p>'],
            ['type' => 'wizard', 'title' => 'Jak wygenerować klucz', 'intro' => '', 'steps' => [
                $step('Otwórz Klucze PGP', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' W lewej kolumnie wybierz „Klucze PGP”. '
                    . 'Na początku lista jest pusta i pojawia się komunikat „Nie znaleziono kluczy”.</p>'),
                $step('Kliknij Utwórz', '<p>Na górze kliknij ikonę ołówka „Utwórz”. Pojawi się formularz z informacją, że wszystkie klucze, publiczne i prywatne, są przechowywane na serwerze.</p>'),
                $step('Wybierz tożsamość i typ klucza', '<p>Przy „Tożsamości” zostaw włączony swój adres. W polu „Typ klucza” wybierz „RSA 4096 bitów”, który działa z większością programów. '
                    . '„RSA 2048 bitów” też jest bezpieczny, a „ECC (krzywe eliptyczne)” jest nowocześniejszy, ale starsze programy mogą go nie obsłużyć.</p>'),
                $step('Ustaw hasło do klucza', '<p>Wpisz hasło w polach „Hasło” i „Potwierdź hasło”. To osobne hasło do klucza prywatnego, inne niż do poczty. '
                    . 'Zapamiętaj je albo zapisz w menedżerze haseł. Bez niego nie odczytasz zaszyfrowanych wiadomości i nikt go nie odzyska.</p>'),
                $step('Zapisz', '<p>Kliknij „Zapisz”. Tworzenie klucza może potrwać kilkadziesiąt sekund. Gotowy klucz pojawi się na liście po lewej.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-pgp-nowy.jpg', 'Klucze PGP w dPoczcie, formularz Utwórz: informacja o przechowywaniu kluczy na serwerze, Tożsamość, Typ klucza RSA 2048 bitów, Hasło, Potwierdź hasło, przycisk Zapisz', 'Ustawienia, „Klucze PGP”, formularz tworzenia klucza.'),
            ['type' => 'text', 'html' => '<h2>Przyciski na górze listy kluczy</h2>'
                . '<p>Ołówek tworzy nowy klucz, a kosz usuwa zaznaczony. Lupa szuka klucza innej osoby na publicznym serwerze kluczy, po jej adresie e-mail. '
                . 'Chmurka ze strzałką w dół importuje klucz z pliku, na przykład klucz publiczny, który przysłał Ci rozmówca. '
                . 'Chmurka ze strzałką w górę eksportuje klucze do pliku. Swój klucz publiczny wyeksportuj i wyślij osobom, które mają do Ciebie pisać szyfrowane wiadomości.</p>'
                . '<p>Przy eksporcie można dołączyć też klucz prywatny. Zrób tak tylko po to, żeby zachować kopię zapasową, i trzymaj ten plik w bezpiecznym miejscu. Nikomu go nie wysyłaj.</p>'],
            ['type' => 'text', 'html' => '<h2>Szyfrowanie i podpisywanie wiadomości</h2>'
                . '<p>Gdy masz klucz, w oknie pisania nowej wiadomości, w kolumnie po prawej pod miejscem na załączniki, pojawią się dodatkowe opcje. '
                . 'Przełącznikami włączysz podpisanie wiadomości, jej zaszyfrowanie i dołączenie swojego klucza publicznego. '
                . 'Pierwszej wiadomości do nowej osoby zwykle nie szyfrujesz, tylko dołączasz do niej swój klucz publiczny, żeby odbiorca mógł Ci odpisać szyfrowaną wiadomością. '
                . 'Instrukcja dostawcy ze zrzutem: <a href="' . $kb . 'jak-moge-wyslac-zaszyfrowana-wiadomosc/">jak wysłać zaszyfrowaną wiadomość</a>.</p>'
                . '<p>Możesz podpisać wiadomość cyfrowo, zaszyfrować ją albo dołączyć swój klucz publiczny. '
                . 'Zaszyfrować wiadomość możesz tylko do osoby, której klucz publiczny masz na liście. Domyślne zachowanie ustawisz w Preferencjach, w dziale „Szyfrowanie”, '
                . 'a w „Tożsamościach” zobaczysz, który klucz jest przypisany do Twojego adresu.</p>'
                . '<p>Zaszyfrowaną wiadomość od innej osoby dPoczta odszyfruje po wpisaniu hasła do Twojego klucza.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Jeśli zapomnisz hasła do klucza albo usuniesz klucz bez kopii zapasowej, nie odczytasz już wiadomości zaszyfrowanych tym kluczem. '
                . 'Przed usunięciem klucza wyeksportuj go. Z pytaniami <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], false],

        'folder-wyslane' => ['Folder „Wysłane” w programie pocztowym i w telefonie', 'wysłane, elementy wysłane, folder wysłanych, sent, outlook, thunderbird, iphone, apple mail, mail, kopia wysłanych, nie widzę wysłanych, brak wysłanych w dpoczcie', [
            ['type' => 'text', 'html' => '<p>Gdy wysyłasz wiadomości z programu na komputerze albo z telefonu, program sam decyduje, gdzie zapisze ich kopię. '
                . 'Jeśli zapisze ją tylko na Twoim urządzeniu albo w niewłaściwym folderze, nie zobaczysz wysłanych wiadomości w dPoczcie ani na innych urządzeniach. '
                . 'Dlatego w każdym programie warto sprawdzić, czy kopie wysłanych trafiają do folderu „Wysłane” na serwerze.</p>'],
            ['type' => 'text', 'html' => '<h2>Microsoft Outlook</h2>'
                . '<p>Outlook nie pozwala wskazać innego folderu na wysłane. Sprawdź tylko, czy w ogóle zapisuje kopie: kliknij „Plik”, potem „Opcje” i „Poczta”. '
                . 'W części „Wysyłanie wiadomości” zaznacz „Zapisz kopie wiadomości w folderze Elementy wysłane”.</p>'
                . '<p>Instrukcja dostawcy: <a href="' . $kb . 'definiowanie-folderu-na-wiadomosci-wyslane-w-programie-pocztowym-microsoft-outlook/">folder wysłanych w Microsoft Outlook</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>iPhone i iPad (aplikacja Mail)</h2>'
                . '<p>Otwórz „Ustawienia”, potem „Mail”, „Konta” i swoje konto służbowe. Wybierz „Zaawansowane”, a w części „Postępowanie” ustaw folder na wysłane wiadomości na „Wysłane” na serwerze.</p>'
                . '<p>Instrukcja dostawcy: <a href="' . $kb . 'definiowanie-folderu-na-wiadomosci-wyslane-w-programie-pocztowym-iphone-mail/">folder wysłanych w iPhone Mail</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Mozilla Thunderbird i Mail na Macu</h2>'
                . '<p>W obu programach folder na wysłane wybierasz w ustawieniach konta. Szczegóły opisują instrukcje dostawcy: '
                . '<a href="' . $kb . 'definiowanie-folderu-na-wiadomosci-wyslane-w-programie-pocztowym-mozilla-thunderbird/">folder wysłanych w Thunderbirdzie</a> i '
                . '<a href="' . $kb . 'definiowanie-folderu-na-wiadomosci-wyslane-w-programie-pocztowym-mail-na-komputerach-apple/">folder wysłanych w Mail na komputerach Apple</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Jak w ogóle dodać skrzynkę do programu albo telefonu, opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/poczta-w-telefonie">Poczta w telefonie, Outlooku i Thunderbirdzie</a>. '
                . 'Więcej porad dostawcy znajdziesz w <a href="https://dhosting.pl/pomoc/">pomocy dhosting.pl</a>, na przykład <a href="' . $kb . 'dpoczta-pl-najwazniejsze-informacje/">dPoczta.pl: najważniejsze informacje</a>.</p>'],
        ], false],

        'problemy-z-odbiorem' => ['Wiadomości nie dochodzą: co sprawdzić', 'nie dochodzą, nie przychodzą, nie dostaję wiadomości, brak wiadomości, zginęła wiadomość, opóźnienie, zwrotka, błąd dostarczenia, kod błędu smtp, outlook 0x800ccc0e, 0x800ccc18, 0x8004210a, 0x800c0133, 0x800ccc15, greylist, pełna skrzynka, wyszukiwanie', [
            ['type' => 'text', 'html' => '<p>Ktoś mówi, że wysłał Ci wiadomość, a jej nie ma? Zanim napiszesz do Helpdesku, sprawdź po kolei poniższe miejsca. Zwykle przyczyna jest w jednym z nich.</p>'],
            ['type' => 'wizard', 'title' => 'Lista do sprawdzenia', 'intro' => '', 'steps' => [
                $step('Folder Spam', '<p>Otwórz folder Spam. Jeśli wiadomość tam jest, otwórz ją i oznacz jako „Nie spam”, a nadawcę dodaj do zaufanych w CleanBox.</p>'),
                $step('Filtry', '<p>W ustawieniach otwórz „Filtry”. Sprawdź, czy któryś filtr nie przenosi takich wiadomości do innego folderu albo ich nie usuwa.</p>'),
                $step('Zablokowane adresy', '<p>W „CleanBox”, w „Regułach adresów”, otwórz zakładkę „Zablokowane adresy”. Wiadomości od zablokowanych nadawców nie trafiają nawet do Spamu.</p>'),
                $step('Miejsce w skrzynce', '<p>Spójrz na pasek zajętego miejsca na dole listy folderów. Gdy skrzynka jest pełna, nowe wiadomości są odrzucane. Opróżnij Kosz i Spam.</p>'),
                $step('Wyszukiwarka', '<p>Poszukaj wiadomości po nadawcy w polu „Szukaj…”. Strzałką obok pola wybierz szukanie we wszystkich folderach, a nie tylko w bieżącym.</p>'),
                $step('Opóźnienie od nowego nadawcy', '<p>Pierwsza wiadomość od kogoś, kto nigdy do Ciebie nie pisał, może przyjść z opóźnieniem przez filtr GreyList. Odczekaj chwilę, nawet do kilku godzin.</p>'),
                $step('Zwrotka u nadawcy', '<p>Poproś nadawcę, żeby sprawdził, czy nie dostał wiadomości zwrotnej o niedostarczeniu. Jest w niej kod błędu, który pomoże nam znaleźć przyczynę.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Błędy w programie Outlook</h2>'
                . '<p>Komunikaty z kodami w rodzaju 0x800CCC0E albo 0x8004210A zwykle znaczą, że program nie może połączyć się z serwerem. Najczęściej przyczyną jest złe hasło, '
                . 'zły adres serwera albo port. Porównaj ustawienia z instrukcją <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/poczta-w-telefonie">Poczta w telefonie, Outlooku i Thunderbirdzie</a>. '
                . 'Opisy poszczególnych kodów są w pomocy dostawcy: '
                . '<a href="' . $kb . 'blad-0x800ccc0e-w-aplikacji-outlook/">0x800CCC0E</a>, '
                . '<a href="' . $kb . 'blad-0x800ccc18-w-aplikacji-outlook/">0x800CCC18</a>, '
                . '<a href="' . $kb . 'blad-0x8004210a-w-aplikacji-outlook/">0x8004210A</a>, '
                . '<a href="' . $kb . 'blad-0x800c0133-w-aplikacji-outlook/">0x800C0133</a> i '
                . '<a href="' . $kb . 'blad-0x800ccc15-w-aplikacji-outlook/">0x800CCC15</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Więcej w pomocy dostawcy</h2><ul>'
                . '<li><a href="' . $kb . 'mozliwe-problemy-z-odbiorem-wiadomosci-e-mail/">Możliwe problemy z odbiorem wiadomości e-mail</a></li>'
                . '<li><a href="' . $kb . 'wiadomosci-nagle-przestaly-do-mnie-docierac/">Wiadomości nagle przestały do mnie docierać</a></li>'
                . '<li><a href="' . $kb . 'mam-problem-z-wyszukiwaniem-wiadomosci/">Mam problem z wyszukiwaniem wiadomości</a></li>'
                . '<li><a href="' . $kb . 'jak-dziala-greylisting-ochrona-antyspamowa/">Jak działa greylisting</a></li>'
                . '<li><a href="' . $kb . 'liczbowe-kody-bledow-smtp/">Kody błędów SMTP z wyjaśnieniem</a></li>'
                . '<li><a href="https://dhosting.pl/pomoc/kategoria/poczta/problemy-z-odbiorem-wiadomosci/">wszystkie artykuły o problemach z odbiorem</a></li></ul>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nic nie pomogło? <a href="' . $hd . '">Napisz do Helpdesku IT</a>. Podaj adres nadawcy, przybliżoną datę i godzinę wysłania i, jeśli masz, treść zwrotki z kodem błędu.</p>'],
        ], false],

        'wiadomosci' => ['Wiadomości w dPoczcie: pisanie, odpowiadanie, załączniki', 'wiadomość, nowa wiadomość, pisanie, utwórz, wyślij, odpowiedz, odpowiedz wszystkim, przekaż, załącznik, dołącz plik, do, dw, udw, kopia, ukryta kopia, temat, kopia robocza, priorytet, potwierdzenie odbioru, etykiety, oznacz, przeczytane, przenieś, archiwizuj, usuń, kosz, drukuj, szukaj', [
            ['type' => 'text', 'html' => '<p>Tu opisujemy codzienną pracę z wiadomościami w dPoczcie: czytanie, pisanie, odpowiadanie, załączniki i porządkowanie. '
                . 'Co jest gdzie na ekranie, pokazuje artykuł <a href="' . $base . 'dpoczta-co-jest-co">dPoczta.pl: co jest co</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Czytanie</h2>'
                . '<p>Kliknij wiadomość na liście, a jej treść pojawi się po prawej. Dwukrotne kliknięcie otwiera ją na całym ekranie. Pogrubione wiadomości są nieprzeczytane. '
                . 'Załączniki są pod nagłówkiem wiadomości. Kliknij załącznik, żeby go podejrzeć albo pobrać.</p>'
                . '<p>Obrazki z obcych serwerów są domyślnie zablokowane. Jeśli ufasz nadawcy, pozwól na nie przyciskiem nad treścią wiadomości.</p>'],
            ['type' => 'wizard', 'title' => 'Nowa wiadomość', 'intro' => '', 'steps' => [
                $step('Kliknij Utwórz', '<p>Kliknij duży przycisk „Utwórz” na górze listy folderów. Otworzy się okno nowej wiadomości ze stopką na dole.</p>'),
                $step('Adresaci', '<p>W polu „Do” wpisz adres albo pierwsze litery nazwiska. Poczta podpowie adresy z kontaktów. Ikona osób obok otwiera książkę adresową, '
                    . 'a plus dodaje pola „Dw” (kopia), „Udw” (ukryta kopia) i „Odpowiedź do”. Gdy piszesz do wielu osób spoza Fundacji, wpisz je w „Udw”, żeby nie widziały nawzajem swoich adresów.</p>'),
                $step('Temat i treść', '<p>Wpisz „Temat” i treść. Pasek nad treścią formatuje tekst: pogrubienie, listy, wyrównanie, krój i wielkość liter, kolor, linki. '
                    . 'Pole „Od” pozwala wybrać inną tożsamość, na przykład adres funkcyjny.</p>'),
                $step('Załączniki', '<p>Plik dodasz przyciskiem „Dołącz plik” w kolumnie „Opcje i załączniki” po prawej albo przeciągając go myszą do ramki ze strzałką. '
                    . 'Poczta przyjmie plik do 2 GB, ale serwer odbiorcy zwykle przyjmuje dużo mniej, więc większe pliki wysyłaj linkiem, jak opisuje artykuł '
                    . '<a href="' . $base . 'duze-zalaczniki">Duże załączniki</a>.</p>'),
                $step('Wyślij', '<p>Kliknij „Wyślij” pod treścią. Kopia trafi do folderu „Wysłane”. Pisaną wiadomość poczta co minutę zapisuje w „Kopiach roboczych”, '
                    . 'więc możesz do niej wrócić. Ikona dyskietki na górze zapisuje ją od razu.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-nowa-wiadomosc.jpg', 'Okno nowej wiadomości w dPoczcie: pola Od, Do, Temat, edytor ze stopką Fundacji, przyciski Wyślij i Otwórz w nowym oknie; po prawej Opcje i załączniki: Dołącz plik do 2 GB, przełączniki Podpisz, Szyfruj, Załącz klucz publiczny, Potwierdzenie odbioru, Status dostarczenia, Zachowaj formatowanie, Priorytet, Zapisz wiadomość w', 'Okno nowej wiadomości ze stopką i kolumną „Opcje i załączniki”.'),
            ['type' => 'text', 'html' => '<h2>Opcje i przyciski w oknie pisania</h2>'
                . '<p>Na górze okna są cztery ikony: dyskietka zapisuje kopię roboczą, spinacz dodaje załącznik, pióro wstawia podpis, a zakładka wstawia gotową odpowiedź z „Odpowiedzi”.</p>'
                . '<p>W kolumnie „Opcje i załączniki” są przełączniki. „Potwierdzenie odbioru” prosi odbiorcę o informację, że przeczytał wiadomość. Używaj go rzadko. '
                . '„Priorytet” oznacza wiadomość jako pilną. „Zapisz wiadomość w” wskazuje folder na kopię wysłanej wiadomości, zwykle „Wysłane”. '
                . 'Przełączniki „Podpisz wiadomość”, „Szyfruj wiadomość” i „Załącz mój klucz publiczny” dotyczą PGP, z którego korzysta Zarząd.</p>'
                . '<p>„Otwórz w nowym oknie” przenosi pisanie do osobnego okna przeglądarki, żeby w tym czasie przeglądać pocztę.</p>'],
            ['type' => 'text', 'html' => '<h2>Odpowiadanie i przekazywanie</h2>'
                . '<p>Nad otwartą wiadomością kliknij „Odpowiedz”, żeby odpisać nadawcy, albo „Odpowiedz wszystkim”, żeby odpisać nadawcy i wszystkim adresatom. '
                . 'Zastanów się, czy wszyscy naprawdę potrzebują Twojej odpowiedzi. „Przekaż” wysyła wiadomość dalej. Strzałka obok pozwala przekazać ją jako załącznik, z oryginalnymi nagłówkami.</p>'],
            ['type' => 'text', 'html' => '<h2>Porządkowanie</h2>'
                . '<p>Wiadomość przeniesiesz do folderu, przeciągając ją myszą na folder po lewej. „Archiwizuj” odkłada ją do Archiwum, a „Usuń” do Kosza, skąd jeszcze ją odzyskasz. '
                . '„Oznacz jako spam”, ikona płomienia, przenosi ją do Spamu. Ikona metki dodaje etykietę, na przykład „Ważne”. '
                . 'Trzy kropki mają resztę działań: oznaczenie jako nieprzeczytane, drukowanie, pobranie, przeniesienie, kopiowanie i utworzenie filtra.</p>'
                . '<p>Kilka wiadomości zaznaczysz, przytrzymując Ctrl (na Macu Cmd) i klikając je na liście. Działania wykonasz wtedy na wszystkich naraz.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Zanim klikniesz link albo otworzysz załącznik, sprawdź nadawcę. Fałszywe wiadomości często udają bank, kuriera albo Fundację i proszą o hasło lub przelew. '
                . 'Podejrzaną wiadomość zgłoś w <a href="' . $hd . '">Helpdesku IT</a>, dołączając jej źródło, jak opisuje artykuł „co jest co”.</p>'],
        ], false],

        'generator-stopki' => ['Obowiązkowa stopka: jak ją wygenerować', 'stopka, podpis, generator stopki, obowiązkowa stopka, wzór stopki, dane organizacji, nip, krs, social, facebook, instagram, linkedin', [
            ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Każda wiadomość ze służbowej skrzynki musi mieć stopkę z generatora Fundacji. Stopka jest elementem tożsamości Fundacji, więc korzystanie z generatora jest obowiązkowe i nie zmieniamy stopki ręcznie: wszyscy mamy ten sam wzór i aktualne dane Fundacji.</p>'],
            ['type' => 'text', 'html' => '<p>Generator działa pod adresem <a href="' . $app . '/pomocne/generatorstopki">' . $app . '/pomocne/generatorstopki</a> i nie wymaga logowania. Wpisane dane zostają w Twojej przeglądarce.</p>'],
            ['type' => 'wizard', 'title' => 'Stopka w pięciu krokach', 'intro' => '', 'steps' => [
                $step('Otwórz generator', '<p>Wejdź na <a href="' . $app . '/pomocne/generatorstopki">generator stopki</a>.</p>'),
                $step('Wpisz swoje dane', '<p>Podaj imię, nazwisko, stanowisko, telefon i adres e-mail. Dane Fundacji (nazwa, adres, NIP, KRS, strona WWW i linki do Facebooka, Instagrama i LinkedIna) są już w stopce.</p>'),
                $step('Sprawdź podgląd', '<p>Po prawej stronie widzisz gotową stopkę. Sprawdź imię, nazwisko i numer telefonu.</p>'),
                $step('Skopiuj stopkę', '<p>Kliknij „Kopiuj stopkę do schowka”. Gdy przycisk zmieni się na „Skopiowano”, stopka jest w schowku. Jeśli przeglądarka zablokuje schowek, zaznacz podgląd myszą i naciśnij Ctrl+C.</p>'),
                $step('Wklej ją w poczcie', '<p>Wklej stopkę (Ctrl+V) w ustawieniach podpisu. W dPoczta.pl robisz to w „Tożsamości”, krok po kroku opisuje to artykuł „<a href="' . $app . '/uslugi-it/uslugi/moja-poczta/podpis-w-poczcie">Jak ustawić podpis (stopkę) w poczcie</a>”. Wskazówki dla Outlooka i Thunderbirda są pod generatorem.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Gdy zmieni się telefon albo stanowisko, wygeneruj stopkę jeszcze raz i podmień ją w poczcie. Jeśli coś źle się wyświetla, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], true],

        'podpis-w-poczcie' => ['Jak ustawić podpis (stopkę) w poczcie', 'podpis, stopka, sygnatura, tożsamość, generator stopki, obowiązkowa stopka, wklej stopkę, html', [
            ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Stopka musi pochodzić z generatora Fundacji: <a href="' . $app . '/pomocne/generatorstopki">' . $app . '/pomocne/generatorstopki</a>. Jak go używać, opisuje artykuł „<a href="' . $app . '/uslugi-it/uslugi/moja-poczta/generator-stopki">Obowiązkowa stopka: jak ją wygenerować</a>”. Tu pokazujemy, gdzie ją wkleić w poczcie.</p>'],
            ['type' => 'wizard', 'title' => 'Stopka z generatora w dPoczta.pl', 'intro' => '', 'steps' => [
                $step('Skopiuj stopkę z generatora', '<p>Otwórz <a href="' . $app . '/pomocne/generatorstopki">generator</a>, wpisz swoje dane i kliknij „Kopiuj stopkę do schowka”.</p>'),
                $step('Otwórz tożsamość', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' W lewej kolumnie wybierz „Tożsamości” i kliknij swój adres w środkowej kolumnie.</p>'),
                $step('Włącz podpis w HTML i wklej stopkę', '<p>W części „Podpis” suwak „Włącz Projektanta Podpisu” zostaw wyłączony. Włącz suwak „Podpis w HTML” pod polem podpisu, '
                    . 'kliknij w pole i wklej stopkę (Ctrl+V). Jeśli w polu była stara stopka, najpierw ją zaznacz i usuń.</p>'),
                $step('Zapisz i sprawdź', '<p>Kliknij „Zapisz” na dole strony. Napisz wiadomość do siebie i zobacz, czy stopka wygląda tak jak w podglądzie generatora.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-tozsamosc-podpis.jpg', 'Tożsamości w dPoczcie: pola Nazwa wyświetlana, E-Mail, Organizacja, Odpowiedź do, Ukryta kopia, Domyślna, część Podpis ze stopką Fundacji w edytorze', 'Ustawienia, „Tożsamości”: stopka wklejona w pole podpisu.'),
            ['type' => 'wizard', 'title' => 'Stopka z generatora w Thunderbirdzie', 'intro' => '', 'steps' => [
                $step('Skopiuj kod HTML', '<p>W <a href="' . $app . '/pomocne/generatorstopki">generatorze</a> wpisz swoje dane i kliknij „Kopiuj kod HTML”. Przycisk „Kopiuj stopkę do schowka” nie nadaje się do Thunderbirda, bo wkleiłby zwykły tekst bez układu.</p>'),
                $step('Otwórz ustawienia konta', '<p>Uruchom Thunderbirda. W lewej kolumnie kliknij prawym przyciskiem myszy nazwę swojej skrzynki i wybierz „Ustawienia”.</p>'),
                $step('Wklej kod w polu „Sygnaturka”', '<p>Zaznacz opcję „Używaj HTML”, potem wklej kod w pole „Sygnaturka” (Ctrl+V). Ustawienia zapisują się same.</p>'),
                $step('Sprawdź wiadomością próbną', '<p>Napisz wiadomość do siebie i zobacz, czy stopka wygląda tak jak w podglądzie generatora.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Żeby stopka wstawiała się sama</h2>'
                . '<p>W ustawieniach wybierz „Preferencje”, potem „Tworzenie wiadomości”. W „Opcjach głównych” ustaw „Twórz wiadomości HTML” na „zawsze”, '
                . 'a w „Opcjach podpisów” ustaw „Automatycznie wstaw podpis” na „zawsze”. Kliknij „Zapisz”. Pozostałe opcje opisuje artykuł '
                . '<a href="' . $app . '/uslugi-it/uslugi/moja-poczta/ustawienia-poczty">Ważne i przydatne ustawienia poczty</a>.</p>'],
            ...itPortalDpocztaShot('dp-tworzenie-wiadomosci.jpg', 'Ustawienia dPoczty, Preferencje, Tworzenie wiadomości: Twórz wiadomości HTML zawsze, Automatycznie wstaw podpis zawsze',
                'Preferencje, „Tworzenie wiadomości”: tu włączasz automatyczne wstawianie stopki.'),
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Zamiast wklejać kod, możesz kliknąć w generatorze „Pobierz plik HTML” i w ustawieniach konta wskazać ten plik jako sygnaturkę. Masz kilka skrzynek? W „Zarządzaj tożsamościami” wybierz „Edytuj” albo „Dodaj” i ustaw stopkę przy każdej tożsamości.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>W Outlooku wklej stopkę z przycisku „Kopiuj stopkę do schowka” w oknie edycji podpisu (Plik → Opcje → Poczta → Podpisy).</p>'],
            ['type' => 'text', 'html' => '<p>W „Tożsamościach” nad podpisem są też inne pola. „Nazwa wyświetlana” to nazwa nadawcy, którą widzą odbiorcy, zwykle imię i nazwisko. '
                . '„Organizacja” możesz zostawić pustą. „Odpowiedź do” wpisz tylko wtedy, gdy odpowiedzi mają trafiać na inny adres. „Ukryta kopia” wysyła kopię każdej Twojej wiadomości na podany adres, '
                . 'więc zwykle zostaw ją pustą. „Domyślna” oznacza tożsamość wybieraną przy pisaniu. Część „Szyfrowanie” z kluczami PGP nie jest potrzebna na co dzień.</p>'],
            ['type' => 'text', 'html' => '<p>W „Tożsamościach” zmienisz też nazwę nadawcy, którą widzą odbiorcy. Jeśli masz dostęp do innej skrzynki, np. działu, dodasz ją przyciskiem „Nowa tożsamość” (ikona ołówka) i wybierzesz przy pisaniu wiadomości.</p>'],
        ], false],

        'autoresponder' => ['Autoodpowiedź na czas nieobecności', 'autoresponder, autoodpowiedź, czym jest autoodpowiedź, urlop, nieobecność, poza biurem, automatyczna odpowiedź, zwolnienie, przykładowa treść, od kiedy, do kiedy', [
            ['type' => 'text', 'html' => '<p>Autoodpowiedź, w poczcie nazwana „Autoresponder”, to wiadomość, którą skrzynka sama wysyła każdemu, kto do Ciebie napisze, '
                . 'gdy jesteś na urlopie, zwolnieniu albo poza biurem. Nadawca od razu wie, że nie odpiszesz szybko i do kogo zwrócić się w pilnej sprawie. '
                . 'Ty nie musisz nic robić: wystarczy raz ustawić daty i treść.</p>'
                . '<p>Wiadomości nadal przychodzą do Twojej skrzynki jak zwykle. Autoodpowiedź niczego nie przekazuje dalej. Jeśli ktoś ma odbierać Twoją pocztę pod Twoją nieobecność, '
                . 'ustaw przekierowanie, jak opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/filtry-i-przekierowanie">Filtry i przekierowanie poczty</a>.</p>'],
            ['type' => 'wizard', 'title' => 'Ustawienie autoodpowiedzi', 'intro' => '', 'steps' => [
                $step('Otwórz ustawienia', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' Wybierz „Autoresponder”.</p>'),
                $step('Ustaw daty i treść', '<p>W „Ustawieniach Autorespondera” zaznacz pole „Włączona”. W polach „Od kiedy” i „Do kiedy” ustaw początek i koniec nieobecności, z godziną. '
                    . 'W polu „Temat” zostaw „Automatyczna odpowiedź” albo wpisz własny. W polu „Treść” napisz, do kiedy Cię nie ma i z kim się kontaktować w pilnych sprawach. Kliknij „Zapisz”.</p>'),
                $step('Wyłącz po powrocie', '<p>Po dacie z pola „Do kiedy” odpowiedź wyłączy się sama. Jeśli wrócisz wcześniej, odznacz pole „Włączona” i kliknij „Zapisz”.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-autoresponder.jpg', 'Ustawienia Autorespondera w dPoczcie: pole Włączona, Od kiedy, Do kiedy, Temat Automatyczna odpowiedź, edytor treści i przycisk Zapisz', 'Ustawienia, „Autoresponder”.'),
            ['type' => 'text', 'html' => '<h2>Przykładowa treść</h2>'
                . '<p>Dzień dobry, dziękuję za wiadomość. Do 28 listopada jestem na urlopie i nie czytam poczty. Odpowiem po powrocie. '
                . 'W pilnych sprawach proszę pisać do [imię i nazwisko osoby zastępującej, jej adres e-mail]. Pozdrawiam, [Twoje imię i nazwisko], Fundacja Edukacji Empatii Rozwoju FEER</p>'
                . '<p>Podmień datę i to, co jest w nawiasach kwadratowych, na swoje dane. Osobę zastępującą uprzedź wcześniej. Nie podawaj w autoodpowiedzi prywatnego numeru telefonu, bo dostanie ją każdy, także nadawca spamu.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie widzisz „Autorespondera” w ustawieniach? <a href="' . $hd . '">Napisz w Helpdesku</a>, włączymy go z panelu administratora.</p>'],
        ], false],

        'filtry-i-przekierowanie' => ['Filtry i przekierowanie poczty', 'filtr, filtry, reguły, reguła, przekierowanie, przekazywanie, forward, kopia, folder, sortowanie, automatyczne przenoszenie, moje filtry, zakres, akcje, nazwa filtru, filtr włączony, faktury, newsletter', [
            ['type' => 'text', 'html' => '<p>Filtr sam robi coś z wiadomościami, które spełniają podane warunki, zaraz po tym, jak przyjdą. Najczęściej przenosi je do wybranego folderu, '
                . 'na przykład faktury do folderu FAKTURA, a newslettery do „Mało istotne”. Może też wysłać kopię na inny adres albo oznaczyć wiadomość. '
                . 'Filtry działają na serwerze, więc sortują pocztę także wtedy, gdy dPoczta jest zamknięta, a porządek zobaczysz w telefonie i w każdym programie.</p>'],
            ['type' => 'text', 'html' => '<h2>Gdzie są filtry</h2>'
                . '<p>' . $gear . ' W lewej kolumnie wybierz „Filtry”. W środku jest zestaw „Moje filtry dpoczta.pl” z listą Twoich filtrów. Na początku lista jest pusta. '
                . 'Ołówek na górze („Utwórz”) dodaje nowy filtr, a kosz usuwa zaznaczony. Koło zębate obok nazwy „Ustawienia” służy do zarządzania zestawami filtrów i nie jest potrzebne na co dzień.</p>'],
            ['type' => 'wizard', 'title' => 'Nowy filtr krok po kroku', 'intro' => '<p>Przykład: wiadomości od Fakturowni mają same trafiać do folderu FAKTURA.</p>', 'steps' => [
                $step('Kliknij Utwórz', '<p>W „Filtrach” kliknij ikonę ołówka na górze. Po prawej pojawi się formularz.</p>'),
                $step('Nazwij filtr', '<p>W polu „Nazwa filtru” wpisz nazwę, po której go poznasz, na przykład „Faktury”. Przełącznik „Filtr włączony” zostaw włączony.</p>'),
                $step('Wybierz zakres', '<p>W polu „Zakres” wybierz „spełniających wszystkie poniższe kryteria”, gdy muszą być spełnione wszystkie reguły, '
                    . 'albo „spełniających dowolne z poniższych kryteriów”, gdy wystarczy jedna. Opcja „wszystkich” obejmuje każdą wiadomość bez wyjątku.</p>'),
                $step('Ustaw regułę', '<p>W części „Reguły” wybierz, co sprawdzać, na przykład „Od” (nadawca), „Temat”, „Do” albo „Treść”. Obok wybierz warunek, najczęściej „zawiera”, '
                    . 'i wpisz szukany tekst, na przykład <code>fakturownia.pl</code>. Plus po prawej dodaje kolejną regułę, a kosz ją usuwa.</p>'),
                $step('Ustaw akcję', '<p>W części „Akcje” zostaw „Przenieś wiadomość do” i wybierz folder, na przykład FAKTURA. Plus po prawej dodaje kolejną akcję.</p>'),
                $step('Zapisz', '<p>Kliknij „Zapisz”. Filtr pojawi się na liście i zadziała dla nowych wiadomości. Wiadomości, które już są w skrzynce, przenieś ręcznie.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-filtr-nowy.jpg', 'Formularz nowego filtra w dPoczcie: Nazwa filtru, Filtr włączony, Zakres spełniających wszystkie poniższe kryteria, Reguły Temat zawiera, Akcje Przenieś wiadomość do Odebrane, przycisk Zapisz', 'Ustawienia, „Filtry”, formularz nowego filtra.'),
            ['type' => 'text', 'html' => '<h2>Najprzydatniejsze akcje</h2><ul>'
                . '<li>„Przenieś wiadomość do”: wiadomość trafia od razu do wybranego folderu, a nie do Odebranych.</li>'
                . '<li>„Skopiuj wiadomość do”: wiadomość zostaje w Odebranych, a jej kopia trafia do folderu.</li>'
                . '<li>„Wyślij kopię do”: kopia idzie na inny adres, a oryginał zostaje u Ciebie. Tak przekażesz pocztę zastępcy na czas urlopu.</li>'
                . '<li>„Przekaż wiadomość na konto”: wiadomość idzie na inny adres i nie zostaje u Ciebie.</li>'
                . '<li>„Ustaw flagi wiadomości”: na przykład od razu oznacza wiadomość jako przeczytaną albo ważną.</li>'
                . '<li>„Przerwij przetwarzanie reguł”: kolejne filtry nie zadziałają już dla tej wiadomości. Przydaje się, gdy masz kilka filtrów, które mogłyby się nakładać.</li></ul>'
                . '<p>Na liście jest też „Usuń wiadomość”, „Odrzuć z komunikatem” i „Odpowiedz wiadomością o treści”. Używaj ich ostrożnie, bo łatwo stracić ważną wiadomość. '
                . 'Na czas urlopu wygodniejsza jest autoodpowiedź, opisana w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/autoresponder">Autoodpowiedź na czas nieobecności</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Zmiana i wyłączanie filtra</h2>'
                . '<p>Kliknij filtr na liście, popraw go po prawej i kliknij „Zapisz”. Żeby filtr na chwilę nie działał, wyłącz przełącznik „Filtr włączony” i zapisz. '
                . 'Filtry działają po kolei, od góry listy.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Filtr szybciej zrobisz z otwartej wiadomości. Zaznacz ją na liście, kliknij trzy kropki „Więcej” nad wiadomością i wybierz „Utwórz filtr”. '
                . 'Formularz wypełni się danymi z tej wiadomości, na przykład nadawcą.</p>'
                . '<p>Instrukcja dostawcy ze zrzutami: <a href="' . $kb . 'jak-korzystac-z-filtrowania-wiadomosci-w-dpoczta-pl/">jak korzystać z filtrowania wiadomości w dPoczcie</a>.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie przekierowuj poczty służbowej na prywatne skrzynki (Gmail, Onet, WP itp.). W wiadomościach bywają dane osobowe, które muszą zostać w systemach organizacji.</p>'],
        ], false],

        'spam-i-zaufani-nadawcy' => ['Spam, zablokowani i zaufani nadawcy', 'spam, antyspam, cleanbox, greylist, greylisting, opóźnienie, wiadomość przychodzi z opóźnieniem, łagodny filtr, więcej spamu niż w outlooku, filtr antyspamowy, czułość filtra, reguły adresów, zaufane adresy, zablokowane adresy, blokowanie, czarna lista, biała lista, zaufani nadawcy, *@feer.org.pl, obrazki, wirusy', [
            ['type' => 'text', 'html' => '<p>Każda skrzynka ma włączone filtry antyspamowe, a poczta jest sprawdzana pod kątem wirusów. Możesz sam zablokować natrętnego nadawcę albo oznaczyć zaufanego.</p>'],
            ['type' => 'wizard', 'title' => 'Blokowanie i zaufane adresy', 'intro' => '', 'steps' => [
                $step('Otwórz CleanBox', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' Na dole lewej kolumny wybierz „CleanBox”. '
                    . 'W środkowej kolumnie są dwie części: „Filtr antyspamowy” i „Reguły adresów”.</p>'),
                $step('Ustaw czułość filtra', '<p>W „Filtrze antyspamowym” zostaw „Czułość filtra” na „Średnia (zalecana)”. Wyższa czułość zatrzyma więcej spamu, '
                    . 'ale częściej wrzuci do Spamu zwykłą wiadomość. Niższa przepuści więcej, ale rzadziej się pomyli. Po zmianie kliknij „Zapisz”.</p>'),
                $step('Dodaj zaufanych nadawców', '<p>Wybierz „Reguły adresów” i zakładkę „Zaufane adresy”, czyli białą listę. Wiadomości od tych nadawców nie trafią do Spamu. '
                    . 'Wpisz adres albo wzorzec i kliknij „Dodaj regułę”. Gwiazdka zastępuje dowolny ciąg znaków, więc <code>*@feer.org.pl</code> obejmuje wszystkie adresy Fundacji. '
                    . 'Polecamy dodać właśnie ten wzorzec.</p>'),
                $step('Zablokuj natrętnego nadawcę', '<p>W zakładce „Zablokowane adresy”, czyli na czarnej liście, wpisz adres albo wzorzec, na przykład <code>*@spam.pl</code>, i kliknij „Dodaj regułę”. '
                    . 'Wiadomości od tego nadawcy będą odrzucane, zanim dotrą do skrzynki, więc nie znajdziesz ich nawet w Spamie.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-cleanbox-filtr.jpg', 'CleanBox, Filtr antyspamowy: Czułość filtra Średnia (zalecana) i przycisk Zapisz', 'CleanBox, „Filtr antyspamowy”.'),
            ...itPortalDpocztaShot('dp-cleanbox-reguly.jpg', 'CleanBox, Reguły adresów, zakładka Zaufane adresy: pole Email lub wzorzec, przycisk Dodaj regułę, Reguły globalne, Twoje reguły z wpisem *@feer.org.pl', 'CleanBox, „Reguły adresów”, zakładka „Zaufane adresy” z regułą *@feer.org.pl.'),
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Filtr antyspamowy dPoczty jest łagodniejszy niż ten w Outlooku z Microsoft 365. Do Odebranych może więc trafić więcej reklam i spamu niż wcześniej. '
                . 'Oznaczaj takie wiadomości ikoną płomienia „Oznacz jako spam”, a natrętnych nadawców dodaj do zablokowanych. Jeśli spamu jest bardzo dużo, podnieś „Czułość filtra” o jeden poziom '
                . 'i przez kilka dni zaglądaj do folderu Spam, czy nie trafia tam zwykła poczta.</p>'],
            ['type' => 'text', 'html' => '<h3>Opóźnione wiadomości i GreyList</h3>'
                . '<p>Serwer pocztowy może korzystać z filtra GreyList. Pierwszą wiadomość od nowego nadawcy na chwilę odrzuca i przyjmuje ją dopiero wtedy, gdy serwer nadawcy spróbuje wysłać ją ponownie. '
                . 'Spamerzy zwykle nie próbują drugi raz, więc ten filtr zatrzymuje dużo spamu. Skutek uboczny jest taki, że wiadomość od nowej osoby może dotrzeć z opóźnieniem, od kilkunastu minut do kilku godzin. '
                . 'Jeśli czekasz na ważną wiadomość od kogoś, kto pisze do Ciebie pierwszy raz, poproś go o wysłanie jej z wyprzedzeniem. '
                . 'Opis dostawcy: <a href="https://dhosting.pl/pomoc/kategoria/poczta/filtry-antyspamowe/">filtry antyspamowe w dhosting.pl</a>.</p>'],
            ['type' => 'text', 'html' => '<p>Pod polem dodawania są dwie listy. „Reguły globalne (tylko do odczytu)” ustawia administrator dla wszystkich skrzynek i nie możesz ich zmienić. '
                . '„Twoje reguły” to adresy dodane przez Ciebie. Krzyżyk przy regule ją usuwa. Znak zapytania przy nagłówku pokazuje krótką podpowiedź.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Blokuj ostrożnie, zwłaszcza całe domeny. Wpis <code>*@gmail.com</code> na czarnej liście odrzuci wiadomości od wszystkich osób z Gmaila, a nadawca nie zawsze dowie się, że wiadomość nie dotarła.</p>'],
            ['type' => 'text', 'html' => '<h3>Obrazki w wiadomościach</h3><p>Poczta domyślnie może blokować obrazki z obcych serwerów, bo służą do śledzenia i oszustw. W otwartej wiadomości kliknij „Zezwól”, żeby pokazać je raz, albo „Zawsze zezwalaj od…”, żeby dodać nadawcę do zaufanych. Ustawienie zmienisz w „Ustawienia” → „Preferencje” → „Wyświetlanie wiadomości”. Polecamy opcję „od zaufanych nadawców”.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Dostałeś podejrzaną wiadomość z prośbą o hasło, kod albo przelew? Nie klikaj linków i <a href="' . $hd . '">zgłoś ją w Helpdesku</a>.</p>'],
        ], false],

        'kontakty' => ['Kontakty w dPoczcie', 'kontakty, książka adresowa, kontakty osobiste, zebrani adresaci, zaufani nadawcy, automatycznie zbierane, nowy kontakt, grupy, wizytówka, szukaj kontaktu, adres e-mail, telefon', [
            ['type' => 'text', 'html' => '<p>Kontakty otworzysz ikoną osoby na prawym pasku dPoczty. Ekran ma trzy kolumny: po lewej książki adresowe i grupy, w środku listę kontaktów z polem „Szukaj…”, '
                . 'a po prawej dane wybranego kontaktu.</p>'],
            ...itPortalDpocztaShot('dp-kontakty.jpg', 'Kontakty w dPoczcie: po lewej książki adresowe Kontakty osobiste, Zebrani adresaci, Zaufani nadawcy, Automatycznie zbierane; w środku zamazana lista kontaktów z polem Szukaj; na górze przyciski Utwórz, drukuj, usuń, szukaj, import, eksport', 'Kontakty. Lista jest celowo zamazana.'),
            ['type' => 'text', 'html' => '<h2>Książki adresowe po lewej</h2>'
                . '<p>„Kontakty osobiste” to kontakty, które sam dodajesz. „Zebrani adresaci” to adresy osób, do których pisałeś. Poczta zapisuje je sama, '
                . 'żeby przy pisaniu podpowiadać adres po pierwszych literach. „Zaufani nadawcy” to osoby, którym pozwoliłeś na przykład wyświetlać obrazki w wiadomościach. '
                . '„Automatycznie zbierane” to adresy zapisane przez pocztę wcześniej. Nad listą jest nagłówek „Grupy” z trzema kropkami, którymi dodasz grupę, na przykład „Zespół”, i wyślesz wiadomość do wszystkich jej członków naraz.</p>'],
            ['type' => 'wizard', 'title' => 'Nowy kontakt', 'intro' => '', 'steps' => [
                $step('Kliknij Utwórz', '<p>Wybierz po lewej „Kontakty osobiste” i kliknij ikonę ołówka „Utwórz” na górze.</p>'),
                $step('Wpisz dane', '<p>Wpisz imię i nazwisko, a w razie potrzeby organizację i zawód. Na karcie „Właściwości” dodaj e-mail, telefon i adres. '
                    . 'Na kartach „Osobiste” i „Notatki” są dodatkowe pola. „Dodaj pole…” dokłada kolejne, na przykład drugi telefon.</p>'),
                $step('Zapisz', '<p>Kliknij „Zapisz” na dole formularza.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Przyciski na górze</h2>'
                . '<p>Ołówek dodaje kontakt, drukarka drukuje wybrany, a kosz go usuwa. Lupa otwiera szukanie zaawansowane, na przykład po firmie. '
                . 'Chmurki służą do importu i eksportu kontaktów, opisanych w osobnej instrukcji dla Koordynatorów IT.</p>'
                . '<p>Kontakt dodasz też prosto z wiadomości: kliknij nazwę nadawcy w otwartej wiadomości i wybierz dodanie do książki adresowej.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>W kontaktach zapisuj tylko dane potrzebne do pracy. Nie wpisuj w notatkach danych wrażliwych, na przykład o zdrowiu. '
                . 'Prywatnych kontaktów nie trzymaj w skrzynce służbowej.</p>'],
        ], false],

        'kontakty-import-eksport' => ['Kontakty: import i eksport (dla Koordynatorów IT)', 'kontakty, książka adresowa, import, eksport, vcard, csv, outlook, koordynator it', [
            ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Ta instrukcja jest dla Koordynatorów IT. Jeśli nie jesteś Koordynatorem IT, nie importuj ani nie eksportuj kontaktów. '
                . 'Gdy potrzebujesz przenieść kontakty, poproś o to swojego Koordynatora IT albo <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
            ['type' => 'text', 'html' => '<p>Kontakty przeniesiesz z innego programu plikiem vCard (.vcf) albo CSV.</p>'],
            ['type' => 'wizard', 'title' => 'Import kontaktów', 'intro' => '', 'steps' => [
                $step('Przygotuj plik', '<p>Wyeksportuj kontakty ze starego programu do pliku vCard albo CSV. W pliku CSV pola rozdziela przecinek, a pierwszy wiersz to nagłówek, np. <code>First Name,Last Name,E-mail Address</code>.</p>'),
                $step('Otwórz Kontakty', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a> i przejdź do „Kontakty”.</p>'),
                $step('Zaimportuj', '<p>W górnym menu kliknij ikonę chmurki do importu (podpowiedź pojawi się po najechaniu kursorem) i wskaż plik.</p>'),
            ]],
            ['type' => 'text', 'html' => '<p>Eksport działa tak samo: w „Kontaktach” kliknij drugą ikonę chmurki, do eksportu.</p>'
                . '<p>Instrukcja dostawcy ze zrzutem i przykładowym plikiem CSV: <a href="' . $kb . 'importowanie-i-eksportowanie-kontaktow-w-dpoczta-pl/">importowanie i eksportowanie kontaktów w dPoczcie</a>.</p>'],
        ], false],

        'kalendarz' => ['Kalendarz: spotkania, udostępnianie, telefon', 'kalendarz, spotkanie, wydarzenie, zaproszenie, uczestnicy, cykl, powiadomienia, przypomnienie, udostępnianie, opublikuj, wolny zajęty, caldav, synchronizacja, telefon, import, eksport, ics, cykliczne', [
            ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Kalendarz w dPoczcie zastępuje kalendarz z Outlooka w Microsoft 365. Nowe spotkania i wydarzenia zapisuj już tutaj. '
                . 'Stare wydarzenia z Outlooka przeniesiesz plikiem, jak opisuje ramka na dole.</p>'],
            ['type' => 'text', 'html' => '<p>Kalendarz otworzysz ikoną kalendarza na prawym pasku dPoczty. Na górze są przyciski: „Stwórz” (nowe wydarzenie), widoki „Dzień”, „Tydzień”, „Miesiąc” i „Agenda” '
                . '(lista najbliższych wydarzeń), „Szukaj” i „Ustawienia”. Po lewej jest mały kalendarz miesiąca do szybkiego przechodzenia między dniami, a pod nim lista Twoich kalendarzy. '
                . 'Na nowym koncie jest jeden kalendarz o nazwie „Nowy kalendarz”. Ołówkiem obok zmienisz jego nazwę na przykład na „Służbowy”.</p>'],
            ...itPortalDpocztaShot('dp-kalendarz.jpg', 'Kalendarz w dPoczcie, widok tygodnia: na górze przyciski Stwórz, Dzień, Tydzień, Miesiąc, Agenda, Szukaj, Ustawienia; po lewej kalendarz miesiąca i lista Kalendarze z pozycją Nowy kalendarz', 'Kalendarz, widok tygodnia.'),
            ['type' => 'wizard', 'title' => 'Nowe wydarzenie albo spotkanie', 'intro' => '', 'steps' => [
                $step('Kliknij Stwórz', '<p>Kliknij „Stwórz” na górze albo dwa razy kliknij wybrany dzień i godzinę w siatce kalendarza.</p>'),
                $step('Podsumowanie', '<p>Na karcie „Podsumowanie” wpisz „Tytuł”, ustaw „Początek” i „Koniec”, a dla wydarzenia na cały dzień włącz „Cały dzień”. '
                    . 'Uzupełnij „Lokalizację”, na przykład adres albo link do spotkania online w <a href="' . $app . '/uslugi-it/uslugi/jitsi-meet/szybkie-spotkanie">Jitsi Meet</a>, i „Opis”. „Pokaż mnie jako” ustawia, czy w tym czasie jesteś zajęty.</p>'),
                $step('Cykl i powiadomienia', '<p>Wydarzenie co tydzień albo co miesiąc ustawisz na karcie „Cykl”. Na karcie „Powiadomienia” dodasz przypomnienie, na przykład 15 minut wcześniej.</p>'),
                $step('Uczestnicy', '<p>Na karcie „Uczestnicy” wpisz adresy osób, które chcesz zaprosić. Dostaną zaproszenie e-mailem i mogą je przyjąć, odrzucić albo odpowiedzieć „Może”. '
                    . 'Na karcie „Załączniki” dodasz pliki, na przykład porządek spotkania.</p>'),
                $step('Zapisz', '<p>Kliknij „Zapisz” na dole. „Anuluj” zamyka okno bez zmian.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-kalendarz-wydarzenie.jpg', 'Okno Stwórz wydarzenie: karty Podsumowanie, Cykl, Powiadomienia, Uczestnicy, Załączniki; pola Tytuł, Początek, Koniec, Cały dzień, Lokalizacja, Opis, URL, Kalendarz, Kategoria, Pokaż mnie jako, Widoczność, Priorytet', 'Okno nowego wydarzenia.'),
            ['type' => 'text', 'html' => '<h2>Udostępnianie kalendarza</h2>'
                . '<p>Kliknij ołówek przy kalendarzu na liście po lewej i wybierz kartę „Udostępnij kalendarz”. Wpisz adres e-mail współpracownika i zaznacz, co może: '
                . '„Podgląd” (widzi wydarzenia), „Edycja” (może je zmieniać), „Udostępnianie” i „Publikacja” (może udostępniać dalej) oraz „Powiadom”, czyli wysłanie mu wiadomości o udostępnieniu. '
                . 'Kliknij „Udostępnij”, a potem „Zapisz”. Najczęściej wystarczy sam podgląd.</p>'],
            ...itPortalDpocztaShot('dp-kalendarz-udostepnij.jpg', 'Edycja kalendarza, karta Udostępnij kalendarz: pole E-Mail i kolumny Udostępnianie, Publikacja, Edycja, Podgląd, Powiadom, przycisk Udostępnij', 'Edycja kalendarza, karta „Udostępnij kalendarz”.'),
            ['type' => 'text', 'html' => '<h2>Pozostałe karty edycji kalendarza</h2>'
                . '<p>„Podsumowanie”: nazwa, opis, kolory i domyślna widoczność wydarzeń.</p>'
                . '<p>„Opublikuj kalendarz”: tworzy link do kalendarza dla osób spoza poczty. Jeden przycisk „Utwórz łącze” publikuje pełne informacje o wydarzeniach, drugi tylko to, kiedy jesteś zajęty, a kiedy wolny. '
                . 'Publikuj ostrożnie, bo każdy, kto ma link, zobaczy te informacje. Zwykle wystarczy publikacja tylko czasu zajętego i wolnego.</p>'
                . '<p>„Synchronizacja”: połączenie z telefonem albo programem na komputerze, opisane niżej.</p>'
                . '<p>„Import / Eksport”: wczytanie wydarzeń z pliku, na przykład wyeksportowanego z Outlooka, i zapis kalendarza do pliku.</p>'],
            ['type' => 'text', 'html' => '<h2>Kalendarz w telefonie i w programie na komputerze</h2>'
                . '<p>Kliknij ołówek przy kalendarzu i wybierz kartę „Synchronizacja”. W części „Tworzenie nowego połączenia CalDav” wpisz „Nazwę wyświetlaną”, na przykład „Telefon”, '
                . 'i wymyśl „Hasło” do tego połączenia. Jeśli urządzenie ma tylko oglądać kalendarz, włącz „Tylko do odczytu”. Kliknij „Dodaj połączenie”. '
                . 'Pojawi się adres kalendarza i nazwa użytkownika. Wpisz je razem z tym hasłem w telefonie albo programie jako konto CalDAV. '
                . 'Na iPhonie dodasz je w Ustawieniach, w części „Kalendarz”, „Konta”, „Dodaj konto”, „Inne”. Na Androidzie potrzebna jest aplikacja obsługująca CalDAV, na przykład DAVx5.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Kalendarz z Outlooka w Microsoft 365 nie przenosi się sam. Jeśli chcesz mieć stare wydarzenia w dPoczcie, wyeksportuj kalendarz z Outlooka do pliku .ics '
                . 'i wczytaj go na karcie „Import / Eksport”. Gdy coś nie działa, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], false],

        'szablony-odpowiedzi' => ['Szablony odpowiedzi', 'szablon, szablony, gotowa odpowiedź, powtarzalna wiadomość', [
            ['type' => 'text', 'html' => '<p>Na te same pytania odpowiadasz wiele razy? Zapisz odpowiedź jako szablon.</p>'],
            ['type' => 'wizard', 'title' => 'Szablon krok po kroku', 'intro' => '', 'steps' => [
                $step('Dodaj szablon', '<p>' . $gear . ' Wybierz „Odpowiedzi”. Kliknij ikonę ołówka na górze (Utwórz), wpisz nazwę i treść, potem „Zapisz”.</p>'),
                $step('Użyj szablonu', '<p>Odpowiadając na wiadomość, kliknij u góry okna ikonę zakładki i wybierz szablon z listy.</p>'),
            ]],
            ...itPortalDpocztaShot('dp-odpowiedzi.jpg', 'Ustawienia dPoczty, Odpowiedzi: pusta lista z napisem Lista jest pusta, na górze ikona ołówka i kosza', 'Ustawienia, „Odpowiedzi”, zanim dodasz pierwszy szablon.'),
        ], false],

        'powiadomienia-o-poczcie' => ['Powiadomienia o nowej poczcie', 'powiadomienia, nowa wiadomość, dźwięk, przeglądarka, alert', [
            ['type' => 'text', 'html' => '<p>Przeglądarka może pokazywać powiadomienie, gdy przyjdzie nowa wiadomość, nawet gdy pracujesz w innej karcie.</p>'],
            ['type' => 'wizard', 'title' => 'Włączenie powiadomień', 'intro' => '', 'steps' => [
                $step('Otwórz preferencje', '<p>' . $gear . ' Wybierz „Preferencje”, potem „Widok skrzynki pocztowej”.</p>'),
                $step('Włącz sprawdzanie poczty', '<p>W części „Nowa wiadomość” włącz „Sprawdzaj, czy nadeszły nowe wiadomości we wszystkich folderach”.</p>'),
                $step('Zezwól przeglądarce', '<p>Kliknij „Konfiguracja przeglądarki” i w okienku przeglądarki wybierz „Zezwalaj”. Kliknij „Test”, żeby sprawdzić, czy powiadomienie się pojawia. Zapisz ustawienia.</p>'),
            ]],
        ], false],

        'duze-zalaczniki' => ['Duże załączniki', 'załącznik, załączniki, limit, rozmiar, duży plik, zip, link, chmura, transfernow, wetransfer, wiadomość wróciła', [
            ['type' => 'text', 'html' => '<p>Dostawca poczty podaje limit 2 GB na załączniki. Serwer odbiorcy często przyjmuje jednak dużo mniej, zwykle od 20 do 30 MB. '
                . 'Wtedy wiadomość wraca do Ciebie z informacją, że jest za duża.</p>'
                . '<p>Plik większy niż ok. 20 MB wyślij jako link, a nie załącznik:</p><ul>'
                . '<li>z <a href="' . $cloud . '">chmury Fundacji</a>, gdy w pliku są dane osobowe albo gdy wysyłasz go komuś z Fundacji,</li>'
                . '<li>przez TransferNow albo WeTransfer, gdy wysyłasz go poza Fundację i nie ma w nim danych osobowych. '
                . 'Jak to zrobić, opisuje artykuł <a href="' . $transfer . '">Bardzo duże pliki: TransferNow i WeTransfer</a>.</li></ul>'
                . '<p>Kilka mniejszych plików możesz też spakować do jednego archiwum ZIP.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Dla IT i Zarządu jest też Seafile, osobna, szyfrowana usługa do przechowywania i przekazywania dużych plików, '
                . 'na zewnętrznym hostingu. Z Seafile korzystają tylko te osoby. Pozostali wysyłają duże pliki linkiem z chmury Fundacji albo przez TransferNow lub WeTransfer, jak opisano wyżej.</p>'],
        ], false],

        'transfernow-wetransfer' => ['Bardzo duże pliki: TransferNow i WeTransfer',
            'transfernow, wetransfer, duży plik, duże pliki, duży załącznik, przesyłanie plików, wysyłka plików, link do pobrania, gb, hasło, zip, 7-zip, phishing, chmura', [
            ['type' => 'text', 'html' => '<p>Plik jest za duży na załącznik? Wyślij odbiorcy link, a plik pobierze on ze strony usługi. '
                . 'Do tego służą TransferNow i WeTransfer. W planie bezpłatnym nie trzeba zakładać konta.</p>'
                . '<p>Zanim wybierzesz usługę, sprawdź, co jest w pliku i do kogo trafi:</p><ul>'
                . '<li><strong>Plik z danymi osobowymi albo odbiorca z Fundacji</strong> (PESEL, adresy, umowy, dokumenty uczestników, dane o zdrowiu): '
                . 'udostępnij plik z <a href="' . $cloud . '">chmury Fundacji</a>. Plik zostaje na serwerze Fundacji, a link możesz wyłączyć w każdej chwili. '
                . 'Przy pliku kliknij ikonę udostępniania, utwórz link i w jego ustawieniach włącz hasło i datę wygaśnięcia.</li>'
                . '<li><strong>Plik bez danych osobowych dla kogoś spoza Fundacji</strong> (zdjęcia z wydarzenia, film, grafiki, prezentacja): '
                . 'możesz użyć TransferNow albo WeTransfer.</li></ul>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Plików z danymi osobowymi nie wysyłaj przez TransferNow ani WeTransfer bez zabezpieczenia. '
                . 'Jeśli odbiorca nie może pobrać pliku z chmury, najpierw spakuj go do archiwum ZIP z hasłem (opis niżej). '
                . 'Hasło podaj SMS-em albo przez telefon, nigdy w tej samej wiadomości co link.</p>'],

            ['type' => 'text', 'html' => '<h2>Którą usługę wybrać</h2>'
                . '<table><thead><tr><th>Plan bezpłatny</th><th>TransferNow</th><th>WeTransfer</th></tr></thead><tbody>'
                . '<tr><td>Największa przesyłka</td><td>5 GB</td><td>3 GB</td></tr>'
                . '<tr><td>Limit przesyłek</td><td>dostawca nie podaje</td><td>10 przesyłek albo 3 GB łącznie w ciągu 30 dni</td></tr>'
                . '<tr><td>Ile dni plik czeka na odbiorcę</td><td>7</td><td>3</td></tr>'
                . '<tr><td>Hasło do pobrania</td><td>tak, bez opłat</td><td>tylko w planie płatnym</td></tr>'
                . '</tbody></table>'
                . '<p>Polecamy TransferNow: przyjmie większy plik, trzyma go dłużej i pozwala ustawić hasło za darmo. '
                . 'WeTransfer wybierz, gdy odbiorca o niego prosi.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Limity sprawdziliśmy 13 listopada 2026 r. Dostawcy zmieniają je bez uprzedzenia, aktualne znajdziesz na ich stronach.</p>'],

            ['type' => 'wizard', 'title' => 'Wysyłka przez TransferNow', 'intro' => '<p>Otwórz <a href="https://www.transfernow.net/pl">transfernow.net</a>. Konto nie jest potrzebne.</p>', 'steps' => [
                $step('Dodaj pliki', '<p>Kliknij przycisk dodawania plików albo przeciągnij pliki na stronę. Wszystkie razem mogą mieć do 5 GB.</p>'),
                $step('Wybierz link albo e-mail', '<p>Polecamy link. Skopiujesz go i wyślesz odbiorcy ze swojej skrzynki w dpoczta.pl, więc odbiorca zobaczy, że wiadomość jest od Ciebie. '
                    . 'Jeśli wolisz wysyłkę e-mailem przez TransferNow, wpisz adres odbiorcy i swój adres służbowy.</p>'),
                $step('Ustaw hasło', '<p>W opcjach przesyłki włącz hasło i wpisz je. Odbiorcy podaj je SMS-em albo przez telefon.</p>'),
                $step('Wyślij', '<p>Kliknij przycisk wysyłania i nie zamykaj karty, dopóki pasek postępu nie dojdzie do końca. '
                    . 'Dostaniesz link albo potwierdzenie e-mailem. Plik będzie czekał na odbiorcę 7 dni.</p>'),
            ]],

            ['type' => 'wizard', 'title' => 'Wysyłka przez WeTransfer', 'intro' => '<p>Otwórz <a href="https://wetransfer.com">wetransfer.com</a>.</p>', 'steps' => [
                $step('Dodaj pliki', '<p>Kliknij plus i wybierz pliki. Wszystkie razem mogą mieć do 3 GB.</p>'),
                $step('Wpisz adresy albo wybierz link', '<p>Wpisz adres odbiorcy, swój adres służbowy, tytuł i krótką wiadomość. W opcjach przesyłki możesz zamiast e-maila wybrać link i wysłać go ze swojej skrzynki.</p>'),
                $step('Potwierdź swój adres', '<p>WeTransfer może wysłać kod na Twój adres. Przepisz go z wiadomości w dpoczta.pl. Hasła do poczty nie podawaj na tej stronie.</p>'),
                $step('Wyślij', '<p>Kliknij przycisk wysyłania i poczekaj do końca. Plik zniknie po 3 dniach. W planie bezpłatnym nie ustawisz hasła, więc wysyłaj tak tylko pliki, które mogłyby trafić do kogoś obcego.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Archiwum ZIP z hasłem</h2>'
                . '<p>Na Windowsie zainstaluj bezpłatny program <a href="https://www.7-zip.org">7-Zip</a>. Kliknij plik albo folder prawym przyciskiem myszy i wybierz „7-Zip”, potem „Dodaj do archiwum…”. '
                . 'Ustaw format „zip” i szyfrowanie „AES-256”, wpisz hasło dwa razy i kliknij „OK”.</p>'
                . '<p>Na Macu użyj bezpłatnego programu <a href="https://www.keka.io/pl/">Keka</a>. Wybierz format ZIP, włącz szyfrowanie AES-256, wpisz hasło i przeciągnij pliki na okno programu.</p>'
                . '<p>Hasło powinno mieć co najmniej 12 znaków. Odbiorca otworzy archiwum w 7-Zip, Keka albo innym programie z obsługą AES-256. '
                . 'Rozpakowywanie wbudowane w Windows takiego archiwum nie otworzy.</p>'],

            ['type' => 'text', 'html' => '<h2>Gdy ktoś przysyła Ci plik</h2>'
                . '<p>Pobierz plik, zanim link wygaśnie, i zapisz go w chmurze Fundacji albo w folderze projektu. Nie zostawiaj go w folderze „Pobrane”.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Oszuści podszywają się pod WeTransfer i TransferNow. Prawdziwe linki prowadzą na adresy '
                . '<code>wetransfer.com</code>, <code>we.tl</code> albo <code>transfernow.net</code>. Strona pobierania nigdy nie prosi o hasło do poczty ani innego konta. '
                . 'Jeśli prosi, nic nie wpisuj i <a href="' . $hd . '">zgłoś wiadomość w Helpdesku</a>. Ostrożnie z plikiem, którego się nie spodziewasz, nawet od znajomego nadawcy.</p>'],
        ], false],
    ];
}
