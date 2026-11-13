<?php
/**
 * modules/it_portal/lib/itPortalMigracjaSeed.php — usługa „Migracja” w Portalu Usług IT (/uslugi-it/uslugi/migracja) z artykułem
 * „migracja”: terminy, powody odejścia od Microsoft 365, co zrobić, loginy i hasła, zmiany z 13.11.2026 (polecenie 2026-11-13:
 * „opisz w /uslugi-it dzisiejsze zmiany i powody migracji, artykuł rozbuduj jako: migracja”), oraz artykuły „dlaczego-migrujemy”
 * i „iam-i-poczta” (polecenie 2026-11-13: „dodaj artykuł dlaczego migrujemy i czym jest IAM i Poczta”; limity skrzynek: 5 GB
 * dla wolontariusza i pracownika, konta kadry kierowniczej bez zmian — „opisz to w bazie”). Tekst prostym językiem (humanizer).
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
         . '<p>Terminy, loginy i hasła opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/migracja/migracja">Migracja z Microsoft 365</a>. '
         . 'Powody zmiany znajdziesz w artykule <a href="' . $app . '/uslugi-it/uslugi/migracja/dlaczego-migrujemy">Dlaczego odchodzimy od Microsoft 365</a>, '
         . 'a opis nowych kont w artykule <a href="' . $app . '/uslugi-it/uslugi/migracja/iam-i-poczta">Czym jest IAM, a czym poczta</a>.</p>';
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
                . '<p>Microsoft zakończył bezpłatne licencje dla organizacji pozarządowych i podnosi ceny, a w 2026 r. jego poczta kilka razy przestała działać, '
                . 'także na całym świecie. Gdy poczta, pliki i spotkania są u jednego dostawcy, jego awaria zatrzymuje całą Fundację. '
                . 'Szczegóły opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/migracja/dlaczego-migrujemy">Dlaczego odchodzimy od Microsoft 365</a>.</p>'],

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

        'dlaczego-migrujemy' => ['Dlaczego odchodzimy od Microsoft 365',
            'dlaczego, powód, microsoft 365, office 365, licencje, ceny, awaria, awarie, vendor lock-in, uzależnienie, otwarte standardy, imap, smtp, migracja', [
            ['type' => 'text', 'html' => '<p>Poczta, pliki i spotkania Fundacji są dziś w Microsoft 365. W 2025 i 2026 r. Microsoft kilka razy zmienił warunki '
                . 'dla organizacji pozarządowych, a jego usługi kilka razy przestały działać. Dlatego przenosimy pracę do narzędzi, nad którymi mamy kontrolę.</p>'],
            ['type' => 'text', 'html' => '<h2>Zmiany warunków</h2><ul>'
                . '<li>1 lipca 2025 r. Microsoft zakończył bezpłatne licencje Microsoft 365 Business Premium i Office 365 E1 dla organizacji pozarządowych. '
                . 'Licencje wygasają przy pierwszym odnowieniu subskrypcji, więc w 2026 r. tracą je kolejne organizacje.</li>'
                . '<li>Część organizacji zgłaszała na forum Microsoftu, że po wygaśnięciu licencji z dnia na dzień straciła dostęp do poczty i plików w OneDrive, '
                . 'dopóki nie kupiła nowych.</li>'
                . '<li>Za darmo zostaje tylko pakiet Business Basic: poczta i Office w przeglądarce i w telefonie, bez programów na komputer, takich jak Outlook czy Word. '
                . 'Pełny pakiet trzeba kupić z rabatem, ale za każdą osobę i co miesiąc.</li>'
                . '<li>1 lipca 2026 r. Microsoft podniósł ceny części pakietów Microsoft 365.</li>'
                . '<li>31 grudnia 2026 r. kończy się wsparcie narzędzia <span lang="en">Fundraising and Engagement</span> dla organizacji pozarządowych.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Awarie w 2026 r.</h2><ul>'
                . '<li>22 stycznia Outlook i Teams nie działały u dziesiątek tysięcy osób.</li>'
                . '<li>31 sierpnia przez kilka godzin na całym świecie nie dało się zalogować do poczty Exchange Online ani wysyłać i odbierać wiadomości. '
                . 'Awaria objęła też Teams, SharePoint i OneDrive. Microsoft podał jako przyczynę błąd w konfiguracji logowania.</li>'
                . '<li>12 i 13 listopada przyszła jedna z największych awarii Microsoft 365. Od wieczora 12 listopada poczta w Outlooku, Teams, SharePoint i Copilot '
                . 'nie działały w 74 krajach, a zgłoszeń było około 140 tysięcy, m.in. z urzędów i brytyjskiej służby zdrowia. Według Microsoftu nie był to atak, '
                . 'tylko skutek zmian w ustawieniach serwerów po aktualizacji. Następnego dnia Microsoft wciąż przywracał działanie usług.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Co zmieniamy</h2>'
                . '<p>Gdy poczta, pliki i spotkania są u jednego dostawcy, jego awaria zatrzymuje pracę całej Fundacji. Każda zmiana jego zasad stawia nas przed wyborem: '
                . 'płacić więcej albo stracić dostęp. Takie uzależnienie od jednego dostawcy nazywa się po angielsku <span lang="en">vendor lock-in</span>.</p>'
                . '<p>Pocztę przenosimy do polskiego dostawcy, u którego koszt nie zależy od zmian w programach Microsoftu. Poczta działa na otwartych standardach '
                . 'IMAP i SMTP, więc w razie potrzeby przeniesiemy ją dalej bez zmiany Twojego adresu. Pliki trafiają do chmury Fundacji, a logowanie do systemu '
                . 'tożsamości IAM. Opis nowych kont jest w artykule <a href="' . $app . '/uslugi-it/uslugi/migracja/iam-i-poczta">Czym jest IAM, a czym poczta</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Terminy i to, co masz zrobić, opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/migracja/migracja">Migracja z Microsoft 365</a>.</p>'],
        ], false],

        'iam-i-poczta' => ['Czym jest IAM, a czym poczta',
            'iam, logowanie jednokrotne, sso, logto, tożsamość, konto, chmura, nextcloud, poczta, skrzynka, limit, 5 gb, pojemność, hasło, login, dpoczta', [
            ['type' => 'text', 'html' => '<p>Po migracji masz dwa konta z osobnymi hasłami: konto IAM i skrzynkę pocztową. Loginem w obu jest zwykle ten sam adres e-mail, '
                . 'ale hasła muszą być różne.</p>'],
            ['type' => 'text', 'html' => '<h2>IAM, czyli logowanie jednokrotne</h2>'
                . '<p>IAM to system tożsamości Fundacji. Jedno konto i jedno hasło otwiera SZO, chmurę plików i kolejne aplikacje Fundacji. '
                . 'Od 1 grudnia 2026 r. tym kontem zalogujesz się do wszystkich aplikacji Fundacji.</p><ul>'
                . '<li>Login to adres e-mail zapisany w SZO.</li>'
                . '<li>Na stronie logowania SZO wybierz „Zaloguj przez logowanie jednokrotne”. Strona logowania IAM ma adres <code>logto.feer.org.pl</code>.</li>'
                . '<li>Konto założysz po zalogowaniu do SZO albo w Systemie Tożsamości, w sekcji <a href="' . $app . '/tozsamosc/index.php#sso">Logowanie jednokrotne</a>.</li>'
                . '<li>Chmura plików ma to samo hasło co IAM. Zastępuje OneDrive: trzymasz w niej pliki, udostępniasz je innym osobom i synchronizujesz z komputerem i telefonem.</li>'
                . '<li>Hasło zmienisz na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. Kod potwierdzenia przyjdzie e-mailem.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Poczta</h2>'
                . '<p>Poczta służbowa ma ten sam adres co dotąd: <code>imie.nazwisko@feer.org.pl</code>. Od 14 listopada 2026 r. działa u polskiego dostawcy, poza Microsoft 365.</p><ul>'
                . '<li>W przeglądarce otwierasz ją na <a href="https://dpoczta.pl">dpoczta.pl</a>. Do telefonu i programu pocztowego dodajesz ją jako konto IMAP, '
                . 'według instrukcji w usłudze <a href="' . $app . '/uslugi-it/uslugi/moja-poczta">Moja poczta</a>.</li>'
                . '<li>Logujesz się pełnym adresem e-mail i hasłem do poczty, innym niż hasło IAM.</li>'
                . '<li>Skrzynka wolontariusza i pracownika ma 5 GB. Skrzynki kadry kierowniczej zachowują dotychczasowy limit.</li>'
                . '<li>Hasło zmienisz na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. Kod potwierdzenia przyjdzie SMS-em, a gdy nie mamy Twojego numeru, na prywatny e-mail.</li>'
                . '<li>Skrzynkę możesz dodatkowo zabezpieczyć weryfikacją dwuetapową (2FA). Opis jest w usłudze Moja poczta.</li></ul>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Skrzynka zapełnia się szybciej, gdy trzymasz w niej duże załączniki. Pliki zapisuj w chmurze i wysyłaj link zamiast załącznika.</p>'],
            ['type' => 'text', 'html' => '<h2>Dlaczego dwa hasła</h2>'
                . '<p>Jeśli ktoś pozna hasło IAM, nie wejdzie do Twojej poczty, i odwrotnie. Dlatego strona Nowe hasło nie przyjmie hasła do poczty takiego samego jak hasło IAM.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasło IAM wpisuj tylko na stronie logowania SZO i na <code>logto.feer.org.pl</code>, a hasło do poczty na dpoczta.pl i w swoim programie pocztowym. '
                . 'Nikt z Fundacji nie poprosi Cię o hasło. <a href="' . $hd . '">Napisz do Helpdesku</a>, jeśli coś Cię zaniepokoi.</p>'],
        ], false],
    ];
}
