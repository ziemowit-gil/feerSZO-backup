<?php
/**
 * modules/it_portal/lib/itPortalMigracjaSeed.php — usługa „Migracja” w Portalu Usług IT (/uslugi-it/uslugi/migracja) z artykułem
 * „migracja”: terminy, powody odejścia od Microsoft 365, co zrobić, loginy i hasła, zmiany z 13.11.2026 (polecenie 2026-11-13:
 * „opisz w /uslugi-it dzisiejsze zmiany i powody migracji, artykuł rozbuduj jako: migracja”). Tekst prostym językiem (humanizer).
 *
 * Źródła faktów: modules/dhosting_migrator/view/_why.php (licencje, ceny, awarie 2026), modules/sso_logto/view/loginInfo.php
 * (wyłączenie Microsoft 365 20.11.2026, IAM), data przełączenia poczty 14.11.2026, „od 1 grudnia jedno hasło do wszystkich aplikacji”
 * (polecenie 2026-11-13), commity z 13.11.2026. Bez nazwy firmy dostawcy poczty (adres dpoczta.pl to adres poczty w przeglądarce).
 * Używa migracja '2026-11_it_portal_migracja_article' (modules/selfrepairDB/logic/migrations.php) i cli/it_portal_migracja_seed.php.
 */

/**
 * Zasiew: usługa „Migracja” (nowa albo nadpisany wstęp) i artykuł „migracja” (nowy albo nadpisany). Bezpieczne do ponownego uruchomienia.
 * @return array{category:string, created:list<string>, updated:list<string>}
 */
function itPortalMigracjaApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'created' => [], 'updated' => []];
    $intro = itPortalSanitizeHtml(itPortalMigracjaIntro($app));

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'migracja'");
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = 'wstęp nadpisany';
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona';
        $catId = 0;
        if (!$dryRun) {
            $aud = array_column(itPortalAudiences(), 'id', 'name');
            $catId = itPortalCategorySave(['name' => 'Migracja', 'slug' => 'migracja', 'icon' => 'cloud', 'sort_order' => 5, 'is_active' => 1,
                'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
        }
    }

    $i = 0;
    foreach (itPortalMigracjaArticles($app) as $slug => [$title, $keywords, $blocks, $pinned]) {
        $old = $catId ? db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep[$old ? 'updated' : 'created'][] = "migracja/{$slug} — {$title}";
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10], $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/** Wstęp strony usługi „Migracja”. */
function itPortalMigracjaIntro(string $app): string {
    return '<p>Fundacja przenosi pracę z Microsoft 365 do nowej poczty, chmury plików i systemu tożsamości IAM. '
         . 'Twój adres e-mail się nie zmienia. Microsoft 365 wyłączamy 20 listopada 2026 r.</p>'
         . '<p>Terminy, powody zmiany, loginy i hasła opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/migracja/migracja">Migracja z Microsoft 365</a>.</p>';
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty na Start].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalMigracjaArticles(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'migracja' => ['Migracja z Microsoft 365: co się zmienia i dlaczego',
            'migracja, microsoft 365, office 365, przeniesienie, poczta, chmura, nextcloud, onedrive, iam, logowanie jednokrotne, hasło, login, nowe hasło, 14 listopada, 20 listopada, 1 grudnia, zmiany', [
            ['type' => 'text', 'html' => '<p>Fundacja przenosi pracę z Microsoft 365 do narzędzi, nad którymi ma kontrolę: nowej poczty, chmury plików '
                . 'i systemu tożsamości IAM (logowanie jednokrotne). Twój adres e-mail zostaje taki sam. Zmieniają się miejsca logowania i hasła.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Ważne daty:</p><ul>'
                . '<li>14 listopada 2026 r. poczta zaczyna działać w nowej skrzynce. Przez kolejne 7 dni co kilka godzin dosyłamy do niej wiadomości, które trafią jeszcze do Microsoft 365.</li>'
                . '<li>20 listopada 2026 r. wyłączamy Microsoft 365. Do tego dnia załóż konto IAM i przenieś do chmury pliki z OneDrive, których potrzebujesz.</li>'
                . '<li>Od 1 grudnia 2026 r. jednym loginem i jednym hasłem IAM zalogujesz się do wszystkich aplikacji Fundacji. Poczta ma swoje, osobne hasło.</li></ul>'],

            ['type' => 'text', 'html' => '<h2>Dlaczego odchodzimy od Microsoft 365</h2>'
                . '<p>Microsoft kilka razy zmienił warunki dla organizacji pozarządowych:</p><ul>'
                . '<li>1 lipca 2025 r. zakończył bezpłatne licencje Microsoft 365 Business Premium i Office 365 E1. Licencje wygasają przy pierwszym odnowieniu subskrypcji, '
                . 'więc w 2026 r. tracą je kolejne organizacje. Część z nich zgłaszała, że po wygaśnięciu z dnia na dzień straciła dostęp do poczty i plików w OneDrive.</li>'
                . '<li>Za darmo zostaje tylko pakiet Business Basic: poczta i Office w przeglądarce i w telefonie, bez programów na komputer. '
                . 'Pełny pakiet trzeba kupować co miesiąc dla każdej osoby.</li>'
                . '<li>1 lipca 2026 r. Microsoft podniósł ceny części pakietów.</li>'
                . '<li>31 grudnia 2026 r. kończy się wsparcie narzędzia <span lang="en">Fundraising and Engagement</span> dla organizacji pozarządowych.</li></ul>'
                . '<p>W 2026 r. poczta Microsoft 365 kilka razy przestała działać. 22 stycznia Outlook i Teams nie działały u dziesiątek tysięcy osób. '
                . '31 sierpnia przez kilka godzin na całym świecie nie dało się wysyłać ani odbierać poczty, a awaria objęła też Teams, SharePoint i OneDrive. '
                . '12 i 13 listopada przyszła jedna z największych awarii: poczta, Teams i SharePoint nie działały w 74 krajach, zgłoszeń było około 140 tysięcy.</p>'
                . '<p>Gdy poczta, pliki i spotkania są u jednego dostawcy, jego awaria zatrzymuje pracę całej Fundacji. Każda zmiana jego zasad '
                . 'oznacza wybór: płacić więcej albo stracić dostęp. Dlatego pocztę przenosimy do polskiego dostawcy, a pliki do chmury Fundacji. '
                . 'Obie usługi działają na otwartych standardach, więc w razie potrzeby przeniesiemy je dalej bez zmiany Twojego adresu.</p>'],

            ['type' => 'wizard', 'title' => 'Co masz zrobić', 'intro' => '<p>Część kroków mógł już zrobić za Ciebie administrator. Wtedy na stronach z kroków 1 i 3 zobaczysz, że są gotowe.</p>', 'steps' => [
                $step('Przenieś pocztę', '<p>Wejdź na stronę <a href="' . $app . '/migracjapoczty">Migracja poczty</a>. Jeśli poczta jest już przeniesiona, zobaczysz to od razu, '
                    . 'a hasło do nowej skrzynki masz w SMS-ie. Jeśli nie, założysz skrzynkę sam: hasło przyjdzie SMS-em, a stare wiadomości skopiujemy w tle.</p>'
                    . '<p>Na tę stronę wejdziesz też bez logowania do SZO. Podaj prywatny e-mail albo PESEL z umowy, adres służbowy i kod z SMS-a.</p>'),
                $step('Zaloguj się do nowej poczty', '<p>W przeglądarce otwórz <a href="https://dpoczta.pl">dpoczta.pl</a>. Login to pełny adres <code>imie.nazwisko@feer.org.pl</code>, '
                    . 'hasło jest w SMS-ie. Jak dodać pocztę do telefonu albo Outlooka, opisują instrukcje w usłudze <a href="' . $app . '/uslugi-it/uslugi/moja-poczta">Moja poczta</a>.</p>'),
                $step('Załóż konto IAM i konto w chmurze', '<p>Zaloguj się do SZO e-mailem i hasłem. Po zalogowaniu SZO zaproponuje założenie konta IAM i konta w chmurze plików. '
                    . 'Wybierz hasło inne niż do poczty. To samo zrobisz w Systemie Tożsamości, w sekcji <a href="' . $app . '/tozsamosc/index.php#sso">Logowanie jednokrotne</a>.</p>'
                    . '<p>Następnym razem na stronie logowania SZO wybierz „Zaloguj przez logowanie jednokrotne”.</p>'),
                $step('Przenieś pliki z OneDrive', '<p>Chmura plików Fundacji zastępuje OneDrive. Trzymasz w niej pliki, udostępniasz je innym osobom i synchronizujesz z komputerem i telefonem przez aplikację. '
                    . 'Do 20 listopada przenieś do chmury pliki, których potrzebujesz. Jeśli administrator zlecił kopiowanie Twojego OneDrive, pliki pojawią się w chmurze same, w folderze „OneDrive”.</p>'),
                $step('Zapisz dane logowania', '<p>Na stronie <a href="' . $app . '/migracjapoczty">Migracja poczty</a> pobierzesz PDF z loginami do poczty, IAM i chmury. Haseł w nim nie ma.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Loginy i hasła</h2>'
                . '<table><thead><tr><th>Gdzie</th><th>Login</th><th>Hasło</th></tr></thead><tbody>'
                . '<tr><td>IAM (logowanie jednokrotne)</td><td>Twój adres e-mail z SZO</td><td>hasło IAM</td></tr>'
                . '<tr><td>Chmura plików</td><td>Twój adres e-mail albo login <code>imie.nazwisko</code></td><td>to samo hasło IAM</td></tr>'
                . '<tr><td>SZO</td><td>Twój adres e-mail</td><td>przycisk „Zaloguj przez logowanie jednokrotne” albo hasło do SZO; od 1 grudnia hasło IAM</td></tr>'
                . '<tr><td>Poczta</td><td><code>imie.nazwisko@feer.org.pl</code></td><td>osobne hasło do poczty</td></tr>'
                . '</tbody></table>'
                . '<p>Hasło do poczty musi być inne niż hasło IAM. Jeśli ktoś pozna jedno z nich, nie wejdzie na drugie konto.</p>'],

            ['type' => 'text', 'html' => '<h2>Jak zmienić hasło</h2>'
                . '<p>Hasło IAM i chmury albo hasło do poczty zmienisz sam na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. Każdą zmianę potwierdzasz kodem. '
                . 'Przy haśle do poczty kod przychodzi SMS-em na numer zapisany w SZO albo w umowie, a gdy numeru nie mamy, na Twój prywatny e-mail. '
                . 'Przy haśle IAM i chmury kod przychodzi e-mailem. '
                . 'Możesz wpisać własne hasło (co najmniej 12 znaków, małe i wielkie litery oraz cyfry) albo dostać losowe SMS-em. '
                . 'Strona nie przyjmie hasła do poczty takiego samego jak hasło IAM i odwrotnie.</p>'
                . '<p>Na stronę wejdziesz też bez logowania do SZO: podaj prywatny e-mail albo PESEL z umowy i adres służbowy. '
                . 'Po każdej zmianie dostaniesz SMS i e-mail z informacją o zmianie. Samo hasło wysyłamy tylko SMS-em.</p>'],

            ['type' => 'text', 'html' => '<h2>Co zmieniło się 13 listopada 2026 r.</h2><ul>'
                . '<li>Działa przenoszenie poczty z Microsoft 365 do nowej skrzynki. Kopiowanie idzie w tle. Po zakończeniu przychodzi e-mail i SMS, że możesz się zalogować. '
                . 'Wiadomości, których nie udało się przenieść, są wypisane na stronie Migracja poczty i zostały w starej skrzynce.</li>'
                . '<li>Każda przenoszona skrzynka ma zgłoszenie w Helpdesku. Dostajesz potwierdzenie z linkiem i możesz w nim odpisać, jeśli coś nie działa.</li>'
                . '<li>Do SZO logujesz się przez IAM albo e-mailem i hasłem. Konto Microsoft służy do logowania już tylko administratorom. '
                . 'Jeśli nie pamiętasz hasła do SZO, ustawisz je kodem z SMS-a. Ekran logowania pokazuje, ile dni zostało do wyłączenia Microsoft 365.</li>'
                . '<li>Po zalogowaniu SZO proponuje założenie konta IAM i konta w chmurze plików.</li>'
                . '<li>Loginy w chmurze mają postać <code>imie.nazwisko</code>. Jeśli Twój dawny login miał znak „_”, przyszedł SMS z nowym loginem i nowym hasłem. '
                . 'Stare konto wyłączyliśmy, ale pliki z niego nie zginęły: napisz do Helpdesku, a przeniesiemy je.</li>'
                . '<li>Hasło do poczty jest osobne od hasła IAM i chmury. SMS z hasłem podaje, od kiedy hasło obowiązuje.</li>'
                . '<li>Przy nowej umowie zakładamy skrzynkę <code>imie.nazwisko@feer.org.pl</code>. Hasło przychodzi SMS-em po podpisaniu umowy.</li>'
                . '<li>W usłudze <a href="' . $app . '/uslugi-it/uslugi/moja-poczta">Moja poczta</a> są nowe instrukcje, m.in. logowanie, zmiana hasła, poczta w telefonie i Outlooku, '
                . 'weryfikacja dwuetapowa, podpis i autoodpowiedź. Dawne instrukcje poczty w Office 365 ukryliśmy.</li>'
                . '<li>Nowa strona <a href="' . $app . '/nowehaslo">Nowe hasło</a> do samodzielnej zmiany haseł.</li>'
                . '<li>Na stronie Migracja poczty pobierzesz PDF z danymi logowania, bez haseł.</li></ul>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nikt z Fundacji nie poprosi Cię o hasło. Hasła wysyłamy tylko SMS-em, nigdy e-mailem. '
                . 'Jeśli przyjdzie SMS o zmianie hasła, której nie robiłeś(-aś), od razu <a href="' . $hd . '">napisz do Helpdesku</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Masz pytanie albo coś nie działa? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
        ], true],
    ];
}
