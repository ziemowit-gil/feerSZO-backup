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

/** Wstęp strony usługi „Moja poczta”. */
function itPortalDpocztaIntro(string $app): string {
    return '<p>Poczta służbowa działa na serwerach dhosting. W przeglądarce otwierasz ją pod adresem <a href="https://dpoczta.pl">dpoczta.pl</a>. '
         . 'Logujesz się pełnym adresem <code>imie.nazwisko@feer.org.pl</code> i hasłem do skrzynki.</p>'
         . itPortalDpocztaOutlookBye()
         . '<p>Wybierz instrukcję z listy. Jeśli czegoś w niej brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>';
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

    return [
        'logowanie-dpoczta' => ['Jak zalogować się do poczty', 'logowanie, login, hasło, sms, dpoczta, webmail, migracja, nowa poczta, imie.nazwisko', [
            ['type' => 'text', 'html' => '<p>Pocztę w przeglądarce otwierasz na stronie <a href="https://dpoczta.pl">dpoczta.pl</a>. Działa na komputerze i w telefonie, bez instalowania czegokolwiek.</p>'],
            ['type' => 'wizard', 'title' => 'Logowanie krok po kroku', 'intro' => '', 'steps' => [
                $step('Otwórz stronę poczty', '<p>Wejdź na <a href="https://dpoczta.pl">dpoczta.pl</a>.</p>'),
                $step('Wpisz login', '<p>Loginem jest pełny adres służbowy: <code>imie.nazwisko@feer.org.pl</code>, małymi literami i bez polskich znaków (np. <code>jan.kowalski@feer.org.pl</code>).</p>'),
                $step('Wpisz hasło z SMS-a', '<p>Hasło do skrzynki dostałeś SMS-em:</p><ul><li>jeśli konto pocztowe istniało przed 13.11.2026, SMS przyszedł po przeniesieniu poczty z Microsoft 365;</li><li>jeśli konto założyliśmy później, SMS przychodzi po podpisaniu umowy.</li></ul>'),
                $step('Zmień hasło na własne', '<p>Po pierwszym logowaniu ustaw własne hasło w SZO, na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. Nie zmieniaj go w ustawieniach dpoczta.pl. Opis jest w instrukcji „Jak zmienić hasło do poczty”.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasło do poczty jest osobne. To inne hasło niż do Panelu współpracownika (SZO), do Microsoft 365 i do logowania jednokrotnego.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie masz SMS-a albo hasło nie działa? <a href="' . $hd . '">Zgłoś to w Helpdesku</a>, nadamy nowe hasło.</p>'],
        ], true],

        // 2026-11-14: hasło do poczty TYLKO przez SZO (/nowehaslo) — zmiana w dpoczta.pl nie synchronizuje się z SZO
        'dpoczta-co-jest-co' => ['dPoczta.pl: co jest co na ekranie i w ustawieniach', 'dpoczta, co jest co, ekran, foldery, odebrane, kopie robocze, wysłane, spam, kosz, archiwum, utwórz, szukaj, wątki, pasek, kontakty, kalendarz, zadania, ustawienia, preferencje, tożsamości, odpowiedzi, cloud view, przypomnienie hasła, klucze pgp, filtry, hasło, pomoc, autoresponder, uwierzytelnianie dwuetapowe, cleanbox, tryb ciemny, wyloguj, miejsce w skrzynce', [
            ['type' => 'text', 'html' => '<p>Po zalogowaniu na <a href="https://dpoczta.pl">dpoczta.pl</a> ekran ma trzy kolumny: po lewej foldery, w środku listę wiadomości, '
                . 'a po prawej treść otwartej wiadomości. Na samym prawym brzegu jest wąski pasek z przejściem do innych części poczty.</p>'],
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
                . 'Etykiety (ikona metki) i trzy kropki z pozostałymi działaniami, na przykład drukowaniem albo pobraniem wiadomości.</p>'],
            ['type' => 'text', 'html' => '<h2>Pasek po prawej</h2>'
                . '<p>Ikony od góry: Poczta (koperta), Kontakty, Kalendarz, Zadania, Ustawienia (koło zębate) i inne aplikacje dostawcy (kratka). '
                . 'Na dole są księżyc, który włącza tryb ciemny, i czerwony przycisk wyłączania, którym się wylogujesz.</p>'],
            ['type' => 'text', 'html' => '<h2>Ustawienia</h2>'
                . '<p>Po kliknięciu koła zębatego po lewej jest lista części ustawień:</p><ul>'
                . '<li>Preferencje: wygląd, język, strefa czasowa, sposób wyświetlania i pisania wiadomości.</li>'
                . '<li>Foldery: dodawanie, zmiana nazw i usuwanie folderów.</li>'
                . '<li>Tożsamości: nazwa nadawcy i podpis, czyli stopka. Opisuje to artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/podpis-w-poczcie">Jak ustawić podpis (stopkę) w poczcie</a>.</li>'
                . '<li>Odpowiedzi: gotowe teksty do wstawiania w wiadomości, opisane w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/szablony-odpowiedzi">Szablony odpowiedzi</a>.</li>'
                . '<li>Cloud View: ustawienia dodatku dostawcy poczty. Nie musisz ich zmieniać.</li>'
                . '<li>Klucze PGP: szyfrowanie wiadomości. Na co dzień nie jest potrzebne.</li>'
                . '<li>Filtry: automatyczne przenoszenie i przekazywanie wiadomości, opisane w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/filtry-i-przekierowanie">Filtry i przekierowanie poczty</a>.</li>'
                . '<li>Pomoc: instrukcje dostawcy poczty.</li>'
                . '<li>Autoresponder: odpowiedź na czas urlopu, opisana w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/autoresponder">Autoodpowiedź na czas nieobecności</a>.</li>'
                . '<li>Uwierzytelnianie dwuetapowe: dodatkowy kod przy logowaniu, opisany w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/weryfikacja-dwuetapowa">Weryfikacja dwuetapowa w poczcie</a>.</li>'
                . '<li>CleanBox: filtr antyspamowy, opisany w artykule <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/spam-i-zaufani-nadawcy">Spam, zablokowani i zaufani nadawcy</a>.</li></ul>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie zmieniaj hasła w częściach „Hasło” ani „Przypomnienie hasła” w ustawieniach poczty. Takie hasło nie trafi do SZO. '
                . 'Hasło do poczty zmieniasz tylko na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>, jak opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/moja-poczta/zmiana-hasla-poczty">Jak zmienić hasło do poczty</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie wiesz, do czego służy jakiś przycisk? Najedź na niego myszą, a pokaże się podpowiedź. Z pytaniami <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], true],

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
        ], true],

        'weryfikacja-dwuetapowa' => ['Weryfikacja dwuetapowa (2FA) w poczcie', '2fa, weryfikacja dwuetapowa, uwierzytelnienie, authenticator, kod, bezpieczeństwo, kody zapasowe', [
            ['type' => 'text', 'html' => '<p>Weryfikacja dwuetapowa chroni skrzynkę, nawet gdy ktoś pozna Twoje hasło. Przy logowaniu oprócz hasła podajesz kod z aplikacji w telefonie.</p>'],
            ['type' => 'wizard', 'title' => 'Włączanie 2FA', 'intro' => '<p>Najpierw zainstaluj w telefonie aplikację z kodami, np. Google Authenticator albo Microsoft Authenticator.</p>', 'steps' => [
                $step('Otwórz ustawienia', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' W lewej kolumnie wybierz „Uwierzytelnianie dwuetapowe”.</p>'),
                $step('Wybierz metodę i potwierdź hasłem', '<p>Zobaczysz dwie metody: „Aplikacja mobilna”, która generuje kody w telefonie, i „Email”, która wysyła kody na inny adres e-mail. '
                    . 'Polecamy aplikację mobilną. Kliknij „Włącz” przy wybranej metodzie. W okienku „Potwierdź hasło” wpisz hasło do skrzynki i kliknij „Kontynuuj”.</p>'),
                $step('Zeskanuj kod QR', '<p>Otwórz aplikację w telefonie i zeskanuj kod QR z ekranu. Wpisz kod, który pokaże aplikacja, i kliknij „Zakończ”.</p><p>Zamiast aplikacji możesz podać inny adres e-mail (nie ten służbowy). Kod przyjdzie tam i jest ważny 5 minut.</p>'),
                $step('Zapisz kody zapasowe', '<p>Kliknij „Pokaż kody zapasowe” i zapisz je albo wydrukuj. Przydadzą się, gdy zgubisz telefon. Zużyte kody wymienisz przyciskiem „Wygeneruj nowe kody zapasowe”.</p>'),
            ]],
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
            ['type' => 'wizard', 'title' => 'Stopka z generatora w Thunderbirdzie', 'intro' => '', 'steps' => [
                $step('Skopiuj kod HTML', '<p>W <a href="' . $app . '/pomocne/generatorstopki">generatorze</a> wpisz swoje dane i kliknij „Kopiuj kod HTML”. Przycisk „Kopiuj stopkę do schowka” nie nadaje się do Thunderbirda, bo wkleiłby zwykły tekst bez układu.</p>'),
                $step('Otwórz ustawienia konta', '<p>Uruchom Thunderbirda. W lewej kolumnie kliknij prawym przyciskiem myszy nazwę swojej skrzynki i wybierz „Ustawienia”.</p>'),
                $step('Wklej kod w polu „Sygnaturka”', '<p>Zaznacz opcję „Używaj HTML”, potem wklej kod w pole „Sygnaturka” (Ctrl+V). Ustawienia zapisują się same.</p>'),
                $step('Sprawdź wiadomością próbną', '<p>Napisz wiadomość do siebie i zobacz, czy stopka wygląda tak jak w podglądzie generatora.</p>'),
            ]],
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
            ['type' => 'text', 'html' => '<h2>Przykładowa treść</h2>'
                . '<p>Dzień dobry, dziękuję za wiadomość. Do 28 listopada jestem na urlopie i nie czytam poczty. Odpowiem po powrocie. '
                . 'W pilnych sprawach proszę pisać do [imię i nazwisko osoby zastępującej, jej adres e-mail]. Pozdrawiam, [Twoje imię i nazwisko], Fundacja Edukacji Empatii Rozwoju FEER</p>'
                . '<p>Podmień datę i to, co jest w nawiasach kwadratowych, na swoje dane. Osobę zastępującą uprzedź wcześniej. Nie podawaj w autoodpowiedzi prywatnego numeru telefonu, bo dostanie ją każdy, także nadawca spamu.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie widzisz „Autorespondera” w ustawieniach? <a href="' . $hd . '">Napisz w Helpdesku</a>, włączymy go z panelu administratora.</p>'],
        ], false],

        'filtry-i-przekierowanie' => ['Filtry i przekierowanie poczty', 'filtr, filtry, reguły, przekierowanie, przekazywanie, forward, folder, sortowanie', [
            ['type' => 'text', 'html' => '<p>Filtr sam przenosi wybrane wiadomości do folderu albo przekazuje je na inny adres.</p>'],
            ['type' => 'wizard', 'title' => 'Filtr z otwartej wiadomości', 'intro' => '<p>Najszybciej zrobisz filtr z wiadomości, która ma być filtrowana.</p>', 'steps' => [
                $step('Zaznacz wiadomość', '<p>Zaznacz wiadomość na liście, a w górnym pasku wybierz „Więcej”, potem „Utwórz filtr”.</p>'),
                $step('Wybierz, po czym filtrować', '<p>Wskaż, czy filtr ma działać np. po nadawcy albo temacie, i kliknij „Następny krok”.</p>'),
                $step('Ustaw akcję', '<p>Nadaj filtrowi nazwę, sprawdź warunki i wybierz folder, do którego mają trafiać wiadomości. Zapisz.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h3>Przekierowanie na inny adres</h3><p>Wejdź w ustawienia, wybierz „Filtry” i dodaj nowy filtr (ikona ołówka pod listą „Moje filtry”). Jako warunek ustaw wiadomości kierowane do Twojej skrzynki, a jako akcję przekazanie na adres docelowy. Jeśli kopia ma nie zostawać w skrzynce, dodaj drugą akcję (ikona plusa): usunięcie wiadomości. Zapisz.</p>'
                . '<p>Filtr wyłączysz bez usuwania, zaznaczając „Filtr wyłączony”.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie przekierowuj poczty służbowej na prywatne skrzynki (Gmail, Onet, WP itp.). W wiadomościach bywają dane osobowe, które muszą zostać w systemach organizacji.</p>'],
        ], false],

        'spam-i-zaufani-nadawcy' => ['Spam, zablokowani i zaufani nadawcy', 'spam, antyspam, cleanbox, blokowanie, czarna lista, biała lista, zaufani nadawcy, obrazki, wirusy', [
            ['type' => 'text', 'html' => '<p>Każda skrzynka ma włączone filtry antyspamowe, a poczta jest sprawdzana pod kątem wirusów. Możesz sam zablokować natrętnego nadawcę albo oznaczyć zaufanego.</p>'],
            ['type' => 'wizard', 'title' => 'Blokowanie i zaufane adresy', 'intro' => '', 'steps' => [
                $step('Otwórz Cleanbox', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>, wejdź w „Ustawienia” i wybierz „Cleanbox”.</p>'),
                $step('Ustaw poziom filtra', '<p>W zakładce „Filtr antyspamowy” ustawisz, jak ostro ma działać filtr.</p>'),
                $step('Dodaj adresy', '<p>W zakładce „Reguły adresów” są dwie listy: „Zaufane adresy” i „Zablokowane adresy”. Wpis <code>*@nazwadomeny.pl</code> obejmuje wszystkie adresy z tej domeny.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h3>Obrazki w wiadomościach</h3><p>Poczta domyślnie może blokować obrazki z obcych serwerów, bo służą do śledzenia i oszustw. W otwartej wiadomości kliknij „Zezwól”, żeby pokazać je raz, albo „Zawsze zezwalaj od…”, żeby dodać nadawcę do zaufanych. Ustawienie zmienisz w „Ustawienia” → „Preferencje” → „Wyświetlanie wiadomości”. Polecamy opcję „od zaufanych nadawców”.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Dostałeś podejrzaną wiadomość z prośbą o hasło, kod albo przelew? Nie klikaj linków i <a href="' . $hd . '">zgłoś ją w Helpdesku</a>.</p>'],
        ], false],

        'kontakty-import-eksport' => ['Kontakty: import i eksport', 'kontakty, książka adresowa, import, eksport, vcard, csv, outlook', [
            ['type' => 'text', 'html' => '<p>Kontakty przeniesiesz z innego programu plikiem vCard (.vcf) albo CSV.</p>'],
            ['type' => 'wizard', 'title' => 'Import kontaktów', 'intro' => '', 'steps' => [
                $step('Przygotuj plik', '<p>Wyeksportuj kontakty ze starego programu do pliku vCard albo CSV. W pliku CSV pola rozdziela przecinek, a pierwszy wiersz to nagłówek, np. <code>First Name,Last Name,E-mail Address</code>.</p>'),
                $step('Otwórz Kontakty', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a> i przejdź do „Kontakty”.</p>'),
                $step('Zaimportuj', '<p>W górnym menu kliknij ikonę chmurki do importu (podpowiedź pojawi się po najechaniu kursorem) i wskaż plik.</p>'),
            ]],
            ['type' => 'text', 'html' => '<p>Eksport działa tak samo: w „Kontaktach” kliknij drugą ikonę chmurki, do eksportu.</p>'],
        ], false],

        'kalendarz' => ['Kalendarz: spotkania, udostępnianie, telefon', 'kalendarz, spotkanie, zaproszenie, udostępnianie, caldav, synchronizacja, telefon, cykliczne', [
            ['type' => 'wizard', 'title' => 'Nowe spotkanie', 'intro' => '', 'steps' => [
                $step('Wybierz termin', '<p>Otwórz kalendarz z menu po prawej stronie i kliknij komórkę przy wybranym dniu i godzinie.</p>'),
                $step('Opisz spotkanie', '<p>Wpisz tytuł, godzinę zakończenia, miejsce i opis. Możesz wybrać kategorię, np. służbowe.</p>'),
                $step('Zaproś uczestników', '<p>W zakładce „Uczestnicy” wpisz adres e-mail, zaznacz wysłanie powiadomienia i kliknij „Dodaj uczestnika”. Uczestnicy dostaną zaproszenie i mogą je przyjąć, odrzucić albo wybrać „Może”.</p>'),
                $step('Powtarzanie i zapis', '<p>Spotkanie co tydzień albo co miesiąc ustawisz w zakładce „Cykl”. Na koniec kliknij „Zapisz”.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h3>Udostępnienie kalendarza</h3><p>W lewej kolumnie najedź kursorem na kalendarz i kliknij ikonę ołówka. W zakładce „Udostępnij kalendarz” wpisz adres współpracownika, zaznacz uprawnienia i kliknij „Udostępnij”.</p>'
                . '<h3>Kalendarz w telefonie i Outlooku</h3><p>Kliknij ołówek przy kalendarzu i przejdź do sekcji „Synchronizacja”. Utwórz nowe połączenie: wpisz nazwę i hasło, wybierz pełny dostęp albo tylko odczyt i kliknij „Dodaj”. Pojawi się adres kalendarza i nazwa użytkownika. Wpisz je w telefonie albo w programie jako konto CalDAV, z hasłem ustawionym przed chwilą.</p>'],
        ], false],

        'szablony-odpowiedzi' => ['Szablony odpowiedzi', 'szablon, szablony, gotowa odpowiedź, powtarzalna wiadomość', [
            ['type' => 'text', 'html' => '<p>Na te same pytania odpowiadasz wiele razy? Zapisz odpowiedź jako szablon.</p>'],
            ['type' => 'wizard', 'title' => 'Szablon krok po kroku', 'intro' => '', 'steps' => [
                $step('Dodaj szablon', '<p>' . $gear . ' Wybierz „Szablony odpowiedzi”, kliknij ikonę ołówka, wpisz tytuł i treść, potem „Zapisz”.</p>'),
                $step('Użyj szablonu', '<p>Odpowiadając na wiadomość, kliknij u góry okna ikonę zakładki i wybierz szablon z listy.</p>'),
            ]],
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
            ['type' => 'text', 'html' => '<p>Dostawca poczty podaje limit 2 GB na załączniki. Serwer odbiorcy często przyjmuje jednak dużo mniej, zwykle 20–30 MB. '
                . 'Wtedy wiadomość wraca do Ciebie z informacją, że jest za duża.</p>'
                . '<p>Plik większy niż ok. 20 MB wyślij jako link, a nie załącznik:</p><ul>'
                . '<li>z <a href="' . $cloud . '">chmury Fundacji</a>, gdy w pliku są dane osobowe albo gdy wysyłasz go komuś z Fundacji,</li>'
                . '<li>przez TransferNow albo WeTransfer, gdy wysyłasz go poza Fundację i nie ma w nim danych osobowych. '
                . 'Jak to zrobić, opisuje artykuł <a href="' . $transfer . '">Bardzo duże pliki: TransferNow i WeTransfer</a>.</li></ul>'
                . '<p>Kilka mniejszych plików możesz też spakować do jednego archiwum ZIP.</p>'],
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
